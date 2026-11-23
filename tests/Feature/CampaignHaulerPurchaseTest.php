<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignHaulerPurchase;
use App\Gameplay\CampaignProbeBootstrap;
use App\Gameplay\CampaignSaturation;
use App\Gameplay\CampaignTrade;
use App\Gameplay\EngineGate;
use App\Gameplay\FleetClaims;
use App\Gameplay\GameException;
use App\Gameplay\HostLock;
use App\Gameplay\RequestBudget;
use App\Gameplay\ShipCommandExecutor;
use App\Gameplay\SpaceTradersApi;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\LocalEngine;
use App\Models\Ship;
use App\Models\ShipAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeSpaceTraders;
use Tests\Support\SaturationFixture;
use Tests\TestCase;

class CampaignHaulerPurchaseTest extends TestCase
{
    use RefreshDatabase;
    use SaturationFixture;

    protected function tearDown(): void
    {
        $this->tearDownSaturation();
        parent::tearDown();
    }

    public function test_zero_travel_purchase_pins_offer_and_records_delivery_before_completion(): void
    {
        [$run, $action] = $this->purchaseIntent();
        $this->assertSame(7, $run->plan_version);
        $this->assertSame($run->plan['offer_configuration_hash'], $action->arguments['offer_configuration_hash']);
        $this->assertSame($run->fingerprint, $run->fresh()->fingerprint);
        app(ShipCommandExecutor::class)->execute($action->id);

        $this->assertSame('confirmed', $action->fresh()->state->value, $action->fresh()->message);
        $delivered = $action->fresh()->outcome['delivered_ship'];
        $event = CampaignEvent::where('kind', 'hauler_purchased')->sole();
        $this->assertSame([$action->id, $delivered, 350000], [$event->data['action_id'], $event->data['ship'], $event->data['price']]);
        $this->assertNotNull($run->fresh()->active_agent_id);
        $this->assertCount(1, app(CampaignProbeBootstrap::class)->reservations($this->agent));
        $this->assertCount(0, app(CampaignProbeBootstrap::class)->reservations($this->agent, undeliveredType: 'SHIP_LIGHT_HAULER'));
        app(AutomationRunner::class)->advance($run->id);
        $this->assertSame('completed', $run->fresh()->state->value, $run->fresh()->blocked_reason ?? '');
        $this->assertSame(1, CampaignEvent::where('kind', 'hauler_purchased')->count());
        $episode = CampaignEpisode::findOrFail($run->campaign_episode_id);
        $this->assertSame('awaiting_auto_arm', app(CampaignSaturation::class)->evaluate($episode->grant(), $episode, $this->agent->refresh())['gates']['G9']['reason']);
    }

    #[DataProvider('postReservationChanges')]
    public function test_purchase_reloads_authority_after_budget_wait(string $change): void
    {
        [$run, $action] = $this->purchaseIntent();
        $before = $this->world();
        app()->instance(RequestBudget::class, new class($run, $action, $change) extends RequestBudget
        {
            public function __construct(private AutomationRun $run, private ShipAction $action, private string $change) {}

            public function reserve(?string $token = null, ?string $account = null, string $priority = 'discovery', float $maxWait = .75): void
            {
                if ($priority !== 'write') {
                    return;
                }
                match ($this->change) {
                    'engine' => app(EngineGate::class)->close(),
                    'stopping' => app(AutomationRunner::class)->stop($this->run),
                    'terminal' => $this->run->update(['state' => 'completed']),
                    'expired' => $this->action->update(['expires_at' => now()->subSecond()]),
                    'waiting' => $this->run->update(['state' => 'waiting']),
                };
            }
        });
        app(ShipCommandExecutor::class)->execute($action->id);
        $action->refresh();
        if ($change === 'waiting') {
            $this->assertSame('confirmed', $action->state->value, $action->message);

            return;
        }
        $this->assertSame('rejected', $action->state->value, $action->message);
        $this->assertNull($action->sent_at);
        $this->assertNotNull($action->settled_at);
        $this->assertSame('pretransport', $action->outcome['local_refusal']['stage']);
        $this->assertSame($before['writes'], $this->world()['writes']);
        $this->assertSame($before['credits'], $this->world()['credits']);
        $this->assertFalse(ShipAction::where('state', 'uncertain')->exists());
        $this->assertDatabaseMissing('confirmed_credit_entries', ['ship_action_id' => $action->id]);
    }

