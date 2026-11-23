<?php

namespace Tests\Support;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\Engine\EngineHost;
use App\Gameplay\FleetMetrics;
use App\Gameplay\ShipCommandExecutor;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\CampaignRevision;
use App\Models\LocalEngine;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;

trait SaturationFixture
{
    protected Agent $agent;

    protected User $operator;

    protected string $fixture;

    protected CampaignRevision $armRevision;

    protected CampaignRevision $revision;

    protected function setUpSaturation(int $hubs = 4, bool $remoteYard = false, bool $seedCoverage = true, int $credits = 2200000, string $agentSymbol = 'LOCAL'): void
    {
        $this->travelTo(now()->startOfSecond());
        config(['gameplay.enabled' => true, 'gameplay.local_engine_enabled' => true, 'gameplay.snapshot_seconds' => 180]);
        $this->app->instance(EngineHost::class, new FakeEngineHost);
        Queue::fake();
        EngineFixture::healthy();
        $this->fixture = tempnam(sys_get_temp_dir(), 'st-saturation-');
        $world = FakeSpaceTraders::world();
        $world['reset'] = now()->subDays(2)->utc()->toDateString();
        $world['next_reset'] = now()->addDays(5)->utc()->toIso8601String();
        $world['credits'] = $credits;
        $world['symbol'] = $agentSymbol;
        $world['ships'][0] = FakeSpaceTraders::ship($agentSymbol.'-1');
        $world['ships'][0]['registration']['role'] = 'COMMAND';
        $world['ships'][] = FakeSpaceTraders::probe($agentSymbol.'-2', 'X1-TEST-ROCK');
        $world['waypoints'][0]['traits'] = [['symbol' => 'MARKETPLACE']];
        $world['waypoints'][1]['traits'] = [['symbol' => 'MARKETPLACE']];
        for ($i = 3; $i <= $hubs; $i++) {
            $world['waypoints'][] = $this->point('X1-TEST-H'.$i, $i + 5);
        }
        $yard = $remoteYard ? 'X1-TEST-YARD' : 'X1-TEST-ROCK';
        if ($remoteYard) {
            $world['waypoints'][] = $this->point($yard, 2376, ['SHIPYARD']);
            $world['missing_markets'] = [$yard];
        } else {
            $world['waypoints'][0]['traits'][] = ['symbol' => 'SHIPYARD'];
        }
        $world['shipyards'][$yard] = [
            'shipTypes' => [['type' => 'SHIP_PROBE'], ['type' => 'SHIP_LIGHT_HAULER']],
            'ships' => [FakeSpaceTraders::shipyardOffer('SHIP_PROBE', FakeSpaceTraders::probe('OFFER-1', $yard), 25000),
                FakeSpaceTraders::shipyardOffer('SHIP_LIGHT_HAULER', FakeSpaceTraders::lightHauler('OFFER-2', $yard), 350000)],
        ];
        $world['goods'] = [
            ['symbol' => 'IRON_ORE', 'tradeVolume' => 20, 'purchasePrice' => 100, 'sellPrice' => 75],
            ['symbol' => 'FUEL', 'tradeVolume' => 10, 'purchasePrice' => 5, 'sellPrice' => 3],
        ];
        $world['market_goods']['X1-TEST-PORT'] = [
            ['symbol' => 'IRON_ORE', 'tradeVolume' => 20, 'purchasePrice' => 10000, 'sellPrice' => 8000],
            $world['goods'][1],
        ];
        $this->operator = User::factory()->create();
        $this->agent = $this->operator->agents()->create(['name' => $agentSymbol, 'token' => 'synthetic-agent', 'faction' => 'VOID',
            'reset_id' => $world['reset'], 'canonical_identity' => AgentSynchronizer::identity($world['reset'], $agentSymbol)]);
        file_put_contents($this->fixture, json_encode($world, JSON_THROW_ON_ERROR));
        FakeSpaceTraders::install($this->fixture);
        $playbook = app(CampaignPlaybook::class);
        $this->armRevision = $playbook->import(str_replace('GIANTSOUP', $agentSymbol, file_get_contents(base_path('campaigns/arm-trade.yaml'))));
        $body = Yaml::parse(file_get_contents(base_path('campaigns/weekly-system-saturation.yaml')));
        $body['agent']['symbol'] = $agentSymbol;
        $body['fleet']['auto_arm']['revision_hash'] = $this->armRevision->content_hash;
        $this->revision = $playbook->import(Yaml::dump($body, 12));
        $sync = app(AgentSynchronizer::class);
        $sync->sync($this->agent, false);
        foreach ($world['waypoints'] as $point) {
            $sync->observe($this->agent, 'waypoint', $point['symbol'], $point, 'listing');
        }
        $sync->observe($this->agent, 'catalogue', 'X1-TEST', ['complete' => true, 'total' => count($world['waypoints'])], 'listing');
        if ($seedCoverage) {
            $this->seedCoverage($world);
        }
    }

