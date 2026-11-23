<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\CampaignProbeBootstrap;
use App\Gameplay\GameException;
use App\Models\AutomationRun;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\LocalEngine;
use App\Models\Ship;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $this->tickC0();
        $reused = CampaignGrant::where('limits->arm->automatic', true)->where('limits->arm->ship', 'LOCAL-9')->sole();
        $this->assertSame('H5', $reused->arm()['label']);
        $this->assertSame($this->agent->reset_id, $reused->arm()['reset_id']);
        $this->assertDatabaseHas('campaign_events', ['kind' => 'auto_arm', 'data->grant_id' => $reused->id]);
    }

    public function test_arms_all_resets_shows_expired_and_prior_grants_and_stopping_run_prevents_double_start(): void
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
        $prior = $this->unresolved('H2', 'LOCAL-A', reset: now()->subWeek()->toDateString(), expires: now()->subMinute());
        $priorLive = $this->unresolved('H3', 'LOCAL-B', reset: now()->subWeek()->toDateString(), expires: now()->addHour());
        $calls = $this->world()['calls'];
        $this->assertSame(0, Artisan::call('campaign:arms'));
        $rows = array_column(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR), null, 'grant');
        $this->assertSame([$replacement->id], array_keys($rows));
        $this->assertSame($this->agent->reset_id, $rows[$replacement->id]['reset_id']);
        $this->assertTrue($rows[$replacement->id]['active']);

        $this->assertSame(0, Artisan::call('campaign:arms', ['--all-resets' => true]));
        $all = array_column(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR), null, 'grant');
        $this->assertSame([$old->id, $replacement->id, $prior->id, $priorLive->id], array_keys($all));
        $this->assertTrue($all[$old->id]['expired']);
        $this->assertSame($old->expires_at->toIso8601String(), $all[$old->id]['expires_at']);
        $this->assertSame('stopping', $all[$old->id]['active_run']['state']);
        $this->assertSame($prior->arm()['reset_id'], $all[$prior->id]['reset_id']);
        $this->assertTrue($all[$prior->id]['expired']);
        $this->assertFalse($all[$priorLive->id]['expired']);
        $this->assertFalse($all[$priorLive->id]['active']);
        $this->assertSame($calls, $this->world()['calls']);
    }

    public function test_one_c0_scan_auto_arms_multiple_deliveries_under_distinct_labels(): void
    {
        $this->setUpSaturation(hubs: 2);
        $episode = $this->primary();
        $first = $this->haulerPurchase($episode, 'first-delivery', 'confirmed', ['delivered_ship' => 'LOCAL-9']);
        $second = $this->haulerPurchase($episode, 'second-delivery', 'confirmed', ['delivered_ship' => 'LOCAL-A']);
        $this->addHaulers('LOCAL-9', 'LOCAL-A');

        $this->tickC0();
        $events = CampaignEvent::where('kind', 'auto_arm')->orderBy('id')->pluck('data')->all();
        $this->assertSame(['LOCAL-9', 'LOCAL-A'], array_column($events, 'ship'));
        $this->assertSame(['H1', 'H2'], array_column($events, 'label'));
        $this->assertSame([$first->id, $second->id], array_column($events, 'purchase_action_id'));
        $grants = CampaignGrant::whereIn('id', array_column($events, 'grant_id'))->orderBy('id')->get();
        $this->assertSame(['H1', 'H2'], $grants->map(fn (CampaignGrant $grant) => $grant->arm()['label'])->all());
        $this->tickC0();
        $this->assertSame(2, CampaignEvent::where('kind', 'auto_arm')->count());
    }

    public function test_c0_holds_plausible_hauler_through_sync_and_movement_until_attributed(): void
    {
        $this->setUpSaturation(hubs: 2);
        $episode = $this->primary();
        $ack = $this->haulerPurchase($episode, 'delayed-c0-delivery');
        $this->addHaulers('LOCAL-9');
        $bootstrap = app(CampaignProbeBootstrap::class);

        $this->tickC0();
        $this->assertSame(['LOCAL-9'], $ack->fresh()->outcome['hauler_delivery_candidates']);
        $this->assertTrue($bootstrap->plausibleUnattributed($this->agent, Ship::where('symbol', 'LOCAL-9')->sole()->snapshot));
        $this->assertSame([$ack->id], $bootstrap->reservations($this->agent)->pluck('action_id')->all());
        $this->assertDatabaseMissing('campaign_grants', ['limits->arm->ship' => 'LOCAL-9']);
        $this->editFixture(function (&$world) {
            foreach ($world['ships'] as &$ship) {
                if ($ship['symbol'] === 'LOCAL-9') {
                    $ship['nav']['waypointSymbol'] = 'X1-TEST-PORT';
                }
            }
        });
        app(AgentSynchronizer::class)->sync($this->agent, false);
        $this->tickC0();
        $moved = Ship::where('symbol', 'LOCAL-9')->sole()->snapshot;
        $this->assertSame('X1-TEST-PORT', $moved['nav']['waypointSymbol']);
        $this->assertTrue($bootstrap->plausibleUnattributed($this->agent, $moved));
        $this->assertDatabaseMissing('campaign_grants', ['limits->arm->ship' => 'LOCAL-9']);

        $tx = ['agentSymbol' => 'LOCAL', 'waypointSymbol' => 'X1-TEST-ROCK', 'shipType' => 'SHIP_LIGHT_HAULER',
            'timestamp' => now()->toIso8601String(), 'price' => 350000];
        $this->editFixture(fn (&$world) => $world['transactions'][] = $tx);
        $this->tickC0();
        $this->assertSame('LOCAL-9', $ack->fresh()->outcome['delivered_ship']);
        $this->assertSame($tx, $ack->fresh()->outcome['delivery_transaction']);
        $this->assertFalse($bootstrap->plausibleUnattributed($this->agent, $moved));
        $this->assertCount(0, $bootstrap->reservations($this->agent));
        $event = CampaignEvent::where('kind', 'hauler_purchased')->sole();
        $this->assertSame($ack->id, $event->data['action_id']);
        $arm = CampaignGrant::where('limits->arm->ship', 'LOCAL-9')->sole();
        $this->assertSame($ack->id, $arm->arm()['purchase_action_id']);
    }

    #[DataProvider('armRetirements')]
    public function test_arm_revoke_ack_pacing_targets_exact_current_grant_and_is_idempotent(bool $alreadyRevoked): void
    {
        $this->setUpSaturation(hubs: 2);
        $episode = $this->primary();
        $purchase = $this->haulerPurchase($episode, 'arm-delivery', 'confirmed', ['delivered_ship' => 'LOCAL-9']);
        $this->addHaulers('LOCAL-9');
        $this->tickC0();
        $arm = CampaignGrant::where('limits->arm->purchase_action_id', $purchase->id)->sole();
        $armEpisode = $this->seedArmEpisode($arm);
        $command = 'campaign:arm-revoke';
        $selector = ['--grant' => $arm->id];
        if ($alreadyRevoked) {
            $this->artisan($command, $selector)->assertSuccessful();
        } else {
            app(CampaignControl::class)->pause($arm, 'operator_pause', $armEpisode);
        }
        $revoked = $arm->fresh()->revoked_at?->toIso8601String();
        $this->artisan($command, $selector + ['--ack-pacing' => true])->assertSuccessful();
        $this->travel(1)->minute();
        $this->artisan($command, $selector + ['--ack-pacing' => true])->assertSuccessful();

        $this->assertNotNull($arm->fresh()->revoked_at);
        $this->assertNotNull($armEpisode->fresh()->closed_at);
        if ($alreadyRevoked) {
            $this->assertSame($revoked, $arm->fresh()->revoked_at->toIso8601String());
        }
        $event = CampaignEvent::where('kind', 'hauler_pacing_acknowledged')->sole();
        $this->assertSame([$arm->id, $purchase->id], [$event->data['grant_id'], $event->data['action_id']]);
        $this->assertSame($this->operator->id, $purchase->fresh()->outcome['pacing_acknowledged']['user_id']);
        $this->assertSame(1, CampaignEvent::where('campaign_grant_id', $arm->id)->where('kind', 'revoked')->count());
    }

    public static function armRetirements(): array
    {
        return ['paused arm' => [false], 'already revoked' => [true]];
    }

    public function test_arm_commands_refuse_ambiguous_labels_and_exact_prior_reset_grants(): void
    {
        $this->setUpSaturation(hubs: 2);
        $this->addHaulers('LOCAL-9');
        $local = $this->arm('LOCAL-9', 'H1');
        $other = $this->operator->agents()->create(['name' => 'SECOND', 'token' => 'synthetic-other', 'faction' => 'VOID',
            'reset_id' => $this->agent->reset_id, 'canonical_identity' => AgentSynchronizer::identity($this->agent->reset_id, 'SECOND')]);
        Ship::create(['agent_id' => $other->id, 'symbol' => 'SECOND-9', 'observed_at' => now(), 'snapshot' => FakeSpaceTraders::lightHauler('SECOND-9', 'X1-TEST-ROCK')]);
        $revision = app(CampaignPlaybook::class)->import(str_replace('LOCAL', 'SECOND', $this->armRevision->source));
        $second = app(CampaignControl::class)->approveArm($revision, $this->operator, ['ship' => 'SECOND-9', 'label' => 'H1'], now()->addHour()->toImmutable());
        $prior = $this->unresolved('H1', 'LOCAL-A', reset: now()->subWeek()->toDateString());
        foreach ([
            ['campaign:arm-pause', ['label' => 'H1']],
            ['campaign:arm-revoke', ['--grant' => $prior->id, '--ack-pacing' => true]],
        ] as [$command, $selector]) {
            try {
                Artisan::call($command, $selector);
                $this->fail('Unsafe arm selection was accepted.');
            } catch (GameException $exception) {
                $this->assertStringContainsString('No unique current-reset arm matches', $exception->getMessage());
            }
        }
        $this->assertNull($local->fresh()->paused_at);
        $this->assertNull($second->fresh()->paused_at);
        $this->assertNull($prior->fresh()->revoked_at);
        $this->artisan('campaign:arm-pause', ['--grant' => $second->id])->assertSuccessful();
        $this->assertSame('operator_pause', $second->fresh()->pause_reason);
        $this->assertNull($local->fresh()->paused_at);
    }

    #[DataProvider('autoArmAuthorityHolds')]
    public function test_c0_auto_arm_waits_for_authority_and_pending_handover(string $hold): void
    {
        $this->setUpSaturation(hubs: 2);
        $episode = $this->primary();
        $control = app(CampaignControl::class);
        $this->addHaulers('LOCAL-9');
        $grant = $episode->grant();
        if ($hold === 'paused') {
            $control->pause($grant, 'operator_pause', $episode);
        } else {
            $this->editFixture(fn (&$world) => $world['ships'][0]['cargo'] = [
                'capacity' => 40, 'units' => 5, 'inventory' => [['symbol' => 'IRON_ORE', 'name' => 'Iron', 'description' => 'Ore', 'units' => 5]],
            ]);
            app(AgentSynchronizer::class)->sync($this->agent, false);
            $pending = $control->approve($this->revision, $this->operator);
            $this->assertSame(2, $pending->slot);
        }

        if ($hold === 'paused') {
            $this->assertFalse(app(CampaignDirector::class)->tick());
        } else {
            $this->tickC0();
        }
        $this->assertDatabaseMissing('campaign_grants', ['limits->arm->ship' => 'LOCAL-9']);
        $this->assertDatabaseMissing('campaign_events', ['kind' => 'auto_arm']);
        if ($hold === 'paused') {
            $control->resume($grant);
        } else {
            $recovery = AutomationRun::where('campaign_episode_id', $episode->id)->where('strategy', 'sell_aboard')->sole();
            $this->workRun($recovery);
            $this->assertSame('completed', $recovery->fresh()->state->value, $recovery->fresh()->blocked_reason ?? '');
        }
        $this->tickC0();
        $this->tickC0();
        $event = CampaignEvent::where('kind', 'auto_arm')->sole();
        $this->assertSame('LOCAL-9', $event->data['ship']);
        $this->assertSame(isset($pending) ? $pending->id : $grant->id, $event->campaign_grant_id);
    }

    public static function autoArmAuthorityHolds(): array
    {
        return [['paused'], ['pending handover']];
    }

    public function test_c0_auto_arm_rolls_back_grant_and_approval_if_the_auto_arm_event_fails(): void
    {
        $this->setUpSaturation(hubs: 2);
        $this->primary();
        $this->addHaulers('LOCAL-9');
        DB::unprepared("CREATE TRIGGER reject_auto_arm BEFORE INSERT ON campaign_events WHEN NEW.kind = 'auto_arm' BEGIN SELECT RAISE(ABORT, 'test audit failure'); END");
        try {
            $this->tickC0();
            $this->assertDatabaseMissing('campaign_grants', ['limits->arm->ship' => 'LOCAL-9']);
            $this->assertDatabaseMissing('campaign_events', ['kind' => 'approval', 'data->arm->ship' => 'LOCAL-9']);
            $conflict = CampaignEvent::where('kind', 'auto_arm_conflict')->sole();
            $this->assertSame('LOCAL-9', $conflict->data['ship']);
        } finally {
            DB::unprepared('DROP TRIGGER reject_auto_arm');
        }

        $this->tickC0();
        $arm = CampaignGrant::where('limits->arm->ship', 'LOCAL-9')->sole();
        $this->assertSame('H1', $arm->arm()['label']);
        $this->assertSame($arm->id, CampaignEvent::where('kind', 'auto_arm')->sole()->data['grant_id']);
        $this->assertSame(1, CampaignEvent::where('kind', 'approval')->where('campaign_grant_id', $arm->id)->count());
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

    private function unresolved(string $label, string $ship, ?string $reset = null, ?CarbonInterface $expires = null): CampaignGrant
    {
        $grant = CampaignGrant::create(['campaign_revision_id' => $this->armRevision->id, 'revision_hash' => $this->armRevision->content_hash,
            'agent_symbol' => 'LOCAL', 'user_id' => $this->operator->id, 'slot' => null, 'paused_at' => now(), 'pause_reason' => 'operator_pause',
            'expires_at' => $expires, 'limits' => $this->armRevision->body['limits'] + ['arm' => array_filter(['label' => $label, 'ship' => $ship, 'reset_id' => $reset])]]);
        if ($reset === null) {
            DB::table('game_runtime')->where('key', 'like', 'reset_schedule:%')->delete();
            DB::table('campaign_grants')->where('id', $grant->id)->update(['created_at' => $this->agent->reset_id.' 00:30:00']);
        }

        return $grant->refresh();
    }
}
