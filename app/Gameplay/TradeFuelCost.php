<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\GameObservation;

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

    /** The higher usable endpoint ask; an endpoint without fuel sales adds no requirement. */
    public function price(Agent $agent, array $endpoints): ?int
    {
        $listings = $this->fuel->listings($agent);
        $prices = [];
        foreach (array_unique($endpoints) as $symbol) {
            $quote = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'market')
                ->where('symbol', $symbol)->orderByDesc('observed_at')->orderByDesc('id')->first();
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
