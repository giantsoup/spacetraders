<?php

namespace App\Console\Commands;

use App\Gameplay\AutomationRunner;
use App\Gameplay\Engine\EngineChildWatch;
use App\Gameplay\Engine\EngineHost;
use App\Gameplay\Engine\EngineLease;
use App\Gameplay\EngineGate;
use App\Gameplay\HostLock;
use App\Gameplay\LocalEngineManager;
use App\Models\AutomationRun;
use App\Models\LocalEngine;
use App\Models\ShipAction;
use Illuminate\Console\Command;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\Log;
use PDOException;

class SuperviseLocalEngine extends Command
{
    private const HEARTBEAT_TIMEOUT_SECONDS = 10;

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
                    // Keep each SQLite wait short enough to enforce the supervisor's heartbeat deadline.
                    $pdo = $connection->getPdo();
                    $busyTimeout = (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn();
                    $pdo->exec('PRAGMA busy_timeout = '.min($busyTimeout, self::SQLITE_BUSY_TIMEOUT_MS));
                }
                $host->claim($engine->session_id, 'supervisor');
                $processes = ['supervisor' => getmypid()];
                $processes['identity'] = $host->identify($processes);
                $engine->update(['processes' => $processes, 'heartbeat_at' => now()]);
                EngineLease::cleanup($engine->session_id);
                $host->onStopSignal(fn () => $gate->close());
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
                LocalEngine::whereKey(1)->where('session_id', $engine->session_id)->whereNull('stop_requested_at')->update(['state' => 'running', 'gate_open' => true, 'heartbeat_at' => now(), 'processes' => json_encode($processes), 'message' => 'Worker and scheduler alive. Awaiting new approvals.']);
                $lastHeartbeat = $last = $this->wallTime();
                $lastHeartbeatMonotonic = $lastMonotonic = hrtime(true);
                $shutdown = null;
                $allowance = LocalEngineManager::shutdownAllowanceSeconds();
                $signalled = false;
                while (true) {
                    $wall = $this->wallTime();
                    $monotonic = hrtime(true);
                    $gap = max($wall - $last, ($monotonic - $lastMonotonic) / 1e9);
                    $last = $wall;
                    $lastMonotonic = $monotonic;
                    if ($gap > 15) {
                        $gate->close('faulted', 'Sleep or heartbeat gap detected. Refresh state and start a fresh session.');
                    }
                    try {
                        $engine->refresh();
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
                        $heartbeat = $this->wallTime();
                        $heartbeatMonotonic = hrtime(true);
                        $this->heartbeat($engine->session_id);
                        $lastHeartbeat = $heartbeat;
                        $lastHeartbeatMonotonic = $heartbeatMonotonic;
                    } catch (\Throwable $error) {
                        if (! $pdo || ! $this->isSqliteLock($error)) {
                            throw $error;
                        }
                        $elapsed = max($this->wallTime() - $lastHeartbeat, (hrtime(true) - $lastHeartbeatMonotonic) / 1e9);
                        Log::warning('Engine heartbeat tick deferred by SQLite contention.', ['session' => $engine->session_id, 'seconds_since_heartbeat' => $elapsed]);
                        if ($elapsed >= self::HEARTBEAT_TIMEOUT_SECONDS) {
                            throw new \RuntimeException('SQLite contention prevented the supervisor heartbeat for '.self::HEARTBEAT_TIMEOUT_SECONDS.' seconds.', previous: $error);
                        }
                        $this->waitForNextTick();

                        continue;
                    }
                    if ($engine->stop_requested_at) {
                        $shutdown ??= $this->wallTime();
                        if (! $signalled) {
                            foreach ($children as $child) {
                                if ($child->isRunning()) {
                                    $child->requestStop();
                                }
                            }
                            $signalled = true;
                        }
                        if (! $children['worker']->isRunning() && ! $children['scheduler']->isRunning()) {
                            foreach (AutomationRun::whereNotNull('active_agent_id')->whereNotNull('stop_requested_at')->get() as $run) {
                                if (! ShipAction::where('agent_id', $run->agent_id)->where(fn ($q) => $q->whereIn('state', ['sending', 'uncertain'])->orWhere('review_required', true))->exists()) {
                                    app(AutomationRunner::class)->finishStop($run, 'Session ended. Reserved work settled; new preview required.');
                                }
                            }
                            $engine->update(['state' => $engine->state === 'faulted' ? 'faulted' : 'off', 'gate_open' => false, 'message' => 'Owned processes exited. Any uncertain action still requires reconciliation.']);
                            break;
                        }
                        if ($this->wallTime() - $shutdown > $allowance) {
                            $engine->update(['state' => 'faulted', 'gate_open' => false, 'message' => "Graceful shutdown exceeded {$allowance} seconds. Force stop may interrupt an upstream request."]);
                        }
                    }
                    $this->waitForNextTick();
                }

                return self::SUCCESS;
            } catch (\Throwable $error) {
                try {
                    Log::channel('engine')->error('Engine supervisor failed.', ['exception' => $error::class, 'message' => $error->getMessage()]);
                } catch (\Throwable) {
                }
                try {
                    $this->recordFault($gate, $pdo !== null, $children);
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

    private function recordFault(EngineGate $gate, bool $sqlite, array $children): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $gate->close('faulted', 'Supervisor failed. Reconcile any reserved action before a fresh preview.');

                return;
            } catch (\Throwable $error) {
                if (! $sqlite || ! $this->isSqliteLock($error) || $attempt === 3) {
                    throw $error;
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

        return $error instanceof PDOException
            && (in_array((int) ($error->errorInfo[1] ?? $error->getCode()) & 0xFF, [5, 6], true)
                || preg_match('/\b(?:database(?: table| schema)? is locked|SQLITE_(?:BUSY|LOCKED))\b/i', $error->getPrevious()?->getMessage() ?? $error->getMessage()) === 1);
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

    protected function waitForNextTick(): void
    {
        usleep(200000);
    }
}
