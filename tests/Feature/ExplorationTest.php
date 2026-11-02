<?php

namespace Tests\Feature;

use App\Enums\ActionState;
use App\Gameplay\AgentSynchronizer;
use App\Gameplay\ExplorationPolicy;
use App\Gameplay\GameException;
use App\Gameplay\ShipCommandExecutor;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Agent;
use App\Models\GameObservation;
use App\Models\ObservationHistory;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class ExplorationTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    private Agent $agent;

    private const TARGET = 'X1-TEST-HIDDEN';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->file = tempnam(sys_get_temp_dir(), 'st-exp01-');
        $world = FakeSpaceTraders::world();
        $ship = &$world['ships'][0];
        $ship['nav']['waypointSymbol'] = self::TARGET;
        $ship['nav']['status'] = 'IN_ORBIT';
        $ship['mounts'][] = ['symbol' => 'MOUNT_SENSOR_ARRAY_I', 'requirements' => ['crew' => 1, 'power' => 1]];
        $world['ships'][] = FakeSpaceTraders::ship('LOCAL-2');
        $point = ['symbol' => self::TARGET, 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 1, 'y' => 0, 'orbitals' => [], 'traits' => [['symbol' => 'UNCHARTED']]];
        $world['waypoints'][] = $point;
        $point['traits'][] = ['symbol' => 'MARKETPLACE'];
        $world['scan_waypoints'] = [$point];
        $world['chart_traits'] = [['symbol' => 'MARKETPLACE']];
        $world['cooldown_seconds'] = 60;
        file_put_contents($this->file, json_encode($world));
        FakeSpaceTraders::install($this->file);
        $user = User::factory()->create();
        $this->agent = $user->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic-exp01', 'account_id' => 'synthetic-account', 'faction' => 'COSMIC', 'reset_id' => $world['reset'], 'canonical_identity' => AgentSynchronizer::identity($world['reset'], 'LOCAL')]);
        $this->actingAs($user);
        app(AgentSynchronizer::class)->sync($this->agent);
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        parent::tearDown();
    }

    private function world(?callable $change = null): array
    {
        $world = json_decode(file_get_contents($this->file), true);
        if ($change) {
            $change($world);
            file_put_contents($this->file, json_encode($world));
        }

        return $world;
    }

    private function arguments(): array
    {
        return ['waypointSymbol' => self::TARGET, 'question' => 'MARKETPLACE', 'maxCooldownSeconds' => 300, 'reserve' => 1000];
    }

    private function preview(string $command = 'scan_waypoints'): ShipAction
    {
        return app(ShipCommandExecutor::class)->preview($this->agent->fresh(), 'LOCAL-1', $command, $this->arguments(), (string) Str::uuid());
    }

    private function execute(ShipAction $action): ShipAction
    {
        app(ShipCommandExecutor::class)->approve($this->agent->fresh(), $action);
        app(ShipCommandExecutor::class)->execute($action->id);

        return $action->fresh();
    }

    public function test_http_preview_approval_and_scan_are_bounded_owned_and_distinct(): void
    {
        $this->world(fn (&$w) => $w['calls'] = []);
        $response = $this->postJson(route('game.commands.preview', $this->agent), ['key' => (string) Str::uuid(), 'ship_symbol' => 'LOCAL-1', 'command' => 'scan_waypoints', 'arguments' => $this->arguments()]);
        $response->assertAccepted()->assertJsonPath('before_state.estimate.exploration.travel_fuel', 0)->assertJsonPath('before_state.estimate.exploration.spend', 0)->assertDontSee('synthetic-exp01');
        $this->assertCount(0, $this->world()['writes']);
        $action = ShipAction::findOrFail($response->json('id'));
        $this->postJson(route('game.commands.approve', [$this->agent, $action->id]))->assertAccepted();
        app(ShipCommandExecutor::class)->execute($action->id);
        $action->refresh();
        $this->assertSame(ActionState::Confirmed, $action->state);
        $this->assertTrue($action->outcome['exploration']['answered']);
        $this->assertTrue($action->outcome['exploration']['trait_present']);
        $this->assertLessThanOrEqual(13, count($this->world()['calls']));
        $this->assertCount(1, $this->world()['writes']);
        $this->assertSame([], $this->world()['writes'][0]['arguments']);
        $scan = GameObservation::where('kind', 'waypoint_scan')->firstOrFail();
        $this->assertSame('LOCAL-1', $scan->observer_ship);
        $this->assertSame($this->agent->reset_id, $scan->reset_id);
        $listing = GameObservation::where('kind', 'waypoint_listing')->where('symbol', self::TARGET)->firstOrFail();
        $this->assertSame([['symbol' => 'UNCHARTED']], $listing->data['traits']);
        $point = GameObservation::where('kind', 'waypoint')->where('symbol', self::TARGET)->firstOrFail();
        $this->assertTrue(ExplorationPolicy::traitsKnown($point->data));
        $this->assertSame('scan', $point->data['traits_source']);
        $time = $point->data['traits_observed_at'];
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->assertSame($time, $point->fresh()->data['traits_observed_at']);
        $this->assertSame($scan->data, $scan->fresh()->data);
        $this->assertSame(1, ObservationHistory::where('kind', 'waypoint_scan')->count());
    }

    public function test_partial_scan_does_not_answer_an_omitted_target_or_change_catalogue_coverage(): void
    {
        $this->world(fn (&$w) => $w['scan_waypoints'] = []);
        $catalogue = GameObservation::where('kind', 'catalogue')->firstOrFail()->data;
        $action = $this->execute($this->preview());
        $this->assertSame(ActionState::Confirmed, $action->state);
        $this->assertFalse($action->outcome['exploration']['answered']);
        $this->assertNull($action->outcome['exploration']['trait_present']);
        $this->assertSame($catalogue, GameObservation::where('kind', 'catalogue')->firstOrFail()->data);
        $this->assertFalse(ExplorationPolicy::traitsKnown(GameObservation::where('kind', 'waypoint')->where('symbol', self::TARGET)->firstOrFail()->data));
        $this->get(route('agents.show', $this->agent))->assertInertia(fn (Assert $page) => $page->where('intelligence.hidden_waypoints', [self::TARGET]));
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertCount(1, $this->world()['writes']);
    }

    public function test_chart_needs_no_sensor_records_reward_and_requires_a_new_decision_afterward(): void
    {
        $this->world(fn (&$w) => $w['ships'][0]['mounts'] = []);
        $action = $this->execute($this->preview('chart'));
        $this->assertSame(ActionState::Confirmed, $action->state);
        $this->assertSame(100, $action->outcome['credit_delta']);
        $this->assertSame(100100, $this->agent->fresh()->credits);
        $this->assertSame('chart', GameObservation::where('kind', 'waypoint_chart')->firstOrFail()->source);
        $this->assertTrue($action->outcome['exploration']['answered']);
        $this->expectExceptionMessage('Traits are already observed');
        $this->preview('chart');
    }

    #[DataProvider('capabilityFailures')]
    public function test_capability_state_and_continuation_fail_before_any_write(string $case): void
    {
        $this->world(function (&$w) use ($case) {
            match ($case) {
                'sensor' => $w['ships'][0]['mounts'] = [],
                'crew' => $w['ships'][0]['crew']['current'] = 0,
                'power' => $w['ships'][0]['reactor']['powerOutput'] = 0,
                'docked' => $w['ships'][0]['nav']['status'] = 'DOCKED',
                'transit' => $w['ships'][0]['nav']['status'] = 'IN_TRANSIT',
                'cooldown' => $w['ships'][0]['cooldown'] = ['remainingSeconds' => 60, 'expiration' => now()->addMinute()->toIso8601String()],
                'fuel' => $w['ships'][0]['fuel']['current'] = 0,
                'reserve' => $w['credits'] = 1000,
            };
            if ($case === 'transit') {
                $w['ships'][0]['nav']['route']['arrival'] = now()->addMinute()->toIso8601String();
            }
        });
        try {
            $this->preview();
            $this->fail('Unsafe preview succeeded.');
        } catch (GameException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertCount(0, $this->world()['writes']);
        $this->assertSame(0, ShipAction::count());
    }

    public static function capabilityFailures(): array
    {
        return array_map(fn ($value) => [$value], ['sensor', 'crew', 'power', 'docked', 'transit', 'cooldown', 'fuel', 'reserve']);
    }

    public function test_question_required_and_foreign_target_and_owner_denied_without_provider_calls(): void
    {
        $calls = count($this->world()['calls']);
        $args = $this->arguments();
        unset($args['question']);
        $this->postJson(route('game.commands.preview', $this->agent), ['key' => (string) Str::uuid(), 'ship_symbol' => 'LOCAL-1', 'command' => 'scan_waypoints', 'arguments' => $args])->assertUnprocessable()->assertJsonValidationErrors('arguments.question');
        $this->assertSame($calls, count($this->world()['calls']));
        $action = $this->preview();
        $this->actingAs(User::factory()->create());
        foreach (['approve', 'stop', 'reconcile'] as $verb) {
            $this->postJson(route('game.commands.'.$verb, [$this->agent, $action->id]))->assertNotFound();
        }
        $this->assertCount(0, $this->world()['writes']);
    }

    public function test_chart_remote_target_and_already_answered_scan_fail(): void
    {
        $this->world(fn (&$w) => $w['ships'][0]['nav']['waypointSymbol'] = 'X1-TEST-ROCK');
        try {
            $this->preview('chart');
            $this->fail('Remote chart accepted.');
        } catch (GameException $e) {
            $this->assertStringContainsString('current waypoint', $e->getMessage());
        }
        $this->world(fn (&$w) => $w['waypoints'][2]['traits'] = [['symbol' => 'SHIPYARD']]);
        $this->expectExceptionMessage('Traits are already observed');
        $this->preview();
    }

    #[DataProvider('uncertainOutcomes')]
    public function test_uncertain_scan_never_replays_or_auto_confirms_from_cooldown(string $failure): void
    {
        $action = $this->preview();
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        $this->world(function (&$w) use ($failure) {
            match ($failure) {
                'timeout' => $w['failure'] = 'timeout_after',
                'wrong_system' => $w['scan_waypoints'][0]['systemSymbol'] = 'X1-OTHER',
                'malformed' => $w['scan_waypoints'][0]['traits'] = 'not an array',
                'duplicate' => $w['scan_waypoints'][] = $w['scan_waypoints'][0],
            };
        });
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame(ActionState::Uncertain, $action->fresh()->state);
        $this->assertSame(0, GameObservation::where('kind', 'waypoint_scan')->count());
        app(ShipCommandExecutor::class)->execute($action->id);
        app(ShipCommandExecutor::class)->reconcile($this->agent, $action);
        $this->assertSame(ActionState::Uncertain, $action->fresh()->state);
        $this->assertCount(1, $this->world()['writes']);
        $this->expectExceptionMessage('pending, uncertain');
        $this->preview();
    }

    public static function uncertainOutcomes(): array
    {
        return array_map(fn ($value) => [$value], ['timeout', 'wrong_system', 'malformed', 'duplicate']);
    }

    public function test_lost_chart_response_remains_uncertain_even_when_reconciliation_sees_a_chart(): void
    {
        $action = $this->preview('chart');
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        $this->world(fn (&$w) => $w['failure'] = 'timeout_after');
        app(ShipCommandExecutor::class)->execute($action->id);
        app(ShipCommandExecutor::class)->reconcile($this->agent, $action);
        $this->assertSame(ActionState::Uncertain, $action->fresh()->state);
        $this->assertNotEmpty($action->fresh()->outcome['waypoint']['chart']);
        $this->assertSame(0, GameObservation::where('kind', 'waypoint_chart')->count());
        $this->assertCount(1, $this->world()['writes']);
    }

    public function test_longer_than_approved_cooldown_is_retained_and_requires_review(): void
    {
        $this->world(fn (&$w) => $w['cooldown_seconds'] = 600);
        $action = $this->execute($this->preview());
        $this->assertSame(ActionState::Confirmed, $action->state);
        $this->assertTrue($action->review_required);
        $this->assertSame(600, $action->outcome['exploration']['cooldown']['remainingSeconds']);
        $this->assertTrue($action->outcome['cooldown_discrepancy']);
        $this->assertFalse($action->outcome['price_discrepancy']);
        $this->assertTrue(Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot['cooldown']['remainingSeconds'] === 600);
        $this->expectExceptionMessage('requires review');
        $this->preview();
    }

    public function test_stop_before_reservation_survives_duplicate_approval_and_worker_delivery(): void
    {
        $action = $this->preview();
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        $this->postJson(route('game.commands.stop', [$this->agent, $action->id]))->assertAccepted()->assertJsonPath('state', 'rejected');
        app(ShipCommandExecutor::class)->execute($action->id);
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        $this->assertSame(ActionState::Rejected, $action->fresh()->state);
        $this->assertCount(0, $this->world()['writes']);
    }

    public function test_stop_during_approval_preflight_cannot_be_overwritten_by_approval(): void
    {
        $action = $this->preview();
        $stopped = false;
        Http::globalRequestMiddleware(function ($request) use ($action, &$stopped) {
            if (! $stopped) {
                $stopped = true;
                app(ShipCommandExecutor::class)->stopManual($action->fresh());
            }

            return $request;
        });
        try {
            app(ShipCommandExecutor::class)->approve($this->agent, $action);
            $this->fail('Stopped preview approved.');
        } catch (GameException $e) {
            $this->assertStringContainsString('stopped', $e->getMessage());
        }
        $this->assertSame(ActionState::Rejected, $action->fresh()->state);
        $this->assertCount(0, $this->world()['writes']);
    }

    public function test_reset_rejects_intent_and_excludes_old_evidence_from_planning(): void
    {
        $action = $this->preview();
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        $this->world(fn (&$w) => $w['reset'] = 'new-world');
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame(ActionState::Rejected, $action->fresh()->state);
        $this->assertTrue($this->agent->fresh()->isArchived());
        $this->assertCount(0, $this->world()['writes']);
        $this->agent->update(['reset_id' => 'new-world']);
        $this->get(route('agents.show', $this->agent))->assertInertia(fn (Assert $page) => $page->has('observations', 0)->has('intelligence.exploration_evidence', 0));
    }

    public function test_changed_capability_or_expired_intent_is_rejected_at_execution(): void
    {
        $action = $this->preview();
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        $this->world(fn (&$w) => $w['ships'][0]['mounts'] = []);
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame(ActionState::Rejected, $action->fresh()->state);
        $this->assertCount(0, $this->world()['writes']);
        $this->world(fn (&$w) => $w['ships'][0]['mounts'][] = ['symbol' => 'MOUNT_SENSOR_ARRAY_I', 'requirements' => []]);
        $action = $this->preview();
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        $action->update(['expires_at' => CarbonImmutable::now()->subSecond()]);
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame(ActionState::Rejected, $action->fresh()->state);
        $this->assertCount(0, $this->world()['writes']);
    }

    public function test_stop_during_execution_preflight_cannot_reserve_a_write(): void
    {
        $action = $this->preview();
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        $stopped = false;
        Http::globalRequestMiddleware(function ($request) use ($action, &$stopped) {
            if (! $stopped) {
                $stopped = true;
                app(ShipCommandExecutor::class)->stopManual($action->fresh());
            }

            return $request;
        });
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame(ActionState::Rejected, $action->fresh()->state);
        $this->assertCount(0, $this->world()['writes']);
    }

    public function test_stop_after_reservation_keeps_the_result_and_never_follows_up(): void
    {
        $action = $this->preview();
        $this->world(fn (&$w) => $w['write_stop_action_id'] = $action->id);
        $action = $this->execute($action);
        $this->assertSame(ActionState::Confirmed, $action->state);
        $this->assertNotEmpty($action->outcome['stop_requested_at']);
        $this->assertTrue($action->outcome['exploration']['answered']);
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertCount(1, $this->world()['writes']);
    }

    public function test_failed_approval_or_read_preflight_does_not_retry_the_same_intent(): void
    {
        $action = $this->preview();
        $this->world(fn (&$w) => $w['failure'] = 'read_503');
        try {
            app(ShipCommandExecutor::class)->approve($this->agent, $action);
            $this->fail('Failed preflight approved.');
        } catch (GameException) {
            $this->assertSame(ActionState::Rejected, $action->fresh()->state);
        }
        $calls = count($this->world()['calls']);
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        $this->assertSame($calls, count($this->world()['calls']));
        $this->assertCount(0, $this->world()['writes']);
    }

    public function test_failed_execution_preflight_does_not_retry_the_same_intent(): void
    {
        $action = $this->preview();
        app(ShipCommandExecutor::class)->approve($this->agent, $action);
        $this->world(fn (&$w) => $w['failure'] = 'read_503');
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame(ActionState::Rejected, $action->fresh()->state);
        $calls = count($this->world()['calls']);
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame($calls, count($this->world()['calls']));
        $this->assertCount(0, $this->world()['writes']);
    }

    public function test_incomplete_catalogue_can_support_one_question_without_claiming_complete_knowledge(): void
    {
        GameObservation::where('kind', 'catalogue')->update(['data' => json_encode(['complete' => false, 'pages' => 1, 'fetched' => 3, 'total' => 20, 'reason' => 'Page budget reached.'])]);
        $action = $this->execute($this->preview());
        $this->assertSame(ActionState::Confirmed, $action->state);
        $this->assertFalse(GameObservation::where('kind', 'catalogue')->firstOrFail()->data['complete']);
    }

    public function test_exploration_routes_enforce_real_csrf_middleware(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $action = $this->preview();
        $calls = count($this->world()['calls']);
        foreach (['approve', 'stop'] as $verb) {
            $this->postJson(route('game.commands.'.$verb, [$this->agent, $action->id]))->assertStatus(419);
        }
        $this->assertSame($calls, count($this->world()['calls']));
    }

    public function test_observed_absence_is_an_answer_and_fresh_listing_cannot_erase_it(): void
    {
        $this->world(fn (&$w) => $w['scan_waypoints'][0]['traits'] = []);
        $action = $this->execute($this->preview());
        $this->assertTrue($action->outcome['exploration']['answered']);
        $this->assertFalse($action->outcome['exploration']['trait_present']);
        app(AgentSynchronizer::class)->sync($this->agent);
        $point = GameObservation::where('kind', 'waypoint')->where('symbol', self::TARGET)->firstOrFail();
        $this->assertSame([], $point->data['traits']);
        $this->assertTrue(ExplorationPolicy::traitsKnown($point->data));
    }

    public function test_stale_continuation_price_and_foreign_or_old_reset_target_cannot_authorize_exploration(): void
    {
        $target = GameObservation::where('kind', 'waypoint')->where('symbol', self::TARGET)->firstOrFail();
        $target->update(['reset_id' => 'previous-world']);
        try {
            $this->preview();
            $this->fail('Previous-reset target accepted.');
        } catch (GameException $e) {
            $this->assertStringContainsString('known candidate', $e->getMessage());
        }
        $target->update(['reset_id' => $this->agent->reset_id, 'agent_id' => User::factory()->create()->agents()->create(['name' => 'OTHER', 'account_id' => 'synthetic-other-account', 'faction' => 'COSMIC', 'token' => 'synthetic-other'])->id]);
        try {
            $this->preview();
            $this->fail('Foreign target accepted.');
        } catch (GameException $e) {
            $this->assertStringContainsString('known candidate', $e->getMessage());
        }
        $target->update(['agent_id' => $this->agent->id]);
        GameObservation::where('kind', 'market')->update(['observed_at' => now()->subHour()]);
        try {
            $this->preview();
            $this->fail('Stale continuation fuel quote accepted.');
        } catch (GameException $e) {
            $this->assertStringContainsString('fuel-safe continuation', $e->getMessage());
        }
        $this->assertCount(0, $this->world()['writes']);
    }

    public function test_unchanged_equipment_at_a_changed_location_still_requires_a_fresh_preview(): void
    {
        $action = $this->preview();
        $this->world(fn (&$w) => $w['ships'][0]['nav']['waypointSymbol'] = 'X1-TEST-ROCK');
        try {
            app(ShipCommandExecutor::class)->approve($this->agent, $action);
            $this->fail('Relocated scanner approved.');
        } catch (GameException $e) {
            $this->assertStringContainsString('location, fuel or equipment changed', $e->getMessage());
        }
        $this->assertSame(ActionState::Rejected, $action->fresh()->state);
        $this->assertCount(0, $this->world()['writes']);
    }
}
