<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OpenAiKeyVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpenAiKeyTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-proj-test_key-0123456789abcdef';

    private const REPLACEMENT = 'sk-proj-replacement-0123456789wxyz';

    private const REJECTED = 'OpenAI rejected this key. Check it in your OpenAI project and paste it again.';

    private const UNREACHABLE = 'Could not reach OpenAI to check this key, so it was not saved. Try again.';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['https://api.spacetraders.io/v2/' => Http::response(['version' => 'v2.3.0', 'resetDate' => '2026-10-25'])]);
    }

    #[DataProvider('successfulStatuses')]
    public function test_a_valid_key_is_trimmed_verified_once_and_saved_encrypted(int $status): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create();
        Http::fake(['https://api.openai.com/v1/models' => function (Request $request, array $options) use ($status) {
            $this->assertSame(10, $options['timeout']);
            $this->assertSame(10, $options['connect_timeout']);
            $this->assertFalse($options['allow_redirects']);

            return Http::response([], $status);
        }]);

        $response = $this->actingAs($user)->put(route('profile.openai-key.update'), ['openai_api_key' => '  '.self::KEY."\n"])
            ->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'))->assertDontSee(self::KEY);

        $user->refresh();
        $raw = $user->getRawOriginal('openai_api_key');
        $this->assertNotSame(self::KEY, $raw);
        $this->assertSame(self::KEY, Crypt::decryptString($raw));
        $this->assertSame(self::KEY, $user->openAiApiKey());
        $this->assertTrue($user->hasOpenAiApiKey());
        $this->assertSame('cdef', $user->openAiApiKeyHint());
        $this->assertInstanceOf(CarbonImmutable::class, $user->openai_api_key_saved_at);
        $this->assertTrue($user->openai_api_key_saved_at->equalTo(now()));
        $response->assertSessionMissing('_old_input.openai_api_key');
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://api.openai.com/v1/models'
            && $request->hasHeader('Authorization', 'Bearer '.self::KEY));
    }

    public static function successfulStatuses(): array
    {
        return [[200], [204], [299]];
    }

    #[DataProvider('invalidKeys')]
    public function test_invalid_format_is_rejected_without_http_or_flashed_input(mixed $key): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from(route('profile.edit'))
            ->put(route('profile.openai-key.update'), ['openai_api_key' => $key])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrors(['openai_api_key' => 'Enter an OpenAI API key. It starts with sk-.'])
            ->assertSessionMissing('_old_input.openai_api_key');

        $this->assertNoSavedKey($user);
        Http::assertNothingSent();
    }

    public static function invalidKeys(): array
    {
        return [
            'missing' => [null], 'empty' => [''], 'whitespace' => ['   '], 'array' => [[self::KEY]],
            'number' => [123], 'prefix' => ['not-an-openai-key'], 'too short' => ['sk-123456789012345'],
            'too long' => ['sk-'.str_repeat('a', 298)], 'punctuation' => ['sk-1234567890123456!'],
            'embedded newline' => ["sk-1234567890123456\nother"],
        ];
    }

    #[DataProvider('failedStatuses')]
    public function test_rejected_or_unavailable_verification_does_not_save_or_leak(int $status, string $message): void
    {
        $user = User::factory()->create();
        $logs = new TestHandler;
        Log::channel()->getLogger()->pushHandler($logs);
        Http::fake(['https://api.openai.com/v1/models' => Http::response(['error' => ['message' => self::KEY]], $status)]);

        $this->actingAs($user)->from(route('profile.edit'))
            ->put(route('profile.openai-key.update'), ['openai_api_key' => self::KEY])
            ->assertRedirect(route('profile.edit'))->assertSessionHasErrors(['openai_api_key' => $message])
            ->assertSessionMissing('_old_input.openai_api_key')->assertDontSee(self::KEY);

        $this->assertStringNotContainsString(self::KEY, serialize(session()->all()));
        $this->assertStringNotContainsString(self::KEY, serialize($logs->getRecords()));
        $this->assertNoSavedKey($user);
        Http::assertSentCount(1);
        $this->get(route('profile.edit'))->assertOk()->assertDontSee(self::KEY);
    }

    public static function failedStatuses(): array
    {
        return [
            'unauthorized' => [401, self::REJECTED], 'forbidden' => [403, self::REJECTED],
            'rate limited' => [429, self::UNREACHABLE], 'server error' => [500, self::UNREACHABLE],
            'unavailable' => [503, self::UNREACHABLE], 'redirect' => [302, self::UNREACHABLE],
            'unexpected client error' => [400, self::UNREACHABLE],
        ];
    }

    public function test_connection_failure_is_sanitized_without_saving_or_flashing_the_key(): void
    {
        $user = User::factory()->create();
        $attempts = 0;
        $logs = new TestHandler;
        Log::channel()->getLogger()->pushHandler($logs);
        Http::fake(['https://api.openai.com/v1/models' => function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('Connection timed out with Bearer '.self::KEY);
        }]);

        $this->actingAs($user)->from(route('profile.edit'))
            ->put(route('profile.openai-key.update'), ['openai_api_key' => self::KEY])
            ->assertSessionHasErrors(['openai_api_key' => self::UNREACHABLE])
            ->assertSessionMissing('_old_input.openai_api_key')->assertDontSee(self::KEY);

        $this->assertSame(1, $attempts);
        $this->assertNoSavedKey($user);
        $this->assertStringNotContainsString(self::KEY, serialize(session()->all()));
        $this->assertStringNotContainsString(self::KEY, serialize($logs->getRecords()));
    }

    public function test_connection_failure_does_not_attach_the_unsafe_exception(): void
    {
        Http::fake(['https://api.openai.com/v1/models' => fn () => throw new ConnectionException(self::KEY)]);

        try {
            app(OpenAiKeyVerifier::class)->verify(self::KEY);
            $this->fail('Verification must reject a connection failure.');
        } catch (ValidationException $e) {
            $this->assertSame(self::UNREACHABLE, $e->getMessage());
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString(self::KEY, (string) $e);
        }
    }

    public function test_replacing_a_key_overwrites_it_and_updates_its_timestamp(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create();
        $user->rememberOpenAiApiKey(self::KEY);
        $savedAt = $user->openai_api_key_saved_at;
        $this->travel(1)->minute();
        Http::fake(['https://api.openai.com/v1/models' => Http::response([], 200)]);

        $this->actingAs($user)->put(route('profile.openai-key.update'), ['openai_api_key' => self::REPLACEMENT])
            ->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertSame(self::REPLACEMENT, $user->openAiApiKey());
        $this->assertTrue($user->openai_api_key_saved_at->greaterThan($savedAt));
        $this->assertSame('wxyz', $user->openAiApiKeyHint());
    }

    public function test_failed_replacement_preserves_the_saved_key_and_timestamp(): void
    {
        $user = User::factory()->create();
        $user->rememberOpenAiApiKey(self::KEY);
        $raw = $user->getRawOriginal('openai_api_key');
        $savedAt = $user->openai_api_key_saved_at;
        Http::fake(['https://api.openai.com/v1/models' => Http::response([], 401)]);

        $this->actingAs($user)->put(route('profile.openai-key.update'), ['openai_api_key' => self::REPLACEMENT])
            ->assertSessionHasErrors(['openai_api_key' => self::REJECTED]);

        $user->refresh();
        $this->assertSame($raw, $user->getRawOriginal('openai_api_key'));
        $this->assertTrue($savedAt->equalTo($user->openai_api_key_saved_at));
    }

    public function test_forgetting_a_key_clears_both_columns_and_can_be_repeated(): void
    {
        $user = User::factory()->create();
        $user->rememberOpenAiApiKey(self::KEY);

        $this->actingAs($user)->delete(route('profile.openai-key.destroy'))->assertRedirect(route('profile.edit'));
        $this->assertNoSavedKey($user);
        $this->delete(route('profile.openai-key.destroy'))->assertRedirect(route('profile.edit'));
        $this->assertNoSavedKey($user);
        Http::assertNothingSent();
    }

    #[DataProvider('keyStates')]
    public function test_profile_html_inertia_and_user_serialization_never_contain_the_key(bool $saved): void
    {
        $user = User::factory()->create();
        if ($saved) {
            $user->rememberOpenAiApiKey(self::KEY);
        }

        $initial = $this->actingAs($user)->get(route('profile.edit'))->assertOk()->assertDontSee(self::KEY);
        $initial->assertInertia(fn (Assert $page) => $page->component('Profile/Edit')
            ->where('openAiKey', [
                'saved' => $saved,
                'savedAt' => $saved ? $user->openai_api_key_saved_at->toIso8601String() : null,
                'hint' => $saved ? 'cdef' : null,
            ])->missing('auth.user.openai_api_key')->missing('auth.user.openai_api_key_saved_at'));
        $this->get(route('profile.edit'), ['X-Inertia' => 'true', 'X-Inertia-Version' => $initial->inertiaPage()['version'] ?? ''])
            ->assertOk()->assertDontSee(self::KEY)->assertJsonMissingPath('props.auth.user.openai_api_key')
            ->assertJsonMissingPath('props.auth.user.openai_api_key_saved_at');
        $this->assertArrayNotHasKey('openai_api_key', $user->toArray());
        $this->assertArrayNotHasKey('openai_api_key_saved_at', $user->toArray());
        $this->assertStringNotContainsString('openai_api_key', $user->toJson());
        $this->assertStringNotContainsString(self::KEY, $user->toJson());
        Http::assertNothingSent();
    }

    public static function keyStates(): array
    {
        return [[false], [true]];
    }

    public function test_corrupt_ciphertext_is_cleared_by_the_model(): void
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update([
            'openai_api_key' => 'corrupt-ciphertext', 'openai_api_key_saved_at' => now(),
        ]);

        $this->assertNull($user->fresh()->openAiApiKey());
        $this->assertNoSavedKey($user);
        Http::assertNothingSent();
    }

    public function test_corrupt_ciphertext_is_shown_as_not_saved_on_the_profile(): void
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update([
            'openai_api_key' => 'corrupt-ciphertext', 'openai_api_key_saved_at' => now(),
        ]);

        $this->actingAs($user->fresh())->get(route('profile.edit'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('openAiKey', ['saved' => false, 'savedAt' => null, 'hint' => null]));

        $this->assertNoSavedKey($user);
        Http::assertNothingSent();
    }

    public function test_both_routes_require_authentication(): void
    {
        $user = User::factory()->create();
        $user->rememberOpenAiApiKey(self::KEY);

        $this->put(route('profile.openai-key.update'), ['openai_api_key' => self::REPLACEMENT])->assertRedirect(route('login'));
        $this->delete(route('profile.openai-key.destroy'))->assertRedirect(route('login'));

        $this->assertSame(self::KEY, $user->fresh()->openAiApiKey());
        Http::assertNothingSent();
    }

    public function test_update_and_forget_only_affect_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $other->rememberOpenAiApiKey(self::REPLACEMENT);
        $raw = $other->getRawOriginal('openai_api_key');
        $savedAt = $other->openai_api_key_saved_at;
        Http::fake(['https://api.openai.com/v1/models' => Http::response([], 200)]);

        $this->actingAs($user)->put(route('profile.openai-key.update'), ['openai_api_key' => self::KEY, 'user_id' => $other->id])
            ->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));
        $this->assertSame(self::KEY, $user->fresh()->openAiApiKey());
        $this->assertSame($raw, $other->fresh()->getRawOriginal('openai_api_key'));

        $this->delete(route('profile.openai-key.destroy'), ['user_id' => $other->id])->assertRedirect(route('profile.edit'));
        $this->assertNoSavedKey($user);
        $other->refresh();
        $this->assertSame($raw, $other->getRawOriginal('openai_api_key'));
        $this->assertTrue($savedAt->equalTo($other->openai_api_key_saved_at));
        Http::assertSentCount(1);
    }

    private function assertNoSavedKey(User $user): void
    {
        $this->assertDatabaseHas('users', [
            'id' => $user->id, 'openai_api_key' => null, 'openai_api_key_saved_at' => null,
        ]);
        $this->assertFalse($user->fresh()->hasOpenAiApiKey());
        $this->assertNull($user->fresh()->openAiApiKeyHint());
    }
}
