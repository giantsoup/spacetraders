<?php

namespace Tests\Unit;

use App\Gameplay\Bridge\BridgeCredits;
use App\Gameplay\Bridge\BridgeScope;
use App\Models\Agent;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\CampaignRevision;
use App\Models\CampaignSample;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BridgeCreditsTest extends TestCase
{
    use RefreshDatabase;

    public function test_wipe_baseline_and_chart_span_replaced_and_revoked_grants(): void
    {
        Http::preventStrayRequests();
        $this->freezeSecond();
        $agent = $this->agent(User::factory()->create(), '2026-11-22', 10000);
        $original = $this->episode($agent, now()->subHours(49), 1000, ['superseded_at' => now()->subDay()]);
        $current = $this->episode($agent, now()->subDay(), 4000, ['slot' => 1]);
        $arm = $this->episode($agent, now()->subHours(6), 7000, [
            'revoked_at' => now(),
            'limits' => ['arm' => ['ship' => 'WIPETEST-A']],
        ]);
        $start = now()->subHours(49);
        $second = now()->subHours(12);
        $rows = [];
        for ($index = 0; $index < 700; $index++) {
            $rows[] = $this->sampleRow($index < 350 ? $original : $current, $index,
                $index < 350 ? $start->copy()->addMinute($index) : $second->copy()->addMinute($index - 350));
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            CampaignSample::insert($chunk);
        }
        $this->sample($arm, 2500, $start);
        $this->sample($current, 6500, now()->subMinutes(61));
        $hourSample = $this->sample($arm, 9000, now()->subMinutes(60));
        $this->sample($arm, 9999, now()->subMinutes(59));

        $archived = $this->agent($agent->user, '2026-11-15', 500, ['connection_state' => 'reset']);
        $old = $this->episode($archived, now()->subDays(8), 100);
        $this->sample($old, 100, now());

        $scope = BridgeScope::resolve($agent->user_id, 'wipetest');
        $this->assertEqualsCanonicalizing([$original->id, $current->id, $arm->id], $scope->episodes->keys()->all());
        $this->assertSame([$current->campaign_grant_id], $scope->openEpisodes->keys()->all());

        DB::enableQueryLog();
        $chart = BridgeCredits::chart($scope);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
        $this->assertStringStartsWith('select "id", "observed_at", "credits" from "campaign_samples"', $queries[0]['query']);
        $this->assertCount(3, $chart);
        $this->assertSame(240, array_sum(array_map('count', $chart)));
        $this->assertSame(['at' => $start->toIso8601String(), 'credits' => 2500], $chart[0][0]);
        $this->assertSame(349, end($chart[0])['credits']);
        $this->assertSame(350, $chart[1][0]['credits']);
        $this->assertSame(9999, end($chart[2])['credits']);

        $credits = BridgeCredits::read($scope);
        $this->assertSame(10000, $credits['credits']);
        $this->assertSame(9000, $credits['wipe_delta']);
        $this->assertSame(1000, $credits['hour_delta']);
        $this->assertSame(3, $credits['day']);
        $this->assertSame(7, $credits['days']);
        $this->assertSame($hourSample->id + 1, $credits['latest_sample_id']);
        $this->travel(16)->minutes();
        $this->assertSame(1, BridgeCredits::read($scope)['hour_delta']);
        $this->travel(1)->minute();
        $this->assertNull(BridgeCredits::read($scope)['hour_delta']);
        Http::assertNothingSent();
    }

    public function test_archiving_hides_the_old_wipe_and_starting_a_new_one_does_not_inherit_a_baseline(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $agent = $this->agent($user, '2026-11-15', 500);
        $old = $this->episode($agent, now()->subDay(), 100, ['slot' => 1]);
        $this->sample($old, 400, now()->subHour());
        $agent->update(['connection_state' => 'reset']);

        $waiting = BridgeScope::resolve($user->id, 'WIPETEST');
        $this->assertNull($waiting->agent);
        $this->assertNull($waiting->resetId);
        $this->assertSame([], BridgeCredits::chart($waiting));
        $this->assertNull(BridgeCredits::read($waiting)['credits']);

        $this->agent($user, '2026-11-22', 175000, ['synced_at' => now()->subMinutes(12)]);
        $fresh = BridgeScope::resolve($user->id, 'WIPETEST');
        $credits = BridgeCredits::read($fresh);
        $this->assertSame('2026-11-22', $fresh->resetId);
        $this->assertSame(175000, $credits['credits']);
        $this->assertSame(now()->subMinutes(12)->toIso8601String(), $credits['data_as_of']);
        $this->assertNull($credits['wipe_delta']);
        $this->assertNull($credits['day']);
        $this->assertNull($credits['latest_sample_id']);
        $this->assertSame([], BridgeCredits::chart($fresh));
        Http::assertNothingSent();
    }

    public function test_chart_keeps_first_and_latest_segments_when_gap_edges_exceed_the_point_budget(): void
    {
        Http::preventStrayRequests();
        $this->freezeSecond();
        $agent = $this->agent(User::factory()->create(), '2026-11-22', 10000);
        $episode = $this->episode($agent, now()->subHours(130), 1000, ['slot' => 1]);
        $start = $episode->opened_at;
        $rows = [];
        for ($index = 0; $index < 125; $index++) {
            $at = $start->addHour()->addHours($index);
            $rows[] = $this->sampleRow($episode, $index * 2, $at);
            $rows[] = $this->sampleRow($episode, $index * 2 + 1, $at->addMinute());
        }
        CampaignSample::insert($rows);

        $chart = BridgeCredits::chart(BridgeScope::resolve($agent->user_id, null));
        $this->assertCount(120, $chart);
        $this->assertSame([0, 1], array_column($chart[0], 'credits'));
        $this->assertSame([12, 13], array_column($chart[1], 'credits'));
        $this->assertSame([248, 249], array_column(end($chart), 'credits'));
        Http::assertNothingSent();
    }

    private function agent(User $user, string $reset, int $credits, array $overrides = []): Agent
    {
        return $user->agents()->create($overrides + [
            'name' => 'WIPETEST', 'token' => 'test-token', 'account_id' => 'test-account',
            'faction' => 'VOID', 'reset_id' => $reset, 'credits' => $credits,
        ]);
    }

    private function episode(Agent $agent, CarbonInterface $opened, int $initialCredits, array $overrides = []): CampaignEpisode
    {
        $revision = CampaignRevision::firstOrCreate(['content_hash' => str_repeat('a', 64)], ['name' => 'test', 'source' => '', 'body' => []]);
        $grant = CampaignGrant::create($overrides + [
            'user_id' => $agent->user_id, 'agent_symbol' => $agent->name, 'campaign_revision_id' => $revision->id,
            'revision_hash' => $revision->content_hash, 'limits' => [],
        ]);

        return CampaignEpisode::create([
            'campaign_grant_id' => $grant->id, 'agent_id' => $agent->id, 'reset_id' => $agent->reset_id,
            'opened_at' => $opened, 'phase' => 'earn', 'next_reset_at' => $opened->copy()->addDays(7),
            'start_credits' => $initialCredits, 'closed_at' => $grant->slot === 1 ? null : now(),
        ]);
    }

    private function sample(CampaignEpisode $episode, int $credits, CarbonInterface $at): CampaignSample
    {
        return CampaignSample::create(['ships' => [], 'request_budget' => []] + $this->sampleRow($episode, $credits, $at));
    }

    private function sampleRow(CampaignEpisode $episode, int $credits, CarbonInterface $at): array
    {
        return [
            'campaign_episode_id' => $episode->id, 'credits' => $credits, 'phase' => 'earn',
            'ships' => '[]', 'request_budget' => '[]', 'observed_at' => $at->toDateTimeString(),
        ];
    }
}
