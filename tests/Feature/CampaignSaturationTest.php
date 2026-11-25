<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignHaulerPurchase;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\CampaignProbeBootstrap;
use App\Gameplay\CampaignSaturation;
use App\Gameplay\CampaignStatus;
use App\Gameplay\CampaignTrade;
use App\Gameplay\FleetClaims;
use App\Gameplay\GameException;
use App\Gameplay\HostLock;
use App\Gameplay\RequestBudget;
use App\Gameplay\SpaceTradersApi;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\LocalEngine;
use App\Models\ShipAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\FakeSpaceTraders;
use Tests\Support\SaturationFixture;
use Tests\TestCase;

class CampaignSaturationTest extends TestCase
{
    use RefreshDatabase;
    use SaturationFixture;

    protected function tearDown(): void
    {
        $this->tearDownSaturation();
        parent::tearDown();
    }

    public function test_candidate_and_active_status_compute_gates_with_zero_sql_writes_or_api_reads(): void
    {
        $this->setUpSaturation();
        $episode = $this->primary();
        $this->schedulerPass();
        $this->manualAcknowledged();
        $candidateBody = $this->revision->body;
        $candidateBody['fleet']['haulers']['min_hub_coverage'] = .75;
        $candidate = app(CampaignPlaybook::class)->import(Yaml::dump($candidateBody, 12));
        $meshCandidateBody = Yaml::parseFile(base_path('campaigns/weekly-system-saturation-mesh.yaml'));
        $meshCandidateBody['agent']['symbol'] = 'LOCAL';
        $meshCandidateBody['fleet']['auto_arm']['revision_hash'] = $this->armRevision->content_hash;
        $meshCandidate = app(CampaignPlaybook::class)->import(Yaml::dump($meshCandidateBody, 12));
        app(AgentSynchronizer::class)->observe($this->agent, 'waypoint', 'X1-TEST-FUEL', $this->point('X1-TEST-FUEL', 11), 'listing');
        app(AgentSynchronizer::class)->observe($this->agent, 'market_listing', 'X1-TEST-FUEL', ['imports' => [], 'exports' => [], 'exchange' => [['symbol' => 'FUEL']]]);
        app(AgentSynchronizer::class)->observe($this->agent, 'waypoint', 'X1-TEST-PROVISIONAL', $this->point('X1-TEST-PROVISIONAL', 12), 'listing');
        $calls = $this->world()['calls'];
        DB::enableQueryLog();
        DB::flushQueryLog();
        $active = app(CampaignStatus::class)->saturation($this->operator->id, 'LOCAL');
        $simulation = app(CampaignStatus::class)->saturation($this->operator->id, 'LOCAL', $candidate);
        $meshSimulation = app(CampaignStatus::class)->saturation($this->operator->id, 'LOCAL', $meshCandidate);
        $writes = array_filter(DB::getQueryLog(), fn ($row) => ! preg_match('/^\s*select\b/i', $row['query']));
        DB::disableQueryLog();

        $this->assertSame([], array_values($writes));
        $this->assertSame($calls, $this->world()['calls']);
        $this->assertSame([], $episode->fresh()->state);
        $this->assertSame('shared_purchase_in_flight', $active['gates']['G2']['reason']);
        $this->assertTrue($active['active_purchase_authority']);
        $this->assertTrue($simulation['active_purchase_authority']);
        $this->assertFalse($simulation['can_purchase']);
        $this->assertSame(3, $simulation['gates']['G3']['required']);
        $this->assertSame(4, $active['gates']['G3']['required']);
        $this->assertSame(4, $active['mesh']['pool_probe_target']);
        $this->assertSame(6, $meshSimulation['mesh']['pool_probe_target']);
        $this->assertSame('shared_purchase_in_flight', $meshSimulation['gates']['G2']['reason']);
        $this->assertTrue($meshSimulation['active_purchase_authority']);
        $this->assertFalse($meshSimulation['can_purchase']);
    }

    public function test_spare_corridor_projects_proof_and_excludes_c0s_claim(): void
    {
        $this->setUpSaturation();
        $episode = $this->primary();
        $this->schedulerPass();
        $saturation = app(CampaignSaturation::class);
        $result = $saturation->evaluate($episode->grant(), $episode, $this->agent);
        $this->assertSame('proof', $result['gates']['G4']['mode']);
        $this->assertSame(20, $result['gates']['G4']['units']);
        $this->assertTrue($result['gates']['G4']['pass']);
        $this->assertSame(300000, $result['gates']['G5']['reserve_components']['working_capital']);

        app(FleetClaims::class)->claim($episode, ['source' => 'X1-TEST-ROCK', 'buyer' => 'X1-TEST-PORT', 'good' => 'IRON_ORE', 'ship' => 'LOCAL-1']);
        $held = $saturation->evaluate($episode->grant(), $episode->refresh(), $this->agent);
        $this->assertSame('spare_corridor', $held['gates']['G4']['reason']);
        $this->assertContains('C0', $held['gates']['G4']['excluded_claims']);
        $saturation->record($episode->grant(), $episode, $held);
        $saturation->record($episode->grant(), $episode, $saturation->evaluate($episode->grant(), $episode, $this->agent));
        $this->assertSame(1, CampaignEvent::where('kind', 'saturation')->count());
        $this->assertNotEmpty($episode->state['saturation_hold']['held_until']);
    }

