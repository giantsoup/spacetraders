<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignMesh;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\CampaignProbeBootstrap;
use App\Gameplay\CampaignSaturation;
use App\Gameplay\CampaignTrade;
use App\Gameplay\CreditAccounting;
use App\Gameplay\FleetMetrics;
use App\Gameplay\GameException;
use App\Gameplay\HostLock;
use App\Gameplay\IdleLedger;
use App\Gameplay\MeshGovernor;
use App\Gameplay\MiningCluster;
use App\Gameplay\MiningPlanner;
use App\Gameplay\RequestBudget;
use App\Gameplay\RequestDeferred;
use App\Gameplay\RequestShadowPrice;
use App\Gameplay\ShipCommandExecutor;
use App\Gameplay\SupplyChainStimulus;
use App\Gameplay\TraderAssigner;
use App\Jobs\ExecuteShipCommand;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\CampaignRevision;
use App\Models\LocalEngine;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Services\SpaceTradersClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\FakeSpaceTraders;
use Tests\Support\SaturationFixture;
use Tests\TestCase;

final class FleetSaturationGovernanceReplayTest extends TestCase
{
    use RefreshDatabase;
    use SaturationFixture;

    protected function tearDown(): void
    {
        $this->tearDownSaturation();
        parent::tearDown();
    }

    public function test_v9_jettison_requires_strict_value_and_preserves_the_hourly_cap(): void
    {
        $cluster = app(MiningCluster::class);
        $evaluate = fn (array $changes = []) => $cluster->evaluateJettison(...array_replace([
            'cargoInventory' => ['ICE_WATER' => 10],
            'netBidsByGood' => ['ICE_WATER' => 50],
            'allowlist' => ['ICE_WATER'],
            'freeHeadroom' => 4,
            'p95Headroom' => 5,
            'tenderDueWithinCooldown' => false,
            'yieldUnits' => 5,
            'evActive' => 101,
            'shadowPrice' => 4,
            'removedThisHourCredits' => 4500,
        ], $changes));

        $this->assertSame([
            'good' => 'ICE_WATER', 'units' => 10, 'opportunity_cost_credits' => 500, 'cash_delta' => 0,
        ], $evaluate());
        $this->assertNull($evaluate(['shadowPrice' => 5]), 'Equality cannot authorize jettison.');
        $this->assertNull($evaluate(['shadowPrice' => 6]));
        $this->assertNull($evaluate(['removedThisHourCredits' => 4501, 'hourlyCap' => 6000]));
        $this->assertNull($evaluate(['allowlist' => ['QUARTZ_SAND']]));
        $this->assertNull($evaluate([
            'cargoInventory' => ['MICROPROCESSORS' => 10],
            'netBidsByGood' => ['MICROPROCESSORS' => 1],
            'allowlist' => ['MICROPROCESSORS'],
        ]));
        $this->assertNull($evaluate(['protectedGoods' => ['ICE_WATER']]));
        $this->assertNull($evaluate(['tenderDueWithinCooldown' => true]));
        $this->assertNull($evaluate(['freeHeadroom' => 5]));
        $this->assertNull($evaluate(['windDown' => true]));
    }

    #[DataProvider('jettisonCargo')]
    public function test_v9_executor_requires_attributable_ore_and_settles_once_at_zero_cash(string $origin): void
    {
        $this->setUpSaturation();
        $episode = $this->primary(revision: $this->fleetRevision([
            'fleet.saturation.extraction.enabled' => true, 'fleet.saturation.extraction.stationary' => true,
        ]));
        $ship = FakeSpaceTraders::ship('LOCAL-9');
        $ship['cargo'] = ['capacity' => 15, 'units' => 10, 'inventory' => [['symbol' => 'ICE_WATER', 'units' => 10]]];
        $this->editFixture(function (array &$world) use ($ship, $origin) {
            $world['ships'][] = $ship;
            $world['foundation'] = true;
            if ($origin === 'protected') {
                $world['contracts'][0]['accepted'] = true;
                $world['contracts'][0]['terms']['deliver'][0]['tradeSymbol'] = 'ICE_WATER';
            }
        });
        app(AgentSynchronizer::class)->saveShip($this->agent, $ship);
        $run = $this->storedRun($episode, 'LOCAL-9', 'mining', [
            'stationary' => true, 'cluster_id' => 'north-field', 'site' => 'X1-TEST-ROCK', 'extraction_headroom' => 7,
            'jettison' => ['net_bids' => ['ICE_WATER' => 50], 'tender_due' => false, 'yield_units' => 5, 'ev_active' => 101, 'shadow_price' => 4],
        ], [
            'phase' => 'mine', 'credit_delta' => 0, 'unsold' => ['ICE_WATER' => 10],
            'expected_state' => ['waypoint' => $ship['nav']['waypointSymbol'], 'status' => $ship['nav']['status'], 'fuel' => $ship['fuel']['current']],
            $origin === 'purchased' ? 'purchased' : 'extracted' => ['ICE_WATER' => 10],
        ]);
        if ($origin === 'wind_down') {
            $episode->update(['phase' => 'wind_down']);
        }
        if (in_array($origin, ['same_cluster', 'other_cluster'], true)) {
            $priorRun = $this->storedRun($episode, 'LOCAL-A', 'mining', [
                'cluster_id' => $origin === 'same_cluster' ? 'north-field' : 'south-field',
            ], attributes: ['state' => 'completed', 'active_agent_id' => null]);
            $this->pendingAction($priorRun, 'jettison', ['symbol' => 'QUARTZ_SAND', 'units' => 12], [
                'estimate' => ['jettison' => ['opportunity_cost_credits' => 4800]],
            ])->update(['state' => 'confirmed', 'sent_at' => now()->subMinutes(20), 'settled_at' => now()->subMinutes(20)]);
        }
        $action = $this->pendingAction($run, 'jettison', ['symbol' => 'ICE_WATER', 'units' => 10, 'acknowledgeLoss' => true]);
        $executor = app(ShipCommandExecutor::class);
        $before = $this->world()['credits'];
        $executor->execute($action->id);
        $executor->execute($action->id);
        $writes = collect($this->world()['calls'])->where('method', 'POST')->where('path', '/my/ships/LOCAL-9/jettison');
        $this->assertSame($before, $this->world()['credits']);
        if (! in_array($origin, ['extracted', 'other_cluster'], true)) {
            $this->assertCount(0, $writes, "Jettison reached the provider with {$origin} cargo.");
            $this->assertNull($action->refresh()->sent_at);

            return;
        }
        $this->assertSame('confirmed', $action->refresh()->state->value, $action->message ?? '');
        $this->assertCount(1, $writes);
        $this->assertSame([], $run->refresh()->totals['unsold']);
        $this->assertSame(['ICE_WATER' => 10], $run->totals['jettisoned']);
        $this->assertSame(500, $run->totals['jettison_opportunity_cost']);
        $this->assertSame(0, $run->totals['cash_contribution']);
        $this->assertSame(0, app(CreditAccounting::class)->outsideRunDelta($this->agent, $run));
        $this->assertDatabaseMissing('confirmed_credit_entries', ['ship_action_id' => $action->id]);
    }

    public static function jettisonCargo(): array
    {
        return [
            'mined cargo' => ['extracted'], 'contract cargo' => ['protected'], 'purchased cargo' => ['purchased'],
            'wind-down' => ['wind_down'], 'cap in the same cluster' => ['same_cluster'], 'cap in a different cluster' => ['other_cluster'],
        ];
    }

    public function test_v10_uses_only_settled_trailing_six_hour_export_value(): void
    {
        $this->setUpSaturation();
        $episode = $this->primary();
        foreach ([
            [15192, -7014, []], [26148, -6606, []], [12808, -6165, []],
            [90000, -21600, []], [90000, 1, []], [90000, -1800, ['settled' => false]],
            [90000, -1800, ['source' => 'X1-SK70-B7']], [90000, -1800, ['good' => 'COPPER']],
        ] as [$net, $seconds, $changes]) {
            $at = now()->addSeconds($seconds);
            $this->load($episode, $changes + [
                'net' => $net, 'source' => 'X1-SK70-A3', 'good' => 'MICROPROCESSORS',
                'finished_at' => $at->toIso8601String(), 'settled' => true,
            ], ['created_at' => $at]);
        }
        $stimulus = app(SupplyChainStimulus::class);
        $rate = $stimulus->trailingSixHourRate($this->agent, 'X1-SK70-A3', 'MICROPROCESSORS');
        $this->assertSame(9024, $rate);
        $this->assertSame(0, $stimulus->trailingSixHourRate($this->agent, 'X1-SK70-A3', 'FUEL'));
        $exports = [['v_hat' => $rate, 'activity' => 'RESTRICTED', 'input_supply' => 'SCARCE']];
        $this->assertSame(1082, $stimulus->bonus($exports, 20, 30, 1));
        $this->assertSame(180, $stimulus->bonus($exports, 20, 25, 1));
        $this->assertSame(0, $stimulus->bonus($exports, 20, 24, 1));
        $this->assertSame(0, $stimulus->bonus($exports, 20, 23, 1));
        $this->assertSame(10000, $stimulus->bonus($exports, 20, 30, 12));
        $this->assertSame(400, $stimulus->bonus($exports, 20, 30, 1, 400));
    }

