<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignDiscovery;
use App\Gameplay\CampaignFleet;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\CampaignProbeBootstrap;
use App\Gameplay\CampaignSafety;
use App\Gameplay\CampaignStatus;
use App\Gameplay\CampaignTrade;
use App\Gameplay\CreditAccounting;
use App\Gameplay\Engine\EngineHost;
use App\Gameplay\FuelPlanner;
use App\Gameplay\GameException;
use App\Gameplay\OperationPlanner;
use App\Gameplay\RequestDeferred;
use App\Gameplay\ShipCommandExecutor;
use App\Gameplay\SpaceTradersApi;
use App\Gameplay\StaleQuote;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\LocalEngine;
use App\Models\ReconMission;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use App\Services\SpaceTradersClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\EngineFixture;
use Tests\Support\FakeEngineHost;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class CampaignProbePoolTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Agent $agent;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now());
        Cache::flush();
        config(['gameplay.enabled' => true, 'gameplay.local_engine_enabled' => true, 'gameplay.snapshot_seconds' => 180]);
        $this->app->instance(EngineHost::class, new FakeEngineHost);
        $this->file = tempnam(sys_get_temp_dir(), 'st-probe-pool-');
        $world = $this->baseWorld();
        file_put_contents($this->file, json_encode($world, JSON_THROW_ON_ERROR));
        FakeSpaceTraders::install($this->file);
        $this->user = User::factory()->create();
        $this->user->rememberAccountToken('synthetic-account-token');
        $this->agent = $this->user->agents()->create([
            'name' => 'LOCAL',
            'token' => 'synthetic-only',
            'faction' => 'VOID',
            'reset_id' => $world['reset'],
            'canonical_identity' => AgentSynchronizer::identity($world['reset'], 'LOCAL'),
        ]);
        app(AgentSynchronizer::class)->sync($this->agent);
        Queue::fake();
        EngineFixture::healthy();
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function test_playbook_validates_optional_probes_policy_and_rejects_invalid_bounds(): void
    {
        $playbook = app(CampaignPlaybook::class);
        $bootstrap = $playbook->import(file_get_contents(base_path('campaigns/weekly-probe-bootstrap.yaml')));
        $this->assertSame('weekly-probe-bootstrap', $bootstrap->name);
        $this->assertSame(3, $bootstrap->body['probes']['target']);
        $this->assertTrue($bootstrap->body['probes']['pool']);

        $legacy = $playbook->import(file_get_contents(base_path('campaigns/weekly-default.yaml')));
        $this->assertArrayNotHasKey('probes', $legacy->body);

        $base = Yaml::parse(file_get_contents(base_path('campaigns/weekly-probe-bootstrap.yaml')));
        foreach ([
            ['target' => 0],
            ['target' => 11],
            ['max_price' => 0],
            ['min_working_capital' => -1],
            ['pool' => 'yes'],
            ['extra' => 1],
        ] as $override) {
            $bad = $base;
            $bad['probes'] = array_merge($bad['probes'], $override);
            try {
                $playbook->validate($bad);
                $this->fail('Expected validation failure for probes override: '.json_encode($override));
            } catch (GameException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_v6_remote_probe_preview_and_ranking_reject_a_stale_over_cap_price(): void
    {
        $yard = 'X1-TEST-YARDB';
        $sync = app(AgentSynchronizer::class);
        $sync->observe($this->agent, 'waypoint', $yard, ['symbol' => $yard, 'systemSymbol' => 'X1-TEST', 'type' => 'ORBITAL_STATION',
            'x' => 4, 'y' => 0, 'traits' => [['symbol' => 'SHIPYARD']]], 'listing');
        $observation = $sync->observe($this->agent, 'shipyard', $yard, ['shipTypes' => [['type' => 'SHIP_PROBE']], 'ships' => [$this->probeOffer(100000)]]);
        $observation->update(['observed_at' => now()->subMinutes(31)]);
        $grant = $this->approve($this->bootstrapBody(target: 2, maxPrice: 50000, minWorkingCapital: 0));
        $episode = CampaignEpisode::create(['campaign_grant_id' => $grant->id, 'agent_id' => $this->agent->id,
            'reset_id' => $this->agent->reset_id, 'start_credits' => 150000, 'phase' => 'earn',
            'opened_at' => now(), 'next_reset_at' => now()->addDays(5)]);
        $considered = [];

        $this->assertNull(app(CampaignProbeBootstrap::class)->plan($grant, $episode, $this->agent, [], $considered));
        $this->assertContains('Observed SHIP_PROBE price 100000 CR exceeds max_price.', array_column($considered, 'rejected'));
        try {
            app(AutomationRunner::class)->previewProbePurchase($this->agent, 'LOCAL-2', $yard, ['minutes' => 15], $episode);
            $this->fail('Expected the stored probe price to block preview before departure.');
        } catch (GameException $exception) {
            $this->assertStringContainsString('SHIP_PROBE price 100000 CR exceeds max_price 50000 CR', $exception->getMessage());
        }
        $this->assertDatabaseMissing('automation_runs', ['strategy' => 'buy_probe']);
        $this->assertSame([], $this->world()['writes']);
    }

    public function test_round_two_purchase_preview_holds_without_budget_evidence(): void
    {
        $episode = $this->prepareBudgetPurchase();
        config(['gameplay.agent_request_caps' => ['LOCAL' => 500]]);
        $this->expectExceptionMessage('insufficient_sample_window');
        app(AutomationRunner::class)->previewProbePurchase($this->agent, 'LOCAL-2', 'X1-TEST-ROCK', ['minutes' => 10], $episode);
    }

    public function test_round_two_pending_purchase_rechecks_formula_before_transport(): void
    {
        $episode = $this->prepareBudgetPurchase();
        $runner = app(AutomationRunner::class);
        $run = $runner->previewProbePurchase($this->agent, 'LOCAL-2', 'X1-TEST-ROCK', ['minutes' => 10], $episode);
        $runner->start($this->agent, $run);
        app(AutomationRunner::class)->advance($run->id);
        $action = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->sole();

        $episode = CampaignEpisode::findOrFail($run->campaign_episode_id);
        $episode->update(['opened_at' => now()->subHours(7)]);
        AutomationRun::create(['agent_id' => $this->agent->id, 'campaign_episode_id' => $episode->id, 'reset_id' => $episode->reset_id,
            'ship_symbol' => 'LOCAL-1', 'strategy' => 'trade', 'state' => 'stopped', 'started_at' => now()->subHours(3),
            'updated_at' => now()->subHours(2), 'expires_at' => now()->subHours(2), 'plan' => [], 'totals' => []]);
        DB::table('fleet_metrics')->insert(['kind' => 'requests', 'ship' => 'LOCAL-1', 'agent_symbol' => 'LOCAL', 'scope' => 'agent',
            'value' => 1000, 'data' => '{}', 'created_at' => now()->subMinutes(150)]);
        config(['gameplay.agent_request_caps' => ['LOCAL' => 500]]);
        $writes = count($this->world()['writes']);
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame('rejected', $action->fresh()->state->value);
        $this->assertStringContainsString('trader ceiling', $action->fresh()->message);
        $this->assertSame($writes, count($this->world()['writes']));
    }

    private function prepareBudgetPurchase(): CampaignEpisode
    {
        $this->setBalance(200000);
        $this->editWorld(function (array &$world) {
            $world['shipyards']['X1-TEST-ROCK'] = ['shipTypes' => [['type' => 'SHIP_PROBE']], 'ships' => [$this->probeOffer(24000)]];
        });
        $this->seedDiscoveryDone();
        app(AgentSynchronizer::class)->observe($this->agent, 'waypoint', 'X1-TEST-ROCK', $this->world()['waypoints'][0], 'listing');
        $grant = $this->approve($this->bootstrapBody(target: 2, maxPrice: 50000, minWorkingCapital: 75000));

        return CampaignEpisode::create([
            'campaign_grant_id' => $grant->id, 'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id,
            'start_credits' => 200000, 'phase' => 'earn', 'opened_at' => now(), 'next_reset_at' => now()->addDays(5),
            'state' => ['role_ships' => ['command' => 'LOCAL-1', 'probe' => 'LOCAL-2']],
        ]);
    }

    public function test_minute_zero_probe_bootstrap_discovers_shipyard_buys_up_to_target_and_reconciles_credits(): void
    {
        $this->editWorld(function (array &$world) {
            $world['credits'] = 150000;
            $world['waypoints'] = [
                ['symbol' => 'X1-TEST-ROCK', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-YARDA', 'systemSymbol' => 'X1-TEST', 'type' => 'ORBITAL_STATION', 'x' => 2, 'y' => 0, 'traits' => [['symbol' => 'SHIPYARD']]],
                ['symbol' => 'X1-TEST-YARDB', 'systemSymbol' => 'X1-TEST', 'type' => 'ORBITAL_STATION', 'x' => 4, 'y' => 0, 'traits' => [['symbol' => 'SHIPYARD']]],
                ['symbol' => 'X1-TEST-M1', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 10, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-M2', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 0, 'y' => 10, 'traits' => [['symbol' => 'MARKETPLACE']]],
            ];
            $world['shipyards'] = [
                'X1-TEST-YARDA' => [
                    'shipTypes' => [['type' => 'SHIP_MINING_DRONE']],
                    'ships' => [$world['offer']],
                ],
                'X1-TEST-YARDB' => [
                    'shipTypes' => [['type' => 'SHIP_PROBE']],
                    'ships' => [$this->probeOffer(24000)],
                ],
            ];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->seedDiscoveryDone();
        $grant = $this->approve($this->bootstrapBody(target: 3, maxPrice: 50000, minWorkingCapital: 75000));

        // First director pass: discovers both shipyards, selects X1-TEST-YARDB, dispatches LOCAL-2 to buy probe #2.
        $this->tickDirector();
        $buy1 = AutomationRun::where('strategy', 'buy_probe')->latest('id')->firstOrFail();
        $this->assertSame('LOCAL-2', $buy1->ship_symbol);
        $this->assertSame('X1-TEST-YARDB', $buy1->plan['shipyard']);
        $this->assertSame(6, $buy1->plan_version);

        // Execute buy_probe run #1 to completion.
        $this->runToCompletion($buy1);
        $this->assertSame('completed', $buy1->fresh()->state->value, $buy1->fresh()->blocked_reason ?? '');
        $this->assertSame(['LOCAL-3'], $buy1->fresh()->totals['purchased_ships']);
        $this->assertSame(126000, $this->agent->fresh()->credits);
        $this->assertSame(2, app(CampaignProbeBootstrap::class)->ownedProbeCount($this->agent->fresh()));

        // Next director pass: LOCAL-3 is already at X1-TEST-YARDB and buys probe #3 while LOCAL-2 can scout!
        $this->tickDirector();
        $buy2 = AutomationRun::where('strategy', 'buy_probe')->where('id', '>', $buy1->id)->firstOrFail();
        $this->assertSame('X1-TEST-YARDB', $buy2->plan['shipyard']);
        $this->runToCompletion($buy2);
        $this->assertSame('completed', $buy2->fresh()->state->value, $buy2->fresh()->blocked_reason ?? '');
        $this->assertSame(['LOCAL-4'], $buy2->fresh()->totals['purchased_ships']);
        $this->assertSame(102000, $this->agent->fresh()->credits);
        $this->assertSame(3, app(CampaignProbeBootstrap::class)->ownedProbeCount($this->agent->fresh()));

        // Subsequent director pass must NOT buy a 4th probe.
        $this->tickDirector();
        $this->assertSame(2, AutomationRun::where('strategy', 'buy_probe')->count());
        $this->assertSame(2, CampaignEvent::where('kind', 'probe_purchased')->count());
        $this->assertSame(0, app(CreditAccounting::class)->summary($this->agent->fresh())['unexplained_change']);
        $this->assertNull($grant->fresh()->paused_at);

        // CampaignStatus labels all 3 pool probes as 'probe'.
        $status = app(CampaignStatus::class)->read();
        $roles = collect($status['ships'])->pluck('role', 'symbol')->all();
        $this->assertSame('command', $roles['LOCAL-1']);
        $this->assertSame('probe', $roles['LOCAL-2']);
        $this->assertSame('probe', $roles['LOCAL-3']);
        $this->assertSame('probe', $roles['LOCAL-4']);
    }

    public function test_probe_bootstrap_respects_max_price_working_capital_and_recon_return_exposure(): void
    {
        // Case A: probe price exceeds max_price -> no buy_ship write, shipyard enters backoff.
        $this->setBalance(200000);
        $this->editWorld(function (array &$world) {
            $world['ships'][1]['nav']['waypointSymbol'] = 'X1-TEST-YARDB';
            $world['waypoints'] = [
                ['symbol' => 'X1-TEST-ROCK', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-YARDB', 'systemSymbol' => 'X1-TEST', 'type' => 'ORBITAL_STATION', 'x' => 4, 'y' => 0, 'traits' => [['symbol' => 'SHIPYARD']]],
            ];
            $world['shipyards'] = [
                'X1-TEST-YARDB' => [
                    'shipTypes' => [['type' => 'SHIP_PROBE']],
                    'ships' => [$this->probeOffer(55000)],
                ],
            ];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->seedDiscoveryDone();
        $grant = $this->approve($this->bootstrapBody(target: 3, maxPrice: 50000, minWorkingCapital: 75000));
        $this->tickDirector();
        $this->assertSame(0, AutomationRun::where('strategy', 'buy_probe')->count());
        $this->assertEmpty($this->world()['writes']);

        // Case B: price fits max_price (30k <= 50k), but credits (100k - 30k = 70k) < min_working_capital (75k).
        $this->setBalance(100000);
        $this->editWorld(function (array &$world) {
            $world['shipyards']['X1-TEST-YARDB']['ships'] = [$this->probeOffer(30000)];
        });
        $this->tickDirector();
        $this->assertSame(0, AutomationRun::where('strategy', 'buy_probe')->count());
        $this->assertEmpty($this->world()['writes']);

        // Case C: credits = 105k, price = 25k (leaves 80k >= 75k), BUT open ReconMission holds 10k return exposure (needs 85k!).
        $this->setBalance(105000);
        $this->editWorld(function (array &$world) {
            $world['shipyards']['X1-TEST-YARDB']['ships'] = [$this->probeOffer(25000)];
        });
        $this->createReconMission('LOCAL-99', 10000);
        $this->assertSame(10000, ReconMission::returnExposure($this->agent->fresh()));
        $this->tickDirector();
        $this->assertSame(0, AutomationRun::where('strategy', 'buy_probe')->count());
        $this->assertEmpty($this->world()['writes']);
        $this->assertNull($grant->fresh()->paused_at, $grant->fresh()->pause_reason ?? '');
    }

    public function test_probe_bootstrap_blocks_in_wind_down_arms_and_never_double_buys(): void
    {
        $this->editWorld(function (array &$world) {
            $world['credits'] = 150000;
            $world['ships'][] = $this->probeShip('LOCAL-3', 'X1-TEST-YARDB');
            $world['ships'][1]['nav']['waypointSymbol'] = 'X1-TEST-YARDB';
            $world['waypoints'] = [
                ['symbol' => 'X1-TEST-ROCK', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-YARDB', 'systemSymbol' => 'X1-TEST', 'type' => 'ORBITAL_STATION', 'x' => 4, 'y' => 0, 'traits' => [['symbol' => 'SHIPYARD']]],
            ];
            $world['shipyards'] = [
                'X1-TEST-YARDB' => [
                    'shipTypes' => [['type' => 'SHIP_PROBE']],
                    'ships' => [$this->probeOffer(24000)],
                ],
            ];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->seedDiscoveryDone();
        // Owned = 2 probes, target = 3 -> only 1 more probe should ever be bought.
        $grant = $this->approve($this->bootstrapBody(target: 3, maxPrice: 50000, minWorkingCapital: 75000));

        // Pass 1 starts one buy_probe run (not yet executed).
        $this->tickDirector();
        $this->assertSame(1, AutomationRun::where('strategy', 'buy_probe')->whereNotNull('active_agent_id')->count());

        // Pass 2 while that buy_probe run is STILL active must NOT start a second buy_probe run on the other idle probe!
        $this->tickDirector();
        $this->assertSame(1, AutomationRun::where('strategy', 'buy_probe')->count());

        // Wind-down must reject buy_ship in CampaignSafety::check.
        $episode = CampaignEpisode::firstOrFail();
        $run = AutomationRun::where('strategy', 'buy_probe')->firstOrFail();
        $episode->update(['phase' => 'wind_down']);
        try {
            app(CampaignSafety::class)->check($run, $this->agent->fresh(), 'buy_ship', ['shipType' => 'SHIP_PROBE', 'maxTotal' => 24000]);
            $this->fail('Wind-down must block buy_ship.');
        } catch (GameException $e) {
            $this->assertStringContainsString('Wind-down', $e->getMessage());
        }

        // Arm grant must never authorize probe bootstrap.
        $revision = app(CampaignPlaybook::class)->import($this->bootstrapBody(target: 5, maxPrice: 50000, minWorkingCapital: 75000));
        $armGrant = CampaignGrant::create([
            'campaign_revision_id' => $revision->id,
            'revision_hash' => $revision->content_hash,
            'user_id' => $this->user->id,
            'slot' => null,
            'limits' => ['reserve_credits' => 0, 'run_max_minutes' => 15, 'arm' => ['label' => 'H1', 'ship' => 'LOCAL-1']],
        ]);
        $armEpisode = CampaignEpisode::create([
            'campaign_grant_id' => $armGrant->id,
            'agent_id' => $this->agent->id,
            'reset_id' => $this->agent->reset_id,
            'start_credits' => 150000,
            'phase' => 'earn',
            'opened_at' => now(),
            'next_reset_at' => now()->addDays(5),
            'state' => ['arm' => 'H1', 'role_ships' => ['command' => 'LOCAL-1']],
        ]);
        $this->assertNull(CampaignProbeBootstrap::policy($armGrant, $armEpisode));
        $this->assertFalse(app(CampaignFleet::class)->poolEnabled($armEpisode));
        $this->assertNotNull($grant);
    }

    public function test_retryable_scout_deferral_stops_pool_selection_for_the_pass(): void
    {
        $this->editWorld(function (array &$world) {
            $world['ships'][] = $this->probeShip('LOCAL-3', 'X1-TEST-ROCK');
            $world['ships'][] = $this->probeShip('LOCAL-4', 'X1-TEST-ROCK');
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $sync = app(AgentSynchronizer::class);
        foreach (['X1-TEST-A0FUEL', 'X1-TEST-M1', 'X1-TEST-M2'] as $market) {
            $sync->observe($this->agent, 'market_listing', $market, [
                'symbol' => $market, 'imports' => [['symbol' => 'IRON_ORE']], 'exports' => [], 'exchange' => [['symbol' => 'FUEL']],
            ]);
        }
        $grant = $this->approve($this->bootstrapBody(target: 3, maxPrice: 50000, minWorkingCapital: 75000));
        $episode = CampaignEpisode::create([
            'campaign_grant_id' => $grant->id, 'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id,
            'start_credits' => $this->agent->credits, 'phase' => 'earn', 'opened_at' => now(), 'next_reset_at' => now()->addDays(5),
            'state' => ['last_discovery_at' => now()->toIso8601String(), 'role_ships' => ['command' => 'LOCAL-1', 'probe' => 'LOCAL-2']],
        ]);
        AutomationRun::create([
            'agent_id' => $this->agent->id, 'active_agent_id' => $this->agent->id, 'campaign_episode_id' => $episode->id,
            'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-1', 'strategy' => 'trade', 'state' => 'running',
            'started_at' => now(), 'expires_at' => now()->addMinutes(15), 'plan' => [], 'totals' => [],
        ]);
        $this->partialMock(AutomationRunner::class)->shouldReceive('preview')->once()
            ->andThrow(new RequestDeferred('Synthetic market budget deferral.', 10));

        $this->tickDirector();

        $this->assertSame('awaiting_run_start', $grant->fresh()->wait_reason);
        $this->assertNull($grant->fresh()->paused_at);
        $this->assertFalse(AutomationRun::where('strategy', 'scout')->exists());
    }

    public function test_multi_probe_pool_scouts_distinct_markets_concurrently_while_command_ship_trades(): void
    {
        $this->editWorld(function (array &$world) {
            $world['ships'][] = $this->probeShip('LOCAL-3', 'X1-TEST-ROCK');
            $world['ships'][] = $this->probeShip('LOCAL-4', 'X1-TEST-ROCK');
            $world['waypoints'] = [
                ['symbol' => 'X1-TEST-ROCK', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-A0FUEL', 'systemSymbol' => 'X1-TEST', 'type' => 'FUEL_STATION', 'x' => 1, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-M1', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 5, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-M2', 'systemSymbol' => 'X1-TEST', 'type' => 'ORBITAL_STATION', 'x' => 0, 'y' => 5, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-M3', 'systemSymbol' => 'X1-TEST', 'type' => 'MOON', 'x' => -5, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
            ];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $sync = app(AgentSynchronizer::class);
        $dir = ['imports' => [['symbol' => 'IRON_ORE']], 'exports' => [], 'exchange' => [['symbol' => 'FUEL']]];
        foreach (['X1-TEST-A0FUEL', 'X1-TEST-M1', 'X1-TEST-M2', 'X1-TEST-M3'] as $market) {
            $sync->observe($this->agent, 'market_listing', $market, ['symbol' => $market] + $dir);
        }
        $grant = $this->approve($this->bootstrapBody(target: 3, maxPrice: 50000, minWorkingCapital: 75000));
        $episode = CampaignEpisode::create([
            'campaign_grant_id' => $grant->id,
            'agent_id' => $this->agent->id,
            'reset_id' => $this->agent->reset_id,
            'start_credits' => 150000,
            'phase' => 'earn',
            'opened_at' => now(),
            'next_reset_at' => now()->addDays(5),
            'state' => ['last_discovery_at' => now()->toIso8601String(), 'role_ships' => ['command' => 'LOCAL-1', 'probe' => 'LOCAL-2']],
        ]);

        // Simulate command ship LOCAL-1 actively running a trade run.
        $commandRun = AutomationRun::create([
            'agent_id' => $this->agent->id,
            'active_agent_id' => $this->agent->id,
            'campaign_episode_id' => $episode->id,
            'reset_id' => $this->agent->reset_id,
            'ship_symbol' => 'LOCAL-1',
            'strategy' => 'trade',
            'plan_version' => 3,
            'state' => 'running',
            'started_at' => now(),
            'expires_at' => now()->addMinutes(15),
            'plan' => ['strategy' => 'trade', 'site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-M1', 'symbol' => 'IRON_ORE', 'units' => 10, 'reserve' => 0, 'max_spend' => 1000, 'max_loss' => 1000, 'cycles' => 1, 'minutes' => 15],
            'totals' => ['phase' => 'buy', 'credit_delta' => 0, 'spending' => 0],
        ]);

        // One director pass must leave LOCAL-1's trade run untouched AND dispatch all 3 probes to 3 distinct trade hubs (ahead of bare FUEL_STATION X1-TEST-A0-FUEL!).
        $this->tickDirector();
        $this->assertNotNull($commandRun->fresh()->active_agent_id);
        $probeRuns = AutomationRun::where('active_agent_id', $this->agent->id)
            ->where('campaign_episode_id', $episode->id)
            ->where('strategy', 'scout')
            ->orderBy('ship_symbol')
            ->get();
        $this->assertCount(3, $probeRuns);
        $this->assertSame(['LOCAL-2', 'LOCAL-3', 'LOCAL-4'], $probeRuns->pluck('ship_symbol')->all());
        $destinations = $probeRuns->map(fn ($run) => $run->plan['destination'])->sort()->values()->all();
        $this->assertSame(['X1-TEST-M1', 'X1-TEST-M2', 'X1-TEST-M3'], $destinations);
    }

    public function test_pool_probe_does_not_duplicate_an_active_command_ship_scout_destination(): void
    {
        $this->editWorld(function (array &$world) {
            $world['waypoints'] = [
                ['symbol' => 'X1-TEST-ROCK', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-M1', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 5, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-M2', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 10, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
            ];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        foreach (['X1-TEST-M1', 'X1-TEST-M2'] as $market) {
            app(AgentSynchronizer::class)->observe($this->agent, 'market_listing', $market, [
                'symbol' => $market, 'imports' => [['symbol' => 'IRON_ORE']], 'exports' => [], 'exchange' => [['symbol' => 'FUEL']],
            ]);
        }
        $grant = $this->approve($this->bootstrapBody(target: 1, maxPrice: 50000, minWorkingCapital: 75000));
        $episode = CampaignEpisode::create([
            'campaign_grant_id' => $grant->id,
            'agent_id' => $this->agent->id,
            'reset_id' => $this->agent->reset_id,
            'start_credits' => 150000,
            'phase' => 'discover',
            'opened_at' => now(),
            'next_reset_at' => now()->addDays(5),
            'state' => ['last_discovery_at' => now()->toIso8601String(), 'role_ships' => ['command' => 'LOCAL-1', 'probe' => 'LOCAL-2']],
        ]);
        $commandRun = AutomationRun::create([
            'agent_id' => $this->agent->id,
            'active_agent_id' => $this->agent->id,
            'campaign_episode_id' => $episode->id,
            'reset_id' => $this->agent->reset_id,
            'ship_symbol' => 'LOCAL-1',
            'strategy' => 'scout',
            'plan_version' => 3,
            'state' => 'running',
            'started_at' => now(),
            'expires_at' => now()->addMinutes(15),
            'plan' => ['strategy' => 'scout', 'site' => 'X1-TEST-ROCK', 'destination' => 'X1-TEST-M1', 'market' => 'X1-TEST-M1', 'one_way' => true, 'reserve' => 0, 'max_spend' => 0, 'max_loss' => 0, 'cycles' => 1, 'minutes' => 15],
            'totals' => ['phase' => 'travel', 'credit_delta' => 0, 'spending' => 0],
        ]);

        $this->tickDirector();

        $this->assertNotNull($commandRun->fresh()->active_agent_id);
        $probeRun = AutomationRun::where('ship_symbol', 'LOCAL-2')->whereNotNull('active_agent_id')->sole();
        $this->assertSame('scout', $probeRun->strategy);
        $this->assertSame('X1-TEST-M2', $probeRun->plan['destination']);
    }

    public function test_multi_probe_pool_keeps_remaining_probes_active_when_recon_holds_one_probe(): void
    {
        $this->editWorld(function (array &$world) {
            $world['ships'][] = $this->probeShip('LOCAL-3', 'X1-TEST-ROCK');
            $world['ships'][] = $this->probeShip('LOCAL-4', 'X1-TEST-ROCK');
            $world['waypoints'] = [
                ['symbol' => 'X1-TEST-ROCK', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-M1', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 5, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-M2', 'systemSymbol' => 'X1-TEST', 'type' => 'ORBITAL_STATION', 'x' => 0, 'y' => 5, 'traits' => [['symbol' => 'MARKETPLACE']]],
            ];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $sync = app(AgentSynchronizer::class);
        $dir = ['imports' => [['symbol' => 'IRON_ORE']], 'exports' => [], 'exchange' => [['symbol' => 'FUEL']]];
        foreach (['X1-TEST-M1', 'X1-TEST-M2'] as $market) {
            $sync->observe($this->agent, 'market_listing', $market, ['symbol' => $market] + $dir);
        }

        // Open ReconMission holding LOCAL-2.
        $this->createReconMission('LOCAL-2', 5000);

        // Legacy playbook (without probes) still returns null for role('probe').
        $legacyGrant = $this->approve(str_replace('GIANTSOUP', 'LOCAL', file_get_contents(base_path('campaigns/weekly-default.yaml'))));
        $legacyEpisode = CampaignEpisode::create([
            'campaign_grant_id' => $legacyGrant->id,
            'agent_id' => $this->agent->id,
            'reset_id' => $this->agent->reset_id,
            'start_credits' => 150000,
            'phase' => 'earn',
            'opened_at' => now(),
            'next_reset_at' => now()->addDays(5),
            'state' => ['role_ships' => ['command' => 'LOCAL-1', 'probe' => 'LOCAL-2']],
        ]);
        $this->assertNull(app(CampaignFleet::class)->role($legacyEpisode, 'probe'));

        // Switch to pool playbook: LOCAL-2 is excluded, LOCAL-3 & LOCAL-4 remain active!
        $legacyEpisode->update(['closed_at' => now()]);
        $legacyGrant->update(['slot' => null, 'revoked_at' => now()]);
        $grant = $this->approve($this->bootstrapBody(target: 3, maxPrice: 50000, minWorkingCapital: 75000));
        $episode = CampaignEpisode::create([
            'campaign_grant_id' => $grant->id,
            'agent_id' => $this->agent->id,
            'reset_id' => $this->agent->reset_id,
            'start_credits' => 150000,
            'phase' => 'earn',
            'opened_at' => now(),
            'next_reset_at' => now()->addDays(5),
            'state' => ['last_discovery_at' => now()->toIso8601String(), 'role_ships' => ['command' => 'LOCAL-1', 'probe' => 'LOCAL-2']],
        ]);

        $fleet = app(CampaignFleet::class);
        $this->assertSame(['LOCAL-3', 'LOCAL-4'], array_column($fleet->probes($episode), 'symbol'));
        $this->assertSame(['LOCAL-1', 'LOCAL-3', 'LOCAL-4'], $fleet->symbols($episode));

        $this->tickDirector();
        $probeRuns = AutomationRun::where('active_agent_id', $this->agent->id)
            ->where('campaign_episode_id', $episode->id)
            ->where('strategy', 'scout')
            ->orderBy('ship_symbol')
            ->get();
        $this->assertSame(['LOCAL-3', 'LOCAL-4'], $probeRuns->pluck('ship_symbol')->all());
        $this->assertSame(['X1-TEST-M1', 'X1-TEST-M2'], $probeRuns->map(fn ($run) => $run->plan['destination'])->sort()->values()->all());
    }

    public function test_local_earn_refusal_does_not_pause_campaign_when_pool_probe_has_active_scout_run(): void
    {
        $this->editWorld(function (array &$world) {
            $world['waypoints'] = [
                ['symbol' => 'X1-TEST-ROCK', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-PORT', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 3, 'y' => 4, 'traits' => [['symbol' => 'MARKETPLACE']]],
            ];
            $world['market_goods'] = [
                'X1-TEST-ROCK' => [
                    ['symbol' => 'IRON_ORE', 'tradeVolume' => 10, 'purchasePrice' => 50, 'sellPrice' => 40],
                    ['symbol' => 'FUEL', 'tradeVolume' => 10, 'purchasePrice' => 5, 'sellPrice' => 3],
                ],
                'X1-TEST-PORT' => [
                    ['symbol' => 'IRON_ORE', 'tradeVolume' => 10, 'purchasePrice' => 100, 'sellPrice' => 90],
                    ['symbol' => 'FUEL', 'tradeVolume' => 10, 'purchasePrice' => 5, 'sellPrice' => 3],
                ],
            ];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $sync = app(AgentSynchronizer::class);
        $dir = ['imports' => [['symbol' => 'IRON_ORE']], 'exports' => [['symbol' => 'IRON_ORE']], 'exchange' => [['symbol' => 'FUEL']]];
        foreach (['X1-TEST-ROCK', 'X1-TEST-PORT'] as $market) {
            $sync->observe($this->agent, 'market_listing', $market, ['symbol' => $market] + $dir);
        }
        // Stale buyer quote at X1-TEST-PORT (no ship parked there) so localTrade hits StaleQuote refusal.
        $this->travel(-200)->seconds();
        $sync->observe($this->agent, 'market', 'X1-TEST-PORT', ['symbol' => 'X1-TEST-PORT', 'tradeGoods' => $this->world()['market_goods']['X1-TEST-PORT']] + $dir);
        $this->travel(200)->seconds();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);

        $body = Yaml::parse($this->bootstrapBody(target: 1, maxPrice: 50000, minWorkingCapital: 75000));
        $body['phases'] = array_values(array_slice($body['phases'], 1));
        $body['trade']['local_earn'] = [
            'projection_limit' => 8,
            'max_attempts' => 2,
            'position_evidence_max_age_seconds' => 300,
            'max_positionings_without_trade' => 1,
        ];
        $grant = app(CampaignControl::class)->approve(
            app(CampaignPlaybook::class)->import(Yaml::dump($body, 10)),
            $this->user,
            now()->addDays(2)->toImmutable()
        );
        $episode = CampaignEpisode::create([
            'campaign_grant_id' => $grant->id,
            'agent_id' => $this->agent->id,
            'reset_id' => $this->agent->reset_id,
            'start_credits' => 150000,
            'phase' => 'earn',
            'opened_at' => now(),
            'next_reset_at' => now()->addDays(5),
            'state' => ['last_discovery_at' => now()->toIso8601String(), 'role_ships' => ['command' => 'LOCAL-1', 'probe' => 'LOCAL-2']],
        ]);

        // Pre-existing active scout run on probe LOCAL-2.
        AutomationRun::create([
            'agent_id' => $this->agent->id,
            'active_agent_id' => $this->agent->id,
            'campaign_episode_id' => $episode->id,
            'reset_id' => $this->agent->reset_id,
            'ship_symbol' => 'LOCAL-2',
            'strategy' => 'scout',
            'plan_version' => 3,
            'state' => 'running',
            'started_at' => now(),
            'expires_at' => now()->addMinutes(15),
            'plan' => ['strategy' => 'scout', 'site' => 'X1-TEST-ROCK', 'destination' => 'X1-TEST-PORT', 'market' => 'X1-TEST-PORT', 'one_way' => true, 'reserve' => 0, 'max_spend' => 0, 'max_loss' => 0, 'cycles' => 1, 'minutes' => 15],
            'totals' => ['phase' => 'observe', 'credit_delta' => 0, 'spending' => 0],
        ]);

        $this->tickDirector();
        $this->assertNull($grant->fresh()->paused_at, 'Pre-existing pool probe run must not cause local_earn refusal to pause C0.');
    }

    public function test_pool_false_uses_serialized_bootstrap_and_legacy_single_probe_semantics(): void
    {
        $this->editWorld(function (array &$world) {
            $world['credits'] = 150000;
            $world['waypoints'] = [
                ['symbol' => 'X1-TEST-ROCK', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-YARDB', 'systemSymbol' => 'X1-TEST', 'type' => 'ORBITAL_STATION', 'x' => 4, 'y' => 0, 'traits' => [['symbol' => 'SHIPYARD']]],
                ['symbol' => 'X1-TEST-M1', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 10, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
            ];
            $world['shipyards'] = [
                'X1-TEST-YARDB' => [
                    'shipTypes' => [['type' => 'SHIP_PROBE']],
                    'ships' => [$this->probeOffer(24000)],
                ],
            ];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->seedDiscoveryDone();
        app(AgentSynchronizer::class)->observe($this->agent, 'market_listing', 'X1-TEST-M1', [
            'symbol' => 'X1-TEST-M1', 'imports' => [['symbol' => 'IRON_ORE']], 'exports' => [], 'exchange' => [['symbol' => 'FUEL']],
        ]);
        $this->approve($this->bootstrapBody(target: 2, maxPrice: 50000, minWorkingCapital: 75000, pool: false));

        // First pass starts buy_probe and returns immediately (serialized, no concurrent scout).
        $this->tickDirector();
        $runs = AutomationRun::whereNotNull('active_agent_id')->get();
        $this->assertCount(1, $runs);
        $this->assertSame('buy_probe', $runs[0]->strategy);

        $episode = CampaignEpisode::firstOrFail();
        $this->assertFalse(app(CampaignFleet::class)->poolEnabled($episode));
        $this->runToCompletion($runs[0]);
        $this->assertSame('completed', $runs[0]->fresh()->state->value);
        $this->assertSame(2, app(CampaignProbeBootstrap::class)->ownedProbeCount($this->agent->fresh()));

        // With pool: false, only the single pinned probe is in symbols() and Recon hold clears role('probe').
        $this->assertCount(2, app(CampaignFleet::class)->symbols($episode->fresh()));
        $this->createReconMission('LOCAL-2', 5000);
        $this->assertNull(app(CampaignFleet::class)->role($episode->fresh(), 'probe'));
    }

    public function test_command_ship_fallback_buys_probe_with_fuel_and_reports_ship_price_without_fuel(): void
    {
        $this->editWorld(function (array &$world) {
            $world['credits'] = 150000;
            // Remove starter probe so command ship LOCAL-1 must act as fallback buyer.
            $world['ships'] = [$world['ships'][0]];
            $world['ships'][0]['fuel'] = ['current' => 50, 'capacity' => 100];
            $world['waypoints'] = [
                ['symbol' => 'X1-TEST-ROCK', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-YARDB', 'systemSymbol' => 'X1-TEST', 'type' => 'ORBITAL_STATION', 'x' => 10, 'y' => 0, 'traits' => [['symbol' => 'SHIPYARD']]],
            ];
            $world['shipyards'] = [
                'X1-TEST-YARDB' => [
                    'shipTypes' => [['type' => 'SHIP_PROBE']],
                    'ships' => [$this->probeOffer(24000)],
                ],
            ];
        });
        Ship::where('agent_id', $this->agent->id)->where('symbol', 'LOCAL-2')->delete();
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->seedDiscoveryDone();
        $this->approve($this->bootstrapBody(target: 1, maxPrice: 50000, minWorkingCapital: 75000));

        $this->tickDirector();
        $buy = AutomationRun::where('strategy', 'buy_probe')->firstOrFail();
        $this->assertSame('LOCAL-1', $buy->ship_symbol);
        $this->assertGreaterThan(0, $buy->plan['route']['departure_refuel_ceiling']);

        $this->runToCompletion($buy);
        $this->assertSame('completed', $buy->fresh()->state->value, $buy->fresh()->blocked_reason ?? '');
        $this->assertGreaterThan(0, $buy->fresh()->totals['fuel_cost']);
        $this->assertSame(1, app(CampaignProbeBootstrap::class)->ownedProbeCount($this->agent->fresh()));
        $this->assertSame(0, app(CreditAccounting::class)->summary($this->agent->fresh())['unexplained_change']);

        // probe_purchased event must report exact ship price (24000), excluding departure fuel spend!
        $event = CampaignEvent::where('kind', 'probe_purchased')->latest('id')->firstOrFail();
        $this->assertSame(24000, $event->data['price']);
        // Acting ship state (LOCAL-1), not delivered probe state, stays in totals.expected_state.
        $this->assertGreaterThan(0, $buy->fresh()->totals['expected_state']['fuel']);
    }

    public function test_concurrent_trade_and_buy_probe_enforce_additive_reserve_and_complete_cleanly(): void
    {
        $this->editWorld(function (array &$world) {
            $world['credits'] = 140000;
            $world['ships'][1]['nav']['waypointSymbol'] = 'X1-TEST-YARDB';
            $world['waypoints'] = [
                ['symbol' => 'X1-TEST-ROCK', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-PORT', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 3, 'y' => 4, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-YARDB', 'systemSymbol' => 'X1-TEST', 'type' => 'ORBITAL_STATION', 'x' => 4, 'y' => 0, 'traits' => [['symbol' => 'SHIPYARD']]],
            ];
            $world['market_goods'] = [
                'X1-TEST-ROCK' => [
                    ['symbol' => 'IRON_ORE', 'tradeVolume' => 10, 'purchasePrice' => 50, 'sellPrice' => 40],
                    ['symbol' => 'FUEL', 'tradeVolume' => 10, 'purchasePrice' => 5, 'sellPrice' => 3],
                ],
                'X1-TEST-PORT' => [
                    ['symbol' => 'IRON_ORE', 'tradeVolume' => 10, 'purchasePrice' => 200, 'sellPrice' => 150],
                    ['symbol' => 'FUEL', 'tradeVolume' => 10, 'purchasePrice' => 5, 'sellPrice' => 3],
                ],
            ];
            $world['shipyards'] = [
                'X1-TEST-YARDB' => [
                    'shipTypes' => [['type' => 'SHIP_PROBE']],
                    'ships' => [$this->probeOffer(40000)],
                ],
            ];
        });
        $this->setBalance(140000);
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->seedDiscoveryDone();
        $dir = ['imports' => [['symbol' => 'IRON_ORE']], 'exports' => [['symbol' => 'IRON_ORE']], 'exchange' => [['symbol' => 'FUEL']]];
        foreach (['X1-TEST-ROCK', 'X1-TEST-PORT'] as $market) {
            app(AgentSynchronizer::class)->observe($this->agent, 'market_listing', $market, ['symbol' => $market] + $dir);
            app(AgentSynchronizer::class)->observe($this->agent, 'market', $market, ['symbol' => $market, 'tradeGoods' => $this->world()['market_goods'][$market]] + $dir);
        }

        $grant = $this->approve($this->bootstrapBody(target: 2, maxPrice: 50000, minWorkingCapital: 75000));
        $episode = CampaignEpisode::create([
            'campaign_grant_id' => $grant->id,
            'agent_id' => $this->agent->id,
            'reset_id' => $this->agent->reset_id,
            'start_credits' => 140000,
            'phase' => 'earn',
            'opened_at' => now(),
            'next_reset_at' => now()->addDays(5),
            'state' => ['last_discovery_at' => now()->toIso8601String(), 'role_ships' => ['command' => 'LOCAL-1', 'probe' => 'LOCAL-2']],
        ]);

        // Active trade in buy phase with 40k remaining max_spend:
        // Additive reserve = 75k (working capital) + 40k (active trade) = 115k.
        // 140k - 40k (probe) = 100k < 115k -> MUST NOT start buy_probe while trade commits 40k!
        $trade = AutomationRun::create([
            'agent_id' => $this->agent->id,
            'active_agent_id' => $this->agent->id,
            'campaign_episode_id' => $episode->id,
            'reset_id' => $this->agent->reset_id,
            'ship_symbol' => 'LOCAL-1',
            'strategy' => 'trade',
            'plan_version' => 3,
            'state' => 'running',
            'started_at' => now(),
            'expires_at' => now()->addMinutes(15),
            'plan' => ['strategy' => 'trade', 'site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-PORT', 'symbol' => 'IRON_ORE', 'units' => 10, 'reserve' => 0, 'max_spend' => 40000, 'max_loss' => 40000, 'cycles' => 1, 'minutes' => 15],
            'totals' => ['phase' => 'buy', 'credit_delta' => 0, 'spending' => 0],
        ]);
        $this->tickDirector();
        $this->assertSame(0, AutomationRun::where('strategy', 'buy_probe')->count());

        // Raise balance to 160k (160k - 40k = 120k >= 115k) -> buy_probe starts!
        $this->setBalance(160000);
        $this->tickDirector();
        $buy = AutomationRun::where('strategy', 'buy_probe')->firstOrFail();
        // Execute buy_ship action (spends 40k -> balance 120k), then simulate concurrent trade spending 10k (balance 110k < buy_probe reserve 115k!).
        app(AutomationRunner::class)->advance($buy->id);
        $buyAction = ShipAction::where('automation_run_id', $buy->id)->where('command', 'buy_ship')->firstOrFail();
        app(ShipCommandExecutor::class)->execute($buyAction->id);
        $this->assertSame('confirmed', $buyAction->fresh()->state->value);
        // Simulate confirmed trade debit pushing credits below buy_probe's pre-spend reserve before buy_probe's final advance.
        $tradeDebit = ShipAction::create([
            'agent_id' => $this->agent->id,
            'automation_run_id' => $trade->id,
            'key' => 'sim-trade-buy',
            'reset_id' => $this->agent->reset_id,
            'ship_symbol' => 'LOCAL-1',
            'origin' => 'automation',
            'target_type' => 'ship',
            'target_id' => 'LOCAL-1',
            'command' => 'buy',
            'arguments' => ['symbol' => 'IRON_ORE', 'units' => 10, 'maxTotal' => 10000, 'reserve' => 0],
            'state' => 'confirmed',
            'outcome' => ['credit_delta' => -10000, 'transaction_total' => 10000],
            'settled_at' => now(),
            'expires_at' => now()->addMinutes(3),
        ]);
        app(CreditAccounting::class)->record($this->agent, $tradeDebit, -10000);
        $this->editWorld(fn (array &$world) => $world['credits'] = 110000);
        $this->agent->update(['credits' => 110000]);

        // Final advance of buy_probe MUST complete and emit probe_purchased rather than blocking on reserve!
        app(AutomationRunner::class)->advance($buy->id);
        $this->assertSame('completed', $buy->fresh()->state->value, $buy->fresh()->blocked_reason ?? '');
        $this->assertSame(1, CampaignEvent::where('kind', 'probe_purchased')->count());
        $this->assertSame(0, app(CreditAccounting::class)->summary($this->agent->fresh())['unexplained_change']);
    }

    public static function deliveryFuelCommitments(): array
    {
        return [
            'fresh trade fuel quote' => ['trade', true, 10],
            'expired trade fuel quote' => ['trade', false, 20],
            'recovery approved fuel ceiling' => ['sell_aboard', false, 20],
        ];
    }

    #[DataProvider('deliveryFuelCommitments')]
    public function test_probe_purchase_protects_loaded_delivery_fuel_at_compose_arrival_and_send(string $strategy, bool $freshQuote, int $reserve): void
    {
        $this->editWorld(function (array &$world) {
            $world['credits'] = 40000;
            $world['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 10, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 10]]];
            $world['ships'][1]['nav']['waypointSymbol'] = 'X1-TEST-YARDB';
            $world['waypoints'][] = ['symbol' => 'X1-TEST-YARDB', 'systemSymbol' => 'X1-TEST', 'type' => 'ORBITAL_STATION', 'x' => 4, 'y' => 0, 'traits' => [['symbol' => 'SHIPYARD']]];
            $world['shipyards'] = ['X1-TEST-YARDB' => ['shipTypes' => [['type' => 'SHIP_PROBE']], 'ships' => [$this->probeOffer(40000)]]];
        });
        $this->setBalance(40000);
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->seedDiscoveryDone();
        $sync = app(AgentSynchronizer::class);
        $market = ['symbol' => 'X1-TEST-PORT', 'imports' => [], 'exports' => [], 'exchange' => [['symbol' => 'FUEL']]];
        $sync->observe($this->agent, 'market_listing', 'X1-TEST-PORT', $market);
        $sync->observe($this->agent, 'market', 'X1-TEST-PORT', $market + ['tradeGoods' => $this->world()['goods']]);
        if (! $freshQuote) {
            GameObservation::where('agent_id', $this->agent->id)->where('kind', 'market')->update(['observed_at' => now()->subSeconds(config('gameplay.fuel_quote_max_seconds') + 1)]);
        }
        $grant = $this->approve($this->bootstrapBody(target: 2, maxPrice: 50000, minWorkingCapital: 0));
        $episode = CampaignEpisode::create([
            'campaign_grant_id' => $grant->id, 'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id,
            'start_credits' => 40000, 'phase' => 'earn', 'opened_at' => now(), 'next_reset_at' => now()->addDays(5),
            'state' => ['role_ships' => ['command' => 'LOCAL-1', 'probe' => 'LOCAL-2']],
        ]);
        $bootstrap = app(CampaignProbeBootstrap::class);
        $limits = ['reserve' => 0, 'minutes' => 15, 'cycles' => 1];
        $runner = app(AutomationRunner::class);
        $buy = $runner->previewProbePurchase($this->agent->fresh(), 'LOCAL-2', 'X1-TEST-YARDB', $limits, $episode);
        $runner->start($this->agent->fresh(), $buy);
        $this->assertSame(0, $buy->plan['reserve']);

        // A concurrent trade commits its loaded cargo after the probe purchase was approved.
        AutomationRun::create([
            'agent_id' => $this->agent->id, 'active_agent_id' => $this->agent->id, 'campaign_episode_id' => $episode->id,
            'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-1', 'strategy' => $strategy,
            'plan_version' => $strategy === 'trade' ? 3 : 4, 'state' => 'running', 'started_at' => now(), 'expires_at' => now()->addMinutes(15),
            'plan' => ['market' => 'X1-TEST-PORT', 'max_spend' => 1000, 'route' => ['fuel_ceiling' => 20]]
                + ($strategy === 'trade' ? ['campaign_trade' => ['fuel_reserve' => 10, 'fuel_safety_ceiling' => 20]] : []),
            'totals' => ['phase' => 'sell', 'spending' => 500],
        ]);
        try {
            $bootstrap->compose($this->agent->fresh(), 'LOCAL-2', 'X1-TEST-YARDB', $limits, $episode, $buy->id);
            $this->fail('Probe preview must preserve loaded delivery fuel.');
        } catch (GameException $e) {
            $this->assertStringContainsString('working-capital reserve '.$reserve.' CR', $e->getMessage());
        }

        $this->setBalance(40000 + $reserve);
        $composed = $bootstrap->compose($this->agent->fresh(), 'LOCAL-2', 'X1-TEST-YARDB', $limits, $episode, $buy->id);
        $this->assertSame($reserve, $composed['plan']['reserve']);
        $ship = Ship::where('symbol', 'LOCAL-2')->firstOrFail()->snapshot;

        // The current balance is checked again after approval, both on arrival and before transport.
        $this->setBalance(40000);
        $step = $bootstrap->next($this->agent->fresh(), $ship, $buy->fresh());
        $this->assertSame('blocked', $step->kind);
        $this->assertStringContainsString('working-capital reserve', $step->reason);
        try {
            app(CampaignSafety::class)->check($buy->fresh(), $this->agent->fresh(), 'buy_ship', ['shipType' => 'SHIP_PROBE', 'maxTotal' => 40000]);
            $this->fail('Purchase transport must preserve loaded delivery fuel.');
        } catch (GameException $e) {
            $this->assertStringContainsString('working-capital reserve', $e->getMessage());
        }
        $this->assertSame(0, ShipAction::where('command', 'buy_ship')->count());

        $this->setBalance(40000 + $reserve);
        $runner->stop($buy);
        $runner->advance($buy->id);
        $buy = $runner->previewProbePurchase($this->agent->fresh(), 'LOCAL-2', 'X1-TEST-YARDB', $limits, $episode);
        $runner->start($this->agent->fresh(), $buy);
        $this->runToCompletion($buy);
        $this->assertSame('completed', $buy->fresh()->state->value, $buy->fresh()->blocked_reason ?? '');
        $this->assertSame($reserve, $this->agent->fresh()->credits);
        if ($freshQuote) {
            $loaded = Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
            $leg = app(FuelPlanner::class)->leg($this->agent->fresh(), $loaded, 'X1-TEST-PORT');
            $this->assertSame(10, $leg['refuel_ceiling']);
        }
    }

    public function test_purchase_slot_serializes_probe_runs_and_arrival_rechecks_the_fleet_target(): void
    {
        $this->editWorld(function (array &$world) {
            $world['ships'][1]['nav']['waypointSymbol'] = 'X1-TEST-YARDB';
            $world['ships'][] = $this->probeShip('LOCAL-3', 'X1-TEST-YARDB');
            $world['waypoints'][] = ['symbol' => 'X1-TEST-YARDB', 'systemSymbol' => 'X1-TEST', 'type' => 'ORBITAL_STATION', 'x' => 4, 'y' => 0, 'traits' => [['symbol' => 'SHIPYARD']]];
            $world['shipyards'] = ['X1-TEST-YARDB' => ['shipTypes' => [['type' => 'SHIP_PROBE']], 'ships' => [$this->probeOffer(24000)]]];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->seedDiscoveryDone();
        $this->approve($this->bootstrapBody(target: 4, maxPrice: 50000, minWorkingCapital: 0));
        $this->tickDirector();
        $this->tickDirector();
        $buys = AutomationRun::where('strategy', 'buy_probe')->whereNotNull('active_agent_id')->orderBy('id')->get();
        $this->assertCount(1, $buys);

        // Ships acquired outside this app still count at arrival.
        $this->editWorld(function (array &$world) {
            $world['ships'][] = $this->probeShip('LOCAL-4', 'X1-TEST-YARDB');
            $world['ships'][] = $this->probeShip('LOCAL-5', 'X1-TEST-YARDB');
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->runToCompletion($buys[0]);
        $this->assertSame('blocked', $buys[0]->fresh()->state->value);
        $this->assertStringContainsString('Probe target', $buys[0]->fresh()->blocked_reason);
        $this->assertSame(0, ShipAction::where('command', 'buy_ship')->count());
    }

    public function test_arrival_price_spike_blocks_and_backs_off_shipyard(): void
    {
        $this->editWorld(function (array &$world) {
            $world['credits'] = 150000;
            $world['waypoints'] = [
                ['symbol' => 'X1-TEST-ROCK', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-YARDB', 'systemSymbol' => 'X1-TEST', 'type' => 'ORBITAL_STATION', 'x' => 4, 'y' => 0, 'traits' => [['symbol' => 'SHIPYARD']]],
            ];
            $world['shipyards'] = [
                'X1-TEST-YARDB' => [
                    'shipTypes' => [['type' => 'SHIP_PROBE']],
                    'ships' => [$this->probeOffer(24000)],
                ],
            ];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->seedDiscoveryDone();
        $this->approve($this->bootstrapBody(target: 2, maxPrice: 50000, minWorkingCapital: 75000));

        // Probe starts at ROCK (remote shipyard price unknown); starts buy_probe run.
        $this->tickDirector();
        $buy = AutomationRun::where('strategy', 'buy_probe')->firstOrFail();

        // Spike probe price at shipyard ABOVE max_price before probe arrives!
        $this->editWorld(function (array &$world) {
            $world['shipyards']['X1-TEST-YARDB']['ships'] = [$this->probeOffer(65000)];
        });
        $this->runToCompletion($buy);
        $this->assertSame('blocked', $buy->fresh()->state->value);
        $this->assertStringContainsString('exceeds max_price', $buy->fresh()->blocked_reason);
        $this->assertSame(0, ShipAction::where('command', 'buy_ship')->count());

        // Next director pass hands off the blocked run and respects 30-min shipyard backoff (no retry!).
        $this->tickDirector();
        $this->assertSame(1, AutomationRun::where('strategy', 'buy_probe')->count());
        $this->assertNotEmpty(CampaignEpisode::firstOrFail()->state['probe_shipyard_rejected']['X1-TEST-YARDB']);
    }

    public function test_pool_trade_does_not_dispatch_duplicate_probe_to_buyer_already_targeted(): void
    {
        $this->editWorld(function (array &$world) {
            $world['credits'] = 150000;
            $world['ships'][] = $this->probeShip('LOCAL-3', 'X1-TEST-ROCK');
            $world['waypoints'] = [
                ['symbol' => 'X1-TEST-ROCK', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-PORT', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 3, 'y' => 4, 'traits' => [['symbol' => 'MARKETPLACE']]],
            ];
            $world['market_goods'] = [
                'X1-TEST-ROCK' => [
                    ['symbol' => 'IRON_ORE', 'tradeVolume' => 10, 'purchasePrice' => 50, 'sellPrice' => 40],
                    ['symbol' => 'FUEL', 'tradeVolume' => 10, 'purchasePrice' => 5, 'sellPrice' => 3],
                ],
                'X1-TEST-PORT' => [
                    ['symbol' => 'IRON_ORE', 'tradeVolume' => 10, 'purchasePrice' => 250, 'sellPrice' => 220],
                    ['symbol' => 'FUEL', 'tradeVolume' => 10, 'purchasePrice' => 5, 'sellPrice' => 3],
                ],
            ];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $sync = app(AgentSynchronizer::class);
        $dir = ['imports' => [['symbol' => 'IRON_ORE']], 'exports' => [['symbol' => 'IRON_ORE']], 'exchange' => [['symbol' => 'FUEL']]];
        foreach (['X1-TEST-ROCK', 'X1-TEST-PORT'] as $market) {
            $sync->observe($this->agent, 'market_listing', $market, ['symbol' => $market] + $dir);
        }
        $sync->observe($this->agent, 'market', 'X1-TEST-PORT', ['symbol' => 'X1-TEST-PORT', 'tradeGoods' => $this->world()['market_goods']['X1-TEST-PORT']] + $dir);

        $grant = $this->approve($this->bootstrapBody(target: 2, maxPrice: 50000, minWorkingCapital: 75000));
        CampaignEpisode::create([
            'campaign_grant_id' => $grant->id,
            'agent_id' => $this->agent->id,
            'reset_id' => $this->agent->reset_id,
            'start_credits' => 150000,
            'phase' => 'earn',
            'opened_at' => now(),
            'next_reset_at' => now()->addDays(5),
            'state' => ['last_discovery_at' => now()->toIso8601String(), 'role_ships' => ['command' => 'LOCAL-1', 'probe' => 'LOCAL-2']],
        ]);

        // Pass 1: command ship at ROCK wants buyer quote at PORT -> dispatches ONE probe (LOCAL-2) to PORT.
        $this->tickDirector();
        $firstRuns = AutomationRun::whereNotNull('active_agent_id')->where('strategy', 'scout')->get();
        $this->assertCount(1, $firstRuns);
        $this->assertSame('X1-TEST-PORT', $firstRuns[0]->plan['destination']);

        // Pass 2: LOCAL-2 is still en route to PORT, LOCAL-3 is idle -> MUST NOT dispatch LOCAL-3 to PORT too!
        $this->tickDirector();
        $secondRuns = AutomationRun::whereNotNull('active_agent_id')->where('strategy', 'scout')->get();
        $this->assertCount(1, $secondRuns);
        $lastDecision = CampaignEvent::where('kind', 'decision')->latest('id')->firstOrFail();
        $this->assertSame('awaiting_buyer_quote', $lastDecision->data['choice']);
    }

    public function test_retained_scout_preview_is_discarded_when_ship_is_held_by_recon(): void
    {
        $this->editWorld(function (array &$world) {
            $world['ships'][] = $this->probeShip('LOCAL-3', 'X1-TEST-ROCK');
            $world['waypoints'] = [
                ['symbol' => 'X1-TEST-ROCK', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-PORT', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 3, 'y' => 4, 'traits' => [['symbol' => 'MARKETPLACE']]],
            ];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $grant = $this->approve($this->bootstrapBody(target: 2, maxPrice: 50000, minWorkingCapital: 75000));
        $episode = CampaignEpisode::create([
            'campaign_grant_id' => $grant->id,
            'agent_id' => $this->agent->id,
            'reset_id' => $this->agent->reset_id,
            'start_credits' => 150000,
            'phase' => 'earn',
            'opened_at' => now(),
            'next_reset_at' => now()->addDays(5),
            'state' => ['last_discovery_at' => now()->toIso8601String(), 'role_ships' => ['command' => 'LOCAL-1', 'probe' => 'LOCAL-2']],
        ]);

        // Retained preview created for LOCAL-2 before LOCAL-2 is lent to a ReconMission.
        $preview = AutomationRun::create([
            'agent_id' => $this->agent->id,
            'campaign_episode_id' => $episode->id,
            'reset_id' => $this->agent->reset_id,
            'ship_symbol' => 'LOCAL-2',
            'strategy' => 'scout',
            'plan_version' => 3,
            'state' => 'preview',
            'expires_at' => now()->addMinutes(5),
            'plan' => ['strategy' => 'scout', 'site' => 'X1-TEST-ROCK', 'destination' => 'X1-TEST-PORT', 'market' => 'X1-TEST-PORT', 'one_way' => true, 'reserve' => 0, 'max_spend' => 0, 'max_loss' => 0, 'cycles' => 1, 'minutes' => 15],
            'totals' => ['phase' => 'observe', 'credit_delta' => 0, 'spending' => 0],
        ]);
        $this->createReconMission('LOCAL-2', 5000);

        $this->tickDirector();
        $this->assertSame('stopped', $preview->fresh()->state->value);
        $this->assertNull($preview->fresh()->active_agent_id);
        $this->assertNull($grant->fresh()->paused_at);
    }

    public function test_configuration_mismatch_on_buy_probe_holds_for_review_and_never_completes_as_probe_purchased(): void
    {
        $this->editWorld(function (array &$world) {
            $world['credits'] = 150000;
            $world['ships'][1]['nav']['waypointSymbol'] = 'X1-TEST-YARDB';
            $world['waypoints'] = [
                ['symbol' => 'X1-TEST-ROCK', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-YARDB', 'systemSymbol' => 'X1-TEST', 'type' => 'ORBITAL_STATION', 'x' => 4, 'y' => 0, 'traits' => [['symbol' => 'SHIPYARD']]],
            ];
            $world['shipyards'] = [
                'X1-TEST-YARDB' => [
                    'shipTypes' => [['type' => 'SHIP_PROBE']],
                    'ships' => [$this->probeOffer(24000)],
                ],
            ];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->seedDiscoveryDone();
        $grant = $this->approve($this->bootstrapBody(target: 2, maxPrice: 50000, minWorkingCapital: 75000));

        $this->tickDirector();
        $buy = AutomationRun::where('strategy', 'buy_probe')->firstOrFail();
        app(AutomationRunner::class)->advance($buy->id);
        $action = ShipAction::where('automation_run_id', $buy->id)->where('command', 'buy_ship')->firstOrFail();

        // Simulate upstream delivering a mismatched frame vs the approved FRAME_PROBE offer.
        $this->editWorld(function (array &$world) {
            $world['deliver_mismatched_frame'] = 'FRAME_MINER';
        });
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertTrue($action->fresh()->review_required);
        $this->assertTrue($action->fresh()->outcome['configuration_mismatch']);
        $this->assertSame('blocked', $buy->fresh()->state->value);
        $this->assertSame([], $buy->fresh()->totals['purchased_ships']);
        $this->assertSame(0, CampaignEvent::where('kind', 'probe_purchased')->count());
        // CampaignSafety pauses C0 on review_required so no second purchase can start.
        $this->assertSame('uncertain_action', app(CampaignSafety::class)->reason($grant, CampaignEpisode::firstOrFail(), $this->agent->fresh()));
    }

    public function test_acknowledged_lost_probe_purchase_reserves_slot_until_late_delivery_arrives(): void
    {
        $grant = $this->approve($this->bootstrapBody(target: 3, maxPrice: 50000, minWorkingCapital: 75000));
        $episode = CampaignEpisode::create([
            'campaign_grant_id' => $grant->id,
            'agent_id' => $this->agent->id,
            'reset_id' => $this->agent->reset_id,
            'start_credits' => 150000,
            'phase' => 'discover',
            'opened_at' => now(),
            'next_reset_at' => now()->addDays(5),
            'state' => ['last_discovery_at' => now()->toIso8601String(), 'role_ships' => ['command' => 'LOCAL-1', 'probe' => 'LOCAL-2']],
        ]);
        // Starter fleet has 1 probe (LOCAL-2). Record an acknowledged lost-response SHIP_PROBE purchase at YARDB.
        $ack = ShipAction::create([
            'agent_id' => $this->agent->id,
            'key' => 'ack-lost-probe',
            'reset_id' => $this->agent->reset_id,
            'ship_symbol' => 'LOCAL-2',
            'origin' => 'automation',
            'target_type' => 'fleet',
            'target_id' => 'X1-TEST-YARDB',
            'command' => 'buy_ship',
            'arguments' => ['shipType' => 'SHIP_PROBE', 'waypointSymbol' => 'X1-TEST-YARDB', 'maxTotal' => 24000, 'reserve' => 75000],
            'state' => 'acknowledged',
            'sent_at' => now(),
            'before_state' => ['estimate' => ['fleet_symbols' => ['LOCAL-1', 'LOCAL-2']]],
            'outcome' => ['acknowledged_at' => now()->toIso8601String()],
            'settled_at' => now(),
            'expires_at' => now()->addMinutes(3),
        ]);
        $bootstrap = app(CampaignProbeBootstrap::class);
        // While delivery is unknown: 1 owned + 1 acknowledged in-flight = 2 (reserves slot!).
        $this->assertSame(1, $bootstrap->ownedProbeCount($this->agent));
        $this->assertSame(1, $bootstrap->inFlightProbePurchases($this->agent, $episode));

        // An unrelated probe appearing at a DIFFERENT shipyard without corroborating YARDB evidence must NOT release the slot!
        Ship::create([
            'agent_id' => $this->agent->id,
            'symbol' => 'LOCAL-9',
            'snapshot' => $this->probeShip('LOCAL-9', 'X1-TEST-YARDA'),
            'observed_at' => now(),
        ]);
        $this->assertSame(2, $bootstrap->ownedProbeCount($this->agent));
        $this->assertSame(1, $bootstrap->inFlightProbePurchases($this->agent, $episode));

        // Incomplete, non-absolute or impossible shipyard transactions (missing agentSymbol, missing timestamp, '', 'now', next Feb 30, hour 25) must NOT release the slot!
        $deliveredSnapshot = $this->probeShip('LOCAL-3', 'X1-TEST-YARDB');
        Ship::create([
            'agent_id' => $this->agent->id,
            'symbol' => 'LOCAL-3',
            'snapshot' => $deliveredSnapshot,
            'observed_at' => now(),
        ]);
        foreach ([
            ['waypointSymbol' => 'X1-TEST-YARDB', 'shipType' => 'SHIP_PROBE', 'price' => 24000, 'timestamp' => now()->toIso8601String()],
            ['waypointSymbol' => 'X1-TEST-YARDB', 'shipType' => 'SHIP_PROBE', 'agentSymbol' => 'LOCAL', 'price' => 24000],
            ['waypointSymbol' => 'X1-TEST-YARDB', 'shipType' => 'SHIP_PROBE', 'agentSymbol' => 'LOCAL', 'price' => 24000, 'timestamp' => ''],
            ['waypointSymbol' => 'X1-TEST-YARDB', 'shipType' => 'SHIP_PROBE', 'agentSymbol' => 'LOCAL', 'price' => 24000, 'timestamp' => 'now'],
            ['waypointSymbol' => 'X1-TEST-YARDB', 'shipType' => 'SHIP_PROBE', 'agentSymbol' => 'LOCAL', 'price' => 24000, 'timestamp' => (now()->year + 1).'-02-30T12:00:00Z'],
            ['waypointSymbol' => 'X1-TEST-YARDB', 'shipType' => 'SHIP_PROBE', 'agentSymbol' => 'LOCAL', 'price' => 24000, 'timestamp' => now()->format('Y-m-d').'T25:00:00Z'],
        ] as $badTx) {
            app(AgentSynchronizer::class)->observe($this->agent, 'shipyard', 'X1-TEST-YARDB', [
                'symbol' => 'X1-TEST-YARDB',
                'shipTypes' => [['type' => 'SHIP_PROBE']],
                'transactions' => [$badTx],
            ]);
            $this->assertSame(1, $bootstrap->inFlightProbePurchases($this->agent, $episode), 'Bad tx should not release slot: '.json_encode($badTx));
        }

        // Now verify automatic campaign tick discovers the late-delivery shipyard transaction on its own
        // (without manually inserting the observation!) and immediately starts buying the remaining probe up to target=4.
        $grant->update(['slot' => null, 'revoked_at' => now()]);
        $episode->update(['closed_at' => now()]);
        $this->editWorld(function (array &$world) use ($deliveredSnapshot) {
            $world['credits'] = 150000;
            $world['ships'][] = $this->probeShip('LOCAL-9', 'X1-TEST-YARDA');
            $world['ships'][] = $deliveredSnapshot;
            $world['waypoints'] = [
                ['symbol' => 'X1-TEST-ROCK', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-YARDB', 'systemSymbol' => 'X1-TEST', 'type' => 'ORBITAL_STATION', 'x' => 4, 'y' => 0, 'traits' => [['symbol' => 'SHIPYARD']]],
            ];
            $world['shipyards'] = [
                'X1-TEST-YARDB' => [
                    'shipTypes' => [['type' => 'SHIP_PROBE']],
                    'ships' => [$this->probeOffer(24000)],
                ],
            ];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        // The corroborating transaction only appears on the live shipyard after the sync,
        // so only the director's own refresh can see it.
        $this->editWorld(function (array &$world) {
            $world['transactions'] = [[
                'waypointSymbol' => 'X1-TEST-YARDB',
                'shipType' => 'SHIP_PROBE',
                'agentSymbol' => 'LOCAL',
                'price' => 24000,
                'timestamp' => now()->toIso8601String(),
            ]];
        });
        $this->assertSame(1, $bootstrap->inFlightProbePurchases($this->agent, $episode));
        $this->seedDiscoveryDone();
        $nextGrant = $this->approve($this->bootstrapBody(target: 4, maxPrice: 50000, minWorkingCapital: 75000));
        // Before tickDirector, owned=3 + inFlight=1 == target(4) -> tickDirector refreshes YARDB automatically,
        // clears the acknowledged reservation to 0, and dispatches buy_probe for probe #4!
        $this->tickDirector();
        $nextEpisode = CampaignEpisode::where('campaign_grant_id', $nextGrant->id)->firstOrFail();
        $this->assertSame(0, $bootstrap->inFlightProbePurchases($this->agent, $nextEpisode, ignoreRunId: AutomationRun::where('strategy', 'buy_probe')->latest('id')->value('id')));
        $this->assertSame(1, AutomationRun::where('campaign_episode_id', $nextEpisode->id)->where('strategy', 'buy_probe')->whereNotNull('active_agent_id')->count());
        $this->assertNotNull($ack->fresh());
    }

    public function test_late_probe_delivery_releases_reservation_after_probe_leaves_yard_before_transaction_appears(): void
    {
        $this->editWorld(function (array &$world) {
            $world['waypoints'] = [
                ['symbol' => 'X1-TEST-ROCK', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-YARDB', 'systemSymbol' => 'X1-TEST', 'type' => 'ORBITAL_STATION', 'x' => 4, 'y' => 0, 'traits' => [['symbol' => 'SHIPYARD']]],
            ];
            $world['shipyards'] = [
                'X1-TEST-YARDB' => ['shipTypes' => [['type' => 'SHIP_PROBE']], 'ships' => [$this->probeOffer(24000)]],
            ];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->seedDiscoveryDone();
        $grant = $this->approve($this->bootstrapBody(target: 3, maxPrice: 50000, minWorkingCapital: 75000));
        $episode = CampaignEpisode::create([
            'campaign_grant_id' => $grant->id,
            'agent_id' => $this->agent->id,
            'reset_id' => $this->agent->reset_id,
            'start_credits' => 150000,
            'phase' => 'discover',
            'opened_at' => now(),
            'next_reset_at' => now()->addDays(5),
            'state' => ['last_discovery_at' => now()->toIso8601String(), 'role_ships' => ['command' => 'LOCAL-1', 'probe' => 'LOCAL-2']],
        ]);
        $ack = ShipAction::create([
            'agent_id' => $this->agent->id,
            'key' => 'ack-probe-before-delivery',
            'reset_id' => $this->agent->reset_id,
            'ship_symbol' => 'LOCAL-2',
            'origin' => 'automation',
            'target_type' => 'fleet',
            'target_id' => 'X1-TEST-YARDB',
            'command' => 'buy_ship',
            'arguments' => ['shipType' => 'SHIP_PROBE', 'waypointSymbol' => 'X1-TEST-YARDB', 'maxTotal' => 24000, 'reserve' => 75000],
            'state' => 'acknowledged',
            'sent_at' => now(),
            'before_state' => ['estimate' => ['fleet_symbols' => ['LOCAL-1', 'LOCAL-2']]],
            'outcome' => ['fleet' => $this->world()['ships']],
            'settled_at' => now(),
            'expires_at' => now()->addMinutes(3),
        ]);
        $bootstrap = app(CampaignProbeBootstrap::class);
        $this->assertSame(1, $bootstrap->inFlightProbePurchases($this->agent, $episode));

        // Delivery arrives after reconciliation. A director pass sees it, but still has no transaction.
        $this->editWorld(function (array &$world) {
            $world['ships'][] = $this->probeShip('LOCAL-3', 'X1-TEST-YARDB');
        });
        $this->tickDirector();
        $this->assertSame(2, $bootstrap->ownedProbeCount($this->agent));
        $this->assertSame(1, $bootstrap->inFlightProbePurchases($this->agent, $episode));
        $this->assertSame(0, AutomationRun::where('strategy', 'buy_probe')->count());

        // The pool's new probe leaves before transaction evidence is available; nobody remains at the yard.
        $this->editWorld(function (array &$world) {
            $world['ships'][2]['nav']['waypointSymbol'] = 'X1-TEST-ROCK';
        });
        app(AgentSynchronizer::class)->sync($this->agent, false);
        $this->assertSame(1, $bootstrap->inFlightProbePurchases($this->agent, $episode));
        $this->editWorld(function (array &$world) {
            $world['transactions'] = [[
                'waypointSymbol' => 'X1-TEST-YARDB', 'shipType' => 'SHIP_PROBE', 'agentSymbol' => 'LOCAL',
                'price' => 24000, 'timestamp' => now()->toIso8601String(),
            ]];
        });

        $this->tickDirector();

        $this->assertSame(1, AutomationRun::where('strategy', 'buy_probe')->whereNotNull('active_agent_id')->count());
        $buy = AutomationRun::where('strategy', 'buy_probe')->whereNotNull('active_agent_id')->sole();
        $this->assertSame(0, $bootstrap->inFlightProbePurchases($this->agent, $episode, ignoreRunId: $buy->id));
        $this->assertSame('X1-TEST-YARDB', $buy->plan['shipyard']);

        // Attribution is durable: the release holds after the transaction ages out of the shipyard's recent list.
        app(AgentSynchronizer::class)->observe($this->agent, 'shipyard', 'X1-TEST-YARDB', [
            'symbol' => 'X1-TEST-YARDB',
            'shipTypes' => [['type' => 'SHIP_PROBE']],
            'transactions' => [],
        ]);
        $this->assertSame('LOCAL-3', $ack->fresh()->outcome['bootstrap_delivered_probe']);
        $this->assertSame(0, $bootstrap->inFlightProbePurchases($this->agent, $episode, ignoreRunId: $buy->id));

        // The same new probe must not release another acknowledged purchase at this yard.
        $another = $ack->fresh()->replicate();
        $another->key = 'ack-probe-without-second-delivery';
        $another->outcome = array_diff_key($ack->fresh()->outcome, ['bootstrap_delivered_probe' => true]);
        $another->save();
        $this->assertSame(1, $bootstrap->inFlightProbePurchases($this->agent, $episode, ignoreRunId: $buy->id));
    }

    public function test_buy_probe_departure_refuel_respects_post_preview_recon_return_exposure(): void
    {
        $this->editWorld(function (array &$world) {
            $world['credits'] = 100000;
            $world['ships'] = [$world['ships'][0]];
            $world['ships'][0]['fuel'] = ['current' => 50, 'capacity' => 100];
            $world['waypoints'] = [
                ['symbol' => 'X1-TEST-ROCK', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-YARDB', 'systemSymbol' => 'X1-TEST', 'type' => 'ORBITAL_STATION', 'x' => 10, 'y' => 0, 'traits' => [['symbol' => 'SHIPYARD']]],
            ];
            $world['shipyards'] = [
                'X1-TEST-YARDB' => [
                    'shipTypes' => [['type' => 'SHIP_PROBE']],
                    'ships' => [$this->probeOffer(20000)],
                ],
            ];
        });
        $this->setBalance(100000);
        Ship::where('agent_id', $this->agent->id)->where('symbol', 'LOCAL-2')->delete();
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->seedDiscoveryDone();
        $this->approve($this->bootstrapBody(target: 1, maxPrice: 50000, minWorkingCapital: 75000));

        // Starts buy_probe on LOCAL-1 with credits=100k, reserve=75k (plenty of room for 5 CR fuel + 20k probe).
        $this->tickDirector();
        $buy = AutomationRun::where('strategy', 'buy_probe')->firstOrFail();

        // Now a ReconMission opens adding 25,000 CR return exposure -> new additive reserve = 75k + 25k = 100k!
        // Even a 5 CR departure refuel would drop credits below 100k, so next() MUST block instead of refueling!
        $this->createReconMission('LOCAL-99', 25000);
        app(AutomationRunner::class)->advance($buy->id);
        $this->assertSame('blocked', $buy->fresh()->state->value);
        $this->assertStringContainsString('additive reserve', $buy->fresh()->blocked_reason);
        $this->assertSame(0, ShipAction::where('automation_run_id', $buy->id)->where('command', 'refuel')->count());
    }

    public function test_operator_release_frees_probe_slot_and_allows_planning(): void
    {
        [$action, $episode] = $this->releasablePurchase();
        $bootstrap = app(CampaignProbeBootstrap::class);
        $this->assertSame(1, $bootstrap->inFlightProbePurchases($this->agent, $episode));
        $calls = $this->world()['calls'];

        $this->assertSame(0, Artisan::call('campaign:release-probe-purchase', ['action' => $action->id, '--user' => $this->user->id]));
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($result['released']);
        $this->assertFalse($result['dry_run']);
        $this->assertSame($action->id, $result['action_id']);
        $this->assertSame('operator release', $result['confirmed_not_sent']['reason']);

        $this->assertSame($calls, $this->world()['calls'], 'Release must use local evidence only.');
        $this->assertSame(['user_id' => $this->user->id, 'at' => now()->toIso8601String(), 'reason' => 'operator release'], $action->fresh()->outcome['confirmed_not_sent'] ?? null);
        $this->assertSame(0, $bootstrap->inFlightProbePurchases($this->agent, $episode));
        $event = CampaignEvent::where('kind', 'probe_purchase_released')->sole();
        $this->assertSame($action->id, $event->data['action_id']);
        $this->assertSame($this->user->id, $event->data['user_id']);
        $this->tickDirector();
        $this->assertSame(1, AutomationRun::where('strategy', 'buy_probe')->whereNotNull('active_agent_id')->count());
    }

    public function test_operator_release_dry_run_writes_nothing(): void
    {
        [$action, $episode] = $this->releasablePurchase();
        $before = $action->fresh()->getAttributes();
        $events = CampaignEvent::count();
        $calls = $this->world()['calls'];

        $this->assertSame(0, Artisan::call('campaign:release-probe-purchase', ['action' => $action->id, '--user' => $this->user->id, '--dry-run' => true]));
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($result['released']);
        $this->assertTrue($result['dry_run']);
        $this->assertSame($action->id, $result['action_id']);

        $this->assertSame($before, $action->fresh()->getAttributes());
        $this->assertSame($events, CampaignEvent::count());
        $this->assertSame($calls, $this->world()['calls']);
        $this->assertSame(1, app(CampaignProbeBootstrap::class)->inFlightProbePurchases($this->agent, $episode));
    }

    public static function retainedPurchaseReceipts(): array
    {
        return [
            '201 empty sanitized response' => [['http_status' => 201, 'body_sha256' => 'x', 'response' => []]],
            '201 agent-only sanitized response' => [['http_status' => 201, 'body_sha256' => 'x', 'response_received_at' => '2026-11-21T12:00:00Z', 'response' => ['agent' => ['symbol' => 'LOCAL', 'credits' => 126000]]]],
            'receipt markers without response' => [['body_sha256' => 'x', 'response_received_at' => '2026-11-21T12:00:00Z']],
            'empty response alone' => [['response' => []]],
            'null response alone' => [['response' => null]],
            'body hash alone' => [['body_sha256' => 'x']],
            'receipt timestamp alone' => [['response_received_at' => '2026-11-21T12:00:00Z']],
            'http status alone' => [['http_status' => 201]],
            'validation marker alone' => [['response_validated' => true]],
            'incomplete 201 transaction' => [['http_status' => 201, 'response' => ['transaction' => ['waypointSymbol' => 'X1-TEST-YARDB', 'shipSymbol' => 'SHIP_PROBE', 'agentSymbol' => 'LOCAL']]]],
            'incomplete 201 ship' => [['http_status' => 201, 'response' => ['ship' => ['symbol' => 'LOCAL-3']]]],
            '200 empty transaction' => [['http_status' => 200, 'response' => ['transaction' => []]]],
            '299 malformed transaction' => [['http_status' => 299, 'response' => ['transaction' => 'incomplete']]],
            '201 empty ship' => [['http_status' => 201, 'response' => ['ship' => []]]],
            '201 malformed ship' => [['http_status' => 201, 'response' => ['ship' => 'LOCAL-3']]],
            '201 null ship' => [['http_status' => 201, 'response' => ['ship' => null]]],
            'validated response without status' => [['response_validated' => true, 'response' => ['ship' => ['symbol' => 'LOCAL-3']]]],
            'legacy transaction without status' => [['response' => ['transaction' => ['waypointSymbol' => 'X1-TEST-YARDB', 'shipType' => 'SHIP_PROBE', 'agentSymbol' => 'LOCAL', 'timestamp' => '2026-11-21T12:00:00Z']]]],
        ];
    }

    #[DataProvider('retainedPurchaseReceipts')]
    public function test_operator_release_refuses_retained_purchase_receipts_even_without_reconciled_delivery(array $receipt): void
    {
        [$action, $episode] = $this->releasablePurchase();
        $action->update(['outcome' => array_merge($action->outcome, $receipt, ['transactions' => [], 'fleet' => []])]);
        $before = $action->fresh()->getAttributes();
        $events = CampaignEvent::count();
        $calls = $this->world()['calls'];

        $this->assertSame(1, Artisan::call('campaign:release-probe-purchase', ['action' => $action->id, '--user' => $this->user->id]));
        $refusal = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($refusal['released']);
        $this->assertStringContainsString('slot stays held because stored receipt evidence shows the purchase may have happened', $refusal['reason']);

        $this->assertSame($before, $action->fresh()->getAttributes());
        $this->assertSame($events, CampaignEvent::count());
        $this->assertSame($calls, $this->world()['calls']);
        $this->assertSame(1, app(CampaignProbeBootstrap::class)->inFlightProbePurchases($this->agent, $episode));
    }

    public function test_operator_release_refuses_repeat_without_restamping_or_duplicate_event(): void
    {
        [$action, $episode] = $this->releasablePurchase();
        $parameters = ['action' => $action->id, '--user' => $this->user->id];
        $this->artisan('campaign:release-probe-purchase', $parameters)->assertSuccessful();
        $before = $action->fresh()->getAttributes();
        $events = CampaignEvent::count();
        $calls = $this->world()['calls'];
        $this->travel(1)->minutes();

        foreach ([false, true] as $dryRun) {
            $this->assertSame(1, Artisan::call('campaign:release-probe-purchase', $parameters + ['--dry-run' => $dryRun]));
            $refusal = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertFalse($refusal['released']);
            $this->assertStringContainsString('already released', $refusal['reason']);
            $this->assertSame($before, $action->fresh()->getAttributes());
            $this->assertSame($events, CampaignEvent::count());
        }
        $this->assertSame($calls, $this->world()['calls']);
        $this->assertSame(0, app(CampaignProbeBootstrap::class)->inFlightProbePurchases($this->agent, $episode));
    }

    public function test_operator_release_missing_grant_returns_json_refusal_without_writes(): void
    {
        [$action, $episode] = $this->releasablePurchase();
        $grantId = $episode->campaign_grant_id;
        // Model a broken audit link while deferring its foreign-key check until the link is restored.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('campaign_episodes')->where('id', $episode->id)->update(['campaign_grant_id' => $grantId + 1000]);
        $before = $action->fresh()->getAttributes();
        $events = CampaignEvent::count();
        $calls = $this->world()['calls'];

        try {
            $this->assertSame(1, Artisan::call('campaign:release-probe-purchase', ['action' => $action->id, '--user' => $this->user->id]));
            $refusal = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertFalse($refusal['released']);
            $this->assertSame('Campaign grant not found; the purchase cannot be released.', $refusal['reason']);
            $this->assertSame($before, $action->fresh()->getAttributes());
            $this->assertSame($events, CampaignEvent::count());
            $this->assertSame($calls, $this->world()['calls']);
            $this->assertSame(1, app(CampaignProbeBootstrap::class)->inFlightProbePurchases($this->agent, $episode));
        } finally {
            DB::table('campaign_episodes')->where('id', $episode->id)->update(['campaign_grant_id' => $grantId]);
            DB::statement('PRAGMA defer_foreign_keys = OFF');
        }
    }

    public static function unknownReleaseIds(): array
    {
        return ['action' => ['action'], 'operator' => ['user']];
    }

    #[DataProvider('unknownReleaseIds')]
    public function test_operator_release_unknown_ids_return_json_refusals(string $unknown): void
    {
        [$action, $episode] = $this->releasablePurchase();
        $before = $action->fresh()->getAttributes();
        $events = CampaignEvent::count();
        $calls = $this->world()['calls'];
        $parameters = ['action' => $action->id, '--user' => $this->user->id];
        $parameters[$unknown === 'action' ? 'action' : '--user'] += 1000;
        $this->assertSame(1, Artisan::call('campaign:release-probe-purchase', $parameters));
        $refusal = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($refusal['released']);
        $this->assertSame($unknown === 'action' ? 'Action not found.' : 'Operator not found.', $refusal['reason']);
        $this->assertSame($before, $action->fresh()->getAttributes());
        $this->assertSame($events, CampaignEvent::count());
        $this->assertSame($calls, $this->world()['calls']);
        $this->assertSame(1, app(CampaignProbeBootstrap::class)->inFlightProbePurchases($this->agent, $episode));
    }

    public static function releaseRefusals(): array
    {
        return [
            'wrong operator' => ['operator', 'does not operate'],
            'wrong state' => ['state', 'Only an acknowledged'],
            'wrong command' => ['command', 'Only an acknowledged'],
            'wrong ship type' => ['type', 'Only an acknowledged'],
            'different reset' => ['reset', 'current reset'],
            'delivered' => ['delivered_ship', 'delivery evidence'],
            'late delivery' => ['bootstrap_delivered_probe', 'delivery evidence'],
            'missing fleet baseline' => ['baseline', 'baseline is incomplete; cannot rule out a delivery. Sync the agent first.'],
            'missing sent timestamp' => ['sent', 'baseline is incomplete; cannot rule out a delivery. Sync the agent first.'],
            'missing shipyard' => ['yard', 'baseline is incomplete; cannot rule out a delivery. Sync the agent first.'],
            'historical observation transaction' => ['history', 'matching shipyard transaction was recorded; the slot stays held because stored evidence shows the purchase may have happened. Syncing cannot clear this evidence.'],
            'observation transaction' => ['observation', 'matching shipyard transaction was recorded; the slot stays held because stored evidence shows the purchase may have happened. Syncing cannot clear this evidence.'],
            'outcome transaction' => ['outcome', 'matching shipyard transaction was recorded; the slot stays held because stored evidence shows the purchase may have happened. Syncing cannot clear this evidence.'],
            'unattributed probe away from yard' => ['probe', 'Unattributed new probe LOCAL-3 may be this delivery. Sync the agent first.'],
        ];
    }

    #[DataProvider('releaseRefusals')]
    public function test_operator_release_refuses_unsafe_or_unauthorized_actions(string $case, string $reason): void
    {
        [$action] = $this->releasablePurchase();
        $operator = $this->user;
        if ($case === 'operator') {
            $operator = User::factory()->create();
        } elseif ($case === 'state') {
            $action->update(['state' => 'uncertain']);
        } elseif ($case === 'command') {
            $action->update(['command' => 'navigate']);
        } elseif ($case === 'type') {
            $action->update(['arguments' => array_merge($action->arguments, ['shipType' => 'SHIP_MINING_DRONE'])]);
        } elseif ($case === 'reset') {
            $action->update(['reset_id' => 'previous-reset']);
        } elseif (in_array($case, ['delivered_ship', 'bootstrap_delivered_probe'], true)) {
            $action->update(['outcome' => [$case => 'LOCAL-3']]);
        } elseif ($case === 'baseline') {
            $action->update(['before_state' => []]);
        } elseif ($case === 'sent') {
            $action->update(['sent_at' => null]);
        } elseif ($case === 'yard') {
            $action->update(['arguments' => ['shipType' => 'SHIP_PROBE']]);
        } elseif (in_array($case, ['observation', 'outcome', 'history'], true)) {
            $transaction = ['waypointSymbol' => 'X1-TEST-YARDB', 'shipType' => 'SHIP_PROBE', 'agentSymbol' => 'LOCAL', 'timestamp' => now()->subSeconds(5)->toIso8601String()];
            if ($case === 'outcome') {
                $action->update(['outcome' => ['transactions' => [$transaction]]]);
            } else {
                app(AgentSynchronizer::class)->observe($this->agent, 'shipyard', 'X1-TEST-YARDB', ['transactions' => [$transaction]]);
                if ($case === 'history') {
                    app(AgentSynchronizer::class)->observe($this->agent, 'shipyard', 'X1-TEST-YARDB', ['transactions' => []]);
                }
            }
        } elseif ($case === 'probe') {
            Ship::create(['agent_id' => $this->agent->id, 'symbol' => 'LOCAL-3', 'snapshot' => $this->probeShip('LOCAL-3', 'X1-TEST-PORT'), 'observed_at' => now()]);
        }
        $before = $action->fresh()->getAttributes();
        $events = CampaignEvent::count();
        $calls = $this->world()['calls'];

        $this->artisan('campaign:release-probe-purchase', ['action' => $action->id, '--user' => $operator->id])
            ->expectsOutputToContain($reason)->assertFailed();

        $this->assertSame($before, $action->fresh()->getAttributes());
        $this->assertSame($events, CampaignEvent::count());
        $this->assertSame($calls, $this->world()['calls']);
    }

    public function test_operator_release_ignores_invalid_transactions_and_other_attributed_probes(): void
    {
        [$action] = $this->releasablePurchase();
        $transaction = ['waypointSymbol' => 'X1-TEST-YARDB', 'shipType' => 'SHIP_PROBE', 'agentSymbol' => 'LOCAL', 'timestamp' => now()->toIso8601String()];
        $invalid = [];
        foreach ([['agentSymbol' => 'OTHER'], ['waypointSymbol' => 'X1-TEST-OTHER'], ['shipType' => 'SHIP_MINING_DRONE'],
            ['timestamp' => now()->subSeconds(6)->toIso8601String()], ['timestamp' => 'now'], ['timestamp' => '2026-02-30T12:00:00Z'],
            ['timestamp' => '2026-11-21T25:00:00Z'], ['timestamp' => null]] as $override) {
            $invalid[] = array_merge($transaction, $override);
        }
        $action->update(['outcome' => ['transactions' => $invalid]]);
        Ship::create(['agent_id' => $this->agent->id, 'symbol' => 'LOCAL-3', 'snapshot' => $this->probeShip('LOCAL-3', 'X1-TEST-PORT'), 'observed_at' => now()]);
        $other = $action->replicate();
        $other->key = 'other-attributed-probe';
        $other->state = 'confirmed';
        $other->outcome = ['delivered_ship' => 'LOCAL-3'];
        $other->save();

        $this->artisan('campaign:release-probe-purchase', ['action' => $action->id, '--user' => $this->user->id])->assertSuccessful();
        $this->assertArrayHasKey('confirmed_not_sent', $action->fresh()->outcome);
    }

    public function test_unverified_loaded_delivery_fuel_refuses_probe_purchase_without_freshness_hold_and_director_continues(): void
    {
        [, $episode] = $this->releasablePurchase();
        ShipAction::query()->delete();
        $runner = app(AutomationRunner::class);
        $buy = $runner->previewProbePurchase($this->agent->fresh(), 'LOCAL-2', 'X1-TEST-YARDB', ['reserve' => 0, 'minutes' => 15, 'cycles' => 1], $episode);
        $runner->start($this->agent->fresh(), $buy);
        $this->editWorld(function (array &$world) {
            $world['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 10, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 10]]];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $delivery = AutomationRun::create([
            'agent_id' => $this->agent->id, 'active_agent_id' => $this->agent->id, 'campaign_episode_id' => $episode->id,
            'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-1', 'strategy' => 'trade', 'plan_version' => 3,
            'state' => 'running', 'started_at' => now(), 'expires_at' => now()->addMinutes(15),
            'plan' => ['market' => 'X1-TEST-PORT', 'max_spend' => 1000], 'totals' => ['phase' => 'sell', 'spending' => 500],
        ]);
        $trade = \Mockery::mock(CampaignTrade::class)->makePartial();
        $trade->shouldReceive('fuelReserve')->andThrow(new GameException('Fresh fuel route unavailable; stale quote.'));
        $this->app->instance(CampaignTrade::class, $trade);
        try {
            app(CampaignProbeBootstrap::class)->compose($this->agent->fresh(), 'LOCAL-2', 'X1-TEST-YARDB', ['reserve' => 0, 'minutes' => 15, 'cycles' => 1], $episode, $buy->id);
            $this->fail('A loaded delivery without verified fuel or approved ceilings must refuse the preview.');
        } catch (GameException $exception) {
            $this->assertStringContainsString('cannot be verified', $exception->getMessage());
        }
        $runner->advance($buy->id);
        $this->assertSame('blocked', $buy->fresh()->state->value);
        $this->assertStringContainsString('cannot be verified', $buy->fresh()->blocked_reason);
        $this->assertStringContainsString('Retry once its fuel quotes are re-read.', $buy->fresh()->blocked_reason);
        app(AgentSynchronizer::class)->observe($this->agent, 'market_listing', 'X1-TEST-PORT', [
            'symbol' => 'X1-TEST-PORT', 'imports' => [['symbol' => 'IRON_ORE']], 'exports' => [], 'exchange' => [['symbol' => 'FUEL']],
        ]);

        $this->tickDirector();

        $this->assertSame('stopped', $buy->fresh()->state->value);
        $this->assertNull($buy->fresh()->active_agent_id);
        $this->assertNotNull($delivery->fresh()->active_agent_id);
        $this->assertSame(0, CampaignEvent::where('kind', 'freshness_hold')->count());
        $this->assertSame(0, ShipAction::where('command', 'buy_ship')->count());
        $this->assertEmpty($this->world()['writes']);
        $this->assertNull($episode->grant()->fresh()->paused_at);
        $this->assertSame(1, AutomationRun::where('strategy', 'scout')->where('ship_symbol', 'LOCAL-2')->whereNotNull('active_agent_id')->count());
    }

    public function test_trade_freshness_block_still_records_freshness_hold(): void
    {
        [$action, $episode] = $this->releasablePurchase();
        $action->delete();
        $run = AutomationRun::findOrFail($action->automation_run_id);
        $run->update(['strategy' => 'trade', 'state' => 'blocked', 'active_agent_id' => $this->agent->id,
            'blocked_reason' => 'Stale quote requires fresh market evidence.', 'started_at' => null,
            'plan' => ['sell_only' => false], 'totals' => ['credit_delta' => 0]]);

        $this->tickDirector();

        $this->assertSame('stopped', $run->fresh()->state->value);
        $this->assertSame(1, CampaignEvent::where('campaign_episode_id', $episode->id)->where('kind', 'freshness_hold')->count());
        $event = CampaignEvent::where('campaign_episode_id', $episode->id)->where('kind', 'freshness_hold')->sole();
        $this->assertSame($run->id, $event->data['run_id']);
    }

    private function releasablePurchase(): array
    {
        $this->editWorld(function (array &$world) {
            $world['ships'][1]['nav']['waypointSymbol'] = 'X1-TEST-YARDB';
            $world['waypoints'][] = ['symbol' => 'X1-TEST-YARDB', 'systemSymbol' => 'X1-TEST', 'type' => 'ORBITAL_STATION', 'x' => 4, 'y' => 0, 'traits' => [['symbol' => 'SHIPYARD']]];
            $world['shipyards'] = ['X1-TEST-YARDB' => ['shipTypes' => [['type' => 'SHIP_PROBE']], 'ships' => [$this->probeOffer(24000)]]];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->seedDiscoveryDone();
        $grant = $this->approve($this->bootstrapBody(target: 2, maxPrice: 50000, minWorkingCapital: 75000));
        $episode = CampaignEpisode::create([
            'campaign_grant_id' => $grant->id, 'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id,
            'start_credits' => 150000, 'phase' => 'discover', 'opened_at' => now(), 'next_reset_at' => now()->addDays(5),
            'state' => ['last_discovery_at' => now()->toIso8601String(), 'role_ships' => ['command' => 'LOCAL-1', 'probe' => 'LOCAL-2']],
        ]);
        $run = AutomationRun::create([
            'agent_id' => $this->agent->id, 'campaign_episode_id' => $episode->id, 'reset_id' => $this->agent->reset_id,
            'ship_symbol' => 'LOCAL-2', 'strategy' => 'buy_probe', 'plan_version' => 6, 'state' => 'stopped',
            'expires_at' => now()->addMinutes(15), 'plan' => [], 'totals' => [],
        ]);
        $action = ShipAction::create([
            'agent_id' => $this->agent->id, 'automation_run_id' => $run->id, 'key' => 'operator-release-probe',
            'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-2', 'origin' => 'automation',
            'target_type' => 'fleet', 'target_id' => 'X1-TEST-YARDB', 'command' => 'buy_ship',
            'arguments' => ['shipType' => 'SHIP_PROBE', 'waypointSymbol' => 'X1-TEST-YARDB', 'maxTotal' => 24000, 'reserve' => 75000],
            'state' => 'acknowledged', 'sent_at' => now(), 'settled_at' => now(), 'expires_at' => now()->addMinutes(3),
            'before_state' => ['estimate' => ['fleet_symbols' => ['LOCAL-1', 'LOCAL-2']]], 'outcome' => ['acknowledged_at' => now()->toIso8601String()],
        ]);

        return [$action, $episode];
    }

    public function test_pool_holds_one_probe_at_active_trade_buyer_while_other_probes_roam(): void
    {
        $this->editWorld(function (array &$world) {
            $world['ships'][1]['nav']['waypointSymbol'] = 'X1-TEST-M1';
            $world['ships'][] = $this->probeShip('LOCAL-3', 'X1-TEST-M1');
            $world['ships'][] = $this->probeShip('LOCAL-4', 'X1-TEST-ROCK');
            $world['waypoints'] = [
                ['symbol' => 'X1-TEST-ROCK', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-M1', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 5, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-M2', 'systemSymbol' => 'X1-TEST', 'type' => 'ORBITAL_STATION', 'x' => 0, 'y' => 5, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-M3', 'systemSymbol' => 'X1-TEST', 'type' => 'MOON', 'x' => -5, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
            ];
            $world['market_goods'] = [
                'X1-TEST-M1' => [
                    ['symbol' => 'IRON_ORE', 'tradeVolume' => 10, 'purchasePrice' => 200, 'sellPrice' => 150],
                    ['symbol' => 'FUEL', 'tradeVolume' => 10, 'purchasePrice' => 5, 'sellPrice' => 3],
                ],
            ];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $sync = app(AgentSynchronizer::class);
        $dir = ['imports' => [['symbol' => 'IRON_ORE']], 'exports' => [], 'exchange' => [['symbol' => 'FUEL']]];
        foreach (['X1-TEST-M1', 'X1-TEST-M2', 'X1-TEST-M3'] as $market) {
            $sync->observe($this->agent, 'market_listing', $market, ['symbol' => $market] + $dir);
        }
        $grant = $this->approve($this->bootstrapBody(target: 3, maxPrice: 50000, minWorkingCapital: 75000));
        $episode = CampaignEpisode::create([
            'campaign_grant_id' => $grant->id,
            'agent_id' => $this->agent->id,
            'reset_id' => $this->agent->reset_id,
            'start_credits' => 150000,
            'phase' => 'earn',
            'opened_at' => now(),
            'next_reset_at' => now()->addDays(5),
            'state' => ['last_discovery_at' => now()->toIso8601String(), 'role_ships' => ['command' => 'LOCAL-1', 'probe' => 'LOCAL-2']],
        ]);

        // Command ship actively trading to buyer X1-TEST-M1; both LOCAL-2 and LOCAL-3 sit at X1-TEST-M1.
        AutomationRun::create([
            'agent_id' => $this->agent->id,
            'active_agent_id' => $this->agent->id,
            'campaign_episode_id' => $episode->id,
            'reset_id' => $this->agent->reset_id,
            'ship_symbol' => 'LOCAL-1',
            'strategy' => 'trade',
            'plan_version' => 3,
            'state' => 'running',
            'started_at' => now(),
            'expires_at' => now()->addMinutes(15),
            'plan' => ['strategy' => 'trade', 'site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-M1', 'symbol' => 'IRON_ORE', 'units' => 10, 'reserve' => 0, 'max_spend' => 1000, 'max_loss' => 1000, 'cycles' => 1, 'minutes' => 15],
            'totals' => ['phase' => 'buy', 'credit_delta' => 0, 'spending' => 0],
        ]);

        // Exactly ONE probe (LOCAL-2) stays parked at X1-TEST-M1 to keep its quote fresh; LOCAL-3 and LOCAL-4 roam to M2 and M3.
        $this->tickDirector();
        $probeRuns = AutomationRun::where('active_agent_id', $this->agent->id)
            ->where('campaign_episode_id', $episode->id)
            ->where('strategy', 'scout')
            ->orderBy('ship_symbol')
            ->get();
        $this->assertSame(['LOCAL-3', 'LOCAL-4'], $probeRuns->pluck('ship_symbol')->all());
        $this->assertSame(['X1-TEST-M2', 'X1-TEST-M3'], $probeRuns->map(fn ($run) => $run->plan['destination'])->sort()->values()->all());
        $buyerObs = GameObservation::where('agent_id', $this->agent->id)->where('kind', 'market')->where('symbol', 'X1-TEST-M1')->first();
        $this->assertNotNull($buyerObs);
        $this->assertNotEmpty($buyerObs->data['tradeGoods']);
    }

    public function test_pool_dispatches_idle_probe_to_claimed_buyer_while_command_ship_positions(): void
    {
        $this->editWorld(function (array &$world) {
            $world['ships'][] = $this->probeShip('LOCAL-3', 'X1-TEST-ROCK');
            $world['waypoints'] = [
                ['symbol' => 'X1-TEST-ROCK', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-BUYER', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 5, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-OTHER', 'systemSymbol' => 'X1-TEST', 'type' => 'ORBITAL_STATION', 'x' => 0, 'y' => 5, 'traits' => [['symbol' => 'MARKETPLACE']]],
            ];
            $world['market_goods'] = [
                'X1-TEST-BUYER' => [
                    ['symbol' => 'IRON_ORE', 'tradeVolume' => 10, 'purchasePrice' => 200, 'sellPrice' => 150],
                    ['symbol' => 'FUEL', 'tradeVolume' => 10, 'purchasePrice' => 5, 'sellPrice' => 3],
                ],
            ];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $sync = app(AgentSynchronizer::class);
        $dir = ['imports' => [['symbol' => 'IRON_ORE']], 'exports' => [], 'exchange' => [['symbol' => 'FUEL']]];
        foreach (['X1-TEST-BUYER', 'X1-TEST-OTHER'] as $market) {
            $sync->observe($this->agent, 'market_listing', $market, ['symbol' => $market] + $dir);
        }
        // X1-TEST-BUYER already has a stale quote (whereas X1-TEST-OTHER is unpriced), so normal scout order would ignore BUYER.
        $this->travel(-300)->seconds();
        $sync->observe($this->agent, 'market', 'X1-TEST-BUYER', ['symbol' => 'X1-TEST-BUYER', 'tradeGoods' => $this->world()['market_goods']['X1-TEST-BUYER']] + $dir);
        $this->travel(300)->seconds();

        $grant = $this->approve($this->bootstrapBody(target: 2, maxPrice: 50000, minWorkingCapital: 75000));
        $episode = CampaignEpisode::create([
            'campaign_grant_id' => $grant->id,
            'agent_id' => $this->agent->id,
            'reset_id' => $this->agent->reset_id,
            'start_credits' => 150000,
            'phase' => 'earn',
            'opened_at' => now(),
            'next_reset_at' => now()->addDays(5),
            'state' => [
                'last_discovery_at' => now()->toIso8601String(),
                'role_ships' => ['command' => 'LOCAL-1', 'probe' => 'LOCAL-2'],
                'claim' => ['good' => 'IRON_ORE', 'source' => 'X1-TEST-ROCK', 'buyer' => 'X1-TEST-BUYER', 'key' => 'IRON_ORE@X1-TEST-BUYER', 'ship' => 'LOCAL-1', 'at' => now()->toIso8601String()],
            ],
        ]);

        // Command ship busy positioning to source; one probe must go to X1-TEST-BUYER, the other to X1-TEST-OTHER.
        AutomationRun::create([
            'agent_id' => $this->agent->id,
            'active_agent_id' => $this->agent->id,
            'campaign_episode_id' => $episode->id,
            'reset_id' => $this->agent->reset_id,
            'ship_symbol' => 'LOCAL-1',
            'strategy' => 'scout',
            'plan_version' => 3,
            'state' => 'running',
            'started_at' => now(),
            'expires_at' => now()->addMinutes(15),
            'plan' => ['strategy' => 'scout', 'site' => 'X1-TEST-OTHER', 'destination' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-ROCK', 'one_way' => true, 'reserve' => 0, 'max_spend' => 0, 'max_loss' => 0, 'cycles' => 1, 'minutes' => 15],
            'totals' => ['phase' => 'travel', 'credit_delta' => 0, 'spending' => 0],
        ]);

        $this->tickDirector();
        $probeRuns = AutomationRun::where('active_agent_id', $this->agent->id)
            ->where('campaign_episode_id', $episode->id)
            ->whereIn('ship_symbol', ['LOCAL-2', 'LOCAL-3'])
            ->orderBy('ship_symbol')
            ->get();
        $this->assertCount(2, $probeRuns);
        $this->assertSame(['X1-TEST-BUYER', 'X1-TEST-OTHER'], $probeRuns->map(fn ($run) => $run->plan['destination'])->sort()->values()->all());
    }

    #[DataProvider('awaitedBuyerOutcomes')]
    public function test_c0_trade_waits_at_the_top_source_then_uses_the_fresh_quote_or_remote_fallback(bool $buyerObserved): void
    {
        $this->travelTo(now()->startOfSecond());
        config(['gameplay.campaign_commit_quote_max_age_seconds' => 180]);
        $this->editWorld(function (array &$world) {
            $world['credits'] = 200000;
            $world['ships'][0]['nav']['waypointSymbol'] = 'X1-TEST-S1';
            $world['ships'][1]['nav']['waypointSymbol'] = 'X1-TEST-M3';
            $world['ships'][1]['nav']['status'] = 'IN_TRANSIT';
            $world['ships'][1]['nav']['route'] = [
                'origin' => ['symbol' => 'X1-TEST-S1'],
                'destination' => ['symbol' => 'X1-TEST-B1'],
                'arrival' => now()->addMinutes(3)->toIso8601String(),
            ];
            $world['ships'][] = $this->probeShip('LOCAL-3', 'X1-TEST-S1');
            $world['waypoints'] = [
                ['symbol' => 'X1-TEST-S1', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-B1', 'systemSymbol' => 'X1-TEST', 'type' => 'MOON', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-S2', 'systemSymbol' => 'X1-TEST', 'type' => 'MOON', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-B2', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 5, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-M3', 'systemSymbol' => 'X1-TEST', 'type' => 'ORBITAL_STATION', 'x' => 0, 'y' => 5, 'traits' => [['symbol' => 'MARKETPLACE']]],
            ];
            $world['market_goods'] = [
                'X1-TEST-S1' => [
                    ['symbol' => 'MEDICINE', 'tradeVolume' => 20, 'purchasePrice' => 3000, 'sellPrice' => 1500],
                    ['symbol' => 'FUEL', 'tradeVolume' => 100, 'purchasePrice' => 70, 'sellPrice' => 65],
                ],
                'X1-TEST-B1' => [
                    ['symbol' => 'MEDICINE', 'tradeVolume' => 20, 'purchasePrice' => 9000, 'sellPrice' => 4800],
                    ['symbol' => 'FUEL', 'tradeVolume' => 100, 'purchasePrice' => 70, 'sellPrice' => 65],
                ],
                'X1-TEST-S2' => [
                    ['symbol' => 'ADVANCED_CIRCUITRY', 'tradeVolume' => 20, 'purchasePrice' => 3500, 'sellPrice' => 1700],
                    ['symbol' => 'FUEL', 'tradeVolume' => 100, 'purchasePrice' => 70, 'sellPrice' => 65],
                ],
                'X1-TEST-B2' => [
                    ['symbol' => 'ADVANCED_CIRCUITRY', 'tradeVolume' => 20, 'purchasePrice' => 12000, 'sellPrice' => 5000],
                    ['symbol' => 'FUEL', 'tradeVolume' => 100, 'purchasePrice' => 70, 'sellPrice' => 65],
                ],
                'X1-TEST-M3' => [
                    ['symbol' => 'FUEL', 'tradeVolume' => 100, 'purchasePrice' => 70, 'sellPrice' => 65],
                ],
            ];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->setBalance(200000);
        $sync = app(AgentSynchronizer::class);
        $listings = [
            'X1-TEST-S1' => ['exports' => [['symbol' => 'MEDICINE']], 'imports' => [], 'exchange' => [['symbol' => 'FUEL']]],
            'X1-TEST-B1' => ['exports' => [], 'imports' => [['symbol' => 'MEDICINE']], 'exchange' => [['symbol' => 'FUEL']]],
            'X1-TEST-S2' => ['exports' => [['symbol' => 'ADVANCED_CIRCUITRY']], 'imports' => [], 'exchange' => [['symbol' => 'FUEL']]],
            'X1-TEST-B2' => ['exports' => [], 'imports' => [['symbol' => 'ADVANCED_CIRCUITRY']], 'exchange' => [['symbol' => 'FUEL']]],
            'X1-TEST-M3' => ['exports' => [], 'imports' => [], 'exchange' => [['symbol' => 'FUEL']]],
        ];
        foreach ($listings as $sym => $dir) {
            $sync->observe($this->agent, 'market_listing', $sym, ['symbol' => $sym] + $dir);
        }
        // Stale quotes for B1, S2, B2 (5 minutes old); fresh quote for S1 where LOCAL-1 sits.
        $this->travel(-300)->seconds();
        foreach (['X1-TEST-B1', 'X1-TEST-S2', 'X1-TEST-B2'] as $sym) {
            $sync->observe($this->agent, 'market', $sym, ['symbol' => $sym, 'tradeGoods' => $this->world()['market_goods'][$sym]] + $listings[$sym]);
        }
        $this->travel(300)->seconds();
        $sync->observe($this->agent, 'market', 'X1-TEST-S1', ['symbol' => 'X1-TEST-S1', 'tradeGoods' => $this->world()['market_goods']['X1-TEST-S1']] + $listings['X1-TEST-S1']);

        $grant = $this->approve($this->bootstrapBody(target: 2, maxPrice: 50000, minWorkingCapital: 75000));
        $episode = CampaignEpisode::create([
            'campaign_grant_id' => $grant->id,
            'agent_id' => $this->agent->id,
            'reset_id' => $this->agent->reset_id,
            'start_credits' => 200000,
            'phase' => 'earn',
            'opened_at' => now(),
            'next_reset_at' => now()->addDays(5),
            'state' => ['last_discovery_at' => now()->toIso8601String(), 'role_ships' => ['command' => 'LOCAL-1', 'probe' => 'LOCAL-2']],
        ]);

        // Case 1: LOCAL-2 is en route to X1-TEST-B1. LOCAL-1 MUST stay at S1 (not ping-pong to S2), while LOCAL-3 roams to M3.
        $this->tickDirector();
        $this->assertNull($grant->fresh()->paused_at, $grant->fresh()->pause_reason ?? '');
        $this->assertSame(0, AutomationRun::where('ship_symbol', 'LOCAL-1')->whereNotNull('active_agent_id')->count());
        $roam = AutomationRun::where('ship_symbol', 'LOCAL-3')->whereNotNull('active_agent_id')->sole();
        $this->assertSame('scout', $roam->strategy);
        $this->assertNotSame('X1-TEST-B1', $roam->plan['destination']);

        // Case 2: All probes busy elsewhere (LOCAL-2 flying to M3 instead of B1, LOCAL-3 still active).
        // StaleQuote at B1 MUST STILL keep LOCAL-1 at S1 instead of ping-ponging to S2!
        $this->editWorld(function (array &$world) {
            $world['ships'][1]['nav']['route']['destination']['symbol'] = 'X1-TEST-M3';
        });
        $this->tickDirector();
        $this->assertSame(0, AutomationRun::where('ship_symbol', 'LOCAL-1')->whereNotNull('active_agent_id')->count());
        $decision = CampaignEvent::where('kind', 'decision')->latest('id')->firstOrFail();
        $this->assertSame('awaiting_buyer_quote', $decision->data['choice']);

        if (! $buyerObserved) {
            $key = 'MEDICINE@X1-TEST-B1';
            $this->assertSame(now()->timestamp, $episode->refresh()->state['awaited_pair_since'][$key]['since']);
            $this->editWorld(function (&$world) {
                $world['ships'][1]['nav']['route']['arrival'] = now()->addMinutes(10)->toIso8601String();
                $world['fuel_consumption'] = 2;
            });
            $this->travel(180)->seconds();
            GameObservation::where('kind', 'market')->whereIn('symbol', ['X1-TEST-S2', 'X1-TEST-B2'])->update(['observed_at' => now()]);
            $this->tickDirector();

            $this->assertSame(now()->timestamp + 180, $episode->refresh()->state['suppressed_pairs'][$key]);
            $position = AutomationRun::where('ship_symbol', 'LOCAL-1')->whereNotNull('active_agent_id')->sole();
            $this->assertSame('scout', $position->strategy);
            $this->assertSame('X1-TEST-S2', $position->plan['destination']);
            $this->runToCompletion($position);
            $this->assertSame('completed', $position->fresh()->state->value, $position->fresh()->blocked_reason ?? '');

            $this->tickDirector();
            $trade = AutomationRun::where('ship_symbol', 'LOCAL-1')->whereNotNull('active_agent_id')->sole();
            $this->assertSame('trade', $trade->strategy);
            $this->assertSame('ADVANCED_CIRCUITRY', $trade->plan['symbol']);
            $this->assertSame('X1-TEST-S2', $trade->plan['site']);
            $this->assertSame('X1-TEST-B2', $trade->plan['market']);

            return;
        }

        // Case 3: Once LOCAL-2 arrives at X1-TEST-B1, LOCAL-1 immediately starts trading MEDICINE S1 -> B1,
        // and LOCAL-2 stays parked at X1-TEST-B1 (not dispatched away!).
        $this->editWorld(function (array &$world) {
            $world['ships'][1]['nav']['waypointSymbol'] = 'X1-TEST-B1';
            $world['ships'][1]['nav']['status'] = 'IN_ORBIT';
        });
        $this->tickDirector();
        $trade = AutomationRun::where('ship_symbol', 'LOCAL-1')->whereNotNull('active_agent_id')->sole();
        $this->assertSame('trade', $trade->strategy);
        $this->assertSame('MEDICINE', $trade->plan['symbol']);
        $this->assertSame('X1-TEST-B1', $trade->plan['market']);
        $this->assertSame(0, AutomationRun::where('ship_symbol', 'LOCAL-2')->whereNotNull('active_agent_id')->count());
    }

    public static function awaitedBuyerOutcomes(): array
    {
        return ['buyer observed' => [true], 'buyer unavailable' => [false]];
    }

    public function test_quote_failure_respects_snapshot_loading_and_seller_first_boundaries(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->installTradeQuotes();
        $trades = app(CampaignTrade::class);
        $planner = app(OperationPlanner::class);
        $source = 'X1-TEST-ROCK';
        $buyer = 'X1-TEST-PORT';
        $good = 'IRON_ORE';
        $market = GameObservation::where('kind', 'market');
        $buyerRow = (clone $market)->where('symbol', $buyer)->sole();
        $sellerRow = (clone $market)->where('symbol', $source)->sole();

        $buyerRow->update(['observed_at' => now()->subSeconds(180)]);
        $this->assertSame($good, $planner->quote($this->agent, $buyer, $good)['symbol']);
        $this->assertFalse($trades->buyerQuoteFreshAlone($this->agent, $buyer, $good, 180));
        $buyerRow->update(['observed_at' => now()->subSeconds(181)]);
        try {
            $planner->quote($this->agent, $buyer, $good);
            $this->fail('The snapshot must fail just past its age limit.');
        } catch (StaleQuote) {
            $this->assertSame('buyer', $trades->quoteLifeFailureSide($this->agent, $source, $buyer, $good, 5, 180));
        }
        $buyerRow->update(['observed_at' => now()->subSeconds(120)]);
        $this->assertSame(60, CampaignTrade::loadingSecondsForVolume(5, 1));
        $this->assertSame('buyer', $trades->quoteLifeFailureSide($this->agent, $source, $buyer, $good, 5, 180));
        $buyerRow->update(['observed_at' => now()->subSeconds(119)]);
        $this->assertNull($trades->quoteLifeFailureSide($this->agent, $source, $buyer, $good, 5, 180));

        $sellerRow->update(['observed_at' => now()->subSeconds(135)]);
        $buyerRow->update(['observed_at' => now()->subSeconds(130)]);
        $this->assertSame('seller', $trades->quoteLifeFailureSide($this->agent, $source, $buyer, $good, 5, 180));
        $buyerRow->update(['observed_at' => now()->subSeconds(181)]);
        $this->assertSame('buyer', $trades->quoteLifeFailureSide($this->agent, $source, $buyer, $good, 5, 180));
        $sellerRow->update(['data' => $sellerRow->data + ['invalidated_by_action' => 10]]);
        $this->assertSame('seller', $trades->quoteLifeFailureSide($this->agent, $source, $buyer, $good, 5, 180));
        $this->assertFalse($trades->buyerQuoteFreshAlone($this->agent, $buyer, $good, 180));
    }

    public function test_buyer_wait_suppression_survives_ranking_then_lifts_and_prunes(): void
    {
        $this->travelTo(now()->startOfSecond());
        config(['gameplay.campaign_commit_quote_max_age_seconds' => 180]);
        $this->installTradeQuotes();
        $grant = $this->approve($this->bootstrapBody(target: 2, maxPrice: 50000, minWorkingCapital: 75000));
        $episode = $this->tradeEpisode($grant);
        $market = GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-PORT')->sole();
        $market->update(['observed_at' => now()->subSeconds(181)]);
        $director = app(CampaignDirector::class);
        $considered = [];
        $row = ['key' => 'IRON_ORE@X1-TEST-PORT', 'good' => 'IRON_ORE', 'source' => 'X1-TEST-ROCK', 'buyer' => 'X1-TEST-PORT', 'units' => 1];
        $key = $row['key'];

        $this->assertFalse($this->invokeDirector($director, 'shouldSuppressAwaitedBuyer', [$grant, $episode, $this->agent, $row, &$considered]));
        $since = now()->timestamp;
        $this->travel(179)->seconds();
        $this->assertFalse($this->invokeDirector($director, 'shouldSuppressAwaitedBuyer', [$grant, $episode, $this->agent, $row, &$considered]));
        $this->assertSame(['since' => $since, 'last_blocked_at' => now()->timestamp], $episode->state['awaited_pair_since'][$key]);
        $this->travel(1)->seconds();
        $this->assertTrue($this->invokeDirector($director, 'shouldSuppressAwaitedBuyer', [$grant, $episode, $this->agent, $row, &$considered]));
        $this->assertArrayNotHasKey($key, $episode->state['awaited_pair_since']);
        $this->assertSame(now()->timestamp + 180, $episode->state['suppressed_pairs'][$key]);

        $ship = Ship::where('symbol', 'LOCAL-1')->sole()->snapshot;
        $staleCopy = $episode->fresh();
        $episode->update(['state' => ['first_seen' => [$key => 42]] + $episode->state]);
        $trades = app(CampaignTrade::class);
        $trades->rank($grant, $staleCopy, $this->agent, $ship, $considered);
        $this->assertSame(42, $episode->fresh()->state['first_seen'][$key]);
        $this->assertSame(now()->timestamp + 180, $episode->fresh()->state['suppressed_pairs'][$key]);

        $market->update(['observed_at' => now()]);
        $this->assertFalse((new \ReflectionMethod($director, 'isPairSuppressed'))->invoke($director, $episode, $this->agent, $grant, $row));
        $ranker = $this->partialMock(CampaignTrade::class);
        $ranker->shouldReceive('rank')->andReturn([$row], []);
        $this->assertNull($this->invokeDirector($director, 'trade', [$grant, $episode, $this->agent, $ship, [], &$considered, ['LOCAL-2']]));
        $this->assertArrayNotHasKey($key, $episode->refresh()->state['suppressed_pairs']);
        $this->assertArrayNotHasKey($key, $episode->state['awaited_pair_since']);

        $this->assertFalse($this->invokeDirector($director, 'shouldSuppressAwaitedBuyer', [$grant, $episode, $this->agent, $row, &$considered]));
        $this->assertSame(now()->timestamp, $episode->state['awaited_pair_since'][$key]['since']);
        $state = $episode->state;
        $state['suppressed_pairs']['EXPIRED'] = now()->timestamp + 301;
        $episode->update(['state' => $state]);
        $this->travel(301)->seconds();
        $this->assertNull($this->invokeDirector($director, 'trade', [$grant, $episode, $this->agent, $ship, [], &$considered]));
        $this->assertSame([], $episode->refresh()->state['suppressed_pairs']);
        $this->assertSame([], $episode->state['awaited_pair_since']);
    }

    public function test_only_admitted_active_scouts_clear_an_unobservable_buyers_timer(): void
    {
        $grant = $this->approve($this->bootstrapBody(target: 2, maxPrice: 50000, minWorkingCapital: 75000));
        $episode = $this->tradeEpisode($grant);
        $buyer = 'X1-TEST-UNOBSERVED';
        $key = 'IRON_ORE@'.$buyer;
        $row = ['key' => $key, 'buyer' => $buyer, 'source' => 'X1-TEST-ROCK'];
        $scout = AutomationRun::create([
            'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'campaign_episode_id' => $episode->id,
            'ship_symbol' => 'LOCAL-2', 'strategy' => 'scout', 'state' => 'preview', 'plan_version' => 1,
            'plan' => ['destination' => $buyer, 'mesh_work' => 'market'], 'totals' => [], 'fingerprint' => 'synthetic',
            'expires_at' => now()->addHour(),
        ]);
        $director = app(CampaignDirector::class);
        $considered = [];
        $this->assertFalse($this->invokeDirector($director, 'shouldSuppressAwaitedBuyer', [$grant, $episode, $this->agent, $row, &$considered]));
        $this->assertArrayHasKey($key, $episode->state['awaited_pair_since']);
        $scout->update(['state' => 'running', 'active_agent_id' => $this->agent->id, 'started_at' => now()]);
        $this->assertFalse($this->invokeDirector($director, 'shouldSuppressAwaitedBuyer', [$grant, $episode, $this->agent, $row, &$considered]));
        $this->assertArrayNotHasKey($key, $episode->state['awaited_pair_since']);
        $scout->update(['state' => 'waiting', 'stop_requested_at' => now()]);
        $this->assertFalse($this->invokeDirector($director, 'shouldSuppressAwaitedBuyer', [$grant, $episode, $this->agent, $row, &$considered]));
        $this->assertArrayHasKey($key, $episode->state['awaited_pair_since']);
        $scout->update(['stop_requested_at' => null]);
        $this->assertFalse($this->invokeDirector($director, 'shouldSuppressAwaitedBuyer', [$grant, $episode, $this->agent, $row, &$considered]));
        $this->assertArrayNotHasKey($key, $episode->state['awaited_pair_since']);
    }

    #[DataProvider('firstQuoteFailures')]
    public function test_seller_first_failures_leave_remote_source_fallbacks_eligible(string $site, string $sellerFailure): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->installTradeQuotes();
        $body = Yaml::parse($this->bootstrapBody(target: 2, maxPrice: 50000, minWorkingCapital: 75000));
        if ($site === 'mesh') {
            $body['probes']['mesh'] = Yaml::parseFile(base_path('campaigns/weekly-system-saturation.yaml'))['probes']['mesh'];
        } elseif ($site === 'no_probe') {
            Ship::where('symbol', 'LOCAL-2')->delete();
        }
        $grant = $this->approve(Yaml::dump($body, 10));
        $episode = $this->tradeEpisode($grant);
        $ship = Ship::where('symbol', 'LOCAL-1')->sole()->snapshot;
        $top = ['key' => 'IRON_ORE@X1-TEST-PORT', 'good' => 'IRON_ORE', 'source' => 'X1-TEST-ROCK', 'buyer' => 'X1-TEST-PORT', 'units' => 5,
            'max_inventory' => 400, 'fuel_safety_ceiling' => 100, 'departure_refuel' => 0, 'predicted_cost' => 400, 'predicted_margin' => 100,
            'score' => 20, 'seconds' => 300];
        $fallback = array_replace($top, ['key' => 'IRON_ORE@X1-TEST-B', 'source' => 'X1-TEST-YARD', 'buyer' => 'X1-TEST-B']);
        if ($site === 'pool') {
            AutomationRun::create([
                'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'campaign_episode_id' => $episode->id,
                'ship_symbol' => 'LOCAL-2', 'strategy' => 'scout', 'state' => 'preview', 'plan_version' => 1,
                'plan' => ['destination' => $top['buyer']], 'totals' => [], 'expires_at' => now()->addHour(),
            ]);
        }
        $buyer = GameObservation::where('kind', 'market')->where('symbol', $top['buyer'])->sole();
        $seller = GameObservation::where('kind', 'market')->where('symbol', $top['source'])->sole();
        $buyerAge = $sellerFailure === 'loading' ? ($site === 'mesh' ? 180 : 130) : 181;
        $buyer->update(['observed_at' => now()->subSeconds($buyerAge)]);
        match ($sellerFailure) {
            'invalidated' => $seller->update(['data' => $seller->data + ['invalidated_by_action' => 4]]),
            'snapshot' => $seller->update(['observed_at' => now()->subSeconds(181)]),
            'loading' => $seller->update(['observed_at' => now()->subSeconds(135)]),
            'missing' => $seller->delete(),
            'fresh' => null,
        };
        $ranker = $this->partialMock(CampaignTrade::class);
        $ranker->shouldReceive('rank')->andReturn([$top, $fallback]);
        $api = $this->partialMock(SpaceTradersApi::class);
        if (in_array($site, ['catch', 'no_probe'], true)) {
            $api->shouldReceive('market')->once()->with($this->agent, $top['source'], false)
                ->andReturn(['symbol' => $top['source'], 'imports' => [], 'exports' => [], 'exchange' => []]);
        } else {
            $api->shouldNotReceive('market');
        }
        $preview = new AutomationRun(['strategy' => 'scout', 'plan' => ['destination' => $fallback['source']]]);
        $this->partialMock(AutomationRunner::class)->shouldReceive('preview')->once()
            ->withArgs(fn ($agent, $symbol, $plan) => $plan['strategy'] === 'scout' && $plan['destination'] === $fallback['source'])->andReturn($preview);
        $considered = [];
        $director = app(CampaignDirector::class);
        $run = $this->invokeDirector($director, 'trade', [$grant, $episode, $this->agent, $ship, [], &$considered, ['LOCAL-2']]);
        $this->assertSame($preview, $run, json_encode($considered));
        $this->assertFalse((new \ReflectionProperty($director, 'awaitingQuote'))->getValue($director));
        $this->assertArrayNotHasKey('awaited_pair_since', $episode->fresh()->state);
    }

    public static function firstQuoteFailures(): array
    {
        $cases = [];
        foreach (['pool', 'mesh', 'catch'] as $site) {
            foreach (['missing', 'invalidated', 'snapshot', 'loading'] as $failure) {
                $cases[$site.' '.$failure] = [$site, $failure];
            }
        }

        return $cases + ['buyer stale without probes' => ['no_probe', 'fresh']];
    }

    #[DataProvider('refreshFallbacks')]
    public function test_trade_rerank_preserves_eligible_same_source_fallbacks(string $scenario): void
    {
        $this->installTradeQuotes();
        $body = Yaml::parse($this->bootstrapBody(target: 2, maxPrice: 50000, minWorkingCapital: 75000));
        if ($scenario === 'mesh') {
            $body['probes']['mesh'] = Yaml::parseFile(base_path('campaigns/weekly-system-saturation.yaml'))['probes']['mesh'];
        }
        $grant = $this->approve(Yaml::dump($body, 10));
        $episode = $this->tradeEpisode($grant);
        $ship = Ship::where('symbol', 'LOCAL-1')->sole()->snapshot;
        $source = $ship['nav']['waypointSymbol'];
        $top = ['key' => 'IRON_ORE@X1-TEST-B1', 'good' => 'IRON_ORE', 'source' => $source, 'buyer' => 'X1-TEST-B1', 'units' => 1,
            'max_inventory' => 100, 'fuel_safety_ceiling' => 100, 'departure_refuel' => 0, 'predicted_cost' => 100, 'predicted_margin' => 100,
            'score' => 20, 'seconds' => 300];
        $fallback = array_replace($top, ['key' => 'IRON_ORE@X1-TEST-PORT', 'buyer' => 'X1-TEST-PORT']);
        if ($scenario === 'usable_reserved') {
            [$top, $fallback] = [$fallback, $top];
            $row = GameObservation::where('kind', 'market')->where('symbol', $top['buyer'])->sole()->replicate(['symbol']);
            $row->forceFill(['symbol' => $fallback['buyer']])->save();
        }
        $newWinner = array_replace($top, ['key' => 'IRON_ORE@X1-TEST-C', 'buyer' => 'X1-TEST-C']);
        if ($scenario === 'blocked_remote') {
            $newWinner['source'] = 'X1-TEST-OTHER';
        }
        $this->partialMock(SpaceTradersApi::class)->shouldReceive('market')->once()->with($this->agent, $source, false)
            ->andReturn(GameObservation::where('kind', 'market')->where('symbol', $source)->sole()->data);
        if (in_array($scenario, ['reserved', 'blocked_remote', 'usable_reserved'], true)) {
            AutomationRun::create([
                'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'campaign_episode_id' => $episode->id,
                'ship_symbol' => 'LOCAL-2', 'strategy' => 'scout', 'state' => 'preview', 'plan_version' => 1,
                'plan' => ['destination' => $scenario === 'reserved' ? $newWinner['buyer'] : $top['buyer']], 'totals' => [], 'expires_at' => now()->addHour(),
            ]);
        }
        if ($scenario === 'suppressed') {
            $episode->update(['state' => ['suppressed_pairs' => [$top['key'] => now()->timestamp + 90]]]);
        }
        $initial = in_array($scenario, ['blocked_remote', 'suppressed', 'usable_reserved'], true) ? [$top, $fallback] : [$fallback];
        $after = in_array($scenario, ['suppressed', 'usable_reserved'], true) ? [$top, $fallback] : [$newWinner, $fallback];
        $this->partialMock(CampaignTrade::class)->shouldReceive('rank')->twice()->andReturn($initial, $after);
        $preview = new AutomationRun(['strategy' => 'trade']);
        $runner = $this->partialMock(AutomationRunner::class);
        if ($scenario === 'eligible') {
            $runner->shouldNotReceive('preview');
        } else {
            $runner->shouldReceive('preview')->once()->withArgs(fn ($agent, $symbol, $plan) => $plan['market'] === $fallback['buyer'])->andReturn($preview);
        }

        $considered = [];
        $run = $this->invokeDirector(app(CampaignDirector::class), 'trade', [$grant, $episode, $this->agent, $ship, [], &$considered, ['LOCAL-2']]);
        $replans = collect($considered)->filter(fn ($row) => str_contains($row['rejected'] ?? '', 'Refreshed quotes changed'))->count();
        $this->assertSame($scenario === 'eligible' ? null : $preview, $run, json_encode($considered));
        $this->assertSame($scenario === 'eligible' ? 1 : 0, $replans);
        if ($scenario === 'usable_reserved') {
            $this->assertArrayNotHasKey('awaited_pair_since', $episode->fresh()->state);
        }
    }

    public static function refreshFallbacks(): array
    {
        return [['blocked_remote'], ['suppressed'], ['reserved'], ['usable_reserved'], ['mesh'], ['eligible']];
    }

    public function test_rerank_excludes_blocked_and_reserved_rows_without_pinning_episode_roles(): void
    {
        $grant = $this->approve($this->bootstrapBody(target: 2, maxPrice: 50000, minWorkingCapital: 75000));
        $episode = $this->tradeEpisode($grant);
        $ship = Ship::where('symbol', 'LOCAL-1')->sole()->snapshot;
        $source = $ship['nav']['waypointSymbol'];
        $reserved = 'X1-TEST-REMOTE';
        AutomationRun::create([
            'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'campaign_episode_id' => $episode->id,
            'ship_symbol' => 'LOCAL-2', 'strategy' => 'scout', 'state' => 'preview', 'plan_version' => 1,
            'plan' => ['destination' => $reserved], 'totals' => [], 'fingerprint' => 'synthetic', 'expires_at' => now()->addHour(),
        ]);
        $rows = [
            ['key' => 'IRON_ORE@'.$reserved, 'good' => 'IRON_ORE', 'source' => $source, 'buyer' => $reserved],
            ['key' => 'IRON_ORE@X1-TEST-B', 'good' => 'IRON_ORE', 'source' => 'X1-TEST-S', 'buyer' => 'X1-TEST-B'],
            ['key' => 'IRON_ORE@X1-TEST-C', 'good' => 'IRON_ORE', 'source' => $source, 'buyer' => 'X1-TEST-C'],
            ['key' => 'IRON_ORE@X1-TEST-D', 'good' => 'IRON_ORE', 'source' => $source, 'buyer' => 'X1-TEST-D'],
        ];
        $rejected = [$rows[2]['key'].'|'.$source => true];
        DB::enableQueryLog();
        DB::flushQueryLog();
        $eligible = $this->invokeDirector(app(CampaignDirector::class), 'filterEligibleCandidates', [$grant, $episode, $this->agent, $ship, $rows, false, true, $rejected]);
        $writes = array_filter(DB::getQueryLog(), fn ($query) => ! preg_match('/^select /i', $query['query']));
        DB::disableQueryLog();
        $this->assertSame([$rows[3]], $eligible);
        $this->assertSame([], $writes);
        $this->assertArrayNotHasKey('role_ships', $episode->refresh()->state);
    }

    #[DataProvider('detailedRetryResults')]
    public function test_listing_only_market_read_is_retried_once_within_the_transport_cache_scope(bool $detailed): void
    {
        $agent = $this->agent;
        $symbol = Ship::where('symbol', 'LOCAL-1')->sole()->snapshot['nav']['waypointSymbol'];
        $director = app(CampaignDirector::class);
        $state = new \ReflectionProperty($director, 'marketReads');
        $details = new \ReflectionProperty($director, 'detailedMarketReads');
        $attempts = new \ReflectionProperty($director, 'detailedRetryAttempted');

        $before = count($this->world()['calls']);
        app(SpaceTradersClient::class)->scope(function () use ($agent, $symbol, $director, $state, $details, $attempts, $detailed) {
            $ships = $this->world()['ships'];
            $this->editWorld(fn (&$world) => $world['ships'] = []);
            $api = app(SpaceTradersApi::class);
            $initial = $api->market($agent, $symbol);
            $this->assertEmpty($initial['tradeGoods'] ?? []);
            $state->setValue($director, [$symbol => true]);
            if ($detailed) {
                $this->editWorld(fn (&$world) => $world['ships'] = $ships);
            }

            $this->assertTrue((new \ReflectionMethod($director, 'canRefreshTradeMarket'))->invoke($director, $agent, $symbol));
            (new \ReflectionMethod($director, 'refreshTradeMarket'))->invoke($director, $agent, $symbol);
            $this->assertSame($detailed ? [$symbol => true] : [], $details->getValue($director));
            $this->assertSame([$symbol => true], $attempts->getValue($director));
            $this->assertFalse((new \ReflectionMethod($director, 'canRefreshTradeMarket'))->invoke($director, $agent, $symbol));
        });
        $paths = array_column(array_slice($this->world()['calls'], $before), 'path');
        $this->assertSame(2, count(array_keys($paths, '/systems/X1-TEST/waypoints/'.$symbol.'/market', true)));
    }

    public static function detailedRetryResults(): array
    {
        return ['detailed retry' => [true], 'listing retry' => [false]];
    }

    private function tradeEpisode(CampaignGrant $grant): CampaignEpisode
    {
        return CampaignEpisode::create([
            'campaign_grant_id' => $grant->id, 'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id,
            'start_credits' => $this->agent->credits, 'phase' => 'earn', 'opened_at' => now(), 'next_reset_at' => now()->addDays(5), 'state' => [],
        ]);
    }

    private function installTradeQuotes(): void
    {
        $sync = app(AgentSynchronizer::class);
        $fuel = ['symbol' => 'FUEL', 'tradeVolume' => 100, 'purchasePrice' => 60, 'sellPrice' => 50];
        foreach (['X1-TEST-ROCK' => 100, 'X1-TEST-PORT' => 450] as $symbol => $price) {
            $sync->observe($this->agent, 'market', $symbol, ['symbol' => $symbol, 'exports' => [], 'imports' => [], 'exchange' => [['symbol' => 'IRON_ORE'], ['symbol' => 'FUEL']],
                'tradeGoods' => [['symbol' => 'IRON_ORE', 'tradeVolume' => 1, 'purchasePrice' => $price, 'sellPrice' => $price - 10], $fuel]]);
        }
    }

    #[DataProvider('parkedReadOrigins')]
    public function test_trade_reuses_detailed_reads_and_retries_failed_parked_reads(string $origin): void
    {
        $this->installTradeQuotes();
        $body = Yaml::parse($this->bootstrapBody(target: 2, maxPrice: 50000, minWorkingCapital: 75000));
        if ($origin === 'directory') {
            $body['probes']['mesh'] = Yaml::parseFile(base_path('campaigns/weekly-system-saturation.yaml'))['probes']['mesh'];
        }
        $grant = $this->approve(Yaml::dump($body, 10));
        $episode = $this->tradeEpisode($grant);
        $buyer = 'X1-TEST-PORT';
        $source = 'X1-TEST-ROCK';
        $agent = $this->agent;
        $sync = app(AgentSynchronizer::class);
        $observer = $origin === 'role' ? $this->probeShip('LOCAL-2', $buyer) : FakeSpaceTraders::lightHauler('LOCAL-4', $buyer);
        $this->editWorld(function (&$world) use ($observer, $origin, $buyer) {
            if ($origin === 'role') {
                $world['ships'][1] = $observer;
            } else {
                $world['ships'][] = $observer;
            }
            if ($origin === 'failed') {
                $world['missing_markets'][] = $buyer;
            }
        });
        $sync->saveShip($agent, $observer);
        GameObservation::where('kind', 'market')->where('symbol', $buyer)->update(['observed_at' => now()->subMinutes(5)]);
        $ship = Ship::where('symbol', 'LOCAL-1')->sole()->snapshot;
        $director = app(CampaignDirector::class);
        $read = new \ReflectionProperty($director, 'detailedMarketReads');
        $row = ['key' => 'IRON_ORE@'.$buyer, 'good' => 'IRON_ORE', 'source' => $source, 'buyer' => $buyer, 'units' => 1,
            'max_inventory' => 100, 'fuel_safety_ceiling' => 100, 'departure_refuel' => 0, 'predicted_cost' => 100,
            'score' => 10, 'seconds' => 300];
        $this->partialMock(CampaignTrade::class)->shouldReceive('rank')->andReturn([$row]);
        $preview = new AutomationRun(['strategy' => 'trade']);
        $this->partialMock(AutomationRunner::class)->shouldReceive('preview')->once()->andReturn($preview);
        $before = count($this->world()['calls']);

        app(SpaceTradersClient::class)->scope(function () use ($origin, $buyer, $agent, $episode, $grant, $director, $read, $ship, $preview) {
            if ($origin === 'role') {
                $this->invokeDirector($director, 'refreshRoleMarkets', [$grant, $episode, $agent, [], false]);
            } elseif ($origin === 'directory') {
                GameObservation::where('kind', 'market_listing')->where('symbol', $buyer)->delete();
                $reads = $details = [];
                app(CampaignDiscovery::class)->advance($episode, $agent, $reads, microtime(true) + 5, $details);
                $this->assertArrayHasKey($buyer, $reads);
                (new \ReflectionProperty($director, 'marketReads'))->setValue($director, $reads);
                $read->setValue($director, $details);
            } else {
                $this->invokeDirector($director, 'refreshParkedMarket', [$episode, $agent]);
            }
            if ($origin === 'failed') {
                $this->assertArrayNotHasKey($buyer, $read->getValue($director));
                $this->editWorld(fn (&$world) => $world['missing_markets'] = []);
            } else {
                $this->assertArrayHasKey($buyer, $read->getValue($director));
            }
            $considered = [];
            $run = $this->invokeDirector($director, 'trade', [$grant, $episode, $agent, $ship, [], &$considered, ['LOCAL-2']]);
            $this->assertSame($preview, $run, json_encode($considered));
        });
        $paths = array_column(array_slice($this->world()['calls'], $before), 'path');
        $this->assertSame($origin === 'failed' ? 2 : 1, count(array_keys($paths, '/systems/X1-TEST/waypoints/'.$buyer.'/market', true)));
    }

    public static function parkedReadOrigins(): array
    {
        return [['role'], ['parked'], ['failed'], ['directory']];
    }

    private function invokeDirector(CampaignDirector $director, string $method, array $arguments): mixed
    {
        return (new \ReflectionMethod($director, $method))->invokeArgs($director, $arguments);
    }

    public function test_stationed_probe_is_excluded_from_pool_scouting_and_stays_parked(): void
    {
        $this->editWorld(function (array &$world) {
            $world['ships'][] = $this->probeShip('LOCAL-3', 'X1-TEST-GATE');
            $world['waypoints'] = [
                ['symbol' => 'X1-TEST-ROCK', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-GATE', 'systemSymbol' => 'X1-TEST', 'type' => 'JUMP_GATE', 'x' => 20, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-M2', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 5, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]],
                ['symbol' => 'X1-TEST-M3', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 0, 'y' => 5, 'traits' => [['symbol' => 'MARKETPLACE']]],
            ];
            $world['market_goods'] = [
                'X1-TEST-ROCK' => [['symbol' => 'FUEL', 'tradeVolume' => 100, 'purchasePrice' => 70, 'sellPrice' => 65]],
                'X1-TEST-GATE' => [['symbol' => 'ANTIMATTER', 'tradeVolume' => 10, 'purchasePrice' => 5000, 'sellPrice' => 4500]],
                'X1-TEST-M2' => [['symbol' => 'FUEL', 'tradeVolume' => 100, 'purchasePrice' => 70, 'sellPrice' => 65]],
                'X1-TEST-M3' => [['symbol' => 'FUEL', 'tradeVolume' => 100, 'purchasePrice' => 70, 'sellPrice' => 65]],
            ];
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->seedDiscoveryDone();
        Ship::where('agent_id', $this->agent->id)->where('symbol', 'LOCAL-3')->update(['stationed_waypoint' => 'X1-TEST-GATE']);

        $grant = $this->approve($this->bootstrapBody(target: 2, maxPrice: 50000, minWorkingCapital: 75000));
        $episode = CampaignEpisode::create([
            'campaign_grant_id' => $grant->id,
            'agent_id' => $this->agent->id,
            'reset_id' => $this->agent->reset_id,
            'start_credits' => 150000,
            'phase' => 'earn',
            'opened_at' => now(),
            'next_reset_at' => now()->addDays(5),
            'state' => ['last_discovery_at' => now()->toIso8601String(), 'role_ships' => ['command' => 'LOCAL-1', 'probe' => 'LOCAL-2']],
        ]);

        $poolSymbols = array_column(app(CampaignFleet::class)->probes($episode), 'symbol');
        $this->assertSame(['LOCAL-2'], $poolSymbols);

        $this->tickDirector();
        $this->assertSame(1, AutomationRun::where('ship_symbol', 'LOCAL-2')->whereNotNull('active_agent_id')->count());
        $this->assertSame(0, AutomationRun::where('ship_symbol', 'LOCAL-3')->whereNotNull('active_agent_id')->count());
    }

    private function baseWorld(): array
    {
        $world = FakeSpaceTraders::world();
        $world['credits'] = 150000;
        $world['next_reset'] = now()->addDays(5)->toIso8601String();
        $world['ships'][0]['registration']['role'] = 'COMMAND';
        $world['ships'][0]['nav']['waypointSymbol'] = 'X1-TEST-ROCK';
        $world['ships'][] = $this->probeShip('LOCAL-2', 'X1-TEST-ROCK');

        return $world;
    }

    private function probeShip(string $symbol, string $waypoint): array
    {
        $ship = FakeSpaceTraders::ship($symbol, $waypoint);
        $ship['registration']['role'] = 'SATELLITE';
        $ship['fuel'] = ['current' => 0, 'capacity' => 0];
        $ship['cargo'] = ['capacity' => 0, 'units' => 0, 'inventory' => []];
        $ship['frame']['symbol'] = 'FRAME_PROBE';
        $ship['frame']['fuelCapacity'] = 0;
        $ship['frame']['moduleSlots'] = 0;
        $ship['frame']['mountingPoints'] = 0;
        $ship['engine']['speed'] = 9;
        $ship['crew'] = ['current' => 0, 'capacity' => 0, 'required' => 0];
        $ship['mounts'] = [];
        $ship['modules'] = [];

        return $ship;
    }

    private function probeOffer(int $price): array
    {
        $probe = $this->probeShip('OFFER', 'X1-TEST-YARDB');

        return array_intersect_key($probe, array_flip(['frame', 'reactor', 'engine', 'crew', 'mounts', 'modules'])) + [
            'type' => 'SHIP_PROBE',
            'name' => 'Probe',
            'purchasePrice' => $price,
        ];
    }

    private function bootstrapBody(int $target, int $maxPrice, int $minWorkingCapital, bool $pool = true): string
    {
        $body = Yaml::parse(file_get_contents(base_path('campaigns/weekly-probe-bootstrap.yaml')));
        $body['agent']['symbol'] = 'LOCAL';
        $body['probes'] = [
            'target' => $target,
            'max_price' => $maxPrice,
            'min_working_capital' => $minWorkingCapital,
            'pool' => $pool,
        ];

        return Yaml::dump($body, 10);
    }

    private function approve(string $yaml): CampaignGrant
    {
        $revision = app(CampaignPlaybook::class)->import($yaml);

        return app(CampaignControl::class)->approve($revision, $this->user);
    }

    private function createReconMission(string $shipSymbol, int $jumpCap): ReconMission
    {
        $terms = [
            'jump_exposure' => $jumpCap,
            'envelope' => $jumpCap * 2,
            'explore_until' => now()->addHours(12)->toIso8601String(),
            'return_deadline' => now()->addDay()->toIso8601String(),
        ];

        return ReconMission::create([
            'agent_id' => $this->agent->id,
            'approved_by' => $this->user->id,
            'approved_at' => now(),
            'reset_id' => $this->agent->reset_id,
            'ship_symbol' => $shipSymbol,
            'origin_system' => 'X1-TEST',
            'origin_gate' => 'X1-TEST-GATE',
            'state' => 'abroad',
            'terms' => $terms,
            'terms_hash' => ReconMission::hash($terms),
            'start_window_until' => now()->addHour(),
            'graph' => [],
            'capability' => [],
            'homeward_authorizations' => [],
            'return_reservation' => ['caps' => [$jumpCap], 'path' => ['X1-NEXT', 'X1-TEST'], 'total' => $jumpCap, 'cursor' => 0],
            'visits' => [],
        ]);
    }

    private function seedDiscoveryDone(): void
    {
        app(AgentSynchronizer::class)->observe($this->agent, 'catalogue', 'X1-TEST', ['complete' => true, 'total' => count($this->world()['waypoints'])], 'listing');
    }

    private function tickDirector(): void
    {
        CampaignGrant::where('slot', 1)->update(['last_directed_at' => null]);
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        app(CampaignDirector::class)->tick();
    }

    private function runToCompletion(AutomationRun $run): void
    {
        for ($step = 0; $step < 25 && $run->fresh()->active_agent_id; $step++) {
            app(AutomationRunner::class)->advance($run->id);
            foreach (ShipAction::where('automation_run_id', $run->id)->where('state', 'pending')->get() as $action) {
                app(ShipCommandExecutor::class)->execute($action->id);
            }
            $this->travel(6)->seconds();
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        }
    }

    private function world(): array
    {
        return json_decode(file_get_contents($this->file), true, flags: JSON_THROW_ON_ERROR);
    }

    private function editWorld(callable $mutator): void
    {
        $world = $this->world();
        $mutator($world);
        file_put_contents($this->file, json_encode($world, JSON_THROW_ON_ERROR));
    }

    private function setBalance(int $credits): void
    {
        $this->editWorld(function (array &$world) use ($credits) {
            $world['credits'] = $credits;
        });
        $this->agent->update(['credits' => $credits]);
        DB::table('agent_credit_baselines')
            ->where('agent_id', $this->agent->id)
            ->where('reset_id', $this->agent->reset_id)
            ->update(['credits' => $credits]);
    }
}
