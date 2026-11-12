<?php

namespace Tests\Unit\Engine;

use App\Gameplay\Engine\EngineIdentity;
use App\Gameplay\Engine\HostCommands;
use App\Gameplay\Engine\HostResult;
use App\Gameplay\Engine\LaunchdEngineHost;
use App\Gameplay\Engine\PosixChild;
use App\Gameplay\GameException;
use App\Gameplay\LocalEngineManager;
use App\Models\LocalEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LaunchdEngineHostTest extends TestCase
{
    private HostCommands $commands;

    private LaunchdEngineHost $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->commands = new class extends HostCommands
        {
            public array $calls = [];

            public array $results = [];

            public function run(array $argv, int $timeout = 10, array $env = []): HostResult
            {
                $this->calls[] = [$argv, $timeout, $env];
                if ($this->results === []) {
                    throw new \LogicException('Unexpected host command.');
                }
                $result = array_shift($this->results);
                if ($result instanceof \Throwable) {
                    throw $result;
                }

                return $result;
            }
        };
        $this->app->instance(HostCommands::class, $this->commands);
        $this->host = $this->app->makeWith(LaunchdEngineHost::class, ['uid' => 501]);
    }

    public function test_identity_is_shared_and_platform_support_is_explicit(): void
    {
        $expected = 'io.spacetraders.engine.'.substr(hash('sha256', realpath(base_path()).'|'.config('database.connections.sqlite.database')), 0, 24);
        $this->assertSame($expected, $this->host->serviceName());
        $this->assertSame($expected, EngineIdentity::label());
        $this->assertSame($expected, (new LocalEngineManager(new \App\Gameplay\HostLock, new \App\Gameplay\EngineGate, $this->host))->label());
        $this->assertSame('macOS', $this->host->platform());
        $this->assertSame(PHP_OS_FAMILY === 'Darwin', $this->host->supported());
        $this->assertFalse($this->host->hasServiceMarker());
        $this->assertSame(['supervisor' => EngineIdentity::directory().'/supervisor.log'], $this->host->logPaths());
        $this->host->claim('session', 'supervisor');
        $this->assertSame([], $this->host->identify(['supervisor' => getmypid()]));
        $this->assertSame([], $this->commands->calls);
    }

    public function test_registration_queries_the_exact_user_target(): void
    {
        $this->commands->results = [new HostResult(0), new HostResult(3)];
        $this->assertTrue($this->host->registered());
        $this->assertFalse($this->host->registered());
        $this->assertSame([
            [['/bin/launchctl', 'print', $this->target()], 10, []],
            [['/bin/launchctl', 'print', $this->target()], 10, []],
        ], $this->commands->calls);
    }

    public function test_install_preserves_the_complete_plist_and_bootstrap_arguments(): void
    {
        $this->commands->results = [new HostResult(0)];
        $php = '/Applications/PHP & Tools/php';
        $env = ['APP_ENV' => 'local', 'APP_KEY' => 'secret<&"\'>', 'CACHE_PATH' => 'a&b'];
        $this->host->install($php, $env);
        $directory = EngineIdentity::directory();
        $xml = fn ($value) => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $expected = '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd"><plist version="1.0"><dict>'
            .'<key>Label</key><string>'.$this->host->serviceName().'</string><key>ProgramArguments</key><array>'
            .'<string>/Applications/PHP &amp; Tools/php</string><string>'.$xml(base_path('artisan')).'</string><string>spacetraders:engine-supervise</string>'
            .'</array><key>WorkingDirectory</key><string>'.$xml(base_path()).'</string><key>EnvironmentVariables</key><dict>'
            .'<key>APP_ENV</key><string>local</string><key>APP_KEY</key><string>secret&lt;&amp;&quot;&apos;&gt;</string><key>CACHE_PATH</key><string>a&amp;b</string>'
            .'</dict><key>RunAtLoad</key><false/><key>KeepAlive</key><false/><key>ExitTimeOut</key><integer>45</integer><key>ThrottleInterval</key><integer>1</integer>'
            .'<key>StandardOutPath</key><string>'.$xml($directory.'/supervisor.log').'</string><key>StandardErrorPath</key><string>'.$xml($directory.'/supervisor.log').'</string></dict></plist>';
        $this->assertSame($expected, file_get_contents($directory.'/service.plist'));
        $this->assertSame('', file_get_contents($directory.'/supervisor.log'));
        $this->assertTrue($this->host->hasServiceMarker());
        $this->assertSame([[['/bin/launchctl', 'bootstrap', 'gui/501', $directory.'/service.plist'], 10, []]], $this->commands->calls);
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0700, fileperms($directory) & 0777);
            $this->assertSame(0600, fileperms($directory.'/service.plist') & 0777);
            $this->assertSame(0600, fileperms($directory.'/supervisor.log') & 0777);
        }
    }

    public function test_launch_never_uses_the_restart_flag(): void
    {
        $this->commands->results = [new HostResult(0)];
        $this->host->launch();
        $this->assertSame([[['/bin/launchctl', 'kickstart', $this->target()], 10, []]], $this->commands->calls);
    }

    public function test_force_stop_kills_before_removing_only_the_owned_service(): void
    {
        $this->commands->results = [new HostResult(0), new HostResult(0)];
        $this->host->forceStop(new LocalEngine);
        $this->assertSame([
            [['/bin/launchctl', 'kill', 'SIGKILL', $this->target()], 10, []],
            [['/bin/launchctl', 'bootout', $this->target()], 10, []],
        ], $this->commands->calls);
    }

    public function test_uninstall_verifies_absence_before_deleting_only_the_marker(): void
    {
        $this->commands->results = [new HostResult(0), new HostResult(5), new HostResult(3)];
        $this->host->install('/php', []);
        $this->commands->calls = [];
        $this->host->uninstall();
        $this->assertFalse($this->host->hasServiceMarker());
        $this->assertFileExists(EngineIdentity::directory().'/supervisor.log');
        $this->assertSame([
            [['/bin/launchctl', 'bootout', $this->target()], 10, []],
            [['/bin/launchctl', 'print', $this->target()], 10, []],
        ], $this->commands->calls);
    }

    #[DataProvider('operationFailures')]
    public function test_operation_errors_preserve_the_original_messages(string $operation, array $exits, string $message, array $arguments): void
    {
        $this->commands->results = [new HostResult(0)];
        $this->host->install('/php', []);
        $this->commands->calls = [];
        $this->commands->results = array_map(fn ($exit) => new HostResult($exit), $exits);
        try {
            match ($operation) {
                'install' => $this->host->install('/php', []),
                'launch' => $this->host->launch(),
                'forceStop' => $this->host->forceStop(new LocalEngine),
                'uninstall' => $this->host->uninstall(),
            };
            $this->fail('Expected a host operation failure.');
        } catch (GameException $exception) {
            $this->assertSame($message, $exception->getMessage());
            $expected = array_map(fn ($argv) => [array_merge(['/bin/launchctl'], array_map(fn ($arg) => match ($arg) {
                'TARGET' => $this->target(),
                'PLIST' => EngineIdentity::directory().'/service.plist',
                default => $arg,
            }, $argv)), 10, []], $arguments);
            $this->assertSame($expected, $this->commands->calls);
            $this->assertTrue($this->host->hasServiceMarker());
        }
    }

    public static function operationFailures(): array
    {
        return [
            'bootstrap' => ['install', [1], 'Herd user launchd domain rejected the owned service. Engine remains off.', [['bootstrap', 'gui/501', 'PLIST']]],
            'kickstart' => ['launch', [1], 'launchd did not start the owned engine.', [['kickstart', 'TARGET']]],
            'kill' => ['forceStop', [1], 'Could not force-stop the fixed owned supervisor. Inspect its state locally.', [['kill', 'SIGKILL', 'TARGET']]],
            'bootout' => ['forceStop', [0, 1], 'Could not confirm removal of the owned service. Inspect its process state locally.', [['kill', 'SIGKILL', 'TARGET'], ['bootout', 'TARGET']]],
            'removal verification' => ['uninstall', [0, 0], 'Owned service removal could not be verified.', [['bootout', 'TARGET'], ['print', 'TARGET']]],
        ];
    }

    public function test_command_timeout_keeps_the_launchd_specific_message(): void
    {
        $this->commands->results = [new GameException('Timed out waiting for an owned engine host operation. Inspect engine state before retrying.')];
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Timed out waiting for the owned launchd operation. Inspect engine state before retrying.');
        $this->host->registered();
    }

    public function test_liveness_uses_the_role_pid_and_is_safe_without_posix(): void
    {
        $engine = new LocalEngine(['processes' => ['supervisor' => getmypid(), 'worker' => 'not-a-pid', 'scheduler' => -1]]);
        $this->assertSame(function_exists('posix_kill'), $this->host->alive($engine, 'supervisor'));
        $this->assertFalse($this->host->alive($engine, 'worker'));
        $this->assertFalse($this->host->alive($engine, 'scheduler'));
        $this->assertFalse($this->host->alive($engine, 'missing'));
        $this->assertSame([], $this->commands->calls);
    }

    public function test_posix_child_exposes_the_process_and_does_not_signal_a_stopped_child(): void
    {
        $process = $this->createMock(Process::class);
        $process->expects($this->once())->method('getPid')->willReturn(123);
        $process->expects($this->exactly(4))->method('isRunning')->willReturn(false);
        $process->expects($this->never())->method('signal');
        $process->expects($this->never())->method('stop');
        $child = new PosixChild($process);
        $this->assertSame(123, $child->pid());
        $this->assertFalse($child->isRunning());
        $this->assertFalse($child->ready(10));
        $child->requestStop();
        $child->kill();
    }

    private function target(): string
    {
        return 'gui/501/'.$this->host->serviceName();
    }
}
