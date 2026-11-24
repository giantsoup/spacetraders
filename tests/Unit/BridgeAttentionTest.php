<?php

namespace Tests\Unit;

use App\Gameplay\Bridge\BridgeAttention;
use App\Gameplay\Bridge\BridgeScope;
use App\Gameplay\RequestBudget;
use App\Models\Agent;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\CampaignRevision;
use App\Models\LocalEngine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BridgeAttentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_drain_markers_and_budget_hold_alone_count_on_the_switcher_and_selected_brief(): void
    {
        Http::preventStrayRequests();
        $this->freezeSecond();
        $user = User::factory()->create();
        $this->campaign($user, 'GIANTSOUP');
        [$fleet, $episode] = $this->campaign($user, 'SOUPFLEET');
        LocalEngine::create(['id' => 1, 'state' => 'running', 'heartbeat_at' => now()->subMinutes(6)]);

        $fleet->update(['wait_reason' => 'drain_pause:host_rate_limits']);
        $this->assertDrain($user);
        $fleet->update(['wait_reason' => null, 'pause_reason' => 'drain_pause:host_rate_limits', 'paused_at' => now()]);
        $this->assertDrain($user, 'paused');
        $fleet->update(['pause_reason' => null, 'paused_at' => null]);
        $episode->update(['state' => ['draining' => 'host_rate_limits']]);
        $this->assertDrain($user);
        $episode->update(['state' => []]);
        app(RequestBudget::class)->drainAgent('SOUPFLEET', true);
        $this->assertDrain($user);
        Http::assertNothingSent();
    }

    public function test_stops_use_current_loads_and_fold_agent_wide_reasons(): void
    {
        Http::preventStrayRequests();
        $this->freezeSecond();
        $user = User::factory()->create();
        [$primary, , $agent] = $this->campaign($user, 'SOUPFLEET');
        $this->campaign($user, 'GIANTSOUP');
        LocalEngine::create(['id' => 1, 'state' => 'off', 'heartbeat_at' => now()]);
        $primary->update(['paused_at' => now()->subMinutes(10), 'pause_reason' => 'uncertain_action']);

        $overCap = $this->arm($primary, 'SOUPFLEET-9');
        $lossEpisode = $this->episode($overCap, $agent);
        $lossEpisode->update(['state' => ['pending_pause' => 'consecutive_losing_loads']]);
        CampaignEvent::create([
            'campaign_grant_id' => $overCap->id, 'campaign_episode_id' => $lossEpisode->id,
            'kind' => 'load_result', 'data' => ['net' => -101],
        ]);
        $failed = $this->arm($primary, 'SOUPFLEET-A', ['paused_at' => now()->subMinutes(2), 'pause_reason' => 'director_error']);
        CampaignEvent::create(['campaign_grant_id' => $failed->id, 'kind' => 'director_error', 'data' => ['message' => 'Persisted failure details']]);
        $expired = $this->arm($primary, 'SOUPFLEET-B', ['expires_at' => now()->subMinute()]);
        $this->arm($primary, 'SOUPFLEET-C', ['paused_at' => now()->subMinutes(8), 'pause_reason' => 'uncertain_action']);

        DB::enableQueryLog();
        $read = BridgeAttention::read(BridgeScope::resolve($user->id, 'SOUPFLEET'), []);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(12, count($queries));
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select', strtolower($query['query']));
        }
        $cards = $read['cards'];
        $this->assertSame(['uncertain_action', 'arm_loss_stop', 'grant_expired', 'director_error'], array_column($cards, 'code'));
        $this->assertSame(['fleet_resume', 'arm_retire', 'arm_retire', 'arm_resume'], array_column($cards, 'review'));
        $this->assertSame([$primary->id, $overCap->id, $expired->id, $failed->id], array_column($cards, 'grant_id'));
        $this->assertSame('SOUPFLEET-9', $cards[1]['ship']);
        $this->assertStringContainsString('-101', $cards[1]['detail']);
        $this->assertSame('Persisted failure details', $cards[3]['detail']);
        $this->assertSame([4, 0], array_column($read['agents'], 'needs_you'));

        $other = BridgeAttention::read(BridgeScope::resolve($user->id, 'GIANTSOUP'), []);
        $this->assertSame([], $other['cards']);
        $this->assertSame([4, 0], array_column($other['agents'], 'needs_you'));
        Http::assertNothingSent();
    }

    public function test_live_command_ship_stall_is_watching_and_clears_while_paused(): void
    {
        Http::preventStrayRequests();
        $this->freezeSecond();
        $user = User::factory()->create();
        [$primary, $episode] = $this->campaign($user, 'GIANTSOUP');
        $episode->update(['state' => ['role_ships' => ['command' => 'GIANTSOUP-1']]]);
        $primary->update(['last_directed_at' => now()->subMinutes(16), 'wait_reason' => 'awaiting_fresh_state']);
        LocalEngine::create(['id' => 1, 'state' => 'running', 'heartbeat_at' => now()]);

        $read = BridgeAttention::read(BridgeScope::resolve($user->id, null), []);
        $this->assertSame([], $read['cards']);
        $this->assertSame([
            'Autopilot waiting because ship data needs a refresh',
            "Autopilot hasn't decided in 15 min",
            'No run started or load sold in 60 min',
            'GIANTSOUP-1 has been idle for at least 25 min',
        ], $read['watching']);

        $primary->update(['paused_at' => now(), 'pause_reason' => 'operator_pause']);
        $read = BridgeAttention::read(BridgeScope::resolve($user->id, null), []);
        $this->assertSame([], $read['watching']);
        $this->assertSame('primary_resume', $read['cards'][0]['review']);
        Http::assertNothingSent();
    }

    public function test_the_engine_footer_and_attention_card_share_the_heartbeat_classification(): void
    {
        $user = User::factory()->create();
        $this->campaign($user, 'GIANTSOUP');
        $engine = LocalEngine::create(['id' => 1, 'state' => 'running', 'heartbeat_at' => now()->subMinutes(6)]);
        $scope = BridgeScope::resolve($user->id, 'GIANTSOUP');

        $stale = BridgeAttention::read($scope, []);
        $this->assertTrue($stale['engine']['heartbeat_stale']);
        $this->assertSame('engine_heartbeat_stale', $stale['cards'][0]['code']);

        $engine->update(['heartbeat_at' => now()]);
        $fresh = BridgeAttention::read($scope, []);
        $this->assertFalse($fresh['engine']['heartbeat_stale']);
        $this->assertSame('running', $fresh['engine']['state']);
        $this->assertNotContains('engine_heartbeat_stale', array_column($fresh['cards'], 'code'));
    }

    private function assertDrain(User $user, string $state = 'draining'): void
    {
        $control = BridgeAttention::read(BridgeScope::resolve($user->id, 'GIANTSOUP'), []);
        $fleet = BridgeAttention::read(BridgeScope::resolve($user->id, 'SOUPFLEET'), []);
        $this->assertSame(['engine_heartbeat_stale'], array_column($control['cards'], 'code'));
        $this->assertSame(['engine_heartbeat_stale', 'drain_pause'], array_column($fleet['cards'], 'code'));
        $this->assertSame(['symbol' => 'SOUPFLEET', 'credits' => 50, 'state' => $state, 'needs_you' => 1], $control['agents'][0]);
        $this->assertSame(0, $control['agents'][1]['needs_you']);
        $this->assertSame('fleet_resume', $fleet['cards'][1]['review']);
    }

    private function campaign(User $user, string $symbol): array
    {
        $revision = CampaignRevision::firstOrCreate(['content_hash' => str_repeat('a', 64)], ['name' => 'test', 'source' => '', 'body' => []]);
        $grant = CampaignGrant::create([
            'user_id' => $user->id, 'agent_symbol' => $symbol, 'campaign_revision_id' => $revision->id,
            'revision_hash' => $revision->content_hash, 'limits' => [], 'slot' => 1,
        ]);
        $agent = $user->agents()->create([
            'name' => $symbol, 'token' => 'test-token', 'account_id' => 'test-account',
            'faction' => 'VOID', 'reset_id' => '2026-11-22', 'credits' => 50,
        ]);

        return [$grant, $this->episode($grant, $agent), $agent];
    }

    private function episode(CampaignGrant $grant, Agent $agent): CampaignEpisode
    {
        return CampaignEpisode::create([
            'campaign_grant_id' => $grant->id, 'agent_id' => $agent->id, 'reset_id' => $agent->reset_id,
            'opened_at' => now()->subHour(), 'phase' => 'earn', 'next_reset_at' => now()->addDays(6), 'start_credits' => 50,
        ]);
    }

    private function arm(CampaignGrant $primary, string $ship, array $overrides = []): CampaignGrant
    {
        return CampaignGrant::create($overrides + [
            'user_id' => $primary->user_id, 'agent_symbol' => $primary->agent_symbol,
            'campaign_revision_id' => $primary->campaign_revision_id, 'revision_hash' => $primary->revision_hash,
            'limits' => ['arm' => ['ship' => $ship, 'max_net_loss' => 100]],
        ]);
    }
}
