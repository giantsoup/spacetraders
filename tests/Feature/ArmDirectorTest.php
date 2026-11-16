<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignFleet;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\Engine\EngineHost;
use App\Gameplay\GameException;
use App\Gameplay\SpaceTradersApi;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\LocalEngine;
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
