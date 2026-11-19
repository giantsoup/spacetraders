<?php

namespace Tests\Support;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\CreditAccounting;
use App\Gameplay\Engine\EngineHost;
use App\Gameplay\GateRecon;
use App\Gameplay\ShipCommandExecutor;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignGrant;
use App\Models\LocalEngine;
use App\Models\ReconMission;
use App\Models\ShipAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

abstract class ReconTestCase extends TestCase
{
    use RefreshDatabase;

    protected string $file;

    protected Agent $agent;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Http::preventStrayRequests();
        config(['gameplay.enabled' => true, 'gameplay.local_engine_enabled' => true, 'gameplay.gate_recon_enabled' => true]);
        $this->app->instance(EngineHost::class, new FakeEngineHost);
        $world = FakeSpaceTraders::world();
        $world['foundation'] = true;
        $world['credits'] = 175000;
        $world['next_reset'] = now()->addDays(3)->toIso8601String();
        $world['ships'][0]['registration']['role'] = 'COMMAND';
        $world['ships'][0]['fuel'] = ['current' => 400, 'capacity' => 400];
        $probe = FakeSpaceTraders::ship('LOCAL-2', 'X1-TEST-GATE');
        $probe['registration']['role'] = 'PROBE';
        $probe['frame']['symbol'] = 'FRAME_PROBE';
        $probe['fuel'] = ['current' => 0, 'capacity' => 0];
        $probe['modules'] = $probe['mounts'] = [];
        $probe['engine']['speed'] = 9;
        $probe['nav']['status'] = 'IN_ORBIT';
        $world['ships'][] = $probe;
        foreach (['TEST', 'NEXT', 'DEEP'] as $index => $system) {
            $world['waypoints'][] = ['symbol' => "X1-$system-GATE", 'systemSymbol' => "X1-$system", 'type' => 'JUMP_GATE', 'x' => 0, 'y' => 0,
                'isUnderConstruction' => false, 'traits' => [['symbol' => 'MARKETPLACE']]];
            $world['market_goods']["X1-$system-GATE"] = [['symbol' => 'ANTIMATTER', 'tradeVolume' => 10, 'purchasePrice' => 6000, 'sellPrice' => 5000]];
        }
        $world['gate_connections'] = ['X1-TEST-GATE' => ['X1-NEXT-GATE'], 'X1-NEXT-GATE' => ['X1-TEST-GATE', 'X1-DEEP-GATE'], 'X1-DEEP-GATE' => ['X1-NEXT-GATE']];
        $world['jump_price'] = 6000;
        $this->file = tempnam(sys_get_temp_dir(), 'st-recon-');
        file_put_contents($this->file, json_encode($world));
        FakeSpaceTraders::install($this->file);
        $this->user = User::factory()->create();
        $this->agent = $this->user->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic-only', 'faction' => 'VOID', 'reset_id' => $world['reset'],
            'canonical_identity' => AgentSynchronizer::identity($world['reset'], 'LOCAL')]);
        app(AgentSynchronizer::class)->sync($this->agent);
        Queue::fake();
        EngineFixture::healthy();
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        parent::tearDown();
    }

    protected function mission(array $terms = []): ReconMission
    {
        return app(GateRecon::class)->approve($this->agent, $this->user, 'LOCAL-2', $terms);
    }

    protected function tick(AutomationRun $run): void
    {
        app(AutomationRunner::class)->advance($run->id);
        foreach (ShipAction::where('automation_run_id', $run->id)->where('state', 'pending')->get() as $action) {
            app(ShipCommandExecutor::class)->execute($action->id);
        }
        $next = $run->fresh()->next_action_at;
        $this->travelTo($next && $next->isFuture() ? $next : now()->addSeconds(6));
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
    }

    protected function changeWorld(array $changes): void
    {
        $world = json_decode(file_get_contents($this->file), true);
        file_put_contents($this->file, json_encode(array_replace_recursive($world, $changes)));
    }

    protected function pendingJump(AutomationRun $run): ShipAction
    {
        for ($i = 0; $i < 80; $i++) {
            app(AutomationRunner::class)->advance($run->id);
            foreach (ShipAction::where('automation_run_id', $run->id)->where('state', 'pending')->get() as $action) {
                if ($action->command === 'jump') {
                    return $action;
                }
                app(ShipCommandExecutor::class)->execute($action->id);
            }
            $this->travel(6)->seconds();
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        }
        $this->fail('Expected a pending jump: '.json_encode($run->fresh()->toArray()));
    }

    protected function campaign(): CampaignGrant
    {
        $body = Yaml::parse(str_replace('GIANTSOUP', 'LOCAL', file_get_contents(base_path('campaigns/weekly-default.yaml'))));
        $body['phases'] = array_slice($body['phases'], 1);
        $grant = app(CampaignControl::class)->approve(app(CampaignPlaybook::class)->import(Yaml::dump($body, 10)), $this->user);

        return $grant;
    }

    protected function balance(int $credits): void
    {
        $world = json_decode(file_get_contents($this->file), true);
        $delta = $credits - $world['credits'];
        $world['credits'] = $credits;
        file_put_contents($this->file, json_encode($world));
        $outside = ShipAction::create(['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-1',
            'origin' => 'manual', 'command' => 'buy', 'key' => (string) Str::uuid(), 'state' => 'confirmed', 'arguments' => [],
            'outcome' => ['credit_delta' => $delta], 'settled_at' => now(), 'expires_at' => now()->addMinutes(3)]);
        app(CreditAccounting::class)->record($this->agent, $outside, $delta);
        $this->agent->update(['credits' => $credits]);
    }

    protected function tradeWorld(int $ask = 100, int $bid = 500): void
    {
        $world = json_decode(file_get_contents($this->file), true);
        $fuel = ['symbol' => 'FUEL', 'tradeVolume' => 100, 'purchasePrice' => 5, 'sellPrice' => 1];
        $world['goods'] = [['symbol' => 'IRON', 'tradeVolume' => 40, 'purchasePrice' => $ask, 'sellPrice' => 1], $fuel];
        $world['market_goods']['X1-TEST-PORT'] = [['symbol' => 'IRON', 'tradeVolume' => 40, 'purchasePrice' => $bid + 100, 'sellPrice' => $bid], $fuel];
        file_put_contents($this->file, json_encode($world));
        foreach (['X1-TEST-ROCK', 'X1-TEST-PORT'] as $symbol) {
            $data = ['symbol' => $symbol, 'imports' => [], 'exports' => [], 'exchange' => [['symbol' => 'IRON'], ['symbol' => 'FUEL']],
                'tradeGoods' => $world['market_goods'][$symbol] ?? $world['goods']];
            app(AgentSynchronizer::class)->observe($this->agent, 'market_listing', $symbol, $data);
            app(AgentSynchronizer::class)->observe($this->agent, 'market', $symbol, $data);
        }
    }

    protected function directorTrade(): AutomationRun
    {
        for ($i = 0; $i < 30; $i++) {
            app(CampaignDirector::class)->tick();
            $run = AutomationRun::whereNotNull('active_agent_id')->first();
            if ($run?->strategy === 'trade') {
                return $run;
            }
            if ($run) {
                $this->tick($run);
            } else {
                $this->travel(31)->seconds();
                LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
            }
        }
        $this->fail('Director did not create trade: '.json_encode(AutomationRun::all()->map(fn ($r) => [$r->id, $r->ship_symbol, $r->state->value, $r->active_agent_id, $r->blocked_reason])->all()));
    }
}
