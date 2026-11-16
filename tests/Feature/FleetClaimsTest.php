<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\CampaignSafety;
use App\Gameplay\CampaignTrade;
use App\Gameplay\Engine\EngineHost;
use App\Gameplay\FleetClaims;
use App\Gameplay\FleetMetrics;
use App\Gameplay\GameException;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\LocalEngine;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\EngineFixture;
use Tests\Support\FakeEngineHost;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class FleetClaimsTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    private User $user;

    private Agent $agent;

    private CampaignGrant $grant;

    private CampaignEpisode $episode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now());
        config(['gameplay.enabled' => true, 'gameplay.local_engine_enabled' => true]);
        $this->app->instance(EngineHost::class, new FakeEngineHost);
        $this->file = tempnam(sys_get_temp_dir(), 'st-claims-');
        $world = FakeSpaceTraders::world();
        $world['next_reset'] = now()->addHours(17)->toIso8601String();
        $world['ships'][0]['registration']['role'] = 'COMMAND';
        $probe = FakeSpaceTraders::ship('LOCAL-2', 'X1-TEST-PORT');
        $probe['registration']['role'] = 'SATELLITE';
        $probe['frame']['symbol'] = 'FRAME_PROBE';
        $world['ships'] = array_values(array_filter($world['ships'], fn ($ship) => $ship['symbol'] !== 'LOCAL-2'));
        $world['ships'][] = $probe;
        $world['market_goods']['X1-TEST-PORT'] = $world['goods'];
        $world['market_goods']['X1-TEST-PORT'][0]['sellPrice'] = 200;
        $world['market_goods']['X1-TEST-PORT'][1]['sellPrice'] = 150;
        file_put_contents($this->file, json_encode($world));
        FakeSpaceTraders::install($this->file);
        $this->user = User::factory()->create();
        $this->user->rememberAccountToken('synthetic-account-token');
        $this->agent = $this->user->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic-only', 'faction' => 'VOID',
            'reset_id' => $world['reset'], 'canonical_identity' => AgentSynchronizer::identity($world['reset'], 'LOCAL')]);
        app(AgentSynchronizer::class)->sync($this->agent);
        Queue::fake();
        EngineFixture::healthy();
        $this->grant = app(CampaignControl::class)->approve(app(CampaignPlaybook::class)->import(
            str_replace('GIANTSOUP', 'LOCAL', file_get_contents(base_path('campaigns/weekly-default.yaml')))), $this->user);
        app(CampaignDirector::class)->tick();
        $this->travel(31)->seconds();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        AutomationRun::query()->update(['active_agent_id' => null, 'state' => 'stopped']);
        $this->episode = CampaignEpisode::firstOrFail();
        $this->agent->refresh();
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        parent::tearDown();
    }

    private function arm(string $label, string $ship = 'LOCAL-1', array $extra = []): array
    {
        $grant = CampaignGrant::create(['campaign_revision_id' => $this->grant->campaign_revision_id, 'user_id' => $this->user->id,
            'revision_hash' => $this->grant->revision_hash, 'slot' => null, 'expires_at' => now()->addHours(12),
            'limits' => $this->grant->limits + ['arm' => $extra + ['label' => $label, 'ship' => $ship, 'follow' => null,
                'max_net_loss' => 50000, 'max_load_spend' => null, 'exposure_share' => 0.5]]]);
        $episode = CampaignEpisode::create(['campaign_grant_id' => $grant->id, 'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id,
            'start_credits' => $this->agent->credits, 'phase' => 'earn', 'state' => ['role_ships' => ['command' => $ship]],
            'next_reset_at' => $this->episode->next_reset_at, 'opened_at' => now()]);

        return [$grant, $episode];
    }

    private function rank(CampaignGrant $grant, CampaignEpisode $episode, ?array $ship = null, ?Agent $agent = null, array &$considered = []): array
    {
        return app(CampaignTrade::class)->rank($grant, $episode, $agent ?? $this->agent->fresh(), $ship ?? $this->ship(), $considered);
    }

    private function ship(string $symbol = 'LOCAL-1'): array
    {
        return Ship::where('symbol', $symbol)->firstOrFail()->snapshot;
    }

    private function claim(CampaignEpisode $episode, string $good, string $source = 'X1-TEST-ROCK', string $buyer = 'X1-TEST-PORT'): void
    {
        app(FleetClaims::class)->claim($episode, ['good' => $good, 'source' => $source, 'buyer' => $buyer, 'key' => $good.'@'.$buyer, 'ship' => 'LOCAL-1']);
    }

    private function goods(array $ranked): array
    {
        return array_column($ranked, 'good');
    }

    private function completedLoad(CampaignEpisode $episode, bool $proof, int $net, string $good = 'IRON_ORE'): AutomationRun
    {
        return AutomationRun::create(['campaign_episode_id' => $episode->id, 'agent_id' => $this->agent->id,
            'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-1', 'strategy' => 'trade', 'plan_version' => 3,
            'state' => 'completed', 'started_at' => now()->subMinutes(5), 'expires_at' => now()->addMinute(),
            'plan' => ['symbol' => $good, 'site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-PORT',
                'campaign_trade' => ['proof' => $proof, 'predicted_margin' => 100, 'limiting_factor' => 'proof', 'best_available' => 120, 'best_unclaimed' => 110]],
            'totals' => ['purchased' => [$good => 5], 'sold' => [$good => 5], 'purchase_cost' => 150,
                'sale_proceeds' => 150 + $net + 2, 'fuel_cost' => 2, 'credit_delta' => $net, 'unsold' => []]]);
    }

    public function test_claims_exclude_other_episodes_pairs_on_both_sides_and_record_best_values(): void
    {
        [$armGrant, $armEpisode] = $this->arm('T1a');
        $unclaimed = $this->rank($armGrant, $armEpisode);
        $this->assertSame(['IRON_ORE', 'COPPER_ORE'], $this->goods($unclaimed));
        $this->claim($this->episode, 'IRON_ORE');
        $considered = [];
        $ranked = $this->rank($armGrant, $armEpisode, considered: $considered);
        $this->assertSame(['COPPER_ORE'], $this->goods($ranked));
        $this->assertSame('IRON_ORE@X1-TEST-PORT', $ranked[0]['best_available_key']);
        $this->assertSame($unclaimed[0]['predicted_margin'], $ranked[0]['best_available']);
        $this->assertSame($ranked[0]['predicted_margin'], $ranked[0]['best_unclaimed']);
        $this->assertTrue(collect($considered)->contains(fn ($row) => ($row['rejected'] ?? null) === 'Claimed by another ship.' && $row['good'] === 'IRON_ORE' && $row['claimed_by'] === 'C0'));
        // The claim also blocks the good at either waypoint of another pair: source side, then buyer side.
        app(FleetClaims::class)->release($this->episode, 'test');
        $this->claim($this->episode, 'IRON_ORE', 'X1-TEST-ROCK', 'X1-ELSEWHERE');
        $this->assertSame(['COPPER_ORE'], $this->goods($this->rank($armGrant, $armEpisode)));
        $this->claim($this->episode, 'IRON_ORE', 'X1-ELSEWHERE', 'X1-TEST-PORT');
        $this->assertSame(['COPPER_ORE'], $this->goods($this->rank($armGrant, $armEpisode)));
        // An active trade run of the other episode counts like a claim.
        app(FleetClaims::class)->release($this->episode, 'test');
        $run = $this->completedLoad($this->episode, true, 0, 'COPPER_ORE');
        $run->update(['state' => 'running', 'active_agent_id' => $this->agent->id]);
        $this->assertSame(['IRON_ORE'], $this->goods($this->rank($armGrant, $armEpisode)));
        // C0 honours an arm's claim too.
        $run->update(['state' => 'stopped', 'active_agent_id' => null]);
        $this->claim($armEpisode, 'IRON_ORE');
        $this->assertSame(['COPPER_ORE'], $this->goods($this->rank($this->grant, $this->episode)));
        $this->assertSame(1, CampaignEvent::where('campaign_episode_id', $armEpisode->id)->where('kind', 'claim')->count());
    }

    public function test_release_and_stale_claims_free_the_pair_and_collisions_are_logged(): void
    {
        [, $armEpisode] = $this->arm('T1a');
        $claims = app(FleetClaims::class);
        $this->claim($this->episode, 'IRON_ORE');
        $this->assertSame(['IRON_ORE@X1-TEST-ROCK' => 'C0', 'IRON_ORE@X1-TEST-PORT' => 'C0'], $claims->taken($armEpisode));
        $this->assertSame([], $claims->taken($this->episode));
        $this->claim($armEpisode, 'IRON_ORE');
        $collision = CampaignEvent::where('kind', 'claim_collision')->sole();
        $this->assertSame($armEpisode->id, $collision->campaign_episode_id);
        $this->assertSame(['C0'], $collision->data['held_by']);
        $claims->release($this->episode, 'empty_hold');
        $this->assertArrayNotHasKey('claim', $this->episode->fresh()->state);
        $this->assertSame('empty_hold', CampaignEvent::where('kind', 'claim_release')->sole()->data['reason']);
        $claims->release($this->episode, 'again');
        $this->assertSame(1, CampaignEvent::where('kind', 'claim_release')->count(), 'Releasing without a claim logs nothing.');
        $this->assertSame([], $claims->taken($armEpisode));
        $this->assertNotSame([], $claims->taken($this->episode));
        $this->travel(31)->minutes();
        $this->assertSame([], $claims->taken($this->episode), 'A claim older than 30 minutes expires.');
    }

    public function test_follower_waits_for_its_leader_then_shares_only_the_leaders_pair(): void
    {
        [$leadGrant, $leadEpisode] = $this->arm('T1a');
        [$followGrant, $followEpisode] = $this->arm('T1b', 'LOCAL-1', ['follow' => 'T1a']);
        $considered = [];
        $this->assertSame([], $this->rank($followGrant, $followEpisode, considered: $considered));
        $this->assertTrue(collect($considered)->contains(fn ($row) => ($row['rejected'] ?? null) === 'Follower waiting for leader claim.'));
        $this->claim($leadEpisode, 'COPPER_ORE');
        $this->claim($this->episode, 'COPPER_ORE');
        $this->assertSame(['COPPER_ORE'], $this->goods($this->rank($followGrant, $followEpisode)), 'The follower ignores claims on its leader pair.');
        $this->claim($followEpisode, 'COPPER_ORE');
        app(FleetClaims::class)->release($this->episode, 'test');
        $this->assertContains('COPPER_ORE', $this->goods($this->rank($leadGrant, $leadEpisode)), 'A follower never blocks its leader.');
        $this->assertNotContains('COPPER_ORE', $this->goods($this->rank($this->grant, $this->episode)), 'Everyone else avoids the shared pair.');
    }

    public function test_arm_exposure_cap_counts_other_arm_cargo_and_sets_the_limiting_factor(): void
    {
        $credits = $this->agent->credits;
        Ship::where('symbol', 'LOCAL-2')->firstOrFail()->update(['snapshot' => array_replace_recursive($this->ship('LOCAL-2'),
            ['cargo' => ['capacity' => 10, 'units' => 1, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 1]]]])]);
        ShipAction::create(['agent_id' => $this->agent->id, 'ship_symbol' => 'LOCAL-2', 'key' => 'held-iron', 'reset_id' => $this->agent->reset_id,
            'command' => 'buy', 'state' => 'confirmed', 'origin' => 'automation', 'expires_at' => now()->addMinute(),
            'arguments' => ['symbol' => 'IRON_ORE', 'units' => 1], 'outcome' => ['units' => 1, 'transaction_total' => 50]]);
        $this->arm('T2', 'LOCAL-2');
        [$armGrant, $armEpisode] = $this->arm('T1a', 'LOCAL-1', ['exposure_share' => 125 / $credits]);
        $iron = collect($this->rank($armGrant, $armEpisode))->firstWhere('good', 'IRON_ORE');
        $this->assertSame(2, $iron['units']);
        $this->assertSame('exposure', $iron['limiting_factor']);
        $this->assertLessThanOrEqual(75, $iron['max_inventory']);
        [$spendGrant, $spendEpisode] = $this->arm('T3', 'LOCAL-1', ['max_load_spend' => 30, 'exposure_share' => 1]);
        $this->assertSame(1, collect($this->rank($spendGrant, $spendEpisode))->firstWhere('good', 'IRON_ORE')['units']);
        // C0 is never capped by arm exposure.
        $c0 = collect($this->rank($this->grant, $this->episode))->firstWhere('good', 'IRON_ORE');
        $this->assertSame(4, $c0['units']);
        $this->assertSame('proof', $c0['limiting_factor']);
    }

    public function test_limiting_factor_names_the_bound_that_set_units(): void
    {
        $iron = fn (array $ranked) => collect($ranked)->firstWhere('good', 'IRON_ORE');
        $this->assertSame('proof', $iron($this->rank($this->grant, $this->episode))['limiting_factor']);
        $this->completedLoad($this->episode, true, 80);
        $this->assertSame('lot', $iron($this->rank($this->grant, $this->episode))['limiting_factor']);
        $ship = $this->ship();
        $ship['cargo']['capacity'] = 5;
        $this->assertSame('hold', $iron($this->rank($this->grant, $this->episode, $ship))['limiting_factor']);
        $first = $iron($this->rank($this->grant, $this->episode));
        $agent = $this->agent->fresh();
        $agent->credits = $agent->credits - $first['max_inventory'] + 70;
        $poor = $iron($this->rank($this->grant, $this->episode, null, $agent));
        $this->assertSame('credits', $poor['limiting_factor']);
        $this->assertLessThan($first['units'], $poor['units']);
    }

    public function test_ranking_counts_preview_commitments_without_double_counting_purchased_cargo(): void
    {
        [$otherGrant, $otherEpisode] = $this->arm('T2', 'LOCAL-2');
        $other = $this->completedLoad($otherEpisode, true, 80, 'FOOD');
        $other->update(['state' => 'preview', 'started_at' => null, 'ship_symbol' => 'LOCAL-2',
            'plan' => array_replace($other->plan, ['max_inventory' => 50]), 'totals' => ['purchase_cost' => 0]]);
        [$grant, $episode] = $this->arm('T1a', 'LOCAL-1', ['exposure_share' => 125 / $this->agent->credits]);
        $first = collect($this->rank($grant, $episode))->firstWhere('good', 'IRON_ORE');
        $this->assertSame(2, $first['units']);
        $this->assertSame('exposure', $first['limiting_factor']);
        $this->assertEquals(75, app(CampaignTrade::class)->allowance($grant, $this->agent, ship: 'LOCAL-1'));
        $this->cargoCost('LOCAL-2', 'FOOD', 1, 20);
        $other->update(['state' => 'running', 'active_agent_id' => $this->agent->id, 'totals' => ['purchase_cost' => 20]]);
        $this->assertEquals(75, app(CampaignTrade::class)->allowance($grant, $this->agent, ship: 'LOCAL-1'));
        $other->update(['active_agent_id' => null, 'state' => 'stopped']);
        $this->assertEquals(105, app(CampaignTrade::class)->allowance($grant, $this->agent, ship: 'LOCAL-1'));
    }

    public function test_interleaved_buys_recheck_fresh_aggregate_exposure_including_own_cargo(): void
    {
        $this->agent->update(['credits' => 200]);
        [$grant, $episode] = $this->arm('T1a', 'LOCAL-1');
        [, $otherEpisode] = $this->arm('T1b', 'LOCAL-2');
        $run = $this->completedLoad($episode, true, 80);
        $run->update(['state' => 'running', 'active_agent_id' => $this->agent->id, 'plan' => array_replace($run->plan, ['max_inventory' => 60]), 'totals' => []]);
        $other = $this->completedLoad($otherEpisode, true, 80);
        $other->update(['state' => 'preview', 'started_at' => null, 'ship_symbol' => 'LOCAL-2', 'plan' => array_replace($other->plan, ['max_inventory' => 60]), 'totals' => []]);
        $safety = app(CampaignSafety::class);
        $check = fn (int $max) => $safety->check($run, $this->agent->fresh(), 'buy', ['symbol' => 'IRON_ORE', 'maxTotal' => $max], boundaryOnly: true);
        $check(40);
        $this->assertWaiting(fn () => $check(41), 'arm exposure');
        $this->cargoCost('LOCAL-2', 'IRON_ORE', 1, 30);
        $other->update(['state' => 'running', 'active_agent_id' => $this->agent->id, 'totals' => ['purchase_cost' => 30]]);
        $this->agent->update(['credits' => 170]);
        $check(25);
        $this->assertWaiting(fn () => $check(26), 'arm exposure');
        $this->cargoCost('LOCAL-1', 'IRON_ORE', 1, 10);
        $this->agent->update(['credits' => 160]);
        $run->update(['totals' => ['purchase_cost' => 10]]);
        $check(10);
        $this->assertWaiting(fn () => $check(11), 'arm exposure');
        $this->assertNull($grant->fresh()->paused_at);
        $this->assertSame('running', $run->fresh()->state->value);
    }

    public function test_recovery_protects_the_buyer_while_other_arms_rank_even_without_a_source(): void
    {
        [$grant, $episode] = $this->arm('T1a');
        [, $recovering] = $this->arm('T2', 'LOCAL-2');
        $recovery = AutomationRun::create(['campaign_episode_id' => $recovering->id, 'agent_id' => $this->agent->id,
            'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-2', 'strategy' => 'sell_aboard', 'state' => 'preview', 'expires_at' => now()->addMinute(),
            'plan' => ['market' => 'X1-TEST-PORT', 'initial_inventory' => ['IRON_ORE' => 1]], 'totals' => []]);
        $this->assertNotContains('IRON_ORE', $this->goods($this->rank($grant, $episode)));
        $this->assertSame(['IRON_ORE@X1-TEST-PORT' => 'T2'], app(FleetClaims::class)->taken($episode));
        $recovery->update(['state' => 'running', 'active_agent_id' => $this->agent->id]);
        $this->assertNotContains('IRON_ORE', $this->goods($this->rank($grant, $episode)));
        $recovery->update(['state' => 'completed', 'active_agent_id' => null]);
        $this->assertContains('IRON_ORE', $this->goods($this->rank($grant, $episode)));
    }

    public function test_inactive_claims_expire_unless_the_ship_still_holds_cargo(): void
    {
        [, $episode] = $this->arm('T2', 'LOCAL-2');
        app(FleetClaims::class)->claim($episode, ['good' => 'IRON_ORE', 'source' => 'X1-TEST-ROCK', 'buyer' => 'X1-TEST-PORT', 'ship' => 'LOCAL-2']);
        $episode->grant()->update(['paused_at' => now()]);
        $this->assertSame([], app(FleetClaims::class)->taken($this->episode));
        $this->cargoCost('LOCAL-2', 'IRON_ORE', 1, 30);
        $episode->grant()->update(['revoked_at' => now()]);
        $episode->update(['closed_at' => now()]);
        $this->travel(31)->minutes();
        $this->assertArrayHasKey('IRON_ORE@X1-TEST-PORT', app(FleetClaims::class)->taken($this->episode));
        $ship = Ship::where('symbol', 'LOCAL-2')->firstOrFail();
        $ship->update(['snapshot' => array_replace($ship->snapshot, ['cargo' => ['capacity' => 10, 'units' => 0, 'inventory' => []]])]);
        $this->assertSame([], app(FleetClaims::class)->taken($this->episode));
    }

    public function test_follower_arriving_first_waits_for_each_confirmed_leader_command(): void
    {
        [, $leader] = $this->arm('T1a');
        [, $episode] = $this->arm('T3', 'LOCAL-2', ['follow' => 'T1a']);
        $this->claim($leader, 'IRON_ORE');
        $leadRun = $this->completedLoad($leader, true, 80);
        $run = $this->completedLoad($episode, true, 80);
        $run->update(['ship_symbol' => 'LOCAL-2', 'totals' => [], 'plan' => array_replace($run->plan, ['campaign_trade' => []])]);
        $check = fn (string $command) => app(CampaignSafety::class)->check($run, $this->agent, $command, ['symbol' => 'IRON_ORE', 'maxTotal' => 1], boundaryOnly: true);
        $this->assertWaiting(fn () => $check('buy'), 'leader buy');
        $this->assertSame(['episode' => $leader->id, 'claim' => $leader->state['claim']], $run->fresh()->plan['campaign_trade']['leader_claim']);
        $buy = $this->leaderAction($leadRun, 'buy', 'X1-TEST-ROCK', now()->subMinute());
        $this->assertWaiting(fn () => $check('buy'), 'leader buy');
        $buy->update(['settled_at' => now(), 'before_state' => ['ship' => ['nav' => ['waypointSymbol' => 'X1-TEST-PORT']]]]);
        $this->assertWaiting(fn () => $check('buy'), 'leader buy');
        $buy->update(['before_state' => ['ship' => ['nav' => ['waypointSymbol' => 'X1-TEST-ROCK']]]]);
        $check('buy');
        $this->assertWaiting(fn () => $check('sell'), 'leader sell');
        $sale = $this->leaderAction($leadRun, 'sell', 'X1-TEST-PORT', now());
        $check('sell');
        $sale->update(['state' => 'pending']);
        $this->travel(21)->minutes();
        $check('sell');
        $this->assertNull($episode->grant()->paused_at);
    }

    public function test_follower_proceeds_when_the_leader_claim_changes_or_is_released(): void
    {
        [, $leader] = $this->arm('T1a');
        [, $episode] = $this->arm('T3', 'LOCAL-2', ['follow' => 'T1a']);
        $this->claim($leader, 'IRON_ORE');
        $run = $this->completedLoad($episode, true, 80);
        $run->update(['ship_symbol' => 'LOCAL-2', 'plan' => array_replace($run->plan, ['campaign_trade' => ['leader_claim' => ['episode' => $leader->id, 'claim' => $leader->state['claim']]]])]);
        $check = fn () => app(CampaignSafety::class)->check($run, $this->agent, 'sell', ['symbol' => 'IRON_ORE'], boundaryOnly: true);
        $this->assertWaiting($check, 'leader sell');
        $this->claim($leader, 'COPPER_ORE');
        $check();
        app(FleetClaims::class)->release($leader, 'done');
        $check();
        $this->assertTrue(true);
    }

    public function test_paused_and_revoked_arms_harvest_late_results_idempotently(): void
    {
        [$grant, $episode] = $this->arm('T1a');
        app(CampaignControl::class)->pause($grant, 'operator_pause', $episode);
        $run = $this->completedLoad($episode, true, 80);
        $this->assertSame(0, CampaignEvent::where('kind', 'load_result')->count());
        $card = app(FleetMetrics::class)->scorecard();
        $this->assertSame(1, $card['arms']['T1a']['loads_total']);
        $this->assertSame($run->id, CampaignEvent::where('kind', 'load_result')->sole()->data['run_id']);
        app(CampaignDirector::class)->tickArms();
        $this->assertSame(1, CampaignEvent::where('kind', 'load_result')->count());
        app(CampaignControl::class)->revokeArm($grant);
        $late = $this->completedLoad($episode, false, 100);
        app(CampaignDirector::class)->tickArms();
        app(FleetMetrics::class)->scorecard();
        $this->assertSame(2, CampaignEvent::where('kind', 'load_result')->count());
        $this->assertSame($late->id, CampaignEvent::where('kind', 'load_result')->latest('id')->firstOrFail()->data['run_id']);
    }

    public function test_follower_recovery_keeps_ordering_but_does_not_wait_on_a_different_pair(): void
    {
        [, $leader] = $this->arm('T1a');
        [, $episode] = $this->arm('T3', 'LOCAL-2', ['follow' => 'T1a']);
        $this->claim($leader, 'IRON_ORE');
        $original = $this->completedLoad($episode, true, 80);
        $original->update(['plan' => array_replace($original->plan, ['campaign_trade' => ['leader_claim' => ['episode' => $leader->id, 'claim' => $leader->state['claim']]]])]);
        $recovery = AutomationRun::create(['campaign_episode_id' => $episode->id, 'agent_id' => $this->agent->id,
            'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-2', 'strategy' => 'sell_aboard', 'state' => 'preview', 'expires_at' => now()->addMinute(),
            'plan' => ['market' => 'X1-TEST-PORT', 'initial_inventory' => ['IRON_ORE' => 1], 'recovery_of' => $original->id], 'totals' => []]);
        $check = fn () => app(CampaignSafety::class)->check($recovery, $this->agent, 'sell', ['symbol' => 'IRON_ORE'], boundaryOnly: true);
        $this->assertWaiting($check, 'leader sell');
        $this->assertSame($original->plan['campaign_trade']['leader_claim'], $recovery->fresh()->plan['campaign_trade']['leader_claim']);
        $this->claim($leader, 'COPPER_ORE');
        $check();
        $this->assertNull($episode->grant()->paused_at);
    }

    private function cargoCost(string $symbol, string $good, int $units, int $cost): void
    {
        $ship = Ship::where('symbol', $symbol)->firstOrFail();
        $ship->update(['snapshot' => array_replace($ship->snapshot, ['cargo' => ['capacity' => 40, 'units' => $units, 'inventory' => [['symbol' => $good, 'units' => $units]]]])]);
        ShipAction::create(['agent_id' => $this->agent->id, 'ship_symbol' => $symbol, 'key' => 'cargo-'.$symbol.'-'.$good, 'reset_id' => $this->agent->reset_id,
            'command' => 'buy', 'state' => 'confirmed', 'origin' => 'automation', 'expires_at' => now()->addMinute(),
            'arguments' => ['symbol' => $good, 'units' => $units], 'outcome' => ['units' => $units, 'transaction_total' => $cost]]);
    }

    private function leaderAction(AutomationRun $run, string $command, string $waypoint, $at): ShipAction
    {
        return ShipAction::create(['agent_id' => $this->agent->id, 'automation_run_id' => $run->id, 'ship_symbol' => $run->ship_symbol,
            'key' => 'leader-'.$command, 'reset_id' => $this->agent->reset_id, 'command' => $command, 'state' => 'confirmed', 'origin' => 'automation',
            'settled_at' => $at, 'expires_at' => now()->addMinute(), 'arguments' => ['symbol' => 'IRON_ORE', 'units' => 1],
            'before_state' => ['ship' => ['nav' => ['waypointSymbol' => $waypoint]]]]);
    }

    private function assertWaiting(callable $check, string $message): void
    {
        try {
            $check();
            $this->fail('The command should wait.');
        } catch (GameException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
            $this->assertSame(5, $exception->retryAfter);
        }
    }

    public function test_arm_reads_c0_proof_and_sales_without_writing_c0s_episode(): void
    {
        [$armGrant, $armEpisode] = $this->arm('T1a');
        $trades = app(CampaignTrade::class);
        $c0 = $this->completedLoad($this->episode, true, 80);
        foreach ([100, 90] as $index => $price) {
            ShipAction::create(['agent_id' => $this->agent->id, 'automation_run_id' => $c0->id, 'ship_symbol' => 'LOCAL-1',
                'key' => 'sale-'.$index, 'reset_id' => $this->agent->reset_id, 'command' => 'sell', 'state' => 'confirmed', 'origin' => 'automation',
                'expires_at' => now()->addMinute(), 'arguments' => ['symbol' => 'IRON_ORE', 'units' => 5],
                'before_state' => ['ship' => ['nav' => ['waypointSymbol' => 'X1-TEST-PORT']]], 'outcome' => ['units' => 5, 'transaction_total' => $price]]);
        }
        $before = $this->episode->fresh()->getAttributes();
        $events = CampaignEvent::where('campaign_episode_id', $this->episode->id)->count();
        $this->assertSame('first_scaled', $trades->mode($armEpisode, 'IRON_ORE', 'X1-TEST-PORT'));
        $this->assertEqualsWithDelta(0.1, $trades->decay($armEpisode, 'IRON_ORE', 'X1-TEST-PORT'), 0.000001);
        $iron = collect($this->rank($armGrant, $armEpisode))->firstWhere('good', 'IRON_ORE');
        $this->assertSame('first_scaled', $iron['mode']);
        // An arm's own proof stays in its episode: C0 still has to prove copper.
        $arm = $this->completedLoad($armEpisode, true, 80, 'COPPER_ORE');
        $this->assertSame('first_scaled', $trades->mode($armEpisode, 'COPPER_ORE', 'X1-TEST-PORT'));
        $this->assertSame('proof', $trades->mode($this->episode, 'COPPER_ORE', 'X1-TEST-PORT'));
        $trades->recordResults($armEpisode);
        $this->assertSame($before, $this->episode->fresh()->getAttributes());
        $this->assertSame($events, CampaignEvent::where('campaign_episode_id', $this->episode->id)->count());
        // Load results carry the fleet scorecard fields.
        $result = CampaignEvent::where('campaign_episode_id', $armEpisode->id)->where('kind', 'load_result')->sole()->data;
        $this->assertSame($arm->id, $result['run_id']);
        $this->assertSame(['LOCAL-1', 'T1a', 'X1-TEST-ROCK', 5, 150, 232, 2, 300, 120, 110, 'proof'],
            [$result['ship'], $result['arm'], $result['source'], $result['units'], $result['purchase_cost'], $result['sale_proceeds'],
                $result['fuel_cost'], $result['seconds'], $result['best_available'], $result['best_unclaimed'], $result['limiting_factor']]);
        $this->assertSame('C0', $trades->results($this->episode)[0]['arm']);
    }

    public function test_c0_ignores_claims_when_the_flag_is_off_but_arms_still_honour_them(): void
    {
        [$armGrant, $armEpisode] = $this->arm('T1a');
        $this->claim($armEpisode, 'IRON_ORE');
        $this->claim($this->episode, 'COPPER_ORE');
        config(['gameplay.campaign_claims' => false]);
        $this->assertSame(['IRON_ORE', 'COPPER_ORE'], $this->goods($this->rank($this->grant, $this->episode)));
        $this->assertSame(['IRON_ORE'], $this->goods($this->rank($armGrant, $armEpisode)));
        config(['gameplay.campaign_claims' => true]);
        $this->assertSame(['COPPER_ORE'], $this->goods($this->rank($this->grant, $this->episode)));
    }
}
