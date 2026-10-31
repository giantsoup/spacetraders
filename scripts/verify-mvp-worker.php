<?php

require __DIR__.'/../vendor/autoload.php';

use App\Enums\ActionState;
use App\Enums\RunState;
use App\Gameplay\AgentSynchronizer;
use App\Gameplay\MiningRunner;
use App\Gameplay\ShipCommandExecutor;
use App\Jobs\ExecuteShipCommand;
use App\Models\AutomationRun;
use App\Models\ShipAction;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeSpaceTraders;

$root = dirname(__DIR__);
$directory = sys_get_temp_dir().'/spacetraders-worker-'.bin2hex(random_bytes(6));
mkdir($directory, 0700);
$file = $directory.'/world.json';
$world = FakeSpaceTraders::world();
$world['verify_worker_storage'] = true;
file_put_contents($file, json_encode($world));
touch($directory.'/database.sqlite');
$env = [
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $directory.'/database.sqlite', 'DATABASE_URL' => '',
    'CACHE_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'database', 'SPACETRADERS_FIXTURE_PATH' => $file, 'SPACETRADERS_AUTOMATION_ENABLED' => 'true',
    'SPACETRADERS_LOCK_PATH' => $directory.'/locks', 'APP_CONFIG_CACHE' => $directory.'/no-config.php',
];
foreach ($env as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Artisan::call('migrate', ['--force' => true]);
$user = User::factory()->create();
$agent = $user->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic-only', 'account_id' => 'synthetic-account', 'faction' => 'COSMIC',
    'reset_id' => '2026-10-25', 'canonical_identity' => AgentSynchronizer::identity('2026-10-25', 'LOCAL')]);
