<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\CampaignTrade;
use App\Gameplay\Engine\EngineHost;
use App\Gameplay\FuelPlanner;
use App\Gameplay\GameException;
use App\Gameplay\OperationPlanner;
use App\Gameplay\ShipCommandExecutor;
use App\Gameplay\TradeFuelCost;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\LocalEngine;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\EngineFixture;
use Tests\Support\FakeEngineHost;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class TradeFuelEconomicsTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    private Agent $agent;

    private CampaignGrant $grant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Http::preventStrayRequests();
        config(['gameplay.enabled' => true, 'gameplay.local_engine_enabled' => true]);
        $this->app->instance(EngineHost::class, new FakeEngineHost);
        $world = FakeSpaceTraders::world();
        $world['next_reset'] = now()->addHours(17)->toIso8601String();
        $world['credits'] = 175000;
        $world['ships'][0]['registration']['role'] = 'COMMAND';
        $world['ships'][0]['fuel'] = ['current' => 400, 'capacity' => 400];
        $world['ships'][0]['frame']['fuelCapacity'] = 400;
        $probe = FakeSpaceTraders::ship('LOCAL-2', 'X1-TEST-PORT');
        $probe['registration']['role'] = 'SATELLITE';
        $probe['frame']['symbol'] = 'FRAME_PROBE';
        $probe['fuel'] = ['current' => 0, 'capacity' => 0];
        $world['ships'][] = $probe;
        $world['waypoints'][1]['x'] = 40;
        $world['waypoints'][1]['y'] = 0;
        $world['goods'] = [['symbol' => 'FUEL', 'tradeVolume' => 40, 'purchasePrice' => 72, 'sellPrice' => 60]];
        $world['market_goods']['X1-TEST-PORT'] = [['symbol' => 'FUEL', 'tradeVolume' => 40, 'purchasePrice' => 92, 'sellPrice' => 78]];
        $world['fuel_consumption'] = 42;
        $this->file = tempnam(sys_get_temp_dir(), 'st-trade-fuel-');
        file_put_contents($this->file, str_replace(['X1-TEST-ROCK', 'X1-TEST-PORT'], ['X1-TEST-A4', 'X1-TEST-H49'], json_encode($world)));
        FakeSpaceTraders::install($this->file);
        $user = User::factory()->create();
        $user->rememberAccountToken('synthetic-account-token');
        $this->agent = $user->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic-only', 'faction' => 'VOID',
            'reset_id' => $world['reset'], 'canonical_identity' => AgentSynchronizer::identity($world['reset'], 'LOCAL')]);
        app(AgentSynchronizer::class)->sync($this->agent);
        Queue::fake();
        EngineFixture::healthy();
        $body = Yaml::parse(str_replace('GIANTSOUP', 'LOCAL', file_get_contents(base_path('campaigns/weekly-default.yaml'))));
        $body['phases'] = array_slice($body['phases'], 1);
        $this->grant = app(CampaignControl::class)->approve(app(CampaignPlaybook::class)->import(Yaml::dump($body, 10)), $user);
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        parent::tearDown();
    }

    private function startTrade(): AutomationRun
    {
        app(CampaignDirector::class)->tick();
        $run = AutomationRun::whereNotNull('active_agent_id')->first();
        $this->assertNotNull($run, 'Director must create and grant-authorize the thin-spread trade.');
        $this->assertSame('trade', $run->strategy);

        return $run;
    }

    private function candidate(?array $ship = null): array
    {
        if (! CampaignEpisode::exists()) {
            app(CampaignDirector::class)->tick();
        }
        $considered = [];
        $ranked = app(CampaignTrade::class)->rank($this->grant, CampaignEpisode::firstOrFail(), $this->agent->fresh(), $ship ?? Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot, $considered);
        $candidate = collect($ranked)->firstWhere('source', 'X1-TEST-A4');
        $this->assertNotNull($candidate, json_encode($considered));

        return $candidate;
    }

    private function advance(AutomationRun $run): void
    {
        $run->update(['next_action_at' => now()]);
        app(AutomationRunner::class)->advance($run->id);
        foreach (ShipAction::where('automation_run_id', $run->id)->where('state', 'pending')->get() as $action) {
            app(ShipCommandExecutor::class)->execute($action->id);
            $this->assertSame('confirmed', $action->fresh()->state->value, $action->fresh()->error ?? 'Command preflight and fake receipt must pass.');
        }
        $this->travel(6)->seconds();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
    }

    public function test_director_authorizes_preflight_executes_and_accounts_for_a_thin_fuel_load(): void
    {
        $run = $this->startTrade();
        $prediction = $run->plan['campaign_trade'];
        $this->assertSame(148, $prediction['predicted_margin']);
        $this->assertSame(56, $prediction['conservative_margin']);
        $this->assertSame(92, $prediction['expected_fuel_cost']);
        $this->assertSame(184, $prediction['cycle_fuel_allowance']);
        $this->assertSame(576, $prediction['fuel_safety_ceiling']);
        $this->assertEquals(56 / ($prediction['seconds'] / 60), $prediction['score']);
        $this->assertSame(60576, $run->plan['max_spend'], 'Director protects full fuel cash even when proof inventory caps at 60,000.');
        $this->assertSame(576, $run->plan['route']['fuel_ceiling']);
        $this->assertSame(148, $run->plan['route']['estimated_cash_margin']);
        $this->assertSame(56, $run->plan['route']['acceptance_margin']);
        $step = app(OperationPlanner::class)->next($this->agent->fresh(), Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot, $run->plan, $run->totals, now()->toImmutable());
        $this->assertSame(736, $step->arguments['reserve']);
        $this->advance($run);
        $ship = Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
        $this->assertSame(40, $ship['cargo']['units']);
        $this->assertSame(400, $ship['fuel']['current'], 'Buying FUEL cargo must not fill or consume the tank.');
        $buy = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy')->firstOrFail();
        $this->assertSame(2880, $buy->outcome['transaction_total']);
        for ($i = 0; $i < 35 && $run->fresh()->active_agent_id; $i++) {
            $this->advance($run);
        }
        $run->refresh();
        $this->assertSame('completed', $run->state->value, $run->blocked_reason ?? json_encode($run->totals));
        $this->assertSame(2880, $run->totals['purchase_cost']);
        $this->assertSame(3120, $run->totals['sale_proceeds']);
        $this->assertSame(72, $run->totals['fuel_cost']);
        $this->assertSame(84, $run->totals['travel_fuel']);
        $refuel = ShipAction::where('automation_run_id', $run->id)->where('command', 'refuel')->firstOrFail();
        $this->assertSame(576, $refuel->arguments['maxTotal']);
        $commands = ShipAction::where('automation_run_id', $run->id)->where('state', 'confirmed')->orderBy('id')->pluck('command')->all();
        $this->assertSame(['buy', 'orbit', 'navigate', 'dock', 'sell', 'orbit', 'navigate', 'dock', 'refuel'], $commands);
        $ship = Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
        $this->assertSame(0, $ship['cargo']['units']);
        $this->assertSame(400, $ship['fuel']['current']);
        $this->assertSame('X1-TEST-A4', $ship['nav']['waypointSymbol']);
        $result = app(CampaignTrade::class)->results(CampaignEpisode::firstOrFail())[0];
        $this->assertSame(168, $result['net']);
        $this->assertSame(148, $result['predicted_margin']);
        $this->assertEquals(168 / 148, $result['realized_ratio']);
        $this->assertTrue($result['proof']);
        $this->assertTrue($result['proof_eligible']);
        $this->assertFalse($result['forced_sale']);
        $this->assertFalse($result['standalone_recovery']);
        $this->assertFalse($result['losing']);
        $this->assertSame('first_scaled', app(CampaignTrade::class)->mode(CampaignEpisode::firstOrFail(), 'FUEL', 'X1-TEST-H49'));
        $this->assertNull($this->grant->fresh()->paused_at);
    }

    public function test_stale_planning_doubles_only_cash_and_execution_still_requires_fresh_quotes(): void
    {
        $run = $this->startTrade();
        GameObservation::where('kind', 'market')->update(['observed_at' => now()->subMinutes(5)]);
        $candidate = $this->candidate();
        $this->assertSame(148, $candidate['predicted_margin']);
        $this->assertSame(56, $candidate['conservative_margin']);
        $this->assertSame(1152, $candidate['fuel_safety_ceiling']);
        $this->expectException(GameException::class);
        app(OperationPlanner::class)->next($this->agent->fresh(), Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot, $run->plan, $run->totals, now()->toImmutable());
    }

    public function test_non_fuel_buyer_uses_source_ask_without_requiring_a_new_seller(): void
    {
        $run = $this->startTrade();
        $listing = GameObservation::where('kind', 'market_listing')->where('symbol', 'X1-TEST-H49')->firstOrFail();
        $listing->update(['data' => ['imports' => [['symbol' => 'FUEL']], 'exports' => [], 'exchange' => []]]);
        $candidate = $this->candidate();
        $this->assertSame(72, $candidate['expected_fuel_cost']);
        $this->assertSame(96, $candidate['conservative_margin']);
        $plan = $run->plan;
        $plan['campaign_trade'] = array_replace($plan['campaign_trade'], $candidate);
        $preview = app(OperationPlanner::class)->preview($this->agent->fresh(), Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot, $plan);
        $this->assertSame(96, $preview['route']['acceptance_margin']);
        $this->assertSame(168, $preview['route']['estimated_cash_margin']);
        $this->assertSame(576, $preview['route']['fuel_ceiling']);
    }

    public function test_near_full_tank_route_keeps_the_legacy_acceptance_ceiling(): void
    {
        $this->startTrade();
        $point = GameObservation::where('kind', 'waypoint')->where('symbol', 'X1-TEST-H49')->firstOrFail();
        $point->update(['data' => array_replace($point->data, ['x' => 196])]);
        $quote = GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-H49')->firstOrFail();
        $goods = $quote->data;
        $goods['tradeGoods'][0]['sellPrice'] = 90;
        $quote->update(['data' => $goods]);
        $candidate = $this->candidate();
        $this->assertSame(368, $candidate['expected_fuel_cost']);
        $this->assertSame(576, $candidate['cycle_fuel_allowance']);
        $this->assertSame(144, $candidate['conservative_margin']);
    }

    public function test_positioning_from_a_non_fuel_start_is_reported_without_a_new_profit_gate(): void
    {
        $this->startTrade();
        app(AgentSynchronizer::class)->observe($this->agent, 'waypoint', 'X1-TEST-A1', [
            'symbol' => 'X1-TEST-A1', 'systemSymbol' => 'X1-TEST', 'x' => -250, 'y' => 0, 'type' => 'PLANET', 'traits' => []]);
        $ship = Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
        $ship['nav']['waypointSymbol'] = 'X1-TEST-A1';
        $candidate = $this->candidate($ship);
        $this->assertSame(216, $candidate['position_cost']);
        $this->assertSame(-68, $candidate['initial_contribution']);
        $this->assertSame(148, $candidate['predicted_margin']);
        $this->assertSame(56, $candidate['conservative_margin']);
        $this->assertGreaterThan(0, $candidate['position_seconds']);
    }

    public function test_endpoint_prices_require_noninvalidated_seller_evidence_within_six_hours(): void
    {
        $cost = app(TradeFuelCost::class);
        $source = GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-A4')->firstOrFail();
        $buyer = GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-H49')->firstOrFail();
        $this->assertSame(92, $cost->price($this->agent, ['X1-TEST-A4', 'X1-TEST-H49']));
        $buyer->update(['observed_at' => now()->subHours(6)->subSecond()]);
        $this->assertSame(72, $cost->price($this->agent, ['X1-TEST-A4', 'X1-TEST-H49']));
        $source->update(['data' => $source->data + ['invalidated_by_action' => 123]]);
        $this->assertSame(['expected_fuel_cost' => 576, 'cycle_fuel_allowance' => 576, 'fuel_safety_ceiling' => 576], $cost->estimate($this->agent, 84, ['X1-TEST-A4', 'X1-TEST-H49'], 576));
        $buyer->update(['observed_at' => now()->subHours(6)]);
        $this->assertSame(92, $cost->price($this->agent, ['X1-TEST-A4', 'X1-TEST-H49']));
        $this->agent->reset_id = 'different-reset';
        $this->assertNull($cost->price($this->agent, ['X1-TEST-A4', 'X1-TEST-H49']));
    }

    public static function previewFailures(): array
    {
        return ['spending' => [['max_spend' => 3000]], 'fuel bound' => [['max_fuel' => 83]]];
    }

    #[DataProvider('previewFailures')]
    public function test_economic_acceptance_cannot_relax_full_spending_or_fuel_bounds(array $changes): void
    {
        $run = $this->startTrade();
        $this->expectException(GameException::class);
        app(OperationPlanner::class)->preview($this->agent->fresh(), Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot, array_replace($run->plan, $changes));
    }

    public function test_economic_acceptance_cannot_relax_physical_fuel_continuation(): void
    {
        $ship = Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
        $ship['fuel']['current'] = 2;
        $this->expectException(GameException::class);
        app(FuelPlanner::class)->leg($this->agent, $ship, 'X1-TEST-H49');
    }

    public function test_a_181_second_buyer_quote_cannot_authorize_a_purchase(): void
    {
        $run = $this->startTrade();
        GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-H49')->update(['observed_at' => now()->subSeconds(181)]);
        $this->expectException(GameException::class);
        app(OperationPlanner::class)->next($this->agent->fresh(), Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot, $run->plan, $run->totals, now()->toImmutable());
    }

    public function test_fuel_price_changes_require_a_new_forecast_even_when_margin_stays_positive(): void
    {
        $run = $this->startTrade();
        $buyer = GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-H49')->firstOrFail();
        $data = $buyer->data;
        $data['tradeGoods'][0]['purchasePrice'] = 93;
        $buyer->update(['data' => $data]);
        $step = app(OperationPlanner::class)->next($this->agent->fresh(), Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot, $run->plan, $run->totals, now()->toImmutable());
        $this->assertSame('blocked', $step->kind);
        $this->assertStringContainsString('Fuel economics changed', $step->reason);
        $this->assertSame(148, $run->fresh()->plan['campaign_trade']['predicted_margin']);
        $this->assertSame(0, ShipAction::count());
    }

    public function test_preview_refresh_rejects_fuel_economics_that_changed_since_ranking(): void
    {
        $run = $this->startTrade();
        app(AutomationRunner::class)->finishStop($run, 'Finish the unchanged fixture before previewing again.');
        $world = json_decode(file_get_contents($this->file), true);
        $world['market_goods']['X1-TEST-H49'][0]['purchasePrice'] = 110;
        file_put_contents($this->file, json_encode($world));

        try {
            app(AutomationRunner::class)->preview($this->agent->fresh(), 'LOCAL-1', $run->plan);
            $this->fail('A refreshed route must not preserve an outdated campaign forecast.');
        } catch (GameException $exception) {
            $this->assertStringContainsString('Fuel economics changed since ranking', $exception->getMessage());
        }

        $quote = GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-H49')->firstOrFail();
        $this->assertSame(110, $quote->data['tradeGoods'][0]['purchasePrice'], 'Preview must refresh the real fake-HTTP quote before rejecting.');
        $this->assertDatabaseCount('automation_runs', 1);
        $this->assertDatabaseMissing('automation_runs', ['active_agent_id' => $this->agent->id]);
        $this->assertSame([], json_decode(file_get_contents($this->file), true)['writes']);
    }

    public function test_authorization_rejects_inconsistent_campaign_and_route_fuel_economics(): void
    {
        $run = $this->startTrade();
        $plan = $run->plan;
        $plan['route']['expected_fuel_cost'] = 110;
        $plan['route']['cycle_fuel_allowance'] = 220;
        $run->plan = $plan;

        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Fuel economics changed since ranking');
        app(CampaignTrade::class)->authorize($this->agent->fresh(), $run, CampaignEpisode::firstOrFail());
    }

    public function test_command_preflight_rejects_a_deteriorated_buyer_before_dispatch(): void
    {
        $run = $this->startTrade();
        app(AutomationRunner::class)->advance($run->id);
        $buy = ShipAction::where('state', 'pending')->firstOrFail();
        $world = json_decode(file_get_contents($this->file), true);
        $world['market_goods']['X1-TEST-H49'][0]['sellPrice'] = 76;
        file_put_contents($this->file, json_encode($world));
        app(ShipCommandExecutor::class)->execute($buy->id);
        $this->assertSame('rejected', $buy->fresh()->state->value);
        $this->assertSame([], json_decode(file_get_contents($this->file), true)['writes']);
        $this->assertSame(0, Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot['cargo']['units']);
    }

    public function test_legacy_active_plan_missing_economics_keeps_full_charge_and_retains_partial_cargo(): void
    {
        $run = $this->startTrade();
        $plan = $run->plan;
        unset($plan['route']['expected_fuel_cost'], $plan['route']['cycle_fuel_allowance'], $plan['route']['fuel_safety_ceiling']);
        $ship = Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
        $ship['cargo'] = ['capacity' => 40, 'units' => 20, 'inventory' => [['symbol' => 'FUEL', 'units' => 20]]];
        $totals = array_replace($run->totals, ['purchased' => ['FUEL' => 20], 'purchase_cost' => 1440]);
        $step = app(OperationPlanner::class)->next($this->agent->fresh(), $ship, $plan, $totals, now()->toImmutable());
        $this->assertSame('blocked', $step->kind);
        $this->assertStringContainsString('Stop purchases', $step->reason);
        $this->assertSame(0, ShipAction::count());
    }

    public function test_legacy_campaign_preview_needs_replanning_before_grant_authorization(): void
    {
        $run = $this->startTrade();
        $plan = $run->plan;
        unset($plan['campaign_trade']['expected_fuel_cost']);
        $run->plan = $plan;
        $this->expectException(GameException::class);
        app(CampaignTrade::class)->authorize($this->agent->fresh(), $run, CampaignEpisode::firstOrFail());
    }

    public function test_partial_loading_spend_and_forecast_are_checked_before_another_batch(): void
    {
        $run = $this->startTrade();
        $ship = Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
        $ship['cargo'] = ['capacity' => 40, 'units' => 20, 'inventory' => [['symbol' => 'FUEL', 'units' => 20]]];
        $totals = array_replace($run->totals, ['purchased' => ['FUEL' => 20], 'purchase_cost' => 1440]);
        $plan = $run->plan;
        $step = app(OperationPlanner::class)->next($this->agent->fresh(), $ship, $plan, $totals, now()->toImmutable());
        $this->assertSame('buy', $step->command);
        $this->assertSame(20, $step->arguments['units']);
        $plan['max_spend'] = 3300;
        $step = app(OperationPlanner::class)->next($this->agent->fresh(), $ship, $plan, $totals, now()->toImmutable());
        $this->assertSame('blocked', $step->kind, 'Partial buys still reserve the full 576 CR return ceiling.');
    }

    public function test_no_endpoint_prices_preserve_the_full_legacy_charge_in_ranking(): void
    {
        $this->startTrade();
        $sync = app(AgentSynchronizer::class);
        foreach (['X1-TEST-A4' => 25, 'X1-TEST-H49' => 60] as $symbol => $price) {
            $listing = ['imports' => [], 'exports' => [], 'exchange' => [['symbol' => 'IRON_ORE']]];
            $sync->observe($this->agent, 'market_listing', $symbol, $listing);
            $sync->observe($this->agent, 'market', $symbol, $listing + ['tradeGoods' => [
                ['symbol' => 'IRON_ORE', 'purchasePrice' => $price + 5, 'sellPrice' => $price, 'tradeVolume' => 40]]]);
        }
        $sync->observe($this->agent, 'waypoint', 'X1-TEST-FUEL', [
            'symbol' => 'X1-TEST-FUEL', 'systemSymbol' => 'X1-TEST', 'x' => 0, 'y' => 0, 'type' => 'PLANET', 'traits' => [['symbol' => 'MARKETPLACE']]]);
        $sync->observe($this->agent, 'market_listing', 'X1-TEST-FUEL', ['imports' => [], 'exports' => [], 'exchange' => [['symbol' => 'FUEL']]]);
        $sync->observe($this->agent, 'market', 'X1-TEST-FUEL', ['imports' => [], 'exports' => [], 'exchange' => [['symbol' => 'FUEL']], 'tradeGoods' => [
            ['symbol' => 'FUEL', 'purchasePrice' => 72, 'sellPrice' => 60, 'tradeVolume' => 40]]]);
        $candidate = $this->candidate();
        $this->assertSame('IRON_ORE', $candidate['good']);
        $this->assertSame(576, $candidate['expected_fuel_cost']);
        $this->assertSame(576, $candidate['cycle_fuel_allowance']);
        $this->assertSame(624, $candidate['predicted_margin']);
        $this->assertSame(624, $candidate['conservative_margin']);
    }

    public function test_best_available_and_unclaimed_use_the_same_conservative_score_as_ranking(): void
    {
        $this->startTrade();
        $sync = app(AgentSynchronizer::class);
        $quote = GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-H49')->firstOrFail();
        $data = $quote->data;
        $data['tradeGoods'][0]['sellPrice'] = 90;
        $quote->update(['data' => $data]);
        $sync->observe($this->agent, 'waypoint', 'X1-TEST-FAR', [
            'symbol' => 'X1-TEST-FAR', 'systemSymbol' => 'X1-TEST', 'x' => 150, 'y' => 0, 'type' => 'PLANET', 'traits' => [['symbol' => 'MARKETPLACE']]]);
        $listing = ['imports' => [], 'exports' => [], 'exchange' => [['symbol' => 'FUEL']]];
        $sync->observe($this->agent, 'market_listing', 'X1-TEST-FAR', $listing);
        $sync->observe($this->agent, 'market', 'X1-TEST-FAR', $listing + ['tradeGoods' => [
            ['symbol' => 'FUEL', 'purchasePrice' => 110, 'sellPrice' => 100, 'tradeVolume' => 40]]]);
        $considered = [];
        $ranked = app(CampaignTrade::class)->rank($this->grant, CampaignEpisode::firstOrFail(), $this->agent->fresh(), Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot, $considered);
        $this->assertCount(2, $ranked);
        // The farther route earns more per load (544 vs 536), but less per minute.
        $this->assertSame('X1-TEST-H49', $ranked[0]['buyer']);
        $this->assertSame(536, $ranked[0]['conservative_margin']);
        $this->assertSame(544, $ranked[1]['conservative_margin']);
        $this->assertGreaterThan($ranked[1]['score'], $ranked[0]['score']);
        foreach ($ranked as $candidate) {
            $this->assertSame($ranked[0]['key'], $candidate['best_available_key']);
            $this->assertSame($ranked[0]['key'], $candidate['best_unclaimed_key']);
            $this->assertEquals($ranked[0]['score'], $candidate['best_available_score']);
            $this->assertSame('conservative_score', $candidate['selection_basis']);
            $this->assertSame('forecast', $candidate['best_margin_basis']);
        }
    }

    public function test_unrefilled_allowance_keeps_historical_reset_prices_without_a_planning_freshness_gate(): void
    {
        $run = $this->startTrade();
        for ($i = 0; $i < 30; $i++) {
            $this->advance($run);
            if (($run->fresh()->totals['travel_fuel'] ?? 0) === 84) {
                break;
            }
        }
        $this->assertSame(84, $run->fresh()->totals['travel_fuel']);
        app(AutomationRunner::class)->stop($run->fresh());
        $this->advance($run);
        $this->assertSame('stopped', $run->fresh()->state->value);
        $this->assertSame(0, $run->fresh()->totals['fuel_cost']);
        GameObservation::where('kind', 'market')->update(['observed_at' => now()->subHours(7)]);
        $this->agent->update(['reset_id' => 'a-new-reset']);
        $result = app(CampaignTrade::class)->results(CampaignEpisode::firstOrFail())[0];
        $this->assertSame(92, $result['unrefilled_fuel_allowance']);
        $this->assertSame(148, $result['net']);
        $this->assertSame(1, $result['realized_ratio']);
    }

    public static function costCases(): array
    {
        return [[84, 92, 576, 92, 184], [202, 92, 576, 276, 552], [396, 92, 576, 368, 576], [601, 92, 576, 576, 576], [84, null, 576, 576, 576], [0, null, 576, 0, 0]];
    }

    #[DataProvider('costCases')]
    public function test_shared_cost_rounds_aggregate_burn_once_and_bounds_both_charges(int $burn, ?int $price, int $legacy, int $expected, int $allowance): void
    {
        $this->assertSame(['expected_fuel_cost' => $expected, 'cycle_fuel_allowance' => $allowance, 'fuel_safety_ceiling' => $legacy], TradeFuelCost::bounded($burn, $price, $legacy));
    }
}
