<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignFleet;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\CampaignTrade;
use App\Gameplay\Engine\EngineHost;
use App\Gameplay\GameException;
use App\Gameplay\HostLock;
use App\Gameplay\ShipCommandExecutor;
use App\Gameplay\SpaceTradersApi;
use App\Jobs\AdvanceAutomationRun;
use App\Jobs\ExecuteShipCommand;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\LocalEngine;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EngineFixture;
use Tests\Support\FakeEngineHost;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class ArmDirectorTest extends TestCase
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
        $this->file = tempnam(sys_get_temp_dir(), 'st-arm-');
        $world = FakeSpaceTraders::world();
        $world['next_reset'] = now()->addHours(17)->toIso8601String();
        $world['ships'][0]['registration']['role'] = 'COMMAND';
        $probe = FakeSpaceTraders::ship('LOCAL-2', 'X1-TEST-PORT');
        $probe['registration']['role'] = 'SATELLITE';
        $probe['frame']['symbol'] = 'FRAME_PROBE';
        $world['ships'][] = $probe;
        $world['ships'][] = FakeSpaceTraders::ship('LOCAL-3');
        $world['market_goods']['X1-TEST-PORT'] = $world['goods'];
        $world['market_goods']['X1-TEST-PORT'][0]['sellPrice'] = 200;
        file_put_contents($this->file, json_encode($world));
        FakeSpaceTraders::install($this->file);
        $this->user = User::factory()->create();
        $this->user->rememberAccountToken('synthetic-account-token');
        $this->agent = $this->user->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic-only', 'faction' => 'VOID',
            'reset_id' => $world['reset'], 'canonical_identity' => AgentSynchronizer::identity($world['reset'], 'LOCAL')]);
        app(AgentSynchronizer::class)->sync($this->agent);
        Queue::fake();
        EngineFixture::healthy();
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        parent::tearDown();
    }

    public function test_arm_directs_only_its_own_ship_and_its_runs_carry_the_arm_episode(): void
    {
        // The fake world has one pair. The arm decides first; C0 uses its claims rollback flag so both trade it.
        config(['gameplay.campaign_claims' => false]);
        $arm = $this->arm('T1b', 'LOCAL-3');
        $this->assertTrue($arm->available());
        $this->assertTrue(app(CampaignDirector::class)->tickArms());
        $c0 = $this->c0();
        $c0Run = AutomationRun::where('ship_symbol', 'LOCAL-1')->whereNotNull('active_agent_id')->firstOrFail();
        $episode = CampaignEpisode::where('campaign_grant_id', $arm->id)->firstOrFail();
        $this->assertSame('earn', $episode->phase);
        $this->assertSame('T1b', $episode->state['arm']);
        $this->assertSame(['command' => 'LOCAL-3'], $episode->state['role_ships']);
        $this->assertNull(app(CampaignFleet::class)->role($episode, 'probe'));
        $this->assertSame(['LOCAL-3'], app(CampaignFleet::class)->symbols($episode));
        $runs = AutomationRun::where('campaign_episode_id', $episode->id)->get();
        $this->assertNotEmpty($runs);
        $this->assertSame(['LOCAL-3'], $runs->pluck('ship_symbol')->unique()->values()->all());
        $active = $runs->whereNotNull('active_agent_id')->first();
        $this->assertSame('trade', $active->strategy);
        // C0 keeps its own episode, run and grant; nothing of the arm leaks into them.
        $c0Episode = CampaignEpisode::where('campaign_grant_id', $c0->id)->firstOrFail();
        $this->assertNotSame($c0Episode->id, $episode->id);
        $this->assertSame($c0Episode->id, $c0Run->fresh()->campaign_episode_id);
        $this->assertSame('LOCAL-1', $c0Run->ship_symbol);
        $this->assertSame(['LOCAL-1', 'LOCAL-2'], app(CampaignFleet::class)->symbols($c0Episode->fresh()));
        $this->assertFalse(AutomationRun::where('campaign_episode_id', $c0Episode->id)->where('ship_symbol', 'LOCAL-3')->exists());
        $this->assertNull($c0->fresh()->paused_at);
        // The next arm pass is throttled for 30 s per arm grant (c0() travelled 62 s, so pass time back).
        app(CampaignDirector::class)->tickArms();
        $this->assertFalse(app(CampaignDirector::class)->tickArms());
    }

    #[DataProvider('withArm')]
    public function test_c0_decision_is_the_same_with_an_arm_active(bool $withArm): void
    {
        if ($withArm) {
            // With claims on, C0 would skip the arm's pair; the rollback flag restores pure v1 ranking.
            config(['gameplay.campaign_claims' => false]);
            $this->arm('T1b', 'LOCAL-3');
            app(CampaignDirector::class)->tickArms();
            $this->assertTrue(AutomationRun::where('ship_symbol', 'LOCAL-3')->whereNotNull('active_agent_id')->exists());
        }
        $c0 = $this->c0();
        $episode = CampaignEpisode::where('campaign_grant_id', $c0->id)->firstOrFail();
        $run = AutomationRun::where('campaign_episode_id', $episode->id)->whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame(['LOCAL-1', 'trade', 'IRON_ORE', 'X1-TEST-PORT', 'X1-TEST-ROCK', 3, 100],
            [$run->ship_symbol, $run->strategy, $run->plan['symbol'], $run->plan['market'], $run->plan['site'], $run->plan['units'], $run->plan['max_inventory']]);
    }

    public function test_c0_skips_a_pair_an_arm_has_claimed(): void
    {
        $this->arm('T1b', 'LOCAL-3');
        app(CampaignDirector::class)->tickArms();
        $this->assertTrue(AutomationRun::where('ship_symbol', 'LOCAL-3')->whereNotNull('active_agent_id')->exists());
        $c0 = $this->c0();
        $episode = CampaignEpisode::where('campaign_grant_id', $c0->id)->firstOrFail();
        $this->assertFalse(AutomationRun::where('campaign_episode_id', $episode->id)->where('strategy', 'trade')->whereNotNull('active_agent_id')->exists());
        $decision = CampaignEvent::where('campaign_episode_id', $episode->id)->where('kind', 'decision')->latest('id')->firstOrFail();
        $this->assertArrayHasKey('Claimed by another ship.', $decision->data['rejections']);
        $this->assertNull($c0->fresh()->paused_at);
    }

    public static function withArm(): array
    {
        return ['without arm' => [false], 'with arm' => [true]];
    }

    public function test_arm_failures_pause_only_that_arm_and_never_touch_the_main_streak(): void
    {
        $c0 = app(CampaignControl::class)->approve(app(CampaignPlaybook::class)->import($this->body()), $this->user);
        $arm = $this->arm('T1b', 'LOCAL-3');
        $this->partialMock(SpaceTradersApi::class)->shouldReceive('resetSchedule')->times(5)->andThrow(new \TypeError('synthetic-secret-must-not-be-logged'));
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            // C0 was directed this pass window, so the scheduler gives the pass to the arm.
            $c0->update(['last_directed_at' => now()]);
            $this->artisan('spacetraders:dispatch-due')->assertSuccessful();
            if ($attempt < 5) {
                $this->assertNull($arm->fresh()->paused_at);
            }
            $this->travel(31)->seconds();
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        }
        $this->assertSame('director_error', $arm->fresh()->pause_reason);
        $this->assertSame('5', DB::table('game_runtime')->where('key', 'arm_director_failures:'.$arm->id)->value('value'));
        $this->assertNull(DB::table('game_runtime')->where('key', 'campaign_director_failures')->value('value'));
        $this->assertNull($c0->fresh()->paused_at);
        $this->assertSame(0, CampaignEvent::where('campaign_grant_id', $c0->id)->where('kind', 'director_error')->count());
        $this->assertSame(5, CampaignEvent::where('campaign_grant_id', $arm->id)->where('kind', 'director_error')->count());
        $this->assertStringNotContainsString('synthetic-secret', CampaignEvent::where('kind', 'director_error')->get()->toJson());
        app(CampaignControl::class)->resumeArm($arm);
        $this->assertNull($arm->fresh()->paused_at);
        $this->assertSame('0', DB::table('game_runtime')->where('key', 'arm_director_failures:'.$arm->id)->value('value'));
    }

    public function test_arms_tick_only_on_passes_where_c0_did_not_attempt(): void
    {
        $this->arm('T1b', 'LOCAL-3');
        $mock = $this->partialMock(CampaignDirector::class);
        $mock->shouldReceive('tick')->once()->andReturn(true);
        $mock->shouldReceive('tickArms')->never();
        $this->artisan('spacetraders:dispatch-due')->assertSuccessful();
    }

    public function test_arms_tick_when_c0_is_throttled(): void
    {
        $this->arm('T1b', 'LOCAL-3');
        $mock = $this->partialMock(CampaignDirector::class);
        $mock->shouldReceive('tick')->once()->andReturn(false);
        $mock->shouldReceive('tickArms')->once()->andReturn(false);
        $this->artisan('spacetraders:dispatch-due')->assertSuccessful();
    }

    public function test_arms_rotate_least_recently_directed_first(): void
    {
        $first = $this->arm('T1a', 'LOCAL-3');
        $this->editWorld(fn (&$world) => $world['ships'][] = FakeSpaceTraders::ship('LOCAL-4'));
        app(AgentSynchronizer::class)->sync($this->agent);
        $second = $this->arm('T1b', 'LOCAL-4');
        $director = app(CampaignDirector::class);
        $director->tickArms();
        $this->assertSame($first->id, $director->armGrantId);
        $director->tickArms();
        $this->assertSame($second->id, $director->armGrantId);
        $this->assertFalse($director->tickArms());
        $this->travel(31)->seconds();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        $director->tickArms();
        $this->assertSame($first->id, $director->armGrantId);
    }

    public function test_expired_arm_pauses_without_directing(): void
    {
        $arm = $this->arm('T1b', 'LOCAL-3', CarbonImmutable::now()->addHour());
        $this->travel(2)->hours();
        $this->assertTrue(app(CampaignDirector::class)->tickArms());
        $this->assertSame('grant_expired', $arm->fresh()->pause_reason);
        $this->assertFalse($arm->fresh()->available());
        $this->assertFalse(CampaignEpisode::where('campaign_grant_id', $arm->id)->exists());
        $this->expectException(GameException::class);
        app(CampaignControl::class)->resumeArm($arm);
    }

    public function test_arm_approval_validates_label_ship_and_follow(): void
    {
        $this->c0();
        $this->arm('T1a', 'LOCAL-3');
        foreach ([['T1a', 'LOCAL-3', null, 'already live'], ['T1c', 'LOCAL-3', null, 'already belongs'], ['T1c', 'LOCAL-1', null, 'role ship'],
            ['T1c', 'LOCAL-9', null, 'synced fleet'], ['T1c', 'LOCAL-2', 'T9', 'not a live arm']] as [$label, $ship, $follow, $message]) {
            try {
                $this->arm($label, $ship, follow: $follow);
                $this->fail('Arm '.$label.' on '.$ship.' was approved.');
            } catch (GameException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
        $this->assertSame(1, CampaignGrant::liveArms()->count());
    }

    public function test_revoke_arm_stops_its_run_and_campaign_revoke_includes_arms(): void
    {
        $arm = $this->arm('T1b', 'LOCAL-3');
        app(CampaignDirector::class)->tickArms();
        $c0 = $this->c0();
        $run = AutomationRun::where('ship_symbol', 'LOCAL-3')->whereNotNull('active_agent_id')->firstOrFail();
        app(CampaignControl::class)->revokeArm($arm);
        $this->assertNotNull($arm->fresh()->revoked_at);
        $this->assertNotNull($run->fresh()->stop_requested_at);
        $this->assertNotNull(CampaignEpisode::where('campaign_grant_id', $arm->id)->firstOrFail()->closed_at);
        $this->assertNull($c0->fresh()->revoked_at);
        $other = $this->arm('T1c', 'LOCAL-3');
        app(CampaignControl::class)->revoke();
        $this->assertNotNull($other->fresh()->revoked_at);
        $this->assertNotNull($c0->fresh()->revoked_at);
        $this->assertSame(0, CampaignGrant::liveArms()->count());
    }

    public function test_arm_commands_approve_list_pause_resume_and_revoke(): void
    {
        $revision = app(CampaignPlaybook::class)->import($this->armBody());
        $this->artisan('campaign:arm', ['revision' => $revision->id, 'ship' => 'LOCAL-3', 'label' => 'T1b', '--hours' => 12, '--max-load' => 5000])->assertSuccessful();
        $grant = CampaignGrant::liveArms()->firstOrFail();
        $this->assertSame(['label' => 'T1b', 'ship' => 'LOCAL-3', 'follow' => null, 'max_net_loss' => 50000, 'max_load_spend' => 5000, 'exposure_share' => 0.5], $grant->arm());
        $this->assertNull($grant->slot);
        $this->assertTrue($grant->expires_at->between(now()->addHours(12)->subMinute(), now()->addHours(12)->addMinute()));
        $this->artisan('campaign:arms')->expectsOutputToContain('"label": "T1b"')->assertSuccessful();
        $this->artisan('campaign:arm-pause', ['label' => 'T1b'])->assertSuccessful();
        $this->assertSame('operator_pause', $grant->fresh()->pause_reason);
        $this->artisan('campaign:arm-resume', ['--all' => true])->assertSuccessful();
        $this->assertNull($grant->fresh()->paused_at);
        $this->artisan('campaign:arm-revoke', ['label' => 'T1b'])->assertSuccessful();
        $this->assertNotNull($grant->fresh()->revoked_at);
    }

    public function test_arm_approval_reserves_c0_label_and_role_ships_without_an_open_episode(): void
    {
        foreach ([['C0', 'LOCAL-3', 'short label'], ['T1a', 'LOCAL-1', 'role ship'], ['T1a', 'LOCAL-2', 'role ship']] as [$label, $ship, $message]) {
            try {
                $this->arm($label, $ship);
                $this->fail('Reserved label or ship was approved.');
            } catch (GameException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
        $c0 = $this->c0();
        CampaignEpisode::where('campaign_grant_id', $c0->id)->update(['closed_at' => now()]);
        $this->expectExceptionMessage('role ship');
        $this->arm('T1a', 'LOCAL-1');
    }

    public function test_resume_refuses_an_arm_past_its_loss_stop(): void
    {
        $grant = $this->arm('T1a', 'LOCAL-3');
        app(CampaignDirector::class)->tickArms();
        $episode = CampaignEpisode::where('campaign_grant_id', $grant->id)->firstOrFail();
        app(CampaignControl::class)->pause($grant, 'arm_loss_stop', $episode);
        CampaignEvent::create(['campaign_grant_id' => $grant->id, 'campaign_episode_id' => $episode->id, 'kind' => 'load_result',
            'data' => ['run_id' => PHP_INT_MAX, 'net' => -50001, 'finished_at' => now()->toIso8601String()]]);
        try {
            app(CampaignControl::class)->resumeArm($grant);
            $this->fail('An arm past its loss stop resumed.');
        } catch (GameException $exception) {
            $this->assertStringContainsString('past its loss stop', $exception->getMessage());
        }
        $this->assertNotNull($grant->fresh()->paused_at);
        $this->artisan('campaign:arms')->expectsOutputToContain('minutes_since_decision')->assertSuccessful();
    }

    public function test_handoff_retains_claim_through_recovery_until_liquidation(): void
    {
        $grant = $this->arm('T1a', 'LOCAL-3');
        $director = app(CampaignDirector::class);
        $director->tickArms();
        $episode = CampaignEpisode::where('campaign_grant_id', $grant->id)->firstOrFail();
        $claim = $episode->state['claim'];
        $run = AutomationRun::where('campaign_episode_id', $episode->id)->whereNotNull('active_agent_id')->firstOrFail();
        $this->editWorld(function (&$world) {
            foreach ($world['ships'] as &$ship) {
                if ($ship['symbol'] === 'LOCAL-3') {
                    $ship['cargo'] = ['capacity' => 40, 'units' => 1, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 1]]];
                }
            }
        });
        $run->update(['state' => 'blocked', 'blocked_reason' => 'Quotes stale.']);
        $grant->refresh()->update(['last_directed_at' => null]);
        $director->tickArms();
        $this->assertSame($claim, $episode->fresh()->state['claim']);
        $recovery = AutomationRun::where('campaign_episode_id', $episode->id)->whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame('sell_aboard', $recovery->strategy);
        $this->assertSame(0, CampaignEvent::where('campaign_episode_id', $episode->id)->where('kind', 'claim_release')->count());
        $this->editWorld(function (&$world) {
            foreach ($world['ships'] as &$ship) {
                if ($ship['symbol'] === 'LOCAL-3') {
                    $ship['cargo'] = ['capacity' => 40, 'units' => 0, 'inventory' => []];
                }
            }
        });
        $recovery->update(['state' => 'completed', 'active_agent_id' => null]);
        $grant->refresh()->update(['last_directed_at' => null]);
        $this->assertTrue($director->tickArms());
        $this->assertNull($grant->fresh()->paused_at, CampaignEvent::where('campaign_episode_id', $episode->id)->get()->toJson());
        $this->assertTrue(CampaignEvent::where('campaign_episode_id', $episode->id)->where('kind', 'claim_release')->exists(), CampaignEvent::where('campaign_episode_id', $episode->id)->get()->toJson());
        $this->assertSame('idle', CampaignEvent::where('campaign_episode_id', $episode->id)->where('kind', 'claim_release')->sole()->data['reason']);
    }

    public function test_follower_run_pins_leader_claim_and_waits_at_the_real_buy_boundary(): void
    {
        $leader = $this->arm('T1a', 'LOCAL-3');
        app(CampaignDirector::class)->tickArms();
        $this->editWorld(fn (&$world) => $world['ships'][] = FakeSpaceTraders::ship('LOCAL-4'));
        app(AgentSynchronizer::class)->sync($this->agent);
        $follower = $this->arm('T3', 'LOCAL-4', follow: 'T1a');
        app(CampaignDirector::class)->tickArms();
        $leaderEpisode = CampaignEpisode::where('campaign_grant_id', $leader->id)->firstOrFail();
        $episode = CampaignEpisode::where('campaign_grant_id', $follower->id)->firstOrFail();
        $run = AutomationRun::where('campaign_episode_id', $episode->id)->whereNotNull('active_agent_id')->firstOrFail();
        $this->assertSame(['episode' => $leaderEpisode->id, 'claim' => $leaderEpisode->state['claim']], $run->plan['campaign_trade']['leader_claim']);
        app(AutomationRunner::class)->advance($run->id);
        $this->assertSame('waiting', $run->fresh()->state->value);
        $this->assertSame('Follower waiting for leader buy.', $run->fresh()->blocked_reason);
        $this->assertFalse(ShipAction::where('automation_run_id', $run->id)->exists());
        $this->assertNull($follower->fresh()->paused_at);
    }

    public function test_buys_shrink_at_enqueue_and_wait_if_exposure_changes_before_transport(): void
    {
        $grant = app(CampaignControl::class)->approveArm(app(CampaignPlaybook::class)->import($this->armBody()), $this->user,
            ['label' => 'T1a', 'ship' => 'LOCAL-3', 'exposure_share' => 0.001], CarbonImmutable::now()->addHours(12));
        app(CampaignDirector::class)->tickArms();
        $episode = CampaignEpisode::where('campaign_grant_id', $grant->id)->firstOrFail();
        $run = AutomationRun::where('campaign_episode_id', $episode->id)->whereNotNull('active_agent_id')->firstOrFail();
        $this->editWorld(fn (&$world) => $world['ships'][] = FakeSpaceTraders::ship('LOCAL-4'));
        app(AgentSynchronizer::class)->sync($this->agent);
        $other = $this->arm('T1b', 'LOCAL-4');
        $otherEpisode = CampaignEpisode::create(['campaign_grant_id' => $other->id, 'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id,
            'start_credits' => $this->agent->credits, 'phase' => 'earn', 'state' => [], 'opened_at' => now(), 'next_reset_at' => now()->addHours(17)]);
        $preview = AutomationRun::create(['campaign_episode_id' => $otherEpisode->id, 'agent_id' => $this->agent->id, 'ship_symbol' => 'LOCAL-4',
            'reset_id' => $this->agent->reset_id, 'strategy' => 'trade', 'state' => 'preview', 'expires_at' => now()->addMinute(),
            'plan' => ['symbol' => 'FOOD', 'site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-PORT', 'max_inventory' => 40], 'totals' => ['phase' => 'buy']]);
        try {
            app(CampaignTrade::class)->authorize($this->agent->fresh(), $run, $episode);
            $this->fail('Commit must recheck other arm reservations.');
        } catch (GameException $exception) {
            $this->assertStringContainsString('arm exposure', $exception->getMessage());
            $this->assertSame(30, $exception->retryAfter);
        }
        app(AutomationRunner::class)->advance($run->id);
        $action = ShipAction::where('automation_run_id', $run->id)->sole();
        $this->assertSame('buy', $action->command);
        $this->assertSame(2, $action->arguments['units']);
        $this->assertSame(60, $action->arguments['maxTotal']);
        $this->assertSame(2, $run->fresh()->plan['units']);
        $this->assertSame(60, $run->fresh()->plan['max_inventory']);
        $preview->update(['plan' => array_replace($preview->plan, ['max_inventory' => 50])]);
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame('pending', $action->fresh()->state->value);
        $this->assertStringContainsString('arm exposure', $action->fresh()->message);
        $this->assertSame([], json_decode(file_get_contents($this->file), true)['writes']);
        $preview->update(['state' => 'stopped']);
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame('confirmed', $action->fresh()->state->value);
        $this->completeRuns([$run]);
        $this->assertSame(2, $run->totals['purchased']['IRON_ORE']);
        $this->assertSame(2, $run->totals['sold']['IRON_ORE']);
        $this->assertNull($grant->fresh()->paused_at);
    }

    public function test_confirmed_purchase_keeps_the_cash_plus_cargo_exposure_invariant(): void
    {
        $this->editWorld(function (&$world) {
            $world['credits'] = 200;
            $world['goods'][0]['purchasePrice'] = 100;
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $grant = $this->arm('T1a', 'LOCAL-3');
        DB::table('agent_credit_baselines')->where('agent_id', $this->agent->id)->update(['credits' => 200]);
        app(CampaignDirector::class)->tickArms();
        $run = AutomationRun::where('ship_symbol', 'LOCAL-3')->whereNotNull('active_agent_id')->sole();
        app(AutomationRunner::class)->advance($run->id);
        $action = ShipAction::where('automation_run_id', $run->id)->sole();
        $this->assertSame(100, $action->arguments['maxTotal']);
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame('confirmed', $action->fresh()->state->value);
        $this->assertSame(100, $this->agent->fresh()->credits);
        $this->assertArmExposure(0.5);
        $this->completeRuns([$run]);
        $this->assertSame(1, $run->totals['sold']['IRON_ORE']);
        $this->assertNull($grant->fresh()->paused_at);
    }

    public function test_two_arms_finish_interleaved_multibatch_loads_at_the_exposure_cap(): void
    {
        $this->editWorld(function (&$world) {
            $world['credits'] = 400;
            $world['goods'][0]['tradeVolume'] = 2;
            $world['ships'][] = FakeSpaceTraders::ship('LOCAL-4');
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $revision = app(CampaignPlaybook::class)->import(str_replace('max_units_batches: 1', 'max_units_batches: 3', $this->armBody()));
        DB::table('agent_credit_baselines')->where('agent_id', $this->agent->id)->update(['credits' => 400]);
        $leader = app(CampaignControl::class)->approveArm($revision, $this->user,
            ['label' => 'T1a', 'ship' => 'LOCAL-3'], CarbonImmutable::now()->addHours(12));
        app(CampaignDirector::class)->tickArms();
        $follower = app(CampaignControl::class)->approveArm($revision, $this->user,
            ['label' => 'T3', 'ship' => 'LOCAL-4', 'follow' => 'T1a'], CarbonImmutable::now()->addHours(12));
        app(CampaignDirector::class)->tickArms();
        $runs = AutomationRun::whereNotNull('active_agent_id')->orderBy('id')->get()->all();
        $this->assertCount(2, $runs);
        foreach ($runs as $run) {
            $this->assertSame(3, $run->plan['units']);
        }
        $this->completeRuns($runs, 0.5);
        foreach ($runs as $run) {
            $this->assertSame(3, $run->totals['purchased']['IRON_ORE']);
            $this->assertSame(3, $run->totals['sold']['IRON_ORE']);
            $this->assertSame([2, 1], ShipAction::where('automation_run_id', $run->id)->where('command', 'buy')->where('state', 'confirmed')->get()->pluck('arguments.units')->all());
        }
        $this->assertNull($leader->fresh()->paused_at);
        $this->assertNull($follower->fresh()->paused_at);
    }

    #[DataProvider('concurrentArms')]
    public function test_small_loads_reserve_only_their_cost_under_a_large_proof_ceiling(?string $follow): void
    {
        $this->editWorld(function (&$world) {
            $world['credits'] = 4000;
            $world['ships'][] = FakeSpaceTraders::ship('LOCAL-4');
            $world['market_goods']['X1-TEST-PORT'][1]['sellPrice'] = 180;
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        DB::table('agent_credit_baselines')->where('agent_id', $this->agent->id)->update(['credits' => 4000]);
        $revision = app(CampaignPlaybook::class)->import($this->armBody(100000));
        app(CampaignControl::class)->approveArm($revision, $this->user,
            ['label' => 'T1a', 'ship' => 'LOCAL-3'], CarbonImmutable::now()->addHours(12));
        app(CampaignDirector::class)->tickArms();
        app(CampaignControl::class)->approveArm($revision, $this->user,
            ['label' => $follow ? 'T3' : 'T1b', 'ship' => 'LOCAL-4', 'follow' => $follow], CarbonImmutable::now()->addHours(12));
        app(CampaignDirector::class)->tickArms();
        $runs = AutomationRun::whereNotNull('active_agent_id')->orderBy('id')->get()->all();
        $this->assertCount(2, $runs);
        foreach ($runs as $run) {
            $this->assertSame(4, $run->plan['units']);
            $this->assertLessThanOrEqual((int) ceil($run->plan['campaign_trade']['predicted_cost'] * 1.05), $run->plan['max_inventory']);
        }
        $this->assertArmExposure(0.5);
        $this->completeRuns($runs, 0.5);
        foreach ($runs as $run) {
            $this->assertSame(4, $run->totals['sold'][$run->plan['symbol']]);
        }
    }

    public static function concurrentArms(): array
    {
        return ['independent' => [null], 'leader and follower' => ['T1a']];
    }

    public function test_departed_load_releases_unspent_commitment_but_keeps_cargo_exposure(): void
    {
        $grant = $this->arm('T1a', 'LOCAL-3');
        app(CampaignDirector::class)->tickArms();
        $run = AutomationRun::whereNotNull('active_agent_id')->sole();
        app(AutomationRunner::class)->advance($run->id);
        app(ShipCommandExecutor::class)->execute(ShipAction::where('automation_run_id', $run->id)->sole()->id);
        $run->refresh();
        $cost = $run->totals['purchase_cost'];
        $run->update(['plan' => array_replace($run->plan, ['max_inventory' => $cost + 100]),
            'totals' => array_replace($run->totals, ['phase' => 'sell'])]);
        $this->assertSame((float) (50000 - $cost), app(CampaignTrade::class)->allowance($grant, $this->agent->fresh(), ship: 'LOCAL-4'));
    }

    #[DataProvider('partialLoads')]
    public function test_partial_load_releases_commitment_and_sells_without_expiring(bool $pending, bool $timeout, ?string $change = null, ?string $guard = null): void
    {
        $this->editWorld(function (&$world) {
            $world['goods'][0]['tradeVolume'] = 1;
            $world['ships'][] = FakeSpaceTraders::ship('LOCAL-4');
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $revision = app(CampaignPlaybook::class)->import(str_replace('max_units_batches: 1', 'max_units_batches: 3', $this->armBody()));
        $grant = app(CampaignControl::class)->approveArm($revision, $this->user,
            ['label' => 'T1a', 'ship' => 'LOCAL-3', 'exposure_share' => 0.001], CarbonImmutable::now()->addHours(12));
        app(CampaignDirector::class)->tickArms();
        $run = AutomationRun::whereNotNull('active_agent_id')->sole();
        $runner = app(AutomationRunner::class);
        $runner->advance($run->id);
        $first = ShipAction::where('automation_run_id', $run->id)->sole();
        app(ShipCommandExecutor::class)->execute($first->id);
        $this->assertSame(1, $run->fresh()->totals['purchased']['IRON_ORE']);
        $this->assertSame(3, $run->fresh()->plan['units']);
        $queued = null;
        if ($pending) {
            if ($change === 'volume') {
                $this->editWorld(fn (&$world) => $world['goods'][0]['tradeVolume'] = 2);
            }
            $runner->advance($run->id);
            $queued = ShipAction::where('automation_run_id', $run->id)->where('state', 'pending')->sole();
            $this->assertSame($change === 'volume' ? 2 : 1, $queued->arguments['units']);
        }
        if ($change) {
            $this->editWorld(function (&$world) use ($change) {
                $world['goods'][0][$change === 'price' ? 'purchasePrice' : 'tradeVolume'] = $change === 'price' ? 31 : 1;
            });
        }
        if ($timeout) {
            $this->travel(120)->seconds();
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        } else {
            $other = $this->arm('T1b', 'LOCAL-4');
            $episode = CampaignEpisode::create(['campaign_grant_id' => $other->id, 'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id,
                'start_credits' => $this->agent->fresh()->credits, 'phase' => 'earn', 'state' => [], 'opened_at' => now(), 'next_reset_at' => now()->addHours(17)]);
            AutomationRun::create(['campaign_episode_id' => $episode->id, 'agent_id' => $this->agent->id, 'ship_symbol' => 'LOCAL-4',
                'reset_id' => $this->agent->reset_id, 'strategy' => 'trade', 'state' => 'preview', 'expires_at' => now()->addMinutes(5),
                'plan' => ['max_inventory' => 80], 'totals' => ['phase' => 'buy']]);
        }
        if ($guard === 'authority') {
            $grant->update(['paused_at' => now(), 'pause_reason' => 'operator_pause']);
        } elseif ($guard) {
            $this->editWorld(function (&$world) use ($guard) {
                if ($guard === 'credits') {
                    $world['credits']++;
                } else {
                    foreach ($world['ships'] as &$ship) {
                        if ($ship['symbol'] === 'LOCAL-3') {
                            $ship['cargo']['units']++;
                            $ship['cargo']['inventory'][0]['units']++;
                        }
                    }
                }
            });
        }
        if ($queued) {
            app(ShipCommandExecutor::class)->execute($queued->id);
            $this->assertSame('rejected', $queued->fresh()->state->value);
        } else {
            $run->update(['next_action_at' => now()]);
            $runner->advance($run->id);
        }
        $run->refresh();
        if ($guard) {
            $this->assertSame('blocked', $run->state->value);
            $this->assertSame('buy', $run->totals['phase']);
            $this->assertSame(3, $run->plan['units']);
            $this->assertArrayNotHasKey('original_units', $run->plan);
            $this->assertCount(1, json_decode(file_get_contents($this->file), true)['writes']);

            return;
        }
        $this->assertSame(1, $run->plan['units']);
        $this->assertSame(30, $run->plan['max_inventory']);
        $this->assertSame(3, $run->plan['original_units']);
        $this->assertSame($run->plan['campaign_trade']['predicted_margin'], $run->plan['original_predicted_margin']);
        $this->assertSame($timeout ? 'timeout' : 'exposure', $run->plan['shortened_reason']);
        $this->assertSame('sell', $run->totals['phase']);
        $this->assertNotSame('blocked', $run->fresh()->state->value);
        $this->assertNull($run->blocked_reason);
        $this->assertSame($timeout ? 70.0 : 0.0, app(CampaignTrade::class)->allowance($grant, $this->agent->fresh(), ship: 'LOCAL-5'));
        $this->completeRuns([$run]);
        $this->assertSame(1, $run->totals['sold']['IRON_ORE']);
        $this->assertSame(1, ShipAction::where('automation_run_id', $run->id)->where('command', 'buy')->where('state', 'confirmed')->count());
        $this->assertNull($grant->fresh()->paused_at);
        $episode = CampaignEpisode::findOrFail($run->campaign_episode_id);
        $result = app(CampaignTrade::class)->results($episode)[0];
        $this->assertTrue($result['shortened']);
        $this->assertSame($timeout ? 'timeout' : 'exposure', $result['shortened_reason']);
        $passing = array_replace($result, ['shortened' => false, 'realized_ratio' => 1, 'net' => 100]);
        $this->assertSame('first_scaled', app(CampaignTrade::class)->mode($episode, $run->plan['symbol'], $run->plan['market'], [$passing, $result]));
        $this->assertSame('proof', app(CampaignTrade::class)->mode($episode, $run->plan['symbol'], $run->plan['market'], [$passing, array_replace($result, ['net' => -1])]));
    }

    public static function partialLoads(): array
    {
        return ['advance cap' => [false, false], 'transport cap' => [true, false],
            'advance timeout' => [false, true], 'transport timeout' => [true, true],
            'transport cap price' => [true, false, 'price'], 'transport cap volume' => [true, false, 'volume'],
            'transport timeout price' => [true, true, 'price'], 'transport timeout volume' => [true, true, 'volume'],
            'transport cargo guard' => [true, true, 'price', 'cargo'], 'transport balance guard' => [true, true, 'price', 'credits'],
            'transport authority guard' => [true, true, 'price', 'authority'], 'advance cargo guard' => [false, true, null, 'cargo']];
    }

    private function assertArmExposure(float $share): void
    {
        $held = 0;
        foreach (Ship::where('agent_id', $this->agent->id)->get() as $ship) {
            $costs = app(CampaignTrade::class)->averageCosts($this->agent, $ship->symbol);
            foreach ($ship->snapshot['cargo']['inventory'] as $item) {
                $held += ($costs[$item['symbol']] ?? 0) * $item['units'];
            }
        }
        $committed = AutomationRun::whereNotNull('active_agent_id')->where('strategy', 'trade')->where('totals->phase', 'buy')->get()
            ->sum(fn ($run) => max(0, $run->plan['max_inventory'] - ($run->totals['purchase_cost'] ?? 0)));
        $this->assertLessThanOrEqual($share * ($this->agent->fresh()->credits + $held), $held + $committed);
    }

    private function completeRuns(array $runs, ?float $share = null): void
    {
        for ($step = 0; $step < 60 && collect($runs)->contains(fn ($run) => $run->active_agent_id); $step++) {
            foreach ($runs as $run) {
                app(AutomationRunner::class)->advance($run->id);
                foreach (ShipAction::where('state', 'pending')->orderBy('id')->get() as $action) {
                    app(ShipCommandExecutor::class)->execute($action->id);
                    if ($share && $action->command === 'buy' && $action->fresh()->state->value === 'confirmed') {
                        $this->assertArmExposure($share);
                    }
                }
                $run->refresh();
            }
            $this->travel(5)->seconds();
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        }
        foreach ($runs as $run) {
            $this->assertSame('completed', $run->fresh()->state->value, $run->blocked_reason ?? '');
            $this->assertTrue($run->expires_at->isFuture());
        }
    }

    public function test_lock_collisions_are_redispatched_on_the_next_five_second_pass(): void
    {
        $grant = $this->arm('T1a', 'LOCAL-3');
        app(CampaignDirector::class)->tickArms();
        $episode = CampaignEpisode::where('campaign_grant_id', $grant->id)->firstOrFail();
        $run = AutomationRun::where('campaign_episode_id', $episode->id)->whereNotNull('active_agent_id')->firstOrFail();
        $action = ShipAction::create(['agent_id' => $this->agent->id, 'automation_run_id' => $run->id, 'ship_symbol' => 'LOCAL-3',
            'key' => 'collision', 'reset_id' => $this->agent->reset_id, 'command' => 'dock', 'state' => 'pending', 'origin' => 'automation',
            'expires_at' => now()->addMinute(), 'arguments' => [], 'last_dispatched_at' => now()]);
        app(HostLock::class)->run('agent-'.$this->agent->id, function () use ($run, $action) {
            (new AdvanceAutomationRun($run->id))->handle(app(AutomationRunner::class));
            (new ExecuteShipCommand($action->id))->handle(app(ShipCommandExecutor::class));
        });
        $this->assertNull($run->fresh()->last_dispatched_at);
        $this->assertNull($action->fresh()->last_dispatched_at);
        $this->travel(5)->seconds();
        $this->artisan('spacetraders:dispatch-due')->assertSuccessful();
        Queue::assertPushed(AdvanceAutomationRun::class, 2);
        Queue::assertPushed(ExecuteShipCommand::class, 1);
    }

    private function body(int $exposure = 100): string
    {
        return str_replace(['GIANTSOUP', 'max_credits: 60000'], ['LOCAL', 'max_credits: '.$exposure], file_get_contents(base_path('campaigns/weekly-default.yaml')));
    }

    private function armBody(int $exposure = 100): string
    {
        return str_replace(['GIANTSOUP', 'max_credits: 60000'], ['LOCAL', 'max_credits: '.$exposure], file_get_contents(base_path('campaigns/arm-trade.yaml')));
    }

    /** C0 as CampaignAutopilotTest::tradeGrant sets it up: one earn tick starts its trade on LOCAL-1. */
    private function c0(): CampaignGrant
    {
        $grant = app(CampaignControl::class)->approve(app(CampaignPlaybook::class)->import($this->body()), $this->user);
        $this->tick();
        CampaignEpisode::where('campaign_grant_id', $grant->id)->firstOrFail()->update(['phase' => 'earn']);
        $this->tick();

        return $grant;
    }

    private function arm(string $label, string $ship, ?CarbonImmutable $expires = null, ?string $follow = null): CampaignGrant
    {
        return app(CampaignControl::class)->approveArm(app(CampaignPlaybook::class)->import($this->armBody()), $this->user,
            ['label' => $label, 'ship' => $ship, 'follow' => $follow], $expires ?? CarbonImmutable::now()->addHours(12));
    }

    private function tick(): void
    {
        app(CampaignDirector::class)->tick();
        $this->travel(31)->seconds();
        if (LocalEngine::find(1)?->state === 'running') {
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        } else {
            EngineFixture::healthy();
        }
    }

    private function editWorld(callable $edit): void
    {
        $world = json_decode(file_get_contents($this->file), true);
        $edit($world);
        file_put_contents($this->file, json_encode($world));
    }
}
