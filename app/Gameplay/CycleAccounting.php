<?php

namespace App\Gameplay;

class CycleAccounting
{
    public function summarize(array $start, array $end, array $cash, int $seconds, int $ships = 1): array
    {
        $net = ($cash['sales'] ?? 0) - array_sum(array_intersect_key($cash, array_flip(['goods', 'fuel', 'repairs', 'jump', 'other'])));
        $unresolved = [];
        if ($start['waypoint'] !== $end['waypoint']) {
            $unresolved[] = 'Positioning or empty return remains open.';
        }
        if ($start['fuel'] !== $end['fuel']) {
            $unresolved[] = 'Starting and ending tank fuel differ.';
        }
        if ($start['inventory'] !== $end['inventory']) {
            $unresolved[] = 'Inventory remains unmatched; unsold cargo is not realized income.';
        }
        if (! ($cash['wear_recorded'] ?? false)) {
            $unresolved[] = 'Wear and repair exposure is unmeasured.';
        }
        if ($seconds < 1) {
            $unresolved[] = 'Elapsed time is not yet measurable.';
        }
        $closed = $unresolved === [];

        return ['state' => $closed ? 'realized_comparable' : 'unresolved', 'cash_contribution' => $net,
            'net_credits_per_hour' => $closed ? $net * 3600 / $seconds : null,
            'cash_per_elapsed_hour' => $seconds > 0 ? $net * 3600 / $seconds : null,
            'income_per_ship_hour' => $closed ? $net * 3600 / ($seconds * max(1, $ships)) : null,
            'elapsed_seconds' => $seconds, 'capital' => $cash['capital'] ?? 0, 'unsold_inventory' => $end['inventory'], 'unresolved' => $unresolved];
    }

    public function fleetInvestment(int $capital, ?int $incrementalNetPerHour, int $minerUnitsDuringRoundTrip, int $haulerCapacity, int $buyerBatchCapacity): array
    {
        $ready = $incrementalNetPerHour !== null && $incrementalNetPerHour > 0 && $minerUnitsDuringRoundTrip <= min($haulerCapacity, $buyerBatchCapacity);

        return ['ready' => $ready, 'payback_hours' => $ready ? $capital / $incrementalNetPerHour : null,
            'reason' => $ready ? 'Estimated incremental gain includes added operating costs; purchase still needs approval.' : 'Need measured incremental income, adequate hauling capacity and buyer throughput before expansion.'];
    }
}
