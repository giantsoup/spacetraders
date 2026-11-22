<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignFleet;
use App\Gameplay\CampaignLocalEarn;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\CampaignStrategy;
use App\Gameplay\CampaignTrade;
use App\Gameplay\ContractPlanner;
use App\Gameplay\Engine\EngineHost;
use App\Gameplay\EngineGate;
use App\Gameplay\FleetClaims;
use App\Gameplay\GameException;
use App\Gameplay\HostLock;
use App\Gameplay\LocalEarnBudget;
use App\Gameplay\LocalEarnRefusal;
use App\Gameplay\MiningPlanner;
use App\Gameplay\ShipCommandExecutor;
use App\Gameplay\SpaceTradersApi;
use App\Gameplay\StaleQuote;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\LocalEngine;
use App\Models\ReconMission;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use App\Services\SpaceTradersClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\EngineFixture;
use Tests\Support\FakeEngineHost;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class CampaignLocalEarnTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    private User $user;

    private Agent $agent;

    private CampaignGrant $grant;

    private CampaignEpisode $episode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Http::preventStrayRequests();
        config(['gameplay.enabled' => true, 'gameplay.local_engine_enabled' => true]);
        $this->app->instance(EngineHost::class, new FakeEngineHost);
        $world = FakeSpaceTraders::world();
        $world['next_reset'] = now()->addHours(17)->toIso8601String();
        $world['ships'][0]['registration']['role'] = 'COMMAND';
        $probe = FakeSpaceTraders::ship('LOCAL-2', 'X1-TEST-PORT');
        $probe['registration']['role'] = 'SATELLITE';
        $probe['frame']['symbol'] = 'FRAME_PROBE';
        $probe['fuel'] = ['current' => 0, 'capacity' => 0];
        $world['ships'][] = $probe;
        $world['market_goods']['X1-TEST-PORT'] = $world['goods'];
        $world['market_goods']['X1-TEST-PORT'][0]['sellPrice'] = 200;
        $world['market_goods']['X1-TEST-PORT'][1]['sellPrice'] = 100;
        $world['fuel_consumption'] = 1;
        $this->file = tempnam(sys_get_temp_dir(), 'st-local-earn-');
        file_put_contents($this->file, json_encode($world));
        FakeSpaceTraders::install($this->file);
        $this->user = User::factory()->create();
        $this->agent = $this->user->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic-only', 'faction' => 'VOID',
            'reset_id' => $world['reset'], 'canonical_identity' => AgentSynchronizer::identity($world['reset'], 'LOCAL')]);
        app(AgentSynchronizer::class)->sync($this->agent);
        foreach (['X1-TEST-ROCK', 'X1-TEST-PORT'] as $market) {
            $this->read($market);
        }
        Queue::fake();
        EngineFixture::healthy();
        $this->grant = app(CampaignControl::class)->approve(app(CampaignPlaybook::class)->import(Yaml::dump($this->body(), 10)), $this->user, now()->toImmutable()->addHours(10));
        $this->episode = CampaignEpisode::create(['campaign_grant_id' => $this->grant->id, 'agent_id' => $this->agent->id, 'reset_id' => $world['reset'],
            'start_credits' => $this->agent->fresh()->credits, 'phase' => 'earn', 'opened_at' => now(), 'next_reset_at' => $world['next_reset'],
            'state' => ['role_ships' => ['command' => 'LOCAL-1', 'probe' => 'LOCAL-2'], 'last_discovery_at' => now()->toIso8601String()]]);
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        parent::tearDown();
    }

    private function body(bool $local = true): array
    {
        $body = Yaml::parse(file_get_contents(base_path('campaigns/rehearsal-macsoup-local-earn.yaml')));
        $body['agent'] = ['symbol' => 'LOCAL', 'faction' => 'VOID'];
        if (! $local) {
            unset($body['trade']['local_earn']);
        }

        return $body;
    }

    private function edit(callable $work): void
    {
        $world = json_decode(file_get_contents($this->file), true);
        $work($world);
        file_put_contents($this->file, json_encode($world));
    }

    private function read(string $market): void
    {
        app(AgentSynchronizer::class)->observe($this->agent, 'market', $market, app(SpaceTradersApi::class)->market($this->agent, $market));
    }

    private function ship(): array
    {
        return Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
    }

    private function shortlist(?float $deadline = null): array
    {
        $considered = $summary = [];
        $rows = app(CampaignLocalEarn::class)->shortlist($this->grant, $this->episode, $this->agent->fresh(), $this->ship(), $considered, $summary, $deadline ?? microtime(true) + 45);

        return [$rows, $summary, $considered];
    }

    private function decision(): array
    {
        return CampaignEvent::where('kind', 'decision')->latest('id')->firstOrFail()->data;
    }

    private function drive(AutomationRun $run): AutomationRun
    {
        for ($i = 0; $i < 80 && $run->fresh()->active_agent_id; $i++) {
            $run->update(['next_action_at' => now()]);
            app(AutomationRunner::class)->advance($run->id);
            foreach (ShipAction::where('automation_run_id', $run->id)->where('state', 'pending')->get() as $action) {
                app(ShipCommandExecutor::class)->execute($action->id);
                $this->assertSame('confirmed', $action->fresh()->state->value, $action->fresh()->error ?? 'Native command receipt');
            }
            $this->travel(6)->seconds();
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        }

        return $run->fresh();
    }

    public function test_real_director_starts_a_smaller_exact_alternative_after_top_spread_disappears_and_confirms_profit(): void
    {
        $this->load($this->episode, 100, true, 'COPPER_ORE');
        $this->edit(fn (&$world) => $world['market_goods']['X1-TEST-PORT'][1]['sellPrice'] = 80);
        $this->read('X1-TEST-PORT');
        $snapshotQueries = 0;
        DB::listen(function ($query) use (&$snapshotQueries) {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'game_observations') && str_contains($query->sql, '"kind" in (?, ?, ?)')
                && in_array('waypoint', $query->bindings, true) && in_array('market_listing', $query->bindings, true) && in_array('market', $query->bindings, true)) {
                $snapshotQueries++;
            }
        });
        $projectionCalls = 0;
        $trades = \Mockery::mock(CampaignTrade::class)->makePartial();
        $trades->shouldReceive('rank')->never();
        $trades->shouldReceive('projectPair')->andReturnUsing(function (...$args) use (&$projectionCalls) {
            $projectionCalls++;

            return (new CampaignTrade)->projectPair(...$args);
        });
        $this->app->instance(CampaignTrade::class, $trades);
        $this->edit(fn (&$world) => $world['calls'] = []);
        $file = $this->file;
        $local = new class($file) extends CampaignLocalEarn
        {
            public array $initial = [];

            public function __construct(private string $file) {}

            public function shortlist(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $ship, array &$considered, array &$summary, float $deadline, ?Collection $evidence = null): array
            {
                $rows = $this->initial = parent::shortlist($grant, $episode, $agent, $ship, $considered, $summary, $deadline, $evidence);
                $world = json_decode(file_get_contents($this->file), true);
                $world['market_goods']['X1-TEST-PORT'][0]['sellPrice'] = 20;
                file_put_contents($this->file, json_encode($world));

                return $rows;
            }
        };
        $this->app->instance(CampaignLocalEarn::class, $local);
        app(CampaignDirector::class)->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->first();
        $this->assertNotNull($run, json_encode(['events' => CampaignEvent::all()->toArray(), 'grant' => $this->grant->fresh()->toArray()]));
        $this->assertSame(['trade', 'COPPER_ORE', 'X1-TEST-ROCK', 'X1-TEST-PORT'], [$run->strategy, $run->plan['symbol'], $run->plan['site'], $run->plan['market']]);
        $this->assertSame('first_scaled', $run->plan['campaign_trade']['mode']);
        $this->assertSame(12, $run->plan['units']);
        $this->assertGreaterThan(0, $run->plan['campaign_trade']['conservative_initial_contribution']);
        $this->assertLessThan($local->initial[0]['conservative_initial_contribution'], $run->plan['campaign_trade']['conservative_initial_contribution']);
        $summary = $this->decision()['local_earn'];
        $this->assertSame(['refused', 'trade_started'], array_column($summary['attempts'], 'outcome'));
        $this->assertSame('spread', $summary['attempts'][0]['stage']);
        $this->assertSame(2, $summary['exact_rechecks']);
        $this->assertSame(1, $summary['exact_projections']);
        $this->assertSame(2, count($summary['endpoint_reads']));
        $this->assertSame(1, $summary['snapshot_loads']);
        $this->assertSame(1, $snapshotQueries);
        $this->assertSame($summary['projections'] + $summary['exact_projections'], $projectionCalls);
        $calls = json_decode(file_get_contents($this->file), true)['calls'];
        $this->assertCount(2, array_filter($calls, fn ($call) => str_ends_with($call['path'], '/market')), 'Native preview/start reads coalesce within the existing client scope; the selector reads only two distinct endpoints.');
        $this->assertLessThanOrEqual(12, $summary['projections']);
        $this->assertDatabaseCount('automation_runs', 2);
        $finished = $this->drive($run);
        $this->assertSame('completed', $finished->state->value, $finished->blocked_reason ?? '');
        app(CampaignTrade::class)->recordResults($this->episode);
        $result = CampaignEvent::where('kind', 'load_result')->where('data->run_id', $run->id)->firstOrFail()->data;
        $this->assertGreaterThan(0, $result['net']);
        $this->assertSame('COPPER_ORE', $result['good']);
        $this->assertSame('local_shortlist_bounded', $result['selection_basis']);
        $this->assertSame(0, Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot['cargo']['units']);
        $this->assertNull($this->grant->fresh()->paused_at);
    }

    public function test_local_foreign_noise_claims_invalid_and_deferred_pairs_never_expand_the_projection_bound_or_first_seen(): void
    {
        $this->noise('X9-FOREIGN', 120);
        $this->noise('X1-TEST', 30);
        // A live arm's deterministic claim is checked before expensive projection.
        $arm = $this->arm();
        app(FleetClaims::class)->claim($arm, ['good' => 'IRON_ORE', 'source' => 'X1-TEST-NOISE0', 'buyer' => 'X1-TEST-NOISE1', 'key' => 'IRON_ORE@X1-TEST-NOISE1']);
        GameObservation::where('symbol', 'X1-TEST-NOISE2')->where('kind', 'market')->update(['data->invalidated_by_action' => 999]);
        $projected = [];
        $trade = \Mockery::mock(CampaignTrade::class)->makePartial();
        $trade->shouldReceive('projectPair')->andReturnUsing(function (...$args) use (&$projected) {
            $projected[] = $args[3];

            return (new CampaignTrade)->projectPair(...$args);
        });
        $this->app->instance(CampaignTrade::class, $trade);
        [$rows, $summary] = $this->shortlist();
        $this->assertCount(12, $projected);
        foreach ($projected as $pair) {
            $this->assertStringStartsWith('X1-TEST-', $pair['source']);
            $this->assertStringStartsWith('X1-TEST-', $pair['market']);
            $this->assertNotContains('X1-TEST-NOISE0', [$pair['source'], $pair['market']]);
            $this->assertNotContains('X1-TEST-NOISE1', [$pair['source'], $pair['market']]);
        }
        $this->assertGreaterThan(0, $summary['deferred_pairs']);
        $this->assertGreaterThan(0, $summary['rejections']['claimed']);
        $this->assertSame(1, $summary['snapshot_loads']);
        $this->assertLessThanOrEqual(12, count($this->episode->fresh()->state['first_seen']));
        $this->assertSame('local_shortlist_bounded', $rows[0]['selection_basis']);
    }

    private function noise(string $system, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $symbol = $system.'-NOISE'.$i;
            $point = ['symbol' => $symbol, 'systemSymbol' => $system, 'x' => 1, 'y' => 1];
            app(AgentSynchronizer::class)->observe($this->agent, 'waypoint', $symbol, $point);
            app(AgentSynchronizer::class)->observe($this->agent, 'market', $symbol, ['symbol' => $symbol,
                'imports' => [], 'exports' => [], 'exchange' => [['symbol' => 'IRON_ORE']],
                'tradeGoods' => [['symbol' => 'IRON_ORE', 'tradeVolume' => 4, 'purchasePrice' => 10, 'sellPrice' => 250 + $i]]]);
        }
    }

    #[DataProvider('netCases')]
    public function test_conservative_positioning_charge_requires_strictly_positive_net(int $cycle, int $position, bool $accept): void
    {
        if (! $accept) {
            $this->expectException(LocalEarnRefusal::class);
        }
        $row = app(CampaignLocalEarn::class)->contribution(['conservative_margin' => $cycle, 'position_fuel_allowance' => $position, 'seconds' => 120]);
        $this->assertSame($cycle - $position, $row['conservative_initial_contribution']);
        $this->assertEquals(($cycle - $position) / 2, $row['score']);
    }

    public static function netCases(): array
    {
        return [[1, 0, true], [101, 100, true], [100, 100, false], [99, 100, false]];
    }

    public function test_current_pair_retains_a_projection_slot_and_only_competes_in_its_readiness_tier(): void
    {
        $this->noise('X1-TEST', 20);
        $state = $this->episode->state;
        $state['current_pair'] = 'COPPER_ORE@X1-TEST-PORT';
        $this->episode->update(['state' => $state]);
        [$rows] = $this->shortlist();
        $this->assertContains('COPPER_ORE@X1-TEST-PORT', array_column($rows, 'key'));
        foreach ($rows as $row) {
            if ($row['source'] === 'X1-TEST-ROCK') {
                $this->assertSame(0, $row['position_fuel_allowance']);
            }
        }
    }

    #[DataProvider('closedFailures')]
    public function test_closed_start_failures_never_advance_to_another_candidate_or_scout(string $kind): void
    {
        $runner = \Mockery::mock(AutomationRunner::class, [app(ShipCommandExecutor::class), app(AgentSynchronizer::class), app(MiningPlanner::class), app(SpaceTradersApi::class), app(EngineGate::class), app(ContractPlanner::class)])->makePartial();
        $calls = 0;
        $runner->shouldReceive('startCampaign')->andReturnUsing(function ($agent, $run, $episode) use ($kind, &$calls) {
            $calls++;
            if ($kind === 'owner') {
                $run->update(['active_agent_id' => $agent->id, 'state' => 'running', 'started_at' => now()]);
            }
            if ($kind === 'action') {
                ShipAction::create(['agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'ship_symbol' => $run->ship_symbol, 'automation_run_id' => $run->id,
                    'key' => 'created-during-start', 'command' => 'dock', 'origin' => 'automation', 'arguments' => [], 'state' => 'rejected', 'expires_at' => now()->addMinute()]);
            }
            throw match ($kind) {
                'api' => new GameException('API-coded refusal', apiCode: 4000),
                'uncertain' => new GameException('Uncertain refusal', uncertain: true),
                'retry' => new GameException('Budget wait', retryAfter: 5),
                'owner', 'action' => new StaleQuote('Typed stale refusal after ownership'),
                'generic' => new GameException('Unclassified refusal before any action.'),
                default => new GameException('Preview exceeds campaign authority.'),
            };
        });
        $this->app->instance(AutomationRunner::class, $runner);
        app(CampaignDirector::class)->tick();
        $this->assertSame(1, $calls);
        $this->assertDatabaseCount('automation_runs', 1);
        $this->assertSame('closed_stop', $this->decision()['local_earn']['end']);
        $this->assertSame('trade', AutomationRun::firstOrFail()->strategy);
        if (in_array($kind, ['uncertain', 'owner', 'action'], true)) {
            $this->assertNotNull($this->grant->fresh()->paused_at);
            $this->assertSame('state_requires_review', $this->grant->fresh()->pause_reason);
        } else {
            $this->assertNull($this->grant->fresh()->paused_at);
            $this->assertSame($kind === 'retry' ? 'awaiting_fresh_state' : 'local_preflight_refused', $this->grant->fresh()->wait_reason);
        }
        if ($kind === 'retry') {
            $this->assertSame('preview', AutomationRun::firstOrFail()->state->value);
        }
    }

    public static function closedFailures(): array
    {
        return array_map(fn ($kind) => [$kind], ['api', 'uncertain', 'retry', 'authority', 'generic', 'owner', 'action']);
    }

    public function test_typed_unsent_preview_refusal_is_finalized_before_the_smaller_alternative_starts(): void
    {
        $runner = \Mockery::mock(AutomationRunner::class, [app(ShipCommandExecutor::class), app(AgentSynchronizer::class), app(MiningPlanner::class), app(SpaceTradersApi::class), app(EngineGate::class), app(ContractPlanner::class)])->makePartial();
        $real = app(AutomationRunner::class);
        $runner->shouldReceive('startCampaign')->andReturnUsing(function ($agent, $run, $episode) use ($real) {
            if ($run->plan['symbol'] === 'IRON_ORE') {
                throw new StaleQuote('Typed candidate loading-life refusal.');
            }

            return $real->startCampaign($agent, $run, $episode);
        });
        $this->app->instance(AutomationRunner::class, $runner);
        app(CampaignDirector::class)->tick();
        $this->assertSame('stopped', AutomationRun::oldest('id')->firstOrFail()->state->value);
        $this->assertSame('COPPER_ORE', AutomationRun::whereNotNull('active_agent_id')->firstOrFail()->plan['symbol']);
        $this->assertSame(1, AutomationRun::whereNotNull('started_at')->count());
    }

    public function test_paced_endpoint_reads_exhaust_useful_time_and_defer_the_entire_pass_without_preview_or_scout(): void
    {
        $this->edit(fn (&$world) => $world['pace_seconds'] = 0.04);
        $local = new class extends CampaignLocalEarn
        {
            public ?float $limited = null;

            public function shortlist(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $ship, array &$considered, array &$summary, float $deadline, ?Collection $evidence = null): array
            {
                $rows = parent::shortlist($grant, $episode, $agent, $ship, $considered, $summary, $deadline, $evidence);
                $this->limited = microtime(true) + self::RESERVE_SECONDS + 0.02;

                return $rows;
            }

            public function reserve(float $deadline): void
            {
                parent::reserve($this->limited === null ? $deadline : min($deadline, $this->limited));
            }
        };
        $this->app->instance(CampaignLocalEarn::class, $local);
        app(CampaignDirector::class)->tick();
        $this->assertDatabaseCount('automation_runs', 0);
        $this->assertSame('local_budget_deferred', $this->grant->fresh()->wait_reason);
        $summary = $this->decision()['local_earn'];
        $this->assertSame('local_budget_deferred', $summary['end']);
        $this->assertLessThanOrEqual(2, count($summary['endpoint_reads']));
        $this->assertSame(0, $summary['exact_projections']);
        $world = json_decode(file_get_contents($this->file), true);
        $this->assertSame([], $world['writes']);
    }

    private function extraMarket(string $symbol, array $goods, int $x = 1): void
    {
        $point = ['symbol' => $symbol, 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => $x, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]];
        $this->edit(function (&$world) use ($symbol, $goods, $point) {
            $world['waypoints'][] = $point;
            $world['market_goods'][$symbol] = $goods;
        });
        app(AgentSynchronizer::class)->observe($this->agent, 'waypoint', $symbol, $point);
        app(AgentSynchronizer::class)->observe($this->agent, 'market', $symbol, ['symbol' => $symbol, 'imports' => [], 'exports' => [], 'exchange' => array_map(fn ($good) => ['symbol' => $good['symbol']], $goods), 'tradeGoods' => $goods]);
    }

    private function freeAndPaidWorld(bool $fuelFree = true): void
    {
        $this->extraMarket('X1-TEST-ALT', [['symbol' => 'GOLD', 'purchasePrice' => 10, 'sellPrice' => 8, 'tradeVolume' => 4]]);
        $this->extraMarket('X1-TEST-OTHER', [['symbol' => 'GOLD', 'purchasePrice' => 120, 'sellPrice' => 100, 'tradeVolume' => 4]], 2);
        $this->edit(function (&$world) use ($fuelFree) {
            $world['ships'][1]['nav']['waypointSymbol'] = 'X1-TEST-ROCK';
            if (! $fuelFree) {
                $world['ships'][1]['frame']['symbol'] = 'FRAME_MINER';
                $world['ships'][1]['fuel'] = ['current' => 100, 'capacity' => 100];
            }
            $world['ships'][] = FakeSpaceTraders::ship('LOCAL-4', 'X1-TEST-OTHER');
        });
        app(AgentSynchronizer::class)->sync($this->agent, false);
        GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-PORT')->update(['observed_at' => now()->subMinutes(5)]);
    }

    public function test_free_buyer_observation_precedes_eligible_paid_command_movement_and_starts_only_one_run(): void
    {
        $this->freeAndPaidWorld();
        [$rows] = $this->shortlist();
        $this->assertNotEmpty(array_filter($rows, fn ($row) => $row['source'] === 'X1-TEST-ROCK' && $row['readiness'] === 2));
        $away = collect($rows)->firstWhere('source', 'X1-TEST-ALT');
        $this->assertTrue(app(CampaignLocalEarn::class)->positionEvidence($this->grant, $this->agent->fresh(), $this->ship(), $away));
        app(CampaignDirector::class)->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame(['scout', 'LOCAL-2', 'X1-TEST-PORT', 'free_observation'], [$run->strategy, $run->ship_symbol, $run->plan['destination'], $run->plan['campaign_local_earn']['kind']]);
        $this->assertSame(0, $run->plan['max_spend']);
        $this->assertSame(0, $run->plan['route']['fuel_estimate']);
        $this->assertArrayNotHasKey('campaign_trade', $run->plan);
        $this->assertSame('X1-TEST-ROCK', $this->ship()['nav']['waypointSymbol']);
        $this->assertSame(0, $this->episode->fresh()->state['local_earn_positioning']['count'] ?? 0);
        $this->assertSame('free_observation_started', $this->decision()['local_earn']['end']);
        $this->assertSame(1, AutomationRun::whereNotNull('started_at')->count());
        $this->assertSame('completed', $this->drive($run)->state->value);
        $this->assertSame([], ShipAction::where('automation_run_id', $run->id)->whereIn('command', ['buy', 'refuel'])->pluck('id')->all());
    }

    public function test_paid_probe_is_excluded_and_positioning_count_counts_only_successes_and_obeys_settled_cooldown(): void
    {
        $this->freeAndPaidWorld(false);
        app(CampaignDirector::class)->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame(['LOCAL-1', 'paid_position'], [$run->ship_symbol, $run->plan['campaign_local_earn']['kind']]);
        $this->assertGreaterThan(0, $run->plan['campaign_local_earn']['candidate']['position_fuel_allowance']);
        $this->assertSame(1, $this->episode->fresh()->state['local_earn_positioning']['count']);
        app(AutomationRunner::class)->startCampaign($this->agent->fresh(), $run, $this->episode);
        $this->assertSame(1, $this->episode->fresh()->state['local_earn_positioning']['count']);
        $this->travel(31)->minutes();
        $this->assertSame(1, app(CampaignLocalEarn::class)->positioning($this->episode)['count'], 'An owned run prevents cooldown reset.');
        $run->update(['active_agent_id' => null, 'state' => 'completed']);
        $this->assertSame(0, app(CampaignLocalEarn::class)->positioning($this->episode)['count']);
    }

    public function test_paid_positioning_cap_is_enforced_at_common_start_even_for_retained_preview(): void
    {
        $this->freeAndPaidWorld(false);
        app(CampaignDirector::class)->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $run->update(['active_agent_id' => null, 'state' => 'preview', 'started_at' => null, 'expires_at' => now()->addMinute()]);
        $this->episode->update(['state' => array_merge($this->episode->fresh()->state, ['local_earn_positioning' => ['count' => 2, 'last_started_at' => now()->toIso8601String()]])]);
        $this->expectExceptionMessage('positioning cap');
        app(AutomationRunner::class)->startCampaign($this->agent->fresh(), $run, $this->episode);
    }

    public function test_paid_movement_rechecks_fuel_costs_after_native_preflight(): void
    {
        $this->marginalPaidPosition();
        $local = app(CampaignLocalEarn::class);
        $mock = \Mockery::mock(CampaignLocalEarn::class)->makePartial();
        $mock->shouldReceive('shortlist')->once()->andReturnUsing(function ($grant, $episode, $agent, $ship, &$considered, &$summary, $deadline) use ($local) {
            $rows = $local->shortlist($grant, $episode, $agent, $ship, $considered, $summary, $deadline);
            $this->assertSame(8, collect($rows)->firstWhere('good', 'GOLD')['conservative_initial_contribution']);
            $this->raiseFuelPrice();

            return $rows;
        });
        $this->app->instance(CampaignLocalEarn::class, $mock);
        app(CampaignDirector::class)->tick();
        $preview = AutomationRun::where('plan->campaign_local_earn->kind', 'paid_position')->firstOrFail();
        $this->assertNull($preview->started_at);
        $this->assertSame('stopped', $preview->state->value);
        $this->assertStringContainsString('contribution', $preview->blocked_reason);
        $this->assertSame(0, $this->episode->fresh()->state['local_earn_positioning']['count'] ?? 0);
        $this->assertDatabaseCount('ship_actions', 0);
    }

    public function test_retained_paid_preview_cannot_start_when_fuel_erases_its_margin(): void
    {
        $this->marginalPaidPosition();
        $real = app(AutomationRunner::class);
        $runner = \Mockery::mock(AutomationRunner::class, [app(ShipCommandExecutor::class), app(AgentSynchronizer::class), app(MiningPlanner::class), app(SpaceTradersApi::class), app(EngineGate::class), app(ContractPlanner::class)])->makePartial();
        $runner->shouldReceive('startCampaign')->once()->andThrow(new GameException('Request budget deferral', retryAfter: 5));
        $this->app->instance(AutomationRunner::class, $runner);
        app(CampaignDirector::class)->tick();
        $preview = AutomationRun::where('state', 'preview')->where('plan->campaign_local_earn->kind', 'paid_position')->firstOrFail();
        $this->assertSame(8, $preview->plan['campaign_local_earn']['candidate']['conservative_initial_contribution']);

        $this->app->instance(AutomationRunner::class, $real);
        $this->raiseFuelPrice();
        $this->travel(31)->seconds();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        app(CampaignDirector::class)->tick();
        $this->assertSame('stopped', $preview->fresh()->state->value);
        $this->assertNull($preview->fresh()->started_at);
        $this->assertStringContainsString('contribution', $preview->fresh()->blocked_reason);
        $this->assertNull($this->grant->fresh()->paused_at);
        $this->assertSame(0, $this->episode->fresh()->state['local_earn_positioning']['count'] ?? 0);
        $this->assertDatabaseCount('ship_actions', 0);
    }

    private function marginalPaidPosition(): void
    {
        $this->freeAndPaidWorld(false);
        $this->edit(function (&$world) {
            $world['market_goods']['X1-TEST-OTHER'][0]['sellPrice'] = 17;
        });
        $this->read('X1-TEST-OTHER');
    }

    private function raiseFuelPrice(): void
    {
        $this->edit(function (&$world) {
            $world['market_goods']['X1-TEST-ROCK'] ??= $world['goods'];
            foreach ($world['market_goods']['X1-TEST-ROCK'] as &$good) {
                if ($good['symbol'] === 'FUEL') {
                    $good['purchasePrice'] = 10;
                }
            }
        });
    }

    #[DataProvider('parkedReadFailures')]
    public function test_optional_parked_market_read_only_defers_for_the_local_time_reserve(bool $reserveReached): void
    {
        $this->extraMarket('X1-TEST-IDLE', [['symbol' => 'GOLD', 'purchasePrice' => 10, 'sellPrice' => 8, 'tradeVolume' => 4]]);
        $this->edit(function (&$world) {
            $world['ships'][] = FakeSpaceTraders::ship('LOCAL-4', 'X1-TEST-IDLE');
        });
        GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-IDLE')->update(['observed_at' => now()->subMinutes(5)]);
        $api = app(SpaceTradersApi::class);
        $mock = \Mockery::mock(SpaceTradersApi::class, [app(SpaceTradersClient::class)])->makePartial();
        $mock->shouldReceive('market')->withArgs(fn ($agent, $symbol) => $symbol === 'X1-TEST-IDLE')->once()
            ->andThrow($reserveReached ? new LocalEarnBudget : new GameException('Synthetic parked-market refusal.', apiCode: 4000));
        $mock->shouldReceive('market')->byDefault()->andReturnUsing(fn ($agent, $symbol) => $api->market($agent, $symbol));
        $this->app->instance(SpaceTradersApi::class, $mock);

        app(CampaignDirector::class)->tick();
        $this->assertNull($this->grant->fresh()->paused_at);
        $this->assertSame('X1-TEST-IDLE', $this->episode->fresh()->state['parked_refresh']);
        if ($reserveReached) {
            $this->assertSame('local_budget_deferred', $this->grant->fresh()->wait_reason);
            $this->assertDatabaseCount('automation_runs', 0);
        } else {
            $this->assertSame('trade', AutomationRun::whereNotNull('active_agent_id')->firstOrFail()->strategy);
        }
    }

    public static function parkedReadFailures(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('freeStopFailures')]
    public function test_free_observer_start_uses_the_same_closed_stop_list_and_cannot_fall_through_to_paid_movement(string $kind): void
    {
        $this->freeAndPaidWorld();
        $real = app(AutomationRunner::class);
        $runner = \Mockery::mock(AutomationRunner::class, [app(ShipCommandExecutor::class), app(AgentSynchronizer::class), app(MiningPlanner::class), app(SpaceTradersApi::class), app(EngineGate::class), app(ContractPlanner::class)])->makePartial();
        $calls = [];
        $runner->shouldReceive('startCampaign')->andReturnUsing(function ($agent, $run, $episode) use ($kind, &$calls) {
            $calls[] = $run->ship_symbol;
            if ($kind === 'owner') {
                $run->update(['active_agent_id' => $agent->id, 'state' => 'running', 'started_at' => now()]);
            }
            throw match ($kind) {
                'api' => new GameException('API refusal', apiCode: 5000),
                'uncertain' => new GameException('Unknown observer outcome', uncertain: true),
                'retry' => new GameException('Wait for observer', retryAfter: 5),
                'owner' => new StaleQuote('Owned observer refusal'),
                default => new GameException('Unclassified observer start'),
            };
        });
        $this->app->instance(AutomationRunner::class, $runner);
        app(CampaignDirector::class)->tick();
        $this->assertSame(['LOCAL-2'], $calls);
        $this->assertDatabaseCount('automation_runs', 1);
        $this->assertSame(0, $this->episode->fresh()->state['local_earn_positioning']['count'] ?? 0);
        $this->assertSame('closed_stop', $this->decision()['local_earn']['end']);
    }

    public static function freeStopFailures(): array
    {
        return array_map(fn ($kind) => [$kind], ['api', 'uncertain', 'retry', 'owner', 'generic']);
    }

    public function test_observer_backoff_and_known_free_refusal_allow_safe_paid_fallback(): void
    {
        $this->freeAndPaidWorld();
        $real = app(AutomationRunner::class);
        $runner = \Mockery::mock(AutomationRunner::class, [app(ShipCommandExecutor::class), app(AgentSynchronizer::class), app(MiningPlanner::class), app(SpaceTradersApi::class), app(EngineGate::class), app(ContractPlanner::class)])->makePartial();
        $calls = [];
        $runner->shouldReceive('startCampaign')->andReturnUsing(function ($agent, $run, $episode) use ($real, &$calls) {
            $calls[] = $run->ship_symbol;
            if ($run->ship_symbol === 'LOCAL-2') {
                throw new LocalEarnRefusal('route', 'Known unsent route refusal.');
            }

            return $real->startCampaign($agent, $run, $episode);
        });
        $this->app->instance(AutomationRunner::class, $runner);
        app(CampaignDirector::class)->tick();
        $this->assertSame(['LOCAL-2', 'LOCAL-1'], $calls);
        $this->assertSame(1, AutomationRun::whereNotNull('started_at')->count());
        $this->assertSame('stopped', AutomationRun::oldest('id')->firstOrFail()->state->value);
        $this->assertNotEmpty($this->episode->fresh()->state['probe_position_failed']);
        $this->assertSame(1, $this->episode->fresh()->state['local_earn_positioning']['count']);
    }

    public function test_safe_candidate_exhaustion_can_use_the_ordinary_scout_fallback(): void
    {
        $this->freeAndPaidWorld();
        GameObservation::where('kind', 'market')->update(['data->invalidated_by_action' => 1]);
        // The fallback still uses native scout rules after candidate exhaustion.
        app(CampaignDirector::class)->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('scout', $run->strategy);
        $this->assertArrayNotHasKey('campaign_local_earn', $run->plan);
        $this->assertSame('candidate_exhausted', $this->decision()['local_earn']['end']);
    }

    public function test_retained_preview_harvests_newly_finalized_losses_before_resuming_and_preserves_it_on_retry(): void
    {
        $real = app(AutomationRunner::class);
        $runner = \Mockery::mock(AutomationRunner::class, [app(ShipCommandExecutor::class), app(AgentSynchronizer::class), app(MiningPlanner::class), app(SpaceTradersApi::class), app(EngineGate::class), app(ContractPlanner::class)])->makePartial();
        $runner->shouldReceive('startCampaign')->once()->andThrow(new GameException('Request budget deferral', retryAfter: 5));
        $this->app->instance(AutomationRunner::class, $runner);
        app(CampaignDirector::class)->tick();
        $preview = AutomationRun::where('state', 'preview')->firstOrFail();
        $this->assertSame($this->episode->id, $preview->campaign_episode_id);
        $this->load($this->episode, -1);
        $this->load($this->episode, -2);
        $this->app->instance(AutomationRunner::class, $real);
        $this->travel(31)->seconds();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        app(CampaignDirector::class)->tick();
        $this->assertSame('stopped', $preview->fresh()->state->value);
        $this->assertNull($preview->fresh()->started_at);
        $this->assertSame(2, CampaignEvent::where('kind', 'load_result')->count());
        $this->assertSame(0, AutomationRun::whereNotNull('active_agent_id')->count());
    }

    #[DataProvider('retainedFailures')]
    public function test_retained_start_keeps_uncertainty_and_work_under_review(string $kind): void
    {
        $runner = \Mockery::mock(AutomationRunner::class, [app(ShipCommandExecutor::class), app(AgentSynchronizer::class), app(MiningPlanner::class), app(SpaceTradersApi::class), app(EngineGate::class), app(ContractPlanner::class)])->makePartial();
        $runner->shouldReceive('startCampaign')->once()->andThrow(new GameException('Wait for the request budget.', retryAfter: 5));
        $this->app->instance(AutomationRunner::class, $runner);
        app(CampaignDirector::class)->tick();
        $preview = AutomationRun::where('state', 'preview')->firstOrFail();

        $runner->shouldReceive('startCampaign')->once()->andReturnUsing(function ($agent, $run) use ($kind, $preview) {
            $this->assertSame($preview->id, $run->id);
            if ($kind === 'owner') {
                $run->update(['active_agent_id' => $agent->id, 'state' => 'running', 'started_at' => now()]);
                throw new StaleQuote('The retained start acquired ownership before the refusal.');
            }
            if ($kind === 'action') {
                ShipAction::create(['agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'ship_symbol' => $run->ship_symbol, 'automation_run_id' => $run->id,
                    'key' => 'created-during-retained-start', 'command' => 'dock', 'origin' => 'automation', 'arguments' => [], 'state' => 'rejected', 'expires_at' => now()->addMinute()]);
            }
            throw new GameException('Retained start refused.', retryAfter: $kind === 'action' ? 5 : 0, uncertain: $kind === 'uncertain');
        });
        $this->travel(31)->seconds();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        app(CampaignDirector::class)->tick();
        $this->assertDatabaseCount('automation_runs', 1);
        if ($kind === 'routine') {
            $this->assertNull($this->grant->fresh()->paused_at);
            $this->assertSame('preview_discarded', $this->decision()['choice']);
            $this->assertSame('stopped', $preview->fresh()->state->value);

            return;
        }

        $this->assertSame('state_requires_review', $this->grant->fresh()->pause_reason);
        $this->assertNotNull($this->grant->fresh()->paused_at);
        $this->assertSame('local_stopped', $this->decision()['choice']);
        if ($kind === 'owner') {
            $this->assertSame($this->agent->id, $preview->fresh()->active_agent_id);
            $this->assertSame('stopping', $preview->fresh()->state->value);
            $this->assertNotNull($preview->fresh()->stop_requested_at);
        } elseif ($kind === 'action') {
            $action = ShipAction::where('automation_run_id', $preview->id)->sole();
            $this->assertSame('rejected', $action->state->value);
        }
    }

    public static function retainedFailures(): array
    {
        return [['uncertain'], ['owner'], ['action'], ['routine']];
    }

    public function test_positioning_requires_persistent_buyer_or_quote_survival_and_preserves_conservative_fuel_and_recon_cash(): void
    {
        $this->freeAndPaidWorld(false);
        [$rows] = $this->shortlist();
        $away = collect($rows)->firstWhere('source', 'X1-TEST-ALT');
        $local = app(CampaignLocalEarn::class);
        $this->assertTrue($local->stableBuyer($this->agent->fresh(), 'X1-TEST-OTHER', 'LOCAL-1'));
        $this->assertFalse($local->stableBuyer($this->agent->fresh(), 'X1-TEST-PORT', 'LOCAL-1'));
        $this->assertSame($away['conservative_margin'] - $away['position_fuel_allowance'], $away['conservative_initial_contribution']);
        GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-OTHER')->update(['observed_at' => now()->subMinutes(10)]);
        $this->assertTrue($local->positionEvidence($this->grant, $this->agent->fresh(), $this->ship(), $away));
        Ship::where('symbol', 'LOCAL-4')->delete();
        $this->assertFalse($local->positionEvidence($this->grant, $this->agent->fresh(), $this->ship(), $away));
        GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-OTHER')->update(['observed_at' => now()->subMinutes(16)]);
        $this->assertFalse($local->positionEvidence($this->grant, $this->agent->fresh(), $this->ship(), $away));
    }

    public function test_cutover_does_not_require_recon_loaned_probe_to_return_and_successor_keeps_original_probe_pin(): void
    {
        $this->edit(function (&$world) {
            $world['ships'][1]['nav']['waypointSymbol'] = 'X9-AWAY-PORT';
            $world['ships'][1]['nav']['systemSymbol'] = 'X9-AWAY';
        });
        app(AgentSynchronizer::class)->sync($this->agent, false);
        $terms = ['return_deadline' => now()->addHours(2)->toIso8601String()];
        ReconMission::create(['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-2', 'state' => 'exploring',
            'terms' => $terms, 'terms_hash' => ReconMission::hash($terms), 'graph' => [],
            'origin_system' => 'X1-TEST', 'origin_gate' => 'X1-TEST-ROCK', 'approved_by' => $this->user->id,
            'approved_at' => now(), 'start_window_until' => now()->addHour(), 'visits' => [], 'capability' => [], 'return_reservation' => [], 'homeward_authorizations' => []]);
        $this->assertTrue(ReconMission::holds($this->agent->fresh(), 'LOCAL-2'));
        $new = $this->replacement();
        $episode = CampaignEpisode::where('campaign_grant_id', $new->id)->firstOrFail();
        $this->assertNull(app(CampaignFleet::class)->role($episode, 'probe'));
        $this->assertSame('LOCAL-2', $episode->state['role_ships']['probe']);
    }

    public function test_a_native_local_projection_and_buy_accept_exactly_one_conservative_credit(): void
    {
        $this->edit(function (&$world) {
            $world['ships'][0]['cargo']['capacity'] = 1;
            $world['market_goods']['X1-TEST-PORT'][0]['sellPrice'] = 41;
            $world['market_goods']['X1-TEST-PORT'][1]['sellPrice'] = 20;
        });
        app(AgentSynchronizer::class)->sync($this->agent, false);
        $this->read('X1-TEST-PORT');
        [$rows] = $this->shortlist();
        $this->assertSame(1, $rows[0]['conservative_initial_contribution']);
        $this->assertSame(0, $rows[0]['position_fuel_allowance']);
        app(CampaignDirector::class)->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('trade', $run->strategy);
        $this->assertSame(1, $run->plan['campaign_trade']['conservative_initial_contribution']);
        $this->assertSame(1, $run->plan['units']);
    }

    public function test_local_quotes_keep_metadata_age_and_must_survive_loading_at_the_strict_boundary(): void
    {
        [$rows] = $this->shortlist();
        $candidate = $rows[0];
        $quote = GameObservation::where('kind', 'market')->where('symbol', $candidate['buyer'])->firstOrFail();
        $loadSeconds = (int) ceil($candidate['units'] / $candidate['source_volume']) * 10 + 10;
        $quote->update(['observed_at' => now()->subSeconds(180 - $loadSeconds)]);
        $stamp = $quote->fresh()->observed_at;
        app(AgentSynchronizer::class)->observe($this->agent, 'market', $candidate['buyer'], ['symbol' => $candidate['buyer'], 'imports' => [], 'exports' => [], 'exchange' => []]);
        $this->assertTrue($stamp->equalTo($quote->fresh()->observed_at));
        $this->expectException(StaleQuote::class);
        app(CampaignTrade::class)->quoteLife($this->agent, ['site' => $candidate['source'], 'market' => $candidate['buyer'], 'symbol' => $candidate['good'], 'units' => $candidate['units'], 'campaign_trade' => ['quote_max_age_seconds' => 180]], []);
    }

    public function test_recon_return_cash_reduces_local_size_and_protects_paid_movement_without_double_charging_profit(): void
    {
        [$before] = $this->shortlist();
        $this->edit(function (&$world) {
            $world['ships'][1]['nav']['systemSymbol'] = 'X9-AWAY';
            $world['ships'][1]['nav']['waypointSymbol'] = 'X9-AWAY-PORT';
        });
        app(AgentSynchronizer::class)->sync($this->agent, false);
        $terms = ['return_deadline' => now()->addHours(2)->toIso8601String(), 'envelope' => 100000, 'jump_exposure' => 7500];
        ReconMission::create(['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-2', 'state' => 'exploring',
            'terms' => $terms, 'terms_hash' => ReconMission::hash($terms), 'graph' => [], 'origin_system' => 'X1-TEST', 'origin_gate' => 'X1-TEST-ROCK',
            'approved_by' => $this->user->id, 'approved_at' => now(), 'start_window_until' => now()->addHour(), 'visits' => [], 'capability' => [],
            'return_reservation' => ['cursor' => 0, 'caps' => [99900], 'total' => 99900, 'path' => []], 'homeward_authorizations' => []]);
        $this->assertSame(99900, ReconMission::returnExposure($this->agent->fresh()));
        [$after] = $this->shortlist();
        $row = collect($after)->firstWhere('good', 'IRON_ORE');
        $original = collect($before)->firstWhere('good', 'IRON_ORE');
        $this->assertLessThan($original['units'], $row['units']);
        $this->assertLessThanOrEqual(100, $row['max_inventory'] + $row['fuel_safety_ceiling'] + $row['departure_refuel']);
        $this->assertGreaterThan(0, $row['conservative_initial_contribution']);
        $this->assertSame($row['conservative_margin'], $row['conservative_initial_contribution']);
        app(CampaignDirector::class)->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('trade', $run->strategy);
        $this->assertLessThanOrEqual(100, $run->plan['max_spend']);
    }

    public function test_replacement_and_paid_start_share_campaign_then_agent_lock_ownership(): void
    {
        $outer = app(HostLock::class);
        $independent = new HostLock;
        $entered = false;
        $outer->scoped('campaign', function () use ($independent, &$entered) {
            try {
                $independent->run('campaign', fn () => $entered = true);
                $this->fail('An independent approval owner entered the campaign boundary.');
            } catch (GameException $exception) {
                $this->assertGreaterThan(0, $exception->retryAfter);
            }
        });
        $this->assertFalse($entered);
        $outer->scoped('agent-'.$this->agent->id, function () use ($independent) {
            try {
                $independent->run('agent-'.$this->agent->id, fn () => null);
                $this->fail('An independent command owner entered the agent boundary.');
            } catch (GameException $exception) {
                $this->assertGreaterThan(0, $exception->retryAfter);
            }
        });
        $new = $this->replacement();
        $this->assertSame(1, $new->slot);
    }

    private function arm(): CampaignEpisode
    {
        $revision = app(CampaignPlaybook::class)->import(Yaml::dump($this->body(false), 10));
        $grant = CampaignGrant::create(['campaign_revision_id' => $revision->id, 'revision_hash' => $revision->content_hash, 'user_id' => $this->user->id,
            'limits' => $revision->body['limits'] + ['arm' => ['label' => 'H1', 'ship' => 'LOCAL-3', 'follow' => null, 'max_net_loss' => 50000, 'exposure_share' => 0.5]], 'expires_at' => now()->addHours(10)]);

        return CampaignEpisode::create(['campaign_grant_id' => $grant->id, 'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id,
            'start_credits' => $this->agent->credits, 'phase' => 'earn', 'opened_at' => now(), 'next_reset_at' => $this->episode->next_reset_at,
            'state' => ['arm' => 'H1', 'role_ships' => ['command' => 'LOCAL-3']]]);
    }

    private function load(CampaignEpisode $episode, int $net, bool $proof = true, string $good = 'IRON_ORE'): AutomationRun
    {
        return AutomationRun::create(['campaign_episode_id' => $episode->id, 'agent_id' => $this->agent->id, 'reset_id' => $episode->reset_id,
            'ship_symbol' => $episode->id === $this->episode->id ? 'LOCAL-1' : 'LOCAL-3', 'strategy' => 'trade', 'plan_version' => 3,
            'state' => 'completed', 'started_at' => now()->subMinute(), 'expires_at' => now()->addMinute(),
            'plan' => ['symbol' => $good, 'site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-PORT', 'campaign_trade' => ['proof' => $proof, 'predicted_margin' => 100]],
            'totals' => ['purchased' => [$good => 4], 'sold' => [$good => 4], 'purchase_cost' => 120, 'sale_proceeds' => 120 + $net, 'fuel_cost' => 0, 'travel_fuel' => 0]]);
    }

    private function replacement(?array $expected = null, $expires = null): CampaignGrant
    {
        $body = $this->body();
        $body['name'] = 'reviewed-successor';

        return app(CampaignControl::class)->approve(app(CampaignPlaybook::class)->import(Yaml::dump($body, 10)), $this->user, $expires,
            $expected ?? ['grant' => $this->grant->id, 'hash' => $this->grant->revision_hash, 'episode' => $this->episode->id]);
    }

    public function test_guarded_replacement_preserves_expiry_roles_results_and_h1_authority_but_switches_learning_and_decay_reference(): void
    {
        $run = $this->load($this->episode, 100);
        $arm = $this->arm();
        $own = $this->load($arm, 100, true, 'COPPER_ORE');
        foreach ([200, 180] as $index => $price) {
            ShipAction::create(['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-1', 'automation_run_id' => $run->id,
                'key' => 'decay-'.$index, 'command' => 'sell', 'origin' => 'automation', 'arguments' => ['symbol' => 'IRON_ORE'], 'state' => 'confirmed', 'expires_at' => now()->addMinute(),
                'before_state' => ['ship' => ['nav' => ['waypointSymbol' => 'X1-TEST-PORT']], 'estimate' => ['quote' => ['tradeVolume' => 4]]],
                'outcome' => ['units' => 4, 'transaction_total' => $price * 4]]);
        }
        $trade = app(CampaignTrade::class);
        $this->assertSame('first_scaled', $trade->mode($arm, 'IRON_ORE', 'X1-TEST-PORT'));
        $this->assertEqualsWithDelta(0.1, $trade->decay($arm, 'IRON_ORE', 'X1-TEST-PORT'), 0.00001);
        $before = $arm->grant()->getAttributes();
        $new = $this->replacement();
        $successor = CampaignEpisode::where('campaign_grant_id', $new->id)->firstOrFail();
        $this->assertTrue($new->expires_at->equalTo($this->grant->expires_at));
        $this->assertSame('earn', $successor->phase);
        $this->assertSame(['role_ships' => $this->episode->state['role_ships']], $successor->state);
        $this->assertSame($successor->id, app(FleetClaims::class)->reference($arm)->id);
        $this->assertSame('proof', $trade->mode($arm, 'IRON_ORE', 'X1-TEST-PORT'));
        $this->assertSame('first_scaled', $trade->mode($arm, 'COPPER_ORE', 'X1-TEST-PORT'));
        $this->assertSame(0.04, $trade->decay($arm, 'IRON_ORE', 'X1-TEST-PORT'));
        $this->assertSame($before, $arm->grant()->getAttributes());
        $this->assertNotNull($this->episode->fresh()->closed_at);
        $this->assertNotNull(AutomationRun::find($run->id));
        $this->assertNotNull(AutomationRun::find($own->id));
        $this->assertSame(2, CampaignEvent::where('kind', 'load_result')->count());
        $this->assertFalse(CampaignGrant::where('slot', 2)->exists());
    }

    public function test_local_earn_replacement_ignores_another_agents_pending_grant(): void
    {
        $other = app(CampaignPlaybook::class)->import(str_replace('GIANTSOUP', 'SOUPBURN', file_get_contents(base_path('campaigns/weekly-default.yaml'))));
        $otherPrimary = app(CampaignControl::class)->approve($other, $this->user);
        $otherPending = $otherPrimary->replicate();
        $otherPending->slot = 2;
        $otherPending->save();

        $this->assertSame(1, $this->replacement()->slot);
        $this->assertSame(1, $otherPrimary->fresh()->slot);
        $this->assertSame(2, $otherPending->fresh()->slot);
    }

    #[DataProvider('replacementBlockers')]
    public function test_replacement_refuses_before_clearing_or_staging_and_preserves_original_authority(string $blocker): void
    {
        if ($blocker === 'pending') {
            $copy = $this->grant->replicate();
            $copy->slot = 2;
            $copy->save();
        } elseif ($blocker === 'pending_pause') {
            $this->episode->update(['state' => $this->episode->state + ['pending_pause' => 'consecutive_losing_loads']]);
        } elseif ($blocker === 'pause') {
            $this->grant->update(['paused_at' => now(), 'pause_reason' => 'operator_pause']);
        } elseif ($blocker === 'loss') {
            $this->load($this->episode, -1);
        } elseif ($blocker === 'saved_loss') {
            $this->load($this->episode, -1);
            app(CampaignTrade::class)->recordResults($this->episode);
        } elseif ($blocker === 'transit' || $blocker === 'cargo') {
            Ship::where('symbol', 'LOCAL-1')->update([$blocker === 'transit' ? 'snapshot->nav->status' : 'snapshot->cargo->units' => $blocker === 'transit' ? 'IN_TRANSIT' : 1]);
        } elseif ($blocker === 'preview') {
            $run = $this->load($this->episode, 1);
            $run->update(['state' => 'preview', 'started_at' => null]);
        } elseif ($blocker === 'busy') {
            $run = $this->load($this->episode, 1);
            $run->update(['state' => 'running', 'active_agent_id' => $this->agent->id]);
        } elseif ($blocker === 'wind') {
            $this->episode->update(['next_reset_at' => now()->addMinutes(30)]);
        } elseif ($blocker === 'null_expiry') {
            DB::table('campaign_grants')->where('id', $this->grant->id)->update(['expires_at' => null]);
        } elseif ($blocker === 'expired') {
            DB::table('campaign_grants')->where('id', $this->grant->id)->update(['expires_at' => now()->subSecond()]);
        } elseif ($blocker === 'uncertain' || $blocker === 'review' || $blocker === 'pending_action') {
            ShipAction::create(['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-1', 'key' => 'cutover-action', 'command' => 'dock', 'origin' => 'automation', 'arguments' => [],
                'state' => $blocker === 'uncertain' ? 'uncertain' : ($blocker === 'review' ? 'confirmed' : 'pending'), 'review_required' => $blocker === 'review', 'expires_at' => now()->addMinute()]);
        } elseif ($blocker === 'cash') {
            $this->agent->update(['credits' => $this->agent->credits + 1]);
        }
        $expected = ['grant' => $this->grant->id, 'hash' => $this->grant->revision_hash, 'episode' => $this->episode->id + ($blocker === 'stale' ? 1 : 0)];
        try {
            $this->replacement($expected, $blocker === 'expiry' ? now()->toImmutable()->addHours(11) : null);
            $this->fail('Unsafe replacement succeeded: '.$blocker);
        } catch (GameException $exception) {
            $this->assertStringContainsString('Local Earn', $exception->getMessage());
        }
        $this->assertSame(1, $this->grant->fresh()->slot);
        $this->assertNull($this->episode->fresh()->closed_at);
        $this->assertSame($blocker === 'pending' ? 1 : 0, CampaignGrant::where('slot', 2)->count());
        $this->assertSame(1, CampaignEvent::where('kind', 'replacement_refused')->count());
    }

    public static function replacementBlockers(): array
    {
        return array_map(fn ($case) => [$case], ['pending', 'pending_pause', 'pause', 'loss', 'saved_loss', 'transit', 'cargo', 'preview', 'busy', 'wind', 'null_expiry', 'expired', 'uncertain', 'review', 'pending_action', 'cash', 'stale', 'expiry']);
    }

    public static function replacementDeadlines(): array
    {
        return [['expiry'], ['wind-down']];
    }

    #[DataProvider('replacementDeadlines')]
    public function test_replacement_cannot_cross_its_time_boundary_during_approval(string $boundary): void
    {
        $this->arm();
        if ($boundary === 'wind-down') {
            $this->episode->update(['next_reset_at' => now()->addHours(2)]);
        }
        $cutoff = $boundary === 'wind-down' ? $this->episode->next_reset_at->subHour() : $this->grant->expires_at;
        $this->partialMock(CampaignTrade::class)->shouldReceive('learningResults')->once()->andReturnUsing(function () use ($cutoff) {
            $this->travelTo($cutoff);

            return [];
        });

        try {
            $this->replacement();
            $this->fail('Replacement passed its cutoff before superseding the original grant.');
        } catch (GameException $exception) {
            $this->assertStringContainsString('expired or reached wind-down', $exception->getMessage());
        }
        $this->assertSame(1, $this->grant->fresh()->slot);
        $this->assertNull($this->episode->fresh()->closed_at);
        $this->assertFalse(CampaignGrant::where('slot', 2)->exists());
    }

    public function test_replacement_checks_union_of_arm_pairs_and_refuses_removal_of_c0_loss_that_would_restore_scale(): void
    {
        $arm = $this->arm();
        $this->load($arm, 100);
        $this->travel(2)->seconds();
        $this->load($arm, 100, false);
        $this->travel(2)->seconds();
        $this->load($this->episode, -1);
        $this->travel(2)->seconds();
        $this->load($this->episode, 100, true, 'COPPER_ORE');
        $this->assertSame('proof', app(CampaignTrade::class)->mode($arm, 'IRON_ORE', 'X1-TEST-PORT'));
        $this->expectExceptionMessage('would increase arm H1 sizing');
        $this->replacement();
    }

    public function test_policy_validation_is_strict_and_legacy_import_hash_and_strategy_are_unchanged(): void
    {
        $source = file_get_contents(base_path('campaigns/weekly-default.yaml'));
        $revision = app(CampaignPlaybook::class)->import($source);
        $this->assertSame(hash('sha256', $source), $revision->content_hash);
        $graph = app(CampaignStrategy::class)->graph($revision->body);
        $this->assertStringContainsString('rerank', $graph['nodes']['trade.stale']['summary']);
        foreach ([['projection_limit', 25], ['projection_limit', '12'], ['max_attempts', 0], ['max_attempts', 13], ['position_evidence_max_age_seconds', 179], ['max_positionings_without_trade', 4], ['unknown', 1]] as [$key, $value]) {
            $body = $this->body();
            $body['trade']['local_earn'][$key] = $value;
            try {
                app(CampaignPlaybook::class)->validate($body);
                $this->fail('Invalid local policy accepted');
            } catch (GameException) {
                $this->assertTrue(true);
            }
        }
        $this->expectExceptionMessage('C0-specific');
        app(CampaignControl::class)->approveArm($this->grant->revision(), $this->user, ['label' => 'H2', 'ship' => 'LOCAL-3'], now()->toImmutable()->addHour());
    }

    public function test_strategy_only_projects_local_policy_in_earn_and_keeps_native_phase_and_learning_explanation(): void
    {
        $strategy = app(CampaignStrategy::class);
        $graph = $strategy->graph($this->body(), 'earn');
        $text = json_encode($graph);
        foreach (['bounded shortlist', '12 pairs', '3 buys', 'fuel-free', '30 minutes', 'Keep that order', 'H1', 'entire local pass'] as $phrase) {
            $this->assertStringContainsString($phrase, $text);
        }
        $this->assertArrayHasKey('trade.budget', $graph['nodes']);
        $this->assertArrayNotHasKey('trade.budget', $strategy->graph($this->body(), 'discover')['nodes']);
        $this->episode->update(['phase' => 'wind_down']);
        $this->assertArrayNotHasKey('trade.budget', $strategy->read()['graph']['nodes']);
    }
}
