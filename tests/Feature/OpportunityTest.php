<?php

namespace Tests\Feature;

use App\Enums\ActionState;
use App\Enums\RunState;
use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\AutomationStaging;
use App\Gameplay\CreditAccounting;
use App\Gameplay\CycleAccounting;
use App\Gameplay\Engine\EngineHost;
use App\Gameplay\EngineGate;
use App\Gameplay\EvidenceAnalysis;
use App\Gameplay\FuelPlanner;
use App\Gameplay\GameException;
use App\Gameplay\MiningPlanner;
use App\Gameplay\OpportunityAssessor;
use App\Gameplay\OpportunityEvaluator;
use App\Gameplay\OpportunityInvestigator;
use App\Gameplay\RequestDeferred;
use App\Gameplay\ShipCommandExecutor;
use App\Gameplay\SpaceTradersApi;
use App\Gameplay\SurveyPool;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\GameObservation;
use App\Models\LocalEngine;
use App\Models\ObservationHistory;
use App\Models\OpportunityInvestigation;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use App\Services\SpaceTradersClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\Support\EngineFixture;
use Tests\Support\FakeEngineHost;
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
        $this->app->instance(EngineHost::class, new FakeEngineHost);
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
        Carbon::setTestNow();
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
        $runner = app(AutomationRunner::class);
        $run = $runner->preview($this->agent->fresh(), 'LOCAL-1', array_merge($defaults, $limits));
        $runner->start($this->agent->fresh(), $run);

        return $run->fresh();
    }

    private function advance(AutomationRun $run): void
    {
        $run->update(['next_action_at' => now()]);
        app(AutomationRunner::class)->advance($run->id);
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

    public static function inconsistentCataloguePages(): array
    {
        return [
            'repeated symbol' => ['repeat', 2, 'changed or repeated records'],
            'shrinking total' => ['shrink', 3, 'changed or repeated records'],
            'premature empty page' => ['empty', 2, 'returned an empty page'],
            'resumed without first-page total' => ['resume', 2, 'without a recorded total'],
        ];
    }

    #[DataProvider('inconsistentCataloguePages')]
    public function test_investigation_keeps_partial_coverage_when_catalogue_pages_are_inconsistent(string $change, int $initialTotal, string $reason): void
    {
        config(['gameplay.local_engine_enabled' => true]);
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->world(function (&$world) use ($initialTotal) {
            $world['page_size'] = 1;
            if ($initialTotal === 3) {
                $world['waypoints'][] = array_replace($world['waypoints'][0], ['symbol' => 'X1-TEST-EXTRA']);
            }
        });
        $record = $this->createPilotInvestigation([]);
        for ($step = 0; $step < 5 && ! isset($record->coverage['waypoint_page']); $step++) {
            $this->postJson(route('game.investigations.advance', [$this->agent, $record]))->assertAccepted();
            $record->refresh();
            $this->assertSame('investigating', $record->status);
        }
        $this->assertSame(2, $record->coverage['waypoint_page']);

        if ($change === 'resume') {
            $coverage = $record->coverage;
            unset($coverage['waypoint_total']);
            $record->update(['coverage' => $coverage]);
        } else {
            $this->world(function (&$world) use ($change) {
                match ($change) {
                    'repeat' => $world['waypoints'][1] = $world['waypoints'][0],
                    'shrink' => array_pop($world['waypoints']),
                    'empty' => $world['page_size'] = 20,
                };
            });
        }

        $record = $this->finishPilotInvestigation($record);
        $this->assertSame('partial', $record->status);
        $this->assertSame($initialTotal, $record->coverage['waypoint_total']);
        $this->assertSame($change === 'resume' ? ['X1-TEST-ROCK', 'X1-TEST-PORT'] : ['X1-TEST-ROCK'], array_keys($record->observations['waypoints']));
        $this->assertStringContainsString($reason, implode(' ', $record->coverage['limits_reached']));
        $this->assertArrayHasKey('X1-TEST-ROCK', $record->observations['markets']);
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

    public function test_fuel_safety_requires_bounded_prices_affordability_and_explicit_uncertainty_fuel(): void
    {
        app(AgentSynchronizer::class)->sync($this->agent);
        $ship = FakeSpaceTraders::ship();
        unset($ship['engine']['speed']);
        $plan = app(FuelPlanner::class)->leg($this->agent->fresh(), $ship, 'X1-TEST-PORT', 99990, 3);
        $this->assertSame(17, $plan['required_fuel']);
        $this->assertSame(3, $plan['uncertainty_reserve']);
        $this->assertNull($plan['estimated_seconds']);
        $this->assertSame('X1-TEST-ROCK', $plan['continuation']);
        $this->travel(181)->seconds();
        $stale = app(FuelPlanner::class)->leg($this->agent->fresh(), $ship, 'X1-TEST-PORT');
        $this->assertTrue($stale['fuel_price_stale']);
        $this->assertSame(20, $stale['refuel_ceiling']);
        $this->travel(6)->hours();
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
        $closed = $accounting->summarize($start, $start, ['sales' => 500, 'goods' => 100, 'fuel' => 20, 'repairs' => 10, 'wear_cost' => 10, 'capital' => 50000], 1800, 2);
        $this->assertEquals(740, $closed['net_credits_per_hour']);
        $this->assertEquals(370, $closed['income_per_ship_hour']);
        $this->assertFalse($accounting->fleetInvestment(['ship_capital' => 50000, 'equipment_capital' => 0, 'base_net' => 2000, 'conservative_net' => 1500, 'elapsed_seconds' => 3600, 'ship_seconds' => 7200, 'production_units' => 100, 'hauler_capacity' => 40, 'buyer_capacity' => 80, 'round_trip_seconds' => 3600], 1000)['ready']);
        $this->assertNull($accounting->fleetInvestment(['ship_capital' => 50000], null)['payback_hours']);
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

    public function test_two_trade_runs_share_credits_and_both_complete_with_interleaved_buys_and_sales(): void
    {
        $this->tradeWorld();
        $this->world(fn (&$world) => $world['ships'][] = FakeSpaceTraders::ship('LOCAL-3'));
        app(AgentSynchronizer::class)->sync($this->agent);
        $first = $this->boundedRun('trade');
        $runner = app(AutomationRunner::class);
        $second = $runner->preview($this->agent->fresh(), 'LOCAL-3', $first->plan);
        $runner->start($this->agent->fresh(), $second);
        for ($step = 0; $step < 50 && ($first->active_agent_id || $second->active_agent_id); $step++) {
            foreach ([$first, $second] as $run) {
                $this->advance($run);
            }
        }
        foreach ([$first, $second] as $run) {
            $this->assertSame('completed', $run->state->value, $run->blocked_reason ?? '');
            $this->assertSame(300, $run->totals['purchase_cost']);
            $this->assertSame(600, $run->totals['sale_proceeds']);
            $this->assertSame(295, $run->totals['credit_delta']);
        }
        $this->assertSame(100590, $this->agent->fresh()->credits);
        $this->assertSame(0, app(CreditAccounting::class)->summary($this->agent->fresh())['unexplained_change']);
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
        app(AutomationRunner::class)->advance($run->id);
        $this->world(fn (&$world) => $world['market_goods']['X1-TEST-PORT'][0]['sellPrice'] = 5);
        $run->update(['next_action_at' => now()]);
        app(AutomationRunner::class)->advance($run->id);
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
        app(AutomationRunner::class)->advance($run->id);
        $pending = ShipAction::where('state', 'pending')->sole();
        app(AutomationRunner::class)->stop($run);
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
        $this->travelTo(now()->setDate(2026, 11, 1)->setTime(12, 0, 0));
        app(AgentSynchronizer::class)->sync($this->agent);
        $quote = GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-ROCK')->sole();
        $this->travel(1)->seconds();
        $preflightAt = now()->toImmutable();
        $lastPriceObservation = null;
        $historyBeforeMutation = null;
        Http::globalRequestMiddleware(function (RequestInterface $request) use ($quote, &$lastPriceObservation, &$historyBeforeMutation) {
            if ($request->getMethod() === 'POST' && str_ends_with($request->getUri()->getPath(), '/purchase')) {
                $lastPriceObservation = $quote->fresh();
                $historyBeforeMutation = ObservationHistory::where('kind', 'market')->where('symbol', $quote->symbol)->count();
                $this->travel(1)->seconds();
            }

            return $request;
        });
        $action = $this->command('buy', ['symbol' => 'IRON_ORE', 'units' => 1, 'maxTotal' => 30]);
        $this->assertSame(ActionState::Confirmed, $action->state);
        $this->assertNotNull($lastPriceObservation);
        $this->assertTrue($lastPriceObservation->observed_at->equalTo($preflightAt));
        $this->assertTrue($lastPriceObservation->observed_at->gt($quote->observed_at));
        $this->assertTrue(now()->greaterThan($lastPriceObservation->observed_at));
        $this->assertSame($action->id, $quote->fresh()->data['invalidated_by_action']);
        $this->assertTrue($lastPriceObservation->observed_at->equalTo($quote->fresh()->observed_at));
        $this->assertSame($historyBeforeMutation, ObservationHistory::where('kind', 'market')->where('symbol', $quote->symbol)->count());
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

    public function test_expanded_operations_use_the_automation_branch_engine_gate_and_credit_ledger(): void
    {
        config(['gameplay.local_engine_enabled' => true]);
        EngineFixture::healthy();
        app(AgentSynchronizer::class)->sync($this->agent);
        $run = $this->boundedRun('scout');
        $this->assertSame(3, $run->plan_version);
        $this->assertSame(LocalEngine::find(1)->session_id, $run->engine_session_id);
        app(AutomationRunner::class)->advance($run->id);
        $pending = ShipAction::where('state', 'pending')->sole();
        $this->assertSame($run->engine_session_id, $pending->engine_session_id);
        app(EngineGate::class)->close();
        app(ShipCommandExecutor::class)->execute($pending->id);
        $this->assertSame(ActionState::Rejected, $pending->fresh()->state);
        $this->assertSame([], json_decode(file_get_contents($this->worldFile), true)['writes']);
    }

    public function test_bounded_loads_use_the_existing_investigation_staging_and_versioned_approval_flow(): void
    {
        config(['gameplay.enabled' => true, 'gameplay.local_engine_enabled' => true]);
        EngineFixture::healthy();
        app(AgentSynchronizer::class)->sync($this->agent);
        $investigator = app(OpportunityInvestigator::class);
        $investigation = $investigator->create($this->agent->fresh(), 'mining', 'X1-TEST');
        for ($step = 0; $step < 20 && $investigation->status === 'investigating'; $step++) {
            $investigation = $investigator->advance($this->agent->fresh(), $investigation);
        }
        $this->assertSame('complete', $investigation->status);
        $candidate = collect($investigation->candidates)->firstWhere('classification', 'ready');
        $draft = app(AutomationStaging::class)->save($this->agent->fresh(), $investigation, $candidate['id'], ['reserve' => 1000, 'max_spend' => 100, 'max_loss' => 100, 'minutes' => 60, 'cycles' => 1, 'purchase_limit' => 40, 'delivery_limit' => 10, 'max_extractions' => 2, 'extraction_headroom' => 15, 'site_seconds' => 300]);
        $runner = app(AutomationRunner::class);
        $run = $runner->previewDraft($this->agent->fresh(), $draft);
        $this->assertSame(2, $run->plan_version);
        $this->assertSame(2, $run->plan['max_extractions']);
        $runner->start($this->agent->fresh(), $run);
        $run->refresh();
        for ($step = 0; $step < 30 && $run->active_agent_id; $step++) {
            $this->advance($run);
        }
        $this->assertSame('completed', $run->state->value, $run->blocked_reason ?? '');
        $this->assertSame(2, $run->totals['attempts']);
        $this->assertSame($run->totals['extracted'], $run->totals['sold']);
        $this->assertGreaterThan(0, DB::table('confirmed_credit_entries')->where('automation_run_id', $run->id)->count());
    }

    public function test_confirmed_travel_collects_the_inputs_and_server_timeline_for_future_calibration(): void
    {
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->world(function (&$world) {
            $world['ships'][0]['nav']['status'] = 'IN_ORBIT';
            $world['travel_seconds'] = 30;
        });
        $action = $this->command('navigate', ['waypointSymbol' => 'X1-TEST-PORT']);
        $travel = $action->outcome['travel'];
        $this->assertSame('CRUISE', $travel['flight_mode']);
        $this->assertEquals(5, $travel['distance']);
        $this->assertSame(['x' => 0, 'y' => 0], $travel['from_coordinates']);
        $this->assertSame(30, $travel['ship_configuration']['engine']['speed']);
        $this->assertEquals(30, $travel['server_duration_seconds']);
        $this->assertSame(19, $travel['predicted_seconds']);
        $report = app(EvidenceAnalysis::class)->report($this->agent->fresh());
        $this->assertSame(1, $report['total_travel_samples']);
        $this->assertEquals(11, $report['travel'][0]['duration_error_mean']);
        $this->assertSame('conservative', config('gameplay.route_evidence'));
    }

    public function test_travel_analysis_groups_configuration_and_routes_and_preserves_reset_and_window_limits(): void
    {
        $sync = app(AgentSynchronizer::class);
        $ship = FakeSpaceTraders::ship();
        foreach ([5, 7, 10] as $index => $fuel) {
            $sync->observe($this->agent, 'travel', 'LOCAL-1', ['configuration' => FuelPlanner::configuration($ship), 'from' => 'X1-TEST-ROCK', 'to' => 'X1-TEST-PORT', 'actual_fuel' => $fuel,
                'prediction' => 7, 'distance' => 5, 'flight_mode' => 'CRUISE', 'tank_capacity' => 100, 'server_duration_seconds' => 20 + $index * 2, 'predicted_seconds' => 19], 'navigation', 'LOCAL-1');
        }
        ObservationHistory::create(['agent_id' => $this->agent->id, 'reset_id' => 'old-world', 'kind' => 'travel', 'symbol' => 'LOCAL-1', 'source' => 'navigation', 'data' => ['actual_fuel' => 9999], 'observed_at' => now()]);
        $report = app(EvidenceAnalysis::class)->report($this->agent);
        $this->assertSame(3, $report['total_travel_samples']);
        $this->assertCount(1, $report['travel']);
        $this->assertSame(1, $report['travel'][0]['fuel_overruns']);
        $this->assertEqualsWithDelta(22 / 3, $report['travel'][0]['actual_fuel_mean'], 0.00001);
        $this->assertEquals(3, $report['travel'][0]['duration_error_mean']);
        config(['gameplay.evidence_travel_limit' => 1]);
        $window = app(EvidenceAnalysis::class)->report($this->agent);
        $this->assertSame(3, $window['total_travel_samples']);
        $this->assertSame(1, $window['travel_samples_in_window']);
        $this->assertSame(10, $window['travel'][0]['actual_fuel_max']);
    }

    public function test_departure_policy_analysis_includes_blocked_and_retained_cargo_runs_without_claiming_profit(): void
    {
        foreach ([1, 3] as $count) {
            AutomationRun::create(['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-1', 'strategy' => 'mining',
                'state' => $count === 1 ? 'completed' : 'blocked', 'started_at' => now()->subHour(), 'expires_at' => now(),
                'plan' => ['site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-PORT', 'equipment' => 'same-equipment', 'initial_waypoint' => 'X1-TEST-ROCK', 'initial_fuel' => 100,
                    'max_extractions' => $count, 'extraction_headroom' => 15, 'site_seconds' => 300],
                'totals' => ['credit_delta' => $count === 1 ? 200 : -5, 'cycles' => $count === 1 ? 1 : 0, 'attempts' => $count, 'sale_proceeds' => $count === 1 ? 220 : 0,
                    'fuel_cost' => $count === 1 ? 20 : 5, 'elapsed_seconds' => $count === 1 ? 3600 : null, 'unsold' => $count === 1 ? [] : ['IRON_ORE' => 20], 'accounting' => ['state' => 'unresolved']]]);
        }
        $report = app(EvidenceAnalysis::class)->report($this->agent);
        $this->assertCount(2, $report['mining_loads']);
        $one = collect($report['mining_loads'])->firstWhere('max_extractions', 1);
        $bounded = collect($report['mining_loads'])->firstWhere('max_extractions', 3);
        $this->assertEquals(200, $one['cash_per_elapsed_hour']);
        $this->assertNull($one['comparable_net_per_hour']);
        $this->assertSame(['blocked' => 1], $bounded['states']);
        $this->assertSame(1, $bounded['runs_retaining_cargo']);
        $this->assertNull($bounded['cash_per_elapsed_hour']);
    }

    public function test_invalid_fuel_changes_stay_visible_but_cannot_authorize_measured_navigation(): void
    {
        app(AgentSynchronizer::class)->sync($this->agent);
        $ship = FakeSpaceTraders::ship();
        app(AgentSynchronizer::class)->observe($this->agent, 'travel', 'LOCAL-1', ['configuration' => FuelPlanner::configuration($ship), 'from' => 'X1-TEST-ROCK', 'to' => 'X1-TEST-PORT',
            'actual_fuel' => -2, 'prediction' => 7, 'flight_mode' => 'CRUISE'], 'navigation', 'LOCAL-1');
        $analysis = app(EvidenceAnalysis::class)->report($this->agent);
        $this->assertSame(1, $analysis['travel'][0]['invalid_fuel_samples']);
        $this->assertNull($analysis['travel'][0]['actual_fuel_mean']);
        config(['gameplay.route_evidence' => 'measured']);
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Measured fuel evidence');
        app(FuelPlanner::class)->leg($this->agent->fresh(), $ship, 'X1-TEST-PORT');
    }

    public static function remoteMiningPolicies(): array
    {
        return [[false, 1], [false, 3], [true, 1], [true, 3]];
    }

    #[DataProvider('remoteMiningPolicies')]
    public function test_remote_buyer_mining_completes_after_quote_ages_during_travel_and_cooldowns(bool $staged, int $extractions): void
    {
        $this->world(function (&$world) {
            $world['ships'][0]['nav']['waypointSymbol'] = 'X1-TEST-PORT';
            $world['cooldown_seconds'] = 80;
            $world['travel_seconds'] = 181;
            $world['goods'] = FakeSpaceTraders::withAllMiningBids($world['goods']);
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $limits = ['reserve' => 1000, 'max_spend' => 1000, 'max_loss' => 1000, 'minutes' => 60, 'cycles' => 1,
            'site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-PORT', 'max_extractions' => $extractions, 'extraction_headroom' => 1, 'site_seconds' => 900,
            'purchase_limit' => 40, 'delivery_limit' => 10];
        config(['gameplay.enabled' => true]);
        $runner = app(AutomationRunner::class);
        if ($staged) {
            $investigator = app(OpportunityInvestigator::class);
            $investigation = $investigator->create($this->agent->fresh(), 'mining', 'X1-TEST');
            for ($step = 0; $step < 20 && $investigation->status === 'investigating'; $step++) {
                $investigation = $investigator->advance($this->agent->fresh(), $investigation);
            }
            $candidate = collect($investigation->candidates)->first(fn ($candidate) => $candidate['classification'] === 'ready' && $candidate['market'] === 'X1-TEST-PORT' && $candidate['site'] === 'X1-TEST-ROCK');
            $this->assertNotNull($candidate);
            $draft = app(AutomationStaging::class)->save($this->agent->fresh(), $investigation, $candidate['id'], $limits);
            $run = $runner->previewDraft($this->agent->fresh(), $draft);
            $this->assertSame(2, $run->plan_version);
        } else {
            $run = $runner->preview($this->agent->fresh(), 'LOCAL-1', $limits);
        }
        $runner->start($this->agent->fresh(), $run);
        for ($step = 0; $step < 35 && $run->fresh()->active_agent_id && $run->fresh()->state->value !== 'blocked'; $step++) {
            $this->advance($run);
            $this->travel(81)->seconds();
        }
        $run->refresh();
        $this->assertSame('completed', $run->state->value, $run->blocked_reason ?? '');
        $this->assertSame($extractions, $run->totals['attempts']);
        $this->assertSame($run->totals['extracted'], $run->totals['sold']);
        $this->assertSame(2, ShipAction::where('command', 'navigate')->where('state', 'confirmed')->count());
    }

    public function test_single_extraction_preview_rejects_headroom_above_the_hold(): void
    {
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('headroom');
        $this->boundedRun('mining', ['market' => 'X1-TEST-ROCK', 'extraction_headroom' => 500]);
    }

    public function test_staged_single_extraction_preview_rejects_headroom_above_the_hold(): void
    {
        app(AgentSynchronizer::class)->sync($this->agent);
        $investigator = app(OpportunityInvestigator::class);
        $investigation = $investigator->create($this->agent->fresh(), 'mining', 'X1-TEST');
        for ($step = 0; $step < 20 && $investigation->status === 'investigating'; $step++) {
            $investigation = $investigator->advance($this->agent->fresh(), $investigation);
        }
        $candidate = collect($investigation->candidates)->firstWhere('classification', 'ready');
        $draft = app(AutomationStaging::class)->save($this->agent->fresh(), $investigation, $candidate['id'], [
            'reserve' => 1000, 'max_spend' => 100, 'max_loss' => 100, 'minutes' => 60, 'cycles' => 1,
            'purchase_limit' => 40, 'delivery_limit' => 10, 'max_extractions' => 1, 'extraction_headroom' => 500, 'site_seconds' => 300]);
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('headroom');
        app(AutomationRunner::class)->previewDraft($this->agent->fresh(), $draft);
    }

    public function test_focused_mining_reads_the_late_buyer_first_and_stages_all_eight_drone_routes(): void
    {
        $focus = $this->pilotWorld();
        $record = $this->createPilotInvestigation($focus);
        $this->assertSame($focus, $record->coverage['focus']);
        $this->assertSame($focus, $record->observations['focus']);
        $record = $this->finishPilotInvestigation($record);

        $this->assertSame('partial', $record->status);
        $this->assertContains('Market observation cap reached; coverage is partial.', $record->coverage['limits_reached']);
        $this->assertArrayNotHasKey('focus_warning', $record->coverage);
        $this->assertCount(89, $record->observations['waypoints']);
        $this->assertCount(62, array_filter($record->observations['waypoints'], fn ($point) => in_array($point['type'], MiningPlanner::SITE_TYPES, true)));
        $markets = array_keys($record->observations['markets']);
        $this->assertSame(['X1-TEST-B7', 'X1-TEST-A25', ...array_map(fn ($n) => sprintf('X1-TEST-A%02d', $n), range(1, 22))], $markets);
        $this->assertCount(24, $this->pilotMarketReads());
        $this->assertSame('/systems/X1-TEST/waypoints/X1-TEST-B7/market', $this->pilotMarketReads()[0]);
        $this->assertNotContains('X1-TEST-B7', array_column(array_column($record->observations['ships'], 'nav'), 'waypointSymbol'));
        $this->assertCount(200, $record->candidates);
        $this->assertSame(['ready'], array_values(array_unique(array_column($record->candidates, 'classification'))));
        $this->assertSame(['X1-TEST-B7'], array_values(array_unique(array_column($record->candidates, 'market'))));
        $this->assertNotContains('X1-TEST-DX5D', array_column($record->candidates, 'site'));

        $expected = [];
        foreach (range(2, 5) as $index) {
            foreach ($focus['sites'] as $site) {
                $expected[] = substr(hash('sha256', json_encode(['mining', 'GIANTSOUP-'.$index, $site, $focus['buyer'], null, false])), 0, 24);
            }
        }
        $top = array_slice($record->candidates, 0, 10);
        $this->assertContains('GIANTSOUP-1', array_column($top, 'ship_symbol'));
        $this->assertCount(10, array_filter($top, fn ($route) => in_array($route['site'], $focus['sites'], true)));
        $this->assertContains($record->candidates[10]['site'], array_diff(array_column($record->observations['waypoints'], 'symbol'), $focus['sites']));
        $this->assertEqualsCanonicalizing($expected, array_values(array_diff(array_column($top, 'id'),
            array_column(array_filter($top, fn ($route) => $route['ship_symbol'] === 'GIANTSOUP-1'), 'id'))));

        $staging = app(AutomationStaging::class);
        foreach ($expected as $id) {
            $draft = $staging->save($this->agent->fresh(), $record, $id, $this->pilotLimits());
            $this->assertSame($id, $draft->candidate['id']);
            $preview = app(AutomationRunner::class)->previewDraft($this->agent->fresh(), $draft);
            $this->assertSame(2, $preview->plan_version);
            $this->assertSame($focus['buyer'], $preview->plan['market']);
            $this->assertSame([], $preview->plan['initial_inventory']);
            $this->assertCount(14, $preview->plan['route']['known_buyers']);
            $this->assertLessThanOrEqual($preview->plan['initial_fuel'], $preview->plan['route']['fuel_estimate']);
            $this->assertLessThanOrEqual($preview->plan['reserve'], $preview->plan['route']['minimum_reserve']);
            $this->assertLessThanOrEqual($preview->plan['max_spend'], $preview->plan['route']['fuel_ceiling']);
        }
        $this->assertDatabaseCount('automation_drafts', 8);
        $this->assertDatabaseCount('ship_actions', 0);

        $observations = $record->observations;
        $observations['market_times'][$focus['buyer']] = now()->subSeconds(181)->toIso8601String();
        $record->update(['observations' => $observations]);
        $this->postJson(route('game.drafts.create', $this->agent), ['investigation_id' => $record->id, 'candidate_id' => $expected[0], 'limits' => $this->pilotLimits()])
            ->assertUnprocessable()->assertJsonPath('message', 'This observed candidate cannot be staged. Complete its prerequisites first.');
    }

    public function test_full_bid_buyer_routes_rank_ahead_of_205_focused_partial_bid_routes(): void
    {
        config(['gameplay.local_engine_enabled' => true, 'gameplay.max_pages' => 12]);
        $focus = ['buyer' => 'X1-TEST-B7', 'sites' => ['X1-TEST-ROCK']];
        $fullBuyer = 'X1-TEST-FULL';
        $this->world(function (&$world) use ($focus, $fullBuyer) {
            [$site, $buyer] = $world['waypoints'];
            $site['traits'] = [];
            $site['x'] = 10;
            $buyer['symbol'] = $focus['buyer'];
            $world['ships'][0]['nav']['waypointSymbol'] = $buyer['symbol'];
            $world['waypoints'] = [$site, $buyer, array_replace($buyer, ['symbol' => $fullBuyer, 'x' => 50, 'y' => 0])];
            foreach (range(1, 204) as $i) {
                $world['waypoints'][] = array_replace($site, ['symbol' => 'X1-TEST-SITE'.$i, 'x' => $i === 1 ? 11 : -10]);
            }
            $allBids = FakeSpaceTraders::withAllMiningBids($world['goods']);
            $world['market_goods'][$fullBuyer] = $allBids;
            $world['market_goods'][$buyer['symbol']] = array_values(array_filter($allBids, fn ($good) => $good['symbol'] !== 'MERITIUM_ORE'));
            $world['priced_markets'] = [$fullBuyer, $buyer['symbol']];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $record = $this->finishPilotInvestigation($this->createPilotInvestigation($focus));

        $this->assertCount(200, $record->candidates);
        $this->assertSame([$focus['buyer'], $fullBuyer, $fullBuyer, ...array_fill(0, 197, $focus['buyer'])], array_column($record->candidates, 'market'));
        $this->assertEqualsCanonicalizing(['X1-TEST-ROCK', 'X1-TEST-SITE1'], array_column(array_slice($record->candidates, 1, 2), 'site'));
        $partial = $record->candidates[0];
        $this->assertSame($focus['sites'][0], $partial['site']);
        $this->assertSame('ready', $partial['classification']);
        $this->assertContains('Departure requires a compatible signed survey. Missing positive bids for MERITIUM_ORE.', $partial['reasons']);

        $onSite = $record->observations;
        $onSite['ships'][0]['nav']['waypointSymbol'] = $partial['site'];
        $first = app(OpportunityEvaluator::class)->evaluate($onSite, 'mining', CarbonImmutable::now())[0];
        $this->assertSame($partial['id'], $first['id']);
        $this->assertArrayNotHasKey('survey_required_for_departure', $first);

        $survey = ['signature' => 'partial-buyer-survey', 'symbol' => $partial['site'], 'deposits' => [['symbol' => 'IRON_ORE']],
            'expiration' => now()->addHour()->toIso8601String(), 'size' => 'SMALL'];
        app(SurveyPool::class)->save($this->agent, $survey, 'LOCAL-2');
        $draft = app(AutomationStaging::class)->save($this->agent->fresh(), $record, $partial['id'], $this->pilotLimits() + ['survey_signature' => $survey['signature']]);
        $preview = app(AutomationRunner::class)->previewDraft($this->agent->fresh(), $draft);
        $this->assertSame($partial['market'], $preview->plan['market']);
        $this->assertSame($survey['signature'], $preview->plan['survey_signature']);
        $this->assertSame(['IRON_ORE'], $preview->plan['route']['known_buyers']);
        $this->assertDatabaseCount('ship_actions', 0);
    }

    public function test_exact_focused_partial_bid_routes_survive_248_full_bid_routes_and_preview_with_surveys(): void
    {
        $focus = $this->pilotWorld();
        $fullBuyer = 'X1-TEST-A25';
        $this->world(function (&$world) use ($focus, $fullBuyer) {
            $world['ships'] = array_slice($world['ships'], 1, 4);
            $world['waypoints'][0]['modifiers'] = [];
            $world['waypoints'] = array_values(array_filter($world['waypoints'], fn ($point) => MiningPlanner::hazard($point) === null
                || in_array($point['symbol'], [$focus['buyer'], $fullBuyer], true)));
            $world['market_goods'][$focus['buyer']] = array_values(array_filter($world['goods'], fn ($good) => $good['symbol'] !== 'MERITIUM_ORE'));
        });
        $record = $this->finishPilotInvestigation($this->createPilotInvestigation($focus));

        $this->assertSame('complete', $record->status);
        $ships = $record->observations['ships'];
        $this->assertCount(4, $ships);
        $this->assertCount(62, array_filter($record->observations['waypoints'], fn ($point) => MiningPlanner::hazard($point) === null));
        $this->assertCount(200, $record->candidates);

        $exact = array_slice($record->candidates, 0, 8);
        $remaining = array_slice($record->candidates, 8);
        $expected = [];
        foreach ($ships as $ship) {
            foreach ($focus['sites'] as $site) {
                $expected[] = [$ship['symbol'], $site];
            }
        }
        $this->assertEqualsCanonicalizing($expected, array_map(fn ($route) => [$route['ship_symbol'], $route['site']], $exact));
        $this->assertSame(array_fill(0, 8, $focus['buyer']), array_column($exact, 'market'));
        $this->assertSame(array_fill(0, 8, true), array_column($exact, 'survey_required_for_departure'));
        $this->assertSame(array_fill(0, 8, false), array_column($exact, 'suggested_trial'));
        $this->assertSame(array_fill(0, 192, $fullBuyer), array_column($remaining, 'market'));
        $this->assertSame([], array_column($remaining, 'survey_required_for_departure'));
        $this->assertTrue($remaining[0]['suggested_trial']);

        $surveys = [];
        foreach ($focus['sites'] as $site) {
            $signature = 'pilot-survey-'.$site;
            app(SurveyPool::class)->save($this->agent, ['signature' => $signature, 'symbol' => $site, 'deposits' => [['symbol' => 'IRON_ORE']],
                'expiration' => now()->addHour()->toIso8601String(), 'size' => 'SMALL'], 'GIANTSOUP-2');
            $surveys[$site] = $signature;
        }
        foreach ($exact as $route) {
            $signature = $surveys[$route['site']];
            $draft = app(AutomationStaging::class)->save($this->agent->fresh(), $record, $route['id'], $this->pilotLimits() + ['survey_signature' => $signature]);
            $preview = app(AutomationRunner::class)->previewDraft($this->agent->fresh(), $draft);
            $this->assertSame(RunState::Preview, $preview->state);
            $this->assertSame($route['id'], $draft->candidate['id']);
            $this->assertSame($signature, $preview->plan['survey_signature']);
            $this->assertSame(['IRON_ORE'], $preview->plan['route']['known_buyers']);
        }
        $this->assertDatabaseCount('automation_drafts', 8);
        $this->assertDatabaseCount('ship_actions', 0);

        $observations = $record->observations;
        $observations['history'] = [];
        foreach ([$focus['buyer'] => 100, $fullBuyer => 50] as $market => $net) {
            $observations['history'][] = [
                'site' => $focus['sites'][0], 'market' => $market, 'at' => now()->toIso8601String(), 'seconds' => 60,
                'equipment' => OpportunityEvaluator::equipment($ships[0]), 'initial_waypoint' => $fullBuyer, 'initial_fuel' => 80,
                'quote_signature' => OpportunityEvaluator::quoteSignature($observations['markets'][$market]),
                'accounting' => ['state' => 'realized_comparable', 'net_after_wear' => $net, 'wear_basis' => 'Observed'],
            ];
        }
        $ranked = app(OpportunityEvaluator::class)->evaluate($observations, 'mining', CarbonImmutable::now());
        $this->assertSame(6000, $ranked[0]['estimated_per_hour']);
        $this->assertFalse($ranked[0]['recommended']);
        $recommended = array_values(array_filter($ranked, fn ($route) => $route['recommended']));
        $this->assertSame([$fullBuyer], array_column($recommended, 'market'));
        $this->assertSame([3000], array_column($recommended, 'estimated_per_hour'));
    }

    public function test_generic_scouting_hints_survive_a_saturated_mining_candidate_cap(): void
    {
        $focus = $this->pilotWorld();
        $record = $this->finishPilotInvestigation($this->createPilotInvestigation($focus));
        $observations = $record->observations;
        unset($observations['focus']);
        $evaluator = app(OpportunityEvaluator::class);
        $now = CarbonImmutable::now();
        $baseline = $evaluator->evaluate($observations, 'mining', $now);
        $this->assertCount(200, $baseline);
        $this->assertSame(['ready'], array_values(array_unique(array_column($baseline, 'classification'))));

        $unpriced = ['X1-TEST-A01', 'X1-TEST-A02', 'X1-TEST-A03', 'X1-TEST-A04'];
        foreach ($unpriced as $market) {
            unset($observations['markets'][$market]['tradeGoods']);
        }
        $pricedOnly = $observations;
        $pricedOnly['markets'] = array_diff_key($observations['markets'], array_flip($unpriced));
        $ranked = $evaluator->evaluate($pricedOnly, 'mining', $now);
        $candidates = $evaluator->evaluate($observations, 'mining', $now);
        $hints = array_column($candidates, 'preparation');
        $this->assertCount(3, $hints);
        $this->assertSame(['observe_market'], array_values(array_unique(array_column($hints, 'kind'))));
        $hintMarkets = array_column($hints, 'market');
        $this->assertCount(3, array_unique($hintMarkets));
        $this->assertSame([], array_diff($hintMarkets, $unpriced));
        $this->assertCount(200, $candidates);
        $this->assertSame(array_column(array_slice($ranked, 0, 197), 'id'),
            array_column(array_filter($candidates, fn ($candidate) => $candidate['classification'] === 'ready'), 'id'));
    }

    public function test_focused_mining_ignores_cached_prices_when_the_buyer_returns_only_a_directory(): void
    {
        $focus = $this->pilotWorld();
        app(AgentSynchronizer::class)->observe($this->agent, 'market', $focus['buyer'], app(SpaceTradersApi::class)->market($this->agent, $focus['buyer']));
        $this->world(fn (&$world) => $world['priced_markets'] = array_values(array_diff($world['priced_markets'], [$focus['buyer']])));

        $record = $this->finishPilotInvestigation($this->createPilotInvestigation($focus));
        $this->assertSame('partial', $record->status);
        $this->assertSame('/systems/X1-TEST/waypoints/X1-TEST-B7/market', $this->pilotMarketReads()[1]);
        $this->assertArrayNotHasKey('tradeGoods', $record->observations['markets'][$focus['buyer']]);
        $this->assertStringContainsString('X1-TEST-B7 has no fresh trade prices', $record->coverage['focus_warning']);
        $this->assertTrue(collect($record->candidates)->contains('classification', 'ready'));
        $this->assertFalse(collect($record->candidates)->where('classification', 'ready')->contains('market', $focus['buyer']));
        $this->assertFalse(collect($record->candidates)->contains(fn ($row) => isset($row['preparation'])));
        $id = substr(hash('sha256', json_encode(['mining', 'GIANTSOUP-2', $focus['sites'][0], $focus['buyer'], null, false])), 0, 24);
        $this->postJson(route('game.drafts.create', $this->agent), ['investigation_id' => $record->id, 'candidate_id' => $id, 'limits' => $this->pilotLimits()])
            ->assertUnprocessable()->assertJsonPath('message', 'This observed candidate cannot be staged. Complete its prerequisites first.');
    }

    public function test_mining_focus_validates_types_scope_and_strategy_against_the_observed_catalogue(): void
    {
        $focus = $this->pilotWorld();
        $other = ['symbol' => 'X1-OTHER-B7', 'systemSymbol' => 'X1-OTHER', 'type' => 'ASTEROID', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]];
        app(AgentSynchronizer::class)->observe($this->agent, 'waypoint', $other['symbol'], $other);
        $url = route('game.investigations.create', $this->agent);
        $base = ['strategy' => 'mining', 'system' => 'X1-TEST'];
        foreach ([
            ['buyer', ['buyer' => $other['symbol']]],
            ['buyer', ['buyer' => $focus['sites'][0]]],
            ['buyer', ['buyer' => 'X1-TEST-UNKNOWN']],
            ['sites.0', ['buyer' => $focus['buyer'], 'sites' => [$other['symbol']]]],
            ['sites.0', ['buyer' => $focus['buyer'], 'sites' => [$focus['buyer']]]],
            ['sites.0', ['buyer' => $focus['buyer'], 'sites' => array_fill(0, 2, $focus['sites'][0])]],
            ['sites', ['buyer' => $focus['buyer'], 'sites' => [...$focus['sites'], 'X1-TEST-B33']]],
            ['buyer', ['strategy' => 'contracts', 'buyer' => null]],
            ['sites', ['strategy' => 'contracts', 'sites' => []]],
        ] as [$field, $invalid]) {
            $this->postJson($url, array_replace($base, $invalid))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('opportunity_investigations', 0);
        $this->postJson($url, $base + ['buyer' => null, 'sites' => null])->assertAccepted()->assertJsonMissingPath('coverage.focus');
        $this->postJson($url, $base + ['sites' => $focus['sites']])->assertUnprocessable()->assertJsonValidationErrors('buyer');
        $this->postJson($url, $base + $focus)->assertAccepted()->assertJsonPath('coverage.focus.sites', $focus['sites']);
    }

    public function test_mining_investigation_enforces_the_request_and_elapsed_time_limits_and_reports_a_missing_buyer(): void
    {
        $focus = $this->pilotWorld();
        $expired = $this->createPilotInvestigation($focus);
        $this->assertSame(45, $expired->coverage['seconds_limit']);
        $this->assertSame(40, $expired->coverage['request_limit']);
        $before = count($this->pilotMarketReads());
        $this->travel(46)->seconds();
        $expired = $this->finishPilotInvestigation($expired);
        $this->assertSame('partial', $expired->status);
        $this->assertSame(0, $expired->coverage['requests']);
        $this->assertSame($before, count($this->pilotMarketReads()));
        $this->assertContains('Investigation request or elapsed-time limit reached.', $expired->coverage['limits_reached']);
        $this->assertStringContainsString('X1-TEST-B7 was not observed', $expired->coverage['focus_warning']);

        $this->world(fn (&$world) => $world['contracts'] = array_map(fn ($index) => FakeSpaceTraders::contract('contract-'.$index), range(1, 161)));
        $record = $this->finishPilotInvestigation($this->createPilotInvestigation($focus));
        $this->assertSame('partial', $record->status);
        $this->assertSame(40, $record->coverage['requests']);
        $this->assertSame(23, count($this->pilotMarketReads()));
        $this->assertSame($focus['buyer'], array_key_first($record->observations['markets']));
        $this->assertContains('Investigation request or elapsed-time limit reached.', $record->coverage['limits_reached']);
    }

    public function test_campaign_catalogue_orders_stationary_markets_from_later_pages_and_contracts_remain_compatible(): void
    {
        $focus = $this->pilotWorld();
        $investigator = app(OpportunityInvestigator::class);
        $record = $investigator->create($this->agent->fresh(), 'mining', 'X1-TEST');
        for ($step = 0; $step < 40 && $record->status === 'investigating'; $step++) {
            $record = $investigator->advance($this->agent->fresh(), $record, campaign: true);
        }
        $this->assertSame('partial', $record->status);
        $this->assertSame('X1-TEST-A25', array_key_first($record->observations['markets']));
        $this->assertArrayNotHasKey($focus['buyer'], $record->observations['markets']);
        $this->assertArrayNotHasKey('X1-TEST-A26', $record->observations['markets']);
        $this->assertArrayNotHasKey('X1-TEST-A24', $record->observations['markets']);
        $this->assertArrayNotHasKey('focus', $record->observations);

        $contracts = $this->postJson(route('game.investigations.create', $this->agent), ['strategy' => 'contracts', 'system' => 'X1-TEST'])->assertAccepted()->json('id');
        $record = $this->finishPilotInvestigation(OpportunityInvestigation::findOrFail($contracts));
        $this->assertArrayNotHasKey('focus', $record->coverage);
        $this->assertSame('contract-1', $record->observations['contracts'][0]['id']);
        $this->assertTrue(collect($record->candidates)->where('classification', 'ready')->contains('contract_id', 'contract-1'));
    }

    public function test_mining_ship_prerequisites_and_all_pairs_fuel_each_report_one_ship_blocker(): void
    {
        $focus = $this->pilotWorld();
        $record = $this->finishPilotInvestigation($this->createPilotInvestigation($focus));
        $observations = $record->observations;
        $observations['ships'] = array_values(array_filter($observations['ships'], fn ($ship) => (int) substr($ship['symbol'], 10) >= 6));
        $evaluator = app(OpportunityEvaluator::class);
        $rows = collect($evaluator->evaluate($observations, 'mining', CarbonImmutable::now()))->keyBy('ship_symbol');
        $this->assertCount(6, $rows);
        foreach ([
            'GIANTSOUP-6' => 'stationary empty cargo',
            'GIANTSOUP-7' => 'CRUISE',
            'GIANTSOUP-8' => 'stationary empty cargo',
            'GIANTSOUP-9' => 'Missing mining laser',
            'GIANTSOUP-10' => 'Insufficient fuel',
            'GIANTSOUP-11' => 'Insufficient fuel',
        ] as $symbol => $reason) {
            $this->assertNull($rows[$symbol]['site']);
            $this->assertNull($rows[$symbol]['market']);
            $this->assertStringContainsString($reason, $rows[$symbol]['reasons'][0]);
        }
        $this->assertCount(6, $evaluator->evaluate($observations, 'mining', CarbonImmutable::now()));

        $ship = $record->observations['ships'][1];
        $ship['fuel'] = ['current' => 24, 'capacity' => 24];
        $observations['ships'] = [$ship];
        $observations['markets'] = array_intersect_key($observations['markets'], array_flip(['X1-TEST-A25', 'X1-TEST-B7']));
        $observations['waypoints']['X1-TEST-B34']['x'] = -4;
        $observations['waypoints']['X1-TEST-B7']['x'] = 6;
        $observations['waypoints']['X1-TEST-A25']['x'] = 0;
        $rows = collect($evaluator->evaluate($observations, 'mining', CarbonImmutable::now()));
        $this->assertTrue($rows->contains(fn ($row) => $row['site'] === 'X1-TEST-B34' && $row['market'] === 'X1-TEST-A25' && $row['classification'] === 'ready'));
        $this->assertFalse($rows->contains(fn ($row) => $row['site'] === 'X1-TEST-B34' && $row['market'] === 'X1-TEST-B7'));
    }

    public static function stagedMiningPreviewBids(): array
    {
        return [
            'remote unsurveyed requires every yield' => [false, false, 'MERITIUM_ORE'],
            'remote surveyed needs only the signed deposits' => [false, true, null],
            'remote surveyed rejects a freshly zeroed deposit bid' => [false, true, 'COPPER_ORE'],
            'on-site unsurveyed accepts mount-specific bids' => [true, false, null],
        ];
    }

    #[DataProvider('stagedMiningPreviewBids')]
    public function test_staged_mining_preview_validates_bids_for_the_planned_departure(bool $onSite, bool $surveyed, ?string $missing): void
    {
        config(['gameplay.local_engine_enabled' => true]);
        $site = 'X1-TEST-ROCK';
        $buyer = 'X1-TEST-PORT';
        $this->world(function (&$world) use ($onSite, $buyer) {
            if ($onSite) {
                $world['ships'][] = FakeSpaceTraders::probe('LOCAL-2', $buyer);
            } else {
                $world['ships'][0]['nav']['waypointSymbol'] = $buyer;
            }
        });
        app(AgentSynchronizer::class)->sync($this->agent, false);
        $limits = $this->pilotLimits();
        if ($surveyed) {
            $limits['survey_signature'] = 'preview-survey';
            app(SurveyPool::class)->save($this->agent, ['signature' => $limits['survey_signature'], 'symbol' => $site,
                'deposits' => [['symbol' => 'IRON_ORE'], ['symbol' => 'COPPER_ORE'], ['symbol' => 'IRON_ORE']],
                'expiration' => now()->addHour()->toIso8601String(), 'size' => 'SMALL'], 'LOCAL-2');
        }
        $record = $this->finishPilotInvestigation($this->createPilotInvestigation([]));
        $candidate = collect($record->candidates)->first(fn ($route) => $route['classification'] === 'ready' && $route['market'] === $buyer && $route['site'] === $site);
        $this->assertNotNull($candidate);
        $draft = app(AutomationStaging::class)->save($this->agent->fresh(), $record, $candidate['id'], $limits);
        $preview = fn () => app(AutomationRunner::class)->previewDraft($this->agent->fresh(), $draft);

        if ($missing !== null) {
            if ($surveyed) {
                $this->changeDepartureBid($missing, 'zero');
            }
            $this->assertThrows($preview, function (GameException $exception) use ($buyer, $missing) {
                $this->assertStringStartsWith("Mining departure held at $buyer: missing positive bids for ", $exception->getMessage());
                $this->assertStringContainsString($missing, $exception->getMessage());

                return true;
            });
            $this->assertDatabaseCount('automation_runs', 0);
        } else {
            $run = $preview();
            $this->assertSame(RunState::Preview, $run->state);
            $this->assertSame($onSite ? $site : $buyer, $run->plan['initial_waypoint']);
            $this->assertSame(['IRON_ORE', 'COPPER_ORE'], $run->plan['route']['known_buyers']);
            $this->assertSame([], $run->plan['route']['unknown_yields']);
        }
        $this->assertDatabaseCount('ship_actions', 0);
    }

    public function test_mining_route_from_a_201_waypoint_catalogue_stages_and_previews_with_direct_waypoint_reads(): void
    {
        config(['gameplay.local_engine_enabled' => true, 'gameplay.max_pages' => 10]);
        $site = 'X1-TEST-ROCK';
        $buyer = 'X1-TEST-PORT';
        $start = 'X1-TEST-START';
        $this->world(function (&$world) use ($start, $buyer) {
            $world['ships'][0]['nav']['waypointSymbol'] = $start;
            $world['ships'][] = FakeSpaceTraders::probe('LOCAL-2', $buyer);
            $world['goods'] = FakeSpaceTraders::withAllMiningBids($world['goods']);
            $world['waypoints'][] = ['symbol' => $start, 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 1, 'y' => 0, 'traits' => []];
            foreach (range(4, 201) as $index) {
                $world['waypoints'][] = ['symbol' => 'X1-TEST-P'.$index, 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => $index, 'y' => 0, 'traits' => []];
            }
        });
        app(AgentSynchronizer::class)->sync($this->agent, false);
        $record = $this->finishPilotInvestigation($this->createPilotInvestigation([]));
        $this->assertSame('partial', $record->status);
        $this->assertSame(201, $record->coverage['waypoint_total']);
        $this->assertCount(200, $record->observations['waypoints']);
        $this->assertContains('Waypoint catalogue is incomplete within the bounded page limit.', $record->coverage['limits_reached']);
        $candidate = collect($record->candidates)->first(fn ($route) => $route['classification'] === 'ready' && $route['market'] === $buyer);
        $this->assertNotNull($candidate);
        $draft = app(AutomationStaging::class)->save($this->agent->fresh(), $record, $candidate['id'], $this->pilotLimits());

        $before = count(json_decode(file_get_contents($this->worldFile), true)['calls']);
        $run = app(AutomationRunner::class)->previewDraft($this->agent->fresh(), $draft);

        $this->assertSame(RunState::Preview, $run->state);
        $this->assertSame([$start, $site, $buyer], [$run->plan['initial_waypoint'], $run->plan['site'], $run->plan['market']]);
        $calls = array_slice(json_decode(file_get_contents($this->worldFile), true)['calls'], $before);
        $prefix = '/systems/X1-TEST/waypoints';
        $paths = array_values(array_filter(array_column($calls, 'path'), fn ($path) => str_starts_with($path, $prefix)));
        $this->assertSame(["$prefix/$start", "$prefix/$site", "$prefix/$buyer", "$prefix/$buyer/market"], $paths);
        $this->assertDatabaseCount('ship_actions', 0);
    }

    public function test_contract_from_a_201_waypoint_catalogue_stages_and_previews_with_direct_waypoint_reads(): void
    {
        config(['gameplay.local_engine_enabled' => true, 'gameplay.max_pages' => 10]);
        $source = 'X1-TEST-ROCK';
        $destination = 'X1-TEST-PORT';
        $start = 'X1-TEST-START';
        $this->world(function (&$world) use ($start, $source, $destination) {
            $world['ships'][0]['nav']['waypointSymbol'] = $start;
            $world['priced_markets'] = [$source];
            $world['waypoints'][] = ['symbol' => $start, 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 1, 'y' => 0, 'traits' => []];
            foreach (range(4, 201) as $index) {
                $world['waypoints'][] = ['symbol' => 'X1-TEST-P'.$index, 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => $index, 'y' => 0, 'traits' => []];
            }
            $world['contracts'][0]['terms']['deliver'][] = ['tradeSymbol' => 'COPPER_ORE', 'destinationSymbol' => $destination, 'unitsRequired' => 2, 'unitsFulfilled' => 0];
            $world['contracts'][0]['terms']['deliver'][] = ['tradeSymbol' => 'IRON_ORE', 'destinationSymbol' => 'X1-TEST-P4', 'unitsRequired' => 1, 'unitsFulfilled' => 0];
            $world['contracts'][0]['terms']['deliver'][] = ['tradeSymbol' => 'IRON_ORE', 'destinationSymbol' => 'X1-TEST-P201', 'unitsRequired' => 1, 'unitsFulfilled' => 1];
        });
        app(AgentSynchronizer::class)->sync($this->agent, false);
        $response = $this->postJson(route('game.investigations.create', $this->agent), ['strategy' => 'contracts', 'system' => 'X1-TEST'])->assertAccepted();
        $record = $this->finishPilotInvestigation(OpportunityInvestigation::findOrFail($response->json('id')));

        $this->assertSame('partial', $record->status);
        $this->assertSame(201, $record->coverage['waypoint_total']);
        $this->assertCount(200, $record->observations['waypoints']);
        $this->assertArrayNotHasKey('X1-TEST-P201', $record->observations['waypoints']);
        $this->assertContains('Waypoint catalogue is incomplete within the bounded page limit.', $record->coverage['limits_reached']);
        $candidate = collect($record->candidates)->first(fn ($route) => $route['classification'] === 'ready' && $route['market'] === $source);
        $this->assertNotNull($candidate);
        $limits = ['reserve' => 2000, 'max_spend' => 500, 'max_loss' => 500, 'minutes' => 60, 'cycles' => 1, 'delivery_limit' => 10, 'purchase_limit' => 40];
        $draft = app(AutomationStaging::class)->save($this->agent->fresh(), $record, $candidate['id'], $limits);

        $before = count(json_decode(file_get_contents($this->worldFile), true)['calls']);
        $run = app(AutomationRunner::class)->previewDraft($this->agent->fresh(), $draft);

        $this->assertSame(RunState::Preview, $run->state);
        $this->assertSame($candidate['contract_plan'], $run->plan['contract_plan']);
        $this->assertSame(3, $run->plan['contract_plan']['deliveries']);
        $calls = array_slice(json_decode(file_get_contents($this->worldFile), true)['calls'], $before);
        $prefix = '/systems/X1-TEST/waypoints';
        $paths = array_values(array_filter(array_column($calls, 'path'), fn ($path) => str_starts_with($path, $prefix)));
        $this->assertEqualsCanonicalizing(["$prefix/$start", "$prefix/$source", "$prefix/$destination", "$prefix/X1-TEST-P4", "$prefix/$source/market"], $paths);
        $this->assertDatabaseCount('ship_actions', 0);
    }

    public static function missingBidsAtDeparture(): array
    {
        return [
            'first cycle, missing' => [1, 'missing'],
            'first cycle, zero' => [1, 'zero'],
            'second cycle, missing' => [2, 'missing'],
            'second cycle, zero' => [2, 'zero'],
        ];
    }

    #[DataProvider('missingBidsAtDeparture')]
    public function test_staged_unsurveyed_mining_holds_departure_if_one_of_fourteen_required_bids_disappears(int $cycle, string $change): void
    {
        $run = $this->startDepartureTestRun();
        if ($cycle === 2) {
            for ($step = 0; $step < 20 && $run->state !== RunState::Blocked && $run->totals['fuel_units'] === 0; $step++) {
                $this->advance($run);
            }
            $this->assertSame(['IRON_ORE' => 10], $run->totals['sold']);
            $this->assertSame(10, $run->totals['fuel_units']);
            $this->assertSame(0, $run->totals['cycles']);
        }
        $this->changeDepartureBid('MERITIUM_ORE', $change);
        $before = json_decode(file_get_contents($this->worldFile), true);
        $this->assertGreaterThan(0, collect($before['goods'])->firstWhere('symbol', 'IRON_ORE')['sellPrice']);
        $this->assertCount(2 * ($cycle - 1), array_filter($before['writes'], fn ($write) => str_ends_with($write['path'], '/navigate')));
        $this->advance($run);

        $this->assertSame(RunState::Blocked, $run->state);
        $this->assertStringContainsString('missing positive bids for MERITIUM_ORE', $run->blocked_reason);
        $this->assertSame($cycle - 1, $run->totals['cycles']);
        $after = json_decode(file_get_contents($this->worldFile), true);
        $this->assertSame($before['writes'], $after['writes']);
        $this->assertSame('X1-TEST-B7', $after['ships'][0]['nav']['waypointSymbol']);
        $this->assertSame(100, $after['ships'][0]['fuel']['current']);
        $this->assertSame(0, $after['ships'][0]['cargo']['units']);
        $this->assertContains('/systems/X1-TEST/waypoints/X1-TEST-B7/market', array_column(array_slice($after['calls'], count($before['calls'])), 'path'));
    }

    public function test_on_site_mining_rejects_bids_for_ore_outside_the_mount_deposits(): void
    {
        Queue::fake();
        config(['gameplay.enabled' => true]);
        $sync = app(AgentSynchronizer::class);
        $sync->sync($this->agent);
        $runner = app(AutomationRunner::class);
        $site = 'X1-TEST-ROCK';
        $run = $runner->preview($this->agent->fresh(), 'LOCAL-1', $this->pilotLimits() + ['site' => $site, 'market' => $site]);

        $this->world(function (&$world) {
            $world['goods'] = array_values(array_filter(FakeSpaceTraders::withAllMiningBids($world['goods']),
                fn ($good) => in_array($good['symbol'], ['FUEL', 'MERITIUM_ORE'], true)));
        });
        $sync->sync($this->agent);
        $agent = $this->agent->fresh();
        $world = json_decode(file_get_contents($this->worldFile), true);
        $ship = $world['ships'][0];
        $this->assertSame(['IRON_ORE', 'COPPER_ORE'], $ship['mounts'][0]['deposits']);
        $this->assertSame($site, $ship['nav']['waypointSymbol']);
        $reason = "No verified buyer for this laser's possible yields.";
        $now = CarbonImmutable::now();
        $this->assertThrows(fn () => app(MiningPlanner::class)->evaluateRoute($ship, $site, $site, array_column($world['waypoints'], null, 'symbol'),
            ['tradeGoods' => $world['goods']], $now, $agent->credits, $now), GameException::class, $reason);
        $report = app(OpportunityAssessor::class)->report($agent, 'mining', 'LOCAL-1');
        $opportunity = collect($report['opportunities'])->firstWhere('market', $site);
        $this->assertSame($reason, $opportunity['reason']);
        $this->assertArrayNotHasKey('route', $opportunity);
        $this->assertThrows(fn () => $runner->start($agent, $run), GameException::class, $reason);
        $this->assertNull($run->fresh()->active_agent_id);
        $this->assertSame([], json_decode(file_get_contents($this->worldFile), true)['writes']);
    }

    public static function unavailableMeritiumBids(): array
    {
        return ['zero bid' => ['zero'], 'missing bid' => ['missing']];
    }

    #[DataProvider('unavailableMeritiumBids')]
    public function test_unsurveyed_departure_requires_all_yields_even_with_a_two_deposit_mount(string $change): void
    {
        $run = $this->startDepartureTestRun(mountDeposits: ['IRON_ORE', 'COPPER_ORE']);
        $this->changeDepartureBid('MERITIUM_ORE', $change);
        $this->advance($run);

        $this->assertSame(RunState::Blocked, $run->state);
        $this->assertStringContainsString('missing positive bids for MERITIUM_ORE', $run->blocked_reason);
        $this->assertSame(['IRON_ORE', 'COPPER_ORE'], $run->plan['route']['possible_yields']);
        $this->assertSame([], json_decode(file_get_contents($this->worldFile), true)['writes']);
    }

    public static function stationaryMiningStatuses(): array
    {
        return ['docked' => ['DOCKED', ['orbit', 'extract']], 'orbiting' => ['IN_ORBIT', ['extract']]];
    }

    #[DataProvider('stationaryMiningStatuses')]
    public function test_miner_already_at_the_site_extracts_when_the_remote_buyer_is_directory_only(string $status, array $writes): void
    {
        Queue::fake();
        $this->world(function (&$world) use ($status) {
            $world['ships'][0]['nav']['status'] = $status;
            $world['ships'][] = FakeSpaceTraders::probe('LOCAL-2', 'X1-TEST-PORT');
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $run = $this->boundedRun('mining');
        $this->world(fn (&$world) => $world['ships'][1]['nav']['waypointSymbol'] = 'X1-TEST-ROCK');
        $this->assertArrayNotHasKey('tradeGoods', app(SpaceTradersApi::class)->market($this->agent, 'X1-TEST-PORT', fresh: true));

        for ($i = 0; $i < count($writes); $i++) {
            $this->advance($run);
        }

        $this->assertSame(1, $run->totals['attempts'], $run->blocked_reason ?? '');
        $world = json_decode(file_get_contents($this->worldFile), true);
        $this->assertSame($writes, array_map(fn ($write) => basename($write['path']), $world['writes']));
    }

    public static function surveyedDepartureBids(): array
    {
        return ['all survey bids positive' => [null], 'deposit bid zero' => ['zero'], 'deposit bid missing' => ['missing']];
    }

    #[DataProvider('surveyedDepartureBids')]
    public function test_surveyed_departure_requires_every_signed_deposit_bid(?string $change): void
    {
        $signed = ['signature' => 'departure-survey', 'symbol' => 'X1-TEST-ROCK',
            'deposits' => [['symbol' => 'IRON_ORE'], ['symbol' => 'COPPER_ORE'], ['symbol' => 'IRON_ORE']],
            'expiration' => now()->addHour()->toIso8601String(), 'size' => 'SMALL'];
        $run = $this->startDepartureTestRun($signed);
        $this->assertSame(['IRON_ORE', 'COPPER_ORE'], $run->plan['route']['possible_yields']);
        if ($change !== null) {
            $this->changeDepartureBid('COPPER_ORE', $change);
            $this->advance($run);

            $this->assertSame(RunState::Blocked, $run->state);
            $this->assertStringContainsString('missing positive bids for COPPER_ORE', $run->blocked_reason);
            $this->assertSame([], json_decode(file_get_contents($this->worldFile), true)['writes']);

            return;
        }
        for ($step = 0; $step < 20 && $run->active_agent_id && $run->state !== RunState::Blocked; $step++) {
            $this->advance($run);
        }
        $this->assertSame(RunState::Completed, $run->state, $run->blocked_reason ?? '');
        $this->assertCount(1, ShipAction::where('automation_run_id', $run->id)->where('command', 'mine_survey')->get());
    }

    public function test_queued_mining_departure_is_rechecked_immediately_before_the_write(): void
    {
        $run = $this->startDepartureTestRun();
        $this->advance($run);
        app(AutomationRunner::class)->advance($run->id);
        $action = ShipAction::where('state', 'pending')->sole();
        $this->assertSame('navigate', $action->command);
        $this->changeDepartureBid('MERITIUM_ORE', 'zero');

        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame(ActionState::Rejected, $action->fresh()->state);
        $this->assertStringContainsString('missing positive bids for MERITIUM_ORE', $run->fresh()->blocked_reason);
        $world = json_decode(file_get_contents($this->worldFile), true);
        $this->assertSame(['orbit'], array_map(fn ($write) => basename($write['path']), $world['writes']));
    }

    private function startDepartureTestRun(?array $survey = null, ?array $mountDeposits = null): AutomationRun
    {
        Carbon::setTestNow(now()->startOfSecond());
        config(['gameplay.local_engine_enabled' => true, 'gameplay.enabled' => true]);
        EngineFixture::healthy();
        Queue::fake();
        $this->world(function (&$world) use ($survey, $mountDeposits) {
            $world['ships'][0]['nav']['waypointSymbol'] = 'X1-TEST-B7';
            $world['waypoints'][1]['symbol'] = 'X1-TEST-B7';
            $world['yields'] = [['symbol' => 'IRON_ORE', 'units' => 10]];
            $world['ships'][0]['mounts'][0]['deposits'] = $mountDeposits;
            if ($survey === null) {
                $world['goods'] = FakeSpaceTraders::withAllMiningBids($world['goods']);
            }
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        if ($survey !== null) {
            app(SurveyPool::class)->save($this->agent, $survey, 'LOCAL-2');
        }
        $focus = ['buyer' => 'X1-TEST-B7', 'sites' => ['X1-TEST-ROCK']];
        $record = $this->finishPilotInvestigation($this->createPilotInvestigation($focus));
        $candidate = $record->candidates[0];
        $this->assertSame($focus['buyer'], $candidate['market']);
        $limits = array_replace($this->pilotLimits(), $survey === null ? ['cycles' => 2] : ['survey_signature' => $survey['signature']]);
        $draft = app(AutomationStaging::class)->save($this->agent->fresh(), $record, $candidate['id'], $limits);
        $runner = app(AutomationRunner::class);
        $run = $runner->previewDraft($this->agent->fresh(), $draft);

        return $runner->start($this->agent->fresh(), $run);
    }

    private function changeDepartureBid(string $symbol, string $change): void
    {
        $this->world(function (&$world) use ($symbol, $change) {
            if ($change === 'missing') {
                $world['goods'] = array_values(array_filter($world['goods'], fn ($good) => $good['symbol'] !== $symbol));

                return;
            }
            $index = array_search($symbol, array_column($world['goods'], 'symbol'), true);
            $this->assertIsInt($index);
            $world['goods'][$index]['sellPrice'] = 0;
        });
    }

    private function pilotWorld(): array
    {
        Carbon::setTestNow(now()->startOfSecond());
        config(['gameplay.local_engine_enabled' => true]);
        $this->world(function (&$world) {
            $waypoint = fn ($suffix, $type, $x = 0, $traits = [], $modifiers = []) => [
                'symbol' => 'X1-TEST-'.$suffix, 'systemSymbol' => 'X1-TEST', 'type' => $type, 'x' => $x, 'y' => 0, 'traits' => $traits, 'modifiers' => $modifiers,
            ];
            $market = fn ($suffix, $x = 0) => $waypoint($suffix, 'PLANET', $x, [['symbol' => 'MARKETPLACE']]);
            $asteroids = [
                $waypoint('DX5D', 'ASTEROID', 0, [], [['symbol' => 'STRIPPED']]),
                $waypoint('B34', 'ASTEROID', 4),
                $waypoint('B8', 'ENGINEERED_ASTEROID', 5, [], [['symbol' => 'EXPLOSIVE_GASES']]),
                $waypoint('B33', 'ASTEROID_FIELD', 8, [], [['symbol' => 'EXPLOSIVE_GASES']]),
            ];
            $world['symbol'] = 'GIANTSOUP';
            $world['waypoints'] = [
                ...$asteroids,
                ...array_map(fn ($n) => $waypoint('ROCK'.$n, 'ASTEROID_FIELD', 6 + $n % 12), range(1, 58)),
                ...array_map(fn ($n) => $market(sprintf('A%02d', $n), $n === 25 ? 0 : $n % 6), range(1, 26)),
                $market('B7', 3),
            ];
            $world['priced_markets'] = array_column(array_slice($world['waypoints'], 62), 'symbol');
            $world['ships'] = array_map(fn ($n) => FakeSpaceTraders::ship('GIANTSOUP-'.$n, 'X1-TEST-A25'), range(1, 11));
            $world['ships'][0]['frame']['symbol'] = 'FRAME_FRIGATE';
            $world['ships'][0]['nav']['stationed_waypoint'] = 'X1-TEST-A24';
            foreach (range(1, 4) as $index) {
                $world['ships'][$index]['frame']['symbol'] = 'FRAME_DRONE';
                $world['ships'][$index]['frame']['fuelCapacity'] = 80;
                $world['ships'][$index]['fuel'] = ['current' => 80, 'capacity' => 80];
            }
            $world['ships'][5]['nav']['waypointSymbol'] = 'X1-TEST-A26';
            $world['ships'][5]['nav']['status'] = 'IN_TRANSIT';
            $world['ships'][5]['nav']['route']['arrival'] = now()->addHour()->toIso8601String();
            $world['ships'][5]['nav']['stationed_waypoint'] = 'X1-TEST-A26';
            $world['ships'][6]['nav']['flightMode'] = 'DRIFT';
            $world['ships'][7]['cargo']['units'] = 1;
            $world['ships'][7]['cargo']['inventory'] = [['symbol' => 'IRON_ORE', 'units' => 1]];
            $world['ships'][8] = FakeSpaceTraders::probe('GIANTSOUP-9', 'X1-TEST-A25');
            $world['ships'][9]['fuel'] = ['current' => 2, 'capacity' => 2];
            $world['ships'][10]['fuel']['current'] = 0;
            $world['contracts'][0]['terms']['deliver'][0]['destinationSymbol'] = 'X1-TEST-A01';
            foreach ($world['ships'] as &$ship) {
                if ($ship['mounts']) {
                    unset($ship['mounts'][0]['deposits']);
                }
            }
            unset($ship);
            $world['goods'] = FakeSpaceTraders::withAllMiningBids($world['goods']);
        });
        $this->agent->update(['name' => 'GIANTSOUP', 'canonical_identity' => AgentSynchronizer::identity($this->agent->reset_id, 'GIANTSOUP')]);
        $sync = app(AgentSynchronizer::class);
        $sync->sync($this->agent, false);
        $world = json_decode(file_get_contents($this->worldFile), true);
        foreach ($world['waypoints'] as $point) {
            $sync->observe($this->agent, 'waypoint', $point['symbol'], $point);
        }

        return ['buyer' => 'X1-TEST-B7', 'sites' => ['X1-TEST-B34', 'X1-TEST-B8']];
    }

    private function createPilotInvestigation(array $focus): OpportunityInvestigation
    {
        $response = $this->postJson(route('game.investigations.create', $this->agent), ['strategy' => 'mining', 'system' => 'X1-TEST'] + $focus)->assertAccepted();

        return OpportunityInvestigation::findOrFail($response->json('id'));
    }

    private function finishPilotInvestigation(OpportunityInvestigation $record): OpportunityInvestigation
    {
        for ($step = 0; $step < 40 && $record->status === 'investigating'; $step++) {
            $this->postJson(route('game.investigations.advance', [$this->agent, $record]))->assertAccepted();
            $record->refresh();
        }
        $this->assertNotSame('investigating', $record->status);

        return $record;
    }

    private function pilotMarketReads(): array
    {
        $world = json_decode(file_get_contents($this->worldFile), true);

        return array_values(array_filter(array_column($world['calls'], 'path'), fn ($path) => str_ends_with($path, '/market')));
    }

    private function pilotLimits(): array
    {
        return ['reserve' => 2000, 'max_spend' => 100, 'max_loss' => 100, 'minutes' => 15, 'cycles' => 1, 'delivery_limit' => 1, 'purchase_limit' => 1];
    }

    public static function invalidTravelEvidence(): array
    {
        return [[0, null], [5, ['amount' => 0]], [5, ['amount' => 8]], [5, ['bad' => 5]], [-1, null]];
    }

    #[DataProvider('invalidTravelEvidence')]
    public function test_zero_or_inconsistent_travel_evidence_is_excluded_from_measured_routing(int $actual, ?array $reported): void
    {
        app(AgentSynchronizer::class)->sync($this->agent);
        $ship = FakeSpaceTraders::ship();
        app(AgentSynchronizer::class)->observe($this->agent, 'travel', 'LOCAL-1', ['configuration' => FuelPlanner::configuration($ship), 'from' => 'X1-TEST-ROCK', 'to' => 'X1-TEST-PORT',
            'actual_fuel' => $actual, 'reported_consumed' => $reported, 'flight_mode' => 'CRUISE'], 'navigation', 'LOCAL-1');
        $report = app(EvidenceAnalysis::class)->report($this->agent);
        $this->assertSame(1, $report['travel'][0]['invalid_fuel_samples']);
        $this->assertNull($report['travel'][0]['actual_fuel_mean']);
        $this->assertSame(1, ObservationHistory::where('kind', 'travel')->count());
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Measured fuel evidence');
        $points = GameObservation::where('kind', 'waypoint')->get()->keyBy('symbol');
        app(FuelPlanner::class)->units($this->agent, $ship, $points['X1-TEST-ROCK']->data, $points['X1-TEST-PORT']->data, 'measured');
    }
}
