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
                'sell' => 'sale_receipt', 'contract_accept', 'contract_fulfill' => 'contract_receipt', 'refuel' => 'fuel_cost', 'buy' => 'acquisition_cost', 'buy_ship' => 'setup_cost', default => 'other'
            },
            'credits' => $delta, 'confirmed_at' => now()]);
    }

    public function apply(AutomationRun $run, ShipAction $action, array $ship, array $outcome, int $delta, int $price): void
    {
        $totals = $run->fresh()->totals;
        $totals['credit_delta'] += $delta;
        $totals['agent_balance_delta'] = $action->before_state['credits'] + $delta - $run->plan['initial_credits'];
        $totals['unsold'] = MiningPlanner::inventory($ship);
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
            if ($action->command === 'mine') {
                $yield = $outcome['yield'];
                $totals['extracted'][$yield['symbol']] = ($totals['extracted'][$yield['symbol']] ?? 0) + $yield['units'];
                $totals['attempts']++;
                $totals['phase'] = 'sell';
                $totals['no_progress'] = $yield['units'] === 0 ? $totals['no_progress'] + 1 : 0;
            }
            if ($action->command === 'sell') {
                $symbol = $action->arguments['symbol'];
                $totals['sold'][$symbol] = ($totals['sold'][$symbol] ?? 0) + $action->arguments['units'];
                $totals['sale_proceeds'] += $price;
            }
        }
        $run->update(['totals' => $totals, 'next_action_at' => now(), 'heartbeat_at' => now(), 'last_dispatched_at' => null]);
        if ($outcome['price_discrepancy'] || $totals['spending'] > $run->plan['max_spend'] || ($totals['no_progress'] ?? 0) >= 1 || $totals['credit_delta'] < -$run->plan['max_loss']) {
            $run->update(['state' => 'blocked', 'blocked_reason' => 'Execution price, spending, loss or no-progress limit reached.']);
        }
    }

    public function summary(Agent $agent): array
    {
        $baseline = DB::table('agent_credit_baselines')->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->first();
        $entries = DB::table('confirmed_credit_entries')->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id);
        $confirmed = (int) (clone $entries)->when($baseline, fn ($q) => $q->where('confirmed_at', '>=', $baseline->observed_at))->sum('credits');
        $change = $baseline && $agent->credits !== null ? $agent->credits - ($baseline->user_starting_credits ?? $baseline->credits) : null;

        $reviewed = (int) DB::table('agent_balance_reviews')->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->sum('adjustment');

        return ['reviewed_adjustments' => $reviewed, 'baseline' => $baseline ? ['credits' => $baseline->credits, 'source' => $baseline->source, 'user_starting_credits' => $baseline->user_starting_credits, 'user_starting_at' => $baseline->user_starting_at, 'observed_at' => $baseline->observed_at] : null,
            'total_balance_change' => $change, 'confirmed_change' => $confirmed, 'manual_change' => (int) (clone $entries)->whereNull('automation_run_id')->sum('credits'),
            'unexplained_change' => $change === null ? null : $agent->credits - $baseline->credits - $confirmed - $reviewed];
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
