<?php

namespace Tests\Feature;

use App\Enums\ActionState;
use App\Gameplay\AutomationRunner;
use App\Gameplay\GameException;
use App\Gameplay\GateRecon;
use App\Gameplay\LocalEngineManager;
use App\Gameplay\ReconReport;
use App\Gameplay\RequestBudget;
use App\Gameplay\ShipCommandExecutor;
use App\Models\GameObservation;
use App\Models\LocalEngine;
use App\Models\ReconMission;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ReconTestCase;

class ReconOutboundTest extends ReconTestCase
{
    private function abroad(array $terms = []): array
    {
        $mission = $this->mission($terms);
        $run = app(GateRecon::class)->start($mission);
        app(ShipCommandExecutor::class)->execute($this->pendingJump($run)->id);
        app(ShipCommandExecutor::class)->execute($this->pendingJump($run)->id);
        app(GateRecon::class)->reconcileSettlement($mission);
        $this->assertSame('X1-DEEP', Ship::where('symbol', 'LOCAL-2')->first()->snapshot['nav']['systemSymbol']);
        $world = json_decode(file_get_contents($this->file), true);
        foreach (['NEWA', 'NEWB'] as $name) {
            $gate = "X1-$name-GATE";
            $world['waypoints'][] = ['symbol' => $gate, 'systemSymbol' => "X1-$name", 'type' => 'JUMP_GATE', 'x' => 0, 'y' => 0,
                'isUnderConstruction' => false, 'traits' => [['symbol' => 'MARKETPLACE']]];
            $world['market_goods'][$gate] = [['symbol' => 'ANTIMATTER', 'tradeVolume' => 10, 'purchasePrice' => 6000, 'sellPrice' => 5000]];
            $world['gate_connections'][$gate] = ['X1-DEEP-GATE', 'X1-TEST-GATE'];
            $world['gate_connections']['X1-DEEP-GATE'][] = $gate;
            $world['gate_connections']['X1-TEST-GATE'][] = $gate;
        }
        $world['waypoints'][] = ['symbol' => 'X1-NEWB-PORT', 'systemSymbol' => 'X1-NEWB', 'type' => 'ORBITAL_STATION', 'x' => 0, 'y' => 0,
            'traits' => [['symbol' => 'MARKETPLACE']]];
        $world['market_goods']['X1-DEEP-GATE'][0]['purchasePrice'] = 20000;
        file_put_contents($this->file, json_encode($world));

        return [$mission->fresh(), $run->fresh()];
    }

    private function grant(ReconMission $mission, ?string $target = 'X1-NEWB', int $cap = 20000, bool $dry = false, ?string $basis = null): array
    {
        return app(GateRecon::class)->authorizeOutbound($mission, $this->user, $target, $cap, $basis ?? $mission->outboundBasis(), $dry);
    }

