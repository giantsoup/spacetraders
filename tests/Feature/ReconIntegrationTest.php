<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignFleet;
use App\Gameplay\CampaignSafety;
use App\Gameplay\CampaignTrade;
use App\Gameplay\CommandRules;
use App\Gameplay\CreditAccounting;
use App\Gameplay\GameException;
use App\Gameplay\GateRecon;
use App\Gameplay\OpportunityAssessor;
use App\Gameplay\ShipCommandExecutor;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\ReconMission;
use App\Models\Ship;
use App\Models\ShipAction;
use Tests\Support\ReconTestCase;

class ReconIntegrationTest extends ReconTestCase
{
    public function test_actual_director_trader_continues_during_loan_without_changing_authority(): void
    {
        $this->tradeWorld();
        $grant = $this->campaign();
        $trader = $this->directorTrade();
        $episode = CampaignEpisode::firstOrFail();
        $episode->update(['state' => array_replace_recursive($episode->state ?? [], ['role_ships' => ['probe' => 'LOCAL-2']])]);
        $trader = AutomationRun::whereNotNull('active_agent_id')->where('ship_symbol', 'LOCAL-1')->firstOrFail();
        $grantBefore = $grant->fresh()->getRawOriginal();
        $revisionBefore = $grant->revision()->getRawOriginal();
        $state = $episode->fresh()->state;
        $mission = $this->mission();
        $this->assertNull(app(CampaignFleet::class)->role($episode->fresh(), 'probe'));
        $this->assertSame($state, $episode->fresh()->state);
        $recon = app(GateRecon::class)->start($mission);
        $jump = $this->pendingJump($recon);
        $this->assertSame(107500, $jump->arguments['reserve']);
        $this->assertSame(7500, $mission->fresh()->ledger()['unknown']);
        app(ShipCommandExecutor::class)->execute($jump->id);
        $this->assertSame(107500, $jump->fresh()->before_state['estimate']['reserve']);
        $this->assertFalse($jump->fresh()->review_required);
        for ($i = 0; $i < 35 && $trader->fresh()->active_agent_id; $i++) {
            $this->tick($trader);
        }
        $this->assertSame('completed', $trader->fresh()->state->value, $trader->fresh()->blocked_reason ?? '');
        $this->assertGreaterThan(0, $trader->fresh()->totals['sale_proceeds']);
        $this->assertSame(0, app(CreditAccounting::class)->summary($this->agent->fresh())['unexplained_change']);
        $this->assertSame($grantBefore, $grant->fresh()->getRawOriginal());
        $this->assertSame($revisionBefore, $grant->revision()->getRawOriginal());
        $this->assertSame($episode->id, CampaignEpisode::firstOrFail()->id);
        $this->assertSame(7500, ReconMission::returnExposure($this->agent));
    }

    public function test_actual_overcharge_acknowledgement_stops_all_runs_then_recon_returns_only(): void
    {
        $this->tradeWorld();
        $grant = $this->campaign();
        $trader = $this->directorTrade();
        // Buy real cargo before the probe's fee is known.
        $this->tick($trader);
        $this->assertGreaterThan(0, Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot['cargo']['units']);
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        $jump = $this->pendingJump($run);
        $this->changeWorld(['jump_price' => 9000]);
        app(ShipCommandExecutor::class)->execute($jump->id);
        $this->assertTrue($jump->fresh()->review_required);
        $this->assertSame('overcharge', $mission->fresh()->hold['reason']);
        $this->assertSame(9000, $mission->fresh()->ledger()['spent']);
        $this->assertNotNull($trader->fresh()->active_agent_id);
        $this->assertNull($grant->fresh()->paused_at);
        $this->actingAs($this->user);
        $this->postJson(route('game.commands.reconcile', ['agent' => $this->agent->id, 'action' => $jump->id]))->assertAccepted();
        $cargo = Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot['cargo'];
        $this->postJson(route('game.commands.acknowledge', ['agent' => $this->agent->id, 'action' => $jump->id]), ['acknowledge' => true, 'note' => 'Reviewed actual jump fee, balance and probe position.'])->assertAccepted();
        $this->assertNull($trader->fresh()->active_agent_id);
        $this->assertNull($run->fresh()->active_agent_id);
        $this->assertSame($cargo, Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot['cargo']);
        $this->assertNull($grant->fresh()->paused_at);
        $return = app(GateRecon::class)->start($mission->fresh());
        $this->assertSame('returning', $mission->fresh()->state);
        $this->assertSame(7500, $return->plan['max_spend']);
        $this->assertSame(0, $return->totals['jump_cost']);
        app(CampaignDirector::class)->tick();
        $recovery = AutomationRun::whereNotNull('active_agent_id')->where('ship_symbol', 'LOCAL-1')->firstOrFail();
        $this->assertNotSame($trader->id, $recovery->id);
        $this->assertTrue($recovery->strategy === 'sell_aboard' || ! empty($recovery->plan['sell_only']));
    }

