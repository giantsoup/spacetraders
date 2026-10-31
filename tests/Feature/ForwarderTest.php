<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ForwarderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_get_forwards_query_parameters_and_preserves_pagination(): void
    {
        $body = ['data' => [], 'meta' => ['total' => 19, 'page' => 2, 'limit' => 10]];
        Http::fake(['api.spacetraders.io/v2/factions*' => Http::response($body)]);

        $this->actingAs(User::factory()->create())->get(route('forward', [
            'method' => 'get', 'url' => '/factions', 'data' => ['page' => 2, 'limit' => 10],
        ]))->assertOk()->assertExactJson($body);

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://api.spacetraders.io/v2/factions?page=2&limit=10'
            && $request->hasHeader('Accept', 'application/json'));
    }

    public function test_post_forwards_json_and_bearer_token_and_preserves_created_status(): void
    {
        Log::spy();
        $body = ['data' => ['token' => 'returned-agent-secret']];
        Http::fake(['api.spacetraders.io/v2/register' => Http::response($body, 201)]);

        $this->actingAs(User::factory()->create())->postJson(route('forward'), [
            'method' => 'post', 'url' => '/register',
            'data' => ['symbol' => 'TEST_AGENT', 'faction' => 'COSMIC'],
            'headers' => ['Authorization' => 'Bearer account-secret'],
        ])->assertCreated()->assertExactJson($body)->assertHeader('Cache-Control', 'no-store, private');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->data() === ['symbol' => 'TEST_AGENT', 'faction' => 'COSMIC']
            && $request->hasHeader('Authorization', 'Bearer account-secret')
            && $request->hasHeader('Content-Type', 'application/json'));
        Log::shouldNotHaveReceived('info');
    }

    public function test_rate_limit_status_body_and_headers_are_preserved_without_retry(): void
    {
        $body = ['error' => ['code' => 429, 'message' => 'Rate limit reached.', 'data' => ['retryAfter' => 2]]];
        Http::fake(['*' => Http::response($body, 429, [
            'Retry-After' => '2', 'X-RateLimit-Remaining' => '0', 'Content-Type' => 'application/json',
            'X-RateLimit-Type' => 'IP Address', 'X-RateLimit-Limit-Burst' => '30', 'X-RateLimit-Limit-Per-Second' => '2',
        ])]);

        $this->actingAs(User::factory()->create())->postJson(route('forward'), [
            'method' => 'get', 'url' => '/my/agent',
        ])->assertStatus(429)->assertExactJson($body)
            ->assertHeader('Retry-After', '2')->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('X-RateLimit-Type', 'IP Address')->assertHeader('X-RateLimit-Limit-Burst', '30')
            ->assertHeader('X-RateLimit-Limit-Per-Second', '2');
        Http::assertSentCount(1);
    }

    public function test_no_content_status_is_preserved(): void
    {
        Http::fake(['*' => Http::response('', 204)]);

        $this->actingAs(User::factory()->create())->postJson(route('forward'), [
            'method' => 'delete', 'url' => '/my/ships/TEST_AGENT-1',
        ])->assertNoContent();
    }

    public function test_bodyless_actions_do_not_send_an_empty_json_array(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->actingAs(User::factory()->create())->postJson(route('forward'), [
            'method' => 'post', 'url' => '/my/ships/TEST_AGENT-1/orbit', 'data' => [],
        ])->assertOk();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->body() === '');
    }

    public function test_get_cannot_trigger_an_upstream_write(): void
    {
        $this->actingAs(User::factory()->create())->getJson(route('forward', [
            'method' => 'post', 'url' => '/register',
        ]))->assertStatus(405);
        Http::assertNothingSent();
    }

    #[DataProvider('unsafeRequests')]
    public function test_invalid_forwarding_requests_are_rejected(array $payload, string $field): void
    {
        $this->actingAs(User::factory()->create())->postJson(route('forward'), array_merge([
            'method' => 'get', 'url' => '/my/agent',
        ], $payload))->assertUnprocessable()->assertJsonValidationErrors($field);
        Http::assertNothingSent();
    }

    public static function unsafeRequests(): array
    {
        return [
            'unsupported method' => [['method' => 'withOptions'], 'method'],
            'absolute URL' => [['url' => 'https://example.com'], 'url'],
            'network URL' => [['url' => '//example.com'], 'url'],
            'traversal' => [['url' => '/../my/account'], 'url'],
            'encoded traversal' => [['url' => '/%2e%2e/my/account'], 'url'],
            'query in URL' => [['url' => '/my/agent?token=secret'], 'url'],
            'arbitrary headers' => [['headers' => ['Host' => 'example.com']], 'headers'],
            'invalid bearer' => [['headers' => ['Authorization' => "Bearer token\r\nHost: example.com"]], 'headers.Authorization'],
            'NUL in bearer' => [['headers' => ['Authorization' => "Bearer token\0value"]], 'headers.Authorization'],
            'non-array data' => [['data' => 'invalid'], 'data'],
        ];
    }

    public function test_connection_failure_returns_a_service_unavailable_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('Timed out'));

        $this->actingAs(User::factory()->create())->postJson(route('forward'), [
            'method' => 'get', 'url' => '/my/agent',
        ])->assertStatus(503)->assertJsonPath('error.message', 'Could not connect to SpaceTraders. Please try again later.');
    }

    public function test_forwarder_requires_authentication(): void
    {
        $this->postJson(route('forward'), ['method' => 'get', 'url' => '/'])->assertUnauthorized();
        Http::assertNothingSent();
    }
}
