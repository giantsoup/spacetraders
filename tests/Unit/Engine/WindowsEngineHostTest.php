<?php

namespace Tests\Unit\Engine;

use App\Gameplay\Engine\EngineLease;
use App\Gameplay\Engine\HostCommands;
use App\Gameplay\Engine\HostResult;
use App\Gameplay\Engine\Windows\WindowsChild;
use App\Gameplay\Engine\Windows\WindowsEngineHost;
use App\Gameplay\GameException;
use App\Models\LocalEngine;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class WindowsEngineHostTest extends TestCase
{
    private RecordingWindowsCommands $commands;

    private WindowsEngineHost $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->commands = new RecordingWindowsCommands;
        $this->app->instance(HostCommands::class, $this->commands);
        $this->host = $this->app->make(WindowsEngineHost::class);
    }

    private function directory(): string
    {
        return config('gameplay.lock_path').'/engine';
    }

    private function php(): string
    {
        $directory = config('gameplay.lock_path').'/PHP & tools';
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/php.exe', '');
        file_put_contents($directory.'/php-win.exe', '');

        return $directory.'/php.exe';
    }

    public function test_install_registers_an_on_demand_utf16_task_without_overwriting(): void
    {
        $php = $this->php();
        $this->host->install($php, ['APP_KEY' => 'private', 'LOG_CHANNEL' => 'stderr']);
        $bytes = file_get_contents($this->directory().'/task.xml');
        $this->assertStringStartsWith("\xFF\xFE", $bytes);
        $xml = mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE');
        foreach (['<MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy>', '<ExecutionTimeLimit>PT0S</ExecutionTimeLimit>',
            '<DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries>', '<StopIfGoingOnBatteries>false</StopIfGoingOnBatteries>',
            '<StopOnIdleEnd>false</StopOnIdleEnd>', '<AllowHardTerminate>true</AllowHardTerminate>', '<Priority>5</Priority>',
            '<LogonType>InteractiveToken</LogonType>', '<RunLevel>LeastPrivilege</RunLevel>', '<UserId>S-1-5-21-123</UserId>',
            'php-win.exe</Command>', 'scripts/engine-launch.php', htmlspecialchars(base_path(), ENT_XML1 | ENT_QUOTES, 'UTF-8')] as $expected) {
            $this->assertStringContainsString($expected, $xml);
        }
        $this->assertStringNotContainsString('<Triggers', $xml);
        $create = $this->commands->calls[array_key_last($this->commands->calls)]['argv'];
        $this->assertSame(['/Create', '/TN', $this->host->serviceName(), '/XML', $this->directory().'/task.xml'], array_slice($create, 1));
        $this->assertNotContains('/F', $create);
        $this->assertTrue($this->host->hasServiceMarker());
        $environment = json_decode(file_get_contents($this->directory().'/service.json'), true);
        $this->assertSame('engine', $environment['LOG_CHANNEL']);
        $this->assertSame(realpath($php), $environment['SPACETRADERS_PHP_BINARY']);
        $this->assertSame($this->directory().'/supervisor.log', $environment['SPACETRADERS_ENGINE_LOG']);
        $this->assertSame('private', $environment['APP_KEY']);
        $this->assertSame($this->directory(), $this->commands->calls[1]['env']['SPACETRADERS_ENGINE_DIRECTORY']);
        $this->assertSame(PHP_OS_FAMILY === 'Windows', $this->host->supported());
        $this->assertSame('Windows', $this->host->platform());
        $this->assertMatchesRegularExpression('/^SpaceTraders-Engine-[a-f0-9]{24}$/', $this->host->serviceName());
    }

    public function test_existing_task_is_refused_before_any_file_is_written(): void
    {
        $this->commands->registered = true;
        try {
            $this->host->install($this->php(), []);
            $this->fail('Existing task must not be overwritten.');
        } catch (GameException $exception) {
            $this->assertStringContainsString('already registered', $exception->getMessage());
        }
        $this->assertCount(1, $this->commands->calls);
        $this->assertFalse($this->host->hasServiceMarker());
    }

    public function test_launch_only_issues_run(): void
    {
        $this->host->launch();
        $this->assertCount(1, $this->commands->calls);
        $this->assertSame(['/Run', '/TN', $this->host->serviceName()], array_slice($this->commands->calls[0]['argv'], 1));
    }

    public function test_failed_acl_hardening_never_writes_secrets_or_registers_a_task(): void
    {
        $this->commands->securityExit = 1;
        try {
            $this->host->install($this->php(), ['APP_KEY' => 'private']);
            $this->fail('An unprotected service must not be installed.');
        } catch (GameException $exception) {
            $this->assertStringContainsString('secure the engine directory', $exception->getMessage());
        }
        $this->assertFalse($this->host->hasServiceMarker());
        $this->assertCount(2, $this->commands->calls);
    }

    public function test_failed_registration_is_reported_without_a_forced_retry(): void
    {
        $this->commands->failOperation = '/Create';
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Could not register');
        $this->host->install($this->php(), []);
    }

    public function test_failed_launch_is_reported_without_other_host_operations(): void
    {
        $this->commands->failOperation = '/Run';
        try {
            $this->host->launch();
            $this->fail('A failed run must be reported.');
        } catch (GameException $exception) {
            $this->assertStringContainsString('Could not start', $exception->getMessage());
        }
        $this->assertCount(1, $this->commands->calls);
    }

    public function test_launcher_rejects_a_marker_outside_the_configured_engine_directory(): void
    {
        mkdir($this->directory(), 0700, true);
        $path = config('gameplay.lock_path').'/service.json';
        file_put_contents($path, '{}');
        $result = (new HostCommands)->run([PHP_BINARY, base_path('scripts/engine-launch.php'), $path], 10, [
            'SPACETRADERS_LOCK_PATH' => config('gameplay.lock_path'),
            'LARAVEL_STORAGE_PATH' => storage_path(),
        ]);
        $this->assertNotSame(0, $result->exit);
        $this->assertStringContainsString('private service.json', $result->out.$result->err);
        $this->assertStringContainsString('private service.json', file_get_contents($this->directory().'/supervisor.log'));
    }

    public function test_launcher_records_preboot_errors_without_dumping_service_secrets(): void
    {
        mkdir($this->directory(), 0700, true);
        $path = $this->directory().'/service.json';
        file_put_contents($path, json_encode(['APP_KEY' => 'synthetic-private-key', 'INVALID' => []]));
        $result = (new HostCommands)->run([PHP_BINARY, base_path('scripts/engine-launch.php'), $path], 10, ['SPACETRADERS_LOCK_PATH' => config('gameplay.lock_path')]);
        $this->assertSame(1, $result->exit);
        $log = file_get_contents($this->directory().'/supervisor.log');
        $this->assertMatchesRegularExpression('/\[\d{4}-\d{2}-\d{2}T.*\] RuntimeException: Invalid engine service environment entry\./', $log);
        $this->assertStringNotContainsString('synthetic-private-key', $log.$result->out.$result->err);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_launcher_rotates_supervisor_log_best_effort_before_boot(bool $blocked): void
    {
        mkdir($this->directory(), 0700, true);
        $log = $this->directory().'/supervisor.log';
        $handle = fopen($log, 'w');
        ftruncate($handle, 10 * 1024 * 1024 + 1);
        fclose($handle);
        if ($blocked) {
            mkdir($log.'.1');
        }
        $path = $this->directory().'/service.json';
        file_put_contents($path, json_encode(['INVALID' => []]));
        $result = (new HostCommands)->run([PHP_BINARY, base_path('scripts/engine-launch.php'), $path], 10, ['SPACETRADERS_LOCK_PATH' => config('gameplay.lock_path')]);
        $this->assertSame(1, $result->exit);
        $this->assertStringContainsString('Invalid engine service environment entry.', file_get_contents($log));
        if ($blocked) {
            $this->assertStringContainsString('Could not rotate supervisor.log', file_get_contents($log));
        } else {
            $this->assertSame(10 * 1024 * 1024 + 1, filesize($log.'.1'));
        }
    }

    public function test_launcher_populates_every_environment_surface_and_artisan_argv(): void
    {
        mkdir($this->directory(), 0700, true);
        $path = $this->directory().'/service.json';
        file_put_contents($path, json_encode([
            'APP_ENV' => 'testing', 'SPACETRADERS_LOCAL_ENGINE_ENABLED' => 'false',
            'APP_CONFIG_CACHE' => storage_path('no-engine-config.php'), 'LARAVEL_STORAGE_PATH' => storage_path(),
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'ENGINE_LAUNCH_TEST' => 'injected-value',
        ]));
        $prepend = $this->directory().'/inspect.php';
        file_put_contents($prepend, '<?php register_shutdown_function(function () { echo json_encode([getenv("ENGINE_LAUNCH_TEST"), $_ENV["ENGINE_LAUNCH_TEST"] ?? null, $_SERVER["ENGINE_LAUNCH_TEST"] ?? null, $_SERVER["argv"]]); });');
        $result = (new HostCommands)->run([PHP_BINARY, '-d', 'auto_prepend_file='.$prepend, base_path('scripts/engine-launch.php'), $path], 10, [
            'SPACETRADERS_LOCK_PATH' => config('gameplay.lock_path'),
            'LARAVEL_STORAGE_PATH' => storage_path(),
        ]);
        $this->assertSame(['injected-value', 'injected-value', 'injected-value', [base_path().'/artisan', 'spacetraders:engine-supervise']], json_decode($result->out, true), $result->err);
    }

    #[TestWith(['command_hash'])]
    #[TestWith(['missing'])]
    #[TestWith(['unreadable'])]
    public function test_force_stop_does_not_kill_an_unverified_identity(string $mismatch): void
    {
        $identity = $this->identity(4321);
        $stored = $identity;
        $stored[$mismatch] = 'different';
        if ($mismatch === 'unreadable') {
            $identity['command_hash'] = null;
        }
        $this->commands->identities = [[$identity]];
        $engine = new LocalEngine(['processes' => ['worker' => 4321, 'identity' => $mismatch === 'missing' ? [] : ['worker' => $stored]]]);
        try {
            $this->host->forceStop($engine);
            $this->fail('Unverified identity must fail closed.');
        } catch (GameException $exception) {
            $this->assertStringStartsWith('Could not confirm', $exception->getMessage());
        }
        $this->assertCount(1, $this->commands->calls);
        $this->assertContains('-EncodedCommand', $this->commands->calls[0]['argv']);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_force_stop_skips_reused_pids_even_when_the_command_line_is_unreadable(bool $unreadable): void
    {
        $stored = ['worker' => $this->identity(1002), 'scheduler' => $this->identity(1003)];
        $reused = $stored['worker'];
        $reused['created_at'] = '2026-11-11T13:00:00.0000000Z';
        $reused['command_hash'] = $unreadable ? null : str_repeat('b', 64);
        $this->commands->identities = [[$reused, $stored['scheduler']], [$reused]];
        $this->host->forceStop(new LocalEngine(['processes' => ['worker' => 1002, 'scheduler' => 1003, 'identity' => $stored]]));
        $kills = array_values(array_filter(array_column($this->commands->calls, 'argv'), fn ($arguments) => in_array('/PID', $arguments, true)));
        $this->assertCount(1, $kills);
        $this->assertSame(['/PID', '1003', '/F'], array_slice($kills[0], 1));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_force_stop_skips_an_unverifiable_pid_only_when_its_session_lease_is_released(bool $unreadable): void
    {
        $session = '11111111-2222-3333-4444-555555555555';
        $identity = $this->identity(4321);
        if ($unreadable) {
            $identity['command_hash'] = null;
        }
        $this->commands->identities = [[$identity], [$identity]];
        Log::partialMock();
        Log::shouldReceive('channel')->with('engine')->andReturnSelf();
        Log::shouldReceive('warning')->once()->with('Skipping unverifiable engine PID because its session lease is released.', [
            'session' => $session, 'role' => 'supervisor', 'pid' => 4321,
        ]);
        $this->host->forceStop(new LocalEngine(['session_id' => $session, 'processes' => ['supervisor' => 4321]]));
        foreach (array_column($this->commands->calls, 'argv') as $arguments) {
            $this->assertNotContains('/PID', $arguments);
        }
    }

    public function test_force_stop_refuses_a_missing_identity_while_its_session_lease_is_held(): void
    {
        $session = '11111111-2222-3333-4444-555555555555';
        $path = EngineLease::path($session, 'supervisor');
        mkdir(dirname($path), 0700, true);
        $lease = fopen($path, 'c+b');
        flock($lease, LOCK_EX);
        $this->commands->identities = [[$this->identity(4321)]];
        try {
            $this->host->forceStop(new LocalEngine(['session_id' => $session, 'processes' => ['supervisor' => 4321]]));
            $this->fail('A live lease without an identity must not be force stopped.');
        } catch (GameException $exception) {
            $this->assertStringContainsString('Could not confirm ownership', $exception->getMessage());
            $this->assertCount(1, $this->commands->calls);
        } finally {
            fclose($lease);
        }
    }

    public function test_force_stop_verifies_every_remaining_process_before_killing_any(): void
    {
        $stored = ['worker' => $this->identity(1002), 'scheduler' => $this->identity(1003)];
        $current = $stored;
        $current['scheduler']['command_hash'] = str_repeat('b', 64);
        $this->commands->identities = [array_values($current)];
        try {
            $this->host->forceStop(new LocalEngine(['processes' => ['worker' => 1002, 'scheduler' => 1003, 'identity' => $stored]]));
            $this->fail('Every identity must be verified before killing any child.');
        } catch (GameException) {
            $this->assertCount(1, $this->commands->calls);
        }
    }

    public function test_force_stop_kills_children_before_supervisor_then_removes_task(): void
    {
        $identities = ['supervisor' => $this->identity(1001), 'worker' => $this->identity(1002), 'scheduler' => $this->identity(1003)];
        $this->commands->identities = [array_values($identities), []];
        $engine = new LocalEngine(['processes' => ['supervisor' => 1001, 'worker' => 1002, 'scheduler' => 1003, 'identity' => $identities]]);
        $this->host->forceStop($engine);
        $calls = array_column($this->commands->calls, 'argv');
        $this->assertSame(['/PID', '1002', '/F'], array_slice($calls[1], 1));
        $this->assertSame(['/PID', '1003', '/F'], array_slice($calls[2], 1));
        $this->assertSame(['/PID', '1001', '/F'], array_slice($calls[3], 1));
        $this->assertSame('/End', $calls[4][1]);
        $this->assertSame('/Delete', $calls[5][1]);
        $this->assertContains('/F', $calls[5]);
        $this->assertSame('/Query', $calls[7][1]);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_force_stop_reports_a_surviving_owned_process_even_if_its_command_line_becomes_unreadable(bool $unreadable): void
    {
        $identity = $this->identity(4321);
        $remaining = $identity;
        if ($unreadable) {
            $remaining['command_hash'] = null;
        }
        $this->commands->identities = [[$identity], [$remaining]];
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Could not confirm all owned engine processes exited');
        $this->host->forceStop(new LocalEngine(['processes' => ['worker' => 4321, 'identity' => ['worker' => $identity]]]));
    }

    public function test_uninstall_verifies_task_removal_before_removing_markers(): void
    {
        mkdir($this->directory(), 0700, true);
        file_put_contents($this->directory().'/service.json', '{}');
        file_put_contents($this->directory().'/task.xml', 'xml');
        $this->host->uninstall();
        $this->assertFileDoesNotExist($this->directory().'/service.json');
        $this->assertFileDoesNotExist($this->directory().'/task.xml');
        $this->assertSame(['/Delete', '/Query'], array_map(fn ($call) => $call['argv'][1], $this->commands->calls));
    }

    public function test_uninstall_keeps_markers_if_task_removal_is_unconfirmed(): void
    {
        mkdir($this->directory(), 0700, true);
        file_put_contents($this->directory().'/service.json', '{}');
        $this->commands->registered = true;
        try {
            $this->host->uninstall();
            $this->fail('Removal must be confirmed.');
        } catch (GameException) {
            $this->assertFileExists($this->directory().'/service.json');
        }
    }

    public function test_install_rotates_oversized_logs(): void
    {
        mkdir($this->directory(), 0700, true);
        $log = $this->directory().'/supervisor.log';
        $handle = fopen($log, 'w');
        ftruncate($handle, 10 * 1024 * 1024 + 1);
        fclose($handle);
        file_put_contents($log.'.1', 'old');
        $this->host->install($this->php(), []);
        $this->assertFileDoesNotExist($log);
        $this->assertSame(10 * 1024 * 1024 + 1, filesize($log.'.1'));
    }

    public function test_rotation_failure_warns_but_does_not_block_installation(): void
    {
        mkdir($this->directory(), 0700, true);
        $log = $this->directory().'/worker.log';
        $handle = fopen($log, 'w');
        ftruncate($handle, 10 * 1024 * 1024 + 1);
        fclose($handle);
        mkdir($log.'.1');
        Log::partialMock();
        Log::shouldReceive('channel')->with('engine')->andReturnSelf();
        Log::shouldReceive('warning')->once()->with('Could not rotate an engine log; continuing with the existing log.', ['path' => $log]);
        $this->host->install($this->php(), []);
        $this->assertTrue($this->host->hasServiceMarker());
        $this->assertFileExists($log);
    }

    public function test_alive_uses_a_lease_not_the_recorded_pid(): void
    {
        $session = '11111111-2222-3333-4444-555555555555';
        $engine = new LocalEngine(['session_id' => $session, 'processes' => ['worker' => getmypid()]]);
        $this->assertFalse($this->host->alive($engine, 'worker'));
        $this->assertSame([], $this->commands->calls);
        $this->assertStringEndsWith($session.'-worker.lease', EngineLease::path($session, 'worker'));
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function test_windows_child_stop_is_cooperative_and_kill_targets_only_the_owned_pid(): void
    {
        $directory = config('gameplay.lock_path');
        mkdir($directory, 0700, true);
        $commands = \Mockery::mock(HostCommands::class);
        $child = new WindowsChild([PHP_BINARY, '-r', 'sleep(30);'], base_path(), [], $directory.'/child.log', $directory.'/child.lease', $commands);
        try {
            $child->requestStop();
            $this->assertTrue($child->isRunning());
            $commands->shouldReceive('run')->once()->withArgs(function (array $arguments) use ($child) {
                return basename(str_replace('\\', '/', $arguments[0])) === 'taskkill.exe'
                    && array_slice($arguments, 1) === ['/PID', (string) $child->pid(), '/F'];
            })->andReturnUsing(fn (array $arguments) => (new HostCommands)->run($arguments));
            $child->kill();
            $deadline = hrtime(true) + 5_000_000_000;
            while ($child->isRunning() && hrtime(true) < $deadline) {
                usleep(20000);
            }
            $this->assertFalse($child->isRunning());
        } finally {
            if ($child->isRunning()) {
                (new HostCommands)->run([getenv('SystemRoot').'/System32/taskkill.exe', '/PID', (string) $child->pid(), '/F']);
            }
        }
    }

    private function identity(int $pid): array
    {
        return ['pid' => $pid, 'created_at' => '2026-11-11T12:00:00.0000000Z', 'command_hash' => str_repeat('a', 64)];
    }
}

class RecordingWindowsCommands extends HostCommands
{
    public array $calls = [];

    public bool $registered = false;

    public array $identities = [];

    public int $securityExit = 0;

    public ?string $failOperation = null;

    public function run(array $argv, int $timeout = 10, array $env = []): HostResult
    {
        $this->calls[] = compact('argv', 'timeout', 'env');
        if (in_array('-EncodedCommand', $argv, true)) {
            return isset($env['SPACETRADERS_ENGINE_PIDS'])
                ? new HostResult(0, json_encode(array_shift($this->identities) ?? []))
                : new HostResult($this->securityExit, 'S-1-5-21-123');
        }

        return new HostResult($argv[1] === $this->failOperation || ($argv[1] === '/Query' && ! $this->registered) ? 1 : 0);
    }
}
