<?php

namespace App\Gameplay\Engine\Windows;

use App\Gameplay\Engine\EngineLease;
use App\Gameplay\Engine\HostCommands;
use App\Gameplay\Engine\OwnedChild;
use App\Gameplay\GameException;

final class WindowsChild implements OwnedChild
{
    private $process;

    private readonly int $processId;

    public function __construct(array $argv, string $directory, array $env, string $log, private readonly string $lease, private readonly HostCommands $commands)
    {
        $this->process = proc_open($argv, [0 => ['file', 'NUL', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $directory, $env, ['bypass_shell' => true]);
        if (! is_resource($this->process)) {
            throw new GameException('Could not start an owned engine child.');
        }
        $this->processId = proc_get_status($this->process)['pid'];
    }

    public function pid(): ?int
    {
        return $this->processId;
    }

    public function isRunning(): bool
    {
        return is_resource($this->process) && proc_get_status($this->process)['running'];
    }

    public function ready(float $seconds): bool
    {
        $deadline = hrtime(true) + (int) (max(0, $seconds) * 1_000_000_000);
        do {
            if (! $this->isRunning()) {
                return false;
            }
            if (EngineLease::held($this->lease)) {
                return $this->isRunning();
            }
            if (hrtime(true) >= $deadline) {
                return false;
            }
            usleep(20000);
        } while (true);
    }

    public function requestStop(): void {}

    public function kill(): void
    {
        if ($this->isRunning()) {
            $this->commands->run([PowerShell::executable('taskkill.exe'), '/PID', (string) $this->processId, '/F']);
        }
    }

    public function __destruct()
    {
        if (is_resource($this->process) && ! $this->isRunning()) {
            proc_close($this->process);
        }
    }
}
