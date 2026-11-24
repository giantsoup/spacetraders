<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ActionState;
use App\Enums\RunState;
use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\CampaignTrade;
use App\Gameplay\CorridorBook;
use App\Gameplay\ExtractionCalibrator;
use App\Gameplay\ExtractionSites;
use App\Gameplay\FleetAllocator;
use App\Gameplay\FuelPlanner;
use App\Gameplay\GameException;
use App\Gameplay\HostLock;
use App\Gameplay\HullCatalog;
use App\Gameplay\MiningCluster;
use App\Gameplay\MiningPlanner;
use App\Gameplay\RequestBudget;
use App\Gameplay\RequestDeferred;
use App\Gameplay\ShipCommandExecutor;
use App\Gameplay\SpaceTradersApi;
use App\Gameplay\StateChain;
use App\Gameplay\SurveyBroker;
use App\Gameplay\SurveyPool;
use App\Gameplay\TraderAssigner;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\GameObservation;
use App\Models\LocalEngine;
use App\Models\ObservationHistory;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\FakeSpaceTraders;
use Tests\Support\SaturationFixture;
use Tests\TestCase;

class FleetSaturationCorridorExtractionTest extends TestCase
{
    use RefreshDatabase;
    use SaturationFixture;

    private const string SITE = 'X1-TEST-ROCK';

    private const string BUYER = 'X1-TEST-PORT';

    private const string MINER = 'LOCAL-4';

    private const string TENDER = 'LOCAL-3';

    private const string ORE = 'IRON_ORE';

    private const string MINE = 'SHIP_MINING_DRONE';

    private const string SIPHON = 'SHIP_SIPHON_DRONE';

    private const string SHUTTLE = 'SHIP_LIGHT_SHUTTLE';

    private const string HAULER = 'SHIP_LIGHT_HAULER';

    private array $provider = [];

    protected function tearDown(): void
    {
        $this->tearDownSaturation();
        parent::tearDown();
    }

    public function test_v4_fuel_adjusted_depth_and_firearms_prices_match_sk70(): void
    {
        $book = app(CorridorBook::class);
        $this->assertSame(1, $book->fuelAdjustedDepth(100, 109, 20, 0));
        $this->assertSame(0, $book->fuelAdjustedDepth(100, 109, 20, 180));
        $this->assertSame(7, $book->fuelAdjustedDepth(2346, 4054, 20, 216));
        $this->assertSame(7, $book->fuelAdjustedDepth(3303, 5550, 20, 576));
        $this->assertSame(6, $book->fuelAdjustedDepth(150, 250, 60, 360));
        $this->assertSame(7, $book->fuelAdjustedDepth(188, 322, 60, 144));

        $orders = [];
        for ($index = 0; $index < 4; $index++) {
            $orders[] = $this->order('X1-SK70-E46', 'FIREARMS', 'buy', 20, 0, "A-$index");
            $orders[] = $this->order('X1-SK70-J59', 'FIREARMS', 'sell', 20, 1168, "A-$index");
        }
        $this->assertSame(3, $book->decayOffset($orders, 'X1-SK70-E46', 'FIREARMS', 'buy', 600, 'B', 20));
        $this->assertSame(3, $book->decayOffset($orders, 'X1-SK70-J59', 'FIREARMS', 'sell', 1768, 'B', 20));
        $this->assertSame(0, $book->decayOffset($orders, 'X1-SK70-J59', 'FIREARMS', 'sell', 1167, 'B', 20));
        $this->assertSame(['ask' => 2652, 'bid' => 3586], $book->priceAndValidate($orders, [
            'ask' => $this->order('X1-SK70-E46', 'FIREARMS', 'buy', 20, 600, 'B'),
            'bid' => $this->order('X1-SK70-J59', 'FIREARMS', 'sell', 20, 1768, 'B'),
        ], [
            'X1-SK70-E46|FIREARMS|buy' => ['price' => 2346, 'volume' => 20],
            'X1-SK70-J59|FIREARMS|sell' => ['price' => 4054, 'volume' => 20],
        ]));
    }

    public function test_v4_shared_book_prices_at_each_eta_and_keeps_guarded_future_orders(): void
    {
        $book = app(CorridorBook::class);
        $agent = $this->ledgerAgent();
        $now = now()->timestamp;
        $source = 'X1-SK70-H52';
        $buyer = 'X1-SK70-A3';
        $other = 'X1-SK70-F50';
        $hauler = [
            $this->order($source, 'COPPER', 'buy', 60, $now + 200, 'HAULER-0') + ['guard_price' => 188],
            $this->order($source, 'COPPER', 'buy', 20, $now + 200, 'HAULER-1') + ['guard_price' => 196],
            $this->order($buyer, 'COPPER', 'sell', 60, $now + 400, 'HAULER-0'),
            $this->order($buyer, 'COPPER', 'sell', 20, $now + 400, 'HAULER-1'),
        ];
        $volumes = ["$source|COPPER|buy" => 60, "$buyer|COPPER|sell" => 180, "$other|COPPER|sell" => 60];
        $quotes = array_map(fn (int $volume, int $price) => compact('volume', 'price'), array_values($volumes), [188, 322, 330]);
        $quotes = array_combine(array_keys($volumes), $quotes);

        $this->assertSame(0, $book->decayOffset($hauler, $source, 'COPPER', 'buy', $now + 200, 'A', 60));
        $this->assertSame(2, $book->decayOffset(array_reverse($hauler), $source, 'COPPER', 'buy', $now + 200, 'Z', 60));
        $this->assertSame(2, $book->decayOffset($hauler, $source, 'COPPER', 'buy', $now + 250, 'SHUTTLE', 60));
        $this->assertSame(0, $book->decayOffset($hauler, $buyer, 'COPPER', 'sell', $now + 350, 'SHUTTLE', 180));
        $this->assertSame(1, $book->decayOffset($hauler, $buyer, 'COPPER', 'sell', $now + 410, 'SHUTTLE', 180));
        $this->assertSame(0, $book->decayOffset($hauler, $other, 'COPPER', 'sell', $now + 500, 'SHUTTLE', 60));

        foreach ($hauler as $index => &$event) {
            $event += ['owner_type' => 'run', 'owner_id' => 'hauler', 'batch_index' => $index];
        }
        unset($event);
        $ids = $book->reserveOrders($agent, $hauler);
        $retained = $book->activeEvents($agent);
        $early = [
            $this->order($source, 'COPPER', 'buy', 40, $now + 150, 'SHUTTLE') + ['owner_type' => 'run', 'owner_id' => 'shuttle'],
            $this->order($other, 'COPPER', 'sell', 40, $now + 500, 'SHUTTLE') + ['owner_type' => 'run', 'owner_id' => 'shuttle'],
        ];
        $this->assertTrue($book->fitsWindow($retained, $early, $volumes, 2));
        $this->assertFalse($book->fitsWindow($retained, [array_replace($early[0], ['units' => 41]), $early[1]], $volumes, 2));
        $this->assertNull($book->priceAndValidate($retained, $early, $quotes));
        $this->assertSame($retained, $book->activeEvents($agent));

        $late = [array_replace($early[0], ['landing_at' => $now + 250]), $early[1]];
        $this->assertSame([204, 330], $book->priceAndValidate($retained, $late, $quotes, depthBatches: 2));
        $book->reserveOrders($agent, $late);
        $this->assertEqualsCanonicalizing($ids, array_column(array_filter($book->activeEvents($agent), fn (array $row) => $row['owner_id'] === 'hauler'), 'id'));
        $this->assertSame(6, count($book->activeEvents($agent)));
    }

    public function test_v4_absorbed_receipt_preserves_exact_capacity_and_unresolved_pressure(): void
    {
        $agent = $this->ledgerAgent();
        $book = app(CorridorBook::class);
        $market = 'X1-SK70-H52';
        $now = now()->timestamp;
        $volumes = ["$market|COPPER|buy" => 60];
        $order = $this->order($market, 'COPPER', 'buy', 60, $now, 'A') + ['owner_type' => 'run', 'owner_id' => '1', 'ship_action_id' => 101];
        $book->reserveOrders($agent, [$order]);
        $book->markSending($agent, 101);
        $book->settleReceipt($agent, 101, 50, $now, 5);
        $book->settleReceipt($agent, 101, 49, $now + 1, 5);
        $this->assertSame(0, $book->recordObservation($agent, $market, 'COPPER', 4));
        $this->assertSame(1, $book->decayOffset($book->activeEvents($agent), $market, 'COPPER', 'buy', $now + 1, 'B', 60));
        $this->assertSame(1, $book->recordObservation($agent, $market, 'COPPER', 6));
        $this->assertSame(0, $book->recordObservation($agent, $market, 'COPPER', 5));
        $this->assertDatabaseHas('observation_generations', ['agent_id' => $agent->id, 'generation' => 6]);
        $settled = $book->activeEvents($agent);
        $this->assertSame(50, $settled[0]['units']);
        $this->assertSame($now, $settled[0]['landing_at']);
        $this->assertSame(0, $book->decayOffset($settled, $market, 'COPPER', 'buy', $now + 1, 'B', 60));
        $this->assertTrue($book->fitsWindow($settled, [$this->order($market, 'COPPER', 'buy', 10, $now + 1799, 'B')], $volumes, 1));
        $this->assertFalse($book->fitsWindow($settled, [$this->order($market, 'COPPER', 'buy', 11, $now + 1799, 'B')], $volumes, 1));
        $this->assertTrue($book->fitsWindow($settled, [$this->order($market, 'COPPER', 'buy', 60, $now + 1800, 'B')], $volumes, 1));

        $unresolved = array_replace($order, ['owner_id' => '2', 'ship_action_id' => 102, 'tie_key' => 'C']);
        $book->reserveOrders($agent, [$unresolved]);
        $book->markSending($agent, 102);
        $this->assertSame(0, $book->recordObservation($agent, $market, 'COPPER', 7));
        $this->travel(1801)->seconds();
        $this->assertSame([102], array_column($book->activeEvents($agent), 'ship_action_id'));
        $this->assertSame(1, $book->decayOffset($book->activeEvents($agent), $market, 'COPPER', 'buy', now()->timestamp, 'D', 60));
        $this->assertFalse($book->fitsWindow($book->activeEvents($agent), [$this->order($market, 'COPPER', 'buy', 1, now()->timestamp, 'D')], $volumes, 1));
    }

