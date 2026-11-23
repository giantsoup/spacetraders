<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\Engine\EngineHost;
use App\Gameplay\RequestDeferred;
use App\Gameplay\SpaceTradersApi;
use App\Jobs\AdvanceAutomationRun;
use App\Jobs\ExecuteShipCommand;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\ShipAction;
use App\Models\User;
use App\Services\SpaceTradersClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\EngineFixture;
use Tests\Support\FakeEngineHost;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class SchedulerThroughputTest extends TestCase
{
    use RefreshDatabase;

    private Agent $agent;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config(['gameplay.enabled' => true, 'gameplay.local_engine_enabled' => true]);
        $this->app->instance(EngineHost::class, new FakeEngineHost);
        $this->file = tempnam(sys_get_temp_dir(), 'scheduler-');
        $world = FakeSpaceTraders::world();
        $world['next_reset'] = now()->addDays(4)->toIso8601String();
        $world['ships'][0]['registration']['role'] = 'COMMAND';
        foreach (['LOCAL-2', 'LOCAL-3', 'LOCAL-4'] as $symbol) {
            $world['ships'][] = FakeSpaceTraders::ship($symbol);
        }
        file_put_contents($this->file, json_encode($world, JSON_THROW_ON_ERROR));
        FakeSpaceTraders::install($this->file);
        $this->agent = User::factory()->create()->agents()->create([
            'name' => 'LOCAL', 'token' => 'synthetic-only', 'faction' => 'VOID',
            'reset_id' => $world['reset'], 'canonical_identity' => AgentSynchronizer::identity($world['reset'], 'LOCAL'),
        ]);
        app(AgentSynchronizer::class)->sync($this->agent);
        EngineFixture::healthy();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        parent::tearDown();
    }

    public function test_dispatch_sweeps_wrap_both_turns_and_combine_lag_samples_without_duplicates(): void
    {
        $pre = $this->seedRun('LOCAL-1', 8);
        $action = ShipAction::create([
            'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-1',
            'key' => 'before-director', 'origin' => 'manual', 'arguments' => [], 'state' => 'pending', 'command' => 'dock', 'expires_at' => now()->addMinute(),
        ]);
        $order = [];
        $director = $this->partialMock(CampaignDirector::class);
        $director->shouldReceive('tick')->twice()->andReturnUsing(function () use (&$order, $pre, $action) {
            Queue::assertPushed(AdvanceAutomationRun::class);
            Queue::assertPushed(ExecuteShipCommand::class);
            $this->assertNotNull($pre->fresh()->last_dispatched_at);
            $this->assertNotNull($action->fresh()->last_dispatched_at);
            if ($order === []) {
                $this->seedRun('LOCAL-2', 2);
            }
            $order[] = 'director';

            return true;
        });
        $director->shouldReceive('tickArmsBatch')->twice()->andReturnUsing(function () use (&$order) {
            $order[] = 'arms';

            return 2;
        });

        $this->artisan('spacetraders:dispatch-due')->assertSuccessful();
        $data = json_decode(DB::table('fleet_metrics')->where('kind', 'scheduler')->sole()->data, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([8, 2], $data['dispatch_lags']);
        $this->assertSame(5, $data['dispatch_lag']);
        $this->assertSame(1, $data['pre_dispatched']);
        $this->assertSame(2, $data['dispatched']);
        $this->assertSame(2, $data['arms_directed']);
        $this->artisan('spacetraders:dispatch-due')->assertSuccessful();
        $this->assertSame(['director', 'arms', 'arms', 'director'], $order);
        Queue::assertPushed(AdvanceAutomationRun::class, 2);
        Queue::assertPushed(ExecuteShipCommand::class, 1);
    }

    public function test_arm_batch_prioritizes_pending_pause_then_idle_before_revisiting_healthy_work(): void
    {
        $healthy = $this->arm('H1', 'LOCAL-2');
        $idle = $this->arm('H2', 'LOCAL-3');
        $urgent = $this->arm('H3', 'LOCAL-4');
        $healthy->update(['last_directed_at' => now()->subSeconds(299)]);
        $urgent->update(['last_directed_at' => now()->subSeconds(45)]);
        $this->seedRun('LOCAL-2', 0, $this->episode($healthy)->id);
        $this->seedRun('LOCAL-4', 0, $this->episode($urgent, ['pending_pause' => 'consecutive_losing_loads'])->id);
        config(['gameplay.arm_pass_max_arms' => 2]);
        $seen = [];
        $runner = function (CampaignGrant $grant, callable $direct, float $seconds) use (&$seen) {
            $seen[] = $grant->id;
            $this->assertGreaterThan(5, $seconds);
            $this->assertLessThanOrEqual(20, $seconds);
        };

        $this->assertSame(2, app(CampaignDirector::class)->tickArmsBatch($runner));
        $this->assertSame([$idle->id, $urgent->id], $seen);
        $seen = [];
        $this->travel(1)->seconds();
        config(['gameplay.arm_pass_max_arms' => 3]);
        $this->assertSame(3, app(CampaignDirector::class)->tickArmsBatch($runner));
        $this->assertSame([$idle->id, $urgent->id, $healthy->id], $seen);
    }

    public function test_arm_batch_keeps_failures_per_grant_and_continues_siblings(): void
    {
        $bad = $this->arm('H1', 'LOCAL-2');
        $good = $this->arm('H2', 'LOCAL-3');
        DB::table('game_runtime')->insert(['key' => 'arm_director_failures:'.$good->id, 'value' => '3']);
        $api = \Mockery::mock(SpaceTradersApi::class, [app(SpaceTradersClient::class)])->makePartial();
        $api->shouldReceive('agent')->twice()->andReturnUsing(static function () {
            static $reads = 0;
            if ($reads++ === 0) {
                throw new \TypeError('synthetic failure');
            }

            return ['credits' => 100000, 'shipCount' => 4];
        });
        $this->app->instance(SpaceTradersApi::class, $api);

        $this->artisan('spacetraders:dispatch-due')->assertSuccessful();

        $this->assertSame('1', DB::table('game_runtime')->where('key', 'arm_director_failures:'.$bad->id)->value('value'));
        $this->assertSame('0', DB::table('game_runtime')->where('key', 'arm_director_failures:'.$good->id)->value('value'));
        $this->assertNotNull($good->fresh()->last_directed_at);
    }

    public function test_both_arm_turns_skip_a_prior_reset_without_stamp_or_new_episode(): void
    {
        $grant = $this->arm('H1', 'LOCAL-2');
        $this->episode($grant);
        CampaignEpisode::where('campaign_grant_id', $grant->id)->update([
            'reset_id' => '2026-10-18', 'closed_at' => now()->subDay(),
        ]);

        $director = app(CampaignDirector::class);
        $this->assertFalse($director->tickArms());
        $this->assertSame(0, $director->tickArmsBatch(fn () => $this->fail('Prior-reset arm was directed.')));
        $this->assertNull($grant->fresh()->last_directed_at);
        $this->assertSame('reset_mismatch', $grant->fresh()->pause_reason);
        $this->assertSame(1, CampaignEpisode::where('campaign_grant_id', $grant->id)->count());
        $this->assertSame(1, CampaignEvent::where('campaign_grant_id', $grant->id)->where('kind', 'pause')->count());
    }

    public function test_arm_batch_stops_selecting_after_the_deadline_and_passes_the_minimum_scope(): void
    {
        $first = $this->arm('H1', 'LOCAL-2');
        $this->arm('H2', 'LOCAL-3');
        config(['gameplay.arm_pass_seconds' => 1]);
        $seen = [];
        $count = app(CampaignDirector::class)->tickArmsBatch(function (CampaignGrant $grant, callable $direct, float $seconds) use (&$seen) {
            $seen[] = $grant->id;
            $this->assertSame(5.0, $seconds);
            usleep(1020000);
        });
        $this->assertSame(1, $count);
        $this->assertSame([$first->id], $seen);
    }

    public function test_schedule_deferral_still_pauses_expired_grants_without_directing_live_siblings(): void
    {
        $expired = $this->arm('H1', 'LOCAL-2');
        $dormant = $this->arm('H2', 'LOCAL-3');
        DB::table('campaign_grants')->where('id', $expired->id)->update(['expires_at' => now()->subMinute()]);
        $this->partialMock(SpaceTradersApi::class)->shouldReceive('resetSchedule')->once()->andThrow(new RequestDeferred('status deferred', 5));
        $this->assertSame(0, app(CampaignDirector::class)->tickArmsBatch(fn () => $this->fail('An arm was directed without reset evidence.')));
        $this->assertSame('grant_expired', $expired->fresh()->pause_reason);
        $this->assertNull($dormant->fresh()->last_directed_at);
        $this->assertNull($dormant->fresh()->paused_at);
    }

    private function arm(string $label, string $ship): CampaignGrant
    {
        $yaml = str_replace('GIANTSOUP', 'LOCAL', file_get_contents(base_path('campaigns/arm-trade.yaml')));
        $revision = app(CampaignPlaybook::class)->import($yaml);

        return app(CampaignControl::class)->approveArm($revision, $this->agent->user, ['label' => $label, 'ship' => $ship], now()->toImmutable()->addDays(2));
    }

    private function episode(CampaignGrant $grant, array $state = []): CampaignEpisode
    {
        return CampaignEpisode::create([
            'agent_id' => $this->agent->id, 'campaign_grant_id' => $grant->id, 'reset_id' => $this->agent->reset_id,
            'start_credits' => 100000, 'phase' => 'earn', 'opened_at' => now(), 'next_reset_at' => now()->addDays(4), 'state' => $state,
        ]);
    }

    private function seedRun(string $ship, int $lag, ?int $episode = null): AutomationRun
    {
        return AutomationRun::create([
            'agent_id' => $this->agent->id, 'active_agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id,
            'campaign_episode_id' => $episode, 'ship_symbol' => $ship, 'state' => 'running', 'strategy' => 'trade',
            'started_at' => now(), 'expires_at' => now()->addHour(), 'next_action_at' => now()->subSeconds($lag), 'plan' => [], 'totals' => [],
        ]);
    }
}
