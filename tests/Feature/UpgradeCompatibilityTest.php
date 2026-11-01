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
            ['POST', '/agents/1/investigations'],
            ['POST', '/agents/1/investigations/1/advance'],
            ['POST', '/agents/1/investigations/1/cancel'],
            ['POST', '/agents/1/drafts'],
            ['POST', '/agents/1/drafts/1/update'],
            ['POST', '/agents/1/drafts/1/preview'],
            ['POST', '/agents/1/engine/start'],
            ['POST', '/agents/1/engine/end'],
            ['POST', '/agents/1/engine/force-stop'],
            ['POST', '/agents/1/accounting/review'],
            ['POST', '/agents/1/sync'],
            ['POST', '/agents/1/commands/preview'],
            ['POST', '/agents/1/commands/1/approve'],
            ['POST', '/agents/1/commands/1/reconcile'],
            ['POST', '/agents/1/commands/1/acknowledge'],
            ['POST', '/agents/1/runs/preview'],
            ['POST', '/agents/1/runs/1/start'],
            ['POST', '/agents/1/runs/1/stop'],
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

    #[DataProvider('upstreamWrites')]
    public function test_head_cannot_trigger_an_upstream_write_without_csrf(string $method): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->actingAs(User::factory()->create())
            ->withHeader('Sec-Fetch-Site', 'cross-site')
            ->head(route('forward', ['method' => $method, 'url' => '/register']))
            ->assertSessionHasErrors('method');

        Http::assertNothingSent();
    }

    public static function upstreamWrites(): array
    {
        return [['post'], ['put'], ['patch'], ['delete']];
    }

    public function test_head_can_forward_a_read_without_a_csrf_token(): void
    {
        Http::fake(['api.spacetraders.io/v2/factions' => Http::response(['data' => []])]);

        $this->actingAs(User::factory()->create())
            ->withHeader('Sec-Fetch-Site', 'cross-site')
            ->head(route('forward', ['method' => 'get', 'url' => '/factions']))
            ->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
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
