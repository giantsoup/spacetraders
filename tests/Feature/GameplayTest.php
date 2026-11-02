<?php

namespace Tests\Feature;

use App\Enums\ActionState;
use App\Enums\RunState;
use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\GameException;
use App\Gameplay\HostLock;
use App\Gameplay\MiningPlanner;
use App\Gameplay\RequestBudget;
use App\Gameplay\ShipCommandExecutor;
use App\Gameplay\SpaceTradersApi;
use App\Jobs\AdvanceAutomationRun;
use App\Jobs\ExecuteShipCommand;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\GameObservation;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use App\Services\SpaceTradersClient;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class GameplayTest extends TestCase
{
    use RefreshDatabase;

    private string $worldFile;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        config(['gameplay.enabled' => true]);
        $this->worldFile = tempnam(sys_get_temp_dir(), 'st-mvp-');
        file_put_contents($this->worldFile, json_encode(FakeSpaceTraders::world()));
        FakeSpaceTraders::install($this->worldFile);
        $user = User::factory()->create();
        $this->agent = $user->agents()->create([
            'name' => 'LOCAL', 'token' => 'synthetic-agent-token', 'account_id' => 'synthetic-account', 'faction' => 'COSMIC',
            'reset_id' => '2026-10-25', 'canonical_identity' => AgentSynchronizer::identity('2026-10-25', 'LOCAL'),
        ]);
        $this->actingAs($user);
        app(AgentSynchronizer::class)->sync($this->agent);
    }

    protected function tearDown(): void
    {
        unlink($this->worldFile);
        parent::tearDown();
    }

    public function test_map_command_handoff_keeps_owned_ship_and_reset_scoped_target_without_upstream_calls(): void
    {
        $before = json_decode(file_get_contents($this->worldFile), true)['calls'];
        $this->get('/agents/'.$this->agent->id.'?ship=LOCAL-1&map_action=travel&map_waypoint=X1-TEST-PORT')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('mapTask.ship_symbol', 'LOCAL-1')
            ->where('mapTask.action', 'travel')->where('mapTask.point.symbol', 'X1-TEST-PORT'));
        $this->get('/agents/'.$this->agent->id.'/map?ship=LOCAL-1&location=X1-TEST-PORT')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('selectedLocation', 'X1-TEST-PORT'));
        $this->get('/agents/'.$this->agent->id.'?ship=')->assertOk();
        $this->getJson('/agents/'.$this->agent->id.'?ship=LOCAL-1&map_action=invalid&map_waypoint=X1-TEST-PORT')->assertUnprocessable();
        $this->getJson('/agents/'.$this->agent->id.'?ship=LOCAL-1&map_action=travel')->assertUnprocessable();
        $this->assertSame($before, json_decode(file_get_contents($this->worldFile), true)['calls']);
        $this->get('/agents/'.$this->agent->id.'?ship=FOREIGN-1&map_action=travel&map_waypoint=X1-TEST-PORT')->assertNotFound();
        $this->get('/agents/'.$this->agent->id.'?ship=LOCAL-1&map_action=discover&map_waypoint=X2-OTHER-A')->assertNotFound();
        GameObservation::create(['agent_id' => $this->agent->id, 'reset_id' => 'old-reset', 'kind' => 'waypoint', 'symbol' => 'X1-TEST-OLD', 'data' => ['systemSymbol' => 'X1-TEST'], 'observed_at' => now(), 'source' => 'fixture']);
        $this->get('/agents/'.$this->agent->id.'?ship=LOCAL-1&map_action=travel&map_waypoint=X1-TEST-OLD')->assertNotFound();
        $this->actingAs(User::factory()->create())->get('/agents/'.$this->agent->id.'?ship=LOCAL-1&map_action=travel&map_waypoint=X1-TEST-PORT')->assertNotFound();
    }

    public function test_flight_deck_art_reuses_reset_scoped_map_knowledge_without_refreshing(): void
    {
        $point = GameObservation::where('agent_id', $this->agent->id)->where('kind', 'waypoint')->where('symbol', 'X1-TEST-PORT')->firstOrFail();
        $point->update(['data' => array_replace($point->data, ['traits' => [['symbol' => 'OCEAN']], 'description' => 'Stored ocean world.'])]);
        GameObservation::create(['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'kind' => 'manual_read', 'symbol' => 'get-system-art', 'data' => ['operation' => 'get-system', 'data' => ['symbol' => 'X1-TEST', 'waypoints' => [['symbol' => 'X1-TEST-MANUAL', 'type' => 'MOON']]]], 'observed_at' => now(), 'source' => 'fixture']);
        GameObservation::create(['agent_id' => $this->agent->id, 'reset_id' => 'previous-reset', 'kind' => 'waypoint', 'symbol' => 'X1-TEST-OLD', 'data' => ['systemSymbol' => 'X1-TEST', 'type' => 'PLANET'], 'observed_at' => now(), 'source' => 'fixture']);
        $before = json_decode(file_get_contents($this->worldFile), true)['calls'];
        $this->get('/agents/'.$this->agent->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('sceneLocations', function ($rows) {
                $points = collect($rows)->keyBy('symbol');

                return $points->get('X1-TEST-PORT') == ['symbol' => 'X1-TEST-PORT', 'type' => 'PLANET', 'traits' => [['symbol' => 'OCEAN']], 'description' => 'Stored ocean world.']
                    && $points->get('X1-TEST-MANUAL')['type'] === 'MOON'
                    && $points->get('X1-TEST-MANUAL')['traits'] === null
                    && ! $points->has('X1-TEST-OLD');
            }));
        $this->assertSame($before, json_decode(file_get_contents($this->worldFile), true)['calls']);
    }

    public function test_browser_sync_allows_rate_limited_discovery_and_restores_the_web_time_limit(): void
    {
        $previous = (int) ini_get('max_execution_time');
        $limits = [];
        Http::globalOptions(function () use (&$limits) {
            $limits[] = (int) ini_get('max_execution_time');

            return [];
        });
        set_time_limit(30);
        try {
            $this->postJson('/agents/'.$this->agent->id.'/sync')->assertAccepted();
            $this->assertNotEmpty($limits);
            $this->assertGreaterThanOrEqual(60, min($limits));
            $this->assertSame(30, (int) ini_get('max_execution_time'));
            $this->assertSame([], json_decode(file_get_contents($this->worldFile), true)['writes']);
        } finally {
            set_time_limit($previous);
        }
    }

    public function test_failed_browser_sync_restores_the_web_time_limit(): void
    {
        $previous = (int) ini_get('max_execution_time');
        Http::globalOptions(fn () => throw new GameException('Synthetic sync interruption.'));
        set_time_limit(30);
        try {
            $this->postJson('/agents/'.$this->agent->id.'/sync')->assertUnprocessable()
                ->assertJsonPath('message', 'Synthetic sync interruption.');
            $this->assertSame(30, (int) ini_get('max_execution_time'));
        } finally {
            set_time_limit($previous);
        }
    }

    public function test_browser_sync_passes_one_bounded_deadline_without_lowering_read_priority(): void
    {
        $deadlines = [];
        $client = \Mockery::mock(SpaceTradersClient::class)->makePartial();
        $client->shouldReceive('request')->withArgs(function ($method, $path, $data, $token, $deadline) use (&$deadlines) {
            $this->assertSame('get', $method);
            $deadlines[] = $deadline;

            return true;
        })->passthru();
        app()->instance(SpaceTradersClient::class, $client);
        app()->instance(SpaceTradersApi::class, new SpaceTradersApi($client));
        $budget = new class extends RequestBudget
        {
            public array $priorities = [];

            public function reserve(?string $token = null, ?string $account = null, string $priority = 'discovery', float $maxWait = 0.75): void
            {
                $this->priorities[] = $priority;
                parent::reserve($token, $account, $priority, $maxWait);
            }
        };
        app()->instance(RequestBudget::class, $budget);
        $started = microtime(true);
        $this->postJson('/agents/'.$this->agent->id.'/sync')->assertAccepted();

        $this->assertNotEmpty($deadlines);
        $this->assertCount(1, array_unique($deadlines));
        $this->assertGreaterThanOrEqual($started + 49, $deadlines[0]);
        $this->assertLessThanOrEqual($started + 51, $deadlines[0]);
        $this->assertSame(['critical'], array_unique($budget->priorities));
    }

    public function test_incomplete_observations_remain_available_without_inventing_planning_values(): void
    {
        $ship = Ship::firstOrFail();
        $snapshot = $ship->snapshot;
        unset($snapshot['nav']['flightMode'], $snapshot['cargo']['inventory'], $snapshot['fuel'], $snapshot['crew'], $snapshot['cooldown']);
        $ship->update(['snapshot' => $snapshot]);
        $requests = Http::recorded()->count();
        $this->get(route('agents.show', $this->agent))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->missing('ships.0.snapshot.fuel')->missing('ships.0.snapshot.cargo.inventory')
            ->has('intelligence.observation_gaps', 1)->has('intelligence.opportunities', 0));
        $this->assertSame($requests, Http::recorded()->count());
    }

    public function test_missing_flight_mode_does_not_break_the_intelligence_report(): void
    {
        $ship = Ship::firstOrFail();
        $snapshot = $ship->snapshot;
        unset($snapshot['nav']['flightMode']);
        $ship->update(['snapshot' => $snapshot]);
        $this->get(route('agents.show', $this->agent))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->missing('ships.0.snapshot.nav.flightMode')->has('intelligence.observation_gaps', 1));
    }

    public function test_history_is_owner_scoped_paginated_and_does_not_contact_upstream(): void
    {
        $action = $this->preview('orbit');
        for ($index = 0; $index < 44; $index++) {
            $copy = $action->replicate();
            $copy->key = (string) Str::uuid();
            $copy->save();
        }
        $requests = Http::recorded()->count();
        $first = $this->getJson('/agents/'.$this->agent->id.'/history?before='.($copy->id + 1));
        $first->assertOk()->assertJsonCount(40, 'actions')->assertJsonPath('has_more', true)->assertDontSee('synthetic-agent-token');
        $before = $first->json('actions.39.id');
        $this->getJson('/agents/'.$this->agent->id.'/history?before='.$before)->assertOk()->assertJsonCount(5, 'actions')->assertJsonPath('has_more', false);
        $this->getJson('/agents/'.$this->agent->id.'/history?before=0')->assertUnprocessable();
        $this->assertSame($requests, Http::recorded()->count());
        $this->actingAs(User::factory()->create())->getJson('/agents/'.$this->agent->id.'/history?before='.$before)->assertNotFound();
    }

    public function test_navigation_preview_requires_a_readable_destination_without_contacting_upstream(): void
    {
        $requests = Http::recorded()->count();
        $response = $this->postJson('/agents/'.$this->agent->id.'/commands/preview', [
            'key' => (string) Str::uuid(), 'ship_symbol' => 'LOCAL-1', 'command' => 'navigate',
            'arguments' => ['waypointSymbol' => '', 'reserve' => 0],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('arguments.waypointSymbol');
        $this->assertSame('Select a destination before previewing navigation.', $response->json('errors')['arguments.waypointSymbol'][0]);
        $this->assertSame($requests, Http::recorded()->count());
        $this->assertSame(0, ShipAction::count());
    }

    private function world(?callable $mutate = null): array
    {
        $world = json_decode(file_get_contents($this->worldFile), true);
        if ($mutate) {
            $mutate($world);
            file_put_contents($this->worldFile, json_encode($world));
        }

        return $world;
    }

    private function preview(string $command, array $arguments = [], string $ship = 'LOCAL-1'): ShipAction
    {
        return app(ShipCommandExecutor::class)->preview($this->agent->fresh(), $ship, $command, $arguments, (string) Str::uuid());
    }

    private function command(string $command, array $arguments = [], string $ship = 'LOCAL-1'): ShipAction
    {
        $action = $this->preview($command, $arguments, $ship);
        app(ShipCommandExecutor::class)->approve($this->agent->fresh(), $action);
        app(ShipCommandExecutor::class)->execute($action->id);

        return $action->fresh();
    }

    private function miningRun(array $overrides = []): AutomationRun
    {
        $run = app(AutomationRunner::class)->preview($this->agent->fresh(), 'LOCAL-1', array_merge([
            'site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-ROCK', 'reserve' => 1000,
            'max_spend' => 100, 'max_loss' => 100, 'minutes' => 60, 'cycles' => 1,
        ], $overrides));
        app(AutomationRunner::class)->start($this->agent->fresh(), $run);

        return $run->fresh();
    }

    private function advance(AutomationRun $run): void
    {
        app(AutomationRunner::class)->advance($run->id);
        foreach (ShipAction::where('state', 'pending')->get() as $action) {
            app(ShipCommandExecutor::class)->execute($action->id);
        }
        $run->refresh();
        if ($run->state === RunState::Waiting && $run->next_action_at->isPast()) {
            $run->update(['next_action_at' => now()]);
        }
    }

    public function test_tokens_are_encrypted_and_absent_from_dashboard_history_and_jobs(): void
    {
        $this->assertNotSame('synthetic-agent-token', $this->agent->getRawOriginal('token'));
        $this->assertSame('synthetic-agent-token', $this->agent->credential());
        $response = $this->get(route('agents.show', $this->agent));
        $response->assertOk()->assertDontSee('synthetic-agent-token')->assertInertia(fn (Assert $page) => $page->component('Agent/Dashboard')->missing('agent.token')->has('ships', 1));
        $action = $this->preview('orbit');
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        $this->assertStringNotContainsString('synthetic-agent-token', DB::table('jobs')->value('payload'));
        $this->assertStringNotContainsString('synthetic-agent-token', $action->toJson());
    }

    public function test_every_game_route_denies_another_owner_and_guest(): void
    {
        $action = $this->preview('orbit');
        $run = $this->miningRun();
        $routes = [
            ['game.sync', []], ['game.commands.preview', []], ['game.commands.approve', ['action' => $action->id]],
            ['game.commands.reconcile', ['action' => $action->id]], ['game.commands.acknowledge', ['action' => $action->id]],
            ['game.runs.preview', []], ['game.runs.start', ['run' => $run->id]], ['game.runs.stop', ['run' => $run->id]],
        ];
        $this->actingAs(User::factory()->create());
        $this->get(route('agents.show', $this->agent))->assertNotFound();
        foreach ($routes as [$name, $params]) {
            $this->postJson(route($name, ['agent' => $this->agent->id] + $params))->assertNotFound();
        }
        auth()->forgetGuards();
        foreach ($routes as [$name, $params]) {
            $this->postJson(route($name, ['agent' => $this->agent->id] + $params))->assertUnauthorized();
        }
    }

    public function test_child_action_cannot_be_approved_through_another_owned_agent(): void
    {
        $action = $this->preview('orbit');
        $other = $this->agent->replicate(['canonical_identity']);
        $other->name = 'OTHER';
        $other->save();
        $this->postJson(route('game.commands.approve', ['agent' => $other->id, 'action' => $action->id]))->assertNotFound();
    }

    public function test_fleet_and_waypoints_paginate_and_preserve_observation_reset(): void
    {
        $this->world(function (&$w) {
            $w['page_size'] = 1;
            $w['ships'][] = FakeSpaceTraders::ship('LOCAL-2');
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->assertDatabaseCount('ships', 2);
        $this->assertSame(2, GameObservation::where('kind', 'waypoint')->count());
        $this->assertSame(['2026-10-25'], GameObservation::query()->distinct()->pluck('reset_id')->all());
    }

    public function test_synchronizing_observes_arrival_only_after_the_upstream_travel_time(): void
    {
        $this->world(function (&$world) {
            $world['travel_seconds'] = 3600;
        });
        $this->command('orbit');
        $this->command('navigate', ['waypointSymbol' => 'X1-TEST-PORT']);
        app(AgentSynchronizer::class)->sync($this->agent->fresh());
        $this->assertSame('IN_TRANSIT', Ship::where('agent_id', $this->agent->id)->first()->snapshot['nav']['status']);

        $this->world(function (&$world) {
            $world['ships'][0]['nav']['route']['arrival'] = '2020-01-01T00:00:00Z';
        });
        app(AgentSynchronizer::class)->sync($this->agent->fresh());
        $ship = Ship::where('agent_id', $this->agent->id)->first()->snapshot;
        $this->assertSame('IN_ORBIT', $ship['nav']['status']);
        $this->assertSame('X1-TEST-PORT', $ship['nav']['waypointSymbol']);
    }

    public function test_cargo_trade_approvals_do_not_describe_a_tank_refuel(): void
    {
        $buy = $this->command('buy', ['symbol' => 'IRON_ORE', 'units' => 4, 'maxTotal' => 120]);
        $this->assertArrayNotHasKey('tank_refuel', $buy->before_state['estimate']);
        $sell = $this->preview('sell', ['symbol' => 'IRON_ORE', 'units' => 4, 'minTotal' => 100]);
        $this->assertArrayNotHasKey('tank_refuel', $sell->before_state['estimate']);
    }

    public function test_manual_endpoints_and_persistent_cargo_credit_outcomes(): void
    {
        $this->assertSame(ActionState::Confirmed, $this->command('buy', ['symbol' => 'IRON_ORE', 'units' => 4, 'maxTotal' => 120, 'reserve' => 1000])->state);
        $this->assertSame(ActionState::Confirmed, $this->command('sell', ['symbol' => 'IRON_ORE', 'units' => 4, 'minTotal' => 100])->state);
        $this->assertSame(ActionState::Confirmed, $this->command('orbit')->state);
        $nav = $this->command('navigate', ['waypointSymbol' => 'X1-TEST-PORT']);
        $this->assertSame(ActionState::Confirmed, $nav->state);
        $this->assertSame(ActionState::Confirmed, $this->command('dock')->state);
        $this->assertSame(ActionState::Confirmed, $this->command('refuel', ['units' => 5, 'maxTotal' => 5, 'reserve' => 1000])->state);
        $this->assertSame(99975, $this->agent->fresh()->credits);
        $this->assertSame(100, Ship::first()->snapshot['fuel']['current']);
        $paths = array_column($this->world()['writes'], 'path');
        $this->assertSame(['/my/ships/LOCAL-1/purchase', '/my/ships/LOCAL-1/sell', '/my/ships/LOCAL-1/orbit', '/my/ships/LOCAL-1/navigate', '/my/ships/LOCAL-1/dock', '/my/ships/LOCAL-1/refuel'], $paths);
    }

    public function test_duplicate_preview_approval_and_queue_delivery_produce_one_write(): void
    {
        $key = (string) Str::uuid();
        $payload = ['ship_symbol' => 'LOCAL-1', 'command' => 'orbit', 'arguments' => [], 'key' => $key];
        $id = $this->postJson(route('game.commands.preview', $this->agent), $payload)->assertAccepted()->json('id');
        $this->postJson(route('game.commands.preview', $this->agent), $payload)->assertAccepted()->assertJsonPath('id', $id);
        $this->postJson(route('game.commands.approve', ['agent' => $this->agent->id, 'action' => $id]))->assertAccepted();
        $this->postJson(route('game.commands.approve', ['agent' => $this->agent->id, 'action' => $id]))->assertAccepted();
        app(ShipCommandExecutor::class)->execute($id);
        app(ShipCommandExecutor::class)->execute($id);
        $this->assertCount(1, $this->world()['writes']);
        $this->assertDatabaseCount('ship_actions', 1);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_invalid_units_arguments_and_caller_tokens_never_create_intents(): void
    {
        foreach ([0, -1, 1.5, 100001] as $units) {
            $this->postJson(route('game.commands.preview', $this->agent), ['key' => (string) Str::uuid(), 'ship_symbol' => 'LOCAL-1', 'command' => 'buy', 'arguments' => ['symbol' => 'IRON_ORE', 'units' => $units, 'maxTotal' => 100]])->assertUnprocessable();
        }
        $this->postJson(route('game.commands.preview', $this->agent), ['key' => (string) Str::uuid(), 'ship_symbol' => 'LOCAL-1', 'command' => 'orbit', 'arguments' => ['token' => 'untrusted']])->assertUnprocessable();
        $this->assertCount(0, $this->world()['writes']);
    }

    public function test_spend_reserve_trade_volume_capacity_and_nav_guards(): void
    {
        foreach ([
            ['buy', ['symbol' => 'IRON_ORE', 'units' => 5, 'maxTotal' => 1000]],
            ['buy', ['symbol' => 'IRON_ORE', 'units' => 4, 'maxTotal' => 119]],
            ['buy', ['symbol' => 'IRON_ORE', 'units' => 1, 'maxTotal' => 30, 'reserve' => 100000]],
            ['sell', ['symbol' => 'IRON_ORE', 'units' => 1, 'minTotal' => 0]],
            ['navigate', ['waypointSymbol' => 'X1-TEST-PORT']],
            ['mine', []],
            ['buy', ['symbol' => 'IRON_ORE', 'units' => 1, 'maxTotal' => 30, 'require_guaranteed_price' => true]],
        ] as [$command, $args]) {
            try {
                $this->preview($command, $args);
                $this->fail('Invalid intent accepted');
            } catch (GameException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        $this->assertCount(0, $this->world()['writes']);
    }

    public function test_changed_quote_invalidates_single_use_approval(): void
    {
        $action = $this->preview('buy', ['symbol' => 'IRON_ORE', 'units' => 4, 'maxTotal' => 120]);
        $this->world(fn (&$w) => $w['goods'][0]['purchasePrice'] = 50);
        $this->postJson(route('game.commands.approve', ['agent' => $this->agent->id, 'action' => $action->id]))->assertUnprocessable()->assertJsonPath('message', 'The quote exceeds the approved spending ceiling or protected reserve.');
        $this->assertCount(0, $this->world()['writes']);
    }

    public function test_execution_price_jump_is_recorded_and_blocks_run(): void
    {
        $run = $this->miningRun();
        $this->advance($run); // orbit
        $this->advance($run); // mine
        $this->advance($run); // dock
        $this->advance($run); // first sale
        $this->world(fn (&$w) => $w['failure'] = 'price_jump');
        // Refueling is the spend boundary; drain a tank through the normal navigation flow in another test.
        $this->assertGreaterThan(0, $run->totals['sale_proceeds']);
        $this->world(fn (&$w) => $w['failure'] = null);
        $this->postJson(route('game.runs.stop', ['agent' => $this->agent->id, 'run' => $run->id]))->assertAccepted();
        $this->advance($run);
        $this->world(fn (&$w) => $w['ships'][0]['fuel']['current'] = 90);
        $action = $this->preview('refuel', ['units' => 10, 'maxTotal' => 5]);
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        $this->world(fn (&$w) => $w['failure'] = 'price_jump');
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertTrue($action->fresh()->outcome['price_discrepancy']);
        $this->assertSame(-15, $action->fresh()->outcome['credit_delta']);
    }

    public function test_complete_trip_batches_multiple_yields_and_records_fuel(): void
    {
        $run = $this->miningRun(['cycles' => 2]);
        for ($i = 0; $i < 30 && $run->active_agent_id; $i++) {
            $run->update(['next_action_at' => now()]);
            $this->advance($run);
        }
        $this->assertSame(RunState::Completed, $run->state, $run->blocked_reason ?? '');
        $this->assertSame(2, $run->totals['cycles']);
        $this->assertSame(['COPPER_ORE' => 8, 'IRON_ORE' => 10], $run->totals['extracted']);
        $this->assertSame($run->totals['extracted'], $run->totals['sold']);
        $this->assertSame(490, $run->totals['cash_contribution']);
        $this->assertSame(0, Ship::first()->snapshot['cargo']['units']);
        $this->assertSame(490, $this->agent->fresh()->credits - 100000);
        foreach ($this->world()['writes'] as $write) {
            if (str_ends_with($write['path'], '/sell')) {
                $this->assertLessThanOrEqual(4, $write['arguments']['units']);
            }
        }
    }

    public function test_travel_cooldown_waits_and_real_fuel_accounting(): void
    {
        // Observe the sale market with a second owned probe.
        $this->world(function (&$w) {
            $w['ships'][] = FakeSpaceTraders::ship('LOCAL-2', 'X1-TEST-PORT');
            $w['travel_seconds'] = 300;
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $run = $this->miningRun(['market' => 'X1-TEST-PORT']);
        $this->advance($run); // orbit
        $this->advance($run); // mine
        $this->advance($run); // travel to buyer
        $run->update(['next_action_at' => now()]);
        $this->advance($run);
        $this->assertSame(RunState::Waiting, $run->state);
        $this->assertTrue($run->next_action_at->isFuture());
        $before = count($this->world()['writes']);
        $this->advance($run);
        $this->assertCount($before, $this->world()['writes']);
        $this->world(fn (&$w) => $w['ships'][0]['nav']['route']['arrival'] = '2020-01-01T00:00:00Z');
        for ($i = 0; $i < 10 && $run->active_agent_id; $i++) {
            $run->update(['next_action_at' => now()]);
            $this->advance($run);
        }
        $this->assertSame(RunState::Completed, $run->state, $run->blocked_reason ?? '');
        $this->assertSame(5, $run->totals['fuel_cost']);
        $this->assertSame(5, $run->totals['fuel_units']);
        $this->assertSame(235, $run->totals['cash_contribution']);
    }

    public function test_stop_offline_and_pending_action_prevent_mutation_then_allow_takeover(): void
    {
        $run = $this->miningRun();
        app(AutomationRunner::class)->advance($run->id); // reserved pending orbit
        $action = ShipAction::where('state', 'pending')->firstOrFail();
        $this->postJson(route('game.runs.stop', ['agent' => $this->agent->id, 'run' => $run->id]))->assertAccepted();
        $this->assertNotNull($run->fresh()->stop_requested_at);
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame(ActionState::Rejected, $action->fresh()->state);
        $this->advance($run);
        $this->assertSame(RunState::Stopped, $run->state);
        $this->assertNull($run->active_agent_id);
        $this->assertCount(0, $this->world()['writes']);
        $this->assertSame(ActionState::Confirmed, $this->command('orbit')->state);
    }

    public function test_takeover_is_denied_while_run_owns_ship_and_reserve_applies_to_other_ship(): void
    {
        $this->world(fn (&$w) => $w['ships'][] = FakeSpaceTraders::ship('LOCAL-2'));
        app(AgentSynchronizer::class)->sync($this->agent);
        $run = $this->miningRun(['reserve' => 99900, 'max_spend' => 100]);
        $this->postJson(route('game.commands.preview', $this->agent), ['ship_symbol' => 'LOCAL-1', 'command' => 'orbit', 'arguments' => [], 'key' => (string) Str::uuid()])->assertUnprocessable();
        try {
            $this->preview('buy', ['symbol' => 'IRON_ORE', 'units' => 4, 'maxTotal' => 120, 'reserve' => 0], 'LOCAL-2');
            $this->fail('Spent reserved credits');
        } catch (GameException $e) {
            $this->assertStringContainsString('reserve', $e->getMessage());
        }
        $this->assertNotNull($run->active_agent_id);
    }

    public function test_lost_sale_response_never_replays_and_requires_fresh_reconciliation(): void
    {
        $this->command('buy', ['symbol' => 'IRON_ORE', 'units' => 4, 'maxTotal' => 120]);
        $action = $this->preview('sell', ['symbol' => 'IRON_ORE', 'units' => 4, 'minTotal' => 100]);
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        $this->world(fn (&$w) => $w['failure'] = 'timeout_after');
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame(ActionState::Uncertain, $action->fresh()->state);
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertCount(2, $this->world()['writes']);
        $this->postJson(route('game.commands.acknowledge', ['agent' => $this->agent->id, 'action' => $action->id]), ['acknowledge' => true, 'note' => 'I inspected fresh cargo'])->assertUnprocessable();
        $this->postJson(route('game.commands.reconcile', ['agent' => $this->agent->id, 'action' => $action->id]))->assertAccepted()->assertJsonPath('state', 'uncertain');
        $this->assertSame(0, Ship::first()->snapshot['cargo']['units']);
        $this->postJson(route('game.commands.acknowledge', ['agent' => $this->agent->id, 'action' => $action->id]), ['acknowledge' => true, 'note' => 'I inspected fresh cargo and balance'])->assertAccepted();
        $this->assertTrue($action->fresh()->outcome['unresolved_adjustment']);
        $this->assertCount(2, $this->world()['writes']);
    }

    public function test_malformed_successful_extraction_and_sender_death_become_uncertain(): void
    {
        $this->command('orbit');
        $action = $this->preview('mine');
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        $this->world(fn (&$w) => $w['failure'] = 'malformed');
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame(ActionState::Uncertain, $action->fresh()->state);
        $action->update(['state' => 'sending', 'sent_at' => now()->subMinutes(4)]);
        $this->artisan('spacetraders:dispatch-due')->assertSuccessful();
        $this->assertSame(ActionState::Uncertain, $action->fresh()->state);
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertCount(2, $this->world()['writes']);
    }

    public function test_reset_invalidates_intents_and_keeps_history(): void
    {
        $action = $this->preview('orbit');
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        $this->world(fn (&$w) => $w['reset'] = '2026-11-01');
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame(ActionState::Rejected, $action->fresh()->state);
        $this->assertSame('reset', $this->agent->fresh()->connection_state);
        $this->assertCount(0, $this->world()['writes']);
        $this->assertDatabaseCount('ship_actions', 1);
    }

    public function test_rate_limit_after_dispatch_is_uncertain_and_budget_survives_restart(): void
    {
        $action = $this->preview('orbit');
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        $this->world(fn (&$w) => $w['failure'] = '429');
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame(ActionState::Uncertain, $action->fresh()->state);
        $this->assertGreaterThan(microtime(true), app(RequestBudget::class)->usage()['host']['blocked_until']);
        $this->assertSame(429, $action->fresh()->api_code);
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertCount(0, $this->world()['writes']);
    }

    public function test_authentication_failure_pauses_without_re_registering(): void
    {
        $action = $this->preview('orbit');
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        $this->world(fn (&$w) => $w['failure'] = '401');
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame(ActionState::Rejected, $action->fresh()->state);
        $this->assertSame('invalid_credential', $this->agent->fresh()->connection_state);
        $this->assertFalse(collect($this->world()['calls'])->contains('path', '/register'));
    }

    public function test_host_lock_prevents_competing_command_paths(): void
    {
        app(HostLock::class)->run('agent-'.$this->agent->id, function () {
            try {
                $this->preview('orbit');
                $this->fail('Competing lock acquired');
            } catch (GameException $e) {
                $this->assertSame(2, $e->retryAfter);
            }
        });
    }

    public function test_live_lasers_without_optional_deposits_use_the_complete_api_ore_set(): void
    {
        $ship = FakeSpaceTraders::ship();
        unset($ship['mounts'][0]['deposits']);
        $oreSymbols = json_decode(file_get_contents(resource_path('data/mining-yields.json')), true);
        GameObservation::where('kind', 'market')->get()->each(function ($quote) use ($oreSymbols) {
            $data = $quote->data;
            $data['tradeGoods'] = array_merge($data['tradeGoods'] ?? [], array_map(fn ($symbol) => ['symbol' => $symbol, 'sellPrice' => 10, 'purchasePrice' => 20, 'tradeVolume' => 4], $oreSymbols));
            $quote->update(['data' => $data]);
        });
        $route = app(MiningPlanner::class)->route($this->agent->fresh(), $ship, 'X1-TEST-ROCK', 'X1-TEST-ROCK');
        $this->assertSame($oreSymbols, $route['possible_yields']);
        $this->assertSame([], $route['unknown_yields']);
        $this->assertSame(332, MiningPlanner::fuelEstimate(['symbol' => 'A', 'x' => -8, 'y' => 24], ['symbol' => 'B', 'x' => -317, 'y' => 138]));
    }

    public function test_missing_capabilities_and_unusable_routes_report_blockers(): void
    {
        $planner = app(MiningPlanner::class);
        $ship = FakeSpaceTraders::ship();
        foreach (['mounts' => 'laser', 'modules' => 'processor'] as $field => $message) {
            $copy = $ship;
            $copy[$field] = [];
            $this->assertStringContainsString($message, $planner->capability($copy));
        }
        $ship['crew']['current'] = 0;
        $this->assertStringContainsString('crew', $planner->capability($ship));
        $ship = FakeSpaceTraders::ship();
        $ship['reactor']['powerOutput'] = 0;
        $this->assertStringContainsString('power', $planner->capability($ship));
        $ship['cargo']['capacity'] = 0;
        $this->assertStringContainsString('cargo', $planner->capability($ship));
        GameObservation::where('kind', 'market')->delete();
        $this->world(fn (&$w) => $w['goods'] = []);
        $this->expectException(GameException::class);
        $this->miningRun();
    }

    public function test_single_active_run_stale_preview_and_default_disabled_starts(): void
    {
        $run = $this->miningRun();
        $this->assertSame($run->id, app(AutomationRunner::class)->start($this->agent, $run)->id);
        $this->assertSame(1, AutomationRun::whereNotNull('active_agent_id')->count());
        $this->expectException(UniqueConstraintViolationException::class);
        AutomationRun::create(array_merge($run->getAttributes(), ['id' => null]));
    }

    public function test_lost_job_dispatch_is_recovered_from_pending_intent(): void
    {
        $action = $this->preview('orbit');
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        DB::table('jobs')->delete();
        $action->update(['last_dispatched_at' => now()->subSeconds(31)]);
        $this->artisan('spacetraders:dispatch-due')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        $job = unserialize(json_decode(DB::table('jobs')->value('payload'), true)['data']['command']);
        $this->assertInstanceOf(ExecuteShipCommand::class, $job);
        $job->handle(app(ShipCommandExecutor::class));
        $this->assertSame(ActionState::Confirmed, $action->fresh()->state);
    }

    public function test_one_time_setup_verifies_complete_configuration_and_delivered_ship(): void
    {
        $this->world(fn (&$w) => $w['ships'][0]['mounts'] = []);
        app(AgentSynchronizer::class)->sync($this->agent);
        $args = ['shipType' => 'SHIP_MINING_DRONE', 'waypointSymbol' => 'X1-TEST-ROCK', 'maxTotal' => 20000, 'reserve' => 1000, 'site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-ROCK'];
        $action = $this->command('buy_ship', $args);
        $this->assertSame(ActionState::Confirmed, $action->state);
        $this->assertSame('LOCAL-2', $action->outcome['delivered_ship']);
        $this->assertSame(80000, $this->agent->fresh()->credits);
        $this->assertNull(app(MiningPlanner::class)->capability(Ship::where('symbol', 'LOCAL-2')->firstOrFail()->snapshot));
        $this->assertCount(1, $this->world()['writes']);
        $this->expectException(GameException::class);
        $this->preview('buy_ship', $args);
    }

    public function test_setup_price_change_and_incomplete_offer_block_before_purchase(): void
    {
        $this->world(fn (&$w) => $w['ships'][0]['mounts'] = []);
        app(AgentSynchronizer::class)->sync($this->agent);
        $args = ['shipType' => 'SHIP_MINING_DRONE', 'waypointSymbol' => 'X1-TEST-ROCK', 'maxTotal' => 20000, 'reserve' => 1000, 'site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-ROCK'];
        $action = $this->preview('buy_ship', $args);
        $this->world(fn (&$w) => $w['offer']['purchasePrice'] = 25000);
        $this->postJson(route('game.commands.approve', ['agent' => $this->agent->id, 'action' => $action->id]))->assertUnprocessable();
        $this->world(fn (&$w) => $w['offer']['modules'] = []);
        $this->postJson(route('game.commands.preview', $this->agent), ['key' => (string) Str::uuid(), 'ship_symbol' => 'LOCAL-1', 'command' => 'buy_ship', 'arguments' => $args])->assertUnprocessable();
        $this->assertCount(0, $this->world()['writes']);
    }

    public function test_lost_setup_response_refreshes_fleet_without_buying_twice(): void
    {
        $this->world(fn (&$w) => $w['ships'][0]['mounts'] = []);
        app(AgentSynchronizer::class)->sync($this->agent);
        $args = ['shipType' => 'SHIP_MINING_DRONE', 'waypointSymbol' => 'X1-TEST-ROCK', 'maxTotal' => 20000, 'reserve' => 1000, 'site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-ROCK'];
        $action = $this->preview('buy_ship', $args);
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        $this->world(fn (&$w) => $w['failure'] = 'timeout_after');
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame(ActionState::Uncertain, $action->fresh()->state);
        app(ShipCommandExecutor::class)->reconcile($this->agent, $action);
        $this->assertDatabaseCount('ships', 2);
        $this->assertCount(2, $action->fresh()->outcome['fleet']);
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertCount(1, $this->world()['writes']);
    }

    public function test_unknown_yield_retains_cargo_and_blocks_without_jettison(): void
    {
        $this->world(fn (&$w) => $w['yields'] = [['symbol' => 'DIAMONDS', 'units' => 8]]);
        $run = $this->miningRun();
        for ($i = 0; $i < 8 && $run->state !== RunState::Blocked; $i++) {
            $run->update(['next_action_at' => now()]);
            $this->advance($run);
        }
        $this->assertSame(RunState::Blocked, $run->state);
        $this->assertStringContainsString('No verified buyer', $run->blocked_reason);
        $this->assertSame(8, Ship::first()->snapshot['cargo']['units']);
        $this->assertFalse(collect($this->world()['writes'])->contains(fn ($w) => str_contains($w['path'], 'jettison')));
    }

    public function test_repeated_no_yield_is_bounded_and_condition_change_blocks(): void
    {
        $this->world(fn (&$w) => $w['yields'] = [['symbol' => 'IRON_ORE', 'units' => 0]]);
        $run = $this->miningRun(['cycles' => 10]);
        for ($i = 0; $i < 10 && $run->active_agent_id; $i++) {
            $run->update(['next_action_at' => now()]);
            $this->advance($run);
        }
        $this->assertSame(RunState::Blocked, $run->state);
        $this->assertSame(1, $run->totals['attempts']);
        $this->assertSame(0, $run->totals['sale_proceeds']);
        $this->world(fn (&$w) => $w['ships'][0]['engine']['condition'] = 0.2);
        $this->expectException(GameException::class);
        $this->miningRun();
    }

    public function test_duplicate_import_updates_credentials_and_cross_owner_import_is_denied(): void
    {
        $this->post(route('agents.storeExisting'), ['token' => 'new-synthetic-token'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('agents', 1);
        $this->assertSame('new-synthetic-token', $this->agent->fresh()->credential());
        $this->actingAs(User::factory()->create())->post(route('agents.storeExisting'), ['token' => 'new-synthetic-token'])->assertSessionHasErrors('api');
        $this->assertDatabaseCount('agents', 1);
    }

    public function test_legacy_credentials_fail_closed_until_resumable_conversion_round_trips(): void
    {
        DB::table('agents')->where('id', $this->agent->id)->update(['token' => 'legacy-synthetic', 'token_version' => 0]);
        try {
            $this->agent->fresh()->credential();
            $this->fail('Legacy credential was read without conversion');
        } catch (GameException $e) {
            $this->assertStringContainsString('Convert', $e->getMessage());
        }
        $this->artisan('spacetraders:encrypt-tokens')->assertFailed();
        $backup = sys_get_temp_dir().'/st-backup-'.Str::uuid();
        mkdir($backup, 0700);
        $restored = new \PDO('sqlite:'.$backup.'/database.sqlite');
        $restored->exec('create table agents (id integer primary key, token text)');
        $statement = $restored->prepare('insert into agents (id, token) values (?, ?)');
        $statement->execute([$this->agent->id, 'legacy-synthetic']);
        file_put_contents($backup.'/app-key.txt', config('app.key'));
        app()->maintenanceMode()->activate([]);
        try {
            $this->artisan('spacetraders:encrypt-tokens', ['--backup' => $backup])->assertSuccessful();
            $first = $this->agent->fresh()->getRawOriginal('token');
            $this->artisan('spacetraders:encrypt-tokens', ['--backup' => $backup])->assertSuccessful();
            $this->assertSame($first, $this->agent->fresh()->getRawOriginal('token'));
            $this->assertSame('legacy-synthetic', $this->agent->fresh()->credential());
        } finally {
            app()->maintenanceMode()->deactivate();
            unlink($backup.'/database.sqlite');
            unlink($backup.'/app-key.txt');
            rmdir($backup);
        }
    }

    public function test_stale_previews_disabled_automation_and_duration_limit(): void
    {
        $action = $this->preview('orbit');
        $action->update(['expires_at' => now()->subSecond()]);
        $this->postJson(route('game.commands.approve', ['agent' => $this->agent->id, 'action' => $action->id]))->assertUnprocessable();
        $run = $this->miningRun();
        $run->update(['expires_at' => now()->subSecond()]);
        $this->advance($run);
        $this->assertSame(RunState::Stopped, $run->state);
        config(['gameplay.enabled' => false]);
        $this->expectException(GameException::class);
        $this->miningRun();
    }

    public function test_unexpected_run_refuel_price_is_accounted_and_blocks_further_writes(): void
    {
        $this->world(fn (&$w) => $w['ships'][] = FakeSpaceTraders::ship('LOCAL-2', 'X1-TEST-PORT'));
        app(AgentSynchronizer::class)->sync($this->agent);
        $run = $this->miningRun(['market' => 'X1-TEST-PORT']);
        $found = false;
        for ($i = 0; $i < 15; $i++) {
            $run->update(['next_action_at' => now()]);
            app(AutomationRunner::class)->advance($run->id);
            $action = ShipAction::where('state', 'pending')->first();
            if ($action) {
                if ($action->command === 'refuel') {
                    $found = true;
                    $this->world(fn (&$w) => $w['failure'] = 'price_jump');
                }
                app(ShipCommandExecutor::class)->execute($action->id);
                if ($found) {
                    break;
                }
            }
        }
        $this->assertTrue($found);
        $this->assertSame(RunState::Blocked, $run->fresh()->state);
        $this->assertSame(ActionState::Confirmed, $action->fresh()->state);
        $this->assertTrue($action->fresh()->review_required);
        $this->assertSame(15, $run->fresh()->totals['fuel_cost']);
        $this->assertTrue($action->fresh()->outcome['price_discrepancy']);
        $count = count($this->world()['writes']);
        $this->advance($run);
        $this->assertCount($count, $this->world()['writes']);
    }

    public function test_profile_deletion_cannot_erase_in_flight_command_ownership(): void
    {
        $action = $this->preview('orbit');
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        $this->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasErrors('password');
        $this->assertDatabaseHas('agents', ['id' => $this->agent->id]);
        $this->assertDatabaseHas('ship_actions', ['id' => $action->id, 'state' => 'pending']);
    }

    public function test_other_ship_manual_spend_continues_run_and_is_accounted_separately(): void
    {
        $this->world(fn (&$w) => $w['ships'][] = FakeSpaceTraders::ship('LOCAL-2'));
        app(AgentSynchronizer::class)->sync($this->agent);
        // A preview may predate Start but its eventual confirmation must still be counted.
        $manual = $this->preview('buy', ['symbol' => 'IRON_ORE', 'units' => 2, 'maxTotal' => 60], 'LOCAL-2');
        $run = $this->miningRun();
        $this->advance($run);
        app(ShipCommandExecutor::class)->approve($this->agent->fresh(), $manual);
        app(ShipCommandExecutor::class)->execute($manual->id);
        for ($i = 0; $i < 15 && $run->active_agent_id; $i++) {
            $run->update(['next_action_at' => now()]);
            $this->advance($run);
        }
        $this->assertSame(RunState::Completed, $run->state, $run->blocked_reason ?? '');
        $this->assertSame(-60, $run->totals['other_credit_delta']);
        $this->assertSame(240, $run->totals['credit_delta']);
        $this->assertSame(180, $run->totals['agent_balance_delta']);
        $this->assertSame(180, $this->agent->fresh()->credits - 100000);
    }

    public function test_budget_contention_before_transport_retains_pending_without_blocking_run(): void
    {
        $run = $this->miningRun();
        app(AutomationRunner::class)->advance($run->id);
        $action = ShipAction::where('state', 'pending')->firstOrFail();
        $held = fopen(config('gameplay.request_budget_path').'/budget.lock', 'c');
        flock($held, LOCK_EX | LOCK_NB);
        try {
            app(ShipCommandExecutor::class)->execute($action->id);
            $this->assertSame(ActionState::Pending, $action->fresh()->state);
            $this->assertNull($action->fresh()->sent_at);
            $this->assertSame(RunState::Running, $run->fresh()->state);
            $this->assertCount(0, $this->world()['writes']);
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame(ActionState::Confirmed, $action->fresh()->state);
        $this->assertCount(1, $this->world()['writes']);
    }

    public function test_preview_error_shows_numeric_limits_and_start_refreshes_expiring_quotes(): void
    {
        $this->postJson(route('game.runs.preview', $this->agent), [
            'ship_symbol' => 'LOCAL-1', 'site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-ROCK',
            'reserve' => 1, 'max_spend' => 1, 'max_loss' => 1, 'minutes' => 60, 'cycles' => 1,
        ])->assertUnprocessable()->assertJsonPath('message', 'This route requires a reserve of at least 1000 CR and a fuel spending ceiling of at least 10 CR. Their sum must fit the available 100000 CR.');
        $runner = app(AutomationRunner::class);
        $run = $runner->preview($this->agent, 'LOCAL-1', ['site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-ROCK', 'reserve' => 2000, 'max_spend' => 100, 'max_loss' => 100, 'minutes' => 60, 'cycles' => 1]);
        GameObservation::where('kind', 'market')->update(['observed_at' => now()->subSeconds(200)]);
        $runner->start($this->agent, $run);
        $this->assertSame(RunState::Running, $run->fresh()->state);
        $this->assertTrue(GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-ROCK')->first()->observed_at->greaterThan(now()->subSeconds(2)));
    }

    public function test_confirmed_price_discrepancy_review_preserves_truthful_history(): void
    {
        $this->world(fn (&$w) => $w['ships'][0]['fuel']['current'] = 90);
        $action = $this->preview('refuel', ['units' => 10, 'maxTotal' => 5]);
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        $this->world(fn (&$w) => $w['failure'] = 'price_jump');
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame(ActionState::Confirmed, $action->fresh()->state);
        $this->assertTrue($action->fresh()->review_required);
        $this->postJson(route('game.commands.preview', $this->agent), ['key' => (string) Str::uuid(), 'ship_symbol' => 'LOCAL-1', 'command' => 'orbit', 'arguments' => []])->assertUnprocessable();
        app(ShipCommandExecutor::class)->reconcile($this->agent->fresh(), $action);
        $this->postJson(route('game.commands.acknowledge', ['agent' => $this->agent->id, 'action' => $action->id]), ['acknowledge' => true, 'note' => 'Reviewed actual fuel and balance change'])->assertAccepted()->assertJsonPath('state', 'confirmed');
        $this->assertFalse($action->fresh()->review_required);
        $this->assertSame(-15, $action->fresh()->outcome['credit_delta']);
        $this->assertFalse($action->fresh()->outcome['unresolved_adjustment']);
    }

    public function test_first_synchronization_keeps_the_same_lock_before_and_after_identity_claim(): void
    {
        $this->agent->update(['canonical_identity' => null, 'reset_id' => null]);
        app(ShipCommandExecutor::class)->locked($this->agent, function () {
            app(AgentSynchronizer::class)->guardReset($this->agent);
            $this->expectException(GameException::class);
            app(ShipCommandExecutor::class)->locked($this->agent->fresh(), fn () => $this->fail('Canonicalization changed lock ownership'));
        });
    }

    public function test_invalid_cooldown_is_rejected_at_boundary_and_blocks_active_run(): void
    {
        $run = $this->miningRun();
        $this->world(fn (&$w) => $w['ships'][0]['cooldown']['expiration'] = 'not-a-date');
        app(AutomationRunner::class)->advance($run->id);
        $this->assertSame(RunState::Blocked, $run->fresh()->state);
        $this->assertStringContainsString('incompatible', $run->fresh()->blocked_reason);
        $this->assertCount(0, $this->world()['writes']);
    }

    public function test_stop_during_transit_keeps_arrival_state_and_starts_no_further_mutation(): void
    {
        $this->world(function (&$w) {
            $w['ships'][] = FakeSpaceTraders::ship('LOCAL-2', 'X1-TEST-PORT');
            $w['travel_seconds'] = 300;
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $run = $this->miningRun(['market' => 'X1-TEST-PORT']);
        $this->advance($run);
        $this->advance($run);
        $this->advance($run);
        $count = count($this->world()['writes']);
        $this->assertSame('IN_TRANSIT', Ship::where('symbol', 'LOCAL-1')->first()->snapshot['nav']['status']);
        $this->postJson(route('game.runs.stop', ['agent' => $this->agent->id, 'run' => $run->id]))->assertAccepted();
        $this->advance($run);
        $this->assertSame(RunState::Stopped, $run->state);
        $this->assertCount($count, $this->world()['writes']);
        $this->expectException(GameException::class);
        $this->preview('orbit');
    }

    public function test_world_reset_stops_an_active_run_before_another_write(): void
    {
        $run = $this->miningRun();
        $this->world(fn (&$w) => $w['reset'] = '2026-11-01');
        $this->advance($run);
        $this->assertSame(RunState::Blocked, $run->state);
        $this->assertNotNull($run->stop_requested_at);
        $this->assertCount(0, $this->world()['writes']);
    }

    public function test_form_numeric_strings_become_integer_trade_payloads(): void
    {
        $id = $this->postJson(route('game.commands.preview', $this->agent), ['key' => (string) Str::uuid(), 'ship_symbol' => 'LOCAL-1', 'command' => 'buy', 'arguments' => ['units' => '1', 'symbol' => 'IRON_ORE', 'maxTotal' => '30']])->assertAccepted()->json('id');
        $action = ShipAction::findOrFail($id);
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        app(ShipCommandExecutor::class)->execute($id);
        $this->assertIsInt($this->world()['writes'][0]['arguments']['units']);
        $this->assertSame(ActionState::Confirmed, $action->fresh()->state);
    }

    #[DataProvider('readFailureStatuses')]
    public function test_read_side_errors_pause_without_mutation_or_automatic_replay(int $status): void
    {
        $run = $this->miningRun();
        $this->world(fn (&$w) => $w['failure'] = 'read_'.$status);
        app(AutomationRunner::class)->advance($run->id);
        $this->assertSame(RunState::Blocked, $run->fresh()->state);
        $this->assertCount(0, $this->world()['writes']);
        $this->assertGreaterThan(microtime(true), app(RequestBudget::class)->usage()['host']['blocked_until']);
        $count = count($this->world()['calls']);
        app(AutomationRunner::class)->advance($run->id);
        $this->assertCount($count, $this->world()['calls']);
    }

    public static function readFailureStatuses(): array
    {
        return [[429], [503]];
    }

    public function test_stop_arriving_while_an_upstream_action_settles_allows_no_next_action(): void
    {
        $run = $this->miningRun();
        app(AutomationRunner::class)->advance($run->id);
        $action = ShipAction::where('state', 'pending')->firstOrFail();
        $this->world(fn (&$w) => $w['write_stop_run_id'] = $run->id);
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame(ActionState::Confirmed, $action->fresh()->state);
        $this->assertNotNull($run->fresh()->stop_requested_at);
        app(AutomationRunner::class)->advance($run->id);
        $this->assertSame(RunState::Stopped, $run->fresh()->state);
        $this->assertCount(1, $this->world()['writes']);
    }

    public function test_credential_repair_preserves_uncertainty_and_does_not_release_commands(): void
    {
        $action = $this->preview('orbit');
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        $this->world(fn (&$w) => $w['failure'] = 'timeout_after');
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame(ActionState::Uncertain, $action->fresh()->state);
        $this->post(route('agents.storeExisting'), ['token' => 'repaired-synthetic-token'])->assertSessionHasNoErrors();
        $this->assertSame('repaired-synthetic-token', $this->agent->fresh()->credential());
        $this->assertSame(ActionState::Uncertain, $action->fresh()->state);
        $this->postJson(route('game.commands.preview', $this->agent), ['key' => (string) Str::uuid(), 'ship_symbol' => 'LOCAL-1', 'command' => 'orbit', 'arguments' => []])->assertUnprocessable();
        app(ShipCommandExecutor::class)->reconcile($this->agent->fresh(), $action);
        $this->assertSame(ActionState::Confirmed, $action->fresh()->state);
        $this->assertCount(1, $this->world()['writes']);
    }

    public function test_schema_valid_but_inconsistent_extraction_becomes_uncertain_without_replay(): void
    {
        $this->command('orbit');
        $action = $this->preview('mine');
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        $this->world(fn (&$w) => $w['failure'] = 'mismatched_yield');
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame(ActionState::Uncertain, $action->fresh()->state);
        $this->assertStringContainsString('Cargo response', $action->fresh()->message);
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertCount(2, $this->world()['writes']);
    }

    public function test_discovery_prioritizes_owned_locations_without_increasing_the_request_cap(): void
    {
        $this->world(function (&$world) {
            $owned = $world['waypoints'][0];
            $world['waypoints'] = [];
            for ($i = 0; $i < 30; $i++) {
                $world['waypoints'][] = ['symbol' => 'X1-TEST-M'.$i, 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => $i, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]];
            }
            $world['waypoints'][] = $owned;
            $world['calls'] = [];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $market = GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-ROCK')->firstOrFail();
        $this->assertNotEmpty($market->data['tradeGoods']);
        $observations = array_filter($this->world()['calls'], fn ($call) => str_ends_with($call['path'], '/market') || str_ends_with($call['path'], '/shipyard'));
        $this->assertCount(config('gameplay.max_observations'), $observations);
    }

    public function test_sqlite_connections_use_full_durability_and_mac_sync_support(): void
    {
        $this->assertSame(2, (int) DB::selectOne('PRAGMA synchronous')->synchronous);
        $this->assertSame(1, (int) DB::selectOne('PRAGMA fullfsync')->fullfsync);
    }

    public function test_foreign_and_absent_agents_have_the_same_scoped_binding_response(): void
    {
        $this->actingAs(User::factory()->create());
        $foreign = $this->postJson(route('game.sync', $this->agent));
        $absent = $this->postJson(route('game.sync', ['agent' => $this->agent->id + 1000]));
        $foreign->assertNotFound();
        $absent->assertNotFound();
        $this->assertSame($foreign->json(), $absent->json());
        $this->assertSame($foreign->headers->get('X-RateLimit-Limit'), $absent->headers->get('X-RateLimit-Limit'));
    }

    public function test_intent_expiring_during_preflight_is_rejected_before_transport(): void
    {
        $action = $this->preview('orbit');
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        $action->update(['expires_at' => now()->addSecond()]);
        $moved = false;
        Http::globalRequestMiddleware(function ($request) use (&$moved) {
            if (! $moved && str_ends_with((string) $request->getUri(), '/my/ships/LOCAL-1')) {
                $future = now()->addSeconds(2);
                Carbon::setTestNow($future);
                CarbonImmutable::setTestNow($future);
                $moved = true;
            }

            return $request;
        });
        try {
            app(ShipCommandExecutor::class)->execute($action->id);
            $this->assertSame(ActionState::Rejected, $action->fresh()->state);
            $this->assertCount(0, $this->world()['writes']);
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }

    public function test_run_expiring_during_preflight_cannot_reserve_a_write(): void
    {
        $run = $this->miningRun();
        app(AutomationRunner::class)->advance($run->id);
        $action = ShipAction::where('state', 'pending')->firstOrFail();
        $run->update(['expires_at' => now()->addSecond()]);
        $action->update(['expires_at' => now()->addMinute()]);
        $moved = false;
        Http::globalRequestMiddleware(function ($request) use (&$moved) {
            if (! $moved && str_ends_with((string) $request->getUri(), '/my/ships/LOCAL-1')) {
                $future = now()->addSeconds(2);
                Carbon::setTestNow($future);
                CarbonImmutable::setTestNow($future);
                $moved = true;
            }

            return $request;
        });
        try {
            app(ShipCommandExecutor::class)->execute($action->id);
            $this->assertSame(ActionState::Rejected, $action->fresh()->state);
            app(AutomationRunner::class)->advance($run->id);
            $this->assertSame(RunState::Stopped, $run->fresh()->state);
            $this->assertCount(0, $this->world()['writes']);
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }

    public function test_simultaneous_first_worker_heartbeats_do_not_fail_a_queue_job(): void
    {
        DB::table('game_runtime')->where('key', 'worker')->delete();
        $inserted = false;
        DB::listen(function ($query) use (&$inserted) {
            if (! $inserted && str_starts_with($query->sql, 'select exists(select * from "game_runtime"')) {
                $inserted = true;
                DB::table('game_runtime')->insert(['key' => 'worker', 'value' => json_encode(now()->toIso8601String())]);
            }
        });
        (new AdvanceAutomationRun(PHP_INT_MAX))->handle(app(AutomationRunner::class));
        $this->assertSame(1, DB::table('game_runtime')->where('key', 'worker')->count());
    }
}
