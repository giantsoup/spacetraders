<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignHaulerPurchase;
use App\Gameplay\CampaignMesh;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\CampaignProbeBootstrap;
use App\Gameplay\CampaignSafety;
use App\Gameplay\CampaignSaturation;
use App\Gameplay\CampaignStatus;
use App\Gameplay\CampaignTrade;
use App\Gameplay\GameException;
use App\Gameplay\MeshGovernor;
use App\Gameplay\MeshPoller;
use App\Gameplay\OperationPlanner;
use App\Gameplay\ProbeYardSelector;
use App\Gameplay\RequestBudget;
use App\Gameplay\RequestDeferred;
use App\Gameplay\ShipCommandExecutor;
use App\Gameplay\SpaceTradersApi;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignRevision;
use App\Models\GameObservation;
use App\Models\ObservationHistory;
use App\Models\ReconMission;
use App\Models\Ship;
use App\Models\ShipAction;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\FakeSpaceTraders;
use Tests\Support\SaturationFixture;
use Tests\TestCase;

final class ProbeMeshSaturationTest extends TestCase
{
    use RefreshDatabase;
    use SaturationFixture;

    private const YARD = 'X1-TEST-ROCK';

    protected function tearDown(): void
    {
        $this->tearDownSaturation();
        parent::tearDown();
    }

    public function test_candidate_status_uses_candidate_model_with_legacy_purchase_authority_and_zero_writes(): void
    {
        $this->setUpSaturation(hubs: 6);
        $episode = $this->primary();
        $this->market('X1-TEST-H6', tier: 2);
        $this->market('X1-TEST-PROVISIONAL', listing: false);
        $candidate = $this->meshRevision();
        ShipAction::create([
            'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'key' => 'synthetic-outstanding',
            'ship_symbol' => 'LOCAL-1', 'origin' => 'manual', 'command' => 'buy_ship', 'state' => 'pending',
            'arguments' => ['shipType' => 'SHIP_PROBE', 'waypointSymbol' => self::YARD], 'expires_at' => now()->addMinute(),
        ]);
        $active = app(CampaignStatus::class)->saturation($this->operator->id, 'LOCAL');
        $calls = $this->world()['calls'];
        $state = $episode->state;
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = app(CampaignStatus::class)->saturation($this->operator->id, 'LOCAL', $candidate);
        $writes = array_filter(DB::getQueryLog(), fn ($row) => ! preg_match('/^\s*select\b/i', $row['query']));
        DB::disableQueryLog();

        $this->assertSame([], array_values($writes));
        $this->assertSame($calls, $this->world()['calls']);
        $this->assertSame($state, $episode->fresh()->state);
        $this->assertSame(4, $active['mesh']['pool_probe_target']);
        $this->assertSame(7, $result['mesh']['pool_probe_target']);
        $this->assertSame([1 => 6, 2 => 1], $result['mesh']['tiers']);
        $this->assertSame('shared_purchase_in_flight', $result['gates']['G2']['reason']);
        $this->assertTrue($result['active_purchase_authority']);
        $this->assertFalse($result['can_purchase']);
        $this->assertSame($this->revision->content_hash, $episode->grant()->revision_hash);
    }

    #[DataProvider('validationCases')]
    public function test_playbook_saturation_bounds(string $key, mixed $value): void
    {
        $body = $this->meshBody();
        if ($key === 'reads_per_pass') {
            $body['probes']['mesh'][$key] = $value;
        } else {
            $body['probes']['mesh']['saturation'][$key] = $value;
        }
        $this->expectException(GameException::class);
        app(CampaignPlaybook::class)->validate($body);
    }

    public static function validationCases(): array
    {
        return [
            ['reads_per_pass', 9], ['tier2', 1], ['demand_max', 9], ['tier2_read_interval_seconds', 299],
            ['yard_read_interval_seconds', 59], ['purchase_yard_read_interval_seconds', 901],
            ['patrol_quality_pct', 9], ['value_seconds', 900], ['hysteresis_seconds', 3601],
            ['min_dwell_seconds', 3601], ['patrol_quote_min_age_seconds', 299], ['patrol_cooldown_seconds', 599],
            ['yard_recovery_cooldown_seconds', 299], ['travel_credits_per_second', 1001],
            ['free_capital_share_pct', 0], ['proof_fuel_buffer_credits', 100001], ['tier2_price_inflation_cap_pct', 201],
            ['stop_purchases_hours_before_reset', 169], ['governor_headroom_pct', 49], ['unlisted', 1],
        ];
    }

    public function test_saturation_rejects_local_earn_before_it_can_dispatch_plain_scouts(): void
    {
        $body = $this->meshBody();
        $body['trade']['local_earn'] = [
            'projection_limit' => 4, 'max_attempts' => 2, 'position_evidence_max_age_seconds' => 300,
            'max_positionings_without_trade' => 1,
        ];

        $this->expectExceptionMessage('Mesh saturation cannot be combined with local Earn.');
        app(CampaignPlaybook::class)->validate($body);
    }

    public function test_zero_hour_purchase_cutoff_uses_reset_time(): void
    {
        $this->setUpSaturation();
        $episode = $this->primary(revision: $this->meshRevision(['stop_purchases_hours_before_reset' => 0]));
        $grant = $episode->grant();
        $episode->update(['next_reset_at' => now()->addMinutes(30)]);
        $policy = $grant->revision()->body['probes'];

        $this->assertTrue($episode->next_reset_at->equalTo(app(ProbeYardSelector::class)->cutoff($episode, $grant, $policy)));
    }

    public function test_inbound_hauler_does_not_evict_a_parked_probe_or_block_its_yard_purchase(): void
    {
        $this->setUpSaturation(hubs: 5, seedCoverage: false);
        foreach ([self::YARD, 'X1-TEST-PORT', 'X1-TEST-H3', 'X1-TEST-H4', 'X1-TEST-H5'] as $symbol) {
            $this->market($symbol);
        }
        $this->addProbe('LOCAL-3', 'X1-TEST-PORT');
        $this->editFixture(function (&$world) {
            $hauler = FakeSpaceTraders::lightHauler('LOCAL-9', self::YARD);
            $hauler['nav']['status'] = 'IN_TRANSIT';
            $hauler['nav']['route'] = ['origin' => ['symbol' => 'X1-TEST-PORT'], 'destination' => ['symbol' => self::YARD],
                'arrival' => now()->addHour()->toIso8601String()];
            $world['ships'][] = $hauler;
        });
        $episode = $this->primary(revision: $this->meshRevision());
        $this->tickC0();
        $run = AutomationRun::where('strategy', 'buy_probe')->whereNotNull('started_at')->sole();
        $this->assertSame('LOCAL-2', $run->ship_symbol);
        $this->assertSame(self::YARD, $run->plan['shipyard']);
        $this->assertArrayNotHasKey('LOCAL-9', app(CampaignMesh::class)->context($episode, $this->agent)['commitments']);
        $this->assertSame('LOCAL-2', CampaignEvent::where('kind', 'mesh_plan')->latest('id')->firstOrFail()->data['purchase_hold']);
        $this->assertNull(app(CampaignMesh::class)->dispatchPlan($episode, $this->agent, [], [], null, [])['purchase_hold']);
        $this->assertFalse(AutomationRun::where('strategy', 'scout')->where('ship_symbol', 'LOCAL-2')->exists());
        $this->workRun($run);
        $this->assertSame('completed', $run->fresh()->state->value, $run->fresh()->blocked_reason ?? '');
        $this->assertSame(1, $this->purchaseWrites());
        $this->assertSame(self::YARD, $this->probe('LOCAL-2')['nav']['waypointSymbol']);
    }

    #[DataProvider('lostMeshAuthorities')]
    public function test_lost_mesh_allowance_rejects_unsent_intent_and_unblocks_cargo_settlement(string $expired): void
    {
        $this->setUpSaturation(hubs: 5, seedCoverage: false);
        foreach ([self::YARD, 'X1-TEST-PORT', 'X1-TEST-H3', 'X1-TEST-H4', 'X1-TEST-H5'] as $symbol) {
            $this->market($symbol);
        }
        $this->yardPrice(25000, persist: true);
        $episode = $this->primary(revision: $this->meshRevision());
        [$run, $intent] = $this->purchaseIntent($episode);
        $this->assertSame('pending', $intent->state->value);

        $path = config('gameplay.request_budget_path').'/budget.json';
        $original = file_get_contents($path);
        if ($expired === 'allowance') {
            $state = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
            $state['mesh_allowances'][$run->plan['mesh_allowance']]['expires'] = microtime(true) - 1;
            file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
        } else {
            ($expired === 'intent' ? $intent : $run)->update(['expires_at' => now()->subSecond()]);
            unlink($path);
        }

        $this->editFixture(function (&$world) {
            $world['ships'][0]['cargo']['units'] = 2;
            $world['ships'][0]['cargo']['inventory'] = [['symbol' => 'IRON_ORE', 'name' => 'Iron Ore', 'description' => 'Fixture cargo', 'units' => 2]];
        });
        $writes = $this->world()['writes'];
        app(ShipCommandExecutor::class)->execute($intent->id);
        $this->assertSame($writes, $this->world()['writes']);
        $this->assertFalse(app(RequestBudget::class)->hasMesh($run->plan['mesh_allowance'], $this->agent->name, $this->agent->reset_id, $run->id));
        $this->assertSame('rejected', $intent->fresh()->state->value);
        $this->assertSame('pretransport', $intent->fresh()->outcome['local_refusal']['stage']);
        $this->assertNull($intent->fresh()->sent_at);
        $this->assertNull($run->fresh()->active_agent_id);
        $this->assertSame('blocked', $run->fresh()->state->value);

        GameObservation::where('symbol', 'X1-TEST-PORT')->whereIn('kind', ['market', 'market_listing'])->delete();
        $this->tickC0();
        $recovery = AutomationRun::where('strategy', 'sell_aboard')->whereNotNull('started_at')->sole();
        $this->workRun($recovery);
        $this->assertSame('completed', $recovery->fresh()->state->value, $recovery->fresh()->blocked_reason ?? '');
        $this->assertCount(1, collect($this->world()['writes'])->where('path', '/my/ships/LOCAL-1/sell'));
    }

    public static function lostMeshAuthorities(): array
    {
        return [['allowance'], ['intent'], ['run']];
    }

    public function test_operator_stationing_after_hauler_purchase_queues_refuses_transport(): void
    {
        $this->setUpSaturation(hubs: 2);
        $episode = $this->primary(revision: $this->meshRevision());
        $this->schedulerPass();
        $runner = app(AutomationRunner::class);
        $run = $runner->previewHaulerPurchase($this->agent, 'LOCAL-2', self::YARD, ['minutes' => 15], $episode);
        $runner->startCampaign($this->agent, $run, $episode);
        $runner->advance($run->id);
        $intent = ShipAction::where('automation_run_id', $run->id)->where('state', 'pending')->sole();
        $this->assertSame('buy_ship', $intent->command);
        $this->assertArrayNotHasKey('mesh_work', $run->plan);

        $this->duringWrite(function () use ($intent) {
            $this->assertSame('sending', $intent->fresh()->state->value);
            Ship::where('symbol', 'LOCAL-2')->update(['stationed_waypoint' => self::YARD]);
        });
        app(ShipCommandExecutor::class)->execute($intent->id);

        $this->assertSame([], $this->world()['writes']);
        $this->assertSame('rejected', $intent->fresh()->state->value);
        $this->assertSame('pretransport', $intent->fresh()->outcome['local_refusal']['stage']);
        $this->assertNull($intent->fresh()->sent_at);
        $this->assertStringContainsString('operator stationed', $intent->fresh()->message);
    }

    public function test_remote_pool_probe_hauler_purchase_respects_governor_hold(): void
    {
        $this->setUpSaturation();
        $this->addProbe('LOCAL-6', 'X1-TEST-PORT');
        $this->yardPrice(25000, persist: true);
        Ship::where('symbol', 'LOCAL-2')->update(['stationed_waypoint' => self::YARD]);
        $episode = $this->primary(revision: $this->meshRevision());
        $this->schedulerPass();
        $runner = app(AutomationRunner::class);

        $preview = $runner->previewHaulerPurchase($this->agent, 'LOCAL-6', self::YARD, ['minutes' => 15], $episode);
        $this->assertSame('X1-TEST-PORT', $preview->plan['site']);
        $this->assertTrue(app(CampaignMesh::class)->invalidatePreview($this->agent, $preview));
        $path = config('gameplay.request_budget_path').'/budget.json';
        $state = json_decode(file_get_contents($path), true);
        $governor = app(MeshGovernor::class);
        $state['mesh_allowances']['other-work'] = [
            'agent' => 'LOCAL', 'reset' => $this->agent->reset_id, 'expires' => microtime(true) + 120,
            'remaining' => $governor->evaluate($this->agent, $episode)['room'],
        ];
        file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
        $this->assertSame('hold', $governor->evaluate($this->agent, $episode)['mode']);

        try {
            $run = $runner->previewHaulerPurchase($this->agent, 'LOCAL-6', self::YARD, ['minutes' => 15], $episode);
            $runner->startCampaign($this->agent, $run, $episode);
            $runner->advance($run->id);
            $this->fail('Remote pool probe purchase bypassed admission.');
        } catch (RequestDeferred $exception) {
            $this->assertStringContainsString('Mesh request admission held', $exception->getMessage());
        }
        $this->assertFalse(ShipAction::where('ship_symbol', 'LOCAL-6')->whereIn('command', ['orbit', 'navigate'])->exists());
        $this->assertSame([], $this->world()['writes']);

        $hold = $state['mesh_allowances']['other-work'];
        unset($state['mesh_allowances']['other-work']);
        file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
        $run = $runner->previewHaulerPurchase($this->agent, 'LOCAL-6', self::YARD, ['minutes' => 15], $episode);
        $runner->startCampaign($this->agent, $run, $episode);
        $runner->advance($run->id);
        $movement = ShipAction::where('automation_run_id', $run->id)->whereIn('command', ['orbit', 'navigate'])->where('state', 'pending')->sole();
        $state = json_decode(file_get_contents($path), true);
        $state['mesh_allowances']['other-work'] = $hold;
        file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
        app(ShipCommandExecutor::class)->execute($movement->id);
        $this->assertNull($movement->fresh()->sent_at);
        $this->assertSame([], $this->world()['writes']);

        $state = json_decode(file_get_contents($path), true);
        unset($state['mesh_allowances']['other-work']);
        file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
        $this->workRun($run);
        $this->assertSame('completed', $run->fresh()->state->value, $run->fresh()->blocked_reason ?? '');
        $this->assertSame(self::YARD, $this->probe('LOCAL-6')['nav']['waypointSymbol']);
        $this->assertSame(1, $this->purchaseWrites());
    }

