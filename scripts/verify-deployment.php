<?php

require __DIR__.'/../vendor/autoload.php';

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\DeploymentGate;
use App\Gameplay\HostLock;
use App\Gameplay\ShipCommandExecutor;
use App\Jobs\ExecuteShipCommand;
use App\Models\ShipAction;
use App\Models\User;
use App\Queue\DeploymentDatabaseQueue;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FakeSpaceTraders;

$root = dirname(__DIR__);
$mode = $argv[1] ?? 'verify';
$directory = $argv[2] ?? sys_get_temp_dir().'/spacetraders-deployment-'.bin2hex(random_bytes(6));
if ($mode === 'verify' || $mode === 'wall-timeout') {
    mkdir($directory, 0700, true);
    touch($directory.'/database.sqlite');
    file_put_contents($directory.'/world.json', json_encode(FakeSpaceTraders::world()));
    $env = ['APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $directory.'/database.sqlite', 'DATABASE_URL' => '',
        'CACHE_STORE' => 'file', 'CACHE_DRIVER' => 'file', 'CACHE_PATH' => $directory.'/cache',
        'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'ops03_'.bin2hex(random_bytes(4)), 'MAIL_MAILER' => 'array',
        'QUEUE_CONNECTION' => 'database', 'SPACETRADERS_FIXTURE_PATH' => $directory.'/world.json',
        'SPACETRADERS_AUTOMATION_ENABLED' => 'false', 'SPACETRADERS_LOCAL_ENGINE_ENABLED' => 'false',
        'SPACETRADERS_LOCK_PATH' => $directory.'/locks', 'SPACETRADERS_DEPLOYMENT_PATH' => $directory.'/deployment',
        'APP_CONFIG_CACHE' => $directory.'/no-config.php', 'LARAVEL_STORAGE_PATH' => $directory.'/storage',
        'SPACETRADERS_RELEASE' => 'pre-cutover', 'LOG_CHANNEL' => 'stderr'];
    foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'app'] as $child) {
        mkdir($directory.'/storage/'.$child, 0700, true);
    }
    file_put_contents($directory.'/environment.json', json_encode($env));
    chmod($directory.'/environment.json', 0600);
} else {
    $env = json_decode(file_get_contents($directory.'/environment.json'), true, flags: JSON_THROW_ON_ERROR);
}
foreach ($env as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$gate = app(DeploymentGate::class);

if ($mode === 'web') {
    Route::get('/_owned-ops03-write', function () use ($directory) {
        DB::table('game_runtime')->upsert([['key' => 'owned-web', 'value' => 'started']], ['key'], ['value']);
        touch($directory.'/web.started');
        $deadline = microtime(true) + 630;
        while (! is_file($directory.'/web.release') && microtime(true) < $deadline) {
            usleep(20000);
        }
        DB::table('game_runtime')->where('key', 'owned-web')->update(['value' => 'finished']);

        return response('finished');
    });
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $request = Request::create('/_owned-ops03-write');
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    exit($response->getStatusCode() === 200 ? 0 : 1);
}
if ($mode === 'crash-lock') {
    Cache::lock('spacetraders:dispatch-due:v1', 120)->get();
    app(HostLock::class)->run('dispatch-due:v1', function () use ($directory) {
        touch($directory.'/lock.acquired');
        exit(93);
    });
}
if ($mode === 'hold-lock') {
    app(HostLock::class)->run('dispatch-due:v1', function () use ($directory) {
        touch($directory.'/lock.held');
        while (! is_file($directory.'/lock.release')) {
            usleep(20000);
        }
    });
    exit(0);
}
if ($mode === 'reservation-window') {
    $queue = new class(DB::connection(), 'jobs', 'spacetraders', 180, true) extends DeploymentDatabaseQueue
    {
        protected function getNextAvailableJob($queue)
        {
            global $directory;
            touch($directory.'/reservation.entered');
            $deadline = microtime(true) + 5;
            while (! is_file($directory.'/reservation.release') && microtime(true) < $deadline) {
                usleep(10000);
            }

            return parent::getNextAvailableJob($queue);
        }
    };
    $queue->setContainer(app());
    $queue->setConnectionName('database');
    $gate->workerPolling();
    if ($job = $queue->pop()) {
        $job->fire();
    }
    $gate->workerStopped();
    exit(0);
}
if ($mode === 'database-contention') {
    DB::beginTransaction();
    touch($directory.'/database.held');
    usleep(1000000);
    DB::commit();
    exit(0);
}
if ($mode === 'controller-race') {
    $controller = new Process([PHP_BINARY, 'artisan', 'spacetraders:deployment', 'close', '--generation=obsolete-waiter', '--wait'], $root, $env, timeout: 10);
    $controller->start();
    $deadline = microtime(true) + 5;
    do {
        usleep(20000);
        $state = $gate->status();
    } while ($state['generation'] !== 'obsolete-waiter' && microtime(true) < $deadline);
    if ($state['generation'] !== 'obsolete-waiter') {
        throw new RuntimeException('Controller failed to close its owned fixture.');
    }
    $gate->open('obsolete-waiter');
    $gate->close('successor');
    $controller->wait();
    $status = $gate->status();
    $passed = $controller->getExitCode() === 1 && $status['generation'] === 'successor' && $status['phase'] === 'draining';
    $gate->open('successor');
    file_put_contents($directory.'/controller-race.json', json_encode(['passed' => $passed, 'exit' => $controller->getExitCode(), 'status' => $status], JSON_PRETTY_PRINT));
    echo $passed ? "PASS superseded waiting controller fails without opening its successor\n" : "FAIL stale waiting controller\n";
    exit($passed ? 0 : 1);
}

$checks = [];
$processes = [];
function check(bool $condition, string $message): void
{
    global $checks;
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks[] = $message;
    echo 'PASS '.$message."\n";
}
function until(callable $condition, float $seconds = 10): void
{
    $deadline = microtime(true) + $seconds;
    while (! $condition()) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Timed out waiting for owned process evidence.');
        }
        usleep(50000);
    }
}
function start(array $command, string $label, ?string $cwd = null, array $overrides = []): Process
{
    global $processes, $root, $env, $directory;
    $process = new Process($command, $cwd ?? $root, array_merge($env, $overrides), timeout: null);
    $process->start();
    $processes[$label] = $process;
    file_put_contents($directory.'/'.$label.'.pid', (string) $process->getPid());

    return $process;
}

