<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\GameObservation;
use Carbon\CarbonImmutable;

class OperationPlanner
{
    public function __construct(private FuelPlanner $fuel) {}

    public function preview(Agent $agent, array $ship, array $limits): array
    {
        $home = $ship['nav']['waypointSymbol'];
        $destination = $limits['strategy'] === 'scout' ? $limits['destination'] : $limits['market'];
        if ($home === $destination) {
            throw new GameException('Choose a different same-system destination.');
        }
        $outbound = $this->fuel->leg($agent, $ship, $destination, $limits['reserve']);
        $returning = $ship;
        $returning['nav']['waypointSymbol'] = $destination;
        $returning['fuel']['current'] -= $outbound['outbound_fuel'];
        $return = $this->fuel->leg($agent, $returning, $home, $limits['reserve']);
        $fuelUnits = $outbound['outbound_fuel'] + $return['outbound_fuel'];
        $fuelCeiling = $return['refuel_ceiling'];
        if ($return['continuation'] !== $home) {
            throw new GameException('This bounded cycle must return to a verified fuel seller at its starting waypoint.');
        }
        if ($fuelUnits > $limits['max_fuel'] || $fuelCeiling > $limits['max_spend']) {
            throw new GameException('Round-trip travel or refueling exceeds the approved fuel/spending limits.');
        }
        $route = ['site' => $home, 'market' => $destination, 'home' => $home, 'outbound' => $outbound, 'return' => $return,
            'fuel_estimate' => $fuelUnits, 'fuel_ceiling' => $fuelCeiling, 'quote_at' => $outbound['fuel_price_at'], 'known_buyers' => [], 'unknown_yields' => []];
        if ($limits['strategy'] === 'scout') {
            if ($limits['max_loss'] < $fuelCeiling) {
                throw new GameException('Observation trip refueling must fit the approved cash-loss bound.');
            }
            $listing = $this->observation($agent, 'market_listing', $destination);
            if (! $listing) {
                throw new GameException('Fetch the remote goods directory before approving a price-observation visit. Hidden traits require separately approved scanning/charting support.');
            }
            $route['purpose'] = 'Observe prices of '.implode(', ', array_unique(array_column(array_merge($listing->data['imports'], $listing->data['exports'], $listing->data['exchange']), 'symbol'))).' at '.$destination.' to resolve buyer, trade or fuel comparisons.';
            $route['stop'] = 'One detailed observation, then return home; stop earlier on any limit or safety failure.';
            $route['information_benefit'] = 'Unknown. No chart reward or displaced earnings are assumed.';
        } else {
            $ask = $this->quote($agent, $home, $limits['symbol']);
            $bid = $this->quote($agent, $destination, $limits['symbol']);
            $inventory = $limits['units'] * $ask['purchasePrice'];
            $margin = $limits['units'] * ($bid['sellPrice'] - $ask['purchasePrice']) - $fuelCeiling;
            if ($ask['purchasePrice'] < 1 || $bid['sellPrice'] < $limits['min_sell_price'] || $margin < $limits['min_margin'] || $inventory > $limits['max_inventory'] || $inventory + $fuelCeiling > $limits['max_spend'] || $inventory > $limits['max_loss'] || $limits['units'] > $ship['cargo']['capacity']) {
                throw new GameException('Trade fails the complete-cycle margin, inventory exposure, cargo, spending or cash-loss bounds.');
            }
            $route += ['source_at' => $ask['observed_at'], 'buyer_at' => $bid['observed_at'], 'inventory_exposure' => $inventory,
                'estimated_cash_margin' => $margin, 'conservative_cash_margin' => -$inventory - $fuelCeiling,
                'empty_return_included' => true, 'earnings_per_hour' => null, 'time_and_wear' => 'Unmeasured; margin is not economic profit.'];
        }
        if ($agent->credits < $limits['reserve'] + $limits['max_spend']) {
            throw new GameException('Approved spending plus protected reserve exceeds available credits.');
        }

        return array_merge($limits, ['site' => $home, 'market' => $destination, 'cycles' => 1, 'route' => $route]);
    }