    public function test_v4_legacy_receipt_is_counted_once_and_newer_market_evidence_only_absorbs_decay(): void
    {
        $agent = $this->ledgerAgent();
        $book = app(CorridorBook::class);
        $market = 'X1-SK70-H52';
        $now = now()->timestamp;
        $action = ShipAction::create([
            'agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'ship_symbol' => 'LOCAL-1',
            'key' => 'legacy-sale', 'origin' => 'automation', 'command' => 'sell', 'state' => ActionState::Confirmed,
            'arguments' => ['symbol' => 'COPPER', 'units' => 52], 'before_state' => ['ship' => ['nav' => ['waypointSymbol' => $market]]],
            'outcome' => ['units' => 52], 'settled_at' => now(), 'expires_at' => now()->addMinute(),
        ]);

        $events = $book->activeEvents($agent);
        $this->assertCount(1, $events);
        $this->assertSame('legacy_receipt', $events[0]['owner_type']);
        $this->assertSame('action:'.$action->id, $events[0]['owner_id']);
        $this->assertSame($action->id, $events[0]['ship_action_id']);
        $this->assertSame(1, $book->decayOffset($events, $market, 'COPPER', 'sell', $now + 2, 'Z', 60));

        $this->travel(1)->seconds();
        $listing = ['symbol' => $market, 'imports' => [], 'exports' => [], 'exchange' => []];
        $sync = app(AgentSynchronizer::class);
        $sync->observe($agent, 'market', $market, $listing);
        $this->assertFalse((bool) $book->activeEvents($agent)[0]['absorbed']);
        $sync->observe($agent, 'market', $market, $listing + ['tradeGoods' => [['symbol' => 'COPPER']]]);
        $events = $book->activeEvents($agent);
        $this->assertCount(1, $events);
        $this->assertTrue((bool) $events[0]['absorbed']);
        $this->assertSame(0, $book->decayOffset($events, $market, 'COPPER', 'sell', $now + 2, 'Z', 60));
        $candidate = $this->order($market, 'COPPER', 'sell', 9, $now + 1799, 'Z');
        $volumes = ["$market|COPPER|sell" => 60];
        $this->assertFalse($book->fitsWindow($events, [$candidate], $volumes, 1));
        $this->assertTrue($book->fitsWindow($events, [array_replace($candidate, ['units' => 8])], $volumes, 1));
        $this->travel(1799)->seconds();
        $this->assertSame([], $book->activeEvents($agent));
        $this->assertDatabaseCount('corridor_book_events', 1);
    }

    public function test_v4_legacy_arm_preview_and_active_commitments_follow_remaining_units_and_release(): void
    {
        $this->setUpSaturation(hubs: 2);
        $ship = FakeSpaceTraders::lightHauler('LOCAL-9', self::SITE);
        $this->editFixture(function (array &$world) use ($ship) {
            $world['ships'][] = $ship;
        });
        app(AgentSynchronizer::class)->sync($this->agent, false, freshFleet: true);
        $episode = $this->seedArmEpisode($this->arm($ship['symbol'], 'H1'));
        $run = AutomationRun::create([
            'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => $ship['symbol'],
            'campaign_episode_id' => $episode->id, 'strategy' => 'trade', 'plan_version' => 3, 'state' => RunState::Preview,
            'expires_at' => now()->addMinute(),
            'plan' => ['site' => self::SITE, 'market' => self::BUYER, 'symbol' => self::ORE, 'units' => 12,
                'route' => ['outbound' => ['estimated_seconds' => 60]]],
            'totals' => ['phase' => 'buy'],
        ]);
        $book = app(CorridorBook::class);
        $events = $book->activeEvents($this->agent);
        $this->assertSame(['buy' => 12, 'sell' => 12], array_column($events, 'units', 'side'));
        $this->assertSame(['run:'.$run->id], array_values(array_unique(array_column($events, 'owner_id'))));
        $this->assertSame(['legacy_run'], array_values(array_unique(array_column($events, 'owner_type'))));

        $run->update(['active_agent_id' => $this->agent->id, 'state' => RunState::Running, 'started_at' => now(),
            'expires_at' => now()->addMinutes(10), 'totals' => ['phase' => 'sell', 'purchased' => [self::ORE => 10], 'sold' => [self::ORE => 4]]]);
        $events = $book->activeEvents($this->agent);
        $this->assertSame(['sell' => 6], array_column($events, 'units', 'side'));
        $at = now()->timestamp + 1;
        $candidate = $this->order(self::BUYER, self::ORE, 'sell', 15, $at, 'Z');
        $volumes = [self::BUYER.'|'.self::ORE.'|sell' => 20];
        $this->assertSame(1, $book->decayOffset($events, self::BUYER, self::ORE, 'sell', $at, 'Z', 20));
        $this->assertFalse($book->fitsWindow($events, [$candidate], $volumes, 1));
        $this->assertSame($events, $book->activeEvents($this->agent));

        $run->update(['active_agent_id' => null, 'state' => RunState::Stopped]);
        $this->assertSame([], $book->activeEvents($this->agent));
        $this->assertDatabaseCount('corridor_book_events', 2);
        $this->assertTrue($book->fitsWindow($book->activeEvents($this->agent), [$candidate], $volumes, 1));
    }

    public function test_v4_greedy_reprices_collisions_and_reports_a_deterministic_residual(): void
    {
        $assigner = app(TraderAssigner::class);
        $book = app(CorridorBook::class);
        $event = fn (int $eta, string $tie) => $this->order('X1-SK70-H52', 'COPPER', 'buy', 40, $eta, $tie);
        $candidate = fn (string $ship, string $route, int $net, array $events = []) => [
            'ship_symbol' => $ship, 'corridor_key' => $route, 'net_credits' => $net, 'seconds' => 3600, 'events' => $events,
        ];
        $quotes = ['X1-SK70-H52|COPPER|buy' => ['price' => 188, 'volume' => 60]];
        $colliding = $candidate('B', 'H52-F50', 1000, [$event(200, 'B')]);
        $this->assertSame(1000, $assigner->assignGreedy(['B'], [$colliding], $book, [], $quotes)['assignments'][0]['score']);
        $candidates = [
            $candidate('A', 'H52-A3', 1200, [$event(100, 'A')]),
            $colliding,
            $candidate('B', 'alternate', 800),
            $candidate('C', 'too-early', 700, [$event(50, 'C')]),
        ];
        $result = $assigner->assignGreedy(['C', 'B', 'A'], $candidates, $book, [], $quotes);
        $this->assertSame($result, $assigner->assignGreedy(['A', 'B', 'C'], array_reverse($candidates), $book, [], $quotes));
        $this->assertSame(2, $result['rounds']);
        $this->assertSame(['H52-A3', 'alternate'], array_column($result['assignments'], 'corridor_key'));
        $this->assertSame([1200, 800], array_column($result['assignments'], 'score'));
        $this->assertSame('C', $result['residual_best']['ship_symbol']);
        $this->assertSame(700, $result['residual_best']['score']);
        $this->assertTrue($result['residual_best']['suppressed']);
        $repriced = $assigner->assignGreedy(['A', 'B'], array_slice($candidates, 0, 2), $book, [], $quotes);
        $this->assertSame(680, $repriced['assignments'][1]['score']);
        $ties = [$candidate('B', 'first', 700), $candidate('A', 'second', 700), $candidate('A', 'first', 700)];
        $tied = $assigner->assignGreedy(['B', 'A'], $ties, $book, [], $quotes);
        $this->assertSame(['A', 'B'], array_column($tied['assignments'], 'ship_symbol'));
        $this->assertSame('first', $tied['assignments'][0]['corridor_key']);
    }

    public function test_v4_j59_canary_owns_one_run_across_service_instances_and_requires_reconciled_retry(): void
    {
        $agent = $this->ledgerAgent();
        $assigner = app(TraderAssigner::class);
        $this->assertSame('waiting_evidence', $assigner->canaryState($agent, 'J59')['state']);
        $this->assertSame('eligible', $assigner->transitionCanary($agent, 'J59', 'eligible', evidence: ['fresh_quotes' => true])['state']);
        $inFlight = $assigner->transitionCanary($agent, 'J59', 'canary_in_flight', 51);
        $this->assertSame($inFlight, (new TraderAssigner)->transitionCanary($agent->fresh(), 'J59', 'canary_in_flight', 51));
        $this->refused(fn () => (new TraderAssigner)->transitionCanary($agent->fresh(), 'J59', 'canary_in_flight', 52), 'conflicts');
        $this->refused(fn () => $assigner->transitionCanary($agent, 'J59', 'passed', 52), 'conflicts');
        $this->assertSame('held', $assigner->transitionCanary($agent, 'J59', 'held', 51)['state']);
        $this->refused(fn () => $assigner->transitionCanary($agent, 'J59', 'eligible'), 'conflicts');
        $this->assertSame('eligible', $assigner->transitionCanary($agent, 'J59', 'eligible', evidence: ['reconciled' => true])['state']);
        $this->assertSame('canary_in_flight', $assigner->transitionCanary($agent, 'J59', 'canary_in_flight', 52)['state']);
        $this->assertSame('passed', $assigner->transitionCanary($agent, 'J59', 'passed', 52)['state']);
        $this->refused(fn () => $assigner->transitionCanary($agent, 'J59', 'canary_in_flight', 53), 'conflicts');
        $this->assertDatabaseCount('corridor_canary_states', 1);
    }

