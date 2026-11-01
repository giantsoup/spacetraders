<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlayerGuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_guide_requires_a_client_login(): void
    {
        $this->get(route('guide'))->assertRedirect(route('login'));
        $this->get(route('agents.guide', 1))->assertRedirect(route('login'));
    }

    public function test_new_player_can_read_the_guide_without_an_agent_or_upstream_requests(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->actingAs(User::factory()->create())->get(route('guide'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Guide/Show')->where('agent', null)->has('agents', 0));

        Http::assertNothingSent();
    }

    public function test_guide_lists_only_owned_agents_and_exposes_only_checklist_identity(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $user = User::factory()->create();
        $owned = $user->agents()->create([
            'name' => 'PILOT', 'token' => 'private-agent-token', 'account_id' => 'private-account',
            'reset_id' => '2026-10-25', 'email' => 'private@example.test', 'faction' => 'COSMIC',
        ]);
        User::factory()->create()->agents()->create(['name' => 'FOREIGN', 'token' => 'foreign-secret', 'account_id' => 'foreign-account', 'faction' => 'COSMIC']);

        $response = $this->actingAs($user)->get(route('agents.guide', $owned));
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('Guide/Show')
            ->where('agent', ['id' => $owned->id, 'name' => 'PILOT', 'reset_id' => '2026-10-25'])
            ->has('agents', 1)->where('agents.0.id', $owned->id)
            ->missing('agents.0.token')->missing('agents.0.account_id')->missing('agents.0.email'));
        $response->assertDontSee('private-agent-token')->assertDontSee('private-account')->assertDontSee('foreign-secret');
        $this->assertDatabaseCount('ship_actions', 0);
        Http::assertNothingSent();
    }

    public function test_foreign_and_missing_agent_guides_are_not_accessible(): void
    {
        $foreign = User::factory()->create()->agents()->create(['name' => 'FOREIGN', 'token' => 'secret', 'account_id' => 'foreign-account', 'faction' => 'COSMIC']);
        $this->actingAs(User::factory()->create())->get(route('agents.guide', $foreign))->assertNotFound();
        $this->get(route('agents.guide', 999999))->assertNotFound();
    }
}
