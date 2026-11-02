<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\GameObservation;
use App\Models\ShipAction;
use Illuminate\Support\Collection;

class CycleAccounting
{
    public function summarize(array $start, array $end, array $cash, int $seconds, int $ships = 1): array
    {
        $net = ($cash['sales'] ?? 0) - array_sum(array_intersect_key($cash, array_flip(['goods', 'fuel', 'repairs', 'jump', 'other'])));
        $wear = $cash['wear_cost'] ?? $cash['wear_allowance'] ?? null;
        $allowance = ! isset($cash['wear_cost']) && isset($cash['wear_allowance']);
        $wearAdjustment = $wear === null ? null : max(0, $wear - ($cash['repairs'] ?? 0));
        $afterWear = $wearAdjustment === null ? null : $net - $wearAdjustment;
        $unresolved = [];
        if (! isset($start['waypoint'], $end['waypoint']) || $start['waypoint'] !== $end['waypoint'] || ($end['status'] ?? null) === 'IN_TRANSIT') {
            $unresolved[] = 'Positioning or empty return remains open.';
        }
        if (! isset($start['fuel'], $end['fuel']) || $start['fuel'] !== $end['fuel']) {
            $unresolved[] = 'Starting and ending tank fuel differ.';
        }
        $initialInventory = $start['inventory'] ?? null;
        $endingInventory = $end['inventory'] ?? null;
        if (is_array($initialInventory)) {
            ksort($initialInventory);
        }
        if (is_array($endingInventory)) {
            ksort($endingInventory);
        }
        if ($initialInventory === null || $endingInventory === null || $initialInventory !== $endingInventory) {
            $unresolved[] = 'Inventory remains unmatched; unsold cargo is not realized income.';
        }
        if ($wear === null) {
            $unresolved[] = 'Wear and repair exposure is unmeasured.';
        }
        if ($seconds < 1) {
            $unresolved[] = 'Elapsed time is not yet measurable.';
        }
        $closed = $unresolved === [];

        return ['state' => $closed ? ($allowance ? 'estimated_comparable' : 'realized_comparable') : 'unresolved', 'cash_contribution' => $net,
            'operating_costs' => array_intersect_key($cash, array_flip(['goods', 'fuel', 'repairs', 'jump', 'other'])),
            'wear_basis' => $wear === null ? 'unmeasured' : ($allowance ? 'explicit_run_allowance_including_repairs' : 'observed_cost_including_repairs'),
            'wear_cost_or_allowance' => $wear, 'additional_wear_deduction' => $wearAdjustment, 'net_after_wear' => $afterWear,
            'net_credits_per_hour' => $closed ? $afterWear * 3600 / $seconds : null,
            'realized_net_per_hour' => $closed && ! $allowance ? $afterWear * 3600 / $seconds : null,
            'cash_per_elapsed_hour' => $seconds > 0 ? $net * 3600 / $seconds : null,
            'income_per_ship_hour' => $closed ? $afterWear * 3600 / ($seconds * max(1, $ships)) : null,
            'ship_count' => $ships, 'ship_hours' => $seconds * $ships / 3600,
            'sold_units_per_elapsed_hour' => $seconds > 0 && isset($cash['sold_units']) ? $cash['sold_units'] * 3600 / $seconds : null,
            'elapsed_seconds' => $seconds, 'capital' => $cash['capital'] ?? null, 'start' => $start, 'end' => $end,
            'unsold_inventory' => $endingInventory, 'unresolved' => $unresolved];
    }

