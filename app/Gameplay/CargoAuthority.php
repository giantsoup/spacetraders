<?php

namespace App\Gameplay;

use App\Models\Agent;

final class CargoAuthority
{
    public function available(Agent $agent, array $ship, string $symbol, int $protected = 0): int
    {
        $committed = 0;
        foreach (app(SpaceTradersApi::class)->contracts($agent) as $contract) {
            if ($contract['accepted'] && ! $contract['fulfilled']) {
                foreach ($contract['terms']['deliver'] as $term) {
                    if ($term['tradeSymbol'] === $symbol) {
                        $committed += $term['unitsRequired'] - $term['unitsFulfilled'];
                    }
                }
            }
        }

        // Conservative allocation: obligations remain protected on the source ship even if other ships also carry the good.
        return max(0, (MiningPlanner::inventory($ship)[$symbol] ?? 0) - max($committed, $protected));
    }
}