    public static function postReservationChanges(): array
    {
        return array_map(fn ($state) => [$state], ['engine', 'stopping', 'terminal', 'expired', 'waiting']);
    }

    public function test_active_hauler_run_refuses_manual_probe_purchase_and_deduplicates_its_pending_action(): void
    {
        [$run, $action] = $this->purchaseIntent();
        $purchases = app(CampaignProbeBootstrap::class);
        $this->assertSame([['run_id' => $run->id, 'action_id' => $action->id, 'type' => 'SHIP_LIGHT_HAULER']], $purchases->reservations($this->agent)->values()->all());
        $this->assertCount(0, $purchases->reservations($this->agent, $run->id));
        app(ShipCommandExecutor::class)->execute($action->id);
        $manual = app(ShipCommandExecutor::class)->preview($this->agent, 'LOCAL-2', 'buy_ship', [
            'shipType' => 'SHIP_PROBE', 'waypointSymbol' => 'X1-TEST-ROCK', 'maxTotal' => 25000, 'reserve' => 0,
        ], 'manual-probe');
        $this->expectExceptionMessage('Shared ship purchase slot is held by run');
        app(ShipCommandExecutor::class)->approve($this->agent, $manual);
    }

    public function test_exact_action_pacing_acknowledgement_is_audited_once_and_checks_linkage(): void
    {
        [$run, $action] = $this->purchaseIntent();
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->artisan('campaign:ack-hauler-pacing', ['action' => $action->id])
            ->expectsOutput('The delivery must have stalled before confirming its first load.')->assertFailed();
        $this->assertArrayNotHasKey('pacing_acknowledged', $action->fresh()->outcome);
        $this->assertDatabaseMissing('campaign_events', ['kind' => 'hauler_pacing_acknowledged']);
        $this->travel(91)->minutes();
        $this->artisan('campaign:ack-hauler-pacing', ['action' => $action->id, '--dry-run' => true])->assertSuccessful();
        $this->assertArrayNotHasKey('pacing_acknowledged', $action->fresh()->outcome);
        $this->artisan('campaign:ack-hauler-pacing', ['action' => $action->id])->assertSuccessful();
        $this->artisan('campaign:ack-hauler-pacing', ['action' => $action->id])->assertSuccessful();
        $audit = CampaignEvent::where('kind', 'hauler_pacing_acknowledged')->sole();
        $this->assertSame($action->id, $audit->data['action_id']);
        $this->assertSame($run->campaign_episode_id, $audit->campaign_episode_id);
    }

    public function test_preflight_configuration_drift_refuses_before_reservation(): void
    {
        [, $action] = $this->purchaseIntent();
        $this->editFixture(function (&$world) {
            $world['shipyards']['X1-TEST-ROCK']['ships'][1]['modules'][1]['symbol'] = 'MODULE_CARGO_HOLD_II';
        });
        $writes = $this->world()['writes'];
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame('rejected', $action->fresh()->state->value);
        $this->assertNull($action->fresh()->sent_at);
        $this->assertStringContainsString('configuration changed', $action->fresh()->message);
        $this->assertSame($writes, $this->world()['writes']);
    }