    public function record(AutomationRun $run, ?array $ship): void
    {
        $run->refresh();
        if (! $run->started_at) {
            return;
        }
        $totals = $run->totals;
        $seconds = max(0, (int) $run->started_at->diffInSeconds(now()));
        $cash = ['sales' => $totals['receipts'] ?? $totals['sale_proceeds'] ?? 0, 'goods' => $totals['purchase_cost'] ?? $totals['acquisition_cost'] ?? 0,
            'fuel' => $totals['fuel_cost'] ?? 0, 'repairs' => $totals['repair_cost'] ?? 0, 'jump' => $totals['jump_cost'] ?? 0,
            'other' => $totals['other_cost'] ?? 0, 'sold_units' => array_sum($totals['sold'] ?? []), 'capital' => $run->plan['capital'] ?? null];
        // Account for confirmed debits that have no more specific operating category.
        $knownCosts = array_sum(array_intersect_key($cash, array_flip(['goods', 'fuel', 'repairs', 'jump', 'other'])));
        $cash['other'] += max(0, ($totals['spending'] ?? $knownCosts) - $knownCosts);
        if (isset($run->plan['wear_allowance'])) {
            $cash['wear_allowance'] = $run->plan['wear_allowance'];
        }
        $start = ['waypoint' => $run->plan['initial_waypoint'] ?? null, 'fuel' => $run->plan['initial_fuel'] ?? null, 'inventory' => $run->plan['initial_inventory'] ?? null];
        $end = ['waypoint' => $ship['nav']['waypointSymbol'] ?? null, 'status' => $ship['nav']['status'] ?? null, 'fuel' => $ship['fuel']['current'] ?? null,
            'inventory' => $ship ? MiningPlanner::inventory($ship) : null];
        $accounting = $this->summarize($start, $end, $cash, $seconds, count($run->plan['participating_ships'] ?? [$run->ship_symbol]));
        $settled = $run->state->value === 'completed' && ! ShipAction::where('automation_run_id', $run->id)->where(fn ($q) => $q->whereIn('state', ['pending', 'sending', 'uncertain', 'acknowledged'])->orWhere('review_required', true))->exists();
        if (! $settled) {
            $accounting['state'] = 'unresolved';
            $accounting['unresolved'][] = 'Run is not completed with all commands settled and reviewed.';
            $accounting['net_credits_per_hour'] = $accounting['realized_net_per_hour'] = $accounting['income_per_ship_hour'] = null;
        }
        $totals['accounting'] = $accounting;
        $totals['accounted_at'] = now()->toIso8601String();
        $totals['elapsed_seconds'] = $seconds;
        $totals['ending_fuel'] = $end['fuel'];
        $totals['unsold'] = $end['inventory'];
        $run->update(['totals' => $totals]);
    }

    public function capital(Agent $agent, array $symbols, ?array $known = null): array
    {
        if ($known === null) {
            $known = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('state', 'confirmed')->where('command', 'buy_ship')->get()
                ->filter(fn ($action) => isset($action->outcome['delivered_ship'], $action->outcome['transaction_total']))
                ->mapWithKeys(fn ($action) => [$action->outcome['delivered_ship'] => $action->outcome['transaction_total']])->all();
        }
        $ships = [];
        foreach ($symbols as $symbol) {
            $ships[$symbol] = $known[$symbol] ?? null;
        }

        return ['ships' => $ships, 'known_ship_capital' => array_sum($ships), 'total' => in_array(null, $ships, true) ? null : array_sum($ships),
            'basis' => 'Confirmed delivered ship purchase including its original loadout. Imported assets and later equipment costs remain unknown.'];
    }

    public function forecast(array $samples): array
    {
        $usable = array_values(array_filter($samples, fn ($s) => in_array($s['accounting']['state'] ?? null, ['realized_comparable', 'estimated_comparable'], true) && ($s['seconds'] ?? 0) > 0));
        if ($usable === []) {
            return ['state' => 'unresolved', 'sample_count' => 0, 'base' => null, 'conservative' => null, 'basis' => 'No comparable closed-cycle observations. Cash contribution alone cannot forecast net income.'];
        }
        $nets = array_column(array_column($usable, 'accounting'), 'net_after_wear');
        $seconds = array_column($usable, 'seconds');
        $baseNet = (int) floor(array_sum($nets) / count($usable));
        $baseSeconds = (int) ceil(array_sum($seconds) / count($usable));
        $lowNet = min($nets);
        $conservativeSeconds = $lowNet < 0 ? min($seconds) : max($seconds);

        return ['state' => 'estimate', 'sample_count' => count($usable), 'excluded_samples' => count($samples) - count($usable),
            'base' => ['net' => $baseNet, 'seconds' => $baseSeconds, 'net_per_hour' => $baseNet * 3600 / $baseSeconds],
            'conservative' => ['net' => $lowNet, 'seconds' => $conservativeSeconds, 'net_per_hour' => $lowNet * 3600 / $conservativeSeconds],
            'basis' => 'Base is the observed sample mean. Conservative combines the lowest observed net with the slowest observed cycle (fastest for a loss). This is a sample envelope, not a calibrated confidence bound.',
            'wear_bases' => array_values(array_unique(array_column(array_column($usable, 'accounting'), 'wear_basis'))),
            'wear_assumption' => 'Historical wear costs or entered allowances are retained in these estimates. A new run may use a different allowance.'];
    }