Artisan::call('migrate', ['--force' => true]);
echo 'Owned evidence: '.$directory."\n";
try {
    if ($mode === 'wall-timeout') {
        $web = start([PHP_BINARY, __FILE__, 'web', $directory], 'web');
        until(fn () => is_file($directory.'/web.started'));
        $started = hrtime(true);
        $controller = start([PHP_BINARY, 'artisan', 'spacetraders:deployment', 'close', '--generation=wall-clock', '--wait'], 'controller');
        until(fn () => $gate->status()['phase'] === 'draining');
        until(fn () => ! $controller->isRunning(), 610);
        $elapsed = (hrtime(true) - $started) / 1e9;
        $status = $gate->status();
        check($controller->getExitCode() === 1 && $elapsed >= 599 && $elapsed <= 605, 'real ten-minute controller aborts within measured tolerance');
        check($status['phase'] === 'open' && $status['outcome'] === 'aborted_timeout' && ! Queue::isPaused('database', 'spacetraders'), 'timeout restores admission and persistent pickup');
        check($web->isRunning() && DB::table('game_runtime')->where('key', 'owned-web')->value('value') === 'started', 'timeout neither kills nor cancels the active web writer');
        touch($directory.'/web.release');
        $web->wait();
        check($web->isSuccessful() && DB::table('game_runtime')->where('key', 'owned-web')->value('value') === 'finished', 'active writer completes normally after abort');
    } else {
        $user = User::factory()->create();
        $agent = $user->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic-only', 'account_id' => 'synthetic-account', 'faction' => 'COSMIC',
            'reset_id' => '2026-10-25', 'canonical_identity' => AgentSynchronizer::identity('2026-10-25', 'LOCAL')]);
        app(AgentSynchronizer::class)->sync($agent);
        $executor = app(ShipCommandExecutor::class);
        $action = $executor->preview($agent, 'LOCAL-1', 'orbit', [], (string) Str::uuid());
        $executor->approve($agent, $action);
        ExecuteShipCommand::dispatch($action->id)->delay(now()->addSeconds(100));
        $delayed = DB::table('jobs')->orderByDesc('id')->first();
        $world = json_decode(file_get_contents($directory.'/world.json'), true);
        $world['failure'] = 'pause_after';
        file_put_contents($directory.'/world.json', json_encode($world));
        $worker = start([PHP_BINARY, 'artisan', 'queue:work', 'database', '--queue=spacetraders', '--sleep=1', '--tries=1', '--timeout=120', '--max-time=170'], 'worker');
        until(fn () => is_file($directory.'/world.json.sent'));
        $web = start([PHP_BINARY, __FILE__, 'web', $directory], 'web');
        until(fn () => is_file($directory.'/web.started'));
        $scheduler = start([PHP_BINARY, 'artisan', 'schedule:run'], 'scheduler');
        until(function () use ($directory) {
            $state = json_decode(file_get_contents($directory.'/deployment/state.json'), true);

            return collect($state['participants'])->contains(fn ($p) => $p['role'] === 'scheduler' && $p['busy']);
        });
        $gate->close('owned-cutover');
        $initial = $gate->status();
        check(! $initial['drained'] && in_array('queue:reserved_jobs', $initial['blockers'], true), 'close waits for an actually reserved gameplay job');
        check(collect($initial['participants'])->contains(fn ($p) => $p['role'] === 'web' && $p['busy']), 'close includes the real HTTP-kernel writer');
        $worker2 = start([PHP_BINARY, 'artisan', 'queue:work', 'database', '--queue=spacetraders', '--sleep=1', '--tries=1', '--timeout=120', '--max-time=170'], 'worker-2', overrides: ['SPACETRADERS_RELEASE' => 'candidate']);
        Artisan::call('spacetraders:dispatch-due');
        check(DB::table('jobs')->where('id', $delayed->id)->first() == $delayed, 'closed dispatch preserves delayed queue bytes and attempts');
        check(DB::table('game_runtime')->where('key', 'owned-web')->value('value') === 'started', 'active writer remains alive during drain');
        touch($directory.'/web.release');
        $web->wait();
        check($web->isSuccessful(), 'active web writer acknowledges after finishing its database write');
        until(fn () => $gate->status()['drained'], 75);
        check($scheduler->wait() === 0, 'pre-close schedule:run descendant exits before drain succeeds');
        check($action->fresh()->state->value === 'confirmed' && count(json_decode(file_get_contents($directory.'/world.json'), true)['writes']) === 1, 'reserved work finishes exactly one fake upstream mutation');
        check(DB::table('jobs')->where('id', $delayed->id)->first() == $delayed, 'both real workers leave pending delayed intent untouched while closed');
        $gate->open('owned-cutover');
        DB::table('jobs')->where('id', $delayed->id)->update(['available_at' => time()]);
        until(fn () => DB::table('jobs')->count() === 0);
        check(count(json_decode(file_get_contents($directory.'/world.json'), true)['writes']) === 1, 'resumed duplicate delivery does not replay confirmed work');
        $gate->close('second-cutover');
        start([PHP_BINARY, 'artisan', 'schedule:run'], 'second-scheduler');
        until(fn () => $gate->status()['drained']);
        try {
            $gate->open('owned-cutover');
            throw new RuntimeException('Stale open was accepted.');
        } catch (RuntimeException $e) {
            check(str_contains($e->getMessage(), 'stale controller'), 'old controller cannot reopen the next release');
        }
        $gate->open('second-cutover');
        // Freeze a real release artifact, using only this checkout's owned locked vendor tree.
        $release = $directory.'/releases/rollback';
        $files = new Filesystem;
        foreach (['app', 'bootstrap', 'config', 'routes', 'database/migrations', 'resources/views', 'tests/Support'] as $part) {
            $files->copyDirectory($root.'/'.$part, $release.'/'.$part);
        }
        symlink($root.'/vendor', $release.'/vendor');
        copy($root.'/composer.json', $release.'/composer.json');
        copy($root.'/artisan', $release.'/artisan');
        $entry = file_get_contents($release.'/artisan');
        $entry = str_replace("require __DIR__.'/vendor/autoload.php';", <<<'BOOT'
require __DIR__.'/vendor/autoload.php';
spl_autoload_register(function ($class) {
    if (str_starts_with($class, 'App\\')) {
        $path = __DIR__.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (is_file($path)) { require $path; }
    }
}, true, true);
BOOT, $entry);
        file_put_contents($release.'/artisan', $entry);
        $rollback = start([PHP_BINARY, 'artisan', 'spacetraders:deployment', 'status'], 'rollback-status', $release, ['SPACETRADERS_RELEASE' => 'rollback']);
        check($rollback->wait() === 0 && json_decode($rollback->getOutput(), true)['version'] === 1, 'frozen rollback release reads the shared version 1 contract and existing SQLite schema');
        $gate->close('rollback-cutover');
        $rollbackWorker = start([PHP_BINARY, 'artisan', 'queue:work', 'database', '--queue=spacetraders', '--once', '--sleep=0'], 'rollback-worker', $release, ['SPACETRADERS_RELEASE' => 'rollback']);
        check($rollbackWorker->wait() === 0, 'rollback artifact worker observes persistent pause without reserving');
        start([PHP_BINARY, 'artisan', 'schedule:run'], 'rollback-scheduler', $release, ['SPACETRADERS_RELEASE' => 'rollback']);
        until(fn () => $gate->status()['drained']);
        $gate->open('rollback-cutover');
        $crash = start([PHP_BINARY, __FILE__, 'crash-lock', $directory], 'crash-lock');
        check($crash->wait() === 93 && is_file($directory.'/lock.acquired'), 'abrupt process exit leaves the scheduler cache lock behind');
        $expiryStarted = microtime(true);
        $event = collect(app(Schedule::class)->events())->first();
        check($event->mutex->exists($event), 'stale scheduler overlap lock suppresses a second pass before expiry');
        $held = start([PHP_BINARY, __FILE__, 'hold-lock', $directory], 'hold-lock');
        until(fn () => is_file($directory.'/lock.held'));
        Cache::lock('spacetraders:dispatch-due:v1', 120)->forceRelease();
        $before = DB::table('game_runtime')->where('key', 'scheduler')->value('value');
        Artisan::call('spacetraders:dispatch-due');
        check(DB::table('game_runtime')->where('key', 'scheduler')->value('value') === $before, 'live OS dispatcher ownership prevents overlap even if cache lock is cleared');
        touch($directory.'/lock.release');
        check($held->wait() === 0, 'owned lock holder exits normally');
        Cache::lock('spacetraders:dispatch-due:v1', 120)->get();
        until(fn () => ! $event->mutex->exists($event), 125);
        $elapsed = microtime(true) - $expiryStarted;
        $passStarted = hrtime(true);
        Artisan::call('spacetraders:dispatch-due');
        $passSeconds = (hrtime(true) - $passStarted) / 1e9;
        check($elapsed >= 119 && $passSeconds < 15, 'real stale-lock expiry recovers dispatch after 120 seconds with measured bounded pass');
        $worker->wait();
        $worker2->wait();
        ExecuteShipCommand::dispatch($action->id);
        $racer = start([PHP_BINARY, __FILE__, 'reservation-window', $directory], 'reservation-racer');
        until(fn () => is_file($directory.'/reservation.entered'));
        $racingClose = start([PHP_BINARY, 'artisan', 'spacetraders:deployment', 'close', '--generation=reservation-race'], 'racing-close');
        usleep(200000);
        check($racingClose->isRunning(), 'close waits outside the actual SQLite reservation critical section');
        touch($directory.'/reservation.release');
        check($racer->wait() === 0 && $racingClose->wait() === 1, 'pre-close reservation finishes; missing continuous readiness is reported honestly');
        ExecuteShipCommand::dispatch($action->id);
        $retained = DB::table('jobs')->get()->toJson();
        $closedRacer = start([PHP_BINARY, __FILE__, 'reservation-window', $directory], 'closed-racer');
        check($closedRacer->wait() === 0 && DB::table('jobs')->get()->toJson() === $retained, 'reservation after close cannot change SQL attempts, even without Laravel pause polling');
        $gate->open('reservation-race');
        for ($i = 0; $i < 50; $i++) {
            ShipAction::create(['agent_id' => $agent->id, 'key' => (string) Str::uuid(), 'reset_id' => $agent->reset_id,
                'ship_symbol' => 'LOCAL-1', 'origin' => 'manual', 'command' => 'orbit', 'arguments' => [], 'state' => 'pending', 'expires_at' => now()->addMinute()]);
        }
        $contender = start([PHP_BINARY, __FILE__, 'database-contention', $directory], 'database-contender');
        until(fn () => is_file($directory.'/database.held'));
        $stressStarted = hrtime(true);
        Artisan::call('spacetraders:dispatch-due');
        $stressSeconds = (hrtime(true) - $stressStarted) / 1e9;
        check($contender->wait() === 0 && $stressSeconds >= 0.8 && $stressSeconds < 15 && DB::table('jobs')->count() === 51, '50-intent dispatcher pass survives real SQLite writer contention within its budget');
        check(count(json_decode(file_get_contents($directory.'/world.json'), true)['writes']) === 1, 'races and dispatcher stress never replay the confirmed fake mutation');
        $controllerRace = start([PHP_BINARY, __FILE__, 'controller-race', $directory], 'controller-race');
        check($controllerRace->wait() === 0, 'superseded waiting controller fails without opening its successor');
        check(DB::table('failed_jobs')->count() === 0, 'owned SQLite run has no failed jobs');
        file_put_contents($directory.'/timings.json', json_encode(['stale_lock_seconds' => $elapsed, 'dispatch_seconds' => $passSeconds, 'contended_50_intent_seconds' => $stressSeconds], JSON_PRETTY_PRINT));
    }
} finally {
    touch($directory.'/web.release');
    touch($directory.'/lock.release');
    foreach ($processes as $label => $process) {
        // All worker commands have finite --max-time. Never kill or clear work for cleanup.
        $process->wait();
        file_put_contents($directory.'/'.$label.'.log', $process->getOutput().$process->getErrorOutput());
    }
    file_put_contents($directory.'/results.json', json_encode(['mode' => $mode, 'checks' => $checks, 'owned_directory' => $directory], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
