<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\CycleAccounting;
use App\Gameplay\EvidenceAnalysis;
use App\Gameplay\OpportunityAssessor;
use App\Gameplay\OpportunityInvestigator;
use App\Gameplay\ShipCommandExecutor;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\ShipAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class CycleEconomicsTest extends TestCase
{
    use RefreshDatabase;

    private string $worldFile;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->worldFile = tempnam(sys_get_temp_dir(), 'st-economics-');
        file_put_contents($this->worldFile, json_encode(FakeSpaceTraders::world()));
        FakeSpaceTraders::install($this->worldFile);
        $user = User::factory()->create();
        $this->agent = $user->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic-economics', 'account_id' => 'fixture', 'faction' => 'COSMIC',
            'reset_id' => '2026-10-25', 'canonical_identity' => AgentSynchronizer::identity('2026-10-25', 'LOCAL')]);
        $this->actingAs($user);
        config(['gameplay.enabled' => true]);
    }

    protected function tearDown(): void
    {
        unlink($this->worldFile);
        parent::tearDown();
    }

    private function runCycle(array $limits = [], ?callable $beforeAdvance = null): AutomationRun
    {
        app(AgentSynchronizer::class)->sync($this->agent->fresh());
        $runner = app(AutomationRunner::class);
        $run = $runner->preview($this->agent->fresh(), 'LOCAL-1', $limits + ['site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-ROCK',
            'reserve' => 2000, 'max_spend' => 100, 'max_loss' => 100, 'minutes' => 60, 'cycles' => 1]);
        $runner->start($this->agent->fresh(), $run);
        $run->update(['started_at' => now()->subSeconds(120)]);
        for ($i = 0; $i < 25 && $run->fresh()->active_agent_id && $run->fresh()->state->value !== 'blocked'; $i++) {
            if ($beforeAdvance) {
                $beforeAdvance($run->fresh());
            }
            $run->update(['next_action_at' => now()]);
            $runner->advance($run->id);
            foreach (ShipAction::where('state', 'pending')->get() as $action) {
                app(ShipCommandExecutor::class)->execute($action->id);
            }
        }

        return $run->fresh();
    }

    public function test_runner_report_and_evaluator_keep_cash_separate_from_allowance_estimates(): void
    {
        $run = $this->runCycle(['wear_allowance' => 20]);
        $this->assertSame('completed', $run->state->value);
        $accounting = $run->totals['accounting'];
        $this->assertSame('estimated_comparable', $accounting['state']);
        $this->assertSame(240, $accounting['cash_contribution']);
        $this->assertSame(220, $accounting['net_after_wear']);
        $this->assertNull($accounting['realized_net_per_hour']);
        $this->assertEquals(1 / 30, $accounting['ship_hours']);
        $this->assertEquals(array_sum($run->totals['sold']) * 30, $accounting['sold_units_per_elapsed_hour']);
        $report = app(EvidenceAnalysis::class)->report($this->agent->fresh());
        $this->assertSame($accounting, $report['cycles'][0]['accounting']);
        $this->assertEquals(6600, $report['mining_loads'][0]['comparable_net_per_hour']);
        $this->assertNull($report['cycles'][0]['capital']['total']);

        $investigator = app(OpportunityInvestigator::class);
        $record = $investigator->create($this->agent->fresh(), 'mining', 'X1-TEST');
        for ($i = 0; $i < 10 && $record->fresh()->status === 'investigating'; $i++) {
            $investigator->advance($this->agent->fresh(), $record->fresh());
        }
        $candidate = collect($record->fresh()->candidates)->firstWhere('market', 'X1-TEST-ROCK');
        $this->assertSame(1, $candidate['estimates']['sample_count']);
        $this->assertSame(220, $candidate['estimates']['base']['net']);
        $this->assertSame(220, $candidate['estimates']['conservative']['net']);
        $this->assertStringContainsString('observed sample', $candidate['estimates']['basis']);
    }

    public function test_missing_wear_unmatched_fuel_and_unsold_cargo_never_become_profit(): void
    {
        $run = $this->runCycle();
        $this->assertSame('unresolved', $run->totals['accounting']['state']);
        $this->assertNull($run->totals['accounting']['net_credits_per_hour']);
        $this->postJson('/agents/'.$this->agent->id.'/economics/investment', ['reference_run' => $run->id, 'basis_note' => 'Synthetic inputs with no comparable current-assets result.', 'costs_complete' => true,
            'elapsed_seconds' => 3600, 'base_net' => 9000, 'conservative_net' => 8000])->assertOk()->assertJsonPath('current_assets_net_per_hour', null)->assertJsonPath('payback_hours', null);
        $start = ['waypoint' => 'A', 'fuel' => 100, 'inventory' => []];
        $open = app(CycleAccounting::class)->summarize($start, ['waypoint' => 'B', 'fuel' => 90, 'inventory' => ['IRON_ORE' => 10]], ['sales' => 250, 'wear_allowance' => 20], 120);
        $this->assertCount(3, $open['unresolved']);
        $this->assertNull($open['net_credits_per_hour']);
    }

    public function test_repairs_and_allowance_cover_the_same_wear_only_once(): void
    {
        $state = ['waypoint' => 'A', 'fuel' => 100, 'inventory' => []];
        $result = app(CycleAccounting::class)->summarize($state, $state, ['sales' => 500, 'repairs' => 30, 'wear_allowance' => 50], 3600, 2);
        $this->assertSame(470, $result['cash_contribution']);
        $this->assertSame(450, $result['net_after_wear']);
        $this->assertEquals(225, $result['income_per_ship_hour']);
        $observed = app(CycleAccounting::class)->summarize($state, $state, ['sales' => 500, 'repairs' => 30, 'wear_cost' => 30, 'wear_allowance' => 50], 3600);
        $this->assertSame('realized_comparable', $observed['state']);
        $this->assertSame(470, $observed['net_after_wear']);
    }

    public function test_open_runner_cycle_and_stop_preserve_unmatched_position_and_fuel(): void
    {
        $world = FakeSpaceTraders::world();
        $world['ships'][] = FakeSpaceTraders::ship('LOCAL-2', 'X1-TEST-PORT');
        file_put_contents($this->worldFile, json_encode($world));
        $run = $this->runCycle(['market' => 'X1-TEST-PORT', 'wear_allowance' => 20]);
        $this->assertSame('completed', $run->state->value);
        $this->assertSame('unresolved', $run->totals['accounting']['state']);
        $this->assertContains('Positioning or empty return remains open.', $run->totals['accounting']['unresolved']);

        $runner = app(AutomationRunner::class);
        $run = $runner->preview($this->agent->fresh(), 'LOCAL-1', ['site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-PORT', 'reserve' => 2000,
            'max_spend' => 100, 'max_loss' => 100, 'minutes' => 60, 'cycles' => 1, 'wear_allowance' => 20]);
        $runner->start($this->agent->fresh(), $run);
        $run->update(['started_at' => now()->subSeconds(120)]);
        $runner->stop($run);
        $runner->advance($run->id);
        $this->assertSame('stopped', $run->fresh()->state->value);
        $this->assertNull($run->fresh()->totals['accounting']['net_credits_per_hour']);
    }

    public function test_reachable_inventory_caps_sale_batches_keeps_unpriced_cargo_and_rejects_stale_or_unreachable_quotes(): void
    {
        app(AgentSynchronizer::class)->sync($this->agent);
        $ship = FakeSpaceTraders::ship();
        $ship['cargo'] = ['units' => 12, 'capacity' => 40, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 10], ['symbol' => 'UNKNOWN_ORE', 'units' => 2]]];
        $estimate = app(CycleAccounting::class)->reachableInventory($this->agent->fresh(), $ship);
        $this->assertSame(100, $estimate['estimated_value_after_fuel']);
        $this->assertSame(['IRON_ORE' => 6, 'UNKNOWN_ORE' => 2], $estimate['unpriced']);
        $this->assertSame(0, $estimate['remaining_fuel_ceiling']);
        app(AgentSynchronizer::class)->saveShip($this->agent->fresh(), $ship);
        $report = app(OpportunityAssessor::class)->report($this->agent->fresh());
        $sale = collect($report['cargo_options'][0]['choices'])->firstWhere('action', 'sell_locally');
        $this->assertSame(100, $sale['estimated_proceeds']);
        $this->assertSame(6, $sale['remaining_unsold_units']);
        $ship['fuel']['current'] = 0;
        $this->assertSame(100, app(CycleAccounting::class)->reachableInventory($this->agent->fresh(), $ship)['estimated_value_after_fuel']);
        $ship['nav']['waypointSymbol'] = 'X1-TEST-PORT';
        $this->assertNull(app(CycleAccounting::class)->reachableInventory($this->agent->fresh(), $ship)['estimated_value_after_fuel']);
        $this->travel(181)->seconds();
        $ship['nav']['waypointSymbol'] = 'X1-TEST-ROCK';
        $ship['fuel']['current'] = 100;
        $this->assertNull(app(CycleAccounting::class)->reachableInventory($this->agent->fresh(), $ship)['estimated_value_after_fuel']);
    }

    public function test_overlapping_ship_intervals_count_wall_time_once_and_each_ship_time_separately_in_report(): void
    {
        $run = $this->runCycle(['wear_allowance' => 20]);
        $copy = $run->replicate();
        $copy->ship_symbol = 'LOCAL-2';
        $copy->started_at = $run->started_at->addSeconds(60);
        $copy->save();
        $report = app(EvidenceAnalysis::class)->report($this->agent->fresh());
        $row = $report['mining_loads'][0];
        $this->assertSame(180, $row['elapsed_seconds']);
        $this->assertEquals(240 / 3600, $row['ship_hours']);
        $this->assertEquals(440 * 3600 / 180, $row['comparable_net_per_hour']);
        $this->assertEquals(6600, $row['income_per_ship_hour']);
    }

    public function test_fleet_comparison_uses_best_owned_current_assets_rate_and_incremental_gain_with_capital_and_throughput(): void
    {
        $run = $this->runCycle(['wear_allowance' => 20]);
        $best = $run->replicate();
        $totals = $best->totals;
        $totals['accounting']['net_credits_per_hour'] = 7000;
        $best->totals = $totals;
        $best->save();
        $case = ['reference_run' => $run->id, 'basis_note' => 'Synthetic measured operating case, same endpoints and matched fuel.', 'costs_complete' => true,
            'ship_capital' => 40000, 'equipment_capital' => 10000, 'base_net' => 9000, 'conservative_net' => 8000, 'elapsed_seconds' => 3600,
            'ship_seconds' => 7200, 'production_units' => 20, 'round_trip_seconds' => 600, 'hauler_capacity' => 30, 'buyer_capacity' => 25];
        $url = '/agents/'.$this->agent->id.'/economics/investment';
        $this->postJson($url, $case)->assertOk()->assertJsonPath('alternative_run', $best->id)->assertJsonPath('current_assets_net_per_hour', 7000)
            ->assertJsonPath('incremental_net_per_hour', 2000)->assertJsonPath('payback_hours', 25)->assertJsonPath('conservative_payback_hours', 50)
            ->assertJsonPath('income_per_ship_hour', 4500)->assertJsonPath('throughput_units_per_hour', 120);
        $case['buyer_capacity'] = 10;
        $this->postJson($url, $case)->assertOk()->assertJsonPath('ready', false)->assertJsonPath('payback_hours', null);
        $case['ship_capital'] = null;
        $this->postJson($url, $case)->assertOk()->assertJsonPath('capital', null);
        $this->postJson($url, array_replace($case, ['production_units' => null]))->assertOk()->assertJsonPath('throughput_units_per_hour', null);
        $case['ship_capital'] = 40000;
        $case['base_net'] = 7000;
        $case['conservative_net'] = 6000;
        $this->postJson($url, $case)->assertOk()->assertJsonPath('payback_hours', null);
        $case['costs_complete'] = false;
        $this->postJson($url, $case)->assertUnprocessable();
        $this->actingAs(User::factory()->create())->postJson($url, $case)->assertNotFound();
    }

    public function test_capital_requires_confirmed_owned_reset_purchase_and_never_uses_offer_prices(): void
    {
        $world = FakeSpaceTraders::world();
        $world['ships'][0]['mounts'] = [];
        file_put_contents($this->worldFile, json_encode($world));
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->assertNull(app(CycleAccounting::class)->capital($this->agent, ['LOCAL-1'])['total']);
        $executor = app(ShipCommandExecutor::class);
        $action = $executor->preview($this->agent->fresh(), 'LOCAL-1', 'buy_ship', ['shipType' => 'SHIP_MINING_DRONE', 'waypointSymbol' => 'X1-TEST-ROCK',
            'maxTotal' => 20000, 'reserve' => 2000, 'site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-ROCK'], (string) Str::uuid());
        $executor->approve($this->agent->fresh(), $action);
        $executor->execute($action->id);
        $symbol = $action->fresh()->outcome['delivered_ship'];
        $this->assertSame(20000, app(CycleAccounting::class)->capital($this->agent->fresh(), [$symbol])['total']);
        $this->agent->reset_id = 'different-world';
        $this->assertNull(app(CycleAccounting::class)->capital($this->agent, [$symbol])['total']);
    }

    public function test_runner_withholds_normalized_income_for_unmatched_starting_fuel(): void
    {
        $world = FakeSpaceTraders::world();
        $world['ships'][0]['fuel']['current'] = 80;
        file_put_contents($this->worldFile, json_encode($world));
        $run = $this->runCycle(['wear_allowance' => 20]);
        $this->assertSame('completed', $run->state->value);
        $this->assertSame(100, $run->totals['ending_fuel']);
        $this->assertContains('Starting and ending tank fuel differ.', $run->totals['accounting']['unresolved']);
        $this->assertNull($run->totals['accounting']['net_credits_per_hour']);
    }

    public function test_runner_retains_unsold_cargo_and_keeps_price_changes_out_of_comparable_income(): void
    {
        $run = $this->runCycle(['wear_allowance' => 20], function ($run) {
            if (($run->totals['attempts'] ?? 0) === 0) {
                return;
            }
            $world = json_decode(file_get_contents($this->worldFile), true);
            foreach ($world['goods'] as &$good) {
                if ($good['symbol'] !== 'FUEL') {
                    $good['sellPrice'] = 0;
                }
            }
            file_put_contents($this->worldFile, json_encode($world));
        });
        $this->assertSame('blocked', $run->state->value);
        $this->assertNotEmpty($run->totals['accounting']['unsold_inventory']);
        $this->assertSame(0, $run->totals['sale_proceeds']);
        $this->assertNull($run->totals['accounting']['net_credits_per_hour']);
        $this->assertSame('unresolved', app(EvidenceAnalysis::class)->report($this->agent->fresh())['cycles'][0]['accounting']['state']);
    }

    public function test_wear_input_rejects_fractional_and_negative_amounts_but_explicit_zero_survives_http(): void
    {
        app(AgentSynchronizer::class)->sync($this->agent);
        $limits = ['ship_symbol' => 'LOCAL-1', 'site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-ROCK', 'reserve' => 2000, 'max_spend' => 100,
            'max_loss' => 100, 'minutes' => 60, 'cycles' => 1];
        $url = '/agents/'.$this->agent->id.'/runs/preview';
        $this->postJson($url, $limits + ['wear_allowance' => -1])->assertUnprocessable()->assertJsonValidationErrors('wear_allowance');
        $this->postJson($url, $limits + ['wear_allowance' => 0.5])->assertUnprocessable()->assertJsonValidationErrors('wear_allowance');
        $this->postJson($url, $limits + ['wear_allowance' => '0'])->assertAccepted()->assertJsonPath('plan.wear_allowance', 0);
    }

    public function test_contract_evaluator_labels_cash_forecasts_as_incomplete_cycle_estimates(): void
    {
        $investigator = app(OpportunityInvestigator::class);
        $record = $investigator->create($this->agent->fresh(), 'contracts', 'X1-TEST');
        for ($i = 0; $i < 10 && $record->fresh()->status === 'investigating'; $i++) {
            $investigator->advance($this->agent->fresh(), $record->fresh());
        }
        $candidate = collect($record->fresh()->candidates)->firstWhere('classification', 'ready');
        $this->assertSame('contract_cash_contribution', $candidate['estimate_kind']);
        $this->assertSame('partial_estimate', $candidate['estimates']['state']);
        $this->assertNull($candidate['estimates']['base']['net_per_hour']);
        $this->assertNull($candidate['estimates']['conservative']['net_per_hour']);
    }
}
