<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['https://api.spacetraders.io/v2/' => Http::response(['version' => 'v2.3.0', 'resetDate' => '2026-10-25'])]);
    }

    public function test_guided_registration_and_import_open_the_exact_saved_agent(): void
    {
        $user = User::factory()->create();
        Http::fake([
            'api.spacetraders.io/v2/register' => Http::response(['data' => ['token' => 'new-agent-token', 'agent' => $this->agent()]], 201),
            'api.spacetraders.io/v2/my/agent' => Http::response(['data' => $this->agent()]),
        ]);
        $registration = $this->actingAs($user)->post(route('agents.store', ['guided' => 1]), [
            'account_token' => 'account-token', 'symbol' => 'TEST_AGENT', 'faction' => 'COSMIC',
        ]);
        $saved = $user->agents()->firstOrFail();
        $registration->assertSessionHasNoErrors()->assertRedirect(route('agents.show', ['agent' => $saved->id, 'guided' => 1]));
        $this->post(route('agents.storeExisting', ['guided' => 1]), ['token' => 'replacement-agent-token'])
            ->assertSessionHasNoErrors()->assertRedirect(route('agents.show', ['agent' => $saved->id, 'guided' => 1]));
        $this->assertSame(1, $user->agents()->count());
        Http::assertSentCount(4);
    }

    public function test_registration_uses_an_account_token_and_saves_the_returned_agent_token(): void
    {
        $user = User::factory()->create();
        Http::fake(['api.spacetraders.io/v2/register' => Http::response([
            'data' => ['token' => 'agent-secret-token', 'agent' => $this->agent()],
        ], 201)]);

        $this->actingAs($user)->post(route('agents.store'), [
            'account_token' => 'account-secret-token',
            'symbol' => 'test_agent',
            'faction' => 'cosmic',
            'email' => 'pilot@example.com',
            'url' => '/untrusted',
            'method' => 'delete',
        ])->assertSessionHasNoErrors()->assertRedirect(route('agents.index'));

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://api.spacetraders.io/v2/register'
            && $request->hasHeader('Authorization', 'Bearer account-secret-token')
            && $request->hasHeader('Accept', 'application/json')
            && $request->data() === [
                'symbol' => 'TEST_AGENT', 'faction' => 'COSMIC', 'email' => 'pilot@example.com',
            ]);
        Http::assertSentCount(2);
        $this->assertDatabaseHas('agents', [
            'user_id' => $user->id, 'name' => 'TEST_AGENT',
            'account_id' => 'account-123', 'faction' => 'COSMIC', 'email' => 'pilot@example.com',
        ]);
        $this->assertDatabaseMissing('agents', ['token' => 'account-secret-token']);
    }

    public function test_email_is_optional_and_not_sent_as_null(): void
    {
        Http::fake(['api.spacetraders.io/v2/register' => Http::response([
            'data' => ['token' => 'agent-secret-token', 'agent' => $this->agent()],
        ], 201)]);

        $this->actingAs(User::factory()->create())->post(route('agents.store'), [
            'account_token' => 'account-secret-token', 'symbol' => 'TEST_AGENT', 'faction' => 'COSMIC',
        ])->assertSessionHasNoErrors()->assertRedirect(route('agents.index'));

        Http::assertSent(fn (Request $request) => $request->data() === [
            'symbol' => 'TEST_AGENT', 'faction' => 'COSMIC',
        ]);
        $this->assertDatabaseHas('agents', ['email' => null]);
    }

    public function test_import_uses_starting_faction_without_fetching_factions(): void
    {
        $user = User::factory()->create();
        Http::fake(['api.spacetraders.io/v2/my/agent' => Http::response(['data' => $this->agent()])]);

        $this->actingAs($user)->post(route('agents.storeExisting'), [
            'token' => 'existing-agent-secret',
        ])->assertSessionHasNoErrors()->assertRedirect(route('agents.index'));

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://api.spacetraders.io/v2/my/agent'
            && $request->hasHeader('Authorization', 'Bearer existing-agent-secret')
            && $request->data() === []);
        Http::assertSentCount(2);
        $this->assertDatabaseHas('agents', [
            'user_id' => $user->id, 'name' => 'TEST_AGENT',
            'faction' => 'COSMIC', 'account_id' => 'account-123',
        ]);
    }

    public function test_agent_pages_do_not_expose_tokens_or_other_users_agents(): void
    {
        $user = User::factory()->create();
        $user->agents()->create([
            'name' => 'TEST_AGENT', 'token' => 'agent-secret-token',
            'account_id' => 'account-123', 'faction' => 'COSMIC',
        ]);
        User::factory()->create()->agents()->create([
            'name' => 'OTHER_AGENT', 'token' => 'other-secret',
            'account_id' => 'other-account', 'faction' => 'VOID',
        ]);

        $this->actingAs($user)->get(route('agents.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Agent/Agents')->has('agents', 1)
            ->where('agents.0.name', 'TEST_AGENT')
            ->missing('agents.0.token_preview')
            ->missing('agents.0.token'));
        $this->get(route('agents.create'))->assertInertia(fn (Assert $page) => $page
            ->component('Agent/CreateAgent')->where('errors', []));
        $this->assertArrayNotHasKey('token', $user->agents()->first()->toArray());
    }

    public function test_registration_validates_fields_before_calling_the_api(): void
    {
        $this->actingAs(User::factory()->create())->from(route('agents.create'))
            ->post(route('agents.store'), [
                'symbol' => 'AB', 'faction' => [], 'email' => 'invalid',
            ])->assertSessionHasErrors(['account_token', 'symbol', 'faction', 'email']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('agents', 0);
    }

    public function test_import_requires_a_token(): void
    {
        $this->actingAs(User::factory()->create())->post(route('agents.storeExisting'), [])
            ->assertSessionHasErrors('token');
        Http::assertNothingSent();
    }

    #[DataProvider('invalidTokens')]
    public function test_invalid_bearer_tokens_are_rejected_before_building_http_headers(string $token): void
    {
        $this->actingAs(User::factory()->create())->from(route('agents.create'))
            ->post(route('agents.store'), [
                'account_token' => $token, 'symbol' => 'TEST_AGENT', 'faction' => 'COSMIC',
            ])->assertSessionHasErrors('account_token');
        $this->assertSessionHasNoSecrets();

        $this->post(route('agents.storeExisting'), ['token' => $token])->assertSessionHasErrors('token');
        $this->assertSessionHasNoSecrets();
        Http::assertNothingSent();
        $this->assertDatabaseCount('agents', 0);
    }

    public static function invalidTokens(): array
    {
        return [
            'header injection' => ["secret\r\nInjected: value"],
            'embedded whitespace' => ['secret token'],
            'NUL' => ["secret\0token"],
            'DEL' => ["secret\x7ftoken"],
        ];
    }

    #[DataProvider('upstreamFailures')]
    public function test_upstream_failures_are_form_errors_and_do_not_save_agents(string $route, mixed $body, int $status, string $message): void
    {
        Http::fake(['*' => Http::response($body, $status)]);

        $this->actingAs(User::factory()->create())->from(route('agents.create'))
            ->post(route($route), [
                'account_token' => 'account-secret-token', 'token' => 'agent-secret-token',
                'symbol' => 'TEST_AGENT', 'faction' => 'COSMIC',
            ])->assertRedirect(route('agents.create'))->assertSessionHasErrors(['api' => $message]);

        $this->assertDatabaseCount('agents', 0);
        $this->assertSessionHasNoSecrets();
    }

    public static function upstreamFailures(): array
    {
        $cases = [];
        foreach (['agents.store', 'agents.storeExisting'] as $route) {
            $cases[$route.' unauthorized'] = [$route, ['error' => ['code' => 401, 'message' => 'Token is invalid.']], 401, 'Token is invalid.'];
            $cases[$route.' validation'] = [$route, ['error' => ['code' => 422, 'message' => 'Invalid symbol.', 'data' => ['symbol' => ['Already taken.']]]], 422, 'Invalid symbol.'];
            $cases[$route.' rate limited'] = [$route, ['error' => ['code' => 429, 'message' => 'Rate limit reached.']], 429, 'Rate limit reached.'];
            $cases[$route.' non-json'] = [$route, '<html>Unavailable</html>', 503, 'SpaceTraders could not complete the request. Please try again later.'];
            $cases[$route.' malformed error'] = [$route, ['error' => ['message' => ['invalid']]], 400, 'SpaceTraders could not complete the request. Please try again later.'];
            $cases[$route.' incomplete success'] = [$route, ['data' => ['symbol' => 'TEST_AGENT']], 200, 'SpaceTraders returned incomplete agent details. No agent was saved.'];
        }

        return $cases;
    }

    public function test_connection_failure_is_a_form_error_without_retrying_registration(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $this->actingAs(User::factory()->create())->from(route('agents.create'))
            ->post(route('agents.store'), [
                'account_token' => 'account-secret-token', 'symbol' => 'TEST_AGENT', 'faction' => 'COSMIC',
            ])->assertSessionHasErrors('api');

        $this->assertDatabaseCount('agents', 0);
        $this->assertSessionHasNoSecrets();
    }

    public function test_agent_routes_require_authentication(): void
    {
        $this->get(route('agents.index'))->assertRedirect(route('login'));
        $this->get(route('agents.create'))->assertRedirect(route('login'));
        $this->post(route('agents.store'))->assertRedirect(route('login'));
        $this->post(route('agents.storeExisting'))->assertRedirect(route('login'));
        Http::assertNothingSent();
    }

    private function agent(): array
    {
        return [
            'accountId' => 'account-123', 'symbol' => 'TEST_AGENT', 'headquarters' => 'X1-TEST-A1',
            'credits' => 175000, 'startingFaction' => 'COSMIC', 'shipCount' => 2,
        ];
    }

    private function assertSessionHasNoSecrets(): void
    {
        $oldInput = session()->getOldInput();
        $this->assertArrayNotHasKey('account_token', $oldInput);
        $this->assertArrayNotHasKey('token', $oldInput);
    }
}