    public function test_nonmarket_shipyard_scout_purchases_hauler_and_arm_completes_its_first_load(): void
    {
        $this->setUpSaturation(hubs: 2, remoteYard: true);
        $this->editFixture(function (&$world) {
            $world['ships'][] = FakeSpaceTraders::probe('LOCAL-6', 'X1-TEST-ROCK', 5);
        });
        $sync = app(AgentSynchronizer::class);
        $sync->observe($this->agent, 'waypoint', 'X1-TEST-YARD', $this->point('X1-TEST-YARD', 393, ['SHIPYARD']), 'listing');
        $sync->sync($this->agent, false);
        $episode = $this->primary();
        $this->schedulerPass();
        $runner = app(AutomationRunner::class);
        $buyer = app(CampaignHaulerPurchase::class);
        $considered = [];
        $scout = app(HostLock::class)->scoped('agent-'.$this->agent->id, fn () => $buyer->plan($episode->grant(), $episode,
            $this->agent, ['LOCAL-1'], $considered, microtime(true) + 30));
        $this->assertNotNull($scout);
        $this->assertSame(['scout', 'shipyard', 36], [$scout->strategy, $scout->plan['observe_kind'], $scout->plan['minutes']]);
        $runner->startCampaign($this->agent, $scout, $episode);
        $this->workRun($scout);
        $this->assertSame('completed', $scout->fresh()->state->value, $scout->fresh()->blocked_reason ?? '');

        $buy = $runner->previewHaulerPurchase($this->agent, 'LOCAL-6', 'X1-TEST-YARD', ['minutes' => 15], $episode);
        $runner->startCampaign($this->agent, $buy, $episode);
        $this->workRun($buy);
        $this->assertSame('completed', $buy->fresh()->state->value, $buy->fresh()->blocked_reason ?? '');
        $purchased = ShipAction::where('automation_run_id', $buy->id)->where('command', 'buy_ship')->sole();
        $lock = app(HostLock::class);
        $lock->run('campaign', fn () => $lock->run('agent-'.$this->agent->id, fn () => app(CampaignControl::class)->autoArm($episode->grant(), $episode, $this->agent)));
        $arm = CampaignGrant::where('limits->arm->purchase_action_id', $purchased->id)->sole();

        $this->assertTrue(app(CampaignDirector::class)->tickArms());
        $position = AutomationRun::where('active_agent_id', $this->agent->id)->sole();
        $this->assertSame('scout', $position->strategy);
        $this->assertSame('X1-TEST-ROCK', $position->plan['destination']);
        $this->workRun($position);
        $this->assertSame('completed', $position->fresh()->state->value, $position->fresh()->blocked_reason ?? '');
        $this->travel(31)->seconds();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        $this->assertTrue(app(CampaignDirector::class)->tickArms());
        $trade = AutomationRun::where('active_agent_id', $this->agent->id)->sole();
        $this->assertSame('trade', $trade->strategy);
        $this->workRun($trade);
        $this->assertSame('completed', $trade->fresh()->state->value, $trade->fresh()->blocked_reason ?? '');
        app(CampaignTrade::class)->harvestArms($this->agent->reset_id);
        $this->assertTrue(CampaignEvent::where('campaign_grant_id', $arm->id)->where('kind', 'load_result')->exists());
        $evaluation = app(CampaignSaturation::class)->evaluate($episode->grant(), $episode->refresh(), $this->agent->refresh());
        $this->assertTrue($evaluation['gates']['G9']['pass']);
        $markets = collect($this->world()['calls'])->filter(fn ($call) => str_ends_with($call['path'], '/market'))->pluck('path');
        $this->assertFalse($markets->contains('/systems/X1-TEST/waypoints/X1-TEST-YARD/market'));
        $this->assertTrue($markets->contains('/systems/X1-TEST/waypoints/X1-TEST-ROCK/market'));
        $this->assertTrue($markets->contains('/systems/X1-TEST/waypoints/X1-TEST-PORT/market'));
    }

