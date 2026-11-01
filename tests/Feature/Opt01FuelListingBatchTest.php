<?php

namespace Tests\Feature;

use App\Gameplay\FuelPlanner;
use App\Gameplay\GameException;
use App\Gameplay\OperationPlanner;
use App\Gameplay\OpportunityAssessor;
use App\Gameplay\SurveyPool;
use App\Models\Agent;
use App\Models\GameObservation;
use App\Models\Ship;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class Opt01FuelListingBatchTest extends TestCase
{
    use RefreshDatabase;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-01T20:00:00Z'));
        config(['gameplay.route_evidence' => 'conservative']);
        $this->agent = User::factory()->create()->agents()->create([
            'name' => 'OPT01', 'token' => 'synthetic-opt01', 'credits' => 100000,
            'account_id' => 'fixture', 'faction' => 'COSMIC', 'reset_id' => 'opt01-world',
        ]);
    }

    public static function marketCounts(): array
    {
        return [[2], [4], [8]];
    }

    #[DataProvider('marketCounts')]
    public function test_multi_market_report_and_route_options_query_scaling(int $count): void
    {
        $ship = $this->fixture($count);
        [$report, $reportQueries] = $this->queries(fn () => app(OpportunityAssessor::class)->report($this->agent));
        [$options, $optionQueries] = $this->queries(fn () => app(FuelPlanner::class)->options($this->agent, $ship, 'X1-TEST-M1'));

        $this->assertCount($count, $report['markets']);
        $this->assertNotEmpty($report['cargo_options'][0]['choices']);
        $this->assertCount($count, collect($report['opportunities'])->where('strategy', 'mining'));
        $this->assertNotEmpty(collect($report['opportunities'])->where('strategy', 'trade')->first()['fuel_units']);
        $this->assertNotEmpty($options);
        if ($path = getenv('OPT01_EVIDENCE_PATH')) {
            file_put_contents($path, json_encode([
                'markets' => $count,
                'report_listing_selects' => count($this->listingQueries($reportQueries)),
                'options_listing_selects' => count($this->listingQueries($optionQueries)),
                'report_total_selects' => count($reportQueries), 'options_total_selects' => count($optionQueries),
                'opportunities' => count($report['opportunities']), 'route_options' => count($options),
                'report_sha256' => hash('sha256', json_encode($report, JSON_THROW_ON_ERROR)),
                'options_sha256' => hash('sha256', json_encode($options, JSON_THROW_ON_ERROR)),
                'quote_at' => $report['markets'][0]['price_at'],
                'listing_query_sample' => $this->listingQueries($reportQueries)[0] ?? null,
                'options_listing_query_sample' => $this->listingQueries($optionQueries)[0] ?? null,
            ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
        }
        $this->assertCount(0, $this->listingQueries($reportQueries));
        $this->assertCount(1, $this->listingQueries($optionQueries));
        $this->assertSame([], array_values(array_filter(array_merge($reportQueries, $optionQueries), fn ($query) => ! str_starts_with($query['query'], 'select '))));
        Http::assertNothingSent();
    }

    public static function sellerCases(): array
    {
        return [
            'exports' => ['exports', true], 'exchange' => ['exchange', true],
            'imports only' => ['imports', false], 'missing directory keeps fallback' => ['missing', true],
            'stale quote with fresh directory' => ['stale', false], 'freshness boundary' => ['boundary', true],
            'invalidated quote' => ['invalidated', false], 'zero price' => ['price', false],
            'zero volume' => ['volume', false], 'missing fuel' => ['no-fuel', false],
            'protected credits' => ['credits', false], 'exact credit bound' => ['exact-credits', true],
            'insufficient fuel' => ['fuel', false], 'exact fuel bound' => ['exact-fuel', true],
            'insufficient capacity' => ['capacity', false], 'different system' => ['system', false],
        ];
    }

    #[DataProvider('sellerCases')]
    public function test_standalone_leg_preserves_seller_freshness_and_bounds(string $case, bool $allowed): void
    {
        $ship = $this->fixture(2);
        $this->listing('X1-TEST-M1')->update(['data' => ['exports' => [], 'exchange' => [], 'imports' => [['symbol' => 'FUEL']]]]);
        $listing = $this->listing('X1-TEST-M0');
        $quote = $this->quote('X1-TEST-M0');
        $reserve = 99990;
        if (in_array($case, ['exports', 'exchange', 'imports'], true)) {
            $listing->update(['data' => array_replace(['exports' => [], 'exchange' => [], 'imports' => []], [$case => [['symbol' => 'FUEL']]])]);
        } elseif ($case === 'missing') {
            $listing->delete();
        } elseif (in_array($case, ['stale', 'boundary'], true)) {
            $quote->update(['observed_at' => now()->subSeconds($case === 'stale' ? 181 : 180)]);
        } elseif ($case === 'invalidated') {
            $quote->update(['data' => $quote->data + ['invalidated_by_action' => 1]]);
        } elseif (in_array($case, ['price', 'volume', 'no-fuel'], true)) {
            $data = $quote->data;
            if ($case === 'no-fuel') {
                array_pop($data['tradeGoods']);
            } else {
                $data['tradeGoods'][2][$case === 'price' ? 'purchasePrice' : 'tradeVolume'] = 0;
            }
            $quote->update(['data' => $data]);
        } elseif ($case === 'credits') {
            $reserve++;
        } elseif (in_array($case, ['fuel', 'exact-fuel', 'capacity'], true)) {
            $ship['fuel'][$case === 'capacity' ? 'capacity' : 'current'] = $case === 'exact-fuel' ? 8 : 7;
        } elseif ($case === 'system') {
            $point = GameObservation::where('kind', 'waypoint')->where('symbol', 'X1-TEST-M0')->sole();
            $point->update(['data' => array_replace($point->data, ['systemSymbol' => 'X2-OTHER'])]);
        }
        $before = GameObservation::orderBy('id')->get()->toJson();
        try {
            $route = app(FuelPlanner::class)->leg($this->agent, $ship, 'X1-TEST-M1', $reserve);
            $this->assertTrue($allowed, $case.' unexpectedly allowed travel');
            $this->assertSame('X1-TEST-M0', $route['continuation']);
            $this->assertSame(8, $route['required_fuel']);
            $this->assertSame(10, $route['refuel_ceiling']);
            $this->assertSame($quote->fresh()->observed_at->toIso8601String(), $route['fuel_price_at']);
        } catch (GameException $e) {
            $this->assertFalse($allowed, $e->getMessage());
        }
        $this->assertSame($before, GameObservation::orderBy('id')->get()->toJson());
        Http::assertNothingSent();
    }

    public function test_same_planner_and_assessor_reload_listing_changes_and_quote_age(): void
    {
        $ship = $this->fixture(2);
        $planner = app(FuelPlanner::class);
        $assessor = app(OpportunityAssessor::class);
        $this->assertNotEmpty($planner->options($this->agent, $ship, 'X1-TEST-M1'));
        $this->assertGreaterThan(1, count($assessor->report($this->agent)['cargo_options'][0]['choices']));
        GameObservation::where('kind', 'market_listing')->update(['data' => json_encode(['imports' => [['symbol' => 'FUEL']], 'exports' => [], 'exchange' => []])]);
        $this->assertSame([], $planner->options($this->agent, $ship, 'X1-TEST-M1'));
        $this->assertCount(1, $assessor->report($this->agent)['cargo_options'][0]['choices']);
        GameObservation::where('kind', 'market_listing')->delete();
        $this->assertNotEmpty($planner->options($this->agent, $ship, 'X1-TEST-M1'));
        $this->travel(161)->seconds();
        $this->assertSame([], $planner->options($this->agent, $ship, 'X1-TEST-M1'));
    }

    public function test_reused_batch_keeps_current_affordability_and_quote_expiry_checks(): void
    {
        $ship = $this->fixture(2);
        $planner = app(FuelPlanner::class);
        $listings = $planner->listings($this->agent);
        $route = $planner->leg($this->agent, $ship, 'X1-TEST-M1', listings: $listings);
        $this->assertSame('2026-11-01T19:59:40+00:00', $route['fuel_price_at']);
        $funded = clone $this->agent;
        $funded->credits = 9;
        try {
            $planner->leg($funded, $ship, 'X1-TEST-M1', listings: $listings);
            $this->fail('Reusing listings ignored the remaining refuel budget.');
        } catch (GameException $e) {
            $this->assertStringContainsString('affordable fuel seller', $e->getMessage());
        }
        $this->travel(161)->seconds();
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('affordable fuel seller');
        $planner->leg($this->agent, $ship, 'X1-TEST-M1', listings: $listings);
    }

    public function test_agent_and_reset_scope_exclude_conflicting_directories(): void
    {
        $ship = $this->fixture(2);
        $other = User::factory()->create()->agents()->create(['name' => 'OTHER', 'account_id' => 'fixture',
            'token' => 'synthetic-other', 'faction' => 'COSMIC', 'credits' => 100000, 'reset_id' => $this->agent->reset_id]);
        foreach (GameObservation::where('kind', 'market_listing')->get() as $listing) {
            $copy = $listing->replicate();
            $copy->agent_id = $other->id;
            $copy->data = ['imports' => [['symbol' => 'FUEL']], 'exports' => [], 'exchange' => []];
            $copy->save();
            $listing->update(['reset_id' => 'old-reset', 'data' => $copy->data]);
        }
        $planner = app(FuelPlanner::class);
        $assessor = app(OpportunityAssessor::class);
        // Neither foreign nor old-reset import-only directories may deny the missing-directory fallback.
        $this->assertNotEmpty($planner->options($this->agent, $ship, 'X1-TEST-M1'));
        $report = $assessor->report($this->agent);
        $this->assertSame(['directory_unknown'], $report['markets']->pluck('state')->unique()->values()->all());
        $this->assertNotEmpty($report['observation_tasks'][0]['route_options']);
        GameObservation::where('agent_id', $this->agent->id)->update(['reset_id' => 'next-reset']);
        $this->agent->reset_id = 'next-reset';
        $this->assertSame([], $planner->options($this->agent, $ship, 'X1-TEST-M1'));
        $this->assertCount(1, $assessor->report($this->agent)['cargo_options'][0]['choices']);
        $this->assertSame([], $planner->options($other, $ship, 'X1-TEST-M1'));
    }

    public function test_empty_listing_scope_is_reused_without_fallback_queries(): void
    {
        $ship = $this->fixture(4);
        GameObservation::where('kind', 'market_listing')->delete();
        [$report, $queries] = $this->queries(fn () => app(OpportunityAssessor::class)->report($this->agent));
        $this->assertCount(0, $this->listingQueries($queries));
        $this->assertNotEmpty($report['observation_tasks'][0]['route_options']);
        [$options, $queries] = $this->queries(fn () => app(FuelPlanner::class)->options($this->agent, $ship, 'X1-TEST-M1'));
        $this->assertCount(1, $this->listingQueries($queries));
        $this->assertNotEmpty($options);
    }

    public function test_operation_and_survey_planning_load_listings_once(): void
    {
        $ship = $this->fixture(4);
        [$plan, $queries] = $this->queries(fn () => app(OperationPlanner::class)->preview($this->agent, $ship,
            ['strategy' => 'scout', 'destination' => 'X1-TEST-M1', 'reserve' => 99900, 'max_fuel' => 10, 'max_spend' => 10, 'max_loss' => 10]));
        $this->assertSame('X1-TEST-M0', $plan['route']['return']['continuation']);
        $this->assertCount(1, $this->listingQueries($queries));
        [$survey, $queries] = $this->queries(fn () => app(SurveyPool::class)->assess($this->agent,
            ['deposits' => [['symbol' => 'IRON_ORE'], ['symbol' => 'COPPER_ORE']], 'expiration' => now()->addHour()->toIso8601String(), 'size' => 'SMALL'], $ship));
        $this->assertTrue($survey['fully_priced']);
        $this->assertCount(1, $this->listingQueries($queries));
    }

    private function listing(string $symbol): GameObservation
    {
        return GameObservation::where('agent_id', $this->agent->id)->where('kind', 'market_listing')->where('symbol', $symbol)->sole();
    }

    private function quote(string $symbol): GameObservation
    {
        return GameObservation::where('agent_id', $this->agent->id)->where('kind', 'market')->where('symbol', $symbol)->sole();
    }

    private function fixture(int $markets): array
    {
        $ship = FakeSpaceTraders::ship('OPT01-1', 'X1-TEST-M0');
        $ship['cargo']['units'] = 1;
        $ship['cargo']['inventory'] = [['symbol' => 'IRON_ORE', 'units' => 1]];
        Ship::create(['agent_id' => $this->agent->id, 'symbol' => $ship['symbol'], 'snapshot' => $ship, 'observed_at' => now()]);
        $goods = FakeSpaceTraders::world()['goods'];
        for ($i = 0; $i < $markets; $i++) {
            $symbol = 'X1-TEST-M'.$i;
            $this->observation('waypoint', $symbol, ['symbol' => $symbol, 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET',
                'x' => $i, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE'], ['symbol' => 'UNCHARTED']]]);
            $this->observation('market_listing', $symbol, ['imports' => [], 'exports' => [['symbol' => 'IRON_ORE'], ['symbol' => 'COPPER_ORE'], ['symbol' => 'FUEL']], 'exchange' => []]);
            $this->observation('market', $symbol, ['tradeGoods' => $goods]);
        }
        $this->observation('waypoint', 'X1-TEST-SITE', ['symbol' => 'X1-TEST-SITE', 'systemSymbol' => 'X1-TEST',
            'type' => 'ASTEROID_FIELD', 'x' => 0, 'y' => 1, 'traits' => []]);

        return $ship;
    }

    private function observation(string $kind, string $symbol, array $data): GameObservation
    {
        return GameObservation::create(['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id,
            'kind' => $kind, 'symbol' => $symbol, 'data' => $data, 'source' => 'opt01-fixture',
            'observed_at' => $kind === 'market' ? now()->subSeconds(20) : now()]);
    }

    private function queries(callable $work): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $result = $work();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        return [$result, $queries];
    }

    private function listingQueries(array $queries): array
    {
        return array_values(array_filter($queries, fn ($query) => in_array('market_listing', $query['bindings'], true)));
    }
}
