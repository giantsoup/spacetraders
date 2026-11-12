<?php

namespace Tests\Feature;

use App\Gameplay\DeploymentGate;
use App\Queue\DeploymentDatabaseQueue;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class DeploymentGateTest extends TestCase
{
    use RefreshDatabase;

    private DeploymentGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'file', 'cache.stores.file.path' => config('deployment.path').'/cache']);
        Cache::purge('file');
        $this->gate = app(DeploymentGate::class);
    }

    protected function tearDown(): void
    {
        unset($this->gate);
        parent::tearDown();
    }

    public function test_close_requires_actual_generation_acknowledgements_and_preserves_pending_jobs(): void
    {
        $web = $this->gate->begin('web');
        $scheduler = $this->gate->begin('scheduler');
        $this->gate->workerPolling();
        $this->gate->close('release-1');
        $this->assertTrue(Queue::isPaused('database', 'spacetraders'));
        $this->assertFalse($this->gate->status()['drained']);
        $this->assertNull($this->gate->begin('web'));
        $this->gate->workerPolling();
        $this->gate->finish($scheduler);
        $this->assertFalse($this->gate->status()['drained']);
        $this->gate->finish($web);
        $this->assertTrue($this->gate->status()['drained']);
        $this->assertSame('closed', $this->gate->close('release-1')['phase']);
        $this->gate->open('release-1');
        $this->assertFalse(Queue::isPaused('database', 'spacetraders'));
        $this->assertNotNull($this->gate->begin('web'));
    }

    public function test_all_dynamic_requests_receive_dark_maintenance_before_sessions_or_controllers(): void
    {
        $this->gate->close('maintenance');
        $this->get('/agents')->assertStatus(503)->assertHeader('Retry-After', '5')->assertSee('color-scheme: dark', false);
        $this->post('/register', ['name' => 'Blocked', 'email' => 'blocked@example.test', 'password' => 'password', 'password_confirmation' => 'password'])->assertStatus(503);
        $this->assertDatabaseCount('users', 0);
        $this->gate->open('maintenance');
        $this->get('/login')->assertOk();
    }

    public function test_ten_minute_deadline_aborts_and_restores_prior_pickup_without_touching_work(): void
    {
        $web = $this->gate->begin('web');
        $this->gate->close('timeout');
        $this->travel(599)->seconds();
        $this->assertSame('draining', $this->gate->status()['phase']);
        $this->travel(1)->seconds();
        $status = $this->gate->status();
        $this->assertSame('open', $status['phase']);
        $this->assertSame('aborted_timeout', $status['outcome']);
        $this->assertFalse(Queue::isPaused('database', 'spacetraders'));
        $this->gate->finish($web);
        Queue::pause('database', 'spacetraders');
        $this->gate->close('prior-pause');
        $this->travel(600)->seconds();
        $this->assertSame('aborted_timeout', $this->gate->status()['outcome']);
        $this->assertTrue(Queue::isPaused('database', 'spacetraders'));
    }

    public function test_closed_gate_survives_deadline_and_stale_open_cannot_resume_another_deployment(): void
    {
        $this->gate->workerPolling();
        $this->gate->close('first');
        $this->gate->workerPolling();
        $this->gate->begin('scheduler');
        $this->assertTrue($this->gate->status()['drained']);
        $this->travel(601)->seconds();
        $this->assertSame('closed', $this->gate->status()['phase']);
        $this->gate->open('first');
        $this->gate->close('second');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('stale controller');
        $this->gate->open('first');
    }

    public function test_pause_is_repaired_and_direct_database_pop_cannot_bypass_close(): void
    {
        $this->assertInstanceOf(DeploymentDatabaseQueue::class, Queue::connection('database'));
        $id = Queue::connection('database')->pushRaw(json_encode(['displayName' => 'retained', 'job' => 'unused']), 'spacetraders');
        $this->gate->close('race');
        Queue::resume('database', 'spacetraders');
        $this->assertNull(Queue::connection('database')->pop('spacetraders'));
        $this->assertTrue(Queue::isPaused('database', 'spacetraders'));
        $this->assertDatabaseHas('jobs', ['id' => $id, 'attempts' => 0, 'reserved_at' => null]);
        $this->assertSame(0, Artisan::call('spacetraders:dispatch-due'));
        $this->assertFalse($this->gate->status()['drained']);
        $this->gate->begin('scheduler');
        $this->assertTrue($this->gate->status()['drained']);
        $this->gate->open('race');
        $job = Queue::connection('database')->pop('spacetraders');
        $this->assertNotNull($job);
        $this->assertDatabaseHas('jobs', ['id' => $id, 'attempts' => 1]);
    }

    public function test_ephemeral_cache_and_unknown_contract_versions_fail_closed(): void
    {
        config(['cache.default' => 'array']);
        try {
            $this->gate->close('bad-cache');
            $this->fail('An array cache cannot coordinate releases.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('persistent file cache', $e->getMessage());
        }
        $path = config('deployment.path').'/state.json';
        file_put_contents($path, json_encode(['version' => 99, 'phase' => 'open']));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported deployment protocol');
        $this->gate->begin('web');
    }

    public function test_schedule_has_stable_release_independent_expiring_overlap_protection(): void
    {
        $event = collect(app(Schedule::class)->events())->first();
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(2, $event->expiresAt);
        $this->assertSame('spacetraders:dispatch-due:v1', $event->mutexName());
    }

    public function test_missing_active_writer_ack_and_reserved_rows_cannot_be_inferred_from_stale_heartbeats(): void
    {
        $writer = new DeploymentGate;
        $writer->begin('web');
        $this->gate->workerPolling();
        $this->gate->close('lost-writer');
        $this->gate->workerPolling();
        $this->gate->begin('scheduler');
        unset($writer);
        $status = $this->gate->status();
        $this->assertFalse($status['drained']);
        $this->assertTrue(collect($status['blockers'])->contains(fn ($blocker) => str_contains($blocker, 'lost_without_ack')));
        $this->gate->open('lost-writer');
    }

    public function test_dead_workers_are_removed_before_close_but_live_workers_remain_required(): void
    {
        $dead = new DeploymentGate;
        $dead->workerPolling();
        $deadId = array_key_first($dead->status()['participants']);
        unset($dead);
        $live = new DeploymentGate;
        $live->workerPolling();
        $closed = $this->gate->close('worker-leases');
        $this->assertArrayNotHasKey($deadId, $closed['participants']);
        $this->assertNotContains($deadId, $closed['required']);
        $this->assertFileDoesNotExist(config('deployment.path').'/'.$deadId.'.lease');
        $this->assertCount(1, $closed['required']);
        $this->assertFalse($this->gate->status()['drained']);
        $live->workerPolling();
        $this->gate->begin('scheduler');
        $this->assertTrue($this->gate->status()['drained']);
        $this->gate->open('worker-leases');
    }

    public function test_release_with_different_cache_paths_cannot_acknowledge_a_shared_gate(): void
    {
        $this->gate->close('shared-paths');
        config(['cache.stores.file.path' => config('deployment.path').'/wrong-cache']);
        Cache::purge('file');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('runtime identity');
        $this->gate->workerPolling();
    }

    public function test_web_admission_covers_application_termination_callbacks(): void
    {
        Route::get('/_owned-termination', function () {
            app()->terminating(function () {
                $this->gate->close('terminating-write');
                $state = $this->gate->status();
                $this->assertTrue(collect($state['participants'])->contains(fn ($p) => $p['role'] === 'web' && $p['busy']));
            });

            return response('ok');
        });
        $this->get('/_owned-termination')->assertOk();
        $this->assertTrue(collect($this->gate->status()['participants'])->contains(fn ($p) => $p['role'] === 'web' && $p['ack'] === 'terminating-write' && ! $p['busy']));
    }

    public function test_unwind_preserves_global_pause_without_leaving_an_extra_queue_pause(): void
    {
        Queue::pauseAll();
        $this->gate->close('global-pause');
        $this->gate->open('global-pause');
        $this->assertTrue(Queue::isPaused('database', 'spacetraders'));
        Queue::resumeAll();
        $this->assertFalse(Queue::isPaused('database', 'spacetraders'));
    }
}
