<?php

namespace Tests\Feature;

use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignPlaybook;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\Ship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CampaignStatusFixture;
use Tests\TestCase;

class CampaignStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->travelTo(now());
        $this->operator = User::factory()->create();
        config(['gameplay.operator_id' => (string) $this->operator->id]);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_operator_sees_local_status_and_bounded_props_without_credentials(): void
    {
        ['grant' => $grant, 'episode' => $episode, 'run' => $run] = CampaignStatusFixture::seed($this->operator);
        $response = $this->actingAs($this->operator)->get('/campaign')->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->component('Campaign/Status')
            ->where('auth.campaignOperator', true)->where('grant.id', $grant->id)->where('grant.active', true)
            ->where('grant.revision.version', 1)->where('grant.pending_grant', null)
            ->where('episode.id', $episode->id)->where('episode.current_credits', 115000)->where('episode.net', 15000)
            ->where('episode.hours_elapsed', 2)->where('currentRun.id', $run->id)->where('currentRun.phase', 'buy')
            ->where('currentRun.plan.good', 'IRON_ORE')->where('lastDecision.rejections.0.reason', 'quote_stale')
            ->where('lastDecision.rejections.0.count', 2)->where('health.engine.state', 'running')->has('health.problems', 0)
            ->has('credits', 24)->where('credits.23.credits', 115000)->has('loads', 1)->where('loads.0.units', 20)
            ->where('loads.0.mode', 'proof')->has('ships', 1)->where('ships.0.fuel.current', 90)->has('events', 2));
        $this->assertStringNotContainsString('synthetic-only', $response->getContent());
    }

    public function test_guests_redirect_and_non_operators_get_404_for_every_endpoint(): void
    {
        ['grant' => $grant, 'run' => $run] = CampaignStatusFixture::seed($this->operator);
        $this->get('/campaign')->assertRedirect('/login');
        foreach (['pause', 'resume'] as $action) {
            $this->post('/campaign/'.$action)->assertRedirect('/login');
        }
        $this->actingAs(User::factory()->create());
        $this->get('/campaign')->assertNotFound();
        foreach (['pause', 'resume'] as $action) {
            $this->post('/campaign/'.$action)->assertNotFound();
        }
        $this->assertTrue($grant->fresh()->available());
        $this->assertSame('running', $run->fresh()->state->value);
        $this->get('/profile')->assertInertia(fn (Assert $page) => $page->where('auth.campaignOperator', false));
        config(['gameplay.operator_id' => null]);
        $this->actingAs($this->operator)->get('/campaign')->assertNotFound();
    }

    public function test_pause_stops_campaign_work_and_resume_clears_authority_hold(): void
    {
        ['grant' => $grant, 'run' => $run] = CampaignStatusFixture::seed($this->operator);
        $this->actingAs($this->operator)->from('/campaign')->post('/campaign/pause')
            ->assertRedirect('/campaign')->assertSessionHas('status', 'Campaign paused.');
        $this->assertSame('operator_pause', $grant->fresh()->pause_reason);
        $this->assertSame('stopping', $run->fresh()->state->value);
        $this->assertNotNull($run->fresh()->stop_requested_at);
        $this->get('/campaign')->assertInertia(fn (Assert $page) => $page->where('grant.active', false)->has('health.problems', 1));
        $this->post('/campaign/resume')->assertRedirect('/campaign')
            ->assertSessionHas('status', 'Campaign resumed; the next decision uses fresh state.');
        $this->assertTrue($grant->fresh()->available());
        $this->assertNull($grant->fresh()->last_directed_at);
        $this->assertDatabaseHas('campaign_events', ['kind' => 'resume', 'campaign_grant_id' => $grant->id]);
    }

    public function test_ship_scenes_use_saved_navigation_and_waypoint_knowledge_only(): void
    {
        ['agent' => $agent, 'ship' => $ship] = CampaignStatusFixture::seed($this->operator);
        $snapshot = $ship->snapshot;
        $snapshot['nav']['systemSymbol'] = 'X1-DEMO';
        $ship->update(['snapshot' => $snapshot]);
        foreach (['SOURCE' => 'PLANET', 'BUYER' => 'ORBITAL_STATION'] as $suffix => $type) {
            GameObservation::create(['agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'kind' => 'waypoint',
                'symbol' => 'X1-DEMO-'.$suffix, 'observed_at' => now(),
                'data' => ['symbol' => 'X1-DEMO-'.$suffix, 'systemSymbol' => 'X1-DEMO', 'type' => $type,
                    'traits' => [['symbol' => 'MARKETPLACE']], 'description' => 'Saved local waypoint']]);
        }
        $departure = now()->subMinute()->toIso8601String();
        $arrival = now()->addMinute()->toIso8601String();
        $transit = $snapshot;
        $transit['registration']['role'] = 'SATELLITE';
        $transit['nav']['status'] = 'IN_TRANSIT';
        $transit['nav']['route'] = ['departureTime' => $departure, 'arrival' => $arrival,
            'destination' => ['symbol' => 'X1-DEMO-BUYER', 'type' => 'ORBITAL_STATION', 'secret' => 'not-public']];
        Ship::create(['agent_id' => $agent->id, 'symbol' => 'OFFLINE-2', 'observed_at' => now(), 'snapshot' => $transit]);
        $unknown = $snapshot;
        $unknown['nav']['waypointSymbol'] = 'X1-DEMO-UNSAVED';
        unset($unknown['nav']['status']);
        Ship::create(['agent_id' => $agent->id, 'symbol' => 'OFFLINE-3', 'observed_at' => now(), 'snapshot' => $unknown]);
        $this->actingAs($this->operator)->get('/campaign')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('ships', 3)->where('ships.0.symbol', 'OFFLINE-1')->where('ships.0.role', 'command')
            ->where('ships.0.waypoint', 'X1-DEMO-SOURCE')->where('ships.0.nav_status', 'DOCKED')
            ->where('ships.0.nav.status', 'DOCKED')->where('ships.0.nav.waypointSymbol', 'X1-DEMO-SOURCE')
            ->where('ships.0.nav.route.departureTime', null)->where('ships.0.nav.route.arrival', null)
            ->where('ships.0.nav.route.destination', ['symbol' => null, 'type' => null])
            ->where('ships.0.locations.0.symbol', 'X1-DEMO-SOURCE')->where('ships.0.locations.0.type', 'PLANET')
            ->where('ships.0.locations.0.traits', [['symbol' => 'MARKETPLACE']])
            ->where('ships.0.fuel.current', 90)->where('ships.0.cargo.units', 20)
            ->where('ships.1.nav_status', 'IN_TRANSIT')->where('ships.1.waypoint', 'X1-DEMO-SOURCE')
            ->where('ships.1.nav.route.departureTime', $departure)->where('ships.1.nav.route.arrival', $arrival)
            ->where('ships.1.nav.route.destination', ['symbol' => 'X1-DEMO-BUYER', 'type' => 'ORBITAL_STATION'])
            ->has('ships.1.locations', 2)->where('ships.1.locations.1.symbol', 'X1-DEMO-BUYER')
            ->where('ships.1.locations.1.type', 'ORBITAL_STATION')->where('ships.1.locations.1.traits', [['symbol' => 'MARKETPLACE']])
            ->where('ships.2.nav.status', null)->where('ships.2.nav.waypointSymbol', 'X1-DEMO-UNSAVED')
            ->where('ships.2.locations.0.type', null)->where('ships.2.locations.0.traits', null));
    }

    public function test_resume_surfaces_game_errors_without_changing_an_expired_grant(): void
    {
        $revision = app(CampaignPlaybook::class)->import(file_get_contents(base_path('campaigns/weekly-default.yaml')));
        $grant = app(CampaignControl::class)->approve($revision, $this->operator, now()->toImmutable()->addMinute());
        app(CampaignControl::class)->pause($grant, 'operator_pause');
        $this->travel(2)->minutes();
        $this->actingAs($this->operator)->from('/campaign')->post('/campaign/resume')
            ->assertRedirect('/campaign')->assertSessionHasErrors(['campaign' => 'Grant expired. Approve a new grant.']);
        $this->assertNotNull($grant->fresh()->paused_at);
    }

    public function test_no_grant_and_no_episode_have_empty_props_and_actions_do_not_create_authority(): void
    {
        $this->actingAs($this->operator)->get('/campaign')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('grant', null)->where('episode', null)->where('currentRun', null)->where('lastDecision', null)
            ->has('loads', 0)->has('credits', 0)->has('ships', 0)->has('events', 0));
        $this->post('/campaign/pause')->assertNotFound();
        $this->post('/campaign/resume')->assertNotFound();
        $this->assertDatabaseCount('campaign_grants', 0);
        $revision = app(CampaignPlaybook::class)->import(file_get_contents(base_path('campaigns/weekly-default.yaml')));
        app(CampaignControl::class)->approve($revision, $this->operator);
        $this->get('/campaign')->assertInertia(fn (Assert $page) => $page->where('episode', null)->where('currentRun', null));
    }

    public function test_recent_lists_exclude_other_grants_and_decisions_and_have_hard_limits(): void
    {
        ['grant' => $grant, 'episode' => $episode] = CampaignStatusFixture::seed($this->operator);
        foreach (range(1, 18) as $index) {
            foreach (['wait', 'recovery_result'] as $kind) {
                CampaignEvent::create(['campaign_grant_id' => $grant->id, 'campaign_episode_id' => $episode->id,
                    'kind' => $kind, 'data' => ['reason' => 'awaiting_fresh_state', 'net' => -10, 'losing' => true]]);
            }
        }
        $other = CampaignGrant::create(['campaign_revision_id' => $grant->campaign_revision_id, 'revision_hash' => $grant->revision_hash,
            'user_id' => $this->operator->id, 'limits' => $grant->limits, 'slot' => 2]);
        CampaignEvent::create(['campaign_grant_id' => $other->id, 'kind' => 'wait', 'data' => ['reason' => 'unrelated']]);
        $this->actingAs($this->operator)->get('/campaign')->assertInertia(fn (Assert $page) => $page
            ->where('grant.id', $grant->id)->where('grant.pending_grant', $other->id)->has('loads', 15)->has('events', 15)
            ->where('events.0.kind', 'wait')->where('events.0.summary', 'awaiting_fresh_state'));
    }

    public function test_historical_authority_and_latest_decision_are_shown_with_only_five_rejection_reasons(): void
    {
        ['grant' => $grant, 'episode' => $episode] = CampaignStatusFixture::seed($this->operator);
        CampaignEvent::create(['campaign_grant_id' => $grant->id, 'campaign_episode_id' => $episode->id, 'kind' => 'decision',
            'data' => ['choice' => 'idle', 'rejections' => ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5, 'f' => 6]]]);
        $grant->update(['slot' => null, 'revoked_at' => now()]);
        $this->actingAs($this->operator)->get('/campaign')->assertInertia(fn (Assert $page) => $page
            ->where('grant.id', $grant->id)->where('grant.active', false)->where('lastDecision.choice', 'idle')
            ->has('lastDecision.rejections', 5)->where('lastDecision.rejections.0.reason', 'f')
            ->where('lastDecision.rejections.4.reason', 'b')->where('events.0.summary', 'discover → earn'));
        $this->post('/campaign/resume')->assertNotFound();
    }
}
