<?php

namespace App\Gameplay\Engine;

use App\Gameplay\GameException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/** Runs short host commands from argument arrays. Tests replace this binding with a fake. */
class HostCommands
{
    /**
     * @param  list<string>  $argv
     * @param  array<string, string>  $env
     */
    public function run(array $argv, int $timeout = 10, array $env = []): HostResult
    {
        $process = new Process($argv, null, $env ?: null);
        try {
            $process->setTimeout($timeout)->run();
        } catch (ProcessTimedOutException) {
            throw new GameException('Timed out waiting for an owned engine host operation. Inspect engine state before retrying.');
        }

        return new HostResult((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput());
    }
}
