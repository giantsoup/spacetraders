<?php

require __DIR__.'/../vendor/autoload.php';

use App\Enums\ActionState;
use App\Gameplay\AgentSynchronizer;
use App\Gameplay\ShipCommandExecutor;
use App\Jobs\ExecuteShipCommand;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeSpaceTraders;

$root = dirname(__DIR__);
$directory = sys_get_temp_dir().'/spacetraders-foundation-worker-'.bin2hex(random_bytes(6));
mkdir($directory, 0700);
$file = $directory.'/world.json';
$world = FakeSpaceTraders::world() + ['foundation' => true];
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

function saveWorld(string $file, array $changes): void
{
    $world = json_decode(file_get_contents($file), true);
    file_put_contents($file, json_encode(array_merge($world, $changes)));
}
function startWorker(string $root, array $env, string $directory)
{
    return proc_open([PHP_BINARY, 'artisan', 'queue:work', 'database', '--queue=spacetraders', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout=120'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory.'/race-worker.log', 'a'], 2 => ['file', $directory.'/race-worker.log', 'a']], $pipes, $root, array_merge(getenv(), $env));
}
function reached(string $marker): void
{
    $deadline = microtime(true) + 5;
    while (! is_file($marker) && microtime(true) < $deadline) {
        usleep(20000);
    }
    check(is_file($marker), 'separate worker reached '.basename($marker));
}
$before = $executor->preview($agent, 'LOCAL-1', 'orbit', [], (string) Str::uuid());
$executor->approve($agent, $before);
saveWorld($file, ['pause_before_reservation' => true]);
$worker = startWorker($root, $env, $directory);
reached($file.'.before');
$executor->stopManual($before); // Independent database transaction while sender owns the agent host lock.
file_put_contents($file.'.resume-before', 'resume owned fixture');
check(proc_close($worker) === 0, 'worker exits after Stop wins reservation race');
check($before->fresh()->state === ActionState::Rejected, 'manual Stop wins before reservation');
check(count(json_decode(file_get_contents($file), true)['writes']) === 0, 'Stop/preflight race sends no write');

$after = $executor->preview($agent, 'LOCAL-1', 'flight_mode', ['flightMode' => 'BURN'], (string) Str::uuid());
$executor->approve($agent, $after);
saveWorld($file, ['pause_after_reservation' => true]);
$worker = startWorker($root, $env, $directory);
reached($file.'.after');
$executor->stopManual($after);
check($after->fresh()->state === ActionState::Sending, 'Stop after reservation leaves sending visible');
file_put_contents($file.'.resume-after', 'resume owned fixture');
check(proc_close($worker) === 0, 'reserved PATCH worker settles after Stop');
check($after->fresh()->state === ActionState::Confirmed && ! empty($after->fresh()->outcome['stop_requested_at']), 'confirmed PATCH retains post-reservation Stop evidence');
check(count(json_decode(file_get_contents($file), true)['writes']) === 1, 'post-reservation Stop causes one write and no follow-up');

$duplicate = $executor->preview($agent, 'LOCAL-1', 'flight_mode', ['flightMode' => 'CRUISE'], (string) Str::uuid());
$executor->approve($agent, $duplicate);
ExecuteShipCommand::dispatch($duplicate->id);
workers($root, $env, $directory);
check($duplicate->fresh()->state === ActionState::Confirmed, 'PATCH survives separate workers and duplicate delivery');
check(count(json_decode(file_get_contents($file), true)['writes']) === 2, 'duplicate PATCH is sent only once');

$lost = $executor->preview($agent, 'LOCAL-1', 'repair', ['maxTotal' => 100, 'reserve' => 1000], (string) Str::uuid());
$executor->approve($agent, $lost);
saveWorld($file, ['failure' => 'timeout_after']);
workers($root, $env, $directory);
check($lost->fresh()->state === ActionState::Uncertain, 'lost repair response remains durable uncertain');
ExecuteShipCommand::dispatch($lost->id);
workers($root, $env, $directory);
$executor->reconcile($agent, $lost);
check($lost->fresh()->state === ActionState::Uncertain, 'repair reconciliation records evidence without inventing confirmation');
check(count(json_decode(file_get_contents($file), true)['writes']) === 3, 'worker restart and duplicate delivery never replay uncertain repair');
check(DB::table('failed_jobs')->count() === 0, 'owned worker queue has no failed jobs');
echo 'Owned disposable evidence: '.$directory."\n";
