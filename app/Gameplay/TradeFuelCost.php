<?php

namespace App\Gameplay;

use App\Models\Agent;

final class TradeFuelCost
{
    public function __construct(private FuelPlanner $fuel) {}

    public static function consumption(int $burn, int $price): int
    {
        return (int) ceil($burn / 100) * $price;
    }

    /** Forecast and acceptance charges cannot exceed the existing full-tank cash ceiling. */
    public static function bounded(int $burn, ?int $price, int $ceiling): array
    {
        $expected = $burn === 0 ? 0 : ($price === null ? $ceiling : min(self::consumption($burn, $price), $ceiling));

        return ['expected_fuel_cost' => $expected, 'cycle_fuel_allowance' => min(2 * $expected, $ceiling), 'fuel_safety_ceiling' => $ceiling];
    }

    public function estimate(Agent $agent, int $burn, array $endpoints, int $ceiling): array
    {
        return self::bounded($burn, $this->price($agent, $endpoints), $ceiling);
    }

    /** Aggregate planned stops and the last continuation hold, without charging a shared top-up twice. */
    public function chainEstimate(array $legs): array
    {
        $expected = $total = $remaining = 0;
        $delivery = null;
        foreach ($legs as $leg) {
            $ceiling = $leg['total_refuel_ceiling'] ?? $leg['totalFuelCeiling'] ?? $leg['remainingFuelCeiling'] ?? $leg['total_ceiling'] ?? $leg['refuel_ceiling'] ?? 0;
            $held = $leg['remainingFuelCeiling'] ?? $leg['remaining_refuel_ceiling'] ?? $ceiling;
            $expected += $leg['expectedFuelCost'] ?? $leg['expected_fuel_cost'] ?? $leg['fuel_cost'] ?? 0;
            $total += $ceiling;
            $remaining += $held;
            if ($delivery === null) {
                $delivery = $leg['deliveryFuelCeiling'] ?? $held;
            }
        }

        return ['expected_fuel_cost' => $expected, 'total_refuel_ceiling' => $total,
            'remaining_refuel_ceiling' => $remaining, 'current_leg_delivery_hold' => $delivery ?? 0];
    }

    /** The higher usable endpoint ask; an endpoint without fuel sales adds no requirement. */
    public function price(Agent $agent, array $endpoints): ?int
    {
        $listings = $this->fuel->listings($agent);
        $prices = [];
        foreach (array_unique($endpoints) as $symbol) {
            $quote = $this->fuel->marketRow($agent, $symbol);
            $fuel = collect($quote?->data['tradeGoods'] ?? [])->firstWhere('symbol', 'FUEL');
            if (! $quote || ! $fuel || ! empty($quote->data['invalidated_by_action']) || ($fuel['purchasePrice'] ?? 0) < 1 || ($fuel['tradeVolume'] ?? 0) < 1
                || $quote->observed_at->lt(now()->subSeconds(config('gameplay.fuel_quote_max_seconds', 21600))) || ! $this->fuel->listsFuel($agent, $symbol, $listings)) {
                continue;
            }
            $prices[] = $fuel['purchasePrice'];
        }

        return $prices ? max($prices) : null;
    }
}
