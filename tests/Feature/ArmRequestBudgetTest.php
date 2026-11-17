<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\Engine\EngineHost;
use App\Gameplay\FleetMetrics;
use App\Gameplay\ShipCommandExecutor;
use App\Jobs\AdvanceAutomationRun;
use App\Jobs\ExecuteShipCommand;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\LocalEngine;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\EngineFixture;
use Tests\Support\FakeEngineHost;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class ArmRequestBudgetTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    private User $user;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now());
        config(['gameplay.enabled' => true, 'gameplay.local_engine_enabled' => true]);
        $this->app->instance(EngineHost::class, new FakeEngineHost);
        $this->file = tempnam(sys_get_temp_dir(), 'st-arm-');
        $world = FakeSpaceTraders::world();
        $world['next_reset'] = now()->addHours(17)->toIso8601String();
        $world['ships'][0]['registration']['role'] = 'COMMAND';
        $probe = FakeSpaceTraders::ship('LOCAL-2', 'X1-TEST-PORT');
        $probe['registration']['role'] = 'SATELLITE';
        $probe['frame']['symbol'] = 'FRAME_PROBE';
        $world['ships'][] = $probe;
        $world['ships'][] = FakeSpaceTraders::ship('LOCAL-3');
        $world['market_goods']['X1-TEST-PORT'] = $world['goods'];
        $world['market_goods']['X1-TEST-PORT'][0]['sellPrice'] = 200;
        file_put_contents($this->file, json_encode($world));
        FakeSpaceTraders::install($this->file);
        $this->user = User::factory()->create();
        $this->user->rememberAccountToken('synthetic-account-token');
        $this->agent = $this->user->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic-only', 'faction' => 'VOID',
            'reset_id' => $world['reset'], 'canonical_identity' => AgentSynchronizer::identity($world['reset'], 'LOCAL')]);
        app(AgentSynchronizer::class)->sync($this->agent);
        Queue::fake();
        EngineFixture::healthy();
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        parent::tearDown();
    }

    private function tick(): void
    {
        app(CampaignDirector::class)->tick();
        $this->travel(31)->seconds();
        EngineFixture::healthy();
    }

    private function armBody(int $exposure = 100): string
    {
        return str_replace(['GIANTSOUP', 'max_credits: 60000'], ['LOCAL', 'max_credits: '.$exposure], file_get_contents(base_path('campaigns/arm-trade.yaml')));
    }

    private function editWorld(callable $edit): void
    {
        $world = json_decode(file_get_contents($this->file), true);
        $edit($world);
        file_put_contents($this->file, json_encode($world));
    }

    private function calls(): array
    {
        return json_decode(file_get_contents($this->file), true)['calls'];
    }

    /**
     * One arm cycle at k=3 buy lots and m=2 sell batches on FakeSpaceTraders (docs/request-budget-plan.md, "Fixture count").
     * The ceilings are today's measured values; later request-budget phases must lower them, never raise them.
     */
    public function test_one_arm_cycle_stays_within_the_request_ceilings(): void
    {
        $this->editWorld(function (&$world) {
            $world['credits'] = 100000;
            $world['goods'][0]['tradeVolume'] = 2;
        });
        app(AgentSynchronizer::class)->sync($this->agent);
        $revision = app(CampaignPlaybook::class)->import(str_replace('max_units_batches: 1', 'max_units_batches: 3', $this->armBody(100000)));
        DB::table('agent_credit_baselines')->where('agent_id', $this->agent->id)->update(['credits' => 100000]);
        app(CampaignControl::class)->approveArm($revision, $this->user, ['label' => 'T1a', 'ship' => 'LOCAL-3'], CarbonImmutable::now()->addHours(12));
        FleetMetrics::reset();
        $base = count($this->calls());
        app(CampaignDirector::class)->tickArms();
        $run = AutomationRun::whereNotNull('active_agent_id')->firstOrFail();
        $advances = 0;
        $noWrite = 0;
        for ($step = 0; $step < 80 && $run->fresh()->active_agent_id; $step++) {
            $pending = ShipAction::count();
            (new AdvanceAutomationRun($run->id))->handle(app(AutomationRunner::class));
            $advances++;
            $noWrite += ShipAction::count() === $pending ? 1 : 0;
            foreach (ShipAction::where('state', 'pending')->orderBy('id')->get() as $action) {
                (new ExecuteShipCommand($action->id))->handle(app(ShipCommandExecutor::class));
            }
            $this->travel(5)->seconds();
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        }
        $this->assertNull($run->fresh()->active_agent_id, 'The cycle did not finish.');
        $this->assertSame('completed', $run->fresh()->state->value, $run->fresh()->blocked_reason ?? '');
        FleetMetrics::flush();

        $calls = array_slice($this->calls(), $base);
        $byCategory = [];
        foreach ($calls as $call) {
            $category = FleetMetrics::category($call['method'], $call['path']);
            $byCategory[$category] = ($byCategory[$category] ?? 0) + 1;
        }
        ksort($byCategory);

        // Ceilings: measured on 2026-11-17 against feature/request-budget-phase0.
        $this->assertLessThanOrEqual(134, count($calls), 'total requests '.json_encode($byCategory));
        $ceilings = ['agent' => 27, 'market' => 41, 'ship' => 26, 'ships_page' => 1, 'status' => 27, 'write:dock' => 2, 'write:navigate' => 2,
            'write:orbit' => 2, 'write:purchase' => 3, 'write:refuel' => 1, 'write:sell' => 2];
        foreach ($byCategory as $category => $count) {
            $this->assertArrayHasKey($category, $ceilings, 'new request category '.$category);
            $this->assertLessThanOrEqual($ceilings[$category], $count, $category);
        }
        $this->assertLessThanOrEqual(2, $noWrite, 'advances that sent no write');

        // The metrics rows agree with what the fake saw, by category and by outcome.
        $rows = DB::table('fleet_metrics')->where('kind', 'requests')->get();
        $this->assertSame(count($calls), (int) $rows->sum('value'));
        $recorded = [];
        foreach ($rows as $row) {
            foreach (json_decode($row->data, true)['categories'] as $category => $count) {
                $recorded[$category] = ($recorded[$category] ?? 0) + $count;
            }
        }
        ksort($recorded);
        $this->assertSame($byCategory, $recorded);
        $this->assertSame(0, (int) $rows->where('ship', 'shared')->sum('value'), 'every request is attributed to a ship');
        $outcomes = DB::table('fleet_metrics')->where('kind', 'advance')->get()->map(fn ($row) => json_decode($row->data, true));
        $this->assertCount($advances, $outcomes);
        $this->assertSame($advances - $noWrite, $outcomes->where('outcome', 'wrote')->count());
        $this->assertSame([], array_diff($outcomes->pluck('outcome')->unique()->all(), ['wrote', 'waited', 'held', 'deferred', 'blocked', 'idle']));
        $passes = DB::table('fleet_metrics')->where('kind', 'director_pass')->get()->map(fn ($row) => json_decode($row->data, true));
        $this->assertSame(['started'], $passes->pluck('outcome')->all());
    }

    public function test_the_reset_read_is_attributed_to_the_arm_ship_or_to_the_director(): void
    {
        $revision = app(CampaignPlaybook::class)->import($this->armBody(100000));
        app(CampaignControl::class)->approveArm($revision, $this->user, ['label' => 'T1a', 'ship' => 'LOCAL-3'], CarbonImmutable::now()->addHours(12));
        FleetMetrics::reset();
        app(CampaignDirector::class)->tickArms();
        FleetMetrics::flush();
        $status = DB::table('fleet_metrics')->where('kind', 'requests')->get()
            ->filter(fn ($row) => isset(json_decode($row->data, true)['categories']['status']));
        $this->assertSame(['LOCAL-3'], $status->pluck('ship')->unique()->values()->all());

        // The next pass finds the run it started still active.
        $this->travel(31)->seconds();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        app(CampaignDirector::class)->tickArms();
        FleetMetrics::flush();
        $this->assertSame(['started', 'awaiting_run'], DB::table('fleet_metrics')->where('kind', 'director_pass')->orderBy('id')->get()->map(fn ($row) => json_decode($row->data, true)['outcome'])->all());

        // C0 has no arm ship: its pass starts with the reset read, labelled director.
        DB::table('fleet_metrics')->delete();
        $body = str_replace(['GIANTSOUP', 'max_credits: 60000'], ['LOCAL', 'max_credits: 100'], file_get_contents(base_path('campaigns/weekly-default.yaml')));
        app(CampaignControl::class)->approve(app(CampaignPlaybook::class)->import($body), $this->user);
        FleetMetrics::reset();
        $this->tick();
        FleetMetrics::flush();
        $status = DB::table('fleet_metrics')->where('kind', 'requests')->where('ship', 'director')->get()
            ->filter(fn ($row) => isset(json_decode($row->data, true)['categories']['status']));
        $this->assertCount(1, $status);
        $this->assertSame(['director'], DB::table('fleet_metrics')->where('kind', 'director_pass')->pluck('ship')->unique()->values()->all());
    }
}
