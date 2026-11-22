<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Models\Agent;
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
                ->where('ships.0.display_name', 'Command Frigate #1')
                ->where('currentRun.ship', 'OFFLINE-1')
                ->where('currentRun.ship_name', 'Command Frigate #1'));

        $ship->update(['nickname' => 'Flagship Alpha']);
        $this->actingAs($operator)->get('/campaign')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ships.0.display_name', 'Flagship Alpha')
                ->where('currentRun.ship_name', 'Flagship Alpha'));
    }
}