    protected function seedCoverage(array $world): void
    {
        $sync = app(AgentSynchronizer::class);
        foreach ($world['waypoints'] as $i => $point) {
            $symbol = $point['symbol'];
            if (! in_array('MARKETPLACE', array_column($point['traits'], 'symbol'), true)) {
                continue;
            }
            if ($i > 0) {
                $world['ships'][] = FakeSpaceTraders::probe($this->agent->name.'-'.($i + 2), $symbol);
            }
            $goods = $world['market_goods'][$symbol] ?? $world['goods'];
            $sync->observe($this->agent, 'market', $symbol, [
                'symbol' => $symbol, 'imports' => [], 'exports' => [], 'exchange' => array_map(fn ($row) => ['symbol' => $row['symbol']], $goods),
                'tradeGoods' => $goods,
            ]);
        }
        file_put_contents($this->fixture, json_encode($world, JSON_THROW_ON_ERROR));
        $sync->sync($this->agent, false);
    }

    protected function point(string $symbol, int $x, array $traits = ['MARKETPLACE']): array
    {
        return ['symbol' => $symbol, 'systemSymbol' => implode('-', array_slice(explode('-', $symbol), 0, 2)), 'type' => 'PLANET', 'x' => $x,
            'y' => 0, 'traits' => array_map(fn ($trait) => ['symbol' => $trait], $traits)];
    }

    protected function primary(string $phase = 'earn', ?CampaignRevision $revision = null): CampaignEpisode
    {
        $control = app(CampaignControl::class);
        $grant = $control->approve($revision ?? $this->revision, $this->operator);

        return CampaignEpisode::create(['agent_id' => $this->agent->id, 'campaign_grant_id' => $grant->id, 'reset_id' => $this->agent->reset_id,
            'phase' => $phase, 'opened_at' => now(), 'start_credits' => $this->agent->fresh()->credits, 'next_reset_at' => $this->world()['next_reset'], 'state' => []]);
    }

    protected function world(): array
    {
        return json_decode(file_get_contents($this->fixture), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function editFixture(callable $edit): void
    {
        $world = $this->world();
        $edit($world);
        file_put_contents($this->fixture, json_encode($world, JSON_THROW_ON_ERROR));
    }

    protected function schedulerPass(): void
    {
        app(FleetMetrics::class)->schedulerPass(['lags' => [2, 8], 'engine_session_id' => LocalEngine::find(1)->session_id]);
    }

    protected function tickC0(): void
    {
        $this->travel(31)->seconds();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        $this->assertTrue(app(CampaignDirector::class)->tick());
    }

    protected function workRun(AutomationRun $run): void
    {
        for ($step = 0; $step < 70 && $run->refresh()->active_agent_id; $step++) {
            app(AutomationRunner::class)->advance($run->id);
            foreach (ShipAction::where('automation_run_id', $run->id)->where('state', 'pending')->get() as $action) {
                app(ShipCommandExecutor::class)->execute($action->id);
            }
            if ($run->refresh()->state->value === 'blocked') {
                break;
            }
            $this->travel(5)->seconds();
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        }
    }

    protected function arm(string $ship, string $label, int $minutes = 240): CampaignGrant
    {
        return app(CampaignControl::class)->approveArm($this->armRevision, $this->operator, ['ship' => $ship, 'label' => $label], now()->toImmutable()->addMinutes($minutes));
    }

    protected function seedArmEpisode(CampaignGrant $grant): CampaignEpisode
    {
        return CampaignEpisode::create(['agent_id' => $this->agent->id, 'campaign_grant_id' => $grant->id,
            'reset_id' => $this->agent->reset_id, 'phase' => 'earn', 'opened_at' => now(),
            'start_credits' => $this->agent->fresh()->credits, 'next_reset_at' => $this->world()['next_reset'], 'state' => []]);
    }

    protected function haulerPurchase(CampaignEpisode $episode, string $key, string $state = 'acknowledged', array $outcome = [], bool $requiresReview = false): ShipAction
    {
        $run = AutomationRun::create(['agent_id' => $this->agent->id, 'campaign_episode_id' => $episode->id,
            'reset_id' => $this->agent->reset_id, 'ship_symbol' => $this->agent->name.'-2', 'strategy' => 'buy_hauler',
            'state' => 'stopped', 'plan_version' => 7, 'expires_at' => now()->addMinutes(3), 'plan' => [], 'totals' => []]);

        return ShipAction::create(['agent_id' => $this->agent->id, 'automation_run_id' => $run->id,
            'ship_symbol' => $run->ship_symbol, 'reset_id' => $this->agent->reset_id, 'key' => $key,
            'origin' => 'automation', 'command' => 'buy_ship', 'state' => $state,
            'arguments' => ['shipType' => 'SHIP_LIGHT_HAULER', 'waypointSymbol' => 'X1-TEST-ROCK'],
            'before_state' => ['estimate' => ['fleet_symbols' => Ship::where('agent_id', $this->agent->id)->pluck('symbol')->all()]],
            'outcome' => $outcome, 'review_required' => $requiresReview, 'sent_at' => now(), 'settled_at' => now(), 'expires_at' => now()->addMinutes(3)]);
    }

    protected function tearDownSaturation(): void
    {
        if (isset($this->fixture)) {
            unlink($this->fixture);
        }
    }
}