    public function test_v4_working_capital_cash_gate_g8_and_price_ceilings(): void
    {
        $hulls = app(HullCatalog::class);
        $allocator = app(FleetAllocator::class);
        for ($count = 1; $count <= 5; $count++) {
            $fleet = ['FRAME_FRIGATE', ...array_fill(0, $count - 1, self::HAULER)];
            $this->assertSame(max(200000, 150000 * ($count + 1)), $hulls->workingCapital($fleet, self::HAULER));
        }
        $this->assertSame(375000, $hulls->workingCapital(['FRAME_FRIGATE', self::SHUTTLE], self::HAULER));
        $this->assertSame(313853, 88853 + $hulls->reserveParts(['FRAME_FRIGATE'], self::SHUTTLE)['total']);
        $this->assertSame([
            'grant' => 10000, 'working_capital' => 225000, 'commitments' => 5000, 'recon_return' => 2000, 'total' => 242000,
        ], $hulls->reserveParts(['FRAME_FRIGATE'], self::SHUTTLE, 10000, 5000, 2000));
        $this->assertSame(154000, $allocator->slotCapitalRequirement([
            ['pair', 88853, 75000], ['pair', 368977, 150000], ['site', 40147, 0],
        ], 150000));

        $shuttle = $this->offer(self::SHUTTLE, 88853, 53044);
        $context = ['base_capital' => 150000, 'hours_to_reset' => 48, 'horizon_after_delivery_hours' => 47];
        $this->assertNull($allocator->rank([$shuttle], $context + ['cash' => 313852])['selected']);
        $this->assertSame(313853, $allocator->rank([$shuttle], $context + ['cash' => 313853])['selected']['cash_gate']);
        $this->assertSame(330853, $allocator->rank([$shuttle], $context + [
            'cash' => 330853, 'grant_reserve' => 10000, 'commitments' => 5000, 'recon_return' => 2000,
        ])['selected']['cash_gate']);

        $hauler = $this->offer(self::HAULER, 368977, 95000);
        $this->assertFalse($allocator->evaluateCandidate($shuttle, 0, 23.999, 23, 40000)['g8']);
        $this->assertTrue($allocator->evaluateCandidate($shuttle, 0, 24, 23, 40000)['g8']);
        $this->assertFalse($allocator->evaluateCandidate($hauler, 0, 24, 23, 40000)['g8']);
        $this->assertTrue($allocator->evaluateCandidate($hauler, 0, 24, 23, 60000)['g8']);

        $atBand = $this->offer(self::SHUTTLE, 115000, 35000) + ['p_min' => 100000];
        $aboveBand = array_replace($atBand, ['price' => 115001, 'effective_cost' => 115001]);
        $this->assertTrue($allocator->evaluateCandidate($atBand, 0, 48, 47)['pass']);
        $this->assertTrue($allocator->evaluateCandidate($aboveBand, 0, 48, 47)['inflation_overrun']);
        $this->assertFalse($allocator->evaluateCandidate($aboveBand, 0, 48, 47)['pass']);
        $this->assertFalse($allocator->evaluateCandidate($aboveBand + ['approved_ceiling' => 115000], 0, 48, 47)['within_ceilings']);
        $this->assertFalse($allocator->evaluateCandidate(array_replace($aboveBand, ['price' => 150001]), 0, 48, 47)['within_ceilings']);
    }

    #[DataProvider('j59TerminalCases')]
    public function test_v4_j59_passes_only_a_fully_settled_profitable_canary(string $case, string $expected): void
    {
        $episode = $this->extractionWorld();
        $legs = [
            ['source' => 'X1-SK70-E46', 'buyer' => 'X1-SK70-J59', 'good' => 'FIREARMS', 'approvedUnits' => 20, 'proofMode' => 'proof', 'predicted_margin' => 20000],
            ['source' => 'X1-SK70-J59', 'buyer' => 'X1-SK70-H52', 'good' => 'DRUGS', 'approvedUnits' => 18, 'proofMode' => 'proof', 'predicted_margin' => 25000],
            ['source' => 'X1-SK70-H52', 'buyer' => 'X1-SK70-E46', 'good' => 'ALUMINUM', 'approvedUnits' => 60, 'proofMode' => 'proof', 'predicted_margin' => 5000],
        ];
        $run = AutomationRun::create([
            'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'campaign_episode_id' => $episode->id,
            'ship_symbol' => 'LOCAL-1', 'strategy' => 'trade', 'plan_version' => 8, 'state' => RunState::Completed,
            'started_at' => now()->subHour(), 'expires_at' => now()->addHour(),
            'plan' => ['canary_family' => 'J59', 'legs' => $legs],
            'totals' => [
                'leg_purchased' => [20, 18, 60],
                'leg_sold' => $case === 'short sale' ? [20, 17, 60] : [20, 18, 60],
                'leg_wrong_buyer' => [false, $case === 'wrong buyer', false],
                'leg_purchase_cost' => [46920, 59454, 9000],
                'leg_sale_proceeds' => [81080, $case === 'below proof ratio' ? 70000 : 99900, 15000],
                'leg_fuel_cost' => [216, 576, $case === 'fuel erases net' ? 90000 : 360],
                'unsold' => $case === 'retained cargo' ? ['ALUMINUM' => 1] : [],
            ],
        ]);
        if ($case === 'uncertain action') {
            ShipAction::create([
                'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'automation_run_id' => $run->id,
                'ship_symbol' => $run->ship_symbol, 'key' => 'unsettled-j59', 'origin' => 'automation', 'command' => 'sell',
                'state' => ActionState::Uncertain, 'arguments' => ['symbol' => 'DRUGS', 'units' => 18], 'expires_at' => now()->addMinute(),
            ]);
        }

        $assigner = app(TraderAssigner::class);
        $assigner->transitionCanary($this->agent, 'J59', 'eligible');
        $assigner->transitionCanary($this->agent, 'J59', 'canary_in_flight', $run->id);
        $trade = app(CampaignTrade::class);
        $trade->recordResults($episode);
        $state = $assigner->canaryState($this->agent, 'J59');
        $this->assertSame($expected, $state['state']);
        $this->assertSame($run->id, $state['automation_run_id']);
        $this->assertSame($case === 'short sale' ? 2 : 3, $state['evidence']['settled_legs']);
        $trade->recordResults($episode);
        $this->assertSame($state, $assigner->canaryState($this->agent, 'J59'));
    }

    public static function j59TerminalCases(): array
    {
        return [
            'three verified loads' => ['verified', 'passed'],
            'short sale' => ['short sale', 'held'],
            'wrong buyer' => ['wrong buyer', 'held'],
            'low realization' => ['below proof ratio', 'held'],
            'negative net after fuel' => ['fuel erases net', 'held'],
            'retained cargo' => ['retained cargo', 'held'],
            'uncertain action' => ['uncertain action', 'held'],
        ];
    }

