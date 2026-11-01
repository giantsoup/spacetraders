<?php

namespace Tests\Feature;

use App\Enums\RunState;
use App\Gameplay\AgentSynchronizer;
use App\Gameplay\HostLock;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AgentArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['https://api.spacetraders.io/v2/' => Http::response(['version' => 'v2.3.0', 'resetDate' => '2026-11-01'])]);
    }

    public function test_listing_automatically_archives_previous_worlds_and_separates_same_name_agents(): void
    {
        $user = User::factory()->create();
        $old = $this->agent($user, '2026-10-25');
        $current = $this->agent($user, '2026-11-01');
        $alreadyArchived = $this->agent($user, '2026-10-18', 'reset');
        $unknown = $this->agent($user, null, 'unsynchronized', 'UNKNOWN');
        $foreign = $this->agent(User::factory()->create(), '2026-10-25', 'connected', 'FOREIGN');

        $this->actingAs($user)->get(route('agents.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('agents', 2)->where('agents.0.id', $current->id)->where('agents.1.id', $unknown->id)
            ->has('archivedAgents', 2)->where('archivedAgents.0.id', $old->id)->where('archivedAgents.1.id', $alreadyArchived->id)
            ->where('archivedAgents.0.connection_state', 'reset')->where('resetCheckError', null)
            ->missing('agents.0.token')->missing('archivedAgents.0.token'));

        $this->assertTrue($old->fresh()->isArchived());
        $this->assertFalse($current->fresh()->isArchived());
        $this->assertFalse($unknown->fresh()->isArchived());
        $this->assertFalse($foreign->fresh()->isArchived());
        Http::assertSentCount(1);
    }

    public function test_archival_preserves_snapshots_and_history_and_requests_old_automation_to_stop_once(): void
    {
        $user = User::factory()->create();
        $agent = $this->agent($user, '2026-10-25');
        $original = $agent->only(['token', 'credits', 'synced_at', 'reset_id', 'canonical_identity']);
        $ship = Ship::create(['agent_id' => $agent->id, 'symbol' => 'SAME-1', 'snapshot' => ['saved' => true], 'observed_at' => now()]);
        $action = ShipAction::create(['agent_id' => $agent->id, 'key' => 'historic-command', 'reset_id' => $agent->reset_id, 'ship_symbol' => 'SAME-1', 'origin' => 'manual', 'command' => 'orbit', 'state' => 'confirmed', 'arguments' => [], 'expires_at' => now()]);
        $run = AutomationRun::create(['agent_id' => $agent->id, 'active_agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'ship_symbol' => 'SAME-1', 'state' => RunState::Running, 'plan' => [], 'totals' => [], 'expires_at' => now()->addHour()]);

        $this->actingAs($user)->get(route('agents.index'))->assertOk();
        $this->assertEquals($original, $agent->fresh()->only(array_keys($original)));
        $this->assertSame(['saved' => true], $ship->fresh()->snapshot);
        $this->assertSame('confirmed', $action->fresh()->state->value);
        $this->assertSame(RunState::Blocked, $run->fresh()->state);
        $this->assertNotNull($run->fresh()->stop_requested_at);
        $stoppedAt = $run->fresh()->stop_requested_at;
        $archivedAt = $agent->fresh()->updated_at;
        $this->travel(1)->minutes();
        $this->get(route('agents.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('agents', 0)->has('archivedAgents', 1));
        $this->assertEquals($stoppedAt, $run->fresh()->stop_requested_at);
        $this->assertEquals($archivedAt, $agent->fresh()->updated_at);
        Http::assertSentCount(1);
    }

    public function test_failed_world_check_keeps_the_saved_lists_available_without_guessing(): void
    {
        $user = User::factory()->create();
        $old = $this->agent($user, '2026-10-25');
        $archived = $this->agent($user, '2026-10-18', 'reset');
        Http::fake(fn () => throw new ConnectionException('Synthetic unavailable status'));

        $this->actingAs($user)->get(route('agents.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('agents.0.id', $old->id)->where('archivedAgents.0.id', $archived->id)
            ->where('resetCheckError', fn ($value) => is_string($value) && str_contains($value, 'Could not finish')));
        $this->assertFalse($old->fresh()->isArchived());
    }

    public function test_busy_agent_is_not_modified_without_its_gameplay_lock(): void
    {
        $user = User::factory()->create();
        $agent = $this->agent($user, '2026-10-25');
        $this->actingAs($user);
        app(HostLock::class)->run('agent-'.$agent->id, function () {
            $this->get(route('agents.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->has('agents', 1)->has('archivedAgents', 0)->where('resetCheckError', fn ($value) => $value !== null));
        });
        $this->assertFalse($agent->fresh()->isArchived());
        $this->get(route('agents.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('agents', 0)->has('archivedAgents', 1)->where('resetCheckError', null));
    }

    public function test_importing_a_reused_name_archives_the_old_world_before_saving_the_new_agent(): void
    {
        $user = User::factory()->create();
        $old = $this->agent($user, '2026-10-25');
        Http::fake(['api.spacetraders.io/v2/my/agent' => Http::response(['data' => ['symbol' => 'SAME', 'accountId' => 'synthetic', 'startingFaction' => 'COSMIC']])]);

        $this->actingAs($user)->post(route('agents.storeExisting'), ['token' => 'new-world-token'])->assertSessionHasNoErrors()->assertRedirect(route('agents.index'));
        $this->assertTrue($old->fresh()->isArchived());
        $current = $user->agents()->unarchived()->sole();
        $this->assertSame('SAME', $current->name);
        $this->assertSame('2026-11-01', $current->reset_id);
        $this->assertNotSame($old->id, $current->id);
        $this->assertSame('synthetic-secret', $old->fresh()->credential());
        $this->assertSame('new-world-token', $current->credential());
    }

    public function test_a_stale_world_observation_does_not_archive_an_agent_imported_during_the_status_request(): void
    {
        $user = User::factory()->create();
        $old = $this->agent($user, '2026-10-18');
        Http::fake(function () use ($user) {
            $this->agent($user, '2026-11-01');

            return Http::response(['version' => 'v2.3.0', 'resetDate' => '2026-10-25']);
        });

        $this->actingAs($user)->get(route('agents.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('agents', 1)->where('agents.0.reset_id', '2026-11-01')
            ->has('archivedAgents', 1)->where('archivedAgents.0.id', $old->id));
    }

    public function test_candidate_identity_is_rechecked_after_acquiring_the_agent_lock(): void
    {
        $user = User::factory()->create();
        $agent = $this->agent($user, '2026-10-18');
        $candidates = $user->agents()->get();
        $agent->update(['reset_id' => '2026-11-01', 'canonical_identity' => AgentSynchronizer::identity('2026-11-01', 'SAME')]);

        app(AgentSynchronizer::class)->archivePreviousWorlds($candidates, '2026-10-25');

        $this->assertFalse($agent->fresh()->isArchived());
        Http::assertNothingSent();
    }

    public function test_guides_exclude_archived_agents_and_redirect_old_checklist_links(): void
    {
        $user = User::factory()->create();
        $old = $this->agent($user, '2026-10-25', 'reset');
        $current = $this->agent($user, '2026-11-01');
        $this->actingAs($user)->get(route('guide'))->assertInertia(fn (Assert $page) => $page->has('agents', 1)->where('agents.0.id', $current->id));
        $this->get(route('agents.guide', $old))->assertRedirect(route('agents.index').'#archived-agents');
        $this->get(route('agents.show', $old))->assertOk()->assertInertia(fn (Assert $page) => $page->where('agent.connection_state', 'reset'));
        Http::assertNothingSent();
    }

    public function test_known_archived_agents_reject_synchronization_without_upstream_requests(): void
    {
        $user = User::factory()->create();
        $old = $this->agent($user, '2026-10-25', 'reset');
        $this->actingAs($user)->postJson(route('game.sync', $old))->assertUnprocessable()->assertJsonPath('message', 'World reset. This agent is archived. Register or import an agent for the current world.');
        Http::assertNothingSent();
        $this->assertTrue($old->fresh()->isArchived());
    }

    private function agent(User $user, ?string $reset, string $state = 'connected', string $name = 'SAME'): Agent
    {
        return $user->agents()->create([
            'name' => $name, 'token' => 'synthetic-secret', 'account_id' => 'synthetic', 'faction' => 'COSMIC',
            'reset_id' => $reset, 'canonical_identity' => $reset ? AgentSynchronizer::identity($reset, $name) : null,
            'connection_state' => $state, 'credits' => 12345, 'synced_at' => now(),
        ]);
    }
}
