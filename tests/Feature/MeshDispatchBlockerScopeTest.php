<?php

namespace Tests\Feature;

use App\Gameplay\ActionInFlight;
use App\Gameplay\AgentSynchronizer;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignMesh;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\CommandRules;
use App\Gameplay\EngineGate;
use App\Gameplay\GameException;
use App\Gameplay\HostLock;
use App\Gameplay\RequestDeferred;
use App\Gameplay\ShipCommandExecutor;
use App\Gameplay\SpaceTradersApi;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\ShipAction;
use App\Services\SpaceTradersClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\FakeSpaceTraders;
use Tests\Support\SaturationFixture;
use Tests\TestCase;

class MeshDispatchBlockerScopeTest extends TestCase
{
    use RefreshDatabase;
    use SaturationFixture;

    protected function tearDown(): void
    {
        $this->tearDownSaturation();
        parent::tearDown();
    }

    public static function blockers(): iterable
    {
        foreach (['pending', 'sending'] as $state) {
            yield [$state, 'navigate', 'LOCAL-2', true, [], 'ship', 'pending'];
            yield [$state, 'transfer', 'LOCAL-2', true, [], 'agent', 'pending'];
            yield [$state, 'refuel', 'LOCAL-2', true, [], 'agent', 'pending'];
            yield [$state, 'refuel', 'LOCAL-1', true, [], 'agent', 'pending'];
            yield [$state, 'navigate', 'LOCAL-2', false, [], 'agent', 'pending'];
            yield [$state, 'navigate', 'LOCAL-1', false, [], 'agent', 'pending'];
            yield [$state, 'navigate', 'LOCAL-2', true, ['body_sha256' => 'hash'], 'agent', 'uncertain_or_review'];
            yield [$state, 'navigate', 'LOCAL-2', true, ['response_received_at' => 'timestamp'], 'agent', 'uncertain_or_review'];
            yield [$state, 'buy_ship', 'LOCAL-1', true, ['response' => []], 'agent', 'uncertain_or_review'];
        }
        yield ['uncertain', 'navigate', 'LOCAL-2', true, [], 'agent', 'uncertain_or_review'];
        yield ['uncertain', 'navigate', 'LOCAL-2', false, [], 'agent', 'uncertain_or_review'];
        yield ['confirmed', 'navigate', 'LOCAL-2', false, [], 'agent', 'uncertain_or_review'];
    }

    #[DataProvider('blockers')]
    public function test_clear_classifies_blocker_scope_accurately(string $state, string $command, string $ship, bool $split, array $outcome, string $scope, string $kind): void
    {
        $this->setUpSaturation(hubs: 2, seedCoverage: false);
        $this->episode($split);
        $blocker = ShipAction::create([
            'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'key' => 'blocker',
            'ship_symbol' => $ship, 'origin' => 'manual', 'command' => $command, 'state' => $state,
            'arguments' => [], 'outcome' => $outcome, 'expires_at' => now()->addMinute(), 'review_required' => $state === 'confirmed',
        ]);

        try {
            app(ShipCommandExecutor::class)->clear($this->agent->refresh(), defer: true, ship: 'LOCAL-2');
            $this->fail('The action must prevent dispatch.');
        } catch (ActionInFlight $exception) {
            $this->assertSame($scope, $exception->scope);
            $this->assertSame($kind, $exception->kind);
            $this->assertFalse($exception->uncertain);
            $this->assertSame($scope === 'ship', $exception->shipLocalPending());
            if ($kind === 'pending' || $outcome !== []) {
                $this->assertSame([$ship], $exception->ships);
                $this->assertSame([$blocker->id], $exception->actionIds);
                $this->assertSame($kind === 'pending' ? 5 : 0, $exception->retryAfter);
            }
        }
    }