    #[DataProvider('yardPriceJumps')]
    public function test_v4_fleet_purchase_refuses_a_live_price_jump_before_transport(int $approved, int $live): void
    {
        $episode = $this->extractionWorld();
        $grant = $episode->grant();
        $purchaser = 'LOCAL-1';
        $ship = $this->savedShip($purchaser);
        $shuttle = FakeSpaceTraders::lightHauler('OFFER-SHUTTLE', self::SITE);
        $shuttle['frame']['symbol'] = 'FRAME_SHUTTLE';
        $shuttle['frame']['fuelCapacity'] = 300;
        $shuttle['modules'][1]['capacity'] = 40;
        $offer = FakeSpaceTraders::shipyardOffer(self::SHUTTLE, $shuttle, $live);
        $this->provider['shipyards'][self::SITE] = ['shipTypes' => [['type' => self::SHUTTLE]], 'ships' => [$offer]];
        $offerHash = ShipCommandExecutor::configurationHash($offer);
        $cutoff = $this->instant()->addMinutes(10);
        $run = AutomationRun::create([
            'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'campaign_episode_id' => $episode->id,
            'ship_symbol' => $purchaser, 'strategy' => 'buy_fleet', 'plan_version' => 8, 'state' => RunState::Running,
            'active_agent_id' => $this->agent->id, 'engine_session_id' => LocalEngine::findOrFail(1)->session_id,
            'started_at' => now(), 'expires_at' => $cutoff,
            'plan' => [
                'strategy' => 'buy_fleet', 'grant_id' => $grant->id, 'revision_hash' => $grant->revision_hash, 'campaign_episode_id' => $episode->id,
                'purchaser' => $purchaser, 'shipyard' => self::SITE, 'ship_type' => self::SHUTTLE, 'hull_class' => 'trader',
                'g2_reservation' => ['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'slot' => 'trader'],
                'allocation_candidate' => ['slot' => 'trader', 'type' => self::SHUTTLE, 'yard' => self::SITE, 'purchaser' => $purchaser],
                'offer_configuration_hash' => $offerHash, 'purchaser_configuration_hash' => ShipCommandExecutor::configurationHash($ship),
                'reserve' => 225000, 'max_price' => $approved, 'max_spend' => $approved, 'max_loss' => $approved,
                'acquisition_cutoff' => $cutoff->toIso8601String(), 'settlement_deadline' => $cutoff->toIso8601String(),
                'initial_waypoint' => self::SITE, 'initial_status' => 'DOCKED', 'initial_fuel' => $ship['fuel']['current'],
                'initial_credits' => $this->agent->credits, 'initial_inventory' => [], 'route' => ['hops' => []],
            ],
            'totals' => ['credit_delta' => 0, 'spending' => 0, 'unsold' => []],
        ]);
        $intent = ShipAction::create([
            'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'automation_run_id' => $run->id,
            'ship_symbol' => $purchaser, 'engine_session_id' => $run->engine_session_id, 'command' => 'buy_ship',
            'key' => 'queued-shuttle', 'origin' => 'automation', 'state' => ActionState::Pending, 'expires_at' => now()->addMinute(),
            'arguments' => ['shipType' => self::SHUTTLE, 'waypointSymbol' => self::SITE, 'maxTotal' => $approved, 'offer_configuration_hash' => $offerHash],
        ]);

        $this->execute($intent);
        $this->assertSame(ActionState::Rejected, $intent->state, $intent->message ?? '');
        $this->assertStringContainsString('approved spending ceiling '.number_format($approved).' CR', $intent->message);
        $this->assertStringContainsString('price '.number_format($live).' CR', $intent->message);
        $this->assertNull($intent->sent_at);
        $this->assertCount(0, $this->writes('/my/ships'));
        $this->assertSame($this->provider['credits'], $this->agent->refresh()->credits);
    }

    public static function yardPriceJumps(): array
    {
        return [
            'first credit above the 15 percent price band' => [115000, 115001],
            'first credit above the hard shuttle cap' => [150000, 150001],
        ];
    }

    public function test_v5_headroom_cooldown_lcb_and_rolling_send_boundary(): void
    {
        $agent = $this->ledgerAgent();
        $sites = app(ExtractionSites::class);
        $load = $sites->cycleModel('mine', 15, 7, 80, 7, 73, 158 / 3, 1, 72);
        $this->assertSame([286, 22, 2, 14], [$load['duration_seconds'], $load['requests'], $load['extractions'], $load['units']]);
        $this->assertEqualsWithDelta(1996 / 3, $load['net_credits'], 0.00001);
        $this->assertSame(6, $sites->maxMiners(80, 19));
        $this->assertSame(8, $sites->maxMiners(80, 20));
        $this->assertSame(5, $sites->maxMiners(50, 3));

        $time = now()->timestamp;
        foreach ([0, 0, 1, 2, 3, 4] as $index => $offset) {
            $actionId = $index + 1;
            $this->assertTrue($sites->admitSend($agent, self::SITE, "MINER-$index", $actionId, $time + $offset)['allowed']);
            $sites->markSendResult($actionId, $index % 2 === 0 ? 'confirmed' : 'uncertain');
        }
        $this->assertSame('site_rate_cap', $sites->admitSend($agent, self::SITE, 'MINER-6', 7, $time + 59)['reason']);
        $this->assertDatabaseCount('extraction_site_sends', 6);
        $this->assertTrue($sites->admitSend($agent, self::SITE, 'MINER-6', 7, $time + 60)['allowed']);
        $this->assertSame('action_already_reserved', $sites->admitSend($agent, self::SITE, 'MINER-6', 7, $time + 60)['reason']);
        $this->assertTrue($sites->admitSend($agent, self::SITE, 'MINER-7', 8, $time + 60)['allowed']);
        $this->assertSame('site_rate_cap', $sites->admitSend($agent, self::SITE, 'MINER-8', 9, $time + 60)['reason']);
    }

    public function test_v5_holds_require_complete_clear_and_stripped_lasts_the_reset(): void
    {
        $agent = $this->ledgerAgent();
        $sites = app(ExtractionSites::class);
        $time = $this->instant();
        $this->assertSame('modifiers_missing', $sites->recordModifiers($agent, self::SITE, [], $time, modifiersPresent: false)['reason']);
        $unstable = $sites->recordModifiers($agent, self::SITE, ['UNSTABLE'], $time->addSecond());
        $this->assertSame($time->addSecond()->addHours(6)->toIso8601String(), $unstable['hold_until']);
        $critical = $sites->recordModifiers($agent, self::SITE, ['CRITICAL_LIMIT'], $time->addHour());
        $this->assertSame('site_hold', $critical['reason']);
        $this->assertSame($time->addHours(7)->toIso8601String(), $critical['hold_until']);
        $this->assertSame('fresh_clear_required', $sites->siteStatus($agent, self::SITE, now: $time->addHours(7))['reason']);
        $this->assertFalse($sites->recordModifiers($agent, self::SITE, [], $time->addHours(7), modifiersPresent: false)['allowed']);
        $this->assertFalse($sites->recordModifiers($agent, self::SITE, [], $time->addHours(7)->addSecond(), 'receipt')['allowed']);
        $clearAt = $time->addHours(7)->addSeconds(2);
        $safe = $sites->recordModifiers($agent, self::SITE, [], $clearAt);
        $this->assertTrue($safe['allowed']);
        $this->assertTrue($safe['reduced_rate']);
        $this->assertSame(3, $sites->rollingCap(reducedRate: $safe['reduced_rate']));
        $this->assertFalse($sites->siteStatus($agent, self::SITE, now: $clearAt->addHours(6))['reduced_rate']);
        $this->assertSame('STRIPPED', $sites->recordModifiers($agent, self::SITE, ['STRIPPED'], $clearAt->addHours(7))['reason']);
        $this->assertSame('STRIPPED', $sites->recordModifiers($agent, self::SITE, [], $clearAt->addDay())['reason']);
        $explosive = $sites->recordModifiers($agent, 'X1-TEST-GAS-ROCK', ['EXPLOSIVE_GASES'], $time);
        $this->assertTrue($explosive['explosive']);
        $ship = ['frame' => ['condition' => .9], 'mounts' => [['condition' => .51]]];
        $this->assertTrue($sites->explosiveConditionCheck($ship, 1));
        $this->assertFalse($sites->explosiveConditionCheck($ship, 2));
    }

    public function test_v6_signed_survey_ranking_preserves_repeats_and_breaks_every_tie(): void
    {
        $this->ledgerAgent();
        $broker = app(SurveyBroker::class);
        $bids = ['IRON_ORE' => 105, 'COPPER_ORE' => 120, 'GOLD_ORE' => 104];
        $repeated = $this->survey('rich', ['COPPER_ORE', 'IRON_ORE', 'COPPER_ORE'], 'SMALL', 120);
        $this->assertSame(115, $broker->evaluateSurvey($repeated, $bids));
        $this->assertSame(138, $broker->evaluateSurvey($repeated, $bids, 1200));
        $surveys = [
            $this->survey('late', ['IRON_ORE'], 'LARGE', 120),
            $this->survey('a', ['IRON_ORE'], 'LARGE', 100),
            $this->survey('medium', ['IRON_ORE'], 'MODERATE', 80),
            $this->survey('under-margin', ['GOLD_ORE'], 'LARGE', 60),
            $this->survey('b', ['IRON_ORE'], 'LARGE', 100),
            $repeated,
        ];
        $ranking = $broker->rankSurveys($surveys, $bids, 100);
        $this->assertSame($ranking, $broker->rankSurveys(array_reverse($surveys), $bids, 100));
        $this->assertSame(['rich', 'a', 'b', 'late', 'medium'], array_column(array_column($ranking, 'survey'), 'signature'));
        $this->assertSame([115, 105, 105, 105, 105], array_column($ranking, 'ev'));
    }

    public function test_v6_soft_lease_expires_at_cooldown_plus_30_and_demand_and_purchase_use_measured_samples(): void
    {
        $agent = $this->ledgerAgent();
        $broker = app(SurveyBroker::class);
        $survey = $this->survey('signed', [self::ORE, self::ORE], 'LARGE', 600);
        app(SurveyPool::class)->save($agent, $survey, 'SURVEYOR');
        $bids = [self::ORE => 120];
        $ready = $this->instant()->addSeconds(80);
        $this->assertSame($survey, $broker->leaseBest($agent, self::SITE, self::MINER, $bids, 100, $ready));
        $this->assertNull($broker->leaseBest($agent, self::SITE, 'LOCAL-5', $bids, 100, $ready));
        $this->travel(109)->seconds();
        $this->assertNull($broker->leaseBest($agent, self::SITE, 'LOCAL-5', $bids, 100, $ready));
        $this->travel(1)->seconds();
        $this->assertSame($survey, $broker->leaseBest($agent, self::SITE, 'LOCAL-5', $bids, 100, $ready));
        $broker->invalidateSignature($agent, 'signed');
        $this->assertNull($broker->leaseBest($agent, self::SITE, self::MINER, $bids, 100, $ready));

        $this->assertSame(['target' => 38, 'shortfall' => 28, 'surveys_needed' => 10, 'writes_needed' => 4], $broker->creationDemand(5, 80, 10, 3, 3));
        $this->assertSame(['target' => 38, 'shortfall' => 0, 'surveys_needed' => 0, 'writes_needed' => 0], $broker->creationDemand(5, 80, 38));
        foreach ([[19, 10, 10], [20, 9, 10], [20, 10, 9]] as $samples) {
            $this->assertSame('insufficient_survey_receipts', $broker->purchaseUplift(6, 140, 57, 120, 52, .5, ...$samples)['reason']);
        }
        $this->assertSame([
            'purchase_allowed' => true, 'reason' => null, 'gross_uplift_per_hour' => 5220.0,
        ], $broker->purchaseUplift(6, 140, 57, 120, 52, .5, 20, 10, 10));
    }

    public function test_v7_canary_cash_precedence_pessimistic_payback_and_fresh_wipe_probe(): void
    {
        $allocator = app(FleetAllocator::class);
        $sites = app(ExtractionSites::class);
        $load = $sites->cycleModel('mine', 15, 3, 120, 7, 73, 158 / 3, 1, 72);
        $this->assertSame([456, 28, 3, 9], [$load['duration_seconds'], $load['requests'], $load['extractions'], $load['units']]);
        $mineRate = $load['net_credits'] * 3600 / $load['duration_seconds'];
        $miner = $this->offer(self::MINE, 40147, $mineRate, $load['requests'] * 3600 / $load['duration_seconds']) + ['canary' => true, 'deploy_hours' => 9543 / 3600];
        $gasLoop = $sites->cycleModel('siphon', 15, 3, 120, 7, 18, 31, 0, 72);
        $gasRefuel = $sites->cycleModel('siphon', 15, 3, 120, 7, 18, 31, 1, 72);
        $gasSeconds = 18 * $gasLoop['duration_seconds'] + $gasRefuel['duration_seconds'];
        $gasRequests = 18 * $gasLoop['requests'] + $gasRefuel['requests'];
        $gasNet = 18 * $gasLoop['net_credits'] + $gasRefuel['net_credits'];
        $this->assertSame([6840, 496, 5229.0], [$gasSeconds, $gasRequests, $gasNet]);
        $siphon = $this->offer(self::SIPHON, 39861, $gasNet * 3600 / $gasSeconds, $gasRequests * 3600 / $gasSeconds) + ['canary' => true];
        $shuttle = $this->offer(self::SHUTTLE, 88853, 53044);
        $probe = ['type' => 'SHIP_PROBE', 'tier' => 1, 'price' => 23021, 'eligible' => true];
        $context = ['phase' => 2, 'base_capital' => 150000, 'hours_to_reset' => 120, 'horizon_after_delivery_hours' => 114, 'saving_rate_per_hour' => 70000];
        $fresh = $context + ['cash' => 245000];
        $this->assertSame('tier_1_probe', $allocator->rank([$miner, $shuttle, $probe], $fresh)['reason']);
        $this->assertNull($allocator->rank([$miner], $fresh)['selected']);
        $this->assertTrue($allocator->rank([$miner, $shuttle], $fresh)['wait_for_trader']);
        $this->assertSame(.98, round((313853 - 245000) / 70000, 2));
        $this->assertSame(1.31, round((313853 - 245000 + 23021) / 70000, 2));

        $postProbe = $context + ['cash' => 1250000];
        $selected = $allocator->rank([$siphon, $miner, $shuttle + ['hold_hours' => 5]], $postProbe);
        $this->assertSame(self::MINE, $selected['selected']['type']);
        $this->assertFalse($selected['wait_for_trader']);
        $this->assertSame(self::SIPHON, $allocator->rank([$siphon, $miner], $postProbe + ['canaries_used' => ['miner']])['selected']['type']);
        $this->assertNull($allocator->rank([$siphon, $miner], $postProbe + ['canaries_used' => ['miner', 'siphon']])['selected']);
        $this->assertSame(self::MINE, $allocator->rank([$miner], $context + ['cash' => 1200000])['selected']['type']);
        $this->assertNull($allocator->rank([$miner], $context + ['cash' => 1199999])['selected']);
        $this->assertNull($allocator->rank([$miner + ['request_fits' => false]], $postProbe)['selected']);
        $this->assertNull($allocator->rank([$miner + ['approved_exposure' => 60001]], $postProbe)['selected']);

        $boundary = $this->offer(self::MINE, 60000, 2500);
        $this->assertTrue($allocator->evaluateCandidate($boundary, 0, 120, 81, canaryMode: true)['pass']);
        $this->assertFalse($allocator->evaluateCandidate(array_replace($boundary, ['delta_v' => 2499]), 0, 120, 81, canaryMode: true)['pass']);
        $this->assertFalse($allocator->evaluateCandidate($boundary, 0, 120, 73, canaryMode: true)['pass']);
        $this->assertFalse($allocator->evaluateCandidate($miner, 15, 120, 114, canaryMode: true)['pass']);
        $this->assertGreaterThan(0, $allocator->evaluateCandidate($siphon, 10, 120, 114, canaryMode: true)['g']);
        $this->assertLessThan(0, $allocator->evaluateCandidate($siphon, 11, 120, 114, canaryMode: true)['g']);
    }

    public function test_v7_scaling_requires_twenty_distinct_receipts_for_the_same_site_and_configuration(): void
    {
        $agent = $this->ledgerAgent();
        $calibrator = app(ExtractionCalibrator::class);
        $sample = ['yield_units' => 7, 'cooldown_seconds' => 80, 'symbol' => self::ORE];
        for ($id = 1; $id <= 19; $id++) {
            $calibrator->recordReceipt($agent, 'mine', self::SITE, 'config-A', $sample + ['action_id' => $id]);
        }
        $calibrator->recordReceipt($agent, 'mine', self::SITE, 'config-A', $sample + ['action_id' => 19]);
        $calibrator->recordReceipt($agent, 'mine', self::SITE, 'config-B', $sample + ['action_id' => 20]);
        $this->assertFalse($calibrator->isCalibratedForScaling($agent, 'mine', self::SITE, 'config-A'));
        $calibrator->recordReceipt($agent, 'mine', self::SITE, 'config-A', $sample + ['action_id' => 21]);
        $this->assertTrue($calibrator->isCalibratedForScaling($agent, 'mine', self::SITE, 'config-A'));
        $this->assertFalse($calibrator->isCalibratedForScaling($agent, 'mine', self::SITE, 'config-B'));
        $this->assertFalse($calibrator->isCalibratedForScaling($agent, 'mine', self::BUYER, 'config-A'));
    }

    public function test_v7_gas_gate_uses_distinct_valid_polls_and_latest_positive_bid(): void
    {
        $agent = $this->ledgerAgent();
        $calibrator = app(ExtractionCalibrator::class);
        $now = $this->instant();
        $gas = ExtractionSites::GAS_GIANT_YIELDS;
        $booked = array_fill_keys($gas, 85);
        $this->gasPoll($now->subDay()->subSecond(), 500);
        $this->gasPoll($now->subHours(10), 80);
        $this->gasPoll($now->subHour(), 100);
        $this->gasPoll($now->subMinutes(10), 300, ['invalidated' => true]);
        $this->gasPoll($now->subMinutes(5), 300, source: 'cache');
        $this->gasPoll($now->subMinutes(4), 100, ['provider_read_at' => $now->subHour()->toIso8601String()]);
        $missing = $calibrator->gasBidGate($agent, self::BUYER, $booked, $now);
        $this->assertSame('insufficient_gas_polls', $missing['reason']);
        $this->assertSame(array_fill_keys($gas, 2), $missing['poll_counts']);

        $this->gasPoll($now->subSeconds(180), 140);
        $exact = $calibrator->gasBidGate($agent, self::BUYER, $booked, $now);
        $this->assertTrue($exact['scaling_allowed']);
        $this->assertSame(array_fill_keys($gas, 100), $exact['medians']);
        $this->assertSame(array_fill_keys($gas, 3), $exact['poll_counts']);
        $this->assertSame('gas_bid_below_median_floor', $calibrator->gasBidGate($agent, self::BUYER, array_replace($booked, [$gas[0] => 84]), $now)['reason']);
        $this->assertSame('gas_bid_stale', $calibrator->gasBidGate($agent, self::BUYER, $booked, $now->addSecond())['reason']);
        $this->gasPoll($now->subSecond(), 500, ['invalidated_by_action' => 77]);
        $invalidated = $calibrator->gasBidGate($agent, self::BUYER, $booked, $now);
        $this->assertSame('gas_bid_stale', $invalidated['reason']);
        $this->assertSame(array_fill_keys($gas, 3), $invalidated['poll_counts']);
        $this->gasPoll($now, 0);
        $this->assertSame('gas_bid_stale', $calibrator->gasBidGate($agent, self::BUYER, $booked, $now)['reason']);
    }

    public function test_v7_gas_gate_rounds_fractional_median_floor_up_to_a_whole_credit(): void
    {
        $agent = $this->ledgerAgent();
        $calibrator = app(ExtractionCalibrator::class);
        $now = $this->instant();
        $this->gasPoll($now->subHour(), 101);
        $this->gasPoll($now->subMinutes(10), 101);
        $this->gasPoll($now, 101);
        $booked = array_fill_keys(ExtractionSites::GAS_GIANT_YIELDS, 86);
        $this->assertTrue($calibrator->gasBidGate($agent, self::BUYER, $booked, $now)['scaling_allowed']);
        $booked['HYDROCARBON'] = 85;
        $this->assertSame('gas_bid_below_median_floor', $calibrator->gasBidGate($agent, self::BUYER, $booked, $now)['reason']);
    }

    #[DataProvider('preloadCases')]
    public function test_v5_an_on_site_miner_checks_fresh_all_yield_or_signed_bids(bool $signed, string $missingGood, bool $allowed): void
    {
        $episode = $this->extractionWorld();
        $extra = [];
        if ($signed) {
            $survey = $this->survey('iron-survey', [self::ORE, self::ORE], 'LARGE', 600);
            app(SurveyPool::class)->save($this->agent, $survey, self::MINER);
            $extra = ['survey_signature' => $survey['signature']];
        }
        $run = $this->startMiner($episode, $extra);
        $command = $signed ? 'mine_survey' : 'mine';
        $intent = $this->stage($run, $command);
        $this->setBid($missingGood, 0);

        $this->execute($intent);
        $path = $signed ? '/extract/survey' : '/extract';
        $this->assertSame($allowed ? ActionState::Confirmed : ActionState::Rejected, $intent->state, $intent->message ?? '');
        $this->assertCount((int) $allowed, $this->writes($path));
        if (! $allowed) {
            $this->assertStringContainsString($missingGood, $intent->message);
            $this->assertSame([], $run->refresh()->totals['unsold']);
        }
    }

    public static function preloadCases(): array
    {
        return [
            'unsurveyed laser requires all fourteen bids' => [false, 'MERITIUM_ORE', false],
            'survey requires its signed deposit bid' => [true, self::ORE, false],
            'survey does not require unrelated bids' => [true, 'MERITIUM_ORE', true],
        ];
    }

    public function test_v5_direct_v8_preview_can_execute_with_its_pinned_route(): void
    {
        $episode = $this->extractionWorld();
        $run = $this->startMiner($episode, keepPreviewRoute: true);
        $first = $this->execute($this->stage($run, 'mine'));
        $this->assertSame(ActionState::Confirmed, $first->state, $first->message ?? '');
        $this->assertCount(1, $this->writes('/extract'));
    }

    public function test_v8_start_pins_measured_acquisition_cutoff_and_pipeline_selection(): void
    {
        $episode = $this->extractionWorld();
        $now = $this->instant();
        $samples = ['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => self::MINER,
            'strategy' => 'mining', 'plan_version' => 8, 'state' => RunState::Completed, 'expires_at' => $now, 'plan' => []];
        foreach ([200, 250, 220, 230] as $seconds) {
            AutomationRun::create($samples + ['totals' => ['cycles' => 1, 'elapsed_seconds' => $seconds]]);
        }
        $selectedAt = $now->subSeconds(15)->toIso8601String();
        $settlement = $now->addSeconds(400);
        $preview = $this->previewEndpoint($episode, self::MINER, 'mining', [
            'settlement_deadline' => $settlement->toIso8601String(), 'pipeline_preselected' => true, 'pipeline_selected_at' => $selectedAt,
        ]);
        $this->assertArrayNotHasKey('elapsed_p90_seconds', $preview->plan);
        AutomationRun::create($samples + ['totals' => ['cycles' => 1, 'elapsed_seconds' => 300]]);

        $plan = app(AutomationRunner::class)->startCampaign($this->agent, $preview, $episode)->plan;
        $cutoff = $now->addSeconds(375);
        $this->assertSame(300, $plan['elapsed_p90_seconds']);
        $this->assertSame($cutoff->toIso8601String(), $plan['acquisition_cutoff']);
        $this->assertSame($plan['route']['delivery_seconds'], $plan['delivery_seconds']);
        $this->assertSame($cutoff->min($settlement->subSeconds($plan['delivery_seconds']))->toIso8601String(), $plan['effective_acquisition_cutoff']);
        $this->assertTrue($plan['pipeline_preselected']);
        $this->assertSame($selectedAt, $plan['pipeline_selected_at']);
    }

    public function test_v8_empty_run_expiry_releases_unused_delivery_fuel_and_corridor_commitment(): void
    {
        $episode = $this->extractionWorld();
        $run = $this->startMiner($episode, keepPreviewRoute: true);
        $owner = 'run:'.$run->id;
        $fuelId = app(StateChain::class)->reserveCredit($this->agent, $owner, 'delivery_fuel', 'refuel', 5,
            runId: $run->id, shipSymbol: $run->ship_symbol);
        $book = app(CorridorBook::class);
        $order = $this->order(self::BUYER, self::ORE, 'sell', 7, now()->timestamp + 30, self::MINER);
        $ids = $book->reserveOrders($this->agent, [$order + ['owner_type' => 'run', 'owner_id' => (string) $run->id]]);
        $run->update(['expires_at' => now()]);

        app(AutomationRunner::class)->advance($run->id);

        $this->assertSame(RunState::Stopped, $run->refresh()->state);
        $this->assertNull($run->active_agent_id);
        $this->assertDatabaseHas('credit_reservations', ['id' => $fuelId, 'state' => 'released']);
        $this->assertDatabaseHas('corridor_book_events', ['id' => $ids[0], 'state' => 'released']);
    }

    public function test_v5_two_confirmed_seven_unit_yields_require_fresh_bids_for_held_ore_before_departure(): void
    {
        $episode = $this->extractionWorld();
        $run = $this->startMiner($episode);
        $first = $this->execute($this->stage($run, 'mine'));
        $this->assertSame(ActionState::Confirmed, $first->state, $first->message ?? '');
        $this->assertSame([self::ORE => 7], $run->refresh()->totals['unsold']);

        $this->advanceTime(80);
        $second = $this->execute($this->stage($run, 'mine'));
        $this->assertSame(ActionState::Confirmed, $second->state, $second->message ?? '');
        $this->assertSame([self::ORE => 14], $run->refresh()->totals['unsold']);
        $this->setBid('MERITIUM_ORE', 0);
        $departure = $this->stage($run, 'navigate');
        $this->setBid(self::ORE, 0);
        $this->execute($departure);
        $this->assertSame(ActionState::Rejected, $departure->state, $departure->message ?? '');
        $this->assertStringContainsString(self::ORE, $departure->message);
        $this->assertCount(2, $this->writes('/extract'));
        $this->assertCount(0, $this->writes('/navigate'));
        $this->assertSame([self::ORE => 14], $run->refresh()->totals['unsold']);
    }

    #[DataProvider('unsafeWaypoints')]
    public function test_v5_queued_extraction_checks_live_waypoint_modifiers(array $modifiers, string $reason, float $mountCondition = 1): void
    {
        $episode = $this->extractionWorld(mountCondition: $mountCondition);
        $run = $this->startMiner($episode, ['max_extractions' => 2]);
        $intent = $this->stage($run, 'mine');
        $this->advanceTime(1);
        $this->provider['waypoints'][0] = array_replace($this->provider['waypoints'][0], $modifiers);
        if ($reason === 'modifiers_missing') {
            unset($this->provider['waypoints'][0]['modifiers']);
        }
        $this->execute($intent);

        $this->assertSame(ActionState::Rejected, $intent->state, $intent->message ?? '');
        $this->assertStringContainsString($reason, $intent->message);
        $this->assertCount(0, $this->writes('/extract'));
        $this->assertDatabaseCount('extraction_site_sends', 0);
        $this->assertTrue(collect($this->provider['calls'])->contains(fn (array $call) => $call['method'] === 'GET' && str_ends_with($call['path'], '/'.self::SITE)));
    }

    public static function unsafeWaypoints(): array
    {
        return [
            'missing' => [[], 'modifiers_missing'],
            'unstable' => [['modifiers' => [['symbol' => 'UNSTABLE']]], 'site_hold'],
            'critical limit' => [['modifiers' => [['symbol' => 'CRITICAL_LIMIT']]], 'site_hold'],
            'stripped' => [['modifiers' => [['symbol' => 'STRIPPED']]], 'STRIPPED'],
            'explosive projection crosses condition floor' => [['modifiers' => [['symbol' => 'EXPLOSIVE_GASES']]], 'condition', .51],
        ];
    }

    public function test_v5_hazard_seen_during_budget_wait_refuses_queued_transport(): void
    {
        $episode = $this->extractionWorld();
        $run = $this->startMiner($episode);
        $intent = $this->stage($run, 'mine');
        $budget = $this->duringWriteBudget(function () {
            $this->advanceTime(1);
            app(ExtractionSites::class)->recordModifiers($this->agent, self::SITE, ['UNSTABLE'], $this->instant(), 'receipt');
        });
        $this->execute($intent);
        $this->assertSame(1, $budget->writes);
        $this->assertCount(0, $this->writes('/extract'));
        $this->assertNull($intent->sent_at);
        $this->assertDatabaseCount('extraction_site_sends', 0);
    }

    #[DataProvider('surveyRefusals')]
    public function test_v6_provider_survey_refusal_invalidates_the_signature_and_rechecks_all_yields(int $code, string $reason): void
    {
        $episode = $this->extractionWorld();
        $survey = $this->survey('server-refused', [self::ORE, self::ORE], 'LARGE', 600);
        app(SurveyPool::class)->save($this->agent, $survey, self::MINER);
        $broker = app(SurveyBroker::class);
        $this->assertSame($survey, $broker->leaseBest($this->agent, self::SITE, self::MINER, [self::ORE => 120], 100, $this->instant()));
        $run = $this->startMiner($episode, ['survey_signature' => $survey['signature']]);
        $intent = $this->stage($run, 'mine_survey');
        // Supply the pinned waypoint here so both provider refusal codes reach invalidation.
        $intent->update(['arguments' => $intent->arguments + ['surveyWaypoint' => self::SITE]]);
        $this->provider['force_survey_error'] = $code;
        $this->execute($intent);
        $this->assertSame(ActionState::Rejected, $intent->state, $intent->message ?? '');
        $this->assertCount(1, $this->writes('/extract/survey'));
        $saved = GameObservation::where('agent_id', $this->agent->id)->where('kind', 'survey')->where('symbol', $survey['signature'])->firstOrFail();
        $this->assertSame($reason, $saved->data['unavailable_reason']);
        $this->assertNull($broker->leaseBest($this->agent, self::SITE, self::MINER, [self::ORE => 120], 100, $this->instant()));

        $this->setBid('MERITIUM_ORE', 0);
        $retry = $this->stage($run, 'mine');
        $this->execute($retry);
        $this->assertSame(ActionState::Rejected, $retry->state, $retry->message ?? '');
        $this->assertStringContainsString('MERITIUM_ORE', $retry->message);
        $this->assertCount(0, $this->writes('/extract'));
    }

    public static function surveyRefusals(): array
    {
        return ['expired' => [4221, 'server_expired'], 'exhausted' => [4224, 'exhausted']];
    }

    public function test_v8_an_ordinary_tender_cannot_start_before_transfer_calibration(): void
    {
        $episode = $this->extractionWorld(stationary: true);
        $binding = ['stationary' => true, 'cluster_id' => self::SITE.'>'.self::BUYER];
        $this->refused(function () use ($episode, $binding): void {
            $run = $this->previewEndpoint($episode, self::TENDER, 'tender', $binding);
            app(AutomationRunner::class)->startCampaign($this->agent, $run, $episode);
        }, 'calibrat');
        $this->assertDatabaseMissing('automation_runs', ['active_agent_id' => $this->agent->id]);
        $this->assertDatabaseCount('transfer_leases', 0);
    }

    public function test_v8_canary_leases_only_at_ready_rendezvous_then_confirms_two_inventories_once(): void
    {
        [$miner, $tender] = $this->startPair();
        $cluster = app(MiningCluster::class);
        $this->assertFalse($cluster->canAdmitOrdinary(false, true, true));
        $this->assertFalse($cluster->canAdmitOrdinary(true, false, true));
        $this->assertTrue($cluster->canAdmitOrdinary(true, true, true));
        $this->assertSame('canary_slot_used', $this->admitPair($miner, $tender)['reason']);

        $mined = $this->execute($this->stage($miner, 'mine'));
        $this->assertSame(ActionState::Confirmed, $mined->state, $mined->message ?? '');
        $this->assertSame([self::ORE => 7], $miner->refresh()->totals['extracted']);
        $this->assertDatabaseHas('extraction_site_sends', ['ship_action_id' => $mined->id, 'state' => 'confirmed']);
        $params = $this->leaseParameters($miner, $tender);
        $this->assertNull($cluster->issueLease($this->agent, $params), 'The source cooldown is active.');
        $this->assertDatabaseCount('transfer_leases', 0);

        $this->advanceTime(80);
        $source = $this->savedShip(self::MINER);
        $target = $this->savedShip(self::TENDER);
        $this->assertNull($cluster->issueLease($this->agent, $params + ['receiver_snapshot' => array_replace($target, [
            'cooldown' => ['readyAt' => $this->instant()->addSecond()->toIso8601String()],
        ])]));
        foreach (['DOCKED', 'IN_TRANSIT'] as $nav) {
            $this->assertNull($cluster->issueLease($this->agent, $params + ['receiver_snapshot' => array_replace($target, [
                'nav' => array_replace($target['nav'], ['status' => $nav]),
            ])]));
        }
        $this->assertNull($cluster->issueLease($this->agent, $params + ['source_snapshot' => array_replace($source, [
            'nav' => array_replace($source['nav'], ['waypointSymbol' => self::BUYER]),
        ])]));
        $this->assertDatabaseCount('transfer_leases', 0);

        $intent = $this->stage($miner, 'transfer');
        $lease = $this->lease($intent);
        $this->assertSame(7, $lease->units);
        $this->assertSame($tender->id, $lease->receiver_run_id);
        $this->assertSame($miner->id, $lease->source_run_id);
        $this->assertSame($miner->plan['canary_pair_id'], $lease->canary_pair_id);
        $this->assertSame($intent->arguments['transferLeaseId'], $lease->id);
        $this->assertSame($this->instant()->addSeconds(60)->toDateTimeString(), $lease->expires_at);
        $this->assertUniqueLeaseEndpoint($lease, 'active_source_key', 'active_receiver_key');
        $this->assertUniqueLeaseEndpoint($lease, 'active_receiver_key', 'active_source_key');
        $this->assertDatabaseCount('transfer_leases', 1);

        $sendingLocks = null;
        DB::listen(function (QueryExecuted $query) use (&$sendingLocks): void {
            if (str_starts_with($query->sql, 'update "transfer_leases"') && in_array('sending', $query->bindings, true)) {
                $locks = app(HostLock::class);
                $writer = 'agent-'.$this->agent->id;
                $sendingLocks = [
                    $locks->holdsExclusive($writer),
                    $locks->holdsExclusive('ship-'.$this->agent->id.'-'.self::MINER),
                    $locks->holdsExclusive('ship-'.$this->agent->id.'-'.self::TENDER),
                ];
            }
        });
        $transportState = null;
        $this->installProvider(function (Request $request) use ($intent, &$transportState): void {
            if (str_ends_with($request->url(), '/transfer')) {
                $transportState = [$intent->fresh()->state, $this->lease($intent)->state];
            }
        });
        $this->execute($intent);
        $this->assertSame([true, true, true], $sendingLocks, $intent->message ?? '');
        $this->assertSame([ActionState::Sending, 'sending'], $transportState);
        $this->assertSame(['tradeSymbol' => self::ORE, 'units' => 7, 'shipSymbol' => self::TENDER], $this->writes('/transfer')[0]['arguments']);
        $this->assertTransferConfirmedOnce($miner, $tender, $intent);
        $this->assertSame('canary_slot_used', $this->admitPair($miner, $tender)['reason']);
        $this->assertSame(self::BUYER, $this->stage($tender, 'navigate')->arguments['waypointSymbol']);
        $this->assertCount(1, $this->writes('/transfer'));
    }

    public function test_v8_granted_lease_expiring_in_write_budget_does_not_transport(): void
    {
        [$miner, $tender, $intent] = $this->readyTransfer();
        $lease = $this->lease($intent);
        $budget = $this->duringWriteBudget(function () {
            $this->advanceTime(60);
        });
        $this->execute($intent);

        $this->assertSame(1, $budget->writes, $intent->message ?? '');
        $this->assertCount(0, $this->writes('/transfer'));
        $this->assertSame(ActionState::Rejected, $intent->state);
        $this->assertNull($intent->sent_at);
        $this->assertSame('expired', $this->lease($intent)->state);
        $this->assertFalse(app(MiningCluster::class)->hasBlockingLease($this->agent, $lease->source_ship));
        $this->assertFalse(app(MiningCluster::class)->hasBlockingLease($this->agent, $lease->receiver_ship));
        $this->assertSame([self::ORE => 7], $miner->refresh()->totals['unsold']);
        $this->assertSame([], $tender->refresh()->totals['unsold']);
    }

    #[DataProvider('changedRendezvous')]
    public function test_v8_rechecks_rendezvous_after_the_write_budget(string $change): void
    {
        [$miner, $tender, $intent] = $this->readyTransfer();
        $budget = $this->duringWriteBudget(function () use ($change, $tender, $intent): void {
            $index = array_search(self::TENDER, array_column($this->provider['ships'], 'symbol'), true);
            match ($change) {
                'pair' => $tender->update(['plan' => array_replace($tender->plan, ['canary_pair_id' => 'different-pair'])]),
                'capacity' => $this->provider['ships'][$index]['cargo']['capacity'] = 6,
                'waypoint' => $this->provider['ships'][$index]['nav']['waypointSymbol'] = self::BUYER,
                'nav' => $this->provider['ships'][$index]['nav']['status'] = 'DOCKED',
                'cooldown' => $this->provider['ships'][$index]['cooldown']['expiration'] = $this->instant()->addSeconds(90)->toIso8601String(),
                'goods' => $intent->update(['arguments' => array_replace($intent->arguments, ['symbol' => 'COPPER_ORE'])]),
                'endpoint' => $intent->update(['arguments' => array_replace($intent->arguments, ['targetShip' => 'LOCAL-1'])]),
            };
        });
        $this->execute($intent);
        $this->assertSame(1, $budget->writes, $intent->message ?? '');
        $this->assertCount(0, $this->writes('/transfer'), "The changed $change reached fake transport.");
        $this->assertSame(ActionState::Rejected, $intent->state, $intent->message ?? '');
        $this->assertSame([self::ORE => 7], $miner->refresh()->totals['unsold']);
        $this->assertSame([], $tender->refresh()->totals['unsold']);
    }

    public static function changedRendezvous(): array
    {
        return array_combine(
            ['pair', 'capacity', 'waypoint', 'nav', 'cooldown', 'goods', 'endpoint'],
            array_map(fn (string $case) => [$case], ['pair', 'capacity', 'waypoint', 'nav', 'cooldown', 'goods', 'endpoint']),
        );
    }

    public function test_v8_replays_a_durable_source_receipt_without_reissuing_the_transfer(): void
    {
        [$miner, $tender, $intent] = $this->readyTransfer();
        $this->installProvider(function (Request $request): void {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/my/ships/'.self::TENDER) && $this->writes('/transfer') !== []) {
                throw new RequestDeferred('Synthetic interruption after the source receipt was persisted.', 5);
            }
        });
        $this->execute($intent);
        $this->assertSame(ActionState::Uncertain, $intent->state, $intent->message ?? '');
        $this->assertTrue($intent->outcome['response_validated']);
        $this->assertSame('uncertain', $this->lease($intent)->state);
        $this->assertSame([self::ORE => 7], $miner->refresh()->totals['unsold']);
        $this->assertSame([], $tender->refresh()->totals['unsold']);
        $this->assertCount(1, $this->writes('/transfer'));

        $this->installProvider();
        app(ShipCommandExecutor::class)->reconcile($this->agent, $intent);
        $this->assertTransferConfirmedOnce($miner, $tender, $intent);
    }