    public function test_v10_competing_downside_reservations_and_receipts_charge_realized_loss_once(): void
    {
        $this->setUpSaturation();
        $episode = $this->primary(revision: $this->fleetRevision(['fleet.saturation.stimulation.enabled' => true]));
        $episode->update(['start_credits' => 600000]);
        $stimulus = app(SupplyChainStimulus::class);
        $this->assertSame(6000, $stimulus->episodeBudget($episode->start_credits));
        $earlierRun = $this->storedRun($episode, 'LOCAL-1', 'trade', attributes: [
            'state' => 'stopped', 'active_agent_id' => null, 'started_at' => null,
        ]);
        $first = $this->reserveStimulus($episode, risk: 1000, run: $earlierRun);
        $this->assertIsInt($first);
        $stimulus->recordDelivery($first, 1000);

        $run = $this->storedRun($episode, 'LOCAL-1', 'trade', [
            'legs' => [['good' => 'COPPER', 'source' => 'X1-TEST-ROCK', 'buyer' => 'X1-TEST-PORT', 'approvedUnits' => 10]],
        ], ['leg_purchased' => [10], 'leg_purchase_cost' => [4900], 'leg_fuel_cost' => [100]]);
        $winner = $this->reserveStimulus($episode, risk: 4000, run: $run);
        $this->assertIsInt($winner);
        $this->assertSame($winner, $this->reserveStimulus($episode, risk: 4000, run: $run));
        $loser = $this->storedRun($episode, 'LOCAL-1', 'trade', attributes: [
            'state' => 'stopped', 'active_agent_id' => null, 'started_at' => null,
        ]);
        $this->assertNull($this->reserveStimulus($episode, risk: 4000, run: $loser));
        $this->assertDatabaseCount('stimulus_entries', 2);
        $run->update(['plan' => $run->plan + ['stimulus_entry_id' => $winner]]);
        $ship = FakeSpaceTraders::ship('LOCAL-1', 'X1-TEST-PORT');
        $firstSale = $this->pendingAction($run, 'sell', ['symbol' => 'COPPER', 'units' => 5], [
            'credits' => $this->agent->credits, 'ship' => $ship,
        ]);
        $accounting = app(CreditAccounting::class);
        $accounting->apply($run, $firstSale, $ship, ['units' => 5], 1750, 1750);
        $this->assertDatabaseHas('stimulus_entries', ['id' => $winner, 'state' => 'reserved', 'downside_reserved' => 4000]);
        $finalSale = $this->pendingAction($run, 'sell', ['symbol' => 'COPPER', 'units' => 5], [
            'credits' => $this->agent->credits, 'ship' => $ship,
        ], key: 'final-stimulus-sale');
        $accounting->apply($run, $finalSale, $ship, ['units' => 5], 1750, 1750);
        $accounting->apply($run, $finalSale, $ship, ['units' => 5], 1750, 1750);
        $run->update(['state' => 'completed', 'active_agent_id' => null]);
        ShipAction::whereKey([$firstSale->id, $finalSale->id])->update(['state' => 'confirmed', 'settled_at' => now()]);
        $this->tickC0();
        $this->tickC0();
        $this->assertDatabaseHas('campaign_events', ['kind' => 'load_result', 'data->run_id' => $run->id, 'data->net' => -1500]);
        $this->assertDatabaseHas('stimulus_entries', [
            'id' => $winner, 'state' => 'delivered', 'downside_reserved' => 0, 'realized_loss' => 1500,
        ]);
        $this->assertSame(3500, $run->refresh()->totals['leg_sale_proceeds'][0]);
        $this->assertNull($this->reserveStimulus($episode, risk: 3501));
        $this->assertIsInt($this->reserveStimulus($episode, risk: 3500));
    }

    public function test_v10_bonus_window_and_effectiveness_block_new_credited_deliveries(): void
    {
        $this->setUpSaturation();
        $episode = $this->primary();
        $stimulus = app(SupplyChainStimulus::class);
        $baseline = ['exports' => ['MICROPROCESSORS' => ['activity' => 'RESTRICTED', 'supply' => 'SCARCE', 'tradeVolume' => 10, 'ask' => 1000]]];
        $this->assertNull($this->reserveStimulus($episode, risk: 1, cycleCap: 0));
        $this->assertNull($this->reserveStimulus($episode, windDown: true));
        $first = $this->reserveStimulus($episode, bonus: 10000, baseline: $baseline);
        $this->assertIsInt($first);
        $stimulus->recordDelivery($first, 0);
        $this->assertNull($this->reserveStimulus($episode, bonus: 1));
        $this->assertIsInt($this->reserveStimulus($episode, bonus: 10000, market: 'X1-TEST-H3'));
        $this->assertIsInt($this->reserveStimulus($episode, bonus: 10000, good: 'SILICON'));
        $this->travel(31)->minutes();
        $this->assertNull($this->reserveStimulus($episode, bonus: 1), 'Elapsed time alone cannot reopen an unmeasured window.');
        $this->assertSame('pending', $stimulus->evaluateEffectiveness($first, []));
        $effective = $baseline;
        $effective['exports']['MICROPROCESSORS']['ask'] = 970;
        $this->assertSame('effective', $stimulus->evaluateEffectiveness($first, $effective));
        $pending = [];
        for ($delivery = 0; $delivery < 3; $delivery++) {
            $id = $this->reserveStimulus($episode, bonus: 1000, baseline: $baseline);
            $this->assertIsInt($id);
            $stimulus->recordDelivery($id, 0);
            $pending[] = $id;
        }
        $this->assertNull($this->reserveStimulus($episode, bonus: 1));
        foreach ($pending as $index => $id) {
            $this->assertSame('ineffective', $stimulus->evaluateEffectiveness($id, $baseline));
            $this->assertSame($index === 2, $stimulus->isBetaDisabled($this->agent, $episode->id));
        }
        $this->travel(31)->minutes();
        $this->assertNull($this->reserveStimulus($episode, bonus: 1, good: 'ALUMINUM'));
    }

    public function test_v10_off_market_recovery_charges_the_episode_loss_without_measuring_stimulus(): void
    {
        $this->setUpSaturation(hubs: 3);
        $episode = $this->primary(revision: $this->fleetRevision(['fleet.saturation.stimulation.enabled' => true]));
        $episode->update(['start_credits' => 600000]);
        $pinned = 'X1-TEST-PORT';
        $actual = 'X1-TEST-H3';
        $run = $this->storedRun($episode, 'LOCAL-1', 'trade', [
            'legs' => [['good' => 'COPPER', 'source' => 'X1-TEST-ROCK', 'buyer' => $pinned, 'approvedUnits' => 10]],
        ], ['leg_purchased' => [10], 'leg_purchase_cost' => [4900], 'leg_fuel_cost' => [100]], attributes: [
            'state' => 'stopped', 'active_agent_id' => null,
        ]);
        $entry = $this->reserveStimulus($episode, risk: 4000, bonus: 1000, run: $run);
        $this->assertIsInt($entry);
        $run->update(['plan' => $run->plan + ['stimulus_entry_id' => $entry]]);
        $recovery = $this->storedRun($episode, 'LOCAL-1', 'sell_aboard', [
            'sell_only' => true, 'recovery_of' => $run->id, 'recovery_leg_index' => 0, 'market' => $actual,
        ], ['sold' => ['COPPER' => 10], 'sale_proceeds_by_good' => ['COPPER' => 3500]], attributes: [
            'state' => 'completed', 'active_agent_id' => null,
        ]);
        $receipt = $this->pendingAction($recovery, 'sell', ['symbol' => 'COPPER', 'units' => 10], [
            'ship' => FakeSpaceTraders::ship('LOCAL-1', $actual),
        ]);
        $receipt->update(['state' => 'confirmed', 'settled_at' => now(), 'outcome' => ['units' => 10, 'transaction_total' => 3500]]);

        $this->tickC0();
        $result = CampaignEvent::where('campaign_episode_id', $episode->id)->where('kind', 'load_result')->where('data->run_id', $run->id)->sole()->data;
        $this->assertSame($pinned, $result['buyer']);
        $this->assertSame([$actual], $result['actual_buyers']);
        $this->assertFalse($result['delivered_to_pinned_buyer']);
        $this->assertSame(-1500, $result['net']);
        $this->assertDatabaseHas('stimulus_entries', [
            'id' => $entry, 'state' => 'released', 'delivered_at' => null, 'measured_at' => now(),
            'realized_loss' => 1500, 'downside_reserved' => 0, 'bonus_reserved' => 0,
        ]);
        $this->assertNull($this->reserveStimulus($episode, risk: 4501));
        $this->assertIsInt($this->reserveStimulus($episode, risk: 4500));
        $stimulus = app(SupplyChainStimulus::class);
        $this->assertSame('released', $stimulus->evaluateEffectiveness($entry, []));
        $this->assertSame(20, $stimulus->betaPermille($this->agent, $episode->id, 20));
    }

    #[DataProvider('campaignAuthority')]
    public function test_v11_legacy_import_hashes_and_offline_versioned_status(bool $active): void
    {
        $this->setUpSaturation(agentSymbol: 'GIANTSOUP');
        $playbook = app(CampaignPlaybook::class);
        foreach ([
            'weekly-system-saturation' => 'b78744f8fcba21d1f4eb0bc7dfc5267e8071b1a7fe03f71b0dd2bcf91d0acdaa',
            'weekly-system-saturation-mesh' => 'ade393fdc896aef030afaee7152f4e17f5a7e741d1b4c86ace5b84344a74c041',
            'arm-trade' => '68d1f4ab8b254ff4ccb9acc4f5ef7821026979353b25334dec3986054e9196a8',
        ] as $name => $hash) {
            $this->assertSame($hash, $playbook->import($this->playbookSource($name))->content_hash);
        }
        $arm = $playbook->import($this->playbookSource('arm-trade-fleet'));
        $revision = $playbook->import($this->playbookSource('weekly-system-saturation-fleet'));
        $this->assertSame($arm->content_hash, $revision->body['fleet']['auto_arm']['revision_hash']);
        $this->assertSame(['FRAME_LIGHT_FREIGHTER', 'FRAME_SHUTTLE'], $revision->body['fleet']['saturation']['auto_arm_frames']);
        $flags = Arr::dot($revision->body['fleet']['saturation']);
        $this->assertSame([
            'enabled' => true, 'compression' => true, 'per_ship_noncredit_locks' => false,
            'corridor.chains_enabled' => false, 'stimulation.enabled' => false,
            'extraction.enabled' => false, 'extraction.stationary' => false,
        ], Arr::only($flags, ['enabled', 'compression', 'per_ship_noncredit_locks', 'corridor.chains_enabled',
            'extraction.enabled', 'extraction.stationary', 'stimulation.enabled']));
        if ($active) {
            $this->primary(revision: $revision);
        }
        $calls = $this->world()['calls'];

        $this->assertSame(0, Artisan::call('campaign:saturation', [
            '--agent' => 'GIANTSOUP', '--revision' => $revision->content_hash, '--json' => true,
        ]));
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('GIANTSOUP', $result['agent']);
        $this->assertArrayHasKey('gates', $result);
        $this->assertSame($calls, $this->world()['calls']);
    }

    public static function campaignAuthority(): array
    {
        return ['with an active grant' => [true], 'without an active grant' => [false]];
    }

    #[DataProvider('invalidVersionedPolicies')]
    public function test_v11_import_rejects_invalid_versioned_policy(array $changes): void
    {
        $body = Yaml::parse($this->playbookSource('weekly-system-saturation-fleet'));
        foreach ($changes as $key => $value) {
            Arr::set($body, 'fleet.saturation.'.$key, $value);
        }

        $this->expectException(GameException::class);
        app(CampaignPlaybook::class)->import(Yaml::dump($body, 12));
    }

    public static function invalidVersionedPolicies(): array
    {
        return [
            'unknown key' => [['unrecognized' => 1]],
            'price preflight' => [['price_preflight_max_seconds' => 11]],
            'run hard cap' => [['run_hard_cap_seconds' => 5401]],
            'chain legs' => [['corridor.max_chain_legs' => 4]],
            'calibration receipts' => [['extraction.canary_receipts' => 19]],
            'hysteresis order' => [['lambda.low_permille' => 850]],
            'stationary needs extraction' => [['extraction.stationary' => true, 'extraction.enabled' => false]],
            'matching auto-arm frames' => [['auto_arm_frames' => ['FRAME_LIGHT_FREIGHTER']]],
        ];
    }