    #[DataProvider('dispatchBlockers')]
    public function test_director_continues_only_for_a_verified_ship_local_pending_blocker(bool $split, bool $oneTarget, string $blocker, bool $started): void
    {
        $this->setUpSaturation(hubs: 2, seedCoverage: false);
        $this->editFixture(function (&$world) use ($oneTarget) {
            $world['waypoints'][] = $this->point('X1-TEST-P', 1, []);
            if ($oneTarget) {
                $world['waypoints'][0]['traits'] = [['symbol' => 'SHIPYARD']];
            } else {
                $world['waypoints'][] = $this->point('X1-TEST-A', 8);
            }
            $world['ships'][1] = FakeSpaceTraders::probe('LOCAL-2', 'X1-TEST-P');
            $world['ships'][] = FakeSpaceTraders::probe('LOCAL-3', 'X1-TEST-P');
        });
        $sync = app(AgentSynchronizer::class);
        $sync->sync($this->agent, false);
        foreach ($this->world()['waypoints'] as $point) {
            $sync->observe($this->agent, 'waypoint', $point['symbol'], $point, 'listing');
            if (in_array('MARKETPLACE', array_column($point['traits'], 'symbol'), true)) {
                $sync->observe($this->agent, 'market', $point['symbol'], [
                    'symbol' => $point['symbol'], 'imports' => [], 'exports' => [], 'exchange' => [['symbol' => 'IRON_ORE']],
                    'tradeGoods' => $this->world()['goods'],
                ]);
            }
        }
        $episode = $this->episode($split);
        $director = app(CampaignDirector::class);
        $agent = $this->agent->refresh();
        $deadline = microtime(true) + 10;
        $plan = app(CampaignMesh::class)->dispatchPlan($episode, $agent, [], [], null, []);
        $first = $plan['moves'][0]['ship'];
        $other = $first === 'LOCAL-2' ? 'LOCAL-3' : 'LOCAL-2';
        $real = app(ShipCommandExecutor::class);
        $attempted = [];
        $inserted = null;
        $hook = Mockery::mock(ShipCommandExecutor::class,
            [app(HostLock::class), app(CommandRules::class), app(SpaceTradersApi::class), $sync, app(EngineGate::class)])->makePartial();
        $this->app->instance(ShipCommandExecutor::class, $hook);
        $hook->shouldReceive('clear')->andReturnUsing(function ($agent, $except = null, $defer = false, $ship = null, $command = null,
            $targetShip = null, $leaseId = null, $reservationOnly = false) use ($real, $first, $blocker, &$attempted, &$inserted) {
            $attempted[] = $ship;
            if ($ship === $first && count($attempted) === 1) {
                if ($blocker === 'deferred') {
                    throw new RequestDeferred('Fixture throttle', 5, 'blocked_until');
                }
                if ($blocker === 'uncertain_exception') {
                    throw new GameException('Fixture uncertainty', uncertain: true);
                }
                $inserted = ShipAction::create([
                    'agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'key' => 'late-blocker', 'origin' => 'manual',
                    'ship_symbol' => $blocker === 'credit' ? 'LOCAL-1' : $ship, 'command' => $blocker === 'credit' ? 'refuel' : 'navigate',
                    'state' => $blocker === 'uncertain' ? 'uncertain' : 'pending', 'arguments' => [],
                    'outcome' => $blocker === 'receipt' ? ['body_sha256' => 'retained-receipt'] : [], 'expires_at' => now()->addMinute(),
                ]);
            }

            $real->clear($agent, $except, $defer, $ship, $command, $targetShip, $leaseId, $reservationOnly);
        });
        (new ReflectionProperty($director, 'deadline'))->setValue($director, $deadline);
        $busy = $reserved = $considered = [];
        $dispatch = new ReflectionMethod($director, 'dispatchSaturationMesh');
        $result = app(HostLock::class)->scoped('agent-'.$agent->id, function () use ($agent, $episode, $director, $dispatch, &$busy, &$reserved, &$considered, $deadline) {
            $arguments = [$episode->grant(), $episode, $agent, &$busy, &$reserved, &$considered];

            return app(SpaceTradersClient::class)->scope(fn () => $dispatch->invokeArgs($director, $arguments), deadline: $deadline);
        });

        $this->assertSame($started, $result, json_encode(CampaignEvent::where('kind', 'mesh_plan')->sole()->data));
        $this->assertSame($started ? [$first, $other] : [$first], array_values(array_unique($attempted)));
        $runs = AutomationRun::where('strategy', 'scout');
        $this->assertSame($started, (clone $runs)->where('ship_symbol', $other)->whereNotNull('started_at')->exists());
        $this->assertNull((clone $runs)->where('ship_symbol', $first)->sole()->started_at);
        if ($blocker !== 'uncertain_exception') {
            $this->assertSame('stopped', (clone $runs)->where('ship_symbol', $first)->sole()->state->value);
        }
        $holds = CampaignEvent::where('kind', 'mesh_plan')->sole()->data['holds'];
        $this->assertCount(1, $holds);
        if ($inserted) {
            $this->assertSame($started ? 'ship' : 'agent', $holds[0]['blocker_scope']);
            $this->assertSame(in_array($blocker, ['receipt', 'uncertain'], true) ? 'uncertain_or_review' : 'pending', $holds[0]['blocker_kind']);
            $this->assertSame($inserted->outcome, $inserted->fresh()->outcome);
        }
        if (! $started) {
            $this->assertFalse((clone $runs)->where('ship_symbol', $other)->exists());
        }
        if ($started && $oneTarget) {
            $this->assertSame($plan['moves'][0]['destination'], $runs->where('ship_symbol', $other)->sole()->plan['destination']);
        }
    }

    public static function dispatchBlockers(): iterable
    {
        yield 'same iteration' => [true, false, 'pending', true];
        yield 'replan same target' => [true, true, 'pending', true];
        yield 'agent-wide pending' => [false, false, 'pending', false];
        yield 'credit on another ship' => [true, false, 'credit', false];
        yield 'ship uncertainty' => [true, false, 'uncertain', false];
        yield 'agent uncertainty' => [false, false, 'uncertain', false];
        yield 'host deferral' => [true, false, 'deferred', false];
        yield 'uncertain transport' => [true, false, 'uncertain_exception', false];
        yield 'receipt-bearing pending' => [true, false, 'receipt', false];
    }

    private function episode(bool $split): CampaignEpisode
    {
        $body = Yaml::parseFile(base_path('campaigns/weekly-system-saturation-fleet.yaml'));
        $body['agent']['symbol'] = $this->agent->name;
        $body['fleet']['auto_arm']['revision_hash'] = $this->armRevision->content_hash;
        $body['fleet']['saturation']['per_ship_noncredit_locks'] = $split;

        return $this->primary(revision: app(CampaignPlaybook::class)->import(Yaml::dump($body, 12)));
    }
}
