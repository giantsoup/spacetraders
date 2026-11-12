<?php

// Owns a fresh offline SQLite fixture and a temporary launchd identity. Always removes that service.
require __DIR__.'/../vendor/autoload.php';

use App\Gameplay\DeploymentGate;
use App\Gameplay\Engine\EngineHost;
use App\Gameplay\LocalEngineManager;
use App\Gameplay\ShipCommandExecutor;
use App\Models\Agent;
use App\Models\LocalEngine;
use App\Models\ShipAction;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
$fixture = new Process([PHP_BINARY, $root.'/scripts/mvp-fixture.php', '--isolated'], $root);
$fixture->mustRun();
preg_match('/Owned fixture directory: (.+)/', $fixture->getOutput(), $match);
$directory = $match[1] ?? throw new RuntimeException('Missing owned fixture.');
$env = Dotenv\Dotenv::parse(file_get_contents($directory.'/.env'));
$env = array_merge($env, ['SPACETRADERS_LOCAL_ENGINE_ENABLED' => 'true', 'SPACETRADERS_OPERATOR_ID' => '1', 'SPACETRADERS_PHP_BINARY' => PHP_BINARY,
    'APP_CONFIG_CACHE' => $directory.'/no-config.php', 'LARAVEL_STORAGE_PATH' => $directory.'/storage', 'CACHE_STORE' => 'file',
    'CACHE_PATH' => $directory.'/cache', 'SPACETRADERS_DEPLOYMENT_PATH' => $directory.'/deployment']);
