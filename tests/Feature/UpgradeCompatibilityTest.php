<?php

namespace Tests\Feature;

use App\Http\Middleware\PreventRequestForgery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UpgradeCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    #[DataProvider('protectedWrites')]
    public function test_writes_without_an_origin_or_token_are_rejected(string $method, string $uri): void
    {
        $this->actingAs(User::factory()->create())
            ->call($method, $uri)
            ->assertStatus(419);

        Http::assertNothingSent();
    }

    public static function protectedWrites(): array
    {
        return [
            ['POST', '/login'],
            ['POST', '/register'],
            ['POST', '/forgot-password'],
            ['POST', '/reset-password'],
            ['POST', '/confirm-password'],
            ['PUT', '/password'],
            ['POST', '/logout'],
            ['POST', '/email/verification-notification'],
            ['POST', '/agents'],
            ['POST', '/agents/storeExisting'],
            ['POST', '/forward'],
            ['PATCH', '/profile'],
            ['DELETE', '/profile'],
        ];
    }

    public function test_same_origin_requests_reach_the_real_handler(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Sec-Fetch-Site', 'same-origin')
            ->patch('/profile', ['name' => 'Updated pilot', 'email' => $user->email])
            ->assertRedirect('/profile');

        $this->assertSame('Updated pilot', $user->fresh()->name);
    }

    public function test_cross_site_request_requires_a_matching_session_token(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['_token' => 'owned-session-token']);

        $this->withHeader('Sec-Fetch-Site', 'cross-site')
            ->patch('/profile', ['name' => 'Rejected', 'email' => $user->email])
            ->assertStatus(419);
        $this->assertSame($user->name, $user->fresh()->name);

        $this->withHeader('X-CSRF-TOKEN', 'owned-session-token')
            ->patch('/profile', ['name' => 'Accepted', 'email' => $user->email])
            ->assertRedirect('/profile');
        $this->assertSame('Accepted', $user->fresh()->name);
    }

    public function test_sanctum_bearer_authentication_preserves_safe_user_serialization(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('upgrade-test')->plainTextToken;

        $this->withToken($token)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('remember_token');
    }

    public function test_sanctum_rejects_an_expired_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('expired', ['*'], now()->subMinute())->plainTextToken;

        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_sanctum_requires_authentication(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }
}
