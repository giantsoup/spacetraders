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

            public function reserve(?string $token = null, ?string $account = null, string $priority = 'discovery', float $maxWait = .75, ?float $baseMaxWait = null, ?float $deadline = null): void
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

    public function test_long_shipyard_scout_uses_the_stored_offers_price_and_configuration(): void
    {
        $this->setUpSaturation(hubs: 2, remoteYard: true);
        $yard = 'X1-TEST-YARD';
        $this->editFixture(function (&$world) use ($yard) {
            $world['waypoints'][2]['x'] = 393;
            $world['shipyards'][$yard]['ships'][1]['purchasePrice'] = 380000;
            $world['ships'][] = FakeSpaceTraders::probe('LOCAL-6', 'X1-TEST-ROCK', 5);
        });
        $sync = app(AgentSynchronizer::class);
        $sync->observe($this->agent, 'waypoint', $yard, $this->point($yard, 393, ['SHIPYARD']), 'listing');
        $sync->observe($this->agent, 'shipyard', $yard, $this->world()['shipyards'][$yard]);
        $sync->sync($this->agent, false);
        $episode = $this->primary();
        $episode->update(['next_reset_at' => now()->addHours(36)]);
        $this->schedulerPass();
        $grant = $episode->grant();
        $saturation = app(CampaignSaturation::class);
        $this->assertSame('payback', $saturation->evaluate($grant, $episode, $this->agent, $yard)['gates']['G8']['reason']);
        $considered = [];

        $run = app(CampaignHaulerPurchase::class)->plan($grant, $episode, $this->agent, ['LOCAL-1'], $considered, microtime(true) + 10);

        $this->assertNotNull($run);
        $this->assertSame(['scout', $yard, 'shipyard'], [$run->strategy, $run->plan['destination'], $run->plan['observe_kind']]);
        $gates = collect($considered)->whereNotNull('gates')->sole()['gates'];
        $this->assertTrue($gates['G4']['pass']);
        $this->assertSame(380000, $gates['G5']['price']);
        $this->assertSame(9.5, $gates['G8']['payback_hours']);
        app(AutomationRunner::class)->startCampaign($this->agent, $run, $episode);
        $this->assertSame('running', $run->fresh()->state->value);
    }

    #[DataProvider('provisionalScoutOffers')]
    public function test_long_shipyard_scout_uses_the_reference_hauler_for_expired_or_incomplete_offers(int $age, int $price, ?string $missingDetail = null): void
    {
        $this->setUpSaturation(hubs: 2, remoteYard: true);
        $yard = 'X1-TEST-YARD';
        $this->editFixture(function (&$world) {
            $world['waypoints'][2]['x'] = 393;
            $world['ships'][] = FakeSpaceTraders::probe('LOCAL-6', 'X1-TEST-ROCK', 5);
        });
        $storedYard = $this->world()['shipyards'][$yard];
        $storedYard['ships'][1]['purchasePrice'] = $price;
        if ($missingDetail === 'engine symbol') {
            unset($storedYard['ships'][1]['engine']['symbol']);
        } elseif ($missingDetail === 'second hold capacity') {
            $storedYard['ships'][1]['modules'] = [
                ['symbol' => 'MODULE_CARGO_HOLD_I', 'capacity' => 40],
                ['symbol' => 'MODULE_CARGO_HOLD_I'],
            ];
        }
        $sync = app(AgentSynchronizer::class);
        $sync->observe($this->agent, 'waypoint', $yard, $this->point($yard, 393, ['SHIPYARD']), 'listing');
        $obs = $sync->observe($this->agent, 'shipyard', $yard, $storedYard);
        $obs->update(['observed_at' => now()->subMinutes($age)]);
        $sync->sync($this->agent, false);
        $episode = $this->primary();
        $this->schedulerPass();
        $grant = $episode->grant();
        $considered = [];

        $run = app(CampaignHaulerPurchase::class)->plan($grant, $episode, $this->agent, ['LOCAL-1'], $considered, microtime(true) + 10);

        $this->assertNotNull($run);
        $this->assertSame(['scout', $yard, 'shipyard'], [$run->strategy, $run->plan['destination'], $run->plan['observe_kind']]);
        $gates = collect($considered)->whereNotNull('gates')->sole()['gates'];
        $this->assertTrue($gates['G4']['pass']);
        $this->assertSame(600000, $gates['G5']['price']);
        $this->assertTrue($gates['G5']['pass']);
        $this->assertTrue($gates['G8']['pass']);
        app(AutomationRunner::class)->startCampaign($this->agent, $run, $episode);
        $this->assertSame('running', $run->fresh()->state->value);
    }

    public static function provisionalScoutOffers(): array
    {
        return [
            'expired over-cap offer' => [31, 750000],
            'fresh incomplete in-cap offer' => [1, 350000, 'engine symbol'],
            'fresh incomplete over-cap offer' => [1, 750000, 'engine symbol'],
            'fresh over-cap offer missing second hold capacity' => [1, 750000, 'second hold capacity'],
        ];
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

    #[DataProvider('shortRemoteOffers')]
    public function test_short_remote_hauler_purchase_requires_fresh_complete_offer_detail(int $observedMinutesAgo, int $arrivalPrice, ?string $arrivalRefusal = null, ?string $missingDetail = null): void
    {
        $this->setUpSaturation(hubs: 2, remoteYard: true);
        $yard = 'X1-TEST-YARD';
        $this->editFixture(function (&$world) use ($yard, $arrivalPrice) {
            $world['waypoints'][2]['x'] = 80;
            $world['shipyards'][$yard]['ships'][1]['purchasePrice'] = $arrivalPrice;
            $world['ships'][] = FakeSpaceTraders::probe('LOCAL-6', 'X1-TEST-ROCK');
        });
        $sync = app(AgentSynchronizer::class);
        $sync->observe($this->agent, 'waypoint', $yard, $this->point($yard, 80, ['SHIPYARD']), 'listing');
        $liveYard = $this->world()['shipyards'][$yard];
        $storedYard = $liveYard;
        $storedYard['ships'][1]['purchasePrice'] = $missingDetail === null ? 750000 : 350000;
        $storedYard['ships'][1]['modules'][1]['symbol'] = 'MODULE_CARGO_HOLD_II';
        if ($missingDetail === 'engine symbol') {
            unset($storedYard['ships'][1]['engine']['symbol']);
        } elseif ($missingDetail === 'second hold capacity') {
            $storedYard['ships'][1]['modules'] = [
                ['symbol' => 'MODULE_CARGO_HOLD_I', 'capacity' => 40],
                ['symbol' => 'MODULE_CARGO_HOLD_I'],
            ];
        }
        $observation = $sync->observe($this->agent, 'shipyard', $yard, $storedYard);
        $observation->update(['observed_at' => now()->subMinutes($observedMinutesAgo)]);
        $sync->sync($this->agent, false);
        $episode = $this->primary();
        $this->schedulerPass();
        $grant = $episode->grant();
        $considered = [];

        $preview = app(CampaignHaulerPurchase::class)->plan($grant, $episode, $this->agent, ['LOCAL-1'], $considered, microtime(true) + 10);
        $gates = collect($considered)->whereNotNull('gates')->sole()['gates'];

        if ($observedMinutesAgo === 30) {
            $this->assertNull($preview);
            $this->assertSame(750000, $gates['G5']['price']);
            $this->assertSame('all_shipyards_over_price_cap', $episode->fresh()->state['saturation_hold']['reason']);
            $this->assertSame([], $this->world()['writes']);
            $this->assertTrue($observation->observed_at->equalTo($observation->fresh()->observed_at));

            return;
        }

        $this->assertNotNull($preview, json_encode($considered));
        $this->assertSame(['buy_hauler', 7, 'LOCAL-6', $yard], [$preview->strategy, $preview->plan_version, $preview->ship_symbol, $preview->plan['shipyard']]);
        $this->assertLessThanOrEqual($preview->plan['minutes'] * 60, $preview->plan['route']['outbound']['estimated_seconds'] + 180);
        $this->assertSame(600000, $gates['G5']['price']);
        $this->assertNull($preview->plan['observed_price']);
        $this->assertNull($preview->plan['offer_configuration_hash']);
        $runner = app(AutomationRunner::class);
        $runner->startCampaign($this->agent, $preview, $episode);
        $run = $preview->fresh();
        $this->assertSame('running', $run->state->value);
        $this->assertSame($preview->fingerprint, $run->fingerprint);
        $this->assertTrue($observation->observed_at->equalTo($observation->fresh()->observed_at));

        if ($arrivalRefusal === 'configuration') {
            $this->editFixture(function (&$world) use ($yard) {
                unset($world['shipyards'][$yard]['ships'][1]['engine']['symbol']);
            });
        } elseif ($arrivalRefusal === 'second hold capacity') {
            $this->editFixture(function (&$world) use ($yard) {
                $world['shipyards'][$yard]['ships'][1]['modules'] = [
                    ['symbol' => 'MODULE_CARGO_HOLD_I', 'capacity' => 40],
                    ['symbol' => 'MODULE_CARGO_HOLD_I'],
                ];
            });
        } elseif ($arrivalRefusal === 'reserve') {
            AutomationRun::create(['agent_id' => $this->agent->id, 'active_agent_id' => $this->agent->id,
                'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-1', 'strategy' => 'trade', 'state' => 'running',
                'expires_at' => now()->addMinutes(15), 'plan' => ['max_spend' => 1600000], 'totals' => ['phase' => 'buy', 'spending' => 0]]);
        }
        $this->workRun($run);
        $ship = Ship::where('agent_id', $this->agent->id)->where('symbol', $run->ship_symbol)->sole();
        $this->assertSame($yard, $ship->snapshot['nav']['waypointSymbol'], $run->blocked_reason ?? '');
        $this->assertSame($arrivalPrice, $observation->fresh()->data['ships'][1]['purchasePrice']);

        if ($arrivalRefusal !== null) {
            $this->assertSame('blocked', $run->state->value);
            $message = match ($arrivalRefusal) {
                'max_price' => '750000 CR exceeds max_price 600000 CR',
                'configuration', 'second hold capacity' => 'A complete verified hauler offer is required at arrival.',
                'reserve' => 'breaches working-capital reserve',
            };
            $this->assertStringContainsString($message, $run->blocked_reason);
            $this->assertArrayNotHasKey('offer_configuration_hash', $run->totals);
            $this->assertDatabaseMissing('ship_actions', ['automation_run_id' => $run->id, 'command' => 'buy_ship']);
            $this->assertDatabaseMissing('campaign_events', ['kind' => 'hauler_purchased']);
            $this->assertFalse(collect($this->world()['calls'])->where('method', 'POST')->contains('path', '/my/ships'));

            return;
        }

        $this->assertSame('completed', $run->state->value, $run->blocked_reason ?? '');
        $action = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->sole();
        $this->assertSame('confirmed', $action->state->value, $action->message ?? '');
        $pin = ShipCommandExecutor::configurationHash($liveYard['ships'][1]);
        $this->assertNotNull($pin);
        $this->assertSame($pin, $run->totals['offer_configuration_hash']);
        $this->assertSame($pin, $action->arguments['offer_configuration_hash']);
        $this->assertSame($arrivalPrice, $action->arguments['maxTotal']);
        $this->assertSame($arrivalPrice, CampaignEvent::where('kind', 'hauler_purchased')->sole()->data['price']);
    }

    public static function shortRemoteOffers(): array
    {
        return [
            'expired offer with lower arrival price' => [31, 350000],
            'fresh over-cap offer at the cutoff' => [30, 350000],
            'expired offer with over-cap arrival price' => [31, 750000, 'max_price'],
            'expired offer with incomplete arrival configuration' => [31, 350000, 'configuration'],
            'expired offer with insufficient liquidity at arrival' => [31, 350000, 'reserve'],
            'fresh incomplete offer with complete arrival configuration' => [1, 350000, null, 'engine symbol'],
            'fresh incomplete offer with incomplete arrival configuration' => [1, 350000, 'configuration', 'engine symbol'],
            'fresh offer missing second hold capacity' => [1, 350000, null, 'second hold capacity'],
            'expired offer with missing second hold capacity at arrival' => [31, 350000, 'second hold capacity'],
        ];
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

    public function test_at_yard_preview_refuses_a_live_offer_without_a_fuel_profile(): void
    {
        $this->setUpSaturation();
        $episode = $this->primary();
        $this->schedulerPass();
        $this->editFixture(function (&$world) {
            unset($world['shipyards']['X1-TEST-ROCK']['ships'][1]['frame']['fuelCapacity']);
        });

        try {
            app(AutomationRunner::class)->previewHaulerPurchase($this->agent, 'LOCAL-2', 'X1-TEST-ROCK', ['minutes' => 15], $episode);
            $this->fail('Expected the incomplete live offer to block preview.');
        } catch (GameException $exception) {
            $this->assertStringContainsString('A complete verified hauler offer is required at the yard.', $exception->getMessage());
        }
        $this->assertDatabaseMissing('automation_runs', ['strategy' => 'buy_hauler']);
        $this->assertDatabaseMissing('ship_actions', ['command' => 'buy_ship']);
        $this->assertSame([], $this->world()['writes']);
    }

    #[DataProvider('inlineOfferChanges')]
    public function test_inline_purchase_rereads_the_offer_after_arrival_and_prevents_unverified_send(string $change, string $state, string $message): void
    {
        $this->setUpSaturation();
        if ($change === 'second hold capacity') {
            $this->editFixture(function (&$world) {
                $world['shipyards']['X1-TEST-ROCK']['ships'][1]['modules'] = [
                    ['symbol' => 'MODULE_CARGO_HOLD_I', 'capacity' => 40],
                    ['symbol' => 'MODULE_CARGO_HOLD_I', 'capacity' => 40],
                ];
            });
        }
        $episode = $this->primary();
        $this->schedulerPass();
        $runner = app(AutomationRunner::class);
        $run = $runner->previewHaulerPurchase($this->agent, 'LOCAL-2', 'X1-TEST-ROCK', ['minutes' => 15], $episode);
        $runner->startCampaign($this->agent, $run, $episode);
        config(['gameplay.inline_execute' => true]);

        $this->editFixture(function (&$world) use ($change) {
            $changed = $world['shipyards']['X1-TEST-ROCK']['ships'];
            if ($change === 'module') {
                $changed[1]['modules'][1]['symbol'] = 'MODULE_CARGO_HOLD_II';
            } elseif ($change === 'fuel capacity') {
                unset($changed[1]['frame']['fuelCapacity']);
            } elseif ($change === 'second hold capacity') {
                unset($changed[1]['modules'][1]['capacity']);
            } else {
                unset($changed[1]['purchasePrice']);
            }
            $world['shipyards']['X1-TEST-ROCK']['offers_after_read'] = $changed;
        });
        $before = $this->world();
        $runner->advance($run->id);
        $action = ShipAction::where('automation_run_id', $run->id)->sole();
        $after = $this->world();
        $calls = collect(array_slice($after['calls'], count($before['calls'])));
        $yardReads = $calls->where('method', 'GET')->filter(fn (array $call) => str_ends_with($call['path'], '/shipyard'));
        $this->assertCount(2, $yardReads);
        $this->assertFalse($calls->where('method', 'POST')->contains('path', '/my/ships'));
        $this->assertDatabaseMissing('campaign_events', ['kind' => 'hauler_purchased']);
        $this->assertSame($state, $action->state->value, $action->message);
        $this->assertNull($action->sent_at);
        $this->assertStringContainsString($message, $action->message);
        $this->assertSame($before['writes'], $after['writes']);
        $this->assertSame($before['credits'], $after['credits']);
    }

    public static function inlineOfferChanges(): array
    {
        return [
            'different modular configuration' => ['module', 'rejected', 'Hauler offer configuration changed'],
            'same hash but missing fuel capacity' => ['fuel capacity', 'rejected', 'A complete verified hauler offer is required'],
            'same hash but missing second hold capacity' => ['second hold capacity', 'rejected', 'A complete verified hauler offer is required'],
            'same hash but missing price' => ['price', 'pending', 'SpaceTraders returned incomplete or incompatible state'],
        ];
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

    public function test_later_purchase_can_explain_the_ship_without_explaining_the_earlier_transaction(): void
    {
        $this->setUpSaturation();
        $episode = $this->primary();
        $bootstrap = app(CampaignProbeBootstrap::class);
        $transaction = ['agentSymbol' => 'LOCAL', 'waypointSymbol' => 'X1-TEST-ROCK', 'shipType' => 'SHIP_LIGHT_HAULER',
            'timestamp' => now()->toIso8601String(), 'price' => 350000];
        $earlier = $this->haulerPurchase($episode, 'earlier-purchase', outcome: ['transactions' => [$transaction]]);
        $this->editFixture(fn (&$world) => $world['ships'][] = FakeSpaceTraders::lightHauler('LOCAL-8', 'X1-TEST-PORT'));
        $sync = app(AgentSynchronizer::class);
        $sync->sync($this->agent, false);
        $this->travel(10)->seconds();
        $later = $this->haulerPurchase($episode, 'later-purchase', 'uncertain');
        $this->editFixture(fn (&$world) => $world['ships'][] = FakeSpaceTraders::lightHauler('LOCAL-9', 'X1-TEST-ROCK'));
        $sync->sync($this->agent, false);

        $bootstrap->reconcileDeliveries($episode->grant(), $episode, $this->agent);

        $this->assertSame(['LOCAL-9'], $earlier->fresh()->outcome['hauler_delivery_candidates']);
        $this->assertNull($bootstrap::deliveredShip($earlier));
        $this->assertFalse(app(CampaignControl::class)->autoArmCandidates($episode, $this->agent)->contains('symbol', 'LOCAL-9'));
        $this->assertSame([$earlier->id, $later->id], $bootstrap->reservations($this->agent)->pluck('action_id')->all());
        $this->assertFalse(CampaignEvent::where('kind', 'hauler_purchased')->exists());
    }

    public function test_cross_yard_outstanding_purchase_keeps_ship_ambiguous_after_movement(): void
    {
        $this->setUpSaturation(hubs: 2, remoteYard: true);
        $episode = $this->primary();
        $bootstrap = app(CampaignProbeBootstrap::class);
        $transaction = ['agentSymbol' => 'LOCAL', 'waypointSymbol' => 'X1-TEST-ROCK', 'shipType' => 'SHIP_LIGHT_HAULER',
            'timestamp' => now()->toIso8601String(), 'price' => 350000];
        $yardA = $this->haulerPurchase($episode, 'yard-a-ack', outcome: ['transactions' => [$transaction]]);
        $this->editFixture(fn (&$world) => $world['ships'][] = FakeSpaceTraders::lightHauler('LOCAL-8', 'X1-TEST-PORT'));
        $sync = app(AgentSynchronizer::class);
        $sync->sync($this->agent, false);
        $this->travel(10)->seconds();
        $yardB = $this->haulerPurchase($episode, 'yard-b-uncertain', 'uncertain');
        $yardB->update(['arguments' => array_replace($yardB->arguments, ['waypointSymbol' => 'X1-TEST-YARD'])]);
        $this->editFixture(fn (&$world) => $world['ships'][] = FakeSpaceTraders::lightHauler('LOCAL-9', 'X1-TEST-ROCK'));
        $sync->sync($this->agent, false);

        $bootstrap->reconcileDeliveries($episode->grant(), $episode, $this->agent);

        $this->assertSame(['LOCAL-9'], $yardA->fresh()->outcome['hauler_delivery_candidates']);
        $this->assertNull($bootstrap::deliveredShip($yardA));
        $this->assertFalse(app(CampaignControl::class)->autoArmCandidates($episode, $this->agent)->contains('symbol', 'LOCAL-9'));
        $this->assertSame([$yardA->id, $yardB->id], $bootstrap->reservations($this->agent)->pluck('action_id')->all());
        $this->assertFalse(CampaignEvent::where('kind', 'hauler_purchased')->exists());
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