    public function test_v8_expiring_a_sending_lease_keeps_both_endpoints_reserved(): void
    {
        [$miner, $tender, $intent] = $this->readyTransfer();
        $cluster = app(MiningCluster::class);
        $lease = $this->lease($intent);
        $this->assertSame(['ok' => true, 'reason' => null], $cluster->validateForTransport($this->agent, $lease->id, $intent->id, [], $this->instant()));
        $this->assertSame('sending', $this->lease($intent)->state);
        $this->advanceTime(61);
        $this->assertSame('uncertain', $cluster->settleLease($this->agent, $lease->id, false, false)['state']);
        $this->assertTrue($cluster->hasBlockingLease($this->agent, $miner->ship_symbol));
        $this->assertTrue($cluster->hasBlockingLease($this->agent, $tender->ship_symbol));
        $this->assertCount(0, $this->writes('/transfer'));
    }

    public function test_v8_delayed_paired_effects_confirm_once_after_unchanged_reads_and_expiry(): void
    {
        [$miner, $tender, $intent] = $this->readyTransfer();
        $cluster = app(MiningCluster::class);
        $executor = app(ShipCommandExecutor::class);
        $this->installProvider(function (Request $request): void {
            if (str_ends_with($request->url(), '/transfer')) {
                $this->provider['writes'][] = ['path' => '/my/ships/'.self::MINER.'/transfer', 'arguments' => $request->data()];

                throw new ConnectionException('Synthetic timeout before either inventory effect is visible.');
            }
        });
        $this->execute($intent);
        $this->assertSame(ActionState::Uncertain, $intent->state, $intent->message ?? '');
        $this->advanceTime(61);
        for ($read = 0; $read < 2; $read++) {
            $this->assertSame(ActionState::Uncertain, $executor->reconcile($this->agent, $intent)->state);
            $this->assertSame('uncertain', $this->lease($intent)->state);
            $this->assertTrue($cluster->hasBlockingLease($this->agent, $miner->ship_symbol));
            $this->assertTrue($cluster->hasBlockingLease($this->agent, $tender->ship_symbol));
        }

        $this->setProviderCargo(self::MINER, 0);
        $this->assertSame(ActionState::Uncertain, $executor->reconcile($this->agent, $intent)->state, 'Source evidence alone cannot settle a transfer.');
        $this->setProviderCargo(self::TENDER, 7);
        $executor->reconcile($this->agent, $intent);
        $this->assertTransferConfirmedOnce($miner, $tender, $intent);
    }