    #[DataProvider('exactAllowanceStages')]
    public function test_remote_pool_probe_hauler_purchase_can_use_an_allowance_that_fits_exactly(bool $staged): void
    {
        $symbol = $staged ? 'GIANTSOUP' : 'LOCAL';
        $ship = $symbol.'-6';
        $this->setUpSaturation(agentSymbol: $symbol);
        $this->addProbe($ship, 'X1-TEST-PORT');
        $this->yardPrice(25000, persist: true);
        Ship::where('symbol', $symbol.'-2')->update(['stationed_waypoint' => self::YARD]);
        $episode = $this->primary(revision: $this->meshRevision(['governor_headroom_pct' => 100]));
        if ($staged) {
            $this->seedStagedTraderBudget($episode);
            config(['gameplay.inline_execute' => true]);
        }
        $this->schedulerPass();
        $budget = new class(microtime(true)) extends RequestBudget
        {
            public function __construct(public float $clock) {}

            protected function now(): float
            {
                return $this->clock;
            }
        };
        $this->app->instance(RequestBudget::class, $budget);
        $governor = app(MeshGovernor::class);
        $charge = $governor->estimate($this->agent, 'hauler_purchase');
        $this->assertSame(29, $charge);
        $limits = $governor->evaluate($this->agent, $episode)['limits'];
        $path = config('gameplay.request_budget_path').'/budget.json';
        $state = json_decode(file_get_contents($path), true);
        $key = 'agent:'.$symbol;
        $seeded = $limits['hour'] - $limits['arm'] - $charge;
        $state['agents'][$key]['t24'] = array_fill(0, $seeded, $budget->clock - 3595);
        file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
        $status = $governor->evaluate($this->agent, $episode);
        $this->assertSame(['normal', 29], [$status['mode'], $status['room']]);

        $runner = app(AutomationRunner::class);
        $run = $runner->previewHaulerPurchase($this->agent, $ship, self::YARD, ['minutes' => 15], $episode);
        $this->assertSame(0, $governor->evaluate($this->agent, $episode)['room']);
        $runner->startCampaign($this->agent, $run, $episode);
        $runner->advance($run->id);
        $this->assertSame(0, $governor->evaluate($this->agent, $episode)['room']);
        $orbit = ShipAction::where('automation_run_id', $run->id)->where('command', 'orbit')->sole();
        if (! $staged) {
            app(ShipCommandExecutor::class)->execute($orbit->id);
            // Legacy admission projects extra arm traffic once the hauler is delivered.
            $budget->clock += 6;
        }
        $this->assertSame('confirmed', $orbit->fresh()->state->value);
        $this->workRun($run);

        $this->assertSame('completed', $run->fresh()->state->value, $run->fresh()->blocked_reason ?? '');
        $this->assertSame(self::YARD, $this->probe($ship)['nav']['waypointSymbol']);
        $this->assertSame(1, $this->purchaseWrites());
        if ($staged) {
            $usage = $budget->usage(agentSymbol: $symbol, requireTelemetry: true);
            $this->assertGreaterThan($seeded, $usage['agent']['tier2_4_last_hour']);
            $this->assertLessThanOrEqual(2880, $usage['agent']['tier2_4_last_hour']);
        }
    }

    public static function exactAllowanceStages(): array
    {
        return ['legacy' => [false], 'staged with sustained hourly pressure' => [true]];
    }

    public function test_legacy_four_and_candidate_eight_are_their_respective_maxima(): void
    {
        $playbook = app(CampaignPlaybook::class);
        $playbook->validate($this->meshBody());
        $legacy = Yaml::parseFile(base_path('campaigns/weekly-system-saturation.yaml'));
        $playbook->validate($legacy);
        $legacy['probes']['mesh']['reads_per_pass'] = 5;
        $this->expectException(GameException::class);
        $playbook->validate($legacy);
    }

