<?php

namespace App\Support;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Log;

/**
 * Logs SQLite write transactions and statements that hold or wait on the database lock long
 * enough to starve the engine heartbeat. Transactions use IMMEDIATE mode, so every outermost
 * transaction holds the single writer lock from BEGIN until COMMIT or ROLLBACK.
 */
class SqliteLockTelemetry
{
    /** @var array<string, array{0: int, 1: list<string>, 2: int}> */
    private array $open = [];

    public function __construct(private float $thresholdSeconds) {}

    public function register(Dispatcher $events): void
    {
        $events->listen(TransactionBeginning::class, function (TransactionBeginning $event) {
            if ($event->connection->getDriverName() === 'sqlite' && $event->connection->transactionLevel() === 1) {
                $this->open[$event->connectionName] = [hrtime(true), $this->trace(), 0];
            }
        });
        $events->listen(QueryExecuted::class, function (QueryExecuted $event) {
            if (isset($this->open[$event->connectionName])) {
                $this->open[$event->connectionName][2]++;
            }
            if ($event->time >= $this->thresholdSeconds * 1000 && $event->connection->getDriverName() === 'sqlite') {
                $this->log('Slow SQLite statement.', [
                    'ms' => round($event->time),
                    'sql' => mb_substr($event->sql, 0, 300),
                    'in_transaction' => $event->connection->transactionLevel() > 0,
                    'trace' => $this->trace(),
                ]);
            }
        });
        $events->listen(TransactionCommitting::class, fn (TransactionCommitting $event) => $this->finish($event->connectionName, 'commit'));
        $events->listen(TransactionRolledBack::class, function (TransactionRolledBack $event) {
            if ($event->connection->transactionLevel() === 0) {
                $this->finish($event->connectionName, 'rollback');
            }
        });
    }

    private function finish(string $connection, string $outcome): void
    {
        $open = $this->open[$connection] ?? null;
        unset($this->open[$connection]);
        if ($open === null) {
            return;
        }
        $seconds = (hrtime(true) - $open[0]) / 1e9;
        if ($seconds >= $this->thresholdSeconds) {
            $this->log('Long SQLite write transaction.', [
                'seconds' => round($seconds, 2),
                'outcome' => $outcome,
                'statements' => $open[2],
                'begun_at' => $open[1],
            ]);
        }
    }

    private function log(string $message, array $context): void
    {
        try {
            Log::channel('engine')->warning($message, $context + [
                'pid' => getmypid(),
                'process' => $this->process(),
            ]);
        } catch (\Throwable) {
        }
    }

    private function process(): string
    {
        if (PHP_SAPI === 'cli') {
            return implode(' ', array_slice($_SERVER['argv'] ?? [], 1, 4));
        }

        return ($_SERVER['REQUEST_METHOD'] ?? '?').' '.($_SERVER['REQUEST_URI'] ?? '?');
    }

    /** @return list<string> */
    private function trace(): array
    {
        $app = base_path('app').DIRECTORY_SEPARATOR;
        $frames = [];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 80) as $frame) {
            $file = $frame['file'] ?? '';
            if (! str_starts_with($file, $app) || str_ends_with($file, 'SqliteLockTelemetry.php')) {
                continue;
            }
            $frames[] = substr($file, strlen(base_path()) + 1).':'.($frame['line'] ?? 0);
            if (count($frames) === 10) {
                break;
            }
        }

        return $frames;
    }
}
