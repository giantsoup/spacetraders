<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignMesh;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\HostLock;
use App\Models\CampaignEpisode;
use App\Models\GameObservation;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Services\SpaceTradersClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\FakeSpaceTraders;
use Tests\Support\SaturationFixture;
use Tests\TestCase;

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
        $director = app(CampaignDirector::class);
        $probe = Ship::where('symbol', 'LOCAL-2')->sole()->snapshot;
        $deadline = microtime(true) + 10;
        (new ReflectionProperty($director, 'deadline'))->setValue($director, $deadline);
        $run = app(HostLock::class)->scoped('agent-'.$agent->id,
            fn () => app(SpaceTradersClient::class)->scope(
                fn () => (new ReflectionMethod($director, 'launchMeshScout'))->invoke(
                    $director, $episode->grant(), $episode, $agent, $probe, $destination, $work, $observe, $deadline),
                deadline: $deadline));
        $mesh = app(CampaignMesh::class);
        $seesObserver = fn () => $mesh->hasRecoveryObserver($episode, $agent, $mesh->model($episode, $agent),
            $mesh->probes($episode, $agent), $destination, 900);
        $this->assertSame($observe === 'market', $seesObserver());
        $plan = $run->plan;
        $run->update(['plan' => array_diff_key($plan, ['mesh_work' => true])]);
        $this->assertFalse($seesObserver());
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