    public function test_v11_staged_bootstrap_runs_owned_c0_proof_before_purchases(): void
    {
        $this->setUpSaturation(agentSymbol: 'GIANTSOUP');
        $episode = $this->primary('discover', $this->fleetRevision());
        config(['gameplay.request_budget_stage' => 'stage1']);
        $governor = app(MeshGovernor::class);
        $this->assertSame('insufficient_sample_window', $governor->evaluate($this->agent, $episode)['reason']);
        $this->assertPurchaseAdmissionHeld($episode);

        $this->tickC0();
        $this->tickC0();
        $proof = AutomationRun::where('campaign_episode_id', $episode->id)
            ->where('strategy', 'trade')->where('plan->fleet_saturation_proof', 'proof')->first();
        $this->assertNotNull($proof, json_encode($episode->refresh()->state, JSON_THROW_ON_ERROR));
        $this->assertSame('GIANTSOUP-1', $proof->ship_symbol);
        $this->workRun($proof);
        $this->assertSame('completed', $proof->refresh()->state->value, $proof->blocked_reason ?? '');
        $this->tickC0();
        $this->assertDatabaseHas('campaign_events', ['campaign_episode_id' => $episode->id, 'kind' => 'load_result', 'data->proof' => true]);
        $this->assertDatabaseMissing('ship_actions', ['command' => 'buy_ship']);
        $this->assertPurchaseAdmissionHeld($episode);
    }

    public function test_v11_staged_purchase_requires_a_full_tagged_sample_with_a_trader_hour(): void
    {
        $this->setUpSaturation(agentSymbol: 'GIANTSOUP');
        FleetMetrics::reset();
        $episode = $this->primary(revision: $this->fleetRevision());
        config(['gameplay.request_budget_stage' => 'stage1']);
        $governor = app(MeshGovernor::class);
        $this->assertSame('insufficient_sample_window', $governor->evaluate($this->agent, $episode)['reason']);
        $episode->update(['opened_at' => now()->subHours(6)]);
        $this->assertSame('insufficient_trader_hours', $governor->evaluate($this->agent, $episode)['reason']);
        $sample = $this->seedTradingSample($episode, activeMinutes: 59);
        $this->assertSame('insufficient_trader_hours', $governor->evaluate($this->agent, $episode)['reason']);
        $sample->update(['started_at' => now()->subHour()]);
        $this->assertSame('normal', $governor->evaluate($this->agent, $episode)['mode']);
        $this->taggedRequest();
        $this->assertSame(1, app(FleetMetrics::class)->untaggedRequestCount('GIANTSOUP'));
        $status = $governor->evaluate($this->agent, $episode);
        $this->assertSame('sampling', $status['mode']);
        $this->assertSame('untagged_request_telemetry', $status['reason']);
        $this->assertPurchaseAdmissionHeld($episode);
    }

    public function test_v11_workload_rates_attribute_borrowing_and_restore_outer_tags(): void
    {
        $this->setUpSaturation();
        FleetMetrics::reset();
        FleetMetrics::forAgent('LOCAL', function () {
            FleetMetrics::withWorkload('trade', function () {
                FleetMetrics::count('GET', '/my/ships/LOCAL-1', ordinary: true);
                FleetMetrics::withWorkload('settlement', function () {
                    FleetMetrics::count('POST', '/my/ships/LOCAL-1/sell', ordinary: false);
                    FleetMetrics::count('POST', '/my/ships/LOCAL-1/sell', ordinary: true);
                }, borrowed: true);
                FleetMetrics::count('POST', '/my/ships/LOCAL-1/purchase', ordinary: true);
            });
            FleetMetrics::withWorkload('contracts', fn () => FleetMetrics::count('GET', '/my/contracts', ordinary: true));
            FleetMetrics::withWorkload('probe', fn () => FleetMetrics::count('GET', '/my/ships/LOCAL-2', ordinary: true));
            FleetMetrics::withWorkload('typo', fn () => FleetMetrics::count('GET', '/my/agent', ordinary: true));
        }, $this->agent->reset_id);
        $metrics = app(FleetMetrics::class);
        $this->assertSame(1, $metrics->untaggedRequestCount('LOCAL'));
        FleetMetrics::flush();
        $this->assertSame(1, $metrics->untaggedRequestCount('LOCAL'));
        $rates = $metrics->workloadRates('LOCAL', 1800);
        $this->assertSame(1, $rates['untagged_requests']);
        $this->assertEquals([
            'manual' => ['requests' => 1, 'ordinary' => 1, 'borrowed' => 0, 'requests_per_hour' => 2, 'ordinary_per_hour' => 2],
            'scout' => ['requests' => 1, 'ordinary' => 1, 'borrowed' => 0, 'requests_per_hour' => 2, 'ordinary_per_hour' => 2],
            'settlement' => ['requests' => 2, 'ordinary' => 1, 'borrowed' => 1, 'requests_per_hour' => 4, 'ordinary_per_hour' => 2],
            'trade' => ['requests' => 2, 'ordinary' => 2, 'borrowed' => 0, 'requests_per_hour' => 4, 'ordinary_per_hour' => 4],
        ], $rates['workloads']);
        $this->assertSame(['requests' => 4, 'ordinary' => 3, 'borrowed' => 1, 'requests_per_hour' => 8, 'ordinary_per_hour' => 6], $rates['overhead']);
        $this->assertDatabaseHas('fleet_metrics', ['agent_symbol' => 'LOCAL', 'data->workload' => 'trade', 'data->reset_id' => $this->agent->reset_id]);
    }

    public function test_v11_directed_legacy_probe_purchase_keeps_governor_normal_and_respects_shared_sync(): void
    {
        $this->setUpSaturation(agentSymbol: 'GIANTSOUP');
        $this->editFixture(fn (array &$world) => $world['ships'] = array_values(array_filter($world['ships'], fn (array $ship) => $ship['symbol'] !== 'GIANTSOUP-5')));
        Ship::where('symbol', 'GIANTSOUP-5')->delete();
        $episode = $this->primary(revision: $this->fleetRevision(['probes.mesh.max_probes' => 4]));
        $this->seedTradingSample($episode);
        config(['gameplay.request_budget_stage' => 'stage1', 'gameplay.inline_execute' => false]);
        $governor = app(MeshGovernor::class);
        $this->assertSame('normal', $governor->evaluate($this->agent, $episode)['mode']);
        $yard = 'X1-TEST-ROCK';
        app(AgentSynchronizer::class)->observe($this->agent, 'shipyard', $yard, $this->world()['shipyards'][$yard]);

        $this->tickC0();
        $purchase = AutomationRun::where('campaign_episode_id', $episode->id)->where('strategy', 'buy_probe')->whereNotNull('active_agent_id')->first();
        $this->assertNotNull($purchase, json_encode([
            'grant' => $episode->grant()->only(['pause_reason', 'wait_reason']),
            'events' => CampaignEvent::where('campaign_grant_id', $episode->campaign_grant_id)->get(['kind', 'data']),
            'failures' => DB::table('game_runtime')->where('key', 'like', '%director_failures%')->get(),
        ], JSON_THROW_ON_ERROR));
        $this->assertLessThan(8, $purchase->plan_version);
        $this->workQueuedRun($purchase);
        $receipt = ShipAction::where('automation_run_id', $purchase->id)->where('command', 'buy_ship')->sole();
        $this->assertSame('confirmed', $receipt->state->value, $receipt->message ?? '');
        FleetMetrics::flush();
        $categories = DB::table('fleet_metrics')->where('kind', 'requests')->where('agent_symbol', 'GIANTSOUP')
            ->where('data->workload', 'buy_probe')->pluck('data')
            ->sum(fn (string $data) => json_decode($data, true, flags: JSON_THROW_ON_ERROR)['categories']['write:buy_ship'] ?? 0);
        $this->assertSame(1, $categories);

        // Reconcile the receipt before measuring steady-state aggregate reads.
        $this->tickC0();
        $key = 'fleet_sync_at:'.$this->agent->id;
        $lastSync = DB::table('game_runtime')->where('key', $key)->value('value');
        $fleetCalls = fn () => collect($this->world()['calls'])->where('method', 'GET')->where('path', '/my/ships')->count();
        $before = $fleetCalls();
        foreach ([120, 180, 270, 300] as $seconds) {
            $this->travelTo(CarbonImmutable::parse($lastSync)->addSeconds($seconds));
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
            $this->assertTrue(app(CampaignDirector::class)->tick(), "{$seconds}s after the shared sync at {$lastSync}");
            $this->assertSame(0, app(FleetMetrics::class)->untaggedRequestCount('GIANTSOUP'));
            $this->assertSame('normal', $governor->evaluate($this->agent, $episode)['mode']);
            if ($seconds < 300) {
                $this->assertSame($lastSync, DB::table('game_runtime')->where('key', $key)->value('value'));
                $this->assertSame($before, $fleetCalls());
            }
        }
        $this->assertGreaterThan($before, $fleetCalls());
    }

    public function test_v10_director_admits_settles_and_measures_a_bounded_loss_stimulus(): void
    {
        $this->setUpSaturation(hubs: 2);
        config(['gameplay.inline_execute' => false]);
        $episode = $this->primary(revision: $this->fleetRevision([
            'fleet.saturation.stimulation.enabled' => true, 'fleet.saturation.stimulation.cycle_loss_credits' => 100,
        ]));
        $source = 'X1-TEST-ROCK';
        $buyer = 'X1-TEST-PORT';
        $export = 'MICROPROCESSORS';
        $this->editFixture(function (array &$world) use ($buyer, $export) {
            $world['market_goods'][$buyer][0]['sellPrice'] = 99;
            $world['market_goods'][$buyer][0]['supply'] = 'SCARCE';
            $world['market_goods'][$buyer][] = ['symbol' => $export, 'type' => 'EXPORT', 'activity' => 'RESTRICTED',
                'supply' => 'LIMITED', 'tradeVolume' => 10, 'purchasePrice' => 1000, 'sellPrice' => 800];
        });
        $this->observeFixtureMarket($buyer);
        app(AgentSynchronizer::class)->observe($this->agent, 'supply_chain', 'X1-TEST',
            ['exportToImportMap' => [$export => ['IRON_ORE']]]);
        $this->load($episode, ['run_id' => -1, 'good' => $export, 'source' => $buyer, 'buyer' => $source,
            'net' => 60000, 'finished_at' => now()->subMinute()->toIso8601String()]);

        $this->tickC0();
        $run = AutomationRun::where('campaign_episode_id', $episode->id)->where('strategy', 'trade')->whereNotNull('started_at')->first();
        $this->assertNotNull($run, json_encode(CampaignEvent::where('campaign_episode_id', $episode->id)->where('kind', 'decision')->pluck('data')));
        $entry = DB::table('stimulus_entries')->where('id', $run->plan['stimulus_entry_id'])->first();
        $this->assertSame('reserved', $entry->state);
        $this->assertGreaterThan(0, $entry->downside_reserved);
        $this->assertLessThanOrEqual(100, $entry->downside_reserved);
        $this->assertSame(-$run->plan['legs'][0]['prediction']['predicted_margin'], $entry->downside_reserved);
        $this->assertSame($entry->downside_reserved, $run->plan['stimulus']['loss_auth']);
        $this->assertGreaterThan($entry->downside_reserved, $entry->bonus_reserved);
        $baseline = json_decode($entry->baseline_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['activity' => 'RESTRICTED', 'supply' => 'LIMITED', 'tradeVolume' => 10, 'ask' => 1000], $baseline['exports'][$export]);
        $this->assertGreaterThan(0, $run->plan['legs'][0]['minSellPrice']);
        $this->workQueuedRun($run);
        $this->assertSame('completed', $run->refresh()->state->value, $run->blocked_reason ?? '');
        $this->assertDatabaseHas('ship_actions', ['automation_run_id' => $run->id, 'command' => 'sell', 'state' => 'confirmed']);

        $this->editFixture(function (array &$world) use ($buyer) {
            $world['market_goods'][$buyer][2]['activity'] = 'WEAK';
        });
        $this->observeFixtureMarket($buyer);
        $this->tickC0();
        $result = CampaignEvent::where('campaign_episode_id', $episode->id)->where('kind', 'load_result')->where('data->run_id', $run->id)->sole()->data;
        $this->assertLessThan(0, $result['net']);
        $this->assertDatabaseHas('stimulus_entries', [
            'id' => $entry->id, 'state' => 'effective', 'downside_reserved' => 0, 'realized_loss' => -$result['net'],
        ]);
    }

