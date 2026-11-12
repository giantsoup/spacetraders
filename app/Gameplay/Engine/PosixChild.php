<?php

namespace App\Gameplay\Engine;

use Symfony\Component\Process\Process;

final class PosixChild implements OwnedChild
{
    public function __construct(private Process $process) {}

    public function pid(): ?int
    {
        return $this->process->getPid();
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    public function ready(float $seconds): bool
    {
        usleep(300000);

        return $this->isRunning();
    }

    public function requestStop(): void
    {
        if ($this->isRunning()) {
            $this->process->signal(SIGTERM);
        }
    }

    public function kill(): void
    {
        if ($this->isRunning()) {
            $this->process->stop(2, SIGTERM);
        }
    }
}
