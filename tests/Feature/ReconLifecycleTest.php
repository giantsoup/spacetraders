<?php

namespace Tests\Feature;

use App\Gameplay\AutomationRunner;
use App\Gameplay\EngineGate;
use App\Gameplay\GameException;
use App\Gameplay\GateRecon;
use App\Gameplay\ShipCommandExecutor;
use App\Models\AutomationRun;
use App\Models\GameObservation;
use App\Models\LocalEngine;
use App\Models\ReconMission;
use App\Models\Ship;
use App\Models\ShipAction;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EngineFixture;
use Tests\Support\ReconTestCase;

class ReconLifecycleTest extends ReconTestCase
{
    private function markets(int $count = 2): void
    {
        $world = json_decode(file_get_contents($this->file), true);
        for ($i = 1; $i <= $count; $i++) {
            $symbol = 'X1-NEXT-M'.$i;
            $world['waypoints'][] = ['symbol' => $symbol, 'systemSymbol' => 'X1-NEXT', 'type' => 'PLANET', 'x' => $i * 5, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]];
            $world['market_goods'][$symbol] = [
                ['symbol' => 'IRON', 'tradeVolume' => 10, 'purchasePrice' => $i === 1 ? 100 : 1000, 'sellPrice' => $i === 1 ? 1 : 400],
                ['symbol' => 'FUEL', 'tradeVolume' => 100, 'purchasePrice' => 5, 'sellPrice' => 1],
            ];
        }
        file_put_contents($this->file, json_encode($world));
    }

    public function test_repeated_real_sampling_confirms_a_promising_system_and_returns(): void
    {
        $this->markets();
        $this->tradeWorld();
        $this->campaign();
        $this->directorTrade();
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        for ($i = 0; $i < 180 && $run->fresh()->active_agent_id; $i++) {
            $this->tick($run);
        }
        $visit = $mission->fresh()->visits['X1-NEXT'];
        $this->assertSame('found', $mission->fresh()->outcome, json_encode($visit));
        $this->assertSame('promising', $visit['classification']);
        foreach (['X1-NEXT-M1', 'X1-NEXT-M2'] as $symbol) {
            $sample = $visit['markets'][$symbol];
            $this->assertGreaterThanOrEqual(900, CarbonImmutable::parse($sample['first_at'])->diffInSeconds($sample['confirmed_at']));
        }
        $this->assertSame(['X1-NEXT-GATE', 'X1-TEST-GATE'], ShipAction::where('command', 'jump')->where('state', 'confirmed')->get()->pluck('arguments.waypointSymbol')->all());
        $this->assertLessThanOrEqual(10, $visit['reads']);
    }

    public function test_persisted_budget_cannot_loop_at_current_system_across_stop_and_restart(): void
    {
        $this->markets(8);
        $this->tradeWorld();
        $this->campaign();
        $this->directorTrade();
        config(['gameplay.route_evidence' => 'measured']);
        $mission = $this->mission(['market_reads_per_visit' => 4, 'evaluation_retry_limit' => 1]);
        $run = app(GateRecon::class)->start($mission);
        for ($i = 0; $i < 90; $i++) {
            $this->tick($run);
            if ($mission->fresh()->visits['X1-NEXT']['budget_spent'] ?? false) {
                break;
            }
        }
        $before = $mission->fresh()->visits['X1-NEXT'];
        $this->assertTrue($before['budget_spent']);
        app(AutomationRunner::class)->stop($run);
        app(GateRecon::class)->reconcileSettlement($mission);
        $new = app(GateRecon::class)->start($mission->fresh());
        $this->assertSame($before, $mission->fresh()->visits['X1-NEXT']);
        for ($i = 0; $i < 220 && $new->fresh()->active_agent_id; $i++) {
            $this->tick($new);
        }
        $this->assertSame('evidence_incomplete', $mission->fresh()->outcome, json_encode($mission->fresh()->visits));
        $this->assertArrayHasKey('X1-DEEP', $mission->fresh()->visits, 'An unvisited system must outrank the spent current-system budget.');
        $this->assertLessThanOrEqual(4, $mission->fresh()->visits['X1-NEXT']['reads']);
    }

    public function test_flag_off_abroad_keeps_loan_and_protected_exposure(): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        $jump = $this->pendingJump($run);
        app(ShipCommandExecutor::class)->execute($jump->id);
        config(['gameplay.gate_recon_enabled' => false]);
        $before = count(json_decode(file_get_contents($this->file), true)['writes']);
        $this->tick($run);
        $this->assertSame($before, count(json_decode(file_get_contents($this->file), true)['writes']));
        $this->assertTrue(ReconMission::holds($this->agent, 'LOCAL-2'));
        $this->assertSame(7500, ReconMission::returnExposure($this->agent));
        $this->assertSame('flag_off_abroad', app(GateRecon::class)->status($mission->fresh())['hold']['reason']);
    }

    public function test_origin_ask_above_cap_has_bounded_reads_and_finite_expiry(): void
    {
        $this->changeWorld(['market_goods' => ['X1-TEST-GATE' => [['symbol' => 'ANTIMATTER', 'tradeVolume' => 10, 'purchasePrice' => 8000, 'sellPrice' => 1]]]]);
        GameObservation::where('kind', 'market')->where('symbol', 'X1-TEST-GATE')->update(['observed_at' => now()->subMinutes(4)]);
        $mission = $this->mission(['explore_until' => now()->addHour()->toIso8601String()]);
        $run = app(GateRecon::class)->start($mission);
        $initialCalls = count(json_decode(file_get_contents($this->file), true)['calls']);
        for ($i = 0; $i < 75 && $run->fresh()->active_agent_id; $i++) {
            $this->tick($run);
        }
        $reads = array_filter(array_slice(json_decode(file_get_contents($this->file), true)['calls'], $initialCalls), fn ($call) => $call['path'] === '/systems/X1-TEST/waypoints/X1-TEST-GATE/market');
        $this->assertLessThanOrEqual(4, count($reads));
        $this->assertSame('time_exhausted', $mission->fresh()->outcome, json_encode(app(GateRecon::class)->status($mission->fresh())));
        $this->assertSame(0, $mission->fresh()->ledger()['spent']);
    }

    public function test_live_cash_wait_is_distinct_from_fixed_envelope_exhaustion(): void
    {
        $mission = $this->mission();
        $this->balance(105000);
        $run = app(GateRecon::class)->start($mission);
        for ($i = 0; $i < 15; $i++) {
            $this->tick($run);
            if (($mission->fresh()->hold['reason'] ?? null) === 'cash_wait') {
                break;
            }
        }
        $this->assertSame('cash_wait', $mission->fresh()->hold['reason']);
        $this->assertSame(75000, $mission->fresh()->ledger()['remaining']);
        $this->balance(175000);
        $jump = $this->pendingJump($run);
        app(ShipCommandExecutor::class)->execute($jump->id);
        $this->assertSame('confirmed', $jump->fresh()->state->value);
    }

    public function test_abroad_server_rejection_and_return_deadline_keep_the_probe_held(): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        app(ShipCommandExecutor::class)->execute($this->pendingJump($run)->id);
        app(GateRecon::class)->cancel($mission->fresh());
        $home = $this->pendingJump($run);
        $this->changeWorld(['jump_reject' => 4229]);
        app(ShipCommandExecutor::class)->execute($home->id);
        $this->assertSame('stranded_rejected', $mission->fresh()->hold['reason']);
        $this->assertNull($run->fresh()->active_agent_id);
        $this->travelTo(CarbonImmutable::parse($mission->terms['return_deadline'])->addSecond());
        app(GateRecon::class)->reconcileSettlement($mission);
        $this->assertTrue(ReconMission::holds($this->agent, 'LOCAL-2'));
        $this->assertSame(7500, ReconMission::returnExposure($this->agent));
        $this->assertNull($mission->fresh()->closed_at);
    }

    public function test_same_reset_preexisting_rejection_closes_instead_of_starting_a_new_cycle(): void
    {
        $mission = $this->mission();
        $ship = Ship::where('symbol', 'LOCAL-2')->firstOrFail()->snapshot;
        ShipAction::create(['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-2', 'origin' => 'manual', 'key' => 'prior-reject',
            'command' => 'jump', 'state' => 'rejected', 'api_code' => 4210, 'arguments' => [], 'before_state' => ['ship' => $ship], 'expires_at' => now()->addMinutes(3)]);
        try {
            app(GateRecon::class)->start($mission);
            $this->fail('A same-configuration reset rejection cannot authorize another trial.');
        } catch (GameException $e) {
            $this->assertSame('capability_rejected', $mission->fresh()->outcome);
            $this->assertSame(0, AutomationRun::count());
        }
    }

    public function test_active_probe_run_is_never_taken_over_and_approval_terms_are_immutable(): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        try {
            app(GateRecon::class)->start($mission->fresh());
            $this->fail('A running probe cannot be taken over.');
        } catch (GameException $e) {
            $this->assertNotNull($run->fresh()->active_agent_id);
            $this->assertSame(1, AutomationRun::count());
        }
        $this->expectException(\LogicException::class);
        $mission->update(['terms' => array_replace($mission->terms, ['envelope' => 1000000])]);
    }

    public function test_failed_best_confirmation_continues_to_another_qualifying_pair(): void
    {
        $this->markets(3);
        $world = json_decode(file_get_contents($this->file), true);
        $world['market_goods']['X1-NEXT-M3'][0]['sellPrice'] = 300;
        file_put_contents($this->file, json_encode($world));
        $this->tradeWorld();
        $this->campaign();
        $this->directorTrade();
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        $changed = false;
        for ($i = 0; $i < 200 && $run->fresh()->active_agent_id; $i++) {
            $this->tick($run);
            if (! $changed && isset($mission->fresh()->visits['X1-NEXT']['markets']['X1-NEXT-M1']['confirmed_at'])) {
                $world = json_decode(file_get_contents($this->file), true);
                $world['market_goods']['X1-NEXT-M2'][0]['sellPrice'] = 90;
                file_put_contents($this->file, json_encode($world));
                $changed = true;
            }
        }
        $this->assertTrue($changed);
        $this->assertSame('found', $mission->fresh()->outcome, json_encode($mission->fresh()->visits));
        $visit = $mission->fresh()->visits['X1-NEXT'];
        $this->assertArrayHasKey('confirmed_at', $visit['markets']['X1-NEXT-M3']);
        $this->assertSame('X1-NEXT-M3', $visit['evaluation']['qualifying'][0]['buyer']);
        $this->assertSame([], $visit['unsampled']);
    }

    public static function excludedGates(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('excludedGates')]
    public function test_construction_and_missing_backlinks_are_excluded_without_authorizing_a_jump(bool $construction): void
    {
        $world = json_decode(file_get_contents($this->file), true);
        foreach ($world['waypoints'] as &$point) {
            if ($point['symbol'] === 'X1-NEXT-GATE') {
                $point['isUnderConstruction'] = $construction;
            }
        }
        unset($point);
        if (! $construction) {
            $world['gate_connections']['X1-NEXT-GATE'] = [];
        }
        file_put_contents($this->file, json_encode($world));
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        for ($i = 0; $i < 20 && $run->fresh()->active_agent_id; $i++) {
            $this->tick($run);
        }
        $this->assertSame(! $construction, $mission->fresh()->graph['X1-NEXT']['complete']);
        $this->assertNull($mission->fresh()->path('X1-TEST', 'X1-NEXT'));
        if ($construction) {
            $this->assertNotNull($mission->fresh()->graph['X1-NEXT']['excluded']);
        } else {
            $this->assertSame('Missing back-link.', $mission->fresh()->graph['X1-NEXT']['exclusion_reasons']['X1-TEST']);
        }
        $this->assertSame(0, ShipAction::where('command', 'jump')->count());
    }

    public function test_stopped_and_already_stopped_runs_resume_with_same_ledger_and_absolute_deadline(): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        app(ShipCommandExecutor::class)->execute($this->pendingJump($run)->id);
        $deadline = $run->fresh()->expires_at;
        $run->update(['state' => 'stopped', 'stop_requested_at' => now()]);
        app(GateRecon::class)->reconcileSettlement($mission);
        $this->assertNull($run->fresh()->active_agent_id);
        $new = app(GateRecon::class)->start($mission->fresh());
        $this->assertSame($deadline->timestamp, $new->expires_at->timestamp);
        $this->assertSame(69000, $new->plan['max_spend']);
        $this->assertSame(6000, $mission->fresh()->ledger()['spent']);
        $this->assertSame(0, $new->totals['credit_delta']);
        $this->assertSame(-6000, $new->plan['initial_external_credit_delta']);
    }

    public function test_catalogue_cursor_survives_a_real_read_deferral_without_restarting_page_one(): void
    {
        $this->markets(25);
        $this->changeWorld(['defer_read_path' => '/systems/X1-NEXT/waypoints', 'defer_read_page' => 2]);
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        for ($i = 0; $i < 25; $i++) {
            $this->tick($run);
            if (str_contains($run->fresh()->blocked_reason ?? '', 'public-read budget')) {
                break;
            }
        }
        $this->assertSame(1, $mission->fresh()->graph['X1-NEXT']['catalogue']['page']);
        $this->assertFalse($mission->fresh()->graph['X1-NEXT']['catalogue']['complete']);
        $this->assertSame(0, ShipAction::where('command', 'jump')->count());
        $before = count(array_filter(json_decode(file_get_contents($this->file), true)['calls'], fn ($call) => $call['path'] === '/systems/X1-NEXT/waypoints'));
        $this->changeWorld(['defer_read_path' => null]);
        $this->tick($run);
        $this->assertSame(2, $mission->fresh()->graph['X1-NEXT']['catalogue']['page']);
        $this->assertTrue($mission->fresh()->graph['X1-NEXT']['catalogue']['complete']);
        $after = count(array_filter(json_decode(file_get_contents($this->file), true)['calls'], fn ($call) => $call['path'] === '/systems/X1-NEXT/waypoints'));
        $this->assertSame($before + 1, $after);
    }

    public function test_an_abroad_ask_over_cap_strands_at_deadline_without_releasing_return_funds(): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        app(ShipCommandExecutor::class)->execute($this->pendingJump($run)->id);
        app(GateRecon::class)->cancel($mission->fresh());
        $this->changeWorld(['market_goods' => ['X1-NEXT-GATE' => [['symbol' => 'ANTIMATTER', 'tradeVolume' => 10, 'purchasePrice' => 8000, 'sellPrice' => 1]]]]);
        for ($i = 0; $i < 4; $i++) {
            $this->tick($run);
        }
        $this->assertSame('ask_over_cap_abroad', $mission->fresh()->hold['reason']);
        $this->travelTo(CarbonImmutable::parse($mission->terms['return_deadline'])->addSecond());
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        app(AutomationRunner::class)->advance($run->id);
        $this->assertSame('stranded', $mission->fresh()->hold['reason']);
        $this->assertSame(7500, ReconMission::returnExposure($this->agent));
        $this->assertTrue(ReconMission::holds($this->agent, 'LOCAL-2'));
    }

    public function test_fully_sampled_unmeasured_evidence_retries_locally_then_closes_incomplete(): void
    {
        $this->markets();
        $this->tradeWorld();
        $this->campaign();
        $this->directorTrade();
        config(['gameplay.route_evidence' => 'measured']);
        $mission = $this->mission(['evaluation_retry_limit' => 1]);
        $run = app(GateRecon::class)->start($mission);
        for ($i = 0; $i < 180 && $run->fresh()->active_agent_id; $i++) {
            $this->tick($run);
        }
        $this->assertSame('evidence_incomplete', $mission->fresh()->outcome);
        $this->assertSame('evidence_incomplete', $mission->fresh()->visits['X1-NEXT']['classification']);
        $this->assertSame([], $mission->fresh()->visits['X1-NEXT']['unsampled']);
        $targets = ShipAction::where('command', 'jump')->where('state', 'confirmed')->get()->pluck('arguments.waypointSymbol')->all();
        $this->assertLessThanOrEqual(2, count(array_filter($targets, fn ($gate) => $gate === 'X1-NEXT-GATE')));
    }

    public function test_fixed_envelope_exhaustion_returns_home_without_spending_c0_profit(): void
    {
        $mission = $this->mission(['envelope' => 20000]);
        $run = app(GateRecon::class)->start($mission);
        for ($i = 0; $i < 70 && $run->fresh()->active_agent_id; $i++) {
            $this->tick($run);
        }
        $this->assertSame('budget_exhausted', $mission->fresh()->outcome);
        $this->assertSame(12000, $mission->fresh()->ledger()['spent']);
        $this->assertSame(['X1-NEXT-GATE', 'X1-TEST-GATE'], ShipAction::where('command', 'jump')->where('state', 'confirmed')->get()->pluck('arguments.waypointSymbol')->all());
    }

    public function test_partial_revisit_samples_only_the_marketplaces_still_missing(): void
    {
        $this->markets(4);
        $world = json_decode(file_get_contents($this->file), true);
        for ($i = 1; $i <= 4; $i++) {
            $world['market_goods']['X1-NEXT-M'.$i][0]['purchasePrice'] = 1000;
            $world['market_goods']['X1-NEXT-M'.$i][0]['sellPrice'] = 1;
        }
        file_put_contents($this->file, json_encode($world));
        $this->tradeWorld();
        $this->campaign();
        $this->directorTrade();
        $mission = $this->mission(['market_reads_per_visit' => 4]);
        $run = app(GateRecon::class)->start($mission);
        for ($i = 0; $i < 170 && $run->fresh()->active_agent_id; $i++) {
            $this->tick($run);
        }
        $this->assertSame('frontier_exhausted', $mission->fresh()->outcome);
        $this->assertSame([], $mission->fresh()->visits['X1-NEXT']['unsampled']);
        foreach (['M1', 'M2', 'M3', 'M4'] as $market) {
            $calls = array_filter(json_decode(file_get_contents($this->file), true)['calls'], fn ($call) => $call['path'] === '/systems/X1-NEXT/waypoints/X1-NEXT-'.$market.'/market');
            $this->assertCount(1, $calls, 'Already sampled markets must not be swept again on a partial revisit.');
        }
    }

    public function test_explicit_resume_of_temporary_cash_return_can_continue_with_the_remaining_fixed_envelope(): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        app(ShipCommandExecutor::class)->execute($this->pendingJump($run)->id);
        $this->balance(105000);
        $home = $this->pendingJump($run);
        $this->assertTrue($home->arguments['recon_homeward']);
        $this->assertSame('cash_wait', $mission->fresh()->outcome);
        app(EngineGate::class)->close();
        app(GateRecon::class)->reconcileSettlement($mission);
        EngineFixture::healthy();
        $resumed = app(GateRecon::class)->start($mission->fresh());
        $this->assertNotSame($run->id, $resumed->id);
        $this->assertSame(69000, $resumed->plan['max_spend']);
        $this->assertSame(75000, $mission->fresh()->terms['envelope']);
        $this->balance(175000);
        for ($i = 0; $i < 160 && $resumed->fresh()->active_agent_id; $i++) {
            $this->tick($resumed);
        }
        $this->assertSame('closed', $mission->fresh()->state, json_encode($mission->fresh()->hold));
        $this->assertSame('frontier_exhausted', $mission->fresh()->outcome);
        $this->assertSame(69000, $resumed->fresh()->plan['max_spend']);
        $this->assertSame(75000, $mission->fresh()->terms['envelope']);
        $continued = $mission->actions()->where('command', 'jump')->where('automation_run_id', $resumed->id)->where('state', 'confirmed')->get();
        $this->assertTrue($continued->contains(fn ($action) => ! $action->arguments['recon_homeward']));
        $this->assertSame(0, $mission->actions()->where('state', 'rejected')->where('message', 'like', '%spending or cash-loss%')->count());
    }

    public function test_cash_wait_return_crossing_exploration_deadline_closes_as_time_exhausted(): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        app(ShipCommandExecutor::class)->execute($this->pendingJump($run)->id);
        $this->balance(105000);
        $home = $this->pendingJump($run);
        $this->assertTrue($home->arguments['recon_homeward']);
        $this->assertSame('cash_wait', $mission->fresh()->outcome);
        app(ShipCommandExecutor::class)->execute($home->id);
        // Stay before the return deadline, but exploration authority has now expired.
        $this->travelTo(CarbonImmutable::parse($mission->terms['explore_until'])->addSecond());
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        for ($i = 0; $i < 15 && $run->fresh()->active_agent_id; $i++) {
            $this->tick($run);
        }
        $this->assertSame('closed', $mission->fresh()->state);
        $this->assertSame('time_exhausted', $mission->fresh()->outcome);
    }

    public function test_large_system_confirms_qualifying_pair_before_visit_read_budget_runs_out_and_skips_fuel_stations_first(): void
    {
        $world = json_decode(file_get_contents($this->file), true);
        // Place two bare FUEL_STATIONs closer to the gate than a distance-0 orbital cluster (C1/C2)
        // plus 11 other standalone markets so total markets (15) exceed market_reads_per_visit (10).
        foreach ([1 => 10, 2 => 15] as $i => $x) {
            $symbol = 'X1-NEXT-FUEL'.$i;
            $world['waypoints'][] = ['symbol' => $symbol, 'systemSymbol' => 'X1-NEXT', 'type' => 'FUEL_STATION', 'x' => $x, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]];
            $world['market_goods'][$symbol] = [['symbol' => 'FUEL', 'tradeVolume' => 100, 'purchasePrice' => 5, 'sellPrice' => 1]];
        }
        foreach (['C1' => [100, 1], 'C2' => [1000, 400]] as $name => $prices) {
            $symbol = 'X1-NEXT-'.$name;
            $world['waypoints'][] = ['symbol' => $symbol, 'systemSymbol' => 'X1-NEXT', 'type' => 'PLANET', 'x' => 40, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]];
            $world['market_goods'][$symbol] = [
                ['symbol' => 'IRON', 'tradeVolume' => 10, 'purchasePrice' => $prices[0], 'sellPrice' => $prices[1]],
                ['symbol' => 'FUEL', 'tradeVolume' => 100, 'purchasePrice' => 5, 'sellPrice' => 1],
            ];
        }
        for ($i = 1; $i <= 11; $i++) {
            $symbol = 'X1-NEXT-EXTRA'.$i;
            $world['waypoints'][] = ['symbol' => $symbol, 'systemSymbol' => 'X1-NEXT', 'type' => 'PLANET', 'x' => 60 + $i * 5, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]];
            $world['market_goods'][$symbol] = [
                ['symbol' => 'IRON', 'tradeVolume' => 10, 'purchasePrice' => 500, 'sellPrice' => 10],
                ['symbol' => 'FUEL', 'tradeVolume' => 100, 'purchasePrice' => 5, 'sellPrice' => 1],
            ];
        }
        file_put_contents($this->file, json_encode($world));
        $this->tradeWorld();
        $this->campaign();
        $this->directorTrade();
        $mission = $this->mission(['market_reads_per_visit' => 10]);
        $run = app(GateRecon::class)->start($mission);
        for ($i = 0; $i < 240 && $run->fresh()->active_agent_id; $i++) {
            $this->tick($run);
        }
        $visit = $mission->fresh()->visits['X1-NEXT'];
        $this->assertSame('found', $mission->fresh()->outcome, json_encode($visit));
        $this->assertSame('promising', $visit['classification']);
        $this->assertNotEmpty($visit['unsampled'], 'Confirmation must succeed even when unsampled markets remain in a large system.');
        $this->assertArrayHasKey('confirmed_at', $visit['markets']['X1-NEXT-C1']);
        $this->assertArrayHasKey('confirmed_at', $visit['markets']['X1-NEXT-C2']);
        $this->assertArrayNotHasKey('X1-NEXT-FUEL1', $visit['markets'], 'Orbital trade clusters must be sampled ahead of closer bare fuel stations.');
        $this->assertLessThanOrEqual(10, $visit['reads']);
    }
}
