<?php

namespace Tests\Feature;

use App\Console\Commands\SuperviseLocalEngine;
use App\Gameplay\EngineGate;
use App\Gameplay\HostLock;
use App\Models\LocalEngine;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
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
        Log::partialMock()->shouldReceive('warning')->times(10);
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

        $this->assertSame(10, $failures);
        $this->assertSame(10.0, $this->seconds);
        $this->assertFaultedAndChildrenKilled();
    }

    public function test_fault_close_retries_one_lock_and_persists_the_stop(): void
    {
        $database = storage_path('engine-contention.sqlite');
        touch($database);
        config(['database.connections.engine_contention' => ['database' => $database] + config('database.connections.sqlite')]);
        DB::setDefaultConnection('engine_contention');

        $attempts = 0;
        try {
            $this->artisan('migrate')->assertSuccessful();
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
        } finally {
            DB::setDefaultConnection('sqlite');
            DB::purge('engine_contention');
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

    private function supervise(Closure $wait): int
    {
        $command = new class(fn () => $this->seconds, $wait) extends SuperviseLocalEngine
        {
            public function __construct(private Closure $clock, private Closure $wait)
            {
                parent::__construct();
            }

            protected function wallTime(): float
            {
                return ($this->clock)();
            }

            protected function waitForNextTick(): void
            {
                ($this->wait)();
            }
        };

        return $command->handle(app(HostLock::class), app(EngineGate::class), $this->host);
    }
}