app(AgentSynchronizer::class)->sync($agent);
function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS '.$message."\n";
}
function workers(string $root, array $env, string $directory): void
{
    $children = [];
    for ($i = 0; $i < 2; $i++) {
        $children[] = proc_open([PHP_BINARY, 'artisan', 'queue:work', 'database', '--queue=spacetraders', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout=120'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory.'/worker-'.$i.'.log', 'a'], 2 => ['file', $directory.'/worker-'.$i.'.log', 'a']], $pipes, $root, array_merge(getenv(), $env));
    }
    foreach ($children as $child) {
        check(proc_close($child) === 0, 'real database worker exited successfully');
    }
}
check((int) DB::selectOne('PRAGMA synchronous')->synchronous === 2, 'file-backed SQLite commits use FULL synchronization');
check((int) DB::selectOne('PRAGMA fullfsync')->fullfsync === 1, 'SQLite enables macOS fullfsync support');
$executor = app(ShipCommandExecutor::class);
$action = $executor->preview($agent, 'LOCAL-1', 'orbit', [], (string) Str::uuid());
$executor->approve($agent, $action);
ExecuteShipCommand::dispatch($action->id); // deliberately duplicate delivery to another worker
workers($root, $env, $directory);
$workerStorage = json_decode(file_get_contents($file), true)['worker_storage'];
check($workerStorage['pid'] !== getmypid() && $workerStorage['synchronous'] === 2 && $workerStorage['fullfsync'] === 1, 'separate queue worker uses FULL and macOS fullfsync');
check($action->fresh()->state === ActionState::Confirmed, 'persisted manual intent confirmed by real worker');
check(count(json_decode(file_get_contents($file), true)['writes']) === 1, 'two workers plus duplicate delivery caused exactly one simulated mutation');
$run = app(MiningRunner::class)->preview($agent->fresh(), 'LOCAL-1', ['site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-ROCK', 'reserve' => 2000, 'max_spend' => 100, 'max_loss' => 100, 'minutes' => 60, 'cycles' => 1]);
app(MiningRunner::class)->start($agent, $run);
for ($i = 0; $i < 12 && $run->fresh()->active_agent_id; $i++) {
    AutomationRun::whereKey($run->id)->update(['next_action_at' => now(), 'last_dispatched_at' => null]);
    Artisan::call('spacetraders:dispatch-due');
    workers($root, $env, $directory);
}
$run->refresh();
check($run->state === RunState::Completed, 'full mining trip completed across real worker process restarts');
check($run->totals['extracted'] === $run->totals['sold'] && $run->totals['cash_contribution'] === 240, 'cargo and confirmed credit outcomes persisted');
// Kill a real worker after the fake upstream committed a purchase and before local confirmation.
$crash = $executor->preview($agent->fresh(), 'LOCAL-1', 'buy', ['symbol' => 'IRON_ORE', 'units' => 1, 'maxTotal' => 30], (string) Str::uuid());
$executor->approve($agent, $crash);
$world = json_decode(file_get_contents($file), true);
$world['failure'] = 'pause_after';
file_put_contents($file, json_encode($world));
$child = proc_open([PHP_BINARY, 'artisan', 'queue:work', 'database', '--queue=spacetraders', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout=120'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory.'/killed-worker.log', 'a'], 2 => ['file', $directory.'/killed-worker.log', 'a']], $pipes, $root, array_merge(getenv(), $env));
$deadline = microtime(true) + 5;
while (! is_file($file.'.sent') && microtime(true) < $deadline) {
    usleep(20000);
}
check(is_file($file.'.sent'), 'real worker reached upstream-commit/local-confirmation crash window');
proc_terminate($child, 9);
proc_close($child);
$world = json_decode(file_get_contents($file), true);
$world['failure'] = null;
file_put_contents($file, json_encode($world));
check($crash->fresh()->state === ActionState::Sending, 'SIGKILL left durable sending intent and released OS ownership');
$crash->update(['sent_at' => now()->subMinutes(4)]);
DB::table('jobs')->whereNotNull('reserved_at')->update(['reserved_at' => time() - 200, 'attempts' => 0]);
Artisan::call('spacetraders:dispatch-due');
ExecuteShipCommand::dispatch($crash->id);
$committed = count($world['writes']);
workers($root, $env, $directory);
check($crash->fresh()->state === ActionState::Uncertain, 'killed sender becomes uncertain rather than pending');
check(count(json_decode(file_get_contents($file), true)['writes']) === $committed, 'restart never repeats the purchase whose response was lost');
$executor->reconcile($agent, $crash);
$crash->update(['state' => 'rejected', 'message' => 'Synthetic operator acknowledged cargo adjustment.']);
// Restore empty fixture cargo for subsequent stop checks, explicitly outside gameplay evidence.
$world = json_decode(file_get_contents($file), true);
$world['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 0, 'inventory' => []];
file_put_contents($file, json_encode($world));
app(AgentSynchronizer::class)->sync($agent);
// Recover an abandoned sender, then ensure a new worker cannot replay it.
$dead = ShipAction::create(['agent_id' => $agent->id, 'key' => (string) Str::uuid(), 'reset_id' => $agent->reset_id, 'ship_symbol' => 'LOCAL-1', 'origin' => 'manual', 'command' => 'mine', 'arguments' => [], 'state' => 'sending', 'sent_at' => now()->subMinutes(4), 'expires_at' => now()->addMinute()]);
$before = count(json_decode(file_get_contents($file), true)['writes']);
Artisan::call('spacetraders:dispatch-due');
ExecuteShipCommand::dispatch($dead->id);
workers($root, $env, $directory);
check($dead->fresh()->state === ActionState::Uncertain, 'abandoned sending intent becomes uncertain after restart');
check(count(json_decode(file_get_contents($file), true)['writes']) === $before, 'uncertain intent is not replayed by a real worker');
$executor->reconcile($agent, $dead);
$dead->update(['state' => 'rejected', 'message' => 'Synthetic operator acknowledged unresolved sender.']);
// Durable Stop with no running worker.
$stopped = app(MiningRunner::class)->preview($agent->fresh(), 'LOCAL-1', ['site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-ROCK', 'reserve' => 2000, 'max_spend' => 100, 'max_loss' => 100, 'minutes' => 60, 'cycles' => 1]);
app(MiningRunner::class)->start($agent, $stopped);
app(MiningRunner::class)->stop($stopped);
workers($root, $env, $directory);
check($stopped->fresh()->state === RunState::Stopped, 'offline Stop survives until the next real worker starts');
check(count(json_decode(file_get_contents($file), true)['writes']) === $before, 'offline Stop starts no additional mutation');
check(DB::table('failed_jobs')->count() === 0, 'no failed jobs in disposable queue');
echo 'Owned disposable evidence: '.$directory."\n";
