<?php

namespace Tests\Unit\Engine;

use App\Console\Commands\SuperviseLocalEngine;
use App\Gameplay\Engine\EngineChildWatch;
use App\Gameplay\Engine\EngineHost;
use App\Gameplay\EngineGate;
use App\Gameplay\HostLock;
use App\Models\LocalEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EngineFixture;
use Tests\Support\FakeEngineHost;
use Tests\Support\FakeOwnedChild;
use Tests\TestCase;

class SuperviseLocalEngineTest extends TestCase
{
    use RefreshDatabase;

    private FakeEngineHost $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(EngineHost::class, $this->host = new FakeEngineHost);
        $this->host->children = ['worker' => new FakeOwnedChild(10002), 'scheduler' => new FakeOwnedChild(10003)];
        $this->app->detectEnvironment(fn () => 'local');
        config(['gameplay.local_engine_enabled' => true]);
    }

    private function supervise(?\Closure $watch = null): int
    {
        $command = new class($watch) extends SuperviseLocalEngine
        {
            public function __construct(private ?\Closure $watch)
            {
                parent::__construct();
            }

            protected function jobStartedAt(string $session): ?float
            {
                return $this->watch ? ($this->watch)($session) : null;
            }
        };

        return $command->handle(app(HostLock::class), app(EngineGate::class), $this->host);
    }

    public function test_missing_service_marker_never_starts_a_session(): void
    {
        $this->host->marker = false;
        $this->assertSame(1, $this->supervise());
        $this->assertNull(LocalEngine::find(1));
        $this->assertSame([], $this->host->calls);
    }

    public function test_nonlocal_environment_never_starts_a_session(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertSame(1, $this->supervise());
        $this->assertNull(LocalEngine::find(1));
        $this->assertSame([], $this->host->calls);
    }

    public function test_engine_fixture_controls_liveness_without_real_processes(): void
    {
        $engine = EngineFixture::healthy();
        foreach (['supervisor', 'worker', 'scheduler'] as $role) {
            $this->assertTrue($this->host->alive($engine, $role));
        }
        EngineFixture::kill('supervisor');
        $this->assertFalse($this->host->alive($engine, 'supervisor'));
        $this->assertTrue($this->host->alive($engine, 'worker'));
        $this->assertTrue($this->host->alive($engine, 'scheduler'));
        $this->assertSame(['alive'], array_values(array_unique(array_column($this->host->calls, 0))));
    }

    public function test_fresh_fixture_clears_a_stop_and_replaces_the_session(): void
    {
        $old = EngineFixture::healthy();
        app(EngineGate::class)->close();
        EngineFixture::kill('worker');
        $engine = EngineFixture::healthy();
        $this->assertNotSame($old->session_id, $engine->session_id);
        $this->assertSame('running', $engine->state);
        $this->assertTrue($engine->gate_open);
        $this->assertNull($engine->stop_requested_at);
        $this->assertTrue($this->host->alive($engine, 'worker'));
    }

    public function test_disabled_engine_never_starts_a_session(): void
    {
        config(['gameplay.local_engine_enabled' => false]);
        $this->assertSame(1, $this->supervise());
        $this->assertNull(LocalEngine::find(1));
    }

    public function test_child_readiness_failure_faults_and_kills_owned_children(): void
    {
        $this->host->children['worker']->isReady = false;
        $this->assertSame(1, $this->supervise());
        $this->assertSame('faulted', LocalEngine::find(1)->state);
        $this->assertSame('Supervisor failed. Reconcile any reserved action before a fresh preview.', LocalEngine::find(1)->message);
        foreach ($this->host->children as $child) {
            $this->assertSame([['ready', 10.0], ['kill']], $child->calls);
        }
    }

    public function test_end_during_readiness_never_reopens_admission(): void
    {
        $this->host->children['scheduler']->onReady = fn () => app(EngineGate::class)->close();
        $this->assertSame(0, $this->supervise(function () {
            $engine = LocalEngine::find(1);
            $this->assertSame('stopping', $engine->state);
            $this->assertFalse($engine->gate_open);

            return null;
        }));
        $this->assertSame('off', LocalEngine::find(1)->state);
        foreach ($this->host->children as $child) {
            $this->assertSame([['ready', 10.0], ['requestStop']], $child->calls);
        }
    }

    public function test_running_session_records_identities_and_exact_child_arguments(): void
    {
        $this->host->identities = ['supervisor' => ['created' => 'synthetic-identity']];
        $this->assertSame(0, $this->supervise(function (string $session) {
            static $observed = false;
            if ($observed) {
                return null;
            }
            $observed = true;
            $engine = LocalEngine::find(1);
            $this->assertSame('running', $engine->state);
            $this->assertTrue($engine->gate_open);
            $this->assertSame($session, $engine->session_id);
            $this->assertSame(['supervisor' => getmypid(), 'worker' => 10002, 'scheduler' => 10003, 'identity' => $this->host->identities], $engine->processes);
            ($this->host->stop)();

            return null;
        }));
        $session = LocalEngine::find(1)->session_id;
        $this->assertContains(['claim', $session, 'supervisor'], $this->host->calls);
        $this->assertContains(['spawn', $session, 'worker', ['queue:work', 'database', '--queue=spacetraders', '--sleep=1', '--tries=1', '--timeout=120']], $this->host->calls);
        $this->assertContains(['spawn', $session, 'scheduler', ['spacetraders:engine-schedule']], $this->host->calls);
        $this->assertSame('off', LocalEngine::find(1)->state);
    }

    public function test_child_exit_faults_and_cooperatively_stops_its_sibling(): void
    {
        $this->assertSame(0, $this->supervise(function () {
            $this->host->children['worker']->running = false;

            return null;
        }));
        $this->assertSame('faulted', LocalEngine::find(1)->state);
        $this->assertFalse(LocalEngine::find(1)->gate_open);
        $this->assertContains(['requestStop'], $this->host->children['scheduler']->calls);
        $this->assertNotContains(['kill'], $this->host->children['scheduler']->calls);
    }

    public static function shutdownStates(): array
    {
        return ['running' => [false], 'already stopping' => [true]];
    }

    #[DataProvider('shutdownStates')]
    public function test_job_watchdog_kills_the_worker_and_faults_even_during_end(bool $stopping): void
    {
        if ($stopping) {
            $this->host->children['scheduler']->onReady = fn () => app(EngineGate::class)->close();
        }
        Log::partialMock();
        Log::shouldReceive('error')->once()->with('Owned worker job exceeded 150 seconds. Terminating worker.', \Mockery::type('array'));
        $this->assertSame(0, $this->supervise(fn () => microtime(true) - 151));
        $this->assertSame('faulted', LocalEngine::find(1)->state);
        $this->assertContains(['kill'], $this->host->children['worker']->calls);
        $this->assertContains(['requestStop'], $this->host->children['scheduler']->calls);
    }

    public function test_job_inside_watchdog_allowance_gets_cooperative_stop(): void
    {
        $this->assertSame(0, $this->supervise(function () {
            app(EngineGate::class)->close();

            return microtime(true) - 149;
        }));
        $this->assertSame('off', LocalEngine::find(1)->state);
        $this->assertContains(['requestStop'], $this->host->children['worker']->calls);
        $this->assertNotContains(['kill'], $this->host->children['worker']->calls);
    }

    public function test_scheduler_exits_before_dispatch_when_child_watch_requests_stop(): void
    {
        $watch = \Mockery::mock(EngineChildWatch::class);
        $watch->shouldReceive('shouldStop')->once()->andReturn(true);
        $this->app->instance(EngineChildWatch::class, $watch);
        $this->artisan('spacetraders:engine-schedule')->assertSuccessful();
        $this->assertSame([['onStopSignal']], $this->host->calls);
    }

    public function test_scheduler_checks_for_stop_during_its_dispatch_wait(): void
    {
        $watch = \Mockery::mock(EngineChildWatch::class);
        $watch->shouldReceive('shouldStop')->times(3)->andReturn(false, true, true);
        $this->app->instance(EngineChildWatch::class, $watch);
        $this->artisan('spacetraders:engine-schedule')->assertSuccessful();
    }
}
