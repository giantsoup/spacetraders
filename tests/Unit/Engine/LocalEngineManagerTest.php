<?php

namespace Tests\Unit\Engine;

use App\Gameplay\Engine\EngineHost;
use App\Gameplay\EngineGate;
use App\Gameplay\GameException;
use App\Gameplay\HostLock;
use App\Gameplay\LocalEngineManager;
use App\Models\LocalEngine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LocalEngineManagerTest extends TestCase
{
    use RefreshDatabase;

    private EngineHost $host;

    private LocalEngineManager $manager;

    private ?string $originalBasePath = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->host = Mockery::mock(EngineHost::class);
        $this->app->instance(EngineHost::class, $this->host);
        $this->manager = new LocalEngineManager(new HostLock, new EngineGate, $this->host);
    }

    protected function tearDown(): void
    {
        if ($this->originalBasePath !== null) {
            $this->app->setBasePath($this->originalBasePath);
        }
        parent::tearDown();
    }

    public function test_disabled_gate_never_resolves_the_platform_host(): void
    {
        config(['gameplay.local_engine_enabled' => false]);
        $this->app->forgetInstance(EngineHost::class);
        $this->app->bind(EngineHost::class, fn () => throw new \LogicException('The disabled engine must not resolve a host.'));
        $gate = new EngineGate;
        $this->assertNull($gate->session());
        $gate->check(null);
    }

    public function test_engine_authorization_does_not_resolve_the_platform_host(): void
    {
        config(['gameplay.local_engine_enabled' => true]);
        $this->app->forgetInstance(EngineHost::class);
        $this->app->bind(EngineHost::class, fn () => throw new \LogicException('Authorization must run before host resolution.'));
        $manager = $this->app->make(LocalEngineManager::class);
        try {
            $manager->authorize(\Illuminate\Http\Request::create('/'));
            $this->fail('Engine controls must reject a non-local application.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_an_injected_host_resolver_is_lazy_and_resolves_only_once(): void
    {
        $resolutions = 0;
        $manager = new LocalEngineManager(new HostLock, new EngineGate, function () use (&$resolutions) {
            $resolutions++;

            return $this->host;
        });
        $this->assertSame(0, $resolutions);
        $engine = $this->engine();
        $this->host->shouldReceive('alive')->twice()->with($engine, 'worker')->andReturn(true);
        $this->assertTrue($manager->alive($engine, 'worker'));
        $this->assertTrue($manager->alive($engine, 'worker'));
        $this->assertSame(1, $resolutions);
    }

    public function test_gate_checks_each_role_through_the_host_without_requiring_pids(): void
    {
        config(['gameplay.local_engine_enabled' => true]);
        $engine = $this->engine();
        foreach (['supervisor', 'worker', 'scheduler'] as $role) {
            $this->host->shouldReceive('alive')->twice()->with(Mockery::on(fn ($snapshot) => $snapshot->session_id === $engine->session_id), $role)->andReturn(true);
        }
        $gate = new EngineGate;
        $this->assertSame($engine->session_id, $gate->session());
        $gate->check($engine->session_id);
        $this->assertTrue($engine->fresh()->gate_open);
    }

    public function test_gate_closes_admission_when_a_role_is_lost(): void
    {
        config(['gameplay.local_engine_enabled' => true]);
        $engine = $this->engine();
        $this->host->shouldReceive('alive')->once()->with(Mockery::type(LocalEngine::class), 'supervisor')->andReturn(true);
        $this->host->shouldReceive('alive')->once()->with(Mockery::type(LocalEngine::class), 'worker')->andReturn(false);
        try {
            (new EngineGate)->check($engine->session_id);
            $this->fail('A missing worker must close admission.');
        } catch (GameException $exception) {
            $this->assertSame('Owned engine processes are not alive.', $exception->getMessage());
            $this->assertSame('faulted', $engine->fresh()->state);
            $this->assertFalse($engine->fresh()->gate_open);
        }
    }

    public function test_missing_engine_is_off_and_liveness_is_false_without_a_host_probe(): void
    {
        $this->assertSame(['state' => 'off', 'gate_open' => false, 'message' => 'Local engine is off.'], $this->manager->status());
        $this->assertFalse($this->manager->alive(null, 'supervisor'));
    }

    public function test_liveness_passes_the_snapshot_and_role_to_the_host(): void
    {
        $engine = $this->engine();
        $this->host->shouldReceive('alive')->once()->with($engine, 'worker')->andReturn(true);
        $this->assertTrue($this->manager->alive($engine, 'worker'));
    }

    #[DataProvider('healthCases')]
    public function test_status_preserves_the_heartbeat_and_process_health_formula(string $state, int $age, int $heartbeatAge, array $deadRoles, string $expected): void
    {
        $engine = $this->engine(['state' => $state, 'updated_at' => now()->subSeconds($age), 'heartbeat_at' => now()->subSeconds($heartbeatAge)]);
        $this->host->shouldReceive('alive')->andReturnUsing(fn ($snapshot, $role) => ! in_array($role, $deadRoles, true));
        $status = $this->manager->status();
        $this->assertSame($expected, $status['state']);
        $this->assertSame($expected !== 'faulted', $status['gate_open']);
        $this->assertSame($expected, $engine->fresh()->state);
        if ($expected === 'faulted') {
            $this->assertSame('Engine heartbeat or supervisor lost. Old approvals are closed.', $status['message']);
        }
    }

    public static function healthCases(): array
    {
        return [
            'healthy running' => ['running', 20, 0, [], 'running'],
            'supervisor lost' => ['running', 20, 0, ['supervisor'], 'faulted'],
            'worker lost' => ['running', 20, 0, ['worker'], 'faulted'],
            'scheduler lost' => ['running', 20, 0, ['scheduler'], 'faulted'],
            'heartbeat lost' => ['running', 20, 16, [], 'faulted'],
            'startup grace' => ['starting', 4, 30, ['supervisor'], 'starting'],
            'startup grace expired' => ['starting', 5, 0, ['supervisor'], 'faulted'],
            'starting only needs supervisor' => ['starting', 6, 0, ['worker', 'scheduler'], 'starting'],
            'stopping only needs supervisor' => ['stopping', 20, 0, ['worker', 'scheduler'], 'stopping'],
            'stopping supervisor lost' => ['stopping', 20, 0, ['supervisor'], 'faulted'],
        ];
    }

    public function test_start_requires_both_marker_and_registration_before_launch(): void
    {
        $this->host->shouldReceive('hasServiceMarker')->once()->andReturn(true);
        $this->host->shouldReceive('registered')->once()->andReturn(true);
        $this->host->shouldReceive('launch')->once();
        $this->assertSame(['state' => 'starting', 'message' => 'Start requested. Waiting for verified child processes.'], $this->manager->start());
        $this->assertDatabaseCount('local_engines', 0);
    }

    #[DataProvider('missingServiceCases')]
    public function test_start_refuses_an_incomplete_install(bool $marker, ?bool $registered): void
    {
        $this->host->shouldReceive('hasServiceMarker')->once()->andReturn($marker);
        if ($registered !== null) {
            $this->host->shouldReceive('registered')->once()->andReturn($registered);
        }
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Install this checkout’s owned engine service from the CLI first.');
        $this->manager->start();
    }

    public static function missingServiceCases(): array
    {
        return ['no marker' => [false, null], 'not registered' => [true, false]];
    }

    public function test_start_does_not_restart_a_healthy_running_session(): void
    {
        $engine = $this->engine();
        $this->host->shouldReceive('alive')->times(3)->andReturn(true);
        $this->assertSame($engine->session_id, $this->manager->start()['session_id']);
    }

    public function test_start_refuses_a_previous_supervisor_that_is_still_exiting(): void
    {
        $this->engine(['state' => 'off']);
        $this->host->shouldReceive('alive')->once()->with(Mockery::type(LocalEngine::class), 'supervisor')->andReturn(true);
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('The previous owned supervisor is still exiting. Wait for shutdown or review the force-stop control.');
        $this->manager->start();
    }

    #[DataProvider('forceStopRefusals')]
    public function test_force_stop_keeps_the_grace_and_fault_preconditions(?string $state, ?int $seconds): void
    {
        if ($state !== null) {
            $this->engine(['state' => $state, 'stop_requested_at' => $seconds === null ? null : now()->subSeconds($seconds)]);
        }
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Force stop is available only after the 40-second graceful shutdown allowance.');
        $this->manager->forceStop();
    }

    public static function forceStopRefusals(): array
    {
        return ['missing' => [null, null], 'not faulted' => ['stopping', 50], 'no request' => ['faulted', null], 'too soon' => ['faulted', 39]];
    }

    public function test_force_stop_closes_admission_before_delegating_at_the_40_second_boundary(): void
    {
        $engine = $this->engine(['state' => 'faulted', 'stop_requested_at' => now()->subSeconds(40)]);
        $this->host->shouldReceive('forceStop')->once()->with(Mockery::on(function ($snapshot) use ($engine) {
            $this->assertSame($engine->session_id, $snapshot->session_id);
            $this->assertFalse($engine->fresh()->gate_open);
            $this->assertSame('Force stop requested. Interrupted writes require reconciliation.', $engine->fresh()->message);

            return true;
        }));
        $this->assertSame('faulted', $this->manager->forceStop()['state']);
    }

    public function test_uninstall_delegates_only_after_the_supervisor_is_dead(): void
    {
        $this->engine(['state' => 'off', 'gate_open' => false]);
        $this->host->shouldReceive('alive')->once()->with(Mockery::type(LocalEngine::class), 'supervisor')->andReturn(false);
        $this->host->shouldReceive('uninstall')->once();
        $this->manager->uninstall();
        $this->assertSame('off', LocalEngine::find(1)->state);
    }

    public function test_uninstall_refuses_a_live_previous_supervisor(): void
    {
        $this->engine(['state' => 'off']);
        $this->host->shouldReceive('alive')->once()->andReturn(true);
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('End the session and wait for all owned processes to exit before uninstalling.');
        $this->manager->uninstall();
    }

    public function test_install_rejects_unsupported_platforms(): void
    {
        $this->host->shouldReceive('supported')->once()->andReturn(false);
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Engine installation requires a supported platform (macOS or Windows), local mode, an operator, SQLite and the local engine feature.');
        $this->manager->install();
    }

    public function test_install_requires_an_existing_operator(): void
    {
        $this->installConfiguration();
        User::whereKey(config('gameplay.operator_id'))->delete();
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Engine installation requires the configured operator user to exist.');
        $this->manager->install();
    }

    public function test_install_requires_the_app_url_host_to_match_exactly(): void
    {
        $this->installConfiguration();
        config(['app.url' => 'http://another-checkout.test']);
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Engine installation requires the APP_URL host to match the configured local engine host.');
        $this->manager->install();
    }

    public function test_install_requires_an_executable_php_path(): void
    {
        $this->installConfiguration();
        config(['gameplay.php_binary' => $this->manager->directory().'/missing-php']);
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Configure an absolute executable PHP 8.5 path.');
        $this->manager->install();
    }

    public function test_install_preserves_runtime_preflight(): void
    {
        $this->installConfiguration(1);
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Configure a PHP 8.5 executable with the required worker extensions and matching Composer pins.');
        $this->manager->install();
    }

    public function test_install_refuses_to_reconfigure_an_already_registered_service(): void
    {
        $this->installConfiguration();
        $this->host->shouldReceive('registered')->once()->andReturn(true);
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Owned engine is already installed. End the session and uninstall before reconfiguration.');
        $this->manager->install();
    }

    public function test_install_command_delegates_the_original_environment_and_reports_the_checkout(): void
    {
        $this->installConfiguration();
        $this->host->shouldReceive('registered')->once()->andReturn(false);
        $this->host->shouldReceive('install')->once()->withArgs(function ($php, $env) {
            $this->assertSame(realpath(PHP_BINARY), $php);
            $this->assertSame([
                'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'),
                'APP_CONFIG_CACHE' => $this->manager->directory().'/no-config.php', 'LARAVEL_STORAGE_PATH' => storage_path(),
                'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => config('database.connections.sqlite.database'), 'DATABASE_URL' => '',
                'QUEUE_CONNECTION' => 'database', 'CACHE_STORE' => 'file', 'CACHE_DRIVER' => 'file', 'CACHE_PATH' => config('cache.stores.file.path'), 'LOG_CHANNEL' => 'stderr',
                'SPACETRADERS_DEPLOYMENT_PATH' => config('deployment.path'), 'SPACETRADERS_RELEASE' => config('deployment.release'),
                'SPACETRADERS_LOCK_PATH' => config('gameplay.lock_path'), 'SPACETRADERS_FIXTURE_PATH' => config('gameplay.fixture_path') ?: '',
                'SPACETRADERS_REQUEST_BUDGET_PATH' => config('gameplay.request_budget_path'),
                'SPACETRADERS_AUTOMATION_ENABLED' => config('gameplay.enabled') ? 'true' : 'false',
                'SPACETRADERS_LOCAL_ENGINE_ENABLED' => 'true', 'SPACETRADERS_OPERATOR_ID' => (string) config('gameplay.operator_id'),
                'SPACETRADERS_LOCAL_HOST' => config('gameplay.local_host'), 'SPACETRADERS_PHP_BINARY' => $php,
            ], $env);

            return true;
        });
        $this->host->shouldReceive('platform')->once()->andReturn('Windows');
        $this->host->shouldReceive('serviceName')->once()->andReturn('SpaceTraders-Engine-test');
        $this->host->shouldReceive('logPaths')->once()->andReturn(['supervisor' => $this->manager->directory().'/supervisor.log']);
        $this->artisan('spacetraders:engine-service', ['operation' => 'install'])
            ->expectsOutput('Platform: Windows')
            ->expectsOutput('Service name: SpaceTraders-Engine-test')
            ->expectsOutput('Checkout: '.base_path())
            ->expectsOutput('Engine directory: '.$this->manager->directory())
            ->expectsOutput('Supervisor log: '.$this->manager->directory().'/supervisor.log')
            ->expectsOutput('Engine remains off. Start it from the flight deck. No gameplay approved.')
            ->assertSuccessful();
        $this->assertDatabaseCount('local_engines', 0);
    }

    public function test_status_command_only_observes_a_healthy_engine_and_service(): void
    {
        $engine = $this->engine();
        $before = $engine->fresh()->getRawOriginal();
        $this->host->shouldReceive('platform')->once()->andReturn('Windows');
        $this->host->shouldReceive('serviceName')->once()->andReturn('SpaceTraders-Engine-test');
        $this->host->shouldReceive('logPaths')->once()->andReturn(['worker' => '/engine/worker.log']);
        $this->host->shouldReceive('hasServiceMarker')->once()->andReturn(true);
        $this->host->shouldReceive('registered')->once()->andReturn(true);
        $this->host->shouldReceive('alive')->times(3)->andReturn(true);
        $this->artisan('spacetraders:engine-service', ['operation' => 'status'])
            ->expectsOutput('Platform: Windows')
            ->expectsOutput('Service name: SpaceTraders-Engine-test')
            ->expectsOutput('Checkout: '.base_path())
            ->expectsOutput('Engine directory: '.$this->manager->directory())
            ->expectsOutput('Worker log: /engine/worker.log')
            ->expectsOutput('Installed: yes')
            ->expectsOutput('Registered: yes')
            ->expectsOutput('Engine state: running')
            ->expectsOutput('Engine message: Test engine.')
            ->assertSuccessful();
        $this->assertSame($before, $engine->fresh()->getRawOriginal());
    }

    private function engine(array $attributes = []): LocalEngine
    {
        return LocalEngine::create(array_merge(['id' => 1, 'state' => 'running', 'gate_open' => true, 'session_id' => 'test-session', 'heartbeat_at' => now(), 'processes' => [], 'message' => 'Test engine.'], $attributes));
    }

    private function installConfiguration(int $preflightExit = 0): void
    {
        $operator = User::factory()->create();
        $this->app['env'] = 'local';
        config(['gameplay.local_engine_enabled' => true, 'gameplay.operator_id' => $operator->id, 'app.url' => 'http://spacetraders.test', 'gameplay.local_host' => 'spacetraders.test', 'gameplay.php_binary' => PHP_BINARY]);
        $this->host->shouldReceive('supported')->once()->andReturn(true);
        $this->originalBasePath = base_path();
        $checkout = $this->manager->directory().'/checkout';
        mkdir($checkout.'/scripts', 0700, true);
        file_put_contents($checkout.'/.php-version', '8.5');
        file_put_contents($checkout.'/scripts/runtime-preflight.php', '<?php exit('.$preflightExit.');');
        $this->app->setBasePath($checkout);
    }
}