    public function test_reviewed_mismatch_clears_agent_hold_and_keeps_the_delivered_ship_excluded(): void
    {
        [$run, $action] = $this->purchaseIntent();
        $this->editFixture(fn (&$world) => $world['deliver_mismatched_frame'] = 'FRAME_HEAVY_FREIGHTER');
        app(ShipCommandExecutor::class)->execute($action->id);
        $action->refresh();
        $this->assertSame('confirmed', $action->state->value);
        $this->assertTrue($action->review_required);
        $this->assertTrue($action->outcome['configuration_mismatch']);
        $this->assertFalse(CampaignEvent::where('kind', 'hauler_purchased')->exists());
        $episode = CampaignEpisode::findOrFail($run->campaign_episode_id);
        $check = fn () => app(CampaignSaturation::class)->evaluate($episode->grant(), $episode->refresh(), $this->agent->refresh());
        $this->assertSame('hauler_configuration_mismatch_review', $check()['gates']['G9']['reason']);
        $runner = app(AutomationRunner::class);
        $limits = ['strategy' => 'scout', 'destination' => 'X1-TEST-PORT', 'one_way' => true, 'max_fuel' => 100,
            'reserve' => 0, 'max_loss' => 1000, 'max_spend' => 2000, 'minutes' => 12];
        try {
            $runner->preview($this->agent, 'LOCAL-1', $limits);
            $this->fail('The unreviewed mismatch did not hold a healthy ship.');
        } catch (GameException $exception) {
            $this->assertStringContainsString('configuration differs', $exception->getMessage());
        }

        $this->artisan('campaign:ack-hauler-pacing', ['action' => $action->id])->assertSuccessful();
        $this->assertSame('hauler_configuration_mismatch_review', $check()['gates']['G9']['reason']);
        app(ShipCommandExecutor::class)->reconcile($this->agent, $action);
        $this->actingAs($this->operator)->postJson(route('game.commands.acknowledge', [$this->agent, $action]), ['note' => 'Reviewed the delivered frame.', 'acknowledge' => true])->assertSuccessful();
        $this->assertTrue(ShipCommandExecutor::reviewedHaulerMismatch($action->refresh()));
        $reviewedEvidence = $action->outcome['reconciled_at'];
        $this->assertSame($reviewedEvidence, $action->outcome['reviewed_reconciled_at']);
        $action->update(['outcome' => array_replace($action->outcome, ['reconciled_at' => now()->addMinute()->toIso8601String()])]);
        $this->assertTrue(ShipCommandExecutor::reviewedHaulerMismatch($action->refresh()));
        $this->assertTrue($check()['gates']['G9']['pass']);
        $healthy = $runner->preview($this->agent, 'LOCAL-1', $limits);
        $runner->startCampaign($this->agent, $healthy, $episode);
        $this->workRun($healthy);
        $this->assertSame('completed', $healthy->fresh()->state->value, $healthy->fresh()->blocked_reason ?? '');
        $this->expectExceptionMessage('configuration differs');
        $runner->preview($this->agent, $action->outcome['delivered_ship'], $limits);
    }

    #[DataProvider('priorResetActions')]
    public function test_prior_reset_actions_do_not_block_current_run_but_current_ship_review_still_does(string $state, bool $currentReview = false): void
    {
        $this->setUpSaturation();
        $episode = $this->primary();
        $runner = app(AutomationRunner::class);
        $probe = Ship::where('symbol', 'LOCAL-2')->sole()->snapshot;
        ShipAction::create(['agent_id' => $this->agent->id, 'ship_symbol' => 'LOCAL-2', 'reset_id' => '2026-10-01',
            'command' => 'buy_ship', 'arguments' => ['shipType' => 'SHIP_LIGHT_HAULER'], 'origin' => 'manual', 'key' => 'old-reset-action',
            'state' => $state, 'expires_at' => now()->addHour(), 'review_required' => $state === 'confirmed',
            'outcome' => $state === 'confirmed' ? ['configuration_mismatch' => true, 'delivered_ship' => 'LOCAL-2'] : []]);
        $scout = $runner->previewMeshScout($this->agent, $probe, 'X1-TEST-PORT', ['reserve' => 0, 'minutes' => 15], $episode);
        $runner->startCampaign($this->agent, $scout, $episode);
        if ($currentReview) {
            ShipAction::create(['agent_id' => $this->agent->id, 'ship_symbol' => 'LOCAL-2', 'reset_id' => $this->agent->reset_id,
                'command' => 'sell', 'arguments' => [], 'origin' => 'manual', 'key' => 'current-review',
                'state' => 'confirmed', 'expires_at' => now()->addHour(), 'review_required' => true]);
        }
        $this->workRun($scout);
        $this->assertSame($currentReview ? 'blocked' : 'completed', $scout->fresh()->state->value, $scout->fresh()->blocked_reason ?? '');
        $this->assertNull($episode->grant()->paused_at);
        $this->assertFalse(CampaignEvent::where('kind', 'pause')->where('data->reason', 'uncertain_action')->exists());
    }

    public static function priorResetActions(): array
    {
        return [['confirmed'], ['pending'], ['sending'], ['uncertain'], ['uncertain', true]];
    }

