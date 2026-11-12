<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SpaceTradersClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SavedAccountTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['https://api.spacetraders.io/v2/' => Http::response(['version' => 'v2.3.0', 'resetDate' => '2026-10-25'])]);
    }

    public function test_successful_opt_in_stores_the_token_encrypted_with_a_timestamp(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create();
        $this->fakeRegistration();

        $this->actingAs($user)->post(route('agents.store'), $this->registration(['remember_account_token' => true]))
            ->assertSessionHasNoErrors()->assertRedirect(route('agents.index'));

        $user->refresh();
        $raw = DB::table('users')->where('id', $user->id)->value('spacetraders_account_token');
        $this->assertNotSame('typed-account-secret', $raw);
        $this->assertSame('typed-account-secret', Crypt::decryptString($raw));
        $this->assertSame('typed-account-secret', $user->savedAccountToken());
        $this->assertTrue($user->hasSavedAccountToken());
        $this->assertInstanceOf(CarbonImmutable::class, $user->spacetraders_account_token_saved_at);
        $this->assertTrue($user->spacetraders_account_token_saved_at->equalTo(now()));
        $this->assertDatabaseHas('agents', ['user_id' => $user->id, 'name' => 'TEST_AGENT']);
    }

    #[DataProvider('withoutOptIn')]
    public function test_successful_registration_does_not_save_without_opt_in(array $options): void
    {
        $user = User::factory()->create();
        $this->fakeRegistration();

        $this->actingAs($user)->post(route('agents.store'), $this->registration($options))->assertSessionHasNoErrors();

        $this->assertNoSavedToken($user);
        $this->assertDatabaseCount('agents', 1);
    }

    public static function withoutOptIn(): array
    {
        return ['omitted' => [[]], 'false' => [['remember_account_token' => false]]];
    }

    #[DataProvider('registrationFailures')]
    public function test_failed_or_ambiguous_registration_never_remembers_a_token(string $failure): void
    {
        $user = User::factory()->create();
        Http::fake(['api.spacetraders.io/v2/register' => $failure === 'timeout'
            ? fn () => throw new ConnectionException('Connection timed out')
            : Http::response($failure === 'incomplete' ? ['data' => []] : ['error' => ['message' => 'Registration failed.']], $failure === 'incomplete' ? 201 : (int) $failure)]);

        $this->actingAs($user)->post(route('agents.store'), $this->registration(['remember_account_token' => true]))
            ->assertSessionHasErrors('api');

        $this->assertNoSavedToken($user);
        $this->assertDatabaseCount('agents', 0);
        $this->assertArrayNotHasKey('account_token', session()->getOldInput());
    }

    public static function registrationFailures(): array
    {
        return [['409'], ['422'], ['500'], ['timeout'], ['incomplete']];
    }

    public function test_registration_does_not_remember_a_token_if_the_agent_cannot_be_saved_locally(): void
    {
        User::factory()->create()->agents()->create([
            'name' => 'TEST_AGENT', 'token' => 'other-agent-secret', 'faction' => 'COSMIC',
        ]);
        $user = User::factory()->create();
        $this->fakeRegistration();

        $this->actingAs($user)->post(route('agents.store'), $this->registration(['remember_account_token' => true]))
            ->assertSessionHasErrors('api');

        $this->assertNoSavedToken($user);
        $this->assertSame(0, $user->agents()->count());
    }

    public function test_empty_account_token_uses_the_saved_token_without_changing_its_saved_date(): void
    {
        $user = User::factory()->create();
        $user->rememberAccountToken('saved-account-secret');
        $savedAt = $user->spacetraders_account_token_saved_at;
        $this->travel(1)->hours();
        $this->fakeRegistration();

        $this->actingAs($user)->post(route('agents.store'), $this->registration(['account_token' => '', 'remember_account_token' => true]))
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/register')
            && $request->hasHeader('Authorization', 'Bearer saved-account-secret'));
        $this->assertSame('saved-account-secret', $user->fresh()->savedAccountToken());
        $this->assertTrue($user->fresh()->spacetraders_account_token_saved_at->equalTo($savedAt));
    }

    public function test_missing_account_token_without_a_saved_token_fails_before_any_http_request(): void
    {
        $this->actingAs(User::factory()->create())->post(route('agents.store'), $this->registration(['account_token' => '']))
            ->assertSessionHasErrors(['account_token' => 'Enter your account token.']);

        Http::assertNothingSent();
    }

    #[DataProvider('rememberOptions')]
    public function test_typed_token_overrides_saved_token_and_replaces_it_only_with_opt_in(bool $remember): void
    {
        $user = User::factory()->create();
        $user->rememberAccountToken('saved-account-secret');
        $this->fakeRegistration();

        $this->actingAs($user)->post(route('agents.store'), $this->registration(['remember_account_token' => $remember]))
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/register')
            && $request->hasHeader('Authorization', 'Bearer typed-account-secret'));
        $this->assertSame($remember ? 'typed-account-secret' : 'saved-account-secret', $user->fresh()->savedAccountToken());
    }

    public static function rememberOptions(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('rejectedTokens')]
    public function test_only_rejected_saved_tokens_are_forgotten(int $status, bool $typed): void
    {
        $user = User::factory()->create();
        $user->rememberAccountToken('saved-account-secret');
        Http::fake(['api.spacetraders.io/v2/register' => Http::response([], $status)]);

        $response = $this->actingAs($user)->postJson(route('agents.store'), $this->registration([
            'account_token' => $typed ? 'typed-account-secret' : '', 'remember_account_token' => true,
        ]))->assertUnprocessable();

        $response->assertJsonPath('errors.api.0', $typed
            ? ($status === 401 ? SpaceTradersClient::TOKEN_INVALID : SpaceTradersClient::TOKEN_FORBIDDEN)
            : 'Your saved account token was rejected and has been forgotten. Paste a current account token from the SpaceTraders dashboard.');
        if ($typed) {
            $this->assertSame('saved-account-secret', $user->fresh()->savedAccountToken());
        } else {
            $this->assertNoSavedToken($user);
        }
        $response->assertDontSee('saved-account-secret')->assertDontSee('typed-account-secret');
    }

    public static function rejectedTokens(): array
    {
        return [[401, false], [403, false], [401, true], [403, true]];
    }

    public function test_an_ambiguous_registration_keeps_the_existing_saved_token(): void
    {
        $user = User::factory()->create();
        $user->rememberAccountToken('saved-account-secret');
        Http::fake(['api.spacetraders.io/v2/register' => Http::response([], 500)]);

        $this->actingAs($user)->post(route('agents.store'), $this->registration(['account_token' => '']))
            ->assertSessionHasErrors(['api' => SpaceTradersClient::REGISTRATION_UNKNOWN]);

        $this->assertSame('saved-account-secret', $user->fresh()->savedAccountToken());
    }

    public function test_forgetting_a_token_is_owner_scoped_and_keeps_only_a_valid_register_again_context(): void
    {
        $user = User::factory()->create();
        $user->rememberAccountToken('saved-account-secret');
        $other = User::factory()->create();
        $other->rememberAccountToken('other-account-secret');

        $this->actingAs($user)->from(route('agents.create', ['from' => 42, 'guided' => 1]))
            ->delete(route('agents.accountToken.forget'), ['user_id' => $other->id])->assertRedirect(route('agents.create'));
        $this->actingAs($user)->delete(route('agents.accountToken.forget', ['from' => 42]))->assertRedirect(route('agents.create', ['from' => 42]));
        $this->actingAs($user)->delete(route('agents.accountToken.forget', ['from' => 'https://example.com']))->assertRedirect(route('agents.create'));

        $this->assertNoSavedToken($user);
        $this->assertSame('other-account-secret', $other->fresh()->savedAccountToken());
        Http::assertNothingSent();
    }

    public function test_a_rejected_saved_token_does_not_erase_a_token_saved_during_the_request(): void
    {
        $user = User::factory()->create();
        $user->rememberAccountToken('saved-account-secret');
        Http::fake(['api.spacetraders.io/v2/register' => function () use ($user) {
            // Another tab replaces the saved token while this registration is in flight.
            User::find($user->id)->rememberAccountToken('replacement-account-secret');

            return Http::response([], 401);
        }]);

        $this->actingAs($user)->postJson(route('agents.store'), $this->registration(['account_token' => '']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.api.0', 'Your saved account token was rejected and has been forgotten. Paste a current account token from the SpaceTraders dashboard.');

        $this->assertSame('replacement-account-secret', $user->fresh()->savedAccountToken());
    }

    public function test_forgetting_a_token_requires_authentication(): void
    {
        $user = User::factory()->create();
        $user->rememberAccountToken('saved-account-secret');

        $this->delete(route('agents.accountToken.forget'))->assertRedirect(route('login'));

        $this->assertSame('saved-account-secret', $user->fresh()->savedAccountToken());
    }

    #[DataProvider('pageTokenStates')]
    public function test_agent_pages_never_serialize_saved_token_attributes(string $route, bool $saved): void
    {
        $user = User::factory()->create();
        if ($saved) {
            $user->rememberAccountToken('saved-account-secret');
        }

        $initial = $this->actingAs($user)->get(route($route))->assertOk()->assertDontSee('saved-account-secret');
        $initial->assertInertia(function (Assert $page) use ($route, $saved, $user) {
            $page->missing('auth.user.spacetraders_account_token')->missing('auth.user.spacetraders_account_token_saved_at');
            if ($route === 'agents.create') {
                $page->where('savedAccountToken.saved', $saved)
                    ->where('savedAccountToken.savedAt', $saved ? $user->spacetraders_account_token_saved_at->toIso8601String() : null);
            }
        });
        $this->get(route($route), ['X-Inertia' => 'true', 'X-Inertia-Version' => $initial->inertiaPage()['version'] ?? ''])
            ->assertOk()->assertDontSee('saved-account-secret')->assertDontSee('spacetraders_account_token')
            ->assertJsonMissingPath('props.auth.user.spacetraders_account_token')
            ->assertJsonMissingPath('props.auth.user.spacetraders_account_token_saved_at');
        $this->assertArrayNotHasKey('spacetraders_account_token', $user->toArray());
        $this->assertArrayNotHasKey('spacetraders_account_token_saved_at', $user->toArray());
    }

    public static function pageTokenStates(): array
    {
        return [['agents.create', false], ['agents.create', true], ['agents.index', false], ['agents.index', true]];
    }

    public function test_credentials_are_excluded_from_flashed_validation_input(): void
    {
        $this->actingAs(User::factory()->create())->from(route('agents.create'))
            ->post(route('agents.store'), $this->registration(['symbol' => 'A', 'token' => 'import-agent-secret']))
            ->assertSessionHasErrors('symbol');

        $oldInput = session()->getOldInput();
        $this->assertSame('A', $oldInput['symbol']);
        $this->assertArrayNotHasKey('account_token', $oldInput);
        $this->assertArrayNotHasKey('token', $oldInput);
        $this->get(route('agents.create'))->assertDontSee('typed-account-secret')->assertDontSee('import-agent-secret');
        Http::assertNothingSent();
    }

    public function test_owned_archived_agent_prefills_registration(): void
    {
        $user = User::factory()->create();
        $agent = $user->agents()->create([
            'name' => 'OLD_AGENT', 'token' => 'archived-agent-secret', 'faction' => 'COSMIC',
            'email' => 'pilot@example.com', 'connection_state' => 'reset',
        ]);

        $this->actingAs($user)->get(route('agents.create', ['from' => $agent->id]))
            ->assertInertia(fn (Assert $page) => $page->where('prefill', [
                'symbol' => 'OLD_AGENT', 'faction' => 'COSMIC', 'email' => 'pilot@example.com', 'fromName' => 'OLD_AGENT',
            ]))->assertDontSee('archived-agent-secret');
        Http::assertNothingSent();
    }

    public function test_prefill_drops_an_unknown_faction_and_preserves_null_email(): void
    {
        $user = User::factory()->create();
        $agent = $user->agents()->create([
            'name' => 'OLD_AGENT', 'token' => 'archived-agent-secret', 'faction' => 'UNKNOWN', 'connection_state' => 'reset',
        ]);

        $this->actingAs($user)->get(route('agents.create', ['from' => $agent->id]))
            ->assertInertia(fn (Assert $page) => $page->where('prefill.faction', null)->where('prefill.email', null));
    }

    #[DataProvider('ignoredPrefills')]
    public function test_invalid_or_unavailable_prefill_is_silently_ignored(string $kind): void
    {
        $user = User::factory()->create();
        $owner = $kind === 'other owner' ? User::factory()->create() : $user;
        $agent = $owner->agents()->create([
            'name' => 'OLD_AGENT', 'token' => 'archived-agent-secret', 'faction' => 'COSMIC',
            'connection_state' => $kind === 'current' ? 'connected' : 'reset',
        ]);
        $query = match ($kind) {
            'missing' => [],
            'nonexistent' => ['from' => $agent->id + 999],
            'non-numeric' => ['from' => 'not-an-id'],
            'overflow' => ['from' => str_repeat('9', 100)],
            'array' => ['from' => [$agent->id]],
            default => ['from' => $agent->id],
        };

        $this->actingAs($user)->get(route('agents.create', $query))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('prefill', null));
        Http::assertNothingSent();
    }

    public static function ignoredPrefills(): array
    {
        return [['missing'], ['current'], ['other owner'], ['nonexistent'], ['non-numeric'], ['overflow'], ['array']];
    }

    public function test_undecryptable_token_is_cleared_and_reported_as_not_saved(): void
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update([
            'spacetraders_account_token' => 'unreadable-ciphertext', 'spacetraders_account_token_saved_at' => now(),
        ]);

        $this->actingAs($user->fresh())->get(route('agents.create'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('savedAccountToken', ['saved' => false, 'savedAt' => null]));

        $this->assertNoSavedToken($user);
        Http::assertNothingSent();
    }

    public function test_undecryptable_token_cannot_be_used_for_registration(): void
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['spacetraders_account_token' => 'unreadable-ciphertext']);

        $this->actingAs($user->fresh())->post(route('agents.store'), $this->registration(['account_token' => '']))
            ->assertSessionHasErrors(['account_token' => 'Enter your account token.']);

        $this->assertNoSavedToken($user);
        Http::assertNothingSent();
    }

    private function registration(array $overrides = []): array
    {
        return array_replace([
            'account_token' => 'typed-account-secret', 'symbol' => 'TEST_AGENT', 'faction' => 'COSMIC',
        ], $overrides);
    }

    private function fakeRegistration(): void
    {
        Http::fake(['api.spacetraders.io/v2/register' => Http::response([
            'data' => ['token' => 'new-agent-secret', 'agent' => [
                'accountId' => 'account-123', 'symbol' => 'TEST_AGENT', 'startingFaction' => 'COSMIC',
            ]],
        ], 201)]);
    }

    private function assertNoSavedToken(User $user): void
    {
        $this->assertDatabaseHas('users', [
            'id' => $user->id, 'spacetraders_account_token' => null, 'spacetraders_account_token_saved_at' => null,
        ]);
        $this->assertFalse($user->fresh()->hasSavedAccountToken());
    }
}
