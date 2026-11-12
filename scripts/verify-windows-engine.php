<?php

if (PHP_OS_FAMILY !== 'Windows') {
    echo "SKIP Windows-only owned engine verification.
";
    exit(0);
}

require __DIR__.'/../vendor/autoload.php';

use App\Gameplay\Engine\EngineHost;
use App\Gameplay\Engine\HostCommands;
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
$fixture->setTimeout(60)->mustRun();
preg_match('/Owned fixture directory: (.+)/', $fixture->getOutput(), $match);
$directory = trim($match[1] ?? throw new RuntimeException('Missing owned fixture.'));
$env = array_merge(Dotenv\Dotenv::parse(file_get_contents($directory.'/.env')), [
    'SPACETRADERS_LOCAL_ENGINE_ENABLED' => 'true', 'SPACETRADERS_OPERATOR_ID' => '1', 'SPACETRADERS_PHP_BINARY' => PHP_BINARY,
    'APP_CONFIG_CACHE' => $directory.'/no-config.php', 'LARAVEL_STORAGE_PATH' => $directory.'/storage', 'CACHE_STORE' => 'file',
    'CACHE_PATH' => $directory.'/cache', 'SPACETRADERS_DEPLOYMENT_PATH' => $directory.'/deployment',
    'SPACETRADERS_REQUEST_BUDGET_PATH' => $directory.'/budget',
]);
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
$failure = null;
$cleanupErrors = [];
$installed = false;
$sentinel = null;
$resume = null;
$system = rtrim(getenv('SystemRoot') ?: 'C:/Windows', '/\\').'/System32/';

function verifyWindows(bool $value, string $message): void
{
    global $steps;
    if (! $value) {
        throw new RuntimeException($message);
    }
    $steps[] = $message;
    echo 'PASS '.$message.PHP_EOL;
}

function untilWindows(callable $check, int $seconds = 12): void
{
    $deadline = microtime(true) + $seconds;
    while (! $check()) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Timed out waiting for owned Windows engine state.');
        }
        usleep(100000);
    }
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

function ownedPid(LocalEngine $engine, string $role): int
{
    $pid = $engine->processes[$role] ?? null;
    if (! is_int($pid) || $pid < 1 || ! app(EngineHost::class)->alive($engine, $role)) {
        throw new RuntimeException('Owned process is not alive: '.$role);
    }
    $recorded = $engine->processes['identity'][$role] ?? null;
    $current = app(EngineHost::class)->identify([$role => $pid]);
    if ($recorded === null || ($current[$role] ?? null) !== $recorded) {
        throw new RuntimeException('Refusing to operate on an unverified process identity: '.$role);
    }

    return $pid;
}

function killOwned(LocalEngine $engine, string $role): void
{
    global $system;
    $result = app(HostCommands::class)->run([$system.'taskkill.exe', '/PID', (string) ownedPid($engine, $role), '/F']);
    if ($result->exit !== 0) {
        throw new RuntimeException('Could not terminate owned '.$role.': '.$result->err);
    }
}

function suspendOwned(LocalEngine $engine): Closure
{
    global $system;
    if (! class_exists(FFI::class)) {
        throw new RuntimeException('CLI FFI is required for the isolated suspend/resume scenarios.');
    }
    $kernel = FFI::cdef('void* OpenProcess(unsigned long access, int inherit, unsigned long pid); int CloseHandle(void* handle);', $system.'kernel32.dll');
    $native = FFI::cdef('long NtSuspendProcess(void* handle); long NtResumeProcess(void* handle);', $system.'ntdll.dll');
    $handle = $kernel->OpenProcess(0x0800, 0, ownedPid($engine, 'supervisor'));
    if (FFI::isNull($handle)) {
        throw new RuntimeException('Could not open the isolated supervisor for suspend/resume.');
    }
    if ($native->NtSuspendProcess($handle) < 0) {
        $kernel->CloseHandle($handle);
        throw new RuntimeException('Could not suspend the isolated supervisor.');
    }

    return static function (bool $terminated = false) use ($kernel, $native, $handle): void {
        try {
            if (! $terminated && $native->NtResumeProcess($handle) < 0) {
                throw new RuntimeException('Could not resume the isolated supervisor.');
            }
        } finally {
            $kernel->CloseHandle($handle);
        }
    };
}