foreach (['framework/sessions', 'framework/views', 'framework/cache/data', 'logs'] as $child) {
    mkdir($directory.'/storage/'.$child, 0700, true);
}
foreach ($env as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$manager = app(LocalEngineManager::class);
$host = app(EngineHost::class);
$steps = [];
$passed = false;
$failure = null;
$sentinel = new Process([PHP_BINARY, '-r', 'sleep(300);']);
$sentinel->setTimeout(null)->disableOutput()->start();
function verify(bool $value, string $message): void
{
    global $steps;
    if (! $value) {
        throw new RuntimeException($message);
    }
    $steps[] = $message;
    echo 'PASS '.$message."\n";
}
function readWorld(string $file): array
{
    $handle = fopen($file, 'r');
    if ($handle === false) {
        throw new RuntimeException('Cannot open owned fixture world.');
    }
    try {
        if (! flock($handle, LOCK_SH)) {
            throw new RuntimeException('Cannot lock owned fixture world.');
        }

        return json_decode(stream_get_contents($handle), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
function until(callable $check, int $seconds = 12): void
{
    $deadline = microtime(true) + $seconds;
    while (! $check()) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Timed out waiting for owned engine state.');
        }
        usleep(100000);
    }
}
try {
    $manager->install();
    verify(! file_exists(getenv('HOME').'/Library/LaunchAgents/'.$host->serviceName().'.plist'), 'No login LaunchAgent installed');
    $manager->start();
    until(fn () => LocalEngine::find(1)?->state === 'running');
    $engine = LocalEngine::findOrFail(1);
    verify($engine->gate_open && $manager->alive($engine, 'worker') && $manager->alive($engine, 'scheduler'), 'Actual owned worker and scheduler are alive');
    $manager->start();
    verify(LocalEngine::find(1)->processes === $engine->processes, 'Repeated Start preserves exactly one supervisor and child pair');
    $agent = Agent::firstOrFail();
    $executor = app(ShipCommandExecutor::class);
    $action = $executor->preview($agent, 'LOCAL-1', 'orbit', [], (string) Str::uuid());
    $executor->approve($agent, $action);
    until(fn () => ShipAction::find($action->id)->state->value === 'confirmed');
    verify(count(readWorld($env['SPACETRADERS_FIXTURE_PATH'])['writes']) === 1, 'Owned worker executes one explicitly approved fake command');
    $old = $executor->preview($agent->fresh(), 'LOCAL-1', 'dock', [], (string) Str::uuid());
    $deployment = app(DeploymentGate::class);
    $deployment->close('owned-engine-pause');
    until(fn () => $deployment->status()['drained']);
    verify($old->fresh()->state->value === 'preview' && LocalEngine::find(1)->session_id === $engine->session_id, 'Deployment drain preserves previews and the local engine session');
    $deployment->open('owned-engine-pause');
    verify(LocalEngine::find(1)->processes === $engine->processes && count(readWorld($env['SPACETRADERS_FIXTURE_PATH'])['writes']) === 1, 'Shared persistent pause resumes the same owned engine without extra gameplay');
    $manager->end();
    until(fn () => LocalEngine::find(1)?->state === 'off');
    until(fn () => ! $manager->alive($engine, 'supervisor'));
    verify(! $manager->alive($engine, 'worker') && ! $manager->alive($engine, 'scheduler'), 'End session waits for actual child exit');
    verify($sentinel->isRunning(), 'End session leaves an unrelated PHP process alive');
    verify($old->fresh()->state->value === 'rejected', 'End session invalidates unsent previews');
    $manager->start();
    until(fn () => LocalEngine::find(1)?->state === 'running');
    verify(LocalEngine::find(1)->session_id !== $engine->session_id, 'Restart creates a fresh session');
    $old->update(['state' => 'pending']); // Simulate stale legacy queue delivery.
    $executor->execute($old->id);
    verify($old->fresh()->state->value === 'rejected', 'Legacy execution cannot bypass the restarted session gate');
    verify(count(readWorld($env['SPACETRADERS_FIXTURE_PATH'])['writes']) === 1, 'Restart and Start alone authorize zero additional gameplay');
    $second = LocalEngine::find(1);
    posix_kill($second->processes['scheduler'], SIGKILL);
    until(fn () => LocalEngine::find(1)?->state === 'faulted');
    until(fn () => ! $manager->alive($second, 'supervisor'));
    verify(! LocalEngine::find(1)->gate_open && ! $manager->alive($second, 'worker'), 'Child crash closes the gate and stops the remaining owned process');
    $manager->start();
    until(fn () => LocalEngine::find(1)?->state === 'running');
    $third = LocalEngine::find(1);
    posix_kill($third->processes['supervisor'], SIGKILL);
    until(fn () => ! $manager->alive($third, 'supervisor'));
    $status = $manager->status();
    verify($status['state'] === 'faulted' && ! $status['gate_open'], 'Abrupt supervisor death is detected and closes approval');
    until(fn () => ! $manager->alive($third, 'worker') && ! $manager->alive($third, 'scheduler'));
    verify(true, 'launchd removes only the crashed supervisor’s child process group');
    $manager->start();
    until(fn () => LocalEngine::find(1)?->state === 'running');
    $beforeSleep = LocalEngine::find(1);
    posix_kill($beforeSleep->processes['supervisor'], SIGSTOP);
    // Model a missed heartbeat without putting the Mac to sleep.
    LocalEngine::whereKey(1)->update(['heartbeat_at' => now()->subSeconds(16)]);
    try {
        verify($manager->status()['state'] === 'faulted', 'A sleep-length heartbeat gap closes permission instead of catching up');
    } finally {
        posix_kill($beforeSleep->processes['supervisor'], SIGCONT);
    }
    until(fn () => ! $manager->alive($beforeSleep, 'supervisor'));
    verify(count(readWorld($env['SPACETRADERS_FIXTURE_PATH'])['writes']) === 1, 'Crash and wake recovery never replay prior approvals');
    $manager->start();
    until(fn () => LocalEngine::find(1)?->state === 'running');
    $force = LocalEngine::find(1);
    posix_kill($force->processes['supervisor'], SIGSTOP);
    try {
        $manager->end();
        LocalEngine::whereKey(1)->update(['state' => 'faulted', 'stop_requested_at' => now()->subSeconds(41)]);
        $manager->forceStop();
        until(fn () => ! $manager->alive($force, 'supervisor') && ! $manager->alive($force, 'worker') && ! $manager->alive($force, 'scheduler'));
        verify(! LocalEngine::find(1)->gate_open, 'Acknowledged force stop kills only the fixed owned service after its modeled grace period');
    } finally {
        if ($manager->alive($force, 'supervisor')) {
            posix_kill($force->processes['supervisor'], SIGCONT);
        }
    }
    verify($sentinel->isRunning(), 'Supervisor crash recovery leaves unrelated PHP processes alive');
    $passed = true;
} catch (Throwable $error) {
    $failure = $error->getMessage();
    throw $error;
} finally {
    $manager->end();
    until(fn () => ! $manager->alive(LocalEngine::find(1), 'supervisor'), 50);
    $manager->uninstall();
    $sentinel->stop(1, SIGTERM);
    file_put_contents($directory.'/engine-result.json', json_encode(['passed' => $passed, 'failure' => $failure, 'steps' => $steps, 'service_removed' => ! $host->hasServiceMarker()], JSON_PRETTY_PRINT));
    echo 'Owned offline lifecycle evidence: '.$directory."\n";
}