    private function extractionWorld(bool $stationary = false, float $mountCondition = 1): CampaignEpisode
    {
        $this->setUpSaturation(hubs: 2, seedCoverage: false);
        $this->provider = $this->world();
        $miner = FakeSpaceTraders::ship(self::MINER);
        $miner['nav']['status'] = 'IN_ORBIT';
        $miner['cargo']['capacity'] = $miner['modules'][1]['capacity'] = 15;
        $miner['fuel'] = ['current' => 80, 'capacity' => 80];
        $miner['frame']['fuelCapacity'] = 80;
        $miner['engine']['speed'] = 9;
        $miner['mounts'][0]['condition'] = $mountCondition;
        $this->provider['ships'][] = $miner;
        if ($stationary) {
            $tender = FakeSpaceTraders::lightHauler(self::TENDER, self::SITE);
            $tender['nav']['status'] = 'IN_ORBIT';
            $this->provider['ships'][] = $tender;
        }
        $this->provider['waypoints'][0]['modifiers'] = [];
        $this->provider['market_goods'][self::BUYER] = FakeSpaceTraders::withAllMiningBids($this->provider['market_goods'][self::BUYER]);
        $this->provider['priced_markets'] = [self::BUYER];
        $this->provider['yields'] = [['symbol' => self::ORE, 'units' => 7]];
        $this->provider['cooldown_seconds'] = 80;
        $this->installProvider();

        $sync = app(AgentSynchronizer::class);
        $sync->sync($this->agent, false, freshFleet: true);
        foreach ([self::SITE, self::BUYER] as $symbol) {
            $quote = app(SpaceTradersApi::class)->market($this->agent, $symbol, fresh: true);
            $sync->observe($this->agent, 'market', $symbol, $quote);
        }
        $body = Yaml::parseFile(base_path('campaigns/weekly-system-saturation-fleet.yaml'));
        $body['agent']['symbol'] = $this->agent->name;
        $body['fleet']['auto_arm']['revision_hash'] = $this->armRevision->content_hash;
        $body['fleet']['saturation']['per_ship_noncredit_locks'] = true;
        $body['fleet']['saturation']['extraction']['enabled'] = true;
        $body['fleet']['saturation']['extraction']['stationary'] = $stationary;

        return $this->primary(revision: app(CampaignPlaybook::class)->import(Yaml::dump($body, 12)));
    }

