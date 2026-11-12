<?php

use App\Enums\ActionState;
use App\Gameplay\ShipCommandExecutor;
use App\Models\Ship;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (config('gameplay.local_engine_enabled') || config('gameplay.fixture_path') !== $directory.'/world.json') {
    throw new RuntimeException('Worker verification requires isolated offline practice with no engine.');
}
$agent = User::where('email', 'mvp-pilot@example.test')->sole()->agents()->sole();
$ship = Ship::where('agent_id', $agent->id)->where('symbol', 'LOCAL-1')->sole();
$operation = $ship->snapshot['nav']['status'] === 'IN_ORBIT' ? 'dock' : 'orbit';
$executor = app(ShipCommandExecutor::class);
$action = $executor->preview($agent, 'LOCAL-1', $operation, [], (string) Str::uuid());
$executor->approve($agent, $action);
$deadline = microtime(true) + 25;
do {
    usleep(250000);
    $action->refresh();
} while ($action->state !== ActionState::Confirmed && microtime(true) < $deadline);
if ($action->state !== ActionState::Confirmed || DB::table('jobs')->count() !== 0 || DB::table('failed_jobs')->count() !== 0) {
    throw new RuntimeException('Practice worker did not confirm the queued command and drain the queue.');
}
echo 'PASS separate database worker confirmed '.$operation.' action '.$action->id.'; jobs=0; failed_jobs=0; native engine=false.'.PHP_EOL;
