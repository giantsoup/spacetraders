<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\CampaignSaturation;
use App\Gameplay\CampaignStatus;
use App\Gameplay\FleetClaims;
use App\Gameplay\HostLock;
use App\Gameplay\RequestBudget;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\LocalEngine;
use App\Models\ShipAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $calls = $this->world()['calls'];
        DB::enableQueryLog();
        DB::flushQueryLog();
        $active = app(CampaignStatus::class)->saturation($this->operator->id, 'LOCAL');
        $simulation = app(CampaignStatus::class)->saturation($this->operator->id, 'LOCAL', $candidate);
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

            public function usage(?string $token = null, ?string $account = null, ?string $agentSymbol = null): array
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

    public function test_paired_incumbent_yield_hold_is_written_once_when_the_three_hour_window_matures(): void
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
        $this->assertSame('incumbent_yield', $result['hold']['reason']);
        $this->assertEquals(50000, $result['hold']['evidence']['before']);
        $this->assertEquals(20000, $result['hold']['evidence']['after']);
        $service->record($primary->grant(), $primary, $result);
        $this->assertTrue($primary->fresh()->state['saturation_yield_reviews'][123]);
        $this->assertSame(1, CampaignEvent::where('kind', 'saturation')->count());
        $this->travel(181)->minutes();
        $this->assertNotSame('incumbent_yield', $service->evaluate($primary->grant(), $primary->refresh(), $this->agent)['hold']['reason']);
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
