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
use Tests\Support\FoundationScenario;

$operations = ['navigate', 'jump', 'warp', 'supply', 'siphon', 'refine', 'install_mount', 'remove_mount', 'install_module', 'remove_module', 'contract_accept', 'contract_deliver', 'contract_fulfill', 'negotiate', 'scrap'];
$command = $argv[1] ?? null;
$postReceipt = ($argv[2] ?? null) === '--post-receipt';
if ($command === null) {
    foreach ($operations as $operation) {
        $child = proc_open([PHP_BINARY, __FILE__, $operation], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, dirname(__DIR__));
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        echo $output;
        if (proc_close($child) !== 0 || ! str_contains($output, $operation.' workers have no failed jobs')) {
            exit(1);
        }
    }
    exit(0);
}
if (! in_array($command, $operations, true)) {
    throw new RuntimeException('Unsupported synthetic scenario');
}
$root = dirname(__DIR__);
$directory = sys_get_temp_dir().'/spacetraders-foundation-uncertainty-'.bin2hex(random_bytes(6));
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
        fwrite(STDERR, 'FAIL '.$message."\n");
        exit(1);
    }
    echo 'PASS '.$message."\n";
}
function workers(string $root, array $env, string $directory): void
{
    $children = [];
    for ($i = 0; $i < 2; $i++) {
        $children[] = proc_open([PHP_BINARY, 'artisan', 'queue:work', 'database', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout=120'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory.'/worker-'.$i.'.log', 'a'], 2 => ['file', $directory.'/worker-'.$i.'.log', 'a']], $pipes, $root, array_merge(getenv(), $env));
    }
    foreach ($children as $child) {
        check(proc_close($child) === 0, 'real database worker exited successfully');
    }
}
check((int) DB::selectOne('PRAGMA synchronous')->synchronous === 2, 'file-backed SQLite commits use FULL synchronization');
check((int) DB::selectOne('PRAGMA fullfsync')->fullfsync === 1, 'SQLite enables macOS fullfsync support');
$executor = app(ShipCommandExecutor::class);

[$scenario, $arguments] = FoundationScenario::make($command);
$scenario['verify_worker_storage'] = true;
file_put_contents($file, json_encode($scenario));
app(AgentSynchronizer::class)->sync($agent);
$action = $executor->preview($agent, 'LOCAL-1', $command, $arguments, (string) Str::uuid());
$executor->approve($agent, $action);
$scenario[$postReceipt ? 'defer_followup_after_write' : 'failure'] = $postReceipt ? true : 'timeout_after';
file_put_contents($file, json_encode($scenario));
workers($root, $env, $directory);
check($action->fresh()->state === ActionState::Uncertain, $command.' lost response persists as uncertain');
if ($postReceipt) {
    check($action->fresh()->outcome['http_status'] === 201 && ! empty($action->fresh()->outcome['body_sha256']) && ! empty($action->fresh()->outcome['response_validated']) && $action->fresh()->sent_at !== null, 'post-receipt budget deferral preserves the validated response and sending evidence');
}
ExecuteShipCommand::dispatch($action->id);
workers($root, $env, $directory);
if ($postReceipt) {
    $current = json_decode(file_get_contents($file), true);
    unset($current['defer_followup_after_write']);
    file_put_contents($file, json_encode($current));
}
$executor->reconcile($agent, $action);
check($action->fresh()->state === ActionState::Uncertain, $command.' reconciliation keeps missing write evidence unresolved');
check(! empty($action->fresh()->outcome['reconciled_at']), $command.' reconciliation retains fresh observations');
$evidence = match ($command) {
    'supply' => 'construction', 'negotiate' => 'contracts',
    'contract_accept', 'contract_deliver', 'contract_fulfill' => 'contract',
    'install_mount', 'remove_mount', 'install_module', 'remove_module' => 'installed',
    'navigate', 'jump', 'warp' => 'waypoint', default => 'fleet',
};
check(array_key_exists($evidence, $action->fresh()->outcome), $command.' records '.$evidence.' evidence');
check((json_decode(file_get_contents($file), true)['worker_storage']['synchronous'] ?? null) === 2 && (json_decode(file_get_contents($file), true)['worker_storage']['fullfsync'] ?? null) === 1, $command.' actual worker used durable SQLite synchronization');
check(count(json_decode(file_get_contents($file), true)['writes']) === 1, $command.' restart/duplicate/reconciliation never replays write');
check(DB::table('failed_jobs')->count() === 0, $command.' workers have no failed jobs');
echo 'Owned disposable evidence: '.$directory."\n";
