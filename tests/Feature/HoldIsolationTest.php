<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\CampaignSafety;
use App\Gameplay\Engine\EngineHost;
use App\Gameplay\GameException;
use App\Gameplay\ShipCommandExecutor;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\LocalEngine;
use App\Models\ShipAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\EngineFixture;
use Tests\Support\FakeEngineHost;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class HoldIsolationTest extends TestCase
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
        $this->file = tempnam(sys_get_temp_dir(), 'st-hold-');
        $world = FakeSpaceTraders::world();
        $world['next_reset'] = now()->addHours(17)->toIso8601String();
        $world['ships'][0]['registration']['role'] = 'COMMAND';
        $probe = FakeSpaceTraders::ship('LOCAL-2', 'X1-TEST-PORT');
        $probe['registration']['role'] = 'SATELLITE';
        $probe['frame']['symbol'] = 'FRAME_PROBE';
        $world['ships'] = array_values(array_filter($world['ships'], fn ($ship) => $ship['symbol'] !== 'LOCAL-2'));
        $world['ships'][] = $probe;
        // Two arm ships identical to the command ship, so they can run the same trade.
        foreach (['LOCAL-3', 'LOCAL-4'] as $symbol) {
            $world['ships'][] = array_replace($world['ships'][0], ['symbol' => $symbol, 'registration' => ['name' => 'LOCAL', 'factionSymbol' => 'COSMIC', 'role' => 'HAULER']]);
        }
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

    public function test_an_arm_ships_review_hold_leaves_c0_and_another_arm_ticking(): void
    {
        $grant = $this->tradeGrant();
        $c0 = AutomationRun::whereNotNull('active_agent_id')->where('campaign_episode_id', CampaignEpisode::where('campaign_grant_id', $grant->id)->value('id'))->firstOrFail();
        [$armGrant3, $arm3] = $this->arm($grant, 'T1a', 'LOCAL-3');
        [$armGrant4, $arm4] = $this->arm($grant, 'T1b', 'LOCAL-4');
        $held = $this->armRun($c0, $arm3, 'LOCAL-3');
        // Runs off the arm episode until CampaignGrant::available() admits arm grants (W1); the arm's safety is asserted below.
        $other = $this->armRun($c0, $arm4, 'LOCAL-4', campaign: false);
        $this->review('LOCAL-3', $held);

        foreach ([$c0, $other] as $run) {
            app(AutomationRunner::class)->advance($run->id);
            $this->assertNotSame('blocked', $run->fresh()->state->value, (string) $run->fresh()->blocked_reason);
            $action = ShipAction::where('automation_run_id', $run->id)->where('state', 'pending')->firstOrFail();
            app(ShipCommandExecutor::class)->execute($action->id);
            $this->assertSame('confirmed', $action->fresh()->state->value, (string) $action->fresh()->message);
        }
        app(AutomationRunner::class)->advance($held->id);
        $this->assertSame('blocked', $held->fresh()->state->value);

        $safety = app(CampaignSafety::class);
        $this->assertNull($safety->reason($grant->fresh(), CampaignEpisode::where('campaign_grant_id', $grant->id)->firstOrFail(), $this->agent->fresh()));
        $this->assertNull($safety->reason($armGrant4, $arm4, $this->agent->fresh()));
        $this->assertSame('uncertain_action', $safety->reason($armGrant3, $arm3, $this->agent->fresh()));

        // Other ships' commands are not held by the review; the held ship and manual commands are.
        $executor = app(ShipCommandExecutor::class);
        $executor->clear($this->agent->fresh(), defer: true, ship: 'LOCAL-1');
        $executor->clear($this->agent->fresh(), defer: true, ship: 'LOCAL-4');
        foreach ([fn () => $executor->clear($this->agent->fresh(), defer: true, ship: 'LOCAL-3'), fn () => $executor->clear($this->agent->fresh())] as $call) {
            try {
                $call();
                $this->fail('Review must hold its own ship and manual commands.');
            } catch (GameException $e) {
                $this->assertStringContainsString('requires review', $e->getMessage());
            }
        }

        AutomationRun::whereKey($c0->id)->update(['active_agent_id' => null, 'state' => 'stopped']);
        $this->tick();
        $this->assertNull($grant->fresh()->paused_at);
        $this->assertNotNull(AutomationRun::where('campaign_episode_id', $c0->campaign_episode_id)->whereNotNull('active_agent_id')->first());
    }

    public function test_a_shipless_review_still_holds_every_ship(): void
    {
        $grant = $this->tradeGrant();
        $c0 = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        ShipAction::create(['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => '', 'key' => 'contract-review',
            'origin' => 'manual', 'command' => 'contract_accept', 'state' => 'confirmed', 'review_required' => true, 'arguments' => [], 'expires_at' => now()->addMinutes(3)]);
        app(AutomationRunner::class)->advance($c0->id);
        $this->assertSame('blocked', $c0->fresh()->state->value);
        $this->assertSame('uncertain_action', app(CampaignSafety::class)->reason($grant->fresh(), CampaignEpisode::firstOrFail(), $this->agent->fresh()));
    }

    public function test_uncertain_action_on_an_arm_ship_still_blocks_everyone(): void
    {
        $grant = $this->tradeGrant();
        $c0 = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        [$armGrant, $arm] = $this->arm($grant, 'T1b', 'LOCAL-4');
        $armRun = $this->armRun($c0, $arm, 'LOCAL-4');
        ShipAction::create(['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-3', 'key' => 'arm-uncertain',
            'origin' => 'automation', 'command' => 'sell', 'state' => 'uncertain', 'arguments' => [], 'expires_at' => now()->addMinutes(3)]);

        foreach ([$c0, $armRun] as $run) {
            app(AutomationRunner::class)->advance($run->id);
            $this->assertSame('blocked', $run->fresh()->state->value);
        }
        $this->assertSame('uncertain_action', $grant->fresh()->pause_reason);
        $this->assertSame('uncertain_action', $armGrant->fresh()->pause_reason);
        $this->expectException(GameException::class);
        app(ShipCommandExecutor::class)->clear($this->agent->fresh(), defer: true, ship: 'LOCAL-1');
    }

    public function test_campaign_is_not_paused_by_another_ships_review(): void
    {
        $grant = $this->tradeGrant();
        AutomationRun::whereNotNull('active_agent_id')->update(['active_agent_id' => null, 'state' => 'stopped']);
        $this->review('LOCAL-3');
        $this->tick();
        $this->assertNull($grant->fresh()->paused_at);
        $this->assertNotNull(AutomationRun::whereNotNull('active_agent_id')->where('ship_symbol', 'LOCAL-1')->first());
        $this->review('LOCAL-1');
        $this->tick();
        $this->assertSame('uncertain_action', $grant->fresh()->pause_reason);
    }

    public function test_arm_loss_stop_pauses_only_past_the_arms_net_loss(): void
    {
        $grant = $this->tradeGrant();
        [$armGrant, $arm] = $this->arm($grant, 'T1b', 'LOCAL-4', 50000);
        $safety = app(CampaignSafety::class);
        $this->loadResult($arm, 20000);
        $this->loadResult($arm, -45000);
        $this->assertNull($safety->reason($armGrant, $arm, $this->agent->fresh()));
        $this->loadResult($arm, 30000);
        $this->loadResult($arm, -56000);
        $this->assertSame('arm_loss_stop', $safety->reason($armGrant, $arm, $this->agent->fresh()));
        // C0 has no arm limits and its own episode is unaffected.
        $this->assertNull($safety->reason($grant->fresh(), CampaignEpisode::where('campaign_grant_id', $grant->id)->firstOrFail(), $this->agent->fresh()));
    }

    public function test_two_interleaved_runs_on_different_ships_never_trip_the_outside_credit_guard(): void
    {
        $grant = $this->tradeGrant();
        $c0 = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        [, $arm] = $this->arm($grant, 'T1b', 'LOCAL-4');
        $armRun = $this->armRun($c0, $arm, 'LOCAL-4', campaign: false);
        $runs = [$c0, $armRun];
        for ($step = 0; $step < 120 && collect($runs)->contains(fn ($run) => $run->fresh()->active_agent_id); $step++) {
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
            // Each run advances while the other's confirmed buys and sells change the shared balance.
            foreach ($runs as $run) {
                app(AutomationRunner::class)->advance($run->id);
                foreach (ShipAction::where('automation_run_id', $run->id)->where('state', 'pending')->get() as $action) {
                    app(ShipCommandExecutor::class)->execute($action->id);
                }
            }
            $this->travel(6)->seconds();
        }
        foreach ($runs as $run) {
            $run = $run->fresh();
            $this->assertStringNotContainsString('credits changed outside', (string) $run->blocked_reason);
            $this->assertSame('completed', $run->state->value, (string) $run->blocked_reason);
            $this->assertContains('sell', ShipAction::where('automation_run_id', $run->id)->where('state', 'confirmed')->pluck('command')->all());
        }
        $this->assertSame($c0->fresh()->totals['credit_delta'], $armRun->fresh()->totals['other_credit_delta']);
    }

    private function tradeGrant(): CampaignGrant
    {
        $body = str_replace('max_credits: 60000', 'max_credits: 100', str_replace('GIANTSOUP', 'LOCAL', file_get_contents(base_path('campaigns/weekly-default.yaml'))));
        $grant = app(CampaignControl::class)->approve(app(CampaignPlaybook::class)->import($body), $this->user);
        $this->tick();
        CampaignEpisode::firstOrFail()->update(['phase' => 'earn']);
        $this->tick();

        return $grant;
    }

    /** An arm grant: slot null, limits['arm'] set, with its own episode commanding one ship. */
    private function arm(CampaignGrant $c0, string $label, string $ship, int $maxLoss = 50000): array
    {
        $grant = $c0->replicate();
        $grant->fill(['slot' => null, 'paused_at' => null, 'pause_reason' => null, 'wait_reason' => null, 'last_directed_at' => null,
            'expires_at' => now()->addHours(12), 'limits' => $c0->limits + ['arm' => ['label' => $label, 'ship' => $ship, 'follow' => null,
                'max_net_loss' => $maxLoss, 'max_load_spend' => null, 'exposure_share' => 0.5]]])->save();
        $c0Episode = CampaignEpisode::where('campaign_grant_id', $c0->id)->firstOrFail();
        $episode = CampaignEpisode::create(['campaign_grant_id' => $grant->id, 'agent_id' => $this->agent->id, 'reset_id' => $c0Episode->reset_id,
            'start_credits' => $this->agent->fresh()->credits, 'phase' => 'earn', 'state' => ['role_ships' => ['command' => $ship]],
            'next_reset_at' => $c0Episode->next_reset_at, 'opened_at' => now()]);

        return [$grant, $episode];
    }

    /** A copy of C0's freshly started trade run on an identical ship, started at the same balance. */
    private function armRun(AutomationRun $c0, CampaignEpisode $episode, string $ship, bool $campaign = true): AutomationRun
    {
        $run = $c0->replicate();
        $run->fill(['campaign_episode_id' => $campaign ? $episode->id : null, 'ship_symbol' => $ship,
            'plan' => array_replace($c0->plan, ['participating_ships' => [$ship]])])->save();

        return $run;
    }

    private function review(string $ship, ?AutomationRun $run = null): ShipAction
    {
        return ShipAction::create(['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => $ship, 'key' => 'review-'.$ship,
            'automation_run_id' => $run?->id, 'origin' => $run ? 'automation' : 'manual', 'command' => 'sell', 'state' => 'confirmed',
            'review_required' => true, 'arguments' => [], 'outcome' => ['credit_delta' => 0], 'expires_at' => now()->addMinutes(3)]);
    }

    private function loadResult(CampaignEpisode $episode, int $net): void
    {
        static $id = 1000;
        CampaignEvent::create(['campaign_grant_id' => $episode->campaign_grant_id, 'campaign_episode_id' => $episode->id, 'kind' => 'load_result',
            'data' => ['run_id' => $id++, 'net' => $net, 'finished_at' => now()->addSeconds($id)->toIso8601String()]]);
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
}
