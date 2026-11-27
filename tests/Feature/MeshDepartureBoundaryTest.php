<?php

namespace Tests\Feature;

use App\Enums\RunState;
use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignMesh;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\CampaignSafety;
use App\Gameplay\GameException;
use App\Gameplay\HostLock;
use App\Gameplay\RequestBudget;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\GameObservation;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Services\SpaceTradersClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\FakeSpaceTraders;
use Tests\Support\SaturationFixture;
use Tests\TestCase;
use Tests\Unit\CampaignMeshYardMoveTest;

class MeshDepartureBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use SaturationFixture;

    protected function tearDown(): void
    {
        $this->tearDownSaturation();
        parent::tearDown();
    }

    public static function destinations(): array
    {
        return [
            'market scout' => ['scout', 'market', 'X1-TEST-PORT'],
            'coverage scout' => ['coverage', 'market', 'X1-TEST-PORT'],
            'proof scout' => ['proof_scout', 'market', 'X1-TEST-PORT'],
            'yard scout' => ['yard', 'shipyard', 'X1-TEST-YARD'],
        ];
    }

    #[DataProvider('destinations')]
    public function test_v8_scout_reaches_the_verified_destination(string $work, string $observe, string $destination): void
    {
        $episode = $this->fleetEpisode();
        $agent = $this->agent->refresh();
        $run = $this->launch($episode, $work, $observe, $destination);
        $mesh = app(CampaignMesh::class);
        $seesObserver = fn () => $mesh->hasRecoveryObserver($episode, $agent, $mesh->model($episode, $agent),
            $mesh->probes($episode, $agent), $destination, 900);
        $this->assertSame($observe === 'market', $seesObserver());
        $plan = $run->plan;
        $run->update(['plan' => array_diff_key($plan, ['mesh_work' => true])]);
        $this->assertFalse($seesObserver());
        if ($observe === 'market') {
            $run->update(['plan_version' => 3, 'plan' => array_replace($plan, ['mesh_work' => 'market'])]);
            $this->assertTrue($seesObserver());
            $run->update(['plan' => array_replace($plan, ['destination' => 'X1-TEST-YARD'])]);
            $this->assertFalse($seesObserver());
            $run->update(['plan_version' => 8]);
        }
        $run->update(['plan' => $plan]);

        $this->workRun($run);

        $this->assertSame('completed', $run->refresh()->state->value, $run->blocked_reason ?? '');
        $this->assertSame('confirmed', ShipAction::where('automation_run_id', $run->id)->where('command', 'navigate')->sole()->state->value);
        $observation = GameObservation::where('kind', $observe)->where('symbol', $destination)->sole();
        $this->assertNotEmpty($observation->data[$observe === 'market' ? 'tradeGoods' : 'ships']);
        if ($observe === 'shipyard') {
            $this->assertFalse(collect($this->world()['calls'])->contains('path', '/systems/X1-TEST/waypoints/'.$destination.'/market'));
        }
    }

    #[DataProviderExternal(CampaignMeshYardMoveTest::class, 'movementPlans')]
    public function test_scout_start_and_both_transport_checks_use_the_classified_terrain(array $movement, bool $yard): void
    {
        $episode = $this->fleetEpisode();
        $destination = $yard ? 'X1-TEST-YARD' : 'X1-TEST-PORT';
        $run = $this->launch($episode, $yard ? 'yard' : 'scout', $yard ? 'shipyard' : 'market', $destination);
        $plan = $movement + array_diff_key($run->plan, ['mesh_work' => true, 'observe_kind' => true]);
        $run->update(['plan' => $plan]);
        $real = app(CampaignMesh::class);
        $calls = [];
        $this->partialMock(CampaignMesh::class)->shouldReceive('assertDeparture')
            ->andReturnUsing(function ($episode, $agent, $ship, $destination, $ignoreRun = null, $yard = false) use ($real, &$calls) {
                $calls[] = $yard;
                $real->assertDeparture($episode, $agent, $ship, $destination, $ignoreRun, $yard);
            });
        $safety = app(CampaignSafety::class);
        if (! isset($movement['mesh_work'])) {
            $this->assertRefused(fn () => $safety->check($run, $this->agent, 'navigate', ['waypointSymbol' => $destination]),
                'Mesh pool scouts require governed campaign authority.');
            $this->assertSame([$yard], $calls);
        } else {
            $safety->check($run, $this->agent, 'orbit');
            $this->assertSame([$yard], $calls);
            $calls = [];
            $safety->check($run, $this->agent, 'navigate', ['waypointSymbol' => $destination]);
            $this->assertSame([$yard, $yard], $calls);
        }

        $calls = [];
        $this->resetToPreview($run);
        if (! isset($movement['mesh_work'])) {
            $this->assertRefused(fn () => $this->start($run), 'Mesh pool scouts require governed campaign authority.');
            $this->assertSame([], $calls);
        } else {
            $this->start($run);
            $this->assertSame([$yard], $calls);
            $this->assertSame(RunState::Running, $run->refresh()->state);
        }
    }

    #[TestWith(['scout', 'X1-TEST-PORT', 'X1-TEST-YARD'])]
    #[TestWith(['yard', 'X1-TEST-YARD', 'X1-TEST-PORT'])]
    public function test_invalid_destinations_rejected_at_all_three_departure_boundaries(string $work, string $valid, string $invalid): void
    {
        $episode = $this->fleetEpisode();
        $run = $this->launch($episode, $work, $work === 'yard' ? 'shipyard' : 'market', $valid);
        $run->update(['plan' => array_replace($run->plan, ['destination' => $invalid])]);
        $safety = app(CampaignSafety::class);
        $this->assertRefused(fn () => $safety->check($run, $this->agent, 'orbit'), 'Mesh movement authority changed');
        $this->assertRefused(fn () => $safety->check($run, $this->agent, 'navigate', ['waypointSymbol' => $invalid]), 'Mesh movement authority changed');
        $this->resetToPreview($run);
        $this->assertRefused(fn () => $this->start($run), 'Mesh movement authority changed');
    }

    public function test_occupancy_and_stationed_probe_semantics_for_market_vs_yard_moves(): void
    {
        $episode = $this->fleetEpisode();
        $target = 'X1-TEST-PORT';
        $point = collect($this->world()['waypoints'])->firstWhere('symbol', $target);
        $point['traits'][] = ['symbol' => 'SHIPYARD'];
        $sync = app(AgentSynchronizer::class);
        $sync->observe($this->agent, 'waypoint', $target, $point, 'listing');
        $sync->observe($this->agent, 'shipyard', $target, $this->world()['shipyards']['X1-TEST-YARD']);
        $run = $this->launch($episode, 'scout', 'market', $target);
        $safety = app(CampaignSafety::class);
        $safety->check($run, $this->agent, 'orbit');
        $safety->check($run, $this->agent, 'navigate', ['waypointSymbol' => $target]);
        $this->editFixture(fn (&$world) => $world['ships'][] = FakeSpaceTraders::probe('LOCAL-4', $target));
        $sync->sync($this->agent, false);
        $parked = Ship::where('symbol', 'LOCAL-4')->sole();

        $this->assertRefused(fn () => $safety->check($run, $this->agent, 'navigate', ['waypointSymbol' => $target]),
            'Mesh destination is occupied by a probe.');
        $parked->update(['stationed_waypoint' => $target]);
        $this->assertRefused(fn () => $safety->check($run, $this->agent, 'navigate', ['waypointSymbol' => $target]),
            'Mesh movement authority changed');
        $run->update(['plan' => array_replace($run->plan, ['mesh_work' => 'yard', 'observe_kind' => 'shipyard'])]);
        $safety->check($run, $this->agent, 'navigate', ['waypointSymbol' => $target]);

        $parked->update(['stationed_waypoint' => null]);
        $this->assertRefused(fn () => $safety->check($run, $this->agent, 'navigate', ['waypointSymbol' => $target]),
            'Mesh destination is occupied by a probe.');
    }

    public function test_v8_fleet_probe_purchaser_without_mesh_work_still_requires_a_yard(): void
    {
        $episode = $this->fleetEpisode();
        $target = 'X1-TEST-YARD';
        $run = $this->launch($episode, 'yard', 'shipyard', $target);
        $run->update(['strategy' => 'buy_fleet', 'plan' => ['shipyard' => $target]
            + array_diff_key($run->plan, ['mesh_work' => true, 'observe_kind' => true])]);
        $real = app(CampaignMesh::class);
        $this->partialMock(CampaignMesh::class)->shouldReceive('assertDeparture')
            ->once()->andReturnUsing(function ($episode, $agent, $ship, $destination, $ignoreRun, $yard) use ($real, $target) {
                $this->assertTrue($yard);
                $this->assertSame($target, $destination);
                $real->assertDeparture($episode, $agent, $ship, $destination, $ignoreRun, $yard);
            });

        app(CampaignSafety::class)->check($run, $this->agent, 'navigate', ['waypointSymbol' => $target]);
    }

    private function assertRefused(callable $command, string $message): void
    {
        try {
            $command();
            $this->fail('Expected the movement boundary to refuse this departure.');
        } catch (GameException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function resetToPreview(AutomationRun $run): void
    {
        $run->update(['state' => RunState::Preview, 'active_agent_id' => null, 'started_at' => null]);
    }

    private function start(AutomationRun $run): AutomationRun
    {
        return RequestBudget::forMeshAllowance($run->plan['mesh_allowance'],
            fn () => app(AutomationRunner::class)->start($this->agent, $run, deadline: microtime(true) + 10),
            $this->agent->name, $this->agent->reset_id, $run->id);
    }

    private function launch(CampaignEpisode $episode, string $work, string $observe, string $destination): AutomationRun
    {
        $agent = $this->agent->refresh();
        $director = app(CampaignDirector::class);
        $probe = Ship::where('symbol', 'LOCAL-2')->sole()->snapshot;
        $deadline = microtime(true) + 10;
        (new ReflectionProperty($director, 'deadline'))->setValue($director, $deadline);

        return app(HostLock::class)->scoped('agent-'.$agent->id,
            fn () => app(SpaceTradersClient::class)->scope(
                fn () => (new ReflectionMethod($director, 'launchMeshScout'))->invoke(
                    $director, $episode->grant(), $episode, $agent, $probe, $destination, $work, $observe, $deadline),
                deadline: $deadline));
    }

    private function fleetEpisode(): CampaignEpisode
    {
        $this->setUpSaturation(hubs: 2, remoteYard: true, seedCoverage: false);
        $this->editFixture(function (&$world) {
            $world['ships'][] = FakeSpaceTraders::probe('LOCAL-3', 'X1-TEST-ROCK');
            foreach ($world['waypoints'] as &$point) {
                if ($point['symbol'] === 'X1-TEST-YARD') {
                    $point['x'] = 4;
                }
            }
        });
        $sync = app(AgentSynchronizer::class);
        $sync->sync($this->agent, false);
        $world = $this->world();
        $sync->observe($this->agent, 'waypoint', 'X1-TEST-YARD', collect($world['waypoints'])->firstWhere('symbol', 'X1-TEST-YARD'), 'listing');
        $sync->observe($this->agent, 'shipyard', 'X1-TEST-YARD', $world['shipyards']['X1-TEST-YARD']);
        $body = Yaml::parseFile(base_path('campaigns/weekly-system-saturation-fleet.yaml'));
        $body['agent']['symbol'] = $this->agent->name;
        $body['fleet']['auto_arm']['revision_hash'] = $this->armRevision->content_hash;

        return $this->primary(revision: app(CampaignPlaybook::class)->import(Yaml::dump($body, 12)));
    }
}
