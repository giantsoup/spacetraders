<?php

namespace Tests\Feature;

use App\Gameplay\CampaignNotifier;
use App\Gameplay\FleetMetrics;
use App\Gameplay\GameException;
use App\Gameplay\GateWatch;
use App\Gameplay\RequestBudget;
use App\Models\AutomationRun;
use App\Models\ReconMission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GateWatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_completion_between_polls_is_read_only_and_notified_once(): void
    {
        $this->travelTo(now());
        $agent = User::factory()->create()->agents()->create(['name' => 'GIANTSOUP', 'token' => 'synthetic-only', 'faction' => 'VOID', 'reset_id' => 'test-reset']);
        $gate = ['symbol' => 'X1-TEST-GATE', 'systemSymbol' => 'X1-TEST', 'type' => 'JUMP_GATE', 'x' => 0, 'y' => 0, 'traits' => [], 'isUnderConstruction' => true];
        Http::preventStrayRequests();
        Http::fakeSequence()->push(['data' => $gate])->push(['data' => array_merge($gate, ['isUnderConstruction' => false])]);
        $this->mock(CampaignNotifier::class)->shouldReceive('alert')->once()->withArgs(fn ($key) => str_ends_with($key, ':gate_opened'));
        $snapshot = $agent->fresh()->getAttributes();
        $watch = app(GateWatch::class);
        $this->assertFalse($watch->pollGate($agent, $gate['symbol'])['completed_transition']);
        $this->assertFalse($watch->pollGate($agent, $gate['symbol'])['polled']);
        $this->travel(30)->minutes();
        $result = $watch->pollGate($agent, $gate['symbol'], true);
        $this->assertTrue($result['completed_transition']);
        $this->assertSame('gate_opened', $result['alert']);
        $this->assertSame($snapshot, $agent->fresh()->getAttributes());
        $this->assertSame(0, ReconMission::count());
        $this->assertSame(0, AutomationRun::count());
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET' || str_contains($request->url(), '/construction'));
    }

    public function test_round_one_transient_gate_read_does_not_consume_poll_interval(): void
    {
        $agent = User::factory()->create()->agents()->create(['name' => 'GIANTSOUP', 'token' => 'synthetic-only', 'faction' => 'VOID', 'reset_id' => 'test-reset']);
        $gate = ['symbol' => 'X1-TEST-GATE', 'systemSymbol' => 'X1-TEST', 'type' => 'JUMP_GATE', 'x' => 0, 'y' => 0, 'traits' => [], 'isUnderConstruction' => true];
        Http::preventStrayRequests();
        Http::fakeSequence()->pushStatus(503)->push(['data' => $gate]);
        try {
            app(GateWatch::class)->pollGate($agent, $gate['symbol']);
            $this->fail('The upstream failure must reach the caller.');
        } catch (GameException $e) {
            $this->assertStringContainsString('503', $e->getMessage());
        }
        $dir = config('gameplay.request_budget_path');
        unlink($dir.'/budget.json');
        $this->assertFalse(app(GateWatch::class)->pollGate($agent, $gate['symbol'])['polled']);
        Http::assertSentCount(1);
        $this->travel(5)->minutes();
        $this->assertTrue(app(GateWatch::class)->pollGate($agent, $gate['symbol'])['polled']);
        Http::assertSentCount(2);
    }

    public function test_round_two_shared_gate_poll_keeps_scorecard_attribution_complete(): void
    {
        $agent = User::factory()->create()->agents()->create(['name' => 'GIANTSOUP', 'token' => 'synthetic-only', 'faction' => 'VOID', 'reset_id' => 'test-reset']);
        $gate = ['symbol' => 'X1-TEST-GATE', 'systemSymbol' => 'X1-TEST', 'type' => 'JUMP_GATE', 'x' => 0, 'y' => 0, 'traits' => [], 'isUnderConstruction' => true];
        Http::preventStrayRequests();
        Http::fakeSequence()->push(['data' => $gate])->push(['data' => ['symbol' => $gate['symbol'], 'materials' => [], 'isComplete' => false]]);
        config(['gameplay.request_budget_stage' => 'stage1']);
        FleetMetrics::forAgent('GIANTSOUP', fn () => app(GateWatch::class)->pollGate($agent, $gate['symbol'], true));
        FleetMetrics::flush();
        $card = app(FleetMetrics::class)->byAgent([
            ['agent_symbol' => 'GIANTSOUP', 'qualified_label' => 'GIANTSOUP:C0', 'episodes' => []],
        ], now()->subHours(6)->toImmutable(), now()->toImmutable())['GIANTSOUP'];
        $this->assertTrue($card['attribution_complete']);
        $this->assertSame(0, $card['all_in_requests']);
        $this->assertSame(2, (int) DB::table('fleet_metrics')->where('ship', 'gate_watch')->where('scope', 'host')->sum('value'));
        $this->assertSame(2, app(RequestBudget::class)->usage(agentSymbol: 'SHARED')['agent']['tier2_4_last_hour']);
    }

    public function test_progress_read_is_optional_tier_four_and_only_runs_during_construction(): void
    {
        $agent = User::factory()->create()->agents()->create(['name' => 'GIANTSOUP', 'token' => 'synthetic-only', 'faction' => 'VOID', 'reset_id' => 'test-reset']);
        $gate = ['symbol' => 'X1-TEST-GATE', 'systemSymbol' => 'X1-TEST', 'type' => 'JUMP_GATE', 'x' => 0, 'y' => 0, 'traits' => [], 'isUnderConstruction' => true];
        Http::fakeSequence()->push(['data' => $gate])->push(['data' => ['symbol' => $gate['symbol'], 'materials' => [['tradeSymbol' => 'FAB_MATS', 'required' => 100, 'fulfilled' => 85]], 'isComplete' => false]]);
        $this->mock(CampaignNotifier::class)->shouldReceive('alert')->once();
        $result = app(GateWatch::class)->pollGate($agent, $gate['symbol'], true);
        $this->assertSame(.85, $result['progress_ratio']);
        $this->assertSame('gate_near_complete', $result['alert']);
        $this->assertSame(2, app(RequestBudget::class)->usage()['host']['priorities']['discovery']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }
}
