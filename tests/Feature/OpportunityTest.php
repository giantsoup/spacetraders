<?php

namespace Tests\Feature;

use App\Enums\ActionState;
use App\Gameplay\AgentSynchronizer;
use App\Gameplay\CycleAccounting;
use App\Gameplay\FuelPlanner;
use App\Gameplay\GameException;
use App\Gameplay\MiningRunner;
use App\Gameplay\OpportunityAssessor;
use App\Gameplay\RequestDeferred;
use App\Gameplay\ShipCommandExecutor;
use App\Gameplay\SurveyPool;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\GameObservation;
use App\Models\ObservationHistory;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use App\Services\SpaceTradersClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class OpportunityTest extends TestCase
{
    use RefreshDatabase;

    private string $worldFile;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->worldFile = tempnam(sys_get_temp_dir(), 'st-opportunity-');
        file_put_contents($this->worldFile, json_encode(FakeSpaceTraders::world()));
        FakeSpaceTraders::install($this->worldFile);
        $user = User::factory()->create();
        $this->agent = $user->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic-opportunity-token', 'account_id' => 'fixture', 'faction' => 'COSMIC',
            'reset_id' => '2026-10-25', 'canonical_identity' => AgentSynchronizer::identity('2026-10-25', 'LOCAL')]);
        $this->actingAs($user);
    }

    protected function tearDown(): void
    {
        unlink($this->worldFile);
        parent::tearDown();
    }

    private function world(callable $change): void
    {
        $world = json_decode(file_get_contents($this->worldFile), true);
        $change($world);
        file_put_contents($this->worldFile, json_encode($world));
    }

    private function command(string $command, array $args = [], string $symbol = 'LOCAL-1'): ShipAction
    {
        $executor = app(ShipCommandExecutor::class);
        $intent = $executor->preview($this->agent->fresh(), $symbol, $command, $args, (string) Str::uuid());
        $executor->approve($this->agent->fresh(), $intent);
        $executor->execute($intent->id);

        return $intent->fresh();
    }

    private function boundedRun(string $strategy, array $limits = []): AutomationRun
    {
        config(['gameplay.enabled' => true]);
        $defaults = ['strategy' => $strategy, 'reserve' => 1000, 'max_spend' => 1000, 'max_loss' => 1000, 'minutes' => 60,
            'max_fuel' => 30, 'max_visits' => 1, 'destination' => 'X1-TEST-PORT', 'market' => 'X1-TEST-PORT',
            'symbol' => 'IRON_ORE', 'units' => 10, 'max_inventory' => 500, 'min_margin' => 10, 'min_sell_price' => 20,
            'site' => 'X1-TEST-ROCK', 'cycles' => 1];
        $runner = app(MiningRunner::class);
        $run = $runner->preview($this->agent->fresh(), 'LOCAL-1', array_merge($defaults, $limits));
        $runner->start($this->agent->fresh(), $run);

        return $run->fresh();
    }

    private function advance(AutomationRun $run): void
    {
        $run->update(['next_action_at' => now()]);
        app(MiningRunner::class)->advance($run->id);
        foreach (ShipAction::where('state', 'pending')->get() as $action) {
            app(ShipCommandExecutor::class)->execute($action->id);
        }
        $run->refresh();
    }

    private function tradeWorld(): void
    {
        $this->world(function (&$world) {
            $world['ships'][] = FakeSpaceTraders::ship('LOCAL-2', 'X1-TEST-PORT');
            $world['market_goods']['X1-TEST-PORT'] = $world['goods'];
            $world['market_goods']['X1-TEST-PORT'][0]['sellPrice'] = 60;
        });
        app(AgentSynchronizer::class)->sync($this->agent);
    }

    public function test_partial_catalogue_and_hidden_traits_stay_incomplete(): void
    {
        config(['gameplay.max_pages' => 1]);
        $this->world(function (&$world) {
            $world['page_size'] = 1;
            $world['waypoints'][0]['traits'] = [['symbol' => 'UNCHARTED']];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $catalogue = GameObservation::where('kind', 'catalogue')->sole();
        $this->assertFalse($catalogue->data['complete']);
        $this->assertSame(1, $catalogue->data['fetched']);
        $this->assertSame(2, $catalogue->data['total']);
        $this->assertSame('UNCHARTED', GameObservation::where('kind', 'waypoint')->sole()->data['traits'][0]['symbol']);
        config(['gameplay.max_pages' => 10]);
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->assertTrue($catalogue->fresh()->data['complete']);
        $this->assertStringContainsString('hidden traits', $catalogue->fresh()->data['reason']);
    }

    public function test_remote_directory_refresh_preserves_detailed_quote_age_and_history(): void
    {
        $sync = app(AgentSynchronizer::class);
        $sync->sync($this->agent);
        $quote = GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-ROCK')->sole();
        $time = $quote->observed_at;
        $this->assertSame('LOCAL-1', $quote->observer_ship);
        $this->travel(4)->minutes();
        $this->world(fn (&$world) => $world['ships'][0]['nav']['waypointSymbol'] = 'X1-TEST-PORT');
        $sync->sync($this->agent);
        $this->assertTrue($quote->fresh()->observed_at->equalTo($time));
        $this->assertNotEmpty($quote->fresh()->data['tradeGoods']);
        $this->assertTrue(GameObservation::where('kind', 'market_listing')->where('symbol', $quote->symbol)->sole()->observed_at->gt($time));
        $this->assertSame(1, ObservationHistory::where('kind', 'market')->where('symbol', $quote->symbol)->count());
        $this->world(fn (&$world) => $world['ships'][0]['nav']['waypointSymbol'] = 'X1-TEST-ROCK');
        $sync->sync($this->agent);
        $this->assertSame(2, ObservationHistory::where('kind', 'market')->where('symbol', $quote->symbol)->count());
        $this->assertNotEmpty(ObservationHistory::where('kind', 'market')->where('symbol', $quote->symbol)->first()->data['tradeGoods']);
    }

    public function test_observation_budget_is_visible_and_reset_does_not_renew_old_prices(): void
    {
        config(['gameplay.max_observations' => 1]);
        $sync = app(AgentSynchronizer::class);
        $sync->sync($this->agent);
        $this->assertFalse(GameObservation::where('kind', 'discovery')->sole()->data['complete']);
        $this->assertSame(1, GameObservation::where('kind', 'discovery')->sole()->data['observations']);
        $this->agent->update(['reset_id' => 'new-fixture-world']);
        $new = $sync->observe($this->agent, 'market', 'X1-TEST-ROCK', ['symbol' => 'X1-TEST-ROCK', 'imports' => [], 'exports' => [], 'exchange' => []]);
        $this->assertArrayNotHasKey('tradeGoods', $new->data);
        $this->assertSame('new-fixture-world', $new->reset_id);
        $this->assertSame('2026-10-25', ObservationHistory::where('kind', 'market')->oldest('id')->first()->reset_id);
    }

    public function test_an_affordable_outbound_leg_with_no_fuel_safe_continuation_is_rejected(): void
    {
        app(AgentSynchronizer::class)->sync($this->agent);
        $ship = FakeSpaceTraders::ship();
        $ship['fuel']['current'] = 10;
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('No fuel-safe continuation');
        app(FuelPlanner::class)->leg($this->agent->fresh(), $ship, 'X1-TEST-PORT');
    }

    public function test_fuel_safety_requires_fresh_prices_affordability_and_explicit_uncertainty_fuel(): void
    {
        app(AgentSynchronizer::class)->sync($this->agent);
        $ship = FakeSpaceTraders::ship();
        $plan = app(FuelPlanner::class)->leg($this->agent->fresh(), $ship, 'X1-TEST-PORT', 99990, 3);
        $this->assertSame(17, $plan['required_fuel']);
        $this->assertSame(3, $plan['uncertainty_reserve']);
        $this->assertNull($plan['estimated_seconds']);
        $this->assertSame('X1-TEST-ROCK', $plan['continuation']);
        $this->travel(181)->seconds();
        $this->expectException(GameException::class);
        app(FuelPlanner::class)->leg($this->agent->fresh(), $ship, 'X1-TEST-PORT');
    }

    public function test_unstable_modifier_is_refreshed_and_blocks_manual_extraction_before_write(): void
    {
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->world(function (&$world) {
            $world['ships'][0]['nav']['status'] = 'IN_ORBIT';
            $world['waypoints'][0]['modifiers'] = [['symbol' => 'UNSTABLE']];
        });
        try {
            app(ShipCommandExecutor::class)->preview($this->agent->fresh(), 'LOCAL-1', 'mine', [], (string) Str::uuid());
            $this->fail('Unsafe extraction was previewed.');
        } catch (GameException $e) {
            $this->assertStringContainsString('UNSTABLE', $e->getMessage());
        }
        $this->assertSame([], json_decode(file_get_contents($this->worldFile), true)['writes']);
        $this->assertSame('UNSTABLE', GameObservation::where('kind', 'waypoint')->where('symbol', 'X1-TEST-ROCK')->sole()->data['modifiers'][0]['symbol']);
    }

    public function test_assessment_is_local_and_keeps_unknown_prices_and_earnings_unresolved(): void
    {
        app(AgentSynchronizer::class)->sync($this->agent);
        $before = json_decode(file_get_contents($this->worldFile), true)['calls'];
        $report = app(OpportunityAssessor::class)->report($this->agent->fresh());
        $this->assertSame('prices_unknown', collect($report['markets'])->firstWhere('symbol', 'X1-TEST-PORT')['state']);
        $this->assertNotEmpty($report['observation_tasks']);
        $this->assertSame('goods_match_only', collect($report['opportunities'])->firstWhere('strategy', 'trade')['state']);
        foreach ($report['opportunities'] as $opportunity) {
            $this->assertNull($opportunity['net_credits_per_hour']);
        }
        $this->assertSame($before, json_decode(file_get_contents($this->worldFile), true)['calls']);
        $this->get(route('agents.show', $this->agent))->assertOk()->assertDontSee('synthetic-opportunity-token');
    }

    public function test_complete_cycle_accounting_discloses_returns_fuel_wear_and_unsold_inventory(): void
    {
        $accounting = new CycleAccounting;
        $start = ['waypoint' => 'A', 'fuel' => 100, 'inventory' => []];
        $end = ['waypoint' => 'B', 'fuel' => 90, 'inventory' => ['ORE' => 10]];
        $open = $accounting->summarize($start, $end, ['sales' => 500, 'goods' => 100, 'fuel' => 20, 'repairs' => 10], 3600);
        $this->assertSame(370, $open['cash_contribution']);
        $this->assertCount(4, $open['unresolved']);
        $this->assertNull($open['net_credits_per_hour']);
        $closed = $accounting->summarize($start, $start, ['sales' => 500, 'goods' => 100, 'fuel' => 20, 'repairs' => 10, 'wear_recorded' => true, 'capital' => 50000], 1800, 2);
        $this->assertEquals(740, $closed['net_credits_per_hour']);
        $this->assertEquals(370, $closed['income_per_ship_hour']);
        $this->assertFalse($accounting->fleetInvestment(50000, 1000, 100, 40, 80)['ready']);
        $this->assertNull($accounting->fleetInvestment(50000, null, 10, 40, 80)['payback_hours']);
    }

    public function test_signed_surveys_preserve_duplicates_and_exhaustion_is_shared(): void
    {
        $signed = ['signature' => 'signed-fixture-001', 'symbol' => 'X1-TEST-ROCK', 'deposits' => [['symbol' => 'IRON_ORE'], ['symbol' => 'IRON_ORE'], ['symbol' => 'COPPER_ORE']], 'expiration' => now()->addHour()->toIso8601String(), 'size' => 'LARGE'];
        $this->world(function (&$world) use ($signed) {
            $world['ships'][0]['nav']['status'] = 'IN_ORBIT';
            $world['ships'][0]['mounts'][] = ['symbol' => 'MOUNT_SURVEYOR_I', 'requirements' => []];
            $world['surveys'] = [$signed];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->assertSame(ActionState::Confirmed, $this->command('survey')->state);
        $survey = GameObservation::where('kind', 'survey')->sole();
        $this->assertSame($signed, $survey->data['signed']);
        $this->assertSame(['IRON_ORE' => 2, 'COPPER_ORE' => 1], app(SurveyPool::class)->summary($signed)['deposit_counts']);
        $assessment = app(SurveyPool::class)->assess($this->agent->fresh(), $signed, Ship::where('symbol', 'LOCAL-1')->sole()->snapshot);
        $this->assertTrue($assessment['fully_priced']);
        $this->assertEqualsWithDelta(80 / 3, $assessment['mix_price_index'], 0.0001);
        $mixed = $signed;
        $mixed['deposits'][] = ['symbol' => 'DIAMONDS'];
        $this->assertNull(app(SurveyPool::class)->assess($this->agent->fresh(), $mixed, Ship::where('symbol', 'LOCAL-1')->sole()->snapshot)['mix_price_index']);
        $action = $this->command('mine_survey', ['surveySignature' => $signed['signature'], 'surveyWaypoint' => $signed['symbol']]);
        $this->assertSame(ActionState::Confirmed, $action->state);
        $writes = json_decode(file_get_contents($this->worldFile), true)['writes'];
        $this->assertSame('/my/ships/LOCAL-1/extract/survey', $writes[1]['path']);
        $this->assertSame($signed, $writes[1]['arguments']);
        $this->world(fn (&$world) => $world['survey_exhausted'] = true);
        $failed = $this->command('mine_survey', ['surveySignature' => $signed['signature'], 'surveyWaypoint' => $signed['symbol']]);
        $this->assertSame(ActionState::Rejected, $failed->state);
        $this->assertTrue($survey->fresh()->data['exhausted']);
        $this->expectException(GameException::class);
        app(SurveyPool::class)->usable($this->agent, $signed['signature'], $signed['symbol']);
    }

    public function test_expired_signed_survey_cannot_be_sent(): void
    {
        $signed = ['signature' => 'expired-fixture', 'symbol' => 'X1-TEST-ROCK', 'deposits' => [['symbol' => 'IRON_ORE']], 'expiration' => now()->subSecond()->toIso8601String(), 'size' => 'SMALL'];
        app(SurveyPool::class)->save($this->agent, $signed, 'LOCAL-1');
        $this->expectException(GameException::class);
        app(SurveyPool::class)->usable($this->agent, $signed['signature'], $signed['symbol']);
    }

    public function test_transfer_checks_both_ship_states_capacity_and_confirms_both_cargoes(): void
    {
        $this->world(function (&$world) {
            $world['ships'][0]['cargo']['units'] = 8;
            $world['ships'][0]['cargo']['inventory'] = [['symbol' => 'IRON_ORE', 'units' => 8]];
            $world['ships'][] = FakeSpaceTraders::ship('LOCAL-2');
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $args = ['symbol' => 'IRON_ORE', 'units' => 5, 'targetShip' => 'LOCAL-2'];
        $action = $this->command('transfer', $args);
        $this->assertSame(ActionState::Confirmed, $action->state);
        $this->assertSame(3, Ship::where('symbol', 'LOCAL-1')->sole()->snapshot['cargo']['units']);
        $this->assertSame(5, Ship::where('symbol', 'LOCAL-2')->sole()->snapshot['cargo']['units']);
        $this->world(fn (&$world) => $world['ships'][1]['nav']['status'] = 'IN_ORBIT');
        try {
            $this->command('transfer', array_merge($args, ['units' => 1]));
            $this->fail('Mismatched ship states allowed.');
        } catch (GameException $e) {
            $this->assertStringContainsString('matching', $e->getMessage());
        }
        $this->world(function (&$world) {
            $world['ships'][1]['nav']['status'] = 'DOCKED';
            $world['ships'][1]['cargo']['capacity'] = 5;
        });
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('capacity');
        $this->command('transfer', array_merge($args, ['units' => 1]));
    }

    public function test_uncertain_transfer_never_replays_and_reconciles_both_ships(): void
    {
        $this->world(function (&$world) {
            $world['ships'][0]['cargo']['units'] = 8;
            $world['ships'][0]['cargo']['inventory'] = [['symbol' => 'IRON_ORE', 'units' => 8]];
            $world['ships'][] = FakeSpaceTraders::ship('LOCAL-2');
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->world(fn (&$world) => $world['failure'] = 'timeout_after');
        $action = $this->command('transfer', ['symbol' => 'IRON_ORE', 'units' => 5, 'targetShip' => 'LOCAL-2']);
        $this->assertSame(ActionState::Uncertain, $action->state);
        app(ShipCommandExecutor::class)->execute($action->id);
        app(ShipCommandExecutor::class)->reconcile($this->agent->fresh(), $action);
        $this->assertSame(1, count(json_decode(file_get_contents($this->worldFile), true)['writes']));
        $this->assertSame(5, Ship::where('symbol', 'LOCAL-2')->sole()->snapshot['cargo']['units']);
        $this->assertSame(ActionState::Uncertain, $action->fresh()->state);
    }

    public function test_scout_answers_one_question_returns_and_stops_with_persisted_limits(): void
    {
        app(AgentSynchronizer::class)->sync($this->agent);
        $run = $this->boundedRun('scout');
        $this->assertStringContainsString('Observe prices', $run->plan['route']['purpose']);
        for ($step = 0; $step < 30 && $run->active_agent_id; $step++) {
            $this->advance($run);
        }
        $this->assertSame('completed', $run->state->value, $run->blocked_reason ?? '');
        $this->assertSame(1, $run->totals['visits']);
        $this->assertSame(10, $run->totals['travel_fuel']);
        $this->assertSame('X1-TEST-ROCK', Ship::where('symbol', 'LOCAL-1')->sole()->snapshot['nav']['waypointSymbol']);
        $this->assertNotEmpty(GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-PORT')->sole()->data['tradeGoods']);
        $this->assertSame([], ShipAction::whereIn('command', ['mine', 'buy', 'sell'])->get()->all());
    }

    public function test_trade_batches_refresh_returns_and_accounts_for_acquisition(): void
    {
        $this->tradeWorld();
        $run = $this->boundedRun('trade');
        for ($step = 0; $step < 40 && $run->active_agent_id; $step++) {
            $this->advance($run);
        }
        $this->assertSame('completed', $run->state->value, $run->blocked_reason ?? '');
        $this->assertSame(['IRON_ORE' => 10], $run->totals['purchased']);
        $this->assertSame(['IRON_ORE' => 10], $run->totals['sold']);
        $this->assertSame(300, $run->totals['purchase_cost']);
        $this->assertSame(600, $run->totals['sale_proceeds']);
        $this->assertSame(295, $run->totals['cash_contribution']);
        $this->assertSame([4, 4, 2], ShipAction::where('automation_run_id', $run->id)->where('command', 'buy')->get()->map(fn ($action) => $action->arguments['units'])->all());
        $this->assertNull($run->totals['accounting']['net_credits_per_hour']);
    }

    public function test_falling_spread_stops_queued_purchase_and_retains_a_remaining_cargo_plan(): void
    {
        $this->tradeWorld();
        $run = $this->boundedRun('trade');
        $this->advance($run);
        $this->assertSame(4, $run->totals['purchased']['IRON_ORE']);
        app(MiningRunner::class)->advance($run->id);
        $this->world(fn (&$world) => $world['market_goods']['X1-TEST-PORT'][0]['sellPrice'] = 5);
        $run->update(['next_action_at' => now()]);
        app(MiningRunner::class)->advance($run->id);
        foreach (ShipAction::where('state', 'pending')->get() as $action) {
            app(ShipCommandExecutor::class)->execute($action->id);
        }
        $run->refresh();
        $this->assertSame('blocked', $run->state->value);
        $this->assertSame(4, Ship::where('symbol', 'LOCAL-1')->sole()->snapshot['cargo']['units']);
        $this->assertSame(1, ShipAction::where('command', 'buy')->where('state', 'confirmed')->count());
        $options = app(OpportunityAssessor::class)->report($this->agent->fresh())['cargo_options'];
        $this->assertSame('retain', $options[0]['choices'][0]['action']);
        $this->assertTrue(collect($options[0]['choices'])->contains('action', 'sell_locally'));
    }

    public function test_bounded_load_returns_when_approved_headroom_is_used(): void
    {
        app(AgentSynchronizer::class)->sync($this->agent);
        $run = $this->boundedRun('mining', ['market' => 'X1-TEST-ROCK', 'max_extractions' => 5, 'extraction_headroom' => 15, 'site_seconds' => 300]);
        for ($step = 0; $step < 40 && $run->active_agent_id; $step++) {
            $this->advance($run);
        }
        $this->assertSame('completed', $run->state->value, $run->blocked_reason ?? '');
        $this->assertSame(3, $run->totals['attempts']);
        $this->assertSame($run->totals['extracted'], $run->totals['sold']);
        $this->assertSame(3, ObservationHistory::where('kind', 'extraction')->count());
        $this->assertSame([], $run->totals['unsold']);
    }

    public function test_navigation_shared_command_rejects_stranding_before_preview_or_dispatch(): void
    {
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->world(function (&$world) {
            $world['ships'][0]['nav']['status'] = 'IN_ORBIT';
            $world['ships'][0]['fuel']['current'] = 10;
        });
        try {
            $this->command('navigate', ['waypointSymbol' => 'X1-TEST-PORT']);
            $this->fail('Stranding navigation approved.');
        } catch (GameException $e) {
            $this->assertStringContainsString('continuation', $e->getMessage());
        }
        $this->assertSame([], json_decode(file_get_contents($this->worldFile), true)['writes']);
    }

    public function test_measured_only_policy_requires_both_edges_for_the_actual_configuration(): void
    {
        app(AgentSynchronizer::class)->sync($this->agent);
        $ship = FakeSpaceTraders::ship();
        $sync = app(AgentSynchronizer::class);
        foreach ([['X1-TEST-ROCK', 'X1-TEST-PORT'], ['X1-TEST-PORT', 'X1-TEST-ROCK']] as [$from, $to]) {
            $sync->observe($this->agent, 'travel', 'LOCAL-1', ['configuration' => FuelPlanner::configuration($ship), 'from' => $from, 'to' => $to, 'actual_fuel' => 5], 'navigation', 'LOCAL-1');
        }
        config(['gameplay.route_evidence' => 'measured']);
        $route = app(FuelPlanner::class)->leg($this->agent->fresh(), $ship, 'X1-TEST-PORT');
        $this->assertSame(12, $route['required_fuel']);
        $ship['engine']['condition'] = 0.9;
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Measured fuel evidence');
        app(FuelPlanner::class)->leg($this->agent->fresh(), $ship, 'X1-TEST-PORT');
    }

    public function test_scout_stop_before_pending_action_prevents_movement(): void
    {
        app(AgentSynchronizer::class)->sync($this->agent);
        $run = $this->boundedRun('scout');
        app(MiningRunner::class)->advance($run->id);
        $pending = ShipAction::where('state', 'pending')->sole();
        app(MiningRunner::class)->stop($run);
        app(ShipCommandExecutor::class)->execute($pending->id);
        $this->assertSame(ActionState::Rejected, $pending->fresh()->state);
        $this->assertSame([], json_decode(file_get_contents($this->worldFile), true)['writes']);
    }

    public function test_trade_lost_purchase_and_reset_never_continue_or_replay(): void
    {
        $this->tradeWorld();
        $run = $this->boundedRun('trade');
        $this->world(fn (&$world) => $world['failure'] = 'timeout_after');
        $this->advance($run);
        $action = ShipAction::where('command', 'buy')->sole();
        $this->assertSame(ActionState::Uncertain, $action->state);
        $this->advance($run);
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame(1, count(json_decode(file_get_contents($this->worldFile), true)['writes']));
        $this->world(fn (&$world) => $world['reset'] = 'new-fixture-reset');
        try {
            app(ShipCommandExecutor::class)->reconcile($this->agent->fresh(), $action);
            $this->fail('Old-world reconciliation accepted.');
        } catch (GameException $e) {
            $this->assertStringContainsString('reset', $e->getMessage());
        }
        $this->assertSame(1, count(json_decode(file_get_contents($this->worldFile), true)['writes']));
    }

    public function test_longer_mining_pauses_when_modifier_changes_after_first_extraction(): void
    {
        app(AgentSynchronizer::class)->sync($this->agent);
        $run = $this->boundedRun('mining', ['market' => 'X1-TEST-ROCK', 'max_extractions' => 5, 'extraction_headroom' => 15, 'site_seconds' => 300]);
        for ($step = 0; $step < 5 && ($run->totals['attempts'] ?? 0) === 0; $step++) {
            $this->advance($run);
        }
        $this->world(fn (&$world) => $world['waypoints'][0]['modifiers'] = [['symbol' => 'CRITICAL_LIMIT']]);
        $this->advance($run);
        $this->assertSame('blocked', $run->state->value);
        $this->assertStringContainsString('CRITICAL_LIMIT', $run->blocked_reason);
        $this->assertSame(1, $run->totals['attempts']);
        $this->assertGreaterThan(0, Ship::where('symbol', 'LOCAL-1')->sole()->snapshot['cargo']['units']);
    }

    public function test_longer_mining_departs_at_site_time_limit_and_never_resumes_after_selling_a_batch(): void
    {
        app(AgentSynchronizer::class)->sync($this->agent);
        $run = $this->boundedRun('mining', ['market' => 'X1-TEST-ROCK', 'max_extractions' => 5, 'extraction_headroom' => 1, 'site_seconds' => 10]);
        for ($step = 0; $step < 5 && ($run->totals['attempts'] ?? 0) === 0; $step++) {
            $this->advance($run);
        }
        $this->travel(11)->seconds();
        for ($step = 0; $step < 20 && $run->active_agent_id; $step++) {
            $this->advance($run);
        }
        $this->assertSame('completed', $run->state->value, $run->blocked_reason ?? '');
        $this->assertSame(1, $run->totals['attempts']);
    }

    public function test_operation_http_validation_requires_explicit_exposure_and_travel_limits(): void
    {
        $this->postJson(route('game.runs.preview', $this->agent), ['strategy' => 'trade', 'ship_symbol' => 'LOCAL-1', 'market' => 'X1-TEST-PORT', 'reserve' => 1000, 'max_spend' => 500, 'max_loss' => 500, 'minutes' => 15])
            ->assertUnprocessable()->assertJsonValidationErrors(['max_inventory', 'max_fuel', 'symbol', 'units', 'min_margin', 'min_sell_price']);
        $this->assertDatabaseCount('automation_runs', 0);
    }

    public function test_exhausted_response_budget_is_shared_with_the_next_command_path(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['version' => 'v2.3.0', 'resetDate' => 'fixture'], 200, [
            'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) (time() + 30),
        ]));
        app(SpaceTradersClient::class)->request('get', '/');
        $this->expectException(RequestDeferred::class);
        app(SpaceTradersClient::class)->request('get', '/my/agent');
    }

    public function test_page_failure_keeps_partial_reset_scoped_geography_and_distinct_scan_evidence(): void
    {
        $this->world(function (&$world) {
            $world['page_size'] = 1;
            $world['fail_waypoint_page'] = 2;
        });
        try {
            app(AgentSynchronizer::class)->sync($this->agent);
            $this->fail('Injected page failure was ignored.');
        } catch (GameException) {
            $this->assertSame(1, GameObservation::where('kind', 'waypoint')->count());
        }
        $this->assertFalse(GameObservation::where('kind', 'catalogue')->sole()->data['complete']);
        $point = GameObservation::where('kind', 'waypoint')->sole()->data;
        $point['traits'] = [['symbol' => 'UNCHARTED']];
        app(AgentSynchronizer::class)->observe($this->agent, 'waypoint', $point['symbol'], $point, 'scan', 'LOCAL-1');
        $this->assertSame('UNCHARTED', GameObservation::where('kind', 'waypoint_scan')->sole()->data['traits'][0]['symbol']);
        $this->assertSame('MARKETPLACE', GameObservation::where('kind', 'waypoint_listing')->sole()->data['traits'][0]['symbol']);
    }

    public function test_refuel_detour_accounts_for_its_own_fuel_time_and_purchases(): void
    {
        $this->world(function (&$world) {
            $world['ships'][0]['fuel'] = ['current' => 10, 'capacity' => 20];
            $world['ships'][0]['engine']['speed'] = 10;
            $world['waypoints'][1]['x'] = 8;
            $world['waypoints'][1]['y'] = 0;
            $stop = $world['waypoints'][1];
            $stop['symbol'] = 'X1-TEST-FUEL';
            $stop['x'] = 3;
            $world['waypoints'][] = $stop;
            $world['ships'][] = FakeSpaceTraders::ship('LOCAL-2', 'X1-TEST-FUEL');
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $ship = Ship::where('symbol', 'LOCAL-1')->sole()->snapshot;
        $options = app(FuelPlanner::class)->options($this->agent->fresh(), $ship, 'X1-TEST-PORT');
        $this->assertCount(1, $options);
        $this->assertSame(['X1-TEST-FUEL', 'X1-TEST-PORT'], $options[0]['stops']);
        $this->assertSame(12, $options[0]['fuel_units']);
        $this->assertSame(51, $options[0]['seconds']);
        $this->assertSame(20, $options[0]['refuel_ceiling']);
        $this->assertStringContainsString('displaced work', $options[0]['extra_costs']);
    }

    public function test_local_mutation_invalidates_a_quote_without_renewing_its_age(): void
    {
        app(AgentSynchronizer::class)->sync($this->agent);
        $quote = GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-ROCK')->sole();
        $action = $this->command('buy', ['symbol' => 'IRON_ORE', 'units' => 1, 'maxTotal' => 30]);
        $this->assertSame($action->id, $quote->fresh()->data['invalidated_by_action']);
        $this->assertTrue($quote->observed_at->equalTo($quote->fresh()->observed_at));
        $report = app(OpportunityAssessor::class)->report($this->agent->fresh());
        $this->assertSame('priced_stale', collect($report['markets'])->firstWhere('symbol', $quote->symbol)['state']);
        $this->command('orbit');
        $navigation = $this->command('navigate', ['waypointSymbol' => 'X1-TEST-PORT']);
        $this->assertSame(ActionState::Confirmed, $navigation->state);
        $this->assertSame(5, $navigation->outcome['travel']['actual_fuel']);
        $this->assertSame(7, $navigation->outcome['travel']['prediction']);
        $this->assertSame(1, ObservationHistory::where('kind', 'travel')->count());
    }

    public function test_scout_stops_without_movement_when_remote_read_answers_the_question(): void
    {
        app(AgentSynchronizer::class)->sync($this->agent);
        $run = $this->boundedRun('scout');
        $this->world(fn (&$world) => $world['ships'][] = FakeSpaceTraders::ship('LOCAL-2', 'X1-TEST-PORT'));
        for ($step = 0; $step < 4 && $run->active_agent_id; $step++) {
            $this->advance($run);
        }
        $this->assertSame('completed', $run->state->value);
        $this->assertSame(0, $run->totals['visits']);
        $this->assertSame('Observation complete. No earnings were assumed.', $run->blocked_reason);
        $this->assertSame([], json_decode(file_get_contents($this->worldFile), true)['writes']);
    }

    public function test_mining_automation_uses_the_explicit_signed_survey_without_a_surveyor_on_the_miner(): void
    {
        app(AgentSynchronizer::class)->sync($this->agent);
        $signed = ['signature' => 'approved-survey', 'symbol' => 'X1-TEST-ROCK', 'deposits' => [['symbol' => 'IRON_ORE'], ['symbol' => 'IRON_ORE']], 'expiration' => now()->addHour()->toIso8601String(), 'size' => 'SMALL'];
        app(SurveyPool::class)->save($this->agent, $signed, 'LOCAL-2');
        $run = $this->boundedRun('mining', ['market' => 'X1-TEST-ROCK', 'survey_signature' => $signed['signature']]);
        for ($step = 0; $step < 20 && $run->active_agent_id; $step++) {
            $this->advance($run);
        }
        $this->assertSame('completed', $run->state->value, $run->blocked_reason ?? '');
        $this->assertSame(1, ShipAction::where('command', 'mine_survey')->count());
        $this->assertSame(0, ShipAction::where('command', 'mine')->count());
        $write = collect(json_decode(file_get_contents($this->worldFile), true)['writes'])->first(fn ($write) => str_ends_with($write['path'], '/extract/survey'));
        $this->assertSame($signed, $write['arguments']);
    }

    public function test_actual_fuel_above_the_prediction_pauses_for_review_instead_of_repeating_a_route(): void
    {
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->world(function (&$world) {
            $world['ships'][0]['nav']['status'] = 'IN_ORBIT';
            $world['fuel_consumption'] = 8;
        });
        $navigation = $this->command('navigate', ['waypointSymbol' => 'X1-TEST-PORT']);
        $this->assertSame(ActionState::Confirmed, $navigation->state);
        $this->assertTrue($navigation->review_required);
        $this->assertSame(8, $navigation->outcome['travel']['actual_fuel']);
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('requires review');
        $this->command('dock');
    }

    public function test_missing_remote_directories_rotate_within_the_existing_observation_cap(): void
    {
        $this->world(function (&$world) {
            for ($index = 0; $index < 26; $index++) {
                $point = $world['waypoints'][1];
                $point['symbol'] = 'X1-TEST-M'.$index;
                $world['waypoints'][] = $point;
            }
        });
        $sync = app(AgentSynchronizer::class);
        $sync->sync($this->agent);
        $report = app(OpportunityAssessor::class)->report($this->agent->fresh());
        $this->assertTrue(collect($report['markets'])->contains('state', 'directory_unknown'));
        $this->assertSame(24, GameObservation::where('kind', 'discovery')->sole()->data['observations']);
        $before = count(array_filter(json_decode(file_get_contents($this->worldFile), true)['calls'], fn ($call) => str_ends_with($call['path'], '/market') || str_ends_with($call['path'], '/shipyard')));
        $sync->sync($this->agent);
        $this->assertSame(28, GameObservation::where('kind', 'market_listing')->count());
        $after = count(array_filter(json_decode(file_get_contents($this->worldFile), true)['calls'], fn ($call) => str_ends_with($call['path'], '/market') || str_ends_with($call['path'], '/shipyard')));
        $this->assertSame(24, $after - $before);
    }
}
