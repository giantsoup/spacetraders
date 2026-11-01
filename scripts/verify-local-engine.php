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
    verify($old->fresh()->state->value === 'rejected', 'End session invalidates unsent previews');
    $manager->start();
    until(fn () => LocalEngine::find(1)?->state === 'running');
    verify(LocalEngine::find(1)->session_id !== $engine->session_id, 'Restart creates a fresh session');
    $old->update(['state' => 'pending']); // Simulate stale legacy queue delivery.
    $executor->execute($old->id);
    verify($old->fresh()->state->value === 'rejected', 'Legacy execution cannot bypass the restarted session gate');
    verify(count(json_decode(file_get_contents($env['SPACETRADERS_FIXTURE_PATH']), true)['writes']) === 1, 'Restart and Start alone authorize zero additional gameplay');
    $manager->end();
    until(fn () => LocalEngine::find(1)?->state === 'off');
    until(fn () => ! $manager->alive(LocalEngine::find(1)->processes['supervisor']));
} finally {
    $manager->end();
    until(fn () => ! $manager->alive(LocalEngine::find(1)?->processes['supervisor'] ?? null), 50);
    $manager->uninstall();
    file_put_contents($directory.'/engine-result.json', json_encode(['passed' => count($steps) >= 9, 'steps' => $steps, 'service_removed' => ! is_file($manager->directory().'/service.plist')], JSON_PRETTY_PRINT));
    echo 'Owned offline lifecycle evidence: '.$directory."\n";
}