    public function test_remote_unknown_offer_is_pinned_only_at_arrival_and_fingerprint_survives(): void
    {
        $this->setUpSaturation(hubs: 2, remoteYard: true);
        $sync = app(AgentSynchronizer::class);
        $this->editFixture(function (&$world) {
            $world['waypoints'][2]['x'] = 80;
            $world['ships'][] = FakeSpaceTraders::probe('LOCAL-6', 'X1-TEST-ROCK');
        });
        $sync->observe($this->agent, 'waypoint', 'X1-TEST-YARD', $this->point('X1-TEST-YARD', 80, ['SHIPYARD']), 'listing');
        $episode = $this->primary();
        $this->schedulerPass();
        $runner = app(AutomationRunner::class);
        $run = $runner->previewHaulerPurchase($this->agent, 'LOCAL-6', 'X1-TEST-YARD', ['minutes' => 15], $episode);
        $this->assertNull($run->plan['offer_configuration_hash']);
        $this->assertNull($run->plan['observed_price']);
        $hash = $run->fingerprint;
        $this->travel(5)->seconds();
        $runner->startCampaign($this->agent, $run, $episode);
        $this->workRun($run);
        $action = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->sole();
        $this->assertSame('confirmed', $action->state->value, $action->message ?? '');
        $this->assertSame($hash, $run->fresh()->fingerprint);
        $this->assertSame($run->fresh()->totals['offer_configuration_hash'], $action->arguments['offer_configuration_hash']);
        $this->assertSame(350000, $action->arguments['maxTotal']);
    }

    #[DataProvider('releaseTypes')]
    public function test_manual_release_audit_has_no_campaign_event_and_broken_links_refuse(string $kind, string $type): void
    {
        $this->setUpSaturation(seedCoverage: false);
        $buy = ShipAction::create(['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-2',
            'key' => 'standalone-release', 'origin' => 'manual', 'command' => 'buy_ship', 'state' => 'acknowledged',
            'arguments' => ['shipType' => $type, 'waypointSymbol' => 'X1-TEST-ROCK'],
            'before_state' => ['estimate' => ['fleet_symbols' => ['LOCAL-1', 'LOCAL-2']]], 'sent_at' => now(), 'expires_at' => now()->addMinute()]);
        $command = 'campaign:release-'.$kind.'-purchase';
        $this->artisan($command, ['action' => $buy->id, '--dry-run' => true])->assertSuccessful();
        $this->assertArrayNotHasKey('confirmed_not_sent', $buy->fresh()->outcome ?? []);
        $this->artisan($command, ['action' => $buy->id])->assertSuccessful();
        $this->assertSame($this->operator->id, $buy->fresh()->outcome['confirmed_not_sent']['user_id']);
        $this->assertDatabaseCount('campaign_events', 0);
        $broken = $buy->replicate();
        $broken->fill(['key' => 'broken-link', 'outcome' => ['campaign_episode_id' => 1000]])->save();
        foreach ([false, true] as $dry) {
            $this->artisan($command, ['action' => $broken->id, '--dry-run' => $dry])->assertFailed();
            $this->assertArrayNotHasKey('confirmed_not_sent', $broken->fresh()->outcome);
        }
        $this->assertDatabaseCount('campaign_events', 0);
    }

    public static function releaseTypes(): array
    {
        return [['probe', 'SHIP_PROBE'], ['hauler', 'SHIP_LIGHT_HAULER']];
    }

    public function test_unreviewed_c0_mismatch_holds_new_approvals_and_allows_an_arm_to_deliver_its_load(): void
    {
        $this->setUpSaturation(hubs: 2);
        $this->editFixture(fn (&$world) => $world['ships'][] = FakeSpaceTraders::lightHauler('LOCAL-9', 'X1-TEST-ROCK'));
        app(AgentSynchronizer::class)->sync($this->agent, false);
        $primary = $this->primary();
        $arm = $this->arm('LOCAL-9', 'H1');
        $runner = app(AutomationRunner::class);
        $probe = Ship::where('symbol', 'LOCAL-3')->sole()->snapshot;
        $scout = fn () => $runner->previewMeshScout($this->agent, $probe, 'X1-TEST-ROCK', ['minutes' => 15, 'reserve' => 0], $primary);
        $unstarted = $scout();
        $this->assertTrue(app(CampaignDirector::class)->tickArms());
        $trade = AutomationRun::whereNotNull('active_agent_id')->where('strategy', 'trade')->sole();
        $runner->advance($trade->id);
        $buy = ShipAction::where('automation_run_id', $trade->id)->where('command', 'buy')->sole();
        app(ShipCommandExecutor::class)->execute($buy->id);
        $this->assertSame('confirmed', $buy->fresh()->state->value);
        $this->assertGreaterThan(0, $trade->fresh()->totals['unsold']['IRON_ORE']);

        $mismatch = $this->haulerPurchase($primary, 'c0-hauler-mismatch', 'confirmed',
            ['configuration_mismatch' => true, 'delivered_ship' => 'LOCAL-A'], requiresReview: true);
        foreach ([$scout, fn () => $runner->start($this->agent, $unstarted)] as $approval) {
            try {
                $approval();
                $this->fail('New approval ignored the configuration mismatch.');
            } catch (GameException $exception) {
                $this->assertStringContainsString('configuration differs', $exception->getMessage());
            }
        }
        $this->assertSame('preview', $unstarted->fresh()->state->value);
        $this->workRun($trade);
        $this->assertSame('completed', $trade->fresh()->state->value, $trade->fresh()->blocked_reason ?? '');
        $this->assertNull($arm->fresh()->paused_at);
        $this->assertTrue($mismatch->fresh()->review_required);
        $this->assertSame(['navigate', 'sell'], ShipAction::where('automation_run_id', $trade->id)->where('state', 'confirmed')
            ->whereIn('command', ['navigate', 'sell'])->orderBy('id')->limit(2)->pluck('command')->all());

        app(ShipCommandExecutor::class)->reconcile($this->agent, $mismatch);
        $this->actingAs($this->operator)->postJson(route('game.commands.acknowledge', [$this->agent, $mismatch]),
            ['note' => 'Reviewed the replacement frame.', 'acknowledge' => true])->assertSuccessful();
        $this->assertSame('running', $runner->startCampaign($this->agent, $scout(), $primary)->state->value);
    }