    public function test_hauler_opportunity_preloads_primary_sales_once_across_corridors(): void
    {
        $this->setUpSaturation();
        $episode = $this->primary();
        $this->schedulerPass();
        $trade = app(CampaignTrade::class);
        $run = AutomationRun::create([
            'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'campaign_episode_id' => $episode->id,
            'ship_symbol' => 'LOCAL-1', 'strategy' => 'trade', 'state' => 'completed', 'plan_version' => 1,
            'plan' => [], 'totals' => [], 'expires_at' => now(),
        ]);
        $port = 'X1-TEST-PORT';
        $second = 'X1-TEST-H3';
        $bid = GameObservation::where('kind', 'market')->where('symbol', $port)->sole()->data;
        $bid['tradeGoods'][0]['sellPrice'] = 1000;
        app(AgentSynchronizer::class)->observe($this->agent, 'market', $second, ['symbol' => $second] + $bid);
        foreach ([[$port, 8000], [$port, 4000], [$second, 1000], [$second, 800]] as $index => [$buyer, $price]) {
            ShipAction::create([
                'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'key' => 'yard-sales-'.$index,
                'automation_run_id' => $run->id, 'ship_symbol' => $run->ship_symbol, 'origin' => 'automation',
                'command' => 'sell', 'state' => 'confirmed', 'arguments' => ['symbol' => 'IRON_ORE'], 'expires_at' => now(),
                'before_state' => ['ship' => ['nav' => ['waypointSymbol' => $buyer]], 'estimate' => ['quote' => ['tradeVolume' => 20]]],
                'outcome' => ['units' => 20, 'transaction_total' => 20 * $price],
            ]);
        }
        $expected = $trade->decay($episode, 'IRON_ORE', $port);
        $this->assertSame(0.5, $expected);
        $reads = 0;
        DB::listen(function ($query) use (&$reads) {
            if (str_contains($query->sql, '"ship_actions"') && in_array('sell', $query->bindings, true)) {
                $reads++;
            }
        });
        $evaluation = app(CampaignSaturation::class)->evaluate($episode->grant(), $episode, $this->agent, 'X1-TEST-ROCK');
        $this->assertSame(1, $reads);
        $this->assertSame($port, $evaluation['gates']['G4']['buyer']);
        $this->assertSame($expected, $evaluation['gates']['G4']['decay']);
    }

    public function test_zero_hubs_and_missing_scheduler_evidence_are_explicit(): void
    {
        $this->setUpSaturation(seedCoverage: false);
        $episode = $this->primary();
        $result = app(CampaignSaturation::class)->evaluate($episode->grant(), $episode, $this->agent);
        $this->assertSame('no_hubs', $result['gates']['G3']['reason']);
        $this->assertNull($result['gates']['G3']['fraction']);
        $this->assertSame('scheduler_evidence', $result['gates']['G6']['reason']);
        $this->assertNull($result['gates']['G6']['median_dispatch_lag_seconds']);
    }

