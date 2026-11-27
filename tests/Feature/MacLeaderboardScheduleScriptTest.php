<?php

namespace Tests\Feature;

use App\Gameplay\HostLock;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class MacLeaderboardScheduleScriptTest extends TestCase
{
    private const LABEL = 'local.spacetraders.leaderboard-capture';

    private string $fixture;

    private string $root;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('The deploy scripts use Unix process and FIFO semantics.');
        }
        $this->fixture = sys_get_temp_dir().'/st-mac-deploy-'.bin2hex(random_bytes(8));
        $this->root = $this->fixture.'/checkout';
        foreach (['/bin', '/home', '/tmp', '/checkout/app/Gameplay', '/checkout/scripts/mac'] as $directory) {
            mkdir($this->fixture.$directory, 0700, true);
        }
        foreach (['app/Gameplay/HostLock.php', 'scripts/mac/deploy.sh', 'scripts/mac/leaderboard-capture.sh'] as $file) {
            copy($this->source().'/'.$file, $this->root.'/'.$file);
            chmod($this->root.'/'.$file, 0755);
        }
        file_put_contents($this->root.'/.env', "APP_URL=http://spacetraders.test\n");
        $this->stubs();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            (new Filesystem)->deleteDirectory($this->fixture);
        }
    }

    public function test_install_lints_and_loads_a_checkout_bound_job_idempotently(): void
    {
        foreach (['deploy.sh', 'leaderboard-capture.sh'] as $script) {
            $this->assertSame(0, $this->process(['/bin/bash', '-n', $this->root.'/scripts/mac/'.$script])->run());
        }
        $rendered = $this->script('leaderboard-capture.sh', '--print-plist')->mustRun()->getOutput();
        $this->assertStringContainsString('<integer>600</integer>', $rendered);
        $this->assertStringContainsString('<key>RunAtLoad</key>', $rendered);
        $this->assertStringContainsString('<key>ProcessType</key>', $rendered);
        $this->assertStringContainsString('<string>'.$this->root.'</string>', $rendered);
        $this->assertStringNotContainsString('KeepAlive', $rendered);
        $path = $this->fixture.'/rendered.plist';
        file_put_contents($path, $rendered);
        $this->assertSame(0, $this->process(['plutil', '-lint', '--', $path])->run());

        $this->assertSame(3, $this->script('leaderboard-capture.sh', '--status')->run());
        $this->script('leaderboard-capture.sh', '--install')->mustRun();
        $this->script('leaderboard-capture.sh', '--install')->mustRun();
        $this->assertSame(1, substr_count($this->trace(), 'launchctl bootstrap'));
        $this->assertStringContainsString('current, loaded: yes', $this->script('leaderboard-capture.sh', '--status')->mustRun()->getOutput());
        $this->script('leaderboard-capture.sh', '--run')->mustRun();
        $this->assertStringContainsString('artisan leaderboard:capture --if-due', $this->trace());
        $this->script('leaderboard-capture.sh', '--uninstall')->mustRun();
        $this->assertFileDoesNotExist($this->fixture.'/home/Library/LaunchAgents/'.self::LABEL.'.plist');
        $this->assertSame(3, $this->script('leaderboard-capture.sh', '--status')->run());
    }

    public function test_install_and_uninstall_refuse_a_development_checkout(): void
    {
        file_put_contents($this->root.'/.env', "APP_URL=http://spacetraders-dev.test\n");
        foreach (['--install', '--uninstall'] as $mode) {
            $command = $this->script('leaderboard-capture.sh', $mode);
            $this->assertSame(1, $command->run());
            $this->assertStringContainsString('require the production checkout', $command->getErrorOutput());
        }
        $this->assertSame('', $this->trace());
    }

    #[DataProvider('engineStates')]
    public function test_apply_installs_after_health_checks_and_the_optional_engine_restart(string $state): void
    {
        $deploy = $this->script('deploy.sh', '--yes')->mustRun(env: ['ENGINE_STATE' => $state]);
        $trace = $this->trace();
        $install = strpos($trace, 'launchctl bootstrap');
        $this->assertNotFalse($install);
        $this->assertLessThan($install, strpos($trace, 'curl'));
        if ($state === 'running') {
            $this->assertLessThan($install, strpos($trace, 'artisan spacetraders:engine-service start'));
            $this->assertLessThan(strpos($deploy->getOutput(), 'Installed'), strpos($deploy->getOutput(), 'OK: Engine restarted.'));
        } else {
            $this->assertStringNotContainsString('engine-service start', $trace);
        }
        $this->assertLessThan(strpos($deploy->getOutput(), 'Deployed'), strpos($deploy->getOutput(), 'Installed'));
        $this->assertLockReleased();
    }

    public static function engineStates(): array
    {
        return [['off'], ['running']];
    }

    public function test_loaded_job_refusing_bootout_aborts_before_engine_or_backup(): void
    {
        touch($this->fixture.'/loaded');
        $deploy = $this->script('deploy.sh', '--yes');
        $this->assertSame(1, $deploy->run(env: ['BOOTOUT_STUCK' => '1', 'ENGINE_STATE' => 'running']));
        $this->assertStringContainsString('Could not bootout', $deploy->getErrorOutput());
        $this->assertFileExists($this->fixture.'/loaded');
        $this->assertStringContainsString('Leaderboard capture may have been stopped', $deploy->getErrorOutput());
        $this->assertQuiesceFailed($deploy);
    }

    public function test_fifo_setup_failure_after_bootout_reports_how_to_restore_capture(): void
    {
        touch($this->fixture.'/loaded');
        $this->stub('/bin/mkfifo', 'exit 42');

        $deploy = $this->script('deploy.sh', '--yes');
        $this->assertSame(42, $deploy->run(env: ['ENGINE_STATE' => 'running']));
        $this->assertStringContainsString('launchctl bootout', $this->trace());
        $this->assertFileDoesNotExist($this->fixture.'/loaded');
        $this->assertQuiesceFailed($deploy);
    }

    public function test_lock_directory_failure_reports_the_reinstall_reminder_once(): void
    {
        touch($this->fixture.'/loaded');
        $bashEnv = $this->fixture.'/mktemp-failure.sh';
        file_put_contents($bashEnv, "mktemp() { return 42; }\n");

        $deploy = $this->script('deploy.sh', '--yes');
        $this->assertSame(42, $deploy->run(env: ['BASH_ENV' => $bashEnv, 'ENGINE_STATE' => 'running']));
        $this->assertQuiesceFailed($deploy);
        $this->assertSame(1, substr_count($deploy->getErrorOutput(), 'leaderboard-capture.sh --install'));
        $this->assertStringContainsString('Could not create the leaderboard capture lock directory', $deploy->getErrorOutput());
    }

    public function test_signal_at_bootout_reports_how_to_restore_capture(): void
    {
        touch($this->fixture.'/loaded');
        $deploy = $this->script('deploy.sh', '--yes');

        $this->assertSame(143, $deploy->run(env: ['SIGNAL_AFTER_BOOTOUT' => '1', 'ENGINE_STATE' => 'running']));
        $this->assertFileDoesNotExist($this->fixture.'/loaded');
        $this->assertQuiesceFailed($deploy);
        $this->assertStringNotContainsString('engine was ended', $deploy->getErrorOutput());
    }

    #[DataProvider('deploySignals')]
    public function test_signal_at_engine_shutdown_completion_reports_how_to_recover(string $signal, int $status): void
    {
        touch($this->fixture.'/loaded');
        $deploy = $this->script('deploy.sh', '--yes');

        $this->assertSame($status, $deploy->run(env: ['SIGNAL_DURING_ENGINE_END' => $signal, 'ENGINE_STATE' => 'running']));
        $this->assertStringContainsString('engine-service end', $this->trace());
        $this->assertStringContainsString('spacetraders:engine-service status', $deploy->getErrorOutput());
        $this->assertStringContainsString('spacetraders:engine-service start', $deploy->getErrorOutput());
        $this->assertStringContainsString('leaderboard-capture.sh --install', $deploy->getErrorOutput());
        $this->assertStringNotContainsString('backup called', $this->trace());
        $this->assertLockReleased();
    }

    #[DataProvider('deploySignals')]
    public function test_signal_after_engine_stop_reports_both_recovery_commands(string $signal, int $status): void
    {
        touch($this->fixture.'/loaded');
        $deploy = $this->script('deploy.sh', '--yes');

        $this->assertSame($status, $deploy->run(env: ['SIGNAL_DURING_BACKUP' => $signal, 'ENGINE_STATE' => 'running']));
        $this->assertStringContainsString('leaderboard-capture.sh --install', $deploy->getErrorOutput());
        $this->assertStringContainsString('engine was ended', $deploy->getErrorOutput());
        $this->assertStringNotContainsString('launchctl bootstrap', $this->trace());
        $this->assertLockReleased();
    }

    public static function deploySignals(): array
    {
        return [['HUP', 129], ['INT', 130], ['TERM', 143]];
    }

    public function test_signal_during_abort_reminders_does_not_repeat_them(): void
    {
        touch($this->fixture.'/loaded');
        $bashEnv = $this->fixture.'/interrupt-reminders.sh';
        file_put_contents($bashEnv, <<<'BASH'
            interrupt_reminder() {
                if [[ "$*" == *'Run ./scripts/mac/leaderboard-capture.sh --install'* && "${reminder_interrupted:-0}" == 0 ]]; then
                    reminder_interrupted=1
                    kill -TERM "$$"
                fi
            }
            echo() {
                builtin echo "$@"
                interrupt_reminder "$@"
            }
            printf() {
                builtin printf "$@"
                interrupt_reminder "$@"
            }
            BASH);
        $deploy = $this->script('deploy.sh', '--yes');

        $this->assertSame(143, $deploy->run(env: ['BASH_ENV' => $bashEnv, 'BOOTOUT_STUCK' => '1']));
        $this->assertQuiesceFailed($deploy);
        $this->assertSame(1, substr_count($deploy->getErrorOutput(), 'leaderboard-capture.sh --install'));
    }

    public function test_deploy_preview_preserves_an_unloaded_job_and_exits_successfully(): void
    {
        $deploy = $this->script('deploy.sh')->mustRun(env: ['ENGINE_STATE' => 'running']);
        $this->assertStringContainsString('(will install/update on --yes)', $deploy->getOutput());
        $this->assertStringNotContainsString('launchctl bootout', $this->trace());
        $this->assertStringNotContainsString('launchctl bootstrap', $this->trace());
        $this->assertFileDoesNotExist(HostLock::leaderboardCaptureLockPath($this->root));
        $this->assertNoDeployWrites();
    }

    public function test_backup_failure_drops_the_fifo_writer_and_releases_the_lock(): void
    {
        touch($this->fixture.'/loaded');
        $deploy = $this->script('deploy.sh', '--yes');
        $this->assertSame(42, $deploy->run(env: ['BACKUP_FAILS' => '1', 'ENGINE_STATE' => 'running']));
        $this->assertStringContainsString('backup fd 8 closed', $this->trace());
        $this->assertStringContainsString('leaderboard-capture.sh --install', $deploy->getErrorOutput());
        $this->assertStringContainsString('engine was ended', $deploy->getErrorOutput());
        $this->assertLockReleased();
    }

    public function test_holder_dying_before_opening_the_fifo_cannot_hang_deploy(): void
    {
        touch($this->fixture.'/loaded');
        $path = $this->root.'/scripts/mac/deploy.sh';
        $source = file_get_contents($path);
        $start = strpos($source, '    "$php_bin" -r', strpos($source, 'leaderboard_fd_open=1'));
        $end = strpos($source, "\n    holder_pid=$!", $start);
        $source = substr($source, 0, $start)."    (\n        /bin/sh -c 'kill -KILL \"\$PPID\"'\n"
            .rtrim(substr($source, $start, $end - $start), " &\n")."\n    ) &".substr($source, $end);
        file_put_contents($path, $source);

        $deploy = $this->script('deploy.sh', '--yes');
        $this->assertSame(1, $deploy->run(env: ['LEADERBOARD_QUIESCE_SECONDS' => '1', 'ENGINE_STATE' => 'running']));
        $this->assertStringContainsString('lock holder failed to start', $deploy->getErrorOutput());
        $this->assertStringNotContainsString('php holder started', $this->trace());
        $this->assertQuiesceFailed($deploy);
    }

    public function test_deploy_waits_for_the_real_manual_cli_even_with_a_different_cached_lock_path(): void
    {
        touch($this->fixture.'/loaded');
        $manual = $this->prepareArtisan();
        try {
            $manual->start();
            $this->assertTrue($manual->waitUntil(fn ($type, $output) => str_contains($output, "HTTP_PAUSED\n")),
                $manual->getErrorOutput().$manual->getOutput());
            $this->assertFileExists(HostLock::leaderboardCaptureLockPath($this->root));
            $deploy = $this->script('deploy.sh', '--yes');
            $this->assertSame(1, $deploy->run(env: ['SPACETRADERS_LOCK_PATH' => $this->fixture.'/different-locks',
                'LEADERBOARD_QUIESCE_SECONDS' => '1', 'ENGINE_STATE' => 'running']));
            $this->assertStringContainsString('Leaderboard capture lock is busy', $deploy->getErrorOutput());
            $this->assertQuiesceFailed($deploy);
            $this->assertTrue($manual->isRunning());
            touch($this->fixture.'/resume-http');
            $this->assertSame(0, $manual->wait(), $manual->getErrorOutput());
            $this->assertStringContainsString('Saved snapshot', $manual->getOutput());
            $this->assertLockReleased();
        } finally {
            $manual->stop();
        }
    }

    private function prepareArtisan(): Process
    {
        foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'resources', 'storage'] as $directory) {
            if ($directory === 'app') {
                (new Filesystem)->copyDirectory($this->source().'/app', $this->root.'/app');
            } elseif ($directory === 'bootstrap') {
                (new Filesystem)->ensureDirectoryExists($this->root.'/bootstrap/cache');
            } elseif ($directory === 'storage') {
                (new Filesystem)->ensureDirectoryExists($this->root.'/storage/framework');
            } else {
                symlink($this->source().'/'.$directory, $this->root.'/'.$directory);
            }
        }
        copy($this->source().'/bootstrap/app.php', $this->root.'/bootstrap/app.php');
        copy($this->source().'/composer.json', $this->root.'/composer.json');
        symlink($this->source().'/vendor', $this->root.'/vendor');
        file_put_contents($this->root.'/artisan', <<<'PHP'
            <?php
            require __DIR__.'/vendor/autoload.php';
            $app = require __DIR__.'/bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            if (in_array('leaderboard:capture', $argv, true)) {
                Illuminate\Support\Facades\Http::fake(function () {
                    echo "HTTP_PAUSED\n";
                    fflush(STDOUT);
                    $deadline = microtime(true) + 10;
                    while (!is_file(getenv('FIXTURE').'/resume-http') && microtime(true) < $deadline) {
                        usleep(25000);
                    }
                    return Illuminate\Support\Facades\Http::response([
                        'resetDate' => '2026-11-20', 'leaderboards' => ['mostCredits' => [], 'mostSubmittedCharts' => []],
                    ]);
                });
            }
            exit($app->handleCommand(new Symfony\Component\Console\Input\ArgvInput));
            PHP);
        $db = $this->fixture.'/capture.sqlite';
        touch($db);
        $env = ['APP_ENV' => 'local', 'APP_CONFIG_CACHE' => $this->fixture.'/cached.php',
            'APP_ROUTES_CACHE' => $this->fixture.'/routes.php', 'DB_DATABASE' => $db, 'DATABASE_URL' => '',
            'SPACETRADERS_LOCK_PATH' => $this->fixture.'/cached-locks', 'SPACETRADERS_REQUEST_BUDGET_ENABLED' => 'false'];
        $this->process([PHP_BINARY, 'artisan', 'migrate', '--force'])->mustRun(env: $env);
        $this->process([PHP_BINARY, 'artisan', 'config:cache'])->mustRun(env: $env);

        $env['SPACETRADERS_LOCK_PATH'] = $this->fixture.'/capture-override';

        return $this->process([PHP_BINARY, 'artisan', 'leaderboard:capture', '--top-only'], $env);
    }

    private function stubs(): void
    {
        $this->stub('/bin/git', <<<'BASH'
            case "$1" in
                branch) echo main ;;
                rev-parse) echo abc1234567890 ;;
                status|fetch|merge-base|checkout|clean) exit 0 ;;
                *) exit 2 ;;
            esac
            BASH);
        $this->stub('/bin/launchctl', <<<'BASH'
            echo "launchctl $*" >>"$FIXTURE/trace"
            case "$1" in
                print) [[ "$2" == */local.spacetraders.leaderboard-capture && -f "$FIXTURE/loaded" ]] ;;
                bootout)
                    [[ "${BOOTOUT_STUCK:-0}" == 1 ]] || rm -f "$FIXTURE/loaded"
                    if [[ "${SIGNAL_AFTER_BOOTOUT:-0}" == 1 ]]; then
                        kill -TERM "$PPID"
                    fi
                    ;;
                bootstrap) touch "$FIXTURE/loaded" ;;
                *) exit 2 ;;
            esac
            BASH);
        $this->stub('/checkout/scripts/herd-php', <<<'BASH'
            if [[ "$1" == "-r" ]]; then
                if [[ "$2" == *leaderboardCaptureLockPath* ]]; then
                    echo "php holder started" >>"$FIXTURE/trace"
                fi
                exec "$HERD_PHP85_BIN" "$@"
            fi
            echo "$*" >>"$FIXTURE/trace"
            if [[ "$*" == "artisan spacetraders:engine-service status" ]]; then
                echo "Engine state: ${ENGINE_STATE:-off}"
            fi
            if [[ "$*" == "artisan spacetraders:engine-service end" && -n "${SIGNAL_DURING_ENGINE_END:-}" ]]; then
                kill -s "$SIGNAL_DURING_ENGINE_END" "$PPID" "$$"
            fi
            BASH);
        $this->stub('/checkout/scripts/mac/backup.sh', <<<'BASH'
            echo "backup called" >>"$FIXTURE/trace"
            if ( : >&8 ) 2>/dev/null; then
                echo "backup inherited fd 8" >>"$FIXTURE/trace"
                exit 43
            fi
            echo "backup fd 8 closed" >>"$FIXTURE/trace"
            if [[ "${BACKUP_FAILS:-0}" == 1 ]]; then
                exit 42
            fi
            if [[ -n "${SIGNAL_DURING_BACKUP:-}" ]]; then
                kill -s "$SIGNAL_DURING_BACKUP" "$PPID" "$$"
            fi
            BASH);
        foreach (['/bin/pnpm', '/bin/composer', '/checkout/scripts/composer'] as $script) {
            $this->stub($script, 'exit 0');
        }
        $this->stub('/bin/curl', <<<'BASH'
            echo "curl" >>"$FIXTURE/trace"
            while [[ $# -gt 0 ]]; do
                if [[ "$1" == "-o" ]]; then
                    echo '<link href="/build/assets/fixture.css">' >"$2"
                    break
                fi
                shift
            done
            echo -n 200
            BASH);
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->stub('/bin/plutil', <<<'BASH'
                [[ "$1" == "-lint" && "$2" == "--" ]]
                exec /usr/bin/python3 -c 'import plistlib, sys; plistlib.load(open(sys.argv[1], "rb"))' "$3"
                BASH);
        }
        $this->stub('/bin/tailscale', 'exit 0');
    }

    private function stub(string $path, string $source): void
    {
        $file = $this->fixture.$path;
        file_put_contents($file, "#!/bin/bash\nset -euo pipefail\n".$source."\n");
        chmod($file, 0755);
    }

    private function script(string $name, string ...$arguments): Process
    {
        return $this->process(['/bin/bash', $this->root.'/scripts/mac/'.$name, ...$arguments]);
    }

    private function process(array $command, array $env = []): Process
    {
        return new Process($command, $this->root, $env + [
            'PATH' => $this->fixture.'/bin:/usr/bin:/bin:/usr/sbin:/sbin',
            'HOME' => $this->fixture.'/home', 'TMPDIR' => $this->fixture.'/tmp',
            'HERD_PHP85_BIN' => PHP_BINARY, 'LAUNCHCTL_BIN' => $this->fixture.'/bin/launchctl',
            'COMPOSER_BIN' => $this->fixture.'/bin/composer', 'FIXTURE' => $this->fixture,
            'APP_CONFIG_CACHE' => false, 'APP_ROUTES_CACHE' => false, 'DATABASE_URL' => false,
            'DB_DATABASE' => false, 'SPACETRADERS_FIXTURE_PATH' => false, 'BASH_ENV' => false,
            'SPACETRADERS_LOCAL_ENGINE_ENABLED' => 'false', 'SPACETRADERS_AUTOMATION_ENABLED' => 'false',
        ], timeout: 15);
    }

    private function trace(): string
    {
        return is_file($this->fixture.'/trace') ? file_get_contents($this->fixture.'/trace') : '';
    }

    private function assertQuiesceFailed(Process $deploy): void
    {
        $this->assertStringContainsString('leaderboard-capture.sh --install', $deploy->getErrorOutput());
        $this->assertNoDeployWrites();
        $this->assertSame([], glob($this->fixture.'/tmp/*'));
    }

    private function assertNoDeployWrites(): void
    {
        foreach (['engine-service end', 'backup called', 'config:clear'] as $write) {
            $this->assertStringNotContainsString($write, $this->trace());
        }
    }

    private function assertLockReleased(): void
    {
        $lock = fopen(HostLock::leaderboardCaptureLockPath($this->root), 'c');
        try {
            $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        } finally {
            fclose($lock);
        }
        $this->assertSame([], glob($this->fixture.'/tmp/*'));
    }

    private function source(): string
    {
        return dirname(__DIR__, 2);
    }
}