    public function history(Agent $agent): array
    {
        return AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('strategy', 'mining')->whereNotNull('started_at')->where('updated_at', '>=', now()->subDays(7))->latest('id')->limit(100)->get()
            ->filter(fn ($run) => isset($run->plan['equipment'], $run->totals['elapsed_seconds'], $run->totals['cash_contribution']) && ($run->totals['cycles'] ?? 0) <= 1 && ($run->plan['max_extractions'] ?? 1) === 1)
            ->map(fn ($run) => ['site' => $run->plan['site'], 'market' => $run->plan['market'], 'equipment' => $run->plan['equipment'], 'quote_signature' => $run->plan['market_quote_signature'] ?? $run->plan['initial_quote_signatures'][$run->plan['market']] ?? null,
                'initial_waypoint' => $run->plan['initial_waypoint'], 'initial_fuel' => $run->plan['initial_fuel'], 'seconds' => $run->totals['elapsed_seconds'], 'contribution' => $run->totals['cash_contribution'],
                'accounting' => $run->totals['accounting'] ?? null, 'at' => $run->updated_at->toIso8601String()])->values()->all();
    }

    public function reachableInventory(Agent $agent, ?array $ship, ?Collection $listings = null): array
    {
        if (! $ship || ! isset($ship['nav']['status'], $ship['nav']['waypointSymbol'], $ship['cargo']['inventory']) || $ship['nav']['status'] === 'IN_TRANSIT' || $agent->isArchived()) {
            return ['state' => 'unresolved', 'estimated_value_after_fuel' => null, 'net_after_all_remaining_costs' => null, 'reason' => 'A stationary observed ship is required.'];
        }
        $inventory = MiningPlanner::inventory($ship);
        if ($inventory === []) {
            return ['state' => 'empty', 'estimated_value_after_fuel' => 0, 'net_after_all_remaining_costs' => 0, 'unpriced' => []];
        }
        $choices = [];
        $listings ??= app(FuelPlanner::class)->listings($agent);
        $quotes = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'market')->where('observed_at', '>=', now()->subSeconds(config('gameplay.snapshot_seconds')))->get();
        foreach ($quotes as $quote) {
            if (! empty($quote->data['invalidated_by_action'])) {
                continue;
            }
            $cost = 0;
            if ($quote->symbol !== $ship['nav']['waypointSymbol']) {
                if (! isset($ship['fuel']['current'], $ship['fuel']['capacity'], $ship['nav']['flightMode'], $ship['nav']['systemSymbol'])) {
                    continue;
                }
                try {
                    $route = app(FuelPlanner::class)->leg($agent, $ship, $quote->symbol, listings: $listings);
                    $cost = $route['refuel_ceiling'];
                } catch (GameException) {
                    continue;
                }
            }
            $gross = 0;
            $remaining = $inventory;
            foreach ($inventory as $symbol => $units) {
                $bid = collect($quote->data['tradeGoods'] ?? [])->firstWhere('symbol', $symbol);
                if (! $bid || ($bid['sellPrice'] ?? 0) < 1 || ($bid['tradeVolume'] ?? 0) < 1) {
                    continue;
                }
                $batch = min($units, $bid['tradeVolume']);
                $gross += $batch * $bid['sellPrice'];
                $remaining[$symbol] -= $batch;
            }
            if ($gross === 0) {
                continue;
            }
            $choices[] = ['buyer' => $quote->symbol, 'gross_one_batch' => $gross, 'remaining_fuel_ceiling' => $cost,
                'estimated_value_after_fuel' => max(0, $gross - $cost), 'unpriced' => array_filter($remaining), 'quote_at' => $quote->observed_at->toIso8601String()];
        }
        usort($choices, fn ($a, $b) => $b['estimated_value_after_fuel'] <=> $a['estimated_value_after_fuel']);

