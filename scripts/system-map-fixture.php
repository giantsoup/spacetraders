<?php

use App\Models\Agent;
use App\Models\GameObservation;
use App\Models\Ship;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Run only after mvp-fixture.php created an owned fixture in this worktree.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$fixture = config('gameplay.fixture_path');
$owned = realpath(storage_path('app/system-map-90'));
if (! $owned || ! $fixture || ! str_starts_with(realpath($fixture) ?: '', $owned.'/')
    || ! str_starts_with(realpath(config('database.connections.sqlite.database')) ?: '', $owned.'/')) {
    throw new RuntimeException('System map fixtures require the owned system-map-90 database and fake transport.');
}
if (in_array('--audit', $argv, true)) {
    $tables = collect(['agents', 'ships', 'game_observations', 'observation_history', 'ship_actions', 'automation_runs', 'jobs'])
        ->mapWithKeys(fn ($table) => [$table => hash('sha256', DB::table($table)->orderBy('id')->get()->toJson())]);
    $world = json_decode(file_get_contents($fixture), true);
    echo json_encode(['game_table_hashes' => $tables, 'fake_transport_calls' => count($world['calls']), 'fake_gameplay_writes' => count($world['writes']),
        'world_hash' => hash_file('sha256', $fixture)], JSON_PRETTY_PRINT)."\n";
    exit;
}
$agent = Agent::firstOrFail();
$seed = function (string $kind, string $symbol, array $data, string $age = '-2 minutes') use ($agent) {
    GameObservation::updateOrCreate(['agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'kind' => $kind, 'symbol' => $symbol],
        ['data' => $data, 'observed_at' => now()->modify($age), 'source' => 'map_fixture']);
};
// Fully replace only the synthetic map knowledge. No live records are reachable.
GameObservation::where('agent_id', $agent->id)->whereIn('kind', ['waypoint', 'market', 'market_listing', 'shipyard', 'catalogue'])->delete();
$types = json_decode(file_get_contents(base_path('docs/api/spacetraders-openapi-2.3.0.json')), true)['components']['schemas']['WaypointType']['enum'];
foreach ($types as $index => $type) {
    $symbol = 'X1-TEST-'.($index === 0 ? 'PORT' : ($index === 5 ? 'ROCK' : str_replace('_', '', $type)));
    $seed('waypoint', $symbol, ['symbol' => $symbol, 'systemSymbol' => 'X1-TEST', 'type' => $type,
        'x' => (int) round(cos($index / count($types) * 2 * M_PI) * (180 + $index * 8)),
        'y' => (int) round(sin($index / count($types) * 2 * M_PI) * (150 + $index * 10)),
        'traits' => $index === 0 ? [['symbol' => 'OCEAN', 'name' => 'Ocean', 'description' => 'Deep blue oceans surround scattered archipelagos.'], ['symbol' => 'MARKETPLACE'], ['symbol' => 'SHIPYARD']]
            : ($index === 2 ? [] : [['symbol' => 'UNCHARTED']]),
        'faction' => $index === 0 ? ['symbol' => 'COSMIC'] : null,
        'orbitals' => [], 'modifiers' => [], 'isUnderConstruction' => false,
    ], $index === 0 ? '-2 days' : '-2 minutes');
}
foreach (['FROZEN', 'VOLCANIC', 'JUNGLE', 'MEGA_CITY'] as $index => $trait) {
    $symbol = 'X1-TEST-'.str_replace('_', '', $trait);
    $seed('waypoint', $symbol, ['symbol' => $symbol, 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => -120 + $index * 90, 'y' => -60,
        'traits' => [['symbol' => $trait, 'description' => ucfirst(strtolower(str_replace('_', ' ', $trait))).' terrain observed.']]]);
}
$seed('waypoint', 'X1-TEST-TWIN', ['symbol' => 'X1-TEST-TWIN', 'systemSymbol' => 'X1-TEST', 'type' => 'MOON', 'x' => 180, 'y' => 0, 'traits' => []]);
$seed('market_listing', 'X1-TEST-PORT', ['imports' => [['symbol' => 'FUEL']], 'exports' => [], 'exchange' => []]);
$seed('market', 'X1-TEST-PORT', ['tradeGoods' => [['symbol' => 'FUEL', 'purchasePrice' => 0, 'sellPrice' => 3, 'supply' => 'ABUNDANT', 'activity' => 'STRONG']], 'invalidated_by_action' => 1], '-2 days');
$seed('shipyard', 'X1-TEST-PORT', ['shipTypes' => [['type' => 'SHIP_MINING_DRONE']], 'ships' => [['type' => 'SHIP_MINING_DRONE', 'name' => 'Practice miner', 'purchasePrice' => 20000]]]);
$seed('market_listing', 'X1-TEST-MOON', ['imports' => [], 'exports' => [], 'exchange' => []]);
$seed('shipyard', 'X1-TEST-MOON', ['shipTypes' => [], 'ships' => []]);
$seed('catalogue', 'X1-TEST', ['complete' => false, 'total' => 40]);
$seed('manual_read', 'get-system-map-fixture', ['operation' => 'get-system', 'data' => ['symbol' => 'X1-TEST', 'type' => 'ORANGE_STAR', 'waypoints' => [['symbol' => 'X1-TEST-ARCHIVE', 'type' => 'MOON', 'x' => -30, 'y' => 30], ['symbol' => 'X1-TEST-UNPLOTTED', 'type' => 'NEBULA']]]]);
$base = Ship::where('agent_id', $agent->id)->firstOrFail();
$snapshot = $base->snapshot;
$snapshot['nav']['waypointSymbol'] = 'X1-TEST-PORT';
$base->update(['snapshot' => $snapshot, 'observed_at' => now()->subMinutes(2)]);
$snapshot['symbol'] = 'LOCAL-2';
$snapshot['nav']['status'] = 'IN_TRANSIT';
$snapshot['nav']['waypointSymbol'] = 'X1-TEST-PORT';
$snapshot['nav']['route']['origin'] = ['symbol' => 'X1-TEST-ROCK', 'type' => 'ASTEROID_FIELD', 'x' => 0, 'y' => 0];
$snapshot['nav']['route']['arrival'] = '2020-01-01T00:00:00Z';
Ship::updateOrCreate(['agent_id' => $agent->id, 'symbol' => 'LOCAL-2'], ['snapshot' => $snapshot, 'observed_at' => now()->subHours(4)]);
$snapshot['symbol'] = 'LOCAL-3';
$snapshot['nav'] = ['systemSymbol' => 'X2-BLANK', 'waypointSymbol' => 'X2-BLANK-A', 'status' => 'DOCKED'];
Ship::updateOrCreate(['agent_id' => $agent->id, 'symbol' => 'LOCAL-3'], ['snapshot' => $snapshot, 'observed_at' => now()]);
Agent::firstOrCreate(['user_id' => $agent->user_id, 'name' => 'EMPTY'], ['token' => 'synthetic-empty', 'account_id' => 'synthetic-empty', 'faction' => 'COSMIC', 'reset_id' => $agent->reset_id, 'connection_state' => 'connected']);
echo "System map synthetic knowledge seeded.\n";
