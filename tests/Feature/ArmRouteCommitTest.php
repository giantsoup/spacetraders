<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\Engine\EngineHost;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\LocalEngine;
use App\Models\ShipAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\EngineFixture;
use Tests\Support\FakeEngineHost;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

/**
 * An arm flies a one-way positioning run to the source of its claimed pair. On arrival it commits
 * to that pair for a bounded time instead of releasing the claim and re-ranking ("positioning thrash").
 * World: the arm (LOCAL-3) starts at PORT, the IRON_ORE buyer; the pair's source is ROCK. LOCAL-1
 * parks at MOON. Once the arm leaves PORT, no ship sees PORT's prices, so its quote ages.
 */
class ArmRouteCommitTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    private User $user;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now());
        config(['gameplay.enabled' => true, 'gameplay.local_engine_enabled' => true]);
        $this->app->instance(EngineHost::class, new FakeEngineHost);
        $this->file = tempnam(sys_get_temp_dir(), 'st-commit-');
        $world = FakeSpaceTraders::world();
        $world['next_reset'] = now()->addHours(17)->toIso8601String();
        $world['waypoints'][] = ['symbol' => 'X1-TEST-MOON', 'systemSymbol' => 'X1-TEST', 'type' => 'MOON', 'x' => 6, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]];
        $world['ships'][0]['registration']['role'] = 'COMMAND';
        $world['ships'][] = FakeSpaceTraders::ship('LOCAL-3', 'X1-TEST-PORT');
        $world['market_goods']['X1-TEST-ROCK'] = $world['goods'];
        $world['market_goods']['X1-TEST-PORT'] = $world['goods'];
        $world['market_goods']['X1-TEST-PORT'][0] = ['symbol' => 'IRON_ORE', 'tradeVolume' => 4, 'purchasePrice' => 250, 'sellPrice' => 200];
        $world['market_goods']['X1-TEST-MOON'] = $world['goods'];
        $world['market_goods']['X1-TEST-MOON'][0]['purchasePrice'] = 60;
        $world['market_goods']['X1-TEST-PORT'][1]['purchasePrice'] = 100;
        file_put_contents($this->file, json_encode($world));
        FakeSpaceTraders::install($this->file);
        $this->user = User::factory()->create();
        $this->user->rememberAccountToken('synthetic-account-token');
        $this->agent = $this->user->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic-only', 'faction' => 'VOID',
            'reset_id' => $world['reset'], 'canonical_identity' => AgentSynchronizer::identity($world['reset'], 'LOCAL')]);
        app(AgentSynchronizer::class)->sync($this->agent);
        // LOCAL-1 priced ROCK; it now parks at MOON and prices that too.
        $this->editWorld(fn (&$world) => $world['ships'][0]['nav']['waypointSymbol'] = 'X1-TEST-MOON');
        app(AgentSynchronizer::class)->sync($this->agent);
        Queue::fake();
        EngineFixture::healthy();
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        parent::tearDown();
    }

    public function test_arm_buys_the_committed_pair_when_a_parked_ship_refreshes_the_buyer(): void
    {
        [$grant, $episode] = $this->positionAtSource();
        $this->editWorld(fn (&$world) => $world['ships'][] = FakeSpaceTraders::ship('LOCAL-4', 'X1-TEST-PORT'));
        app(AgentSynchronizer::class)->sync($this->agent, false);
        $this->later(400);
        $this->armPass($grant);
        $run = $this->activeRun($episode);
        $this->assertSame(['trade', 'IRON_ORE', 'X1-TEST-ROCK', 'X1-TEST-PORT'], [$run->strategy, $run->plan['symbol'], $run->plan['site'], $run->plan['market']]);
        // The parked ship's read made the buyer fresh, so no older-quote allowance applies.
        $this->assertArrayNotHasKey('buyer_quote_max_age_seconds', $run->plan['campaign_trade']);
        $this->assertTrue(CarbonImmutable::parse($run->plan['route']['buyer_at'])->gte(now()->subSeconds(5)));
        $this->assertSame(0, $this->events($episode, 'claim_release')->count());
        $this->assertSame('trade_started', $this->events($episode, 'commit_end')->sole()->data['reason']);
        $this->assertArrayNotHasKey('commit', $episode->fresh()->state);
    }

    public function test_arm_buys_the_committed_pair_against_a_buyer_quote_within_the_bound(): void
    {
        [$grant, $episode] = $this->positionAtSource();
        $this->later(400);
        $this->armPass($grant);
        $run = $this->activeRun($episode);
        $this->assertSame(['trade', 'X1-TEST-ROCK', 'X1-TEST-PORT'], [$run->strategy, $run->plan['site'], $run->plan['market']]);
        $this->assertSame(900, $run->plan['campaign_trade']['buyer_quote_max_age_seconds']);
        $this->assertTrue(CarbonImmutable::parse($run->plan['route']['buyer_at'])->lt(now()->subSeconds(180)));
        // The cost-basis sell floor is unchanged.
        $this->assertSame((int) ceil($run->plan['campaign_trade']['predicted_cost'] / $run->plan['units']), $run->plan['min_sell_price']);
        $this->assertSame(0, $this->events($episode, 'claim_release')->count());
        $this->assertSame('IRON_ORE', $episode->fresh()->state['claim']['good']);
        // The run's own buy step accepts the same bounded buyer quote.
        app(AutomationRunner::class)->advance($run->id);
        $this->assertNotSame('blocked', $run->fresh()->state->value, (string) $run->fresh()->blocked_reason);
        $this->assertSame('buy', ShipAction::where('automation_run_id', $run->id)->latest('id')->firstOrFail()->command);
    }

    public function test_with_commitment_off_the_arm_thrashes_as_before(): void
    {
        // The production trace: release on arrival, stale buyer rejects the pair, reposition elsewhere.
        config(['gameplay.campaign_commit_minutes' => 0]);
        [$grant, $episode] = $this->positionAtSource(commit: false);
        $this->later(400);
        $this->armPass($grant);
        $this->assertSame('idle', $this->events($episode, 'claim_release')->sole()->data['reason']);
        $this->assertStringContainsString('X1-TEST-PORT for IRON_ORE are unknown or stale', json_encode($this->lastDecision($episode)->data));
        $run = $this->activeRun($episode);
        $this->assertSame(['scout', 'X1-TEST-MOON'], [$run->strategy, $run->plan['destination']]);
        $this->assertSame(0, $this->events($episode, 'commit')->count());
    }

    public function test_arm_waits_for_a_buyer_quote_then_re_ranks_after_the_bound(): void
    {
        [$grant, $episode] = $this->positionAtSource();
        $this->later(1000);
        $this->armPass($grant);
        $this->assertNull($this->activeRun($episode, required: false));
        $decision = $this->lastDecision($episode);
        $this->assertSame('awaiting_buyer_quote', $decision->data['choice']);
        $this->assertStringContainsString('Committed pair: buyer quote at X1-TEST-PORT', json_encode($decision->data));
        $this->assertSame('IRON_ORE', $episode->fresh()->state['claim']['good']);
        $this->assertNotNull($episode->fresh()->state['commit']['arrived_at']);
        $this->assertSame(0, $this->events($episode, 'claim_release')->count());

        // Still within the bound: keeps waiting and keeps the claim.
        $this->later(300);
        $this->armPass($grant);
        $this->assertSame('awaiting_buyer_quote', $this->lastDecision($episode)->data['choice']);
        $this->assertSame(0, $this->events($episode, 'claim_release')->count());

        // Past arrival + campaign_commit_minutes: the commitment ends and normal ranking resumes.
        $this->later(301);
        $this->armPass($grant);
        $this->assertSame('expired', $this->events($episode, 'commit_end')->sole()->data['reason']);
        $this->assertSame('idle', $this->events($episode, 'claim_release')->sole()->data['reason']);
        // Normal ranking: the stale buyer rejects the pair and the arm repositions to the next one.
        $decision = $this->lastDecision($episode);
        $this->assertSame('trade', $decision->data['choice']);
        $this->assertStringContainsString('X1-TEST-PORT for IRON_ORE are unknown or stale', json_encode($decision->data));
        $run = $this->activeRun($episode);
        $this->assertSame(['scout', 'X1-TEST-MOON'], [$run->strategy, $run->plan['destination']]);
        $this->assertSame('X1-TEST-MOON', $episode->fresh()->state['commit']['source']);
    }

    public function test_arm_switches_when_the_committed_pair_turns_unprofitable(): void
    {
        [$grant, $episode] = $this->positionAtSource();
        $this->editWorld(function (&$world) {
            $world['market_goods']['X1-TEST-ROCK'][0]['purchasePrice'] = 300;
            $world['market_goods']['X1-TEST-ROCK'][1]['sellPrice'] = 200;
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->later(60);
        $this->armPass($grant);
        $end = $this->events($episode, 'commit_end')->sole();
        $this->assertSame(['pair_not_ranked', 'IRON_ORE@X1-TEST-PORT'], [$end->data['reason'], $end->data['key']]);
        $run = $this->activeRun($episode);
        $this->assertSame(['scout', 'X1-TEST-MOON'], [$run->strategy, $run->plan['destination']]);
        $state = $episode->fresh()->state;
        $this->assertSame(['COPPER_ORE', 'X1-TEST-MOON', 'X1-TEST-ROCK'], [$state['claim']['good'], $state['claim']['source'], $state['claim']['buyer']]);
        $this->assertSame('COPPER_ORE@X1-TEST-ROCK', $state['commit']['key']);
    }

    public function test_arm_switches_only_for_a_pair_that_beats_the_ratio_including_repositioning(): void
    {
        [$grant, $episode] = $this->positionAtSource();
        $this->editWorld(fn (&$world) => $world['market_goods']['X1-TEST-ROCK'][1]['sellPrice'] = 2000);
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->later(60);
        $this->armPass($grant);
        $end = $this->events($episode, 'commit_end')->sole();
        $this->assertSame(['better_pair', 'COPPER_ORE@X1-TEST-ROCK'], [$end->data['reason'], $end->data['better_key']]);
        $this->assertGreaterThanOrEqual($end->data['committed_score'] * 1.1, $end->data['better_score']);
        $this->assertSame(['scout', 'X1-TEST-MOON'], [$this->activeRun($episode)->strategy, $this->activeRun($episode)->plan['destination']]);
    }

    public function test_a_slightly_better_pair_does_not_break_the_commitment(): void
    {
        [$grant, $episode] = $this->positionAtSource();
        // COPPER from MOON at 35 into ROCK at 36 is profitable but far below 1.1 x the committed pair.
        $this->editWorld(fn (&$world) => $world['market_goods']['X1-TEST-ROCK'][1]['sellPrice'] = 36);
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->later(60);
        $this->armPass($grant);
        $run = $this->activeRun($episode);
        $this->assertSame(['trade', 'IRON_ORE'], [$run->strategy, $run->plan['symbol']]);
        $this->assertSame('trade_started', $this->events($episode, 'commit_end')->sole()->data['reason']);
    }

    public function test_c0_positioning_never_commits(): void
    {
        $this->editWorld(function (&$world) {
            $world['ships'] = array_values(array_filter($world['ships'], fn ($ship) => $ship['symbol'] !== 'LOCAL-3'));
            $world['ships'][0]['nav']['waypointSymbol'] = 'X1-TEST-PORT';
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $grant = app(CampaignControl::class)->approve(app(CampaignPlaybook::class)->import($this->body('weekly-default')), $this->user);
        $this->c0Pass();
        $episode = CampaignEpisode::where('campaign_grant_id', $grant->id)->firstOrFail();
        $episode->update(['phase' => 'earn']);
        $this->c0Pass();
        $run = $this->activeRun($episode);
        $this->assertSame(['scout', 'X1-TEST-ROCK'], [$run->strategy, $run->plan['destination']]);
        $this->arrive($run, 'LOCAL-1');
        $this->later(400);
        $this->c0Pass();
        $this->assertSame(0, $this->events($episode, 'commit')->count());
        $this->assertArrayNotHasKey('commit', $episode->fresh()->state);
        $this->assertSame('idle', $this->events($episode, 'claim_release')->sole()->data['reason']);
    }

    /** @return array{0: CampaignGrant, 1: CampaignEpisode} */
    private function positionAtSource(bool $commit = true): array
    {
        $grant = app(CampaignControl::class)->approveArm(app(CampaignPlaybook::class)->import($this->body('arm-trade')), $this->user,
            ['label' => 'T1a', 'ship' => 'LOCAL-3'], CarbonImmutable::now()->addHours(12));
        $this->armPass($grant);
        $episode = CampaignEpisode::where('campaign_grant_id', $grant->id)->firstOrFail();
        $run = $this->activeRun($episode);
        $this->assertSame(['scout', 'X1-TEST-ROCK'], [$run->strategy, $run->plan['destination']]);
        $state = $episode->fresh()->state;
        $this->assertSame(['IRON_ORE', 'X1-TEST-ROCK', 'X1-TEST-PORT'], [$state['claim']['good'], $state['claim']['source'], $state['claim']['buyer']]);
        if ($commit) {
            $this->assertSame(['IRON_ORE@X1-TEST-PORT', 'X1-TEST-ROCK', 'LOCAL-3', $run->id],
                [$state['commit']['key'], $state['commit']['source'], $state['commit']['ship'], $state['commit']['run_id']]);
            $this->assertSame(1, $this->events($episode, 'commit')->count());
        } else {
            $this->assertArrayNotHasKey('commit', $state);
        }
        $this->arrive($run, 'LOCAL-3');

        return [$grant, $episode];
    }

    private function arrive(AutomationRun $run, string $ship): void
    {
        $this->editWorld(function (&$world) use ($run, $ship) {
            foreach ($world['ships'] as &$row) {
                if ($row['symbol'] === $ship) {
                    $row['nav']['waypointSymbol'] = $run->plan['destination'];
                }
            }
        });
        $run->update(['state' => 'completed', 'active_agent_id' => null]);
        app(AgentSynchronizer::class)->sync($this->agent, false);
    }

    private function armPass(CampaignGrant $grant): void
    {
        $grant->refresh()->update(['last_directed_at' => null]);
        $this->assertTrue(app(CampaignDirector::class)->tickArms());
        $this->assertNull($grant->fresh()->paused_at, CampaignEvent::where('campaign_grant_id', $grant->id)->get()->toJson());
    }

    private function c0Pass(): void
    {
        CampaignGrant::where('slot', 1)->update(['last_directed_at' => null]);
        app(CampaignDirector::class)->tick();
    }

    private function later(int $seconds): void
    {
        $this->travel($seconds)->seconds();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
    }

    private function activeRun(CampaignEpisode $episode, bool $required = true): ?AutomationRun
    {
        $query = AutomationRun::where('campaign_episode_id', $episode->id)->whereNotNull('active_agent_id')->latest('id');

        return $required ? $query->firstOrFail() : $query->first();
    }

    private function events(CampaignEpisode $episode, string $kind)
    {
        return CampaignEvent::where('campaign_episode_id', $episode->id)->where('kind', $kind)->get();
    }

    private function lastDecision(CampaignEpisode $episode): CampaignEvent
    {
        return CampaignEvent::where('campaign_episode_id', $episode->id)->where('kind', 'decision')->latest('id')->firstOrFail();
    }

    private function body(string $name): string
    {
        return str_replace(['GIANTSOUP', 'max_credits: 60000'], ['LOCAL', 'max_credits: 100'], file_get_contents(base_path('campaigns/'.$name.'.yaml')));
    }

    private function editWorld(callable $edit): void
    {
        $world = json_decode(file_get_contents($this->file), true);
        $edit($world);
        file_put_contents($this->file, json_encode($world));
    }
}
