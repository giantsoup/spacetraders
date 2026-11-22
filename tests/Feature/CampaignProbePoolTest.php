<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignDirector;
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
use App\Gameplay\ShipCommandExecutor;
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

    public function test_arrival_target_counts_other_in_flight_purchases_after_manual_probe_purchase(): void
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
        $this->assertCount(2, $buys);

        // An external manual purchase fills one of the two remaining slots while both runs are active.
        $this->editWorld(function (array &$world) {
            $world['ships'][] = $this->probeShip('LOCAL-4', 'X1-TEST-YARDB');
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $this->runToCompletion($buys[0]);
        $this->assertSame('blocked', $buys[0]->fresh()->state->value);
        $this->assertStringContainsString('Probe target', $buys[0]->fresh()->blocked_reason);
        $this->assertSame(0, ShipAction::where('command', 'buy_ship')->count());

        // Once the first run releases its slot, the other run can buy exactly the final probe.
        app(AutomationRunner::class)->stop($buys[0]);
        app(AutomationRunner::class)->advance($buys[0]->id);
        $this->runToCompletion($buys[1]);
        $this->assertSame('completed', $buys[1]->fresh()->state->value, $buys[1]->fresh()->blocked_reason ?? '');
        $this->assertSame(4, app(CampaignProbeBootstrap::class)->ownedProbeCount($this->agent->fresh()));
        $this->assertSame(1, ShipAction::where('command', 'buy_ship')->count());
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
