<?php

namespace Tests\Feature;

use App\Gameplay\AutomationRunner;
use App\Gameplay\CommandRules;
use App\Gameplay\CreditAccounting;
use App\Gameplay\EngineGate;
use App\Gameplay\GameException;
use App\Gameplay\GateRecon;
use App\Gameplay\ShipCommandExecutor;
use App\Models\GameObservation;
use App\Models\ReconMission;
use App\Models\ShipAction;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EngineFixture;
use Tests\Support\FakeSpaceTraders;
use Tests\Support\ReconTestCase;

class GateReconTest extends ReconTestCase
{
    public function test_real_v5_runner_walks_beyond_one_hop_and_accounts_for_return(): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        for ($i = 0; $i < 150 && $run->fresh()->active_agent_id; $i++) {
            $this->tick($run);
        }
        $this->assertSame('closed', $mission->fresh()->state, $run->fresh()->blocked_reason ?? json_encode($mission->fresh()->toArray()));
        $this->assertSame('frontier_exhausted', $mission->fresh()->outcome);
        $this->assertSame('completed', $run->fresh()->state->value);
        $jumps = ShipAction::where('command', 'jump')->where('state', 'confirmed')->get();
        $this->assertSame(['X1-NEXT-GATE', 'X1-DEEP-GATE', 'X1-NEXT-GATE', 'X1-TEST-GATE'], $jumps->pluck('arguments.waypointSymbol')->all());
        $this->assertSame(24000, $mission->fresh()->ledger()['spent']);
        $this->assertSame(24000, $run->fresh()->totals['jump_cost']);
        $this->assertSame(-24000, $run->fresh()->totals['cash_contribution']);
        $this->assertSame(0, ReconMission::returnExposure($this->agent));
    }

    public function test_executor_local_refusal_finalizes_run_without_server_capability_evidence(): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        $jump = $this->pendingJump($run);
        GameObservation::where('kind', 'market')->update(['observed_at' => now()->subMinutes(4)]);
        app(ShipCommandExecutor::class)->execute($jump->id);
        $this->assertTrue(GateRecon::neverSent($jump->fresh()));
        $this->assertSame('restart_needed', $mission->fresh()->hold['reason']);
        $this->assertSame([], $mission->fresh()->capability);
        $this->assertNull($run->fresh()->active_agent_id);
        $this->assertSame(0, $mission->fresh()->ledger()['spent']);
        $next = app(GateRecon::class)->start($mission->fresh());
        $this->assertNotSame($run->id, $next->id);
        $this->assertSame(75000, $next->plan['max_spend']);
        $this->assertSame(0, $next->totals['credit_delta']);
    }

    public function test_real_server_capability_rejection_closes_at_home_without_another_advance(): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        $jump = $this->pendingJump($run);
        $this->changeWorld(['jump_reject' => 4210]);
        app(ShipCommandExecutor::class)->execute($jump->id);
        $this->assertFalse(GateRecon::neverSent($jump->fresh()));
        $this->assertSame('capability_rejected', $mission->fresh()->outcome);
        $this->assertSame('closed', $mission->fresh()->state);
        $this->assertNull($run->fresh()->active_agent_id);
        $this->assertFalse(ReconMission::holds($this->agent, 'LOCAL-2'));
        $this->assertSame(0, $mission->fresh()->ledger()['spent']);
    }

    public function test_uncertain_jump_keeps_funds_and_is_never_replayed(): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        $jump = $this->pendingJump($run);
        $this->changeWorld(['failure' => 'timeout_after']);
        app(ShipCommandExecutor::class)->execute($jump->id);
        $this->assertSame('uncertain', $jump->fresh()->state->value);
        $this->assertSame('review', $mission->fresh()->state);
        $this->assertSame(7500, ReconMission::returnExposure($this->agent));
        app(ShipCommandExecutor::class)->execute($jump->id);
        app(AutomationRunner::class)->advance($run->id);
        $this->assertCount(1, array_filter(json_decode(file_get_contents($this->file), true)['writes'], fn ($write) => str_ends_with($write['path'], '/jump')));
        $this->expectException(GameException::class);
        app(GateRecon::class)->start($mission->fresh());
    }

    public static function invalidations(): array
    {
        $cases = [];
        foreach (['end', 'begin', 'fault'] as $operation) {
            foreach ([false, true] as $abroad) {
                foreach ([false, true] as $deferred) {
                    $cases[$operation.'-'.($abroad ? 'abroad' : 'home').'-'.($deferred ? 'deferred' : 'pending')] = [$operation, $abroad, $deferred];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('invalidations')]
    public function test_engine_invalidations_preserve_atomic_markers_and_explicit_new_run(string $operation, bool $abroad, bool $deferred): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        $jump = $this->pendingJump($run);
        if ($abroad) {
            app(ShipCommandExecutor::class)->execute($jump->id);
            $jump = $this->pendingJump($run);
        }
        if ($deferred) {
            $this->changeWorld(['defer_jump_before_transport' => true]);
            app(ShipCommandExecutor::class)->execute($jump->id);
            $this->assertSame('pending', $jump->fresh()->state->value);
            $this->assertSame(1, $jump->fresh()->outcome['transport_reservations']);
            $this->assertSame(1, $jump->fresh()->outcome['pretransport_deferrals']);
            $this->changeWorld(['defer_jump_before_transport' => false]);
        }
        $before = $mission->fresh()->ledger()['spent'];
        $gate = app(EngineGate::class);
        match ($operation) {
            'begin' => $gate->begin(), 'fault' => $gate->close('faulted'), default => $gate->close()
        };
        $this->assertTrue(GateRecon::neverSent($jump->fresh()));
        $this->assertSame('engine_invalidated', $jump->fresh()->outcome['local_refusal']['stage']);
        $this->artisan('spacetraders:dispatch-due')->assertSuccessful();
        $this->assertSame('restart_needed', $mission->fresh()->hold['reason']);
        $this->assertNull($run->fresh()->active_agent_id);
        $this->assertSame($before, $mission->fresh()->ledger()['spent']);
        $this->assertSame($abroad ? 7500 : 0, $mission->fresh()->exposure());
        EngineFixture::healthy();
        $next = app(GateRecon::class)->start($mission->fresh());
        $this->assertNotSame($run->id, $next->id);
        $this->assertSame($run->expires_at->timestamp, $next->expires_at->timestamp);
        $this->assertSame($abroad ? 69000 : 75000, $next->plan['max_spend']);
        $this->assertSame(0, $next->totals['jump_cost']);
        app(ShipCommandExecutor::class)->execute($jump->id);
        $this->assertSame($before, $mission->fresh()->ledger()['spent']);
    }

    public function test_own_return_can_spend_below_the_exploration_floor_and_its_own_exposure(): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        $jump = $this->pendingJump($run);
        app(ShipCommandExecutor::class)->execute($jump->id);
        app(GateRecon::class)->cancel($mission->fresh());
        $run->refresh();
        $this->changeWorld(['credits' => 10000]);
        // A confirmed outside receipt explains the synthetic C0 loss without resetting any baseline.
        $outside = ShipAction::create(['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-1', 'origin' => 'manual',
            'command' => 'buy', 'key' => 'synthetic-c0-cost', 'state' => 'confirmed', 'arguments' => [], 'outcome' => ['credit_delta' => -159000], 'expires_at' => now()->addMinutes(3), 'settled_at' => now()]);
        app(CreditAccounting::class)->record($this->agent, $outside, -159000);
        $home = $this->pendingJump($run);
        $this->assertSame(0, $home->arguments['reserve']);
        $this->assertSame(7500, ReconMission::returnExposure($this->agent));
        app(ShipCommandExecutor::class)->execute($home->id);
        $this->assertSame('confirmed', $home->fresh()->state->value, $home->fresh()->message);
        $this->assertSame(4000, $this->agent->fresh()->credits);
        $this->assertSame(0, $home->fresh()->before_state['estimate']['reserve']);
        $this->assertSame(0, ReconMission::returnExposure($this->agent));
    }

    public static function rejectionEvidence(): array
    {
        return [
            'code-less server 4xx' => ['codeless_jump_rejection', false],
            'throw after sending' => ['throw_jump_after_reservation', false],
            'expired matched pretransport deferral' => ['expire_jump_deferral', true],
        ];
    }

    #[DataProvider('rejectionEvidence')]
    public function test_only_trusted_matched_local_refusals_are_never_sent(string $failure, bool $neverSent): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        $jump = $this->pendingJump($run);
        $this->changeWorld([$failure => true]);
        app(ShipCommandExecutor::class)->execute($jump->id);
        $this->assertSame('rejected', $jump->fresh()->state->value);
        $this->assertSame($neverSent, GateRecon::neverSent($jump->fresh()));
        $this->assertSame($neverSent ? 'restart_needed' : 'rejected', $mission->fresh()->hold['reason']);
        $this->assertSame(1, $jump->fresh()->outcome['transport_reservations']);
        $this->assertSame($neverSent ? 1 : 0, $jump->fresh()->outcome['pretransport_deferrals'] ?? 0);
        $this->assertSame([], $mission->fresh()->capability);
        $this->assertSame(0, $mission->fresh()->ledger()['spent']);
    }

    public function test_pending_budget_deferral_increments_neither_counter_then_local_refusal_can_resume(): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        $jump = $this->pendingJump($run);
        $this->changeWorld(['defer_probe_preflight' => true]);
        app(ShipCommandExecutor::class)->execute($jump->id);
        $this->assertSame('pending', $jump->fresh()->state->value);
        $this->assertSame(0, $jump->fresh()->outcome['transport_reservations'] ?? 0);
        $this->assertSame(0, $jump->fresh()->outcome['pretransport_deferrals'] ?? 0);
        $this->assertArrayNotHasKey('local_refusal', $jump->fresh()->outcome);
        $this->changeWorld(['defer_probe_preflight' => false]);
        GameObservation::where('kind', 'market')->update(['observed_at' => now()->subMinutes(4)]);
        app(ShipCommandExecutor::class)->execute($jump->id);
        $this->assertTrue(GateRecon::neverSent($jump->fresh()));
    }

    public function test_matched_reservation_then_pending_refusal_preserves_counters(): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        $jump = $this->pendingJump($run);
        $this->changeWorld(['defer_jump_before_transport' => true]);
        app(ShipCommandExecutor::class)->execute($jump->id);
        $this->assertSame('pending', $jump->fresh()->state->value);
        $this->changeWorld(['defer_jump_before_transport' => false]);
        GameObservation::where('kind', 'market')->update(['observed_at' => now()->subMinutes(4)]);
        app(ShipCommandExecutor::class)->execute($jump->id);
        $this->assertTrue(GateRecon::neverSent($jump->fresh()));
        $this->assertSame(1, $jump->fresh()->outcome['transport_reservations']);
        $this->assertSame(1, $jump->fresh()->outcome['pretransport_deferrals']);
    }

    public static function nonLocalEvidence(): array
    {
        return [
            'retained receipt' => ['pending', ['body_sha256' => 'synthetic-retained', 'transport_reservations' => 1, 'pretransport_deferrals' => 1], 'uncertain'],
            'sending' => ['sending', ['transport_reservations' => 1], 'sending'],
            'unmatched reservation' => ['pending', ['transport_reservations' => 1], 'rejected'],
            'legacy markerless rejection' => ['rejected', [], 'rejected'],
        ];
    }

    #[DataProvider('nonLocalEvidence')]
    public function test_engine_invalidation_never_clobbers_receipts_or_reclassifies_sending_or_legacy(string $state, array $outcome, string $expected): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        $jump = $this->pendingJump($run);
        $jump->update(['state' => $state, 'outcome' => $outcome]);
        app(EngineGate::class)->close();
        $this->assertSame($expected, $jump->fresh()->state->value);
        foreach ($outcome as $key => $value) {
            $this->assertSame($value, $jump->fresh()->outcome[$key]);
        }
        $this->assertFalse(GateRecon::neverSent($jump->fresh()));
        app(GateRecon::class)->reconcileSettlement($mission);
        if ($expected === 'sending') {
            $this->assertNotNull($run->fresh()->active_agent_id);
        } else {
            $this->assertSame('review', $mission->fresh()->state);
        }
    }

    public function test_due_sweep_repairs_a_crash_after_durable_home_rejection_on_a_blocked_run(): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        $jump = $this->pendingJump($run);
        $before = app(CommandRules::class)->inspect($this->agent, $run->ship_symbol, 'jump', $jump->arguments, $run->fresh());
        // Durable boundary left by a sender that died after rejection persistence, before its callback.
        $jump->update(['state' => 'rejected', 'before_state' => $before, 'api_code' => 4210,
            'outcome' => ['transport_reservations' => 1], 'settled_at' => now()]);
        $run->update(['state' => 'blocked']);
        $this->assertSame('exploring', $mission->fresh()->state);
        $this->artisan('spacetraders:dispatch-due')->assertSuccessful();
        $this->assertSame('capability_rejected', $mission->fresh()->outcome);
        $this->assertNull($run->fresh()->active_agent_id);
        $this->assertFalse(ReconMission::holds($this->agent, 'LOCAL-2'));
        $this->assertSame(0, $mission->fresh()->ledger()['spent']);
    }

    public function test_temporary_gate_failure_waits_until_retry_and_then_keeps_exploring(): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        $fixture = $this->file;
        $failures = 0;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($fixture, &$failures) {
            if (parse_url($request->url(), PHP_URL_PATH) === '/v2/systems/X1-NEXT/waypoints/X1-NEXT-GATE' && $failures++ === 0) {
                throw new GameException('Temporary public gate read failure.', retryAfter: 5);
            }
            $world = json_decode(file_get_contents($fixture), true);
            [$body, $status] = FakeSpaceTraders::respond($world, $request);
            file_put_contents($fixture, json_encode($world));

            return Http::response($body, $status);
        });
        $this->tick($run);
        $retry = $mission->fresh()->graph['X1-NEXT']['retry_at'];
        $this->tick($run);
        $this->assertNull($mission->fresh()->closed_at);
        $this->assertSame('evidence_wait', $mission->fresh()->hold['reason']);
        $this->assertSame($retry, $mission->fresh()->hold['next_check_at']);
        $jump = $this->pendingJump($run);
        $this->assertSame('X1-NEXT-GATE', $jump->arguments['waypointSymbol']);
        $this->assertGreaterThanOrEqual(2, $failures);
    }

    public function test_permanent_gate_read_failure_exhausts_bounded_retries_without_claiming_frontier_exhausted(): void
    {
        $mission = $this->mission(['evaluation_retry_limit' => 1]);
        $run = app(GateRecon::class)->start($mission);
        $fixture = $this->file;
        $failures = 0;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($fixture, &$failures) {
            if (parse_url($request->url(), PHP_URL_PATH) === '/v2/systems/X1-NEXT/waypoints/X1-NEXT-GATE') {
                $failures++;
                throw new GameException('Persistent public gate read failure.', retryAfter: 5);
            }
            $world = json_decode(file_get_contents($fixture), true);
            [$body, $status] = FakeSpaceTraders::respond($world, $request);
            file_put_contents($fixture, json_encode($world));

            return Http::response($body, $status);
        });
        for ($i = 0; $i < 12 && $run->fresh()->active_agent_id; $i++) {
            $this->tick($run);
        }
        $this->assertSame(2, $failures);
        $this->assertSame('evidence_incomplete', $mission->fresh()->outcome);
        $this->assertNotNull($mission->fresh()->closed_at);
        $this->assertSame(0, $mission->actions()->where('command', 'jump')->count());
    }
}