    public function next(Agent $agent, array $ship, array $plan, array $totals, CarbonImmutable $now): MiningStep
    {
        if ($ship['nav']['status'] === 'IN_TRANSIT') {
            $arrival = CarbonImmutable::parse($ship['nav']['route']['arrival']);

            return MiningStep::wait($arrival->greaterThan($now) ? $arrival : $now->addSeconds(5));
        }
        $expected = [];
        foreach ($totals['purchased'] ?? [] as $symbol => $units) {
            if ($units - ($totals['sold'][$symbol] ?? 0) > 0) {
                $expected[$symbol] = $units - ($totals['sold'][$symbol] ?? 0);
            }
        }
        $actual = MiningPlanner::inventory($ship);
        ksort($expected);
        ksort($actual);
        if ($expected !== $actual) {
            return MiningStep::blocked('Cargo changed outside the approved operation. Retain cargo and reconcile before continuing.');
        }
        $phase = $totals['phase'];
        if ($plan['strategy'] === 'scout' && $phase === 'observe' && ($totals['travel_fuel'] ?? 0) === 0 && $ship['nav']['waypointSymbol'] === $plan['site']) {
            $quote = $this->observation($agent, 'market', $plan['market']);
            if ($quote && ! empty($quote->data['tradeGoods']) && $quote->observed_at->gte($now->subSeconds(5))) {
                return MiningStep::trip();
            }
        }
        if ($plan['strategy'] === 'trade' && $phase === 'buy') {
            if ($ship['nav']['waypointSymbol'] !== $plan['site']) {
                return MiningStep::blocked('Source location changed outside this run. Replan before buying.');
            }
            $ask = $this->quote($agent, $plan['site'], $plan['symbol']);
            $bid = $this->quote($agent, $plan['market'], $plan['symbol']);
            $remaining = $plan['units'] - ($totals['purchased'][$plan['symbol']] ?? 0);
            $spent = $totals['purchase_cost'] ?? 0;
            $projectedMargin = $plan['units'] * $bid['sellPrice'] - $spent - $remaining * $ask['purchasePrice'] - $plan['route']['fuel_ceiling'];
            if ($remaining <= 0) {
                return $this->travel($ship, $plan['market']);
            }
            if ($projectedMargin < $plan['min_margin'] || $ask['purchasePrice'] < 1 || $bid['sellPrice'] <= $ask['purchasePrice']) {
                return MiningStep::blocked('Spread deteriorated. Stop purchases. Owned cargo retained; compare local sale and reachable buyers using remaining costs before a new approval.');
            }
            if ($ship['nav']['status'] !== 'DOCKED') {
                return MiningStep::act('dock');
            }
            $units = min($remaining, $ask['tradeVolume'], $ship['cargo']['capacity'] - $ship['cargo']['units'], intdiv(max(0, $plan['max_inventory'] - $spent), $ask['purchasePrice']));
            $cost = $units * $ask['purchasePrice'];
            if ($units < 1 || $cost + $spent + $totals['fuel_cost'] + $plan['route']['fuel_ceiling'] > $plan['max_spend']) {
                return MiningStep::blocked('Remaining purchase exceeds inventory or spending bounds. Owned cargo retained.');
            }

            return MiningStep::act('buy', ['symbol' => $plan['symbol'], 'units' => $units, 'maxTotal' => $cost, 'reserve' => $plan['reserve']]);
        }
        $destination = $phase === 'return' ? $plan['site'] : $plan['market'];
        if ($ship['nav']['waypointSymbol'] !== $destination) {
            return $this->travel($ship, $destination);
        }
        if ($phase === 'observe') {
            $quote = $this->observation($agent, 'market', $destination);
            if (! $quote || empty($quote->data['tradeGoods']) || $quote->observed_at->lt($now->subSeconds(5))) {
                return MiningStep::blocked('Detailed prices still unavailable. The observation question remains unanswered.');
            }

            return MiningStep::trip();
        }
        if ($phase === 'sell') {
            if ($ship['nav']['status'] !== 'DOCKED') {
                return MiningStep::act('dock');
            }
            $remaining = ($totals['purchased'][$plan['symbol']] ?? 0) - ($totals['sold'][$plan['symbol']] ?? 0);
            if ($remaining > 0) {
                $bid = $this->quote($agent, $destination, $plan['symbol']);
                $units = min($remaining, $bid['tradeVolume']);
                if ($bid['sellPrice'] < $plan['min_sell_price']) {
                    return MiningStep::blocked('Buyer is below the approved owned-cargo floor. Retain cargo; local sale, hold and alternate-buyer options need review.');
                }

                return MiningStep::act('sell', ['symbol' => $plan['symbol'], 'units' => $units, 'minTotal' => $units * $plan['min_sell_price']]);
            }

            return MiningStep::trip();
        }
        if ($ship['nav']['status'] !== 'DOCKED') {
            return MiningStep::act('dock');
        }
        $missing = $ship['fuel']['capacity'] - $ship['fuel']['current'];
        if ($missing > 0) {
            $quote = $this->quote($agent, $destination, 'FUEL');
            $units = min($missing, $quote['tradeVolume'] * 100);
            $ceiling = min($plan['route']['fuel_ceiling'], $plan['max_spend'] - $totals['fuel_cost'] - ($totals['purchase_cost'] ?? 0));
            if ((int) ceil($units / 100) * $quote['purchasePrice'] > $ceiling) {
                return MiningStep::blocked('Return refueling exceeds the remaining approved budget.');
            }

            return MiningStep::act('refuel', ['units' => $units, 'maxTotal' => $ceiling, 'reserve' => $plan['reserve']]);
        }

        return MiningStep::trip();
    }

    public function quote(Agent $agent, string $market, string $symbol): array
    {
        $quote = $this->observation($agent, 'market', $market);
        $good = collect($quote?->data['tradeGoods'] ?? [])->firstWhere('symbol', $symbol);
        if (! $quote || ! $good || ! empty($quote->data['invalidated_by_action']) || $quote->observed_at->lt(now()->subSeconds(config('gameplay.snapshot_seconds')))) {
            throw new GameException('Detailed prices at '.$market.' for '.$symbol.' are unknown or stale. Stop and retain any owned cargo.');
        }

        return $good + ['observed_at' => $quote->observed_at->toIso8601String()];
    }

    private function observation(Agent $agent, string $kind, string $symbol): ?GameObservation
    {
        return GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', $kind)->where('symbol', $symbol)->first();
    }

    private function travel(array $ship, string $destination): MiningStep
    {
        return $ship['nav']['status'] === 'DOCKED' ? MiningStep::act('orbit') : MiningStep::act('navigate', ['waypointSymbol' => $destination]);
    }
}
