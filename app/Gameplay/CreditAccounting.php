<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
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
        DB::table('confirmed_credit_entries')->insertOrIgnore(['agent_id' => $agent->id, 'reset_id' => $action->reset_id, 'ship_action_id' => $action->id, 'automation_run_id' => $action->automation_run_id,
            'kind' => match ($action->command) {
                'sell' => 'sale_receipt', 'contract_accept', 'contract_fulfill' => 'contract_receipt', 'refuel' => 'fuel_cost', 'buy' => 'acquisition_cost', 'buy_ship' => isset($action->arguments['site'], $action->arguments['market']) ? 'setup_cost' : 'ship_purchase', default => 'other'
            },
            'credits' => $delta, 'confirmed_at' => now()]);
    }

    public function apply(AutomationRun $run, ShipAction $action, array $ship, array $outcome, int $delta, int $price): void
    {
        $totals = $run->fresh()->totals;
        $totals['credit_delta'] += $delta;
        $totals['agent_balance_delta'] = $action->before_state['credits'] + $delta - $run->plan['initial_credits'];
        $totals['unsold'] = MiningPlanner::inventory($ship);
        $totals['expected_state'] = ['waypoint' => $ship['nav']['waypointSymbol'], 'status' => $ship['nav']['status'], 'fuel' => $ship['fuel']['current']];
        $totals['receipts'] = ($totals['receipts'] ?? 0) + max(0, $delta);
        $totals['spending'] = ($totals['spending'] ?? 0) + max(0, -$delta);
        $totals['cash_contribution'] = $totals['receipts'] - $totals['spending'];
        if ($action->command === 'refuel') {
            $totals['fuel_cost'] += $price;
            $totals['fuel_units'] += max(0, $ship['fuel']['current'] - $action->before_state['ship']['fuel']['current']);
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
            if (in_array($action->command, ['mine', 'mine_survey'], true)) {
                $yield = $outcome['yield'];
                $totals['extracted'][$yield['symbol']] = ($totals['extracted'][$yield['symbol']] ?? 0) + $yield['units'];
                $totals['attempts']++;
                $totals['load_attempts'] = ($totals['load_attempts'] ?? 0) + 1;
                $totals['load_started_at'] ??= $action->sent_at->toIso8601String();
                $totals['phase'] = $totals['load_attempts'] >= ($run->plan['max_extractions'] ?? 1) ? 'sell' : 'mine';
                $totals['no_progress'] = $yield['units'] === 0 ? $totals['no_progress'] + 1 : 0;
            }
            if ($action->command === 'buy' && $run->strategy === 'trade') {
                $symbol = $action->arguments['symbol'];
                $totals['purchased'][$symbol] = ($totals['purchased'][$symbol] ?? 0) + $action->arguments['units'];
                $totals['purchase_cost'] = ($totals['purchase_cost'] ?? 0) + $price;
                if ($totals['purchased'][$symbol] >= $run->plan['units']) {
                    $totals['phase'] = 'sell';
                }
            }
            if ($action->command === 'sell') {
                $symbol = $action->arguments['symbol'];
                $totals['sold'][$symbol] = ($totals['sold'][$symbol] ?? 0) + $action->arguments['units'];
                $totals['sale_proceeds'] += $price;
            }
        }
        if ($action->command === 'buy_ship' && ! empty($outcome['delivered_ship']) && empty($outcome['configuration_mismatch'])) {
            $totals['purchased_ships'] = array_values(array_unique([...($totals['purchased_ships'] ?? []), $outcome['delivered_ship']]));
        }
        if ($action->command === 'jump') {
            $totals['jump_cost'] = ($totals['jump_cost'] ?? 0) + $price;
            $totals['jumps'] = ($totals['jumps'] ?? 0) + 1;
        }
        if ($action->command === 'navigate') {
            $totals['travel_fuel'] = ($totals['travel_fuel'] ?? 0) + ($outcome['travel']['actual_fuel'] ?? 0);
        }
        $run->update(['totals' => $totals, 'next_action_at' => now(), 'heartbeat_at' => now(), 'last_dispatched_at' => null]);
        if ($outcome['price_discrepancy'] || ($outcome['fuel_discrepancy'] ?? false) || ! empty($outcome['configuration_mismatch']) || (isset($run->plan['max_fuel']) && ($totals['travel_fuel'] ?? 0) > $run->plan['max_fuel']) || $totals['spending'] > $run->plan['max_spend'] || ($totals['no_progress'] ?? 0) >= 1 || $totals['credit_delta'] < -$run->plan['max_loss']) {
            $run->update(['state' => 'blocked', 'blocked_reason' => ! empty($outcome['configuration_mismatch']) ? 'Delivered ship configuration differed from the approved offer. Review required.' : 'Execution price, spending, loss or no-progress limit reached.']);
        }
        app(CycleAccounting::class)->record($run, $ship);
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
            app(AgentSynchronizer::class)->sync($agent, false);
            if ($agent->credits !== $observedBalance) {
                throw new GameException('Balance changed since review. Refresh and review again.');
            }
            $summary = $this->summary($agent);
            DB::transaction(function () use ($agent, $observedBalance, $note, $startingBalance, $summary) {
                if ($startingBalance !== null) {
                    DB::table('agent_credit_baselines')->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->update(['user_starting_credits' => $startingBalance, 'user_starting_at' => now()]);
                }
                if (($summary['unexplained_change'] ?? 0) !== 0) {
                    DB::table('agent_balance_reviews')->insert(['agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'adjustment' => $summary['unexplained_change'], 'observed_balance' => $observedBalance, 'note' => $note, 'reviewed_at' => now()]);
                }
            });

            return $this->summary($agent);
        });
    }
}
