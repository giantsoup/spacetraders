<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ForwarderTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_factions_read_preserves_pagination(): void
    {
        Http::preventStrayRequests();
        $body = ['data' => [], 'meta' => ['total' => 19, 'page' => 2, 'limit' => 10]];
        Http::fake(['*' => Http::response($body)]);
        $this->actingAs(User::factory()->create())->get(route('forward', [
            'method' => 'get', 'url' => '/factions', 'data' => ['page' => 2, 'limit' => 10],
        ]))->assertOk()->assertExactJson($body);
        Http::assertSentCount(1);
    }

    public function test_all_authenticated_gameplay_and_forwarded_mutations_are_closed(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->actingAs(User::factory()->create());
        foreach ([
            ['method' => 'post', 'url' => '/register'],
            ['method' => 'get', 'url' => '/my/agent'],
            ['method' => 'get', 'url' => '/factions', 'headers' => ['Authorization' => 'Bearer secret']],
            ['method' => 'get', 'url' => 'https://example.com'],
        ] as $payload) {
            $this->postJson(route('forward'), $payload)->assertUnprocessable();
        }
        Http::assertNothingSent();
    }

    public function test_forwarder_requires_authentication(): void
    {
        $this->postJson(route('forward'), ['method' => 'get', 'url' => '/'])->assertUnauthorized();
    }
}