    public function test_v9_director_preselects_from_verified_arrival_and_credits_only_the_pipeline_gap(): void
    {
        $this->setUpSaturation(hubs: 2);
        config(['gameplay.inline_execute' => false]);
        $primary = $this->primary(revision: $this->fleetRevision());
        $this->storedRun($primary, 'LOCAL-1', 'position');
        $ship = FakeSpaceTraders::lightHauler('LOCAL-A', 'X1-TEST-ROCK');
        $this->editFixture(function (array &$world) use ($ship) {
            $world['ships'][] = $ship;
            $world['travel_seconds'] = 50;
        });
        app(AgentSynchronizer::class)->saveShip($this->agent, $ship);
        $arm = $this->arm($ship['symbol'], 'H1');
        $episode = $this->seedArmEpisode($arm);
        $episode->update(['opened_at' => now()->subHour()]);
        $director = app(CampaignDirector::class);
        $this->assertTrue($director->tickArms());
        $run = AutomationRun::where('campaign_episode_id', $episode->id)->where('strategy', 'trade')->whereNotNull('started_at')->sole();
        $location = fn () => Ship::where('symbol', $ship['symbol'])->sole()->snapshot['nav']['status'];
        for ($step = 0; $step < 12 && $location() !== 'IN_TRANSIT'; $step++) {
            $this->queuedAdvance($run);
            $this->advanceClock(5);
        }
        $this->assertSame('deliver', $run->refresh()->totals['phase']);
        $this->assertSame('IN_TRANSIT', $location());
        $this->advanceClock(30);
        $before = DB::table('corridor_book_events')->where('owner_type', 'run')->where('state', 'reserved')->count();
        $this->assertTrue($director->tickArms());
        $selection = $episode->refresh()->state['d5_preselected'][$ship['symbol']] ?? null;
        $this->assertNotNull($selection);
        $this->assertSame('X1-TEST-ROCK|X1-TEST-PORT|IRON_ORE', $selection['corridor']);
        $this->assertSame($before, DB::table('corridor_book_events')->where('owner_type', 'run')->where('state', 'reserved')->count());
        $this->assertSame(1, AutomationRun::where('campaign_episode_id', $episode->id)->whereNotNull('started_at')->count());
        $this->workQueuedRun($run);
        $this->assertSame('completed', $run->refresh()->state->value, $run->blocked_reason ?? '');
        $this->assertNotSame('IN_TRANSIT', Ship::where('symbol', $ship['symbol'])->sole()->snapshot['nav']['status']);
        $this->observeFixtureMarket('X1-TEST-ROCK');
        $this->observeFixtureMarket('X1-TEST-PORT');
        $this->advanceClock(30);
        $this->assertTrue($director->tickArms());
        $next = AutomationRun::where('campaign_episode_id', $episode->id)->whereKeyNot($run->id)->whereNotNull('started_at')->sole();
        $this->assertTrue($next->plan['pipeline_preselected']);
        $this->assertSame($selection['selected_at'], $next->plan['pipeline_selected_at']);
        $this->assertArrayNotHasKey($ship['symbol'], $episode->refresh()->state['d5_preselected'] ?? []);

        $this->advanceClock(10);
        $ledger = app(IdleLedger::class);
        $arms = collect([$arm]);
        $credited = $ledger->evaluateAgent($this->agent, $arms)['by_arm']['H1']['busy_seconds'];
        $withoutGrace = $ledger->evaluateAgent($this->agent, $arms, pipelineGraceSeconds: 0)['by_arm']['H1']['busy_seconds'];
        $gap = $next->started_at->timestamp - strtotime($run->totals['finished_at']);
        $this->assertGreaterThan(0, $gap);
        $this->assertLessThanOrEqual(60, $gap);
        $this->assertSame($gap, $credited - $withoutGrace);
    }

    public function test_v4_director_assigns_the_contested_corridor_to_the_later_higher_scoring_arm(): void
    {
        $this->setUpSaturation(hubs: 3);
        config(['gameplay.inline_execute' => false]);
        $primary = $this->primary(revision: $this->fleetRevision(['fleet.saturation.corridor.side_depth_batches' => 1]));
        $this->storedRun($primary, 'LOCAL-1', 'position');
        $slow = FakeSpaceTraders::lightHauler('LOCAL-A', 'X1-TEST-ROCK');
        $fast = FakeSpaceTraders::lightHauler('LOCAL-B', 'X1-TEST-ROCK');
        $fast['engine']['speed'] = 60;
        $this->editFixture(function (array &$world) use ($slow, $fast) {
            array_push($world['ships'], $slow, $fast);
            $world['goods'][] = ['symbol' => 'COPPER_ORE', 'tradeVolume' => 20, 'purchasePrice' => 100, 'sellPrice' => 75];
            $world['market_goods']['X1-TEST-H3'] = [
                ['symbol' => 'COPPER_ORE', 'tradeVolume' => 20, 'purchasePrice' => 5000, 'sellPrice' => 4000],
                $world['goods'][1],
            ];
        });
        $this->observeFixtureMarket('X1-TEST-ROCK');
        $this->observeFixtureMarket('X1-TEST-H3');
        foreach ([$slow, $fast] as $ship) {
            app(AgentSynchronizer::class)->saveShip($this->agent, $ship);
        }
        $earlier = $this->arm($slow['symbol'], 'H1');
        $later = $this->arm($fast['symbol'], 'H2');
        $earlyEpisode = $this->seedArmEpisode($earlier);
        $lateEpisode = $this->seedArmEpisode($later);
        $later->update(['last_directed_at' => now()->subSeconds(35)]);

        $director = app(CampaignDirector::class);
        $this->assertTrue($director->tickArms());
        $this->assertSame($earlier->id, $director->armGrantId);
        $runs = AutomationRun::whereIn('campaign_episode_id', [$earlyEpisode->id, $lateEpisode->id])
            ->where('strategy', 'trade')->whereNotNull('started_at')->orderBy('id')->get();
        $this->assertSame([$fast['symbol'], $slow['symbol']], $runs->pluck('ship_symbol')->all());
        $this->assertSame([$lateEpisode->id, $earlyEpisode->id], $runs->pluck('campaign_episode_id')->all());
        $this->assertSame(['X1-TEST-PORT', 'X1-TEST-H3'], $runs->pluck('plan.legs.0.buyer')->all());
        $this->assertSame(['IRON_ORE', 'COPPER_ORE'], $runs->pluck('plan.legs.0.good')->all());
        $sellOrders = DB::table('corridor_book_events')->where('owner_type', 'run')->where('side', 'sell')->where('state', 'reserved')->orderBy('id')->get();
        $this->assertSame($runs->pluck('id')->map(fn (int $id) => (string) $id)->all(), $sellOrders->pluck('owner_id')->all());
        $this->assertSame(['X1-TEST-PORT', 'X1-TEST-H3'], $sellOrders->pluck('market')->all());
    }

    public function test_v11_demand_prioritizes_active_trader_buyers_and_limits_standing_extraction(): void
    {
        $this->setUpSaturation();
        $episode = $this->primary(revision: $this->fleetRevision([
            'fleet.saturation.corridor.chains_enabled' => true, 'fleet.saturation.extraction.enabled' => true,
        ]));
        $episode->update(['state' => ['active_buyer' => 'ACTIVE']]);
        $this->storedRun($episode, 'LOCAL-1', 'trade', ['legs' => [['buyer' => 'CHAIN']]]);
        foreach (['ORE-LATE' => 100, 'ORE-FIRST' => 20, 'ORE-SECOND' => 30] as $buyer => $seconds) {
            $this->storedRun($episode, $buyer, 'mining', ['buyer' => $buyer, 'eta' => now()->timestamp + $seconds]);
        }
        $this->event($episode, 'decision', ['candidates' => [
            ['source' => 'S1', 'buyer' => 'B1', 'score' => 30],
            ['source' => 'S2', 'buyer' => 'B2', 'score' => 20],
            ['source' => 'S3', 'buyer' => 'B3', 'score' => 10],
        ]]);
        $this->assertSame(
            ['ACTIVE', 'CHAIN', 'ORE-FIRST', 'ORE-SECOND', 'S1', 'B1', 'S2', 'B2'],
            app(CampaignMesh::class)->demand($episode, $this->agent),
        );
    }

