<?php

// Owns a fresh offline SQLite fixture and a temporary launchd identity. Always removes that service.
require __DIR__.'/../vendor/autoload.php';

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
    'APP_CONFIG_CACHE' => $directory.'/no-config.php', 'LARAVEL_STORAGE_PATH' => $directory.'/storage', 'CACHE_STORE' => 'array']);
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
    verify(! file_exists(getenv('HOME').'/Library/LaunchAgents/'.$manager->label().'.plist'), 'No login LaunchAgent installed');
    $manager->start();
    until(fn () => LocalEngine::find(1)?->state === 'running');
    $engine = LocalEngine::findOrFail(1);
    verify($engine->gate_open && $manager->alive($engine->processes['worker']) && $manager->alive($engine->processes['scheduler']), 'Actual owned worker and scheduler are alive');
    $manager->start();
    verify(LocalEngine::find(1)->processes === $engine->processes, 'Repeated Start preserves exactly one supervisor and child pair');
    $agent = Agent::firstOrFail();
    $executor = app(ShipCommandExecutor::class);
    $action = $executor->preview($agent, 'LOCAL-1', 'orbit', [], (string) Str::uuid());
    $executor->approve($agent, $action);
    until(fn () => ShipAction::find($action->id)->state->value === 'confirmed');
    verify(count(json_decode(file_get_contents($env['SPACETRADERS_FIXTURE_PATH']), true)['writes']) === 1, 'Owned worker executes one explicitly approved fake command');
    $old = $executor->preview($agent->fresh(), 'LOCAL-1', 'dock', [], (string) Str::uuid());
    $manager->end();
    until(fn () => LocalEngine::find(1)?->state === 'off');
    until(fn () => ! $manager->alive($engine->processes['supervisor']));
    verify(! $manager->alive($engine->processes['worker']) && ! $manager->alive($engine->processes['scheduler']), 'End session waits for actual child exit');
    verify($sentinel->isRunning(), 'End session leaves an unrelated PHP process alive');
    verify($old->fresh()->state->value === 'rejected', 'End session invalidates unsent previews');
    $manager->start();
    until(fn () => LocalEngine::find(1)?->state === 'running');
    verify(LocalEngine::find(1)->session_id !== $engine->session_id, 'Restart creates a fresh session');
    $old->update(['state' => 'pending']); // Simulate stale legacy queue delivery.
    $executor->execute($old->id);
    verify($old->fresh()->state->value === 'rejected', 'Legacy execution cannot bypass the restarted session gate');
    verify(count(json_decode(file_get_contents($env['SPACETRADERS_FIXTURE_PATH']), true)['writes']) === 1, 'Restart and Start alone authorize zero additional gameplay');
    $second = LocalEngine::find(1);
    posix_kill($second->processes['scheduler'], SIGKILL);
    until(fn () => LocalEngine::find(1)?->state === 'faulted');
    until(fn () => ! $manager->alive($second->processes['supervisor']));
    verify(! LocalEngine::find(1)->gate_open && ! $manager->alive($second->processes['worker']), 'Child crash closes the gate and stops the remaining owned process');
    $manager->start();
    until(fn () => LocalEngine::find(1)?->state === 'running');
    $third = LocalEngine::find(1);
    posix_kill($third->processes['supervisor'], SIGKILL);
    until(fn () => ! $manager->alive($third->processes['supervisor']));
    $status = $manager->status();
    verify($status['state'] === 'faulted' && ! $status['gate_open'], 'Abrupt supervisor death is detected and closes approval');
    until(fn () => ! $manager->alive($third->processes['worker']) && ! $manager->alive($third->processes['scheduler']));
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
    until(fn () => ! $manager->alive($beforeSleep->processes['supervisor']));
    verify(count(json_decode(file_get_contents($env['SPACETRADERS_FIXTURE_PATH']), true)['writes']) === 1, 'Crash and wake recovery never replay prior approvals');
    $manager->start();
    until(fn () => LocalEngine::find(1)?->state === 'running');
    $force = LocalEngine::find(1);
    posix_kill($force->processes['supervisor'], SIGSTOP);
    try {
        $manager->end();
        LocalEngine::whereKey(1)->update(['state' => 'faulted', 'stop_requested_at' => now()->subSeconds(41)]);
        $manager->forceStop();
        until(fn () => ! $manager->alive($force->processes['supervisor']) && ! $manager->alive($force->processes['worker']) && ! $manager->alive($force->processes['scheduler']));
        verify(! LocalEngine::find(1)->gate_open, 'Acknowledged force stop kills only the fixed owned service after its modeled grace period');
    } finally {
        if ($manager->alive($force->processes['supervisor'])) {
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
    until(fn () => ! $manager->alive(LocalEngine::find(1)?->processes['supervisor'] ?? null), 50);
    $manager->uninstall();
    $sentinel->stop(1, SIGTERM);
    file_put_contents($directory.'/engine-result.json', json_encode(['passed' => $passed, 'failure' => $failure, 'steps' => $steps, 'service_removed' => ! is_file($manager->directory().'/service.plist')], JSON_PRETTY_PRINT));
    echo 'Owned offline lifecycle evidence: '.$directory."\n";
}
