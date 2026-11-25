<?php

namespace App\Gameplay;

use App\Enums\ActionState;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\Ship;
use App\Models\ShipAction;
use Illuminate\Support\Facades\DB;

class CreditAccounting
{
    public function baseline(Agent $agent): void
    {
        if ($agent->credits !== null && $agent->reset_id) {
            DB::table('agent_credit_baselines')->insertOrIgnore(['agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'credits' => $agent->credits, 'source' => 'first_observed_local', 'observed_at' => now()]);
        }
    }

    public function record(Agent $agent, ShipAction $action, int $delta): void
    {
        if ($delta === 0 && in_array($action->command, ['siphon', 'transfer', 'jettison', 'survey'], true)) {
            return;
        }

        DB::table('confirmed_credit_entries')->insertOrIgnore(['agent_id' => $agent->id, 'reset_id' => $action->reset_id, 'ship_action_id' => $action->id, 'automation_run_id' => $action->automation_run_id,
            'kind' => match ($action->command) {
                'sell' => 'sale_receipt', 'contract_accept', 'contract_fulfill' => 'contract_receipt', 'refuel' => 'fuel_cost', 'buy' => 'acquisition_cost', 'buy_ship', 'buy_fleet' => isset($action->arguments['site'], $action->arguments['market']) ? 'setup_cost' : 'ship_purchase', default => 'other'
            },
            'credits' => $delta, 'confirmed_at' => now()]);
    }

    public function apply(AutomationRun $run, ShipAction $action, array $ship, array $outcome, int $delta, int $price): void
    {
        DB::transaction(function () use ($run, $action, $ship, $outcome, $delta, $price) {
            $locked = AutomationRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $this->applyReceipt($locked, $action, $ship, $outcome, $delta, $price);
        });
        $run->refresh();
    }

    private function applyReceipt(AutomationRun $run, ShipAction $action, array $ship, array $outcome, int $delta, int $price): void
    {
        $totals = $run->totals;
        $v8 = ($run->plan['plan_version'] ?? $run->plan_version) === 8 || isset($run->plan['legs']);
        if ($v8 || in_array($action->command, ['siphon', 'transfer', 'jettison', 'survey', 'buy_fleet'], true)) {
            if (! empty($totals['applied_action_ids'][$action->id])) {
                return;
            }
            $totals['applied_action_ids'][$action->id] = true;
        }
        $totals['credit_delta'] = ($totals['credit_delta'] ?? 0) + $delta;
        $totals['agent_balance_delta'] = $action->before_state['credits'] + $delta - $run->plan['initial_credits'];
        $totals['unsold'] = MiningPlanner::inventory($ship);
        $totals['expected_state'] = ['waypoint' => $ship['nav']['waypointSymbol'], 'status' => $ship['nav']['status'], 'fuel' => $ship['fuel']['current']];
        $totals['receipts'] = ($totals['receipts'] ?? 0) + max(0, $delta);
        $totals['spending'] = ($totals['spending'] ?? 0) + max(0, -$delta);
        $totals['cash_contribution'] = $totals['receipts'] - $totals['spending'];
        if ($action->command === 'refuel') {
            $totals['fuel_cost'] = ($totals['fuel_cost'] ?? 0) + $price;
            $totals['fuel_units'] = ($totals['fuel_units'] ?? 0) + max(0, $ship['fuel']['current'] - $action->before_state['ship']['fuel']['current']);
        }
        if ($run->strategy === 'contracts') {
            $totals['step_index']++;
            $totals['contract'] = $outcome['contract'] ?? $totals['contract'];
            if ($action->command === 'buy') {
                $totals['acquisition_cost'] += $price;
            }
            if ($action->command === 'contract_deliver') {
                $totals['deliveries']++;
            }
        } else {
            if (in_array($action->command, ['mine', 'mine_survey', 'siphon'], true)) {
                $yield = $outcome['yield'];
                $totals['extracted'][$yield['symbol']] = ($totals['extracted'][$yield['symbol']] ?? 0) + $yield['units'];
                $totals['attempts'] = ($totals['attempts'] ?? 0) + 1;
                $totals['load_attempts'] = ($totals['load_attempts'] ?? 0) + 1;
                $totals['load_started_at'] ??= $action->sent_at->toIso8601String();
                $free = min($ship['cargo']['capacity'] ?? PHP_INT_MAX, $run->plan['unit_cap'] ?? PHP_INT_MAX) - ($ship['cargo']['units'] ?? 0);
                $limit = $run->plan['max_extractions'] ?? ($v8 ? PHP_INT_MAX : 1);
                $deliver = $totals['load_attempts'] >= $limit || ($v8 && $free < ($run->plan['extraction_headroom'] ?? 7));
                $totals['phase'] = $deliver ? ($v8 ? 'deliver' : 'sell') : ($action->command === 'siphon' ? 'siphon' : 'mine');
                $totals['no_progress'] = $yield['units'] === 0 ? ($totals['no_progress'] ?? 0) + 1 : 0;
            }
            if ($action->command === 'buy' && $run->strategy === 'trade') {
                $symbol = $action->arguments['symbol'];
                $totals['purchased'][$symbol] = ($totals['purchased'][$symbol] ?? 0) + ($outcome['units'] ?? $action->arguments['units']);
                $totals['purchase_cost'] = ($totals['purchase_cost'] ?? 0) + $price;
                if (! isset($run->plan['legs']) && $totals['purchased'][$symbol] >= $run->plan['units']) {
                    $totals['phase'] = 'sell';
                }
            }
            if ($action->command === 'sell') {
                $symbol = $action->arguments['symbol'];
                $totals['sold'][$symbol] = ($totals['sold'][$symbol] ?? 0) + ($outcome['units'] ?? $action->arguments['units']);
                $totals['sale_proceeds'] = ($totals['sale_proceeds'] ?? 0) + $price;
                if ($v8) {
                    $totals['sale_proceeds_by_good'][$symbol] = ($totals['sale_proceeds_by_good'][$symbol] ?? 0) + $price;
                }
            }
        }
        if (in_array($action->command, ['buy_ship', 'buy_fleet'], true) && ! empty($outcome['delivered_ship']) && empty($outcome['configuration_mismatch'])) {
            $totals['purchased_ships'] = array_values(array_unique([...($totals['purchased_ships'] ?? []), $outcome['delivered_ship']]));
        }
        if ($action->command === 'jump') {
            $totals['jump_cost'] = ($totals['jump_cost'] ?? 0) + $price;
            $totals['jumps'] = ($totals['jumps'] ?? 0) + 1;
        }
        if ($action->command === 'navigate') {
            $totals['travel_fuel'] = ($totals['travel_fuel'] ?? 0) + ($outcome['travel']['actual_fuel'] ?? 0);
        }
        if ($v8) {
            $this->applyRouteReceipt($run, $action, $ship, $outcome, $price, $totals);
        }
        if ($action->command === 'transfer') {
            $this->applyTransfer($run, $action, $outcome, $totals);
        }
        if ($action->command === 'jettison') {
            $this->applyJettison($action, $outcome, $totals);
        }
        if ($action->command === 'survey') {
            $totals['survey_actions'] = ($totals['survey_actions'] ?? 0) + 1;
            $totals['surveys_created'] = ($totals['surveys_created'] ?? 0) + ($outcome['surveys_created'] ?? (isset($outcome['surveys']) ? count($outcome['surveys']) : 1));
        }
        $run->update(['totals' => $totals, 'next_action_at' => now(), 'heartbeat_at' => now(), 'last_dispatched_at' => null]);
        if (($outcome['price_discrepancy'] ?? false) || ($outcome['fuel_discrepancy'] ?? false) || ! empty($outcome['configuration_mismatch']) || (isset($run->plan['max_fuel']) && ($totals['travel_fuel'] ?? 0) > $run->plan['max_fuel']) || $totals['spending'] > $run->plan['max_spend'] || ($totals['no_progress'] ?? 0) >= 1 || ! empty($totals['leg_short_sale']) || $totals['credit_delta'] < -$run->plan['max_loss']) {
            $run->update(['state' => 'blocked', 'blocked_reason' => ! empty($outcome['configuration_mismatch']) ? 'Delivered ship configuration differed from the approved offer. Review required.' : 'Execution price, spending, loss or no-progress limit reached.']);
        }
        app(CycleAccounting::class)->record($run, $ship);
    }

    private function applyRouteReceipt(AutomationRun $run, ShipAction $action, array $ship, array $outcome, int $price, array &$totals): void
    {
        $plan = $run->plan;
        $command = $action->command;
        $legIndex = $action->arguments['leg_index'] ?? $totals['leg_index'] ?? $plan['leg_index'] ?? 0;
        $hopIndex = $action->arguments['hop_index'] ?? $totals['hop_index'] ?? $plan['hop_index'] ?? 0;
        $leg = $plan['legs'][$legIndex] ?? null;
        $at = ($action->settled_at ?? now())->toIso8601String();
        if ($leg && ($totals['leg_index'] ?? 0) !== $legIndex) {
            $totals['leg_index'] = $legIndex;
            $totals['hop_index'] = 0;
            $totals['phase'] = 'buy';
        }
        if (($action->arguments['phase'] ?? null) === 'deliver') {
            $totals['phase'] = 'deliver';
        }
        if ($leg && in_array($command, ['buy', 'sell', 'navigate', 'refuel'], true)) {
            $totals['leg_started_at'][$legIndex] ??= ($action->sent_at ?? $action->settled_at ?? now())->toIso8601String();
        }
        if ($command === 'navigate') {
            $totals['hop_index'] = $hopIndex + 1;
            if ($leg) {
                $totals['leg_travel_fuel'][$legIndex] = ($totals['leg_travel_fuel'][$legIndex] ?? 0) + ($outcome['travel']['actual_fuel'] ?? 0);
            }
        }
        if ($command === 'refuel') {
            $totals['hop_fuel_cost'][$hopIndex] = ($totals['hop_fuel_cost'][$hopIndex] ?? 0) + $price;
            if ($leg) {
                $totals['leg_fuel_cost'][$legIndex] = ($totals['leg_fuel_cost'][$legIndex] ?? 0) + $price;
                $added = max(0, $ship['fuel']['current'] - $action->before_state['ship']['fuel']['current']);
                $totals['leg_fuel_units'][$legIndex] = ($totals['leg_fuel_units'][$legIndex] ?? 0) + $added;
                $totals['leg_hop_fuel_cost'][$legIndex][$hopIndex] = ($totals['leg_hop_fuel_cost'][$legIndex][$hopIndex] ?? 0) + $price;
            }
        }
        if ($leg && ($command === 'buy' || $command === 'sell')) {
            $this->applyTradeReceipt($action, $outcome, $price, $totals, $leg, $legIndex, $at);
        }
        if ($run->strategy === 'position' && $command === 'flight_mode') {
            $mode = $ship['nav']['flightMode'] ?? null;
            if ($mode !== null && $mode === ($action->arguments['flightMode'] ?? null)) {
                $totals['position_phase'] = $mode === 'DRIFT' ? 'navigate' : 'complete';
            }
        }
        if ($run->strategy === 'tender' && ($ship['nav']['waypointSymbol'] ?? null) === ($plan['site'] ?? null)
            && in_array($command, ['orbit', 'navigate'], true) && ! isset($totals['rendezvous_started_at'])) {
            $totals['rendezvous_started_at'] = $ship['nav']['status'] === 'IN_TRANSIT' ? $ship['nav']['route']['arrival'] : $at;
        }
    }

    private function applyTradeReceipt(ShipAction $action, array $outcome, int $price, array &$totals, array $leg, int $index, string $at): void
    {
        $units = $outcome['units'] ?? $action->arguments['units'];
        if ($action->command === 'buy') {
            $totals['leg_purchased'][$index] = ($totals['leg_purchased'][$index] ?? 0) + $units;
            $totals['leg_purchase_cost'][$index] = ($totals['leg_purchase_cost'][$index] ?? 0) + $price;
            if ($totals['leg_purchased'][$index] >= $leg['approvedUnits']) {
                $totals['phase'] = 'deliver';
            }

            return;
        }
        $totals['leg_sold'][$index] = ($totals['leg_sold'][$index] ?? 0) + $units;
        $totals['leg_sale_proceeds'][$index] = ($totals['leg_sale_proceeds'][$index] ?? 0) + $price;
        if (($action->before_state['ship']['nav']['waypointSymbol'] ?? null) !== $leg['buyer']) {
            $totals['leg_wrong_buyer'][$index] = true;
        }
        if ($units !== $action->arguments['units']) {
            $totals['leg_short_sale'][$index] = true;

            return;
        }
        if ($totals['leg_sold'][$index] < ($totals['leg_purchased'][$index] ?? $leg['approvedUnits'])) {
            return;
        }

        $totals['leg_finished_at'][$index] = $at;
        $totals['leg_index'] = $index + 1;
        $totals['hop_index'] = 0;
        $totals['phase'] = 'buy';
    }

    private function applyTransfer(AutomationRun $run, ShipAction $action, array $outcome, array &$totals): void
    {
        $good = $action->arguments['symbol'];
        $units = $outcome['units'] ?? $action->arguments['units'];
        $target = $action->arguments['targetShip'] ?? null;
        $agent = Agent::findOrFail($run->agent_id);
        $lease = isset($action->arguments['transfer_lease_id']) || isset($action->arguments['transferLeaseId']) || isset($run->plan['cluster_id'])
            ? DB::table('transfer_leases')->where('agent_id', $agent->id)->where('reset_id', $run->reset_id)
                ->where('ship_action_id', $action->id)->where('source_run_id', $run->id)->where('source_ship', $action->ship_symbol)
                ->where('receiver_ship', $target)->where('good', $good)->where('units', $units)->first()
            : null;
        $basis = $lease->cost_basis ?? $outcome['transferred_cost_basis']
            ?? (app(CampaignTrade::class)->averageCosts($agent, $action->ship_symbol, $action->id)[$good] ?? 0) * $units;
        $totals['transferred_out'][$good] = ($totals['transferred_out'][$good] ?? 0) + $units;
        $totals['transferred_cost_out'][$good] = ($totals['transferred_cost_out'][$good] ?? 0) + $basis;
        $action->update(['outcome' => array_replace($action->outcome ?? [], ['transferred_cost_basis' => $basis])]);
        if (! $lease) {
            return;
        }
        $receiver = AutomationRun::whereKey($lease->receiver_run_id)->where('agent_id', $agent->id)->where('reset_id', $run->reset_id)
            ->where('ship_symbol', $target)->lockForUpdate()->first();
        if (! $receiver || ! empty($receiver->totals['applied_transfer_ids'][$action->id])) {
            return;
        }

        $receipt = $receiver->totals;
        $receipt['applied_transfer_ids'][$action->id] = true;
        $receipt['transferred_in'][$good] = ($receipt['transferred_in'][$good] ?? 0) + $units;
        $receipt['transferred_cost_in'][$good] = ($receipt['transferred_cost_in'][$good] ?? 0) + $basis;
        $snapshot = Ship::where('agent_id', $agent->id)->where('symbol', $target)->first()?->snapshot;
        if ($snapshot) {
            $receipt['unsold'] = MiningPlanner::inventory($snapshot);
        }
        $receiver->update(['totals' => $receipt, 'next_action_at' => now()]);
    }

    private function applyJettison(ShipAction $action, array $outcome, array &$totals): void
    {
        $good = $action->arguments['symbol'];
        $units = $outcome['units'] ?? $action->arguments['units'];
        $expense = $outcome['jettison_opportunity_cost'] ?? $action->arguments['opportunity_cost'] ?? $action->before_state['estimate']['jettison']['opportunity_cost_credits'] ?? $action->before_state['estimate']['opportunity_cost'] ?? 0;
        $totals['jettisoned'][$good] = ($totals['jettisoned'][$good] ?? 0) + $units;
        $totals['jettison_opportunity_cost'] = ($totals['jettison_opportunity_cost'] ?? 0) + $expense;
    }

    public function outsideRunDelta(Agent $agent, AutomationRun $run): int
    {
        $modern = array_key_exists('initial_external_credit_delta', $run->plan);
        $delta = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('state', 'confirmed')
            ->where(function ($query) use ($run, $modern) {
                $query->whereNull('automation_run_id');
                if ($modern) {
                    $query->orWhere('automation_run_id', '!=', $run->id);
                }
            })->get()->sum(fn ($action) => $action->outcome['credit_delta'] ?? 0);

        // Historical in-flight plans retain their manual-only snapshot semantics.
        return (int) $delta - ($modern ? $run->plan['initial_external_credit_delta'] : ($run->plan['initial_manual_credit_delta'] ?? 0));
    }

    /** Read persisted agent and ledger state in one read-only SELECT snapshot, ignoring unsaved model changes. */
    public function summary(Agent $agent): array
    {
        // Read the balance and its ledger from one SQLite snapshot without an IMMEDIATE transaction.
        $snapshot = DB::selectOne(<<<'SQL'
            SELECT agents.credits, agents.reset_id, baseline.id AS baseline_id, baseline.credits AS baseline_credits,
                   baseline.source, baseline.user_starting_credits, baseline.user_starting_at, baseline.observed_at,
                   (SELECT COALESCE(SUM(entries.credits), 0)
                    FROM confirmed_credit_entries AS entries
                    WHERE entries.agent_id = agents.id AND entries.reset_id = agents.reset_id
                      AND (baseline.id IS NULL OR entries.confirmed_at >= baseline.observed_at)) AS confirmed_change,
                   (SELECT COALESCE(SUM(entries.credits), 0)
                    FROM confirmed_credit_entries AS entries
                    WHERE entries.agent_id = agents.id AND entries.reset_id = agents.reset_id
                      AND entries.automation_run_id IS NULL) AS manual_change,
                   (SELECT COALESCE(SUM(reviews.adjustment), 0)
                    FROM agent_balance_reviews AS reviews
                    WHERE reviews.agent_id = agents.id AND reviews.reset_id = agents.reset_id) AS reviewed_adjustments
            FROM agents
            LEFT JOIN agent_credit_baselines AS baseline
                ON baseline.agent_id = agents.id AND baseline.reset_id = agents.reset_id
            WHERE agents.id = ?
            SQL, [$agent->id]);

        $hasBaseline = isset($snapshot->baseline_id);
        $credits = isset($snapshot->credits) ? (int) $snapshot->credits : null;
        $baselineCredits = $hasBaseline ? (int) $snapshot->baseline_credits : null;
        $userStartingCredits = isset($snapshot->user_starting_credits) ? (int) $snapshot->user_starting_credits : null;
        $confirmed = (int) ($snapshot->confirmed_change ?? 0);
        $reviewed = (int) ($snapshot->reviewed_adjustments ?? 0);
        $change = $hasBaseline && $credits !== null ? $credits - ($userStartingCredits ?? $baselineCredits) : null;

        return [
            'reviewed_adjustments' => $reviewed,
            'baseline' => $hasBaseline ? [
                'credits' => $baselineCredits,
                'source' => $snapshot->source,
                'user_starting_credits' => $userStartingCredits,
                'user_starting_at' => $snapshot->user_starting_at,
                'observed_at' => $snapshot->observed_at,
            ] : null,
            'total_balance_change' => $change,
            'confirmed_change' => $confirmed,
            'manual_change' => (int) ($snapshot->manual_change ?? 0),
            'unexplained_change' => $change === null ? null : $credits - $baselineCredits - $confirmed - $reviewed,
        ];
    }

    public function review(Agent $agent, int $observedBalance, string $note, ?int $startingBalance): array
    {
        return app(ShipCommandExecutor::class)->locked($agent, function () use ($agent, $observedBalance, $note, $startingBalance) {
            app(ShipCommandExecutor::class)->clear($agent);
            if (AutomationRun::where('agent_id', $agent->id)->whereNotNull('active_agent_id')->exists()) {
                throw new GameException('Stop and settle the active run before reviewing balance attribution.');
            }
            $agent->refresh();
            app(AgentSynchronizer::class)->guardReset($agent, fresh: true);
            $balance = (int) app(SpaceTradersApi::class)->agent($agent, fresh: true)['credits'];
            DB::transaction(function () use ($agent, $balance, $observedBalance, $note, $startingBalance) {
                $settled = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                    ->whereIn('state', [ActionState::Acknowledged, ActionState::Confirmed, ActionState::Rejected]);
                DB::table('credit_reservations')->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                    ->where('state', 'uncertain')->whereIn('ship_action_id', $settled->select('id'))
                    ->update(['state' => 'settled', 'updated_at' => now()]);
                if (app(StateChain::class)->hasUnresolvedCreditAction($agent)) {
                    throw new GameException('Settle the outstanding credit reservation before reviewing balance attribution.');
                }
                $agent->update(['credits' => $balance, 'connection_state' => 'connected', 'synced_at' => now()]);
                $this->baseline($agent);
                if ($balance !== $observedBalance) {
                    return;
                }
                if ($startingBalance !== null) {
                    DB::table('agent_credit_baselines')->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->update(['user_starting_credits' => $startingBalance, 'user_starting_at' => now()]);
                }
                $this->recordBalanceReview($agent, $note);
            });
            if ($balance !== $observedBalance) {
                throw new GameException('Balance changed since review. Refresh and review again.');
            }

            return $this->summary($agent);
        });
    }

    public function recordBalanceReview(Agent $agent, string $note, bool $recordZero = false): void
    {
        $adjustment = $this->summary($agent)['unexplained_change'] ?? 0;
        if ($adjustment !== 0 || $recordZero) {
            DB::table('agent_balance_reviews')->insert(['agent_id' => $agent->id, 'reset_id' => $agent->reset_id,
                'adjustment' => $adjustment, 'observed_balance' => $agent->credits, 'note' => $note, 'reviewed_at' => now()]);
        }
    }
}
