<?php

namespace Tests\Feature;

use App\Gameplay\AgentRegistration;
use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignFleet;
use App\Gameplay\CampaignHealth;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\CampaignRecovery;
use App\Gameplay\CampaignRegistrationFailure;
use App\Gameplay\CampaignReport;
use App\Gameplay\CampaignSafety;
use App\Gameplay\CampaignStatus;
use App\Gameplay\CampaignTrade;
use App\Gameplay\ContractPlanner;
use App\Gameplay\CreditAccounting;
use App\Gameplay\Engine\EngineHost;
use App\Gameplay\EngineGate;
use App\Gameplay\FuelPlanner;
use App\Gameplay\GameException;
use App\Gameplay\MiningPlanner;
use App\Gameplay\OperationPlanner;
use App\Gameplay\ReadStateUnavailable;
use App\Gameplay\RequestBudget;
use App\Gameplay\RequestDeferred;
use App\Gameplay\ShipCommandExecutor;
use App\Gameplay\SpaceTradersApi;
use App\Jobs\ExecuteShipCommand;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\CampaignSample;
use App\Models\GameObservation;
use App\Models\LocalEngine;
use App\Models\OpportunityInvestigation;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use App\Services\SpaceTradersClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\EngineFixture;
use Tests\Support\FakeEngineHost;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class CampaignAutopilotTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    private User $user;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now());
        config(['gameplay.enabled' => true, 'gameplay.local_engine_enabled' => true]);
        $this->app->instance(EngineHost::class, new FakeEngineHost);
        $this->file = tempnam(sys_get_temp_dir(), 'st-campaign-');
        $world = FakeSpaceTraders::world();
        $world['next_reset'] = now()->addHours(17)->toIso8601String();
        $world['ships'][0]['registration']['role'] = 'COMMAND';
        file_put_contents($this->file, json_encode($world));
        FakeSpaceTraders::install($this->file);
        $this->user = User::factory()->create();
        $this->user->rememberAccountToken('synthetic-account-token');
        $this->agent = $this->user->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic-only', 'faction' => 'VOID',
            'reset_id' => $world['reset'], 'canonical_identity' => AgentSynchronizer::identity($world['reset'], 'LOCAL')]);
        app(AgentSynchronizer::class)->sync($this->agent);
        Queue::fake();
        EngineFixture::healthy();
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        parent::tearDown();
    }

    public function test_engine_close_finishes_each_run_and_restart_resumes_only_campaign_authority(): void
    {
        $grant = $this->tradeGrant();
        $campaign = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $manual = $campaign->replicate();
        $manual->fill(['campaign_episode_id' => null, 'ship_symbol' => 'LOCAL-3'])->save();
        app(AutomationRunner::class)->advance($campaign->id);
        $oldAction = ShipAction::where('state', 'pending')->firstOrFail();
        app(EngineGate::class)->close('off');
        foreach ([$campaign, $manual] as $run) {
            $this->assertNotNull($run->fresh()->stop_requested_at);
            app(AutomationRunner::class)->advance($run->id);
            $this->assertSame('stopped', $run->fresh()->state->value);
            $this->assertNull($run->fresh()->active_agent_id);
        }
        $this->assertSame('rejected', $oldAction->fresh()->state->value);
        EngineFixture::healthy();
        $this->tick();
        $next = AutomationRun::where('campaign_episode_id', $campaign->campaign_episode_id)->whereNotNull('active_agent_id')->firstOrFail();
        $this->assertNotSame($campaign->id, $next->id);
        $this->assertNull($manual->fresh()->active_agent_id);
        $this->assertNull($grant->fresh()->paused_at);
    }

    public function test_role_pinning_preserves_controls_written_after_the_episode_was_loaded(): void
    {
        $this->tradeGrant();
        $episode = CampaignEpisode::firstOrFail();
        $episode->update(['state' => []]);
        CampaignEpisode::whereKey($episode->id)->update(['state' => ['probe_position_failed' => '2026-11-15T12:00:00Z', 'pending_pause' => 'operator_review']]);
        $this->assertSame('LOCAL-2', app(CampaignFleet::class)->role($episode, 'probe')['symbol']);
        $state = $episode->fresh()->state;
        $this->assertSame('2026-11-15T12:00:00Z', $state['probe_position_failed']);
        $this->assertSame('operator_review', $state['pending_pause']);
        $this->assertSame('LOCAL-2', $state['role_ships']['probe']);
        $this->assertSame($state, $episode->state);
    }

    public function test_role_reads_do_not_pin_closed_episodes_even_if_they_closed_after_loading(): void
    {
        $this->tradeGrant();
        $episode = CampaignEpisode::firstOrFail();
        $episode->update(['state' => []]);
        CampaignEpisode::whereKey($episode->id)->update(['closed_at' => now()]);
        app(CampaignFleet::class)->symbols($episode);
        $this->assertSame([], $episode->fresh()->state);
    }

    public function test_buying_an_earlier_sorting_probe_keeps_the_episodes_existing_role_ship(): void
    {
        $this->tradeWorld();
        $this->editWorld(fn (&$world) => $world['ships'][1]['symbol'] = 'LOCAL-Z');
        $this->approve();
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $this->assertSame('LOCAL-Z', $episode->state['role_ships']['probe']);
        $this->editWorld(function (&$world) {
            $world['foundation'] = true;
            $world['offer']['type'] = 'SHIP_PROBE';
            $world['offer']['frame']['symbol'] = 'FRAME_PROBE';
            $world['offer']['mounts'] = [];
        });
        $action = app(ShipCommandExecutor::class)->preview($this->agent->fresh(), 'LOCAL-1', 'buy_ship',
            ['shipType' => 'SHIP_PROBE', 'waypointSymbol' => 'X1-TEST-ROCK', 'maxTotal' => 20000, 'reserve' => 0], 'second-probe');
        app(ShipCommandExecutor::class)->approve($this->agent->fresh(), $action);
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame('confirmed', $action->fresh()->state->value);
        $this->assertSame('FRAME_PROBE', Ship::where('symbol', 'LOCAL-3')->firstOrFail()->snapshot['frame']['symbol']);
        $this->tick();
        $this->assertSame('LOCAL-Z', $episode->fresh()->state['role_ships']['probe']);
        $this->editWorld(fn (&$world) => $world['ships'][] = FakeSpaceTraders::ship('LOCAL-HAULER'));
        app(AgentSynchronizer::class)->sync($this->agent);
        $roles = array_column(app(CampaignStatus::class)->read()['ships'], 'role', 'symbol');
        $this->assertSame(['LOCAL-1' => 'command', 'LOCAL-3' => 'fleet', 'LOCAL-HAULER' => 'fleet', 'LOCAL-Z' => 'probe'], $roles);
    }

    #[DataProvider('campaignDeliverySpending')]
    public function test_manual_spending_protects_delivery_fuel_for_every_campaign_cargo_strategy(string $strategy, string $command, array $arguments, bool $market): void
    {
        config(['gameplay.manual_reserve_floor' => 0]);
        $this->tradeGrant(1000);
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        app(AutomationRunner::class)->advance($run->id);
        app(ShipCommandExecutor::class)->execute(ShipAction::where('state', 'pending')->firstOrFail()->id);
        $plan = array_replace($run->fresh()->plan, ['max_spend' => 100000]);
        if (! $market) {
            unset($plan['market']);
        }
        $run->update(['strategy' => $strategy, 'plan' => $plan]);
        $this->editWorld(function (&$world) {
            $world['ships'][] = FakeSpaceTraders::ship('LOCAL-3');
            $world['ships'][2]['fuel']['current'] = 0;
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $action = app(ShipCommandExecutor::class)->preview($this->agent->fresh(), 'LOCAL-3', $command, $arguments, 'delivery-protected');
        $this->assertSame($market ? 10 : ($command === 'buy_ship' ? 0 : 1), $action->before_state['estimate']['reserve']);
    }

    public static function campaignDeliverySpending(): array
    {
        $cases = [];
        foreach (['trade', 'sell_aboard', 'mining'] as $strategy) {
            foreach (self::manualCampaignSpending() as [$command, $arguments]) {
                $cases[] = [$strategy, $command, $arguments, true];
            }
            $cases[] = [$strategy, 'buy_ship', ['shipType' => 'SHIP_MINING_DRONE', 'waypointSymbol' => 'X1-TEST-ROCK', 'maxTotal' => 20000, 'reserve' => 0], true];
        }
        $cases[] = ['mining', 'buy_ship', ['shipType' => 'SHIP_MINING_DRONE', 'waypointSymbol' => 'X1-TEST-ROCK', 'maxTotal' => 20000, 'reserve' => 0], false];

        return $cases;
    }

    public function test_purchase_preview_protects_delivery_fuel_while_a_campaign_trade_carries_cargo(): void
    {
        $grant = $this->tradeGrant(1000);
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        app(AutomationRunner::class)->advance($run->id);
        app(ShipCommandExecutor::class)->execute(ShipAction::where('state', 'pending')->firstOrFail()->id);
        $this->assertGreaterThan(0, Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot['cargo']['units']);
        $run->update(['plan' => array_replace($run->fresh()->plan, ['max_spend' => 100000])]);
        $action = app(ShipCommandExecutor::class)->preview($this->agent->fresh(), 'LOCAL-1', 'buy_ship',
            ['shipType' => 'SHIP_MINING_DRONE', 'waypointSymbol' => 'X1-TEST-ROCK', 'maxTotal' => 20000, 'reserve' => 0], 'fuel-protected');
        $this->assertSame(10, $action->before_state['estimate']['protected_in_flight']);
        $this->assertNull($grant->fresh()->paused_at);
    }

    public function test_expired_delivery_quotes_never_block_free_commands_and_purchases_fall_back_to_the_planned_fuel(): void
    {
        $this->tradeGrant(1000);
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        app(AutomationRunner::class)->advance($run->id);
        app(ShipCommandExecutor::class)->execute(ShipAction::where('state', 'pending')->firstOrFail()->id);
        $this->assertGreaterThan(0, Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot['cargo']['units']);
        $this->editWorld(fn (&$world) => $world['ships'][] = FakeSpaceTraders::ship('LOCAL-3'));
        app(AgentSynchronizer::class)->sync($this->agent);
        GameObservation::where('kind', 'market')->update(['observed_at' => now()->subHours(7)]);

        $orbit = app(ShipCommandExecutor::class)->preview($this->agent->fresh(), 'LOCAL-3', 'orbit', [], 'stale-free-orbit');
        $this->assertSame('preview', $orbit->state->value);

        $purchase = app(ShipCommandExecutor::class)->preview($this->agent->fresh(), 'LOCAL-1', 'buy_ship',
            ['shipType' => 'SHIP_MINING_DRONE', 'waypointSymbol' => 'X1-TEST-ROCK', 'maxTotal' => 20000, 'reserve' => 0], 'stale-purchase');
        $planned = $run->fresh()->plan['campaign_trade'];
        $this->assertSame((int) max($planned['fuel_reserve'], $planned['fuel']), $purchase->before_state['estimate']['protected_in_flight']);
    }

    public function test_stale_fallback_never_protects_less_than_the_fresh_delivery_reserve_with_asymmetric_fuel_prices(): void
    {
        $this->tradeWorld();
        $this->editWorld(fn (&$world) => $world['market_goods']['X1-TEST-PORT'][2]['purchasePrice'] = 100);
        $this->approve(str_replace('max_credits: 60000', 'max_credits: 1000', $this->body()));
        $this->tick();
        CampaignEpisode::firstOrFail()->update(['phase' => 'earn']);
        $this->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        app(AutomationRunner::class)->advance($run->id);
        app(ShipCommandExecutor::class)->execute(ShipAction::where('state', 'pending')->firstOrFail()->id);
        $this->editWorld(fn (&$world) => $world['ships'][] = FakeSpaceTraders::ship('LOCAL-3'));
        app(AgentSynchronizer::class)->sync($this->agent);
        $arguments = ['shipType' => 'SHIP_MINING_DRONE', 'waypointSymbol' => 'X1-TEST-ROCK', 'maxTotal' => 20000, 'reserve' => 0];
        $fresh = app(ShipCommandExecutor::class)->preview($this->agent->fresh(), 'LOCAL-3', 'buy_ship', $arguments, 'asymmetric-fresh');
        GameObservation::where('kind', 'market')->update(['observed_at' => now()->subHour()]);
        $stale = app(ShipCommandExecutor::class)->preview($this->agent->fresh(), 'LOCAL-3', 'buy_ship', $arguments, 'asymmetric-stale');
        $planned = $run->fresh()->plan['campaign_trade'];
        $this->assertNotSame($planned['fuel_reserve'], $planned['fuel']);
        $this->assertGreaterThan(0, $fresh->before_state['estimate']['protected_in_flight']);
        $this->assertGreaterThanOrEqual($fresh->before_state['estimate']['protected_in_flight'], $stale->before_state['estimate']['protected_in_flight']);
    }

    public function test_expired_recovery_without_a_planned_allowance_refuses_only_spending(): void
    {
        $this->tradeGrant(1000);
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        app(AutomationRunner::class)->advance($run->id);
        app(ShipCommandExecutor::class)->execute(ShipAction::where('state', 'pending')->firstOrFail()->id);
        $plan = $run->fresh()->plan;
        unset($plan['campaign_trade']);
        $run->update(['strategy' => 'sell_aboard', 'plan' => $plan]);
        $this->editWorld(fn (&$world) => $world['ships'][] = FakeSpaceTraders::ship('LOCAL-3'));
        app(AgentSynchronizer::class)->sync($this->agent);
        GameObservation::where('kind', 'market')->update(['observed_at' => now()->subHours(7)]);
        $before = count(json_decode(file_get_contents($this->file), true)['calls'] ?? []);
        $orbit = app(ShipCommandExecutor::class)->preview($this->agent->fresh(), 'LOCAL-3', 'orbit', [], 'recovery-free-orbit');
        $this->assertSame('preview', $orbit->state->value);
        $calls = array_slice(json_decode(file_get_contents($this->file), true)['calls'] ?? [], $before);
        $this->assertSame([], array_values(array_filter($calls, fn ($call) => str_contains($call['path'], '/ships/LOCAL-1'))));
        try {
            app(ShipCommandExecutor::class)->preview($this->agent->fresh(), 'LOCAL-3', 'buy_ship',
                ['shipType' => 'SHIP_MINING_DRONE', 'waypointSymbol' => 'X1-TEST-ROCK', 'maxTotal' => 20000, 'reserve' => 0], 'recovery-stale-purchase');
            $this->fail('Spending should wait for a verifiable delivery reserve.');
        } catch (GameException $e) {
            $this->assertStringContainsString('Campaign ship LOCAL-1 carries cargo', $e->getMessage());
        }
    }

    #[DataProvider('manualCampaignSpending')]
    public function test_idle_campaign_commitments_allow_manual_spending_on_a_fleet_ship(string $command, array $arguments): void
    {
        $this->tradeGrant();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $run->update(['strategy' => 'scout', 'plan' => array_replace($run->plan, ['max_spend' => 100000])]);
        $this->editWorld(function (&$world) {
            $world['ships'][] = FakeSpaceTraders::ship('LOCAL-3');
            $world['ships'][2]['fuel']['current'] = 0;
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $action = app(ShipCommandExecutor::class)->preview($this->agent->fresh(), 'LOCAL-3', $command, $arguments, 'manual-fleet-spending');
        $this->assertSame(2000, $action->before_state['estimate']['reserve']);
        $this->assertEmpty(json_decode(file_get_contents($this->file), true)['writes']);
    }

    public static function manualCampaignSpending(): array
    {
        return [
            ['buy', ['symbol' => 'IRON_ORE', 'units' => 1, 'maxTotal' => 100, 'reserve' => 0]],
            ['refuel', ['units' => 100, 'maxTotal' => 100, 'reserve' => 0]],
        ];
    }

    public function test_non_role_pending_action_defers_only_the_write_and_settled_discrepancy_still_pauses(): void
    {
        $grant = $this->tradeGrant();
        AutomationRun::whereNotNull('active_agent_id')->update(['active_agent_id' => null, 'state' => 'stopped']);
        $action = ShipAction::create(['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-3',
            'key' => 'unrelated-pending', 'origin' => 'manual', 'command' => 'buy', 'state' => 'pending', 'arguments' => [], 'expires_at' => now()->addMinutes(3)]);
        $this->tick();
        $this->assertNull($grant->fresh()->paused_at);
        $this->assertSame('awaiting_run_start', $grant->fresh()->wait_reason);
        $action->update(['state' => 'rejected']);
        $this->editWorld(fn (&$world) => $world['credits'] -= 100);
        $this->tick();
        $this->assertSame('unexplained_change', $grant->fresh()->pause_reason);
    }

    public function test_campaign_ignores_a_non_role_run_and_status_stays_with_its_own_run(): void
    {
        $grant = $this->tradeGrant();
        $campaign = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $manual = $campaign->replicate();
        $manual->fill(['campaign_episode_id' => null, 'ship_symbol' => 'LOCAL-3', 'state' => 'blocked'])->save();
        $campaign->update(['state' => 'stopped', 'active_agent_id' => null]);
        $this->tick();
        $this->assertNull($manual->fresh()->stop_requested_at);
        $this->assertSame($this->agent->id, $manual->fresh()->active_agent_id);
        $active = AutomationRun::where('campaign_episode_id', $campaign->campaign_episode_id)->whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame($active->id, app(CampaignStatus::class)->read()['currentRun']['id']);
        $this->assertSame('trade', CampaignEvent::where('kind', 'decision')->latest('id')->firstOrFail()->data['choice']);
        $this->assertNull($grant->fresh()->paused_at);
    }

    public function test_campaign_waits_without_stopping_a_manual_run_on_a_role_ship(): void
    {
        $grant = $this->tradeGrant();
        $manual = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $manual->update(['campaign_episode_id' => null, 'state' => 'blocked']);
        $this->tick();
        $this->assertNull($manual->fresh()->stop_requested_at);
        $this->assertSame('role_ship_busy', CampaignEvent::where('kind', 'decision')->latest('id')->firstOrFail()->data['choice']);
        $this->assertNull($grant->fresh()->paused_at);
    }

    public function test_campaign_defers_accounting_for_another_ships_in_flight_write_but_uncertainty_pauses(): void
    {
        $grant = $this->tradeGrant();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $action = ShipAction::create(['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-3',
            'key' => 'other-ship', 'origin' => 'manual', 'command' => 'buy', 'state' => 'sending', 'arguments' => [], 'expires_at' => now()->addMinutes(3)]);
        $this->editWorld(fn (&$world) => $world['credits'] -= 100);
        $this->tick();
        $this->assertNull($grant->fresh()->paused_at);
        app(AutomationRunner::class)->advance($run->id);
        $this->assertNotSame('blocked', $run->fresh()->state->value);
        $action->update(['state' => 'uncertain']);
        $this->tick();
        $this->assertSame('uncertain_action', $grant->fresh()->pause_reason);
    }

    public function test_pending_grant_replacement_ignores_a_manual_hauler_run(): void
    {
        $grant = $this->tradeGrant();
        $campaign = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $pending = $this->approve($this->body()."\n# replacement\n");
        $this->assertSame(2, $pending->slot);
        $manual = $campaign->replicate();
        $manual->fill(['campaign_episode_id' => null, 'ship_symbol' => 'LOCAL-3'])->save();
        $campaign->update(['state' => 'stopped', 'active_agent_id' => null]);
        app(CampaignControl::class)->activatePending();
        $this->assertSame(1, $pending->fresh()->slot);
        $this->assertNotNull($grant->fresh()->superseded_at);
        $this->assertNull($manual->fresh()->stop_requested_at);
    }

    public function test_manual_fleet_purchase_during_a_campaign_run_is_explained_and_the_run_continues(): void
    {
        $grant = $this->tradeGrant(1000);
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $run->update(['plan' => array_replace($run->plan, ['max_spend' => 100000])]);
        $action = app(ShipCommandExecutor::class)->preview($this->agent->fresh(), 'LOCAL-1', 'buy_ship',
            ['shipType' => 'SHIP_MINING_DRONE', 'waypointSymbol' => 'X1-TEST-ROCK', 'maxTotal' => 20000, 'reserve' => 0], 'fleet-purchase');
        $this->assertSame(0, $action->before_state['estimate']['protected_in_flight']);
        $this->assertSame([], json_decode(file_get_contents($this->file), true)['writes']);
        app(ShipCommandExecutor::class)->approve($this->agent->fresh(), $action);
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame('confirmed', $action->fresh()->state->value);
        $this->assertDatabaseHas('ships', ['agent_id' => $this->agent->id, 'symbol' => 'LOCAL-3']);
        $this->assertSame(0, app(CreditAccounting::class)->summary($this->agent->fresh())['unexplained_change']);
        $this->assertNull(app(CampaignSafety::class)->reason($grant, CampaignEpisode::firstOrFail(), $this->agent->fresh()));
        for ($i = 0; $i < 100 && $run->fresh()->active_agent_id; $i++) {
            app(AutomationRunner::class)->advance($run->id);
            foreach (ShipAction::where('state', 'pending')->get() as $pending) {
                app(ShipCommandExecutor::class)->execute($pending->id);
            }
            $this->travel(6)->seconds();
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        }
        $this->assertSame('completed', $run->fresh()->state->value, $run->fresh()->blocked_reason ?? '');
        $this->assertSame(-20000, $run->fresh()->totals['other_credit_delta']);
        $this->assertNull($grant->fresh()->paused_at);
    }

    public function test_a_budget_deferred_positioning_preview_starts_first_on_the_next_paced_tick(): void
    {
        $this->tradeWorld();
        app(AgentSynchronizer::class)->sync($this->agent);
        $grant = $this->approve();
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $episode->update(['phase' => 'earn', 'opened_at' => now()->subMinutes(16)]);
        $this->editWorld(function (&$world) {
            $point = $world['waypoints'][0];
            $point['symbol'] = 'X1-TEST-MINE';
            $point['traits'] = [];
            $world['waypoints'][] = $point;
            $world['ships'][0]['nav']['waypointSymbol'] = $point['symbol'];
            $world['pace_seconds'] = 0.5;
        });
        app(AgentSynchronizer::class)->observe($this->agent, 'waypoint', 'X1-TEST-MINE', json_decode(file_get_contents($this->file), true)['waypoints'][2]);
        $deferStart = true;
        $requests = 0;
        $this->partialMock(RequestBudget::class)->shouldReceive('reserve')->andReturnUsing(function () use (&$deferStart, &$requests) {
            $requests++;
            if (($deferStart && AutomationRun::where('state', 'preview')->exists()) || (! $deferStart && $requests > 6)) {
                throw new RequestDeferred('Synthetic paced start budget exhausted.', 5);
            }
        });
        $this->tick();
        $preview = AutomationRun::where('state', 'preview')->latest('id')->firstOrFail();
        $this->assertSame('scout', $preview->strategy);
        $this->assertSame('LOCAL-1', $preview->ship_symbol);
        $this->assertSame('X1-TEST-ROCK', $preview->plan['market']);
        $this->assertDatabaseCount('opportunity_investigations', 0);
        $deferStart = false;
        $requests = 0;
        $this->tick();
        $this->assertSame($preview->id, AutomationRun::whereNotNull('active_agent_id')->value('id'));
        $this->assertSame('running', $preview->fresh()->state->value);
        $this->assertLessThanOrEqual(6, $requests);
        $this->assertNull($grant->fresh()->paused_at);
    }

    public function test_a_retained_preview_with_changed_ship_state_is_discarded_without_starting(): void
    {
        $this->tradeWorld();
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->approve();
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $preview = app(AutomationRunner::class)->preview($this->agent, 'LOCAL-1', ['strategy' => 'scout', 'destination' => 'X1-TEST-PORT',
            'reserve' => 0, 'max_fuel' => 100, 'max_spend' => 100, 'max_loss' => 100, 'minutes' => 15, 'cycles' => 1, 'one_way' => true]);
        $preview->update(['campaign_episode_id' => $episode->id]);
        $this->editWorld(function (&$world) {
            $world['ships'][0]['fuel']['current']--;
        });
        $this->tick();
        $this->assertSame('stopped', $preview->fresh()->state->value);
        $this->assertStringContainsString('Ship state', $preview->fresh()->blocked_reason);
        $this->assertNull($preview->fresh()->active_agent_id);
        $this->assertDatabaseMissing('automation_runs', ['active_agent_id' => $this->agent->id]);
    }

    public function test_mining_investigation_is_deferred_to_a_tick_without_a_trade_attempt(): void
    {
        $grant = $this->approve($this->miningBody());
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $episode->update(['phase' => 'earn', 'opened_at' => now()->subMinutes(16)]);
        $this->tick();
        $this->assertDatabaseCount('opportunity_investigations', 0);
        $this->tick();
        $this->assertDatabaseCount('opportunity_investigations', 1);
        $this->assertNull($grant->fresh()->paused_at);
    }

    public function test_a_pending_grant_cannot_bypass_a_pause_after_the_run_settles(): void
    {
        $grant = $this->tradeGrant(100);
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $pending = $this->approve($this->body()."\n# pending revision\n");
        $this->assertSame(2, $pending->slot);
        $this->artisan('campaign:pause')->assertSuccessful();
        app(AutomationRunner::class)->finishStop($run, 'Settled after pause.');
        for ($tick = 0; $tick < 3; $tick++) {
            $this->tick();
        }
        $this->assertNotNull($grant->fresh()->paused_at);
        $this->assertSame(1, $grant->fresh()->slot);
        $this->assertSame(2, $pending->fresh()->slot);
        $this->assertDatabaseMissing('automation_runs', ['active_agent_id' => $this->agent->id]);
    }

    public function test_pending_grant_activates_after_expiry_when_the_busy_run_settles(): void
    {
        $this->tradeWorld();
        $revision = app(CampaignPlaybook::class)->import($this->body());
        $grant = app(CampaignControl::class)->approve($revision, $this->user, now()->toImmutable()->addMinutes(3));
        $this->tick();
        CampaignEpisode::firstOrFail()->update(['phase' => 'earn']);
        $this->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $pending = $this->approve($this->body()."\n# replacement grant\n");
        $this->travel(4)->minutes();
        $this->tick();
        $this->assertSame('grant_expired', $grant->fresh()->pause_reason);
        app(AutomationRunner::class)->finishStop($run, 'Settled after expiry.');
        $this->tick();
        $this->assertSame(1, $pending->fresh()->slot);
        $this->assertNull($pending->fresh()->paused_at);
        $this->assertNull($grant->fresh()->slot);
    }

    public function test_resume_resets_the_director_failure_streak(): void
    {
        $grant = $this->approve();
        app(CampaignControl::class)->pause($grant, 'director_error');
        DB::table('game_runtime')->upsert([['key' => 'campaign_director_failures', 'value' => '5']], ['key'], ['value']);
        $this->artisan('campaign:resume')->assertSuccessful();
        $this->assertSame('0', DB::table('game_runtime')->where('key', 'campaign_director_failures')->value('value'));
        $this->assertNull($grant->fresh()->paused_at);
    }

    public function test_deadline_before_transport_is_retryable_and_malformed_status_waits(): void
    {
        try {
            app(SpaceTradersClient::class)->scope(fn () => app(SpaceTradersClient::class)->request('get', '/'), deadline: microtime(true) - 1);
            $this->fail('Expired scope must not send.');
        } catch (GameException $exception) {
            $this->assertGreaterThan(0, $exception->retryAfter);
        }
        $grant = $this->approve();
        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['maintenance' => true]));
        $this->tick();
        $this->assertNull($grant->fresh()->paused_at);
        $this->assertSame('awaiting_fresh_state', $grant->fresh()->wait_reason);
    }

    public static function inconsistentReads(): array
    {
        return [['cargo'], ['fuel'], ['cooldown']];
    }

    #[DataProvider('inconsistentReads')]
    public function test_inconsistent_get_waits_then_correct_state_continues(string $part): void
    {
        $grant = $this->approve();
        $original = json_decode(file_get_contents($this->file), true)['ships'][0];
        $this->editWorld(function (&$world) use ($part) {
            if ($part === 'cargo') {
                $world['ships'][0]['cargo']['units'] = 1;
            } elseif ($part === 'fuel') {
                $world['ships'][0]['fuel']['current'] = 1000;
            } else {
                $world['ships'][0]['cooldown'] = ['remainingSeconds' => 10, 'totalSeconds' => 1];
            }
        });
        $this->tick();
        $this->assertNull($grant->fresh()->paused_at);
        $this->assertSame('awaiting_fresh_state', $grant->fresh()->wait_reason);
        $this->editWorld(function (&$world) use ($original) {
            $world['ships'][0] = $original;
        });
        $this->tick();
        $this->assertNull($grant->fresh()->paused_at);
        $this->assertNull($grant->fresh()->wait_reason);
        $this->assertDatabaseCount('campaign_episodes', 1);
    }

    public function test_upstream_budget_record_failure_waits_instead_of_blocking_the_run(): void
    {
        $this->tradeGrant(100);
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->editWorld(function (&$world) {
            $world['failure'] = 'read_429';
        });
        $this->partialMock(RequestBudget::class)->shouldReceive('observe')->andReturnUsing(function ($response) {
            if ($response->status() === 429) {
                throw new \RuntimeException('Synthetic budget storage failure.');
            }
        });
        app(AutomationRunner::class)->advance($run->id);
        $this->assertSame('waiting', $run->fresh()->state->value);
        $this->assertNotNull($run->fresh()->next_action_at);
    }

    public function test_not_sent_registration_intent_is_neutralized_and_can_register_next_tick(): void
    {
        $grant = $this->approve();
        $this->tick();
        $this->editWorld(function (&$world) {
            $world['reset'] = now()->subHour()->toIso8601String();
            $world['campaign_registration'] = true;
        });
        $attempts = 0;
        $this->partialMock(AgentRegistration::class)->shouldReceive('register')->andReturnUsing(function (...$arguments) use (&$attempts) {
            if (++$attempts === 1) {
                throw new CampaignRegistrationFailure('not_sent');
            }

            return (new AgentRegistration)->register(...$arguments);
        });
        $this->tick();
        $this->assertNull($grant->fresh()->paused_at);
        $this->assertSame('not_sent', CampaignEvent::where('kind', 'registration_intent')->latest('id')->firstOrFail()->data['outcome']);
        $this->tick();
        $this->assertSame(2, Agent::count());
        $this->assertNull($grant->fresh()->paused_at);
    }

    public static function retryablePositionFailures(): array
    {
        return [[new RequestDeferred('Synthetic positioning budget refusal.', 5)],
            [new ReadStateUnavailable('Synthetic positioning read unavailable.')],
            [new GameException('Synthetic positioning rate limit.', retryAfter: 10)]];
    }

    #[DataProvider('retryablePositionFailures')]
    public function test_probe_positioning_is_retried_after_a_transient_failure(GameException $failure): void
    {
        $this->tradeWorld();
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->editWorld(function (&$world) {
            $world['ships'][1]['nav']['waypointSymbol'] = 'X1-TEST-ROCK';
            $world['ships'][1]['fuel'] = ['capacity' => 0, 'current' => 0];
        });
        $body = Yaml::parse($this->body());
        $body['phases'] = array_slice($body['phases'], 1);
        $grant = $this->approve(Yaml::dump($body, 10));
        $runner = \Mockery::mock(AutomationRunner::class, [app(ShipCommandExecutor::class), app(AgentSynchronizer::class), app(MiningPlanner::class), app(SpaceTradersApi::class), app(EngineGate::class), app(ContractPlanner::class)])->makePartial();
        $this->app->instance(AutomationRunner::class, $runner);
        $runner->shouldReceive('preview')->byDefault()->passthru();
        $runner->shouldReceive('preview')->withArgs(fn ($agent, $symbol) => $symbol === 'LOCAL-2')->once()
            ->andThrow($failure);
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $this->assertArrayNotHasKey('probe_position_failed', $episode->state ?? []);
        $run = AutomationRun::whereNotNull('active_agent_id')->first();
        if ($run) {
            app(AutomationRunner::class)->finishStop($run, 'Synthetic completed trade.');
        }
        $runner->shouldReceive('preview')->withArgs(fn ($agent, $symbol) => $symbol === 'LOCAL-2')->once()->passthru();
        $this->tick();
        $position = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('scout', $position->strategy);
        $this->assertSame('LOCAL-2', $position->ship_symbol);
        $this->assertNull($grant->fresh()->paused_at);
    }

    public function test_probe_positioning_failure_falls_back_to_direct_quote_refresh(): void
    {
        $this->tradeWorld();
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->editWorld(function (&$world) {
            $world['ships'][1]['nav']['waypointSymbol'] = 'X1-TEST-ROCK';
            $world['ships'][1]['fuel'] = ['capacity' => 0, 'current' => 0];
            $world['ships'][1]['frame']['symbol'] = 'FRAME_MINER';
        });
        $body = Yaml::parse($this->body());
        $body['phases'] = array_slice($body['phases'], 1);
        $grant = $this->approve(Yaml::dump($body, 10));
        $this->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('trade', $run->strategy);
        $this->assertSame('LOCAL-1', $run->ship_symbol);
        $this->assertNull($grant->fresh()->paused_at);
    }

    public function test_cargo_at_a_non_market_refreshes_stale_fuel_and_buyer_evidence(): void
    {
        $this->tradeWorld();
        app(AgentSynchronizer::class)->sync($this->agent);
        $grant = $this->approve();
        $this->tick();
        GameObservation::where('kind', 'market')->update(['observed_at' => now()->subHour()]);
        $this->editWorld(function (&$world) {
            $point = $world['waypoints'][0];
            $point['symbol'] = 'X1-TEST-MINE';
            $point['traits'] = [];
            $world['waypoints'][] = $point;
            $world['ships'][0]['nav']['waypointSymbol'] = $point['symbol'];
            $world['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 3, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 3]]];
        });
        app(AgentSynchronizer::class)->observe($this->agent, 'waypoint', 'X1-TEST-MINE', json_decode(file_get_contents($this->file), true)['waypoints'][2]);
        GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-ROCK')->delete();
        GameObservation::where('kind', 'market_listing')->where('symbol', 'X1-TEST-ROCK')->delete();
        $this->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('sell_aboard', $run->strategy);
        $this->assertSame('X1-TEST-PORT', $run->plan['market']);
        $this->assertNull($grant->fresh()->paused_at);
        $this->assertTrue(GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-PORT')->firstOrFail()->observed_at->gt(now()->subMinutes(2)));
    }

    public function test_recovery_without_probe_uses_onboard_fuel_and_last_known_bid_until_arrival(): void
    {
        $this->tradeWorld();
        app(AgentSynchronizer::class)->sync($this->agent);
        $grant = $this->approve();
        $this->tick();
        GameObservation::where('kind', 'market')->update(['observed_at' => now()->subHour()]);
        $this->editWorld(function (&$world) {
            $point = $world['waypoints'][0];
            $point['symbol'] = 'X1-TEST-MINE';
            $point['traits'] = [];
            $world['waypoints'][] = $point;
            $world['ships'] = [$world['ships'][0]];
            $world['ships'][0]['nav']['waypointSymbol'] = $point['symbol'];
            $world['ships'][0]['nav']['status'] = 'IN_ORBIT';
            $world['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 3, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 3]]];
        });
        Ship::where('symbol', 'LOCAL-2')->delete();
        app(AgentSynchronizer::class)->observe($this->agent, 'waypoint', 'X1-TEST-MINE', json_decode(file_get_contents($this->file), true)['waypoints'][2]);
        $this->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('sell_aboard', $run->strategy);
        $this->assertSame('X1-TEST-PORT', $run->plan['market']);
        $leg = $run->plan['route']['outbound'];
        $this->assertSame($leg['outbound_fuel'] + config('gameplay.fuel_reserve_units'), $leg['required_fuel']);
        $lowFuel = Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
        $lowFuel['fuel']['current'] = $leg['required_fuel'] - 1;
        try {
            app(FuelPlanner::class)->recoveryLeg($this->agent, $lowFuel, $run->plan['market']);
            $this->fail('Sell-only delivery must preserve the existing fuel reserve.');
        } catch (GameException $exception) {
            $this->assertStringContainsString('fuel-safe', $exception->getMessage());
        }
        app(AutomationRunner::class)->advance($run->id);
        $action = ShipAction::where('automation_run_id', $run->id)->where('command', 'navigate')->firstOrFail();
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame('confirmed', $action->fresh()->state->value);
        $this->assertNull($grant->fresh()->paused_at);
        $this->assertDatabaseMissing('ship_actions', ['automation_run_id' => $run->id, 'command' => 'buy']);
        $this->assertDatabaseMissing('ship_actions', ['automation_run_id' => $run->id, 'command' => 'refuel']);
    }

    public static function recoveryPhases(): array
    {
        return [[false, false], [true, false], [false, true]];
    }

    #[DataProvider('recoveryPhases')]
    public function test_stale_recovery_evidence_positions_the_probe_except_during_wind_down(bool $windDown, bool $deferred): void
    {
        $this->tradeWorld();
        app(AgentSynchronizer::class)->sync($this->agent);
        $grant = $this->approve();
        $this->tick();
        if ($windDown) {
            CampaignEpisode::firstOrFail()->update(['phase' => 'wind_down', 'next_reset_at' => now()->addMinutes(30)]);
        }
        GameObservation::where('kind', 'market')->update(['observed_at' => now()->subHour()]);
        $this->editWorld(function (&$world) {
            $point = $world['waypoints'][0];
            $point['symbol'] = 'X1-TEST-MINE';
            $point['traits'] = [];
            $world['waypoints'][] = $point;
            foreach ($world['ships'] as &$ship) {
                $ship['nav']['waypointSymbol'] = $point['symbol'];
                $ship['nav']['status'] = 'IN_ORBIT';
            }
            $world['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 3, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 3]]];
            $world['ships'][1]['fuel'] = ['capacity' => 0, 'current' => 0];
        });
        app(AgentSynchronizer::class)->observe($this->agent, 'waypoint', 'X1-TEST-MINE', json_decode(file_get_contents($this->file), true)['waypoints'][2]);
        if ($deferred) {
            $runner = \Mockery::mock(AutomationRunner::class, [app(ShipCommandExecutor::class), app(AgentSynchronizer::class), app(MiningPlanner::class), app(SpaceTradersApi::class), app(EngineGate::class), app(ContractPlanner::class)])->makePartial();
            $this->app->instance(AutomationRunner::class, $runner);
            $runner->shouldReceive('startCampaign')->byDefault()->passthru();
            $runner->shouldReceive('startCampaign')->withArgs(fn ($agent, $run) => $run->ship_symbol === 'LOCAL-2')->once()
                ->andThrow(new RequestDeferred('Synthetic recovery observer start deferred.', 5));
        }
        $this->tick();
        if ($deferred) {
            $this->assertDatabaseCount('automation_runs', 1);
            $this->assertDatabaseMissing('automation_runs', ['active_agent_id' => $this->agent->id]);
            $runner->shouldReceive('startCampaign')->withArgs(fn ($agent, $run) => $run->ship_symbol === 'LOCAL-2')->once()->passthru();
            $this->tick();
        }
        $position = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        if ($windDown) {
            $this->assertSame('sell_aboard', $position->strategy);
            $this->assertSame('LOCAL-1', $position->ship_symbol);
            $this->assertNull($grant->fresh()->paused_at);

            return;
        }
        $this->assertSame('scout', $position->strategy);
        $this->assertSame('LOCAL-2', $position->ship_symbol);
        $this->assertSame('X1-TEST-PORT', $position->plan['market']);
        app(AutomationRunner::class)->advance($position->id);
        $action = ShipAction::where('automation_run_id', $position->id)->where('command', 'navigate')->firstOrFail();
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame('confirmed', $action->fresh()->state->value);
        for ($step = 0; $step < 6 && $position->fresh()->active_agent_id; $step++) {
            app(AutomationRunner::class)->advance($position->id);
            foreach (ShipAction::where('automation_run_id', $position->id)->where('state', 'pending')->get() as $action) {
                app(ShipCommandExecutor::class)->execute($action->id);
            }
        }
        $this->assertNull($position->fresh()->active_agent_id);
        $this->tick();
        $this->assertSame('sell_aboard', AutomationRun::whereNotNull('active_agent_id')->firstOrFail()->strategy);
        $this->assertNull($grant->fresh()->paused_at);
    }

    public function test_recovery_refresh_rotation_advances_despite_one_market_refusal(): void
    {
        $this->tradeWorld();
        app(AgentSynchronizer::class)->sync($this->agent);
        $grant = $this->approve();
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $quote = GameObservation::where('kind', 'market')->firstOrFail();
        app(AgentSynchronizer::class)->observe($this->agent, 'market', 'X1-TEST-Z', array_merge($quote->data, ['symbol' => 'X1-TEST-Z']));
        $this->editWorld(function (&$world) {
            $world['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 3, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 3]]];
        });
        $api = app(SpaceTradersApi::class);
        $mock = \Mockery::mock(SpaceTradersApi::class, [app(SpaceTradersClient::class)])->makePartial();
        $visited = [];
        $mock->shouldReceive('market')->andReturnUsing(function ($agent, $symbol) use ($api, &$visited) {
            $visited[] = $symbol;
            if (count($visited) === 1) {
                throw new RequestDeferred('Synthetic recovery refresh refusal.', 5);
            }

            return $api->market($agent, $symbol);
        });
        $this->app->instance(SpaceTradersApi::class, $mock);
        $this->tick();
        $this->assertSame(2, $episode->fresh()->state['recovery_refresh']);
        $this->assertSame(['X1-TEST-PORT', 'X1-TEST-ROCK'], array_slice($visited, 0, 2));
        $this->assertNull($grant->fresh()->paused_at);
    }

    public function test_a_new_run_resets_productive_progress_and_wind_down_suppresses_the_alert(): void
    {
        $grant = $this->tradeGrant(100);
        $episode = CampaignEpisode::firstOrFail();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->travel(70)->minutes();
        EngineFixture::healthy();
        $grant->update(['last_directed_at' => now()]);
        $this->assertArrayHasKey('campaign_progress_stalled:'.$grant->id, app(CampaignHealth::class)->problems());
        app(AutomationRunner::class)->finishStop($run, 'Synthetic completed trade.');
        $grant->update(['last_directed_at' => null]);
        $this->tick();
        $this->assertNotSame($run->id, AutomationRun::whereNotNull('active_agent_id')->firstOrFail()->id);
        $this->assertSame([], app(CampaignHealth::class)->problems());
        $this->travel(70)->minutes();
        EngineFixture::healthy();
        $grant->update(['last_directed_at' => now()]);
        $episode->update(['phase' => 'wind_down']);
        $this->assertSame([], app(CampaignHealth::class)->problems());
    }

    public function test_earn_discovery_advances_during_runs_and_only_reads_occupied_markets(): void
    {
        $this->tradeGrant(100);
        $episode = CampaignEpisode::firstOrFail();
        $episode->update(['state' => array_merge($episode->state ?? [], ['last_discovery_at' => now()->subHour()->toIso8601String()])]);
        $this->editWorld(function (&$world) {
            $point = $world['waypoints'][1];
            for ($index = 0; $index < 24; $index++) {
                $point['symbol'] = 'X1-TEST-M'.$index;
                $world['waypoints'][] = $point;
            }
            $world['calls'] = [];
        });
        for ($tick = 0; $tick < 4; $tick++) {
            $before = count(json_decode(file_get_contents($this->file), true)['calls']);
            $this->tick();
            $calls = json_decode(file_get_contents($this->file), true)['calls'];
            $this->assertLessThanOrEqual(6, count($calls) - $before);
        }
        $this->assertGreaterThanOrEqual(26, GameObservation::where('kind', 'waypoint')->count());
        $this->assertGreaterThan(now()->subMinutes(5), CarbonImmutable::parse($episode->fresh()->state['last_discovery_at']));
        $markets = collect($calls)->filter(fn ($call) => str_ends_with($call['path'], '/market'))->pluck('path')->unique()->values()->all();
        sort($markets);
        $this->assertSame(['/systems/X1-TEST/waypoints/X1-TEST-PORT/market', '/systems/X1-TEST/waypoints/X1-TEST-ROCK/market'], $markets);
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        app(AutomationRunner::class)->finishStop($run, 'Synthetic completed trade.');
        $episode->refresh()->update(['state' => array_merge($episode->state, ['last_discovery_at' => now()->subHour()->toIso8601String()])]);
        $this->tick();
        $this->assertSame('trade', AutomationRun::whereNotNull('active_agent_id')->firstOrFail()->strategy);
    }

    public function test_discovery_uses_bounded_ticks_with_a_paced_transport(): void
    {
        $grant = $this->approve();
        GameObservation::where('kind', 'catalogue')->delete();
        $this->editWorld(function (&$world) {
            $world['pace_seconds'] = 0.5;
            $point = $world['waypoints'][1];
            for ($index = 0; $index < 24; $index++) {
                $point['symbol'] = 'X1-TEST-M'.$index;
                $world['waypoints'][] = $point;
            }
            $world['calls'] = [];
        });
        for ($tick = 0; $tick < 3; $tick++) {
            $before = count(json_decode(file_get_contents($this->file), true)['calls']);
            $started = microtime(true);
            $this->tick();
            $count = count(json_decode(file_get_contents($this->file), true)['calls']) - $before;
            $this->assertLessThanOrEqual(6, $count);
            $this->assertLessThan(8, microtime(true) - $started);
            $this->assertNull($grant->fresh()->paused_at);
        }
        $this->assertArrayHasKey('discovery', CampaignEpisode::firstOrFail()->state);
        $this->assertGreaterThanOrEqual(26, GameObservation::where('kind', 'waypoint')->count());
    }

    public function test_mining_investigation_continues_across_ticks_with_twenty_four_markets(): void
    {
        config(['gameplay.request_budget_enabled' => true]);
        $grant = $this->approve($this->miningBody());
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $episode->update(['phase' => 'earn', 'opened_at' => now()->subMinutes(16)]);
        $this->editWorld(function (&$world) {
            $world['pace_seconds'] = 0.02;
            $point = $world['waypoints'][1];
            for ($index = 0; $index < 24; $index++) {
                $point['symbol'] = 'X1-TEST-M'.$index;
                $world['waypoints'][] = $point;
            }
        });
        $id = null;
        for ($tick = 0; $tick < 20 && ! AutomationRun::whereNotNull('active_agent_id')->exists(); $tick++) {
            $this->tick();
            $current = $episode->fresh()->state['mining_investigation_id'] ?? null;
            if ($current) {
                $id ??= $current;
                $this->assertSame($id, $current);
            }
        }
        $this->assertNotNull($id);
        $record = OpportunityInvestigation::findOrFail($id);
        $this->assertNull($record->cancelled_at);
        $this->assertGreaterThan(0, $record->coverage['requests']);
        $this->assertNull($grant->fresh()->paused_at);
        $this->assertSame('mining', AutomationRun::whereNotNull('active_agent_id')->firstOrFail()->strategy);
    }

    public function test_decision_events_are_bounded_deduplicated_and_status_is_concise(): void
    {
        $grant = $this->approve();
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $control = app(CampaignControl::class);
        $candidates = array_fill(0, 25, ['rejected' => 'unavailable']);
        $first = $control->event($grant, $episode, 'decision', ['choice' => 'idle', 'candidates' => $candidates]);
        $second = $control->event($grant, $episode, 'decision', ['choice' => 'idle', 'candidates' => $candidates, 'credits' => 123]);
        $this->assertSame($first->id, $second->id);
        $this->assertCount(10, $first->data['candidates']);
        $this->assertSame(['unavailable' => 25], $first->data['rejections']);
        $wait = $control->event($grant, null, 'wait', ['reason' => 'awaiting_fresh_state']);
        $this->assertSame($wait->id, $control->event($grant, null, 'wait', ['reason' => 'awaiting_fresh_state'])->id);
        $this->artisan('campaign:status')->doesntExpectOutputToContain('candidates')->assertSuccessful();
    }

    #[DataProvider('decisionRunIds')]
    public function test_decision_deduplication_respects_the_run_id(?int $firstRun, ?int $secondRun, bool $deduplicated): void
    {
        $grant = $this->approve();
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $control = app(CampaignControl::class);
        $count = CampaignEvent::where('kind', 'decision')->count();
        $first = $control->event($grant, $episode, 'decision', ['choice' => 'sell_aboard', 'run_id' => $firstRun]);
        $second = $control->event($grant, $episode, 'decision', ['choice' => 'sell_aboard', 'run_id' => $secondRun]);

        $this->assertSame($deduplicated, $first->id === $second->id);
        $this->assertSame($count + ($deduplicated ? 1 : 2), CampaignEvent::where('kind', 'decision')->count());
        $this->assertSame($secondRun, $second->data['run_id']);
    }

    public static function decisionRunIds(): array
    {
        return ['different runs' => [1, 2, false], 'same run' => [1, 1, true],
            'missing then present' => [null, 1, false], 'both missing' => [null, null, true]];
    }

    public function test_director_crashes_do_not_block_dispatch_and_five_consecutive_failures_pause(): void
    {
        $grant = $this->tradeGrant();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        app(AutomationRunner::class)->advance($run->id);
        $action = ShipAction::where('state', 'pending')->firstOrFail();
        $this->partialMock(CampaignDirector::class)->shouldReceive('tick')->times(5)->andThrow(new \TypeError('synthetic-secret-must-not-be-logged'));
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->artisan('spacetraders:dispatch-due')->assertSuccessful();
            if ($attempt < 5) {
                $this->assertNull($grant->fresh()->paused_at);
            }
        }
        Queue::assertPushed(ExecuteShipCommand::class, fn ($job) => $job->actionId === $action->id);
        $this->assertSame('director_error', $grant->fresh()->pause_reason);
        $this->assertSame(5, CampaignEvent::where('kind', 'director_error')->count());
        $this->assertStringNotContainsString('synthetic-secret', CampaignEvent::where('kind', 'director_error')->get()->toJson());
    }

    public function test_standalone_recovery_does_not_change_proof_mode_or_losing_streak(): void
    {
        $grant = $this->approve();
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $this->completedLoad($episode, true, 90);
        for ($index = 0; $index < 2; $index++) {
            $run = $this->completedLoad($episode, false, -10, forced: true);
            $run->update(['strategy' => 'sell_aboard', 'plan' => array_merge($run->plan, ['sell_only' => true, 'initial_inventory' => ['IRON_ORE' => 5], 'cargo_cost' => 150])]);
        }
        app(CampaignTrade::class)->recordResults($episode);
        $this->assertSame('first_scaled', app(CampaignTrade::class)->mode($episode, 'IRON_ORE', 'X1-TEST-PORT'));
        $this->assertNull(app(CampaignSafety::class)->reason($grant, $episode, $this->agent));
        $this->assertSame(1, CampaignEvent::where('kind', 'load_result')->count());
        $this->assertSame(2, CampaignEvent::where('kind', 'recovery_result')->count());
        $report = app(CampaignReport::class)->build($episode)['json'];
        $this->assertCount(1, $report['load_results']);
        $this->assertCount(2, $report['standalone_recoveries']);
    }

    public static function registrationResponses(): array
    {
        return [[429, 'not_sent'], [409, 'conflict'], [503, 'ambiguous'], [408, 'ambiguous']];
    }

    #[DataProvider('registrationResponses')]
    public function test_campaign_registration_classifies_known_transport_outcomes(int $status, string $outcome): void
    {
        Http::swap(new Factory);
        Http::fake(fn () => Http::response([], $status));
        try {
            app(SpaceTradersClient::class)->register(['symbol' => 'LOCAL', 'faction' => 'VOID'], 'synthetic-only', campaign: true);
            $this->fail('Registration must defer or require review.');
        } catch (CampaignRegistrationFailure $exception) {
            $this->assertSame($outcome, $exception->outcome);
        }
    }

    public function test_registration_deadline_before_transport_is_not_ambiguous(): void
    {
        Http::swap(new Factory);
        Http::fake();
        $client = app(SpaceTradersClient::class);
        try {
            $client->scope(fn () => $client->register(['symbol' => 'LOCAL', 'faction' => 'VOID'], 'synthetic-only', campaign: true), deadline: microtime(true) - 1);
            $this->fail('Expired registration must not send.');
        } catch (CampaignRegistrationFailure $exception) {
            $this->assertSame('not_sent', $exception->outcome);
        }
        Http::assertNothingSent();
    }

    public function test_one_way_positioning_does_not_require_a_return_leg_and_refuels_at_destination(): void
    {
        $this->tradeWorld();
        app(AgentSynchronizer::class)->sync($this->agent);
        $ship = Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
        $ship['fuel']['current'] = 12;
        $listing = GameObservation::where('kind', 'market_listing')->where('symbol', 'X1-TEST-ROCK')->firstOrFail();
        $data = $listing->data;
        $data['exchange'] = array_values(array_filter($data['exchange'], fn ($good) => $good['symbol'] !== 'FUEL'));
        $listing->update(['data' => $data]);
        $limits = ['strategy' => 'scout', 'destination' => 'X1-TEST-PORT', 'reserve' => 0, 'max_fuel' => 100, 'max_spend' => 20, 'max_loss' => 20, 'one_way' => true];
        $planner = app(OperationPlanner::class);
        $plan = $planner->preview($this->agent, $ship, $limits);
        $this->assertNull($plan['route']['return']);
        $ship['nav']['waypointSymbol'] = 'X1-TEST-PORT';
        $ship['fuel']['current'] = 5;
        $step = $planner->next($this->agent, $ship, $plan, ['phase' => 'observe', 'fuel_cost' => 0], now()->toImmutable());
        $this->assertSame('refuel', $step->command);
    }

    public function test_one_way_positioning_refuels_before_a_leg_that_current_fuel_cannot_cover(): void
    {
        $this->tradeWorld();
        app(AgentSynchronizer::class)->sync($this->agent);
        $ship = Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
        $ship['fuel']['current'] = 0;
        $limits = ['strategy' => 'scout', 'destination' => 'X1-TEST-PORT', 'reserve' => 0, 'max_fuel' => 100, 'max_spend' => 20, 'max_loss' => 20, 'one_way' => true];
        $planner = app(OperationPlanner::class);
        $plan = $planner->preview($this->agent, $ship, $limits);
        $step = $planner->next($this->agent, $ship, $plan, ['phase' => 'observe', 'fuel_cost' => 0], now()->toImmutable());
        $this->assertSame('refuel', $step->command);
        $this->assertGreaterThan(0, $step->arguments['maxTotal']);
    }

    public function test_transaction_boundary_campaign_check_is_read_only_and_does_not_replay_history(): void
    {
        $grant = $this->tradeGrant();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        app(CampaignControl::class)->pause($grant, 'operator_pause');
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        try {
            DB::transaction(fn () => app(CampaignSafety::class)->check($run, $this->agent, 'buy', [], boundaryOnly: true));
            $this->fail('Paused authority cannot reserve a transaction.');
        } catch (GameException) {
            $this->assertNotNull($grant->fresh()->paused_at);
        }
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select', strtolower($query));
            $this->assertStringNotContainsString('ship_actions', $query);
            $this->assertStringNotContainsString('confirmed_credit_entries', $query);
        }
    }

    public function test_local_request_budget_refusal_waits_without_pausing(): void
    {
        $grant = $this->approve();
        $this->partialMock(RequestBudget::class)->shouldReceive('reserve')->andThrow(new RequestDeferred('Synthetic local budget refusal.', 5));
        $this->tick();
        $this->assertSame('awaiting_fresh_state', $grant->fresh()->wait_reason);
        $this->assertNull($grant->fresh()->paused_at);
        $this->assertSame([], json_decode(file_get_contents($this->file), true)['writes']);
    }

    public function test_one_way_run_executes_departure_and_destination_refuelling_without_returning(): void
    {
        $this->tradeWorld();
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->editWorld(function (&$world) {
            $world['ships'][0]['fuel']['current'] = 0;
        });
        $run = app(AutomationRunner::class)->preview($this->agent, 'LOCAL-1', ['strategy' => 'scout', 'destination' => 'X1-TEST-PORT',
            'reserve' => 0, 'max_fuel' => 100, 'max_spend' => 20, 'max_loss' => 20, 'minutes' => 15, 'cycles' => 1, 'one_way' => true]);
        app(AutomationRunner::class)->start($this->agent, $run);
        for ($step = 0; $step < 20 && $run->fresh()->active_agent_id; $step++) {
            app(AutomationRunner::class)->advance($run->id);
            foreach (ShipAction::where('automation_run_id', $run->id)->where('state', 'pending')->get() as $action) {
                app(ShipCommandExecutor::class)->execute($action->id);
            }
            $this->travel(6)->seconds();
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        }
        $this->assertSame('completed', $run->fresh()->state->value, $run->fresh()->blocked_reason ?? '');
        $this->assertSame(2, ShipAction::where('automation_run_id', $run->id)->where('command', 'refuel')->where('state', 'confirmed')->count());
        $this->assertSame(1, ShipAction::where('automation_run_id', $run->id)->where('command', 'navigate')->where('state', 'confirmed')->count());
        $ship = Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
        $this->assertSame('X1-TEST-PORT', $ship['nav']['waypointSymbol']);
        $this->assertSame($ship['fuel']['capacity'], $ship['fuel']['current']);
    }

    public function test_scout_order_prefers_unpriced_markets_then_the_oldest_price(): void
    {
        $tasks = collect([
            ['ship' => 'P', 'destination' => 'A2', 'fact' => 'Stale prices'],
            ['ship' => 'P', 'destination' => 'B7', 'fact' => 'Stale prices'],
            ['ship' => 'P', 'destination' => 'C4', 'fact' => 'Unknown prices'],
            ['ship' => 'P', 'destination' => 'A3', 'fact' => 'Unknown prices'],
        ]);
        $markets = collect([
            ['symbol' => 'A2', 'price_at' => '2026-11-15T19:00:06+00:00'],
            ['symbol' => 'B7', 'price_at' => '2026-11-15T18:10:00+00:00'],
            ['symbol' => 'C4', 'price_at' => null],
            ['symbol' => 'A3', 'price_at' => null],
        ]);

        $this->assertSame(['A3', 'C4', 'B7', 'A2'], CampaignDirector::scoutOrder($tasks, $markets)->pluck('destination')->all());
    }

    public function test_the_probe_scouts_an_unpriced_market_one_way_instead_of_repricing_a_stale_one(): void
    {
        $this->tradeWorld();
        $this->editWorld(function (&$world) {
            foreach ([['X1-TEST-ALPHA', 6, 8], ['X1-TEST-ZULU', 9, 12]] as [$symbol, $x, $y]) {
                $world['waypoints'][] = ['symbol' => $symbol, 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => $x, 'y' => $y, 'traits' => [['symbol' => 'MARKETPLACE']]];
            }
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $sync = app(AgentSynchronizer::class);
        $directory = ['imports' => [['symbol' => 'IRON_ORE']], 'exports' => [], 'exchange' => [['symbol' => 'FUEL']]];
        // Markets come back in symbol order, so the stale market is listed before the unpriced one,
        // as on the live wipe where the probe looped on X1-NK60-A2 while A3 onward stayed unpriced.
        $this->travel(-10)->minutes();
        $sync->observe($this->agent, 'market_listing', 'X1-TEST-ALPHA', ['symbol' => 'X1-TEST-ALPHA'] + $directory);
        $sync->observe($this->agent, 'market', 'X1-TEST-ALPHA', ['symbol' => 'X1-TEST-ALPHA', 'tradeGoods' => [['symbol' => 'FUEL', 'tradeVolume' => 10, 'purchasePrice' => 5, 'sellPrice' => 3, 'supply' => 'MODERATE', 'type' => 'EXCHANGE']]] + $directory);
        $sync->observe($this->agent, 'market_listing', 'X1-TEST-ZULU', ['symbol' => 'X1-TEST-ZULU'] + $directory);
        $this->travel(10)->minutes();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        $this->approve();
        for ($step = 0; $step < 5 && ! AutomationRun::whereNotNull('active_agent_id')->exists(); $step++) {
            $this->tick();
        }
        $scout = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('scout', $scout->strategy);
        $this->assertSame('LOCAL-2', $scout->ship_symbol);
        $this->assertSame('X1-TEST-ZULU', $scout->plan['destination']);
        $this->assertTrue($scout->plan['one_way']);
    }

    public function test_a_blocked_probe_positioning_run_does_not_permanently_block_trade(): void
    {
        $this->tradeWorld();
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->editWorld(function (&$world) {
            $world['ships'][1]['nav']['waypointSymbol'] = 'X1-TEST-ROCK';
        });
        $body = Yaml::parse($this->body());
        $body['phases'] = array_slice($body['phases'], 1);
        $grant = $this->approve(Yaml::dump($body, 10));
        $this->tick();
        $probe = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('scout', $probe->strategy);
        $probe->update(['state' => 'blocked', 'blocked_reason' => 'Synthetic navigation failure.']);
        $this->tick();
        $trade = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('trade', $trade->strategy);
        $this->assertSame('LOCAL-1', $trade->ship_symbol);
        $this->assertNull($grant->fresh()->paused_at);
    }

    public function test_throttled_ticks_do_not_erase_consecutive_director_failures(): void
    {
        $grant = $this->approve();
        $this->partialMock(SpaceTradersApi::class)->shouldReceive('resetSchedule')->times(5)->andThrow(new \TypeError('Synthetic director failure.'));
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->artisan('spacetraders:dispatch-due')->assertSuccessful();
            $this->travel(5)->seconds();
            $this->artisan('spacetraders:dispatch-due')->assertSuccessful();
            $this->travel(26)->seconds();
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        }
        $this->assertSame('director_error', $grant->fresh()->pause_reason);
        $this->assertSame(5, CampaignEvent::where('kind', 'director_error')->count());
    }

    public function test_campaign_run_waits_for_malformed_get_state_without_pausing(): void
    {
        $grant = $this->tradeGrant();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->editWorld(function (&$world) {
            $world['ships'][0]['cooldown']['expiration'] = 'not-a-date';
        });
        app(AutomationRunner::class)->advance($run->id);
        $this->assertSame('waiting', $run->fresh()->state->value);
        $this->assertNull($grant->fresh()->paused_at);
        $this->assertSame([], json_decode(file_get_contents($this->file), true)['writes']);
    }

    private function body(): string
    {
        return str_replace('GIANTSOUP', 'LOCAL', file_get_contents(base_path('campaigns/weekly-default.yaml')));
    }

    /** The default playbook no longer has the mining fallback; mining tests restore it. */
    private function miningBody(): string
    {
        return str_replace('      - { role: probe, strategy: scout }
',
            '      - { role: probe, strategy: scout }
      - { role: command, strategy: mining, when: { minutes_without_trade_gte: 15 } }
', $this->body());
    }

    private function approve(?string $source = null): CampaignGrant
    {
        return app(CampaignControl::class)->approve(app(CampaignPlaybook::class)->import($source ?? $this->body()), $this->user);
    }

    private function tick(): void
    {
        app(CampaignDirector::class)->tick();
        $this->travel(31)->seconds();
        if (LocalEngine::find(1)?->state === 'running') {
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        } else {
            EngineFixture::healthy();
        }
    }

    private function editWorld(callable $edit): void
    {
        $world = json_decode(file_get_contents($this->file), true);
        $edit($world);
        file_put_contents($this->file, json_encode($world));
    }

    public function test_import_is_idempotent_and_revisions_and_grant_terms_are_immutable(): void
    {
        $revision = app(CampaignPlaybook::class)->import($this->body());
        $this->assertSame($revision->id, app(CampaignPlaybook::class)->import($this->body())->id);
        $this->assertNotSame($revision->id, app(CampaignPlaybook::class)->import($this->body()."\n# changed hypothesis\n")->id);
        $grant = app(CampaignControl::class)->approve($revision, $this->user);
        try {
            $grant->update(['limits' => []]);
            $this->fail('Grant terms must be immutable.');
        } catch (\LogicException) {
            $this->assertCount(1, CampaignGrant::all());
        }
        $this->expectException(\LogicException::class);
        $revision->update(['body' => []]);
    }

    public function test_final_policy_has_no_credit_floor_or_loss_cap(): void
    {
        $grant = $this->approve();
        $this->assertSame(0, $grant->limits['reserve_credits']);
        $this->assertArrayNotHasKey('episode_loss_cap', $grant->limits);
        $this->assertSame('next_leg', $grant->limits['fuel_reserve']);
        $this->assertArrayNotHasKey('adverse_drop', $grant->revision()->body['trade']['lot']);
    }

    public function test_fully_loaded_trade_can_depart_with_stale_source_and_buyer_quotes(): void
    {
        $this->tradeGrant();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $ship = Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
        $ship['cargo']['units'] = $run->plan['units'];
        $ship['cargo']['inventory'] = [['symbol' => $run->plan['symbol'], 'units' => $run->plan['units']]];
        $totals = array_replace($run->totals, ['purchased' => [$run->plan['symbol'] => $run->plan['units']], 'purchase_cost' => 90]);
        GameObservation::where('kind', 'market')->update(['observed_at' => now()->subHour()]);
        $step = app(OperationPlanner::class)->next($this->agent, $ship, $run->plan, $totals, now()->toImmutable());
        $this->assertSame('orbit', $step->command);
    }

    public function test_status_fails_for_paused_authority_or_stale_engine_heartbeat(): void
    {
        $grant = $this->approve();
        $this->artisan('campaign:status')->assertSuccessful();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()->subMinutes(6)]);
        $this->artisan('campaign:status')->assertFailed();
        EngineFixture::healthy();
        app(CampaignControl::class)->pause($grant, 'operator_pause');
        $this->artisan('campaign:status')->assertFailed();
    }

    private function completedLoad(CampaignEpisode $episode, bool $proof, int $net, float $prediction = 100, bool $forced = false, string $good = 'IRON_ORE'): AutomationRun
    {
        return AutomationRun::create(['campaign_episode_id' => $episode->id, 'agent_id' => $this->agent->id,
            'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-1', 'strategy' => 'trade', 'plan_version' => 3,
            'state' => 'completed', 'started_at' => now()->subMinute(), 'expires_at' => now()->addMinute(),
            'plan' => ['symbol' => $good, 'site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-PORT',
                'forced_sale' => $forced, 'campaign_trade' => ['proof' => $proof, 'predicted_margin' => $prediction]],
            'totals' => ['purchased' => [$good => 5], 'sold' => [$good => 5], 'purchase_cost' => 150,
                'sale_proceeds' => 150 + $net + 2, 'fuel_cost' => 2, 'credit_delta' => $net, 'unsold' => []]]);
    }

    public function test_prediction_accounts_for_bid_decay_source_inflation_and_fuel(): void
    {
        $prediction = app(CampaignTrade::class)->prediction(12,
            ['purchasePrice' => 10, 'tradeVolume' => 5], ['sellPrice' => 20, 'tradeVolume' => 4], 0.1, 2);
        $this->assertSame(['predicted_cost' => 136, 'predicted_revenue' => 216, 'predicted_margin' => 78], $prediction);
    }

    public function test_failed_proof_does_not_scale_and_passing_proof_scales_three_then_five_buyer_batches(): void
    {
        $this->tradeWorld();
        $this->editWorld(function (&$world) {
            $world['goods'][0]['tradeVolume'] = 10;
            $world['market_goods']['X1-TEST-PORT'][0]['tradeVolume'] = 5;
        });
        $grant = $this->approve();
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $trades = app(CampaignTrade::class);
        $considered = [];
        $ship = Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
        $rank = fn () => $trades->rank($grant, $episode, $this->agent->fresh(), $ship, $considered);
        $this->assertSame(5, $rank()[0]['units']);
        $this->completedLoad($episode, true, 79);
        $this->assertSame('proof', $trades->mode($episode, 'IRON_ORE', 'X1-TEST-PORT'));
        $this->assertSame(5, $rank()[0]['units']);
        $this->completedLoad($episode, true, 80);
        $this->assertSame('first_scaled', $trades->mode($episode, 'IRON_ORE', 'X1-TEST-PORT'));
        $this->assertSame(15, $rank()[0]['units']);
        $this->completedLoad($episode, false, 200);
        $this->assertSame('scaled', $trades->mode($episode, 'IRON_ORE', 'X1-TEST-PORT'));
        $this->assertSame(25, $rank()[0]['units']);
        $this->assertSame('proof', $trades->mode($episode, 'COPPER_ORE', 'X1-TEST-PORT'));
    }

    public function test_a_losing_load_requires_a_new_proof_and_two_losses_pause(): void
    {
        $grant = $this->approve();
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $this->completedLoad($episode, true, 100);
        $this->completedLoad($episode, false, -1);
        $trades = app(CampaignTrade::class);
        $this->assertSame('proof', $trades->mode($episode, 'IRON_ORE', 'X1-TEST-PORT'));
        $this->assertNull(app(CampaignSafety::class)->reason($grant, $episode, $this->agent));
        $this->completedLoad($episode, true, -5, 100, true);
        $this->tick();
        $this->assertSame('consecutive_losing_loads', $grant->fresh()->pause_reason);
        $this->artisan('campaign:resume')->assertSuccessful();
        $this->assertNull(app(CampaignSafety::class)->reason($grant->fresh(), $episode->fresh(), $this->agent));
    }

    public function test_a_quote_that_expires_during_loading_does_not_authorize_a_buy(): void
    {
        $this->tradeGrant();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        GameObservation::where('kind', 'market')->where('symbol', $run->plan['market'])->update(['observed_at' => now()->subSeconds(175)]);
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Freshness hold');
        app(CampaignTrade::class)->quoteLife($this->agent, $run->plan, $run->totals);
    }

    public function test_a_freshness_held_load_is_stopped_and_delivered_without_another_buy(): void
    {
        $this->tradeGrant(1000);
        $old = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        app(AutomationRunner::class)->advance($old->id);
        $buy = ShipAction::where('automation_run_id', $old->id)->where('command', 'buy')->firstOrFail();
        app(ShipCommandExecutor::class)->execute($buy->id);
        $this->assertSame('confirmed', $buy->fresh()->state->value);
        $old->refresh()->update(['state' => 'waiting', 'blocked_reason' => 'Freshness hold: buyer quote expired.', 'next_action_at' => now()->addMinutes(3)]);
        $this->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('stopped', $old->fresh()->state->value);
        $this->assertSame('sell_aboard', $run->strategy);
        $this->assertSame($old->id, $run->plan['recovery_of']);
        for ($step = 0; $step < 30 && $run->fresh()->active_agent_id; $step++) {
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
            app(AutomationRunner::class)->advance($run->id);
            foreach (ShipAction::where('automation_run_id', $run->id)->where('state', 'pending')->get() as $action) {
                app(ShipCommandExecutor::class)->execute($action->id);
            }
            $this->travel(6)->seconds();
        }
        $this->assertSame('completed', $run->fresh()->state->value, $run->fresh()->blocked_reason ?? '');
        $this->assertSame(0, Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot['cargo']['units']);
        $this->assertSame(1, ShipAction::where('command', 'buy')->count());
        $this->assertCount(1, app(CampaignTrade::class)->results(CampaignEpisode::firstOrFail()));
    }

    public function test_buy_batch_is_reduced_or_skipped_to_preserve_delivery_fuel(): void
    {
        $grant = $this->tradeGrant(1000);
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $ship = Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
        $trades = app(CampaignTrade::class);
        $fuel = $trades->fuelReserve($this->agent, 'LOCAL-1', $run->plan['market']);
        $this->agent->credits = $fuel + 65;
        $step = app(OperationPlanner::class)->next($this->agent, $ship, $run->plan, $run->totals, now()->toImmutable());
        $this->assertSame('buy', $step->command);
        $this->assertSame(2, $step->arguments['units']);
        $this->assertSame($fuel, $step->arguments['reserve']);
        $this->agent->credits = $fuel + 29;
        $step = app(OperationPlanner::class)->next($this->agent, $ship, $run->plan, $run->totals, now()->toImmutable());
        $this->assertSame('blocked', $step->kind);
        $this->assertNull($grant->fresh()->paused_at);
    }

    public function test_switches_only_at_the_configured_improvement_or_bid_drop(): void
    {
        $this->tradeWorld();
        $grant = $this->approve();
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $ship = Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
        $trades = app(CampaignTrade::class);
        $considered = [];
        $rank = $trades->rank($grant, $episode, $this->agent, $ship, $considered);
        $this->assertSame('IRON_ORE', $rank[0]['good']);
        $state = $episode->fresh()->state;
        $state['current_pair'] = $rank[0]['key'];
        $episode->update(['state' => $state]);
        $quote = GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-PORT')->firstOrFail();
        $data = $quote->data;
        $data['tradeGoods'][1]['sellPrice'] = 212;
        $quote->update(['data' => $data]);
        $rank = $trades->rank($grant, $episode, $this->agent, $ship, $considered);
        $this->assertSame('IRON_ORE', $rank[0]['good'], 'A small improvement must not churn the current pair.');
        $data['tradeGoods'][1]['sellPrice'] = 300;
        $quote->update(['data' => $data]);
        $rank = $trades->rank($grant, $episode, $this->agent, $ship, $considered);
        $this->assertSame('COPPER_ORE', $rank[0]['good']);
        $data['tradeGoods'][0]['sellPrice'] = 180;
        $data['tradeGoods'][1]['sellPrice'] = 200;
        $quote->update(['data' => $data]);
        $rank = $trades->rank($grant, $episode, $this->agent, $ship, $considered);
        $this->assertSame('COPPER_ORE', $rank[0]['good']);
        $this->assertTrue(collect($considered)->contains(fn ($row) => str_contains($row['rejected'] ?? '', 'first-seen')));
    }

    public function test_two_losing_loads_sell_aboard_before_pausing(): void
    {
        $grant = $this->approve();
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $this->completedLoad($episode, true, -1);
        $this->completedLoad($episode, true, -1);
        $this->editWorld(function (&$world) {
            $world['ships'][0]['cargo']['units'] = 3;
            $world['ships'][0]['cargo']['inventory'] = [['symbol' => 'IRON_ORE', 'units' => 3]];
        });
        $this->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('sell_aboard', $run->strategy);
        $this->assertNull($grant->fresh()->paused_at);
        for ($step = 0; $step < 10 && $run->fresh()->active_agent_id; $step++) {
            app(AutomationRunner::class)->advance($run->id);
            foreach (ShipAction::where('automation_run_id', $run->id)->where('state', 'pending')->get() as $action) {
                app(ShipCommandExecutor::class)->execute($action->id);
            }
            $this->travel(6)->seconds();
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        }
        $this->tick();
        $this->assertSame('consecutive_losing_loads', $grant->fresh()->pause_reason);
        $this->assertSame(0, Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot['cargo']['units']);
        $this->assertDatabaseMissing('ship_actions', ['command' => 'buy']);
    }

    public function test_recovery_holds_break_even_then_forces_a_sale_after_ten_minutes(): void
    {
        $this->tradeGrant(100);
        $old = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        app(AutomationRunner::class)->advance($old->id);
        $buy = ShipAction::where('automation_run_id', $old->id)->where('command', 'buy')->firstOrFail();
        app(ShipCommandExecutor::class)->execute($buy->id);
        $old->refresh()->update(['state' => 'blocked', 'blocked_reason' => 'Spread deteriorated.']);
        $this->editWorld(function (&$world) {
            $world['goods'][0]['sellPrice'] = 1;
            $world['market_goods']['X1-TEST-PORT'][0]['sellPrice'] = 1;
        });
        $this->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame(30, $run->plan['sale_floor']);
        $ship = Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
        $ship['nav']['waypointSymbol'] = $run->plan['market'];
        $ship['nav']['status'] = 'DOCKED';
        $sync = app(AgentSynchronizer::class);
        $sync->observe($this->agent, 'market', $run->plan['market'], app(SpaceTradersApi::class)->market($this->agent, $run->plan['market']));
        $this->assertSame('wait', app(CampaignRecovery::class)->next($this->agent, $ship, $run)->kind);
        $this->travel(10)->minutes();
        $sync->observe($this->agent, 'market', $run->plan['market'], app(SpaceTradersApi::class)->market($this->agent, $run->plan['market']));
        $step = app(CampaignRecovery::class)->next($this->agent, $ship, $run->fresh());
        $this->assertSame('sell', $step->command);
        $this->assertSame(3, $step->arguments['minTotal']);
        $this->assertTrue($run->fresh()->plan['forced_sale']);
    }

    public function test_decay_is_learned_only_from_this_episodes_equal_sized_sales_at_the_buyer(): void
    {
        $this->approve();
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $run = $this->completedLoad($episode, true, 100);
        foreach ([100, 90] as $index => $price) {
            ShipAction::create(['agent_id' => $this->agent->id, 'automation_run_id' => $run->id, 'ship_symbol' => 'LOCAL-1',
                'key' => 'sale-decay-'.$index, 'reset_id' => $this->agent->reset_id, 'command' => 'sell', 'state' => 'confirmed', 'origin' => 'automation',
                'expires_at' => now()->addMinute(), 'arguments' => ['symbol' => 'IRON_ORE', 'units' => 5],
                'before_state' => ['ship' => ['nav' => ['waypointSymbol' => 'X1-TEST-PORT']]],
                'outcome' => ['units' => 5, 'transaction_total' => $price]]);
        }
        $trades = app(CampaignTrade::class);
        $this->assertEqualsWithDelta(0.1, $trades->decay($episode, 'IRON_ORE', 'X1-TEST-PORT'), 0.000001);
        $this->assertSame(0.04, $trades->decay($episode, 'IRON_ORE', 'X1-TEST-ROCK'));
        $this->assertSame(0.04, $trades->decay($episode, 'COPPER_ORE', 'X1-TEST-PORT'));
    }

    public function test_zero_capacity_probe_parking_is_one_way_and_precedes_a_fresh_trade(): void
    {
        $this->tradeWorld();
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->editWorld(function (&$world) {
            $world['ships'][1]['nav']['waypointSymbol'] = 'X1-TEST-ROCK';
            $world['ships'][1]['fuel'] = ['capacity' => 0, 'current' => 0];
            $world['ships'][1]['frame']['symbol'] = 'FRAME_PROBE';
            $world['ships'][1]['frame']['fuelCapacity'] = 0;
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $body = Yaml::parse($this->body());
        $body['phases'] = array_slice($body['phases'], 1);
        $grant = $this->approve(Yaml::dump($body, 10));
        $this->tick();
        $this->assertTrue(AutomationRun::whereNotNull('active_agent_id')->exists(), json_encode(CampaignEvent::all()->toArray()).json_encode($grant->fresh()->toArray()));
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('LOCAL-2', $run->ship_symbol);
        $this->assertTrue($run->plan['one_way']);
        for ($step = 0; $step < 20 && $run->fresh()->active_agent_id; $step++) {
            app(AutomationRunner::class)->advance($run->id);
            foreach (ShipAction::where('automation_run_id', $run->id)->where('state', 'pending')->get() as $action) {
                app(ShipCommandExecutor::class)->execute($action->id);
            }
            $this->travel(6)->seconds();
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        }
        $this->assertSame('completed', $run->fresh()->state->value, $run->fresh()->blocked_reason ?? '');
        $this->assertSame('X1-TEST-PORT', Ship::where('symbol', 'LOCAL-2')->firstOrFail()->snapshot['nav']['waypointSymbol']);
        $this->assertSame(1, ShipAction::where('automation_run_id', $run->id)->where('command', 'navigate')->count());
        $this->tick();
        $trade = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('trade', $trade->strategy);
        $this->assertSame('LOCAL-1', $trade->ship_symbol);
    }

    public function test_expiring_remote_quote_rejects_a_pending_buy_without_pausing_or_sending(): void
    {
        $grant = $this->tradeGrant();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        app(AutomationRunner::class)->advance($run->id);
        $buy = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy')->firstOrFail();
        $this->editWorld(function (&$world) {
            $world['ships'][1]['nav']['waypointSymbol'] = 'X1-TEST-ROCK';
        });
        app(AgentSynchronizer::class)->sync($this->agent, false);
        GameObservation::where('kind', 'market')->where('symbol', $run->plan['market'])->update(['observed_at' => now()->subSeconds(175)]);
        app(ShipCommandExecutor::class)->execute($buy->id);
        $this->assertSame('rejected', $buy->fresh()->state->value);
        $this->assertStringContainsString('Freshness hold', $buy->fresh()->message);
        $this->assertNull($grant->fresh()->paused_at);
        $this->assertSame([], json_decode(file_get_contents($this->file), true)['writes']);
    }

    public function test_recovery_reselects_a_new_qualifying_buyer_before_the_fallback(): void
    {
        $this->tradeGrant(100);
        $old = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        app(AutomationRunner::class)->advance($old->id);
        $buy = ShipAction::where('automation_run_id', $old->id)->where('command', 'buy')->firstOrFail();
        app(ShipCommandExecutor::class)->execute($buy->id);
        $old->refresh()->update(['state' => 'blocked', 'blocked_reason' => 'Spread deteriorated.']);
        $this->editWorld(function (&$world) {
            $world['goods'][0]['sellPrice'] = 1;
            $world['market_goods']['X1-TEST-PORT'][0]['sellPrice'] = 1;
        });
        $this->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $ship = Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
        $ship['nav']['waypointSymbol'] = $run->plan['market'];
        $ship['nav']['status'] = 'DOCKED';
        $this->travel(3)->minutes();
        $other = GameObservation::where('kind', 'market')->where('symbol', '!=', $run->plan['market'])->firstOrFail();
        $data = $other->data;
        $data['tradeGoods'][0]['sellPrice'] = 35;
        unset($data['invalidated_by_action']);
        $other->update(['data' => $data, 'observed_at' => now()]);
        app(AgentSynchronizer::class)->observe($this->agent, 'market', $run->plan['market'], app(SpaceTradersApi::class)->market($this->agent, $run->plan['market']));
        $step = app(CampaignRecovery::class)->next($this->agent, $ship, $run);
        $this->assertSame('blocked', $step->kind);
        $this->assertStringContainsString('better reachable buyer', $step->reason);
        $this->assertSame(now()->toIso8601String(), $run->fresh()->plan['recovery_started_at']);
        $this->assertEmpty($run->fresh()->plan['forced_sale'] ?? null);
    }

    public function test_audited_schema_imports_trade_rules_and_sell_aboard(): void
    {
        $revision = app(CampaignPlaybook::class)->import($this->body());
        $this->assertSame(15, $revision->body['limits']['run_max_minutes']);
        $this->assertSame(0.8, $revision->body['trade']['proof']['min_realized_ratio']);
        $this->assertSame('sell_aboard', $revision->body['phases'][2]['activities'][0]['strategy']);
    }

    public static function invalidPlaybooks(): array
    {
        return [['version: 1', 'version: 2'], ['reserve_credits: 0', 'reserve_credits: -1'],
            ['strategy: mining', 'strategy: contracts'], ['role: command', 'role: LOCAL-1'],
            ['hours_to_reset_lte: 1', 'unknown_gate: 1'], ['faction: VOID', 'faction: NOT_REAL'],
            ['version: 1', "version: 1\nunknown: true"],
            ['reserve_credits: 0', "reserve_credits: 0\n  ship_purchase: true"],
            ['minutes_without_trade_gte: 15', 'conservative_margin_gte: 1'], ['first_scaled_max_buyer_batches: 3', 'adverse_drop: 0.3']];
    }

    #[DataProvider('invalidPlaybooks')]
    public function test_invalid_playbooks_fail_closed(string $from, string $to): void
    {
        $this->expectException(GameException::class);
        app(CampaignPlaybook::class)->import(str_replace($from, $to, $this->miningBody()));
    }

    public function test_approval_supersedes_only_between_runs_and_revoke_stops_owned_work(): void
    {
        $first = $this->approve();
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $run = AutomationRun::create(['campaign_episode_id' => $episode->id, 'agent_id' => $this->agent->id, 'active_agent_id' => $this->agent->id,
            'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-1', 'strategy' => 'mining', 'state' => 'running', 'expires_at' => now()->addHour(), 'plan' => [], 'totals' => []]);
        $second = $this->approve($this->body()."\n# revision two\n");
        $this->assertSame(1, $first->fresh()->slot);
        $this->assertSame(2, $second->slot);
        $run->update(['active_agent_id' => null, 'state' => 'stopped']);
        $this->tick();
        $this->assertNotNull($first->fresh()->superseded_at);
        $this->assertSame(1, $second->fresh()->slot);
        app(CampaignControl::class)->revoke();
        $this->assertNotNull($second->fresh()->revoked_at);
        $this->assertNull($second->fresh()->slot);
    }

    public function test_engine_closed_waits_and_running_engine_resumes_with_fresh_previews(): void
    {
        $grant = $this->approve();
        app(EngineGate::class)->close('off');
        $this->tick();
        $this->assertSame('awaiting_engine', $grant->fresh()->wait_reason);
        $this->assertSame(0, CampaignEpisode::count());
        $this->tick();
        $this->assertSame(1, CampaignEpisode::count());
        $this->assertNull($grant->fresh()->paused_at);
        $this->assertSame([], json_decode(file_get_contents($this->file), true)['writes']);
    }

    public function test_confirmed_reset_registers_once_and_reuses_latest_agent_email(): void
    {
        $this->agent->update(['email' => $this->user->email]);
        $this->approve();
        $this->tick();
        $this->editWorld(function (&$world) {
            $world['reset'] = now()->subHour()->toIso8601String();
            $world['campaign_registration'] = true;
        });
        $this->tick();
        $this->assertSame(2, CampaignEpisode::count());
        $this->assertNotNull(CampaignEpisode::oldest('id')->first()->closed_at);
        $this->assertSame($this->user->email, Agent::latest('id')->first()->email);
        $world = json_decode(file_get_contents($this->file), true);
        $this->assertCount(1, array_filter($world['writes'], fn ($call) => $call['path'] === '/register'));
        $this->tick();
        $this->assertSame(2, Agent::count());
    }

    public function test_a_manually_registered_new_reset_closes_the_previous_episode_without_registering_again(): void
    {
        $grant = $this->approve();
        $this->tick();
        $previous = CampaignEpisode::firstOrFail();
        $reset = now()->subHour()->toIso8601String();
        $current = $this->user->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic-new-world', 'faction' => 'VOID',
            'reset_id' => $reset, 'canonical_identity' => AgentSynchronizer::identity($reset, 'LOCAL')]);
        $this->editWorld(function (&$world) use ($reset) {
            $world['reset'] = $reset;
        });

        $this->tick();

        $this->assertNotNull($previous->fresh()->closed_at);
        $this->assertSame('reset', $this->agent->fresh()->connection_state);
        $this->assertSame($current->id, CampaignEpisode::whereNull('closed_at')->sole()->agent_id);
        $this->assertNull($grant->fresh()->paused_at);
        $this->assertSame([], json_decode(file_get_contents($this->file), true)['writes']);
    }

    public function test_unknown_or_overdue_unconfirmed_reset_never_registers(): void
    {
        $grant = $this->approve();
        $this->editWorld(function (&$world) {
            unset($world['next_reset']);
        });
        $this->tick();
        $this->assertSame('awaiting_reset_schedule', $grant->fresh()->wait_reason);
        $this->editWorld(function (&$world) {
            $world['next_reset'] = now()->subHour()->toIso8601String();
        });
        $this->tick();
        $this->assertSame(0, CampaignEpisode::count());
    }

    public static function safetyFailures(): array
    {
        return [['uncertain_action'], ['unexplained_change']];
    }

    #[DataProvider('safetyFailures')]
    public function test_safety_failures_pause_the_whole_campaign(string $reason): void
    {
        $grant = $this->approve();
        $this->tick();
        if ($reason === 'uncertain_action') {
            ShipAction::create(['agent_id' => $this->agent->id, 'key' => 'unknown', 'ship_symbol' => 'LOCAL-1', 'reset_id' => $this->agent->reset_id,
                'command' => 'mine', 'origin' => 'manual', 'state' => 'uncertain', 'expires_at' => now()->addMinute(), 'arguments' => [], 'before_state' => [], 'outcome' => []]);
        } else {
            $this->editWorld(function (&$world) {
                $world['credits'] = 99999;
            });
        }
        $this->tick();
        $this->assertNotNull($grant->fresh()->paused_at);
        $this->assertSame($reason, $grant->fresh()->pause_reason);
        $this->assertDatabaseHas('campaign_events', ['kind' => 'pause']);
    }

    public function test_phase_gates_and_failed_trade_fall_back_to_investigated_mining(): void
    {
        $grant = $this->approve($this->miningBody());
        $this->tick();
        CampaignEpisode::first()->update(['opened_at' => now()->subMinutes(91)]);
        for ($attempt = 0; $attempt < 12 && ! AutomationRun::whereNotNull('active_agent_id')->exists(); $attempt++) {
            $this->tick();
        }
        $this->assertSame('earn', CampaignEpisode::first()->phase);
        $run = AutomationRun::whereNotNull('active_agent_id')->first();
        $this->assertNotNull($run, json_encode(CampaignEvent::where('kind', 'decision')->latest('id')->first()?->data));
        $this->assertSame('mining', $run->strategy);
        $this->assertSame(2, $run->plan_version);
        $this->assertGreaterThanOrEqual($run->plan['route']['fuel_ceiling'], $run->plan['reserve']);
        $this->assertNull($grant->fresh()->paused_at);
        $this->assertTrue(CampaignEvent::where('kind', 'decision')->get()->contains(fn ($event) => str_contains(json_encode($event->data), 'trade')));
    }

    public function test_wind_down_blocks_trade_and_report_outputs_both_formats(): void
    {
        $this->approve();
        $this->tick();
        $episode = CampaignEpisode::first();
        $episode->update(['phase' => 'earn']);
        $this->travel(16)->hours();
        EngineFixture::healthy();
        $this->tick();
        $this->assertSame('wind_down', $episode->fresh()->phase);
        $report = app(CampaignReport::class)->build($episode->fresh());
        $this->assertArrayHasKey('predictions', $report['json']);
        $this->assertStringContainsString('Credits over time', $report['markdown']);
        $this->artisan('campaign:status')->expectsOutputToContain('wind_down')->assertSuccessful();
        $this->artisan('campaign:report', ['episode' => $episode->id])->expectsOutputToContain('Markdown:')->assertSuccessful();
    }

    public function test_leftover_cargo_creates_sell_only_recovery(): void
    {
        $grant = $this->approve();
        $this->tick();
        $this->editWorld(function (&$world) {
            $world['ships'][0]['cargo']['units'] = 3;
            $world['ships'][0]['cargo']['inventory'] = [['symbol' => 'IRON_ORE', 'units' => 3]];
        });
        $this->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertTrue($run->plan['sell_only']);
        $this->assertNull($grant->fresh()->paused_at);
        app(AutomationRunner::class)->advance($run->id);
        $this->assertNull($run->fresh()->blocked_reason, json_encode($run->fresh()->totals));
        $this->assertDatabaseHas('ship_actions', ['automation_run_id' => $run->id, 'command' => 'sell']);
        $this->assertDatabaseMissing('ship_actions', ['automation_run_id' => $run->id, 'command' => 'buy']);
    }

    #[DataProvider('navigationWaypointCache')]
    public function test_automated_navigation_refreshes_only_missing_or_stale_waypoints(?int $ageSeconds): void
    {
        $this->tradeGrant();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        for ($step = 0; $step < 10; $step++) {
            app(AutomationRunner::class)->advance($run->id);
            $action = ShipAction::where('automation_run_id', $run->id)->where('state', 'pending')->firstOrFail();
            if ($action->command === 'navigate') {
                break;
            }
            app(ShipCommandExecutor::class)->execute($action->id);
            $this->travel(6)->seconds();
        }
        $this->assertSame('navigate', $action->command);
        $symbol = $action->arguments['waypointSymbol'];
        $point = GameObservation::where('agent_id', $this->agent->id)->where('kind', 'waypoint')->where('symbol', $symbol)->firstOrFail();
        $observedAt = now()->subSeconds($ageSeconds ?? 0);
        if ($ageSeconds === null) {
            $point->delete();
        } else {
            $point->update(['observed_at' => $observedAt]);
        }
        $callsBefore = count(json_decode(file_get_contents($this->file), true)['calls']);

        app(ShipCommandExecutor::class)->execute($action->id);

        $this->assertSame('confirmed', $action->fresh()->state->value, json_encode($action->fresh()->outcome));
        $calls = array_slice(json_decode(file_get_contents($this->file), true)['calls'], $callsBefore);
        $reads = array_filter($calls, fn ($call) => $call['method'] === 'GET' && $call['path'] === '/systems/X1-TEST/waypoints/'.$symbol);
        $this->assertCount($ageSeconds === null || $ageSeconds > 3600 ? 1 : 0, $reads);
        $saved = GameObservation::where('agent_id', $this->agent->id)->where('kind', 'waypoint')->where('symbol', $symbol)->firstOrFail();
        $this->assertSame(($ageSeconds === null || $ageSeconds > 3600 ? now() : $observedAt)->toDateTimeString(), $saved->observed_at->toDateTimeString());
    }

    public static function navigationWaypointCache(): array
    {
        return ['stale' => [3601], 'fresh' => [60], 'missing' => [null]];
    }

    #[DataProvider('recoveryAttemptHistory')]
    public function test_recovery_pauses_only_after_consecutive_fruitless_attempts(array $history, int $limit, bool $pauses): void
    {
        config(['gameplay.campaign_recovery_attempts' => $limit]);
        $grant = $this->approve();
        $this->tick();
        $this->editWorld(function (&$world) {
            $world['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 3, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 3]]];
        });
        $this->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('sell_aboard', $run->strategy);
        $episode = CampaignEpisode::findOrFail($run->campaign_episode_id);
        $window = ['LOCAL-1' => now()->subMinutes(10)->toIso8601String()];
        $episode->update(['state' => array_merge($episode->state, ['recovery_since' => $window, 'recovery_attempts_since' => $window])]);
        foreach ($history as $index => $attempt) {
            $past = $index === 0 ? $run : $run->replicate();
            $past->fill(['active_agent_id' => null, 'state' => $attempt['state'] ?? 'stopped',
                'ship_symbol' => $attempt['ship_symbol'] ?? 'LOCAL-1',
                'campaign_episode_id' => array_key_exists('campaign_episode_id', $attempt) ? $attempt['campaign_episode_id'] : $episode->id,
                'started_at' => ! empty($attempt['before_window']) ? now()->subMinutes(11) : now(),
                'totals' => array_merge($run->totals, ['sale_proceeds' => $attempt['sale_proceeds'] ?? 0, 'credit_delta' => $attempt['credit_delta'] ?? 0])])->save();
        }
        $count = AutomationRun::count();

        $this->tick();

        $this->assertSame($pauses ? 'recovery_stalled' : null, $grant->fresh()->pause_reason);
        $this->assertSame($count + ($pauses ? 0 : 1), AutomationRun::count());
        $this->assertSame(! $pauses, AutomationRun::whereNotNull('active_agent_id')->exists());
        if ($pauses) {
            $this->assertSame('recovery_stalled', app(CampaignStatus::class)->read()['grant']['pause_reason']);
            $this->assertArrayHasKey('campaign_paused:'.$grant->id.':recovery_stalled', app(CampaignHealth::class)->problems());
        } else {
            $this->assertSame('sell_aboard', AutomationRun::whereNotNull('active_agent_id')->firstOrFail()->strategy);
        }
    }

    public function test_recovery_attempts_count_from_their_own_marker_and_resume_starts_a_fresh_window(): void
    {
        $grant = $this->approve();
        $this->tick();
        $this->editWorld(function (&$world) {
            $world['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 3, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 3]]];
        });
        $this->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $episode = CampaignEpisode::findOrFail($run->campaign_episode_id);
        // Fruitless runs recorded before this code ran: recovery_since is old, but there is no attempts marker yet.
        $state = $episode->fresh()->state;
        $state['recovery_since'] = ['LOCAL-1' => now()->subHours(2)->toIso8601String()];
        unset($state['recovery_attempts_since']);
        $episode->update(['state' => $state]);
        $run->fill(['active_agent_id' => null, 'state' => 'stopped', 'started_at' => now()->subHour()])->save();
        foreach (range(1, 6) as $minutes) {
            $run->replicate()->fill(['started_at' => now()->subMinutes($minutes)])->save();
        }

        $this->tick();

        $this->assertNull($grant->fresh()->pause_reason, 'Earlier fruitless runs must not pause the first tick after deploy.');
        $this->assertSame('sell_aboard', AutomationRun::whereNotNull('active_agent_id')->firstOrFail()->strategy);

        AutomationRun::whereNotNull('active_agent_id')->update(['active_agent_id' => null, 'state' => 'stopped']);
        $since = $episode->fresh()->state['recovery_attempts_since']['LOCAL-1'];
        foreach (range(1, 5) as $ignored) {
            $run->replicate()->fill(['started_at' => $since])->save();
        }
        $this->tick();
        $this->assertSame('recovery_stalled', $grant->fresh()->pause_reason);

        $this->travel(5)->seconds();
        app(CampaignControl::class)->resume();
        $this->assertArrayNotHasKey('recovery_attempts_since', $episode->fresh()->state);
        $this->tick();

        $this->assertNull($grant->fresh()->pause_reason, 'An operator resume gives recovery a fresh window.');
        $this->assertSame('sell_aboard', AutomationRun::whereNotNull('active_agent_id')->firstOrFail()->strategy);
    }

    public static function recoveryAttemptHistory(): array
    {
        $four = array_fill(0, 4, []);

        return [
            'five failures pause' => [[...$four, []], 5, true],
            'four failures start the fifth' => [$four, 5, false],
            'blocked failures count' => [array_fill(0, 5, ['state' => 'blocked']), 5, true],
            'sale with zero net resets streak' => [[...$four, ['sale_proceeds' => 10], ...$four], 5, false],
            'positive credit delta resets streak' => [[...$four, ['credit_delta' => 10], ...$four], 5, false],
            'completed run resets streak' => [[...$four, ['state' => 'completed'], ...$four], 5, false],
            'older recovery window excluded' => [[['before_window' => true], ...$four], 5, false],
            'other ship excluded' => [[['ship_symbol' => 'LOCAL-2'], ...$four], 5, false],
            'other episode excluded' => [[['campaign_episode_id' => null], ...$four], 5, false],
            'configured limit respected' => [array_fill(0, 2, []), 2, true],
            'zero limit treated as one' => [[['state' => 'completed']], 0, false],
        ];
    }

    private function tradeWorld(): void
    {
        $this->editWorld(function (&$world) {
            $probe = FakeSpaceTraders::ship('LOCAL-2', 'X1-TEST-PORT');
            $probe['registration']['role'] = 'SATELLITE';
            $probe['frame']['symbol'] = 'FRAME_PROBE';
            $world['ships'] = array_values(array_filter($world['ships'], fn ($ship) => $ship['symbol'] !== 'LOCAL-2'));
            $world['ships'][] = $probe;
            $world['market_goods']['X1-TEST-PORT'] = $world['goods'];
            $world['market_goods']['X1-TEST-PORT'][0]['sellPrice'] = 200;
        });
    }

    private function tradeGrant(int $exposure = 100): CampaignGrant
    {
        $this->tradeWorld();
        $grant = $this->approve(str_replace('max_credits: 60000', 'max_credits: '.$exposure, $this->body()));
        $this->tick();
        CampaignEpisode::firstOrFail()->update(['phase' => 'earn']);
        $this->tick();

        return $grant;
    }

    public function test_trade_refreshes_stale_quotes_sizes_to_the_grant_and_reselects_goods(): void
    {
        $this->tradeGrant();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('trade', $run->strategy);
        $this->assertSame(3, $run->plan['units']);
        $this->assertSame(100, $run->plan['max_inventory']);
        $this->assertSame('IRON_ORE', $run->plan['symbol']);
        $this->assertLessThanOrEqual(100, $run->plan['route']['inventory_exposure']);
        $run->update(['state' => 'stopped', 'active_agent_id' => null]);
        GameObservation::where('kind', 'market')->update(['observed_at' => now()->subHour()]);
        $this->editWorld(function (&$world) {
            $world['market_goods']['X1-TEST-PORT'][0]['sellPrice'] = 1;
            $world['market_goods']['X1-TEST-PORT'][1]['sellPrice'] = 300;
        });
        $this->tick();
        $next = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertNotSame($run->id, $next->id);
        $this->assertSame('COPPER_ORE', $next->plan['symbol']);
        $this->assertSame(2, $next->plan['units']);
        $this->assertSame('X1-TEST-PORT', $next->plan['market']);
        $this->assertTrue(GameObservation::where('kind', 'market')->get()->every(fn ($quote) => $quote->observed_at->gt(now()->subMinutes(3))));
    }

    public function test_mining_waits_for_fifteen_minutes_without_a_feasible_trade(): void
    {
        $this->approve($this->miningBody());
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $episode->update(['phase' => 'earn']);
        $this->tick();
        $this->assertFalse(AutomationRun::whereNotNull('active_agent_id')->exists());
        $episode->update(['opened_at' => now()->subMinutes(16)]);
        for ($tick = 0; $tick < 12 && ! AutomationRun::whereNotNull('active_agent_id')->exists(); $tick++) {
            $this->tick();
        }
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('mining', $run->strategy);
    }

    public function test_pause_resume_and_revoke_invalidate_authority_before_a_pending_buy(): void
    {
        $grant = $this->tradeGrant();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        app(AutomationRunner::class)->advance($run->id);
        $action = ShipAction::where('automation_run_id', $run->id)->firstOrFail();
        $this->assertSame('buy', $action->command);
        $this->artisan('campaign:pause')->assertSuccessful();
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame('rejected', $action->fresh()->state->value);
        $this->assertSame([], json_decode(file_get_contents($this->file), true)['writes']);
        $this->artisan('campaign:resume')->assertSuccessful();
        $this->assertNull($grant->fresh()->paused_at);
        $this->assertNotNull($run->fresh()->stop_requested_at);
        $this->tick();
        $this->tick();
        $next = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertNotSame($run->id, $next->id);
        app(CampaignControl::class)->revoke();
        $this->assertNotNull($next->fresh()->stop_requested_at);
    }

    public function test_wind_down_rejects_a_pending_buy_without_sending_it(): void
    {
        $this->tradeGrant();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        app(AutomationRunner::class)->advance($run->id);
        $action = ShipAction::where('automation_run_id', $run->id)->firstOrFail();
        $this->travel(16)->hours();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        $action->update(['expires_at' => now()->addMinute()]);
        $run->update(['expires_at' => now()->addHour()]);
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame('rejected', $action->fresh()->state->value);
        $this->assertStringContainsString('Wind-down', $action->fresh()->message);
        $this->assertSame([], json_decode(file_get_contents($this->file), true)['writes']);
    }

    public function test_engine_restart_never_replays_an_old_preview_or_pending_action(): void
    {
        $grant = $this->tradeGrant();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        app(AutomationRunner::class)->advance($run->id);
        $oldAction = ShipAction::where('automation_run_id', $run->id)->firstOrFail();
        app(EngineGate::class)->close('off');
        $this->tick();
        $this->tick();
        $this->tick();
        $next = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertNotSame($run->id, $next->id);
        $this->assertSame('stopped', $run->fresh()->state->value);
        $this->assertSame('rejected', $oldAction->fresh()->state->value);
        app(ShipCommandExecutor::class)->execute($oldAction->id);
        $this->assertSame([], json_decode(file_get_contents($this->file), true)['writes']);
        $this->assertNull($grant->fresh()->paused_at);
    }

    public function test_registration_ambiguity_waits_then_retries_once_and_conflict_pauses(): void
    {
        $grant = $this->approve();
        $this->tick();
        $this->editWorld(function (&$world) {
            $world['reset'] = now()->subHour()->toIso8601String();
            $world['campaign_registration'] = true;
            $world['failure'] = 'timeout_after';
        });
        $this->tick();
        $this->assertNull($grant->fresh()->paused_at);
        $this->assertSame('awaiting_registration', $grant->fresh()->wait_reason);
        $this->tick();
        $this->assertNull($grant->fresh()->paused_at);
        $this->editWorld(function (&$world) {
            $world['failure'] = 'timeout_after';
        });
        $this->tick();
        $this->assertSame('registration_unknown', $grant->fresh()->pause_reason);
        $world = json_decode(file_get_contents($this->file), true);
        $this->assertCount(2, array_filter($world['writes'], fn ($call) => $call['path'] === '/register'));
    }

    public function test_cargo_without_known_buyer_pauses_and_preserves_inventory(): void
    {
        $grant = $this->approve();
        $this->tick();
        $this->editWorld(function (&$world) {
            $world['ships'][0]['cargo']['units'] = 1;
            $world['ships'][0]['cargo']['inventory'] = [['symbol' => 'UNKNOWN_GOOD', 'units' => 1]];
        });
        $this->tick();
        $this->assertSame('no_cargo_buyer', $grant->fresh()->pause_reason);
        $this->assertSame([], json_decode(file_get_contents($this->file), true)['writes']);
        $this->assertArrayNotHasKey('recovery_since', CampaignEpisode::firstOrFail()->state ?? [], 'The sale-floor clock starts only once a buyer is known.');
    }

    public function test_director_throttle_is_durable_and_status_does_not_make_requests(): void
    {
        $this->approve();
        app(CampaignDirector::class)->tick();
        $count = CampaignEvent::count();
        app(CampaignDirector::class)->tick();
        $this->assertSame($count, CampaignEvent::count());
        $calls = json_decode(file_get_contents($this->file), true)['calls'];
        $this->artisan('campaign:status')->assertSuccessful();
        $this->assertSame($calls, json_decode(file_get_contents($this->file), true)['calls']);
    }

    public function test_a_complete_trade_uses_confirmed_receipts_and_report_totals(): void
    {
        $this->tradeGrant(1000);
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        for ($step = 0; $step < 100 && $run->fresh()->active_agent_id; $step++) {
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
            app(AutomationRunner::class)->advance($run->id);
            foreach (ShipAction::where('automation_run_id', $run->id)->where('state', 'pending')->get() as $action) {
                app(ShipCommandExecutor::class)->execute($action->id);
            }
            $this->travel(6)->seconds();
        }
        $run->refresh();
        $this->assertSame('completed', $run->state->value, $run->blocked_reason ?? json_encode($run->totals));
        $this->assertGreaterThan(0, $run->totals['cash_contribution']);
        $this->assertSame([], $run->totals['unsold']);
        $this->assertSame(1, $run->totals['cycles']);
        $report = app(CampaignReport::class)->build(CampaignEpisode::findOrFail($run->campaign_episode_id));
        $this->assertSame($run->totals['credit_delta'], $report['json']['runs'][0]['net_credits']);
        $this->assertSame($run->totals['credit_delta'], $report['json']['strategies']['trade']['net_credits']);
        $this->assertGreaterThan(0, $report['json']['runs'][0]['credits_per_ship_hour']);
        $this->assertDatabaseMissing('ship_actions', ['command' => 'buy_ship']);
        $this->assertDatabaseMissing('ship_actions', ['command' => 'contract_accept']);
    }

    public static function gateMetrics(): array
    {
        return [['minutes_elapsed_gte', 30, 31, 29], ['hours_to_reset_lte', 12, 11, 13],
            ['credits_gte', 100000, 100001, 99999], ['markets_observed_gte', 6, 6, 5], ['cycles_completed_gte', 10, 11, 9]];
    }

    #[DataProvider('gateMetrics')]
    public function test_every_phase_gate_metric_is_supported(string $metric, int $threshold, int $passing, int $failing): void
    {
        $playbooks = app(CampaignPlaybook::class);
        $this->assertTrue($playbooks->passes([$metric => $threshold], [$metric => $passing]));
        $this->assertFalse($playbooks->passes([$metric => $threshold], [$metric => $failing]));
        $this->assertFalse($playbooks->passes([$metric => $threshold], []));
    }

    public function test_dispatcher_runs_the_director_when_the_engine_is_already_running(): void
    {
        $this->approve();
        $this->artisan('spacetraders:dispatch-due')->assertSuccessful();
        $this->assertSame(1, CampaignEpisode::count());
        $count = CampaignEvent::count();
        $this->artisan('spacetraders:dispatch-due')->assertSuccessful();
        $this->assertSame($count, CampaignEvent::count());
    }

    public function test_import_command_and_malformed_yaml_errors(): void
    {
        $this->artisan('campaign:import', ['file' => base_path('campaigns/weekly-default.yaml')])->expectsOutputToContain('Revision:')->assertSuccessful();
        $this->assertDatabaseCount('campaign_revisions', 1);
        foreach (['name: [', "name: first\nname: second\n", "name: object\nversion: !php/object anything\n"] as $source) {
            try {
                app(CampaignPlaybook::class)->import($source);
                $this->fail('Malformed or unsafe YAML must be rejected.');
            } catch (GameException) {
                $this->assertDatabaseCount('campaign_revisions', 1);
            }
        }
    }

    public function test_report_scores_predictions_and_records_fifteen_minute_samples(): void
    {
        $this->approve();
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $episode->update(['opened_at' => now()->subHours(25)]);
        $this->travel(16)->minutes();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        $this->tick();
        $this->assertSame(2, CampaignSample::where('campaign_episode_id', $episode->id)->count());
        $report = app(CampaignReport::class)->build($episode->fresh());
        $this->assertSame('not_observed', $report['json']['predictions'][0]['result']);
        $this->assertNull($report['json']['predictions'][0]['actual']);
        $this->assertSame('pass', $report['json']['predictions'][2]['result']);
        $this->assertSame(0, $report['json']['predictions'][2]['actual']);
        $this->assertGreaterThanOrEqual(0, $report['json']['idle_seconds']);
        $this->assertArrayHasKey('requests_used_between_samples', $report['json']);
    }

    public function test_expiry_pauses_active_authority_and_resume_requires_new_approval(): void
    {
        $revision = app(CampaignPlaybook::class)->import($this->body());
        $grant = app(CampaignControl::class)->approve($revision, $this->user, now()->toImmutable()->addSeconds(10));
        $this->tick();
        $this->tick();
        $this->assertSame('grant_expired', $grant->fresh()->pause_reason);
        $this->expectException(GameException::class);
        app(CampaignControl::class)->resume();
    }

    private function driveRun(AutomationRun $run, int $steps = 100): AutomationRun
    {
        for ($step = 0; $step < $steps && $run->fresh()->active_agent_id; $step++) {
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
            app(AutomationRunner::class)->advance($run->id);
            foreach (ShipAction::where('automation_run_id', $run->id)->where('state', 'pending')->get() as $action) {
                app(ShipCommandExecutor::class)->execute($action->id);
            }
            $this->travel(6)->seconds();
        }

        return $run->fresh();
    }

    private function confirmedCommands(AutomationRun $run): array
    {
        return ShipAction::where('automation_run_id', $run->id)->where('state', 'confirmed')->orderBy('id')->pluck('command')->all();
    }

    private function extraProbe(string $symbol, string $waypoint, bool $fuelFree = true): array
    {
        $probe = FakeSpaceTraders::ship($symbol, $waypoint);
        $probe['registration']['role'] = 'SATELLITE';
        $probe['frame']['symbol'] = 'FRAME_PROBE';
        if ($fuelFree) {
            $probe['fuel'] = ['current' => 0, 'capacity' => 0];
        }

        return $probe;
    }

    /** Adds a marketplace waypoint to the fake world and to this agent's observations. */
    private function addMarket(string $symbol, int $x): void
    {
        $this->editWorld(function (&$world) use ($symbol, $x) {
            $world['waypoints'][] = ['symbol' => $symbol, 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => $x, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]];
        });
        $point = collect(json_decode(file_get_contents($this->file), true)['waypoints'])->firstWhere('symbol', $symbol);
        app(AgentSynchronizer::class)->observe($this->agent, 'waypoint', $symbol, $point);
        app(AgentSynchronizer::class)->observe($this->agent, 'market', $symbol, app(SpaceTradersApi::class)->market($this->agent, $symbol));
        // Priced already (no fuel sold), so the discover-phase probe has no reason to scout it.
        $goods = array_values(array_filter(json_decode(file_get_contents($this->file), true)['goods'], fn ($good) => $good['symbol'] !== 'FUEL'));
        $market = GameObservation::where('kind', 'market')->where('symbol', $symbol)->firstOrFail();
        $market->update(['data' => array_merge($market->data, ['tradeGoods' => $goods])]);
    }

    public static function lowFuelTrades(): array
    {
        return ['units capped by the proof batch' => [1000, null], 'credits capped by proof max_credits' => [95, 95]];
    }

    #[DataProvider('lowFuelTrades')]
    public function test_a_trade_docked_at_a_fuel_seller_on_a_low_tank_refuels_before_buying_and_completes(int $exposure, ?int $capped): void
    {
        $this->tradeWorld();
        $this->editWorld(function (&$world) {
            $world['ships'][0]['fuel']['current'] = 5;
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->approve(str_replace('max_credits: 60000', 'max_credits: '.$exposure, $this->body()));
        $this->tick();
        CampaignEpisode::firstOrFail()->update(['phase' => 'earn']);
        $this->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('trade', $run->strategy);
        $this->assertSame('proof', $run->plan['campaign_trade']['mode']);
        $this->assertSame(5, $run->plan['initial_fuel']);
        $departure = $run->plan['route']['departure_refuel_ceiling'];
        $this->assertGreaterThan(0, $departure);
        $this->assertSame($departure, $run->plan['campaign_trade']['departure_refuel']);
        $this->assertSame($run->plan['campaign_trade']['max_inventory'] + $run->plan['campaign_trade']['fuel'] + $departure, $run->plan['max_spend']);
        if ($capped !== null) {
            $this->assertSame($capped, $run->plan['max_inventory']);
        }
        // The live 5-unit tank cannot fund the delivery leg: a buy before the top-up would be refused.
        try {
            app(CampaignTrade::class)->fuelReserve($this->agent, 'LOCAL-1', $run->plan['market']);
            $this->fail('The delivery fuel reserve needs the departure top-up first.');
        } catch (GameException $exception) {
            $this->assertStringContainsString('fuel-safe', $exception->getMessage());
        }
        $run = $this->driveRun($run);
        $this->assertSame('completed', $run->state->value, $run->blocked_reason ?? json_encode($run->totals));
        $commands = $this->confirmedCommands($run);
        $this->assertSame('refuel', $commands[0], json_encode($commands));
        $this->assertLessThan(array_search('navigate', $commands, true), array_search('buy', $commands, true));
        $this->assertLessThan(array_search('sell', $commands, true), array_search('navigate', $commands, true));
        $this->assertSame([], $run->totals['unsold']);
        $this->assertGreaterThan(0, $run->totals['cash_contribution']);
        $this->assertLessThanOrEqual($run->plan['max_spend'], $run->totals['spending']);
        $this->assertSame(100, Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot['fuel']['current']);
    }

    public function test_a_full_tank_trade_plans_no_departure_top_up(): void
    {
        $this->tradeGrant(1000);
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame(0, $run->plan['route']['departure_refuel_ceiling']);
        $this->assertSame(0, $run->plan['campaign_trade']['departure_refuel']);
        $this->driveRun($run, 1);
        $this->assertSame('buy', ShipAction::where('automation_run_id', $run->id)->orderBy('id')->firstOrFail()->command);
    }

    public static function recoveryBuyerFuel(): array
    {
        return ['buyer sells fuel' => [true], 'buyer does not sell fuel' => [false]];
    }

    #[DataProvider('recoveryBuyerFuel')]
    public function test_recovery_docked_at_a_fuel_seller_on_a_low_tank_refuels_before_navigating_then_sells(bool $buyerSellsFuel): void
    {
        $this->tradeWorld();
        $this->editWorld(function (&$world) use ($buyerSellsFuel) {
            $world['ships'][0]['fuel']['current'] = 5;
            $world['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 3, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 3]]];
            if (! $buyerSellsFuel) {
                $world['market_goods']['X1-TEST-PORT'] = array_values(array_filter($world['market_goods']['X1-TEST-PORT'], fn ($good) => $good['symbol'] !== 'FUEL'));
            }
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $grant = $this->approve();
        $this->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('sell_aboard', $run->strategy);
        $this->assertSame('X1-TEST-PORT', $run->plan['market']);
        $this->assertGreaterThan(0, $run->plan['route']['departure_refuel_ceiling']);
        $this->assertGreaterThan(0, $run->plan['route']['fuel_ceiling']);
        $this->assertSame($buyerSellsFuel, $run->plan['route']['post_sale_refuel_ceiling'] > 0);
        $run = $this->driveRun($run);
        $this->assertSame('completed', $run->state->value, $run->blocked_reason ?? json_encode($run->totals));
        $commands = $this->confirmedCommands($run);
        $this->assertSame('refuel', $commands[0], json_encode($commands));
        $this->assertLessThan(array_search('navigate', $commands, true), array_search('refuel', $commands, true));
        $this->assertContains('sell', $commands);
        $this->assertSame($buyerSellsFuel ? 'refuel' : 'sell', end($commands), json_encode($commands));
        $this->assertCount($buyerSellsFuel ? 2 : 1, array_keys($commands, 'refuel', true));
        $ship = Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
        $this->assertSame(0, $ship['cargo']['units']);
        $this->assertSame('X1-TEST-PORT', $ship['nav']['waypointSymbol']);
        $this->assertSame($buyerSellsFuel, $ship['fuel']['current'] === $ship['fuel']['capacity']);
        $this->assertNull($grant->fresh()->paused_at);
    }

    public function test_command_ship_stall_alert_ignores_probe_and_extra_ship_runs(): void
    {
        $grant = $this->tradeGrant(100);
        $episode = CampaignEpisode::firstOrFail();
        $episode->update(['opened_at' => now()->subHours(2)]);
        $command = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('LOCAL-1', $command->ship_symbol);
        app(AutomationRunner::class)->finishStop($command, 'Synthetic completed trade.');
        $key = 'command_ship_stalled:'.$grant->id;
        $problems = function () use ($grant) {
            EngineFixture::healthy();
            $grant->update(['last_directed_at' => now()]);

            return app(CampaignHealth::class)->problems();
        };
        $this->travel(CampaignHealth::COMMAND_STALL_MINUTES - 2)->minutes();
        $this->assertArrayNotHasKey($key, $problems());
        foreach (['LOCAL-2', 'LOCAL-3'] as $other) {
            AutomationRun::create(['campaign_episode_id' => $episode->id, 'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id,
                'ship_symbol' => $other, 'strategy' => 'scout', 'plan_version' => 3, 'state' => 'completed', 'started_at' => now(),
                'expires_at' => now()->addMinutes(15), 'plan' => [], 'totals' => []]);
        }
        $this->travel(3)->minutes();
        $found = $problems();
        $this->assertArrayHasKey($key, $found);
        $this->assertArrayNotHasKey('campaign_progress_stalled:'.$grant->id, $found, 'Probe runs still count for the sixty-minute check.');
        AutomationRun::create(['campaign_episode_id' => $episode->id, 'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id,
            'ship_symbol' => 'LOCAL-1', 'strategy' => 'sell_aboard', 'plan_version' => 4, 'state' => 'completed', 'started_at' => now(),
            'expires_at' => now()->addMinutes(15), 'plan' => [], 'totals' => []]);
        $this->assertArrayNotHasKey($key, $problems());
    }

    public function test_command_ship_stall_alert_waits_for_grace_and_earn_and_skips_pauses_and_stale_engines(): void
    {
        $grant = $this->tradeGrant(100);
        $episode = CampaignEpisode::firstOrFail();
        app(AutomationRunner::class)->finishStop(AutomationRun::whereNotNull('active_agent_id')->firstOrFail(), 'Synthetic completed trade.');
        $key = 'command_ship_stalled:'.$grant->id;
        $problems = function () use ($grant) {
            $grant->update(['last_directed_at' => now()]);

            return app(CampaignHealth::class)->problems();
        };
        $this->travel(CampaignHealth::COMMAND_STALL_GRACE_MINUTES - 3)->minutes();
        EngineFixture::healthy();
        $this->assertArrayNotHasKey($key, $problems(), 'The episode opened under thirty minutes ago.');
        $this->travel(4)->minutes();
        EngineFixture::healthy();
        $this->assertArrayHasKey($key, $problems());
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()->subMinutes(6)]);
        $this->assertArrayNotHasKey($key, $problems());
        EngineFixture::healthy();
        foreach (['discover', 'wind_down'] as $phase) {
            $episode->update(['phase' => $phase]);
            $this->assertArrayNotHasKey($key, $problems());
        }
        $episode->update(['phase' => 'earn']);
        $this->assertArrayHasKey($key, $problems());
        app(CampaignControl::class)->pause($grant, 'operator_pause');
        $this->assertArrayNotHasKey($key, $problems());
        $this->travel(40)->minutes();
        EngineFixture::healthy();
        $this->artisan('campaign:resume')->assertSuccessful();
        $this->assertArrayNotHasKey($key, $problems(), 'Paused time does not count.');
        $this->travel(CampaignHealth::COMMAND_STALL_MINUTES + 1)->minutes();
        EngineFixture::healthy();
        $this->assertArrayHasKey($key, $problems());
        // Entering earn restarts the timer from the phase change.
        app(CampaignControl::class)->event($grant->fresh(), $episode, 'phase', ['from' => 'discover', 'to' => 'earn']);
        $this->assertArrayNotHasKey($key, $problems());
    }

    public function test_a_loss_re_proves_only_its_pair_and_a_covered_forced_sale_is_not_a_loss(): void
    {
        $grant = $this->approve();
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $trades = app(CampaignTrade::class);
        $this->completedLoad($episode, true, 100);
        $this->assertSame('first_scaled', $trades->mode($episode, 'IRON_ORE', 'X1-TEST-PORT'));
        $this->completedLoad($episode, true, -5, 100, false, 'COPPER_ORE');
        $this->assertSame('first_scaled', $trades->mode($episode, 'IRON_ORE', 'X1-TEST-PORT'), 'Another pair\'s loss does not re-prove this pair.');
        $this->assertSame('proof', $trades->mode($episode, 'COPPER_ORE', 'X1-TEST-PORT'));
        $this->completedLoad($episode, false, 50, 100, true);
        $this->assertSame('scaled', $trades->mode($episode, 'IRON_ORE', 'X1-TEST-PORT'));
        $forced = collect($trades->results($episode))->last();
        $this->assertTrue($forced['forced_sale']);
        $this->assertFalse($forced['losing']);
        $this->tick();
        $this->assertNull($grant->fresh()->pause_reason);
        // A result saved under the old rule (every forced sale flagged losing) is judged by its net.
        app(CampaignControl::class)->event($grant, $episode, 'load_result', ['run_id' => 999, 'good' => 'COPPER_ORE', 'buyer' => 'X1-TEST-PORT',
            'net' => 10, 'proof' => false, 'forced_sale' => true, 'losing' => true, 'finished_at' => now()->toIso8601String()]);
        $this->assertNull(app(CampaignSafety::class)->reason($grant->fresh(), $episode->fresh(), $this->agent));
        $this->completedLoad($episode, false, -1, 100, true);
        $this->assertSame('proof', $trades->mode($episode, 'IRON_ORE', 'X1-TEST-PORT'), 'A forced sale with negative net still loses.');
    }

    public function test_a_parked_non_role_ship_at_the_buyer_replaces_the_probe_trip(): void
    {
        $this->tradeWorld();
        $this->editWorld(function (&$world) {
            $world['ships'][1]['nav']['waypointSymbol'] = 'X1-TEST-ROCK';
            $world['ships'][] = $this->extraProbe('LOCAL-3', 'X1-TEST-PORT');
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->approve(str_replace('max_credits: 60000', 'max_credits: 1000', $this->body()));
        $this->tick();
        CampaignEpisode::firstOrFail()->update(['phase' => 'earn']);
        $this->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('trade', $run->strategy);
        $this->assertSame('LOCAL-1', $run->ship_symbol);
        $this->assertSame('X1-TEST-PORT', $run->plan['market']);
        $episode = CampaignEpisode::firstOrFail();
        $this->assertSame('LOCAL-2', $episode->state['role_ships']['probe']);
        $this->assertSame('LOCAL-1', $episode->state['role_ships']['command']);
        $this->assertFalse(AutomationRun::where('ship_symbol', 'LOCAL-2')->where('strategy', 'scout')->exists(), 'The role probe stays put.');
        $this->assertFalse(AutomationRun::where('ship_symbol', 'LOCAL-3')->exists(), 'The campaign never drives a non-role ship.');
    }

    public static function roleProbeStates(): array
    {
        return ['role probe idle elsewhere' => ['idle'], 'role probe in transit' => ['in_transit'], 'role probe failed recently' => ['failed']];
    }

    #[DataProvider('roleProbeStates')]
    public function test_recovery_reads_a_stale_best_buyer_through_a_parked_ship_instead_of_the_role_probe(string $probeState): void
    {
        $this->tradeWorld();
        $this->editWorld(function (&$world) use ($probeState) {
            $world['ships'][1] = $this->extraProbe('LOCAL-2', 'X1-TEST-ROCK');
            if ($probeState === 'in_transit') {
                $world['ships'][1]['nav']['status'] = 'IN_TRANSIT';
                $world['ships'][1]['nav']['route']['arrival'] = now()->addHour()->toIso8601String();
            }
            $world['ships'][] = $this->extraProbe('LOCAL-3', 'X1-TEST-PORT');
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->addMarket('X1-TEST-ZED', 40);
        $grant = $this->approve();
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $this->assertSame('LOCAL-2', $episode->fresh()->state['role_ships']['probe']);
        $this->editWorld(function (&$world) {
            $world['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 3, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 3]]];
        });
        // The evidence rotation reads ROCK and ZED this tick, so only a parked-ship read can refresh PORT.
        $episode->update(['state' => array_merge($episode->fresh()->state, ['recovery_refresh' => 1]
            + ($probeState === 'failed' ? ['probe_position_failed' => now()->toIso8601String()] : []))]);
        GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-PORT')->update(['observed_at' => now()->subHour()]);
        $this->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('sell_aboard', $run->strategy, json_encode(CampaignEvent::where('kind', 'decision')->latest('id')->first()?->data));
        $this->assertSame('X1-TEST-PORT', $run->plan['market'], 'The best buyer, not the lower local bid.');
        $this->assertFalse(AutomationRun::where('ship_symbol', 'LOCAL-2')->exists(), 'The role probe is not dispatched.');
        $this->assertFalse(CampaignEvent::where('kind', 'decision')->get()->contains(fn ($event) => $event->data['choice'] === 'awaiting_recovery_observer'));
        $run = $this->driveRun($run);
        $this->assertSame('completed', $run->state->value, $run->blocked_reason ?? json_encode($run->totals));
        $sale = ShipAction::where('automation_run_id', $run->id)->where('command', 'sell')->where('state', 'confirmed')->firstOrFail();
        $this->assertSame('X1-TEST-PORT', $sale->before_state['ship']['nav']['waypointSymbol']);
        $this->assertNull($grant->fresh()->paused_at);
    }

    public function test_recovery_reads_a_stale_fuel_seller_through_a_parked_ship_instead_of_the_role_probe(): void
    {
        $this->tradeWorld();
        $this->editWorld(function (&$world) {
            $world['market_goods']['X1-TEST-PORT'] = array_values(array_filter($world['market_goods']['X1-TEST-PORT'], fn ($good) => $good['symbol'] !== 'FUEL'));
            $world['ships'][1] = $this->extraProbe('LOCAL-2', 'X1-TEST-PORT');
            $world['ships'][] = $this->extraProbe('LOCAL-3', 'X1-TEST-ROCK');
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->addMarket('X1-TEST-ZED', 40);
        $grant = $this->approve();
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $this->editWorld(function (&$world) {
            $point = $world['waypoints'][0];
            $point['symbol'] = 'X1-TEST-MINE';
            $point['traits'] = [];
            $world['waypoints'][] = $point;
            $world['ships'][0]['nav']['waypointSymbol'] = $point['symbol'];
            $world['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 3, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 3]]];
        });
        app(AgentSynchronizer::class)->observe($this->agent, 'waypoint', 'X1-TEST-MINE', collect(json_decode(file_get_contents($this->file), true)['waypoints'])->firstWhere('symbol', 'X1-TEST-MINE'));
        // The rotation reads ZED and the buyer PORT this tick; the only fuel seller, ROCK, stays stale.
        $episode->update(['state' => array_merge($episode->fresh()->state, ['recovery_refresh' => 2])]);
        GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-ROCK')->update(['observed_at' => now()->subHour()]);
        $this->tick();
        $this->assertTrue(GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-ROCK')->firstOrFail()->observed_at->gt(now()->subMinute()), 'The parked ship read the fuel seller.');
        $this->assertFalse(AutomationRun::where('ship_symbol', 'LOCAL-2')->exists(), 'The role probe is not dispatched.');
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('sell_aboard', $run->strategy);
        $this->assertSame('X1-TEST-PORT', $run->plan['market']);
        $this->assertNull($grant->fresh()->paused_at);
    }

    public function test_active_run_ticks_refresh_parked_non_role_markets_in_rotation(): void
    {
        $this->tradeWorld();
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->addMarket('X1-TEST-YAK', 30);
        $this->addMarket('X1-TEST-ZED', 40);
        $this->editWorld(function (&$world) {
            $world['ships'][] = $this->extraProbe('LOCAL-3', 'X1-TEST-YAK');
            $world['ships'][] = $this->extraProbe('LOCAL-4', 'X1-TEST-ZED');
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->approve(str_replace('max_credits: 60000', 'max_credits: 1000', $this->body()));
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $episode->update(['phase' => 'earn']);
        $this->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('trade', $run->strategy);
        // Keep discovery's own occupied-market sweep out of this tick sequence.
        $state = $episode->fresh()->state;
        unset($state['discovery']);
        $episode->update(['state' => array_merge($state, ['last_discovery_at' => now()->addHour()->toIso8601String()])]);
        $readAt = fn (string $symbol) => GameObservation::where('kind', 'market')->where('symbol', $symbol)->firstOrFail()->observed_at;
        GameObservation::where('kind', 'market')->whereIn('symbol', ['X1-TEST-YAK', 'X1-TEST-ZED'])->update(['observed_at' => now()->subHour()]);
        $order = [];
        for ($tick = 0; $tick < 4; $tick++) {
            $before = ['X1-TEST-YAK' => $readAt('X1-TEST-YAK'), 'X1-TEST-ZED' => $readAt('X1-TEST-ZED')];
            $this->tick();
            $this->assertSame($run->id, AutomationRun::whereNotNull('active_agent_id')->firstOrFail()->id, 'The run stays active.');
            $read = array_keys(array_filter($before, fn ($at, $symbol) => $readAt($symbol)->gt($at), ARRAY_FILTER_USE_BOTH));
            $this->assertLessThanOrEqual(1, count($read), 'At most one parked market per tick.');
            $order[] = $read[0] ?? null;
            if ($tick === 1) {
                $this->travel(181)->seconds();
                LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
                $run->update(['expires_at' => now()->addMinutes(15)]);
            }
        }
        $this->assertSame(['X1-TEST-YAK', 'X1-TEST-ZED', 'X1-TEST-YAK', 'X1-TEST-ZED'], $order);
        $this->assertSame('awaiting_run', CampaignEvent::where('kind', 'decision')->latest('id')->firstOrFail()->data['choice']);
    }

    public function test_parked_market_refresh_skips_markets_read_within_the_quote_age(): void
    {
        $this->tradeWorld();
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->addMarket('X1-TEST-YAK', 30);
        $this->editWorld(function (&$world) {
            $world['ships'][] = $this->extraProbe('LOCAL-3', 'X1-TEST-YAK');
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->approve(str_replace('max_credits: 60000', 'max_credits: 1000', $this->body()));
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $episode->update(['phase' => 'earn']);
        $this->tick();
        $state = $episode->fresh()->state;
        unset($state['discovery']);
        $episode->update(['state' => array_merge($state, ['last_discovery_at' => now()->addHour()->toIso8601String()])]);
        GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-YAK')->update(['observed_at' => now()->subSeconds(60)]);
        $calls = count(array_filter(json_decode(file_get_contents($this->file), true)['calls'], fn ($call) => str_ends_with($call['path'], 'X1-TEST-YAK/market')));
        $this->tick();
        $after = count(array_filter(json_decode(file_get_contents($this->file), true)['calls'], fn ($call) => str_ends_with($call['path'], 'X1-TEST-YAK/market')));
        $this->assertSame($calls, $after);
    }

    private function lowFuelWorld(array $ship = [], ?callable $edit = null): void
    {
        $this->tradeWorld();
        $this->editWorld(function (&$world) use ($ship, $edit) {
            $world['ships'][0] = array_replace_recursive($world['ships'][0], ['fuel' => ['current' => 5]], $ship);
            if ($edit) {
                $edit($world);
            }
        });
        app(AgentSynchronizer::class)->sync($this->agent);
    }

    private function startEarnTrade(int $exposure = 1000): AutomationRun
    {
        $this->approve(str_replace('max_credits: 60000', 'max_credits: '.$exposure, $this->body()));
        $this->tick();
        CampaignEpisode::firstOrFail()->update(['phase' => 'earn']);
        $this->tick();

        return AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
    }

    public function test_departure_fuel_is_spending_not_margin_and_is_not_charged_to_the_load(): void
    {
        // 4 units at 30 sell for 33: the 12 CR spread covers the 10 CR return-fuel ceiling but not
        // the 10 CR departure ceiling as well.
        $this->lowFuelWorld(edit: function (&$world) {
            $world['market_goods']['X1-TEST-PORT'][0]['sellPrice'] = 33;
        });
        $run = $this->startEarnTrade();
        $this->assertSame('trade', $run->strategy);
        $route = $run->plan['route'];
        $this->assertGreaterThan(0, $route['departure_refuel_ceiling']);
        $this->assertSame($run->plan['units'] * 3 - ($route['fuel_ceiling'] - $route['departure_refuel_ceiling']), $route['estimated_cash_margin']);
        $this->assertLessThan(1, $route['estimated_cash_margin'] - $route['departure_refuel_ceiling'], 'The old margin check would have rejected this pair.');
        $run = $this->driveRun($run);
        $this->assertSame('completed', $run->state->value, $run->blocked_reason ?? json_encode($run->totals));
        $refuels = ShipAction::where('automation_run_id', $run->id)->where('command', 'refuel')->where('state', 'confirmed')->orderBy('id')->get();
        $this->assertCount(2, $refuels);
        $this->assertSame($refuels->sum(fn ($action) => $action->outcome['transaction_total']), $run->totals['fuel_cost'], 'Run totals keep every fuel bill.');
        $result = app(CampaignTrade::class)->results(CampaignEpisode::firstOrFail())[0];
        $returnFuel = $refuels->last()->outcome['transaction_total'];
        $this->assertSame($run->totals['sale_proceeds'] - $run->totals['purchase_cost'] - $returnFuel, $result['cash_net']);
        $this->assertSame($result['cash_net'] - $result['unrefilled_fuel_allowance'], $result['net']);
        $this->assertFalse($result['losing']);
    }

    public function test_a_post_sale_top_up_beyond_the_loads_own_travel_is_not_charged_to_it(): void
    {
        $this->tradeWorld();
        $this->editWorld(function (&$world) {
            $world['ships'][0]['nav']['waypointSymbol'] = 'X1-TEST-PORT';
            $world['ships'][0]['fuel']['current'] = 5;
            $world['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 3, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 3]]];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->approve();
        $this->tick();
        $run = $this->driveRun(AutomationRun::whereNotNull('active_agent_id')->firstOrFail());
        $this->assertSame('completed', $run->state->value, $run->blocked_reason ?? json_encode($run->totals));
        $this->assertSame(['sell', 'refuel'], $this->confirmedCommands($run));
        $this->assertGreaterThan(0, $run->totals['fuel_cost']);
        $result = collect(app(CampaignTrade::class)->results(CampaignEpisode::firstOrFail()))->firstWhere('run_id', $run->id);
        $this->assertSame($run->totals['sale_proceeds'], $result['net'], 'No travel, so the earlier loads\' fuel is not charged here.');
    }

    public function test_recovery_reads_its_own_stale_market_before_planning_the_departure_top_up(): void
    {
        $this->lowFuelWorld(['cargo' => ['capacity' => 40, 'units' => 3, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 3]]]]);
        GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-ROCK')->update(['observed_at' => now()->subHour()]);
        $this->assertNull(app(FuelPlanner::class)->sellerQuote($this->agent, 'X1-TEST-ROCK'));
        $plan = app(CampaignRecovery::class)->compose($this->agent, 'LOCAL-1', 'X1-TEST-PORT',
            ['reserve' => 0, 'max_spend' => 100000, 'max_loss' => PHP_INT_MAX, 'minutes' => 15, 'cycles' => 1])['plan'];
        $this->assertGreaterThan(0, $plan['route']['departure_refuel_ceiling']);
        $this->assertTrue(GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-ROCK')->firstOrFail()->observed_at->gt(now()->subMinute()));
    }

    public function test_a_trade_blocks_instead_of_buying_when_the_departure_top_up_exceeds_its_ceiling(): void
    {
        $this->lowFuelWorld();
        $run = $this->startEarnTrade();
        $this->assertGreaterThan(0, $run->plan['route']['departure_refuel_ceiling']);
        $this->editWorld(function (&$world) {
            $world['goods'][2]['purchasePrice'] = 500;
        });
        $run = $this->driveRun($run, 3);
        $this->assertSame('blocked', $run->state->value);
        $this->assertStringContainsString('Departure refuel exceeds', $run->blocked_reason);
        $this->assertSame(0, ShipAction::where('automation_run_id', $run->id)->count());
        $this->assertSame([], json_decode(file_get_contents($this->file), true)['writes']);
    }

    public function test_recovery_blocks_instead_of_departing_when_the_departure_top_up_exceeds_its_ceiling(): void
    {
        $this->lowFuelWorld(['cargo' => ['capacity' => 40, 'units' => 3, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 3]]]]);
        $grant = $this->approve();
        $this->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('sell_aboard', $run->strategy);
        $this->editWorld(function (&$world) {
            $world['goods'][2]['purchasePrice'] = 500;
        });
        $run = $this->driveRun($run, 3);
        $this->assertSame('blocked', $run->state->value);
        $this->assertStringContainsString('Departure refuel exceeds', $run->blocked_reason);
        $this->assertFalse(ShipAction::where('automation_run_id', $run->id)->whereIn('command', ['orbit', 'navigate', 'refuel'])->exists());
        $this->assertSame('X1-TEST-ROCK', Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot['nav']['waypointSymbol']);
        $this->assertNull($grant->fresh()->paused_at);
    }

    public function test_one_way_arrival_refuel_caps_at_the_unspent_fuel_ceiling(): void
    {
        $this->tradeWorld();
        app(AgentSynchronizer::class)->sync($this->agent);
        $planner = app(OperationPlanner::class);
        $ship = Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
        $ship['fuel']['current'] = 12;
        $listing = GameObservation::where('kind', 'market_listing')->where('symbol', 'X1-TEST-ROCK')->firstOrFail();
        $data = $listing->data;
        $data['exchange'] = array_values(array_filter($data['exchange'], fn ($good) => $good['symbol'] !== 'FUEL'));
        $listing->update(['data' => $data]);
        $plan = $planner->preview($this->agent, $ship, ['strategy' => 'scout', 'destination' => 'X1-TEST-PORT', 'reserve' => 0, 'max_fuel' => 100, 'max_spend' => 100, 'max_loss' => 100, 'one_way' => true]);
        $ceiling = $plan['route']['fuel_ceiling'];
        $ship['nav']['waypointSymbol'] = 'X1-TEST-PORT';
        $ship['fuel']['current'] = 5;
        $step = $planner->next($this->agent, $ship, $plan, ['phase' => 'observe', 'fuel_cost' => 3], now()->toImmutable());
        $this->assertSame('refuel', $step->command);
        $this->assertSame($ceiling - 3, $step->arguments['maxTotal']);
    }

    public function test_return_refuel_caps_at_the_unspent_fuel_ceiling(): void
    {
        $planner = app(OperationPlanner::class);
        $grant = $this->tradeGrant(1000);
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $home = Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
        $home['fuel']['current'] = 90;
        $totals = array_replace($run->totals, ['phase' => 'return', 'fuel_cost' => 3, 'purchased' => [], 'sold' => []]);
        $step = $planner->next($this->agent->fresh(), $home, $run->plan, $totals, now()->toImmutable());
        $this->assertSame('refuel', $step->command);
        $this->assertSame($run->plan['route']['fuel_ceiling'] - 3, $step->arguments['maxTotal']);
        $this->assertNull($grant->fresh()->paused_at);
    }

    public function test_wind_down_recovery_skips_the_post_sale_top_up(): void
    {
        $this->lowFuelWorld(['cargo' => ['capacity' => 40, 'units' => 3, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 3]]]]);
        $this->approve();
        $this->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertGreaterThan(0, $run->plan['route']['post_sale_refuel_ceiling']);
        CampaignEpisode::firstOrFail()->update(['next_reset_at' => now()->addMinutes(50)]);
        $run = $this->driveRun($run);
        $this->assertSame('completed', $run->state->value, $run->blocked_reason ?? json_encode($run->totals));
        $commands = $this->confirmedCommands($run);
        $this->assertSame('sell', end($commands), json_encode($commands));
        $this->assertCount(1, array_keys($commands, 'refuel', true), 'Only the departure top-up.');
    }

    public function test_recovery_starting_in_orbit_docks_then_refuels_before_departing(): void
    {
        $this->lowFuelWorld(['nav' => ['status' => 'IN_ORBIT'], 'cargo' => ['capacity' => 40, 'units' => 3, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 3]]]]);
        $this->approve();
        $this->tick();
        $run = $this->driveRun(AutomationRun::whereNotNull('active_agent_id')->firstOrFail());
        $this->assertSame('completed', $run->state->value, $run->blocked_reason ?? json_encode($run->totals));
        $this->assertSame(['dock', 'refuel', 'orbit', 'navigate'], array_slice($this->confirmedCommands($run), 0, 4));
    }

    public function test_command_positioning_from_a_fuel_seller_refuels_before_departing(): void
    {
        $this->tradeWorld();
        $this->editWorld(function (&$world) {
            $world['market_goods']['X1-TEST-ZED'] = [['symbol' => 'FUEL', 'tradeVolume' => 10, 'purchasePrice' => 5, 'sellPrice' => 3]];
        });
        $this->addMarket('X1-TEST-ZED', 40);
        $this->editWorld(function (&$world) {
            $world['ships'][0]['nav']['waypointSymbol'] = 'X1-TEST-ZED';
            $world['ships'][0]['fuel']['current'] = 5;
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $run = $this->startEarnTrade();
        $this->assertSame('scout', $run->strategy);
        $this->assertSame('LOCAL-1', $run->ship_symbol);
        $this->assertSame('X1-TEST-ROCK', $run->plan['destination']);
        $this->assertGreaterThan(0, $run->plan['route']['departure_refuel_ceiling']);
        $run = $this->driveRun($run);
        $this->assertSame('completed', $run->state->value, $run->blocked_reason ?? json_encode($run->totals));
        $this->assertSame(['refuel', 'orbit', 'navigate'], array_slice($this->confirmedCommands($run), 0, 3));
    }

    public function test_idle_ticks_refresh_a_parked_market_and_a_failed_read_does_not_block_the_trade(): void
    {
        $this->tradeWorld();
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->addMarket('X1-TEST-YAK', 30);
        $this->addMarket('X1-TEST-ZED', 40);
        $this->editWorld(function (&$world) {
            $world['ships'][] = $this->extraProbe('LOCAL-3', 'X1-TEST-YAK');
            $world['ships'][] = $this->extraProbe('LOCAL-4', 'X1-TEST-ZED');
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $grant = $this->approve(str_replace('max_credits: 60000', 'max_credits: 1000', $this->body()));
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $state = $episode->state;
        unset($state['discovery']);
        $episode->update(['phase' => 'earn', 'state' => array_merge($state, ['last_discovery_at' => now()->addHour()->toIso8601String()])]);
        GameObservation::where('kind', 'market')->whereIn('symbol', ['X1-TEST-YAK', 'X1-TEST-ZED'])->update(['observed_at' => now()->subHour()]);
        $api = app(SpaceTradersApi::class);
        $mock = \Mockery::mock(SpaceTradersApi::class, [app(SpaceTradersClient::class)])->makePartial();
        $mock->shouldReceive('market')->andReturnUsing(function ($agent, $symbol) use ($api) {
            if ($symbol === 'X1-TEST-YAK') {
                throw new GameException('Synthetic parked-market refusal.');
            }

            return $api->market($agent, $symbol);
        });
        $this->app->instance(SpaceTradersApi::class, $mock);
        $this->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('trade', $run->strategy, 'A failed parked read does not block the decision.');
        $this->assertNull($grant->fresh()->paused_at);
        $this->assertSame('X1-TEST-YAK', $episode->fresh()->state['parked_refresh']);
        $this->assertTrue(GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-YAK')->firstOrFail()->observed_at->lt(now()->subMinutes(30)));
        app(AutomationRunner::class)->finishStop($run, 'Synthetic completed trade.');
        $this->tick();
        $this->assertTrue(GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-ZED')->firstOrFail()->observed_at->gt(now()->subMinute()), 'The next idle tick reads the next parked market.');
    }
}
