<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\GameException;
use App\Models\AutomationRun;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\LocalEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\FakeSpaceTraders;
use Tests\Support\SaturationFixture;
use Tests\TestCase;

class CampaignAutoArmTest extends TestCase
{
    use RefreshDatabase;
    use SaturationFixture;

    protected function tearDown(): void
    {
        $this->tearDownSaturation();
        parent::tearDown();
    }

    public function test_pinned_revision_must_exist_before_any_approval_write(): void
    {
        $this->setUpSaturation();
        $body = $this->revision->body;
        $body['fleet']['auto_arm']['revision_hash'] = str_repeat('1', 64);
        $bad = app(CampaignPlaybook::class)->import(Yaml::dump($body, 12));
        try {
            $this->primary(revision: $bad);
            $this->fail('Missing pin granted purchase authority.');
        } catch (GameException $expected) {
            $this->assertStringContainsString('Import the pinned auto-arm revision', $expected->getMessage());
            $this->assertDatabaseCount('campaign_grants', 0);
            $this->assertDatabaseCount('campaign_events', 0);
        }
    }

    public function test_paused_legacy_grants_without_expiry_reserve_ship_and_noncontiguous_labels(): void
    {
        $this->setUpSaturation();
        $this->addHaulers('LOCAL-9', 'LOCAL-A', 'LOCAL-B', 'LOCAL-C');
        $episode = $this->primary();
        $old = $this->unresolved('H1', 'LOCAL-9');
        $this->unresolved('H3', 'LOCAL-C');
        foreach ([['LOCAL-9', 'HA'], ['LOCAL-A', 'H1']] as [$ship, $label]) {
            try {
                $this->arm($ship, $label);
                $this->fail('Expected an unresolved legacy reservation.');
            } catch (GameException $exception) {
                $this->assertStringContainsString('already', $exception->getMessage());
            }
        }
        $this->editFixture(fn (&$world) => $world['next_reset'] = now()->addDays(4)->toIso8601String());
        $this->tickC0();
        $this->tickC0();
        $new = CampaignGrant::where('limits->arm->automatic', true)->orderBy('id')->get();
        $this->assertSame(['H2', 'H4'], $new->map(fn ($grant) => $grant->arm()['label'])->all());
        $this->assertSame(['LOCAL-A', 'LOCAL-B'], $new->map(fn ($grant) => $grant->arm()['ship'])->all());
        $this->assertSame(2, CampaignEvent::where('kind', 'auto_arm_conflict')->count());
        $this->assertSame(2, CampaignEvent::where('kind', 'auto_arm')->count());
        $this->assertSame($this->agent->reset_id, $new->first()->arm()['reset_id']);
        $this->assertSame(0.5, $new->first()->arm()['exposure_share']);

        $this->seedArmEpisode($old)->update(['reset_id' => '2026-10-18', 'closed_at' => now()->subDays(3)]);
        $manual = $this->arm('LOCAL-9', 'H1');
        $this->assertSame('H1', $manual->arm()['label']);
        $this->artisan('campaign:arm-pause', ['--grant' => $manual->id])->assertSuccessful();
        $this->assertSame('operator_pause', $manual->fresh()->pause_reason);
        $this->artisan('campaign:arm-resume', ['--grant' => $manual->id])->assertSuccessful();
        $this->assertNull($manual->fresh()->paused_at);
        $this->artisan('campaign:arm-revoke', ['--grant' => $manual->id])->assertSuccessful();
        $this->assertNotNull($manual->fresh()->revoked_at);
        $this->assertNull($old->fresh()->revoked_at);
    }

    public function test_expired_same_reset_arm_remains_listed_and_stopping_run_prevents_double_start(): void
    {
        $this->setUpSaturation();
        $this->addHaulers('LOCAL-9');
        $old = $this->arm('LOCAL-9', 'H1', 1);
        $episode = $this->seedArmEpisode($old);
        $run = AutomationRun::create(['agent_id' => $this->agent->id, 'active_agent_id' => $this->agent->id,
            'campaign_episode_id' => $episode->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-9',
            'strategy' => 'trade', 'state' => 'stopping', 'started_at' => now(), 'stop_requested_at' => now(),
            'expires_at' => now()->addMinute(), 'plan' => [], 'totals' => []]);
        $this->travel(2)->minutes();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        $replacement = $this->arm('LOCAL-9', 'H1');
        app(CampaignDirector::class)->tickArmsBatch(fn ($grant, $direct) => $direct());
        $this->assertSame([$run->id], AutomationRun::whereNotNull('active_agent_id')->pluck('id')->all());
        $this->assertNotNull($replacement->fresh()->last_directed_at);
        Artisan::call('campaign:arms');
        $rows = collect(json_decode(Artisan::output(), true))->keyBy('grant');
        $this->assertTrue($rows->get($old->id)['expired']);
        $this->assertFalse($rows->get($replacement->id)['expired']);
        $this->assertSame('stopping', $rows->get($old->id)['active_run']['state']);
    }

    private function addHaulers(string ...$symbols): void
    {
        $this->editFixture(function (&$world) use ($symbols) {
            foreach ($symbols as $symbol) {
                $world['ships'][] = FakeSpaceTraders::lightHauler($symbol, 'X1-TEST-ROCK');
            }
        });
        app(AgentSynchronizer::class)->sync($this->agent, false);
    }

    private function unresolved(string $label, string $ship): CampaignGrant
    {
        $grant = CampaignGrant::create(['campaign_revision_id' => $this->armRevision->id, 'revision_hash' => $this->armRevision->content_hash,
            'agent_symbol' => 'LOCAL', 'user_id' => $this->operator->id, 'slot' => null, 'paused_at' => now(), 'pause_reason' => 'operator_pause',
            'limits' => $this->armRevision->body['limits'] + ['arm' => ['label' => $label, 'ship' => $ship]]]);
        DB::table('game_runtime')->where('key', 'like', 'reset_schedule:%')->delete();
        DB::table('campaign_grants')->where('id', $grant->id)->update(['created_at' => $this->agent->reset_id.' 00:30:00']);

        return $grant->refresh();
    }
}