    private function refuse(callable $work, string $message): void
    {
        try {
            $work();
            $this->fail('Expected refusal: '.$message);
        } catch (GameException|\LogicException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_dry_run_ranks_complete_catalogues_and_apply_requires_the_live_top_target(): void
    {
        [$mission, $old] = $this->abroad();
        $this->assertNull($mission->outbound_authorizations);
        $before = ShipAction::count();
        $writes = json_decode(file_get_contents($this->file), true)['writes'];
        $result = $this->grant($mission, null, dry: true);
        $this->assertSame(['X1-NEWB', 'X1-NEWA'], array_column($result['candidates'], 'system'));
        $this->assertSame('X1-NEWB', $result['selected']);
        $this->assertSame($writes, json_decode(file_get_contents($this->file), true)['writes']);
        $this->assertSame(15000, $result['record']['funding']['protected_return']);
        $this->assertSame(35000, $result['record']['funding']['required']);
        $this->assertNull($mission->fresh()->outbound_authorizations);
        $this->assertNotNull($old->fresh()->active_agent_id);
        $this->assertSame($before, ShipAction::count());
        $this->refuse(fn () => $this->grant($mission, 'X1-NEWA'), 'highest-ranked');
        $this->refuse(fn () => $this->grant($mission, null), 'exact target');
        $this->grant($mission);
        $this->assertNull($old->fresh()->active_agent_id);
        $this->assertCount(1, $mission->fresh()->outbound_authorizations);
        $this->assertSame($before, ShipAction::count());
        $this->refuse(fn () => $this->grant($mission->fresh()), 'unused grant');
    }

    public static function invalidCaps(): array
    {
        return [[0], [7500], [7499], [20001], [-1]];
    }

    #[DataProvider('invalidCaps')]
    public function test_domain_and_command_reject_invalid_caps(int $cap): void
    {
        [$mission] = $this->abroad();
        $this->refuse(fn () => $this->grant($mission, cap: $cap), 'integer cap');
        $this->refuse(fn () => $this->artisan('recon:authorize-outbound', ['mission' => $mission->id, '--jump-cap' => (string) $cap,
            '--expect-basis' => $mission->terms_hash, '--user' => $this->user->id, '--target' => 'X1-NEWB'])->run(), $cap > 20000 || $cap < 0 ? 'integer' : 'integer cap');
        $this->assertNull($mission->fresh()->outbound_authorizations);
    }

    public function test_cli_applies_replays_after_movement_and_reports_without_mutating_authority(): void
    {
        [$mission] = $this->abroad();
        $options = ['mission' => $mission->id, '--jump-cap' => '20000', '--expect-basis' => $mission->terms_hash, '--user' => $this->user->id];
        $this->artisan('recon:authorize-outbound', $options + ['--dry-run' => true])->assertSuccessful();
        $this->artisan('recon:authorize-outbound', $options + ['--target' => 'X1-NEWB'])->assertSuccessful();
        $records = $mission->fresh()->outbound_authorizations;
        $run = app(GateRecon::class)->start($mission->fresh());
        $this->assertSame($records[0]['hash'], $run->plan['outbound_basis']);
        $jump = $this->pendingJump($run);
        $this->changeWorld(['jump_price' => 20000]);
        app(ShipCommandExecutor::class)->execute($jump->id);
        $this->assertSame(ActionState::Confirmed, $jump->fresh()->state, $jump->fresh()->message ?? '');
        $replay = $this->grant($mission, basis: $mission->terms_hash);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($jump->id, $replay['intent_id']);
        $this->assertNotNull($run->fresh()->active_agent_id);
        $this->refuse(fn () => $this->grant($mission, 'X1-NEWA', basis: $mission->terms_hash), 'different approval');
        $this->assertStringContainsString('bound/consumed', app(ReconReport::class)->build($mission->fresh()));
        $this->artisan('recon:status', ['mission' => $mission->id])->assertSuccessful();
        $this->assertSame($records, $mission->fresh()->outbound_authorizations);
    }

    public function test_cash_dip_exclusivity_keeps_grant_available_then_exact_jump_adopts_bound_return_and_scouts(): void
    {
        [$mission] = $this->abroad();
        $terms = $mission->terms;
        $saved = $mission->return_reservation;
        $this->grant($mission);
        $run = app(GateRecon::class)->start($mission->fresh());
        $this->balance(130000);
        for ($i = 0; $i < 4; $i++) {
            $this->tick($run);
        }
        $this->assertSame('exploring', $mission->refresh()->state);
        $this->assertSame('outbound_wait', $mission->hold['reason']);
        $this->assertSame('available', $mission->outbound()['status']);
        $this->assertSame(2, $mission->actions()->where('command', 'jump')->count());
        $this->balance(163000);
        $jump = $this->pendingJump($run);
        $this->assertSame('X1-NEWB-GATE', $jump->arguments['waypointSymbol']);
        $this->assertSame(20000, $jump->arguments['maxTotal']);
        $this->assertSame(115000, $jump->arguments['reserve']);
        $this->assertSame(15000, $jump->arguments['recon_inflight_exposure']);
        $this->assertSame(15000, ReconMission::returnExposure($this->agent));
        $this->assertSame($saved, $mission->fresh()->return_reservation);
        $this->assertSame(['X1-NEWB', 'X1-TEST'], $jump->outcome['recon_return_reservation']['path']);
        $this->changeWorld(['jump_price' => 20000]);
        app(ShipCommandExecutor::class)->execute($jump->id);
        app(GateRecon::class)->reconcileSettlement($mission);
        $this->assertSame(ActionState::Confirmed, $jump->fresh()->state, $jump->fresh()->message ?? '');
        $this->assertSame(32000, $mission->fresh()->ledger()['spent']);
        $writes = json_decode(file_get_contents($this->file), true)['writes'];
        $this->assertSame(['waypointSymbol' => 'X1-NEWB-GATE'], end($writes)['arguments']);
        $this->assertSame(7500, $mission->exposure());
        $this->assertSame($jump->outcome['recon_return_reservation'], $mission->return_reservation);
        $this->assertSame($terms, $mission->terms);
        for ($i = 0; $i < 5; $i++) {
            $this->tick($run);
        }
        $this->assertTrue(GameObservation::where('kind', 'market')->where('symbol', 'like', 'X1-NEWB-%')->exists());
        $this->assertArrayHasKey('X1-NEWB', $mission->fresh()->visits);
    }

    public function test_one_over_quote_holds_at_cap_sends_and_later_legs_keep_original_policy(): void
    {
        [$mission] = $this->abroad();
        $this->grant($mission);
        $this->changeWorld(['market_goods' => ['X1-DEEP-GATE' => [['symbol' => 'ANTIMATTER', 'purchasePrice' => 20001, 'sellPrice' => 1, 'tradeVolume' => 10]]]]);
        $run = app(GateRecon::class)->start($mission->fresh());
        for ($i = 0; $i < 3; $i++) {
            $this->tick($run);
        }
        $this->assertSame('available', $mission->fresh()->outbound()['status']);
        $this->assertSame('exploring', $mission->state);
        $this->changeWorld(['market_goods' => ['X1-DEEP-GATE' => [['symbol' => 'ANTIMATTER', 'purchasePrice' => 20000, 'sellPrice' => 1, 'tradeVolume' => 10]]]]);
        $this->travel(901)->seconds();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        $jump = $this->pendingJump($run);
        app(ShipCommandExecutor::class)->execute($jump->id);
        $this->assertSame(ActionState::Confirmed, $jump->fresh()->state);
        app(GateRecon::class)->reconcileSettlement($mission);
        app(AutomationRunner::class)->finishStop($run->fresh(), 'Fixture return.');
        $mission->refresh()->update(['state' => 'returning', 'outcome' => 'cancelled']);
        $return = app(GateRecon::class)->start($mission->fresh());
        $home = $this->pendingJump($return);
        $this->assertSame(7500, $home->arguments['maxTotal']);
        $this->assertArrayNotHasKey('recon_outbound_hash', $home->arguments);
    }

    public function test_presend_cash_dip_is_never_sent_and_consumption_requires_fresh_approval(): void
    {
        [$mission] = $this->abroad();
        $first = $this->grant($mission)['record'];
        $run = app(GateRecon::class)->start($mission->fresh());
        $jump = $this->pendingJump($run);
        $saved = $mission->fresh()->return_reservation;
        $this->balance(130000);
        app(ShipCommandExecutor::class)->execute($jump->id);
        $this->assertTrue(GateRecon::neverSent($jump->fresh()));
        app(GateRecon::class)->reconcileSettlement($mission);
        $this->assertSame('bound/consumed', $mission->fresh()->outbound()['status']);
        $this->assertSame($saved, $mission->return_reservation);
        $this->assertSame(2, $mission->actions()->where('command', 'jump')->where('state', 'confirmed')->count());
        $this->balance(163000);
        $next = $this->grant($mission->fresh())['record'];
        $this->assertSame($first['hash'], $next['prior_basis']);
        $this->assertSame($first, $mission->fresh()->outbound_authorizations[0]);
        $new = app(GateRecon::class)->start($mission->fresh());
        $second = $this->pendingJump($new);
        $this->assertNotSame($jump->id, $second->id);
        $this->assertSame($next['hash'], $second->arguments['recon_outbound_hash']);
    }

    public static function neverReplay(): array
    {
        return [['expiry'], ['cutoff'], ['timeout'], ['overcharge'], ['end']];
    }

    #[DataProvider('neverReplay')]
    public function test_bound_intent_is_not_replayed_after_lifecycle_boundaries(string $boundary): void
    {
        [$mission] = $this->abroad();
        $this->grant($mission);
        $run = app(GateRecon::class)->start($mission->fresh());
        $jump = $this->pendingJump($run);
        $saved = $mission->fresh()->return_reservation;
        if ($boundary === 'expiry') {
            $this->travelTo($jump->expires_at->addSecond());
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        } elseif ($boundary === 'cutoff') {
            $this->travelTo($mission->terms['explore_until']);
            $jump->update(['expires_at' => now()->addMinutes(2)]);
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        } elseif ($boundary === 'timeout') {
            $this->changeWorld(['failure' => 'timeout_after']);
        } elseif ($boundary === 'overcharge') {
            $this->changeWorld(['jump_price' => 20001]);
        } else {
            app(LocalEngineManager::class)->end();
        }
        app(ShipCommandExecutor::class)->execute($jump->id);
        $calls = json_decode(file_get_contents($this->file), true)['calls'];
        app(ShipCommandExecutor::class)->execute($jump->id);
        $this->assertSame($calls, json_decode(file_get_contents($this->file), true)['calls']);
        app(GateRecon::class)->reconcileSettlement($mission);
        $this->assertNotSame('available', $mission->fresh()->outbound()['status']);
        if ($boundary === 'timeout') {
            $this->assertSame(ActionState::Uncertain, $jump->fresh()->state);
            $this->assertSame(20000, $mission->ledger()['unknown']);
            $this->assertSame($saved, $mission->return_reservation);
            $this->assertSame(15000, $mission->exposure());
        } elseif ($boundary === 'overcharge') {
            $this->assertTrue($jump->fresh()->review_required);
            $this->assertSame('review', $mission->state);
            $this->assertSame(['X1-NEWB', 'X1-TEST'], $mission->return_reservation['path']);
        } else {
            $this->assertSame(ActionState::Rejected, $jump->fresh()->state);
            $this->assertSame($saved, $mission->return_reservation);
        }
    }

    public function test_real_transition_raw_json_cas_and_model_append_guard(): void
    {
        [$mission] = $this->abroad();
        $record = $this->grant($mission, dry: true)['record'];
        $writer = new \ReflectionMethod(GateRecon::class, 'transition');
        $stale = $mission->fresh();
        DB::table('recon_missions')->where('id', $mission->id)->update(['outbound_authorizations' => '[]']);
        $this->refuse(fn () => $writer->invoke(app(GateRecon::class), $stale, ['outbound_authorizations' => [$record]]), 'changed during transition');
        $this->assertSame('[]', $mission->refresh()->getRawOriginal('outbound_authorizations'));
        $writer->invoke(app(GateRecon::class), $mission, ['outbound_authorizations' => [$record]]);
        $this->assertSame($record['hash'], $mission->outboundBasis());
        $rawStale = $mission->fresh();
        $next = $record;
        $next['prior_basis'] = $record['hash'];
        unset($next['hash']);
        $next['hash'] = ReconMission::hash($next);
        DB::table('recon_missions')->where('id', $mission->id)->update(['outbound_authorizations' => json_encode([$record], JSON_PRETTY_PRINT)]);
        $this->refuse(fn () => $writer->invoke(app(GateRecon::class), $rawStale, ['outbound_authorizations' => [$record, $next]]), 'changed during transition');
        $stale = $mission->fresh();
        $raw = $stale->getRawOriginal('outbound_authorizations');
        $mission->refresh();
        $this->refuse(fn () => $mission->update(['outbound_authorizations' => []]), 'append-only');
        $this->assertSame($raw, $mission->fresh()->getRawOriginal('outbound_authorizations'));
        $forged = $record;
        $forged['prior_basis'] = $record['hash'];
        $this->refuse(fn () => $writer->invoke(app(GateRecon::class), $stale, ['outbound_authorizations' => [$record, $forged]]), 'chain is invalid');
    }

    public static function pretransportExpiryBoundaries(): array
    {
        return [['reverse-gate-read'], ['transport-budget'], ['intent-expiry'], ['quote-expiry']];
    }

    #[DataProvider('pretransportExpiryBoundaries')]
    public function test_expiry_crossed_during_pretransport_work_cannot_send(string $boundary): void
    {
        [$mission] = $this->abroad();
        $this->grant($mission);
        $cutoff = CarbonImmutable::parse($mission->terms['explore_until']);
        if (in_array($boundary, ['reverse-gate-read', 'transport-budget'], true)) {
            $this->travelTo($cutoff->subSeconds(10));
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        }
        $run = app(GateRecon::class)->start($mission->fresh());
        $jump = $this->pendingJump($run);
        $departureQuote = GameObservation::where('kind', 'market')->where('symbol', 'X1-DEEP-GATE');
        if ($boundary === 'intent-expiry') {
            $cutoff = $jump->expires_at->toImmutable();
            $this->travelTo($cutoff->subSecond());
            $departureQuote->update(['observed_at' => now()]);
        } elseif ($boundary === 'quote-expiry') {
            $cutoff = now()->toImmutable()->addSeconds(2);
            $departureQuote->update(['observed_at' => $cutoff->subSeconds(config('gameplay.snapshot_seconds') + 1)]);
        }
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        $reserve = $mission->fresh()->return_reservation;
        $writes = json_decode(file_get_contents($this->file), true)['writes'];

        if ($boundary === 'reverse-gate-read') {
            DB::listen(function ($query) use ($cutoff) {
                if (! str_starts_with($query->sql, 'insert into "observation_history"')) {
                    return;
                }
                foreach ($query->bindings as $value) {
                    $data = is_string($value) ? json_decode($value, true) : null;
                    if (($data['operation'] ?? null) === 'get-jump-gate' && ($data['parameters']['waypointSymbol'] ?? null) === 'X1-NEWB-GATE') {
                        $this->travelTo($cutoff);
                    }
                }
            });
        } else {
            $this->partialMock(RequestBudget::class)->shouldReceive('reserve')->andReturnUsing(function ($token, $account, $priority) use ($cutoff) {
                if ($priority === 'write') {
                    $this->travelTo($cutoff);
                }
            });
        }

        app(ShipCommandExecutor::class)->execute($jump->id);
        $this->assertTrue(now()->equalTo($cutoff), 'The test must reach the expiry boundary before transport.');
        $this->assertSame($writes, json_decode(file_get_contents($this->file), true)['writes']);
        $jump->refresh();
        $this->assertTrue(GateRecon::neverSent($jump));
        $this->assertNull($jump->sent_at);
        $this->assertSame($boundary === 'reverse-gate-read' ? 'preflight' : 'pretransport', $jump->outcome['local_refusal']['stage']);
        $this->assertSame($jump->id, $mission->fresh()->outbound()['intent_id']);
        $this->assertSame($reserve, $mission->fresh()->return_reservation);
    }

    public function test_forged_duplicate_and_stale_run_cannot_send(): void
    {
        [$mission, $old] = $this->abroad();
        $this->grant($mission);
        $ship = Ship::where('symbol', 'LOCAL-2')->first()->snapshot;
        $this->refuse(fn () => app(GateRecon::class)->next($this->agent, $ship, $old), 'authority does not match');
        $run = app(GateRecon::class)->start($mission->fresh());
        $jump = $this->pendingJump($run);
        $duplicate = $jump->replicate();
        $duplicate->key = (string) Str::uuid();
        $duplicate->save();
        app(ShipCommandExecutor::class)->execute($duplicate->id);
        $this->refuse(fn () => app(GateRecon::class)->validateOutboundIntent($run->fresh(), $duplicate->fresh()), 'exact bound outbound');
        $this->assertNotSame(ActionState::Confirmed, $duplicate->fresh()->state);
        $this->assertSame(ActionState::Pending, $jump->fresh()->state);
    }

    public function test_catalogue_incomplete_and_budget_deferral_append_nothing_and_keep_run(): void
    {
        [$mission, $run] = $this->abroad(['catalogue_pages_per_system' => 1]);
        $world = json_decode(file_get_contents($this->file), true);
        for ($i = 0; $i < 25; $i++) {
            $world['waypoints'][] = ['symbol' => 'X1-NEWA-M'.$i, 'systemSymbol' => 'X1-NEWA', 'type' => 'ASTEROID', 'x' => 0, 'y' => 0, 'traits' => []];
        }
        file_put_contents($this->file, json_encode($world));
        $this->refuse(fn () => $this->grant($mission, null, dry: true), 'catalogue incomplete');
        $this->assertNull($mission->fresh()->outbound_authorizations);
        $this->assertNotNull($run->fresh()->active_agent_id);
    }

    public function test_saved_return_path_wins_over_new_equal_length_shortest_path(): void
    {
        [$mission, $run] = $this->abroad();
        $this->grant($mission, dry: true);
        app(AutomationRunner::class)->finishStop($run, 'Fixture return.');
        $mission->refresh()->update(['state' => 'returning', 'outcome' => 'cancelled']);
        $graph = $mission->graph;
        // Force BFS to prefer the new equal-length path while preserving the original.
        $graph['X1-DEEP']['connections'] = ['X1-NEWB-GATE', 'X1-NEXT-GATE', 'X1-NEWA-GATE'];
        $mission->update(['graph' => $graph]);
        $this->assertSame(['X1-DEEP', 'X1-NEWB', 'X1-TEST'], $mission->path('X1-DEEP', 'X1-TEST'));
        $return = app(GateRecon::class)->start($mission->fresh());
        $this->changeWorld(['market_goods' => ['X1-DEEP-GATE' => [['symbol' => 'ANTIMATTER', 'purchasePrice' => 6000, 'sellPrice' => 1, 'tradeVolume' => 10]]]]);
        $jump = $this->pendingJump($return);
        $this->assertSame(['X1-DEEP', 'X1-NEXT', 'X1-TEST'], $jump->arguments['recon_path']);
    }

    public static function approvalBoundaries(): array
    {
        return [['owner'], ['reset'], ['transit'], ['busy'], ['review'], ['closed'], ['cutoff'], ['returning'], ['time'], ['envelope'], ['cargo'], ['equipment'], ['unexplained']];
    }

    #[DataProvider('approvalBoundaries')]
    public function test_approval_refuses_unsafe_lifecycle_or_funding_without_stopping_previous_run(string $boundary): void
    {
        [$mission, $run] = $this->abroad($boundary === 'envelope' ? ['envelope' => 40000] : []);
        $owner = $this->user;
        if ($boundary === 'owner') {
            $owner = User::factory()->create();
        } elseif ($boundary === 'reset') {
            $this->agent->update(['reset_id' => 'other-reset']);
        } elseif ($boundary === 'transit') {
            $this->changeWorld(['ships' => [1 => ['nav' => ['status' => 'IN_TRANSIT', 'route' => ['arrival' => now()->addHour()->toIso8601String()]]]]]);
        } elseif ($boundary === 'busy') {
            ShipAction::create(['agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-2',
                'origin' => 'manual', 'command' => 'orbit', 'key' => (string) Str::uuid(), 'arguments' => [], 'state' => 'pending', 'expires_at' => now()->addMinutes(2)]);
        } elseif ($boundary === 'cargo') {
            $this->changeWorld(['ships' => [1 => ['cargo' => ['units' => 1, 'inventory' => [['symbol' => 'IRON', 'units' => 1]]]]]]);
        } elseif ($boundary === 'equipment') {
            $this->changeWorld(['ships' => [1 => ['mounts' => [['symbol' => 'MOUNT_SENSOR_ARRAY_I']]]]]);
        } elseif ($boundary === 'unexplained') {
            $this->changeWorld(['credits' => 162999]);
        } elseif ($boundary === 'review') {
            $mission->actions()->latest('id')->first()->update(['review_required' => true]);
        } elseif ($boundary === 'closed') {
            $mission->update(['state' => 'closed', 'closed_at' => now()]);
        } elseif ($boundary === 'cutoff') {
            $this->travelTo($mission->terms['explore_until']);
        } elseif ($boundary === 'returning') {
            $mission->update(['state' => 'returning']);
        } elseif ($boundary === 'time') {
            $mission->actions()->where('command', 'jump')->first()->update(['outcome->response->cooldown->totalSeconds' => 200000]);
        }
        try {
            app(GateRecon::class)->authorizeOutbound($mission, $owner, 'X1-NEWB', 20000, $mission->terms_hash);
            $this->fail('Unsafe approval must fail: '.$boundary);
        } catch (GameException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertNull($mission->fresh()->outbound_authorizations);
        $this->assertNotNull($run->fresh()->active_agent_id);
    }

    public function test_live_commitment_increase_before_send_requires_new_approval(): void
    {
        [$mission] = $this->abroad();
        $this->grant($mission);
        $run = app(GateRecon::class)->start($mission->fresh());
        $jump = $this->pendingJump($run);
        $this->assertSame(0, $jump->arguments['recon_commitments']);
        $other = $run->replicate();
        $other->ship_symbol = 'LOCAL-1';
        $other->strategy = 'scout';
        $other->plan = ['reserve' => 10000, 'max_spend' => 20000];
        $other->save();
        app(ShipCommandExecutor::class)->execute($jump->id);
        $this->assertTrue(GateRecon::neverSent($jump->fresh()));
        $this->assertStringContainsString('commitments', $jump->message ?? $jump->fresh()->message);
        $this->assertSame('bound/consumed', $mission->fresh()->outbound()['status']);
    }

    public function test_rescue_expires_unused_outbound_and_remains_return_only(): void
    {
        [$mission] = $this->abroad();
        $this->grant($mission);
        $rescue = app(GateRecon::class)->authorizeReturn($mission->fresh(), $this->user, 10000, 20000, now()->toImmutable()->addDay());
        $this->assertSame(10000, app(GateRecon::class)->status($mission->fresh())['active_jump_cap']);
        $this->assertSame($rescue['hash'], app(GateRecon::class)->status($mission->fresh())['jump_cap_source']);
        $this->assertSame('expired', $mission->fresh()->outbound()['status']);
        $this->refuse(fn () => $this->grant($mission->fresh()), 'no unused grant or rescue');
        $run = app(GateRecon::class)->start($mission->fresh());
        $this->changeWorld(['market_goods' => ['X1-DEEP-GATE' => [['symbol' => 'ANTIMATTER', 'purchasePrice' => 6000, 'sellPrice' => 1, 'tradeVolume' => 10]]]]);
        $jump = $this->pendingJump($run);
        $this->assertTrue($jump->arguments['recon_homeward']);
        $this->assertSame(10000, $jump->arguments['maxTotal']);
    }

    public function test_broken_saved_route_enters_review_and_does_not_replace_it(): void
    {
        [$mission, $run] = $this->abroad();
        $this->grant($mission, dry: true);
        app(AutomationRunner::class)->finishStop($run, 'Fixture return.');
        $mission->refresh()->update(['state' => 'returning', 'outcome' => 'cancelled']);
        $saved = $mission->return_reservation;
        $graph = $mission->graph;
        $graph['X1-NEXT']['connections'] = ['X1-TEST-GATE'];
        $mission->update(['graph' => $graph]);
        $new = app(GateRecon::class)->start($mission->fresh());
        $this->tick($new);
        $this->assertSame('review', $mission->fresh()->state);
        $this->assertSame($saved, $mission->return_reservation);
    }

    public function test_catalogue_read_deferral_is_actionable_and_retries_keep_completed_pages(): void
    {
        [$mission, $run] = $this->abroad();
        $this->changeWorld(['defer_read_path' => '/systems/X1-NEWB/waypoints', 'defer_read_page' => 1]);
        $this->refuse(fn () => $this->grant($mission, null, dry: true), 'public-read budget');
        $this->assertNull($mission->fresh()->outbound_authorizations);
        $this->assertTrue($mission->graph['X1-NEWA']['catalogue']['complete']);
        $this->assertNotNull($run->fresh()->active_agent_id);
        $this->changeWorld(['defer_read_path' => null]);
        $this->assertSame('X1-NEWB', $this->grant($mission, null, dry: true)['selected']);
    }

    public function test_legacy_column_upgrade_run_and_missing_max_total_keep_baseline(): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        $migration = require database_path('migrations/2026_11_19_000002_add_outbound_authorizations_to_recon_missions.php');
        $migration->down();
        $this->assertDatabaseHas('recon_missions', ['id' => $mission->id, 'terms_hash' => $mission->terms_hash]);
        $migration->up();
        $this->assertNull($mission->fresh()->outbound_authorizations);
        $plan = $run->plan;
        unset($plan['outbound_basis']);
        $run->update(['plan' => $plan]);
        $jump = $this->pendingJump($run);
        $this->assertSame(7500, $jump->arguments['maxTotal']);
        $arguments = $jump->arguments;
        unset($arguments['maxTotal']);
        $jump->update(['arguments' => $arguments, 'state' => 'uncertain']);
        $this->assertSame(7500, $mission->fresh()->ledger()['unknown']);
        $this->assertSame($mission->terms_hash, $mission->outboundBasis());
    }

    public function test_catalogue_tie_uses_system_symbol_and_a_changed_top_target_is_refused(): void
    {
        [$mission] = $this->abroad();
        $world = json_decode(file_get_contents($this->file), true);
        $world['waypoints'] = array_values(array_filter($world['waypoints'], fn ($point) => $point['symbol'] !== 'X1-NEWB-PORT'));
        file_put_contents($this->file, json_encode($world));
        $this->refuse(fn () => $this->grant($mission), 'highest-ranked eligible neighbor: X1-NEWA');
        $dry = $this->grant($mission, null, dry: true);
        $this->assertSame(['X1-NEWA', 'X1-NEWB'], array_column($dry['candidates'], 'system'));
    }

    public function test_subsequent_outbound_quotes_and_intents_use_baseline_cap(): void
    {
        [$mission] = $this->abroad();
        $this->grant($mission);
        $run = app(GateRecon::class)->start($mission->fresh());
        $jump = $this->pendingJump($run);
        app(ShipCommandExecutor::class)->execute($jump->id);
        app(GateRecon::class)->reconcileSettlement($mission);
        $visits = $mission->visits;
        $visits['X1-NEWB'] = ['classification' => 'below_bar'];
        $mission->update(['visits' => $visits]);
        $this->changeWorld(['market_goods' => ['X1-NEWB-GATE' => [['symbol' => 'ANTIMATTER', 'purchasePrice' => 9000, 'sellPrice' => 1, 'tradeVolume' => 10]]]]);
        for ($i = 0; $i < 8; $i++) {
            $this->tick($run);
        }
        $this->assertSame(3, $mission->actions()->where('command', 'jump')->count());
        $this->assertSame('ask_over_cap_home', $mission->refresh()->hold['reason']);
        $this->assertSame(7500, app(GateRecon::class)->status($mission)['active_jump_cap']);
        $this->changeWorld(['market_goods' => ['X1-NEWB-GATE' => [['symbol' => 'ANTIMATTER', 'purchasePrice' => 6000, 'sellPrice' => 1, 'tradeVolume' => 10]]]]);
        $this->travel(901)->seconds();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        $later = $this->pendingJump($run);
        $this->assertFalse($later->arguments['recon_homeward']);
        $this->assertSame(7500, $later->arguments['maxTotal']);
        $this->assertArrayNotHasKey('recon_outbound_hash', $later->arguments);
    }

    public static function dispatchChanges(): array
    {
        return [['invalidated'], ['stale'], ['live-link'], ['saved-route'], ['return-route']];
    }

    #[DataProvider('dispatchChanges')]
    public function test_dispatch_revalidates_quote_links_and_frozen_routes_without_replay(string $change): void
    {
        [$mission] = $this->abroad();
        $this->grant($mission);
        $run = app(GateRecon::class)->start($mission->fresh());
        $jump = $this->pendingJump($run);
        if ($change === 'invalidated') {
            GameObservation::where('kind', 'market')->where('symbol', 'X1-DEEP-GATE')->update(['data->invalidated_by_action' => 999]);
        } elseif ($change === 'stale') {
            GameObservation::where('kind', 'market')->where('symbol', 'X1-DEEP-GATE')->update(['observed_at' => now()->subHour()]);
        } elseif ($change === 'live-link') {
            $world = json_decode(file_get_contents($this->file), true);
            $world['gate_connections']['X1-NEWB-GATE'] = ['X1-TEST-GATE'];
            file_put_contents($this->file, json_encode($world));
        } else {
            $graph = $mission->fresh()->graph;
            $graph[$change === 'saved-route' ? 'X1-NEXT' : 'X1-NEWB']['connections'] = [];
            $mission->update(['graph' => $graph]);
        }
        app(ShipCommandExecutor::class)->execute($jump->id);
        $this->assertTrue(GateRecon::neverSent($jump->fresh()), $jump->message ?? $jump->fresh()->message);
        $this->assertSame('bound/consumed', $mission->fresh()->outbound()['status']);
        $this->assertSame(2, $mission->actions()->where('command', 'jump')->where('state', 'confirmed')->count());
        $before = json_decode(file_get_contents($this->file), true)['calls'];
        app(ShipCommandExecutor::class)->execute($jump->id);
        $this->assertSame($before, json_decode(file_get_contents($this->file), true)['calls']);
    }

    public function test_remote_reset_dry_run_refuses_without_archiving_or_stopping_runs(): void
    {
        [$mission, $run] = $this->abroad();
        $this->changeWorld(['reset' => 'later-reset']);
        $this->refuse(fn () => $this->grant($mission, null, dry: true), 'World reset detected');
        $this->assertNotNull($run->fresh()->active_agent_id);
        $this->assertNull($run->stop_requested_at);
        $this->assertFalse($this->agent->fresh()->isArchived());
        $this->assertNull($mission->fresh()->outbound_authorizations);
    }

    public function test_real_approval_does_not_rebase_raw_authority_during_observation_refresh(): void
    {
        [$mission, $run] = $this->abroad();
        $changed = false;
        DB::listen(function ($query) use ($mission, &$changed) {
            if (! $changed && str_contains($query->sql, 'update "recon_missions" set "graph"')) {
                $changed = true;
                DB::table('recon_missions')->where('id', $mission->id)->update(['outbound_authorizations' => '[]']);
            }
        });
        $this->refuse(fn () => $this->grant($mission), 'changed during transition');
        $this->assertTrue($changed);
        $this->assertSame('[]', $mission->fresh()->getRawOriginal('outbound_authorizations'));
        $this->assertNotNull($run->fresh()->active_agent_id);
        $this->assertSame(2, $mission->actions()->where('command', 'jump')->count());
    }

    public function test_unfunded_dry_run_still_reports_rank_and_checks_without_granting_authority(): void
    {
        [$mission, $run] = $this->abroad();
        $this->balance(130000);
        $dry = $this->grant($mission, null, dry: true);
        $this->assertSame('X1-NEWB', $dry['selected']);
        $this->assertFalse($dry['record']['funding']['cash_fits']);
        $this->assertTrue($dry['record']['funding']['envelope_fits']);
        $this->refuse(fn () => $this->grant($mission), 'cash/envelope');
        $this->assertNull($mission->fresh()->outbound_authorizations);
        $this->assertNotNull($run->fresh()->active_agent_id);
    }
}
