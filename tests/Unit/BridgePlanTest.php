<?php

namespace Tests\Unit;

use App\Gameplay\Bridge\BridgePlan;
use App\Gameplay\Bridge\BridgeScope;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\CampaignRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BridgePlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_budget_compact_line_and_exception_warnings_use_the_correct_tiers(): void
    {
        Http::preventStrayRequests();
        $grant = $this->primary();
        $scope = BridgeScope::resolve($grant->user_id, null);
        config([
            'gameplay.request_budget_enabled' => true,
            'gameplay.request_budget_stage' => 'off',
            'gameplay.host_tier2_4_per_minute' => 96,
            'gameplay.host_tier1_reserve_per_minute' => 18,
            'gameplay.agent_tier1_reserve_per_hour' => 300,
            'gameplay.agent_request_caps' => ['GIANTSOUP' => 100],
        ]);
        $path = config('gameplay.request_budget_path').'/budget.json';
        $this->assertSame('Request budget unavailable', BridgePlan::read($scope)['budget']);
        mkdir(dirname($path), 0700, true);
        file_put_contents($path, '{');
        $this->assertSame('Request budget unavailable', BridgePlan::read($scope)['budget']);

        $now = microtime(true);
        file_put_contents($path, json_encode([
            'buckets' => [],
            'host_tiers' => ['t24' => array_fill(0, 87, $now), 't1' => [$now]],
            'host_rate_limits' => [$now],
            'agents' => ['agent:GIANTSOUP' => [
                't24' => array_fill(0, 89, $now),
                't1' => array_fill(0, 20, $now),
                'deferred_at' => array_fill(0, 10, $now),
            ]],
        ], JSON_THROW_ON_ERROR));

        $plan = BridgePlan::read($scope);
        $this->assertSame('Requests 89 / 100 an hour · host 87 / 96 a minute · 1 rate limit (15 min)', $plan['budget']);
        $this->assertSame('Tier 1 settlement requests 20 / 300 an hour · host 1 / 18 a minute', $plan['budget_detail']);
        $this->assertSame([
            'SpaceTraders rate-limited us once in 15 min',
            '8% of requests waited for budget this hour',
            'Request rate near the host limit',
        ], $plan['watching']);

        config(['gameplay.agent_request_caps' => ['GIANTSOUP' => 98]]);
        $this->assertContains("Using 91% of GIANTSOUP's request allowance", BridgePlan::read($scope)['watching']);
        config(['gameplay.agent_request_caps' => ['SHARED' => 100]]);
        $this->assertStringStartsWith('No Tier 2–4 allowance', BridgePlan::read($scope)['budget']);
        $this->assertCount(3, BridgePlan::read($scope)['watching']);
        config(['gameplay.agent_request_caps' => []]);
        $this->assertStringContainsString('no cap', BridgePlan::read($scope)['budget']);
        config(['gameplay.request_budget_enabled' => false]);
        $this->assertSame('Request budget off', BridgePlan::read($scope)['budget']);
        $this->assertSame([], BridgePlan::read($scope)['watching']);
        Http::assertNothingSent();
    }

    public function test_pending_plan_explains_what_prevents_activation(): void
    {
        Http::preventStrayRequests();
        $this->freezeSecond();
        $grant = $this->primary();
        CampaignGrant::create([
            'user_id' => $grant->user_id, 'agent_symbol' => $grant->agent_symbol,
            'campaign_revision_id' => $grant->campaign_revision_id, 'revision_hash' => $grant->revision_hash,
            'limits' => [], 'slot' => 2,
        ]);

        $plan = BridgePlan::read(BridgeScope::resolve($grant->user_id, null));
        $this->assertSame('Waiting for the new wipe', $plan['phase']);
        $this->assertSame(['discover', 'earn'], $plan['phases']);
        $this->assertSame('New playbook queued for the next safe point', $plan['pending']);

        $grant->update(['wait_reason' => 'drain_pause:host_rate_limits']);
        $plan = BridgePlan::read(BridgeScope::resolve($grant->user_id, null));
        $this->assertSame('New playbook queued. Waiting for the main campaign to resume or expire.', $plan['pending']);

        $agent = User::findOrFail($grant->user_id)->agents()->create([
            'name' => 'GIANTSOUP', 'token' => 'test-token', 'account_id' => 'test-account',
            'faction' => 'VOID', 'reset_id' => '2026-11-22',
        ]);
        $episode = CampaignEpisode::create([
            'campaign_grant_id' => $grant->id, 'agent_id' => $agent->id, 'reset_id' => $agent->reset_id,
            'opened_at' => now(), 'phase' => 'earn', 'next_reset_at' => now()->addDays(7), 'start_credits' => 0,
        ]);
        $run = AutomationRun::create([
            'campaign_episode_id' => $episode->id, 'agent_id' => $agent->id, 'active_agent_id' => $agent->id,
            'reset_id' => $agent->reset_id, 'ship_symbol' => 'GIANTSOUP-1', 'strategy' => 'trade',
            'plan' => [], 'totals' => [], 'expires_at' => now()->addMinutes(30), 'state' => 'blocked', 'stop_requested_at' => now(),
        ]);
        $this->travel(2)->hours();
        $plan = BridgePlan::read(BridgeScope::resolve($grant->user_id, null));
        $this->assertSame('earn', $plan['phase']);
        $this->assertSame('New playbook queued. Waiting for the main campaign to settle.', $plan['pending']);

        $run->update(['active_agent_id' => null, 'state' => 'stopped']);
        $plan = BridgePlan::read(BridgeScope::resolve($grant->user_id, null));
        $this->assertSame('New playbook queued for the next safe point', $plan['pending']);
        Http::assertNothingSent();
    }

    private function primary(): CampaignGrant
    {
        $user = User::factory()->create();
        $revision = CampaignRevision::create([
            'content_hash' => str_repeat('a', 64), 'name' => 'test', 'source' => '',
            'body' => ['phases' => [['id' => 'discover'], ['id' => 'earn']]],
        ]);

        return CampaignGrant::create([
            'user_id' => $user->id, 'agent_symbol' => 'GIANTSOUP', 'campaign_revision_id' => $revision->id,
            'revision_hash' => $revision->content_hash, 'limits' => [], 'slot' => 1, 'expires_at' => now()->addHour(),
        ]);
    }
}