    #[DataProvider('shuttleLifecycleStartingPoints')]
    public function test_v11_delivered_shuttle_releases_pacing_only_after_its_linked_full_load(bool $replayDelivery): void
    {
        $this->setUpSaturation(hubs: 2);
        $episode = $this->primary(revision: $this->fleetRevision());
        $yard = 'X1-TEST-ROCK';
        $type = 'SHIP_LIGHT_SHUTTLE';
        $shuttle = FakeSpaceTraders::lightHauler('OFFER-3', $yard);
        $shuttle['frame']['symbol'] = 'FRAME_SHUTTLE';
        $shuttle['frame']['fuelCapacity'] = 300;
        $shuttle['modules'][1]['capacity'] = 40;
        $offer = FakeSpaceTraders::shipyardOffer($type, $shuttle, 100000);
        $this->editFixture(function (array &$world) use ($yard, $type, $offer) {
            $world['shipyards'][$yard]['shipTypes'][] = ['type' => $type];
            $world['shipyards'][$yard]['ships'][] = $offer;
            $world['deliver_mismatched_frame'] = 'FRAME_LIGHT_FREIGHTER';
        });
        app(AgentSynchronizer::class)->observe($this->agent, 'shipyard', $yard, $this->world()['shipyards'][$yard]);
        $this->schedulerPass();
        foreach (range(1, 3) as $index) {
            $this->load($episode, [
                'run_id' => -$index, 'proof' => true, 'proof_eligible' => true, 'fully_settled' => true,
                'net' => 100000, 'predicted_margin' => 100000, 'realized_ratio' => 1,
                'source' => $yard, 'buyer' => 'X1-TEST-PORT', 'good' => 'IRON_ORE', 'ship' => 'LOCAL-1',
                'finished_at' => now()->subMinutes($index)->toIso8601String(),
            ]);
        }
        if ($replayDelivery) {
            // Start at a retained receipt so the review and load path can run independently of purchase transport.
            $delivered = $shuttle;
            $delivered['symbol'] = 'LOCAL-9';
            $delivered['frame']['symbol'] = 'FRAME_LIGHT_FREIGHTER';
            $delivered['cargo']['capacity'] = 40;
            $this->editFixture(fn (array &$world) => $world['ships'][] = $delivered);
            app(AgentSynchronizer::class)->saveShip($this->agent, $delivered);
            $purchase = $this->storedRun($episode, 'LOCAL-1', 'buy_fleet', ['ship_type' => $type, 'shipyard' => $yard],
                attributes: ['state' => 'blocked', 'active_agent_id' => null]);
            $action = $this->pendingAction($purchase, 'buy_ship', ['shipType' => $type, 'waypointSymbol' => $yard, 'maxTotal' => 100000]);
            $action->update([
                'state' => 'confirmed', 'review_required' => true, 'sent_at' => now(), 'settled_at' => now(),
                'outcome' => ['delivered_ship' => 'LOCAL-9', 'configuration_mismatch' => true, 'transaction_total' => 100000],
            ]);
        } else {
            $runner = app(AutomationRunner::class);
            $purchase = $runner->preview($this->agent, 'LOCAL-1', [
                'plan_version' => 8, 'strategy' => 'buy_fleet', 'shipyard' => $yard, 'ship_type' => $type,
            ], $episode);
            $runner->startCampaign($this->agent, $purchase, $episode);
            $this->workRun($purchase);
            $action = ShipAction::where('automation_run_id', $purchase->id)->where('command', 'buy_ship')->sole();
            $transports = collect($this->world()['calls'])->where('method', 'POST')->where('path', '/my/ships');
            $this->assertCount(1, $transports, $action->message ?? '');
        }
        $this->assertSame('confirmed', $action->state->value, $action->message ?? '');
        $this->assertTrue($action->review_required);
        $this->assertTrue($action->outcome['configuration_mismatch']);
        $ship = $action->outcome['delivered_ship'];
        $this->assertSame([$purchase->id, $episode->id], array_map(fn ($row) => $row->id, array_slice(app(CampaignProbeBootstrap::class)->linkage($action), 0, 2)));
        $this->assertSame('hauler_configuration_mismatch_review', $this->pacing($episode)['reason']);
        $this->autoArm($episode);
        $this->assertDatabaseMissing('campaign_grants', ['limits->arm->ship' => $ship]);

        $this->editFixture(function (array &$world) use ($ship) {
            foreach ($world['ships'] as &$row) {
                if ($row['symbol'] === $ship) {
                    $row['frame']['symbol'] = 'FRAME_SHUTTLE';
                    $row['fuel'] = ['current' => 300, 'capacity' => 300];
                }
            }
        });
        app(ShipCommandExecutor::class)->reconcile($this->agent, $action);
        $this->actingAs($this->operator)->postJson(route('game.commands.acknowledge', [$this->agent, $action]), [
            'note' => 'Reviewed corrected shuttle configuration.', 'acknowledge' => true,
        ])->assertSuccessful();
        $this->assertFalse($action->refresh()->review_required);
        $this->assertSame('awaiting_auto_arm', $this->pacing($episode)['reason']);
        $this->autoArm($episode);

        $arm = CampaignGrant::where('limits->arm->purchase_action_id', $action->id)->sole();
        $this->assertSame($ship, $arm->arm()['ship']);
        $this->assertSame('FRAME_SHUTTLE', Ship::where('symbol', $ship)->sole()->snapshot['frame']['symbol']);
        $this->assertSame('awaiting_first_load', $this->pacing($episode)['reason']);
        $armEpisode = $this->seedArmEpisode($arm);
        $linked = [
            'finished_at' => now()->toIso8601String(), 'run_id' => -20,
            'good' => 'IRON_ORE', 'source' => $yard, 'buyer' => 'X1-TEST-PORT', 'proof' => false,
            'ship' => $ship, 'campaign_grant_id' => $arm->id, 'purchase_action_id' => $action->id,
            'fully_settled' => true, 'net' => 12000,
        ];
        foreach ([
            ['fully_settled' => false],
            ['shortened' => true],
            ['standalone_recovery' => true],
            ['used_recovery' => true],
            ['purchase_action_id' => $action->id + 1],
            ['ship' => 'LOCAL-1'],
            ['campaign_grant_id' => $episode->campaign_grant_id],
        ] as $changes) {
            $this->load($armEpisode, $changes + $linked);
            $this->assertSame('awaiting_first_load', $this->pacing($episode)['reason']);
        }
        $this->load($episode, $linked);
        $this->assertSame('awaiting_first_load', $this->pacing($episode)['reason']);
        $this->storedRun($episode, 'LOCAL-1', 'position');
        $this->travel(31)->seconds();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        $this->assertTrue(app(CampaignDirector::class)->tickArms());
        $trade = AutomationRun::where('active_agent_id', $this->agent->id)->where('ship_symbol', $ship)->where('strategy', 'trade')->first();
        $this->assertNotNull($trade, json_encode([
            'arm' => $arm->refresh()->only(['pause_reason', 'wait_reason', 'last_directed_at']),
            'failures' => DB::table('game_runtime')->where('key', 'like', '%director_failures%')->get()->all(),
            'events' => CampaignEvent::where('campaign_grant_id', $arm->id)->where('kind', '!=', 'load_result')->get(['kind', 'data'])->all(),
            'runs' => AutomationRun::where('campaign_episode_id', $armEpisode->id)->get(['id', 'state', 'blocked_reason', 'strategy'])->all(),
        ], JSON_THROW_ON_ERROR));
        $this->workRun($trade);
        $this->assertSame('completed', $trade->refresh()->state->value, $trade->blocked_reason ?? '');
        app(CampaignTrade::class)->harvestArms($this->agent->reset_id);
        $settled = CampaignEvent::where('campaign_episode_id', $armEpisode->id)->where('kind', 'load_result')
            ->where('data->run_id', $trade->id)->sole()->data;
        $this->assertTrue($settled['fully_settled']);
        $this->assertSame([$ship, $arm->id, $action->id], [$settled['ship'], $settled['campaign_grant_id'], $settled['purchase_action_id']]);
        $final = $this->pacing($episode);
        $this->assertTrue($final['pass']);
        $this->assertSame([$action->id, $arm->id, $settled['finished_at']], [$final['action_id'], $final['arm'], $final['first_sale_at']]);
    }

    public static function shuttleLifecycleStartingPoints(): array
    {
        return ['purchase through transport' => [false], 'retained delivery receipt' => [true]];
    }

    public function test_v11_legacy_hauler_pacing_still_releases_after_its_arm_load(): void
    {
        $this->setUpSaturation(hubs: 2);
        $episode = $this->primary();
        $ship = FakeSpaceTraders::lightHauler('LOCAL-9', 'X1-TEST-ROCK');
        app(AgentSynchronizer::class)->saveShip($this->agent, $ship);
        $action = $this->haulerPurchase($episode, 'legacy-hauler', 'confirmed', ['delivered_ship' => 'LOCAL-9']);
        $this->autoArm($episode);
        $arm = CampaignGrant::where('limits->arm->purchase_action_id', $action->id)->sole();
        $this->assertSame('awaiting_first_load', $this->pacing($episode)['reason']);
        $this->load($this->seedArmEpisode($arm), ['finished_at' => now()->toIso8601String(), 'net' => 1000]);
        $this->assertTrue($this->pacing($episode)['pass']);
    }

    public function test_v12_price_uses_integer_tie_groups_and_a_single_charge(): void
    {
        $price = app(RequestShadowPrice::class);
        foreach ([
            [[[122, 2], [130, 2], [65, 1]], 3, 65, 0],
            [[[130, 2], [65, 1], [122, 2]], 3, 65, 0],
            [[[122, 2]], 4, 61, 2],
            [[], 4, 0, 4],
            [[[121, 2], [100, 1]], 0, 61, 0],
        ] as [$candidates, $excess, $clear, $remaining]) {
            $this->assertSame(['lambda_clear' => $clear, 'residual_excess' => $remaining], $price->clearingPrice($candidates, $excess));
        }
        $this->assertSame(['price' => 15, 'target' => 60], $price->stepPrice(10, 950, 60));
        $this->assertSame(['price' => 5, 'target' => 0], $price->stepPrice(10, 599, 60));
        $this->assertSame(['price' => 30, 'target' => 30], $price->stepPrice(32, 750, 60));
        $this->assertSame(28, app(TraderAssigner::class)->score(100, 12, 3600, 6));
    }

    public function test_v12_shedding_preserves_settlement_coverage_and_started_trade(): void
    {
        $ordered = app(RequestShadowPrice::class)->sheddingOrder([
            ['tier' => 0, 'key' => 'loaded settlement'],
            ['tier' => 1, 'key' => 'retained reconciliation'],
            ['tier' => 2, 'key' => 'Tier-1 coverage'],
            ['tier' => 3, 'key' => 'started trade', 'started' => true],
            ['tier' => 3, 'key' => 'unstarted trade'],
            ['tier' => 4, 'key' => 'miner', 'class' => 'mining'],
            ['tier' => 4, 'key' => 'survey', 'class' => 'surveyor'],
            ['tier' => 4, 'key' => 'siphon', 'class' => 'siphon'],
            ['tier' => 5, 'key' => 'required discovery', 'elective' => false],
            ['tier' => 5, 'key' => 'elective discovery', 'elective' => true],
            ['tier' => 6, 'key' => 'patrol'],
            ['tier' => 6, 'key' => 'spent poll', 'spent' => true],
        ]);

        $this->assertSame(['patrol', 'elective discovery', 'siphon', 'survey', 'miner', 'unstarted trade'], array_column($ordered, 'key'));
    }

