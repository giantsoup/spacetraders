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
        $listings = $this->fuel->listings($agent);
        $departureCeiling = 0;
        $planner = $agent;
        // Campaign trades and command-ship positioning top up first at a verified fuel seller,
        // so every leg, the return included, is planned from the refuelled ship.
        $topUp = ! empty($limits['campaign_trade']) || ! empty($limits['departure_refuel']) ? $this->fuel->departureRefuel($agent, $ship, $listings) : null;
        if ($topUp) {
            $departureCeiling = $topUp['ceiling'];
            $planner = $topUp['agent'];
            $ship = $topUp['ship'];
        }
        try {
            $outbound = $this->fuel->leg($planner, $ship, $destination, $limits['reserve'], listings: $listings);
        } catch (GameException $exception) {
            if ($topUp) {
                throw $exception;
            }
            $listing = $listings->get($home);
            if ($limits['strategy'] !== 'scout' || empty($limits['one_way']) || $ship['fuel']['current'] >= $ship['fuel']['capacity']
                || ! $listing || ! in_array('FUEL', array_column(array_merge($listing->data['exports'], $listing->data['exchange']), 'symbol'), true)) {
                throw $exception;
            }
            $quote = $this->quote($agent, $home, 'FUEL');
            $departureCeiling = (int) ceil($ship['fuel']['capacity'] / 100) * $quote['purchasePrice'] * 2;
            $funded = clone $agent;
            $funded->credits -= $departureCeiling;
            $refueled = $ship;
            $refueled['fuel']['current'] = $ship['fuel']['capacity'];
            $outbound = $this->fuel->leg($funded, $refueled, $destination, $limits['reserve'], listings: $listings);
        }
        $returning = $ship;
        $returning['nav']['waypointSymbol'] = $destination;
        $returning['fuel']['current'] -= $outbound['outbound_fuel'];
        $oneWay = $limits['strategy'] === 'scout' && ! empty($limits['one_way']);
        $return = $oneWay ? null : $this->fuel->leg($planner, $returning, $home, $limits['reserve'], listings: $listings);
        $fuelUnits = $outbound['outbound_fuel'] + ($return['outbound_fuel'] ?? 0);
        $fuelCeiling = ($oneWay ? $outbound['refuel_ceiling'] : $return['refuel_ceiling']) + $departureCeiling;
        if (! $oneWay && $return['continuation'] !== $home) {
            throw new GameException('This bounded cycle must return to a verified fuel seller at its starting waypoint.');
        }
        if ($fuelUnits > $limits['max_fuel'] || $fuelCeiling > $limits['max_spend']) {
            throw new GameException('Round-trip travel or refueling exceeds the approved fuel/spending limits.');
        }
        $route = ['site' => $home, 'market' => $destination, 'home' => $home, 'outbound' => $outbound, 'return' => $return,
            'departure_refuel_ceiling' => $departureCeiling, 'fuel_estimate' => $fuelUnits, 'fuel_ceiling' => $fuelCeiling, 'quote_at' => $outbound['fuel_price_at'], 'known_buyers' => [], 'unknown_yields' => []];
        if ($limits['strategy'] === 'scout') {
            if ($limits['max_loss'] < $fuelCeiling) {
                throw new GameException('Observation trip refueling must fit the approved cash-loss bound.');
            }
            $listing = $listings->get($destination);
            if (! $listing) {
                throw new GameException('Fetch the remote goods directory before approving a price-observation visit. Hidden traits require separately approved scanning/charting support.');
            }
            $route['purpose'] = 'Observe prices of '.implode(', ', array_unique(array_column(array_merge($listing->data['imports'], $listing->data['exports'], $listing->data['exchange']), 'symbol'))).' at '.$destination.' to resolve buyer, trade or fuel comparisons.';
            $route['stop'] = 'One detailed observation, then return home; stop earlier on any limit or safety failure.';
            $route['information_benefit'] = 'Unknown. No chart reward or displaced earnings are assumed.';
        } else {
            $ask = $this->quote($agent, $home, $limits['symbol']);
            $bid = $this->quote($agent, $destination, $limits['symbol'], self::buyerAge($limits));
            $inventory = $limits['units'] * $ask['purchasePrice'];
            // Departure fuel refills the tank for later work, not this trade: it counts toward spending, not margin.
            $costs = app(TradeFuelCost::class)->estimate($agent, $fuelUnits, [$home, $destination], $fuelCeiling - $departureCeiling);
            if (! empty($limits['campaign_trade']) && ($costs['expected_fuel_cost'] !== ($limits['campaign_trade']['expected_fuel_cost'] ?? null)
                || $costs['cycle_fuel_allowance'] !== ($limits['campaign_trade']['cycle_fuel_allowance'] ?? null))) {
                throw new GameException('Fuel economics changed since ranking. Rerank before creating a fresh campaign preview.');
            }
            $route += $costs;
            $margin = $limits['units'] * ($bid['sellPrice'] - $ask['purchasePrice']) - $costs['cycle_fuel_allowance'];
            if ($ask['purchasePrice'] < 1 || $bid['sellPrice'] < $limits['min_sell_price'] || $margin <= 0 || $margin < $limits['min_margin'] || $inventory > $limits['max_inventory'] || $inventory + $fuelCeiling > $limits['max_spend'] || $inventory > $limits['max_loss'] || $limits['units'] > $ship['cargo']['capacity']) {
                throw new GameException('Trade fails the complete-cycle margin, inventory exposure, cargo, spending or cash-loss bounds.');
            }
            $route += ['source_at' => $ask['observed_at'], 'buyer_at' => $bid['observed_at'], 'inventory_exposure' => $inventory,
                'estimated_cash_margin' => $margin + $costs['cycle_fuel_allowance'] - $costs['expected_fuel_cost'], 'acceptance_margin' => $margin, 'conservative_cash_margin' => -$inventory - $fuelCeiling,
                'empty_return_included' => true, 'earnings_per_hour' => null, 'time_and_wear' => 'Unmeasured; margin is not economic profit.'];
        }
        if ($agent->credits < $limits['reserve'] + $limits['max_spend']) {
            throw new GameException('Approved spending plus protected reserve exceeds available credits.');
        }

        return array_merge($limits, ['site' => $home, 'market' => $destination, 'cycles' => 1, 'route' => $route]);
    }

    public function next(Agent $agent, array $ship, array $plan, array $totals, CarbonImmutable $now): AutomationStep
    {
        if ($ship['nav']['status'] === 'IN_TRANSIT') {
            $arrival = CarbonImmutable::parse($ship['nav']['route']['arrival']);

            return AutomationStep::wait($arrival->greaterThan($now) ? $arrival : $now->addSeconds(5));
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
            return AutomationStep::blocked('Cargo changed outside the approved operation. Retain cargo and reconcile before continuing.');
        }
        $phase = $totals['phase'];
        // Departure top-up runs first: before the first buy and before leaving the starting seller.
        $departure = $plan['route']['departure_refuel_ceiling'] ?? 0;
        if ($departure > 0 && in_array($phase, ['buy', 'observe'], true) && $ship['nav']['waypointSymbol'] === $plan['site']
            && ($totals['travel_fuel'] ?? 0) === 0 && array_sum($totals['purchased'] ?? []) === 0 && $ship['fuel']['current'] < $ship['fuel']['capacity']) {
            $fuel = $this->quote($agent, $plan['site'], 'FUEL');
            $units = min($ship['fuel']['capacity'] - $ship['fuel']['current'], $fuel['tradeVolume'] * 100);
            $ceiling = min($departure - ($totals['fuel_cost'] ?? 0), $plan['max_spend'] - ($totals['fuel_cost'] ?? 0) - ($totals['purchase_cost'] ?? 0));
            if ((int) ceil($units / 100) * $fuel['purchasePrice'] > $ceiling) {
                // The legs were planned from a full tank; never buy or depart on a partial one.
                return AutomationStep::blocked('Departure refuel exceeds its approved ceiling. Owned cargo retained; replan before departure.');
            }
            if ($ship['nav']['status'] !== 'DOCKED') {
                return AutomationStep::act('dock');
            }

            return AutomationStep::act('refuel', ['units' => $units, 'maxTotal' => $ceiling, 'reserve' => $plan['reserve']]);
        }
        if ($plan['strategy'] === 'scout' && empty($plan['one_way']) && $phase === 'observe' && ($totals['travel_fuel'] ?? 0) === 0 && $ship['nav']['waypointSymbol'] === $plan['site']) {
            $quote = $this->observation($agent, 'market', $plan['market']);
            if ($quote && ! empty($quote->data['tradeGoods']) && $quote->observed_at->gte($now->subSeconds(5))) {
                return AutomationStep::trip();
            }
        }
        if ($plan['strategy'] === 'trade' && $phase === 'buy') {
            if ($ship['nav']['waypointSymbol'] !== $plan['site']) {
                return AutomationStep::blocked('Source location changed outside this run. Replan before buying.');
            }
            $remaining = $plan['units'] - ($totals['purchased'][$plan['symbol']] ?? 0);
            if ($remaining <= 0) {
                return $this->travel($ship, $plan['market']);
            }
            $ask = $this->quote($agent, $plan['site'], $plan['symbol']);
            $bid = $this->quote($agent, $plan['market'], $plan['symbol'], self::buyerAge($plan));
            $spent = $totals['purchase_cost'] ?? 0;
            // Once the departure top-up has run, its actual cost is in fuel_cost; count its ceiling only before then.
            // The margin, like the preview's, excludes departure fuel; spending includes it.
            $fuelExposure = ($totals['fuel_cost'] ?? 0) + $plan['route']['fuel_ceiling'] - (($totals['fuel_units'] ?? 0) > 0 ? $departure : 0);
            // Historical plans retain their full margin charge until a fresh preview supplies all economics fields.
            $allowance = $plan['route']['fuel_ceiling'] - $departure;
            if (isset($plan['route']['expected_fuel_cost'], $plan['route']['cycle_fuel_allowance'], $plan['route']['fuel_safety_ceiling'])) {
                $costs = app(TradeFuelCost::class)->estimate($agent, $plan['route']['fuel_estimate'], [$plan['site'], $plan['market']], $allowance);
                if ($costs['expected_fuel_cost'] !== $plan['route']['expected_fuel_cost'] || $costs['cycle_fuel_allowance'] !== $plan['route']['cycle_fuel_allowance']) {
                    return AutomationStep::blocked('Fuel economics changed. Retain owned cargo and create a fresh preview before further purchases.');
                }
                $allowance = $costs['cycle_fuel_allowance'];
            }
            $projectedMargin = $plan['units'] * $bid['sellPrice'] - $spent - $remaining * $ask['purchasePrice'] - $allowance;
            if ($projectedMargin <= 0 || $projectedMargin < $plan['min_margin'] || $ask['purchasePrice'] < 1 || $bid['sellPrice'] <= $ask['purchasePrice']) {
                return AutomationStep::blocked('Spread deteriorated. Stop purchases. Owned cargo retained; compare local sale and reachable buyers using remaining costs before a new approval.');
            }
            if ($ship['nav']['status'] !== 'DOCKED') {
                return AutomationStep::act('dock');
            }
            $units = min($remaining, $ask['tradeVolume'], $ship['cargo']['capacity'] - $ship['cargo']['units'], intdiv(max(0, $plan['max_inventory'] - $spent), $ask['purchasePrice']));
            $reserve = $plan['reserve'];
            if (! empty($plan['campaign_trade'])) {
                app(CampaignTrade::class)->quoteLife($agent, $plan, $totals);
                $reserve = max($reserve, app(CampaignTrade::class)->fuelReserve($agent, $ship['symbol'], $plan['market']));
                $units = min($units, intdiv(max(0, $agent->credits - $reserve), $ask['purchasePrice']));
            }
            $cost = $units * $ask['purchasePrice'];
            if ($units < 1 || $cost + $spent + $fuelExposure > $plan['max_spend']) {
                return AutomationStep::blocked('Remaining purchase exceeds inventory or spending bounds. Owned cargo retained.');
            }

            return AutomationStep::act('buy', ['symbol' => $plan['symbol'], 'units' => $units, 'maxTotal' => $cost, 'reserve' => $reserve]);
        }
        $destination = $phase === 'return' ? $plan['site'] : $plan['market'];
        if ($ship['nav']['waypointSymbol'] !== $destination) {
            if (! empty($plan['one_way']) && $ship['fuel']['current'] < $plan['route']['outbound']['required_fuel']) {
                if ($ship['nav']['status'] !== 'DOCKED') {
                    return AutomationStep::act('dock');
                }
                $fuel = $this->quote($agent, $ship['nav']['waypointSymbol'], 'FUEL');
                $units = min($ship['fuel']['capacity'] - $ship['fuel']['current'], $fuel['tradeVolume'] * 100);
                $ceiling = min($plan['route']['departure_refuel_ceiling'] - $totals['fuel_cost'], $plan['max_spend'] - $totals['fuel_cost']);

                return AutomationStep::act('refuel', ['units' => $units, 'maxTotal' => $ceiling, 'reserve' => $plan['reserve']]);
            }

            return $this->travel($ship, $destination);
        }
        if ($phase === 'observe') {
            $quote = $this->observation($agent, 'market', $destination);
            if (! $quote || empty($quote->data['tradeGoods']) || $quote->observed_at->lt($now->subSeconds(5))) {
                return AutomationStep::blocked('Detailed prices still unavailable. The observation question remains unanswered.');
            }

            if (! empty($plan['one_way']) && $ship['fuel']['current'] < $ship['fuel']['capacity']) {
                $listing = $this->fuel->listings($agent)->get($destination);
                if ($listing && in_array('FUEL', array_column(array_merge($listing->data['exports'], $listing->data['exchange']), 'symbol'), true)) {
                    if ($ship['nav']['status'] !== 'DOCKED') {
                        return AutomationStep::act('dock');
                    }
                    $fuel = $this->quote($agent, $destination, 'FUEL');
                    $units = min($ship['fuel']['capacity'] - $ship['fuel']['current'], $fuel['tradeVolume'] * 100);
                    $ceiling = min($plan['route']['fuel_ceiling'] - $totals['fuel_cost'], $plan['max_spend'] - $totals['fuel_cost']);
                    if ((int) ceil($units / 100) * $fuel['purchasePrice'] <= $ceiling) {
                        return AutomationStep::act('refuel', ['units' => $units, 'maxTotal' => $ceiling, 'reserve' => $plan['reserve']]);
                    }
                }
            }

            return AutomationStep::trip();
        }
        if ($phase === 'sell') {
            if ($ship['nav']['status'] !== 'DOCKED') {
                return AutomationStep::act('dock');
            }
            $remaining = ($totals['purchased'][$plan['symbol']] ?? 0) - ($totals['sold'][$plan['symbol']] ?? 0);
            if ($remaining > 0) {
                $bid = $this->quote($agent, $destination, $plan['symbol']);
                $units = min($remaining, $bid['tradeVolume']);
                $floor = ! empty($plan['campaign_trade']) ? (int) ceil(($totals['purchase_cost'] ?? 0) / max(1, $totals['purchased'][$plan['symbol']] ?? 0)) : $plan['min_sell_price'];
                if ($bid['sellPrice'] < $floor) {
                    return AutomationStep::blocked('Buyer is below the approved owned-cargo floor. Retain cargo; local sale, hold and alternate-buyer options need review.');
                }

                return AutomationStep::act('sell', ['symbol' => $plan['symbol'], 'units' => $units, 'minTotal' => $units * $floor]);
            }

            return AutomationStep::trip();
        }
        if ($ship['nav']['status'] !== 'DOCKED') {
            return AutomationStep::act('dock');
        }
        $missing = $ship['fuel']['capacity'] - $ship['fuel']['current'];
        if ($missing > 0) {
            $quote = $this->quote($agent, $destination, 'FUEL');
            $units = min($missing, $quote['tradeVolume'] * 100);
            $ceiling = min($plan['route']['fuel_ceiling'] - $totals['fuel_cost'], $plan['max_spend'] - $totals['fuel_cost'] - ($totals['purchase_cost'] ?? 0));
            if ((int) ceil($units / 100) * $quote['purchasePrice'] > $ceiling) {
                return AutomationStep::blocked('Return refueling exceeds the remaining approved budget.');
            }

            return AutomationStep::act('refuel', ['units' => $units, 'maxTotal' => $ceiling, 'reserve' => $plan['reserve']]);
        }

        return AutomationStep::trip();
    }

    /**
     * A detailed quote no older than the snapshot age. An arm's committed trade may accept an older
     * buyer quote ($maxAge, from its plan's campaign_trade.buyer_quote_max_age_seconds); nothing else does.
     */
    public function quote(Agent $agent, string $market, string $symbol, ?int $maxAge = null): array
    {
        $quote = $this->observation($agent, 'market', $market);
        $good = collect($quote?->data['tradeGoods'] ?? [])->firstWhere('symbol', $symbol);
        if (! $quote || ! $good || ! empty($quote->data['invalidated_by_action']) || $quote->observed_at->lt(now()->subSeconds(max((int) config('gameplay.snapshot_seconds'), $maxAge ?? 0)))) {
            throw new StaleQuote('Detailed prices at '.$market.' for '.$symbol.' are unknown or stale. Stop and retain any owned cargo.');
        }

        return $good + ['observed_at' => $quote->observed_at->toIso8601String()];
    }

    /** The older buyer quote age a committed arm trade plan accepts, or null for the snapshot age. */
    public static function buyerAge(array $plan): ?int
    {
        $age = $plan['campaign_trade']['buyer_quote_max_age_seconds'] ?? null;

        return is_int($age) && $age > 0 ? $age : null;
    }

    private function observation(Agent $agent, string $kind, string $symbol): ?GameObservation
    {
        return GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', $kind)->where('symbol', $symbol)->orderByDesc('observed_at')->orderByDesc('id')->first();
    }

    private function travel(array $ship, string $destination): AutomationStep
    {
        return $ship['nav']['status'] === 'DOCKED' ? AutomationStep::act('orbit') : AutomationStep::act('navigate', ['waypointSymbol' => $destination]);
    }
}
