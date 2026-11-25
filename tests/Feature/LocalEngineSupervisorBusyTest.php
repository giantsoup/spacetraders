<?php

namespace Tests\Feature;

use App\Console\Commands\SuperviseLocalEngine;
use App\Gameplay\Engine\EngineHost;
use App\Gameplay\EngineGate;
use App\Gameplay\HostLock;
use App\Gameplay\LocalEngineManager;
use App\Models\LocalEngine;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FakeEngineHost;
use Tests\Support\FakeOwnedChild;
use Tests\TestCase;

class LocalEngineSupervisorBusyTest extends TestCase
{
    use DatabaseMigrations;

    private FakeEngineHost $host;

    private float $seconds = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = new FakeEngineHost;
        $this->host->children = ['worker' => new FakeOwnedChild(10002), 'scheduler' => new FakeOwnedChild(10003)];
        $this->app->detectEnvironment(fn () => 'local');
        config(['gameplay.local_engine_enabled' => true]);
    }

    #[DataProvider('loopQueries')]
    public function test_supervisor_keeps_its_children_and_recovers_after_one_locked_tick(string $queryPrefix, bool $wrapped): void
    {
        $ticks = $failures = 0;
        $this->freezeTime();
        Log::partialMock()->shouldReceive('warning')->once();
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use ($queryPrefix, $wrapped, &$ticks, &$failures) {
            if ($ticks === 1 && $failures === 0 && str_starts_with($query, $queryPrefix)) {
                $failures++;
                throw $this->lockError($query, $bindings, $wrapped);
            }
        });

        $this->assertSame(0, $this->supervise(function () use (&$ticks) {
            $engine = LocalEngine::findOrFail(1);
            $this->assertTrue($engine->gate_open);
            $this->assertSame('running', $engine->state);
            $this->assertSame($ticks === 1 ? now()->subSecond()->timestamp : now()->timestamp, $engine->heartbeat_at->timestamp);
            if (++$ticks === 3) {
                ($this->host->stop)();
                foreach ($this->host->children as $child) {
                    $child->running = false;
                }
            }
            $this->seconds++;
            $this->travel(1)->seconds();
        }));

        $this->assertSame(1, $failures);
        $this->assertSame('off', LocalEngine::findOrFail(1)->state);
        foreach ($this->host->children as $child) {
            $this->assertNotContains(['kill'], $child->calls);
        }
    }

    public static function loopQueries(): array
    {
        return [
            'wrapped heartbeat busy' => ['update "local_engines" set "heartbeat_at"', true],
            'PDO refresh locked' => ['select * from "local_engines"', false],
        ];
    }

    public function test_persistent_heartbeat_lock_faults_below_the_stale_threshold(): void
    {
        $failures = 0;
        Log::partialMock()->shouldReceive('warning')->once();
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$failures) {
            if ($this->seconds > 0 && str_starts_with($query, 'update "local_engines" set "heartbeat_at"')) {
                $failures++;
                throw $this->lockError($query, $bindings);
            }
        });

        $this->assertSame(1, $this->supervise(function () {
            $this->seconds++;
            $this->assertLessThan(15, $this->seconds);
        }));

        $this->assertSame(9, $failures);
        $this->assertSame(10.0, $this->seconds);
        $this->assertFaultedAndChildrenKilled();
        $this->assertSame(SuperviseLocalEngine::CONTENTION_FAULT_MESSAGE, LocalEngine::findOrFail(1)->message);
    }

    public function test_fault_close_retries_one_lock_and_persists_the_stop(): void
    {
        $attempts = 0;
        $this->withFileDatabase(function (string $database) use (&$attempts) {
            $writer = new PDO('sqlite:'.$database);
            $this->host->children['worker']->isReady = false;
            $this->host->children['worker']->onReady = function () use (&$attempts, $writer) {
                DB::connection()->beforeStartingTransaction(function () use (&$attempts, $writer) {
                    switch (++$attempts) {
                        case 1:
                            $writer->exec('BEGIN IMMEDIATE');
                            break;
                        case 2:
                            foreach ($this->host->children as $child) {
                                $this->assertFalse($child->isRunning());
                            }
                            $writer->rollBack();
                            break;
                    }
                });
            };

            $this->assertSame(1, $this->supervise(fn () => null));
            $this->assertSame(2, $attempts);
            $this->assertFaultedAndChildrenKilled();
        });
    }

    public function test_startup_heartbeat_waits_for_a_real_lock_to_clear(): void
    {
        $this->withFileDatabase(function (string $database) {
            $writer = new Process([PHP_BINARY, '-r', '
                $pdo = new PDO("sqlite:".$argv[1]);
                $pdo->exec("BEGIN IMMEDIATE");
                fwrite(STDOUT, "locked\n");
                fflush(STDOUT);
                usleep(1750000);
                $pdo->rollBack();
            ', $database]);
            $this->host->children['worker']->onReady = function () use ($writer) {
                $writer->start();
                $this->assertTrue($writer->waitUntil(fn (string $type, string $output) => str_contains($output, 'locked')));
            };
            foreach ($this->host->children as $child) {
                $child->stopOnRequest = true;
            }

            try {
                $originalTimeout = (int) DB::connection()->getPdo()->query('PRAGMA busy_timeout')->fetchColumn();
                $this->assertSame(0, $this->supervise(function () {
                    ($this->host->stop)();
                }));
                $this->assertSame(0, $writer->wait());
                $this->assertSame('off', LocalEngine::findOrFail(1)->state);
                $this->assertSame($originalTimeout, (int) DB::connection()->getPdo()->query('PRAGMA busy_timeout')->fetchColumn());
            } finally {
                $writer->stop(1);
            }
        });
    }

    #[DataProvider('shutdownPersistenceSteps')]
    public function test_unwritable_shutdown_releases_ownership_at_its_monotonic_deadline(bool $gateAlreadyClosed): void
    {
        config(['gameplay.engine_shutdown_seconds' => 30]);
        $this->app->instance(EngineHost::class, $this->host);
        $this->withFileDatabase(function (string $database) use ($gateAlreadyClosed) {
            $writer = new PDO('sqlite:'.$database);
            $monotonic = 0.0;
            $ticks = 0;
            foreach ($this->host->children as $child) {
                $child->stopOnRequest = true;
            }
            $engineLog = storage_path('logs/shutdown-deadline.log');
            config(['logging.channels.engine.path' => $engineLog]);

            try {
                $this->assertSame(1, $this->supervise(function () use ($writer, $gateAlreadyClosed, &$ticks, &$monotonic) {
                    switch (++$ticks) {
                        case 1:
                            if ($gateAlreadyClosed) {
                                app(EngineGate::class)->close();
                            } else {
                                ($this->host->stop)();
                            }
                            $writer->exec('BEGIN IMMEDIATE');
                            DB::connection()->getPdo()->exec('PRAGMA busy_timeout = 1');
                            break;
                        case 2:
                            $monotonic = 30.0;
                            $this->seconds = -60;
                            break;
                        default:
                            $this->fail('Supervisor kept ownership after its shutdown deadline.');
                    }
                }, function () use (&$monotonic) {
                    return $monotonic;
                }));
                $this->assertSame(2, $ticks, is_file($engineLog) ? file_get_contents($engineLog) : '');
                $this->assertSame($gateAlreadyClosed ? 'stopping' : 'running', LocalEngine::findOrFail(1)->state);
                $this->assertTrue(app(HostLock::class)->run('engine-instance', fn () => true));
                $this->assertStringContainsString('Engine supervisor could not persist shutdown before its deadline.', file_get_contents($engineLog));
                foreach ($this->host->children as $child) {
                    $this->assertSame([['ready', 10.0], ['requestStop']], $child->calls);
                }
            } finally {
                if ($writer->inTransaction()) {
                    $writer->rollBack();
                }
            }
            $this->host->alive['supervisor'] = false;
            $this->assertSame('faulted', app(LocalEngineManager::class)->status()['state']);
        });
    }

    public static function shutdownPersistenceSteps(): array
    {
        return ['close the gate on a signal' => [false], 'finalize after an external stop' => [true]];
    }

    public function test_signal_during_a_heartbeat_retries_a_locked_gate_close(): void
    {
        $closeAttempts = 0;
        foreach ($this->host->children as $child) {
            $child->stopOnRequest = true;
        }
        DB::connection()->beforeStartingTransaction(function () use (&$closeAttempts) {
            if ($this->seconds > 0 && ++$closeAttempts === 1) {
                throw new PDOException('SQLITE_BUSY');
            }
        });
        DB::connection()->beforeExecuting(function (string $query) {
            if ($this->seconds === 1.0 && str_starts_with($query, 'update "local_engines" set "heartbeat_at"')) {
                ($this->host->stop)();
            }
        });

        $this->assertSame(0, $this->supervise(function () {
            $this->assertLessThan(6, ++$this->seconds);
        }));
        $this->assertSame(2, $closeAttempts);
        $this->assertSame('off', LocalEngine::findOrFail(1)->state);
        $this->assertNotNull(LocalEngine::findOrFail(1)->stop_requested_at);
    }

    public function test_expired_heartbeat_during_contention_cannot_revive_after_a_scheduling_delay(): void
    {
        $recoveredWrites = 0;
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$recoveredWrites) {
            if ($this->seconds > 0 && str_starts_with($query, 'update "local_engines" set "heartbeat_at"')) {
                if ($this->seconds < 10) {
                    throw $this->lockError($query, $bindings);
                }
                $recoveredWrites++;
            }
        });

        $this->assertSame(1, $this->supervise(function () {
            if ($this->seconds === 9.0) {
                $this->seconds += 7;
            } elseif ($this->seconds >= 16) {
                app(EngineGate::class)->close();
                foreach ($this->host->children as $child) {
                    $child->running = false;
                }
            } else {
                $this->seconds++;
            }
        }));
        $this->assertSame(0, $recoveredWrites);
        $this->assertFaultedAndChildrenKilled();
    }

    public function test_observed_stop_signals_children_even_when_heartbeats_remain_locked(): void
    {
        DB::connection()->beforeExecuting(function (string $query, array $bindings) {
            if ($this->seconds > 0 && str_starts_with($query, 'update "local_engines" set "heartbeat_at"')) {
                throw $this->lockError($query, $bindings);
            }
        });

        $this->assertSame(0, $this->supervise(function () {
            if ($this->seconds === 0.0) {
                app(EngineGate::class)->close();
            } else {
                foreach ($this->host->children as $child) {
                    $this->assertContains(['requestStop'], $child->calls);
                    $this->assertTrue($child->isRunning());
                    if ($this->seconds === 12.0) {
                        $child->running = false;
                    }
                }
            }
            $this->seconds++;
        }));
        $this->assertSame(13.0, $this->seconds);
        $this->assertSame('off', LocalEngine::findOrFail(1)->state);
        foreach ($this->host->children as $child) {
            $this->assertSame([['ready', 10.0], ['requestStop']], $child->calls);
        }
    }

    public function test_real_sleep_gap_still_closes_a_running_session(): void
    {
        $ticks = 0;
        $this->assertSame(0, $this->supervise(function () use (&$ticks) {
            if ($ticks++ === 0) {
                $this->seconds += 16;

                return;
            }
            $this->assertSame('faulted', LocalEngine::findOrFail(1)->state);
            foreach ($this->host->children as $child) {
                $this->assertContains(['requestStop'], $child->calls);
                $child->running = false;
            }
        }));
        $this->assertSame(2, $ticks);
    }

    private function lockError(string $query, array $bindings, bool $wrapped = true): PDOException
    {
        $error = new PDOException($wrapped ? 'SQLSTATE[HY000]: General error: 5 database is locked' : 'database table is locked');
        $error->errorInfo = ['HY000', $wrapped ? 5 : 6, $error->getMessage()];

        return $wrapped ? new QueryException('sqlite', $query, $bindings, $error) : $error;
    }

    private function assertFaultedAndChildrenKilled(): void
    {
        $engine = LocalEngine::findOrFail(1);
        $this->assertSame('faulted', $engine->state);
        $this->assertFalse($engine->gate_open);
        $this->assertNotNull($engine->stop_requested_at);
        foreach ($this->host->children as $child) {
            $this->assertContains(['kill'], $child->calls);
            $this->assertFalse($child->isRunning());
        }
    }

    private function withFileDatabase(Closure $work): void
    {
        $database = storage_path('engine-contention.sqlite');
        touch($database);
        config(['database.connections.engine_contention' => ['database' => $database] + config('database.connections.sqlite')]);
        DB::setDefaultConnection('engine_contention');
        try {
            $this->artisan('migrate')->assertSuccessful();
            $work($database);
        } finally {
            DB::setDefaultConnection('sqlite');
            DB::purge('engine_contention');
        }
    }

    private function supervise(Closure $wait, ?Closure $monotonic = null): int
    {
        $command = new class(fn () => $this->seconds, $wait, $monotonic) extends SuperviseLocalEngine
        {
            public function __construct(private Closure $clock, private Closure $wait, private ?Closure $monotonic)
            {
                parent::__construct();
            }

            protected function wallTime(): float
            {
                return ($this->clock)();
            }

            protected function monotonicTime(): float
            {
                return $this->monotonic ? ($this->monotonic)() : parent::monotonicTime();
            }

            protected function waitForNextTick(): void
            {
                ($this->wait)();
            }
        };

        return $command->handle(app(HostLock::class), app(EngineGate::class), $this->host);
    }
}
