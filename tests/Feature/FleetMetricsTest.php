<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\FleetMetrics;
use App\Gameplay\GameException;
use App\Gameplay\RequestDeferred;
use App\Gameplay\ShipCommandExecutor;
use App\Jobs\AdvanceAutomationRun;
use App\Jobs\ExecuteShipCommand;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\CampaignRevision;
use App\Models\ShipAction;
use App\Models\User;
use App\Services\SpaceTradersClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class FleetMetricsTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    private User $user;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-17 00:00:00'));
        FleetMetrics::flush();
        $this->file = tempnam(sys_get_temp_dir(), 'st-fleet-');
        $world = FakeSpaceTraders::world();
        file_put_contents($this->file, json_encode($world));
        FakeSpaceTraders::install($this->file);
        $this->user = User::factory()->create();
        $this->agent = $this->user->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic-only', 'faction' => 'VOID',
            'reset_id' => $world['reset'], 'canonical_identity' => AgentSynchronizer::identity($world['reset'], 'LOCAL')]);
    }

    protected function tearDown(): void
    {
        FleetMetrics::flush();
        unlink($this->file);
        parent::tearDown();
    }

    public function test_requests_are_attributed_to_the_current_ship_and_flushed(): void
    {
        $client = app(SpaceTradersClient::class);
        FleetMetrics::as('LOCAL-1', function () use ($client) {
            $client->request('get', '/my/agent', [], 'synthetic-only');
            FleetMetrics::as('LOCAL-2', fn () => FleetMetrics::count('post', '/my/ships/LOCAL-2/orbit'));
            $this->assertSame('LOCAL-1', FleetMetrics::current());
            FleetMetrics::count('post', '/my/ships/LOCAL-1/dock');
        });
        $this->assertNull(FleetMetrics::current());
        $client->request('get', '/my/agent', [], 'synthetic-only');
        $this->assertSame(0, DB::table('fleet_metrics')->count());
        FleetMetrics::flush();
        $rows = DB::table('fleet_metrics')->where('kind', 'requests')->get()->keyBy('ship');
        $this->assertEquals(2, $rows['LOCAL-1']->value);
        $this->assertSame(['reads' => 1, 'writes' => 1], json_decode($rows['LOCAL-1']->data, true));
        $this->assertEquals(1, $rows['LOCAL-2']->value);
        $this->assertEquals(1, $rows['shared']->value);
    }

    public function test_counter_flushes_itself_after_thirty_seconds(): void
    {
        FleetMetrics::count('get', '/x');
        $this->assertSame(0, DB::table('fleet_metrics')->count());
        $this->travel(31)->seconds();
        FleetMetrics::count('get', '/x');
        $this->assertEquals(2, DB::table('fleet_metrics')->where('ship', 'shared')->sum('value'));
        FleetMetrics::count('get', '/x');
        $this->assertEquals(2, DB::table('fleet_metrics')->sum('value'));
    }

    public function test_job_attributes_run_ship_and_counts_lock_collisions_and_deferrals(): void
    {
        $run = DB::table('automation_runs')->insertGetId(['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-3',
            'state' => 'running', 'plan' => '{}', 'totals' => '{}', 'last_dispatched_at' => now(), 'expires_at' => now()->addHour(), 'strategy' => 'trade', 'created_at' => now(), 'updated_at' => now()]);
        $calls = 0;
        $this->mock(AutomationRunner::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('advance')->andReturnUsing(function () use (&$calls) {
                FleetMetrics::count('get', '/my/ships/LOCAL-3');
                throw match (++$calls) {
                    1 => new GameException('Another operation owns this command boundary. Retry shortly.', retryAfter: 2),
                    default => new RequestDeferred('Budget.', 3),
                };
            });
        });
        (new AdvanceAutomationRun($run))->handle(app(AutomationRunner::class));
        $this->assertNull(AutomationRun::findOrFail($run)->last_dispatched_at);
        AutomationRun::whereKey($run)->update(['last_dispatched_at' => now()]);
        (new AdvanceAutomationRun($run))->handle(app(AutomationRunner::class));
        $this->assertNotNull(AutomationRun::findOrFail($run)->last_dispatched_at);
        FleetMetrics::flush();
        $this->assertEquals(2, DB::table('fleet_metrics')->where(['kind' => 'requests', 'ship' => 'LOCAL-3'])->value('value'));
        $this->assertEquals(1, DB::table('fleet_metrics')->where(['kind' => 'lock_collision', 'ship' => 'LOCAL-3'])->value('value'));
        $this->assertEquals(1, DB::table('fleet_metrics')->where(['kind' => 'deferral', 'ship' => 'LOCAL-3'])->value('value'));
    }

    public function test_scheduler_pass_records_timings_median_lag_and_queue_depth(): void
    {
        DB::table('jobs')->insert(['queue' => 'spacetraders', 'payload' => '{}', 'attempts' => 0, 'available_at' => 0, 'created_at' => 0]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => 0, 'created_at' => 0]);
        app(FleetMetrics::class)->schedulerPass(['director' => 0.5, 'arms' => 0.25, 'dispatch' => 0.1, 'lags' => [3, 1, 2, 10]]);
        app(FleetMetrics::class)->schedulerPass(['dispatch' => 0.2]);
        $rows = DB::table('fleet_metrics')->where('kind', 'scheduler')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(0.85, $rows[0]->value, 0.0001);
        $data = json_decode($rows[0]->data, true);
        $this->assertEquals(2.5, $data['dispatch_lag']);
        $this->assertSame(1, $data['queue_depth']);
        $this->assertSame(4, $data['dispatched']);
        $this->assertNull(json_decode($rows[1]->data, true)['dispatch_lag']);
    }

    public function test_command_lock_collision_clears_only_pending_dispatches(): void
    {
        $action = ShipAction::create(['agent_id' => $this->agent->id, 'ship_symbol' => 'LOCAL-1', 'key' => 'collision', 'reset_id' => $this->agent->reset_id,
            'command' => 'dock', 'state' => 'pending', 'origin' => 'automation', 'expires_at' => now()->addMinute(), 'arguments' => [], 'last_dispatched_at' => now()]);
        $this->mock(ShipCommandExecutor::class)->shouldReceive('execute')->twice()
            ->andThrow(new GameException('Another operation owns this command boundary. Retry shortly.', retryAfter: 2));
        (new ExecuteShipCommand($action->id))->handle(app(ShipCommandExecutor::class));
        $this->assertNull($action->fresh()->last_dispatched_at);
        $action->update(['state' => 'sending', 'last_dispatched_at' => now()]);
        (new ExecuteShipCommand($action->id))->handle(app(ShipCommandExecutor::class));
        $this->assertNotNull($action->fresh()->last_dispatched_at);
    }

    public function test_metrics_reset_discards_previous_test_counts_and_clock(): void
    {
        FleetMetrics::count('get');
        $this->travel(2)->days();
        FleetMetrics::reset();
        FleetMetrics::count('post');
        $this->assertSame(0, DB::table('fleet_metrics')->count());
        FleetMetrics::flush();
        $this->assertEquals(1, DB::table('fleet_metrics')->sum('value'));
    }

    public function test_default_t0_uses_the_earliest_arm_not_the_week_old_control(): void
    {
        $t0 = CarbonImmutable::now();
        $this->metricEpisode('C0', $t0->subWeek());
        $this->metricEpisode('T1b', $t0);
        $closed = $this->metricEpisode('S1', $t0->subHour());
        $closed->grant()->update(['revoked_at' => now()]);
        $closed->update(['closed_at' => now()]);
        $card = app(FleetMetrics::class)->scorecard(reset: 'R1');
        $this->assertSame($t0->subHour()->toIso8601String(), $card['since']);
        $this->artisan('fleet:scorecard', ['--reset' => 'R1'])->expectsOutputToContain('T0 '.$card['since'])->assertSuccessful();
        $this->assertSame($t0->subDays(2)->toIso8601String(), app(FleetMetrics::class)->scorecard($t0->subDays(2), reset: 'R1')['since']);
    }

    public function test_flags_require_twelve_steady_loads_and_exclude_warmup_from_blocks(): void
    {
        $t0 = CarbonImmutable::now();
        $minutes = [40, 50, 60, 70, 80, 90, 130, 140, 150, 160, 170, 180];
        $episodes = [];
        foreach (['T1b' => 100, 'T1c' => 110, 'S1' => 220] as $label => $net) {
            $episode = $episodes[$label] = $this->metricEpisode($label, $t0);
            $this->metricLoad($episode, $t0->addMinutes(30), 999999);
            foreach ($label === 'S1' ? array_slice($minutes, 0, 11) : $minutes as $minute) {
                $this->metricLoad($episode, $t0->addMinutes($minute), $net);
            }
        }
        $card = app(FleetMetrics::class)->scorecard(until: $t0->addHours(4), reset: 'R1');
        $flag = collect($card['flags'])->where('label', 'S1')->firstWhere('metric', 'net_per_hour');
        $this->assertTrue($flag['at_least_1_5x'] && $flag['outside_t1_range'] && $flag['same_direction_both_blocks']);
        $this->assertFalse($flag['enough_loads']);
        $this->assertFalse($flag['flagged']);
        $this->assertSame(6, $card['arms']['S1']['blocks'][0]['loads']);
        $this->assertEqualsWithDelta(880, $card['arms']['S1']['blocks'][0]['net_per_hour'], 0.001);
        $this->metricLoad($episodes['S1'], $t0->addMinutes(180), 220);
        $card = app(FleetMetrics::class)->scorecard(until: $t0->addHours(4), reset: 'R1');
        $this->assertTrue(collect($card['flags'])->where('label', 'S1')->firstWhere('metric', 'net_per_hour')['flagged']);
        $episodes['T1c']->update(['opened_at' => $t0->addHours(2)]);
        CampaignEvent::where('campaign_episode_id', $episodes['T1c']->id)->delete();
        $this->metricLoad($episodes['T1c'], $t0->addHours(3), 110);
        $this->metricLoad($episodes['T1c'], $t0->addMinutes(210), 110);
        $card = app(FleetMetrics::class)->scorecard(until: $t0->addHours(4), reset: 'R1');
        $this->assertNull($card['arms']['T1c']['blocks'][0]['net_per_hour']);
        $flag = collect($card['flags'])->where('label', 'S1')->firstWhere('metric', 'net_per_hour');
        $this->assertFalse($flag['same_direction_both_blocks']);
        $this->assertFalse($flag['flagged']);
    }

    public function test_unobserved_blocks_and_shared_units_are_unavailable(): void
    {
        $t0 = CarbonImmutable::now();
        foreach (['T1a', 'T3', 'T1b', 'T1c'] as $label) {
            $episode = $this->metricEpisode($label, $t0);
            $this->metricLoad($episode, $t0->addMinutes(150), 99999);
            $this->metricLoad($episode, $t0->addMinutes(180), 100);
        }
        $card = app(FleetMetrics::class)->scorecard(until: $t0->addHours(4), reset: 'R1');
        $this->assertNull($card['arms']['T1b']['blocks'][0]['net_per_hour']);
        $this->assertSame(0, $card['arms']['T1b']['blocks'][0]['loads']);
        $this->assertEqualsWithDelta(100 / 1.5, $card['arms']['T1b']['blocks'][1]['net_per_hour'], 0.001);
        $this->assertNull($card['shared_pair']['blocks'][0]['unit']);
        $this->assertNull($card['shared_pair']['blocks'][0]['ratio']);
    }

    private function metricEpisode(string $label, CarbonImmutable $opened): CampaignEpisode
    {
        $revision = CampaignRevision::firstOrCreate(['content_hash' => str_repeat('a', 64)], ['name' => 'arm', 'source' => '', 'body' => []]);
        $grant = CampaignGrant::create(['campaign_revision_id' => $revision->id, 'user_id' => $this->user->id, 'revision_hash' => $revision->content_hash,
            'limits' => $label === 'C0' ? [] : ['arm' => ['label' => $label, 'ship' => 'LOCAL-'.$label]], 'slot' => $label === 'C0' ? 1 : null]);

        return CampaignEpisode::create(['campaign_grant_id' => $grant->id, 'agent_id' => $this->agent->id, 'reset_id' => 'R1', 'start_credits' => 0,
            'phase' => 'earn', 'state' => [], 'next_reset_at' => $opened->addWeeks(2), 'opened_at' => $opened]);
    }

    public function test_blocks_include_a_steady_load_exactly_at_t0(): void
    {
        $t0 = CarbonImmutable::now();
        $episode = $this->metricEpisode('T1b', $t0->subHour());
        $this->metricLoad($episode, $t0->subMinutes(10), 99999);
        foreach ([0, 60, 120, 180, 240] as $minute) {
            $this->metricLoad($episode, $t0->addMinutes($minute), 100);
        }
        $row = app(FleetMetrics::class)->scorecard($t0, $t0->addHours(4), 'R1')['arms']['T1b'];
        $this->assertSame(5, $row['loads']);
        $this->assertSame([3, 2], array_column($row['blocks'], 'loads'));
        $this->assertEquals($row['net'], array_sum(array_column($row['blocks'], 'net')));
    }

    private function metricLoad(CampaignEpisode $episode, CarbonImmutable $at, int $net): void
    {
        CampaignEvent::create(['campaign_grant_id' => $episode->campaign_grant_id, 'campaign_episode_id' => $episode->id, 'kind' => 'load_result',
            'data' => ['run_id' => CampaignEvent::count() + 1, 'net' => $net, 'finished_at' => $at->toIso8601String(), 'best_unclaimed' => 100]]);
    }

    public function test_scorecard_reports_per_arm_rates_blocks_shared_pair_and_flags(): void
    {
        $t0 = CarbonImmutable::parse('2026-11-17 00:00:00');
        $revision = CampaignRevision::create(['content_hash' => str_repeat('a', 64), 'name' => 'arm', 'source' => '', 'body' => []]);
        $episodes = [];
        foreach (['C0' => 'LOCAL-1', 'T1a' => 'LOCAL-3', 'T1b' => 'LOCAL-4', 'T1c' => 'LOCAL-5', 'T3' => 'LOCAL-6', 'S1' => 'LOCAL-7'] as $label => $ship) {
            $grant = CampaignGrant::create(['campaign_revision_id' => $revision->id, 'user_id' => $this->user->id, 'revision_hash' => $revision->content_hash,
                'limits' => $label === 'C0' ? [] : ['arm' => ['label' => $label, 'ship' => $ship, 'follow' => $label === 'T3' ? 'T1a' : null]], 'slot' => $label === 'C0' ? 1 : null]);
            $episodes[$label] = CampaignEpisode::create(['campaign_grant_id' => $grant->id, 'agent_id' => $this->agent->id, 'reset_id' => 'R1', 'start_credits' => 0,
                'phase' => 'earn', 'state' => ['role_ships' => ['command' => $ship]], 'next_reset_at' => $t0->addDay(), 'opened_at' => $t0]);
        }
        // Each arm sells one load every 15 minutes for 4 hours; S1 earns 2x T1 in both blocks.
        $net = ['C0' => 1000, 'T1a' => 900, 'T1b' => 1000, 'T1c' => 1200, 'T3' => 500, 'S1' => 2200];
        $id = 0;
        foreach ($net as $label => $value) {
            $episode = $episodes[$label];
            for ($i = 1; $i <= 16; $i++) {
                $data = ['run_id' => ++$id, 'good' => 'IRON', 'buyer' => 'X1-B', 'net' => $value + ($i % 2 ? 50 : -50), 'finished_at' => $t0->addMinutes(15 * $i)->toIso8601String()];
                if ($label !== 'C0') {
                    $data += ['ship' => $episode->state['role_ships']['command'], 'arm' => $label, 'started_at' => $t0->addMinutes(15 * $i - 12)->toIso8601String(),
                        'best_unclaimed' => $i % 2 ? 2000 : ['predicted_margin' => 2000, 'key' => 'IRON|A|B'], 'limiting_factor' => $i % 4 ? 'hold' : 'credits'];
                }
                CampaignEvent::create(['campaign_grant_id' => $episode->campaign_grant_id, 'campaign_episode_id' => $episode->id, 'kind' => 'load_result', 'data' => $data]);
            }
        }
        ShipAction::forceCreate(['agent_id' => $this->agent->id, 'key' => 'buy-s1', 'reset_id' => 'R1', 'ship_symbol' => 'LOCAL-1', 'origin' => 'manual', 'command' => 'buy_ship',
            'state' => 'confirmed', 'arguments' => [], 'outcome' => ['delivered_ship' => 'LOCAL-7', 'transaction_total' => 300000], 'expires_at' => $t0]);
        ShipAction::forceCreate(['agent_id' => $this->agent->id, 'key' => 'hold', 'reset_id' => 'R1', 'ship_symbol' => 'LOCAL-4', 'origin' => 'automation', 'command' => 'sell',
            'state' => 'confirmed', 'review_required' => true, 'arguments' => [], 'expires_at' => $t0, 'created_at' => $t0->addHour()]);
        DB::table('fleet_metrics')->insert(['kind' => 'requests', 'ship' => 'LOCAL-4', 'value' => 375, 'data' => null, 'created_at' => $t0->addHour()->toDateTimeString()]);

        $card = app(FleetMetrics::class)->scorecard($t0, $t0->addHours(4), 'R1');

        $t1b = $card['arms']['T1b'];
        $this->assertSame(15, $t1b['loads']);
        $this->assertSame(16, $t1b['loads_total']);
        $this->assertEqualsWithDelta(3.75, $t1b['hours'], 0.001);
        $this->assertEqualsWithDelta((15 * 1000 - 50) / 3.75, $t1b['net_per_hour'], 0.01);
        [$low, $high] = $t1b['net_per_hour_80'];
        $this->assertTrue($low <= $t1b['net_per_hour'] && $t1b['net_per_hour'] <= $high);
        $this->assertSame([$low, $high], app(FleetMetrics::class)->scorecard($t0, $t0->addHours(4), 'R1')['arms']['T1b']['net_per_hour_80']);
        $this->assertEqualsWithDelta((15 * 1000 - 50) / (15 * 2000), $t1b['pair_ratio'], 0.0001);
        $this->assertSame(['hold' => 11, 'credits' => 4], $t1b['limiting_factors']);
        $this->assertEquals(15.0, $t1b['first_sale_minutes']);
        $this->assertSame(1, $t1b['holds']);
        $this->assertEqualsWithDelta(100, $t1b['requests_per_ship_hour'], 0.01);
        $this->assertEqualsWithDelta(3 + 15 * 3, $t1b['stall_minutes'], 0.1);
        $this->assertSame(300000, $card['arms']['S1']['ship_price']);
        $this->assertEqualsWithDelta(300000 / $card['arms']['S1']['net_per_hour'], $card['arms']['S1']['payback_hours'], 0.01);
        $this->assertNull($card['arms']['C0']['pair_ratio']);
        $this->assertSame(['unknown' => 15], $card['arms']['C0']['limiting_factors']);
        $this->assertSame('LOCAL-1', $card['arms']['C0']['ship']);
        $this->assertSame(7, $card['arms']['T1b']['blocks'][0]['loads']);
        $this->assertEqualsWithDelta((900 + 500) / 2200, $card['shared_pair']['ratio'], 0.01);

        $flags = collect($card['flags'])->where('metric', 'net_per_hour')->keyBy('label');
        $this->assertTrue($flags['S1']['flagged']);
        $this->assertFalse($flags['C0']['flagged']);
        $this->assertArrayNotHasKey('T1a', $flags->all());
        $this->assertArrayNotHasKey('T3', $flags->all());

        $this->artisan('fleet:scorecard', ['--since' => $t0->toIso8601String(), '--until' => $t0->addHours(4)->toIso8601String(), '--reset' => 'R1'])
            ->expectsOutputToContain('Shared-pair unit')->assertSuccessful();
        $this->artisan('fleet:scorecard', ['--reset' => 'R1', '--json' => true])->assertSuccessful();
    }
}
