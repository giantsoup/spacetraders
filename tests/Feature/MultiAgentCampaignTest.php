<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignHealth;
use App\Gameplay\CampaignNotifier;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\CampaignSafety;
use App\Gameplay\CampaignStatus;
use App\Gameplay\CampaignStrategy;
use App\Gameplay\CampaignWatchdog;
use App\Gameplay\FleetClaims;
use App\Gameplay\GameException;
use App\Gameplay\GateRecon;
use App\Gameplay\HostLock;
use App\Gameplay\RequestBudget;
use App\Gameplay\RequestDeferred;
use App\Gameplay\ShipPurpose;
use App\Jobs\AdvanceAutomationRun;
use App\Jobs\ExecuteShipCommand;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\ReconMission;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class MultiAgentCampaignTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now());
        $this->user = User::factory()->create();
        config(['gameplay.operator_id' => $this->user->id]);
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function campaign(string $symbol): array
    {
        $revision = app(CampaignPlaybook::class)->import(str_replace('GIANTSOUP', $symbol, file_get_contents(base_path('campaigns/weekly-default.yaml'))));
        $grant = app(CampaignControl::class)->approve($revision, $this->user);
        $agent = $this->user->agents()->create(['name' => $symbol, 'token' => 'synthetic-'.$symbol, 'reset_id' => 'test-reset', 'faction' => 'VOID']);
        $ship = FakeSpaceTraders::ship($symbol.'-1');
        $ship['registration']['role'] = 'COMMAND';
        Ship::create(['agent_id' => $agent->id, 'symbol' => $ship['symbol'], 'snapshot' => $ship, 'observed_at' => now()]);
        $episode = CampaignEpisode::create(['campaign_grant_id' => $grant->id, 'agent_id' => $agent->id, 'reset_id' => $agent->reset_id,
            'start_credits' => 0, 'phase' => 'earn', 'opened_at' => now()->subHours(4), 'next_reset_at' => now()->addDay(),
            'state' => ['role_ships' => ['command' => $ship['symbol']]]]);

        return [$grant, $agent, $episode];
    }

    public function test_status_strategy_web_pause_and_cli_are_agent_scoped_including_paused_primaries(): void
    {
        [$a] = $this->campaign('GIANTSOUP');
        [$b] = $this->campaign('SOUPBURN');
        $this->assertSame($b->id, app(CampaignStatus::class)->authority($this->user->id, 'SOUPBURN')['grant']->id);
        $this->assertSame(substr($b->revision_hash, 0, 12), app(CampaignStrategy::class)->read($this->user->id, 'SOUPBURN')['source']['hash']);
        $this->withoutVite();
        $this->actingAs($this->user)->get(route('campaign.show', ['agent' => 'SOUPBURN']))
            ->assertOk()->assertInertia(fn ($page) => $page->where('selectedAgent', 'SOUPBURN')->where('grant.id', $b->id));
        $this->post(route('campaign.pause', ['agent' => 'SOUPBURN']))->assertRedirect(route('campaign.show', ['agent' => 'SOUPBURN']));
        $this->assertNotNull($b->fresh()->paused_at);
        $this->assertNull($a->fresh()->paused_at);
        try {
            app(CampaignControl::class)->resolvePrimary($this->user->id);
            $this->fail('Paused primaries still require a selector.');
        } catch (GameException $e) {
            $this->assertStringContainsString('--agent', $e->getMessage());
        }
        $this->artisan('campaign:resume', ['--agent' => 'SOUPBURN'])->assertSuccessful();
        $this->artisan('campaign:pause', ['--agent' => 'GIANTSOUP'])->assertSuccessful();
        $this->assertNull($b->fresh()->paused_at);
        $this->artisan('campaign:revoke', ['--all' => true])->assertSuccessful();
        $this->assertSame(0, CampaignGrant::allPrimaries()->count());
    }

    public function test_pending_replacement_and_activation_do_not_touch_another_agents_slots(): void
    {
        [$a, $agentA, $episodeA] = $this->campaign('GIANTSOUP');
        [$b, $agentB, $episodeB] = $this->campaign('SOUPBURN');
        $run = $this->activeRun($agentA, $episodeA, 'GIANTSOUP-1');
        $pendingA = app(CampaignControl::class)->approve($a->revision(), $this->user);
        $this->activeRun($agentB, $episodeB, 'SOUPBURN-1');
        $pendingB = app(CampaignControl::class)->approve($b->revision(), $this->user);
        $this->assertSame(2, $pendingA->slot);
        $this->assertSame(2, $pendingB->slot);
        $run->update(['active_agent_id' => null, 'state' => 'stopped']);
        app(CampaignControl::class)->activatePending($this->user->id, 'GIANTSOUP');
        $this->assertSame(1, $pendingA->fresh()->slot);
        $this->assertSame(2, $pendingB->fresh()->slot);
        $this->assertSame(1, $b->fresh()->slot);
    }

    private function activeRun(Agent $agent, CampaignEpisode $episode, string $ship): AutomationRun
    {
        return AutomationRun::create(['agent_id' => $agent->id, 'active_agent_id' => $agent->id, 'campaign_episode_id' => $episode->id,
            'reset_id' => $agent->reset_id, 'ship_symbol' => $ship, 'strategy' => 'trade', 'state' => 'running',
            'expires_at' => now()->addHour(), 'next_action_at' => now(), 'plan' => [], 'totals' => []]);
    }

    public function test_dispatch_slice_sees_a_lean_agent_behind_more_than_250_busy_agent_actions(): void
    {
        [$a, $agentA, $episodeA] = $this->campaign('GIANTSOUP');
        [$b, $agentB, $episodeB] = $this->campaign('SOUPBURN');
        $a->update(['paused_at' => now()]);
        $b->update(['paused_at' => now()]);
        foreach (range(1, 301) as $i) {
            ShipAction::create(['agent_id' => $agentA->id, 'reset_id' => $agentA->reset_id, 'ship_symbol' => 'GIANTSOUP-'.($i % 6),
                'key' => (string) $i, 'origin' => 'test', 'command' => 'dock', 'state' => 'pending', 'arguments' => [], 'expires_at' => now()->addHour()]);
        }
        $lean = ShipAction::create(['agent_id' => $agentB->id, 'reset_id' => $agentB->reset_id, 'ship_symbol' => 'SOUPBURN-1',
            'key' => 'lean', 'origin' => 'test', 'command' => 'dock', 'state' => 'pending', 'arguments' => [], 'expires_at' => now()->addHour()]);
        foreach (range(1, 6) as $i) {
            $this->activeRun($agentA, $episodeA, 'GIANTSOUP-'.$i);
        }
        $leanRun = $this->activeRun($agentB, $episodeB, 'SOUPBURN-1');
        $this->artisan('spacetraders:dispatch-due')->assertSuccessful();
        Queue::assertPushed(ExecuteShipCommand::class, fn ($job) => $job->actionId === $lean->id);
        Queue::assertPushed(AdvanceAutomationRun::class, fn ($job) => $job->runId === $leanRun->id);
        Queue::assertPushed(ExecuteShipCommand::class, 50);
        Queue::assertPushed(AdvanceAutomationRun::class, 7);
    }

    public function test_health_reports_all_agents_and_selected_agent_only(): void
    {
        [$a] = $this->campaign('GIANTSOUP');
        [$b] = $this->campaign('SOUPBURN');
        $b->update(['paused_at' => now(), 'pause_reason' => 'operator_pause']);
        $all = app(CampaignHealth::class)->problems();
        $this->assertArrayHasKey('SOUPBURN:campaign_paused:'.$b->id.':operator_pause', $all);
        $this->assertArrayNotHasKey('campaign_paused:'.$b->id.':operator_pause', app(CampaignHealth::class)->problems($a));
    }

    public function test_watchdog_recovers_an_agent_independently_after_a_restart(): void
    {
        [$a] = $this->campaign('GIANTSOUP');
        [$b] = $this->campaign('SOUPBURN');
        $a->update(['paused_at' => now(), 'pause_reason' => 'test']);
        $b->update(['paused_at' => now(), 'pause_reason' => 'test']);
        $notifier = $this->mock(CampaignNotifier::class);
        $notifier->shouldReceive('alert')->once()->withArgs(fn ($key) => $key === 'GIANTSOUP:campaign_paused:'.$a->id.':test');
        $notifier->shouldReceive('alert')->once()->withArgs(fn ($key) => $key === 'SOUPBURN:campaign_paused:'.$b->id.':test');
        $notifier->shouldReceive('alert')->once()->withArgs(fn ($key) => $key === 'engine_heartbeat_stale');
        $notifier->shouldReceive('recovered')->once()->withArgs(fn ($key) => $key === 'SOUPBURN:campaign_paused:'.$b->id.':test');
        (new CampaignWatchdog)->check();
        app(CampaignControl::class)->resume($b);
        $next = (new CampaignWatchdog)->check();
        $this->assertArrayHasKey('GIANTSOUP:campaign_paused:'.$a->id.':test', $next);
        $this->assertArrayNotHasKey('SOUPBURN:campaign_paused:'.$b->id.':test', $next);
    }

    public function test_roles_and_gate_recon_follow_each_agents_primary(): void
    {
        [$a, $agentA, $episodeA] = $this->campaign('GIANTSOUP');
        [$b, $agentB, $episodeB] = $this->campaign('SOUPBURN');
        foreach ([[$agentA, $episodeA], [$agentB, $episodeB]] as [$agent, $expected]) {
            $mission = new ReconMission(['agent_id' => $agent->id, 'reset_id' => $agent->reset_id]);
            $this->assertSame($expected->id, app(GateRecon::class)->episode($mission)->id);
            $purposes = app(ShipPurpose::class)->forAgent($agent, Ship::where('agent_id', $agent->id)->get());
            $this->assertSame('Main Trader', $purposes[$agent->name.'-1']);
        }
    }

    public function test_round_one_host_429_drains_second_primary_before_c0_baseline(): void
    {
        [$a, $agent, $episode] = $this->campaign('GIANTSOUP');
        $this->pressure(100, 5, 3);
        app(CampaignHealth::class)->evaluateKillSwitch();
        $this->assertFalse($a->fresh()->isDraining());
        $episode->update(['opened_at' => now()->subHour()]);
        [$b] = $this->campaign('SOUPBURN');
        app(CampaignHealth::class)->evaluateKillSwitch();
        $this->assertSame('drain_pause:host_rate_limits', $b->fresh()->wait_reason);
    }

    public function test_round_one_safety_evaluation_skips_a_busy_agent_lock(): void
    {
        $this->campaign('GIANTSOUP');
        [$grant, $agent] = $this->campaign('SOUPBURN');
        $safety = $this->mock(CampaignSafety::class);
        $safety->shouldReceive('reason')->zeroOrMoreTimes()->andReturnUsing(
            fn ($grant) => $grant->agent_symbol === 'SOUPBURN' ? 'unexplained_change' : null,
        );
        (new HostLock)->run('agent-'.$agent->id, fn () => app(CampaignHealth::class)->evaluateKillSwitch());
        $this->assertNull($grant->fresh()->paused_at);
        app(CampaignHealth::class)->evaluateKillSwitch();
        $this->assertSame('unexplained_change', $grant->fresh()->pause_reason);
    }

    public function test_round_one_cannibalization_requires_trading_in_control_system_and_sustained_margin_drop(): void
    {
        [$control, $agent, $episode] = $this->campaign('GIANTSOUP');
        foreach (['X1-SK70-A', 'X1-SK70-B', 'X1-SK70-C'] as $waypoint) {
            GameObservation::create(['agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'kind' => 'market', 'symbol' => $waypoint,
                'observed_at' => now(), 'data' => ['tradeGoods' => [['symbol' => 'COPPER_ORE', 'sellPrice' => 100]]]]);
        }
        $this->load($control, $episode, 1000);
        app(CampaignHealth::class)->evaluateKillSwitch();
        DB::table('campaign_events')->where('kind', 'load_result')->update(['created_at' => now()->subHours(4)]);
        [$other, $otherAgent, $otherEpisode] = $this->campaign('SOUPBURN');
        $probe = FakeSpaceTraders::ship('SOUPBURN-2');
        $probe['nav']['systemSymbol'] = 'X1-SK70';
        $probe['registration']['role'] = 'SATELLITE';
        Ship::create(['agent_id' => $otherAgent->id, 'symbol' => 'SOUPBURN-2', 'snapshot' => $probe, 'observed_at' => now()]);
        $this->load($control, $episode, 700);
        app(CampaignControl::class)->event($other, $otherEpisode, 'load_result', ['ship' => 'SOUPBURN-1', 'buyer' => 'X1-TEST-PORT']);
        app(CampaignHealth::class)->evaluateKillSwitch();
        $this->assertFalse($other->fresh()->isDraining(), 'A parked probe and a sale elsewhere are not overlap.');

        $trader = Ship::where('agent_id', $otherAgent->id)->where('symbol', 'SOUPBURN-1')->sole();
        $snapshot = $trader->snapshot;
        $snapshot['nav']['systemSymbol'] = 'X1-SK70';
        $trader->update(['snapshot' => $snapshot]);
        app(CampaignControl::class)->event($other, $otherEpisode, 'load_result', ['ship' => $trader->symbol, 'buyer' => 'X1-SK70-A']);
        app(CampaignHealth::class)->evaluateKillSwitch();
        $this->assertFalse($other->fresh()->isDraining(), 'A single observation is not a sustained breach.');
        for ($minute = 0; $minute < 15; $minute++) {
            $this->travel(1)->minute();
            app(CampaignHealth::class)->evaluateKillSwitch();
        }
        $this->assertSame('drain_pause:shared_system_cannibalization', $other->fresh()->wait_reason);
    }

    public function test_round_one_old_rate_limits_cannot_cascade_after_first_drain(): void
    {
        $this->campaign('GIANTSOUP');
        [$burn] = $this->campaign('SOUPBURN');
        [$fleet] = $this->campaign('SOUPFLEET');
        $this->pressure(0, 0, 3);
        app(CampaignHealth::class)->evaluateKillSwitch();
        $this->assertTrue($fleet->fresh()->isDraining());
        app(CampaignControl::class)->pause($fleet, $fleet->fresh()->wait_reason);
        app(CampaignHealth::class)->evaluateKillSwitch();
        $this->assertFalse($burn->fresh()->isDraining());
    }

    public function test_round_one_drain_survives_restart_but_does_not_block_the_next_reset(): void
    {
        $this->campaign('GIANTSOUP');
        [$grant, $agent, $episode] = $this->campaign('SOUPBURN');
        app(CampaignControl::class)->drainPause($grant, 'test_pressure', $episode);
        try {
            RequestBudget::forAgent('SOUPBURN', fn () => (new RequestBudget)->reserve(priority: 'run', maxWait: 0));
            $this->fail('A new budget instance must still honor the active drain.');
        } catch (RequestDeferred) {
        }
        (new HostLock)->run('agent-'.$agent->id, fn () => app(AgentSynchronizer::class)->archiveForReset($agent, 'next-reset'));
        RequestBudget::forAgent('SOUPBURN', fn () => (new RequestBudget)->reserve(priority: 'run', maxWait: 0));
        $this->assertTrue($agent->fresh()->isArchived());
        $this->assertTrue($grant->fresh()->isDraining(), 'The budget releases the symbol, but the operator still owns grant authority.');
    }

    private function pressure(int $ordinary, int $settlement = 0, int $rateLimits = 0): void
    {
        $dir = config('gameplay.request_budget_path');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $path = $dir.'/budget.json';
        $state = is_file($path) ? json_decode(file_get_contents($path), true) : ['buckets' => []];
        $state['host_tiers'] = ['t24' => array_fill(0, $ordinary, microtime(true)), 't1' => array_fill(0, $settlement, microtime(true))];
        $state['host_rate_limits'] = array_fill(0, $rateLimits, microtime(true));
        file_put_contents($path, json_encode($state));
    }

    private function load(CampaignGrant $grant, CampaignEpisode $episode, int $net = 1000): void
    {
        app(CampaignControl::class)->event($grant, $episode, 'load_result', ['net' => $net, 'units' => 10, 'good' => 'IRON_ORE']);
    }

    public function test_c0_baseline_excludes_arms_and_drains_newest_agent(): void
    {
        [$a, $agent, $episode] = $this->campaign('GIANTSOUP');
        $this->load($a, $episode);
        $arm = CampaignGrant::create(['campaign_revision_id' => $a->campaign_revision_id, 'revision_hash' => $a->revision_hash,
            'user_id' => $this->user->id, 'limits' => $a->limits + ['arm' => ['label' => 'H1', 'ship' => 'GIANTSOUP-5']], 'slot' => null]);
        $armEpisode = CampaignEpisode::create(['campaign_grant_id' => $arm->id, 'agent_id' => $agent->id, 'reset_id' => $agent->reset_id,
            'phase' => 'earn', 'start_credits' => 0, 'opened_at' => now()->subHours(4), 'next_reset_at' => now()->addDay()]);
        $this->load($arm, $armEpisode, -999999);
        app(CampaignHealth::class)->evaluateKillSwitch();
        $state = json_decode(DB::table('game_runtime')->where('key', 'kill_switch:'.$this->user->id.':test-reset')->value('value'), true);
        $this->assertEqualsWithDelta(1000 / 3, $state['baseline']['cr_per_hour'], .001);
        [$b] = $this->campaign('SOUPBURN');
        [$c] = $this->campaign('SOUPFLEET');
        $this->pressure(87);
        app(CampaignHealth::class)->evaluateKillSwitch();
        $this->assertFalse($c->fresh()->isDraining());
        for ($minute = 1; $minute <= 5; $minute++) {
            $this->travel(1)->minute();
            $this->pressure(87);
            app(CampaignHealth::class)->evaluateKillSwitch();
        }
        $this->assertTrue($c->fresh()->isDraining());
        $this->assertFalse($b->fresh()->isDraining());
        $this->assertNull($a->fresh()->paused_at);
        try {
            RequestBudget::forAgent('SOUPFLEET', fn () => app(RequestBudget::class)->reserve(priority: 'write', maxWait: 0));
            $this->fail('Draining blocks new writes even when pacing is disabled.');
        } catch (RequestDeferred) {
        }
        RequestBudget::forAgent('SOUPFLEET', fn () => app(RequestBudget::class)->reserve(priority: 'settlement', maxWait: 0));
        $this->addToAssertionCount(1);
        app(CampaignControl::class)->resume($c);
        RequestBudget::forAgent('SOUPFLEET', fn () => app(RequestBudget::class)->reserve(priority: 'write', maxWait: 0));
    }

    public function test_uncertain_writes_hard_pause_only_affected_agent(): void
    {
        [$a] = $this->campaign('GIANTSOUP');
        [$b, $agentB] = $this->campaign('SOUPBURN');
        ShipAction::create(['agent_id' => $agentB->id, 'reset_id' => $agentB->reset_id, 'ship_symbol' => 'SOUPBURN-1',
            'key' => 'uncertain', 'origin' => 'test', 'command' => 'dock', 'state' => 'uncertain', 'arguments' => [], 'expires_at' => now()->addHour()]);
        app(CampaignHealth::class)->evaluateKillSwitch();
        $this->assertSame('uncertain_action', $b->fresh()->pause_reason);
        $this->assertNull($a->fresh()->paused_at);
    }

    public function test_cross_agent_claims_follow_actual_system_and_inflight_cargo_is_not_double_counted(): void
    {
        [$a, $agentA, $episodeA] = $this->campaign('GIANTSOUP');
        [$b, $agentB, $episodeB] = $this->campaign('SOUPBURN');
        $claims = app(FleetClaims::class);
        $claims->claim($episodeB, ['good' => 'IRON_ORE', 'source' => 'X1-TEST-ROCK', 'buyer' => 'X1-TEST-PORT', 'ship' => 'SOUPBURN-1', 'units' => 40]);
        $this->assertSame('SOUPBURN:C0', $claims->taken($episodeA)['IRON_ORE@X1-TEST-PORT']);
        $run = $this->activeRun($agentB, $episodeB, 'SOUPBURN-1');
        $run->update(['plan' => ['symbol' => 'IRON_ORE', 'site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-PORT', 'units' => 40]]);
        $row = Ship::where('agent_id', $agentB->id)->firstOrFail();
        $snapshot = $row->snapshot;
        $snapshot['nav']['status'] = 'IN_TRANSIT';
        $snapshot['nav']['waypointSymbol'] = 'X1-TEST-PORT';
        $snapshot['nav']['route']['arrival'] = now()->addMinute()->toIso8601String();
        $snapshot['cargo'] = ['units' => 17, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 17]]];
        $row->update(['snapshot' => $snapshot]);
        $this->assertSame([['holder' => 'SOUPBURN:C0', 'units' => 17, 'eta' => $snapshot['nav']['route']['arrival']]], $claims->inFlight($episodeA, 'IRON_ORE', 'X1-TEST-PORT'));
        $rowA = Ship::where('agent_id', $agentA->id)->firstOrFail();
        $snapshotA = $rowA->snapshot;
        $snapshotA['nav']['systemSymbol'] = 'X1-AWAY';
        $rowA->update(['snapshot' => $snapshotA]);
        $this->assertSame([], $claims->taken($episodeA));
    }

    public function test_three_primaries_and_arms_rotate_under_shared_campaign_lock_after_restart(): void
    {
        $primaries = [];
        $arms = [];
        foreach (['GIANTSOUP', 'SOUPBURN', 'SOUPFLEET'] as $symbol) {
            [$grant] = $this->campaign($symbol);
            $primaries[] = $grant;
            $arms[] = CampaignGrant::create(['campaign_revision_id' => $grant->campaign_revision_id, 'revision_hash' => $grant->revision_hash,
                'user_id' => $this->user->id, 'limits' => $grant->limits + ['arm' => ['label' => 'H1', 'ship' => $symbol.'-5']], 'slot' => null]);
        }
        // Disabled engine proves local cadence without upstream calls.
        foreach ($primaries as $expected) {
            $director = new CampaignDirector;
            $this->assertTrue($director->tick());
            $this->assertSame($expected->id, $director->primaryGrantId);
        }
        $this->assertFalse((new CampaignDirector)->tick());
        foreach ($arms as $expected) {
            $director = new CampaignDirector;
            $this->assertTrue($director->tickArms());
            $this->assertSame($expected->id, $director->armGrantId);
        }
        $this->assertFalse((new CampaignDirector)->tickArms());
        Http::assertNothingSent();
    }
}
