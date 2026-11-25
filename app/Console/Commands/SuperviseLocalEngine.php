<?php

namespace App\Console\Commands;

use App\Gameplay\AutomationRunner;
use App\Gameplay\Engine\EngineChildWatch;
use App\Gameplay\Engine\EngineHost;
use App\Gameplay\Engine\EngineLease;
use App\Gameplay\Engine\HeartbeatContention;
use App\Gameplay\EngineGate;
use App\Gameplay\HostLock;
use App\Gameplay\LocalEngineManager;
use App\Models\AutomationRun;
use App\Models\LocalEngine;
use App\Models\ShipAction;
use Illuminate\Console\Command;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\Log;
use PDO;
use PDOException;

class SuperviseLocalEngine extends Command
{
    private const HEARTBEAT_TIMEOUT_SECONDS = 10;

    /** The watchdog may restart a session that faulted with exactly this message (see EngineAutoRecovery). */
    public const CONTENTION_FAULT_MESSAGE = 'SQLite contention prevented the supervisor heartbeat. Reconcile any reserved action before a fresh preview.';

    private const SQLITE_BUSY_TIMEOUT_MS = 1000;

    protected $signature = 'spacetraders:engine-supervise';

    protected $description = 'Own exactly one worker and scheduler until the local session ends';

