<?php

namespace Tests\Feature;

use App\Gameplay\Engine\EngineHost;
use App\Gameplay\LocalEngineManager;
use App\Models\LocalEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocalEngineServiceCommandTest extends TestCase
{
    use RefreshDatabase;

    private LocalEngineManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Sleep::fake(syncWithCarbon: true);
        $this->manager = Mockery::mock(LocalEngineManager::class);
        $this->app->instance(LocalEngineManager::class, $this->manager);
        $this->app->instance(EngineHost::class, Mockery::mock(EngineHost::class));
        $this->manager->shouldNotReceive('forceStop', 'install', 'uninstall');
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        parent::tearDown();
    }

    public function test_start_waits_until_running(): void
    {
        $this->manager->shouldReceive('status')->andReturn(
            ['state' => 'off'], ['state' => 'starting'], ['state' => 'running'],
        );
        $this->manager->shouldReceive('start')->once()->andReturn(['state' => 'starting']);
        $this->manager->shouldNotReceive('end');

        $this->artisan('spacetraders:engine-service', ['operation' => 'start'])
            ->expectsOutput('Engine state: running')
            ->assertSuccessful();

        Sleep::assertSequence([Sleep::for(500)->milliseconds()]);
    }

    public function test_start_refuses_a_faulted_engine_without_starting(): void
    {
        $this->manager->shouldReceive('status')->once()->andReturn(['state' => 'faulted', 'message' => 'Heartbeat lost.']);
        $this->manager->shouldNotReceive('start', 'end');

        $this->artisan('spacetraders:engine-service', ['operation' => 'start'])
            ->expectsOutput('Engine is faulted. Review it on spacetraders.test before starting.')
            ->assertFailed();

        Sleep::assertNeverSlept();
    }

    public function test_start_timeout_reports_the_last_state_and_message(): void
    {
        $startedAt = now();
        $this->manager->shouldReceive('status')->andReturn(
            ['state' => 'off'], ['state' => 'starting', 'message' => 'Waiting for worker.'],
        );
        $this->manager->shouldReceive('start')->once()->andReturn(['state' => 'starting']);
        $this->manager->shouldNotReceive('end');

        $this->artisan('spacetraders:engine-service', ['operation' => 'start'])
            ->expectsOutput('Engine state: starting')
            ->expectsOutput('Engine message: Waiting for worker.')
            ->expectsOutputToContain('60 seconds')
            ->assertFailed();

        Sleep::assertSleptTimes(120);
        $this->assertSame(60, (int) $startedAt->diffInSeconds(now()));
    }

    public function test_end_succeeds_immediately_when_already_off(): void
    {
        $this->manager->shouldReceive('status')->once()->andReturn(['state' => 'off']);
        $this->manager->shouldNotReceive('end', 'start', 'alive');

        $this->artisan('spacetraders:engine-service', ['operation' => 'end'])
            ->expectsOutput('Engine state: off')
            ->assertSuccessful();

        Sleep::assertNeverSlept();
    }

    #[DataProvider('roles')]
    public function test_end_waits_for_off_and_each_owned_role_to_exit_using_the_original_snapshot(string $liveRole): void
    {
        $engine = LocalEngine::create(['id' => 1, 'state' => 'running', 'session_id' => 'original-session', 'processes' => [$liveRole => ['pid' => 1234]]]);
        $this->manager->shouldReceive('status')->andReturn(
            ['state' => 'running'], ['state' => 'stopping'], ['state' => 'off'], ['state' => 'off'],
        );
        $this->manager->shouldReceive('end')->once()->andReturnUsing(function () use ($engine) {
            $engine->update(['state' => 'off', 'processes' => []]);

            return ['state' => 'stopping'];
        });
        $this->manager->shouldNotReceive('start');
        foreach (['supervisor', 'worker', 'scheduler'] as $role) {
            $this->manager->shouldReceive('alive')->with(Mockery::on(fn ($snapshot) => $snapshot instanceof LocalEngine
                && $snapshot->session_id === 'original-session'
                && $snapshot->processes === [$liveRole => ['pid' => 1234]]), $role)
                ->andReturn(...($role === $liveRole ? [true, true, false] : [false]));
        }

        $this->artisan('spacetraders:engine-service', ['operation' => 'end'])
            ->expectsOutput('Engine state: off')
            ->assertSuccessful();

        Sleep::assertSequence([Sleep::for(500)->milliseconds(), Sleep::for(500)->milliseconds()]);
    }

    public static function roles(): array
    {
        return ['supervisor' => ['supervisor'], 'worker' => ['worker'], 'scheduler' => ['scheduler']];
    }

    #[DataProvider('endTimeouts')]
    public function test_end_timeout_requires_operator_force_stop_without_using_it(string $state, bool $alive): void
    {
        LocalEngine::create(['id' => 1, 'state' => 'running', 'processes' => []]);
        $this->manager->shouldReceive('status')->andReturn(
            ['state' => 'running'], ['state' => $state, 'message' => 'Shutdown incomplete.'],
        );
        $this->manager->shouldReceive('end')->once()->andReturn(['state' => 'stopping']);
        $this->manager->shouldReceive('alive')->andReturn($alive);
        $this->manager->shouldNotReceive('start');

        $this->artisan('spacetraders:engine-service', ['operation' => 'end'])
            ->expectsOutput('Engine state: '.$state)
            ->expectsOutput('Engine message: Shutdown incomplete.')
            ->expectsOutputToContain('Force stop on spacetraders.test')
            ->assertFailed();

        Sleep::assertSleptTimes(120);
    }

    public static function endTimeouts(): array
    {
        return ['off with surviving processes' => ['off', true], 'not off with no processes' => ['stopping', false]];
    }
}
