<?php

namespace Tests\Feature;

use App\Enums\RunState;
use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\Bridge\BridgeGuards;
use App\Gameplay\CampaignHealth;
use App\Gameplay\CampaignProbeBootstrap;
use App\Gameplay\CampaignSafety;
use App\Gameplay\CampaignSaturation;
use App\Gameplay\CommandRules;
use App\Gameplay\CorridorBook;
use App\Gameplay\CreditAccounting;
use App\Gameplay\FleetMetrics;
use App\Gameplay\FuelPlanner;
use App\Gameplay\GameException;
use App\Gameplay\HostLock;
use App\Gameplay\IdleLedger;
use App\Gameplay\MeshGovernor;
use App\Gameplay\OperationPlanner;
use App\Gameplay\ReadStateUnavailable;
use App\Gameplay\RequestBudget;
use App\Gameplay\RequestDeferred;
use App\Gameplay\RoutePlanner;
use App\Gameplay\ShipCommandExecutor;
use App\Gameplay\SpaceTradersApi;
use App\Gameplay\StateChain;
use App\Jobs\ExecuteShipCommand;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\CampaignRevision;
use App\Models\GameObservation;
use App\Models\ReconMission;
use App\Models\ShipAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class FleetSaturationTransportTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'X1-TEST-ROCK';

    private const BUYER = 'X1-TEST-PORT';

    private const SHIP = 'LOCAL-1';

    private Agent $agent;

    private CampaignEpisode $episode;

    private CarbonImmutable $epoch;

    private array $world;

    protected function setUp(): void
    {
        parent::setUp();
        $this->epoch = CarbonImmutable::parse('2026-10-26 12:00:00 UTC');
        $this->at(0);
        config([
            'gameplay.enabled' => true,
            'gameplay.local_engine_enabled' => false,
            'gameplay.manual_reserve_floor' => 1,
            'gameplay.reset_cache_seconds' => 86400,
        ]);
        Queue::fake();
        $this->world = FakeSpaceTraders::world();
        $this->world['next_reset'] = $this->epoch->addDays(5)->toIso8601String();
        $this->world['foundation'] = true;
        $this->world['ships'][0]['registration']['role'] = 'COMMAND';
        $this->world['ships'][] = FakeSpaceTraders::ship('LOCAL-2', self::SOURCE);
        $this->world['ships'][] = FakeSpaceTraders::ship('LOCAL-3', self::BUYER);
        $this->world['priced_markets'] = [self::SOURCE, self::BUYER];
        $this->world['goods'] = [
            ['symbol' => 'IRON_ORE', 'type' => 'EXCHANGE', 'tradeVolume' => 80, 'purchasePrice' => 10, 'sellPrice' => 5],
            ['symbol' => 'FUEL', 'type' => 'EXCHANGE', 'tradeVolume' => 10, 'purchasePrice' => 5, 'sellPrice' => 3],
            ['symbol' => 'HYDROCARBON', 'type' => 'EXCHANGE', 'tradeVolume' => 80, 'purchasePrice' => 10, 'sellPrice' => 5],
        ];
        $this->world['market_goods'][self::BUYER] = $this->world['goods'];
        $this->world['market_goods'][self::BUYER][0]['sellPrice'] = 30;
        $this->world['market_goods'][self::BUYER][2]['sellPrice'] = 30;
        foreach ($this->world['waypoints'] as &$point) {
            $point['modifiers'] = [];
        }
        unset($point);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() !== 'GET') {
                $this->world['write_workloads'][] = FleetMetrics::currentWorkload();
            }
            if ($request->method() === 'POST' && str_ends_with($path, '/purchase') && ($this->world['lose_purchase_before_apply'] ?? false)) {
                throw new ConnectionException('Connection closed before the purchase reached the provider.');
            }
            if ($request->method() === 'GET' && str_ends_with($path, '/my/ships/LOCAL-1') && ($this->world['navigation_refresh_error'] ?? false) && $this->world['writes'] !== []) {
                throw new GameException('Synthetic damage refresh failed.');
            }
            $refuel = $request->method() === 'POST' && str_ends_with($path, '/refuel');
            $damageReceipt = $request->method() === 'POST' && str_ends_with($path, '/navigate')
                ? ($this->world['navigation_damage_receipt'] ?? null) : null;
            $shipBeforeTravel = $damageReceipt !== null ? $this->world['ships'][0] : null;
            $this->world['foundation'] = ! $refuel;
            [$body, $status] = FakeSpaceTraders::respond($this->world, $request);
            $this->world['foundation'] = true;
            $lostResponse = 'lose_'.basename($path).'_response';
            if ($request->method() === 'POST' && ($this->world[$lostResponse] ?? false)) {
                $this->world[$lostResponse] = false;
                throw new ConnectionException('Command was applied upstream before the connection closed.');
            }
            if (str_ends_with($path, '/refuel') && $request->method() === 'POST') {
                unset($body['data']['cargo']);
            }
            if (preg_match('~/my/ships/[^/]+/(purchase|sell)$~', $path)) {
                unset($body['data']['fuel']);
            }
            if ($request->method() === 'POST' && str_ends_with($path, '/purchase') && isset($this->world['purchase_receipt_units'])) {
                $body['data']['transaction']['units'] = $this->world['purchase_receipt_units'];
            }
            if ($request->method() === 'POST' && str_ends_with($path, '/purchase') && isset($this->world['purchase_omitted_field'])) {
                if ($this->world['purchase_omitted_field'] === 'transaction') {
                    unset($body['data']['transaction']);
                } else {
                    unset($body['data']['transaction'][$this->world['purchase_omitted_field']]);
                }
            }
            if ($damageReceipt !== null) {
                $body['data']['events'] = [['symbol' => 'SHIP_ENGINE_OVERHEATED']];
                $nav = &$body['data']['nav'];
                $nav['route']['origin'] = ['symbol' => $shipBeforeTravel['nav']['waypointSymbol']];
                match ($damageReceipt) {
                    'bad destination' => $nav['waypointSymbol'] = self::SOURCE,
                    'bad route origin' => $nav['route']['origin']['symbol'] = self::BUYER,
                    'bad route destination' => $nav['route']['destination']['symbol'] = self::SOURCE,
                    'stale receipt' => $nav['route']['departureTime'] = now()->subHour()->toIso8601String(),
                    'future departure', 'skewed with fresh read' => $nav['route']['departureTime'] = now()->addSeconds(6)->toIso8601String(),
                    'within clock skew' => $nav['route']['departureTime'] = now()->subSeconds(4)->toIso8601String(),
                    'reversed route times' => $nav['route']['arrival'] = $nav['route']['departureTime'],
                    default => null,
                };
                if ($damageReceipt === 'stale receipt') {
                    $this->world['ships'][0] = $shipBeforeTravel;
                }
            }

            return Http::response($body, $status);
        });
        $this->agent = User::factory()->create()->agents()->create([
            'name' => 'LOCAL', 'token' => 'synthetic-fleet-token', 'faction' => 'COSMIC',
            'reset_id' => $this->world['reset'], 'credits' => $this->world['credits'], 'synced_at' => now(),
            'canonical_identity' => AgentSynchronizer::identity($this->world['reset'], 'LOCAL'),
        ]);
    }

    #[DataProvider('extractions')]
    public function test_three_partial_extraction_receipts_refresh_cargo_and_cooldown_without_extending_other_fields(string $command): void
    {
        $run = $this->extractionRun($command);
        $initial = $this->fields();
        $path = $command === 'mine' ? 'extract' : 'siphon';

        foreach ([0, 80, 160] as $index => $second) {
            $this->at($second);
            $before = $this->fields();
            if ($index === 2) {
                $this->assertSame(['nav', 'fuel', 'config', 'mounts', 'modules'], $this->staleness()['stale_fields']);
                foreach (['cargo', 'cooldown'] as $field) {
                    $this->assertSame('receipt:'.$command, $before[$field]['source']);
                    $this->assertSame($this->epoch->addSeconds(80)->timestamp, CarbonImmutable::parse($before[$field]['observed_at'])->timestamp);
                }
            }
            $this->execute($this->action($command, run: $run));
            $fields = $this->fields();
            $this->assertSame(($index + 1) * 10, $fields['cargo']['value']['units']);
            $this->assertSame('receipt:'.$command, $fields['cooldown']['source']);
            $this->assertSame($index < 2 ? $initial['fuel']['observed_at'] : $fields['nav']['observed_at'], $fields['fuel']['observed_at']);
        }

        $this->assertSame([
            'POST /my/ships/LOCAL-1/'.$path,
            'POST /my/ships/LOCAL-1/'.$path,
            'GET /my/ships/LOCAL-1',
            'POST /my/ships/LOCAL-1/'.$path,
        ], $this->shipTrace());
    }

    #[DataProvider('extractions')]
    public function test_ship_evidence_that_expires_during_budget_wait_is_refreshed_before_extraction(string $command): void
    {
        $run = $this->extractionRun($command);
        $first = $this->action($command, run: $run);
        $this->execute($first);
        $this->at(80);
        $this->execute($this->action($command, run: $run));
        $this->at(120);
        $this->assertFalse($this->staleness()['needs_refresh']);
        $action = $this->action($command, run: $run);
        $this->afterWriteReservation(function () use ($action) {
            $this->assertSame('sending', $action->fresh()->state->value);
            $this->assertSame(['POST', 'POST'], array_map(fn ($call) => explode(' ', $call)[0], $this->shipTrace()));
            $this->at(121);
            $this->assertSame(['nav', 'fuel', 'config', 'mounts', 'modules'], $this->staleness()['stale_fields']);
        });
        $this->execute($action);
        $path = $command === 'mine' ? 'extract' : 'siphon';
        $this->assertSame([
            'POST /my/ships/LOCAL-1/'.$path,
            'POST /my/ships/LOCAL-1/'.$path,
            'GET /my/ships/LOCAL-1',
            'POST /my/ships/LOCAL-1/'.$path,
        ], $this->shipTrace());
    }

    public static function extractions(): array
    {
        return [
            'mine' => ['mine'],
            'siphon' => ['siphon'],
        ];
    }

    #[DataProvider('originQuotes')]
    public function test_navigation_only_reuses_current_exact_origin_fuel_evidence(string $case, int $reads): void
    {
        $this->campaign(true);
        $this->world['ships'][0]['nav']['status'] = 'IN_ORBIT';
        if ($case === 'non-market') {
            $this->world['waypoints'][0]['traits'] = [];
        }
        $this->seedWorld();
        $quote = GameObservation::where('kind', 'market')->where('symbol', self::SOURCE)->first();
        match ($case) {
            'missing' => $quote->delete(),
            'stale' => $quote->update(['observed_at' => now()->subSeconds(901)]),
            'wrong reset' => $quote->update(['reset_id' => 'previous-reset']),
            'wrong origin' => $quote->update(['symbol' => 'X1-TEST-OTHER']),
            'invalidated' => app(AgentSynchronizer::class)->invalidateMarket($this->agent, self::SOURCE, 1),
            default => null,
        };

        $hop = $this->hop(self::SOURCE, self::BUYER);
        $run = $this->campaignRun('position', ['route' => ['hops' => [$hop], 'fuel_ceiling' => 50]]);
        $action = $this->action('navigate', ['waypointSymbol' => self::BUYER, 'hop_index' => 0], $run);
        $this->execute($action);

        $this->assertCount($reads, $this->marketGets(self::SOURCE));
        $this->assertSame(['POST /my/ships/LOCAL-1/navigate'], $this->shipTrace());
    }

    public static function originQuotes(): array
    {
        return [
            'warm exact origin' => ['warm', 0],
            'older than 900s' => ['stale', 1],
            'different reset' => ['wrong reset', 1],
            'unrelated waypoint' => ['wrong origin', 1],
            'no origin observation' => ['missing', 1],
            'writer invalidation' => ['invalidated', 1],
            'no market at origin' => ['non-market', 0],
        ];
    }

    #[DataProvider('navigationDamageRefreshes')]
    public function test_v8_navigation_settles_a_validated_receipt_when_only_the_damage_refresh_is_deferred(string $case, string $expected): void
    {
        $this->campaign(true);
        $this->world['ships'][0]['nav']['status'] = 'IN_ORBIT';
        $this->world['travel_seconds'] = 60;
        $this->seedWorld();
        $this->world['navigation_damage_receipt'] = $case;
        $this->world['defer_followup_after_write'] = ! in_array($case, ['failed refresh', 'skewed with fresh read'], true);
        $this->world['navigation_refresh_error'] = $case === 'failed refresh';
        if ($case === 'fuel mismatch') {
            $this->world['fuel_consumption'] = 90;
        }

        $hop = $this->hop(self::SOURCE, self::BUYER);
        $run = $this->campaignRun('position', ['route' => ['hops' => [$hop], 'fuel_ceiling' => 50]]);
        $action = $this->action('navigate', ['waypointSymbol' => self::BUYER, 'hop_index' => 0], $run);
        $this->execute($action, $expected);

        $this->assertTrue($action->outcome['response_validated']);
        if ($expected === 'confirmed') {
            $fuelMismatch = $case === 'fuel mismatch';
            $this->assertSame($fuelMismatch, $action->review_required);
            $this->assertSame($fuelMismatch, $action->outcome['fuel_discrepancy']);
            $this->assertSame(self::BUYER, $this->fields()['nav']['value']['waypointSymbol']);
            $this->assertSame(['config', 'mounts', 'modules'], $this->staleness()['stale_fields']);
            $this->assertSame($fuelMismatch ? 'blocked' : 'running', $run->refresh()->state->value);
            if (! $fuelMismatch) {
                $this->assertGreaterThan(now(), $run->next_action_at);
            }
            $this->assertNull($this->episode->grant()->paused_at);
        }

        $this->execute($action, $expected);
        $this->assertSame($case === 'skewed with fresh read' ? ['POST /my/ships/LOCAL-1/navigate', 'GET /my/ships/LOCAL-1'] : ['POST /my/ships/LOCAL-1/navigate'], $this->shipTrace());
        if ($case === 'stale receipt') {
            $this->assertSame(self::SOURCE, $this->world['ships'][0]['nav']['waypointSymbol']);
            $this->world['defer_followup_after_write'] = false;
            $this->assertSame('uncertain', app(ShipCommandExecutor::class)->reconcile($this->agent, $action)->state->value);
            $this->assertCount(1, $this->world['writes']);
        }
    }

    public static function navigationDamageRefreshes(): array
    {
        return [
            'deferred damage refresh' => ['deferred refresh', 'confirmed'],
            'failed damage refresh' => ['failed refresh', 'uncertain'],
            'invalid destination' => ['bad destination', 'uncertain'],
            'wrong route origin' => ['bad route origin', 'uncertain'],
            'wrong route destination' => ['bad route destination', 'uncertain'],
            'stale receipt' => ['stale receipt', 'uncertain'],
            'future departure' => ['future departure', 'uncertain'],
            'clock skew with fresh corroboration' => ['skewed with fresh read', 'confirmed'],
            'clock skew within tolerance' => ['within clock skew', 'confirmed'],
            'reversed route times' => ['reversed route times', 'uncertain'],
            'fuel mismatch preserves review' => ['fuel mismatch', 'confirmed'],
        ];
    }

    public function test_reconciling_a_deferred_navigation_receipt_preserves_the_newer_ship_observation(): void
    {
        $this->campaign(true);
        $this->world['ships'][0]['nav']['status'] = 'IN_ORBIT';
        $this->world['travel_seconds'] = 60;
        $this->seedWorld();
        $this->world['navigation_damage_receipt'] = 'failed refresh';
        $this->world['navigation_refresh_error'] = true;

        $run = $this->campaignRun('position', ['route' => ['hops' => [$this->hop(self::SOURCE, self::BUYER)], 'fuel_ceiling' => 50]]);
        $action = $this->action('navigate', ['waypointSymbol' => self::BUYER, 'hop_index' => 0], $run);
        $this->execute($action, 'uncertain');
        $this->assertTrue($action->outcome['response_validated']);

        $this->at(120);
        $this->world['ships'][0]['nav']['status'] = 'DOCKED';
        $this->world['ships'][0]['engine']['condition'] = 0.7;
        $newer = app(AgentSynchronizer::class)->saveShip($this->agent, $this->world['ships'][0]);
        $this->world['navigation_refresh_error'] = false;
        $this->world['defer_followup_after_write'] = true;

        $settled = app(ShipCommandExecutor::class)->reconcile($this->agent, $action);

        $this->assertSame('confirmed', $settled->state->value);
        $this->assertSame($newer->snapshot, $newer->fresh()->snapshot);
        $this->assertEquals($newer->observed_at, $newer->fresh()->observed_at);
        $this->assertSame('DOCKED', $this->fields()['nav']['value']['status']);
        $this->assertSame(0.7, $this->fields()['config']['value']['engine']['condition']);
        $this->assertCount(1, $this->world['writes']);
    }

    public function test_fleet_purchase_liquidity_uses_the_command_ships_remaining_trade_lot_exposure(): void
    {
        $this->campaign(true);
        $this->world['credits'] = 7291023;
        $this->seedWorld();
        $run = $this->campaignRun('trade', ['max_spend' => 7291599, 'legs' => [[
            'source' => self::SOURCE, 'buyer' => self::BUYER, 'good' => 'SHIP_PLATING',
            'approvedUnits' => 18, 'maxInventory' => 7291023, 'acquisition_cash' => 79098,
            'remainingFuelCeiling' => 576,
        ]]], ['phase' => 'buy', 'leg_purchase_cost' => [2000], 'leg_fuel_cost' => [20]]);
        app(StateChain::class)->reserveCredit($this->agent, 'run:'.$run->id, 'trade_lot', 'buy', 77098, runId: $run->id, shipSymbol: self::SHIP);

        $grant = $this->episode->grant();
        $policy = $grant->revision()->body['fleet']['haulers'];
        $expected = 77654;
        $bootstrap = app(CampaignProbeBootstrap::class);
        foreach (['SHIP_LIGHT_HAULER', 'SHIP_MINING_DRONE'] as $type) {
            $parts = $bootstrap->reserveParts($this->agent, $grant, $policy, incomingType: $type, episode: $this->episode);
            $this->assertSame($expected, $parts['commitments']);
        }
        $gate = app(CampaignSaturation::class)->evaluate($grant, $this->episode, $this->agent, offerIndependentOnly: true)['gates']['G5'];
        $this->assertTrue($gate['pass'], json_encode($gate));
        $this->assertSame($expected, $gate['reserve_components']['commitments']);
        $this->assertSame(7291023, $run->fresh()->plan['legs'][0]['maxInventory']);
    }

    public function test_mesh_arm_without_its_primary_grant_holds_before_reserving_budget(): void
    {
        $this->campaign(true);
        $this->seedWorld();
        $body = Yaml::parseFile(base_path('campaigns/arm-trade-fleet.yaml'));
        $revision = CampaignRevision::create([
            'name' => $body['name'], 'source' => Yaml::dump($body, 12), 'body' => $body,
            'content_hash' => hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)),
        ]);
        $arm = $this->armEpisode('LOCAL-2', $revision);
        $this->episode->grant()->update(['paused_at' => now()]);
        $run = $this->campaignRun('trade', ['mesh_work' => 'trade'], shipSymbol: 'LOCAL-2');
        $run->update(['campaign_episode_id' => $arm->id]);
        $budget = $this->partialMock(RequestBudget::class);
        $budget->shouldReceive('usage')->never();
        $budget->shouldReceive('refreshMesh')->never();
        $governor = app(MeshGovernor::class);
        $status = $governor->evaluate($this->agent, $arm, $run);
        $this->assertSame('hold', $status['mode']);
        $this->assertSame('saturation_grant_unavailable', $status['reason']);
        $this->assertSame(0, $status['room']);

        try {
            $governor->run($this->agent, $run, fn () => $this->fail('Mesh arm proceeded without its primary grant.'));
            $this->fail('Mesh arm did not defer.');
        } catch (RequestDeferred $exception) {
            $this->assertSame('saturation_grant_unavailable', $exception->delaySource);
        }
    }

    public function test_legacy_mesh_without_a_headroom_setting_degrades_at_ninety_percent(): void
    {
        $this->campaign(true);
        config(['gameplay.host_tier2_4_per_minute' => 100]);
        $this->episode->setRelation('policyRevision', new CampaignRevision(['body' => ['probes' => [
            'target' => 1, 'max_price' => 0, 'min_working_capital' => 0,
        ]]]));
        $usage = [
            'agent' => ['tier2_4_last_hour' => 4099],
            'mesh' => ['used' => 0, 'outstanding' => 0],
            'host' => ['tier2_4_last_60_seconds' => 0, 'rate_limits_last_15_minutes' => 0],
        ];
        $this->partialMock(RequestBudget::class)->shouldReceive('usage')->twice()->andReturn(
            $usage,
            ['agent' => ['tier2_4_last_hour' => 4100]] + $usage,
        );

        $governor = app(MeshGovernor::class);
        $this->assertSame('normal', $governor->evaluate($this->agent, $this->episode)['mode']);
        $status = $governor->evaluate($this->agent, $this->episode);
        $this->assertSame('degraded', $status['mode']);
        $this->assertSame('agent_hour_threshold', $status['reason']);
    }

    #[DataProvider('pricedBoundaries')]
    public function test_priced_action_is_rechecked_at_the_transport_boundary(string $case, bool $sends): void
    {
        $this->campaign(true);
        $this->world['ships'][0]['fuel']['current'] = 90;
        $this->seedWorld();
        $run = $this->campaignRun('position');
        $action = $this->action('refuel', ['units' => 10, 'maxTotal' => $case === 'above ceiling' ? 4 : 5], $run);
        if ($case !== 'above ceiling') {
            $this->afterWriteReservation(function () use ($case, $action, $run) {
                $this->assertSame('sending', $action->fresh()->state->value);
                $this->assertCount(1, $this->marketGets(self::SOURCE));
                match ($case) {
                    'ten seconds' => $this->at(10),
                    'eleven seconds' => $this->at(11),
                    'invalidated' => app(AgentSynchronizer::class)->invalidateMarket($this->agent, self::SOURCE, $action->id),
                    'expired' => $action->update(['expires_at' => now()->subSecond()]),
                    'cancelled' => app(AutomationRunner::class)->stop($run),
                };
            });
        }

        app(ShipCommandExecutor::class)->execute($action->id);

        $this->assertCount(1, $this->marketGets(self::SOURCE));
        $this->assertCount((int) $sends, $this->world['writes'], (string) $action->fresh()->message);
        $this->assertSame($sends, $action->fresh()->state->value === 'confirmed');
    }

    public static function pricedBoundaries(): array
    {
        return [
            'exact ten seconds' => ['ten seconds', true],
            'eleven seconds' => ['eleven seconds', false],
            'other writer' => ['invalidated', false],
            'expired' => ['expired', false],
            'cancelled' => ['cancelled', false],
            'price above approved maximum' => ['above ceiling', false],
        ];
    }

    #[DataProvider('continuationBoundaries')]
    public function test_cruise_continuation_is_revalidated_after_the_budget_wait(string $change, bool $sends): void
    {
        $this->campaign(true);
        $this->world['ships'][0]['nav']['status'] = 'IN_ORBIT';
        $this->seedWorld();
        $hop = array_replace($this->hop(self::SOURCE, self::BUYER), [
            'continuation' => self::BUYER, 'continuation_fuel' => 0, 'continuation_ceiling' => 10,
        ]);
        $run = $this->campaignRun('position', ['route' => ['hops' => [$hop], 'fuel_ceiling' => 10]]);
        $action = $this->action('navigate', ['waypointSymbol' => self::BUYER, 'hop_index' => 0], $run);
        $waited = false;
        $this->afterWriteReservation(function () use ($change, &$waited, $action) {
            $waited = true;
            $this->assertSame('sending', $action->fresh()->state->value);
            $observations = GameObservation::where('agent_id', $this->agent->id)->where('reset_id', $this->agent->reset_id)
                ->where('symbol', self::BUYER);
            $listing = (clone $observations)->where('kind', 'market_listing')->sole();
            $quote = (clone $observations)->where('kind', 'market')->sole();
            match ($change) {
                'no fuel' => $listing->update(['data->exchange' => [['symbol' => 'IRON_ORE']]]),
                'closed market' => $listing->delete(),
                'price increase' => $quote->update(['data->tradeGoods' => array_replace($quote->data['tradeGoods'], [
                    1 => array_replace($quote->data['tradeGoods'][1], ['purchasePrice' => 6]),
                ])]),
                'invalidated' => app(AgentSynchronizer::class)->invalidateMarket($this->agent, self::BUYER, $action->id),
                'too old' => $quote->update(['observed_at' => now()->subSeconds(config('gameplay.fuel_quote_max_seconds', 21600) + 1)]),
                'less cash' => $this->agent->update(['credits' => 9]),
                'none' => null,
            };
        });

        $this->execute($action, expected: null);

        $this->assertTrue($waited, (string) $action->message);
        $this->assertSame([], $this->marketGets(self::BUYER));
        $this->assertNotContains('/my/agent', array_column($this->world['calls'], 'path'));
        $this->assertCount((int) $sends, $this->world['writes'], (string) $action->message);
        $this->assertSame($sends ? 'confirmed' : 'rejected', $action->state->value, (string) $action->message);
        if (! $sends) {
            $this->assertNull($action->sent_at);
        }
    }

    public static function continuationBoundaries(): array
    {
        return [
            'seller stops carrying fuel' => ['no fuel', false],
            'seller is unavailable' => ['closed market', false],
            'fuel exceeds the pinned ceiling' => ['price increase', false],
            'observation generation changes' => ['invalidated', false],
            'quote ages out' => ['too old', false],
            'balance no longer covers fuel' => ['less cash', false],
            'funded seller remains valid' => ['none', true],
        ];
    }

    #[DataProvider('remoteFuelQuotes')]
    public function test_remote_cruise_continuation_requires_funding_for_stale_evidence_within_the_quote_limit(int $age, int $ceiling, bool $sends): void
    {
        config(['gameplay.fuel_quote_max_seconds' => 300]);
        $this->campaign(true);
        $this->world['ships'][0]['nav']['status'] = 'IN_ORBIT';
        $this->seedWorld();
        $this->world['ships'][2]['nav']['waypointSymbol'] = self::SOURCE;
        $this->world['priced_markets'] = [self::SOURCE];
        $hop = array_replace($this->hop(self::SOURCE, self::BUYER), [
            'continuation' => self::BUYER, 'continuation_fuel' => 0, 'continuation_ceiling' => $ceiling,
        ]);
        $run = $this->campaignRun('position', ['route' => ['hops' => [$hop], 'fuel_ceiling' => $ceiling]]);
        $this->at($age);
        $action = $this->action('navigate', ['waypointSymbol' => self::BUYER, 'hop_index' => 0], $run);

        $this->execute($action, expected: $sends ? 'confirmed' : 'rejected');

        $this->assertCount((int) $sends, $this->world['writes']);
    }

    public static function remoteFuelQuotes(): array
    {
        return [
            'fresh evidence remains funded' => [10, 10, true],
            'stale evidence gets the funded doubled ceiling' => [200, 20, true],
            'stale evidence exceeds a fresh-price ceiling' => [200, 10, false],
            'evidence older than the maximum is refused' => [301, 20, false],
        ];
    }

    #[DataProvider('creditInterleavings')]
    public function test_credit_intents_settle_in_order_or_wait_for_reconciliation(bool $split, string $firstOutcome): void
    {
        $this->campaign($split);
        $this->world['credits'] = 1000;
        $this->world['goods'][1]['purchasePrice'] = 80;
        $this->world['market_goods'][self::BUYER][1]['purchasePrice'] = 70;
        $this->world['ships'][0]['fuel']['current'] = 90;
        $this->world['ships'][2]['fuel']['current'] = 90;
        $this->seedWorld();
        $firstRun = $this->campaignRun('position');
        $secondRun = $this->campaignRun('position', shipSymbol: 'LOCAL-3');
        $first = $this->action('refuel', ['units' => 10, 'maxTotal' => 80], $firstRun, 'preview');
        $second = $this->action('refuel', ['units' => 10, 'maxTotal' => 70], $secondRun, 'preview');
        $executor = app(ShipCommandExecutor::class);
        $executor->locked($this->agent, function () use ($executor, $first, $second, $firstRun, $secondRun) {
            $executor->reserveCreditIntent($this->agent, $firstRun, $first);
            $executor->reserveCreditIntent($this->agent, $secondRun, $second);
            $this->assertSame($first->id, $executor->promoteCreditIntent($this->agent));
        });

        $executor->execute($second->id);
        $this->assertSame([], $this->world['writes']);
        $this->assertSame('preview', $second->fresh()->state->value);
        $this->afterWriteReservation(function () use ($firstOutcome, $second, $executor) {
            $this->assertBlocked(fn () => (new HostLock)->shared('agent-'.$this->agent->id, fn () => $this->fail('A reader entered while a credit writer owned the transport lock.')));
            $this->assertBlocked(fn () => (new HostLock)->exclusive('agent-'.$this->agent->id, fn () => $this->fail('A second writer entered while the first was waiting.')));
            $executor->execute($second->id);
            $this->assertSame([], $this->world['writes']);
            if ($firstOutcome === 'uncertain') {
                $this->world['failure'] = '503';
            }
            if ($firstOutcome === 'review') {
                $this->world['goods'][1]['purchasePrice'] = 100;
            }
        });
        $this->at(1);
        $executor->execute($first->id);
        $this->world['failure'] = null;

        if ($firstOutcome !== 'settled') {
            $this->assertSame($firstOutcome === 'uncertain', $first->fresh()->state->value === 'uncertain');
            $this->assertSame($firstOutcome === 'review', (bool) $first->fresh()->review_required);
            $this->assertNull($executor->locked($this->agent, fn () => $executor->promoteCreditIntent($this->agent)));
            $executor->execute($second->id);
            $this->assertCount(0, array_filter($this->world['calls'], fn ($call) => $call['method'] !== 'GET' && str_contains($call['path'], 'LOCAL-3')));
            $this->assertSame('reserved', $this->reservation($second)->state);

            return;
        }

        $this->assertSame(920, $this->agent->fresh()->credits, (string) $first->fresh()->message);
        $this->assertSame($second->id, $executor->locked($this->agent, fn () => $executor->promoteCreditIntent($this->agent)));
        $this->execute($second);
        $this->assertSame(850, $this->agent->fresh()->credits);
        $this->assertSame(['/my/ships/LOCAL-1/refuel', '/my/ships/LOCAL-3/refuel'], array_column($this->world['writes'], 'path'));
        $this->assertSame(-80, (int) $this->reservation($first)->settled_delta);
        $this->assertSame(-70, (int) $this->reservation($second)->settled_delta);

        $first->refresh();
        $before = $firstRun->fresh()->totals;
        $accounting = app(CreditAccounting::class);
        $accounting->apply($firstRun, $first, $first->outcome['ship'] ?? $first->before_state['ship'], $first->outcome, -80, 80);
        $accounting->record($this->agent, $first, -80);
        $executor->execute($first->id);
        $this->assertSame($before, $firstRun->fresh()->totals);
        $this->assertSame(850, $this->agent->fresh()->credits);
        $this->assertSame(1, DB::table('confirmed_credit_entries')->where('ship_action_id', $first->id)->count());
        $this->assertCount(2, $this->world['writes']);
    }

    public static function creditInterleavings(): array
    {
        return [
            'exclusive credit order' => [false, 'settled'],
            'dual-scope credit order' => [true, 'settled'],
            'uncertain first credit action' => [true, 'uncertain'],
            'price review after first receipt' => [true, 'review'],
        ];
    }

    public function test_acknowledging_applied_refuel_with_lost_response_unblocks_the_next_credit_write(): void
    {
        $this->campaign(true);
        $this->world['credits'] = 1000;
        $this->world['ships'][0]['fuel']['current'] = 90;
        $this->world['ships'][2]['fuel']['current'] = 90;
        $this->seedWorld();
        $run = $this->campaignRun('position');
        $action = $this->action('refuel', ['units' => 10, 'maxTotal' => 5], $run);
        $this->world['lose_refuel_response'] = true;
        $this->execute($action, expected: 'uncertain');
        $this->assertSame('uncertain', $action->state->value);
        $this->assertSame('uncertain', $this->reservation($action)->state);
        $this->assertSame(995, $this->world['credits']);

        $this->actingAs(User::findOrFail($this->agent->user_id))
            ->postJson(route('game.commands.reconcile', [$this->agent, $action]))
            ->assertAccepted()->assertJsonPath('outcome.reconciled_agent_credits', 995);
        $this->assertSame(1000, $this->agent->fresh()->credits);
        $this->postJson(route('game.commands.acknowledge', [$this->agent, $action]), [
            'acknowledge' => true, 'note' => 'Reviewed fuel and the provider balance.',
        ])->assertAccepted()->assertJsonPath('state', 'acknowledged');
        $this->assertSame('settled', $this->reservation($action)->state);
        $this->assertSame(-5, (int) $this->reservation($action)->settled_delta);
        $this->assertSame(995, $this->agent->fresh()->credits);
        $this->assertSame(0, app(CreditAccounting::class)->summary($this->agent)['unexplained_change']);
        $this->assertDatabaseHas('agent_balance_reviews', ['agent_id' => $this->agent->id, 'adjustment' => -5, 'observed_balance' => 995]);

        $next = $this->action('refuel', ['units' => 10, 'maxTotal' => 5], $this->campaignRun('position', shipSymbol: 'LOCAL-3'));
        $this->execute($next);
        $this->assertSame('confirmed', $next->state->value, (string) $next->message);
        $this->assertSame(990, $this->agent->fresh()->credits);
        $this->assertSame(0, app(CreditAccounting::class)->summary($this->agent)['unexplained_change']);
        $this->assertSame(['/my/ships/LOCAL-1/refuel', '/my/ships/LOCAL-3/refuel'], array_column($this->world['writes'], 'path'));
    }

    public function test_balance_review_adopts_provider_balance_and_cleans_up_old_acknowledged_reservation(): void
    {
        $this->campaign(true);
        $this->world['ships'][0]['fuel']['current'] = 90;
        $this->seedWorld();
        $run = $this->campaignRun('position');
        $action = $this->action('refuel', ['units' => 10, 'maxTotal' => 5], $run);
        $this->world['lose_refuel_response'] = true;
        $this->execute($action, expected: 'uncertain');
        $this->assertSame('uncertain', $this->reservation($action)->state);
        $action->update(['state' => 'acknowledged']);
        $run->update(['state' => 'stopped', 'active_agent_id' => null]);

        $summary = app(CreditAccounting::class)->review($this->agent, $this->world['credits'], 'Reviewed previously acknowledged refuel.', null);

        $this->assertSame(-5, $summary['reviewed_adjustments']);
        $this->assertSame(0, $summary['unexplained_change']);
        $this->assertSame($this->world['credits'], $this->agent->fresh()->credits);
        $this->assertSame('settled', $this->reservation($action)->state);
    }

    public function test_two_automation_runs_enter_and_execute_the_credit_queue_in_order(): void
    {
        config(['gameplay.inline_execute' => false]);
        $this->campaign(true);
        $this->world['ships'][0]['fuel']['current'] = 90;
        $this->world['ships'][2]['fuel']['current'] = 90;
        $this->seedWorld();
        $firstRun = $this->positionRefuelRun(self::SHIP, self::SOURCE, self::BUYER);
        $secondRun = $this->positionRefuelRun('LOCAL-3', self::BUYER, self::SOURCE);
        $this->advance($firstRun, execute: false);
        $this->advance($secondRun, execute: false);
        $first = ShipAction::where('automation_run_id', $firstRun->id)->sole();
        $second = ShipAction::where('automation_run_id', $secondRun->id)->sole();
        $this->assertSame('pending', $first->state->value);
        $this->assertSame('preview', $second->state->value);
        $this->assertSame('reserved', $this->reservation($second)->state);

        $this->execute($second, expected: 'preview');
        $this->assertSame([], $this->world['writes']);
        $this->execute($first);
        $this->assertSame('confirmed', $first->state->value, (string) $first->message);
        $this->advance($secondRun);
        $this->assertSame('confirmed', $second->fresh()->state->value, (string) $second->fresh()->message);
        $this->assertSame(['/my/ships/LOCAL-1/refuel', '/my/ships/LOCAL-3/refuel'], array_column($this->world['writes'], 'path'));
    }

    public function test_an_unbooked_manual_credit_intent_settles_before_an_automation_reservation_is_promoted(): void
    {
        config(['gameplay.inline_execute' => false]);
        $this->campaign(true);
        $this->world['ships'][0]['fuel']['current'] = 90;
        $this->world['ships'][2]['fuel']['current'] = 90;
        $this->seedWorld();
        $executor = app(ShipCommandExecutor::class);
        $manual = $executor->approve($this->agent, $executor->preview($this->agent, 'LOCAL-3', 'refuel',
            ['units' => 10, 'maxTotal' => 5], (string) Str::uuid()));
        $run = $this->positionRefuelRun(self::SHIP, self::SOURCE, self::BUYER);

        $this->advance($run, execute: false);
        $queued = ShipAction::where('automation_run_id', $run->id)->sole();
        $this->assertSame('preview', $queued->state->value);
        $this->assertSame('reserved', $this->reservation($queued)->state);
        $this->assertSame([], $this->world['writes']);
        $this->execute($manual);
        $this->advance($run);

        $this->assertSame('confirmed', $queued->fresh()->state->value, (string) $queued->fresh()->message);
        $this->assertSame(['/my/ships/LOCAL-3/refuel', '/my/ships/LOCAL-1/refuel'], array_column($this->world['writes'], 'path'));
    }

    public function test_lost_mesh_allowance_releases_the_unsent_intent_and_empty_runs_fuel_and_corridor_holds(): void
    {
        $this->campaign(true);
        $this->world['ships'][0]['fuel']['current'] = 90;
        $this->seedWorld();
        $run = $this->campaignRun('position', ['mesh_work' => 'position', 'mesh_allowance' => 'lost-allowance']);
        $action = $this->action('refuel', ['units' => 10, 'maxTotal' => 5], $run, 'preview');
        $chain = app(StateChain::class);
        $hold = $chain->reserveCredit($this->agent, 'run:'.$run->id, 'delivery_fuel', 'refuel', 50, runId: $run->id);
        $orders = $this->bookPartialTrade($run);
        $executor = app(ShipCommandExecutor::class);
        $executor->locked($this->agent, function () use ($executor, $run, $action) {
            $executor->reserveCreditIntent($this->agent, $run, $action);
            $this->assertSame($action->id, $executor->promoteCreditIntent($this->agent));
        });

        $this->execute($action, expected: 'rejected');

        $this->assertSame([], $this->world['writes']);
        $this->assertSame('rejected', $action->state->value, (string) $action->message);
        $this->assertTrue($action->outcome['confirmed_not_sent']);
        $this->assertNull($action->sent_at);
        $this->assertNull($run->fresh()->active_agent_id);
        $this->assertSame('released', $this->reservation($action)->state);
        $this->assertSame('released', DB::table('credit_reservations')->find($hold)->state);
        $this->assertSame('released', $this->bookOrder($orders[0])->state);
    }

    #[DataProvider('workerWorkloads')]
    public function test_legacy_and_manual_worker_transports_are_tagged(string $workload): void
    {
        $this->campaign(false);
        $this->seedWorld();
        if ($workload !== 'manual') {
            $run = $this->campaignRun($workload);
            $run->update(['plan_version' => 4, 'campaign_episode_id' => null]);
            $action = $this->action('orbit', [], $run);
        } else {
            $executor = app(ShipCommandExecutor::class);
            $action = $executor->approve($this->agent, $executor->preview($this->agent, self::SHIP, 'orbit', [], (string) Str::uuid()));
        }

        $this->execute($action);

        $this->assertSame([$workload], $this->world['write_workloads']);
    }

    public static function workerWorkloads(): iterable
    {
        foreach (['manual', 'buy_probe', 'buy_hauler', 'scout', 'trade', 'mining', 'sell_aboard'] as $workload) {
            yield $workload => [$workload];
        }
    }

    public function test_two_credit_reservations_cannot_spend_the_same_liquidity(): void
    {
        $this->campaign(true);
        $this->world['credits'] = 1000;
        $this->seedWorld();
        $firstRun = $this->campaignRun('position', ['reserve' => 900]);
        $secondRun = $this->campaignRun('position', ['reserve' => 900], shipSymbol: 'LOCAL-3');
        $first = $this->action('refuel', ['units' => 10, 'maxTotal' => 80], $firstRun, 'preview');
        $second = $this->action('refuel', ['units' => 10, 'maxTotal' => 70], $secondRun, 'preview');
        $executor = app(ShipCommandExecutor::class);

        $executor->locked($this->agent, function () use ($executor, $firstRun, $secondRun, $first, $second) {
            $executor->reserveCreditIntent($this->agent, $firstRun, $first);
            $this->assertBlocked(fn () => $executor->reserveCreditIntent($this->agent, $secondRun, $second));
            $this->assertSame($first->id, $executor->promoteCreditIntent($this->agent));
        });

        $this->assertSame('pending', $this->reservation($first)->state);
        $this->assertSame('preview', $second->fresh()->state->value);
        $this->assertSame(0, DB::table('credit_reservations')->where('ship_action_id', $second->id)->count());
        $this->assertSame([], $this->world['writes']);
    }

    #[DataProvider('scopedConflicts')]
    public function test_same_ship_and_lease_conflicts_block_campaign_sell_and_manual_refuel(string $conflict, string $state, string $ship, bool $late): void
    {
        $this->campaign(true);
        $this->loadShip($ship);
        $this->seedWorld();
        $executor = app(ShipCommandExecutor::class);
        $manual = $executor->preview($this->agent, $ship, 'refuel', ['units' => 10, 'maxTotal' => 5], 'manual-refuel');
        $inject = fn () => $conflict === 'action' ? $this->action('orbit', state: $state, ship: $ship) : $this->lease($state);

        if ($late) {
            $executor->approve($this->agent, $manual);
            $this->afterWriteReservation($inject);
            $executor->execute($manual->id);
        } else {
            $inject();
            $this->assertBlocked(fn () => $executor->approve($this->agent, $manual));
        }
        $this->assertCount(0, $this->world['writes']);
        $this->assertNotSame('confirmed', $manual->fresh()->state->value);

        ShipAction::whereKeyNot($manual->id)->where('command', 'orbit')->delete();
        DB::table('transfer_leases')->delete();
        $manual->update(['state' => 'rejected']);
        $run = $this->loadedRun($ship);
        if ($late) {
            $this->advance($run, execute: false);
            $sell = ShipAction::where('automation_run_id', $run->id)->where('command', 'sell')->sole();
            $this->afterWriteReservation(function () use ($sell, $inject) {
                $this->assertSame('sending', $sell->fresh()->state->value);
                $inject();
            });
            $executor->execute($sell->id);
            $this->assertNotSame('confirmed', $sell->fresh()->state->value);
        } else {
            $inject();
            $this->advance($run);
            $this->assertFalse(ShipAction::where('automation_run_id', $run->id)->where('command', 'sell')->exists());
        }

        $this->assertSame([], $this->world['writes']);
    }

    public static function scopedConflicts(): iterable
    {
        foreach (['pending', 'sending', 'uncertain'] as $state) {
            foreach ([false, true] as $late) {
                $boundary = $late ? 'transport' : 'scheduling';
                yield "same-ship $state at $boundary" => ['action', $state, self::SHIP, $late];
            }
        }
        foreach (['granted', 'sending', 'uncertain'] as $state) {
            foreach ([self::SHIP, 'LOCAL-2'] as $ship) {
                foreach ([false, true] as $late) {
                    $boundary = $late ? 'transport' : 'scheduling';
                    yield "$state lease holds $ship at $boundary" => ['lease', $state, $ship, $late];
                }
            }
        }
    }

    #[DataProvider('uncertaintyScopes')]
    public function test_campaign_health_safety_and_bridge_apply_the_same_uncertainty_scope(string $conflict, bool $split, ?string $reason): void
    {
        $this->campaign($split);
        $this->loadShip(self::SHIP);
        $this->loadShip('LOCAL-2');
        $this->seedWorld();
        $grant = $this->episode->grant();
        $otherGrant = CampaignGrant::create([
            'user_id' => $grant->user_id, 'agent_symbol' => 'SECOND', 'slot' => 1,
            'campaign_revision_id' => $grant->campaign_revision_id, 'revision_hash' => $grant->revision_hash,
            'limits' => $grant->limits, 'expires_at' => $grant->expires_at,
        ]);
        $executor = app(ShipCommandExecutor::class);
        $manuals = [];
        foreach ([self::SHIP, 'LOCAL-2'] as $ship) {
            $manuals[] = $executor->preview($this->agent, $ship, 'refuel', ['units' => 10, 'maxTotal' => 5], 'manual-'.$ship);
        }
        match ($conflict) {
            'non-credit' => $this->action('orbit', state: 'uncertain'),
            'transfer' => $this->action('transfer', ['targetShip' => 'LOCAL-2'], state: 'uncertain'),
            'credit' => $this->action('buy', state: 'uncertain'),
            'price review' => $this->action('sell', state: 'confirmed')->update(['review_required' => true]),
            'configuration' => $this->action('buy_ship', state: 'confirmed')->update(['review_required' => true, 'outcome' => ['configuration_mismatch' => true]]),
            'balance' => $this->agent->update(['credits' => $this->agent->credits + 100]),
        };
        if (in_array($conflict, ['non-credit', 'transfer'], true)) {
            $this->lease($conflict === 'transfer' ? 'uncertain' : 'granted');
        }

        $safety = app(CampaignSafety::class);
        $this->assertSame($reason, $safety->reason($grant, $this->episode, $this->agent->fresh()));
        $this->assertSame($reason === null ? [] : [$reason], array_column(app(BridgeGuards::class)->agentBlockers($this->agent->fresh()), 'code'));
        app(CampaignHealth::class)->evaluateKillSwitch();
        $this->assertSame($reason, $grant->fresh()->pause_reason);
        $this->assertNull($otherGrant->fresh()->paused_at);
        foreach ($manuals as $manual) {
            $this->assertBlocked(fn () => $executor->approve($this->agent->fresh(), $manual));
            $run = $this->loadedRun($manual->ship_symbol);
            $this->advance($run);
            $this->assertFalse(ShipAction::where('automation_run_id', $run->id)->where('command', 'sell')->exists());
        }
        $this->assertSame([], $this->world['writes']);

        $other = $this->campaignRun('position', [
            'route' => ['mode' => 'CRUISE', 'destination' => self::SOURCE, 'fuel_ceiling' => 0, 'hops' => [$this->hop(self::BUYER, self::SOURCE)]],
        ], shipSymbol: 'LOCAL-3');
        if ($reason === null) {
            $safety->check($other, $this->agent->fresh(), 'orbit');
            $executor->clear($this->agent->fresh(), defer: true, ship: 'LOCAL-3', command: 'orbit');
        }
        $this->advance($other);

        $this->assertSame($reason === null ? ['/my/ships/LOCAL-3/orbit'] : [], array_column($this->world['writes'], 'path'));
        $this->assertSame($reason !== null, $grant->fresh()->paused_at !== null);
    }

    public static function uncertaintyScopes(): array
    {
        return [
            'split non-credit uncertainty' => ['non-credit', true, null],
            'split uncertain transfer' => ['transfer', true, null],
            'exclusive non-credit uncertainty' => ['non-credit', false, 'uncertain_action'],
            'agent-wide credit uncertainty' => ['credit', true, 'uncertain_action'],
            'agent-wide price review' => ['price review', true, 'uncertain_action'],
            'agent-wide purchase configuration review' => ['configuration', true, 'uncertain_action'],
            'agent-wide unexplained balance' => ['balance', true, 'unexplained_change'],
        ];
    }

    public function test_idle_ledger_separates_friction_structural_and_capacity_with_sixty_seconds_of_pipeline_grace(): void
    {
        $result = app(IdleLedger::class)->evaluate(['T1' => [
            'from' => 0, 'to' => 600, 'busy' => [[0, 200], [260, 300], [361, 450]],
            'pipelined_gaps' => [[200, 260], [300, 361]],
            'reasons' => [[200, 361, 'handoff'], [450, 480, 'no_corridor_slot'], [480, 510, 'request_governor']],
        ]]);

        $this->assertSame([
            'eligible_seconds' => 600, 'busy_seconds' => 389, 'idle_seconds' => 211,
            'idle_share' => 211 / 600, 'friction_share' => 61 / 600,
            'structural_share' => .05, 'capacity_share' => .05, 'unattributed_share' => .15,
        ], $result['by_arm']['T1']);
    }

    #[DataProvider('compressedCycles')]
    public function test_compressed_trade_counts_each_transport_and_preserves_arrival_fields(int $seconds, int $batches, int $requests, bool $split): void
    {
        $this->campaign($split);
        $this->world['travel_seconds'] = $seconds;
        $this->world['ships'][0]['fuel']['current'] = 90;
        $this->seedWorld();
        $planner = app(OperationPlanner::class);
        $ship = $this->world['ships'][0];
        $outbound = $planner->cruiseRoute($this->agent, $ship, self::BUYER);
        $ship['nav']['waypointSymbol'] = self::BUYER;
        $ship['fuel']['current'] = $outbound['fuel_after'];
        $return = $planner->cruiseRoute($this->agent, $ship, self::SOURCE);
        $this->assertGreaterThan(0, $outbound['hops'][0]['continuation_ceiling']);
        $this->assertGreaterThan(0, $return['hops'][0]['continuation_ceiling']);
        $run = $this->tradeRun($batches * 10, array_fill(0, $batches, 10), extraLeg: [
            'source' => self::SOURCE, 'approachHopCount' => 1, 'hops' => $return['hops'], 'prediction' => ['predicted_cost' => 100],
        ]);
        $plan = $run->plan;
        $plan['legs'][0]['hops'] = $outbound['hops'];
        $run->update(['plan' => $plan]);
        $rules = app(CommandRules::class);
        $rules->shipForRun($this->agent, $run, force: true);
        $rules->refreshCredits($this->agent, $run, true, reconcile: true);

        $this->execute($this->action('refuel', ['units' => 10, 'maxTotal' => 5], $run));
        for ($index = 0; $index < $batches; $index++) {
            $this->advance($run);
        }
        $this->assertSame('deliver', $run->totals['phase']);
        $this->advance($run);
        $this->advance($run);
        $this->assertSame('IN_TRANSIT', $this->fields()['nav']['value']['status']);
        $this->at($seconds + 1);
        $this->advance($run);
        $arrival = $this->fields();
        $this->assertSame('DOCKED', $arrival['nav']['value']['status']);
        foreach (['cargo', 'fuel', 'cooldown', 'config', 'mounts', 'modules'] as $field) {
            $this->assertSame('ship_get', $arrival[$field]['source']);
            $this->assertSame($this->epoch->addSeconds($seconds + 1)->timestamp, CarbonImmutable::parse($arrival[$field]['observed_at'])->timestamp);
        }
        $this->at($seconds + 11);
        $this->afterWriteReservation(function (string $priority) use ($arrival) {
            $this->assertSame('settlement', $priority);
            $duringPreflight = $this->fields();
            foreach (['cargo', 'fuel', 'cooldown', 'config', 'mounts', 'modules'] as $field) {
                $this->assertSame($arrival[$field], $duringPreflight[$field]);
            }
        });
        $sale = null;
        for ($index = 0; $index < $batches; $index++) {
            $this->advance($run);
            $sale ??= ShipAction::where('automation_run_id', $run->id)->where('command', 'sell')->oldest('id')->firstOrFail();
        }
        $this->assertSame($batches * 10, $sale->before_state['ship']['cargo']['units']);
        foreach (['fuel', 'cooldown', 'config', 'mounts', 'modules'] as $field) {
            $this->assertSame($arrival[$field], $this->fields()[$field]);
        }
        $this->assertSame(1, $run->totals['leg_index']);
        $this->assertSame(100, DB::table('credit_reservations')->where('owner_key', 'run:'.$run->id)
            ->where('purpose', 'trade_lot')->whereNull('ship_action_id')->where('state', 'reserved')->sole()->exposure);
        $this->advance($run);
        $this->advance($run);
        $this->at(2 * $seconds + 12);
        $this->advance($run);

        $writes = 7 + 2 * $batches;
        $trace = array_map(fn ($call) => $call['method'].' '.$call['path'], $this->world['calls']);
        $this->assertCount(3, array_filter($trace, fn ($call) => $call === 'GET /my/ships/LOCAL-1'), json_encode($trace));
        $this->assertCount($writes, $this->world['writes'], json_encode($trace));
        $this->assertCount($requests, $trace, json_encode($trace));
        $this->assertCount($batches + 2, $this->marketGets(self::SOURCE), json_encode($trace));
        $this->assertCount($batches + 1, $this->marketGets(self::BUYER));
    }

    public static function compressedCycles(): iterable
    {
        foreach ([false, true] as $split) {
            $lock = $split ? 'split' : 'exclusive';
            yield "$lock 102s H52-A3 one batch" => [102, 1, 18, $split];
            yield "$lock 73s B34-B7 two batches" => [73, 2, 22, $split];
            yield "$lock 18s C39-C40 four batches" => [18, 4, 30, $split];
        }
    }

    #[DataProvider('acquisitionDeadlines')]
    public function test_partial_acquisition_expiring_in_budget_wait_becomes_one_pinned_settlement(string $command, int $deadline, bool $inline): void
    {
        config(['gameplay.inline_execute' => $inline]);
        $cutoff = ['acquisition_cutoff' => $this->epoch->addSeconds(1200)->toIso8601String()];
        $trade = $command === 'buy';
        $good = $command === 'siphon' ? 'HYDROCARBON' : 'IRON_ORE';
        $balance = $trade ? 600 : 0;
        $bought = $trade ? 60 : 10;
        if ($trade) {
            $this->campaign(true);
            $this->world['ships'][0] = FakeSpaceTraders::lightHauler(self::SHIP, self::SOURCE);
            $this->world['ships'][0]['registration']['role'] = 'COMMAND';
            $this->world['goods'][0]['purchasePrice'] = 10;
            $this->seedWorld();
            $run = $this->tradeRun(80, [60, 20], $cutoff, ['source' => self::BUYER, 'buyer' => self::SOURCE, 'hops' => [$this->hop(self::BUYER, self::SOURCE)]], $deadline);
        } else {
            $run = $this->extractionRun($command, $cutoff + ['route' => ['hops' => [$this->hop(self::SOURCE, self::BUYER)], 'fuel_ceiling' => 40]]);
        }
        $bookIds = $trade ? $this->bookPartialTrade($run) : [];
        $chain = app(StateChain::class);
        $owner = 'run:'.$run->id;
        $delivery = $chain->reserveCredit($this->agent, $owner, 'delivery', 'sell', 40, runId: $run->id);
        $fuel = $chain->reserveCredit($this->agent, $owner, 'delivery_fuel', 'refuel', 70, runId: $run->id);
        $this->advance($run);
        $this->assertSame([$good => $bought], $run->totals['unsold']);
        $this->assertSame(-$balance, $run->totals['credit_delta']);

        $this->at($deadline - 1);
        if ($trade) {
            $this->world['goods'][0]['purchasePrice'] = 25;
            $this->world['goods'][0]['sellPrice'] = 25;
        }
        $this->refreshMarkets();
        $this->afterWriteReservation(function () use ($run, $command, $trade, $deadline) {
            $inFlight = ShipAction::where('automation_run_id', $run->id)->where('command', $command)->where('state', 'sending')->sole();
            if ($trade) {
                $this->assertSame(500, (int) $this->reservation($inFlight)->exposure);
            }
            $this->at($deadline);
        });
        $this->advance($run);
        $path = ['buy' => 'purchase', 'mine' => 'extract', 'siphon' => 'siphon'][$command];
        $this->assertSame(['/my/ships/LOCAL-1/'.$path], array_column($this->world['writes'], 'path'));
        $this->assertSame('deliver', $run->totals['phase'], (string) $run->blocked_reason);
        $this->assertSame($deadline === 1200, isset($run->totals['acquisition_closed_at']));
        $this->assertSame([$good => $bought], $run->totals['unsold']);
        $refused = ShipAction::where('automation_run_id', $run->id)->where('command', $command)->where('state', 'rejected')->sole();
        $this->assertTrue($refused->outcome['confirmed_not_sent']);
        $this->assertNull($refused->sent_at);
        if ($trade) {
            $this->assertSame(['reason' => 'acquisition_deadline', 'units' => 60, 'purchase_cost' => 600], $run->totals['leg_acquisition_closed'][0]);
            $this->assertSame('released', $this->reservation($refused)->state);
            $this->assertSame(20, (int) $this->bookOrder($bookIds[1])->units);
            $this->assertSame('released', $this->bookOrder($bookIds[1])->state);
            $this->assertSame(60, (int) $this->bookOrder($bookIds[2])->units);
            $this->assertSame('reserved', $this->bookOrder($bookIds[2])->state);
            $this->assertSame($deadline === 1200 ? 'released' : 'reserved', $this->bookOrder($bookIds[3])->state);
        }
        foreach ([$delivery => 40, $fuel => 70] as $id => $exposure) {
            $hold = DB::table('credit_reservations')->find($id);
            $this->assertSame('reserved', $hold->state);
            $this->assertSame($exposure, (int) $hold->exposure);
        }

        $mark = $run->totals['leg_acquisition_closed'][0] ?? $run->totals['acquisition_closed_at'];
        $this->advance($run);
        $this->advance($run);
        $this->at($deadline + 1);
        $this->advance($run);
        $salePriority = null;
        $this->afterWriteReservation(function (string $priority) use ($run, &$salePriority) {
            $this->assertTrue(ShipAction::where('automation_run_id', $run->id)->where('command', 'sell')->where('state', 'sending')->exists());
            $salePriority = $priority;
        });
        $this->advance($run);
        $this->assertSame('settlement', $salePriority);
        $this->assertSame($mark, $run->totals['leg_acquisition_closed'][0] ?? $run->totals['acquisition_closed_at']);
        $this->assertSame([], $run->totals['unsold'], (string) $run->blocked_reason);
        $sale = ShipAction::where('automation_run_id', $run->id)->where('command', 'sell')->where('state', 'confirmed')->sole();
        $this->assertSame($bought, $sale->arguments['units']);
        $this->assertSame(self::BUYER, $sale->before_state['ship']['nav']['waypointSymbol']);
        $this->assertSame(1, count(array_filter($this->world['writes'], fn ($write) => str_ends_with($write['path'], '/sell'))));
        $this->assertSame(1, count(array_filter($this->world['writes'], fn ($write) => str_ends_with($write['path'], '/'.$path))));
        if ($trade) {
            config(['gameplay.inline_execute' => false]);
            $this->advance($run, execute: false);
            $this->assertSame(1, $run->totals['leg_index']);
            $hasNewBuy = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy')->where('arguments->leg_index', 1)->exists();
            $this->assertSame($deadline === 1100, $hasNewBuy, (string) $run->blocked_reason);
        }
    }

    public static function acquisitionDeadlines(): iterable
    {
        foreach ([false, true] as $inline) {
            $mode = $inline ? 'inline' : 'queued';
            yield "$mode buy at latestBuyAt" => ['buy', 1100, $inline];
            yield "$mode buy at cutoff" => ['buy', 1200, $inline];
            yield "$mode mine at cutoff" => ['mine', 1200, $inline];
            yield "$mode siphon at cutoff" => ['siphon', 1200, $inline];
        }
    }

    public function test_loaded_extraction_stops_at_settlement_minus_delivery_seconds_during_budget_wait(): void
    {
        $run = $this->extractionRun('mine', [
            'effective_acquisition_cutoff' => $this->epoch->addSeconds(80)->toIso8601String(),
            'acquisition_cutoff' => $this->epoch->addSeconds(90)->toIso8601String(),
            'settlement_deadline' => $this->epoch->addSeconds(100)->toIso8601String(),
            'delivery_seconds' => 50,
        ]);
        $first = $this->action('mine', [], $run);
        $this->execute($first);
        $this->assertSame('confirmed', $first->state->value);
        $this->assertSame(['IRON_ORE' => 10], $run->fresh()->totals['unsold']);
        $this->at(49);
        $second = $this->action('mine', [], $run);
        $this->afterWriteReservation(fn () => $this->at(50));

        $this->execute($second, expected: 'rejected');

        $this->assertCount(1, $this->world['writes']);
        $this->assertTrue($second->outcome['confirmed_not_sent']);
        $this->assertNull($second->sent_at);
        $run->refresh();
        $this->assertSame('deliver', $run->totals['phase']);
        $this->assertSame(['IRON_ORE' => 10], $run->totals['unsold']);
    }

    #[DataProvider('deliveryCommands')]
    public function test_delivery_intent_cannot_transport_after_the_current_trade_legs_deadline(string $command): void
    {
        $this->campaign(true);
        $this->loadShip(self::SHIP);
        $this->world['ships'][0]['nav']['waypointSymbol'] = $command === 'sell' ? self::BUYER : self::SOURCE;
        $this->world['ships'][0]['nav']['status'] = $command === 'sell' ? 'DOCKED' : 'IN_ORBIT';
        $this->seedWorld();
        $run = $this->tradeRun(10, [10], ['initial_inventory' => []]);
        $plan = $run->plan;
        $plan['legs'][0]['deliveryDeadline'] = $this->epoch->addSeconds(10)->toIso8601String();
        $run->update(['plan' => $plan, 'totals' => array_replace($run->totals, [
            'phase' => 'deliver', 'leg_purchased' => [10], 'leg_purchase_cost' => [100], 'hop_index' => $command === 'sell' ? 1 : 0,
        ])]);
        $this->at(9);
        $arguments = $command === 'sell'
            ? ['symbol' => 'IRON_ORE', 'units' => 10, 'minTotal' => 110]
            : ['waypointSymbol' => self::BUYER, 'hop_index' => 0];
        $action = $this->action($command, $arguments + ['leg_index' => 0, 'phase' => 'deliver'], $run);
        $waited = false;
        $this->afterWriteReservation(function () use (&$waited) {
            $waited = true;
            $this->at(10);
        });

        $this->execute($action, expected: 'rejected');

        $this->assertTrue($waited, (string) $action->message);
        $this->assertSame([], $this->world['writes']);
        $this->assertSame('rejected', $action->state->value);
        $this->assertStringContainsString('Delivery deadline reached', $action->message);
        $this->assertNull($action->sent_at);
        $this->assertSame(['IRON_ORE' => 10], $run->fresh()->totals['unsold']);
    }

    public static function deliveryCommands(): array
    {
        return ['sell at the buyer' => ['sell'], 'navigate with the lot' => ['navigate']];
    }

    public function test_empty_closed_trade_leg_preserves_and_flies_the_displaced_next_legs_approach(): void
    {
        config(['gameplay.inline_execute' => false]);
        $this->campaign(true);
        $this->world['ships'][0] = FakeSpaceTraders::lightHauler(self::SHIP, self::SOURCE);
        $this->world['ships'][0]['registration']['role'] = 'COMMAND';
        $this->seedWorld();
        $run = $this->tradeRun(80, [60, 20], extraLeg: [
            'source' => self::BUYER, 'buyer' => self::SOURCE, 'approachHopCount' => 1,
            'hops' => [$this->hop(self::SOURCE, self::BUYER), $this->hop(self::BUYER, self::SOURCE)],
        ], latest: 10);
        $bookIds = $this->bookPartialTrade($run);
        $this->at(9);
        $action = $this->action('buy', ['symbol' => 'IRON_ORE', 'units' => 60, 'maxTotal' => 600, 'leg_index' => 0], $run);
        $this->afterWriteReservation(fn () => $this->at(10));

        $this->execute($action, expected: 'rejected');

        $this->assertSame([], $this->world['writes']);
        $this->assertTrue($action->outcome['confirmed_not_sent']);
        $run->refresh();
        $this->assertSame(1, $run->totals['leg_index']);
        $this->assertSame('buy', $run->totals['phase']);
        $this->assertSame('running', $run->state->value);
        $this->assertArrayNotHasKey('acquisition_closed_at', $run->totals);
        $this->assertSame(0, $run->totals['leg_acquisition_closed'][0]['units']);
        $this->assertSame('released', $this->bookOrder($bookIds[0])->state);
        $this->assertSame('reserved', $this->bookOrder($bookIds[3])->state);

        $this->advance($run);
        $this->advance($run);
        $navigation = ShipAction::where('automation_run_id', $run->id)->where('command', 'navigate')->sole();
        $this->assertSame('confirmed', $navigation->state->value, (string) $navigation->message);
        $this->assertSame(self::BUYER, $navigation->arguments['waypointSymbol']);
        $this->assertSame(1, $navigation->arguments['leg_index']);
        $this->assertSame(['/my/ships/LOCAL-1/orbit', '/my/ships/LOCAL-1/navigate'], array_column($this->world['writes'], 'path'));
    }

    public function test_deadline_rejects_the_proven_unsent_action_even_when_another_unknown_prevents_closing_the_leg(): void
    {
        $this->campaign(true);
        $this->seedWorld();
        $run = $this->tradeRun(10, [10], latest: 10);
        $this->at(9);
        $action = $this->action('buy', ['symbol' => 'IRON_ORE', 'units' => 10, 'maxTotal' => 100, 'leg_index' => 0], $run);
        $this->afterWriteReservation(function () use ($run) {
            $this->action('orbit', [], $run, 'uncertain');
            $this->at(10);
        });

        $this->execute($action, expected: 'rejected');

        $this->assertSame([], $this->world['writes']);
        $this->assertTrue($action->outcome['confirmed_not_sent']);
        $this->assertNull($action->sent_at);
        $this->assertSame('released', $this->reservation($action)->state);
        $this->assertArrayNotHasKey('acquisition_closed_at', $run->fresh()->totals);
        $this->assertSame($this->agent->id, $run->fresh()->active_agent_id);
    }

    #[DataProvider('driftBoundaries')]
    public function test_drift_positioning_restores_cruise_after_arrival_or_a_refused_navigation(string $case): void
    {
        $late = in_array($case, ['late dispatch', 'expired intent'], true);
        $lateArrival = $case === 'late receipt';
        $this->campaign(true);
        $this->world['ships'][0]['nav']['status'] = 'IN_ORBIT';
        $this->world['ships'][0]['engine']['speed'] = 10;
        $this->world['waypoints'][1]['x'] = 240;
        $this->world['waypoints'][1]['y'] = 0;
        $this->world['travel_seconds'] = $lateArrival ? 6016 : 6015;
        $this->world['fuel_consumption'] = 1;
        $this->seedWorld();
        $router = app(RoutePlanner::class);
        $deadline = $this->epoch->addSeconds(7815);
        $ship = $this->world['ships'][0];
        $route = $router->planDrift($this->agent, $ship, self::BUYER, $deadline);
        $this->assertSame(6015, $route['estimated_seconds']);
        $this->assertBlocked(fn () => $router->planDrift($this->agent, $ship, self::BUYER, $deadline->subSecond()));

        $runner = app(AutomationRunner::class);
        $run = $runner->preview($this->agent, self::SHIP, [
            'plan_version' => 8, 'strategy' => 'position', 'mode' => 'DRIFT', 'destination' => self::BUYER,
            'settlement_deadline' => $deadline->toIso8601String(), 'route' => $route,
            'reserve' => 0, 'max_fuel' => 10, 'max_spend' => 100, 'max_loss' => 100,
        ], $this->episode);
        $runner->start($this->agent, $run);
        $this->assertGreaterThan(5400, $this->epoch->diffInSeconds($run->expires_at));
        $this->advance($run);
        $this->assertSame('DRIFT', $this->fields()['nav']['value']['flightMode']);
        if ($late) {
            $this->afterWriteReservation(function () use ($run, $case) {
                $pending = ShipAction::where('automation_run_id', $run->id)->where('command', 'navigate')->where('state', 'sending')->sole();
                if ($case === 'expired intent') {
                    $pending->update(['expires_at' => now()->subSecond()]);
                }
                $this->at(1);
            });
        }

        $this->advance($run);
        if ($late) {
            $failed = ShipAction::where('automation_run_id', $run->id)->where('command', 'navigate')->sole();
            $this->assertSame('rejected', $failed->state->value);
            $this->assertNull($failed->sent_at);
            $this->assertArrayNotHasKey('expired_unsent', $failed->outcome);
            $this->assertSame('cleanup', $run->totals['position_phase']);
        } else {
            $this->assertSame('IN_TRANSIT', $this->fields()['nav']['value']['status']);
            $this->advance($run);
            $this->assertSame('DRIFT', $this->fields()['nav']['value']['flightMode']);
            $this->at($this->world['travel_seconds'] + 1);
        }
        $this->advance($run);
        $this->advance($run);

        $writes = $late ? ['/my/ships/LOCAL-1/nav', '/my/ships/LOCAL-1/nav']
            : ['/my/ships/LOCAL-1/nav', '/my/ships/LOCAL-1/navigate', '/my/ships/LOCAL-1/nav'];
        $this->assertSame($writes, array_column($this->world['writes'], 'path'), (string) $run->blocked_reason);
        $this->assertSame(['DRIFT', 'CRUISE'], array_column(array_column($this->world['writes'], 'arguments'), 'flightMode'));
        $this->assertSame('CRUISE', $this->fields()['nav']['value']['flightMode']);
        $this->assertSame($late ? self::SOURCE : self::BUYER, $this->fields()['nav']['value']['waypointSymbol']);
        $this->assertSame('IN_ORBIT', $this->fields()['nav']['value']['status']);
        if ($lateArrival) {
            $navigation = ShipAction::where('automation_run_id', $run->id)->where('command', 'navigate')->sole();
            $this->assertTrue($navigation->review_required);
            $this->assertSame('blocked', $run->state->value);
        } else {
            $this->assertNull($run->active_agent_id);
        }
        $this->assertCount($late ? 1 : 2, array_filter($this->shipTrace(), fn ($request) => $request === 'GET /my/ships/LOCAL-1'));
    }

    public static function driftBoundaries(): array
    {
        return [
            '6015s conservative arrival exactly on bound' => ['exact'],
            'one second late after switching to DRIFT' => ['late dispatch'], ['expired intent'],
            'sent arrival exceeds the conservative bound' => ['late receipt'],
        ];
    }

    public function test_sk70_cruise_routes_preserve_fuel_and_pick_the_lexicographic_tie(): void
    {
        $this->seedSk70();
        $router = app(RoutePlanner::class);
        $ship = $this->world['ships'][0];
        $paths = [];
        $topUps = [];
        foreach (['J59', 'H52', 'E46'] as $target) {
            $route = $router->planCruise($this->agent, $ship, 'X1-SK70-'.$target);
            $paths[] = $this->sk70Path($route);
            foreach ($route['hops'] as $hop) {
                if ($hop['topped_up']) {
                    $topUps[] = [substr($hop['seller'], 8), $hop['topped_up'], $hop['fuel_cost'], $hop['refuel_ceiling']];
                }
            }
            $ship['nav']['waypointSymbol'] = 'X1-SK70-'.$target;
            $ship['fuel']['current'] = $route['fuel_after'];
        }

        $this->assertSame([['E46', 'I57', 'J59'], ['J59', 'I56', 'H52'], ['H52', 'E46']], $paths);
        $this->assertSame([
            ['I57', 279, 216, 864],
            ['J59', 495, 360, 864],
            ['I56', 273, 216, 864],
            ['H52', 497, 360, 864],
        ], $topUps);
        $this->assertSame(497, $ship['fuel']['current']);

        $ship['nav']['waypointSymbol'] = 'X1-SK70-J59';
        $ship['fuel']['current'] = 600;
        $tied = $router->planCruise($this->agent, $ship, 'X1-SK70-H52');
        app(AgentSynchronizer::class)->invalidateMarket($this->agent, 'X1-SK70-I56', 1);
        $alternate = $router->planCruise($this->agent, $ship, 'X1-SK70-H52');
        $this->assertSame(['J59', 'I56', 'H52'], $this->sk70Path($tied));
        $this->assertSame(['J59', 'I57', 'H52'], $this->sk70Path($alternate));
        $this->assertSame($tied['seconds'], $alternate['seconds']);
    }

    public function test_cruise_origin_top_up_stale_ceiling_outage_and_unaffordable_continuation(): void
    {
        $this->seedSk70();
        $router = app(RoutePlanner::class);
        $ship = $this->world['ships'][0];
        $ship['fuel']['current'] = 10;
        $fresh = $router->planCruise($this->agent, $ship, 'X1-SK70-H52');
        $this->assertSame('X1-SK70-E46', $fresh['hops'][0]['seller']);
        $this->assertSame(590, $fresh['hops'][0]['topped_up']);
        $this->assertSame(864, $fresh['hops'][0]['refuel_ceiling']);
        GameObservation::where('kind', 'market')->where('symbol', 'X1-SK70-E46')->sole()->update(['observed_at' => now()->subSeconds(901)]);
        $fallback = $router->planCruise($this->agent, $ship, 'X1-SK70-H52');
        $this->assertTrue($fallback['hops'][0]['stale_fallback']);
        $this->assertSame(1728, $fallback['hops'][0]['refuel_ceiling']);

        $ship['fuel']['current'] = 600;
        $this->assertBlocked(fn () => $router->planCruise($this->agent, $ship, 'X1-SK70-DRY', $this->agent->credits - 1));

        $ship['nav']['waypointSymbol'] = 'X1-SK70-I57';
        $approach = $router->planCruise($this->agent, $ship, 'X1-SK70-J59');
        $this->assertSame(['I57', 'J59'], $this->sk70Path($approach));
        $this->assertSame(105, $approach['fuel_after']);
        app(AgentSynchronizer::class)->invalidateMarket($this->agent, 'X1-SK70-J59', 2);
        try {
            $changed = $router->planCruise($this->agent, $ship, 'X1-SK70-J59');
        } catch (GameException) {
            $changed = null;
        }
        $this->assertNotSame(['I57', 'J59'], $changed === null ? null : $this->sk70Path($changed));
    }

    private function seedSk70(): void
    {
        // Coordinates from the acceptance audit. DRY is a synthetic non-market continuation test.
        $this->world['waypoints'] = [];
        foreach (['E46' => [-46, -30], 'I57' => [-189, -128], 'I56' => [-372, -253], 'J58' => [-496, -336],
            'J59' => [-596, -405], 'H52' => [45, 12], 'DRY' => [-50, -30]] as $suffix => [$x, $y]) {
            $this->world['waypoints'][] = [
                'symbol' => 'X1-SK70-'.$suffix, 'systemSymbol' => 'X1-SK70', 'type' => 'PLANET',
                'x' => $x, 'y' => $y, 'traits' => $suffix === 'DRY' ? [] : [['symbol' => 'MARKETPLACE']],
                'modifiers' => [],
            ];
        }
        $this->world['priced_markets'] = array_column($this->world['waypoints'], 'symbol');
        $this->world['goods'][1]['purchasePrice'] = 72;
        $this->world['ships'][0] = FakeSpaceTraders::lightHauler(self::SHIP, 'X1-SK70-E46');
        $this->world['ships'][0]['nav']['systemSymbol'] = 'X1-SK70';
        $this->world['ships'][0]['fuel']['current'] = 497;
        $this->seedWorld();
    }

    private function sk70Path(array $route): array
    {
        return array_map(fn ($waypoint) => substr($waypoint, 8), $route['path']);
    }

    #[DataProvider('startBands')]
    public function test_trade_start_applies_soft_bands_and_at_most_one_local_recomposition(int $ask, int $bid, bool $recompose): void
    {
        $this->campaign(true);
        $this->world['goods'][0]['purchasePrice'] = 100;
        $this->world['market_goods'][self::BUYER][0]['sellPrice'] = 200;
        $this->world['ships'][2]['fuel']['current'] = 90;
        $this->seedWorld();
        $runner = app(AutomationRunner::class);
        $preview = $runner->preview($this->agent, self::SHIP, [
            'plan_version' => 8, 'strategy' => 'trade', 'site' => self::SOURCE, 'market' => self::BUYER,
            'symbol' => 'IRON_ORE', 'units' => 10, 'reserve' => 0, 'max_inventory' => 5000,
            'max_fuel' => 100, 'max_spend' => 5000, 'max_loss' => 5000,
        ], $this->episode);
        $approved = $preview->plan['legs'][0];
        $outside = $this->campaignRun('position', shipSymbol: 'LOCAL-3');
        $this->execute($this->action('refuel', ['units' => 10, 'maxTotal' => 5], $outside));
        $this->world['goods'][0]['purchasePrice'] = $ask;
        $this->world['market_goods'][self::BUYER][0]['sellPrice'] = $bid;
        $this->refreshMarkets();
        $this->world['calls'] = [];

        $runner->start($this->agent, $preview);
        $runner->start($this->agent, $preview);
        $this->assertSame('running', $preview->fresh()->state->value);
        $this->assertSame(99995, $preview->plan['initial_credits']);
        $this->assertSame(['GET /my/agent', 'GET /my/ships/LOCAL-1'], array_map(fn ($call) => $call['method'].' '.$call['path'], $this->world['calls']));
        $this->assertSame($recompose, isset($preview->plan['start_recomposed_at']));
        $this->assertSame($recompose ? 1 : 0, $preview->plan['plan_revision'] ?? 0);
        $this->assertSame($recompose ? $ask : $approved['quoteEvidence']['ask']['purchasePrice'], $preview->plan['legs'][0]['quoteEvidence']['ask']['purchasePrice']);
        $this->assertSame(self::BUYER, $preview->plan['legs'][0]['buyer']);
    }

    public static function startBands(): array
    {
        return [
            'exact price boundaries and profit within 2000 CR with another ship debit' => [105, 190, false],
            'positive but outside soft ask band' => [106, 190, true],
        ];
    }

    public function test_trade_start_reserves_the_lot_before_either_ship_queues_a_purchase(): void
    {
        $this->campaign(true);
        $this->world['credits'] = 180;
        $this->world['goods'][0]['tradeVolume'] = 5;
        $this->seedWorld();
        $armEpisode = $this->armEpisode('LOCAL-2');
        $runner = app(AutomationRunner::class);
        $limits = [
            'plan_version' => 8, 'strategy' => 'trade', 'site' => self::SOURCE, 'market' => self::BUYER,
            'symbol' => 'IRON_ORE', 'units' => 10, 'reserve' => 0, 'max_inventory' => 5000,
            'max_fuel' => 100, 'max_spend' => 5000, 'max_loss' => 5000, 'proofMode' => 'scaled',
        ];
        $first = $runner->preview($this->agent, self::SHIP, $limits, $this->episode);
        $second = $runner->preview($this->agent, 'LOCAL-2', $limits, $armEpisode);
        $runner->startCampaign($this->agent, $first, $this->episode);
        $hold = DB::table('credit_reservations')->where('owner_key', 'run:'.$first->id)
            ->where('purpose', 'trade_lot')->whereNull('ship_action_id')->sole();
        $this->assertSame(100, $hold->exposure);
        $this->assertSame('reserved', $hold->state);
        $this->assertSame(0, ShipAction::where('command', 'buy')->count());

        try {
            $runner->startCampaign($this->agent, $second, $armEpisode);
            $this->fail('The competing run booked cash already reserved for the first lot.');
        } catch (GameException $exception) {
            $this->assertStringContainsString('Insufficient unreserved cash', $exception->getMessage());
        }
        $this->assertSame('stopped', $second->fresh()->state->value);
        $this->assertCount(0, $this->world['writes']);

        $this->advance($first, execute: false);
        $buy = ShipAction::where('automation_run_id', $first->id)->where('command', 'buy')->sole();
        $reservation = $this->reservation($buy);
        $this->assertSame($hold->owner_key, $reservation->owner_key);
        $this->assertSame('trade_lot', $reservation->purpose);
        $this->assertSame(100 + $first->plan['route']['fuel_ceiling'], app(StateChain::class)->outstandingExposure($this->agent));
        $this->execute($buy);
        $this->assertSame(50, DB::table('credit_reservations')->find($hold->id)->exposure);
        $this->advance($first);
        $this->assertSame('released', DB::table('credit_reservations')->find($hold->id)->state);
    }

    #[DataProvider('manualRefuelFunding')]
    public function test_manual_refuel_preserves_a_v8_trade_reserve_and_counts_each_booked_hold_once(int $credits, bool $loaded): void
    {
        $this->campaign(true);
        $this->world['credits'] = $credits;
        $this->world['ships'][2]['fuel']['current'] = 90;
        $this->seedWorld();
        $runner = app(AutomationRunner::class);
        $executor = app(ShipCommandExecutor::class);
        $refuel = $executor->preview($this->agent, 'LOCAL-3', 'refuel', ['units' => 10, 'maxTotal' => 5], (string) Str::uuid());
        $preview = $runner->preview($this->agent, self::SHIP, [
            'plan_version' => 8, 'strategy' => 'trade', 'site' => self::SOURCE, 'market' => self::BUYER,
            'symbol' => 'IRON_ORE', 'units' => 10, 'reserve' => 60, 'max_inventory' => 5000,
            'max_fuel' => 100, 'max_spend' => 5000, 'max_loss' => 5000,
        ], $this->episode);
        $run = $runner->startCampaign($this->agent, $preview, $this->episode);
        $holds = DB::table('credit_reservations')->where('owner_key', 'run:'.$run->id)->where('state', 'reserved');
        $this->assertSame(['delivery_fuel' => 10, 'trade_lot' => 100], $holds->orderBy('purpose')->pluck('exposure', 'purpose')->all());
        $this->assertSame(60, $run->plan['reserve']);

        if ($loaded) {
            $this->advance($run);
            $this->assertSame(['IRON_ORE' => 10], $run->totals['unsold']);
            $this->assertSame(10, app(StateChain::class)->outstandingExposure($this->agent));
            $this->assertSame(75, $this->world['credits']);
            $this->world['writes'] = [];
        }
        $refuel->update(['state' => 'pending']);
        $this->execute($refuel, $loaded ? 'confirmed' : 'rejected');

        if (! $loaded) {
            $this->assertStringContainsString('protected reserve', $refuel->message);
        }
        $this->assertSame($loaded ? 70 : 170, $this->world['credits']);
        $this->assertSame($loaded ? ['/my/ships/LOCAL-3/refuel'] : [], array_column($this->world['writes'], 'path'));
    }

    public static function manualRefuelFunding(): array
    {
        return [
            'reserve and unopened lot leave no headroom' => [170, false],
            'loaded trade retains one delivery fuel hold' => [175, true],
        ];
    }

    #[DataProvider('fleetPurchaseRefuelPrices')]
    public function test_manual_refuel_counts_booked_credits_once_during_a_fleet_purchase(int $price, string $expected): void
    {
        $this->campaign(true);
        $this->world['credits'] = 225;
        $this->world['ships'][2]['fuel']['current'] = 90;
        $this->world['market_goods'][self::BUYER][1]['purchasePrice'] = $price;
        $this->loadShip('LOCAL-2');
        $this->seedWorld();
        $chain = app(StateChain::class);
        $hold = $chain->reserveCredit($this->agent, 'recovery:LOCAL-2', 'delivery_fuel', 'refuel', 20, shipSymbol: 'LOCAL-2');
        $executor = app(ShipCommandExecutor::class);
        $refuel = $executor->preview($this->agent, 'LOCAL-3', 'refuel', ['units' => 10, 'maxTotal' => $price], (string) Str::uuid());
        $executor->approve($this->agent, $refuel);
        $this->campaignRun('buy_fleet', ['reserve' => 220, 'reserve_parts' => ['working_capital' => 200, 'commitments' => 20]]);

        $this->execute($refuel, $expected);

        if ($expected === 'rejected') {
            $this->assertStringContainsString('protected reserve', $refuel->message);
        }
        $this->assertSame($expected === 'confirmed' ? 220 : 225, $this->world['credits']);
        $this->assertSame($expected === 'confirmed' ? ['/my/ships/LOCAL-3/refuel'] : [], array_column($this->world['writes'], 'path'));
        $this->assertSame('reserved', DB::table('credit_reservations')->find($hold)->state);
    }

    public static function fleetPurchaseRefuelPrices(): array
    {
        return ['inside the funded floor' => [5, 'confirmed'], 'below the funded floor' => [6, 'rejected']];
    }

    #[DataProvider('fleetPurchaseRefuelPrices')]
    public function test_manual_refuel_counts_unbooked_legacy_recovery_fuel_once_during_a_fleet_purchase(int $price, string $expected): void
    {
        $this->campaign(true);
        $this->world['credits'] = 300015;
        $this->world['ships'][2]['fuel']['current'] = 90;
        $this->world['market_goods'][self::SOURCE] = $this->world['goods'];
        $this->world['market_goods'][self::BUYER][1]['purchasePrice'] = $price;
        $this->loadShip(self::SHIP);
        $this->seedWorld();
        $recovery = $this->campaignRun('sell_aboard', ['market' => self::SOURCE]);
        $recovery->update(['plan_version' => 4]);
        $this->assertSame(0, app(StateChain::class)->outstandingExposure($this->agent));
        $grant = $this->episode->grant();
        $parts = app(CampaignProbeBootstrap::class)->reserveParts($this->agent, $grant, $grant->revision()->body['fleet']['haulers'],
            incomingType: 'SHIP_LIGHT_HAULER', episode: $this->episode);
        $this->assertSame(10, $parts['commitments']);
        $this->assertSame(300010, array_sum($parts));
        $this->campaignRun('buy_fleet', ['reserve' => array_sum($parts), 'reserve_parts' => $parts], shipSymbol: 'LOCAL-2');

        $this->assertManualRefuelPreservesFloor(300010, $price, $expected);
    }

    #[DataProvider('fleetPurchaseReconRefuelPrices')]
    public function test_manual_refuel_counts_live_recon_return_once_during_a_fleet_purchase(int $exposure, int $price, string $expected): void
    {
        $this->campaign(true);
        $floor = 300000 + max(20, $exposure);
        $this->world['credits'] = $floor + 5;
        $this->world['ships'][1]['nav']['systemSymbol'] = 'X9-AWAY';
        $this->world['ships'][1]['nav']['waypointSymbol'] = 'X9-AWAY-PORT';
        $this->world['ships'][2]['fuel']['current'] = 90;
        $this->world['market_goods'][self::BUYER][1]['purchasePrice'] = $price;
        $this->seedWorld();
        $terms = ['return_deadline' => now()->addHours(2)->toIso8601String(), 'envelope' => 1000, 'jump_exposure' => 100];
        $mission = ReconMission::create([
            'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-2',
            'state' => 'exploring', 'terms' => $terms, 'terms_hash' => ReconMission::hash($terms), 'graph' => [],
            'origin_system' => 'X1-TEST', 'origin_gate' => self::SOURCE, 'approved_by' => $this->agent->user_id,
            'approved_at' => now(), 'start_window_until' => now()->addHour(), 'visits' => [], 'capability' => [],
            'return_reservation' => ['cursor' => 0, 'caps' => [20], 'total' => 20, 'path' => []], 'homeward_authorizations' => [],
        ]);
        $grant = $this->episode->grant();
        $parts = app(CampaignProbeBootstrap::class)->reserveParts($this->agent, $grant, $grant->revision()->body['fleet']['haulers'],
            incomingType: 'SHIP_LIGHT_HAULER', episode: $this->episode);
        $this->assertSame(0, $parts['commitments']);
        $this->assertSame(20, $parts['recon_return']);
        $this->assertSame(300020, array_sum($parts));
        $this->campaignRun('buy_fleet', ['reserve' => array_sum($parts), 'reserve_parts' => $parts]);
        $mission->update(['return_reservation' => ['cursor' => 0, 'caps' => [$exposure], 'total' => $exposure, 'path' => []]]);
        $this->assertSame($exposure, ReconMission::returnExposure($this->agent));

        $this->assertManualRefuelPreservesFloor($floor, $price, $expected);
    }

    public static function fleetPurchaseReconRefuelPrices(): array
    {
        return [
            'unchanged exposure at floor' => [20, 5, 'confirmed'],
            'unchanged exposure below floor' => [20, 6, 'rejected'],
            'increased exposure at floor' => [35, 5, 'confirmed'],
            'increased exposure below floor' => [35, 6, 'rejected'],
            'cleared exposure at original floor' => [0, 5, 'confirmed'],
            'cleared exposure below original floor' => [0, 6, 'rejected'],
        ];
    }

    private function assertManualRefuelPreservesFloor(int $floor, int $price, string $expected): void
    {
        $executor = app(ShipCommandExecutor::class);
        if ($expected === 'confirmed') {
            $refuel = $executor->preview($this->agent, 'LOCAL-3', 'refuel', ['units' => 10, 'maxTotal' => $price], (string) Str::uuid());
            $this->assertSame($floor, $refuel->before_state['estimate']['reserve']);
            $executor->approve($this->agent, $refuel);
        } else {
            $refuel = $this->action('refuel', ['units' => 10, 'maxTotal' => $price], ship: 'LOCAL-3');
        }
        $this->execute($refuel, $expected);
        if ($expected === 'rejected') {
            $this->assertStringContainsString('protected reserve', $refuel->message);
        }
        $this->assertSame($expected === 'confirmed' ? $floor : $floor + 5, $this->world['credits']);
        $this->assertSame($expected === 'confirmed' ? ['/my/ships/LOCAL-3/refuel'] : [], array_column($this->world['writes'], 'path'));
    }

    public function test_manual_refuel_counts_a_legacy_recovery_runs_booked_fuel_once(): void
    {
        $this->campaign(true);
        $this->world['credits'] = 31;
        $this->world['ships'][2]['fuel']['current'] = 90;
        $this->loadShip(self::SHIP);
        $this->seedWorld();
        $recovery = $this->campaignRun('sell_aboard', ['market' => self::BUYER]);
        $recovery->update(['plan_version' => 4]);
        $chain = app(StateChain::class);
        $chain->reserveCredit($this->agent, 'run:'.$recovery->id, 'delivery_fuel', 'refuel', 10, runId: $recovery->id, shipSymbol: self::SHIP);
        $chain->reserveCredit($this->agent, 'run:'.$recovery->id, 'trade_lot', 'buy', 15, runId: $recovery->id, shipSymbol: self::SHIP);
        $executor = app(ShipCommandExecutor::class);
        $refuel = $executor->preview($this->agent, 'LOCAL-3', 'refuel', ['units' => 10, 'maxTotal' => 5], (string) Str::uuid());
        $this->assertSame(26, $refuel->before_state['estimate']['reserve']);
        $this->execute($executor->approve($this->agent, $refuel));
        $this->assertSame(26, $this->world['credits']);
        $this->assertSame(25, $chain->outstandingExposure($this->agent));
    }

    public function test_trade_timing_requires_five_comparable_full_runs(): void
    {
        $this->campaign(true);
        $this->seedWorld();
        $sample = [
            'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => self::SHIP,
            'strategy' => 'trade', 'plan_version' => 8, 'state' => 'completed', 'expires_at' => now(),
        ];
        $leg = ['approvedUnits' => 10];
        $full = ['cycles' => 1, 'leg_sold' => [10]];
        foreach ([200, 210, 220, 230] as $seconds) {
            AutomationRun::create($sample + ['plan' => ['legs' => [$leg]], 'totals' => $full + ['elapsed_seconds' => $seconds]]);
        }
        AutomationRun::create($sample + ['plan' => ['legs' => [$leg]], 'totals' => $full + ['elapsed_seconds' => 700, 'empty_cutoff_exit' => true]]);
        AutomationRun::create($sample + ['plan' => ['legs' => [$leg]], 'totals' => ['cycles' => 0, 'elapsed_seconds' => 800]]);
        AutomationRun::create($sample + ['plan' => ['legs' => [$leg, $leg]], 'totals' => ['cycles' => 1, 'leg_sold' => [10, 10], 'elapsed_seconds' => 850]]);
        $limits = [
            'plan_version' => 8, 'strategy' => 'trade', 'site' => self::SOURCE, 'market' => self::BUYER,
            'symbol' => 'IRON_ORE', 'units' => 10, 'reserve' => 0, 'max_inventory' => 5000,
            'max_fuel' => 100, 'max_spend' => 5000, 'max_loss' => 5000,
        ];
        $runner = app(AutomationRunner::class);
        $preview = $runner->preview($this->agent, self::SHIP, $limits, $this->episode);
        $this->assertArrayNotHasKey('elapsed_p90_seconds', $preview->plan);
        $this->assertSame($this->epoch->addSeconds(900)->toIso8601String(), $preview->plan['acquisition_cutoff']);

        AutomationRun::create($sample + ['plan' => ['legs' => [$leg]], 'totals' => $full + ['elapsed_seconds' => 240]]);
        $plan = $runner->startCampaign($this->agent, $preview, $this->episode)->plan;
        $this->assertSame(240, $plan['elapsed_p90_seconds']);
        $this->assertSame($this->epoch->addSeconds(300)->toIso8601String(), $plan['acquisition_cutoff']);
    }

    public function test_trade_chain_timing_excludes_a_partial_lot_followed_by_a_skipped_leg(): void
    {
        $this->campaign(true);
        $this->seedWorld();
        $legs = array_fill(0, 2, ['source' => self::SOURCE, 'buyer' => self::BUYER, 'good' => 'IRON_ORE', 'approvedUnits' => 10]);
        $sample = [
            'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => self::SHIP,
            'strategy' => 'trade', 'plan_version' => 8, 'state' => 'completed', 'expires_at' => now(), 'plan' => ['legs' => $legs],
        ];
        $full = ['cycles' => 1, 'leg_sold' => [10, 10]];
        foreach ([420, 430, 440, 450] as $seconds) {
            AutomationRun::create($sample + ['totals' => $full + ['elapsed_seconds' => $seconds]]);
        }
        $partialTotals = [
            'cycles' => 1, 'elapsed_seconds' => 700, 'leg_index' => 2, 'leg_sold' => [4],
        ];
        AutomationRun::create($sample + ['totals' => $partialTotals + [
            'leg_acquisition_closed' => [
                ['reason' => 'acquisition_deadline', 'units' => 4, 'purchase_cost' => 40],
                ['reason' => 'acquisition_deadline', 'units' => 0, 'purchase_cost' => 0],
            ],
        ]]);
        $limits = [
            'plan_version' => 8, 'strategy' => 'trade', 'legs' => $legs, 'reserve' => 0,
            'max_inventory' => 5000, 'max_fuel' => 100, 'max_spend' => 5000, 'max_loss' => 5000,
        ];
        $runner = app(AutomationRunner::class);
        $preview = $runner->preview($this->agent, self::SHIP, $limits, $this->episode);
        $this->assertArrayNotHasKey('elapsed_p90_seconds', $preview->plan);

        AutomationRun::create($sample + ['totals' => $partialTotals]);
        $preview = $runner->preview($this->agent, self::SHIP, $limits, $this->episode);
        $this->assertArrayNotHasKey('elapsed_p90_seconds', $preview->plan);

        AutomationRun::create($sample + ['totals' => $full + ['elapsed_seconds' => 460]]);
        $preview = $runner->preview($this->agent, self::SHIP, $limits, $this->episode);
        $this->assertSame(460, $preview->plan['elapsed_p90_seconds']);
    }

    public function test_priced_sell_rejects_a_prefetched_quote_from_another_waypoint_and_checks_its_own_floor(): void
    {
        $this->campaign(true);
        $this->loadShip(self::SHIP);
        $this->world['goods'][0]['sellPrice'] = 80;
        $this->world['market_goods'][self::BUYER][0]['sellPrice'] = 10;
        $this->seedWorld();
        $run = $this->loadedRun(self::SHIP);
        $remote = GameObservation::where('kind', 'market')->where('symbol', self::SOURCE)->sole();
        $sale = $this->action('sell', ['symbol' => 'IRON_ORE', 'units' => 10, 'minTotal' => 200], $run);
        $sale->update(['before_state' => ['price_preflight' => ['id' => $remote->id, 'version' => $remote->data['observation_version']]]]);

        app(ShipCommandExecutor::class)->execute($sale->id);

        $this->assertCount(0, $this->marketGets(self::SOURCE));
        $this->assertCount(1, $this->marketGets(self::BUYER));
        $this->assertCount(0, $this->world['writes']);
        $this->assertSame('rejected', $sale->fresh()->state->value);
        $this->assertStringContainsString('below the approved minimum', $sale->fresh()->message);
    }

    public function test_fresh_shipyard_price_above_the_approved_ceiling_prevents_purchase_transport(): void
    {
        $this->campaign(true);
        $this->seedWorld();
        $run = $this->campaignRun('buy_fleet', ['shipyard' => self::SOURCE, 'max_spend' => 30000, 'max_loss' => 30000]);
        $offer = $this->world['offer'];
        $this->world['offer']['purchasePrice'] = 22000;
        $purchase = $this->action('buy_ship', [
            'waypointSymbol' => self::SOURCE, 'shipType' => $offer['type'], 'maxTotal' => $offer['purchasePrice'],
            'offer_configuration_hash' => ShipCommandExecutor::configurationHash($offer),
        ], $run);

        app(ShipCommandExecutor::class)->execute($purchase->id);

        $this->assertCount(1, array_filter($this->world['calls'], fn ($call) => $call['method'] === 'GET' && str_ends_with($call['path'], '/shipyard')));
        $this->assertCount(0, $this->world['writes']);
        $this->assertSame('rejected', $purchase->fresh()->state->value);
        $this->assertStringContainsString('approved spending ceiling', $purchase->fresh()->message);
    }

    #[DataProvider('cutoffOwnership')]
    public function test_cutoff_closes_empty_work_but_keeps_unknown_acquisition_owned(?string $state): void
    {
        $run = $this->extractionRun('mine', ['acquisition_cutoff' => $this->epoch->addSeconds(1200)->toIso8601String()]);
        $hold = app(StateChain::class)->reserveCredit($this->agent, 'run:'.$run->id, 'delivery_fuel', 'refuel', 70, runId: $run->id);
        if ($state !== null) {
            $this->advance($run);
            $unfinished = $this->action('mine', run: $run, state: $state);
            $unfinished->update(['sent_at' => now()]);
        }
        $this->at(1200);
        $this->advance($run);
        $this->advance($run);

        $this->assertSame($state === null ? 'completed' : 'running', $run->state->value);
        $this->assertSame($state === null ? null : $this->agent->id, $run->active_agent_id);
        $this->assertSame($state === null ? 'released' : 'reserved', DB::table('credit_reservations')->find($hold)->state);
        $this->assertSame($state === null ? [] : ['IRON_ORE' => 10], $run->totals['unsold']);
        $this->assertSame($state === null, $run->totals['empty_cutoff_exit'] ?? false);
        $this->assertSame(0, $run->totals['cycles'] ?? 0);
        $this->assertCount($state === null ? 0 : 1, $this->world['writes']);
        $this->assertSame(0, AutomationRun::where('strategy', 'sell_aboard')->count());
    }

    public static function cutoffOwnership(): array
    {
        return ['empty' => [null], 'sending' => ['sending'], 'uncertain' => ['uncertain']];
    }

    public function test_settlement_expiry_preserves_the_fuel_needed_for_recovery(): void
    {
        $this->campaign(true);
        $this->world['credits'] = 120;
        $this->world['travel_seconds'] = 18;
        $this->world['ships'][0]['fuel']['current'] = 1;
        $this->world['ships'][1]['fuel']['current'] = 90;
        $this->seedWorld();
        $run = $this->tradeRun(10, [10], [
            'settlement_deadline' => $this->epoch->addSeconds(20)->toIso8601String(), 'route' => ['fuel_ceiling' => 20],
        ]);
        $fuel = app(StateChain::class)->reserveCredit($this->agent, 'run:'.$run->id, 'delivery_fuel', 'refuel', 20,
            runId: $run->id, shipSymbol: self::SHIP);
        $this->advance($run);
        $this->assertSame(['IRON_ORE' => 10], $run->totals['unsold']);
        $this->assertSame(20, $this->agent->fresh()->credits);

        $this->at(20);
        $this->advance($run);
        $this->assertSame('stopped', $run->state->value);
        $hold = DB::table('credit_reservations')->find($fuel);
        $this->assertSame('recovery:'.self::SHIP, $hold->owner_key);
        $this->assertNull($hold->automation_run_id);
        $this->assertSame('reserved', $hold->state);
        $this->assertSame(20, app(StateChain::class)->outstandingExposure($this->agent));

        $competitor = $this->action('refuel', ['units' => 10, 'maxTotal' => 5], ship: 'LOCAL-2');
        $this->execute($competitor, 'rejected');
        $this->assertStringContainsString('protected reserve', $competitor->message);
        $this->assertCount(1, $this->world['writes']);

        $this->refreshMarkets();
        $runner = app(AutomationRunner::class);
        $recovery = $runner->previewRecovery($this->agent, self::SHIP, self::BUYER, [
            'recovery_of' => $run->id, 'minutes' => 10, 'reserve' => 0, 'max_spend' => 20, 'max_loss' => 20,
        ], $this->episode);
        $runner->startCampaign($this->agent, $recovery, $this->episode);
        $hold = DB::table('credit_reservations')->find($fuel);
        $this->assertSame('run:'.$recovery->id, $hold->owner_key);
        $this->assertSame($recovery->id, $hold->automation_run_id);
        $this->assertSame(20, $hold->exposure);
        $this->advance($recovery);
        $this->assertSame(5, $recovery->totals['fuel_cost'], (string) $recovery->blocked_reason);
        $this->assertSame(15, DB::table('credit_reservations')->find($fuel)->exposure);
        $this->advance($recovery);
        $this->advance($recovery);
        $this->at(39);
        $this->advance($recovery);
        $this->advance($recovery);
        $this->advance($recovery);
        $this->assertSame('completed', $recovery->state->value, (string) $recovery->blocked_reason);
        $this->assertSame([], $recovery->totals['unsold']);
        $this->assertSame('released', DB::table('credit_reservations')->find($fuel)->state);
        $this->assertSame(0, app(StateChain::class)->outstandingExposure($this->agent));
        $this->assertSame([
            '/my/ships/LOCAL-1/purchase', '/my/ships/LOCAL-1/refuel', '/my/ships/LOCAL-1/orbit',
            '/my/ships/LOCAL-1/navigate', '/my/ships/LOCAL-1/dock', '/my/ships/LOCAL-1/sell',
        ], array_column($this->world['writes'], 'path'));
    }

    public function test_manual_sale_releases_recovery_fuel_only_after_the_last_units_sell(): void
    {
        $this->campaign(true);
        $this->loadShip(self::SHIP);
        $this->seedWorld();
        $chain = app(StateChain::class);
        $hold = $chain->reserveCredit($this->agent, 'recovery:'.self::SHIP, 'delivery_fuel', 'refuel', 20, shipSymbol: self::SHIP);
        $executor = app(ShipCommandExecutor::class);

        $partial = $executor->preview($this->agent, self::SHIP, 'sell', ['symbol' => 'IRON_ORE', 'units' => 4, 'minTotal' => 120], (string) Str::uuid());
        $this->execute($executor->approve($this->agent, $partial));
        $this->assertSame(6, $this->fields()['cargo']['value']['units']);
        $this->assertSame('reserved', DB::table('credit_reservations')->find($hold)->state);
        $this->assertSame(20, $chain->outstandingExposure($this->agent));

        $last = $executor->preview($this->agent, self::SHIP, 'sell', ['symbol' => 'IRON_ORE', 'units' => 6, 'minTotal' => 180], (string) Str::uuid());
        $this->execute($executor->approve($this->agent, $last));
        $this->assertSame(0, $this->fields()['cargo']['value']['units']);
        $this->assertSame('released', DB::table('credit_reservations')->find($hold)->state);
        $this->assertSame(0, $chain->outstandingExposure($this->agent));
    }

    #[DataProvider('scrapResponses')]
    public function test_scrapping_releases_recovery_fuel_after_ship_removal_is_resolved(bool $lostResponse): void
    {
        $this->campaign(true);
        $this->loadShip(self::SHIP);
        $this->world['ships'][0]['nav']['waypointSymbol'] = self::SOURCE;
        $this->seedWorld();
        $chain = app(StateChain::class);
        $hold = $chain->reserveCredit($this->agent, 'recovery:'.self::SHIP, 'delivery_fuel', 'refuel', 20, shipSymbol: self::SHIP);
        $executor = app(ShipCommandExecutor::class);
        $scrap = $executor->preview($this->agent, self::SHIP, 'scrap', [
            'confirmShip' => self::SHIP, 'acknowledgeLoss' => true, 'minTotal' => 100,
        ], (string) Str::uuid());
        $executor->approve($this->agent, $scrap);
        $this->world['lose_scrap_response'] = $lostResponse;
        $this->execute($scrap, $lostResponse ? 'uncertain' : 'confirmed');

        if ($lostResponse) {
            $executor->reconcile($this->agent, $scrap);
            $this->assertFalse($scrap->outcome['actor_present']);
            $this->assertSame('reserved', DB::table('credit_reservations')->find($hold)->state);
            $executor->acknowledgeUncertain($this->agent, $scrap, 'Reviewed removed ship.');
            $this->assertSame('acknowledged', $scrap->state->value);
        }

        $this->assertDatabaseMissing('ships', ['agent_id' => $this->agent->id, 'symbol' => self::SHIP]);
        $this->assertSame('released', DB::table('credit_reservations')->find($hold)->state);
        $this->assertSame(0, $chain->outstandingExposure($this->agent));
        $this->assertSame(['/my/ships/'.self::SHIP.'/scrap'], array_column($this->world['writes'], 'path'));
    }

    public static function scrapResponses(): array
    {
        return ['confirmed receipt' => [false], 'lost response and reconciled disappearance' => [true]];
    }

    #[DataProvider('reconciledCargo')]
    public function test_acknowledging_a_lost_cargo_response_rechecks_recovery_fuel(string $command, int $units, ?string $blocker, string $expected, bool $shipAbsent = false): void
    {
        $this->campaign(true);
        $this->loadShip(self::SHIP);
        $this->world['ships'][1]['nav']['waypointSymbol'] = self::BUYER;
        $this->seedWorld();
        $chain = app(StateChain::class);
        $hold = $chain->reserveCredit($this->agent, 'recovery:'.self::SHIP, 'delivery_fuel', 'refuel', 20, shipSymbol: self::SHIP);
        $executor = app(ShipCommandExecutor::class);
        $arguments = ['symbol' => 'IRON_ORE', 'units' => $units] + match ($command) {
            'sell' => ['minTotal' => 30 * $units],
            'jettison' => ['acknowledgeLoss' => true],
            'transfer' => ['targetShip' => 'LOCAL-2'],
        };
        $action = $executor->preview($this->agent, self::SHIP, $command, $arguments, (string) Str::uuid());
        $executor->approve($this->agent, $action);
        $this->world['lose_'.$command.'_response'] = true;
        $this->execute($action, 'uncertain');
        if ($shipAbsent) {
            array_shift($this->world['ships']);
        }
        if ($blocker === 'credit') {
            $this->action('buy', state: 'uncertain');
        } elseif ($blocker === 'recovery') {
            $this->campaignRun('sell_aboard');
        }

        $executor->reconcile($this->agent, $action);
        $this->assertSame(! $shipAbsent, $action->outcome['actor_present']);
        $this->assertSame($shipAbsent ? null : 10 - $units, $action->outcome['ship']['cargo']['units'] ?? null);
        $this->assertSame('reserved', DB::table('credit_reservations')->find($hold)->state);
        $executor->acknowledgeUncertain($this->agent, $action, 'Reviewed remaining cargo.');
        $this->assertSame('acknowledged', $action->state->value);
        $this->assertSame($expected, DB::table('credit_reservations')->find($hold)->state);
        if ($blocker === 'recovery') {
            $this->assertSame('stopped', AutomationRun::where('strategy', 'sell_aboard')->sole()->state->value);
        }
        $this->assertSame(['/my/ships/'.self::SHIP.'/'.$command], array_column($this->world['writes'], 'path'));
    }

    public static function reconciledCargo(): array
    {
        return [
            'sale empties cargo' => ['sell', 10, null, 'released'],
            'sale leaves partial cargo' => ['sell', 4, null, 'reserved'],
            'another uncertain credit action' => ['sell', 10, 'credit', 'reserved'],
            'acknowledgment stops active recovery' => ['sell', 10, 'recovery', 'released'],
            'ship disappears after sale' => ['sell', 4, null, 'released', true],
            'jettison empties cargo' => ['jettison', 10, null, 'released'],
            'transfer empties cargo' => ['transfer', 10, null, 'released'],
        ];
    }

    #[DataProvider('lostRecoverySaleCargo')]
    public function test_acknowledging_a_lost_recovery_sale_settles_claimed_delivery_fuel(int $version, int $saleUnits): void
    {
        $this->campaign(true);
        $this->loadShip(self::SHIP);
        $this->world['ships'][0]['nav']['waypointSymbol'] = self::SOURCE;
        $this->world['ships'][0]['fuel']['current'] = 1;
        $this->world['ships'][1]['fuel']['current'] = 90;
        $this->world['travel_seconds'] = 18;
        $this->world['market_goods'][self::BUYER][0]['tradeVolume'] = $saleUnits;
        $this->seedWorld();
        $this->refreshMarkets();
        $chain = app(StateChain::class);
        $hold = $chain->reserveCredit($this->agent, 'recovery:'.self::SHIP, 'delivery_fuel', 'refuel', 20, shipSymbol: self::SHIP);
        $runner = app(AutomationRunner::class);
        $limits = ['plan_version' => $version, 'minutes' => 10, 'reserve' => 0, 'max_spend' => 20, 'max_loss' => 20];
        $recovery = $runner->previewRecovery($this->agent, self::SHIP, self::BUYER, $limits, $this->episode);
        $runner->startCampaign($this->agent, $recovery, $this->episode);
        $this->assertSame($version, $recovery->plan_version);
        $this->assertSame('run:'.$recovery->id, DB::table('credit_reservations')->find($hold)->owner_key);
        $this->assertSame(20, DB::table('credit_reservations')->find($hold)->exposure);

        $this->advance($recovery);
        $this->assertSame(5, $recovery->totals['fuel_cost'], (string) $recovery->blocked_reason);
        $this->assertSame(15, DB::table('credit_reservations')->find($hold)->exposure);
        $this->advance($recovery);
        $this->advance($recovery);
        $this->at(39);
        $this->world['lose_sell_response'] = true;
        $this->advance($recovery);
        $this->advance($recovery);
        $sale = ShipAction::where('automation_run_id', $recovery->id)->where('command', 'sell')->sole();
        $this->assertSame('uncertain', $sale->state->value, (string) $sale->message);
        $this->assertTrue(AutomationRunner::hasTradeCargo($recovery));
        $executor = app(ShipCommandExecutor::class);
        $executor->reconcile($this->agent, $sale);
        $remaining = 10 - $saleUnits;
        $this->assertSame($remaining, $sale->outcome['ship']['cargo']['units']);
        $this->assertSame('reserved', DB::table('credit_reservations')->find($hold)->state);

        $executor->acknowledgeUncertain($this->agent, $sale, 'Reviewed the sale and remaining cargo.');

        $this->assertSame('acknowledged', $sale->state->value);
        $this->assertSame('stopped', $recovery->fresh()->state->value);
        $fuel = DB::table('credit_reservations')->find($hold);
        if ($remaining === 0) {
            $this->assertSame('released', $fuel->state);
            $this->assertSame(0, $chain->outstandingExposure($this->agent));
        } else {
            $this->assertSame('recovery:'.self::SHIP, $fuel->owner_key);
            $this->assertNull($fuel->automation_run_id);
            $this->assertSame(self::SHIP, $fuel->ship_symbol);
            $this->assertSame('reserved', $fuel->state);
            $this->assertSame(15, $chain->outstandingExposure($this->agent));
            $this->world['ships'][0]['cargo']['units'] = 0;
            $this->world['ships'][0]['cargo']['inventory'] = [];
            app(AgentSynchronizer::class)->refreshShip($this->agent, self::SHIP, fresh: true);
            $this->assertSame('released', DB::table('credit_reservations')->find($hold)->state);
            $this->assertSame(0, $chain->outstandingExposure($this->agent));
        }
    }

    public static function lostRecoverySaleCargo(): array
    {
        return [
            'v8 empty cargo' => [8, 10],
            'v8 remaining cargo' => [8, 4],
            'v4 empty cargo' => [4, 10],
            'v4 remaining cargo' => [4, 4],
        ];
    }

    public function test_acknowledging_a_purchase_with_conflicting_evidence_keeps_the_booked_capacity(): void
    {
        $this->campaign(true);
        $this->seedWorld();
        $run = $this->tradeRun(10, [10]);
        $book = app(CorridorBook::class);
        $corridor = ['market' => self::SOURCE, 'good' => 'IRON_ORE', 'side' => 'buy'];
        [$order] = $book->reserveOrders($this->agent, [$corridor + [
            'owner_type' => 'run', 'owner_id' => (string) $run->id, 'units' => 10,
            'landing_at' => now()->timestamp, 'tie_key' => 'conflicting-purchase',
        ]]);
        $this->at(10);
        $this->refreshMarkets();
        $this->advance($run, execute: false);
        $buy = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy')->sole();
        $this->assertSame($buy->id, $this->bookOrder($order)->ship_action_id);
        $this->world['purchase_receipt_units'] = 1;
        $this->execute($buy, 'uncertain');
        $this->assertSame('uncertain', $this->bookOrder($order)->state);
        $this->assertTrue($buy->outcome['response_validated']);
        $this->assertSame(1, $buy->outcome['response']['transaction']['units']);

        $executor = app(ShipCommandExecutor::class);
        $executor->reconcile($this->agent, $buy);
        $this->assertSame('uncertain', $buy->state->value);
        $this->assertSame(10, $buy->outcome['ship']['cargo']['units']);
        $executor->acknowledgeUncertain($this->agent, $buy);

        $this->assertSame('acknowledged', $buy->state->value);
        $this->assertSame('settled', $this->bookOrder($order)->state);
        $key = self::SOURCE.'|IRON_ORE|buy';
        $nextOrder = $corridor + ['units' => 79, 'landing_at' => now()->timestamp + 1, 'tie_key' => 'next-order'];
        $this->assertFalse($book->fitsWindow($book->activeEvents($this->agent, [$key]), [$nextOrder], [$key => 80], depthBatches: 1));
        $this->assertSame(10, $this->bookOrder($order)->units);
    }

    #[TestWith(['transaction'])]
    #[TestWith(['pricePerUnit'])]
    public function test_acknowledging_an_unvalidated_purchase_response_keeps_the_booked_capacity(string $omittedField): void
    {
        $this->campaign(true);
        $this->seedWorld();
        $run = $this->tradeRun(10, [10]);
        $book = app(CorridorBook::class);
        $corridor = ['market' => self::SOURCE, 'good' => 'IRON_ORE', 'side' => 'buy'];
        [$order] = $book->reserveOrders($this->agent, [$corridor + [
            'owner_type' => 'run', 'owner_id' => (string) $run->id, 'units' => 20,
            'landing_at' => now()->timestamp, 'tie_key' => 'unvalidated-purchase',
        ]]);
        $this->at(10);
        $this->refreshMarkets();
        $this->advance($run, execute: false);
        $buy = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy')->sole();
        $this->assertSame($buy->id, $this->bookOrder($order)->ship_action_id);

        $this->world['purchase_omitted_field'] = $omittedField;
        $this->execute($buy, 'uncertain');
        $this->assertSame('uncertain', $this->bookOrder($order)->state);
        $this->assertArrayNotHasKey('response_validated', $buy->outcome);
        $this->assertNotEmpty($buy->outcome['body_sha256']);
        $this->assertNotEmpty($buy->outcome['response_received_at']);
        $response = $buy->outcome['response'];
        $this->assertSame(10, $response['cargo']['units']);
        $this->assertSame($this->world['credits'], $response['agent']['credits']);
        if ($omittedField === 'transaction') {
            $this->assertArrayNotHasKey('transaction', $response);
        } else {
            $this->assertSame(10, $response['transaction']['units']);
            $this->assertArrayNotHasKey($omittedField, $response['transaction']);
        }

        $executor = app(ShipCommandExecutor::class);
        $executor->reconcile($this->agent, $buy);
        $this->assertSame('uncertain', $buy->state->value);
        $this->assertSame(10, $buy->outcome['ship']['cargo']['units']);
        $this->assertSame($this->world['transactions'], $buy->outcome['transactions']);
        $executor->acknowledgeUncertain($this->agent, $buy);

        $this->assertSame('acknowledged', $buy->state->value);
        $event = $this->bookOrder($order);
        $this->assertSame('settled', $event->state);
        $this->assertSame(20, $event->units);
        $key = self::SOURCE.'|IRON_ORE|buy';
        $nextOrder = $corridor + ['units' => 65, 'landing_at' => now()->timestamp + 1, 'tie_key' => 'next-order'];
        $this->assertFalse($book->fitsWindow($book->activeEvents($this->agent, [$key]), [$nextOrder], [$key => 80], depthBatches: 1));
    }

    #[DataProvider('marketTradeEvidence')]
    public function test_acknowledgement_checks_market_transactions_against_cargo(
        string $command,
        bool $applied,
        bool $staleCargo,
        int $bookedUnits,
        string $expectedState,
        int $expectedUnits,
        ?array $transactionTimestamps = null,
        array $evidenceOverrides = [],
    ): void {
        $this->campaign(true);
        $sell = $command === 'sell';
        if ($sell) {
            $this->loadShip(self::SHIP);
        }
        $beforeCargo = $this->world['ships'][0]['cargo'];
        $this->seedWorld();
        $run = $this->tradeRun(10, [10], ['initial_inventory' => []]);
        if ($sell) {
            $run->update(['totals' => array_replace($run->totals, [
                'phase' => 'deliver', 'leg_purchased' => [10], 'leg_purchase_cost' => [100], 'hop_index' => 1,
            ])]);
        }
        $market = $sell ? self::BUYER : self::SOURCE;
        $type = $sell ? 'SELL' : 'PURCHASE';
        $corridor = ['market' => $market, 'good' => 'IRON_ORE', 'side' => $command];
        $book = app(CorridorBook::class);
        [$order] = $book->reserveOrders($this->agent, [$corridor + [
            'owner_type' => 'run', 'owner_id' => (string) $run->id, 'units' => $bookedUnits,
            'landing_at' => now()->timestamp, 'tie_key' => 'lost-trade',
        ]]);
        $this->at(10);
        $this->refreshMarkets();
        $this->advance($run, execute: false);
        $action = ShipAction::where('automation_run_id', $run->id)->where('command', $command)->sole();
        $this->assertSame($action->id, $this->bookOrder($order)->ship_action_id);

        $lostResponse = $sell ? 'lose_sell_response' : 'lose_purchase_response';
        $this->world[$applied ? $lostResponse : 'lose_purchase_before_apply'] = true;
        $this->execute($action, 'uncertain');
        $this->assertSame('uncertain', $this->bookOrder($order)->state);
        foreach (['response', 'body_sha256', 'response_received_at'] as $receiptKey) {
            $this->assertArrayNotHasKey($receiptKey, $action->outcome ?? []);
        }
        if (! $applied) {
            $this->world['transactions'][] = [
                'shipSymbol' => self::SHIP, 'waypointSymbol' => $market, 'tradeSymbol' => 'IRON_ORE',
                'type' => $type, 'units' => 10, 'timestamp' => $action->sent_at->subSecond()->toIso8601String(),
            ];
        }
        if ($transactionTimestamps !== null) {
            $transaction = $this->world['transactions'][0];
            $this->world['transactions'] = array_map(
                fn (?string $timestamp) => array_replace($transaction, ['timestamp' => $timestamp]),
                $transactionTimestamps,
            );
        } else {
            $this->assertSame($applied, CarbonImmutable::parse($this->world['transactions'][0]['timestamp'])->gte($action->sent_at));
        }
        $this->assertSame($type, $this->world['transactions'][0]['type']);
        $this->assertSame(10, $this->world['transactions'][0]['units']);
        if (array_key_exists('transactions', $evidenceOverrides)) {
            $this->world['transactions'] = $evidenceOverrides['transactions'];
        }
        $actualCargo = $this->world['ships'][0]['cargo'];
        $this->assertSame($applied ? ($sell ? 0 : 10) : $beforeCargo['units'], $actualCargo['units']);
        if ($staleCargo) {
            $this->world['ships'][0]['cargo'] = $beforeCargo;
        }

        $executor = app(ShipCommandExecutor::class);
        $executor->reconcile($this->agent, $action);
        $this->world['ships'][0]['cargo'] = $actualCargo;
        $this->assertSame('uncertain', $action->state->value);
        $this->assertSame($staleCargo ? $beforeCargo['units'] : $actualCargo['units'], $action->outcome['ship']['cargo']['units']);
        $assertHistoryPreserved = function () use ($action) {
            $evidence = json_decode($action->getRawOriginal('outcome'), flags: JSON_THROW_ON_ERROR);
            $this->assertTrue(property_exists($evidence, 'transactions'));
            $this->assertSame(json_encode($this->world['transactions'], JSON_THROW_ON_ERROR), json_encode($evidence->transactions, JSON_THROW_ON_ERROR));
        };
        $assertHistoryPreserved();
        if (isset($evidenceOverrides['stored'])) {
            [$attribute, $path, $value] = $evidenceOverrides['stored'];
            $evidence = $action->{$attribute};
            data_set($evidence, $path, $value);
            DB::table('ship_actions')->where('id', $action->id)->update([$attribute => json_encode($evidence, JSON_THROW_ON_ERROR)]);
        }

        $executor->acknowledgeUncertain($this->agent, $action);

        $this->assertSame('acknowledged', $action->state->value);
        $assertHistoryPreserved();
        $event = $this->bookOrder($order);
        $this->assertSame($expectedState, $event->state);
        $this->assertSame($expectedUnits, $event->units);
        $key = "$market|IRON_ORE|$command";
        $nextUnits = $bookedUnits === 20 ? 65 : 79;
        $nextOrder = $corridor + ['units' => $nextUnits, 'landing_at' => now()->timestamp + 1, 'tie_key' => 'next-order'];
        $fits = $expectedState === 'released' || $expectedUnits + $nextUnits <= 80;
        $this->assertSame($fits, $book->fitsWindow($book->activeEvents($this->agent, [$key]), [$nextOrder], [$key => 80], depthBatches: 1));
    }

    public static function marketTradeEvidence(): array
    {
        $transaction = [
            'shipSymbol' => self::SHIP, 'waypointSymbol' => self::SOURCE, 'tradeSymbol' => 'IRON_ORE',
            'type' => 'PURCHASE', 'units' => 10, 'timestamp' => '2026-10-26T12:00:11Z',
        ];
        $history = fn (mixed $transactions) => ['transactions' => $transactions];

        return [
            'purchase transaction conflicts with stale cargo' => ['buy', true, true, 10, 'settled', 10],
            'sell transaction conflicts with stale cargo' => ['sell', true, true, 10, 'settled', 10],
            'purchase transaction predates the send' => ['buy', false, false, 10, 'released', 10],
            'fractional timestamp with timezone offset predates the send' => ['buy', false, false, 10, 'released', 10, ['2026-10-26T17:00:09.999999999+05:00']],
            'post-send purchase transaction agrees with cargo' => ['buy', true, false, 20, 'settled', 10, ['2026-10-26T12:00:11Z']],
            'relative transaction timestamp' => ['buy', true, false, 20, 'settled', 20, ['yesterday']],
            'impossible transaction date' => ['buy', true, false, 20, 'settled', 20, ['2026-09-31T12:00:00Z']],
            'invalid transaction clock time' => ['buy', true, true, 10, 'settled', 10, ['2026-10-25T24:00:00Z']],
            'invalid transaction timezone offset' => ['buy', true, true, 10, 'settled', 10, ['2026-10-26T12:00:10+24:00']],
            'transaction timestamp without timezone' => ['buy', true, true, 10, 'settled', 10, ['2026-10-25T12:00:00']],
            'missing transaction timestamp' => ['buy', true, false, 20, 'settled', 20, [null]],
            'unparseable transaction timestamp' => ['buy', true, true, 10, 'settled', 10, ['not-a-timestamp']],
            'two post-send transactions agree with cargo' => ['buy', true, false, 20, 'settled', 20, ['2026-10-26T12:00:11Z', '2026-10-26T12:00:12Z']],
            'explicit null market history' => ['buy', true, false, 20, 'settled', 20, null, $history(null)],
            'market history object keyed by row name' => ['buy', true, false, 20, 'settled', 20, null, $history((object) ['row' => $transaction])],
            'market history object keyed by index' => ['buy', true, false, 20, 'settled', 20, null, $history((object) [$transaction])],
            'empty market history object' => ['buy', true, false, 20, 'settled', 20, null, $history((object) [])],
            'scalar market history' => ['buy', true, false, 20, 'settled', 20, null, $history(false)],
            'padded transaction type with stale cargo' => ['buy', true, true, 20, 'settled', 20, null, $history([array_replace($transaction, ['type' => 'PURCHASE '])])],
            'unknown transaction type with stale cargo' => ['buy', true, true, 20, 'settled', 20, null, $history([array_replace($transaction, ['type' => 'BUY'])])],
            'padded transaction ship with stale cargo' => ['buy', true, true, 20, 'settled', 20, null, $history([array_replace($transaction, ['shipSymbol' => 'LOCAL-1 '])])],
            'padded transaction waypoint with stale cargo' => ['buy', true, true, 20, 'settled', 20, null, $history([array_replace($transaction, ['waypointSymbol' => self::SOURCE."\n"])])],
            'padded transaction good with stale cargo' => ['buy', true, true, 20, 'settled', 20, null, $history([array_replace($transaction, ['tradeSymbol' => 'IRON_ORE '])])],
            'lowercase transaction ship with stale cargo' => ['buy', true, true, 20, 'settled', 20, null, $history([array_replace($transaction, ['shipSymbol' => strtolower(self::SHIP)])])],
            'lowercase transaction waypoint with stale cargo' => ['buy', true, true, 20, 'settled', 20, null, $history([array_replace($transaction, ['waypointSymbol' => strtolower(self::SOURCE)])])],
            'lowercase transaction good with stale cargo' => ['buy', true, true, 20, 'settled', 20, null, $history([array_replace($transaction, ['tradeSymbol' => 'iron_ore'])])],
            'missing transaction ship' => ['buy', true, false, 20, 'settled', 20, null, $history([array_diff_key($transaction, ['shipSymbol' => true])])],
            'missing transaction waypoint' => ['buy', true, false, 20, 'settled', 20, null, $history([array_diff_key($transaction, ['waypointSymbol' => true])])],
            'missing transaction good' => ['buy', true, false, 20, 'settled', 20, null, $history([array_diff_key($transaction, ['tradeSymbol' => true])])],
            'missing transaction type' => ['buy', true, false, 20, 'settled', 20, null, $history([array_diff_key($transaction, ['type' => true])])],
            'empty transaction ship' => ['buy', true, false, 20, 'settled', 20, null, $history([array_replace($transaction, ['shipSymbol' => ''])])],
            'non-string transaction type' => ['buy', true, false, 20, 'settled', 20, null, $history([array_replace($transaction, ['type' => 1])])],
            'non-array transaction row' => ['buy', true, false, 20, 'settled', 20, null, $history(['invalid'])],
            'fully identified unrelated transaction' => ['buy', true, false, 20, 'settled', 10, null, $history([$transaction, array_replace($transaction, ['shipSymbol' => 'LOCAL-2', 'units' => 5])])],
            'object inventory before send' => ['buy', true, false, 20, 'settled', 20, null, ['stored' => ['before_state', 'ship.cargo.inventory', (object) []]]],
            'numeric-keyed object inventory after send' => ['buy', true, false, 20, 'settled', 20, null, ['stored' => ['outcome', 'ship.cargo.inventory', (object) [['symbol' => 'IRON_ORE', 'units' => 10]]]]],
            'invalid cargo item before send' => ['buy', true, false, 20, 'settled', 20, null, ['stored' => ['before_state', 'ship.cargo.inventory', [['units' => 0]]]]],
            'total cargo units disagree with inventory' => ['buy', true, false, 20, 'settled', 20, null, ['stored' => ['outcome', 'ship.cargo.units', 0]]],
            'explicit null stored response body hash' => ['buy', true, false, 20, 'settled', 20, null, ['stored' => ['outcome', 'body_sha256', null]]],
            'explicit null stored response timestamp' => ['buy', true, false, 20, 'settled', 20, null, ['stored' => ['outcome', 'response_received_at', null]]],
        ];
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_ship_reads_reject_a_json_object_used_as_cargo_inventory(bool $fleet): void
    {
        $this->world['ships'][0]['cargo']['inventory'] = (object) [];
        $this->expectException(ReadStateUnavailable::class);
        $this->expectExceptionMessage('incompatible cargo inventory');

        $api = app(SpaceTradersApi::class);
        if ($fleet) {
            $api->ships($this->agent);
        } else {
            $api->ship($this->agent, self::SHIP);
        }
    }

    #[DataProvider('uncertainCorridorOutcomes')]
    public function test_acknowledging_a_lost_purchase_bounds_the_corridor_charge(?int $reconciledUnits, string $clearedBy): void
    {
        $this->campaign(true);
        $this->seedWorld();
        $run = $this->tradeRun(10, [10]);
        $book = app(CorridorBook::class);
        [$order] = $book->reserveOrders($this->agent, [[
            'owner_type' => 'run', 'owner_id' => (string) $run->id, 'market' => self::SOURCE,
            'good' => 'IRON_ORE', 'side' => 'buy', 'units' => 20, 'landing_at' => $this->epoch->timestamp,
            'tie_key' => 'lost-purchase',
        ]]);
        $this->at(10);
        $this->refreshMarkets();
        $this->advance($run, execute: false);
        $buy = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy')->sole();
        $this->assertSame($buy->id, $this->bookOrder($order)->ship_action_id);
        $this->world[$reconciledUnits === 0 ? 'lose_purchase_before_apply' : 'lose_purchase_response'] = true;
        $this->execute($buy, 'uncertain');
        $this->assertSame('uncertain', $this->bookOrder($order)->state);
        if ($reconciledUnits === null) {
            array_shift($this->world['ships']);
        }

        $executor = app(ShipCommandExecutor::class);
        $executor->reconcile($this->agent, $buy);
        $this->assertSame($reconciledUnits, $buy->outcome['ship']['cargo']['units'] ?? null);
        if ($clearedBy === 'read before acknowledgement') {
            $this->at(12);
            $this->refreshMarkets();
        }
        $executor->acknowledgeUncertain($this->agent, $buy);

        $this->assertSame('acknowledged', $buy->state->value);
        $this->assertSame('stopped', $run->fresh()->state->value);
        $event = $this->bookOrder($order);
        $chargedUnits = function () use ($book) {
            $market = self::SOURCE;

            return $book->decayOffset($book->activeEvents($this->agent, ["$market|IRON_ORE|buy"]), $market, 'IRON_ORE', 'buy', now()->timestamp + 1, 'next-order', 1);
        };
        if ($reconciledUnits === 0) {
            $this->assertSame('released', $event->state);
            $this->assertSame(0, $chargedUnits());

            return;
        }
        $this->assertSame('settled', $event->state);
        $this->assertSame($reconciledUnits ?? 20, $event->units);
        $this->assertNotNull($event->observed_version);
        $alreadyObserved = $clearedBy === 'read before acknowledgement';
        $this->assertSame($alreadyObserved, (bool) $event->absorbed);
        $this->assertSame($alreadyObserved ? 0 : $event->units, $chargedUnits());

        if ($clearedBy === 'read after acknowledgement') {
            $this->at(13);
            $this->refreshMarkets();
            $this->assertTrue((bool) $this->bookOrder($order)->absorbed);
            $this->assertSame(0, $chargedUnits());
            $key = self::SOURCE.'|IRON_ORE|buy';
            $recoverySeconds = (int) config('fleet.saturation.corridor.recovery_seconds', 1800);
            $fitsCapacity = function () use ($book, $key, $recoverySeconds) {
                $nextOrder = ['market' => self::SOURCE, 'good' => 'IRON_ORE', 'side' => 'buy', 'units' => 80,
                    'landing_at' => now()->timestamp, 'tie_key' => 'next-order'];

                return $book->fitsWindow($book->activeEvents($this->agent, [$key]), [$nextOrder], [$key => 80], depthBatches: 1, recoverySeconds: $recoverySeconds);
            };
            $this->assertFalse($fitsCapacity());
            $this->at($event->landing_at - $this->epoch->timestamp + $recoverySeconds);
            $this->assertTrue($fitsCapacity());
        } elseif ($clearedBy === 'recovery window') {
            $this->at(7200);
            $this->assertFalse((bool) $this->bookOrder($order)->absorbed);
            $this->assertSame([], $book->activeEvents($this->agent, [self::SOURCE.'|IRON_ORE|buy']));
        }
        $this->assertSame(0, $chargedUnits());
    }

    public static function uncertainCorridorOutcomes(): array
    {
        return [
            'applied purchase absorbed after acknowledgement' => [10, 'read after acknowledgement'],
            'applied purchase already observed' => [10, 'read before acknowledgement'],
            'applied purchase decays without observation' => [10, 'recovery window'],
            'purchase never applied' => [0, 'no charge'],
            'unknown purchase absorbed after acknowledgement' => [null, 'read after acknowledgement'],
            'unknown purchase decays without observation' => [null, 'recovery window'],
        ];
    }

    public function test_acknowledgement_releases_the_mesh_allowances_of_all_stopped_runs(): void
    {
        $this->campaign(true);
        $this->seedWorld();
        $action = $this->action('refuel', state: 'uncertain');
        $executor = app(ShipCommandExecutor::class);
        $executor->reconcile($this->agent, $action);
        $governor = app(MeshGovernor::class);
        $budget = app(RequestBudget::class);
        $room = $governor->evaluate($this->agent, $this->episode)['room'];
        $this->assertGreaterThan(30, $room);
        $allowance = $governor->admit($this->agent, $this->episode, 'demand', 30);
        $legacyAllowance = $governor->admit($this->agent, $this->episode, 'demand', $room - 30);
        $run = $this->campaignRun('position', ['mesh_allowance' => $allowance]);
        $legacy = $this->campaignRun('scout', ['mesh_allowance' => $legacyAllowance], shipSymbol: 'LOCAL-2');
        $legacy->update(['plan_version' => 4]);
        $budget->bindMesh($allowance, $run->id, 600);
        $budget->bindMesh($legacyAllowance, $legacy->id, 600);
        try {
            $governor->admit($this->agent, $this->episode, 'demand', 1);
            $this->fail('The two unused allowances should leave no room for another request.');
        } catch (RequestDeferred $exception) {
            $this->assertStringContainsString('agent_hour_headroom', $exception->getMessage());
        }

        $executor->acknowledgeUncertain($this->agent, $action);

        $this->assertSame('stopped', $run->fresh()->state->value);
        $this->assertSame('stopped', $legacy->fresh()->state->value);
        $this->assertSame(0, $budget->usage(agentSymbol: $this->agent->name)['mesh']['outstanding']);
        $replacement = $governor->admit($this->agent, $this->episode, 'demand', 1);
        $this->assertSame(1, $budget->usage(agentSymbol: $this->agent->name)['mesh']['outstanding']);
        $governor->release($replacement);
    }

    #[TestWith([RunState::Stopped])]
    #[TestWith([RunState::Completed])]
    public function test_queued_legacy_recovery_rejects_without_blocking_the_terminal_run(RunState $terminalState): void
    {
        $this->campaign(true);
        $this->loadShip(self::SHIP);
        $this->loadShip('LOCAL-2');
        $this->seedWorld();
        $executor = app(ShipCommandExecutor::class);
        $sale = $executor->preview($this->agent, 'LOCAL-2', 'sell', ['symbol' => 'IRON_ORE', 'units' => 10, 'minTotal' => 300], (string) Str::uuid());
        $executor->approve($this->agent, $sale);
        $this->world['lose_sell_response'] = true;
        $this->execute($sale, 'uncertain');
        $executor->reconcile($this->agent, $sale);

        $recovery = $this->campaignRun('sell_aboard', ['market' => self::BUYER]);
        $recovery->update(['plan_version' => 4]);
        $queued = $this->action('sell', ['symbol' => 'IRON_ORE', 'units' => 10, 'minTotal' => 300], $recovery);
        app(StateChain::class)->reserveCredit($this->agent, 'run:'.$recovery->id, 'delivery', 'sell', 0, $queued->id, $recovery->id, self::SHIP);
        [$order] = app(CorridorBook::class)->reserveOrders($this->agent, [[
            'owner_type' => 'run', 'owner_id' => (string) $recovery->id, 'market' => self::BUYER,
            'good' => 'IRON_ORE', 'side' => 'sell', 'units' => 10, 'landing_at' => now()->timestamp,
            'tie_key' => 'queued-recovery', 'ship_action_id' => $queued->id,
        ]]);
        if ($terminalState === RunState::Completed) {
            $recovery->update(['state' => RunState::Completed, 'active_agent_id' => null, 'next_action_at' => null]);
        }
        $executor->acknowledgeUncertain($this->agent, $sale);
        $reason = $recovery->fresh()->blocked_reason;
        $this->assertSame($terminalState, $recovery->fresh()->state);
        $this->assertSame('reserved', $this->reservation($queued)->state);
        $this->assertSame('reserved', $this->bookOrder($order)->state);

        $this->execute($queued, 'rejected');

        $this->assertSame(['/my/ships/LOCAL-2/sell'], array_column($this->world['writes'], 'path'));
        $this->assertSame($terminalState, $recovery->fresh()->state);
        $this->assertSame($reason, $recovery->fresh()->blocked_reason);
        $this->assertTrue($queued->outcome['confirmed_not_sent']);
        $this->assertSame('released', $this->reservation($queued)->state);
        $this->assertSame('released', $this->bookOrder($order)->state);
    }

    #[DataProvider('existingRecoveryFuelHolds')]
    public function test_acknowledging_another_ships_action_preserves_a_stopped_trades_delivery_fuel(?int $existing): void
    {
        $this->campaign(true);
        $this->loadShip(self::SHIP);
        $this->loadShip('LOCAL-2');
        $this->seedWorld();
        $trade = $this->campaignRun('trade', ['initial_inventory' => []]);
        $chain = app(StateChain::class);
        $runOwner = 'run:'.$trade->id;
        $recoveryOwner = 'recovery:'.self::SHIP;
        $chain->reserveCredit($this->agent, $runOwner, 'delivery_fuel', 'refuel', 20, runId: $trade->id, shipSymbol: self::SHIP);
        $lot = $chain->reserveCredit($this->agent, $runOwner, 'trade_lot', 'buy', 100, runId: $trade->id, shipSymbol: self::SHIP);
        if ($existing !== null) {
            $chain->reserveCredit($this->agent, $recoveryOwner, 'delivery_fuel', 'refuel', $existing, shipSymbol: self::SHIP);
        }
        $orders = $this->bookPartialTrade($trade);
        $executor = app(ShipCommandExecutor::class);
        $sale = $executor->preview($this->agent, 'LOCAL-2', 'sell', ['symbol' => 'IRON_ORE', 'units' => 10, 'minTotal' => 300], (string) Str::uuid());
        $executor->approve($this->agent, $sale);
        $this->world['lose_sell_response'] = true;
        $this->execute($sale, 'uncertain');
        $executor->reconcile($this->agent, $sale);
        $buy = $this->action('buy', ['symbol' => 'IRON_ORE', 'units' => 10], $trade);
        $commitment = $chain->reserveCredit($this->agent, $runOwner, 'trade_lot', 'buy', 10, $buy->id, $trade->id, self::SHIP);
        DB::table('credit_reservations')->where('id', $commitment)->update(['state' => 'pending']);
        DB::table('corridor_book_events')->where('id', $orders[0])->update(['ship_action_id' => $buy->id]);

        $executor->acknowledgeUncertain($this->agent, $sale, 'Reviewed the sale on the other ship.');

        $this->assertSame('stopped', $trade->fresh()->state->value);
        $fuel = DB::table('credit_reservations')->where('owner_key', $recoveryOwner)->whereNull('ship_action_id')->where('state', 'reserved')->sole();
        $this->assertSame(max(20, $existing ?? 0), $fuel->exposure);
        $this->assertNull($fuel->automation_run_id);
        $this->assertSame(self::SHIP, $fuel->ship_symbol);
        $this->assertFalse(DB::table('credit_reservations')->where('owner_key', $runOwner)->whereNull('ship_action_id')->where('state', 'reserved')->exists());
        $this->assertSame('released', DB::table('credit_reservations')->find($lot)->state);
        $this->assertSame('pending', DB::table('credit_reservations')->find($commitment)->state);
        $this->assertSame('pending', $buy->fresh()->state->value);
        $this->assertSame('reserved', $this->bookOrder($orders[0])->state);
        $this->assertSame('released', $this->bookOrder($orders[1])->state);
        $this->assertSame(max(20, $existing ?? 0) + 10, $chain->outstandingExposure($this->agent));

        $this->execute($buy, 'rejected');
        $this->assertSame('stopped', $trade->fresh()->state->value);
        $this->assertSame('released', DB::table('credit_reservations')->find($commitment)->state);
        $this->assertSame('released', $this->bookOrder($orders[0])->state);
        $this->assertSame(max(20, $existing ?? 0), $chain->outstandingExposure($this->agent));
    }

    public static function existingRecoveryFuelHolds(): array
    {
        return ['unbooked recovery' => [null], 'smaller recovery hold' => [15], 'larger recovery hold' => [25]];
    }

    public function test_full_ship_refresh_releases_empty_recovery_fuel_after_credit_and_recovery_ownership_clear(): void
    {
        $this->campaign(true);
        $this->loadShip(self::SHIP);
        $this->seedWorld();
        $chain = app(StateChain::class);
        $hold = $chain->reserveCredit($this->agent, 'recovery:'.self::SHIP, 'delivery_fuel', 'refuel', 20, shipSymbol: self::SHIP);
        $recovery = $this->campaignRun('sell_aboard');
        $sync = app(AgentSynchronizer::class);
        $this->world['ships'][0]['cargo']['units'] = 0;
        $this->world['ships'][0]['cargo']['inventory'] = [];

        $this->assertSame(0, $sync->refreshShip($this->agent, self::SHIP, fresh: true)->snapshot['cargo']['units']);
        $this->assertSame('reserved', DB::table('credit_reservations')->find($hold)->state);

        $recovery->update(['active_agent_id' => null, 'state' => 'stopped']);
        $sale = $this->action('sell', ['symbol' => 'IRON_ORE', 'units' => 10], $recovery);
        foreach (['pending', 'sending', 'uncertain'] as $state) {
            $sale->update(['state' => $state]);
            $sync->refreshShip($this->agent, self::SHIP, fresh: true);
            $this->assertSame('reserved', DB::table('credit_reservations')->find($hold)->state, $state);
        }

        $sale->update(['state' => 'confirmed']);
        $sync->refreshShip($this->agent, self::SHIP, fresh: true);
        $this->assertSame('released', DB::table('credit_reservations')->find($hold)->state);
        $this->assertSame(0, $chain->outstandingExposure($this->agent));
    }

    #[TestWith(['running', true])]
    #[TestWith(['running', false])]
    #[TestWith(['stopping', false])]
    #[TestWith(['blocked', false])]
    public function test_legacy_advance_preserves_the_local_balance_during_an_uncertain_credit_write(string $state, bool $withArm): void
    {
        $this->campaign($withArm);
        $this->seedWorld();
        $arm = $withArm ? $this->armEpisode('LOCAL-2') : null;
        $run = $this->campaignRun('trade', shipSymbol: 'LOCAL-2');
        $run->update(['plan_version' => 3, 'campaign_episode_id' => $arm?->id, 'state' => $state, 'next_action_at' => now(),
            'blocked_reason' => $state === 'blocked' ? 'Existing review' : null,
            'stop_requested_at' => $state === 'stopping' ? now() : null]);
        if ($state !== 'running') {
            $run->update(['totals' => $run->totals + ['accounting' => ['state' => 'saved']]]);
        }
        $original = $run->fresh();
        $write = $this->action('refuel', ['units' => 10], state: 'uncertain', ship: self::SHIP);
        $write->update(['sent_at' => now()]);
        $stored = $this->agent->credits;
        $syncedAt = $this->agent->synced_at->toIso8601String();
        $this->world['credits'] -= 5;
        $this->at(1);

        $this->advance($run);

        $this->assertSame($stored, $this->agent->fresh()->credits);
        $this->assertSame($syncedAt, $this->agent->fresh()->synced_at->toIso8601String());
        $this->assertSame($withArm ? 'stopping' : ($state === 'running' ? 'blocked' : $state), $run->state->value);
        if ($withArm) {
            $this->assertSame('uncertain_action', $arm->grant()->pause_reason);
        }
        if ($state !== 'running') {
            $this->assertEquals($original->next_action_at, $run->next_action_at);
            $this->assertEquals($original->stop_requested_at, $run->stop_requested_at);
            $this->assertSame($original->blocked_reason, $run->blocked_reason);
            $this->assertSame($original->totals, $run->totals);
        }
        $this->assertNotContains('/my/agent', array_column($this->world['calls'], 'path'));
        $this->assertCount(0, $this->world['writes']);
    }

    public function test_automation_intents_cannot_be_approved_manually(): void
    {
        $this->campaign(true);
        $this->seedWorld();
        $run = $this->tradeRun(10, [10]);
        $buy = $this->action('buy', ['symbol' => 'IRON_ORE', 'units' => 10, 'maxTotal' => 100], $run, state: 'preview');
        try {
            app(ShipCommandExecutor::class)->approve($this->agent, $buy);
            $this->fail('Manual approval advanced an automation intent.');
        } catch (GameException $exception) {
            $this->assertSame('Automation intents cannot be approved manually.', $exception->getMessage());
        }
        $this->assertSame('preview', $buy->fresh()->state->value);
        $this->assertCount(0, $this->world['writes']);
    }

    #[DataProvider('settlementAuthority')]
    public function test_loaded_pair_survives_request_and_lock_waits_until_its_settlement_deadline(bool $authorized): void
    {
        $this->campaign(true);
        $this->world['ships'][0]['nav']['waypointSymbol'] = self::BUYER;
        $this->loadShip(self::SHIP, 10);
        $this->seedWorld();
        $runner = app(AutomationRunner::class);
        $run = $this->tradeRun(10, [10], [
            'initial_inventory' => [], 'elapsed_p90_seconds' => 80, 'acquisition_cutoff' => $this->epoch->addSeconds(100)->toIso8601String(),
            'settlement_deadline' => $this->epoch->addSeconds(200)->toIso8601String(),
        ]);
        $run->update(['totals' => array_replace($run->totals, [
            'phase' => 'deliver', 'leg_purchased' => [10], 'leg_purchase_cost' => [100], 'hop_index' => 1,
        ])]);
        $this->at(101);
        $blocker = $this->action('orbit', state: 'pending');
        $this->advance($run);
        $this->assertSame('waiting', $run->state->value);
        $this->assertStringNotContainsString('Duration reached', (string) $run->blocked_reason);
        $this->assertCount(0, $this->world['writes']);
        $blocker->update(['state' => 'rejected']);

        $this->at(106);
        $this->afterWriteReservation(fn () => throw new RequestDeferred('Synthetic T0 request wait', 5));
        $this->advance($run);
        $this->assertSame($this->agent->id, $run->active_agent_id);
        $this->assertStringNotContainsString('Duration reached', (string) $run->blocked_reason);
        $this->assertCount(0, $this->world['writes']);

        if (! $authorized) {
            $this->episode->grant()->update(['revoked_at' => now()]);
        }
        $this->at(200);
        $this->advance($run);
        $this->assertSame($authorized ? 'stopped' : 'blocked', $run->state->value, (string) $run->blocked_reason);
        $this->assertSame(['IRON_ORE' => 10], $run->totals['unsold']);

        if (! $authorized) {
            $this->assertCount(0, $this->world['writes']);
            $this->assertSame($this->agent->id, $run->active_agent_id);

            return;
        }

        $this->refreshMarkets();
        $recovery = $runner->previewRecovery($this->agent, self::SHIP, self::BUYER, [
            'recovery_of' => $run->id, 'minutes' => 10, 'reserve' => 0, 'max_spend' => 500, 'max_loss' => 500,
        ], $this->episode);
        $runner->startCampaign($this->agent, $recovery, $this->episode);
        $this->advance($recovery);
        $this->assertSame('sell_aboard', $recovery->strategy);
        $this->assertSame([], $recovery->totals['unsold'], (string) $recovery->blocked_reason);
        $this->assertSame(['/my/ships/LOCAL-1/sell'], array_column($this->world['writes'], 'path'));
    }

    public static function settlementAuthority(): array
    {
        return ['authorized recovery' => [true], 'revoked authority protects cargo' => [false]];
    }

    private function at(int $seconds): void
    {
        $this->travelTo($this->epoch->addSeconds($seconds));
    }

    private function campaign(bool $split): void
    {
        $body = Yaml::parseFile(base_path('campaigns/weekly-system-saturation-fleet.yaml'));
        $body['agent'] = ['symbol' => 'LOCAL', 'faction' => 'COSMIC'];
        $body['fleet']['saturation']['per_ship_noncredit_locks'] = $split;
        $body['fleet']['saturation']['corridor']['chains_enabled'] = true;
        $body['fleet']['saturation']['extraction']['enabled'] = true;
        $body['fleet']['saturation']['extraction']['stationary'] = true;
        $revision = CampaignRevision::create([
            'name' => $body['name'], 'source' => Yaml::dump($body, 12), 'body' => $body,
            'content_hash' => hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)),
        ]);
        $grant = CampaignGrant::create([
            'user_id' => $this->agent->user_id, 'agent_symbol' => 'LOCAL', 'slot' => 1,
            'campaign_revision_id' => $revision->id, 'revision_hash' => $revision->content_hash,
            'limits' => $body['limits'], 'expires_at' => now()->addDays(4),
        ]);
        $this->episode = CampaignEpisode::create([
            'agent_id' => $this->agent->id, 'campaign_grant_id' => $grant->id, 'reset_id' => $this->agent->reset_id,
            'start_credits' => $this->agent->credits, 'phase' => 'earn', 'state' => [],
            'next_reset_at' => $this->world['next_reset'], 'opened_at' => now(),
        ]);
        $this->assertSame($split, app(CampaignSafety::class)->perShipLocksEnabled($this->agent));
    }

    private function seedWorld(): void
    {
        $sync = app(AgentSynchronizer::class);
        $this->agent->update(['credits' => $this->world['credits'], 'synced_at' => now()]);
        app(CreditAccounting::class)->baseline($this->agent);
        foreach ($this->world['ships'] as $ship) {
            $sync->saveShip($this->agent, app(SpaceTradersApi::class)->shipData($ship));
        }
        foreach ($this->world['waypoints'] as $point) {
            $symbol = $point['symbol'];
            $sync->observe($this->agent, 'waypoint', $symbol, $point, 'listing');
            if (in_array('MARKETPLACE', array_column($point['traits'], 'symbol'), true)) {
                $sync->observe($this->agent, 'market', $symbol, app(SpaceTradersApi::class)->market($this->agent, $symbol));
            }
        }
        app(SpaceTradersApi::class)->status(fresh: true);
        $this->world['calls'] = [];
    }

    private function armEpisode(string $ship, ?CampaignRevision $revision = null): CampaignEpisode
    {
        $primary = $this->episode->grant();
        $arm = CampaignGrant::create([
            'user_id' => $primary->user_id, 'agent_symbol' => $this->agent->name,
            'campaign_revision_id' => $revision?->id ?? $primary->campaign_revision_id, 'revision_hash' => $revision?->content_hash ?? $primary->revision_hash,
            'expires_at' => $primary->expires_at,
            'limits' => $primary->limits + ['arm' => [
                'ship' => $ship, 'label' => 'second-ship', 'reset_id' => $this->agent->reset_id,
                'exposure_share' => 1, 'max_load_spend' => 5000, 'max_net_loss' => 5000,
            ]],
        ]);
        $episode = $this->episode->replicate();
        $episode->campaign_grant_id = $arm->id;
        $episode->save();

        return $episode;
    }

    private function campaignRun(string $strategy, array $plan = [], array $totals = [], string $shipSymbol = self::SHIP): AutomationRun
    {
        $ship = collect($this->world['ships'])->firstWhere('symbol', $shipSymbol);
        $inventory = array_column($ship['cargo']['inventory'], 'units', 'symbol');
        $grant = $this->episode->grant();

        return AutomationRun::create([
            'agent_id' => $this->agent->id, 'active_agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id,
            'ship_symbol' => $shipSymbol, 'campaign_episode_id' => $this->episode->id, 'plan_version' => 8,
            'strategy' => $strategy, 'state' => 'running', 'started_at' => now(), 'next_action_at' => now(),
            'expires_at' => $plan['settlement_deadline'] ?? $this->epoch->addHours(6),
            'plan' => $plan + [
                'strategy' => $strategy, 'plan_version' => 8, 'grant_id' => $grant->id, 'revision_hash' => $grant->revision_hash,
                'campaign_episode_id' => $this->episode->id, 'initial_waypoint' => $ship['nav']['waypointSymbol'],
                'initial_status' => $ship['nav']['status'], 'initial_fuel' => $ship['fuel']['current'],
                'initial_inventory' => $inventory, 'initial_external_credit_delta' => 0, 'initial_credits' => $this->agent->fresh()->credits,
                'initial_reviewed_adjustment' => app(CreditAccounting::class)->summary($this->agent)['reviewed_adjustments'],
                'reserve' => 0, 'max_spend' => 5000, 'max_loss' => 5000, 'max_fuel' => 500,
                'acquisition_cutoff' => $this->epoch->addHours(5)->toIso8601String(),
                'settlement_deadline' => $this->epoch->addHours(6)->toIso8601String(),
            ],
            'totals' => $totals + [
                'credit_delta' => 0, 'fuel_cost' => 0, 'spending' => 0, 'travel_fuel' => 0,
                'sold' => [], 'unsold' => $inventory, 'yielded' => [], 'load_attempts' => 0,
                'phase' => 'acquire',
            ],
        ]);
    }

    private function extractionRun(string $command, array $plan = [], array $totals = []): AutomationRun
    {
        $this->campaign(true);
        $this->world['ships'][0]['nav']['status'] = 'IN_ORBIT';
        $this->world['yields'] = [['symbol' => 'IRON_ORE', 'units' => 10]];
        if ($command === 'siphon') {
            $this->world['waypoints'][0]['type'] = 'GAS_GIANT';
            $this->world['ships'][0]['mounts'][0]['symbol'] = 'MOUNT_GAS_SIPHON_I';
            $this->world['ships'][0]['modules'][0]['symbol'] = 'MODULE_GAS_PROCESSOR_I';
        }
        $this->world['market_goods'][self::BUYER] = FakeSpaceTraders::withAllMiningBids($this->world['market_goods'][self::BUYER]);
        $this->seedWorld();

        if (isset($plan['route']['hops'])) {
            foreach ($plan['route']['hops'] as &$hop) {
                $hop['configuration'] = FuelPlanner::configuration($this->world['ships'][0]);
            }
            unset($hop);
        }

        return $this->campaignRun($command === 'mine' ? 'mining' : 'siphon', $plan + [
            'site' => self::SOURCE, 'market' => self::BUYER, 'buyer' => self::BUYER, 'stationary' => true,
            'allowed_goods' => [$command === 'mine' ? 'IRON_ORE' : 'HYDROCARBON'], 'extraction_headroom' => 7,
            'route' => ['hops' => [], 'fuel_ceiling' => 0],
        ], $totals);
    }

    private function action(string $command, array $arguments = [], ?AutomationRun $run = null, string $state = 'pending', string $ship = self::SHIP): ShipAction
    {
        return ShipAction::create([
            'key' => (string) Str::uuid(), 'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id,
            'ship_symbol' => $run?->ship_symbol ?? $ship, 'origin' => $run ? 'automation' : 'manual',
            'command' => $command, 'state' => $state, 'arguments' => $arguments, 'automation_run_id' => $run?->id,
            'expires_at' => now()->addMinutes(3),
        ]);
    }

    private function execute(ShipAction $action, ?string $expected = 'confirmed'): void
    {
        app(ShipCommandExecutor::class)->execute($action->id);
        $action->refresh();
        if ($expected !== null) {
            $this->assertSame($expected, $action->state->value, (string) $action->message);
        }
    }

    private function fields(): array
    {
        return $this->staleness()['fields'];
    }

    private function staleness(): array
    {
        return app(StateChain::class)->staleFields($this->agent, self::SHIP);
    }

    private function shipTrace(): array
    {
        return array_values(array_map(fn ($call) => $call['method'].' '.$call['path'], array_filter(
            $this->world['calls'], fn ($call) => str_starts_with($call['path'], '/my/ships/'.self::SHIP),
        )));
    }

    private function marketGets(string $waypoint): array
    {
        return array_filter($this->world['calls'], fn ($call) => $call['method'] === 'GET' && str_ends_with($call['path'], '/'.$waypoint.'/market'));
    }

    private function hop(string $from, string $to, int $burn = 5): array
    {
        return ['origin' => $from, 'destination' => $to, 'burn' => $burn, 'seconds' => $this->world['travel_seconds'],
            'configuration' => FuelPlanner::configuration($this->world['ships'][0]),
            'fuel_before' => 100, 'fuel_after' => 95, 'uncertainty_reserve' => 2,
            'topped_up' => 0, 'seller' => null, 'refuel_ceiling' => 0, 'continuation_ceiling' => 0];
    }

    private function positionRefuelRun(string $ship, string $origin, string $destination): AutomationRun
    {
        $hop = array_replace($this->hop($origin, $destination), ['topped_up' => 10, 'refuel_ceiling' => 5]);

        return $this->campaignRun('position', ['route' => [
            'mode' => 'CRUISE', 'origin' => $origin, 'destination' => $destination, 'hops' => [$hop], 'fuel_ceiling' => 5,
        ]], shipSymbol: $ship);
    }

    private function advance(AutomationRun $run, bool $execute = true): void
    {
        app(AutomationRunner::class)->advance($run->id);
        if ($execute && ! config('gameplay.inline_execute')) {
            foreach (ShipAction::where('automation_run_id', $run->id)->where('state', 'pending')->get() as $action) {
                (new ExecuteShipCommand($action->id))->handle(app(ShipCommandExecutor::class));
            }
        }
        $run->refresh();
    }

    private function assertBlocked(Closure $command): void
    {
        try {
            $command();
            $this->fail('Expected an authority or command-lock refusal.');
        } catch (GameException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
    }

    private function reservation(ShipAction $action): object
    {
        return DB::table('credit_reservations')->where('ship_action_id', $action->id)->sole();
    }

    private function lease(string $state): int
    {
        return DB::table('transfer_leases')->insertGetId([
            'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'cluster_id' => 'fixture-lease',
            'source_ship' => self::SHIP, 'receiver_ship' => 'LOCAL-2', 'source_run_id' => 1, 'receiver_run_id' => 2,
            'waypoint' => self::BUYER, 'good' => 'IRON_ORE', 'units' => 1, 'state' => $state,
            'expires_at' => now()->addMinutes(3),
        ]);
    }

    private function loadShip(string $symbol, int $units = 10): void
    {
        foreach ($this->world['ships'] as &$ship) {
            if ($ship['symbol'] === $symbol) {
                $ship['cargo']['units'] = $units;
                $ship['cargo']['inventory'] = [['symbol' => 'IRON_ORE', 'units' => $units]];
                $ship['nav']['waypointSymbol'] = self::BUYER;
                $ship['fuel']['current'] = 90;
            }
        }
    }

    private function loadedRun(string $ship): AutomationRun
    {
        return $this->campaignRun('mining', [
            'site' => self::SOURCE, 'market' => self::BUYER, 'buyer' => self::BUYER, 'allowed_goods' => ['IRON_ORE'],
            'route' => ['hops' => [], 'fuel_ceiling' => 0], 'initial_inventory' => [],
        ], ['phase' => 'deliver', 'extracted' => ['IRON_ORE' => 10]], $ship);
    }

    private function tradeRun(int $units, array $buyBatches, array $plan = [], ?array $extraLeg = null, int $latest = 1500): AutomationRun
    {
        $leg = [
            'source' => self::SOURCE, 'buyer' => self::BUYER, 'good' => 'IRON_ORE',
            'approvedUnits' => $units, 'maxInventory' => $units * 30, 'minSellPrice' => 11,
            'expectedFuelCost' => 5, 'remainingFuelCeiling' => 50, 'approachHopCount' => 0,
            'hops' => [$this->hop(self::SOURCE, self::BUYER)], 'quoteEvidence' => ['buyer_max_age' => 900],
            'buyBatches' => $buyBatches, 'sellBatches' => $buyBatches,
            'latestBuyAt' => $this->epoch->addSeconds($latest)->toIso8601String(),
            'deliveryDeadline' => $this->epoch->addHours(3)->toIso8601String(),
        ];
        $legs = [$leg];
        if ($extraLeg) {
            $legs[] = $extraLeg + array_replace($leg, [
                'latestBuyAt' => $this->epoch->addSeconds(1500)->toIso8601String(),
                'approvedUnits' => 10, 'minSellPrice' => 1, 'maxInventory' => 300, 'buyBatches' => [10], 'sellBatches' => [10],
            ]);
        }

        return $this->campaignRun('trade', $plan + ['legs' => $legs, 'route' => ['fuel_ceiling' => 50], 'market' => self::BUYER, 'min_margin' => 1], ['phase' => 'buy']);
    }

    private function refreshMarkets(): void
    {
        $sync = app(AgentSynchronizer::class);
        foreach ([self::SOURCE, self::BUYER] as $symbol) {
            $sync->observe($this->agent, 'market', $symbol, app(SpaceTradersApi::class)->market($this->agent, $symbol, fresh: true));
        }
    }

    private function bookPartialTrade(AutomationRun $run): array
    {
        return app(CorridorBook::class)->reserveOrders($this->agent, array_map(
            fn ($order, $index) => $order + ['owner_type' => 'run', 'owner_id' => (string) $run->id, 'good' => 'IRON_ORE',
                'tie_key' => 'partial-'.$index, 'landing_at' => $this->epoch->addSeconds(1300)->timestamp],
            [
                ['leg_index' => 0, 'batch_index' => 0, 'side' => 'buy', 'units' => 60, 'market' => self::SOURCE],
                ['leg_index' => 0, 'batch_index' => 1, 'side' => 'buy', 'units' => 20, 'market' => self::SOURCE],
                ['leg_index' => 0, 'batch_index' => 0, 'side' => 'sell', 'units' => 80, 'market' => self::BUYER],
                ['leg_index' => 1, 'batch_index' => 0, 'side' => 'buy', 'units' => 10, 'market' => self::BUYER],
            ], [0, 1, 2, 3],
        ));
    }

    private function bookOrder(int $id): object
    {
        return DB::table('corridor_book_events')->find($id);
    }

    private function afterWriteReservation(Closure $hook): void
    {
        $this->app->instance(RequestBudget::class, new class($hook) extends RequestBudget
        {
            private float $elapsed = 0;

            private ?string $waitingPriority = null;

            public function __construct(private ?Closure $hook) {}

            public function reserve(?string $token = null, ?string $account = null, string $priority = 'discovery', float $maxWait = 0.75, ?float $baseMaxWait = null, ?float $deadline = null): void
            {
                if ($this->hook !== null && in_array($priority, ['write', 'settlement'], true) && ShipAction::where('state', 'sending')->exists()) {
                    // Install an exhausted bucket with a short upstream retry window.
                    $this->waitingPriority = $priority;
                    $this->observe(new Response(new PsrResponse(200, ['Retry-After' => '0.5', 'X-RateLimit-Remaining' => '0'])), $token, $account);
                }

                parent::reserve($token, $account, $priority, $maxWait, $baseMaxWait, $deadline);
            }

            protected function now(): float
            {
                return parent::now() + $this->elapsed;
            }

            protected function pause(float $seconds): void
            {
                $this->elapsed += $seconds;
                if ($this->waitingPriority !== null && $this->hook !== null) {
                    $hook = $this->hook;
                    $this->hook = null;
                    $hook($this->waitingPriority);
                    $this->waitingPriority = null;
                }
            }
        });
    }
}
