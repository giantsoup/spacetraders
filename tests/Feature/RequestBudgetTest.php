<?php

namespace Tests\Feature;

use App\Gameplay\RequestBudget;
use App\Gameplay\RequestDeferred;
use App\Services\SpaceTradersClient;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
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
        $this->assertEquals(2, $usage['account']['available']);
        $this->assertEquals(1.9, $usage['host']['sustained_per_second']);
        $budget->observe($this->response(200, ['X-RateLimit-Type' => 'Account', 'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => gmdate('c', (int) $budget->time + 30)]), 'a');
        $this->assertGreaterThanOrEqual($budget->time + 30, $budget->usage('a')['account']['blocked_until']);
        $budget->reserve('b', null, 'write');
        $this->expectException(RequestDeferred::class);
        $budget->reserve('a', null, 'write');
    }

    public function test_reported_remaining_cannot_refill_past_the_server_window(): void
    {
        $budget = $this->budget();
        $budget->observe($this->response(200, ['X-RateLimit-Type' => 'Account', 'X-RateLimit-Remaining' => '3', 'X-RateLimit-Reset' => (string) ($budget->time + 30)]), 'a');
        $budget->reserve('a', null, 'write');
        $budget->reserve('a', null, 'write');
        $budget->time += 10;
        try {
            $budget->reserve('a', null, 'write');
            $this->fail('Local refill bypassed the reported server window.');
        } catch (RequestDeferred) {
            $this->assertSame(2, $budget->usage('a')['account']['requests']);
        }
        $budget->time += 21;
        $budget->reserve('a', null, 'write');
        $this->assertSame(3, $budget->usage('a')['account']['requests']);
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
