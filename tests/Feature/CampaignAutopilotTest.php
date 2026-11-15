<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\CampaignRecovery;
use App\Gameplay\CampaignReport;
use App\Gameplay\CampaignSafety;
use App\Gameplay\CampaignTrade;
use App\Gameplay\Engine\EngineHost;
use App\Gameplay\EngineGate;
use App\Gameplay\GameException;
use App\Gameplay\OperationPlanner;
use App\Gameplay\ShipCommandExecutor;
use App\Gameplay\SpaceTradersApi;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\CampaignSample;
use App\Models\GameObservation;
use App\Models\LocalEngine;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function body(): string
    {
        return str_replace('GIANTSOUP', 'LOCAL', file_get_contents(base_path('campaigns/weekly-default.yaml')));
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

    private function completedLoad(CampaignEpisode $episode, bool $proof, int $net, float $prediction = 100, bool $forced = false): AutomationRun
    {
        return AutomationRun::create(['campaign_episode_id' => $episode->id, 'agent_id' => $this->agent->id,
            'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-1', 'strategy' => 'trade', 'plan_version' => 3,
            'state' => 'completed', 'started_at' => now()->subMinute(), 'expires_at' => now()->addMinute(),
            'plan' => ['symbol' => 'IRON_ORE', 'site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-PORT',
                'forced_sale' => $forced, 'campaign_trade' => ['proof' => $proof, 'predicted_margin' => $prediction]],
            'totals' => ['purchased' => ['IRON_ORE' => 5], 'sold' => ['IRON_ORE' => 5], 'purchase_cost' => 150,
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

    public function test_a_losing_or_forced_load_requires_a_new_proof_and_two_losses_pause(): void
    {
        $grant = $this->approve();
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $this->completedLoad($episode, true, 100);
        $this->completedLoad($episode, false, -1);
        $trades = app(CampaignTrade::class);
        $this->assertSame('proof', $trades->mode($episode, 'IRON_ORE', 'X1-TEST-PORT'));
        $this->assertNull(app(CampaignSafety::class)->reason($grant, $episode, $this->agent));
        $this->completedLoad($episode, true, 100, 100, true);
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

    public function test_probe_parking_is_one_way_and_precedes_a_fresh_trade(): void
    {
        $this->tradeWorld();
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->editWorld(function (&$world) {
            $world['ships'][1]['nav']['waypointSymbol'] = 'X1-TEST-ROCK';
        });
        $body = Yaml::parse($this->body());
        $body['phases'] = array_slice($body['phases'], 1);
        $this->approve(Yaml::dump($body, 10));
        $this->tick();
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
        app(CampaignPlaybook::class)->import(str_replace($from, $to, $this->body()));
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
        $grant = $this->approve();
        $this->tick();
        CampaignEpisode::first()->update(['opened_at' => now()->subMinutes(31)]);
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
        $grant = $this->approve(str_replace('max_credits: 10000', 'max_credits: '.$exposure, $this->body()));
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
        $this->approve();
        $this->tick();
        $episode = CampaignEpisode::firstOrFail();
        $episode->update(['phase' => 'earn']);
        $this->tick();
        $this->assertFalse(AutomationRun::whereNotNull('active_agent_id')->exists());
        $episode->update(['opened_at' => now()->subMinutes(16)]);
        $this->tick();
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

    public function test_registration_timeout_is_durably_paused_and_never_retried(): void
    {
        $grant = $this->approve();
        $this->tick();
        $this->editWorld(function (&$world) {
            $world['reset'] = now()->subHour()->toIso8601String();
            $world['campaign_registration'] = true;
            $world['failure'] = 'timeout_after';
        });
        $this->tick();
        $this->assertNotNull($grant->fresh()->paused_at);
        app(CampaignControl::class)->resume();
        $this->tick();
        $this->assertSame('registration_unknown', $grant->fresh()->pause_reason);
        $world = json_decode(file_get_contents($this->file), true);
        $this->assertCount(1, array_filter($world['writes'], fn ($call) => $call['path'] === '/register'));
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
}