    public function test_v12_alternating_pressure_parks_once_and_needs_three_prospective_low_passes(): void
    {
        $price = app(RequestShadowPrice::class);
        $state = ['paused' => false, 'low_passes' => 0];
        $moves = [];
        $lambda = 0;
        for ($minute = 0; $minute < 60; $minute++) {
            $without = $minute % 2 === 0 ? 860 : 870;
            $with = $without + 40;
            $active = $state['paused'] ? $without : $with;
            $nextLambda = $price->stepPrice($lambda, $active, 60)['price'];
            $this->assertLessThanOrEqual(5, abs($nextLambda - $lambda));
            $lambda = $nextLambda;
            $next = $price->updateHysteresis($state, $active, $with);
            if ($next['paused'] !== $state['paused']) {
                $moves[] = [$minute, $next['paused']];
            }
            $state = $next;
        }
        $this->assertSame([[0, true]], $moves);
        foreach ([850, 850, 851, 850, 850, 850] as $index => $prospective) {
            $state = $price->updateHysteresis($state, 810, $prospective);
            $this->assertSame($index !== 5, $state['paused']);
        }
        $held = $price->updateHysteresis(['paused' => true, 'low_passes' => 2, 'positive_charged_score' => false], 810, 850);
        $this->assertTrue($held['paused']);
        $this->assertSame(0, $held['low_passes']);
    }

    public function test_v12_recalibration_uses_strict_twenty_percent_and_the_j59_overrun_rule(): void
    {
        $price = app(RequestShadowPrice::class);
        $this->assertFalse($price->recalibrationRequired(120, 100));
        $this->assertTrue($price->recalibrationRequired(121, 100));
        $this->assertFalse($price->recalibrationRequired(80, 100));
        $this->assertTrue($price->recalibrationRequired(79, 100));
        $this->assertFalse($price->recalibrationRequired(100, 100, stage1J59Unavailable: true));
        $this->assertTrue($price->recalibrationRequired(101, 100, stage1J59Unavailable: true));
    }

    #[DataProvider('requestEnvelopes')]
    public function test_v12_governor_forecast_moves_one_transport_from_reserved_to_actual(string $stage, int $hard, int $ordinary): void
    {
        $this->setUpSaturation(agentSymbol: 'GIANTSOUP');
        $episode = $this->primary(revision: $this->fleetRevision());
        $this->seedTradingSample($episode);
        config(['gameplay.request_budget_stage' => $stage]);
        $governor = app(MeshGovernor::class);
        $before = $governor->evaluate($this->agent, $episode);
        $this->assertSame($hard, $before['hard_limit']);
        $this->assertSame($ordinary, $before['limits']['hour']);
        $this->assertSame('normal', $before['mode'], $before['reason'] ?? '');

        $allowance = $governor->admit($this->agent, $episode, 'coverage', 120);
        $candidates = [['key' => 'pair', 'tier' => 3, 'gross' => 3000, 'requests' => 27]];
        $booked = $governor->beginPass($this->agent, $episode, $candidates);
        $this->assertSame(['actual' => $before['rolling_usage']['actual'], 'remaining' => 120, 'proposed' => 27], $booked['rolling_usage']);
        RequestBudget::forMeshAllowance($allowance, fn () => $this->taggedRequest('coverage'), 'GIANTSOUP', $this->agent->reset_id);
        $sent = $governor->beginPass($this->agent, $episode, $candidates);
        $this->assertSame(['actual' => $booked['rolling_usage']['actual'] + 1, 'remaining' => 119, 'proposed' => 27], $sent['rolling_usage']);
        $utilization = intdiv(1000 * ($before['rolling_usage']['actual'] + 147), $hard);
        $this->assertSame($utilization, $booked['shadow_price']['utilization_permille']);
        $this->assertSame($utilization, $sent['shadow_price']['utilization_permille']);
        $this->assertTrue($governor->release($allowance));
    }

    public static function requestEnvelopes(): array
    {
        return [
            'Unstaged' => ['off', 4608, 4147],
            'Stage 1' => ['stage1', 3400, 3060],
            'Stage 2' => ['stage2', 2880, 2592],
        ];
    }

    public static function stagedReplayEnvelopes(): array
    {
        return [
            'Stage 1' => ['stage1', 3400, 3060, 2338],
            'Stage 2' => ['stage2', 2880, 2592, 1870],
        ];
    }