    public function test_inline_purchase_rereads_the_offer_after_arrival_and_rejects_drift(): void
    {
        $this->setUpSaturation();
        $episode = $this->primary();
        $this->schedulerPass();
        $runner = app(AutomationRunner::class);
        $run = $runner->previewHaulerPurchase($this->agent, 'LOCAL-2', 'X1-TEST-ROCK', ['minutes' => 15], $episode);
        $runner->startCampaign($this->agent, $run, $episode);
        config(['gameplay.inline_execute' => true]);

        $this->editFixture(function (&$world) {
            $changed = $world['shipyards']['X1-TEST-ROCK']['ships'];
            $changed[1]['modules'][1]['symbol'] = 'MODULE_CARGO_HOLD_II';
            $world['shipyards']['X1-TEST-ROCK']['offers_after_read'] = $changed;
        });
        $before = $this->world();
        $runner->advance($run->id);
        $action = ShipAction::where('automation_run_id', $run->id)->sole();
        $this->assertSame('rejected', $action->state->value, $action->message);
        $this->assertNull($action->sent_at);
        $this->assertStringContainsString('configuration changed', $action->message);
        $after = $this->world();
        $yardReads = collect(array_slice($after['calls'], count($before['calls'])))->where('method', 'GET')
            ->filter(fn (array $call) => str_ends_with($call['path'], '/shipyard'));
        $this->assertCount(2, $yardReads);
        $this->assertSame($before['writes'], $after['writes']);
        $this->assertSame($before['credits'], $after['credits']);
    }

    #[DataProvider('releaseTypes')]
    public function test_settled_failed_attempt_blocks_the_active_purchase_and_records_the_right_yard(string $kind, string $type): void
    {
        $this->setUpSaturation(seedCoverage: $kind === 'hauler');
        $episode = $this->primary();
        if ($kind === 'probe') {
            $sync = app(AgentSynchronizer::class);
            $sync->observe($this->agent, 'market', 'X1-TEST-ROCK', app(SpaceTradersApi::class)->market($this->agent, 'X1-TEST-ROCK'));
            $sync->observe($this->agent, 'market_listing', 'X1-TEST-PORT', ['symbol' => 'X1-TEST-PORT', 'imports' => [], 'exports' => [], 'exchange' => [['symbol' => 'IRON_ORE']]]);
        }
        $this->schedulerPass();
        $runner = app(AutomationRunner::class);
        $run = $kind === 'hauler'
            ? $runner->previewHaulerPurchase($this->agent, 'LOCAL-2', 'X1-TEST-ROCK', ['minutes' => 15], $episode)
            : $runner->previewProbePurchase($this->agent, 'LOCAL-2', 'X1-TEST-ROCK', ['minutes' => 15], $episode);
        $runner->startCampaign($this->agent, $run, $episode);
        $runner->advance($run->id);
        $action = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->sole();
        $this->assertSame($type, $action->arguments['shipType']);
        $action->update(['state' => 'rejected', 'settled_at' => now(), 'outcome' => []]);
        $this->travel(10)->seconds();
        $runner->advance($run->id);

        $this->assertSame('blocked', $run->fresh()->state->value);
        $this->assertSame(ucfirst($kind).' purchase already attempted for this run.', $run->fresh()->blocked_reason);
        $this->assertSame('failed', $episode->fresh()->state[$kind.'_shipyard_rejected']['X1-TEST-ROCK']['reason']);
        $this->assertSame([$action->id], ShipAction::where('automation_run_id', $run->id)->pluck('id')->all());
    }

