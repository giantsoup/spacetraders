<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\Engine\EngineHost;
use App\Gameplay\ShipCommandExecutor;
use App\Jobs\AdvanceAutomationRun;
use App\Jobs\ExecuteShipCommand;
use App\Models\LocalEngine;
use App\Models\ShipAction;
use App\Models\User;
use App\Queue\RequireEngineWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\EngineFixture;
use Tests\Support\FakeEngineHost;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class EngineWorkerGuardTest extends TestCase
{
    use RefreshDatabase;

    private string $worldFile;

    private string|false $originalSession;

    private LocalEngine $engine;

    private ShipAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->originalSession = getenv('SPACETRADERS_ENGINE_WORKER_SESSION');
        putenv('SPACETRADERS_ENGINE_WORKER_SESSION');
        config(['gameplay.enabled' => true, 'gameplay.local_engine_enabled' => true, 'queue.connections.database.after_commit' => false]);
        $this->app->instance(EngineHost::class, new FakeEngineHost);
        $this->engine = EngineFixture::healthy();
        $this->worldFile = tempnam(sys_get_temp_dir(), 'st-worker-guard-');
        file_put_contents($this->worldFile, json_encode(FakeSpaceTraders::world()));
        FakeSpaceTraders::install($this->worldFile);
        $agent = User::factory()->create()->agents()->create([
            'name' => 'LOCAL', 'token' => 'synthetic-agent-token', 'account_id' => 'synthetic-account', 'faction' => 'COSMIC',
            'reset_id' => '2026-10-25', 'canonical_identity' => AgentSynchronizer::identity('2026-10-25', 'LOCAL'),
        ]);
        app(AgentSynchronizer::class)->sync($agent);
        $executor = app(ShipCommandExecutor::class);
        $this->action = $executor->preview($agent->fresh(), 'LOCAL-1', 'orbit', [], (string) Str::uuid());
        $executor->approve($agent->fresh(), $this->action);
        $this->action->refresh();
        DB::table('jobs')->delete();
        Http::swap(new Factory);
        Http::fake();
    }

    protected function tearDown(): void
    {
        putenv($this->originalSession === false ? 'SPACETRADERS_ENGINE_WORKER_SESSION' : 'SPACETRADERS_ENGINE_WORKER_SESSION='.$this->originalSession);
        unlink($this->worldFile);
        parent::tearDown();
    }

    #[DataProvider('unauthorizedWorkers')]
    public function test_unowned_workers_release_without_gameplay_effects(?string $session, string $state, bool $engineExists): void
    {
        if ($session !== null) {
            putenv('SPACETRADERS_ENGINE_WORKER_SESSION='.($session === 'matching' ? $this->engine->session_id : $session));
        }
        $this->engine->update(['state' => $state]);
        if (! $engineExists) {
            $this->engine->delete();
        }
        $before = $this->action->getRawOriginal();
        $runtime = DB::table('game_runtime')->get()->toJson();
        $job = new ExecuteShipCommand($this->action->id);
        Bus::dispatch($job);
        $this->work();

        $this->assertSame('pending', $this->action->fresh()->state->value);
        $this->assertSame($before, $this->action->fresh()->getRawOriginal());
        $this->assertSame($runtime, DB::table('game_runtime')->get()->toJson());
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('jobs', ['queue' => 'spacetraders', 'reserved_at' => null, 'attempts' => 1, 'available_at' => now()->addSeconds(5)->timestamp]);
        $this->assertDatabaseCount('failed_jobs', 0);
        Http::assertNothingSent();
    }

    public static function unauthorizedWorkers(): array
    {
        return [
            'plain worker' => [null, 'running', true],
            'old session' => ['old-session', 'running', true],
            'engine starting' => ['matching', 'starting', true],
            'engine stopping' => ['matching', 'stopping', true],
            'engine off' => ['matching', 'off', true],
            'engine faulted' => ['matching', 'faulted', true],
            'missing engine' => ['matching', 'running', false],
        ];
    }

    public function test_repeated_releases_do_not_exhaust_the_one_exception_limit_before_owned_execution(): void
    {
        Bus::dispatch(new ExecuteShipCommand($this->action->id));
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->work();
            $this->travel(5)->seconds();
        }
        Http::assertNothingSent();
        $this->engine->update(['heartbeat_at' => now()]);
        putenv('SPACETRADERS_ENGINE_WORKER_SESSION='.$this->engine->session_id);
        Http::swap(new Factory);
        FakeSpaceTraders::install($this->worldFile);
        $this->work();

        $this->assertSame('confirmed', $this->action->fresh()->state->value);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/orbit'));
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_engine_disabled_allows_terminal_and_practice_workers(): void
    {
        config(['gameplay.local_engine_enabled' => false]);
        Http::swap(new Factory);
        FakeSpaceTraders::install($this->worldFile);
        Bus::dispatch(new ExecuteShipCommand($this->action->id));
        $this->work();

        $this->assertSame('confirmed', $this->action->fresh()->state->value);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/orbit'));
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_automation_is_guarded_and_warnings_are_once_per_process(): void
    {
        $warning = new \ReflectionProperty(RequireEngineWorker::class, 'warned');
        $previous = $warning->getValue();
        $warning->setValue(null, false);
        $logger = Log::getFacadeRoot();
        Log::shouldReceive('warning')->once()->with('Gameplay job released: this worker does not own the current running engine session.');
        try {
            Bus::dispatch(new AdvanceAutomationRun(123));
            Bus::dispatch(new ExecuteShipCommand($this->action->id));
            $this->work();
            $this->work();
            $this->assertDatabaseCount('jobs', 2);
            $this->assertDatabaseCount('automation_runs', 0);
            $this->assertDatabaseCount('failed_jobs', 0);
            Http::assertNothingSent();
        } finally {
            $warning->setValue(null, $previous);
            Log::swap($logger);
        }
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_owned_or_engine_disabled_workers_can_advance_automation(bool $enabled): void
    {
        config(['gameplay.local_engine_enabled' => $enabled]);
        if ($enabled) {
            putenv('SPACETRADERS_ENGINE_WORKER_SESSION='.$this->engine->session_id);
        }
        $runner = \Mockery::mock(AutomationRunner::class);
        $runner->shouldReceive('advance')->once()->with(123);
        $this->app->instance(AutomationRunner::class, $runner);
        Bus::dispatch(new AdvanceAutomationRun(123));
        $this->work();

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_actual_handler_exceptions_fail_once_without_replaying(): void
    {
        putenv('SPACETRADERS_ENGINE_WORKER_SESSION='.$this->engine->session_id);
        $executor = \Mockery::mock(ShipCommandExecutor::class);
        $executor->shouldReceive('execute')->once()->with($this->action->id)->andThrow(new \RuntimeException('Synthetic worker exception.'));
        $this->app->instance(ShipCommandExecutor::class, $executor);
        Bus::dispatch(new ExecuteShipCommand($this->action->id));
        $this->work();

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertSame('pending', $this->action->fresh()->state->value);
        Http::assertNothingSent();
    }

    public function test_default_worker_does_not_reserve_gameplay_jobs(): void
    {
        $this->assertSame('default', config('queue.connections.database.queue'));
        foreach ([new ExecuteShipCommand($this->action->id), new AdvanceAutomationRun(123)] as $job) {
            $this->assertSame('database', $job->connection);
            $this->assertSame('spacetraders', $job->queue);
            Bus::dispatch($job);
        }
        $this->assertNull(Queue::connection('database')->pop());
        $this->assertDatabaseCount('jobs', 2);
        $this->assertSame(0, DB::table('jobs')->sum('attempts'));
        Http::assertNothingSent();
    }

    private function work(): void
    {
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'spacetraders', '--once' => true, '--sleep' => 0, '--tries' => 1, '--timeout' => 0]);
    }
}
