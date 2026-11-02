<?php

namespace Tests\Feature;

use App\Gameplay\RequestBudget;
use App\Models\Agent;
use App\Models\User;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RateLimitStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        config(['gameplay.request_budget_enabled' => true]);
    }

    public function test_status_requires_login_and_an_owned_agent(): void
    {
        $this->getJson(route('game.rate-limit'))->assertUnauthorized();
        $user = User::factory()->create();
        $foreign = $this->agent(User::factory()->create());
        $this->actingAs($user)->getJson(route('game.rate-limit', ['agent' => $foreign->id]))->assertNotFound();
        $this->getJson(route('game.rate-limit', ['agent' => 99999]))->assertNotFound();
        $this->getJson(route('game.rate-limit', ['agent' => 'invalid']))->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_status_reports_shared_host_and_account_without_transport_or_budget_mutation(): void
    {
        $agent = $this->agent(User::factory()->create());
        $budget = app(RequestBudget::class);
        $budget->reserve('synthetic-secret', 'synthetic-account', 'write');
        $budget->observe(new \Illuminate\Http\Client\Response(new Response(200, [
            'X-RateLimit-Type' => 'IP Address', 'X-RateLimit-Limit-Per-Second' => '2',
            'X-RateLimit-Limit-Burst' => '30', 'X-RateLimit-Remaining' => '1', 'Authorization' => 'never-expose',
        ])), 'synthetic-secret', 'synthetic-account');
        $file = config('gameplay.request_budget_path').'/budget.json';
        $before = file_get_contents($file);

        $response = $this->actingAs($agent->user)->getJson(route('game.rate-limit', ['agent' => $agent->id]))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('mode', 'live')->assertJsonPath('pacing_enabled', true)
            ->assertJsonPath('buckets.host.requests', 1)->assertJsonPath('buckets.account.requests', 1)
            ->assertJsonPath('buckets.host.requests_last_60_seconds', 1)
            ->assertJsonPath('buckets.host.requests_per_second_last_5', 0.2)
            ->assertJsonPath('buckets.host.last_response.scope', 'IP address')
            ->assertJsonPath('buckets.host.last_response.per_second', 2)
            ->assertJsonPath('buckets.host.last_response.burst', 30)
            ->assertJsonPath('buckets.host.last_response.remaining', 1);
        $this->assertGreaterThanOrEqual(9, $response->json('buckets.host.available'));
        $this->assertStringNotContainsString('synthetic-secret', $response->getContent());
        $this->assertStringNotContainsString('synthetic-account', $response->getContent());
        $this->assertStringNotContainsString('never-expose', $response->getContent());
        $this->assertSame($before, file_get_contents($file));
        $this->getJson(route('game.rate-limit'))->assertOk()->assertJsonMissingPath('buckets.account');
        Http::assertNothingSent();
    }

    public function test_account_cooldown_is_reported_without_leaking_to_another_account(): void
    {
        $agent = $this->agent(User::factory()->create());
        $other = $this->agent($agent->user, 'other-account');
        app(RequestBudget::class)->observe(new \Illuminate\Http\Client\Response(new Response(429, [
            'X-RateLimit-Type' => 'Account', 'Retry-After' => '30',
        ])), 'synthetic-secret', 'synthetic-account');

        $response = $this->actingAs($agent->user)->getJson(route('game.rate-limit', ['agent' => $agent->id]))->assertOk();
        $this->assertGreaterThan($response->json('as_of') + 25, $response->json('buckets.account.blocked_until'));
        $response->assertJsonPath('buckets.account.rate_limits', 1)->assertJsonPath('buckets.host.blocked_until', 0);
        $this->getJson(route('game.rate-limit', ['agent' => $other->id]))->assertOk()
            ->assertJsonPath('buckets.account.blocked_until', 0)->assertJsonPath('buckets.account.rate_limits', 0);
        Http::assertNothingSent();
    }

    public function test_missing_state_is_unknown_and_corrupt_state_is_unavailable(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson(route('game.rate-limit'))->assertOk()
            ->assertJsonPath('buckets.host.responses', 0)->assertJsonPath('buckets.host.last_response', null);
        $this->assertDirectoryDoesNotExist(config('gameplay.request_budget_path'));
        mkdir(config('gameplay.request_budget_path'), 0700, true);
        file_put_contents(config('gameplay.request_budget_path').'/budget.json', '{corrupt');
        $this->getJson(route('game.rate-limit'))->assertServiceUnavailable()
            ->assertJsonPath('message', 'Rate-limit status is unavailable. Try again shortly.');
        Http::assertNothingSent();
    }

    public function test_server_report_parses_numeric_headers_and_discards_untrusted_text(): void
    {
        $budget = app(RequestBudget::class);
        $budget->observe(new \Illuminate\Http\Client\Response(new Response(200, [
            'X-RateLimit-Type' => '<script>untrusted</script>', 'X-RateLimit-Limit-Per-Second' => '1e999',
            'X-RateLimit-Limit-Burst' => '-1', 'X-RateLimit-Remaining' => 'not-a-number',
        ])));
        $this->actingAs(User::factory()->create())->getJson(route('game.rate-limit'))->assertOk()
            ->assertJsonPath('buckets.host.last_response.scope', 'Unspecified')
            ->assertJsonPath('buckets.host.last_response.per_second', null)
            ->assertJsonPath('buckets.host.last_response.burst', null)
            ->assertJsonPath('buckets.host.last_response.remaining', null);
    }

    private function agent(User $user, string $account = 'synthetic-account'): Agent
    {
        return $user->agents()->create([
            'name' => 'RATE', 'account_id' => $account, 'faction' => 'COSMIC', 'token' => 'synthetic-secret',
        ]);
    }
}
