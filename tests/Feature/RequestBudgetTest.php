<?php

namespace Tests\Feature;

use App\Gameplay\GameException;
use App\Gameplay\RequestBudget;
use App\Gameplay\RequestDeferred;
use App\Services\SpaceTradersClient;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RequestBudgetTest extends TestCase
{
    private function budget(): RequestBudgetClock
    {
        config(['gameplay.request_budget_enabled' => true]);

        return new RequestBudgetClock;
    }

    public function test_account_limit_pauses_only_that_account_and_survives_a_new_instance(): void
    {
        $budget = $this->budget();
        $budget->observe($this->response(429, ['X-RateLimit-Type' => 'Account', 'Retry-After' => '30']), 'token-a', 'account-a');
        $restarted = $this->budget();
        $restarted->reserve('token-b', 'account-b', 'write');
        $this->assertSame(1, $restarted->usage('token-b', 'account-b')['account']['requests']);
        $this->assertGreaterThan($restarted->time, $restarted->usage('token-a', 'account-a')['account']['blocked_until']);
        $this->expectException(RequestDeferred::class);
        $restarted->reserve('different-token-same-account', 'account-a', 'write');
    }

    public function test_ip_and_unknown_cloud_limits_pause_all_tokens(): void
    {
        foreach (['IP Address', ''] as $type) {
            $budget = $this->budget();
            $budget->observe($this->response(429, ['X-RateLimit-Type' => $type, 'Retry-After' => '20']), 'token-a', 'account-a');
            foreach (['a', 'b'] as $name) {
                try {
                    $budget->reserve('token-'.$name, 'account-'.$name, 'write');
                    $this->fail('Shared host pause was bypassed.');
                } catch (RequestDeferred) {
                    $this->assertGreaterThan($budget->time, $budget->usage()['host']['blocked_until']);
                }
            }
        }
    }

    public function test_sustained_usage_and_burst_are_bounded_for_sixty_simulated_seconds(): void
    {
        $budget = $this->budget();
        $start = $budget->time;
        $sent = [];
        while ($budget->time < $start + 60) {
            try {
                $budget->reserve('a', null, 'write');
                $sent[] = $budget->time;
                $budget->observe($this->response(200, ['X-RateLimit-Type' => 'IP Address', 'X-RateLimit-Limit-Per-Second' => '2',
                    'X-RateLimit-Limit-Burst' => '30', 'X-RateLimit-Remaining' => '1',
                    'X-RateLimit-Reset' => gmdate('c', (int) ceil($budget->time + 1))]), 'a');
            } catch (RequestDeferred) {
                $budget->time += 0.05;
            }
        }
        $this->assertLessThanOrEqual(125, count($sent));
        $this->assertGreaterThanOrEqual(114, count($sent));
        $this->assertSame(10, count(array_filter($sent, fn ($at) => $at === $start)));
        $usage = $budget->usage('a');
        $this->assertSame(count($sent), $usage['host']['requests']);
        $this->assertSame(count($sent), $usage['account']['requests']);
        $this->assertLessThanOrEqual(2.1, $usage['host']['requests_per_second_last_60']);
    }

    public function test_headers_correct_capacity_rate_remaining_and_reset_with_a_margin(): void
    {
        $budget = $this->budget();
        $budget->observe($this->response(200, ['X-RateLimit-Type' => 'Account', 'X-RateLimit-Limit-Per-Second' => '1', 'X-RateLimit-Limit-Burst' => '5', 'X-RateLimit-Remaining' => '3']), 'a');
        $usage = $budget->usage('a');
        $this->assertEquals(0.95, $usage['account']['sustained_per_second']);
        $this->assertEquals(4, $usage['account']['burst_capacity']);
        $this->assertEquals(4, $usage['account']['available']);
        $this->assertEquals(1.9, $usage['host']['sustained_per_second']);
        $budget->observe($this->response(200, ['X-RateLimit-Type' => 'Account', 'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => gmdate('c', (int) $budget->time + 30)]), 'a');
        $this->assertGreaterThanOrEqual($budget->time + 30, $budget->usage('a')['account']['blocked_until']);
        $budget->reserve('b', null, 'write');
        $this->expectException(RequestDeferred::class);
        $budget->reserve('a', null, 'write');
    }

    public function test_successful_exhaustion_uses_the_short_server_retry_instead_of_error_backoff(): void
    {
        $budget = $this->budget();
        $budget->observe($this->response(200, ['X-RateLimit-Type' => 'IP Address', 'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => gmdate('c', (int) $budget->time + 1), 'Retry-After' => '1']), 'a');

        $this->assertEqualsWithDelta($budget->time + 1.25, $budget->usage('a')['host']['blocked_until'], 0.001);
    }

    public function test_read_sequence_waits_through_short_successful_exhaustion_without_restarting(): void
    {
        $budget = $this->budget();
        app()->instance(RequestBudget::class, $budget);
        $start = $budget->time;
        Http::preventStrayRequests();
        Http::fake(['*' => Http::sequence()
            ->push(['status' => 'online'], 200, ['X-RateLimit-Remaining' => '1'])
            ->push(['data' => ['credits' => 10000]], 200, ['X-RateLimit-Type' => 'IP Address', 'X-RateLimit-Remaining' => '0', 'Retry-After' => '1'])
            ->push(['data' => []])]);
        $client = app(SpaceTradersClient::class);
        $client->request('get', '/');
        $client->request('get', '/my/agent', token: 'a');
        $this->assertTrue($client->request('get', '/my/ships', token: 'a')->successful());

        $this->assertGreaterThanOrEqual(1.25, $budget->time - $start);
        $this->assertLessThanOrEqual(2, $budget->time - $start);
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public static function upstreamBackoffs(): array
    {
        return [[429], [502], [503]];
    }

    #[DataProvider('upstreamBackoffs')]
    public function test_reads_still_defer_actual_upstream_error_backoffs(int $status): void
    {
        $budget = $this->budget();
        app()->instance(RequestBudget::class, $budget);
        $budget->observe($this->response($status, ['Retry-After' => '1']), 'a');
        Http::preventStrayRequests();
        Http::fake();
        try {
            app(SpaceTradersClient::class)->request('get', '/my/ships', token: 'a');
            $this->fail('Read bypassed upstream backoff.');
        } catch (RequestDeferred $e) {
            $this->assertGreaterThanOrEqual($status === 502 ? 180 : 5, $e->retryAfter);
            Http::assertNothingSent();
        }
    }

    public function test_short_read_wait_cannot_exceed_its_collection_deadline(): void
    {
        $budget = $this->budget();
        app()->instance(RequestBudget::class, $budget);
        $budget->observe($this->response(200, ['X-RateLimit-Remaining' => '0', 'Retry-After' => '1']), 'a');
        Http::preventStrayRequests();
        Http::fake();
        try {
            app(SpaceTradersClient::class)->request('get', '/my/ships', token: 'a', deadline: microtime(true) + 0.5);
            $this->fail('Read waited beyond its collection deadline.');
        } catch (RequestDeferred) {
            Http::assertNothingSent();
        }
    }

    public function test_write_waits_through_short_successful_preflight_exhaustion(): void
    {
        $budget = $this->budget();
        app()->instance(RequestBudget::class, $budget);
        $budget->observe($this->response(200, ['X-RateLimit-Remaining' => '0', 'Retry-After' => '1']), 'a');
        Http::preventStrayRequests();
        Http::fake();
        $started = $budget->time;
        app(SpaceTradersClient::class)->request('post', '/my/ships/A/orbit', token: 'a');
        $this->assertGreaterThanOrEqual(1.25, $budget->time - $started);
        $this->assertLessThanOrEqual(2, $budget->time - $started);
        Http::assertSentCount(1);
    }

    #[DataProvider('upstreamBackoffs')]
    public function test_writes_still_defer_actual_upstream_error_backoffs(int $status): void
    {
        $budget = $this->budget();
        app()->instance(RequestBudget::class, $budget);
        $budget->observe($this->response($status, ['Retry-After' => '1']), 'a');
        Http::preventStrayRequests();
        Http::fake();
        try {
            app(SpaceTradersClient::class)->request('post', '/my/ships/A/orbit', token: 'a');
            $this->fail('Write bypassed upstream error backoff.');
        } catch (RequestDeferred) {
            Http::assertNothingSent();
        }
    }

    public static function requestPriorities(): array
    {
        return [['write'], ['critical'], ['discovery']];
    }

    #[DataProvider('requestPriorities')]
    public function test_captured_live_success_remaining_is_advisory_for_all_request_priorities(string $priority): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/rate-limit-success.json')), true);
        $budget = $this->budget();
        $budget->time = (float) strtotime($fixture['observed_at']);
        $budget->reserve('a', null, 'critical');
        $budget->observe($this->response($fixture['status'], $fixture['headers']), 'a');
        $budget->reserve('a', null, $priority);
        $this->assertSame(2, $budget->usage('a')['host']['requests']);
        $this->assertSame(0, $budget->usage('a')['host']['deferrals']);
        $this->assertEquals(1.9, $budget->usage('a')['host']['sustained_per_second']);
        $this->assertEquals(10, $budget->usage('a')['host']['burst_capacity']);
    }

    public function test_legacy_positive_remaining_window_is_removed_without_clearing_real_pauses_or_usage(): void
    {
        $budget = $this->budget();
        $budget->reserve(null, null, 'write');
        $file = config('gameplay.request_budget_path').'/budget.json';
        $state = json_decode(file_get_contents($file), true);
        $state['buckets']['host']['server_remaining'] = 0;
        $state['buckets']['host']['server_reset_at'] = $budget->time + 3600;
        file_put_contents($file, json_encode($state));
        $budget->reserve(null, null, 'critical');
        $this->assertSame(2, $budget->usage()['host']['requests']);
        $state = json_decode(file_get_contents($file), true);
        $this->assertArrayNotHasKey('server_remaining', $state['buckets']['host']);
        $state['buckets']['host']['blocked_until'] = $budget->time + 30;
        file_put_contents($file, json_encode($state));
        $this->expectException(RequestDeferred::class);
        $budget->reserve(null, null, 'write');
    }

    public function test_writes_and_critical_reads_have_capacity_reserved_ahead_of_discovery(): void
    {
        $budget = $this->budget();
        for ($i = 0; $i < 8; $i++) {
            $budget->reserve('a', null, 'write');
        }
        try {
            $budget->reserve('a');
            $this->fail('Discovery consumed protected capacity.');
        } catch (RequestDeferred) {
            $this->assertSame(1, $budget->usage('a')['host']['deferrals']);
        }
        $budget->reserve('a', null, 'critical');
        $budget->reserve('a', null, 'write');
        $this->assertSame(10, $budget->usage('a')['host']['requests']);
    }

    public function test_separate_process_and_database_configuration_share_the_host_budget_without_tokens_on_disk(): void
    {
        $budget = $this->budget();
        $budget->reserve('never-store-this-token', 'never-store-this-account', 'write');
        $directory = config('gameplay.request_budget_path');
        $code = 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); config(["database.default"=>"sqlite", "database.connections.sqlite.database"=>":memory:", "gameplay.request_budget_path"=>$argv[1]]); $b=new App\\Gameplay\\RequestBudget; $b->reserve("other-token", null,"write"); echo json_encode($b->usage()["host"]["requests"]);';
        $process = new Process([PHP_BINARY, '-r', $code, $directory], base_path(), ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $directory.'/no-config.php']);
        $process->mustRun();
        $this->assertSame('2', trim($process->getOutput()));
        $this->assertSame(2, $this->budget()->usage()['host']['requests']);
        $state = file_get_contents($directory.'/budget.json');
        $this->assertStringNotContainsString('never-store-this', $state);
        $this->assertSame(0600, fileperms($directory.'/budget.json') & 0777);
        file_put_contents($directory.'/budget.json', '{broken');
        $this->expectException(RequestDeferred::class);
        $budget->reserve('a');
    }

    public function test_read_reuse_is_scoped_and_invalidated_by_writes_including_rejected_writes(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::sequence()->push(['value' => 1])->push([], 400)->push(['value' => 2])->push(['value' => 3])]);
        $client = app(SpaceTradersClient::class);
        $client->scope(function () use ($client) {
            $this->assertSame(1, $client->request('get', '/my/agent', token: 'a')->json('value'));
            $this->assertSame(1, $client->request('get', '/my/agent', token: 'a')->json('value'));
            $client->request('post', '/my/ships/A/orbit', token: 'a');
            $this->assertSame(2, $client->request('get', '/my/agent', token: 'a')->json('value'));
        });
        $client->scope(fn () => $this->assertSame(3, $client->request('get', '/my/agent', token: 'a')->json('value')));
        Http::assertSentCount(4);
    }

    public function test_relative_budget_paths_fail_before_transport(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        config(['gameplay.request_budget_path' => 'per-checkout-relative-path']);
        try {
            app(SpaceTradersClient::class)->request('post', '/my/ships/A/orbit', token: 'a');
            $this->fail('An unshared relative path was accepted.');
        } catch (RequestDeferred) {
            Http::assertNothingSent();
        }
    }

    public function test_usage_is_a_side_effect_free_atomic_snapshot_even_while_the_writer_lock_is_held(): void
    {
        $budget = $this->budget();
        $directory = config('gameplay.request_budget_path');
        $budget->usage('a');
        $this->assertDirectoryDoesNotExist($directory);
        $budget->reserve('a', null, 'write');
        $file = $directory.'/budget.json';
        $bytes = file_get_contents($file);
        touch($file, time() - 60);
        clearstatcache(true, $file);
        $mtime = filemtime($file);
        $handle = fopen($directory.'/budget.lock', 'c');
        flock($handle, LOCK_EX);
        try {
            $this->assertSame(1, $budget->usage('a')['host']['requests']);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        clearstatcache(true, $file);
        $this->assertSame($mtime, filemtime($file));
        $this->assertSame($bytes, file_get_contents($file));
    }

    public function test_three_process_response_recording_has_no_losses_or_lock_failures(): void
    {
        $directory = config('gameplay.request_budget_path');
        mkdir($directory, 0700, true);
        $code = 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
            .'config(["gameplay.request_budget_path"=>$argv[1], "gameplay.request_budget_enabled"=>false]); $b=new App\\Gameplay\\RequestBudget;'
            .'$r=new Illuminate\\Http\\Client\\Response(new GuzzleHttp\\Psr7\\Response(200, [], "{}")); $failed=0;'
            .'for($i=0;$i<400;$i++){try{$b->observe($r,"token-".$argv[2],"account-".$argv[2]);}catch(Throwable){$failed++;}usleep(2000);}echo $failed;';
        $processes = [];
        foreach ([1, 2, 3] as $number) {
            $process = new Process([PHP_BINARY, '-r', $code, $directory, (string) $number], base_path(), ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $directory.'/no-config.php']);
            $process->start();
            $processes[] = $process;
        }
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame('0', trim($process->getOutput()));
        }
        $this->assertSame(1200, app(RequestBudget::class)->usage()['host']['responses']);
    }

    public static function unsavedResponseGuidance(): array
    {
        return [[429], [502], [200]];
    }

    #[DataProvider('unsavedResponseGuidance')]
    public function test_unsaved_response_guidance_holds_transport_for_the_server_interval(int $status): void
    {
        $clock = new RequestBudgetClock;
        app()->instance(RequestBudget::class, $clock);
        $file = config('gameplay.request_budget_path').'/budget.json';
        $saved = null;
        $headers = $status === 200 ? ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) ($clock->time + 90)] : ['Retry-After' => '90'];
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($file, &$saved, $status, $headers) {
            if ($request->method() === 'POST') {
                $saved = file_get_contents($file);
                file_put_contents($file, '{broken');

                return Http::response(['accepted' => true], $status, $headers);
            }

            return Http::response(['ok' => true]);
        });
        try {
            $result = app(SpaceTradersClient::class)->request('post', '/my/ships/A/orbit', token: 'a');
            $this->assertSame(200, $status, 'An unsaved error pause must preserve uncertainty.');
            $this->assertTrue($result->json('accepted'));
        } catch (GameException $e) {
            $this->assertNotSame(200, $status, 'A valid 2xx response must be retained.');
            $this->assertTrue($e->uncertain);
        }
        file_put_contents($file, $saved);
        $next = new RequestBudgetClock;
        $next->time = $clock->time + 20;
        app()->instance(RequestBudget::class, $next);
        try {
            app(SpaceTradersClient::class)->request('get', '/');
            $this->fail('The process hold ended before server guidance allowed.');
        } catch (RequestDeferred $e) {
            $this->assertGreaterThanOrEqual($status === 502 ? 160 : 70, $e->retryAfter);
            Http::assertSentCount(1);
        }
        $next->time += 200;
        $this->assertTrue(app(SpaceTradersClient::class)->request('get', '/')->successful());
        Http::assertSentCount(2);
    }

    private function response(int $status, array $headers): \Illuminate\Http\Client\Response
    {
        return new \Illuminate\Http\Client\Response(new Response($status, $headers, '{}'));
    }
}

class RequestBudgetClock extends RequestBudget
{
    public float $time;

    public function __construct()
    {
        $this->time = (float) time();
    }

    protected function now(): float
    {
        return $this->time;
    }

    protected function pause(float $seconds): void
    {
        $this->time += $seconds;
    }
}