    #[DataProvider('competingSends')]
    public function test_late_delivery_keeps_shared_slot_while_another_send_could_explain_it(string $state, bool $overlap): void
    {
        $this->setUpSaturation();
        $episode = $this->primary();
        $bootstrap = app(CampaignProbeBootstrap::class);
        $ack = $this->haulerPurchase($episode, 'lost-hauler');
        $this->editFixture(fn (&$world) => $world['ships'][] = FakeSpaceTraders::lightHauler('LOCAL-9', 'X1-TEST-ROCK'));
        app(AgentSynchronizer::class)->sync($this->agent, false);
        $transaction = ['agentSymbol' => 'LOCAL', 'waypointSymbol' => 'X1-TEST-ROCK', 'shipType' => 'SHIP_LIGHT_HAULER',
            'timestamp' => now()->toIso8601String(), 'price' => 350000];
        if (! $overlap) {
            $this->travel(10)->seconds();
        }
        $other = $this->haulerPurchase($episode, 'competing-hauler', $state);
        if ($state === 'pending') {
            $other->update(['sent_at' => null]);
        }
        $this->editFixture(fn (&$world) => $world['transactions'][] = $transaction);

        $bootstrap->reconcileDeliveries($episode->grant(), $episode, $this->agent);
        $this->assertSame(['LOCAL-9'], $ack->fresh()->outcome['hauler_delivery_candidates']);
        $this->assertSame($overlap ? null : 'LOCAL-9', $bootstrap::deliveredShip($ack->fresh()));
        $ids = $bootstrap->reservations($this->agent)->pluck('action_id')->all();
        $this->assertSame($overlap ? [$ack->id, $other->id] : [$other->id], $ids);
        $this->assertSame($overlap ? 0 : 1, CampaignEvent::where('kind', 'hauler_purchased')->count());
    }

    public static function competingSends(): array
    {
        return [
            'uncertain overlap' => ['uncertain', true],
            'sending overlap' => ['sending', true],
            'pending overlap' => ['pending', true],
            'later uncertain send' => ['uncertain', false],
        ];
    }

    public function test_release_hauler_purchase_cli_keeps_receipt_evidence_and_the_slot(): void
    {
        $this->setUpSaturation();
        $purchase = $this->haulerPurchase($this->primary(), 'receipt-release', outcome: ['body_sha256' => hash('sha256', 'receipt')]);
        foreach ([true, false] as $dryRun) {
            $this->artisan('campaign:release-hauler-purchase', ['action' => $purchase->id, '--dry-run' => $dryRun])
                ->expectsOutputToContain('stored receipt evidence')->assertFailed();
        }
        $this->assertSame(['body_sha256' => hash('sha256', 'receipt')], $purchase->fresh()->outcome);
        $this->assertSame([$purchase->id], app(CampaignProbeBootstrap::class)->reservations($this->agent)->pluck('action_id')->all());
        $this->assertDatabaseMissing('campaign_events', ['kind' => 'hauler_purchase_released']);
    }

