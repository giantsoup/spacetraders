<?php

namespace Tests\Feature;

use App\Maps\SystemMapKnowledge;
use App\Models\Agent;
use App\Models\GameObservation;
use App\Models\ObservationHistory;
use App\Models\Ship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SystemMapTest extends TestCase
{
    use RefreshDatabase;

    private function agent(?User $user = null): Agent
    {
        return ($user ?? User::factory()->create())->agents()->create(['name' => 'MAP', 'account_id' => 'synthetic', 'faction' => 'COSMIC', 'token' => 'synthetic-only', 'reset_id' => 'map-reset', 'connection_state' => 'connected']);
    }

    private function ship(Agent $agent, string $symbol = 'MAP-1', array $nav = []): Ship
    {
        return Ship::create(['agent_id' => $agent->id, 'symbol' => $symbol, 'snapshot' => ['nav' => $nav + ['systemSymbol' => 'X1-MAP', 'waypointSymbol' => 'X1-MAP-A', 'status' => 'DOCKED']], 'observed_at' => now()]);
    }

    private function observe(Agent $agent, string $kind, string $symbol, array $data, ?string $reset = null, string $age = '-1 minute'): GameObservation
    {
        return GameObservation::create(['agent_id' => $agent->id, 'reset_id' => $reset ?? $agent->reset_id, 'kind' => $kind, 'symbol' => $symbol, 'data' => $data, 'observed_at' => now()->modify($age), 'source' => 'fixture']);
    }

    public function test_map_requires_authentication_and_owner_and_rejects_other_agents_ships(): void
    {
        Http::preventStrayRequests();
        $agent = $this->agent();
        $this->get(route('agents.map', $agent))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('agents.map', $agent))->assertNotFound();
        $this->actingAs($agent->user)->get(route('agents.map', ['agent' => $agent, 'ship' => 'FOREIGN-1']))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_map_is_a_reset_scoped_read_with_no_gameplay_or_observation_writes(): void
    {
        Http::preventStrayRequests();
        $agent = $this->agent();
        $this->ship($agent);
        $this->ship($agent, 'MAP-2', ['systemSymbol' => 'X2-OTHER', 'waypointSymbol' => 'X2-OTHER-A']);
        $this->observe($agent, 'waypoint', 'X1-MAP-A', ['type' => 'PLANET', 'x' => 0, 'y' => -10, 'traits' => []]);
        $this->observe($agent, 'waypoint', 'X1-MAP-OLD', ['x' => 2, 'y' => 4], 'old-reset');
        $this->observe($this->agent(), 'waypoint', 'X1-MAP-PRIVATE', ['x' => 1, 'y' => 1]);
        $this->observe($agent, 'waypoint', 'X2-OTHER-A', ['x' => 1, 'y' => 1]);
        ObservationHistory::create(['agent_id' => $agent->id, 'reset_id' => 'old-reset', 'kind' => 'waypoint', 'symbol' => 'X1-MAP-HISTORY', 'data' => ['x' => 4, 'y' => 4], 'observed_at' => now(), 'source' => 'fixture']);
        $before = $this->gameTables();
        $this->actingAs($agent->user)->get(route('agents.map', $agent))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Agent/SystemMap')->where('map.system', 'X1-MAP')->has('map.locations', 1)
            ->where('map.locations.0.symbol', 'X1-MAP-A')->where('map.locations.0.x', 0)->where('map.locations.0.y', -10)
            ->where('map.locations.0.traits', [])->where('map.locations.0.market.presence', 'absent')->missing('agent.token'));
        $this->get(route('agents.map', ['agent' => $agent, 'ship' => 'MAP-2']))->assertInertia(fn (Assert $page) => $page->where('map.system', 'X2-OTHER'));
        $this->assertSame($before, $this->gameTables());
        Http::assertNothingSent();
    }

    public function test_manual_system_knowledge_preserves_richer_traits_and_unplotted_unknowns(): void
    {
        $agent = $this->agent();
        $ship = $this->ship($agent);
        $this->observe($agent, 'waypoint', 'X1-MAP-A', ['type' => 'PLANET', 'traits' => [['symbol' => 'FROZEN', 'description' => 'An icy landscape.']], 'traits_observed_at' => '2026-01-01T00:00:00Z'], age: '-2 days');
        $this->observe($agent, 'manual_read', 'get-system-saved', ['operation' => 'get-system', 'data' => ['symbol' => 'X1-MAP', 'type' => 'RED_STAR', 'waypoints' => [
            ['symbol' => 'X1-MAP-A', 'type' => 'PLANET', 'x' => 4, 'y' => -8],
            ['symbol' => 'X1-MAP-B', 'x' => '12', 'y' => null],
        ]]]);
        $this->observe($agent, 'waypoint', 'X1-MAP-C', ['x' => 0, 'y' => 0, 'traits' => [['symbol' => 'UNCHARTED']]]);
        $map = app(SystemMapKnowledge::class)->forShip($agent, $ship);
        $this->assertSame('RED_STAR', $map['system_info']['type']);
        $this->assertSame('FROZEN', $map['locations'][0]['traits'][0]['symbol']);
        $this->assertSame('2026-01-01T00:00:00Z', $map['locations'][0]['traits_observed_at']);
        $this->assertSame(4, $map['locations'][0]['x']);
        $this->assertNull($map['locations'][1]['x']);
        $this->assertNull($map['locations'][1]['traits']);
        $this->assertNull($map['locations'][2]['traits']);
        $this->assertNull($map['catalogue']);
    }

    public function test_directory_empty_unknown_quotes_and_old_invalidated_prices_keep_distinct_ages(): void
    {
        $agent = $this->agent();
        $ship = $this->ship($agent);
        $this->observe($agent, 'market', 'X1-MAP-A', ['tradeGoods' => [['symbol' => 'FUEL', 'purchasePrice' => 0, 'sellPrice' => 3]], 'invalidated_by_action' => 7], age: '-2 days');
        $this->observe($agent, 'market_listing', 'X1-MAP-A', ['imports' => [], 'exports' => [], 'exchange' => []]);
        $this->observe($agent, 'shipyard', 'X1-MAP-A', ['shipTypes' => [], 'ships' => []]);
        $this->observe($agent, 'catalogue', 'X1-MAP', ['complete' => false, 'total' => 20]);
        $map = app(SystemMapKnowledge::class)->forShip($agent, $ship);
        $point = $map['locations'][0];
        $this->assertSame('observed', $point['market']['presence']);
        $this->assertSame([], $point['market']['directory']['data']['imports']);
        $this->assertSame(0, $point['market']['prices']['data']['tradeGoods'][0]['purchasePrice']);
        $this->assertNotSame($point['market']['prices']['observed_at'], $point['market']['directory']['observed_at']);
        $this->assertSame(7, $point['market']['prices']['data']['invalidated_by_action']);
        $this->assertSame([], $point['shipyard']['offers']['data']['shipTypes']);
        $this->assertFalse($map['catalogue']['complete']);
    }

    public function test_empty_fleet_and_in_transit_never_confirm_destination_even_after_arrival_time(): void
    {
        $agent = $this->agent();
        $this->actingAs($agent->user)->get(route('agents.map', $agent))->assertOk()->assertInertia(fn (Assert $page) => $page->where('map.system', null)->has('map.locations', 0));
        $ship = $this->ship($agent, nav: ['status' => 'IN_TRANSIT', 'waypointSymbol' => 'X1-MAP-B', 'route' => ['origin' => ['symbol' => 'X1-MAP-A', 'x' => 0, 'y' => 1], 'arrival' => '2020-01-01T00:00:00Z']]);
        $map = app(SystemMapKnowledge::class)->forShip($agent, $ship);
        $this->assertTrue($map['ship']['in_transit']);
        $this->assertSame('X1-MAP-A', $map['ship']['position']);
        $this->assertSame('X1-MAP-B', $map['ship']['waypoint']);
        $this->assertCount(1, $map['locations']);
    }

    public function test_service_only_locations_and_null_traits_remain_unknown_and_unplotted(): void
    {
        $agent = $this->agent();
        $ship = $this->ship($agent);
        $this->observe($agent, 'waypoint', 'X1-MAP-A', ['traits' => null, 'x' => 0, 'y' => 0]);
        $this->observe($agent, 'market_listing', 'X1-MAP-SERVICE', ['imports' => [], 'exports' => [], 'exchange' => []]);
        $this->observe($agent, 'catalogue', 'X1-MAP', ['complete' => true]);
        $map = app(SystemMapKnowledge::class)->forShip($agent, $ship);
        $this->assertNull($map['locations'][0]['traits']);
        $this->assertNull($map['locations'][1]['x']);
        $this->assertSame('X1-MAP-SERVICE', $map['locations'][1]['symbol']);
        $this->assertSame('observed', $map['locations'][1]['market']['presence']);
        $this->assertNull($map['locations'][1]['market']['prices']);
        $this->assertTrue($map['catalogue']['complete']);
    }

    private function gameTables(): array
    {
        return collect(['agents', 'ships', 'game_observations', 'observation_history', 'ship_actions', 'automation_runs', 'jobs'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
    }
}