    private function installProvider(?callable $beforeResponse = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($beforeResponse) {
            $beforeResponse?->__invoke($request);
            [$body, $status] = FakeSpaceTraders::respond($this->provider, $request);

            return Http::response($body, $status);
        });
    }

    private function previewEndpoint(CampaignEpisode $episode, string $ship, string $strategy, array $extra = []): AutomationRun
    {
        return app(AutomationRunner::class)->preview($this->agent, $ship, $extra + [
            'plan_version' => 8, 'strategy' => $strategy, 'site' => self::SITE, 'buyer' => self::BUYER,
            'reserve' => 0, 'minutes' => 30, 'cycles' => 1, 'max_spend' => 500, 'max_loss' => 500,
            'max_fuel' => 100, 'allowed_goods' => [self::ORE], 'extraction_headroom' => 7,
            'min_sell_price' => 1,
        ], $episode);
    }

    private function startMiner(CampaignEpisode $episode, array $extra = [], bool $keepPreviewRoute = false): AutomationRun
    {
        $preview = $this->previewEndpoint($episode, self::MINER, 'mining', $extra);
        if (! $keepPreviewRoute) {
            // Supply quote metadata so other cases can reach their own guards even when the legacy fuel check requires it.
            $plan = $preview->plan;
            $plan['route']['quote_at'] = $this->instant()->toIso8601String();
            $preview->update(['plan' => $plan]);
        }

        return app(AutomationRunner::class)->startCampaign($this->agent, $preview, $episode);
    }

    private function startPair(): array
    {
        $episode = $this->extractionWorld(stationary: true);
        $calibrator = app(ExtractionCalibrator::class);
        $config = FuelPlanner::configuration($this->savedShip(self::MINER));
        for ($id = 1; $id <= 20; $id++) {
            $calibrator->recordReceipt($this->agent, 'mine', self::SITE, $config, [
                'action_id' => $id + 1000, 'yield_units' => 7, 'cooldown_seconds' => 80, 'symbol' => self::ORE,
            ]);
        }
        $this->assertDatabaseMissing('automation_runs', ['active_agent_id' => $this->agent->id]);
        $binding = ['stationary' => true, 'cluster_id' => self::SITE.'>'.self::BUYER, 'unit_cap' => 15, 'combined_exposure' => 1000];
        $miner = $this->previewEndpoint($episode, self::MINER, 'mining', $binding);
        $tender = $this->previewEndpoint($episode, self::TENDER, 'tender', $binding);
        $this->assertDatabaseCount('transfer_leases', 0);
        $this->assertSame('proof_required', $this->admitPair($miner, $tender, proofPassed: false)['reason']);
        $pair = $this->admitPair($miner, $tender);
        $this->assertTrue($pair['allowed'], $pair['reason'] ?? '');
        $this->assertNotEmpty($pair['canary_pair_id']);
        $runner = app(AutomationRunner::class);
        foreach ([$miner, $tender] as $run) {
            // Bind the durable pair to the two preview IDs, as the director does before start.
            $run->update(['plan' => array_replace($run->plan, ['canary_pair_id' => $pair['canary_pair_id'], 'canary_transfer' => true, 'unit_cap' => 15])]);
            $this->assertSame(RunState::Preview, $run->state);
            $this->assertTrue($run->plan['canary_transfer']);
            $this->assertSame($pair['canary_pair_id'], $run->plan['canary_pair_id']);
        }
        $this->assertSame('canary_slot_used', $this->admitPair($miner, $tender)['reason']);
        $this->assertDatabaseCount('transfer_leases', 0);
        foreach ([$miner, $tender] as $run) {
            $runner->startCampaign($this->agent, $run, $episode);
            $this->assertSame(RunState::Running, $run->refresh()->state);
            $this->assertSame($pair['canary_pair_id'], $run->plan['canary_pair_id']);
        }
        $this->assertDatabaseCount('transfer_leases', 0);

        return [$miner, $tender];
    }

    private function admitPair(AutomationRun $miner, AutomationRun $tender, bool $proofPassed = true): array
    {
        return app(MiningCluster::class)->admitCanaryPair(
            $this->agent, $miner->plan['cluster_id'], self::SITE, self::BUYER, self::MINER, self::TENDER,
            $miner->id, $tender->id, [self::ORE], 15, 1000, proofPassed: $proofPassed,
        );
    }

    private function readyTransfer(): array
    {
        [$miner, $tender] = $this->startPair();
        $mined = $this->execute($this->stage($miner, 'mine'));
        $this->assertSame(ActionState::Confirmed, $mined->state, $mined->message ?? '');
        $this->advanceTime(80);

        return [$miner, $tender, $this->stage($miner, 'transfer')];
    }

    private function leaseParameters(AutomationRun $miner, AutomationRun $tender): array
    {
        return [
            'cluster_id' => $miner->plan['cluster_id'], 'canary_pair_id' => $miner->plan['canary_pair_id'],
            'source_ship' => self::MINER, 'receiver_ship' => self::TENDER, 'source_run_id' => $miner->id, 'receiver_run_id' => $tender->id,
            'waypoint' => self::SITE, 'good' => self::ORE, 'units' => 7,
        ];
    }

    private function lease(ShipAction $action): object
    {
        return DB::table('transfer_leases')->where('ship_action_id', $action->id)->firstOrFail();
    }

    private function assertUniqueLeaseEndpoint(object $lease, string $unique, string $different): void
    {
        $duplicate = array_replace((array) $lease, ['ship_action_id' => null, $different => 'different-endpoint']);
        unset($duplicate['id']);
        try {
            DB::table('transfer_leases')->insert($duplicate);
            $this->fail('Expected database uniqueness on '.$unique);
        } catch (QueryException $exception) {
            $this->assertStringContainsString('transfer_leases.'.$unique, $exception->getMessage());
        }
    }

    private function assertTransferConfirmedOnce(AutomationRun $miner, AutomationRun $tender, ShipAction $intent): void
    {
        $this->assertSame(ActionState::Confirmed, $intent->refresh()->state, $intent->message ?? '');
        $this->assertSame('confirmed', $this->lease($intent)->state);
        $this->assertSame([], MiningPlanner::inventory($this->savedShip(self::MINER)));
        $this->assertSame([self::ORE => 7], MiningPlanner::inventory($this->savedShip(self::TENDER)));
        $sourceTotals = $miner->refresh()->totals;
        $receiverTotals = $tender->refresh()->totals;
        $this->assertSame([self::ORE => 7], $sourceTotals['transferred_out']);
        $this->assertSame([self::ORE => 7], $receiverTotals['transferred_in']);
        $this->assertSame([], $sourceTotals['unsold']);
        $this->assertSame([self::ORE => 7], $receiverTotals['unsold']);
        $this->execute($intent);
        app(ShipCommandExecutor::class)->reconcile($this->agent, $intent);
        $this->assertSame($sourceTotals, $miner->refresh()->totals);
        $this->assertSame($receiverTotals, $tender->refresh()->totals);
        $this->assertCount(1, $this->writes('/transfer'));
        $this->assertDatabaseCount('transfer_leases', 1);
    }

    private function duringWriteBudget(callable $callback): RequestBudget
    {
        $budget = new class($callback) extends RequestBudget
        {
            public int $writes = 0;

            public function __construct(private readonly mixed $onWrite) {}

            public function reserve(?string $token = null, ?string $account = null, string $priority = 'discovery', float $maxWait = 0.75): void
            {
                if ($priority === 'write' && ++$this->writes === 1) {
                    ($this->onWrite)();
                }
            }
        };
        $this->app->instance(RequestBudget::class, $budget);

        return $budget;
    }

    private function savedShip(string $symbol): array
    {
        return Ship::where('agent_id', $this->agent->id)->where('symbol', $symbol)->firstOrFail()->snapshot;
    }

    private function setProviderCargo(string $symbol, int $units): void
    {
        $index = array_search($symbol, array_column($this->provider['ships'], 'symbol'), true);
        $this->provider['ships'][$index]['cargo']['units'] = $units;
        $this->provider['ships'][$index]['cargo']['inventory'] = $units > 0
            ? [['symbol' => self::ORE, 'units' => $units, 'name' => 'Iron Ore', 'description' => 'Synthetic ore']]
            : [];
    }

    private function setBid(string $symbol, int $bid): void
    {
        $index = array_search($symbol, array_column($this->provider['market_goods'][self::BUYER], 'symbol'), true);
        $this->provider['market_goods'][self::BUYER][$index]['sellPrice'] = $bid;
    }

    private function advanceTime(int $seconds): void
    {
        $this->travel($seconds)->seconds();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
    }

    private function stage(AutomationRun $run, string $command): ShipAction
    {
        $run->refresh()->update(['next_action_at' => now()]);
        app(AutomationRunner::class)->advance($run->id);
        $intent = ShipAction::where('automation_run_id', $run->id)->where('state', ActionState::Pending->value)->latest('id')->first();
        $this->assertNotNull($intent, $run->refresh()->blocked_reason ?? "No queued $command action.");
        $this->assertSame($command, $intent->command);

        return $intent;
    }

    private function execute(ShipAction $intent): ShipAction
    {
        app(ShipCommandExecutor::class)->execute($intent->id);

        return $intent->refresh();
    }

    private function writes(string $suffix): array
    {
        return array_values(array_filter($this->provider['writes'], fn (array $write) => str_ends_with($write['path'], $suffix)));
    }

    private function order(string $market, string $good, string $side, int $units, int $landingAt, string $tieKey): array
    {
        return ['market' => $market, 'good' => $good, 'side' => $side, 'units' => $units, 'landing_at' => $landingAt, 'tie_key' => $tieKey];
    }

    private function ledgerAgent(): Agent
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-24T16:00:00Z'));

        return $this->agent = User::factory()->create()->agents()->create([
            'name' => 'LOCAL', 'token' => 'synthetic-only', 'faction' => 'VOID', 'reset_id' => '2026-11-23',
        ]);
    }

    private function instant(): CarbonImmutable
    {
        return CarbonImmutable::now()->utc()->startOfSecond();
    }

    private function refused(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail('Expected refusal containing '.$message);
        } catch (GameException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function offer(string $type, int $price, float $value, float $requests = 0): array
    {
        return ['slot' => $type, 'type' => $type, 'price' => $price, 'effective_cost' => $price, 'delta_v' => $value, 'delta_q' => $requests];
    }

    private function survey(string $signature, array $deposits, string $size, int $expiresIn): array
    {
        return ['signature' => $signature, 'symbol' => self::SITE, 'deposits' => array_map(fn (string $symbol) => ['symbol' => $symbol], $deposits),
            'size' => $size, 'expiration' => $this->instant()->addSeconds($expiresIn)->toIso8601String()];
    }

    private function gasPoll(CarbonImmutable $time, int $bid, array $extra = [], string $source = 'direct'): void
    {
        ObservationHistory::create([
            'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'kind' => 'market', 'symbol' => self::BUYER,
            'observed_at' => $time, 'source' => $source,
            'data' => $extra + ['provider_read_at' => $time->toIso8601String(), 'tradeGoods' => array_map(
                fn (string $gas) => ['symbol' => $gas, 'sellPrice' => $bid, 'tradeVolume' => 100],
                ExtractionSites::GAS_GIANT_YIELDS,
            )],
        ]);
    }
}