    #[DataProvider('v7GateTransitions')]
    public function test_v7_purchase_revalidates_each_gate_after_preview_and_at_arrival(string $gate, string $stage, string $reason): void
    {
        $this->setUpSaturation(hubs: 2);
        $episode = $this->primary();
        $grant = $episode->grant();
        $this->schedulerPass();
        $runner = app(AutomationRunner::class);
        $probe = 'LOCAL-2';
        $yard = 'X1-TEST-ROCK';
        $saturation = app(CampaignSaturation::class);
        $offer = $this->world()['shipyards'][$yard]['ships'][1];
        $initial = $saturation->evaluate($grant, $episode, $this->agent, $yard, $offer);
        $this->assertTrue($initial['can_purchase']);
        $this->assertCount(9, array_filter($initial['gates'], fn ($evidence) => $evidence['pass']));
        $run = $runner->previewHaulerPurchase($this->agent, $probe, $yard, ['minutes' => 15], $episode);
        $this->assertSame(7, $run->plan_version);
        if ($stage === 'arrival') {
            $runner->startCampaign($this->agent, $run, $episode);
            $this->assertSame('running', $run->refresh()->state->value);
        }
        match ($gate) {
            'G1' => $grant->update(['paused_at' => now(), 'pause_reason' => 'operator_pause']),
            'G2' => $this->haulerPurchase($episode, 'competing-purchase'),
            'G3' => GameObservation::where('agent_id', $this->agent->id)->where('kind', 'market_listing')->delete(),
            'G4' => app(FleetClaims::class)->claim($episode, ['source' => $yard, 'buyer' => 'X1-TEST-PORT', 'good' => 'IRON_ORE', 'ship' => 'LOCAL-1']),
            'G5' => AutomationRun::create(['agent_id' => $this->agent->id, 'active_agent_id' => $this->agent->id,
                'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-1', 'strategy' => 'trade', 'state' => 'running',
                'expires_at' => now()->addMinutes(15), 'plan' => ['max_spend' => 2000000], 'totals' => ['phase' => 'buy', 'spending' => 0]]),
            'G6' => DB::table('fleet_metrics')->where('kind', 'scheduler')->delete(),
            'G7' => config(['gameplay.host_tier2_4_per_minute' => 1]),
            'G8' => $episode->update(['next_reset_at' => now()->addHours(23)]),
            'G9' => $this->haulerPurchase($episode, 'previous-hauler', 'confirmed', ['delivered_ship' => 'LOCAL-9']),
        };
        $current = $saturation->evaluate($grant->refresh(), $episode->refresh(), $this->agent, $yard, $offer, $run->id);
        $this->assertFalse($current['gates'][$gate]['pass'], json_encode($current['gates'][$gate]));
        $before = $this->world();
        if ($stage === 'start') {
            try {
                $runner->start($this->agent, $run);
                $this->fail($gate.' was not revalidated at start.');
            } catch (GameException $exception) {
                $this->assertStringContainsString($reason, $exception->getMessage());
            }
            $this->assertNull($run->fresh()->started_at);
        } else {
            $runner->advance($run->id);
            $this->assertSame('blocked', $run->fresh()->state->value);
            $this->assertStringContainsString($reason, $run->fresh()->blocked_reason);
        }
        $this->assertDatabaseMissing('ship_actions', ['automation_run_id' => $run->id]);
        $this->assertSame($before['writes'], $this->world()['writes']);
        $this->assertSame($before['credits'], $this->world()['credits']);
    }

    public static function v7GateTransitions(): array
    {
        $cases = [
            'G1' => ['outside current C0 campaign authority', 'Campaign authority is paused'],
            'G2' => ['Shared ship purchase slot is held', 'Shared ship purchase slot is held'],
            'G3' => ['no_hubs', 'no_hubs'],
            'G4' => ['spare_corridor', 'spare_corridor'],
            'G5' => ['liquidity', 'breaches working-capital reserve'],
            'G6' => ['scheduler_evidence', 'scheduler_evidence'],
            'G7' => ['ordinary_hour_headroom', 'ordinary_hour_headroom'],
            'G8' => ['time_to_reset', 'time_to_reset'],
            'G9' => ['awaiting_auto_arm', 'awaiting_auto_arm'],
        ];
        $matrix = [];
        foreach ($cases as $gate => [$start, $arrival]) {
            $matrix[$gate.' at start'] = [$gate, 'start', $start];
            $matrix[$gate.' at arrival'] = [$gate, 'arrival', $arrival];
        }

        return $matrix;
    }

    private function purchaseIntent(): array
    {
        $this->setUpSaturation();
        $episode = $this->primary();
        $this->schedulerPass();
        $runner = app(AutomationRunner::class);
        $run = $runner->previewHaulerPurchase($this->agent, 'LOCAL-2', 'X1-TEST-ROCK', ['minutes' => 15], $episode);
        $runner->startCampaign($this->agent, $run, $episode);
        $runner->advance($run->id);

        return [$run->fresh(), ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->sole()];
    }
}