    public function test_effective_rescue_caps_protect_all_spending_after_stop_and_release_by_confirmed_hop(): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        app(ShipCommandExecutor::class)->execute($this->pendingJump($run)->id);
        app(ShipCommandExecutor::class)->execute($this->pendingJump($run)->id);
        app(AutomationRunner::class)->stop($run);
        app(AutomationRunner::class)->advance($run->id);
        $this->balance(120000);
        $this->tradeWorld(2500, 10000);
        $terms = $mission->fresh()->getRawOriginal('terms');
        $record = app(GateRecon::class)->authorizeReturn($mission->fresh(), $this->user, 10000, 20000, now()->toImmutable()->addDay());
        $this->assertSame([10000, 10000], $record['caps']);
        $this->assertSame($terms, $mission->fresh()->getRawOriginal('terms'));
        $this->assertSame(20000, ReconMission::returnExposure($this->agent));
        $this->assertSame(20000, app(GateRecon::class)->status($mission->fresh())['return_exposure']);
        $ship = Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
        $before = app(CommandRules::class)->inspect($this->agent->fresh(), 'LOCAL-1', 'buy', ['symbol' => 'IRON', 'units' => 40, 'maxTotal' => 100000, 'reserve' => 0]);
        $this->assertSame(20000, $before['estimate']['reserve']);
        $this->tradeWorld(2600, 10000);
        try {
            app(CommandRules::class)->inspect($this->agent->fresh(), 'LOCAL-1', 'buy', ['symbol' => 'IRON', 'units' => 40, 'maxTotal' => 104000, 'reserve' => 0]);
            $this->fail('Manual spending must preserve 20,000 in effective rescue caps.');
        } catch (GameException $e) {
            $this->assertStringContainsString('reserve', $e->getMessage());
        }
        $grant = $this->campaign();
        app(CampaignDirector::class)->tick();
        $episode = CampaignEpisode::firstOrFail();
        $considered = [];
        $ranked = app(CampaignTrade::class)->rank($grant, $episode, $this->agent->fresh(), $ship, $considered);
        $this->assertLessThanOrEqual(99980, $ranked[0]['max_inventory']);
        $this->assertLessThanOrEqual(38, $ranked[0]['units']);
        $c0 = AutomationRun::where('ship_symbol', 'LOCAL-1')->whereNotNull('active_agent_id')->firstOrFail();
        $this->assertNotNull($c0->campaign_episode_id);
        try {
            app(CampaignSafety::class)->check($c0, $this->agent->fresh(), 'buy', ['maxTotal' => 104000]);
            $this->fail('C0 must preserve the effective rescue caps.');
        } catch (GameException $e) {
            $this->assertStringContainsString('reserve', $e->getMessage());
        }
        // In the exact no-fuel-commitment case the 100,000 spend is permitted.
        $traderRow = Ship::where('symbol', 'LOCAL-1')->firstOrFail();
        $originalShip = $traderRow->snapshot;
        $fuelFree = $originalShip;
        $fuelFree['frame']['symbol'] = 'FRAME_PROBE';
        $fuelFree['fuel'] = ['current' => 0, 'capacity' => 0];
        $traderRow->update(['snapshot' => $fuelFree]);
        app(CampaignSafety::class)->check($c0, $this->agent->fresh(), 'buy', ['maxTotal' => 100000]);
        $traderRow->update(['snapshot' => $originalShip]);
        $this->assertSame(20000, app(GateRecon::class)->status($mission->fresh())['return_exposure']);
        $this->changeWorld(['repair_price' => 104000]);
        $plain = new AutomationRun(['strategy' => 'scout', 'plan' => ['reserve' => 0, 'max_spend' => 120000, 'max_loss' => 120000],
            'totals' => ['fuel_cost' => 0, 'credit_delta' => 0]]);
        try {
            app(CommandRules::class)->validate($this->agent->fresh(), 'LOCAL-1', 'repair', ['maxTotal' => 104000, 'reserve' => 0], ['ship' => $originalShip, 'active' => null], $plain);
            $this->fail('Plain spending runs must protect return caps too.');
        } catch (GameException $e) {
            $this->assertStringContainsString('reserve', $e->getMessage());
        }
        app(AutomationRunner::class)->finishStop($c0, 'Release unused fixture commitment.');
        $return = app(GateRecon::class)->start($mission->fresh());
        $first = $this->pendingJump($return);
        $this->assertSame(10000, $first->arguments['reserve']);
        $this->changeWorld(['jump_price' => 9000]);
        app(ShipCommandExecutor::class)->execute($first->id);
        $this->assertSame('confirmed', $first->fresh()->state->value, $first->fresh()->message);
        $this->assertSame(10000, ReconMission::returnExposure($this->agent));
        $last = $this->pendingJump($return);
        $this->changeWorld(['failure' => 'timeout_after']);
        app(ShipCommandExecutor::class)->execute($last->id);
        $this->assertSame('uncertain', $last->fresh()->state->value);
        $this->assertSame(10000, ReconMission::returnExposure($this->agent));
    }

    public function test_cli_dry_run_approval_status_cancel_and_report_are_real_and_default_flag_is_off(): void
    {
        $options = ['--user' => $this->user->id, '--agent' => 'LOCAL', '--ship' => 'LOCAL-2'];
        $this->artisan('recon:approve', $options + ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, ReconMission::count());
        $this->artisan('recon:approve', $options)->assertSuccessful();
        $mission = ReconMission::firstOrFail();
        $this->artisan('recon:status')->assertSuccessful();
        $this->artisan('recon:report', ['mission' => $mission->id])->assertSuccessful();
        $report = storage_path('app/recon/mission-'.$mission->id.'.md');
        $this->assertFileExists($report);
        $this->assertStringContainsString('C0 relocation not authorized', file_get_contents($report));
        $this->artisan('recon:cancel', ['mission' => $mission->id])->assertSuccessful();
        $this->assertSame('cancelled', $mission->fresh()->outcome);
        config(['gameplay.gate_recon_enabled' => false]);
        $this->expectException(GameException::class);
        $this->mission();
    }

    public function test_return_overcharge_never_reduces_protected_caps_to_remaining_total_authority(): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        app(ShipCommandExecutor::class)->execute($this->pendingJump($run)->id);
        app(ShipCommandExecutor::class)->execute($this->pendingJump($run)->id);
        app(AutomationRunner::class)->stop($run);
        app(GateRecon::class)->reconcileSettlement($mission);
        $this->balance(120000);
        $terms = $mission->fresh()->getRawOriginal('terms');
        app(GateRecon::class)->authorizeReturn($mission->fresh(), $this->user, 10000, 20000, now()->toImmutable()->addDay());
        $this->assertSame($terms, $mission->fresh()->getRawOriginal('terms'));
        $this->assertSame(20000, ReconMission::returnExposure($this->agent));
        $return = app(GateRecon::class)->start($mission->fresh());
        $jump = $this->pendingJump($return);
        $this->changeWorld(['jump_price' => 15000]);
        app(ShipCommandExecutor::class)->execute($jump->id);
        $this->assertTrue($jump->fresh()->review_required);
        $this->assertSame(5000, $mission->fresh()->authorization()['remaining_total']);
        $this->assertSame(10000, ReconMission::returnExposure($this->agent));
        app(AutomationRunner::class)->finishStop($return, 'Settled overcharge fixture.');
        $this->assertSame(10000, ReconMission::returnExposure($this->agent));
        $this->assertSame(10000, app(GateRecon::class)->status($mission->fresh())['return_exposure']);
        $this->assertTrue(ReconMission::holds($this->agent, 'LOCAL-2'));
    }

    public function test_foreign_market_observations_do_not_change_c0_rank_or_same_system_scouts(): void
    {
        $this->tradeWorld();
        $grant = $this->campaign();
        $this->directorTrade();
        $episode = CampaignEpisode::firstOrFail();
        $ship = Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
        $beforeConsidered = [];
        $before = app(CampaignTrade::class)->rank($grant, $episode, $this->agent->fresh(), $ship, $beforeConsidered);
        $market = ['symbol' => 'X1-NEXT-FOREIGN', 'imports' => [], 'exports' => [], 'exchange' => [['symbol' => 'IRON'], ['symbol' => 'FUEL']],
            'tradeGoods' => [['symbol' => 'IRON', 'tradeVolume' => 100, 'purchasePrice' => 1, 'sellPrice' => 50000], ['symbol' => 'FUEL', 'tradeVolume' => 100, 'purchasePrice' => 1, 'sellPrice' => 5000]]];
        app(AgentSynchronizer::class)->observe($this->agent, 'waypoint', $market['symbol'], ['symbol' => $market['symbol'], 'systemSymbol' => 'X1-NEXT', 'type' => 'PLANET', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]]);
        app(AgentSynchronizer::class)->observe($this->agent, 'market_listing', $market['symbol'], $market);
        app(AgentSynchronizer::class)->observe($this->agent, 'market', $market['symbol'], $market);
        $afterConsidered = [];
        $after = app(CampaignTrade::class)->rank($grant, $episode->fresh(), $this->agent->fresh(), $ship, $afterConsidered);
        $this->assertSame($before, $after);
        $report = app(OpportunityAssessor::class)->report($this->agent->fresh(), shipSymbol: 'LOCAL-1');
        $foreign = collect($report['observation_tasks'])->where('destination', $market['symbol']);
        $this->assertTrue($foreign->every(fn ($task) => ! $task['ready_for_bounded_preview']));
    }

    public function test_manual_spending_protects_only_return_exposure_not_the_unused_recon_envelope(): void
    {
        $this->tradeWorld(2600, 10000);
        $mission = $this->mission();
        app(GateRecon::class)->start($mission);
        $before = app(CommandRules::class)->inspect($this->agent->fresh(), 'LOCAL-1', 'buy', ['symbol' => 'IRON', 'units' => 40, 'maxTotal' => 104000, 'reserve' => 0]);
        $this->assertLessThan(75000, $before['estimate']['reserve']);
        $this->assertSame(104000, $before['estimate']['cost_estimate']);
        $this->assertSame(0, ReconMission::returnExposure($this->agent));
    }

    public function test_departure_waits_while_c0_holds_cargo_and_leaves_after_its_real_sale(): void
    {
        $this->balance(165000);
        $this->tradeWorld(2600, 4000);
        $this->campaign();
        $trader = $this->directorTrade();
        $mission = $this->mission();
        $this->tick($trader);
        $this->assertGreaterThan(0, Ship::where('symbol', 'LOCAL-1')->firstOrFail()->snapshot['cargo']['units']);
        $run = app(GateRecon::class)->start($mission);
        for ($i = 0; $i < 15; $i++) {
            $this->tick($run);
            if (($mission->fresh()->hold['reason'] ?? null) === 'cash_wait') {
                break;
            }
        }
        $this->assertSame('cash_wait', $mission->fresh()->hold['reason']);
        $this->assertSame(65000, $mission->fresh()->terms['envelope']);
        for ($i = 0; $i < 35 && $trader->fresh()->active_agent_id; $i++) {
            $this->tick($trader);
        }
        $this->assertSame('completed', $trader->fresh()->state->value, $trader->fresh()->blocked_reason ?? '');
        $this->assertTrue(ShipAction::where('automation_run_id', $trader->id)->where('command', 'sell')->where('state', 'confirmed')->exists());
        $jump = $this->pendingJump($run);
        app(ShipCommandExecutor::class)->execute($jump->id);
        $this->assertSame('confirmed', $jump->fresh()->state->value, $jump->fresh()->message);
        $this->assertSame(65000, $mission->fresh()->terms['envelope'], 'C0 profits do not increase the exploration envelope.');
    }

    public function test_explicit_rescue_dry_run_and_cash_floor_then_fresh_run_limits(): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        app(ShipCommandExecutor::class)->execute($this->pendingJump($run)->id);
        app(ShipCommandExecutor::class)->execute($this->pendingJump($run)->id);
        $terms = $mission->fresh()->getRawOriginal('terms');
        $this->balance(119999);
        try {
            app(GateRecon::class)->authorizeReturn($mission->fresh(), $this->user, 10000, 20000, now()->toImmutable()->addDay());
            $this->fail('Rescue approval cannot lower the exploration cash floor.');
        } catch (GameException $e) {
            $this->assertSame([], $mission->fresh()->homeward_authorizations);
        }
        $this->balance(120000);
        $options = ['mission' => $mission->id, '--user' => $this->user->id, '--jump-cap' => 10000, '--total-exposure' => 20000, '--until' => now()->addDay()->toIso8601String()];
        $this->artisan('recon:authorize-return', $options + ['--dry-run' => true])->assertSuccessful();
        $this->assertSame([], $mission->fresh()->homeward_authorizations);
        $this->assertSame(15000, ReconMission::returnExposure($this->agent));
        $this->artisan('recon:authorize-return', $options)->assertSuccessful();
        $this->assertSame($terms, $mission->fresh()->getRawOriginal('terms'));
        $this->assertNull($run->fresh()->active_agent_id);
        $this->assertSame(20000, ReconMission::returnExposure($this->agent));
        $new = app(GateRecon::class)->start($mission->fresh());
        $this->assertSame(20000, $new->plan['max_spend']);
        $this->assertSame(20000, $new->plan['max_loss']);
        $this->assertSame('returning', $mission->fresh()->state);
    }

    public function test_explicit_rescue_can_authorize_a_longer_fresh_home_path_on_the_same_mission(): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        app(ShipCommandExecutor::class)->execute($this->pendingJump($run)->id);
        app(AutomationRunner::class)->stop($run);
        app(GateRecon::class)->reconcileSettlement($mission);
        $this->assertSame(7500, ReconMission::returnExposure($this->agent));
        $world = json_decode(file_get_contents($this->file), true);
        $world['gate_connections']['X1-TEST-GATE'] = ['X1-ALT-GATE'];
        $world['gate_connections']['X1-NEXT-GATE'] = ['X1-ALT-GATE'];
        $world['gate_connections']['X1-ALT-GATE'] = ['X1-TEST-GATE', 'X1-NEXT-GATE'];
        $world['waypoints'][] = ['symbol' => 'X1-ALT-GATE', 'systemSymbol' => 'X1-ALT', 'type' => 'JUMP_GATE', 'x' => 0, 'y' => 0,
            'traits' => [['symbol' => 'MARKETPLACE']], 'isUnderConstruction' => false];
        $world['market_goods']['X1-ALT-GATE'] = [['symbol' => 'ANTIMATTER', 'purchasePrice' => 9000, 'sellPrice' => 1, 'tradeVolume' => 10]];
        file_put_contents($this->file, json_encode($world));
        $this->balance(120000);
        $terms = $mission->fresh()->getRawOriginal('terms');
        $record = app(GateRecon::class)->authorizeReturn($mission->fresh(), $this->user, 10000, 20000, now()->toImmutable()->addDay());
        $this->assertSame(['X1-NEXT', 'X1-ALT', 'X1-TEST'], $record['path']);
        $this->assertSame($terms, $mission->fresh()->getRawOriginal('terms'));
        $this->assertSame(20000, ReconMission::returnExposure($this->agent));
        $return = app(GateRecon::class)->start($mission->fresh());
        for ($i = 0; $i < 45 && $return->fresh()->active_agent_id; $i++) {
            $this->tick($return);
        }
        $this->assertSame('closed', $mission->fresh()->state, $return->fresh()->blocked_reason ?? '');
        $this->assertSame(0, ReconMission::returnExposure($this->agent));
        $this->assertSame(20000, $return->fresh()->plan['max_spend']);
    }

    public function test_acknowledged_uncertain_jump_can_only_resume_home_under_later_return_approval(): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        $lost = $this->pendingJump($run);
        $this->changeWorld(['failure' => 'timeout_after']);
        app(ShipCommandExecutor::class)->execute($lost->id);
        $this->assertSame('uncertain', $lost->fresh()->state->value);
        $this->changeWorld(['failure' => null]);
        $this->actingAs($this->user);
        $this->postJson(route('game.commands.reconcile', ['agent' => $this->agent->id, 'action' => $lost->id]))->assertAccepted();
        $this->postJson(route('game.commands.acknowledge', ['agent' => $this->agent->id, 'action' => $lost->id]),
            ['acknowledge' => true, 'note' => 'Reviewed probe position and unresolved outbound fee; never replay.'])->assertAccepted();
        $this->assertSame('acknowledged', $lost->fresh()->state->value);
        $this->assertArrayNotHasKey('transaction_total', $lost->fresh()->outcome);
        app(CreditAccounting::class)->review($this->agent, $this->agent->fresh()->credits, 'Reviewed unknown outbound fee against fresh balance.', null);
        try {
            app(GateRecon::class)->start($mission->fresh());
            $this->fail('Acknowledgement alone must not authorize a new jump.');
        } catch (GameException $e) {
            $this->assertStringContainsString('operator review', $e->getMessage());
        }
        $this->assertSame(7500, $mission->fresh()->ledger()['unknown']);
        // A rescue must not inherit the temporary-cash return path that resumes exploration.
        $mission->update(['outcome' => 'cash_wait']);
        app(GateRecon::class)->authorizeReturn($mission->fresh(), $this->user, 7500, 7500, now()->toImmutable()->addDay());
        $return = app(GateRecon::class)->start($mission->fresh());
        $this->assertSame('returning', $mission->fresh()->state);
        $this->assertSame(7500, ReconMission::returnExposure($this->agent));
        for ($i = 0; $i < 30 && $return->fresh()->active_agent_id; $i++) {
            $this->tick($return);
        }
        $this->assertSame('closed', $mission->fresh()->state);
        $this->assertSame('X1-TEST', Ship::where('symbol', 'LOCAL-2')->firstOrFail()->snapshot['nav']['systemSymbol']);
        $newJumps = $mission->actions()->where('command', 'jump')->where('id', '>', $lost->id)->get();
        $this->assertCount(1, $newJumps);
        $this->assertTrue($newJumps[0]->arguments['recon_homeward']);
        $this->assertSame('X1-TEST-GATE', $newJumps[0]->arguments['waypointSymbol']);
        $this->assertSame('acknowledged', $lost->fresh()->state->value);
        $this->assertSame(7500, $mission->fresh()->ledger()['unknown']);
        $this->assertSame(0, ReconMission::returnExposure($this->agent));
    }

    public function test_outbound_overcharge_return_closes_without_claiming_the_frontier_was_exhausted(): void
    {
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        $overcharge = $this->pendingJump($run);
        $this->changeWorld(['jump_price' => 9000]);
        app(ShipCommandExecutor::class)->execute($overcharge->id);
        $this->assertTrue($overcharge->fresh()->review_required);
        $this->actingAs($this->user);
        $this->postJson(route('game.commands.reconcile', ['agent' => $this->agent->id, 'action' => $overcharge->id]))->assertAccepted();
        $this->postJson(route('game.commands.acknowledge', ['agent' => $this->agent->id, 'action' => $overcharge->id]),
            ['acknowledge' => true, 'note' => 'Reviewed outbound overcharge; return only.'])->assertAccepted();
        $this->changeWorld(['jump_price' => 6000]);
        $return = app(GateRecon::class)->start($mission->fresh());
        $this->assertSame(7500, $return->plan['max_spend']);
        for ($i = 0; $i < 40 && $return->fresh()->active_agent_id; $i++) {
            $this->tick($return);
        }
        $this->assertSame('closed', $mission->fresh()->state);
        $this->assertSame('cancelled', $mission->fresh()->outcome);
        $this->assertSame(0, $mission->actions()->where('command', 'jump')->where('id', '>', $overcharge->id)->get()
            ->filter(fn ($action) => ! $action->arguments['recon_homeward'])->count());
    }

    public function test_overcharge_during_real_cash_wait_return_never_restarts_exploration(): void
    {
        $world = json_decode(file_get_contents($this->file), true);
        foreach (['FAR', 'EDGE', 'ZERO'] as $system) {
            $gate = 'X1-'.$system.'-GATE';
            $world['waypoints'][] = ['symbol' => $gate, 'systemSymbol' => 'X1-'.$system, 'type' => 'JUMP_GATE', 'x' => 0, 'y' => 0,
                'isUnderConstruction' => false, 'traits' => [['symbol' => 'MARKETPLACE']]];
            $world['market_goods'][$gate] = [['symbol' => 'ANTIMATTER', 'tradeVolume' => 10, 'purchasePrice' => 6000, 'sellPrice' => 5000]];
        }
        $world['gate_connections']['X1-DEEP-GATE'][] = 'X1-FAR-GATE';
        $world['gate_connections']['X1-FAR-GATE'] = ['X1-DEEP-GATE', 'X1-EDGE-GATE'];
        $world['gate_connections']['X1-EDGE-GATE'] = ['X1-FAR-GATE'];
        $world['gate_connections']['X1-TEST-GATE'][] = 'X1-ZERO-GATE';
        $world['gate_connections']['X1-ZERO-GATE'] = ['X1-TEST-GATE'];
        file_put_contents($this->file, json_encode($world));
        $mission = $this->mission();
        $run = app(GateRecon::class)->start($mission);
        foreach (['X1-NEXT-GATE', 'X1-DEEP-GATE', 'X1-FAR-GATE'] as $destination) {
            $jump = $this->pendingJump($run);
            $this->assertSame($destination, $jump->arguments['waypointSymbol']);
            app(ShipCommandExecutor::class)->execute($jump->id);
        }
        $this->balance(105000);
        $cheapReturn = $this->pendingJump($run);
        $this->assertTrue($cheapReturn->arguments['recon_homeward']);
        $this->assertSame('cash_wait', $mission->fresh()->outcome);
        app(ShipCommandExecutor::class)->execute($cheapReturn->id);
        $overcharge = $this->pendingJump($run);
        $this->assertTrue($overcharge->arguments['recon_homeward']);
        $this->changeWorld(['jump_price' => 9000]);
        app(ShipCommandExecutor::class)->execute($overcharge->id);
        $this->assertTrue($overcharge->fresh()->review_required);
        $this->actingAs($this->user);
        $this->postJson(route('game.commands.reconcile', ['agent' => $this->agent->id, 'action' => $overcharge->id]))->assertAccepted();
        $this->postJson(route('game.commands.acknowledge', ['agent' => $this->agent->id, 'action' => $overcharge->id]),
            ['acknowledge' => true, 'note' => 'Reviewed intermediate return overcharge; stop exploration.'])->assertAccepted();
        $this->changeWorld(['jump_price' => 6000]);
        $this->balance(175000);
        $return = app(GateRecon::class)->start($mission->fresh());
        $this->assertSame(7500, $return->plan['max_spend']);
        for ($i = 0; $i < 40 && $return->fresh()->active_agent_id; $i++) {
            $this->tick($return);
        }
        $this->assertSame('closed', $mission->fresh()->state, json_encode($mission->fresh()->hold));
        $this->assertSame('cancelled', $mission->fresh()->outcome);
        $after = $mission->actions()->where('command', 'jump')->where('id', '>', $overcharge->id)->get();
        $this->assertCount(1, $after);
        $this->assertTrue($after[0]->arguments['recon_homeward']);
        $this->assertSame('X1-TEST-GATE', $after[0]->arguments['waypointSymbol']);
        $this->assertArrayNotHasKey('X1-EDGE', $mission->fresh()->visits);
    }
}