    #[DataProvider('stagedReplayEnvelopes')]
    public function test_v12_sk70_queued_replay_runs_near_the_staged_ordinary_cap(string $stage, int $hard, int $ordinary, int $background): void
    {
        $this->setUpSaturation(hubs: 2, seedCoverage: false, agentSymbol: 'GIANTSOUP');
        FleetMetrics::reset();
        config(['gameplay.inline_execute' => false]);
        $world = $this->sk70ReplayWorld();
        $trace = [];
        $budget = null;
        $start = null;
        $quoteFaultAt = null;
        $leaseFaultAt = null;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$world, &$trace, &$budget, &$start, &$quoteFaultAt, &$leaseFaultAt) {
            $path = substr(parse_url($request->url(), PHP_URL_PATH), 3) ?: '/';
            $workload = FleetMetrics::currentWorkload();
            $quoteFault = $budget && $quoteFaultAt === null && $workload === 'trade' && str_ends_with($path, '/market');
            $leaseFault = $budget && $leaseFaultAt === null && $workload === 'mining' && $path === '/my/ships/GIANTSOUP-TENDER'
                && DB::table('transfer_leases')->where('state', 'granted')->exists();
            if ($quoteFault || $leaseFault) {
                if ($quoteFault) {
                    $quoteFaultAt = $budget->clock;
                } else {
                    $leaseFaultAt = $budget->clock;
                }
                [$body, $status] = [['error' => ['code' => 429, 'message' => 'Replay rate limit']], 429];
            } else {
                if ($request->method() === 'POST' && str_ends_with($path, '/navigate')) {
                    $ship = collect($world['ships'])->firstWhere('symbol', explode('/', $path)[3]);
                    $points = array_column($world['waypoints'], null, 'symbol');
                    $world['fuel_consumption'] = $ship['nav']['flightMode'] === 'DRIFT' ? 1
                        : MiningPlanner::fuelEstimate($points[$ship['nav']['waypointSymbol']], $points[$request->data()['waypointSymbol']]);
                }
                [$body, $status] = FakeSpaceTraders::respond($world, $request);
                if ($path === '/my/agent') {
                    $body['data']['symbol'] = 'GIANTSOUP';
                    $body['data']['headquarters'] = 'X1-SK70-H52';
                }
            }
            if ($budget) {
                $trace[] = [
                    'at' => $budget->clock - $start->timestamp, 'method' => $request->method(), 'path' => $path,
                    'workload' => $workload, 'status' => $status, 'ordinary' => $budget->usedOrdinaryCapacity('settlement'),
                ];
            }

            return Http::response($body, $status, $status === 429 ? ['Retry-After' => '5'] : []);
        });

        $sync = app(AgentSynchronizer::class);
        FleetMetrics::withWorkload('director', fn () => $sync->sync($this->agent, false, freshFleet: true));
        foreach ($world['waypoints'] as $point) {
            $sync->observe($this->agent, 'waypoint', $point['symbol'], $point, 'listing');
        }
        $sync->observe($this->agent, 'catalogue', 'X1-SK70', ['complete' => true, 'total' => count($world['waypoints'])], 'listing');
        $this->observeSk70ReplayMarkets($world);
        $director = app(CampaignDirector::class);
        $arm = $this->arm('GIANTSOUP-A', 'H1');
        $armEpisode = $this->seedArmEpisode($arm);
        $this->assertTrue($director->tickArms());
        $legacy = AutomationRun::where('campaign_episode_id', $armEpisode->id)->where('strategy', 'trade')->whereNotNull('started_at')->sole();
        $this->assertLessThan(8, $legacy->plan_version);
        $this->workQueuedRun($legacy);
        $this->assertSame('completed', $legacy->refresh()->state->value, $legacy->blocked_reason ?? '');
        $receipts = ShipAction::where('automation_run_id', $legacy->id)->whereIn('command', ['buy', 'sell'])->where('state', 'confirmed')->get();
        $this->assertEqualsCanonicalizing(['buy', 'sell'], $receipts->pluck('command')->all());

        $episode = $this->primary(revision: $this->fleetRevision([
            'probes.mesh.max_probes' => 4,
            'fleet.auto_arm.frames' => ['FRAME_SHUTTLE'],
            'fleet.saturation.auto_arm_frames' => ['FRAME_SHUTTLE'],
            'fleet.saturation.extraction.enabled' => true,
            'fleet.saturation.extraction.stationary' => true,
            'fleet.saturation.extraction.max_miners' => 1,
            'fleet.saturation.extraction.max_siphons' => 1,
        ]));
        $this->seedTradingSample($episode);
        $metrics = app(FleetMetrics::class);
        $baseline = $metrics->workloadRates('GIANTSOUP', 7200)['workloads'];
        $this->observeSk70ReplayMarkets($world);
        foreach ($receipts as $receipt) {
            $this->assertDatabaseMissing('corridor_book_events', ['ship_action_id' => $receipt->id]);
        }
        $start = now()->toImmutable();
        $budget = new class extends RequestBudget
        {
            public float $clock;

            protected function now(): float
            {
                return $this->clock;
            }

            protected function pause(float $seconds): void
            {
                $this->clock += $seconds;
            }
        };
        $budget->clock = $start->timestamp;
        $this->app->instance(RequestBudget::class, $budget);
        config([
            'gameplay.request_budget_stage' => $stage, 'gameplay.request_budget_enabled' => true,
            'gameplay.request_budget_path' => config('gameplay.request_budget_path').'-sk70-replay',
        ]);
        $budget->drainAgent('GIANTSOUP', false);
        $governor = app(MeshGovernor::class);
        $this->assertSame($hard, $governor->evaluate($this->agent, $episode)['hard_limit']);
        $this->assertSame('normal', $governor->evaluate($this->agent, $episode)['mode'], $governor->evaluate($this->agent, $episode)['reason'] ?? '');
        $client = app(SpaceTradersClient::class);
        $markets = ['A3', 'F50', 'D43', 'E46', 'K90', 'D44', 'E47', 'B7', 'C40'];
        $nextRead = 0;
        $standingRead = function (float $elapsed) use ($budget, $start, $client, $markets, &$nextRead) {
            $budget->clock = max($budget->clock, $start->timestamp + $elapsed);
            $this->travelTo($start->addSeconds((int) $elapsed));
            $market = $markets[$nextRead % count($markets)];
            FleetMetrics::forAgent('GIANTSOUP', fn () => FleetMetrics::withWorkload('coverage', fn () => $client->scope(
                fn () => $client->request('GET', "/systems/X1-SK70/waypoints/X1-SK70-{$market}/market", token: $this->agent->token, agentSymbol: 'GIANTSOUP', fresh: true),
                priority: 'director',
            )), $this->agent->reset_id);
            $nextRead++;
        };
        for ($elapsed = 0; $elapsed < 900; $elapsed += 10) {
            $budget->clock = max($budget->clock, $start->timestamp + $elapsed);
            $this->travelTo($start->addSeconds($elapsed));
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
            if ($elapsed % 30 === 0) {
                $directed = $director->tick();
                $this->assertTrue($directed, json_encode([$elapsed, $episode->grant()->only(['pause_reason', 'wait_reason']),
                    ShipAction::where('review_required', true)->get(['command', 'message', 'outcome'])], JSON_THROW_ON_ERROR));
            }
            foreach (AutomationRun::where('active_agent_id', $this->agent->id)->where('next_action_at', '<=', now())->orderBy('id')->get() as $run) {
                $this->queuedAdvance($run);
            }
            // Standing reads use gaps between worker bursts and leave room for the next tick.
            $host = $budget->usage()['host'];
            if ($host['tier2_4_last_60_seconds'] < 50 && $host['blocked_until'] <= $start->timestamp + $elapsed + 6) {
                $standingRead($elapsed + 6);
            }
        }
        $this->assertGreaterThan(0, $nextRead);
        foreach ($receipts as $receipt) {
            $this->assertDatabaseHas('corridor_book_events', [
                'owner_type' => 'legacy_receipt', 'ship_action_id' => $receipt->id, 'side' => $receipt->command,
                'market' => $receipt->before_state['ship']['nav']['waypointSymbol'], 'units' => $receipt->outcome['units'],
            ]);
        }
        foreach (['trade' => ['buy', 'sell'], 'mining' => ['mine', 'sell'], 'siphon' => ['siphon', 'sell']] as $strategy => $commands) {
            $runs = AutomationRun::where('plan_version', 8)->where('strategy', $strategy)->whereNotNull('started_at')->get();
            $this->assertNotEmpty($runs, $strategy.' was never started. '.json_encode(CampaignEvent::where('campaign_episode_id', $episode->id)
                ->where('kind', 'decision')->latest('id')->limit(3)->get()->map(fn (CampaignEvent $event) => Arr::only($event->data, ['choice', 'fleet_saturation', 'rejections']))));
            foreach ($commands as $command) {
                $this->assertTrue(ShipAction::whereIn('automation_run_id', $runs->pluck('id'))->where('command', $command)->where('state', 'confirmed')->exists(),
                    json_encode([$strategy, $command, $runs->map->only(['ship_symbol', 'state', 'blocked_reason', 'totals'])], JSON_THROW_ON_ERROR));
            }
        }

        $this->assertNotNull($quoteFaultAt, 'The queued trade never requested a retryable quote.');
        $this->assertNotNull($leaseFaultAt, json_encode([
            'canaries' => DB::table('corridor_canary_states')->get(['corridor_family', 'state']),
            'leases' => DB::table('transfer_leases')->get(['state', 'source_ship', 'receiver_ship']),
            'proofs' => CampaignEvent::where('campaign_episode_id', $episode->id)->where('kind', 'load_result')->get()
                ->map(fn (CampaignEvent $event) => Arr::only($event->data, ['good', 'proof', 'proof_eligible', 'net', 'realized_ratio'])),
            'runs' => AutomationRun::whereIn('strategy', ['mining', 'tender'])->get()->map->only(['ship_symbol', 'state', 'blocked_reason']),
        ], JSON_THROW_ON_ERROR));
        $faults = array_values(array_filter($trace, fn (array $call) => $call['status'] === 429));
        $this->assertCount(2, $faults);
        foreach ($faults as $fault) {
            $following = array_values(array_filter($trace, fn (array $call) => $call['at'] > $fault['at']));
            $this->assertGreaterThanOrEqual($fault['at'] + 5, $following[0]['at']);
            $this->assertNotEmpty(array_filter($following, fn (array $call) => $call['path'] === $fault['path']
                && $call['workload'] === $fault['workload'] && $call['status'] === 200));
        }
        $this->assertDatabaseHas('transfer_leases', ['state' => 'confirmed']);
        $this->assertCount(1, array_filter($trace, fn (array $call) => $call['method'] === 'POST' && str_ends_with($call['path'], '/transfer') && $call['status'] < 300));
        $this->assertGreaterThan(0, $budget->usage(agentSymbol: 'GIANTSOUP')['agent']['deferrals']);

        $remainingReads = $background - $nextRead;
        for ($i = 0; $i < $remainingReads; $i++) {
            $standingRead(900 + $i * 2699 / $remainingReads);
        }
        $this->assertSame([], array_filter($trace, fn (array $call) => $call['status'] >= 400 && $call['status'] !== 429));
        $this->assertSame([], array_filter($trace, fn (array $call) => $call['workload'] === null));
        $ordinaryTrace = array_column(array_filter($trace, fn (array $call) => $call['ordinary']), 'at');
        $protectedTrace = array_column(array_filter($trace, fn (array $call) => ! $call['ordinary']), 'at');
        $peak = $this->rollingPeak($ordinaryTrace, 3600);
        $this->assertGreaterThan($ordinary - 40, $peak);
        $this->assertLessThanOrEqual($ordinary, $peak);
        $this->assertLessThanOrEqual($hard, $this->rollingPeak(array_column($trace, 'at'), 3600));
        $this->assertLessThanOrEqual(76, $this->rollingPeak($ordinaryTrace, 60));
        $this->assertLessThanOrEqual(18, $this->rollingPeak($protectedTrace, 60));
        $cap = $governor->evaluate($this->agent, $episode);
        $this->assertSame($ordinary, $cap['limits']['hour']);
        $usage = $budget->usage(agentSymbol: 'GIANTSOUP');
        $this->assertSame(count($trace), $usage['host']['responses']);
        $this->assertLessThanOrEqual($ordinary, $usage['agent']['tier2_4_last_hour']);
        $this->assertGreaterThan($ordinary - 96, $usage['agent']['tier2_4_last_hour']);
        try {
            $allowance = $governor->admit($this->agent, $episode, 'trade', 96);
            $governor->release($allowance);
            $this->fail('The near-cap governor admitted more than the available ordinary capacity.');
        } catch (RequestDeferred $exception) {
            $this->assertStringContainsString('Mesh request admission held', $exception->getMessage());
        }

        FleetMetrics::flush();
        $this->assertSame(0, $metrics->untaggedRequestCount('GIANTSOUP'));
        $workloads = $metrics->workloadRates('GIANTSOUP', 7200)['workloads'];
        $byWorkload = collect($trace)->groupBy('workload')->sortKeys();
        $expected = $byWorkload->map(fn ($calls) => ['requests' => $calls->count(), 'ordinary' => $calls->where('ordinary', true)->count()])->all();
        $measured = [];
        foreach ($workloads as $tag => $entry) {
            $counts = ['requests' => $entry['requests'] - ($baseline[$tag]['requests'] ?? 0), 'ordinary' => $entry['ordinary'] - ($baseline[$tag]['ordinary'] ?? 0)];
            if ($counts['requests'] > 0) {
                $measured[$tag] = $counts;
            }
        }
        $this->assertSame($expected, $measured);
        $workerRequests = array_map(fn (array $workload) => $workload['requests'], Arr::only($measured, ['trade', 'mining', 'siphon', 'director', 'tender']));
        $this->assertSame(['director' => 63, 'mining' => 203, 'siphon' => 225, 'tender' => 21, 'trade' => 332], $workerRequests);
        $mesh = array_sum(array_column(Arr::only($measured, ['coverage', 'scout', 'discovery']), 'requests'));
        $this->assertSame($background + 44, $mesh);
        $price = app(RequestShadowPrice::class);
        $observed = $expected['trade']['requests'];
        $this->assertFalse($price->recalibrationRequired($observed, $observed));
        $this->assertTrue($price->recalibrationRequired($observed, (int) floor($observed * 0.8)));
        $this->assertSame($stage === 'stage1', $price->recalibrationRequired($observed, $observed - 1, $stage === 'stage1'));
    }

    private function sk70ReplayWorld(): array
    {
        $world = $this->world();
        $world['foundation'] = true;
        $world['waypoints'] = $world['ships'] = $world['market_goods'] = $world['shipyards'] = [];
        $world['travel_seconds'] = 10;
        $world['cooldown_seconds'] = 40;
        $world['yields'] = [['symbol' => 'IRON_ORE', 'units' => 7]];
        $world['siphon_yield'] = ['symbol' => 'HYDROCARBON', 'units' => 7];
        $fuel = $world['goods'][1];
        foreach (['H52' => 0, 'A3' => 4, 'F50' => 6, 'D43' => 8, 'E46' => 10, 'K90' => 12, 'E47' => 14,
            'D44' => 16, 'G51' => 18, 'B34' => 20, 'B7' => 22, 'C39' => 24, 'C40' => 26] as $id => $x) {
            $market = 'X1-SK70-'.$id;
            $point = $this->point($market, $x) + ['modifiers' => []];
            $point['type'] = match ($id) {
                'B34' => 'ASTEROID_FIELD',
                'C39' => 'GAS_GIANT',
                default => 'PLANET',
            };
            if (in_array($id, ['B34', 'C39'], true)) {
                $point['traits'] = [];
            }
            $world['waypoints'][] = $point;
            $world['market_goods'][$market] = [$fuel];
        }
        foreach ([
            ['H52', ['A3' => 322, 'F50' => 332], 'COPPER', 188, 60],
            ['H52', ['D43' => 270, 'E46' => 250, 'K90' => 260], 'ALUMINUM', 150, 60],
            ['E47', ['K90' => 286], 'POLYNUCLEOTIDES', 148, 20],
            ['K90', ['D44' => 2565], 'FOOD', 2295, 60],
            ['G51', ['E47' => 241], 'FERTILIZERS', 135, 60],
        ] as [$source, $buyers, $good, $ask, $volume]) {
            $world['market_goods']['X1-SK70-'.$source][] = ['symbol' => $good, 'purchasePrice' => $ask, 'sellPrice' => $ask - 5, 'tradeVolume' => $volume];
            foreach ($buyers as $buyer => $bid) {
                $world['market_goods']['X1-SK70-'.$buyer][] = ['symbol' => $good, 'purchasePrice' => $bid + 5, 'sellPrice' => $bid, 'tradeVolume' => $volume];
            }
        }
        $world['market_goods']['X1-SK70-B7'] = FakeSpaceTraders::withAllMiningBids([$fuel,
            ['symbol' => 'IRON_ORE', 'purchasePrice' => 53, 'sellPrice' => 48, 'tradeVolume' => 180],
            ['symbol' => 'COPPER_ORE', 'purchasePrice' => 58, 'sellPrice' => 53, 'tradeVolume' => 180],
            ['symbol' => 'ALUMINUM_ORE', 'purchasePrice' => 62, 'sellPrice' => 57, 'tradeVolume' => 180],
        ]);
        foreach (['HYDROCARBON' => 43, 'LIQUID_HYDROGEN' => 23, 'LIQUID_NITROGEN' => 27] as $good => $bid) {
            $world['market_goods']['X1-SK70-C40'][] = ['symbol' => $good, 'purchasePrice' => $bid + 4, 'sellPrice' => $bid, 'tradeVolume' => 180];
        }
        $world['priced_markets'] = array_keys($world['market_goods']);
        $command = FakeSpaceTraders::ship('GIANTSOUP-1', 'X1-SK70-H52');
        $command['registration']['role'] = 'COMMAND';
        $miner = FakeSpaceTraders::ship('GIANTSOUP-MINER', 'X1-SK70-B34');
        $siphon = FakeSpaceTraders::ship('GIANTSOUP-SIPHON', 'X1-SK70-C39');
        $siphon['frame']['symbol'] = 'FRAME_DRONE';
        $siphon['mounts'] = [['symbol' => 'MOUNT_GAS_SIPHON_I', 'strength' => 10, 'condition' => 1.0, 'requirements' => ['power' => 1, 'crew' => 1]]];
        $siphon['modules'][0]['symbol'] = 'MODULE_GAS_PROCESSOR_I';
        foreach ([&$miner, &$siphon] as &$drone) {
            $drone['nav']['status'] = 'IN_ORBIT';
            $drone['cargo']['capacity'] = $drone['modules'][1]['capacity'] = 15;
            $drone['fuel'] = ['current' => 80, 'capacity' => 80];
            $drone['frame']['fuelCapacity'] = 80;
            $drone['engine']['speed'] = 9;
        }
        unset($drone);
        $tender = FakeSpaceTraders::lightHauler('GIANTSOUP-TENDER', 'X1-SK70-B34');
        $tender['nav']['status'] = 'IN_ORBIT';
        $world['ships'] = [$command, FakeSpaceTraders::lightHauler('GIANTSOUP-A', 'X1-SK70-H52'), $miner, $siphon, $tender];
        foreach (['H52', 'A3', 'F50', 'K90'] as $index => $market) {
            $world['ships'][] = FakeSpaceTraders::probe('GIANTSOUP-P'.$index, 'X1-SK70-'.$market);
        }
        foreach ($world['ships'] as &$ship) {
            $ship['nav']['systemSymbol'] = 'X1-SK70';
        }
        unset($ship);

        return $world;
    }

    private function observeSk70ReplayMarkets(array $world): void
    {
        $sync = app(AgentSynchronizer::class);
        foreach ($world['market_goods'] as $market => $goods) {
            $sync->observe($this->agent, 'market', $market, [
                'symbol' => $market, 'imports' => [], 'exports' => [],
                'exchange' => array_map(fn (array $good) => ['symbol' => $good['symbol']], $goods), 'tradeGoods' => $goods,
            ]);
        }
    }

    private function rollingPeak(array $times, int $seconds): int
    {
        $peak = $first = 0;
        foreach ($times as $last => $at) {
            while ($times[$first] <= $at - $seconds) {
                $first++;
            }
            $peak = max($peak, $last - $first + 1);
        }

        return $peak;
    }

    public function test_phase2_fleet_playbook_matches_live_revision_and_only_enables_extraction(): void
    {
        $playbook = app(CampaignPlaybook::class);
        $phase1 = $playbook->import($this->playbookSource('weekly-system-saturation-fleet'));
        $phase2 = $playbook->import($this->playbookSource('weekly-system-saturation-fleet-phase2'));
        // Production revision 11 (grant 13) was imported from these exact bytes.
        $this->assertSame('fd9668d983bbe32f0e8a43e4c0dd7824abcd1ff9440b2a41728c26e31f20219c', $phase2->content_hash);
        $before = Arr::dot($phase1->body);
        $after = Arr::dot($phase2->body);
        $this->assertSame(['fleet.saturation.extraction.enabled' => true], array_diff_assoc($after, $before));
        $this->assertSame(array_keys($before), array_keys($after));
        $this->assertFalse($after['fleet.saturation.extraction.stationary']);
    }

    private function playbookSource(string $name): string
    {
        return file_get_contents(base_path("campaigns/{$name}.yaml"));
    }

    private function queuedAdvance(AutomationRun $run): void
    {
        app(AutomationRunner::class)->advance($run->id);
        foreach (ShipAction::where('automation_run_id', $run->id)->where('state', 'pending')->orderBy('id')->get() as $action) {
            (new ExecuteShipCommand($action->id))->handle(app(ShipCommandExecutor::class));
        }
    }

    private function workQueuedRun(AutomationRun $run): void
    {
        for ($step = 0; $step < 80 && $run->refresh()->active_agent_id && $run->state->value !== 'blocked'; $step++) {
            $this->queuedAdvance($run);
            $this->advanceClock(5);
        }
    }

    private function advanceClock(int $seconds): void
    {
        $this->travel($seconds)->seconds();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
    }

    private function observeFixtureMarket(string $market): void
    {
        $world = $this->world();
        $goods = $world['market_goods'][$market] ?? $world['goods'];
        app(AgentSynchronizer::class)->observe($this->agent, 'market', $market, [
            'symbol' => $market, 'imports' => [], 'exports' => [],
            'exchange' => array_map(fn (array $good) => ['symbol' => $good['symbol']], $goods),
            'tradeGoods' => $goods,
        ]);
    }

    private function fleetRevision(array $changes = []): CampaignRevision
    {
        $playbook = app(CampaignPlaybook::class);
        $arm = $playbook->import(str_replace('GIANTSOUP', $this->agent->name, $this->playbookSource('arm-trade-fleet')));
        $body = Yaml::parse($this->playbookSource('weekly-system-saturation-fleet'));
        $body['agent']['symbol'] = $this->agent->name;
        $body['fleet']['auto_arm']['revision_hash'] = $arm->content_hash;
        foreach ($changes as $key => $value) {
            Arr::set($body, $key, $value);
        }

        return $playbook->import(Yaml::dump($body, 12));
    }

    private function storedRun(CampaignEpisode $episode, string $ship, string $strategy, array $plan = [], array $totals = [], array $attributes = []): AutomationRun
    {
        $grant = $episode->grant();

        return AutomationRun::create($attributes + [
            'agent_id' => $this->agent->id, 'active_agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id,
            'campaign_episode_id' => $episode->id, 'engine_session_id' => LocalEngine::find(1)?->session_id,
            'ship_symbol' => $ship, 'strategy' => $strategy, 'state' => 'running', 'plan_version' => 8,
            'started_at' => now(), 'expires_at' => now()->addHour(),
            'plan' => $plan + [
                'plan_version' => 8, 'grant_id' => $grant->id, 'revision_hash' => $grant->revision_hash,
                'campaign_episode_id' => $episode->id, 'initial_credits' => $this->agent->credits,
                'max_spend' => 5000, 'max_loss' => 5000, 'reserve' => 0,
            ],
            'totals' => $totals,
        ]);
    }

    private function pendingAction(AutomationRun $run, string $command, array $arguments, array $before = [], ?string $key = null): ShipAction
    {
        return ShipAction::create([
            'agent_id' => $run->agent_id, 'ship_symbol' => $run->ship_symbol, 'reset_id' => $run->reset_id,
            'automation_run_id' => $run->id, 'engine_session_id' => $run->engine_session_id, 'key' => $key ?? "{$run->id}:{$command}", 'origin' => 'automation',
            'command' => $command, 'arguments' => $arguments, 'before_state' => $before,
            'state' => 'pending', 'expires_at' => now()->addMinutes(3),
        ]);
    }

    private function reserveStimulus(CampaignEpisode $episode, int $risk = 0, int $bonus = 1000, string $market = 'X1-TEST-PORT', string $good = 'COPPER',
        int $cycleCap = 5000, bool $windDown = false, array $baseline = [], ?AutomationRun $run = null): ?int
    {
        $run ??= $this->storedRun($episode, 'LOCAL-1', 'trade', attributes: ['state' => 'preview', 'active_agent_id' => null]);

        return app(SupplyChainStimulus::class)->reserve(
            $this->agent, $episode, $run, $market, $good, $risk, $bonus, $cycleCap, 50000, 10000, $windDown, $baseline,
        );
    }

    private function load(CampaignEpisode $episode, array $data, array $attributes = []): void
    {
        $this->event($episode, 'load_result', $data, $attributes);
    }

    private function event(CampaignEpisode $episode, string $kind, array $data, array $attributes = []): void
    {
        CampaignEvent::create($attributes + [
            'campaign_episode_id' => $episode->id, 'campaign_grant_id' => $episode->campaign_grant_id,
            'kind' => $kind, 'data' => $data,
        ]);
    }

    private function assertPurchaseAdmissionHeld(CampaignEpisode $episode): void
    {
        try {
            $id = app(MeshGovernor::class)->admit($this->agent, $episode, 'buy_fleet');
            app(MeshGovernor::class)->release($id);
            $this->fail('The governor admitted a staged fleet purchase without gated evidence.');
        } catch (RequestDeferred $exception) {
            $this->assertStringContainsString('Mesh request admission held', $exception->getMessage());
        }
    }

    private function seedTradingSample(CampaignEpisode $episode, int $activeMinutes = 120): AutomationRun
    {
        FleetMetrics::reset();
        $episode->update(['opened_at' => now()->subHours(6)]);
        $ship = $this->agent->name.'-1';
        $run = $this->storedRun($episode, $ship, 'trade', ['symbol' => 'IRON_ORE'], attributes: [
            'state' => 'completed', 'active_agent_id' => null, 'started_at' => now()->subMinutes($activeMinutes),
        ]);
        DB::table('fleet_metrics')->insert([
            'kind' => 'requests', 'agent_symbol' => $this->agent->name, 'scope' => 'agent', 'ship' => $ship,
            'value' => 400, 'data' => json_encode(['workload' => 'trade', 'reset_id' => $this->agent->reset_id], JSON_THROW_ON_ERROR),
            'created_at' => now()->subMinutes(30),
        ]);

        return $run;
    }

    private function taggedRequest(?string $workload = null): void
    {
        FleetMetrics::forAgent($this->agent->name, fn () => FleetMetrics::withWorkload($workload,
            fn () => app(SpaceTradersClient::class)->request('GET', '/my/agent', token: $this->agent->token, agentSymbol: $this->agent->name, fresh: true),
        ), $this->agent->reset_id);
    }

    private function pacing(CampaignEpisode $episode): array
    {
        return app(CampaignSaturation::class)->evaluate($episode->grant(), $episode->refresh(), $this->agent->refresh())['gates']['G9'];
    }

    private function autoArm(CampaignEpisode $episode): void
    {
        $lock = app(HostLock::class);
        $lock->run('campaign', fn () => $lock->run('agent-'.$this->agent->id,
            fn () => app(CampaignControl::class)->autoArm($episode->grant(), $episode, $this->agent)));
    }
}
