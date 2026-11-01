<?php

namespace App\Console\Commands;

use App\Gameplay\DeploymentGate;
use Illuminate\Console\Command;

class DeploymentControl extends Command
{
    protected $signature = 'spacetraders:deployment {operation : close, open, or status} {--generation= : Unique deployment identifier} {--wait : Wait for drain, at most ten minutes}';

    protected $description = 'Version 1 persistent deployment admission and drain contract';

    public function handle(DeploymentGate $gate): int
    {
        $operation = $this->argument('operation');
        $generation = $this->option('generation');
        if (! in_array($operation, ['close', 'open', 'status'], true) || ($operation !== 'status' && (! is_string($generation) || ! preg_match('/\A[a-zA-Z0-9._-]{1,100}\z/', $generation)))) {
            $this->error('Use close/open with --generation=<unique identifier>, or status.');

            return self::INVALID;
        }
        if ($operation === 'open') {
            $gate->open($generation);
        } elseif ($operation === 'close') {
            $gate->close($generation);
        }
        do {
            $status = $gate->status();
            if ($operation === 'close' && $status['generation'] !== $generation) {
                $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                return self::FAILURE;
            }
            if ($operation !== 'close' || ! $this->option('wait') || $status['phase'] !== 'draining') {
                break;
            }
            usleep(200000);
        } while (true);
        $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $operation === 'close' && ! $status['drained'] ? self::FAILURE : self::SUCCESS;
    }
}