    #[DataProvider('observationTargets')]
    public function test_verified_fuel_only_and_provisional_scouts_arrive_and_start_movement_dwell(int $tier, bool $listing): void
    {
        $this->setUpSaturation(seedCoverage: false);
        $this->market(self::YARD, tier: 2);
        $this->market('X1-TEST-PORT');
        $this->market('X1-TEST-H3', $tier, $listing);
        $this->market('X1-TEST-H4');
        $episode = $this->primary(revision: $this->meshRevision());
        $runner = app(AutomationRunner::class);
        $probe = $this->probe('LOCAL-2');
        if (! $listing) {
            try {
                app(OperationPlanner::class)->project($this->agent, $probe, [
                    'strategy' => 'scout', 'destination' => 'X1-TEST-H3', 'one_way' => true, 'max_fuel' => 0,
                    'max_spend' => 0, 'max_loss' => 0, 'reserve' => 0, 'mesh_episode' => $episode->id,
                ]);
                $this->fail('A caller flag must not authorize the provisional target.');
            } catch (GameException $exception) {
                $this->assertStringContainsString('goods directory', $exception->getMessage());
            }
        }
        $history = ObservationHistory::where('kind', 'market')->where('symbol', 'X1-TEST-H3');
        $this->assertSame(0, $history->count());
        $scout = $runner->previewMeshScout($this->agent, $probe, 'X1-TEST-H3', ['minutes' => 15, 'reserve' => 0], $episode);
        $runner->startCampaign($this->agent, $scout, $episode);
        $this->workRun($scout);
        $this->assertSame('completed', $scout->fresh()->state->value, $scout->fresh()->blocked_reason ?? '');
        $this->assertSame(0, $scout->fresh()->totals['travel_fuel']);
        $this->assertSame('X1-TEST-H3', $this->probe('LOCAL-2')['nav']['waypointSymbol']);
        $this->assertTrue(GameObservation::where('kind', 'market_listing')->where('symbol', 'X1-TEST-H3')->exists());
        $this->assertNotEmpty(GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-H3')->sole()->data['tradeGoods']);
        $this->assertSame(1, $history->count());
        $this->assertNotEmpty(app(CampaignMesh::class)->probes($episode, $this->agent)[0]['mesh_arrival']);
        $writes = $this->world()['writes'];
        $this->expectExceptionMessage('dwell');
        try {
            app(CampaignMesh::class)->assertDeparture($episode, $this->agent, $this->probe('LOCAL-2'), 'X1-TEST-H4');
        } finally {
            $this->assertSame($writes, $this->world()['writes']);
        }
    }

    public static function observationTargets(): array
    {
        return [[2, true], [1, false]];
    }

    #[DataProvider('navigationBoundaries')]
    public function test_cross_pass_departures_and_station_changes_are_revalidated_before_transport(string $change): void
    {
        $this->setUpSaturation(hubs: 5);
        $episode = $this->primary(revision: $this->meshRevision());
        $this->market('X1-TEST-DEST');
        if ($change === 'yard') {
            app(AgentSynchronizer::class)->observe($this->agent, 'waypoint', 'X1-TEST-DEST',
                $this->point('X1-TEST-DEST', 10, ['SHIPYARD']), 'listing');
        }
        $probe = $this->probe('LOCAL-2');
        $runner = app(AutomationRunner::class);
        $run = $runner->previewMeshScout($this->agent, $probe, 'X1-TEST-DEST', ['reserve' => 0], $episode,
            $change === 'yard' ? 'shipyard' : 'market');
        $runner->startCampaign($this->agent, $run, $episode);
        $runner->advance($run->id);
        $orbit = ShipAction::where('automation_run_id', $run->id)->where('state', 'pending')->sole();
        app(ShipCommandExecutor::class)->execute($orbit->id);
        $this->travel(6)->seconds();
        $runner->advance($run->id);
        $navigate = ShipAction::where('automation_run_id', $run->id)->where('state', 'pending')->sole();
        $this->assertSame('navigate', $navigate->command);
        $writes = $this->world()['writes'];

        $this->duringWrite(function () use ($navigate, $episode, $change) {
            $this->assertSame('sending', $navigate->fresh()->state->value);
            if ($change === 'station') {
                Ship::where('symbol', 'LOCAL-2')->update(['stationed_waypoint' => self::YARD]);

                return;
            }
            AutomationRun::create([
                'agent_id' => $this->agent->id, 'active_agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id,
                'campaign_episode_id' => $episode->id, 'ship_symbol' => 'LOCAL-3', 'strategy' => 'scout', 'state' => 'running',
                'started_at' => now(), 'expires_at' => now()->addMinute(),
                'plan' => ['initial_waypoint' => 'X1-TEST-PORT', 'destination' => 'X1-TEST-OFF'], 'totals' => [],
            ]);
        });
        app(ShipCommandExecutor::class)->execute($navigate->id);
        $this->assertSame($writes, $this->world()['writes']);
        $this->assertSame('rejected', $navigate->fresh()->state->value, $navigate->fresh()->message);
        $this->assertNull($navigate->fresh()->sent_at);
        $this->assertSame('pretransport', $navigate->fresh()->outcome['local_refusal']['stage']);
        $this->assertStringContainsString($change === 'station' ? 'pool authority' : 'G3 floor', $navigate->fresh()->message);
    }

    public static function navigationBoundaries(): array
    {
        return [['station'], ['other_departure']];
    }

    public function test_first_probe_purchase_leaves_the_full_proof_reserve_and_only_a_confirmed_buy_invalidates(): void
    {
        $this->setUpSaturation(hubs: 4, seedCoverage: false, credits: 175000);
        foreach (['X1-TEST-ROCK', 'X1-TEST-PORT', 'X1-TEST-H3', 'X1-TEST-H4'] as $symbol) {
            $this->market($symbol);
        }
        $episode = $this->primary(revision: $this->meshRevision());
        $this->yardPrice(21627, persist: true);
        $runner = app(AutomationRunner::class);
        $run = $runner->previewProbePurchase($this->agent, 'LOCAL-2', self::YARD, ['minutes' => 15], $episode);
        $this->assertSame(25000, $run->plan['mesh_purchase']['ceiling']);
        $runner->startCampaign($this->agent, $run, $episode);
        $this->workRun($run);
        $this->assertSame('completed', $run->fresh()->state->value, $run->fresh()->blocked_reason ?? '');
        $this->assertSame(153373, $this->agent->fresh()->credits);
        $current = GameObservation::where('kind', 'shipyard')->where('symbol', self::YARD)->sole();
        $this->assertTrue(app(ProbeYardSelector::class)->quote($this->agent, self::YARD, $current)['invalidated']);
        $this->yardPrice(22288, persist: true);
        try {
            $runner->previewProbePurchase($this->agent, 'LOCAL-2', self::YARD, ['minutes' => 15], $episode);
            $this->fail('Second buy would consume protected proof capital.');
        } catch (GameException $exception) {
            $this->assertStringContainsString('3373 CR', $exception->getMessage());
        }
        $this->assertSame(1, $this->purchaseWrites());
        $this->editFixture(function (&$world) {
            $fuel = $world['goods'][1];
            $world['market_goods'][self::YARD] = [['symbol' => 'IRON_ORE', 'tradeVolume' => 20, 'purchasePrice' => 3000, 'sellPrice' => 2500], $fuel];
            $world['market_goods']['X1-TEST-PORT'] = [['symbol' => 'IRON_ORE', 'tradeVolume' => 20, 'purchasePrice' => 9000, 'sellPrice' => 8000], $fuel];
            $world['ships'][2]['nav']['waypointSymbol'] = 'X1-TEST-PORT';
        });
        $sync = app(AgentSynchronizer::class);
        $sync->sync($this->agent, false);
        foreach ([self::YARD, 'X1-TEST-PORT'] as $symbol) {
            $sync->observe($this->agent, 'market', $symbol, app(SpaceTradersApi::class)->market($this->agent, $symbol));
        }
        $this->tickC0();
        $trade = AutomationRun::where('strategy', 'trade')->where('campaign_episode_id', $episode->id)->first();
        $this->assertNotNull($trade, json_encode(CampaignEvent::where('kind', 'decision')->latest('id')->value('data')));
        $this->workRun($trade);
        $this->assertSame(60000, $trade->fresh()->totals['purchase_cost'] ?? null, $trade->fresh()->blocked_reason ?? '');
        app(CampaignTrade::class)->recordResults($episode);
        $proof = CampaignEvent::where('kind', 'load_result')->sole();
        $this->assertTrue($proof->data['proof']);
        $this->assertGreaterThan(0, $proof->data['net']);
        $cash = app(ProbeYardSelector::class)->cash($this->agent->fresh(), $episode, $episode->grant(),
            CampaignProbeBootstrap::policy($episode->grant(), $episode), 75000);
        $this->assertSame(0, $cash['proof_guard']);
    }

    #[DataProvider('spendBoundaries')]
    public function test_fresh_preflight_and_post_reservation_spend_boundaries(string $change): void
    {
        $this->setUpSaturation(hubs: 4, seedCoverage: false);
        $this->market(self::YARD);
        $this->market('X1-TEST-PORT');
        $this->market('X1-TEST-H3');
        $this->market('X1-TEST-H4', tier: 2);
        $this->yardPrice(25000, persist: true);
        $episode = $this->primary(revision: $this->meshRevision());
        [$run, $action] = $this->purchaseIntent($episode);
        if ($change === 'preflight') {
            $this->yardPrice(50001);
        } else {
            $this->duringWrite(function () use ($change, $episode, $action) {
                $this->assertSame('sending', $action->fresh()->state->value);
                $this->assertSame(25000, $action->fresh()->before_state['estimate']['purchasePrice']);
                match ($change) {
                    'cutoff' => $episode->update(['next_reset_at' => now()->addHours(12)]),
                    'cash' => $this->agent->update(['credits' => 175000]),
                    'receipt' => $this->yardPrice(50001),
                };
            });
        }
        app(ShipCommandExecutor::class)->execute($action->id);
        $action->refresh();
        if ($change === 'receipt') {
            $this->assertSame('confirmed', $action->state->value, $action->message);
            $this->assertTrue($action->review_required);
            $this->assertSame(50001, $action->outcome['transaction_total']);
            $this->assertNotNull(Ship::where('symbol', $action->outcome['delivered_ship'])->first());
            $this->assertTrue(app(CampaignSafety::class)->priceReview($episode->grant(), $episode, $this->agent));
            $this->assertTrue(app(ProbeYardSelector::class)->quote($this->agent, self::YARD, GameObservation::where('kind', 'shipyard')->sole())['invalidated']);
            $this->assertSame(1, $this->purchaseWrites());
            $this->tickC0();
            $this->assertNotNull($episode->grant()->paused_at);
            $this->assertSame(1, $this->purchaseWrites());
        } else {
            $this->assertSame('rejected', $action->state->value, $action->message);
            $this->assertNull($action->sent_at);
            $this->assertSame($change === 'preflight' ? 'preflight' : 'pretransport', $action->outcome['local_refusal']['stage']);
            $this->assertSame(0, $this->purchaseWrites());
            $this->assertCount(2, Ship::where('agent_id', $this->agent->id)->get());
        }
    }

    public static function spendBoundaries(): array
    {
        return [['preflight'], ['cutoff'], ['cash'], ['receipt']];
    }

    public function test_second_buy_rechecks_reclassified_third_tier_one_target_after_sending(): void
    {
        $this->setUpSaturation(hubs: 4, seedCoverage: false);
        $this->market(self::YARD);
        $this->market('X1-TEST-PORT');
        $this->market('X1-TEST-H3');
        $this->market('X1-TEST-H4');
        $this->market('X1-TEST-FUEL', tier: 2);
        $this->addProbe('LOCAL-3', self::YARD);
        $this->addProbe('LOCAL-4', self::YARD);
        Ship::where('symbol', 'LOCAL-4')->update(['stationed_waypoint' => self::YARD]);
        $episode = $this->primary(revision: $this->meshRevision());
        $this->seedArrival($episode, 'LOCAL-2', self::YARD);
        $this->yardPrice(21627, persist: true);
        $this->travel(1)->seconds();
        $this->yardPrice(27034, persist: true);
        $selector = app(ProbeYardSelector::class);
        $this->assertSame(1, $selector->gain($episode, $this->agent, $this->probe('LOCAL-3'), self::YARD)['tier']);
        [$run, $action] = $this->purchaseIntent($episode, 'LOCAL-3');
        $this->assertSame([1 => 2, 2 => 0], $run->plan['mesh_purchase']['gain']['base']);
        $this->assertSame([1 => 3, 2 => 0], $run->plan['mesh_purchase']['gain']['delivered']);
        $this->duringWrite(function () use ($episode, $run, $action, $selector) {
            $this->assertSame('sending', $action->fresh()->state->value);
            $this->assertSame(27034, $action->fresh()->before_state['estimate']['purchasePrice']);
            $this->market('X1-TEST-H4', tier: 2);
            $gain = $selector->gain($episode, $this->agent, $this->probe('LOCAL-3'), self::YARD, $run->id);
            $this->assertSame([[1 => 2, 2 => 0], [1 => 2, 2 => 1], 2], [$gain['base'], $gain['delivered'], $gain['tier']]);
        });
        app(ShipCommandExecutor::class)->execute($action->id);
        $action->refresh();
        $this->assertSame('rejected', $action->state->value, $action->message);
        $this->assertStringContainsString('27033 CR', $action->message);
        $this->assertNull($action->sent_at);
        $this->assertSame(0, $this->purchaseWrites());
    }

    #[DataProvider('retainedPreviews')]
    public function test_retained_mesh_previews_release_proven_unused_resources_and_keep_inconclusive_ownership(string $kind, bool $inconclusive): void
    {
        $this->setUpSaturation(hubs: 4, seedCoverage: false);
        $this->market(self::YARD);
        $this->market('X1-TEST-PORT');
        $this->market('X1-TEST-H3');
        $this->market('X1-TEST-H4');
        $this->yardPrice(25000, persist: true);
        $episode = $this->primary(revision: $this->meshRevision());
        $runner = app(AutomationRunner::class);
        $run = $kind === 'purchase'
            ? $runner->previewProbePurchase($this->agent, 'LOCAL-2', self::YARD, ['minutes' => 15], $episode)
            : $runner->previewMeshScout($this->agent, $this->probe('LOCAL-2'), 'X1-TEST-H4', ['reserve' => 0], $episode);
        if ($kind === 'yard') {
            $run->update(['plan' => array_replace($run->plan, ['mesh_work' => 'yard'])]);
        }
        if ($inconclusive) {
            ShipAction::create([
                'agent_id' => $this->agent->id, 'automation_run_id' => $run->id, 'reset_id' => $this->agent->reset_id,
                'key' => 'unresolved-mesh-test', 'ship_symbol' => 'LOCAL-2', 'origin' => 'automation', 'state' => 'uncertain',
                'command' => 'navigate', 'arguments' => ['waypointSymbol' => 'X1-TEST-H4'], 'sent_at' => now(), 'expires_at' => now()->addMinutes(3),
            ]);
        }
        app(RequestBudget::class)->bindMesh($run->plan['mesh_allowance'], $run->id, 1);
        $before = $this->world()['calls'];
        app(CampaignMesh::class)->cleanRetained($episode, $this->agent);
        $this->assertSame($before, $this->world()['calls']);
        $this->assertSame($inconclusive ? 'preview' : 'stopped', $run->fresh()->state->value);
        $this->assertSame($inconclusive, app(CampaignProbeBootstrap::class)->reservations($this->agent)->isNotEmpty());
        $this->assertSame($inconclusive, app(CampaignMesh::class)->yardWork($episode, $this->agent));
    }

    public static function retainedPreviews(): array
    {
        return [['purchase', false], ['yard', false], ['purchase', true], ['yard', true]];
    }

    public function test_governor_uses_direct_usage_and_paginates_estimates_for_large_fleets(): void
    {
        $this->setUpSaturation(seedCoverage: false, agentSymbol: 'GIANTSOUP');
        $episode = $this->primary(revision: $this->meshRevision());
        $governor = app(MeshGovernor::class);
        $this->assertSame(4608, $governor->evaluate($this->agent, $episode)['limits']['hour']);
        $this->assertSame(14, $governor->estimate($this->agent, 'buy'));
        $this->editFixture(function (&$world) {
            for ($i = 3; $i <= 21; $i++) {
                $world['ships'][] = FakeSpaceTraders::probe('GIANTSOUP-'.$i, self::YARD);
            }
        });
        app(AgentSynchronizer::class)->sync($this->agent, false);
        $this->assertSame(17, $governor->estimate($this->agent, 'buy'));
        $this->assertSame(18, $governor->estimate($this->agent, 'scout'));
        foreach (['stage1' => 3400, 'stage2' => 2880] as $stage => $limit) {
            config(['gameplay.request_budget_stage' => $stage]);
            $status = $governor->evaluate($this->agent, $episode);
            $this->assertSame($limit, $status['limits']['hour']);
            $this->assertSame('sampling', $status['mode']);
            $id = $governor->admit($this->agent, $episode, 'directory');
            $governor->release($id);
        }
        $this->expectExceptionMessage('insufficient_sample_window');
        $governor->admit($this->agent, $episode, 'buy');
    }

    public function test_director_pins_a_g3_refused_anchor_and_dispatches_the_spare_to_the_uncovered_hub(): void
    {
        $this->setUpSaturation(seedCoverage: false);
        $this->layout([
            'ROCK' => [0, []], 'A' => [50, ['IRON_ORE']], 'B' => [150, ['IRON_ORE', 'COPPER_ORE', 'GOLD_ORE']],
            'C' => [-100, ['IRON_ORE']], 'P' => [0, []],
        ], ['LOCAL-2' => ['A', 9], 'LOCAL-3' => ['P', 9]]);
        $grant = app(CampaignControl::class)->approve($this->meshRevision(), $this->operator, now()->addSeconds(600)->toImmutable());
        $episode = CampaignEpisode::create([
            'agent_id' => $this->agent->id, 'campaign_grant_id' => $grant->id, 'reset_id' => $this->agent->reset_id,
            'phase' => 'earn', 'opened_at' => now(), 'next_reset_at' => $this->world()['next_reset'],
            'start_credits' => $this->agent->credits, 'state' => [],
        ]);
        $mesh = app(CampaignMesh::class);
        $first = $mesh->dispatchPlan($episode, $this->agent, [], [], null, []);
        $this->assertSame(['LOCAL-3' => 'X1-TEST-A', 'LOCAL-2' => 'X1-TEST-B'], array_column($first['moves'], 'destination', 'ship'));
        $this->tickC0();
        $scout = AutomationRun::where('campaign_episode_id', $episode->id)->where('plan->mesh_work', 'market')->sole();
        $this->assertSame('LOCAL-3', $scout->ship_symbol);
        $this->assertSame('X1-TEST-C', $scout->plan['destination']);
        $this->assertSame(293, $scout->plan['route']['outbound']['estimated_seconds']);
        $event = CampaignEvent::where('kind', 'mesh_plan')->latest('id')->firstOrFail();
        $this->assertSame(['LOCAL-2' => 'X1-TEST-A'], $event->data['pins']);
        $this->assertSame('X1-TEST-A', $this->probe('LOCAL-2')['nav']['waypointSymbol']);
        $this->tickC0();
        $this->assertSame(1, AutomationRun::where('campaign_episode_id', $episode->id)->where('plan->mesh_work', 'market')->count());
        $this->workRun($scout);
        $this->assertSame('completed', $scout->fresh()->state->value, $scout->fresh()->blocked_reason ?? '');
        $this->assertSame(['X1-TEST-C'], collect($this->world()['writes'])->filter(fn ($write) => str_ends_with($write['path'], '/navigate'))->pluck('arguments.waypointSymbol')->all());
    }

    public function test_director_and_worker_hold_second_departure_across_passes_until_verified_arrival(): void
    {
        $this->setUpSaturation(seedCoverage: false);
        $points = ['ROCK' => [0, []], 'A' => [20, ['IRON_ORE']], 'AX' => [20, ['IRON_ORE']],
            'B' => [40, ['IRON_ORE']], 'BX' => [40, ['IRON_ORE']]];
        $probes = ['LOCAL-2' => ['A', 30], 'LOCAL-3' => ['B', 30]];
        for ($i = 4; $i <= 10; $i++) {
            $points['H'.$i] = [$i * 20, ['IRON_ORE']];
            $probes['LOCAL-'.$i] = ['H'.$i, 30];
        }
        $this->layout($points, $probes);
        $body = $this->meshRevision()->body;
        $body['probes']['mesh']['base'] = 10;
        $body['probes']['mesh']['dispatches_per_pass'] = 1;
        $episode = $this->primary(revision: app(CampaignPlaybook::class)->import(Yaml::dump($body, 12)));
        GameObservation::where('kind', 'market')->whereIn('symbol', ['X1-TEST-AX', 'X1-TEST-BX'])
            ->update(['observed_at' => now()->subMinutes(10)]);
        $this->editFixture(function (&$world) {
            $world['travel_seconds'] = 20;
            $world['hold_arrivals'] = ['LOCAL-2'];
        });

        $scouts = fn () => AutomationRun::where('campaign_episode_id', $episode->id)->where('plan->mesh_work', 'market')->whereNotNull('started_at');
        $mesh = app(CampaignMesh::class);
        $this->assertCount(9, $mesh->coverage($episode, $this->agent)[0]);
        $this->assertSame(8, $mesh->coverage($episode, $this->agent)[1]);
        $this->tickC0();
        $first = $scouts()->sole();
        $this->assertSame('LOCAL-2', $first->ship_symbol);
        $this->assertSame('X1-TEST-AX', $first->plan['destination']);

        $this->tickC0();
        $this->assertSame(1, $scouts()->count());
        $this->assertSame('X1-TEST-A', $this->probe('LOCAL-2')['nav']['waypointSymbol']);
        $this->assertSame('X1-TEST-B', $this->probe('LOCAL-3')['nav']['waypointSymbol']);
        $this->assertSame([], $this->world()['writes']);
        $hold = collect(CampaignEvent::where('kind', 'mesh_plan')->latest('id')->firstOrFail()->data['holds'])->firstWhere('ship', 'LOCAL-3');
        $this->assertSame(['projected' => 7, 'verified' => 9, 'required' => 8, 'pass' => false], $hold['coverage']);

        $runner = app(AutomationRunner::class);
        $executor = app(ShipCommandExecutor::class);
        foreach (['orbit', 'navigate'] as $command) {
            $runner->advance($first->id);
            $intent = ShipAction::where('automation_run_id', $first->id)->where('state', 'pending')->sole();
            $this->assertSame($command, $intent->command);
            $executor->execute($intent->id);
            $this->assertSame('confirmed', $intent->fresh()->state->value, $intent->fresh()->message ?? '');
            $this->travel(6)->seconds();
        }

        $this->tickC0();
        $this->assertTrue(now()->gt($this->probe('LOCAL-2')['nav']['route']['arrival']));
        $this->assertSame('IN_TRANSIT', $this->probe('LOCAL-2')['nav']['status']);
        $this->assertSame(1, $scouts()->count());
        $this->assertSame(['X1-TEST-AX'], collect($this->world()['writes'])->filter(fn ($write) => str_ends_with($write['path'], '/navigate'))->pluck('arguments.waypointSymbol')->all());

        $this->editFixture(fn (&$world) => $world['hold_arrivals'] = []);
        $this->workRun($first);
        $this->assertSame('completed', $first->fresh()->state->value, $first->fresh()->blocked_reason ?? '');
        $this->assertArrayHasKey('X1-TEST-AX', $mesh->coverage($episode, $this->agent)[0]);
        $this->tickC0();
        $second = $scouts()->whereKeyNot($first->id)->sole();
        $this->assertSame('LOCAL-3', $second->ship_symbol);
        $this->assertSame('X1-TEST-BX', $second->plan['destination']);
        $this->workRun($second);
        $this->assertSame('completed', $second->fresh()->state->value, $second->fresh()->blocked_reason ?? '');
    }

    public function test_director_dispatches_incoming_patrol_to_open_sibling_in_same_pass(): void
    {
        $this->setUpSaturation(seedCoverage: false);
        $this->layout(['ROCK' => [0, []], 'A' => [10, ['IRON_ORE']], 'B' => [10, ['IRON_ORE']], 'C' => [10, ['IRON_ORE']],
            'P' => [9, []], 'X' => [50, ['FUEL']], 'S' => [100, ['IRON_ORE']]],
            ['LOCAL-2' => ['A', 30], 'LOCAL-3' => ['P', 30], 'LOCAL-4' => ['S', 30]]);
        $body = $this->meshRevision()->body;
        $body['probes']['mesh']['base'] = 1;
        $episode = $this->primary(revision: app(CampaignPlaybook::class)->import(Yaml::dump($body, 12)));
        $runner = app(AutomationRunner::class);
        $leaving = $runner->previewMeshScout($this->agent, $this->probe('LOCAL-2'), 'X1-TEST-X', ['reserve' => 0], $episode);
        $runner->startCampaign($this->agent, $leaving, $episode);
        $this->editFixture(function (&$world) {
            $hauler = FakeSpaceTraders::lightHauler('LOCAL-9', 'X1-TEST-B');
            $hauler['nav']['status'] = 'IN_TRANSIT';
            $hauler['nav']['route'] = ['origin' => ['symbol' => 'X1-TEST-S'], 'destination' => ['symbol' => 'X1-TEST-B'],
                'arrival' => now()->addHour()->toIso8601String()];
            $world['ships'][] = $hauler;
        });
        $this->tickC0();

        $incoming = AutomationRun::where('ship_symbol', 'LOCAL-3')->whereNotNull('started_at')->sole();
        $this->assertSame('X1-TEST-B', $incoming->plan['destination']);
        $this->assertSame('X1-TEST-A', $this->probe('LOCAL-2')['nav']['waypointSymbol']);
    }

    public function test_director_dispatches_incoming_patrol_to_open_sibling_beside_recon_probe(): void
    {
        $this->setUpSaturation(seedCoverage: false);
        $this->layout(['ROCK' => [0, []], 'A' => [10, ['IRON_ORE']], 'B' => [10, ['IRON_ORE']], 'P' => [9, []], 'S' => [100, ['IRON_ORE']]],
            ['LOCAL-2' => ['A', 30], 'LOCAL-3' => ['P', 30], 'LOCAL-4' => ['S', 30]]);
        $this->holdProbeForRecon('LOCAL-2');
        $body = $this->meshRevision()->body;
        $body['probes']['mesh']['base'] = 1;
        $this->primary(revision: app(CampaignPlaybook::class)->import(Yaml::dump($body, 12)));

        $this->tickC0();

        $incoming = AutomationRun::where('ship_symbol', 'LOCAL-3')->whereNotNull('started_at')->sole();
        $this->assertSame('X1-TEST-B', $incoming->plan['destination']);
        $this->assertFalse(AutomationRun::where('ship_symbol', 'LOCAL-2')->exists());
    }

    public function test_director_dispatches_spare_to_uncovered_singleton_beside_recon_probe(): void
    {
        $this->setUpSaturation(seedCoverage: false);
        $this->layout(['ROCK' => [0, []], 'A' => [10, ['IRON_ORE']], 'C' => [13, ['IRON_ORE']], 'P' => [9, []]],
            ['LOCAL-2' => ['A', 30], 'LOCAL-3' => ['P', 30]]);
        $this->holdProbeForRecon('LOCAL-2');
        $this->primary(revision: $this->meshRevision());

        $this->tickC0();

        $run = AutomationRun::where('ship_symbol', 'LOCAL-3')->whereNotNull('started_at')->sole();
        $this->assertSame('X1-TEST-C', $run->plan['destination']);
    }

    public function test_purchase_work_does_not_protect_a_pool_probe_sharing_its_anchor_with_recon(): void
    {
        $this->setUpSaturation(seedCoverage: false);
        $this->layout(['ROCK' => [0, []], 'A' => [10, ['IRON_ORE']], 'B' => [13, ['IRON_ORE']]],
            ['LOCAL-2' => ['A', 30], 'LOCAL-3' => ['A', 30]]);
        $this->holdProbeForRecon('LOCAL-2');
        $episode = $this->primary(revision: $this->meshRevision());
        $this->haulerPurchase($episode, 'committed-hauler-delivery', state: 'sending');

        $plan = app(CampaignMesh::class)->dispatchPlan($episode, $this->agent, [], [], null, []);

        $this->assertSame(['LOCAL-3'], array_column($plan['moves'], 'ship'));
        $this->assertSame(['X1-TEST-B'], array_column($plan['moves'], 'destination'));
        $this->assertArrayNotHasKey('LOCAL-3', $plan['restrictions']);
    }

    public function test_probe_delivery_has_no_gain_when_only_the_recon_occupied_target_remains(): void
    {
        $this->setUpSaturation(seedCoverage: false);
        $this->layout(['ROCK' => [0, []], 'A' => [10, ['IRON_ORE']], 'C' => [13, ['IRON_ORE']]],
            ['LOCAL-2' => ['A', 30], 'LOCAL-3' => ['C', 30]]);
        $this->holdProbeForRecon('LOCAL-2');
        $episode = $this->primary(revision: $this->meshRevision());

        $gain = app(ProbeYardSelector::class)->gain($episode, $this->agent, $this->probe('LOCAL-3'), self::YARD);

        $this->assertSame([1 => 1, 2 => 0], $gain['base']);
        $this->assertSame($gain['base'], $gain['delivered']);
        $this->assertSame(0, $gain['tier1']);
        $this->assertNull($gain['tier']);
    }

    public function test_queued_departures_from_another_episode_reduce_available_floor_once(): void
    {
        $this->setUpSaturation(hubs: 5);
        $other = $this->primary();
        $episode = $this->primary(revision: $this->meshRevision());
        $this->market('X1-TEST-DEST', tier: 2);
        $mesh = app(CampaignMesh::class);
        $ship = $this->probe('LOCAL-2');
        $this->assertSame(['projected' => 4, 'verified' => 5, 'required' => 4, 'pass' => true],
            $mesh->floorAfter($mesh->departureEvidence($episode, $this->agent), $ship));
        $departure = AutomationRun::create([
            'agent_id' => $this->agent->id, 'active_agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id,
            'campaign_episode_id' => $other->id, 'ship_symbol' => 'LOCAL-3', 'strategy' => 'scout', 'state' => 'running',
            'started_at' => now()->subMinute(), 'expires_at' => now()->addMinute(),
            'plan' => ['initial_waypoint' => 'X1-TEST-PORT', 'destination' => 'X1-TEST-DEST'], 'totals' => [],
        ]);
        $projection = $mesh->floorAfter($mesh->departureEvidence($episode, $this->agent), $ship);
        $this->assertSame(['projected' => 3, 'verified' => 5, 'required' => 4, 'pass' => false], $projection);
        $departure->update(['state' => 'stopped', 'active_agent_id' => null]);
        $mesh->assertFloor($episode, $this->agent, $ship);
        $this->assertSame([], $this->world()['writes']);
    }

    public function test_worker_blocked_market_scout_records_only_its_probe_backoff_in_handoff(): void
    {
        $this->setUpSaturation(hubs: 4, seedCoverage: false, credits: 100000);
        $this->layout([
            'ROCK' => [0, []], 'A' => [100, ['IRON_ORE']], 'Z' => [110, ['IRON_ORE']], 'P' => [0, []],
        ], ['LOCAL-2' => ['P', 9], 'LOCAL-3' => ['ROCK', 9]]);
        $episode = $this->primary(revision: $this->meshRevision());
        $episode->update(['state' => ['probe_position_failed' => now()->toIso8601String()]]);
        $runner = app(AutomationRunner::class);
        $scout = $runner->previewMeshScout($this->agent, $this->probe('LOCAL-2'), 'X1-TEST-A', ['reserve' => 0], $episode);
        $runner->startCampaign($this->agent, $scout, $episode);
        $this->editFixture(fn (&$world) => $world['market_goods']['X1-TEST-A'] = []);
        $this->workRun($scout);
        $this->assertSame('blocked', $scout->fresh()->state->value, $scout->fresh()->blocked_reason ?? '');
        $this->tickC0();
        $failures = $episode->fresh()->state['mesh_dispatch_failed'];
        $this->assertSame(['LOCAL-2'], array_keys($failures));
        $this->assertSame('X1-TEST-A', $failures['LOCAL-2']['destination']);
        $this->assertSame($scout->id, $failures['LOCAL-2']['run_id']);
        $this->assertTrue(AutomationRun::where('ship_symbol', 'LOCAL-3')->where('plan->mesh_work', 'market')->whereNotNull('started_at')->exists());
    }

    public function test_fresh_station_covered_yard_can_position_spare_then_buy_a_tier_two_probe_while_dwelling(): void
    {
        $this->setUpSaturation(hubs: 4, seedCoverage: false);
        $this->market(self::YARD);
        $this->market('X1-TEST-PORT');
        $this->market('X1-TEST-H3');
        $this->market('X1-TEST-H4', tier: 2);
        $this->addProbe('LOCAL-3', 'X1-TEST-PORT');
        $this->addProbe('LOCAL-4', self::YARD);
        $this->artisan('ship:station', ['ship' => 'LOCAL-4', 'waypoint' => self::YARD])->assertSuccessful();
        $offMarket = 'X1-TEST-PARK';
        $point = $this->point($offMarket, 1, []);
        $this->editFixture(function (&$world) use ($offMarket, $point) {
            $world['waypoints'][] = $point;
            $world['ships'][1]['nav']['waypointSymbol'] = $offMarket;
        });
        app(AgentSynchronizer::class)->observe($this->agent, 'waypoint', $offMarket, $point, 'listing');
        app(AgentSynchronizer::class)->sync($this->agent, false);
        $this->yardPrice(21627, persist: true);
        $episode = $this->primary(revision: $this->meshRevision());
        $bootstrap = app(CampaignProbeBootstrap::class);
        $considered = [];
        $scout = $bootstrap->plan($episode->grant(), $episode, $this->agent, [], $considered, microtime(true) + 15);
        $this->assertNotNull($scout, json_encode($considered));
        $this->assertSame(['LOCAL-2', self::YARD, 'yard'], [$scout->ship_symbol, $scout->plan['destination'], $scout->plan['mesh_work']]);
        $this->assertSame([[1 => 2, 2 => 0], [1 => 2, 2 => 1], 2], [$considered[0]['gain']['base'], $considered[0]['gain']['delivered'], $considered[0]['gain']['tier']]);
        $this->workRun($scout);
        $this->assertSame('completed', $scout->fresh()->state->value, $scout->fresh()->blocked_reason ?? '');
        $this->assertTrue((bool) collect(app(CampaignMesh::class)->probes($episode, $this->agent))->firstWhere('symbol', 'LOCAL-2')['mesh_arrival']);
        $this->assertSame(self::YARD, $this->probe('LOCAL-4')['nav']['waypointSymbol']);
        $this->yardPrice(27034, persist: true);
        $this->assertNull($bootstrap->plan($episode->grant(), $episode, $this->agent, [], $considered, microtime(true) + 15));
        $this->assertSame(0, $this->purchaseWrites());
        $this->yardPrice(26500, persist: true);
        $buy = $bootstrap->plan($episode->grant(), $episode, $this->agent, [], $considered, microtime(true) + 15);
        $this->assertNotNull($buy, json_encode($considered));
        $this->assertSame(2, $buy->plan['mesh_purchase']['tier']);
        $this->workRun($buy);
        $this->assertSame('completed', $buy->fresh()->state->value, $buy->fresh()->blocked_reason ?? '');
        $this->assertSame(1, $this->purchaseWrites());
        $this->assertFalse(ShipAction::where('ship_symbol', 'LOCAL-4')->exists());
        $delivery = $buy->fresh()->totals['purchased_ships'][0];
        $delivered = collect(app(CampaignMesh::class)->probes($episode, $this->agent))->firstWhere('symbol', $delivery);
        $this->assertArrayNotHasKey('mesh_arrival', $delivered);
        app(CampaignMesh::class)->assertDeparture($episode, $this->agent, $delivered, 'X1-TEST-H3');
    }

    public function test_ranked_start_rejection_releases_preview_and_falls_back_to_a_second_yard(): void
    {
        $this->setUpSaturation(hubs: 5, seedCoverage: false);
        foreach ([self::YARD, 'X1-TEST-PORT', 'X1-TEST-H3', 'X1-TEST-H4', 'X1-TEST-H5'] as $symbol) {
            $this->market($symbol);
        }
        $second = 'X1-TEST-PORT';
        $this->addProbe('LOCAL-3', $second);
        $point = collect($this->world()['waypoints'])->firstWhere('symbol', $second);
        $point['traits'][] = ['symbol' => 'SHIPYARD'];
        $offer = [
            'shipTypes' => [['type' => 'SHIP_PROBE']],
            'ships' => [FakeSpaceTraders::shipyardOffer('SHIP_PROBE', FakeSpaceTraders::probe('OFFER-2', $second), 31000)],
        ];
        $this->editFixture(fn (&$world) => $world['shipyards'][$second] = $offer);
        app(AgentSynchronizer::class)->observe($this->agent, 'waypoint', $second, $point, 'listing');
        app(AgentSynchronizer::class)->observe($this->agent, 'shipyard', $second, $offer);
        $this->yardPrice(21627, persist: true);
        $episode = $this->primary(revision: $this->meshRevision());
        $changed = false;
        $hook = function () use (&$changed, $episode) {
            if (! $changed && AutomationRun::where('campaign_episode_id', $episode->id)->where('state', 'preview')
                ->where('plan->shipyard', self::YARD)->exists()) {
                $changed = true;
                $this->yardPrice(90000);
            }
        };
        $this->onBudgetReservation($hook);
        $considered = [];
        $run = app(CampaignProbeBootstrap::class)->plan($episode->grant(), $episode, $this->agent, [], $considered, microtime(true) + 10);
        $this->assertTrue($changed);
        $this->assertNotNull($run, json_encode($considered));
        $this->assertSame($second, $run->plan['shipyard']);
        $first = AutomationRun::where('strategy', 'buy_probe')->where('plan->shipyard', self::YARD)->sole();
        $this->assertSame('stopped', $first->state->value);
        $this->assertArrayNotHasKey('mesh_allowance', $first->plan);
        $this->assertFalse(ShipAction::where('automation_run_id', $first->id)->exists());
        $this->workRun($run);
        $this->assertSame(1, $this->purchaseWrites());
    }

    public function test_preview_refresh_reorders_two_affordable_yards(): void
    {
        $this->setUpSaturation(hubs: 5, seedCoverage: false);
        foreach ([self::YARD, 'X1-TEST-PORT', 'X1-TEST-H3', 'X1-TEST-H4', 'X1-TEST-H5'] as $symbol) {
            $this->market($symbol);
        }
        $second = 'X1-TEST-PORT';
        $this->addProbe('LOCAL-3', $second);
        $point = collect($this->world()['waypoints'])->firstWhere('symbol', $second);
        $point['traits'][] = ['symbol' => 'SHIPYARD'];
        $offer = ['shipTypes' => [['type' => 'SHIP_PROBE']],
            'ships' => [FakeSpaceTraders::shipyardOffer('SHIP_PROBE', FakeSpaceTraders::probe('OFFER-2', $second), 31000)]];
        $this->editFixture(fn (&$world) => $world['shipyards'][$second] = $offer);
        app(AgentSynchronizer::class)->observe($this->agent, 'waypoint', $second, $point, 'listing');
        app(AgentSynchronizer::class)->observe($this->agent, 'shipyard', $second, $offer);
        $this->yardPrice(22000, persist: true);
        $this->yardPrice(35000);

        $episode = $this->primary(revision: $this->meshRevision());
        $considered = [];
        $run = app(CampaignProbeBootstrap::class)->plan($episode->grant(), $episode, $this->agent, [], $considered, microtime(true) + 10);
        $this->assertNotNull($run, json_encode($considered));
        $this->assertSame($second, $run->plan['shipyard']);
        $first = AutomationRun::where('strategy', 'buy_probe')->where('plan->shipyard', self::YARD)->sole();
        $this->assertSame(35000, $first->plan['observed_price']);
        $this->assertSame('stopped', $first->state->value);
        $this->assertArrayNotHasKey('mesh_allowance', $first->plan);
        $this->workRun($run);
        $this->assertSame(1, $this->purchaseWrites());
    }

    #[DataProvider('acceleratedYardQuoteAges')]
    public function test_occupied_stale_yard_quote_is_polled_on_the_accelerated_purchase_cadence(int $age): void
    {
        $this->setUpSaturation(hubs: 5, seedCoverage: false);
        foreach ([self::YARD, 'X1-TEST-PORT', 'X1-TEST-H3', 'X1-TEST-H4', 'X1-TEST-H5'] as $symbol) {
            $this->market($symbol);
        }
        $this->yardPrice(90000, persist: true);
        $this->yardPrice(25000);
        GameObservation::where('kind', 'shipyard')->where('symbol', self::YARD)->update(['observed_at' => now()->subSeconds($age)]);
        $episode = $this->primary(revision: $this->meshRevision());
        $considered = $reads = $purchaseYards = [];
        $run = app(CampaignProbeBootstrap::class)->plan($episode->grant(), $episode, $this->agent, [], $considered,
            microtime(true) + 10, meshReads: $reads, purchaseYards: $purchaseYards);

        $this->assertContains(self::YARD, $purchaseYards);
        $this->assertArrayHasKey('shipyard:'.self::YARD, $reads);
        $this->assertNotNull($run, json_encode($considered));
        $this->assertSame(25000, $run->plan['observed_price']);
    }

    public static function acceleratedYardQuoteAges(): array
    {
        return [['age' => 301], ['age' => 901]];
    }

    public function test_over_ceiling_tier_two_yard_uses_routine_cadence_until_its_quote_is_stale(): void
    {
        $this->setUpSaturation(hubs: 4);
        $this->market('X1-TEST-FUEL', tier: 2);
        $this->yardPrice(25000, persist: true);
        $this->yardPrice(40000, persist: true);
        $yard = GameObservation::where('kind', 'shipyard')->where('symbol', self::YARD)->sole();
        $episode = $this->primary(revision: $this->meshRevision());
        $policy = CampaignProbeBootstrap::policy($episode->grant(), $episode);
        $selector = app(ProbeYardSelector::class);
        $this->assertSame(2, $selector->gain($episode, $this->agent, $this->probe('LOCAL-2'), self::YARD)['tier']);
        $this->assertLessThan(40000, $selector->ceiling($this->agent, self::YARD, $policy, 2));
        $considered = $purchaseYards = $reads = [];
        $deadline = microtime(true) + 10;
        $yard->update(['observed_at' => now()->subSeconds(899)]);
        $selector->rank($episode, $this->agent, $policy, [], 0, $deadline, $considered, $purchaseYards);
        $this->assertNotContains(self::YARD, $purchaseYards);
        $poller = app(MeshPoller::class);
        $poller->refresh($episode, $this->agent, $reads, $deadline, phase: 'routine', purchaseYards: $purchaseYards);
        $this->assertArrayNotHasKey('shipyard:'.self::YARD, $reads);

        $yard->update(['observed_at' => now()->subSeconds(900)]);
        $reads = [];
        $poller->refresh($episode, $this->agent, $reads, $deadline, phase: 'routine', purchaseYards: $purchaseYards);
        $this->assertArrayHasKey('shipyard:'.self::YARD, $reads);

        $yard->update(['observed_at' => now()->subSeconds(901)]);
        $selector->rank($episode, $this->agent, $policy, [], 0, $deadline, $considered, $purchaseYards);
        $this->assertContains(self::YARD, $purchaseYards);
        $reads = [];
        $poller->refresh($episode, $this->agent, $reads, $deadline, phase: 'purchase', purchaseYards: $purchaseYards);
        $this->assertArrayHasKey('shipyard:'.self::YARD, $reads);
    }

    public function test_director_hauler_discovery_preserves_over_ceiling_probe_only_yard_cadence(): void
    {
        $this->setUpSaturation();
        $yard = 'X1-TEST-C40';
        $this->market('X1-TEST-FUEL', tier: 2);
        $this->editFixture(function (&$world) use ($yard) {
            $world['waypoints'][] = $this->point($yard, 9, ['SHIPYARD']);
            $probeOffer = array_shift($world['shipyards'][self::YARD]['ships']);
            array_shift($world['shipyards'][self::YARD]['shipTypes']);
            $world['shipyards'][$yard] = ['shipTypes' => [['type' => 'SHIP_PROBE']], 'ships' => [$probeOffer]];
            $world['ships'][0]['nav']['waypointSymbol'] = $yard;
        });
        $sync = app(AgentSynchronizer::class);
        $sync->sync($this->agent, false);
        $sync->observe($this->agent, 'waypoint', $yard, collect($this->world()['waypoints'])->firstWhere('symbol', $yard), 'listing');
        $data = ['symbol' => $yard] + $this->world()['shipyards'][$yard];
        $sync->observe($this->agent, 'shipyard', $yard, $data, observer: 'LOCAL-1');
        $data['ships'][0]['purchasePrice'] = 40000;
        $this->editFixture(fn (&$world) => $world['shipyards'][$yard]['ships'][0]['purchasePrice'] = 40000);
        $quote = $sync->observe($this->agent, 'shipyard', $yard, $data, observer: 'LOCAL-1');
        $quote->update(['observed_at' => now()->subSeconds(300)]);
        $episode = $this->primary(revision: $this->meshRevision());
        $this->schedulerPass();

        $policy = CampaignProbeBootstrap::policy($episode->grant(), $episode);
        $selector = app(ProbeYardSelector::class);
        $this->assertSame(2, $selector->gain($episode, $this->agent, $this->probe('LOCAL-2'), $yard)['tier']);
        $this->assertLessThan(40000, $selector->ceiling($this->agent, $yard, $policy, 2));
        $this->assertTrue(app(CampaignSaturation::class)->evaluate($episode->grant(), $episode, $this->agent, offerIndependentOnly: true)['can_purchase']);
        $before = count($this->world()['calls']);

        $this->tickC0();

        $calls = array_column(array_slice($this->world()['calls'], $before), 'path');
        $this->assertContains('/systems/X1-TEST/waypoints/'.self::YARD.'/shipyard', $calls);
        $this->assertNotContains('/systems/X1-TEST/waypoints/'.$yard.'/shipyard', $calls);
        $this->assertTrue(AutomationRun::where('strategy', 'buy_hauler')->whereNotNull('started_at')->exists());
    }

    #[DataProvider('haulerDiscoveryAdmission')]
    public function test_saturated_hauler_discovery_shares_admission_for_a_stale_mixed_yard(string $mode): void
    {
        $this->setUpSaturation();
        $this->editFixture(fn (&$world) => $world['shipyards'][self::YARD]['ships'][1]['purchasePrice'] = 3000000);
        $episode = $this->primary(revision: $this->meshRevision());
        $this->schedulerPass();
        $quote = app(AgentSynchronizer::class)->observe($this->agent, 'shipyard', self::YARD,
            ['symbol' => self::YARD] + $this->world()['shipyards'][self::YARD], observer: 'LOCAL-2');
        $quote->update(['observed_at' => now()->subSeconds(181)]);
        $this->assertTrue(app(CampaignSaturation::class)->evaluate($episode->grant(), $episode, $this->agent, offerIndependentOnly: true)['can_purchase']);
        $key = 'shipyard:'.self::YARD;
        $reads = $mode === 'deduplicated' ? [$key => true] : [];
        if ($mode === 'hold') {
            $path = config('gameplay.request_budget_path').'/budget.json';
            $state = json_decode(file_get_contents($path), true);
            $governor = app(MeshGovernor::class);
            $state['mesh_allowances']['other-work'] = [
                'agent' => $this->agent->name, 'reset' => $this->agent->reset_id, 'expires' => microtime(true) + 120,
                'remaining' => $governor->evaluate($this->agent, $episode)['room'],
            ];
            file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
            $this->assertSame('hold', $governor->evaluate($this->agent, $episode)['mode']);
        }
        $before = count($this->world()['calls']);
        $considered = [];

        $this->assertNull(app(CampaignHaulerPurchase::class)->plan($episode->grant(), $episode, $this->agent, [], $considered, microtime(true) + 15, $reads));

        $this->assertContains('Observed SHIP_LIGHT_HAULER price and fuel breach the working-capital reserve.', array_column($considered, 'rejected'));
        $calls = array_column(array_slice($this->world()['calls'], $before), 'path');
        $yardReads = count(array_keys($calls, '/systems/X1-TEST/waypoints/'.self::YARD.'/shipyard', true));
        $this->assertSame($mode === 'admitted' ? 1 : 0, $yardReads);
        $this->assertSame($mode === 'hold' ? [] : [$key => true], $reads);
        $usage = app(RequestBudget::class)->usage(agentSymbol: $this->agent->name, requireTelemetry: true);
        $this->assertSame($yardReads, $usage['mesh']['used']);
    }

    public static function haulerDiscoveryAdmission(): array
    {
        return ['admitted' => ['admitted'], 'governor hold' => ['hold'], 'shared read map' => ['deduplicated']];
    }

    public function test_legacy_hauler_discovery_refreshes_a_stale_yard_last_observed_as_probe_only(): void
    {
        $this->setUpSaturation();
        $episode = $this->primary();
        $this->schedulerPass();
        $data = ['symbol' => self::YARD] + $this->world()['shipyards'][self::YARD];
        array_pop($data['ships']);
        array_pop($data['shipTypes']);
        $quote = app(AgentSynchronizer::class)->observe($this->agent, 'shipyard', self::YARD, $data, observer: 'LOCAL-2');
        $quote->update(['observed_at' => now()->subSeconds(181)]);
        $considered = [];
        $reads = ['shipyard:'.self::YARD => true];

        $run = app(CampaignHaulerPurchase::class)->plan($episode->grant(), $episode, $this->agent, [], $considered, microtime(true) + 15, $reads);

        $this->assertSame(self::YARD, $run?->plan['shipyard'], json_encode($considered));
    }

    public function test_late_probe_attribution_invalidates_a_quote_observed_after_settlement(): void
    {
        $this->setUpSaturation(seedCoverage: false);
        $episode = $this->primary(revision: $this->meshRevision());
        $action = ShipAction::create(['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id,
            'ship_symbol' => 'LOCAL-2', 'key' => 'late-probe-quote-watermark',
            'origin' => 'automation', 'command' => 'buy_ship', 'state' => 'acknowledged',
            'arguments' => ['shipType' => 'SHIP_PROBE', 'waypointSymbol' => self::YARD, 'maxTotal' => 25000],
            'before_state' => ['estimate' => ['fleet_symbols' => ['LOCAL-1', 'LOCAL-2']]],
            'outcome' => [], 'sent_at' => now()->subMinute(), 'settled_at' => now(), 'expires_at' => now()->addMinutes(3)]);
        $transaction = ['waypointSymbol' => self::YARD, 'shipType' => 'SHIP_PROBE', 'agentSymbol' => 'LOCAL',
            'price' => 25000, 'timestamp' => $action->sent_at->toIso8601String()];
        $this->travel(10)->seconds();
        $sync = app(AgentSynchronizer::class);
        $quoteData = $this->world()['shipyards'][self::YARD] + ['transactions' => [$transaction]];
        $quote = $sync->observe($this->agent, 'shipyard', self::YARD, $quoteData);
        $selector = app(ProbeYardSelector::class);
        $this->assertTrue($selector->quote($this->agent, self::YARD, $quote)['fresh']);
        $this->travel(10)->seconds();
        $this->addProbe('LOCAL-3', self::YARD);

        app(CampaignProbeBootstrap::class)->reconcileDeliveries($episode->grant(), $episode, $this->agent);

        $this->assertSame('LOCAL-3', CampaignProbeBootstrap::deliveredShip($action->fresh()));
        $this->assertTrue($selector->quote($this->agent, self::YARD, $quote)['invalidated']);
        $this->assertSame(now()->utc()->toIso8601String(), $action->fresh()->outcome['delivery_attributed_at']);
        $this->travel(1)->seconds();
        $fresh = $sync->observe($this->agent, 'shipyard', self::YARD, $quoteData);
        $this->assertTrue($selector->quote($this->agent, self::YARD, $fresh)['fresh']);
    }

    public function test_request_budget_limits_an_overrun_and_sheds_before_the_host_line(): void
    {
        $this->setUpSaturation(seedCoverage: false);
        $episode = $this->primary(revision: $this->meshRevision());
        $clock = microtime(true);
        $budget = new class($clock) extends RequestBudget
        {
            public function __construct(public float $clock) {}

            protected function now(): float
            {
                return $this->clock;
            }
        };
        $this->app->instance(RequestBudget::class, $budget);
        $path = config('gameplay.request_budget_path').'/budget.json';
        file_put_contents($path, json_encode(['buckets' => [], 'agents' => ['agent:LOCAL' => ['t24' => array_fill(0, 4387, $clock - 90)]]]));
        $governor = app(MeshGovernor::class);
        $this->assertSame(1, $governor->evaluate($this->agent, $episode)['room']);
        $id = $governor->admit($this->agent, $episode, 'demand');
        $this->assertSame(0, $governor->evaluate($this->agent, $episode)['room']);
        RequestBudget::forMeshAllowance($id, fn () => $budget->reserve(priority: 'director', maxWait: 0), 'LOCAL', $this->agent->reset_id);
        try {
            RequestBudget::forMeshAllowance($id, fn () => $budget->reserve(priority: 'director', maxWait: 0), 'LOCAL', $this->agent->reset_id);
            $this->fail('Overrun would exceed the hourly envelope.');
        } catch (GameException $exception) {
            $this->assertGreaterThan(0, $exception->retryAfter);
        }
        $governor->release($id);
        file_put_contents($path, json_encode(['buckets' => [], 'host_tiers' => ['t24' => array_fill(0, 75, $clock - 1)]]));
        $this->assertSame('host_minute_headroom', $governor->evaluate($this->agent, $episode)['reason']);
        file_put_contents($path, json_encode(['buckets' => [], 'host_rate_limits' => array_fill(0, 3, $clock - 1)]));
        $this->assertSame('host_rate_limits', $governor->evaluate($this->agent, $episode)['reason']);
    }

    public function test_unoccupied_yard_directory_can_recover_with_one_bounded_quote_scout(): void
    {
        $this->setUpSaturation(hubs: 4, remoteYard: true, seedCoverage: false);
        $yard = 'X1-TEST-YARD';
        $point = $this->point($yard, 30, ['SHIPYARD']);
        $this->editFixture(function (&$world) use ($yard, $point) {
            foreach ($world['waypoints'] as &$waypoint) {
                if ($waypoint['symbol'] === $yard) {
                    $waypoint = $point;
                }
            }
            $world['market_goods'][self::YARD] = [$world['goods'][1]];
        });
        app(AgentSynchronizer::class)->observe($this->agent, 'waypoint', $yard, $point, 'listing');
        $this->market(self::YARD, tier: 2);
        foreach (['X1-TEST-PORT', 'X1-TEST-H3', 'X1-TEST-H4'] as $destination) {
            $this->market($destination);
        }
        $cached = $this->world()['shipyards'][$yard];
        $cached['ships'][0]['purchasePrice'] = 90000;
        app(AgentSynchronizer::class)->observe($this->agent, 'shipyard', $yard, $cached);
        GameObservation::where('kind', 'shipyard')->where('symbol', $yard)->update(['observed_at' => now()->subHour()]);
        $episode = $this->primary(revision: $this->meshRevision());
        $bootstrap = app(CampaignProbeBootstrap::class);
        $considered = $reads = [];
        $scout = $bootstrap->plan($episode->grant(), $episode, $this->agent, [], $considered, microtime(true) + 15, meshReads: $reads);

        $this->assertNotNull($scout, json_encode($considered));
        $this->assertSame('yard', $scout->plan['mesh_work']);
        $this->assertNull($bootstrap->plan($episode->grant(), $episode, $this->agent, [], $considered, microtime(true) + 15));
        $this->workRun($scout);
        $this->assertSame('completed', $scout->fresh()->state->value, $scout->fresh()->blocked_reason ?? '');
        $this->assertNotEmpty(GameObservation::where('kind', 'shipyard')->where('symbol', $yard)->sole()->data['ships']);
        $run = $bootstrap->plan($episode->grant(), $episode, $this->agent, [], $considered, microtime(true) + 15);
        $this->assertNotNull($run, json_encode($considered));
        $this->assertSame($yard, $run->plan['shipyard']);
        $this->workRun($run);
        $this->assertSame(1, $this->purchaseWrites());
    }

    public function test_governed_cargo_recovery_deduplicates_a_scout_until_detailed_arrival(): void
    {
        $this->setUpSaturation(hubs: 4, seedCoverage: false);
        $this->layout(['ROCK' => [0, ['FUEL']], 'PORT' => [3, ['IRON_ORE', 'FUEL']], 'P' => [0, []]], ['LOCAL-2' => ['P', 9]]);
        $this->editFixture(function (&$world) {
            $world['ships'][0]['cargo']['units'] = 2;
            $world['ships'][0]['cargo']['inventory'] = [['symbol' => 'IRON_ORE', 'name' => 'Iron Ore', 'description' => 'Fixture cargo', 'units' => 2]];
        });
        GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-PORT')->update(['observed_at' => now()->subHour()]);
        $episode = $this->primary(revision: $this->meshRevision());
        $this->tickC0();
        $scout = AutomationRun::where('strategy', 'scout')->where('plan->mesh_work', 'market')->sole();
        $this->assertSame('X1-TEST-PORT', $scout->plan['destination']);
        $this->assertFalse(AutomationRun::where('strategy', 'sell_aboard')->exists());
        $this->tickC0();
        $this->assertSame(1, AutomationRun::where('strategy', 'scout')->where('plan->mesh_work', 'market')->count());
        $this->workRun($scout);
        $this->assertSame('completed', $scout->fresh()->state->value, $scout->fresh()->blocked_reason ?? '');
        $this->assertNotEmpty(GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-PORT')->sole()->data['tradeGoods']);
        $this->tickC0();
        $this->assertTrue(AutomationRun::where('strategy', 'sell_aboard')->whereNotNull('started_at')->exists());
    }

    #[DataProvider('delayedObservers')]
    public function test_cargo_recovery_sells_to_verified_buyer_when_a_delayed_probe_cannot_observe(string $delay): void
    {
        $this->setUpSaturation(seedCoverage: false);
        $this->layout(['ROCK' => [0, ['FUEL']], 'A' => [3, ['IRON_ORE']], 'B' => [4, ['IRON_ORE', 'FUEL']], 'P' => [0, []]],
            ['LOCAL-2' => ['P', 25], 'LOCAL-3' => ['B', 30]]);
        $episode = $this->primary(revision: $this->meshRevision());
        if ($delay !== 'unrelated_transit') {
            $runner = app(AutomationRunner::class);
            $run = $runner->previewMeshScout($this->agent, $this->probe('LOCAL-2'), 'X1-TEST-A', ['reserve' => 0], $episode);
            $runner->startCampaign($this->agent, $run, $episode);
        }
        if ($delay === 'margin' || $delay === 'scheduled') {
            $run->update([
                // tickC0 advances 31 seconds, leaving 19 seconds for an 18-second route in the margin case.
                'expires_at' => now()->addSeconds($delay === 'margin' ? 50 : 120),
                'next_action_at' => $delay === 'margin' ? now() : now()->addSeconds(121),
            ]);
        } else {
            if ($delay === 'transit_scheduled') {
                // tickC0 advances 31 seconds, leaving 30 s to arrival, 120 s to expiry, and 121 s to next_action_at.
                $run->update([
                    'expires_at' => now()->addSeconds(151),
                    'next_action_at' => now()->addSeconds(152),
                ]);
            }
            $this->editFixture(function (&$world) use ($delay) {
                $nav = &$world['ships'][1]['nav'];
                $nav['waypointSymbol'] = 'X1-TEST-A';
                $nav['status'] = 'IN_TRANSIT';
                $nav['route'] = ['origin' => ['symbol' => 'X1-TEST-P'], 'destination' => ['symbol' => 'X1-TEST-A'],
                    'arrival' => match ($delay) {
                        'transit_scheduled' => now()->addSeconds(61)->toIso8601String(),
                        'scout_transit' => now()->addHours(2)->toIso8601String(),
                        default => now()->addDays(6)->toIso8601String(),
                    }];
            });
        }
        $this->loadRecoveryCargo();
        GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-A')->update(['observed_at' => now()->subHour()]);
        GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-B')->update(['observer_ship' => 'LOCAL-3']);
        $this->tickC0();

        $run = AutomationRun::where('strategy', 'sell_aboard')->whereNotNull('started_at')->sole();
        $this->assertSame('X1-TEST-B', $run->plan['market']);
        $this->workRun($run);
        $this->assertSame('completed', $run->fresh()->state->value, $run->fresh()->blocked_reason ?? '');
        $this->assertSame(0, $this->probe('LOCAL-1')['cargo']['units']);
    }

    public static function delayedObservers(): array
    {
        return [
            'unrelated transit past campaign cutoff' => ['unrelated_transit'],
            'active scout arriving after run expiry' => ['scout_transit'],
            'in-transit scout worker scheduled after run expiry' => ['transit_scheduled'],
            'stationary scout misses the transport margin' => ['margin'],
            'stationary scout scheduled after run expiry' => ['scheduled'],
        ];
    }

    public function test_governor_refusal_retains_cargo_when_the_old_buyer_bid_is_positive(): void
    {
        $this->setUpSaturation(seedCoverage: false, agentSymbol: 'GIANTSOUP');
        $this->layout(['ROCK' => [0, ['FUEL']], 'PORT' => [3, ['IRON_ORE', 'FUEL']], 'P' => [0, []]], ['GIANTSOUP-2' => ['P', 9]]);
        $this->editFixture(function (&$world) {
            $world['ships'][0]['cargo']['units'] = 2;
            $world['ships'][0]['cargo']['inventory'] = [['symbol' => 'IRON_ORE', 'name' => 'Iron Ore', 'description' => 'Fixture cargo', 'units' => 2]];
        });
        GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-PORT')->update(['observed_at' => now()->subHour()]);
        $episode = $this->primary(revision: $this->meshRevision());
        config(['gameplay.request_budget_stage' => 'stage2']);
        $this->assertSame('sampling', app(MeshGovernor::class)->evaluate($this->agent, $episode)['mode']);

        $this->tickC0();
        $this->assertFalse(AutomationRun::whereIn('strategy', ['scout', 'sell_aboard'])->whereNotNull('started_at')->exists());
        $this->assertSame([], $this->world()['writes']);
        $this->assertDatabaseHas('campaign_events', ['kind' => 'decision', 'data->choice' => 'awaiting_cargo_buyer_quote_or_route']);
    }

    #[DataProvider('settlementModes')]
    public function test_restricted_mesh_recovery_sells_cargo_without_an_observer_scout(string $mode, bool $parked = false): void
    {
        $this->setUpSaturation(seedCoverage: false);
        $this->layout(['ROCK' => [0, ['FUEL']], 'PORT' => [3, ['IRON_ORE', 'FUEL']], 'P' => [0, []]], ['LOCAL-2' => ['P', 9]]);
        $this->loadRecoveryCargo();
        if ($parked) {
            $this->addProbe('LOCAL-3', 'X1-TEST-PORT');
        }
        $quote = GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-PORT')->sole();
        $quote->update(['observed_at' => now()->subHour()]);
        $episode = $this->primary(phase: $mode === 'wind_down' ? 'wind_down' : 'earn', revision: $this->meshRevision());
        $control = app(CampaignControl::class);
        if ($mode === 'drain') {
            $control->drainPause($episode->grant(), 'test_pressure', $episode);
        } elseif ($mode === 'settlement_only') {
            $episode->update(['state' => ['pending_pause' => 'consecutive_losing_loads']]);
        } elseif ($mode === 'pending_handover') {
            $this->assertSame(2, $control->approve($this->revision, $this->operator)->slot);
        }
        if (in_array($mode, ['wind_down', 'pending_handover'], true)) {
            try {
                app(AutomationRunner::class)->previewMeshScout($this->agent, $this->probe('LOCAL-2'), 'X1-TEST-PORT', ['reserve' => 0], $episode);
                $this->fail('Settlement mode authorized a new observer scout.');
            } catch (GameException $exception) {
                $this->assertStringContainsString('lacks bound campaign authority', $exception->getMessage());
            }
        }

        $this->tickC0();

        $run = AutomationRun::where('strategy', 'sell_aboard')->whereNotNull('started_at')->sole();
        $this->assertTrue($run->plan['mesh_settlement_only']);
        if ($parked) {
            $this->assertSame('LOCAL-3', $quote->fresh()->observer_ship);
            $this->assertTrue($quote->fresh()->observed_at->gt($quote->observed_at));
        }
        $this->workRun($run);
        $this->assertSame('completed', $run->fresh()->state->value, $run->fresh()->blocked_reason ?? '');
        $this->assertSame(2, $run->fresh()->totals['sold']['IRON_ORE']);
        $this->assertSame(0, $this->probe('LOCAL-1')['cargo']['units']);
        $this->assertFalse(AutomationRun::where('strategy', 'scout')->exists());
    }

    public static function settlementModes(): array
    {
        return [['drain'], ['settlement_only'], ['settlement_only', true], ['wind_down'], ['pending_handover']];
    }

    #[DataProvider('degradedBuyerLocations')]
    public function test_degraded_recovery_reads_at_most_two_parked_buyers_in_one_pass(bool $atThirdBuyer): void
    {
        $this->setUpSaturation(seedCoverage: false);
        $this->layout(['ROCK' => [0, ['FUEL']], 'A' => [3, ['IRON_ORE']], 'B' => [4, ['IRON_ORE']], 'C' => [5, ['IRON_ORE']]],
            ['LOCAL-2' => ['A', 30], 'LOCAL-3' => ['B', 30], 'LOCAL-4' => ['C', 30]]);
        $this->loadRecoveryCargo();
        $this->editFixture(function (&$world) use ($atThirdBuyer) {
            if ($atThirdBuyer) {
                $world['ships'][0]['nav']['waypointSymbol'] = 'X1-TEST-C';
            }
            foreach (['A', 'B', 'C'] as $name) {
                $world['market_goods']['X1-TEST-'.$name][0]['sellPrice'] = 0;
            }
        });
        GameObservation::where('kind', 'market')->whereIn('symbol', ['X1-TEST-A', 'X1-TEST-B', 'X1-TEST-C'])
            ->update(['observed_at' => now()->subHour()]);
        $episode = $this->primary(revision: $this->meshRevision());
        $governor = app(MeshGovernor::class);
        $limits = $governor->evaluate($this->agent, $episode)['limits'];
        $state = ['buckets' => [], 'agents' => ['agent:LOCAL' => ['t24' => array_fill(0, (int) ceil($limits['hour'] * 0.9), microtime(true) - 90)]]];
        file_put_contents(config('gameplay.request_budget_path').'/budget.json', json_encode($state, JSON_THROW_ON_ERROR));
        $this->assertSame('degraded', $governor->evaluate($this->agent, $episode)['mode']);
        $before = count($this->world()['calls']);

        $this->tickC0();

        $markets = collect(array_slice($this->world()['calls'], $before))->pluck('path')
            ->filter(fn (string $path) => preg_match('~/X1-TEST-[ABC]/market$~', $path));
        $this->assertCount(2, $markets);
        $this->assertFalse(AutomationRun::where('strategy', 'sell_aboard')->exists());
        $this->assertSame([], $this->world()['writes']);
    }

    public static function degradedBuyerLocations(): array
    {
        return ['remote buyers' => [false], 'cargo ship at third buyer' => [true]];
    }

    private function loadRecoveryCargo(): void
    {
        $this->editFixture(function (&$world) {
            $world['ships'][0]['cargo']['units'] = 2;
            $world['ships'][0]['cargo']['inventory'] = [['symbol' => 'IRON_ORE', 'name' => 'Iron Ore', 'description' => 'Fixture cargo', 'units' => 2]];
        });
        app(AgentSynchronizer::class)->sync($this->agent, false);
    }

    public function test_director_does_not_resubmit_a_retained_purchase_when_admission_is_denied(): void
    {
        $this->setUpSaturation(hubs: 4, seedCoverage: false);
        foreach ([self::YARD, 'X1-TEST-PORT', 'X1-TEST-H3', 'X1-TEST-H4'] as $symbol) {
            $this->market($symbol);
        }
        $this->yardPrice(25000, persist: true);
        $episode = $this->primary(revision: $this->meshRevision());
        $run = app(AutomationRunner::class)->previewProbePurchase($this->agent, 'LOCAL-2', self::YARD, ['minutes' => 15], $episode);
        $path = config('gameplay.request_budget_path').'/budget.json';
        $state = json_decode(file_get_contents($path), true);
        $state['mesh_allowances'][$run->plan['mesh_allowance']]['expires'] = microtime(true) - 1;
        $state['host_tiers']['t24'] = array_fill(0, 75, microtime(true) - 1);
        file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
        $before = count($this->world()['calls']);
        $this->tickC0();
        $newCalls = array_slice($this->world()['calls'], $before);
        $this->assertSame('stopped', $run->fresh()->state->value);
        $this->assertArrayNotHasKey('mesh_allowance', $run->fresh()->plan);
        $this->assertFalse(collect($newCalls)->contains(fn ($call) => str_ends_with($call['path'], '/shipyard')));
        $this->assertSame(1, AutomationRun::where('strategy', 'buy_probe')->count());
        $this->assertSame(0, $this->purchaseWrites());
    }

    public function test_a_pin_added_after_preview_prevents_start(): void
    {
        $this->setUpSaturation(seedCoverage: false);
        $this->market('X1-TEST-H3');
        $episode = $this->primary(revision: $this->meshRevision());
        $runner = app(AutomationRunner::class);
        $run = $runner->previewMeshScout($this->agent, $this->probe('LOCAL-2'), 'X1-TEST-H3', ['reserve' => 0], $episode);
        $this->artisan('ship:station', ['ship' => 'LOCAL-2', 'waypoint' => self::YARD])->assertSuccessful();
        try {
            $runner->startCampaign($this->agent, $run, $episode);
            $this->fail('Pinned probe accepted as a campaign scout.');
        } catch (GameException $exception) {
            $this->assertStringContainsString('pool authority', $exception->getMessage());
        }
        $this->assertNull($run->fresh()->started_at);
        $this->assertSame([], $this->world()['writes']);
        $this->artisan('ship:station', ['ship' => 'LOCAL-2', '--clear' => true])->assertSuccessful();
        $this->assertNull(Ship::where('symbol', 'LOCAL-2')->sole()->stationed_waypoint);
    }

    public function test_demand_limit_includes_active_buyer_and_reports_uncovered_demand(): void
    {
        $this->setUpSaturation(hubs: 4, seedCoverage: false);
        $episode = $this->primary(revision: $this->meshRevision(['demand_max' => 2]));
        $candidates = [['source' => self::YARD, 'buyer' => 'X1-TEST-PORT', 'score' => 2], ['source' => 'X1-TEST-H3', 'buyer' => 'X1-TEST-H4', 'score' => 1]];
        app(CampaignControl::class)->event($episode->grant(), $episode, 'decision', ['candidates' => $candidates]);
        $poller = app(MeshPoller::class);
        $reads = [];
        $buyer = 'X1-TEST-H4';
        $this->assertSame([$buyer, self::YARD], $poller->demand($episode, $this->agent, $buyer));
        $result = $poller->refresh($episode, $this->agent, $reads, microtime(true) + 10, $buyer, 'demand');
        $this->assertSame([$buyer], $result['uncovered']);
        $this->assertSame([self::YARD], array_keys($reads));
    }

    #[DataProvider('governorBoundaryModes')]
    public function test_governor_holds_directory_at_ninety_percent(string $mode): void
    {
        $this->setUpSaturation(seedCoverage: false, agentSymbol: 'GIANTSOUP');
        $episode = $this->primary(revision: $this->meshRevision());
        config(['gameplay.host_tier2_4_per_minute' => 100, 'gameplay.request_budget_stage' => $mode === 'sampling' ? 'stage2' : 'off']);
        $governor = app(MeshGovernor::class);
        $status = $governor->evaluate($this->agent, $episode);
        $boundary = intdiv($status['limits']['hour'] * 9, 10) - $status['limits']['arm'];
        $path = config('gameplay.request_budget_path').'/budget.json';
        $record = function (int $count) use ($path) {
            file_put_contents($path, json_encode(['buckets' => [], 'agents' => ['agent:GIANTSOUP' => ['t24' => array_fill(0, $count, microtime(true) - 90)]]]));
        };

        $record($boundary - 1);
        $this->assertSame($mode, $governor->evaluate($this->agent, $episode)['mode']);
        $directory = $governor->admit($this->agent, $episode, 'directory');
        $this->assertTrue($governor->release($directory));

        $record($boundary);
        $this->assertSame('degraded', $governor->evaluate($this->agent, $episode)['mode']);
        $demand = $governor->admit($this->agent, $episode, 'demand');
        $this->assertTrue($governor->release($demand));
        $this->expectExceptionMessage('agent_hour_threshold');
        $governor->admit($this->agent, $episode, 'directory');
    }

    public static function governorBoundaryModes(): array
    {
        return [['sampling'], ['normal']];
    }

    public function test_staged_trader_headroom_protects_a_committed_hauler_at_the_ceiling(): void
    {
        $this->setUpSaturation(seedCoverage: false, agentSymbol: 'GIANTSOUP');
        $episode = $this->primary(revision: $this->meshRevision());
        $this->seedStagedTraderBudget($episode);
        $sync = app(AgentSynchronizer::class);
        $sync->saveShip($this->agent, FakeSpaceTraders::lightHauler('GIANTSOUP-3', self::YARD));
        $sync->saveShip($this->agent, FakeSpaceTraders::lightHauler('GIANTSOUP-4', self::YARD));
        $governor = app(MeshGovernor::class);
        $initial = $governor->evaluate($this->agent, $episode);
        $this->assertSame([2880, 3, 4, 180], [$initial['limits']['hour'], $initial['sample']['traders'], $initial['sample']['formula_max_traders'], $initial['room']]);

        $this->haulerPurchase($episode, 'committed-hauler-delivery', state: 'sending');

        $status = $governor->evaluate($this->agent, $episode);
        $this->assertSame(180, $status['limits']['trader']);
        $this->assertSame(180, $status['room']);
    }

    private function seedStagedTraderBudget(CampaignEpisode $episode): void
    {
        $episode->update(['opened_at' => now()->subHours(7)]);
        AutomationRun::create([
            'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'campaign_episode_id' => $episode->id,
            'ship_symbol' => 'GIANTSOUP-1', 'strategy' => 'trade', 'state' => 'completed', 'started_at' => now()->subHours(6),
            'expires_at' => now(), 'plan' => ['symbol' => 'IRON_ORE'], 'totals' => [],
        ]);
        DB::table('fleet_metrics')->insert([
            ['kind' => 'requests', 'agent_symbol' => 'GIANTSOUP', 'scope' => 'agent', 'ship' => 'GIANTSOUP-1',
                'value' => 2400, 'data' => '{}', 'created_at' => now()->subHour()],
            ['kind' => 'requests', 'agent_symbol' => 'GIANTSOUP', 'scope' => 'agent', 'ship' => 'shared',
                'value' => 6600, 'data' => '{"workload":"director"}', 'created_at' => now()->subHour()],
        ]);
        config(['gameplay.request_budget_stage' => 'stage2']);
    }

    public function test_measured_staged_trader_headroom_limits_a_sustained_hour_of_optional_work(): void
    {
        $this->setUpSaturation(seedCoverage: false, agentSymbol: 'GIANTSOUP');
        $episode = $this->primary(revision: $this->meshRevision());
        $this->seedStagedTraderBudget($episode);
        $clock = microtime(true);
        $budget = new class($clock) extends RequestBudget
        {
            public function __construct(public float $clock) {}

            protected function now(): float
            {
                return $this->clock;
            }
        };
        $this->app->instance(RequestBudget::class, $budget);
        $governor = app(MeshGovernor::class);
        $initial = $governor->evaluate($this->agent, $episode);
        $this->assertSame(4, $initial['sample']['formula_max_traders']);
        $this->assertSame(980, $initial['limits']['trader']);
        file_put_contents(config('gameplay.request_budget_path').'/budget.json', json_encode(['buckets' => []]));
        $refused = 0;
        $optional = 0;
        $ordinaryRequests = 0;
        for ($minute = 0; $minute < 60; $minute++) {
            $budget->clock = $clock + 60 * $minute;
            $ordinary = $minute < 40 ? 32 : 31;
            RequestBudget::forAgent('GIANTSOUP', function () use ($budget, $ordinary) {
                for ($i = 0; $i < $ordinary; $i++) {
                    $budget->reserve(priority: $i < 20 ? 'run' : 'director', maxWait: 0);
                }
            });
            $ordinaryRequests += $ordinary;
            try {
                $governor->immediate($this->agent, $episode, 'scout', function () use ($budget, &$optional) {
                    for ($i = 0; $i < 17; $i++) {
                        $budget->reserve(priority: 'director', maxWait: 0);
                        $optional++;
                    }
                });
            } catch (RequestDeferred) {
                $refused++;
            }
        }
        $usage = $budget->usage(agentSymbol: 'GIANTSOUP', requireTelemetry: true);
        $this->assertSame(1900, $ordinaryRequests);
        $this->assertGreaterThan(0, $refused);
        $this->assertGreaterThan(600, $optional);
        $this->assertLessThanOrEqual(980, $optional);
        $this->assertSame($optional, $usage['mesh']['used']);
        $this->assertSame($ordinaryRequests + $optional, $usage['agent']['tier2_4_last_hour']);
        $this->assertLessThanOrEqual(2880, $usage['agent']['tier2_4_last_hour']);
    }

    public function test_deterministic_start_rejection_backs_off_only_the_scout_that_changed(): void
    {
        $this->setUpSaturation(seedCoverage: false, credits: 100000);
        $this->layout([
            'ROCK' => [0, []], 'P' => [1, []], 'A' => [50, ['IRON_ORE']], 'B' => [100, ['IRON_ORE']],
        ], ['LOCAL-2' => ['P', 9], 'LOCAL-3' => ['ROCK', 9]]);
        $episode = $this->primary(revision: $this->meshRevision());
        $changed = false;
        $hook = function () use (&$changed, $episode) {
            if (! $changed && AutomationRun::where('campaign_episode_id', $episode->id)->where('state', 'preview')->where('ship_symbol', 'LOCAL-2')->exists()) {
                $changed = true;
                $this->editFixture(function (&$world) {
                    $world['ships'][1]['nav']['status'] = 'IN_ORBIT';
                });
            }
        };
        $this->onBudgetReservation($hook);
        $this->tickC0();
        $this->assertTrue($changed);
        $this->assertSame(['LOCAL-2'], array_keys($episode->fresh()->state['mesh_dispatch_failed']));
        $refused = AutomationRun::where('ship_symbol', 'LOCAL-2')->where('plan->mesh_work', 'market')->sole();
        $this->assertSame('stopped', $refused->state->value);
        $this->assertFalse(ShipAction::where('automation_run_id', $refused->id)->exists());
        $this->assertTrue(AutomationRun::where('ship_symbol', 'LOCAL-3')->where('plan->mesh_work', 'market')->whereNotNull('started_at')->exists());
    }

    public function test_tier_two_only_opportunity_yields_the_slot_to_an_eligible_hauler(): void
    {
        $this->setUpSaturation(hubs: 4);
        $this->market('X1-TEST-FUEL', tier: 2);
        $this->yardPrice(25000, persist: true);
        $episode = $this->primary(revision: $this->meshRevision());
        $this->schedulerPass();
        $considered = [];
        $bootstrap = app(CampaignProbeBootstrap::class);
        $this->assertNull($bootstrap->plan($episode->grant(), $episode, $this->agent, [], $considered, microtime(true) + 15));
        $this->assertContains('tier2_yields_to_hauler', array_column($considered, 'hold'), json_encode($considered));
        $this->assertCount(0, $bootstrap->reservations($this->agent));
        $this->assertFalse(AutomationRun::where('strategy', 'buy_probe')->exists());
    }

    private function layout(array $points, array $probes): void
    {
        $this->editFixture(function (&$world) use ($points, $probes) {
            $world['waypoints'] = [];
            $world['market_goods'] = [];
            $world['shipyards'] = [];
            $world['ships'] = [$world['ships'][0]];
            foreach ($points as $name => [$x, $goods]) {
                $symbol = 'X1-TEST-'.$name;
                $world['waypoints'][] = $this->point($symbol, $x, $goods ? ['MARKETPLACE'] : []);
                $world['market_goods'][$symbol] = array_map(fn ($good) => ['symbol' => $good, 'tradeVolume' => 20, 'purchasePrice' => 100, 'sellPrice' => 75], $goods);
            }
            foreach ($probes as $ship => [$origin, $speed]) {
                $world['ships'][] = FakeSpaceTraders::probe($ship, 'X1-TEST-'.$origin, $speed);
            }
        });
        GameObservation::where('agent_id', $this->agent->id)->whereIn('kind', ['waypoint', 'market_listing', 'market'])->delete();
        $sync = app(AgentSynchronizer::class);
        $sync->sync($this->agent, false);
        foreach ($this->world()['waypoints'] as $point) {
            $symbol = $point['symbol'];
            $sync->observe($this->agent, 'waypoint', $symbol, $point, 'listing');
            $goods = $this->world()['market_goods'][$symbol];
            if ($goods) {
                $sync->observe($this->agent, 'market', $symbol, ['symbol' => $symbol, 'imports' => [], 'exports' => [],
                    'exchange' => array_map(fn ($good) => ['symbol' => $good['symbol']], $goods), 'tradeGoods' => $goods]);
            }
        }
    }

    private function meshBody(): array
    {
        return Yaml::parseFile(base_path('campaigns/weekly-system-saturation-mesh.yaml'));
    }

    private function meshRevision(array $overrides = []): CampaignRevision
    {
        $body = $this->meshBody();
        $body['probes']['mesh']['saturation'] = array_replace($body['probes']['mesh']['saturation'], $overrides);
        $body['agent']['symbol'] = $this->agent->name;
        $body['fleet']['auto_arm']['revision_hash'] = $this->armRevision->content_hash;

        return app(CampaignPlaybook::class)->import(Yaml::dump($body, 12));
    }

    private function market(string $symbol, int $tier = 1, bool $listing = true): void
    {
        $this->editFixture(function (&$world) use ($symbol, $tier) {
            if (! collect($world['waypoints'])->contains('symbol', $symbol)) {
                $world['waypoints'][] = $this->point($symbol, 12 + count($world['waypoints']));
            }
            $world['market_goods'][$symbol] = $tier === 2 ? [$world['goods'][1]] : $world['goods'];
        });
        $point = collect($this->world()['waypoints'])->firstWhere('symbol', $symbol);
        app(AgentSynchronizer::class)->observe($this->agent, 'waypoint', $symbol, $point, 'listing');
        if ($listing) {
            app(AgentSynchronizer::class)->observe($this->agent, 'market_listing', $symbol, [
                'symbol' => $symbol, 'imports' => [], 'exports' => [],
                'exchange' => array_map(fn ($good) => ['symbol' => $good['symbol']], $this->world()['market_goods'][$symbol]),
            ]);
        }
    }

    private function holdProbeForRecon(string $symbol): void
    {
        $terms = ['return_deadline' => now()->addHours(2)->toIso8601String()];
        ReconMission::create(['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => $symbol, 'state' => 'exploring',
            'terms' => $terms, 'terms_hash' => ReconMission::hash($terms), 'graph' => [],
            'origin_system' => 'X1-TEST', 'origin_gate' => self::YARD, 'approved_by' => $this->operator->id,
            'approved_at' => now(), 'start_window_until' => now()->addHour(), 'visits' => [], 'capability' => [], 'return_reservation' => [], 'homeward_authorizations' => []]);
    }

    private function addProbe(string $symbol, string $destination): void
    {
        $probe = FakeSpaceTraders::probe($symbol, $destination);
        $this->editFixture(fn (&$world) => $world['ships'][] = $probe);
        app(AgentSynchronizer::class)->saveShip($this->agent, $probe);
    }

    private function probe(string $symbol): array
    {
        return Ship::where('agent_id', $this->agent->id)->where('symbol', $symbol)->sole()->snapshot;
    }

    private function yardPrice(int $price, bool $persist = false): void
    {
        $this->editFixture(fn (&$world) => $world['shipyards'][self::YARD]['ships'][0]['purchasePrice'] = $price);
        if ($persist) {
            app(AgentSynchronizer::class)->observe($this->agent, 'shipyard', self::YARD, $this->world()['shipyards'][self::YARD]);
        }
    }

    private function purchaseIntent(CampaignEpisode $episode, string $ship = 'LOCAL-2'): array
    {
        $runner = app(AutomationRunner::class);
        $run = $runner->previewProbePurchase($this->agent, $ship, self::YARD, ['minutes' => 15], $episode);
        $runner->startCampaign($this->agent, $run, $episode);
        $runner->advance($run->id);
        $action = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->first();
        $this->assertNotNull($action, $run->fresh()->blocked_reason ?? 'Missing purchase intent.');

        return [$run, $action];
    }

    private function purchaseWrites(): int
    {
        return collect($this->world()['writes'])->where('path', '/my/ships')->count();
    }

    private function duringWrite(Closure $hook): void
    {
        $this->onBudgetReservation(function (string $priority) use ($hook) {
            if ($priority === 'write') {
                $hook();
            }
        });
    }

    private function onBudgetReservation(Closure $hook): void
    {
        $this->app->instance(RequestBudget::class, new class($hook) extends RequestBudget
        {
            public function __construct(private Closure $hook) {}

            public function reserve(?string $token = null, ?string $account = null, string $priority = 'discovery', float $maxWait = 0.75): void
            {
                parent::reserve($token, $account, $priority, $maxWait);
                ($this->hook)($priority);
            }
        });
    }

    private function seedArrival(CampaignEpisode $episode, string $ship, string $destination): void
    {
        $route = [
            'origin' => ['symbol' => 'X1-TEST-PORT'], 'destination' => ['symbol' => $destination],
            'arrival' => now()->subSeconds(10)->toIso8601String(),
        ];
        $this->editFixture(function (&$world) use ($ship, $route) {
            foreach ($world['ships'] as &$row) {
                if ($row['symbol'] === $ship) {
                    $row['nav']['route'] = $route;
                }
            }
        });
        app(AgentSynchronizer::class)->sync($this->agent, false);
        $run = AutomationRun::create([
            'agent_id' => $this->agent->id, 'campaign_episode_id' => $episode->id, 'reset_id' => $episode->reset_id,
            'ship_symbol' => $ship, 'strategy' => 'scout', 'state' => 'completed', 'started_at' => now()->subMinute(),
            'expires_at' => now()->addMinute(), 'plan' => ['mesh_work' => 'yard', 'destination' => $destination], 'totals' => [],
        ]);
        ShipAction::create([
            'automation_run_id' => $run->id, 'agent_id' => $this->agent->id, 'reset_id' => $episode->reset_id,
            'key' => 'mesh-arrival-'.$ship, 'ship_symbol' => $ship, 'origin' => 'automation', 'command' => 'navigate',
            'state' => 'confirmed', 'arguments' => ['waypointSymbol' => $destination],
            'outcome' => ['travel' => ['server_route' => $route]], 'expires_at' => now()->addMinute(), 'sent_at' => now()->subMinute(),
        ]);
    }
}