        return ($choices[0] ?? ['estimated_value_after_fuel' => null, 'unpriced' => $inventory]) + ['state' => $choices === [] ? 'unresolved' : 'partial_estimate',
            'net_after_all_remaining_costs' => null,
            'reason' => 'One observed sale batch per good at one reachable buyer, less remaining fuel ceiling. Remaining wear and selling-time exposure are unmeasured, so full net value is unresolved. Excess and unpriced cargo stay uncertain. Refresh before selling; no value is realized or added to comparable income.'];
    }

    public function timeline(array $intervals): array
    {
        $union = function (array $ranges): int {
            usort($ranges, fn ($a, $b) => $a['start'] <=> $b['start']);
            $seconds = 0;
            $end = null;
            foreach ($ranges as $range) {
                $seconds += max(0, $range['end'] - max($range['start'], $end ?? $range['start']));
                $end = max($end ?? $range['end'], $range['end']);
            }

            return $seconds;
        };
        $shipSeconds = collect($intervals)->groupBy('ship')->sum(fn ($ranges) => $union($ranges->all()));

        return ['elapsed_seconds' => $union($intervals), 'ship_hours' => $shipSeconds / 3600,
            'basis' => 'Union of observed run intervals. Overlapping cooldown, travel, API waits and parallel ships count once in elapsed time; each ship contributes its own occupied time. Gaps between runs are excluded.'];
    }

    public function fleetInvestment(array $case, ?float $alternativeRate): array
    {
        $capital = isset($case['ship_capital'], $case['equipment_capital']) ? $case['ship_capital'] + $case['equipment_capital'] : null;
        $capacity = min($case['hauler_capacity'] ?? 0, $case['buyer_capacity'] ?? 0);
        $throughputKnown = isset($case['production_units'], $case['round_trip_seconds'], $case['hauler_capacity'], $case['buyer_capacity']);
        $throughputReady = $throughputKnown && $case['production_units'] <= $capacity;
        $base = $case['base_net'] ?? null;
        $conservative = $case['conservative_net'] ?? null;
        $seconds = $case['elapsed_seconds'] ?? 0;
        $baseRate = $base !== null && $seconds > 0 ? $base * 3600 / $seconds : null;
        $conservativeRate = $conservative !== null && $seconds > 0 ? $conservative * 3600 / $seconds : null;
        $incremental = $baseRate !== null && $alternativeRate !== null ? $baseRate - $alternativeRate : null;
        $lowIncremental = $conservativeRate !== null && $alternativeRate !== null ? $conservativeRate - $alternativeRate : null;
        $ready = $throughputReady && $capital !== null && ($case['ship_seconds'] ?? 0) > 0 && ($lowIncremental ?? 0) > 0 && ($incremental ?? 0) > 0;

        return ['state' => $ready ? 'operator_estimate' : 'unresolved', 'ready' => $ready, 'capital' => $capital,
            'ship_capital' => $case['ship_capital'] ?? null, 'equipment_capital' => $case['equipment_capital'] ?? null,
            'base_net_per_hour' => $baseRate, 'conservative_net_per_hour' => $conservativeRate, 'current_assets_net_per_hour' => $alternativeRate,
            'incremental_net_per_hour' => $incremental, 'conservative_incremental_net_per_hour' => $lowIncremental,
            'payback_hours' => $ready ? $capital / $incremental : null, 'conservative_payback_hours' => $ready ? $capital / $lowIncremental : null,
            'ship_hours' => isset($case['ship_seconds']) ? $case['ship_seconds'] / 3600 : null,
            'income_per_ship_hour' => $base !== null && ($case['ship_seconds'] ?? 0) > 0 ? $base * 3600 / $case['ship_seconds'] : null,
            'production_units_per_round_trip' => $case['production_units'] ?? null, 'hauler_capacity' => $case['hauler_capacity'] ?? null,
            'buyer_capacity_per_round_trip' => $case['buyer_capacity'] ?? null,
            'throughput_units_per_hour' => $throughputKnown && $case['round_trip_seconds'] > 0 ? min($case['production_units'], $capacity) * 3600 / $case['round_trip_seconds'] : null,
            'reason' => $ready ? 'Operator estimate after all operating costs and wear, relative to the best comparable measured current-assets alternative. Separate purchase approval remains required.' : 'Need comparable current-assets income, complete capital and ship-time inputs, positive incremental returns and adequate hauling and buyer throughput.'];
    }
}
