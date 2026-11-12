<?php

namespace App\Gameplay\Engine;

use App\Gameplay\GameException;
use App\Models\LocalEngine;
use Symfony\Component\Process\Process;

class LaunchdEngineHost implements EngineHost
{
    public function __construct(private HostCommands $commands, private ?int $uid = null) {}

    public function supported(): bool
    {
        return PHP_OS_FAMILY === 'Darwin';
    }

    public function platform(): string
    {
        return 'macOS';
    }

    public function serviceName(): string
    {
        return EngineIdentity::label();
    }

    private function domain(): string
    {
        return 'gui/'.($this->uid ?? posix_geteuid());
    }

    private function target(): string
    {
        return $this->domain().'/'.$this->serviceName();
    }

    private function launchctl(array $arguments): HostResult
    {
        try {
            return $this->commands->run(array_merge(['/bin/launchctl'], $arguments), 10);
        } catch (GameException $exception) {
            if ($exception->getMessage() !== 'Timed out waiting for an owned engine host operation. Inspect engine state before retrying.') {
                throw $exception;
            }

            throw new GameException('Timed out waiting for the owned launchd operation. Inspect engine state before retrying.');
        }
    }

    public function hasServiceMarker(): bool
    {
        return is_file(EngineIdentity::directory().'/service.plist');
    }

    public function registered(): bool
    {
        return $this->launchctl(['print', $this->target()])->successful();
    }

    public function install(string $php, array $env): void
    {
        $directory = EngineIdentity::directory();
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        chmod($directory, 0700);
        $xml = fn ($value) => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $plist = '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd"><plist version="1.0"><dict>'
            .'<key>Label</key><string>'.$this->serviceName().'</string><key>ProgramArguments</key><array>'
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
        if (! $this->launchctl(['bootstrap', $this->domain(), $directory.'/service.plist'])->successful()) {
            throw new GameException('Herd user launchd domain rejected the owned service. Engine remains off.');
        }
    }

    public function launch(): void
    {
        if (! $this->launchctl(['kickstart', $this->target()])->successful()) {
            throw new GameException('launchd did not start the owned engine.');
        }
    }

    public function forceStop(LocalEngine $engine): void
    {
        if (! $this->launchctl(['kill', 'SIGKILL', $this->target()])->successful()) {
            throw new GameException('Could not force-stop the fixed owned supervisor. Inspect its state locally.');
        }
        if (! $this->launchctl(['bootout', $this->target()])->successful()) {
            throw new GameException('Could not confirm removal of the owned service. Inspect its process state locally.');
        }
    }

    public function uninstall(): void
    {
        $this->launchctl(['bootout', $this->target()]);
        if ($this->registered()) {
            throw new GameException('Owned service removal could not be verified.');
        }
        @unlink(EngineIdentity::directory().'/service.plist');
    }

    public function alive(LocalEngine $engine, string $role): bool
    {
        $pid = $engine->processes[$role] ?? null;

        return is_int($pid) && $pid > 0 && function_exists('posix_kill') && posix_kill($pid, 0);
    }

    public function claim(string $session, string $role): void {}

    public function spawn(string $session, string $role, array $artisanArguments): OwnedChild
    {
        $process = new Process(array_merge([config('gameplay.php_binary'), base_path('artisan')], $artisanArguments), base_path());
        $process->setTimeout(null)->disableOutput()->start();

        return new PosixChild($process);
    }

    public function onStopSignal(callable $stop): void
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }

    public function identify(array $pids): array
    {
        return [];
    }

    public function logPaths(): array
    {
        return ['supervisor' => EngineIdentity::directory().'/supervisor.log'];
    }
}
