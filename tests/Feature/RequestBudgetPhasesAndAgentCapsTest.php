<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\Engine\EngineHost;
use App\Gameplay\FleetMetrics;
use App\Gameplay\FuelPlanner;
use App\Gameplay\GameException;
use App\Gameplay\RequestBudget;
use App\Gameplay\RequestDeferred;
use App\Gameplay\ShipCommandExecutor;
use App\Gameplay\SpaceTradersApi;
use App\Jobs\AdvanceAutomationRun;
use App\Jobs\ExecuteShipCommand;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\LocalEngine;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\EngineFixture;
use Tests\Support\FakeEngineHost;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class RequestBudgetPhasesAndAgentCapsTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    private User $user;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-22 14:00:00'));
        config(['gameplay.enabled' => true, 'gameplay.local_engine_enabled' => true, 'gameplay.inline_execute' => true, 'gameplay.reset_cache_seconds' => 60]);
        $this->app->instance(EngineHost::class, new FakeEngineHost);
        $this->file = tempnam(sys_get_temp_dir(), 'st-rbp-');
        $world = FakeSpaceTraders::world();
        $world['next_reset'] = now()->addHours(17)->toIso8601String();
        $world['ships'][0]['registration']['role'] = 'COMMAND';
        $probe = FakeSpaceTraders::ship('LOCAL-2', 'X1-TEST-PORT');
        $probe['registration']['role'] = 'SATELLITE';
        $probe['frame']['symbol'] = 'FRAME_PROBE';
        $world['ships'][] = $probe;
        $world['ships'][] = FakeSpaceTraders::ship('LOCAL-3');
        $world['market_goods']['X1-TEST-PORT'] = $world['goods'];
        $world['market_goods']['X1-TEST-PORT'][0]['sellPrice'] = 200;
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
        FleetMetrics::flush();
        unlink($this->file);
        parent::tearDown();
    }

    private function calls(): array
    {
        return json_decode(file_get_contents($this->file), true)['calls'];
    }

    public function test_phase1_inline_execute_confirms_immediately_and_fallback_job_is_noop(): void
    {
        $revision = app(CampaignPlaybook::class)->import(str_replace(['GIANTSOUP', 'max_credits: 60000'], ['LOCAL', 'max_credits: 100000'], file_get_contents(base_path('campaigns/arm-trade.yaml'))));
        app(CampaignControl::class)->approveArm($revision, $this->user, ['label' => 'T1a', 'ship' => 'LOCAL-3'], CarbonImmutable::now()->addHours(12));
        app(CampaignDirector::class)->tickArms();
        $run = AutomationRun::whereNotNull('active_agent_id')->sole();

        $before = count($this->calls());
        (new AdvanceAutomationRun($run->id))->handle(app(AutomationRunner::class));
        $action = ShipAction::where('automation_run_id', $run->id)->sole();
        $this->assertSame('confirmed', $action->state->value);

        // Fallback job finds action already confirmed and sends zero extra API calls.
        $afterAdvance = count($this->calls());
        (new ExecuteShipCommand($action->id))->handle(app(ShipCommandExecutor::class));
        $this->assertSame($afterAdvance, count($this->calls()));
        $this->assertGreaterThan($before, $afterAdvance);
    }

    public function test_phase2_reset_cache_hits_within_60s_and_reads_fresh_within_10m_of_reset_or_on_reconcile(): void
    {
        $api = app(SpaceTradersApi::class);
        $api->clearResetCache();
        $start = count($this->calls());

        $first = $api->resetSchedule();
        $second = $api->resetSchedule();
        $this->assertSame($first, $second);
        $this->assertSame(1, count($this->calls()) - $start, 'Second read within 60s hits cache');

        // Reconcile / fresh bypasses cache.
        $api->resetSchedule(fresh: true);
        $this->assertSame(2, count($this->calls()) - $start);

        // After 61s, cache expires and reads fresh.
        $this->travel(61)->seconds();
        $api->resetSchedule();
        $this->assertSame(3, count($this->calls()) - $start);

        // Corrupt cache fails closed and reads fresh.
        DB::table('game_runtime')->where('key', 'like', 'reset_schedule:%')->update(['value' => '{corrupt']);
        $api->resetSchedule();
        $this->assertSame(4, count($this->calls()) - $start);

        // Within 10 minutes of next_reset forces fresh reads.
        $this->travelTo(CarbonImmutable::parse($first['next_reset'])->subMinutes(9));
        $api->resetSchedule();
        $api->resetSchedule();
        $this->assertSame(6, count($this->calls()) - $start);
    }

    public function test_phase2_4113_rejection_clears_reset_cache_and_archives_agent(): void
    {
        $api = app(SpaceTradersApi::class);
        $api->resetSchedule();
        $this->assertTrue(DB::table('game_runtime')->where('key', 'like', 'reset_schedule:%')->exists());

        Http::swap(new Factory);
        Http::fake([
            'https://api.spacetraders.io/v2/my/agent' => Http::response(['error' => ['code' => 4113, 'message' => 'Token reset date mismatch']], 401),
            'https://api.spacetraders.io/v2/' => Http::response(['resetDate' => '2026-11-29', 'version' => 'v2', 'serverResets' => ['next' => '2026-12-06T13:00:00Z']], 200),
        ]);

        try {
            $api->agent($this->agent);
            $this->fail('Expected 4113 rejection');
        } catch (GameException $e) {
            $this->assertSame(4113, $e->apiCode);
            $this->assertFalse($e->uncertain);
        }
        $this->assertFalse(DB::table('game_runtime')->where('key', 'like', 'reset_schedule:%')->exists());

        try {
            app(AgentSynchronizer::class)->guardReset($this->agent->fresh(), fresh: true);
            $this->fail('Expected archive on reset mismatch');
        } catch (GameException) {
            $this->assertSame('reset', $this->agent->fresh()->connection_state);
        }
    }

    public function test_phase3_local_arm_director_pass_uses_zero_api_calls_when_balance_is_fresh(): void
    {
        $revision = app(CampaignPlaybook::class)->import(str_replace(['GIANTSOUP', 'max_credits: 60000'], ['LOCAL', 'max_credits: 100000'], file_get_contents(base_path('campaigns/arm-trade.yaml'))));
        app(CampaignControl::class)->approveArm($revision, $this->user, ['label' => 'T1a', 'ship' => 'LOCAL-3'], CarbonImmutable::now()->addHours(12));
        app(CampaignDirector::class)->tickArms();
        $this->assertTrue(AutomationRun::whereNotNull('active_agent_id')->exists());

        // Advance 31s: reset cache (60s) and synced_at (60s) are both still fresh -> 0 API calls!
        $this->travel(31)->seconds();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        $before = count($this->calls());
        app(CampaignDirector::class)->tickArms();
        $this->assertSame($before, count($this->calls()), 'Local arm pass with fresh balance and cached reset makes 0 API calls');

        // Advance another 35s (>60s since synced_at): reads reset + fresh GET /my/agent (no ships_page).
        $this->travel(35)->seconds();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        $beforeStale = count($this->calls());
        app(CampaignDirector::class)->tickArms();
        $newCalls = array_slice($this->calls(), $beforeStale);
        $categories = array_map(fn ($c) => FleetMetrics::category($c['method'], $c['path']), $newCalls);
        $this->assertContains('agent', $categories);
        $this->assertNotContains('ships_page', $categories);
    }

    public function test_per_agent_buckets_isolate_agents_on_one_account_and_preserve_tier1_settlement_under_saturation(): void
    {
        $dir = sys_get_temp_dir().'/st-rb-agent-'.uniqid();
        config([
            'gameplay.request_budget_enabled' => true,
            'gameplay.request_budget_path' => $dir,
            'gameplay.host_tier2_4_per_minute' => 6,
            'gameplay.host_tier1_reserve_per_minute' => 4,
            'gameplay.agent_tier1_reserve_per_hour' => 3,
            'gameplay.agent_request_caps' => ['GIANTSOUP' => 4, 'SOUPBURN' => 2],
        ]);
        $budget = new class extends RequestBudget
        {
            public float $clock = 1_700_000_000.0;

            protected function now(): float
            {
                return $this->clock;
            }

            protected function pause(float $seconds): void
            {
                $this->clock += $seconds;
            }
        };

        // Both agents share ONE account_id; SOUPBURN hits its 2/hr Tiers 2-4 cap while GIANTSOUP still runs freely.
        RequestBudget::forAgent('SOUPBURN', fn () => $budget->reserve('tok-burn', 'shared-account', 'critical', 0));
        RequestBudget::forAgent('SOUPBURN', fn () => $budget->reserve('tok-burn', 'shared-account', 'critical', 0));
        try {
            RequestBudget::forAgent('SOUPBURN', fn () => $budget->reserve('tok-burn', 'shared-account', 'critical', 0));
            $this->fail('SOUPBURN should be capped at 2 Tiers 2-4 requests');
        } catch (RequestDeferred) {
        }

        // GIANTSOUP on the same account is NOT blocked by SOUPBURN's cap.
        RequestBudget::forAgent('GIANTSOUP', fn () => $budget->reserve('tok-giant', 'shared-account', 'critical', 0));
        RequestBudget::forAgent('GIANTSOUP', fn () => $budget->reserve('tok-giant', 'shared-account', 'critical', 0));
        RequestBudget::forAgent('GIANTSOUP', fn () => $budget->reserve('tok-giant', 'shared-account', 'critical', 0));
        RequestBudget::forAgent('GIANTSOUP', fn () => $budget->reserve('tok-giant', 'shared-account', 'critical', 0));

        // Now BOTH agents AND the host Tiers 2-4 envelope (6/min) are 100% saturated.
        try {
            RequestBudget::forAgent('GIANTSOUP', fn () => $budget->reserve('tok-giant', 'shared-account', 'critical', 0));
            $this->fail('Host Tiers 2-4 envelope is saturated');
        } catch (RequestDeferred) {
        }

        // Tier-1 settlement reserve STILL admits sell_aboard / BURN->CRUISE recovery for BOTH agents under saturation!
        $budget->clock += 2.0; // refill token-bucket burst tokens while remaining in same 60s window
        RequestBudget::forAgent('SOUPBURN', fn () => $budget->reserve('tok-burn', 'shared-account', 'settlement', 0));
        RequestBudget::forAgent('GIANTSOUP', fn () => $budget->reserve('tok-giant', 'shared-account', 'settlement', 0));

        $burnUsage = $budget->usage('tok-burn', 'shared-account', 'SOUPBURN');
        $this->assertSame(2, $burnUsage['agent']['tier2_4_last_hour']);
        $this->assertSame(1, $burnUsage['agent']['tier1_last_hour']);
        $this->assertSame(6, $burnUsage['host']['tier2_4_last_60_seconds']);
        $this->assertSame(2, $burnUsage['host']['tier1_last_60_seconds']);
    }

    public function test_burn_cargo_settles_through_cruise_under_multi_agent_host_saturation(): void
    {
        $world = json_decode(file_get_contents($this->file), true);
        $world['ships'][0]['nav']['flightMode'] = 'BURN';
        $world['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 3, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 3]]];
        file_put_contents($this->file, json_encode($world));
        app(AgentSynchronizer::class)->sync($this->agent);
        $revision = app(CampaignPlaybook::class)->import(str_replace('GIANTSOUP', 'LOCAL', file_get_contents(base_path('campaigns/weekly-default.yaml'))));
        $grant = app(CampaignControl::class)->approve($revision, $this->user);
        $episode = CampaignEpisode::create(['campaign_grant_id' => $grant->id, 'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id,
            'start_credits' => $this->agent->fresh()->credits, 'phase' => 'earn', 'opened_at' => now(), 'next_reset_at' => now()->addDay(),
            'state' => ['role_ships' => ['command' => 'LOCAL-1']]]);
        $budget = new class extends RequestBudget
        {
            public float $clock = 1700000000;

            protected function now(): float
            {
                return $this->clock;
            }

            protected function pause(float $seconds): void
            {
                $this->clock += $seconds;
            }
        };
        $this->app->instance(RequestBudget::class, $budget);
        config(['gameplay.request_budget_enabled' => true, 'gameplay.host_tier2_4_per_minute' => 6,
            'gameplay.host_tier1_reserve_per_minute' => 18, 'gameplay.agent_tier1_reserve_per_hour' => 300,
            'gameplay.agent_request_caps' => ['LOCAL' => 3, 'OTHER' => 3]]);
        @unlink(config('gameplay.request_budget_path').'/budget.json');
        foreach (['LOCAL', 'OTHER'] as $symbol) {
            for ($i = 0; $i < 3; $i++) {
                RequestBudget::forAgent($symbol, fn () => $budget->reserve(priority: 'critical', maxWait: 0));
            }
        }
        app(CampaignControl::class)->drainPause($grant, 'test_pressure', $episode);
        $runner = app(AutomationRunner::class);
        $run = $runner->previewRecovery($this->agent, 'LOCAL-1', 'X1-TEST-PORT', ['reserve' => 0, 'max_loss' => 10000, 'max_spend' => 10000,
            'minutes' => $grant->limits['run_max_minutes'], 'recovery_started_at' => now()->toIso8601String()], $episode);
        $beforeConfiguration = $run->plan['initial_configuration'];
        $runner->startCampaign($this->agent, $run, $episode);
        $commands = [];
        for ($i = 0; $i < 80 && $run->fresh()->active_agent_id; $i++) {
            $budget->clock += 2;
            // Other routine traffic keeps the host envelope saturated while settlement is paced.
            $path = config('gameplay.request_budget_path').'/budget.json';
            $state = json_decode(file_get_contents($path), true);
            $state['host_tiers']['t24'] = array_fill(0, 6, $budget->clock);
            file_put_contents($path, json_encode($state));
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
            $run->update(['next_action_at' => now()]);
            $runner->advance($run->id);
            $commands = ShipAction::where('automation_run_id', $run->id)->where('state', 'confirmed')->pluck('command')->all();
            $this->travel(1)->seconds();
        }
        $this->assertSame(['flight_mode', 'orbit', 'navigate', 'dock', 'refuel', 'sell'], $commands, $run->fresh()->blocked_reason ?? '');
        $writes = json_decode(file_get_contents($this->file), true)['writes'];
        $this->assertSame('CRUISE', $writes[0]['arguments']['flightMode'] ?? null);
        $ship = Ship::where('agent_id', $this->agent->id)->where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
        $this->assertSame(0, $ship['cargo']['units']);
        $this->assertSame('CRUISE', $ship['nav']['flightMode']);
        $this->assertNotSame($beforeConfiguration, $run->fresh()->plan['initial_configuration']);
        $this->assertSame(FuelPlanner::configuration($ship), $run->fresh()->plan['initial_configuration']);
        $this->assertSame(6, $budget->usage()['host']['tier2_4_last_60_seconds']);
        $this->assertNull($run->fresh()->active_agent_id);
    }

    public function test_round_one_settlement_borrows_the_idle_ordinary_envelope_after_the_reserve(): void
    {
        config(['gameplay.request_budget_enabled' => true, 'gameplay.agent_request_caps' => ['LOCAL' => 1000]]);
        $budget = new class extends RequestBudget
        {
            public float $clock = 1700000000;

            protected function now(): float
            {
                return $this->clock;
            }
        };
        @unlink(config('gameplay.request_budget_path').'/budget.json');
        for ($i = 0; $i < 360; $i++) {
            $budget->clock += 10;
            RequestBudget::forAgent('LOCAL', fn () => $budget->reserve(priority: 'settlement', maxWait: 0));
        }
        $this->assertSame(300, $budget->usage(agentSymbol: 'LOCAL')['agent']['tier1_last_hour']);
        $this->assertSame(60, $budget->usage(agentSymbol: 'LOCAL')['agent']['tier2_4_last_hour']);
        $this->assertSame(360, $budget->usage()['host']['priorities']['settlement']);
    }

    public function test_round_two_settlement_borrowing_preserves_other_agents_reserves_and_ordinary_caps(): void
    {
        config(['gameplay.request_budget_enabled' => true, 'gameplay.request_budget_stage' => 'stage1']);
        $budget = new class extends RequestBudget
        {
            public float $clock = 1700000000;

            protected function now(): float
            {
                return $this->clock;
            }
        };
        $path = config('gameplay.request_budget_path').'/budget.json';
        foreach ([[96, 0, 'ordinary host full'], [0, 1600, 'agent ordinary cap full']] as [$host, $ordinary, $case]) {
            file_put_contents($path, json_encode([
                'buckets' => [],
                'host_tiers' => ['t24' => array_fill(0, $host, $budget->clock - 5), 't1' => []],
                'agents' => ['agent:SOUPBURN' => ['t24' => array_fill(0, $ordinary, $budget->clock - 90), 't1' => array_fill(0, 300, $budget->clock - 90)]],
            ]));
            try {
                RequestBudget::forAgent('SOUPBURN', fn () => $budget->reserve(priority: 'settlement', maxWait: 0));
                $this->fail('Exhausted agent cannot settle with '.$case);
            } catch (RequestDeferred) {
                $this->assertSame(300, $budget->usage(agentSymbol: 'SOUPBURN')['agent']['tier1_last_hour']);
            }
            RequestBudget::forAgent('GIANTSOUP', fn () => $budget->reserve(priority: 'settlement', maxWait: 0));
            $this->assertSame(1, $budget->usage(agentSymbol: 'GIANTSOUP')['agent']['tier1_last_hour']);
        }
    }

    public function test_round_one_stage_caps_and_shared_bucket_are_selectable(): void
    {
        config(['gameplay.request_budget_stage' => 'stage1', 'gameplay.request_budget_enabled' => true]);
        $budget = app(RequestBudget::class);
        $this->assertSame(3400, $budget->usage(agentSymbol: 'GIANTSOUP')['agent']['cap_per_hour']);
        $this->assertSame(1600, $budget->usage(agentSymbol: 'SOUPBURN')['agent']['cap_per_hour']);
        $this->assertSame(0, $budget->usage(agentSymbol: 'SOUPFLEET')['agent']['cap_per_hour']);
        $this->assertSame(760, $budget->usage(agentSymbol: 'SHARED')['agent']['cap_per_hour']);
        $this->assertSame(2880, RequestBudget::stageCaps('stage2')['GIANTSOUP']);
        $budget->reserve(priority: 'discovery');
        $this->assertGreaterThan(0, $budget->usage(agentSymbol: 'SHARED')['agent']['tier2_4_last_hour']);
    }

    public function test_unlisted_agent_cap_matches_admission_so_loading_finishes_at_stage1(): void
    {
        config(['gameplay.request_budget_stage' => 'stage1', 'gameplay.request_budget_enabled' => true]);
        $budget = app(RequestBudget::class);
        $this->assertSame(0, $budget->usage(agentSymbol: 'MACSOUP')['agent']['cap_per_hour']);
        $this->assertTrue($budget->ordinaryCapReached('MACSOUP'));
        $this->expectException(RequestDeferred::class);
        RequestBudget::forAgent('MACSOUP', fn () => $budget->reserve(priority: 'run', maxWait: 0));
    }

    public function test_stage_off_leaves_agents_uncapped(): void
    {
        config(['gameplay.request_budget_stage' => 'off', 'gameplay.agent_request_caps' => []]);
        $budget = app(RequestBudget::class);
        $this->assertNull($budget->usage(agentSymbol: 'GIANTSOUP')['agent']['cap_per_hour']);
        $this->assertFalse($budget->ordinaryCapReached('GIANTSOUP'));
    }

    public function test_round_one_scorecard_never_counts_elapsed_time_without_trading_activity(): void
    {
        config(['gameplay.agent_request_caps' => ['local' => 1800]]);
        $this->assertSame(0.0, app(FleetMetrics::class)->byAgent([
            ['agent_symbol' => 'LOCAL', 'ship' => 'LOCAL-1', 'hours' => 2, 'episodes' => [], 'qualified_label' => 'LOCAL:C0'],
        ], now()->subHours(2)->toImmutable(), now()->toImmutable())['LOCAL']['active_trader_hours']);
    }

    public function test_fleet_metrics_records_host_scope_collection_time_attribution_and_split_rates(): void
    {
        config(['gameplay.agent_request_caps' => ['local' => 1800]]);
        $revision = app(CampaignPlaybook::class)->import(str_replace('GIANTSOUP', 'LOCAL', file_get_contents(base_path('campaigns/weekly-default.yaml'))));
        $grant = app(CampaignControl::class)->approve($revision, $this->user);
        $opened = CarbonImmutable::now()->subHours(8);
        $episode = CampaignEpisode::create([
            'campaign_grant_id' => $grant->id, 'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id,
            'start_credits' => 175000, 'phase' => 'earn', 'opened_at' => $opened, 'next_reset_at' => now()->addHours(15),
            'state' => ['role_ships' => ['command' => 'LOCAL-1', 'probe' => 'LOCAL-2']],
        ]);
        CampaignEvent::create([
            'campaign_grant_id' => $grant->id, 'campaign_episode_id' => $episode->id, 'kind' => 'load_result',
            'data' => ['run_id' => 1, 'ship' => 'LOCAL-1', 'good' => 'IRON_ORE', 'buyer' => 'X1-TEST-PORT', 'net' => 10000, 'finished_at' => $opened->toIso8601String()],
        ]);
        CampaignEvent::create([
            'campaign_grant_id' => $grant->id, 'campaign_episode_id' => $episode->id, 'kind' => 'load_result',
            'data' => ['run_id' => 2, 'ship' => 'LOCAL-1', 'good' => 'IRON_ORE', 'buyer' => 'X1-TEST-PORT', 'net' => 12000, 'finished_at' => now()->subMinutes(10)->toIso8601String()],
        ]);

        foreach ([['LOCAL-1', 120, 90], ['LOCAL-3', 120, 30], ['LOCAL-3', 85, 50], ['LOCAL-2', 120, 30]] as [$ship, $start, $end]) {
            AutomationRun::create(['agent_id' => $this->agent->id, 'campaign_episode_id' => $episode->id, 'reset_id' => $episode->reset_id,
                'ship_symbol' => $ship, 'strategy' => 'trade', 'state' => 'stopped', 'started_at' => now()->subMinutes($start),
                'updated_at' => now()->subMinutes($end), 'expires_at' => now(), 'plan' => [], 'totals' => []]);
        }
        FleetMetrics::reset();
        // Scheduler pass is explicitly host-scoped.
        app(FleetMetrics::class)->schedulerPass(['director' => 0.1, 'arms' => 0.0, 'dispatch' => 0.1, 'lags' => [1]]);
        $this->assertSame('host', DB::table('fleet_metrics')->where('kind', 'scheduler')->value('scope'));

        // Two ship-hours total. The overlapping hauler run and the probe contribute no time.
        foreach ([['LOCAL-1', 150, 100, []], ['LOCAL-3', 250, 100, []], ['LOCAL-2', 150, 100, []],
            ['LOCAL-1', 50, 100, ['workload' => 'director']], ['LOCAL-1', 1000, 420, []]] as [$ship, $value, $age, $data]) {
            DB::table('fleet_metrics')->insert(['kind' => 'requests', 'ship' => $ship, 'agent_symbol' => 'LOCAL',
                'scope' => 'agent', 'value' => $value, 'data' => json_encode($data), 'created_at' => now()->subMinutes($age)->toDateTimeString()]);
        }

        $card = app(FleetMetrics::class)->scorecard($opened, now()->toImmutable(), $this->agent->reset_id);
        $local = $card['by_agent']['LOCAL'];
        $this->assertTrue($local['attribution_complete']);
        $this->assertEqualsWithDelta(2.0, $local['active_trader_hours'], 0.01);
        $this->assertEqualsWithDelta(300.0, $local['all_in_req_per_active_trader_hr'], 1.0);
        $this->assertEqualsWithDelta(200.0, $local['trader_only_req_per_active_trader_hr'], 1.0);
        $this->assertEqualsWithDelta(200 / 6, $local['agent_overhead_req_hr'], 1.0);
        // Formula: floor((1800 - 200/6) / 200) = 8 traders (zero double counting of probe/director overhead).
        $this->assertSame(8, $local['formula_max_traders']);
        $this->assertNull($local['purchase_hold_reason']);
        $partial = app(FleetMetrics::class)->scorecard(now()->subHours(2)->toImmutable(), now()->toImmutable(), $this->agent->reset_id)['by_agent']['LOCAL'];
        $this->assertNull($partial['formula_max_traders']);
        $this->assertSame('insufficient_sample_window', $partial['purchase_hold_reason']);

        // Unattributed agent-scoped request row triggers purchase hold (host rows do NOT).
        DB::table('fleet_metrics')->insert([
            'kind' => 'requests', 'ship' => 'shared', 'agent_symbol' => null, 'scope' => 'agent', 'value' => 10, 'data' => json_encode(['reads' => 10, 'writes' => 0]), 'created_at' => now()->subMinutes(20)->toDateTimeString(),
        ]);
        $held = app(FleetMetrics::class)->scorecard($opened, now()->toImmutable(), $this->agent->reset_id)['by_agent']['LOCAL'];
        $this->assertFalse($held['attribution_complete']);
        $this->assertNull($held['formula_max_traders']);
        $this->assertSame('incomplete_agent_attribution', $held['purchase_hold_reason']);
    }
}
