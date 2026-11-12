<?php

namespace Tests\Feature;

use App\Enums\ActionState;
use App\Gameplay\AgentSynchronizer;
use App\Gameplay\RequestBudget;
use App\Gameplay\RequestDeferred;
use App\Gameplay\ShipCommandExecutor;
use App\Models\Agent;
use App\Models\User;
use App\Services\SpaceTradersClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class BudgetResponseTest extends TestCase
{
    use RefreshDatabase;

    private string $worldFile;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->worldFile = tempnam(sys_get_temp_dir(), 'st-budget-response-');
        file_put_contents($this->worldFile, json_encode(FakeSpaceTraders::world()));
        FakeSpaceTraders::install($this->worldFile);
        $user = User::factory()->create();
        $this->agent = $user->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic-response-secret', 'account_id' => 'synthetic-account', 'faction' => 'COSMIC',
            'reset_id' => '2026-10-25', 'canonical_identity' => AgentSynchronizer::identity('2026-10-25', 'LOCAL')]);
        app(AgentSynchronizer::class)->sync($this->agent);
    }

    protected function tearDown(): void
    {
        unlink($this->worldFile);
        parent::tearDown();
    }

    public static function holdDurations(): array
    {
        return [[150, true], [1900, false]];
    }

    #[DataProvider('holdDurations')]
    public function test_successful_write_confirms_when_response_recording_contends(int $milliseconds, bool $recorded): void
    {
        $this->contendOnWriteResponse($milliseconds);
        $executor = app(ShipCommandExecutor::class);
        $action = $executor->preview($this->agent, 'LOCAL-1', 'orbit', [], (string) Str::uuid());
        $executor->approve($this->agent, $action);
        $executor->execute($action->id);
        $world = json_decode(file_get_contents($this->worldFile), true);
        $this->assertSame(ActionState::Confirmed, $action->fresh()->state, $action->fresh()->message);
        $this->assertCount(1, $world['writes']);
        $this->assertSame('IN_ORBIT', $world['ships'][0]['nav']['status']);
        if ($recorded) {
            $this->assertSame(count($world['calls']), app(RequestBudget::class)->usage()['host']['responses']);
        } else {
            $this->assertSame(count($world['calls']) - 1, app(RequestBudget::class)->usage()['host']['responses']);
            $log = file_get_contents(storage_path('logs/gameplay.log'));
            $this->assertStringContainsString('response budget recording failed', $log);
            $this->assertStringNotContainsString('synthetic-response-secret', $log);
            $this->assertStringNotContainsString('synthetic-account', $log);
            $this->assertProcessTransportPaused($world);
        }
        // Redispatch must never duplicate an accepted mutation.
        $executor->execute($action->id);
        $this->assertCount(1, json_decode(file_get_contents($this->worldFile), true)['writes']);
    }

    public static function failedWriteStatuses(): array
    {
        return [[429], [503]];
    }

    #[DataProvider('failedWriteStatuses')]
    public function test_unsaved_error_pause_retains_uncertainty_and_blocks_process_transport_until_expiry(int $status): void
    {
        $this->contendOnWriteResponse(1900);
        $executor = app(ShipCommandExecutor::class);
        $action = $executor->preview($this->agent, 'LOCAL-1', 'orbit', [], (string) Str::uuid());
        $executor->approve($this->agent, $action);
        $world = json_decode(file_get_contents($this->worldFile), true);
        $world['failure'] = (string) $status;
        file_put_contents($this->worldFile, json_encode($world));
        $executor->execute($action->id);
        $this->assertSame(ActionState::Uncertain, $action->fresh()->state);
        $world = json_decode(file_get_contents($this->worldFile), true);
        $this->assertSame([], $world['writes']);
        $this->assertProcessTransportPaused($world);
        // A later budget instance in the same worker process must inherit the hold.
        $clock = new class extends RequestBudget
        {
            protected function now(): float
            {
                return microtime(true) + 300;
            }
        };
        app()->instance(RequestBudget::class, $clock);
        $this->assertTrue(app(SpaceTradersClient::class)->request('get', '/')->successful());
        $this->assertCount(count($world['calls']) + 1, json_decode(file_get_contents($this->worldFile), true)['calls']);
    }

    public static function responseValidity(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('responseValidity')]
    public function test_budget_exceptions_preserve_success_but_do_not_bypass_game_response_validation(bool $valid): void
    {
        if (! $valid) {
            $world = json_decode(file_get_contents($this->worldFile), true);
            $world['ships'][0]['nav']['status'] = 'IN_ORBIT';
            $world['failure'] = 'malformed';
            file_put_contents($this->worldFile, json_encode($world));
        }
        $state = (object) ['post' => false];
        Event::listen(RequestSending::class, fn ($event) => $state->post = $event->request->method() === 'POST');
        app()->instance(RequestBudget::class, new class($state) extends RequestBudget
        {
            public function __construct(private \stdClass $state) {}

            public function observe(Response $response, ?string $token = null, ?string $account = null): void
            {
                if ($this->state->post) {
                    throw new \RuntimeException('never-log-this-budget-exception');
                }
                parent::observe($response, $token, $account);
            }
        });
        $executor = app(ShipCommandExecutor::class);
        $action = $executor->preview($this->agent, 'LOCAL-1', $valid ? 'orbit' : 'mine', [], (string) Str::uuid());
        $executor->approve($this->agent, $action);
        $executor->execute($action->id);
        $this->assertSame($valid ? ActionState::Confirmed : ActionState::Uncertain, $action->fresh()->state);
        $this->assertCount(1, json_decode(file_get_contents($this->worldFile), true)['writes']);
        $this->assertStringNotContainsString('never-log-this-budget-exception', file_get_contents(storage_path('logs/gameplay.log')));
    }

    private function assertProcessTransportPaused(array $world): void
    {
        app()->forgetInstance(RequestBudget::class);
        try {
            app(SpaceTradersClient::class)->request('get', '/');
            $this->fail('Transport started despite an unpersisted response-budget pause.');
        } catch (RequestDeferred) {
            $this->assertCount(count($world['calls']), json_decode(file_get_contents($this->worldFile), true)['calls']);
        }
    }

    private function contendOnWriteResponse(int $milliseconds): void
    {
        $state = (object) ['post' => false];
        Event::listen(RequestSending::class, fn ($event) => $state->post = $event->request->method() === 'POST');
        $contend = function (callable $work) use ($milliseconds) {
            $directory = config('gameplay.request_budget_path');
            $ready = $directory.'/holder-ready';
            $code = '$h=fopen($argv[1],"c"); flock($h, LOCK_EX); file_put_contents($argv[2],"ready"); echo "ready\n"; fflush(STDOUT); usleep((int)$argv[3]*1000); flock($h, LOCK_UN); fclose($h);';
            $holder = new Process([PHP_BINARY, '-r', $code, $directory.'/budget.lock', $ready, (string) $milliseconds], timeout: 10);
            $holder->start();
            try {
                $this->assertTrue($holder->waitUntil(fn ($type, $output) => $type === Process::OUT && str_contains($output, "ready\n")), 'Competing process must signal that it holds the budget lock.');
                $this->assertFileExists($ready, 'Real competing process must hold the budget lock.');

                return $work();
            } finally {
                $holder->wait();
                @unlink($ready);
            }
        };
        app()->instance(RequestBudget::class, new class($state, $contend) extends RequestBudget
        {
            public function __construct(private \stdClass $state, private \Closure $contend) {}

            public function observe(Response $response, ?string $token = null, ?string $account = null): void
            {
                if ($this->state->post) {
                    ($this->contend)(fn () => parent::observe($response, $token, $account));
                } else {
                    parent::observe($response, $token, $account);
                }
            }
        });
    }
}