try {
    verifyWindows(! $host->hasServiceMarker() && ! $host->registered(), 'Fresh fixture owns an absent temporary task identity');
    $installed = true;
    $manager->install();
    $query = app(HostCommands::class)->run([$system.'schtasks.exe', '/Query', '/TN', $host->serviceName(), '/XML']);
    verifyWindows($query->exit === 0, 'Temporary on-demand task is registered');
    $document = new DOMDocument;
    verifyWindows($document->loadXML($query->out), 'Task XML parses successfully');
    $xml = new DOMXPath($document);
    verifyWindows($xml->query('//*[local-name()="Triggers"]/*')->length === 0, 'Task XML has no login or timer triggers');
    foreach (['MultipleInstancesPolicy' => 'IgnoreNew', 'ExecutionTimeLimit' => 'PT0S', 'DisallowStartIfOnBatteries' => 'false', 'StopIfGoingOnBatteries' => 'false', 'LogonType' => 'InteractiveToken', 'RunLevel' => 'LeastPrivilege'] as $element => $expected) {
        $nodes = $xml->query('//*[local-name()="'.$element.'"]');
        verifyWindows($nodes->length === 1 && $nodes->item(0)->textContent === $expected, 'Task '.$element.' is '.$expected);
    }
    $sentinel = new Process([PHP_BINARY, '-r', 'sleep(300);']);
    $sentinel->setTimeout(null)->disableOutput()->start();
    $manager->start();
    untilWindows(fn () => LocalEngine::find(1)?->state === 'running', 30);
    $engine = LocalEngine::findOrFail(1);
    verifyWindows($engine->gate_open && $manager->alive($engine, 'worker') && $manager->alive($engine, 'scheduler'), 'Actual leased worker and scheduler are alive');
    $manager->start();
    verifyWindows(LocalEngine::find(1)->processes === $engine->processes, 'Repeated Start preserves one supervisor and child pair');
    $agent = Agent::firstOrFail();
    $executor = app(ShipCommandExecutor::class);
    $action = $executor->preview($agent, 'LOCAL-1', 'orbit', [], (string) Str::uuid());
    $executor->approve($agent, $action);
    untilWindows(fn () => ShipAction::find($action->id)->state->value === 'confirmed');
    verifyWindows(count(readWorld($env['SPACETRADERS_FIXTURE_PATH'])['writes']) === 1, 'Owned Windows worker executes one explicitly approved fake command');
    $old = $executor->preview($agent->fresh(), 'LOCAL-1', 'dock', [], (string) Str::uuid());
    $manager->end();
    untilWindows(fn () => LocalEngine::find(1)?->state === 'off');
    untilWindows(fn () => ! $manager->alive($engine, 'supervisor'));
    verifyWindows(! $manager->alive($engine, 'worker') && ! $manager->alive($engine, 'scheduler'), 'End waits for actual leased child exit');
    verifyWindows($old->fresh()->state->value === 'rejected' && $sentinel->isRunning(), 'End invalidates previews and leaves unrelated PHP alive');
    $manager->start();
    untilWindows(fn () => LocalEngine::find(1)?->state === 'running', 30);
    $second = LocalEngine::findOrFail(1);
    verifyWindows($second->session_id !== $engine->session_id, 'Restart creates a fresh session');
    $old->update(['state' => 'pending']);
    $executor->execute($old->id);
    verifyWindows($old->fresh()->state->value === 'rejected', 'Old queue delivery cannot bypass the new session gate');
    killOwned($second, 'scheduler');
    untilWindows(fn () => LocalEngine::find(1)?->state === 'faulted');
    untilWindows(fn () => ! $manager->alive($second, 'supervisor'));
    verifyWindows(! LocalEngine::find(1)->gate_open && ! $manager->alive($second, 'worker'), 'Scheduler crash faults and stops its owned sibling');
    $manager->start();
    untilWindows(fn () => LocalEngine::find(1)?->state === 'running', 30);
    $third = LocalEngine::findOrFail(1);
    killOwned($third, 'supervisor');
    untilWindows(fn () => ! $manager->alive($third, 'supervisor'), 1);
    verifyWindows($manager->status()['state'] === 'faulted', 'Supervisor death faults the engine');
    untilWindows(fn () => ! $manager->alive($third, 'worker') && ! $manager->alive($third, 'scheduler'), 5);
    verifyWindows(true, 'Orphaned worker and scheduler exit within five seconds');
    $manager->start();
    untilWindows(fn () => LocalEngine::find(1)?->state === 'running', 30);
    $beforeSleep = LocalEngine::findOrFail(1);
    $resume = suspendOwned($beforeSleep);
    try {
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()->subSeconds(16)]);
        verifyWindows($manager->status()['state'] === 'faulted', 'Suspended supervisor with a sleep-length heartbeat gap closes approval');
    } finally {
        $resume();
        $resume = null;
    }
    untilWindows(fn () => ! $manager->alive($beforeSleep, 'supervisor'));
    verifyWindows(count(readWorld($env['SPACETRADERS_FIXTURE_PATH'])['writes']) === 1, 'Crash and wake recovery never replay approvals');
    $manager->start();
    untilWindows(fn () => LocalEngine::find(1)?->state === 'running', 30);
    $force = LocalEngine::findOrFail(1);
    $resume = suspendOwned($force);
    $manager->end();
    LocalEngine::whereKey(1)->update(['state' => 'faulted', 'stop_requested_at' => now()->subSeconds(41)]);
    $manager->forceStop();
    $resume(true);
    $resume = null;
    untilWindows(fn () => ! $manager->alive($force, 'supervisor') && ! $manager->alive($force, 'worker') && ! $manager->alive($force, 'scheduler'));
    verifyWindows(! LocalEngine::find(1)->gate_open && ! $host->registered(), 'Force stop removes only the verified owned processes and task');
    verifyWindows($sentinel->isRunning(), 'Force stop preserves unrelated PHP sentinel');
} catch (Throwable $error) {
    $failure = $error->getMessage();
} finally {
    if ($resume !== null) {
        try {
            $resume();
        } catch (Throwable $error) {
            $cleanupErrors[] = $error->getMessage();
        }
    }
    if ($installed) {
        try {
            $manager->end();
            untilWindows(fn () => ! $manager->alive(LocalEngine::find(1), 'supervisor') && ! $manager->alive(LocalEngine::find(1), 'worker') && ! $manager->alive(LocalEngine::find(1), 'scheduler'), 50);
        } catch (Throwable $error) {
            $cleanupErrors[] = $error->getMessage();
            try {
                LocalEngine::whereKey(1)->update(['state' => 'faulted', 'stop_requested_at' => now()->subSeconds(41)]);
                $manager->forceStop();
            } catch (Throwable $stopError) {
                $cleanupErrors[] = $stopError->getMessage();
            }
        } finally {
            try {
                $manager->uninstall();
            } catch (Throwable $error) {
                $cleanupErrors[] = $error->getMessage();
            }
        }
    }
    $sentinel?->stop(0, 9);
    $removed = ! $host->registered() && ! $host->hasServiceMarker();
    file_put_contents($directory.'/windows-engine-result.json', json_encode(['passed' => $failure === null && $cleanupErrors === [] && $removed, 'failure' => $failure, 'cleanup_errors' => $cleanupErrors, 'steps' => $steps, 'service_removed' => $removed], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo 'Owned Windows lifecycle evidence: '.$directory.PHP_EOL;
}
if ($failure !== null || $cleanupErrors !== [] || ! $removed) {
    fwrite(STDERR, implode(PHP_EOL, array_filter([$failure, ...$cleanupErrors, $removed ? null : 'Temporary task removal was not verified.'])).PHP_EOL);
    exit(1);
}
