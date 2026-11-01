<?php

namespace App\Gameplay;

use App\Models\LocalEngine;
use Illuminate\Http\Request;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class LocalEngineManager
{
    public function __construct(private HostLock $lock, private EngineGate $gate) {}

    public function authorize(Request $request): void
    {
        abort_unless(app()->environment('local') && $this->gate->enabled()
            && (string) $request->user()->id === (string) config('gameplay.operator_id')
            && $request->getHost() === config('gameplay.local_host')
            && in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);
    }

    public function label(): string
    {
        return 'io.spacetraders.engine.'.substr(hash('sha256', realpath(base_path()).'|'.config('database.connections.sqlite.database')), 0, 24);
    }

    public function directory(): string
    {
        return config('gameplay.lock_path').'/engine';
    }

    private function target(): string
    {
        return 'gui/'.posix_geteuid().'/'.$this->label();
    }

    private function launchctl(array $arguments): Process
    {
        $process = new Process(array_merge(['/bin/launchctl'], $arguments));
        try {
            $process->setTimeout(10)->run();
        } catch (ProcessTimedOutException) {
            throw new GameException('Timed out waiting for the owned launchd operation. Inspect engine state before retrying.');
        }

        return $process;
    }

    public function install(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin' || ! app()->environment('local') || ! $this->gate->enabled() || ! config('gameplay.operator_id') || config('database.default') !== 'sqlite') {
            throw new GameException('Engine installation requires macOS, local mode, an operator, SQLite and the local engine feature.');
        }
        $php = realpath(config('gameplay.php_binary'));
        if (! $php || ! is_executable($php)) {
            throw new GameException('Configure an absolute executable PHP 8.4 path.');
        }
        $runtime = new Process([$php, '-r', 'exit(PHP_VERSION_ID >= 80401 && PHP_VERSION_ID < 80500 ? 0 : 1);']);
        $runtime->run();
        if (! $runtime->isSuccessful()) {
            throw new GameException('Configure an absolute PHP 8.4 executable.');
        }
        $this->lock->run('engine-control', function () use ($php) {
            if ($this->launchctl(['print', $this->target()])->isSuccessful()) {
                throw new GameException('Owned engine is already installed. End the session and uninstall before reconfiguration.');
            }
            $directory = $this->directory();
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            chmod($directory, 0700);
            $env = [
                'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'),
                'APP_CONFIG_CACHE' => $directory.'/no-config.php', 'LARAVEL_STORAGE_PATH' => storage_path(),
                'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => config('database.connections.sqlite.database'), 'DATABASE_URL' => '',
                'QUEUE_CONNECTION' => 'database', 'CACHE_STORE' => 'array', 'CACHE_DRIVER' => 'array', 'LOG_CHANNEL' => 'stderr',
                'SPACETRADERS_LOCK_PATH' => config('gameplay.lock_path'), 'SPACETRADERS_FIXTURE_PATH' => config('gameplay.fixture_path') ?: '',
                'SPACETRADERS_AUTOMATION_ENABLED' => config('gameplay.enabled') ? 'true' : 'false',
                'SPACETRADERS_LOCAL_ENGINE_ENABLED' => 'true', 'SPACETRADERS_OPERATOR_ID' => (string) config('gameplay.operator_id'),
                'SPACETRADERS_LOCAL_HOST' => config('gameplay.local_host'), 'SPACETRADERS_PHP_BINARY' => $php,
            ];
            $xml = fn ($value) => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $plist = '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd"><plist version="1.0"><dict>'
                .'<key>Label</key><string>'.$this->label().'</string><key>ProgramArguments</key><array>'
                .implode('', array_map(fn ($arg) => '<string>'.$xml($arg).'</string>', [$php, base_path('artisan'), 'spacetraders:engine-supervise']))
                .'</array><key>WorkingDirectory</key><string>'.$xml(base_path()).'</string><key>EnvironmentVariables</key><dict>';
            foreach ($env as $key => $value) {
                $plist .= '<key>'.$xml($key).'</key><string>'.$xml($value).'</string>';
            }
            $plist .= '</dict><key>RunAtLoad</key><false/><key>KeepAlive</key><false/><key>ExitTimeOut</key><integer>45</integer><key>ThrottleInterval</key><integer>1</integer>'
                .'<key>StandardOutPath</key><string>'.$xml($directory.'/supervisor.log').'</string><key>StandardErrorPath</key><string>'.$xml($directory.'/supervisor.log').'</string></dict></plist>';
            file_put_contents($directory.'/service.plist', $plist, LOCK_EX);
            chmod($directory.'/service.plist', 0600);
            touch($directory.'/supervisor.log');
            chmod($directory.'/supervisor.log', 0600);
            if (! $this->launchctl(['bootstrap', 'gui/'.posix_geteuid(), $directory.'/service.plist'])->isSuccessful()) {
                throw new GameException('Herd user launchd domain rejected the owned service. Engine remains off.');
            }
        });
    }

    public function start(): array
    {
        return $this->lock->run('engine-control', function () {
            $status = $this->status();
            if (in_array($status['state'], ['running', 'starting', 'stopping'], true)) {
                return $status;
            }
            if ($this->alive(LocalEngine::find(1)?->processes['supervisor'] ?? null)) {
                throw new GameException('The previous owned supervisor is still exiting. Wait for shutdown or review the force-stop control.');
            }
            if (! is_file($this->directory().'/service.plist') || ! $this->launchctl(['print', $this->target()])->isSuccessful()) {
                throw new GameException('Install this checkout’s owned engine service from the CLI first.');
            }
            // No -k: an existing supervisor must never be killed by Start.
            if (! $this->launchctl(['kickstart', $this->target()])->isSuccessful()) {
                throw new GameException('launchd did not start the owned engine.');
            }

            return ['state' => 'starting', 'message' => 'Start requested. Waiting for verified child processes.'];
        });
    }

    public function end(): array
    {
        $this->gate->close();

        return $this->status();
    }

    public function forceStop(): array
    {
        $engine = LocalEngine::find(1);
        if (! $engine || $engine->state !== 'faulted' || ! $engine->stop_requested_at || $engine->stop_requested_at->gt(now()->subSeconds(40))) {
            throw new GameException('Force stop is available only after the 40-second graceful shutdown allowance.');
        }
        $this->gate->close('faulted', 'Force stop requested. Interrupted writes require reconciliation.');
        if (! $this->launchctl(['kill', 'SIGKILL', $this->target()])->isSuccessful()) {
            throw new GameException('Could not force-stop the fixed owned supervisor. Inspect its state locally.');
        }
        if (! $this->launchctl(['bootout', $this->target()])->isSuccessful()) {
            throw new GameException('Could not confirm removal of the owned service. Inspect its process state locally.');
        }

        return $this->status();
    }

    public function uninstall(): void
    {
        $status = $this->status();
        if (in_array($status['state'], ['starting', 'running', 'stopping'], true) || $this->alive(LocalEngine::find(1)?->processes['supervisor'] ?? null)) {
            throw new GameException('End the session and wait for all owned processes to exit before uninstalling.');
        }
        $this->launchctl(['bootout', $this->target()]);
        if ($this->launchctl(['print', $this->target()])->isSuccessful()) {
            throw new GameException('Owned service removal could not be verified.');
        }
        @unlink($this->directory().'/service.plist');
    }

    public function alive(?int $pid): bool
    {
        return $pid && posix_kill($pid, 0);
    }

    public function status(): array
    {
        $engine = LocalEngine::find(1);
        if (! $engine) {
            return ['state' => 'off', 'gate_open' => false, 'message' => 'Local engine is off.'];
        }
        if (in_array($engine->state, ['starting', 'running', 'stopping'], true)
            && ! ($engine->state === 'starting' && $engine->updated_at->gt(now()->subSeconds(5)))
            && (! $this->alive($engine->processes['supervisor'] ?? null) || ($engine->state === 'running' && (! $this->alive($engine->processes['worker'] ?? null) || ! $this->alive($engine->processes['scheduler'] ?? null))) || $engine->heartbeat_at?->lt(now()->subSeconds(15)))) {
            $this->gate->close('faulted', 'Engine heartbeat or supervisor lost. Old approvals are closed.');
            $engine->refresh();
        }

        return $engine->only(['state', 'gate_open', 'session_id', 'heartbeat_at', 'message', 'stop_requested_at']);
    }
}