    public function test_scheduler_uses_combined_dispatch_samples_nearest_rank_and_latest_run_finish(): void
    {
        $this->setUpSaturation();
        $this->editFixture(fn (&$world) => $world['ships'][] = FakeSpaceTraders::lightHauler('LOCAL-9', 'X1-TEST-ROCK'));
        app(AgentSynchronizer::class)->sync($this->agent, false);
        $arm = $this->arm('LOCAL-9', 'H1');
        $episode = $this->seedArmEpisode($arm);
        $this->travel(200)->seconds();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        $row = ['kind' => 'scheduler', 'scope' => 'host', 'value' => 0, 'created_at' => now()];
        $session = LocalEngine::find(1)->session_id;
        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 20, 90] as $depth) {
            DB::table('fleet_metrics')->insert($row + ['data' => json_encode(['engine_session_id' => $session, 'queue_depth' => $depth, 'dispatch_lags' => [10, 20]])]);
        }
        $policy = $this->revision->body['fleet']['haulers']['scheduler'];
        $service = app(CampaignSaturation::class);
        $first = $service->scheduler($this->agent, collect([$arm]), $policy);
        $this->assertSame('idle_arm_wait', $first['reason']);
        $this->assertSame(20, $first['p90_queue_depth']);
        $this->assertSame(15.0, $first['median_dispatch_lag_seconds']);
        AutomationRun::create(['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'campaign_episode_id' => $episode->id,
            'ship_symbol' => 'LOCAL-9', 'strategy' => 'scout', 'state' => 'completed', 'started_at' => now()->subMinute(), 'expires_at' => now(),
            'plan' => [], 'totals' => ['finished_at' => now()->subSeconds(15)->toIso8601String()]]);
        $this->assertTrue($service->scheduler($this->agent, collect([$arm]), $policy)['pass']);
    }

    public function test_mature_earlier_auto_arm_stops_expansion_while_later_arm_is_still_in_its_window(): void
    {
        $this->setUpSaturation();
        $this->editFixture(function (&$world) {
            $world['ships'][] = FakeSpaceTraders::lightHauler('LOCAL-9', 'X1-TEST-ROCK');
            $world['ships'][] = FakeSpaceTraders::lightHauler('LOCAL-A', 'X1-TEST-ROCK');
        });
        app(AgentSynchronizer::class)->sync($this->agent, false);
        $episode = $this->primary();
        $lock = app(HostLock::class);
        $lock->run('campaign', fn () => $lock->run('agent-'.$this->agent->id,
            fn () => app(CampaignControl::class)->autoArm($episode->grant(), $episode, $this->agent)));
        [$earlier, $later] = CampaignGrant::where('limits->arm->automatic', true)->orderBy('id')->get()->all();
        $earlyEpisode = $this->seedArmEpisode($earlier);
        $this->load($earlyEpisode, -186, 300000, 1);
        $this->load($earlyEpisode, -40, 10000, 2);
        $this->load($this->seedArmEpisode($later), -90, 500000, 3);
        app(CampaignControl::class)->revokeArm($earlier);
        $saturation = app(CampaignSaturation::class);
        $result = $saturation->evaluate($episode->grant(), $episode, $this->agent);
        $this->assertSame('marginal_yield', $result['hold']['reason']);
        $this->assertSame($earlier->id, $result['hold']['evidence']['grant_id']);
        $this->assertTrue($result['hold']['permanent']);
        $saturation->record($episode->grant(), $episode, $result);
        $this->assertSame('marginal_yield', $episode->fresh()->state['saturation_hold']['reason']);
    }

    public function test_nonpositive_measured_reference_yield_prevents_payback_assumption(): void
    {
        $this->setUpSaturation();
        $this->editFixture(fn (&$world) => $world['ships'][] = FakeSpaceTraders::lightHauler('LOCAL-9', 'X1-TEST-ROCK'));
        app(AgentSynchronizer::class)->sync($this->agent, false);
        $episode = $this->primary();
        $arm = $this->arm('LOCAL-9', 'H1');
        $armEpisode = $this->seedArmEpisode($arm);
        $this->load($armEpisode, -240, 10000, 1);
        $this->load($armEpisode, -10, -1500, 2);
        $result = app(CampaignSaturation::class)->evaluate($episode->grant(), $episode, $this->agent);
        $this->assertFalse($result['gates']['G8']['assumed']);
        $this->assertSame('nonpositive_reference_yield', $result['gates']['G8']['reason']);
    }

    public function test_fuel_safe_return_cannot_be_replaced_by_a_different_continuation(): void
    {
        $this->setUpSaturation(hubs: 2);
        $episode = $this->primary();
        $sync = app(AgentSynchronizer::class);
        $rock = $this->world()['goods'];
        $sync->observe($this->agent, 'market', 'X1-TEST-ROCK', [
            'symbol' => 'X1-TEST-ROCK', 'imports' => [], 'exports' => [], 'exchange' => [['symbol' => 'IRON_ORE']],
            'tradeGoods' => [$rock[0]],
        ]);
        $port = $this->world()['market_goods']['X1-TEST-PORT'];
        $sync->observe($this->agent, 'market', 'X1-TEST-PORT', [
            'symbol' => 'X1-TEST-PORT', 'imports' => [], 'exports' => [], 'exchange' => [['symbol' => 'IRON_ORE'], ['symbol' => 'FUEL']],
            'tradeGoods' => $port,
        ]);
        $result = app(CampaignSaturation::class)->evaluate($episode->grant(), $episode, $this->agent);
        $this->assertSame('spare_corridor', $result['gates']['G4']['reason']);
        $this->assertStringContainsString('starting fuel seller', $result['gates']['G4']['last_refusal']);
    }

    public function test_idle_wall_hour_unions_actions_with_runs_and_excludes_recovery(): void
    {
        $this->setUpSaturation();
        $this->editFixture(fn (&$world) => $world['ships'][] = FakeSpaceTraders::lightHauler('LOCAL-9', 'X1-TEST-ROCK'));
        app(AgentSynchronizer::class)->sync($this->agent, false);
        $primary = $this->primary();
        $arm = $this->arm('LOCAL-9', 'H1');
        $episode = $this->seedArmEpisode($arm);
        $episode->update(['opened_at' => now()->subMinutes(90)]);
        AutomationRun::create(['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'campaign_episode_id' => $episode->id,
            'ship_symbol' => 'LOCAL-9', 'strategy' => 'scout', 'state' => 'completed', 'started_at' => now()->subHour(), 'expires_at' => now()->subMinutes(15),
            'plan' => [], 'totals' => ['finished_at' => now()->subMinutes(15)->toIso8601String()]]);
        $action = ShipAction::create(['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-9',
            'key' => 'arm-busy-window', 'origin' => 'manual', 'command' => 'dock', 'state' => 'confirmed', 'arguments' => [],
            'created_at' => now()->subMinutes(30), 'settled_at' => now()->subMinutes(10), 'expires_at' => now()->subMinutes(10)]);
        $assess = fn () => app(CampaignSaturation::class)->evaluate($primary->grant(), $primary, $this->agent)['gates']['G4'];
        $this->assertEqualsWithDelta(1 / 6, $assess()['idle']['by_arm']['H1'], .0001);
        $action->delete();
        $this->assertSame(.25, $assess()['idle']['maximum']);
        $this->assertSame('idle_share', $assess()['reason']);
        $episode->update(['state' => ['recovering_since' => now()->toIso8601String()]]);
        $this->assertEquals(0.0, $assess()['idle']['maximum']);
    }

    #[DataProvider('idleHoldOrigins')]
    public function test_hauler_plan_records_and_recovers_idle_holds_before_buying(string $origin): void
    {
        $this->setUpSaturation();
        $this->editFixture(fn (&$world) => $world['ships'][] = FakeSpaceTraders::lightHauler('LOCAL-9', 'X1-TEST-ROCK'));
        app(AgentSynchronizer::class)->sync($this->agent, false);
        $episode = $this->primary();
        $grant = $episode->grant();
        $arm = $this->seedArmEpisode($this->arm('LOCAL-9', 'H1'));
        $arm->update(['opened_at' => now()->subMinutes(90)]);
        $this->schedulerPass();

        $saturation = app(CampaignSaturation::class);
        $plan = app(CampaignHaulerPurchase::class);
        $considered = [];
        $hold = ['gate' => 'G4', 'reason' => 'spare_corridor', 'minutes' => null, 'permanent' => false];
        if ($origin === 'yard') {
            $episode->update(['state' => ['saturation_hold' => $hold]]);
        } elseif ($origin === 'worker') {
            try {
                $saturation->assertPurchase($grant, $episode, $this->agent, 'X1-TEST-ROCK', persist: true);
                $this->fail('The worker must refuse a high-idle purchase.');
            } catch (GameException $exception) {
                $this->assertStringContainsString('idle_share', $exception->getMessage());
                $this->assertSame('offer_independent', $episode->refresh()->state['saturation_hold']['scope']);
            }
        }

        $calls = $this->world()['calls'];
        $busy = ['LOCAL-1', 'LOCAL-2', 'LOCAL-3', 'LOCAL-4', 'LOCAL-5', 'LOCAL-9'];
        $this->assertNull($plan->plan($grant, $episode, $this->agent, $busy, $considered, microtime(true) + 10));
        $this->assertSame($calls, $this->world()['calls']);
        $this->assertSame('idle_share', $considered[0]['gates']['G4']['reason']);
        $expected = $origin === 'yard' ? $hold : ['permanent' => false, 'reason' => 'idle_share', 'gate' => 'G4', 'minutes' => null, 'scope' => 'offer_independent'];
        $this->assertSame($expected, $episode->refresh()->state['saturation_hold']);

        $full = $saturation->evaluate($grant, $episode, $this->agent, 'X1-TEST-ROCK');
        $this->assertSame('idle_share', $full['gates']['G4']['limiting_factor']);
        $this->assertSame([], $full['gates']['G4']['excluded_claims']);
        $this->assertSame('no_eligible_yard', $saturation->evaluate($grant, $episode, $this->agent, candidateYards: [])['gates']['G4']['reason']);

        $cashConstrained = clone $this->agent;
        $cashConstrained->credits = 400000;
        $limited = $saturation->evaluate($grant, $episode, $cashConstrained, 'X1-TEST-ROCK');
        $this->assertSame('idle_share', $limited['gates']['G4']['reason']);
        $this->assertSame('G5', $limited['hold']['gate']);

        $arm->update(['state' => ['recovering_since' => now()->toIso8601String()]]);
        $events = CampaignEvent::where('kind', 'saturation')->count();
        $this->assertNull($plan->plan($grant, $episode, $this->agent, $busy, $considered, microtime(true) + 10));
        $this->assertSame($origin === 'yard' ? $hold : null, $episode->refresh()->state['saturation_hold'] ?? null);
        $this->assertSame($events + ($origin === 'yard' ? 0 : 1), CampaignEvent::where('kind', 'saturation')->count());
        $saturation->record($grant, $episode, $saturation->evaluate($grant, $episode, $this->agent, offerIndependentOnly: true));
        $this->assertSame($events + ($origin === 'yard' ? 0 : 1), CampaignEvent::where('kind', 'saturation')->count());
    }

    public static function idleHoldOrigins(): array
    {
        return [['director'], ['worker'], ['yard']];
    }

    #[DataProvider('timedSaturationHolds')]
    public function test_recovered_idle_share_clears_only_legacy_idle_holds(string $path, string $reason): void
    {
        $this->setUpSaturation(hubs: 2);
        $episode = $this->primary();
        $grant = $episode->grant();
        $this->schedulerPass();
        $hold = ['gate' => 'G4', 'reason' => $reason, 'minutes' => 60, 'held_until' => now()->addMinutes(45)->toIso8601String()];
        if ($reason !== 'idle_share') {
            $hold['permanent'] = false;
        }
        $episode->update(['state' => ['saturation_hold' => $hold]]);
        $expected = $reason === 'idle_share' ? null : $hold;
        $yard = 'X1-TEST-ROCK';
        if ($path === 'full') {
            app(AgentSynchronizer::class)->observe($this->agent, 'shipyard', $yard, $this->world()['shipyards'][$yard]);
        }
        $calls = $this->world()['calls'];

        if ($path === 'bootstrap') {
            $busy = array_column($this->world()['ships'], 'symbol');
            $considered = [];
            $this->assertNull(app(CampaignProbeBootstrap::class)->plan($grant, $episode, $this->agent, $busy, $considered,
                microtime(true) + 10, CampaignHaulerPurchase::TYPE));
        } else {
            $saturation = app(CampaignSaturation::class);
            $evaluation = $saturation->evaluate($grant, $episode, $this->agent, offerIndependentOnly: $path !== 'full');
            $this->assertSame($expected, $evaluation['hold']);
            $saturation->record($grant, $episode, $evaluation);
        }

        $this->assertSame($expected, $episode->refresh()->state['saturation_hold'] ?? null);
        $this->assertSame($calls, $this->world()['calls']);
        if ($expected === null) {
            $this->assertArrayNotHasKey('saturation_hold', $episode->state);
            $event = CampaignEvent::where('kind', 'saturation')->sole();
            $this->assertSame('idle_share', $event->data['previous_reason']);
            $this->assertNull($event->data['hold']);
        } else {
            $this->assertDatabaseMissing('campaign_events', ['kind' => 'saturation']);
        }
    }

    public static function timedSaturationHolds(): array
    {
        return [
            'full clears idle hold' => ['full', 'idle_share'],
            'offer-independent clears idle hold' => ['offer-independent', 'idle_share'],
            'bootstrap clears idle hold with busy ships' => ['bootstrap', 'idle_share'],
            'full preserves yard hold' => ['full', 'no_unclaimed_corridor'],
            'offer-independent preserves yard hold' => ['offer-independent', 'no_unclaimed_corridor'],
            'bootstrap preserves yard hold' => ['bootstrap', 'no_unclaimed_corridor'],
        ];
    }

    public function test_request_envelope_uses_ordinary_attribution_and_independent_host_pressure(): void
    {
        $this->setUpSaturation();
        $this->editFixture(fn (&$world) => $world['ships'][] = FakeSpaceTraders::lightHauler('LOCAL-9', 'X1-TEST-ROCK'));
        app(AgentSynchronizer::class)->sync($this->agent, false);
        $this->arm('LOCAL-9', 'H1');
        $budget = new class extends RequestBudget
        {
            public int $hour = 4288;

            public int $minute = 76;

            public int $limits = 2;

            public function usage(?string $token = null, ?string $account = null, ?string $agentSymbol = null, bool $requireTelemetry = false): array
            {
                return ['agent' => ['tier2_4_last_hour' => $this->hour], 'host' => ['tier2_4_last_60_seconds' => $this->minute, 'rate_limits_last_15_minutes' => $this->limits]];
            }
        };
        app()->instance(RequestBudget::class, $budget);
        DB::table('fleet_metrics')->insert(['kind' => 'requests', 'agent_symbol' => 'LOCAL', 'ship' => 'LOCAL-9', 'scope' => 'agent',
            'value' => 405, 'data' => json_encode(['ordinary' => 320]), 'created_at' => now()->subMinutes(25)]);
        $policy = $this->revision->body['fleet']['haulers'];
        $check = fn () => app(CampaignSaturation::class)->requests($this->agent, $policy);
        $this->assertTrue($check()['pass']);
        $this->assertSame(320, $check()['projected_arm_requests']);
        $budget->hour++;
        $this->assertSame('ordinary_hour_headroom', $check()['reason']);
        $budget->hour--;
        $budget->minute++;
        $this->assertSame('ordinary_host_minute_headroom', $check()['reason']);
        $budget->minute--;
        $budget->limits++;
        $this->assertSame('host_rate_limits', $check()['reason']);
    }

    #[DataProvider('incumbentLifecycle')]
    public function test_paired_incumbent_yield_uses_arms_active_through_both_windows(?string $pause, int $minute = 0): void
    {
        $this->setUpSaturation();
        $this->editFixture(function (&$world) {
            $world['ships'][] = FakeSpaceTraders::lightHauler('LOCAL-9', 'X1-TEST-ROCK');
            $world['ships'][] = FakeSpaceTraders::lightHauler('LOCAL-A', 'X1-TEST-ROCK');
        });
        app(AgentSynchronizer::class)->sync($this->agent, false);
        $primary = $this->primary();
        $incumbent = $this->arm('LOCAL-9', 'H1');
        $incumbentEpisode = $this->seedArmEpisode($incumbent);
        $incumbentEpisode->update(['opened_at' => now()->subHours(8)]);
        $this->load($incumbentEpisode, -380, 10000, 1);
        $this->load($incumbentEpisode, -280, 150000, 2);
        $this->load($incumbentEpisode, -80, 60000, 3);
        if ($pause) {
            $now = now();
            $this->travelTo($now->copy()->addMinutes($minute));
            $control = app(CampaignControl::class);
            if ($pause === 'pause') {
                $control->pause($incumbent, 'operator_pause', $incumbentEpisode);
            } else {
                $control->drainPause($incumbent, 'operator_pause', $incumbentEpisode);
            }
            $this->travel(20)->minutes();
            $control->resumeArm($incumbent);
            $this->travelTo($now);
            $this->assertNull($incumbent->fresh()->paused_at);
            $this->assertFalse($incumbent->fresh()->isDraining());
        }
        $new = CampaignGrant::create(['campaign_revision_id' => $this->armRevision->id, 'revision_hash' => $this->armRevision->content_hash,
            'user_id' => $this->operator->id, 'slot' => null, 'limits' => $this->armRevision->body['limits'] + ['arm' => [
                'ship' => 'LOCAL-A', 'label' => 'H2', 'reset_id' => $this->agent->reset_id, 'automatic' => true, 'purchase_action_id' => 123,
            ]]]);
        $newEpisode = $this->seedArmEpisode($new);
        $newEpisode->update(['opened_at' => now()->subHours(4)]);
        $this->load($newEpisode, -185, 10000, 4);
        $this->load($newEpisode, -35, 100000, 5);
        $service = app(CampaignSaturation::class);
        $result = $service->evaluate($primary->grant(), $primary, $this->agent);
        if ($pause) {
            $this->assertNotSame('incumbent_yield', $result['hold']['reason'] ?? null);
            $service->record($primary->grant(), $primary, $result);
            $this->assertArrayNotHasKey('saturation_yield_reviews', $primary->fresh()->state);

            return;
        }
        $this->assertSame('incumbent_yield', $result['hold']['reason']);
        $this->assertEquals(50000, $result['hold']['evidence']['before']);
        $this->assertEquals(20000, $result['hold']['evidence']['after']);
        $service->record($primary->grant(), $primary, $result);
        $this->assertTrue($primary->fresh()->state['saturation_yield_reviews'][123]);
        $this->assertSame(1, CampaignEvent::where('kind', 'saturation')->count());
        $this->travel(181)->minutes();
        $this->assertNotSame('incumbent_yield', $service->evaluate($primary->grant(), $primary->refresh(), $this->agent)['hold']['reason']);
    }

    public static function incumbentLifecycle(): array
    {
        return [
            'continuously active' => [null],
            'pause in pre-window' => ['pause', -270],
            'pause in post-window' => ['pause', -150],
            'drain starts before and overlaps pre-window' => ['drain_pause', -375],
        ];
    }

    #[DataProvider('earlyPurchaseHolds')]
    public function test_held_c0_purchase_pass_skips_all_shipyard_and_sync_reads(string $reason): void
    {
        $this->setUpSaturation(credits: $reason === 'liquidity' ? 300000 : 2200000);
        $episode = $this->primary();
        $this->schedulerPass();
        if ($reason === 'time_to_reset') {
            $episode->update(['next_reset_at' => now()->addHours(23)]);
        } elseif ($reason === 'awaiting_first_load') {
            $this->editFixture(fn (&$world) => $world['ships'][] = FakeSpaceTraders::lightHauler('LOCAL-9', 'X1-TEST-ROCK'));
            app(AgentSynchronizer::class)->sync($this->agent, false);
            $action = $this->haulerPurchase($episode, 'awaiting-first-sale', 'confirmed', ['delivered_ship' => 'LOCAL-9']);
            $lock = app(HostLock::class);
            $lock->run('campaign', fn () => $lock->run('agent-'.$this->agent->id, fn () => app(CampaignControl::class)->autoArm($episode->grant(), $episode, $this->agent)));
            $this->assertDatabaseHas('campaign_grants', ['limits->arm->purchase_action_id' => $action->id]);
        }
        $before = $this->world()['calls'];
        $considered = [];
        $grant = $episode->grant();

        $this->assertNull(app(CampaignHaulerPurchase::class)->plan($grant, $episode, $this->agent, [], $considered, microtime(true) + 10));
        $this->assertSame($reason, $episode->fresh()->state['saturation_hold']['reason']);
        $this->assertSame($before, $this->world()['calls']);
        $this->assertSame($reason, $considered[0]['hold']['reason']);
        try {
            app(AutomationRunner::class)->previewHaulerPurchase($this->agent, 'LOCAL-2', 'X1-TEST-ROCK', ['minutes' => 15], $episode);
            $this->fail('A held purchase produced a preview.');
        } catch (GameException $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage());
        }
        $this->assertSame($before, $this->world()['calls']);
        $this->assertFalse(AutomationRun::where('strategy', 'buy_hauler')->whereIn('state', ['preview', 'running'])->exists());
    }

    public static function earlyPurchaseHolds(): array
    {
        return [['awaiting_first_load'], ['time_to_reset'], ['liquidity']];
    }

    public function test_offer_liquidity_holds_once_and_c0_records_the_driving_gates(): void
    {
        $this->setUpSaturation(credits: 450000);
        $episode = $this->primary();
        $grant = $episode->grant();
        $this->schedulerPass();
        $yard = 'X1-TEST-ROCK';
        app(AgentSynchronizer::class)->observe($this->agent, 'shipyard', $yard, $this->world()['shipyards'][$yard]);
        $before = $this->world()['calls'];
        $buyer = app(CampaignHaulerPurchase::class);

        for ($pass = 0; $pass < 2; $pass++) {
            $considered = [];
            $this->assertNull($buyer->plan($grant, $episode, $this->agent, [], $considered, microtime(true) + 10));
            $snapshot = collect($considered)->whereNotNull('gates')->sole();
            $this->assertSame('liquidity', $snapshot['hold']['reason']);
            $this->assertSame([350000, 300000], [$snapshot['gates']['G5']['price'], $snapshot['gates']['G5']['reserve']]);
            $this->assertSame(1, CampaignEvent::where('kind', 'saturation')->count());
        }
        $this->assertSame($before, $this->world()['calls']);
        $saturation = app(CampaignSaturation::class);
        $saturation->record($grant, $episode, $saturation->evaluate($grant, $episode, $this->agent, offerIndependentOnly: true));
        $this->assertSame('liquidity', $episode->fresh()->state['saturation_hold']['reason']);
        $this->assertSame(1, CampaignEvent::where('kind', 'saturation')->count());

        $this->tickC0();
        $this->assertSame(1, CampaignEvent::where('kind', 'saturation')->count());
        $decision = CampaignEvent::where('kind', 'decision')->latest('id')->firstOrFail();
        $this->assertSame('liquidity', $decision->data['saturation']['hold']['reason']);
        $this->assertSame('liquidity', $decision->data['saturation_reasons']['G5']);
        $this->assertSame(350000, $decision->data['saturation']['gates']['G5']['price']);
    }

    public function test_estimated_purchase_fuel_counts_toward_liquidity_before_preview(): void
    {
        $this->setUpSaturation(hubs: 2, remoteYard: true, credits: 650000);
        $yard = 'X1-TEST-YARD';
        $sync = app(AgentSynchronizer::class);
        $sync->observe($this->agent, 'waypoint', $yard, $this->point($yard, 8, ['SHIPYARD']), 'listing');
        $sync->observe($this->agent, 'shipyard', $yard, $this->world()['shipyards'][$yard]);
        $episode = $this->primary();
        $this->schedulerPass();
        $before = $this->world()['calls'];
        $considered = [];

        $this->assertNull(app(CampaignHaulerPurchase::class)->plan($episode->grant(), $episode, $this->agent,
            ['LOCAL-2', 'LOCAL-4'], $considered, microtime(true) + 10));
        $snapshot = collect($considered)->whereNotNull('gates')->sole();
        $gate = $snapshot['gates']['G5'];
        $this->assertSame('liquidity', $snapshot['hold']['reason']);
        $this->assertSame($gate['available_credits'] - $gate['reserve'], $gate['price']);
        $this->assertGreaterThan(0, $gate['purchase_fuel_ceiling']);
        $this->assertSame($before, $this->world()['calls']);
    }

    public function test_c0_purchase_decision_records_the_selected_offers_corridor_and_payback(): void
    {
        $this->setUpSaturation(hubs: 2);
        $this->editFixture(fn (&$world) => $world['shipyards']['X1-TEST-ROCK']['ships'][1]['purchasePrice'] = 380000);
        $body = $this->revision->body;
        $body['phases'][1]['activities'][0]['when'] = ['credits_gte' => 2300000];
        $this->primary(revision: app(CampaignPlaybook::class)->import(Yaml::dump($body, 12)));
        $this->schedulerPass();

        $this->tickC0();

        $decision = CampaignEvent::where('kind', 'decision')->where('data->choice', 'buy_hauler')->sole();
        $gates = $decision->data['saturation']['gates'];
        $this->assertTrue($gates['G4']['pass']);
        $this->assertGreaterThan(0, $gates['G4']['units']);
        $this->assertSame(380000, $gates['G5']['price']);
        $this->assertTrue($gates['G8']['pass']);
        $this->assertSame(9.5, $gates['G8']['payback_hours']);
        $this->assertNull($decision->data['saturation']['hold']);
        $run = AutomationRun::findOrFail($decision->data['run_id']);
        $this->workRun($run);
        $this->assertSame('completed', $run->fresh()->state->value, $run->fresh()->blocked_reason ?? '');
        $this->assertSame(380000, CampaignEvent::where('kind', 'hauler_purchased')->sole()->data['price']);
    }

    #[DataProvider('incompleteRemoteOffers')]
    public function test_status_projects_incomplete_remote_offers_provisionally_without_permanent_stops(int $price, int $hours, string $detail, ?string $hold): void
    {
        $this->setUpSaturation(hubs: 2, remoteYard: true);
        $yard = 'X1-TEST-YARD';
        $storedYard = $this->world()['shipyards'][$yard];
        $storedYard['ships'][1]['purchasePrice'] = $price;
        match ($detail) {
            'engine' => $storedYard['ships'][1]['engine'] = ['speed' => 15],
            'fuel' => $storedYard['ships'][1]['frame']['fuelCapacity'] = null,
            'cargo' => $storedYard['ships'][1]['modules'] = [],
            'second hold capacity' => $storedYard['ships'][1]['modules'] = [
                ['symbol' => 'MODULE_CARGO_HOLD_I', 'capacity' => 40],
                ['symbol' => 'MODULE_CARGO_HOLD_I'],
            ],
        };
        $sync = app(AgentSynchronizer::class);
        $sync->observe($this->agent, 'waypoint', $yard, $this->point($yard, 80, ['SHIPYARD']), 'listing');
        $sync->observe($this->agent, 'shipyard', $yard, $storedYard);
        $episode = $this->primary();
        $episode->update(['next_reset_at' => now()->addHours($hours)]);
        $this->schedulerPass();
        $before = $this->world()['calls'];

        $saturation = app(CampaignSaturation::class);
        $reference = $saturation->evaluate($episode->grant(), $episode, $this->agent, $yard);
        $direct = $saturation->evaluate($episode->grant(), $episode, $this->agent, $yard, $storedYard['ships'][1]);
        $status = app(CampaignStatus::class)->saturation($this->operator->id, 'LOCAL');

        $this->assertTrue($reference['gates']['G4']['pass']);
        foreach ([$direct, $status] as $result) {
            $this->assertTrue($result['provisional']);
            $this->assertSame($yard, $result['yard']);
            $this->assertSame($reference['gates']['G4'], $result['gates']['G4']);
            $this->assertSame(600000, $result['gates']['G5']['price']);
            $this->assertSame(15, $result['gates']['G8']['payback_hours']);
            $this->assertSame($hold, $result['hold']['reason'] ?? null);
            $this->assertFalse($result['hold']['permanent'] ?? false);
        }
        $this->assertSame($before, $this->world()['calls']);
    }

    public static function incompleteRemoteOffers(): array
    {
        return [
            'in-cap price without engine symbol' => [350000, 120, 'engine', null],
            'over-cap price without fuel capacity' => [750000, 120, 'fuel', null],
            'out-of-horizon price without cargo capacity' => [350000, 26, 'cargo', 'payback'],
            'over-cap price without second hold capacity' => [750000, 120, 'second hold capacity', null],
        ];
    }

    #[DataProvider('permanentYardStops')]
    public function test_permanent_yard_stops_require_a_complete_home_catalogue(int $price, int $hours, string $reason): void
    {
        $this->setUpSaturation(hubs: 2);
        $yard = 'X1-TEST-ROCK';
        $this->editFixture(fn (&$world) => $world['shipyards'][$yard]['ships'][1]['purchasePrice'] = $price);
        $sync = app(AgentSynchronizer::class);
        $sync->observe($this->agent, 'shipyard', $yard, $this->world()['shipyards'][$yard]);
        $sync->observe($this->agent, 'catalogue', 'X1-TEST', ['complete' => false, 'total' => 22], 'listing');
        $episode = $this->primary();
        $episode->update(['next_reset_at' => now()->addHours($hours)]);
        $this->schedulerPass();
        $buyer = app(CampaignHaulerPurchase::class);
        $considered = [];

        $this->assertNull($buyer->plan($episode->grant(), $episode, $this->agent, [], $considered, microtime(true) + 10));
        $this->assertFalse($episode->fresh()->state['saturation_hold']['permanent']);

        $sync->observe($this->agent, 'catalogue', 'X1-TEST', ['complete' => true, 'total' => 22], 'listing');
        $considered = [];
        $this->assertNull($buyer->plan($episode->grant(), $episode, $this->agent, [], $considered, microtime(true) + 10));
        $hold = $episode->fresh()->state['saturation_hold'];
        $this->assertTrue($hold['permanent']);
        $this->assertSame($reason, $hold['reason']);
    }

    public static function permanentYardStops(): array
    {
        return [
            'max_price' => [650000, 120, 'all_shipyards_over_price_cap'],
            'payback' => [400000, 30, 'payback'],
        ];
    }

    public function test_an_empty_hauler_candidate_search_reports_no_eligible_yard(): void
    {
        $this->setUpSaturation(hubs: 2);
        $yard = 'X1-TEST-ROCK';
        $this->editFixture(function (&$world) use ($yard) {
            array_pop($world['shipyards'][$yard]['shipTypes']);
            array_pop($world['shipyards'][$yard]['ships']);
        });
        $episode = $this->primary();
        $this->schedulerPass();
        $considered = [];

        $this->assertNull(app(CampaignHaulerPurchase::class)->plan($episode->grant(), $episode, $this->agent, [], $considered, microtime(true) + 10));
        $snapshot = collect($considered)->whereNotNull('gates')->sole();
        $this->assertSame('no_eligible_yard', $snapshot['gates']['G4']['reason']);
        $this->assertSame('no_eligible_yard', $episode->fresh()->state['saturation_hold']['reason']);
    }

    public function test_g4_uses_cargo_modules_and_rejects_the_unprofitable_forty_unit_offer(): void
    {
        $this->setUpSaturation(hubs: 2);
        $body = $this->revision->body;
        $body['fleet']['haulers']['min_unclaimed_score'] = 150000;
        $episode = $this->primary(revision: app(CampaignPlaybook::class)->import(Yaml::dump($body, 12)));
        $this->schedulerPass();
        $this->editFixture(function (&$world) {
            $world['goods'][0]['tradeVolume'] = 80;
            $world['market_goods']['X1-TEST-PORT'][0]['tradeVolume'] = 80;
            $world['shipyards']['X1-TEST-ROCK']['ships'][1]['modules'][0] = ['symbol' => 'MODULE_CREW_QUARTERS_I', 'capacity' => 40];
        });
        $sync = app(AgentSynchronizer::class);
        foreach (['X1-TEST-ROCK', 'X1-TEST-PORT'] as $point) {
            $sync->observe($this->agent, 'market', $point, app(SpaceTradersApi::class)->market($this->agent, $point));
        }
        $offer = $this->world()['shipyards']['X1-TEST-ROCK']['ships'][1];
        $service = app(CampaignSaturation::class);
        $projection = fn (array $offer) => $service->evaluate($episode->grant(), $episode, $this->agent, 'X1-TEST-ROCK', $offer)['gates']['G4'];
        $this->assertSame(80, $projection($offer)['units']);
        $this->assertTrue($projection($offer)['pass'], json_encode($projection($offer)));
        $offer['modules'][1]['capacity'] = 40;
        $actual = $projection($offer);
        $this->assertSame(40, $actual['units']);
        $this->assertFalse($actual['pass'], json_encode($actual));
        $this->assertSame('spare_corridor', $actual['reason']);
    }

    #[DataProvider('yardAvailability')]
    public function test_g4_checks_other_eligible_yards_before_persisting_a_fleet_hold(bool $viable): void
    {
        $this->setUpSaturation(hubs: 2);
        $this->editFixture(function (&$world) use ($viable) {
            $world['waypoints'][] = $this->point('X1-TEST-AAA', 8, ['SHIPYARD']);
            $world['ships'][] = FakeSpaceTraders::probe('LOCAL-6', 'X1-TEST-AAA');
            $cheap = FakeSpaceTraders::lightHauler('OFFER-AAA', 'X1-TEST-AAA');
            $cheap['frame']['fuelCapacity'] = 1;
            $world['shipyards']['X1-TEST-AAA'] = ['shipTypes' => [['type' => 'SHIP_LIGHT_HAULER']],
                'ships' => [FakeSpaceTraders::shipyardOffer('SHIP_LIGHT_HAULER', $cheap, 250000)]];
            if (! $viable) {
                $world['shipyards']['X1-TEST-ROCK']['ships'][1]['frame']['fuelCapacity'] = 1;
            }
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $episode = $this->primary();
        $this->schedulerPass();
        $considered = [];
        $run = app(CampaignHaulerPurchase::class)->plan($episode->grant(), $episode, $this->agent, [], $considered, microtime(true) + 10);
        $this->assertDatabaseMissing('campaign_events', ['kind' => 'hauler_purchased']);
        $rejections = collect($considered)->where('shipyard', 'X1-TEST-AAA')->pluck('rejected');
        $this->assertContains('Hauler purchase held: spare_corridor.', $rejections);
        if (! $viable) {
            $this->assertNull($run);
            $hold = $episode->fresh()->state['saturation_hold'];
            $this->assertSame('no_unclaimed_corridor', $hold['reason']);
            $this->assertSame(now()->addHour()->toIso8601String(), $hold['held_until']);

            return;
        }
        $this->assertNotNull($run);
        $this->assertSame('X1-TEST-ROCK', $run->plan['shipyard']);
        $this->assertArrayNotHasKey('saturation_hold', $episode->fresh()->state);
        app(AutomationRunner::class)->startCampaign($this->agent, $run, $episode);
        $this->workRun($run);
        $this->assertSame('completed', $run->fresh()->state->value, $run->fresh()->blocked_reason ?? '');
        $event = CampaignEvent::where('kind', 'hauler_purchased')->sole();
        $this->assertSame('X1-TEST-ROCK', $event->data['shipyard']);
    }

    public static function yardAvailability(): array
    {
        return ['alternate corridor' => [true], 'all yards fail' => [false]];
    }

    private function load(CampaignEpisode $episode, int $minutes, int $net, int $run): void
    {
        app(CampaignControl::class)->event($episode->grant(), $episode, 'load_result', ['finished_at' => now()->addMinutes($minutes)->toIso8601String(),
            'good' => 'IRON_ORE', 'buyer' => 'X1-TEST-PORT', 'run_id' => $run, 'net' => $net, 'proof' => true, 'proof_eligible' => $net > 0, 'realized_ratio' => 1]);
    }

    private function manualAcknowledged(): ShipAction
    {
        return ShipAction::create(['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-2',
            'key' => 'manual-unattributed', 'origin' => 'manual', 'command' => 'buy_ship', 'state' => 'acknowledged',
            'arguments' => ['shipType' => 'SHIP_LIGHT_HAULER', 'waypointSymbol' => 'X1-TEST-ROCK'], 'before_state' => ['estimate' => ['fleet_symbols' => ['LOCAL-1', 'LOCAL-2']]],
            'expires_at' => now()->addMinute(), 'sent_at' => now()]);
    }
}
