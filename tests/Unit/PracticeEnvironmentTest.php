<?php

namespace Tests\Unit;

use App\Gameplay\Engine\EngineHost;
use App\Gameplay\LocalEngineManager;
use App\Models\User;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use SpaceTraders\Windows\PracticeEnvironment;
use Tests\TestCase;

require_once __DIR__.'/../../scripts/windows/PracticeEnvironment.php';

class PracticeEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = config('gameplay.lock_path').'/practice';
        mkdir($this->directory, 0700, true);
    }

    public function test_old_practice_defaults_to_worker_mode(): void
    {
        $this->assertSame('worker', PracticeEnvironment::mode($this->directory));
        PracticeEnvironment::assertMode($this->directory, false, 'queue:work');
    }

    public function test_unknown_persisted_mode_is_refused(): void
    {
        file_put_contents($this->directory.'/.practice-mode', 'unknown');
        $this->expectExceptionMessage('Invalid persisted practice mode');
        PracticeEnvironment::mode($this->directory);
    }

    #[TestWith(['queue:work'])]
    #[TestWith(['queue:listen'])]
    #[TestWith(['schedule:work'])]
    #[TestWith(['schedule:run'])]
    #[TestWith(['spacetraders:engine-supervise'])]
    #[TestWith(['--verify-worker'])]
    public function test_engine_practice_refuses_plain_children(string $command): void
    {
        file_put_contents($this->directory.'/.practice-mode', 'engine');
        $this->expectExceptionMessage('Plain workers and schedulers are forbidden');
        PracticeEnvironment::assertMode($this->directory, true, $command);
    }

    #[TestWith(['--engine-verify'])]
    #[TestWith(['--engine-status'])]
    #[TestWith(['spacetraders:engine-service'])]
    #[TestWith(['spacetraders:engine-supervise'])]
    public function test_worker_practice_refuses_engine_controls(string $command): void
    {
        $this->expectExceptionMessage('Engine controls are forbidden in worker practice');
        PracticeEnvironment::assertMode($this->directory, false, $command);
    }

    #[TestWith(['worker', true])]
    #[TestWith(['engine', false])]
    public function test_mode_and_effective_config_must_agree(string $mode, bool $enabled): void
    {
        file_put_contents($this->directory.'/.practice-mode', $mode);
        $this->expectExceptionMessage('Practice mode does not match');
        PracticeEnvironment::assertMode($this->directory, $enabled, 'env');
    }

    public function test_main_checkout_task_identity_is_refused_before_any_host_mutation(): void
    {
        $host = Mockery::mock(EngineHost::class);
        $host->shouldReceive('serviceName')->once()->andReturn('main-task');
        $this->app->instance(EngineHost::class, $host);
        $this->expectExceptionMessage('Practice must never use the main checkout engine task');
        PracticeEnvironment::assertEngineIdentity('main-task');
    }

    public function test_configuration_persists_actual_seeded_operator_and_preserves_secrets_and_paths(): void
    {
        User::factory()->create();
        $operator = User::factory()->create();
        file_put_contents($this->directory.'/browser.json', json_encode(['email' => $operator->email], JSON_THROW_ON_ERROR));
        file_put_contents($this->directory.'/.env', "APP_KEY=unchanged\r\nDB_DATABASE=owned.sqlite\r\nSPACETRADERS_LOCAL_ENGINE_ENABLED=false\r\n");
        PracticeEnvironment::configureEngine($this->directory);
        $environment = file_get_contents($this->directory.'/.env');
        $values = Dotenv::parse($environment);
        $this->assertSame('engine', PracticeEnvironment::mode($this->directory));
        $this->assertSame('true', $values['SPACETRADERS_LOCAL_ENGINE_ENABLED']);
        $this->assertSame('true', $values['SPACETRADERS_AUTOMATION_ENABLED']);
        $this->assertSame((string) $operator->id, $values['SPACETRADERS_OPERATOR_ID']);
        $this->assertSame('127.0.0.1', $values['SPACETRADERS_LOCAL_HOST']);
        $this->assertSame('http://127.0.0.1:8010', $values['APP_URL']);
        $this->assertSame('unchanged', $values['APP_KEY']);
        $this->assertSame('owned.sqlite', $values['DB_DATABASE']);
        $this->assertSame(1, substr_count($environment, 'SPACETRADERS_LOCAL_ENGINE_ENABLED='));
        PracticeEnvironment::configureEngine($this->directory);
        $this->assertSame($environment, file_get_contents($this->directory.'/.env'));
        PracticeEnvironment::assertMode($this->directory, true, 'spacetraders:engine-service');
    }

    #[TestWith(['off', false, [], true])]
    #[TestWith(['faulted', false, [], true])]
    #[TestWith(['running', false, [], false])]
    #[TestWith(['stopping', false, [], false])]
    #[TestWith(['off', true, [], false])]
    #[TestWith(['off', false, ['worker' => true], false])]
    #[TestWith(['faulted', false, ['supervisor' => true], false])]
    #[TestWith(['faulted', false, ['scheduler' => true], false])]
    #[TestWith(['off', false, ['worker' => null], false])]
    public function test_shutdown_requires_terminal_state_closed_gate_and_every_released_lease(string $state, bool $gate, array $leases, bool $expected): void
    {
        $this->assertSame($expected, PracticeEnvironment::stopped([
            'state' => $state, 'gate_open' => $gate,
            'leases' => array_replace(['supervisor' => false, 'worker' => false, 'scheduler' => false], $leases),
        ]));
    }

    public function test_end_uses_the_ui_manager_and_waits_for_closed_gate_and_released_leases(): void
    {
        $manager = Mockery::mock(LocalEngineManager::class);
        $manager->shouldReceive('status')->twice()->andReturn(
            ['state' => 'running', 'gate_open' => true], ['state' => 'off', 'gate_open' => false],
        );
        $manager->shouldReceive('alive')->times(6)->andReturn(false);
        $manager->shouldReceive('end')->once();
        $host = Mockery::mock(EngineHost::class);
        $host->shouldReceive('serviceName')->twice()->andReturn('practice-task');
        $host->shouldReceive('registered')->twice()->andReturn(true);
        $host->shouldReceive('hasServiceMarker')->twice()->andReturn(true);
        $this->app->instance(LocalEngineManager::class, $manager);
        $this->app->instance(EngineHost::class, $host);
        $this->assertTrue(PracticeEnvironment::stopped(PracticeEnvironment::end(false)));
    }

    public function test_force_end_waits_for_the_current_stop_allowance_after_a_mid_shutdown_fault(): void
    {
        $started = now()->startOfSecond();
        $this->travelTo($started);
        $forced = false;
        $poll = 0;
        $manager = Mockery::mock(LocalEngineManager::class);
        $manager->shouldReceive('end')->once();
        $manager->shouldReceive('status')->times(6)->andReturnUsing(function () use ($started, &$poll, &$forced) {
            $seconds = [0, 39, 45, 69, 70, 70][$poll++];
            $this->travelTo($started->copy()->addSeconds($seconds));

            return [
                'state' => $forced ? 'off' : ($seconds < 45 ? 'stopping' : 'faulted'),
                'gate_open' => false,
                'stop_requested_at' => $started->copy()->addSeconds($seconds < 45 ? 0 : 30),
            ];
        });
        $manager->shouldReceive('alive')->times(18)->andReturnUsing(function ($engine, $role) use (&$forced) {
            return $role === 'worker' && ! $forced;
        });
        $manager->shouldReceive('forceStop')->once()->andReturnUsing(function () use ($started, &$forced) {
            $this->assertSame($started->copy()->addSeconds(70)->getTimestamp(), now()->getTimestamp());
            $forced = true;

            return [];
        });
        $host = Mockery::mock(EngineHost::class);
        $host->shouldReceive('serviceName')->times(6)->andReturn('practice-task');
        $host->shouldReceive('registered')->times(6)->andReturn(true);
        $host->shouldReceive('hasServiceMarker')->times(6)->andReturn(true);
        $this->app->instance(LocalEngineManager::class, $manager);
        $this->app->instance(EngineHost::class, $host);
        $this->assertTrue(PracticeEnvironment::stopped(PracticeEnvironment::end(true)));
        $this->assertTrue($forced);
    }

    #[TestWith(['http://localhost:8010', '127.0.0.1'])]
    #[TestWith(['http://127.0.0.1:8010', 'spacetraders.test'])]
    public function test_engine_host_must_match_the_loopback_browser_url(string $url, string $host): void
    {
        $engineHost = Mockery::mock(EngineHost::class);
        $engineHost->shouldReceive('serviceName')->once()->andReturn('practice-task');
        $this->app->instance(EngineHost::class, $engineHost);
        $this->app['env'] = 'local';
        config(['gameplay.enabled' => true, 'gameplay.operator_id' => 1, 'app.url' => $url, 'gameplay.local_host' => $host]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('127.0.0.1:8010 host');
        PracticeEnvironment::assertEngineIdentity('main-task');
    }
}
