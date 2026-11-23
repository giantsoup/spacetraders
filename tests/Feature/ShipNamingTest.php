<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\ShipPurpose;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\Ship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CampaignStatusFixture;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class ShipNamingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_semantic_names_cover_common_frames_roles_and_minimal_fallbacks(): void
    {
        $this->assertSame('Command Frigate #1', Ship::semanticName('GIANTSOUP-1', [
            'frame' => ['symbol' => 'FRAME_FRIGATE', 'name' => 'Frigate'],
            'registration' => ['role' => 'COMMAND'],
        ]));
        $this->assertSame('Probe #2', Ship::semanticName('GIANTSOUP-2', [
            'frame' => ['symbol' => 'FRAME_PROBE', 'name' => 'Probe'],
            'registration' => ['role' => 'SATELLITE'],
        ]));
        $this->assertSame('Light Hauler #5', Ship::semanticName('GIANTSOUP-5', [
            'frame' => ['symbol' => 'FRAME_LIGHT_FREIGHTER', 'name' => 'Light Freighter'],
            'registration' => ['role' => 'HAULER'],
        ]));
        $this->assertSame('Light Shuttle #6', Ship::semanticName('GIANTSOUP-6', [
            'frame' => ['symbol' => 'FRAME_SHUTTLE', 'name' => 'Shuttle'],
            'registration' => ['role' => 'TRANSPORT'],
        ]));
        $this->assertSame('Mining Drone #7', Ship::semanticName('GIANTSOUP-7', [
            'frame' => ['symbol' => 'FRAME_DRONE', 'name' => 'Drone'],
            'registration' => ['role' => 'EXCAVATOR'],
        ]));
        $this->assertSame('Surveyor Drone #8', Ship::semanticName('GIANTSOUP-8', [
            'frame' => ['symbol' => 'FRAME_DRONE', 'name' => 'Drone'],
            'registration' => ['role' => 'SURVEYOR'],
        ]));
        $this->assertSame('Siphon Drone #9', Ship::semanticName('GIANTSOUP-9', [
            'frame' => ['symbol' => 'FRAME_DRONE', 'name' => 'Drone'],
            'registration' => ['role' => 'EXCAVATOR'],
            'mounts' => [['symbol' => 'MOUNT_GAS_SIPHON_I']],
        ]));
        $this->assertSame('LOCAL-2', Ship::semanticName('LOCAL-2', [
            'nav' => ['systemSymbol' => 'X1-MAP', 'waypointSymbol' => 'X1-MAP-A', 'status' => 'DOCKED'],
        ]));
    }

    public function test_owner_can_rename_ship_reset_nickname_and_sync_preserves_custom_name(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'st-ship-names-');
        $world = FakeSpaceTraders::world();
        $world['ships'][0]['symbol'] = 'LOCAL-1';
        $world['ships'][0]['registration']['role'] = 'COMMAND';
        $world['ships'][0]['frame']['symbol'] = 'FRAME_FRIGATE';
        $world['ships'][0]['frame']['name'] = 'Frigate';
        file_put_contents($file, json_encode($world, JSON_THROW_ON_ERROR));
        FakeSpaceTraders::install($file);

        try {
            $owner = User::factory()->create();
            $other = User::factory()->create();
            $agent = Agent::create([
                'user_id' => $owner->id,
                'name' => 'LOCAL',
                'token' => 'synthetic-only',
                'faction' => 'VOID',
                'reset_id' => $world['reset'],
                'canonical_identity' => AgentSynchronizer::identity($world['reset'], 'LOCAL'),
                'connection_state' => 'connected',
            ]);
            app(AgentSynchronizer::class)->sync($agent);

            $ship = Ship::where('agent_id', $agent->id)->where('symbol', 'LOCAL-1')->firstOrFail();
            $this->assertSame('Command Frigate #1', $ship->displayName());

            // Non-owner is refused.
            $this->actingAs($other)
                ->patchJson(route('game.ships.nickname', [$agent, 'LOCAL-1']), ['nickname' => 'Stolen'])
                ->assertNotFound();

            // Owner renames ship locally.
            $this->actingAs($owner)
                ->patchJson(route('game.ships.nickname', [$agent, 'LOCAL-1']), ['nickname' => '  Main Trader  '])
                ->assertOk()
                ->assertJson([
                    'symbol' => 'LOCAL-1',
                    'nickname' => 'Main Trader',
                    'display_name' => 'Main Trader',
                ]);
            $this->assertSame('Main Trader', $ship->fresh()->displayName());

            // SpaceTraders fleet sync must preserve the custom local nickname.
            app(AgentSynchronizer::class)->sync($agent->fresh());
            $this->assertSame('Main Trader', $ship->fresh()->nickname);
            $this->assertSame('Main Trader', $ship->fresh()->displayName());

            // Dashboard and SystemMap both expose display_name.
            $this->actingAs($owner)->get(route('agents.show', $agent))->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('ships.0.symbol', 'LOCAL-1')
                    ->where('ships.0.display_name', 'Main Trader')
                    ->where('ships.0.nickname', 'Main Trader'));
            $this->actingAs($owner)->get(route('agents.map', $agent))->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('ships.0.symbol', 'LOCAL-1')
                    ->where('ships.0.display_name', 'Main Trader'));

            // Clearing nickname reverts to automatic semantic name.
            $this->actingAs($owner)
                ->patchJson(route('game.ships.nickname', [$agent, 'LOCAL-1']), ['nickname' => '   '])
                ->assertOk()
                ->assertJson([
                    'symbol' => 'LOCAL-1',
                    'nickname' => null,
                    'display_name' => 'Command Frigate #1',
                ]);
            $this->assertNull($ship->fresh()->nickname);
            $this->assertSame('Command Frigate #1', $ship->fresh()->displayName());
        } finally {
            @unlink($file);
        }
    }

    public function test_campaign_status_includes_semantic_and_custom_ship_names(): void
    {
        Http::preventStrayRequests();
        $operator = User::factory()->create();
        config(['gameplay.operator_id' => (string) $operator->id]);
        ['ship' => $ship] = CampaignStatusFixture::seed($operator);
        $snapshot = $ship->snapshot;
        $snapshot['frame'] = ['symbol' => 'FRAME_FRIGATE', 'name' => 'Frigate'];
        $snapshot['registration']['role'] = 'COMMAND';
        $ship->update(['snapshot' => $snapshot]);

        $this->actingAs($operator)->get('/campaign')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ships.0.symbol', 'OFFLINE-1')
                ->where('ships.0.display_name', 'Command Frigate #1 · Main Trader: Iron Ore (SOURCE→BUYER)')
                ->where('ships.0.purpose', 'Main Trader: Iron Ore (SOURCE→BUYER)')
                ->where('currentRun.ship', 'OFFLINE-1')
                ->where('currentRun.ship_name', 'Command Frigate #1 · Main Trader: Iron Ore (SOURCE→BUYER)'));

        $ship->update(['nickname' => 'Flagship Alpha']);
        $this->actingAs($operator)->get('/campaign')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ships.0.display_name', 'Flagship Alpha · Main Trader: Iron Ore (SOURCE→BUYER)')
                ->where('currentRun.ship_name', 'Flagship Alpha · Main Trader: Iron Ore (SOURCE→BUYER)'));
    }

    public function test_ship_purpose_distinguishes_probes_active_haulers_and_standby_ships(): void
    {
        Http::preventStrayRequests();
        $operator = User::factory()->create();
        config(['gameplay.operator_id' => (string) $operator->id]);
        ['agent' => $agent, 'episode' => $episode, 'run' => $run] = CampaignStatusFixture::seed($operator);

        $run->update(['plan' => array_merge($run->plan, [
            'symbol' => 'ADVANCED_CIRCUITRY',
            'site' => 'X1-SK70-D43',
            'market' => 'X1-SK70-A4',
        ])]);

        // Probe #2 parked at active buyer X1-SK70-A4 -> Buyer Watch (A4).
        $probe2 = Ship::create([
            'agent_id' => $agent->id,
            'symbol' => 'OFFLINE-2',
            'observed_at' => now(),
            'snapshot' => [
                'symbol' => 'OFFLINE-2',
                'registration' => ['role' => 'SATELLITE'],
                'frame' => ['symbol' => 'FRAME_PROBE', 'name' => 'Probe'],
                'nav' => ['systemSymbol' => 'X1-SK70', 'waypointSymbol' => 'X1-SK70-A4', 'status' => 'IN_ORBIT'],
                'fuel' => ['current' => 0, 'capacity' => 0],
                'cargo' => ['units' => 0, 'capacity' => 0, 'inventory' => []],
            ],
        ]);
        // Probe #3 roving in transit to X1-SK70-DX5D -> Market Scout → DX5D.
        $probe3 = Ship::create([
            'agent_id' => $agent->id,
            'symbol' => 'OFFLINE-3',
            'observed_at' => now(),
            'snapshot' => [
                'symbol' => 'OFFLINE-3',
                'registration' => ['role' => 'SATELLITE'],
                'frame' => ['symbol' => 'FRAME_PROBE', 'name' => 'Probe'],
                'nav' => [
                    'systemSymbol' => 'X1-SK70',
                    'waypointSymbol' => 'X1-SK70-DX5D',
                    'status' => 'IN_TRANSIT',
                    'route' => ['destination' => ['symbol' => 'X1-SK70-DX5D']],
                ],
                'fuel' => ['current' => 0, 'capacity' => 0],
                'cargo' => ['units' => 0, 'capacity' => 0, 'inventory' => []],
            ],
        ]);
        // Light Hauler #5 armed as H1 actively trading MEDICINE D42 -> D45.
        $hauler = Ship::create([
            'agent_id' => $agent->id,
            'symbol' => 'OFFLINE-5',
            'observed_at' => now(),
            'snapshot' => [
                'symbol' => 'OFFLINE-5',
                'registration' => ['role' => 'HAULER'],
                'frame' => ['symbol' => 'FRAME_LIGHT_FREIGHTER', 'name' => 'Light Freighter'],
                'nav' => ['systemSymbol' => 'X1-SK70', 'waypointSymbol' => 'X1-SK70-D42', 'status' => 'DOCKED'],
                'fuel' => ['current' => 400, 'capacity' => 400],
                'cargo' => ['units' => 0, 'capacity' => 80, 'inventory' => []],
            ],
        ]);
        // Light Shuttle #6 bought but not armed yet -> Standby.
        $shuttle = Ship::create([
            'agent_id' => $agent->id,
            'symbol' => 'OFFLINE-6',
            'observed_at' => now(),
            'snapshot' => [
                'symbol' => 'OFFLINE-6',
                'registration' => ['role' => 'TRANSPORT'],
                'frame' => ['symbol' => 'FRAME_SHUTTLE', 'name' => 'Shuttle'],
                'nav' => ['systemSymbol' => 'X1-SK70', 'waypointSymbol' => 'X1-SK70-A2', 'status' => 'DOCKED'],
                'fuel' => ['current' => 300, 'capacity' => 300],
                'cargo' => ['units' => 0, 'capacity' => 40, 'inventory' => []],
            ],
        ]);

        // Replace with a pool-enabled grant + episode so OFFLINE-2 and OFFLINE-3 are pool probes.
        $poolRevision = app(CampaignPlaybook::class)->import(
            str_replace('GIANTSOUP', $agent->name, file_get_contents(base_path('campaigns/weekly-probe-bootstrap.yaml')))
        );
        $episode->grant()->update(['slot' => null, 'revoked_at' => now()]);
        $episode->update(['closed_at' => now()]);
        $poolGrant = app(CampaignControl::class)->approve($poolRevision, $operator);
        $poolEpisode = CampaignEpisode::create([
            'campaign_grant_id' => $poolGrant->id,
            'agent_id' => $agent->id,
            'reset_id' => $agent->reset_id,
            'start_credits' => 400000,
            'phase' => 'earn',
            'opened_at' => now(),
            'next_reset_at' => now()->addDays(5),
            'state' => ['role_ships' => ['command' => 'OFFLINE-1', 'probe' => 'OFFLINE-2']],
        ]);
        $run->update(['campaign_episode_id' => $poolEpisode->id]);
        $commandShip = Ship::where('agent_id', $agent->id)->where('symbol', 'OFFLINE-1')->firstOrFail();
        $cmdSnap = $commandShip->snapshot;
        $cmdSnap['nav']['systemSymbol'] = 'X1-SK70';
        $commandShip->update(['snapshot' => $cmdSnap]);

        // Create live arm grant H1 for OFFLINE-5 + active trade run.
        $armGrant = CampaignGrant::create([
            'campaign_revision_id' => $poolRevision->id,
            'revision_hash' => $poolRevision->content_hash,
            'user_id' => $operator->id,
            'slot' => null,
            'limits' => ['reserve_credits' => 0, 'run_max_minutes' => 15, 'arm' => ['label' => 'H1', 'ship' => 'OFFLINE-5']],
            'expires_at' => now()->addDays(2),
        ]);
        $armEpisode = CampaignEpisode::create([
            'campaign_grant_id' => $armGrant->id,
            'agent_id' => $agent->id,
            'reset_id' => $agent->reset_id,
            'start_credits' => 400000,
            'phase' => 'earn',
            'opened_at' => now(),
            'next_reset_at' => now()->addDays(5),
            'state' => ['arm' => 'H1', 'role_ships' => ['command' => 'OFFLINE-5']],
        ]);
        AutomationRun::create([
            'agent_id' => $agent->id,
            'active_agent_id' => $agent->id,
            'campaign_episode_id' => $armEpisode->id,
            'reset_id' => $agent->reset_id,
            'ship_symbol' => 'OFFLINE-5',
            'strategy' => 'trade',
            'plan_version' => 3,
            'state' => 'running',
            'started_at' => now(),
            'expires_at' => now()->addMinutes(15),
            'plan' => ['strategy' => 'trade', 'symbol' => 'MEDICINE', 'site' => 'X1-SK70-D42', 'market' => 'X1-SK70-D45', 'units' => 80],
            'totals' => ['phase' => 'buy'],
        ]);

        $ships = Ship::where('agent_id', $agent->id)->orderBy('symbol')->get();
        $purposes = app(ShipPurpose::class)->forAgent($agent, $ships);

        $this->assertSame('Buyer Watch (A4)', $purposes['OFFLINE-2']);
        $this->assertSame('Probe #2 · Buyer Watch (A4)', $probe2->displayName($purposes['OFFLINE-2']));
        $this->assertSame('Market Scout → DX5D', $purposes['OFFLINE-3']);
        $this->assertSame('Probe #3 · Market Scout → DX5D', $probe3->displayName($purposes['OFFLINE-3']));
        $this->assertSame('H1: Medicine (D42→D45)', $purposes['OFFLINE-5']);
        $this->assertSame('Light Hauler #5 · H1: Medicine (D42→D45)', $hauler->displayName($purposes['OFFLINE-5']));
        $this->assertSame('Standby', $purposes['OFFLINE-6']);
        $this->assertSame('Light Shuttle #6 · Standby', $shuttle->displayName($purposes['OFFLINE-6']));

        // Stationing a probe at a JUMP_GATE renders Gate Watch (and en route when not there yet).
        GameObservation::create([
            'agent_id' => $agent->id,
            'reset_id' => $agent->reset_id,
            'kind' => 'waypoint',
            'symbol' => 'X1-SK70-I56',
            'source' => 'read',
            'observed_at' => now(),
            'data' => ['symbol' => 'X1-SK70-I56', 'type' => 'JUMP_GATE', 'systemSymbol' => 'X1-SK70'],
        ]);
        $probe3->update(['stationed_waypoint' => 'X1-SK70-I56']);
        $ships = Ship::where('agent_id', $agent->id)->orderBy('symbol')->get();
        $purposes = app(ShipPurpose::class)->forAgent($agent, $ships);
        $this->assertSame('Gate Watch → I56', $purposes['OFFLINE-3']);

        $snap3 = $probe3->snapshot;
        $snap3['nav']['status'] = 'IN_ORBIT';
        $snap3['nav']['waypointSymbol'] = 'X1-SK70-I56';
        $probe3->update(['snapshot' => $snap3]);
        $ships = Ship::where('agent_id', $agent->id)->orderBy('symbol')->get();
        $purposes = app(ShipPurpose::class)->forAgent($agent, $ships);
        $this->assertSame('Gate Watch (I56)', $purposes['OFFLINE-3']);
        $this->assertSame('Probe #3 · Gate Watch (I56)', $probe3->displayName($purposes['OFFLINE-3']));
    }
}