    public function handle(HostLock $lock, EngineGate $gate, EngineHost $host): int
    {
        if (! $gate->enabled() || ! app()->environment('local') || ! $host->hasServiceMarker()) {
            return self::FAILURE;
        }

        return $lock->run('engine-instance', function () use ($gate, $host) {
            $engine = $gate->begin();
            $children = [];
            $pdo = null;
            $busyTimeout = null;
            try {
                $connection = $engine->getConnection();
                if ($connection->getDriverName() === 'sqlite') {
                    $pdo = $connection->getPdo();
                    $busyTimeout = (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn();
                }
                $host->claim($engine->session_id, 'supervisor');
                $processes = ['supervisor' => getmypid()];
                $processes['identity'] = $host->identify($processes);
                $engine->update(['processes' => $processes, 'heartbeat_at' => now()]);
                EngineLease::cleanup($engine->session_id);
                $stopSignal = false;
                $host->onStopSignal(function () use (&$stopSignal) {
                    $stopSignal = true;
                });
                foreach ([
                    'worker' => ['queue:work', 'database', '--queue=spacetraders', '--sleep=1', '--tries=1', '--timeout=120'],
                    'scheduler' => ['spacetraders:engine-schedule'],
                ] as $name => $arguments) {
                    $children[$name] = $host->spawn($engine->session_id, $name, $arguments);
                }
                $workerReady = $children['worker']->ready(10);
                $this->heartbeat($engine->session_id);
                $schedulerReady = $children['scheduler']->ready(10);
                $this->heartbeat($engine->session_id);
                if (! $workerReady || ! $schedulerReady) {
                    throw new \RuntimeException('Owned child failed to start.');
                }
                $processes = ['supervisor' => getmypid(), 'worker' => $children['worker']->pid(), 'scheduler' => $children['scheduler']->pid()];
                $processes['identity'] = $host->identify($processes);
                $this->heartbeat($engine->session_id);
                $engine->update(['processes' => $processes]);
                if (! $stopSignal) {
                    LocalEngine::whereKey(1)->where('session_id', $engine->session_id)->whereNull('stop_requested_at')->update(['state' => 'running', 'gate_open' => true, 'heartbeat_at' => now(), 'processes' => json_encode($processes), 'message' => 'Worker and scheduler alive. Awaiting new approvals.']);
                }
                $shortTimeout = $busyTimeout !== null && $busyTimeout > self::SQLITE_BUSY_TIMEOUT_MS;
                if ($shortTimeout) {
                    $pdo->exec('PRAGMA busy_timeout = '.self::SQLITE_BUSY_TIMEOUT_MS);
                }
                $lastHeartbeat = $last = $this->wallTime();
                $lastHeartbeatMonotonic = $lastMonotonic = hrtime(true);
                $contention = null;
                $shutdown = null;
                $shutdownDeadline = null;
                $allowance = LocalEngineManager::shutdownAllowanceSeconds();
                while (true) {
                    $wall = $this->wallTime();
                    $monotonic = hrtime(true);
                    $gap = max($wall - $last, ($monotonic - $lastMonotonic) / 1e9);
                    $last = $wall;
                    $lastMonotonic = $monotonic;
                    if ($stopSignal) {
                        $shutdownDeadline ??= $this->monotonicTime() + $allowance;
                    }
                    try {
                        $engine->refresh();
                        if (! $engine->stop_requested_at && ($stopSignal || $gap > 15)) {
                            if ($stopSignal) {
                                $shutdownDeadline ??= $this->monotonicTime() + $allowance;
                                if ($pdo) {
                                    $this->limitShutdownWait($pdo, min($busyTimeout, self::SQLITE_BUSY_TIMEOUT_MS), $shutdownDeadline);
                                    $shortTimeout = true;
                                }
                                $gate->close();
                            } else {
                                $gate->close('faulted', 'Sleep or heartbeat gap detected. Refresh state and start a fresh session.');
                            }
                            $engine->refresh();
                        }
                        $jobStartedAt = $this->jobStartedAt($engine->session_id);
                        $jobTimedOut = $children['worker']->isRunning() && $jobStartedAt !== null && $this->wallTime() - $jobStartedAt > 150;
                        if ($jobTimedOut) {
                            Log::error('Owned worker job exceeded 150 seconds. Terminating worker.', ['session' => $engine->session_id]);
                            $children['worker']->kill();
                        }
                        if ($jobTimedOut || (! $engine->stop_requested_at && (! $children['worker']->isRunning() || ! $children['scheduler']->isRunning()))) {
                            $gate->close('faulted', 'An owned child exited. New approvals are closed.');
                            $engine->refresh();
                        }
                        if ($engine->stop_requested_at) {
                            $shutdownDeadline ??= $this->monotonicTime() + $allowance;
                            $shutdown ??= $this->requestChildStop($children);
                            if ($shortTimeout) {
                                $pdo->exec('PRAGMA busy_timeout = '.$busyTimeout);
                                $shortTimeout = false;
                            }
                            if (! $children['worker']->isRunning() && ! $children['scheduler']->isRunning()) {
                                if ($pdo) {
                                    $this->limitShutdownWait($pdo, $busyTimeout, $shutdownDeadline);
                                }
                                $this->finishShutdown($engine);
                                break;
                            }
                            if ($this->wallTime() - $shutdown > $allowance) {
                                $engine->update(['state' => 'faulted', 'gate_open' => false, 'message' => "Graceful shutdown exceeded {$allowance} seconds. Force stop may interrupt an upstream request."]);
                            }
                        } elseif ($contention && ! $stopSignal) {
                            $this->checkHeartbeatDeadline($lastHeartbeat, $lastHeartbeatMonotonic, $contention);
                        }

                        $heartbeat = $this->wallTime();
                        $heartbeatMonotonic = hrtime(true);
                        $this->heartbeat($engine->session_id);
                        $lastHeartbeat = $heartbeat;
                        $lastHeartbeatMonotonic = $heartbeatMonotonic;
                        $contention = null;
                    } catch (\Throwable $error) {
                        if (! $pdo || ! $this->isSqliteLock($error)) {
                            throw $error;
                        }
                        if ($contention === null) {
                            Log::warning('Engine supervisor tick deferred by SQLite contention.', ['session' => $engine->session_id]);
                        }
                        $contention = $error;
                        if ($stopSignal || $engine->stop_requested_at) {
                            $shutdownDeadline ??= $this->monotonicTime() + $allowance;
                            $shutdown ??= $this->requestChildStop($children);
                            $persistingStop = $stopSignal && ! $engine->stop_requested_at;
                            $childrenExited = ! $children['worker']->isRunning() && ! $children['scheduler']->isRunning();
                            if (($persistingStop || $childrenExited) && $this->monotonicTime() >= $shutdownDeadline) {
                                $this->logEngineError('Engine supervisor could not persist shutdown before its deadline.', $error);

                                return self::FAILURE;
                            }
                        } elseif ($shutdown === null) {
                            $this->checkHeartbeatDeadline($lastHeartbeat, $lastHeartbeatMonotonic, $error);
                        }
                    }
                    $this->waitForNextTick();
                }

                return self::SUCCESS;
            } catch (\Throwable $error) {
                $this->logEngineError('Engine supervisor failed.', $error);
                try {
                    if ($busyTimeout !== null && $busyTimeout > self::SQLITE_BUSY_TIMEOUT_MS) {
                        $pdo->exec('PRAGMA busy_timeout = '.self::SQLITE_BUSY_TIMEOUT_MS);
                    }
                    $this->recordFault($gate, $pdo !== null, $children, $error instanceof HeartbeatContention
                        ? self::CONTENTION_FAULT_MESSAGE : 'Supervisor failed. Reconcile any reserved action before a fresh preview.');
                } finally {
                    $this->killChildren($children);
                }

                return self::FAILURE;
            } finally {
                if ($busyTimeout !== null) {
                    $pdo->exec('PRAGMA busy_timeout = '.$busyTimeout);
                }
            }
        });
    }

    private function checkHeartbeatDeadline(float $lastHeartbeat, int $lastHeartbeatMonotonic, \Throwable $error): void
    {
        $elapsed = max($this->wallTime() - $lastHeartbeat, (hrtime(true) - $lastHeartbeatMonotonic) / 1e9);
        // EngineGate rejects admissions once the stored heartbeat is 15 seconds old.
        if ($elapsed >= self::HEARTBEAT_TIMEOUT_SECONDS) {
            throw new HeartbeatContention('SQLite contention prevented the supervisor heartbeat for '.self::HEARTBEAT_TIMEOUT_SECONDS.' seconds.', previous: $error);
        }
    }

    private function requestChildStop(array $children): float
    {
        $requestedAt = $this->wallTime();
        foreach ($children as $child) {
            if ($child->isRunning()) {
                $child->requestStop();
            }
        }

        return $requestedAt;
    }

    private function finishShutdown(LocalEngine $engine): void
    {
        foreach (AutomationRun::whereNotNull('active_agent_id')->whereNotNull('stop_requested_at')->get() as $run) {
            if (! ShipAction::where('agent_id', $run->agent_id)->where(fn ($q) => $q->whereIn('state', ['sending', 'uncertain'])->orWhere('review_required', true))->exists()) {
                app(AutomationRunner::class)->finishStop($run, 'Session ended. Reserved work settled; new preview required.');
            }
        }
        $engine->update(['state' => $engine->state === 'faulted' ? 'faulted' : 'off', 'gate_open' => false, 'message' => 'Owned processes exited. Any uncertain action still requires reconciliation.']);
    }

    private function limitShutdownWait(PDO $pdo, int $timeout, float $deadline): void
    {
        $remaining = max(0, (int) floor(($deadline - $this->monotonicTime()) * 1000));
        $pdo->exec('PRAGMA busy_timeout = '.min($remaining, $timeout));
    }

    private function logEngineError(string $message, \Throwable $error): void
    {
        try {
            Log::channel('engine')->error($message, ['exception' => $error::class, 'message' => $error->getMessage()]);
        } catch (\Throwable) {
        }
    }

    private function recordFault(EngineGate $gate, bool $sqlite, array $children, string $message): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $gate->close('faulted', $message);

                return;
            } catch (\Throwable $error) {
                if (! $sqlite || ! $this->isSqliteLock($error)) {
                    throw $error;
                }
                if ($attempt === 3) {
                    $this->logEngineError('Engine supervisor could not persist its fault.', $error);

                    return;
                }
                if ($attempt === 1) {
                    // An owned process may be holding the writer lock needed to persist the fault.
                    $this->killChildren($children);
                }
                usleep(200000);
            }
        }
    }

    private function killChildren(array $children): void
    {
        foreach ($children as $child) {
            if ($child->isRunning()) {
                $child->kill();
            }
        }
    }

    private function isSqliteLock(\Throwable $error): bool
    {
        if ($error instanceof DeadlockException) {
            $error = $error->getPrevious();
        }

        if (! $error instanceof PDOException) {
            return false;
        }

        return in_array((int) ($error->errorInfo[1] ?? $error->getCode()) & 0xFF, [5, 6], true)
            || preg_match('/\b(?:database(?: table| schema)? is locked|SQLITE_(?:BUSY|LOCKED)(?:_[A-Z]+)*)\b/i',
                $error->getMessage()."\n".($error->getPrevious()?->getMessage() ?? '')) === 1;
    }

    protected function jobStartedAt(string $session): ?float
    {
        return EngineChildWatch::jobStartedAt($session);
    }

    private function heartbeat(string $session): void
    {
        LocalEngine::whereKey(1)->where('session_id', $session)->update(['heartbeat_at' => now()]);
    }

    protected function wallTime(): float
    {
        return microtime(true);
    }

    protected function monotonicTime(): float
    {
        return hrtime(true) / 1e9;
    }

    protected function waitForNextTick(): void
    {
        usleep(200000);
    }
}
