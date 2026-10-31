<?php

namespace Tests\Feature;

use App\Enums\ActionState;
use App\Enums\RunState;
use App\Gameplay\AgentSynchronizer;
use App\Gameplay\GameException;
use App\Gameplay\HostLock;
use App\Gameplay\MiningPlanner;
use App\Gameplay\MiningRunner;
use App\Gameplay\ShipCommandExecutor;
use App\Jobs\ExecuteShipCommand;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\GameObservation;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
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
        $run = app(MiningRunner::class)->preview($this->agent->fresh(), 'LOCAL-1', array_merge([
            'site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-ROCK', 'reserve' => 1000,
            'max_spend' => 100, 'max_loss' => 100, 'minutes' => 60, 'cycles' => 1,
        ], $overrides));
        app(MiningRunner::class)->start($this->agent->fresh(), $run);

        return $run->fresh();
    }

    private function advance(AutomationRun $run): void
    {
        app(MiningRunner::class)->advance($run->id);
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
        app(MiningRunner::class)->advance($run->id); // reserved pending orbit
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
        $this->assertGreaterThan(microtime(true), (float) json_decode(DB::table('game_runtime')->where('key', 'request_at')->value('value')));
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
        $this->assertSame($run->id, app(MiningRunner::class)->start($this->agent, $run)->id);
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
            app(MiningRunner::class)->advance($run->id);
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
        app(MiningRunner::class)->advance($run->id);
        $action = ShipAction::where('state', 'pending')->firstOrFail();
        $held = null;
        Http::globalRequestMiddleware(function ($request) use (&$held) {
            if ($request->getMethod() === 'GET' && str_ends_with((string) $request->getUri(), '/my/ships/LOCAL-1') && ! $held) {
                $held = fopen(config('gameplay.lock_path').'/'.hash('sha256', 'upstream-budget').'.lock', 'c');
                flock($held, LOCK_EX | LOCK_NB);
            }

            return $request;
        });
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
        $runner = app(MiningRunner::class);
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
        app(MiningRunner::class)->advance($run->id);
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
        app(MiningRunner::class)->advance($run->id);
        $this->assertSame(RunState::Blocked, $run->fresh()->state);
        $this->assertCount(0, $this->world()['writes']);
        $this->assertGreaterThan(microtime(true), (float) json_decode(DB::table('game_runtime')->where('key', 'request_at')->value('value')));
        $count = count($this->world()['calls']);
        app(MiningRunner::class)->advance($run->id);
        $this->assertCount($count, $this->world()['calls']);
    }

    public static function readFailureStatuses(): array
    {
        return [[429], [503]];
    }

    public function test_stop_arriving_while_an_upstream_action_settles_allows_no_next_action(): void
    {
        $run = $this->miningRun();
        app(MiningRunner::class)->advance($run->id);
        $action = ShipAction::where('state', 'pending')->firstOrFail();
        $this->world(fn (&$w) => $w['write_stop_run_id'] = $run->id);
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame(ActionState::Confirmed, $action->fresh()->state);
        $this->assertNotNull($run->fresh()->stop_requested_at);
        app(MiningRunner::class)->advance($run->id);
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
        app(MiningRunner::class)->advance($run->id);
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
            app(MiningRunner::class)->advance($run->id);
            $this->assertSame(RunState::Stopped, $run->fresh()->state);
            $this->assertCount(0, $this->world()['writes']);
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }
}
