<?php

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\SpaceTradersApi;
use App\Models\Agent;
use App\Models\GameObservation;
use App\Models\Ship;
use App\Models\ShipAction;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeSpaceTraders;

// Owned synthetic browser fixture only. Never loads or resets a live database.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$owned = str_replace('\\', '/', realpath(storage_path('app/system-map-90')) ?: '');
$file = config('gameplay.fixture_path');
if (! $owned || ! str_starts_with(str_replace('\\', '/', realpath($file ?: '') ?: ''), $owned.'/')
    || ! str_starts_with(str_replace('\\', '/', realpath(config('database.connections.sqlite.database')) ?: ''), $owned.'/')
    || config('gameplay.local_engine_enabled') || config('gameplay.enabled')) {
    throw new RuntimeException('Only the owned offline map fixture with both engines disabled is allowed.');
}
if (in_array('--drain', $argv, true)) {
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'spacetraders', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1, '--timeout' => 120]);
    echo "Owned fixture queue drained.\n";
    exit;
}
$scenario = $argv[1] ?? 'travel';
if (! in_array($scenario, ['travel', 'scan', 'chart', 'no-sensor', 'cooldown', 'transit', 'error', '--expire', '--scene'], true)) {
    throw new RuntimeException('Unknown synthetic scenario.');
}
$agent = Agent::where('name', 'LOCAL')->firstOrFail();
if ($agent->account_id !== 'synthetic-account') {
    throw new RuntimeException('Synthetic agent required.');
}
if ($scenario === '--scene') {
    $symbol = $argv[2] ?? '';
    if (! preg_match('/^[A-Z0-9_-]+$/D', $symbol)) {
        throw new RuntimeException('A synthetic location symbol is required.');
    }
    $point = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'waypoint')->where('symbol', $symbol)->first()?->data;
    $ship = Ship::where('agent_id', $agent->id)->where('symbol', 'LOCAL-2')->firstOrFail();
    $snapshot = $ship->snapshot;
    $snapshot['nav']['status'] = ($argv[4] ?? '') === 'DOCKED' ? 'DOCKED' : 'IN_TRANSIT';
    $snapshot['nav']['waypointSymbol'] = $symbol;
    $snapshot['nav']['systemSymbol'] = 'X1-TEST';
    $snapshot['nav']['route']['destination'] = ['symbol' => $symbol, 'type' => $point['type'] ?? ($argv[3] ?? null)];
    $snapshot['nav']['route']['departureTime'] = '2019-12-31T23:00:00Z';
    $snapshot['nav']['route']['arrival'] = '2020-01-01T00:00:00Z';
    $ship->update(['snapshot' => $snapshot]);
    echo "Owned scene snapshot selected.\n";
    exit;
}
if ($scenario === '--expire') {
    ShipAction::where('agent_id', $agent->id)->where('state', 'preview')->update(['expires_at' => now()->subMinute()]);
    echo "Owned preview expired.\n";
    exit;
}
GameObservation::where('agent_id', $agent->id)->whereIn('kind', ['system', 'manual_read'])->delete();
GameObservation::where('agent_id', $agent->id)->where('kind', 'waypoint')->where('symbol', 'X1-TEST-ROCK')->delete();
$world = FakeSpaceTraders::world();
$ship = FakeSpaceTraders::ship('LOCAL-1', $scenario === 'chart' ? 'X1-TEST-ROCK' : 'X1-TEST-PORT');
if (! in_array($scenario, ['travel', 'no-sensor'], true)) {
    $ship['nav']['status'] = 'IN_ORBIT';
    $ship['mounts'][] = ['symbol' => 'MOUNT_SENSOR_ARRAY_I', 'requirements' => ['crew' => 1, 'power' => 1]];
}
if ($scenario === 'cooldown') {
    $ship['cooldown'] = ['remainingSeconds' => 300, 'expiration' => now()->addMinutes(5)->toIso8601String()];
}
if ($scenario === 'transit') {
    $ship['nav']['status'] = 'IN_TRANSIT';
    $ship['nav']['route']['arrival'] = now()->addMinutes(5)->toIso8601String();
    $ship['nav']['route']['origin'] = $world['waypoints'][0];
}
$world['ships'] = [$ship];
$world['waypoints'][0]['traits'] = [['symbol' => 'UNCHARTED']];
$world['scan_waypoints'] = [array_replace($world['waypoints'][0], ['traits' => [['symbol' => 'MINERAL_DEPOSITS', 'name' => 'Mineral deposits', 'description' => 'Synthetic scan revealed mineral deposits.']]])];
$world['chart_traits'] = [['symbol' => 'MINERAL_DEPOSITS']];
$world['cooldown_seconds'] = 120;
$world['travel_seconds'] = 120;
file_put_contents($file, json_encode($world));
ShipAction::where('agent_id', $agent->id)->delete();
DB::table('jobs')->delete();
$agent->update(['credits' => $world['credits']]);
Ship::where('agent_id', $agent->id)->where('symbol', 'LOCAL-1')->update(['snapshot' => app(SpaceTradersApi::class)->shipData($ship), 'observed_at' => now()]);
app(AgentSynchronizer::class)->observe($agent, 'waypoint', 'X1-TEST-ROCK', $world['waypoints'][0], 'fixture');
app(AgentSynchronizer::class)->observe($agent, 'waypoint', 'X1-TEST-PORT', $world['waypoints'][1], 'fixture');
app(AgentSynchronizer::class)->observe($agent, 'market', 'X1-TEST-PORT', ['symbol' => 'X1-TEST-PORT', 'tradeGoods' => $world['goods'], 'imports' => [], 'exports' => [], 'exchange' => [['symbol' => 'FUEL']]], 'fixture');
app(AgentSynchronizer::class)->observe($agent, 'market_listing', 'X1-TEST-PORT', ['imports' => [], 'exports' => [], 'exchange' => [['symbol' => 'FUEL']]], 'fixture');
echo "Owned synthetic {$scenario} scenario prepared.\n";
