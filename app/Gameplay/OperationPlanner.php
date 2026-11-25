<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\CampaignEpisode;
use App\Models\GameObservation;
use App\Models\Ship;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class OperationPlanner
{
    public function __construct(private FuelPlanner $fuel) {}

    public function preview(Agent $agent, array $ship, array $limits, ?CampaignEpisode $episode = null): array
    {
        try {
            return $this->project($agent, $ship, $limits, episode: $episode);
        } catch (GameException $exception) {
            throw ! empty($limits['campaign_trade']['local_earn']) || ! empty($limits['campaign_local_earn']) ? LocalEarnRefusal::planning($exception) : $exception;
        }
    }

    public function project(Agent $agent, array $ship, array $limits, ?array $projectedQuotes = null, ?CampaignEpisode $episode = null): array
    {
        if (($limits['plan_version'] ?? null) === 8 && in_array($limits['strategy'] ?? null, ['trade', 'position', 'siphon', 'survey', 'tender', 'mining', 'canary_transfer'], true)) {
            return $this->projectV8($agent, $ship, $limits, $episode);
        }
        $saturationScout = $episode && isset(CampaignMesh::policy($episode)['saturation']);
        if ($saturationScout) {
            app(CampaignMesh::class)->authorizeScout($episode, $agent, $ship, $limits);
        }
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
        $observe = $limits['observe_kind'] ?? 'market';
        if ($observe !== 'market' && ($observe !== 'shipyard' || ! $oneWay)) {
            throw new GameException('Shipyard observation requires a one-way scout.');
        }
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
            if ($observe === 'shipyard') {
                $point = $this->observation($agent, 'waypoint', $destination)?->data;
                if (($point['systemSymbol'] ?? null) !== $ship['nav']['systemSymbol']
                    || ! in_array('SHIPYARD', array_column($point['traits'] ?? [], 'symbol'), true)) {
                    throw new GameException('A current same-system shipyard observation is required.');
                }
                $route['purpose'] = 'Verify detailed ship offers at '.$destination.'.';
            } else {
                if (! $listing && (! $saturationScout || empty(app(CampaignMesh::class)->model($episode, $agent)->targets[$destination]['provisional']))) {
                    throw new GameException('Fetch the remote goods directory before approving a price-observation visit. Hidden traits require separately approved scanning/charting support.');
                }
                $route['purpose'] = ! $listing ? 'Observe and classify the marketplace at '.$destination.'.' : 'Observe prices of '.implode(', ', array_unique(array_column(array_merge($listing->data['imports'], $listing->data['exports'], $listing->data['exchange']), 'symbol'))).' at '.$destination.' to resolve buyer, trade or fuel comparisons.';
            }
            $route['stop'] = 'One detailed observation, then return home; stop earlier on any limit or safety failure.';
            $route['information_benefit'] = 'Unknown. No chart reward or displaced earnings are assumed.';
        } else {
            $ask = $projectedQuotes['ask'] ?? $this->quote($agent, $home, $limits['symbol']);
            $bid = $projectedQuotes['bid'] ?? $this->quote($agent, $destination, $limits['symbol'], self::buyerAge($limits));
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

    public function projectV8(Agent $agent, array $ship, array $limits, ?CampaignEpisode $episode = null): array
    {
        $now = CarbonImmutable::now();
        $grant = $episode?->grant();
        $policy = CampaignPlaybook::saturationPolicy($grant?->revision()->body ?? []);
        $hard = $episode ? $episode->next_reset_at->toImmutable() : $now->addHours(4);
        if ($grant?->expires_at) {
            $hard = $hard->min($grant->expires_at->toImmutable());
        }
        $settlement = $this->v8Deadline($limits['settlement_deadline'] ?? null, $hard)->min($hard);
        $acquisition = $this->v8Deadline($limits['acquisition_cutoff'] ?? null, $settlement)->min($settlement);
        if ($episode) {
            $acquisition = $acquisition->min($episode->next_reset_at->toImmutable()->subHour());
        }
        if ($acquisition->lte($now) || $ship['nav']['status'] === 'IN_TRANSIT' || $ship['cargo']['units'] !== 0
            || ($ship['nav']['flightMode'] ?? null) !== 'CRUISE') {
            throw new GameException('A v8 operation requires a stationary empty CRUISE hull and time for acquisition.');
        }

        $plan = array_replace($limits, ['plan_version' => 8, 'cycles' => 1, 'site' => $ship['nav']['waypointSymbol'],
            'initial_inventory' => [], 'initial_configuration' => FuelPlanner::configuration($ship),
            'acquisition_cutoff' => $acquisition->toIso8601String(), 'settlement_deadline' => $settlement->toIso8601String(),
            'leg_index' => 0, 'hop_index' => 0]);
        $router = app(RoutePlanner::class);
        $baseReserve = $plan['reserve'] ?? 0;
        if ($plan['strategy'] === 'trade') {
            return $this->projectTradeV8($agent, $ship, $plan, $now, $acquisition, $settlement, $episode);
        }
        if ($plan['strategy'] === 'position') {
            $destination = $limits['destination'] ?? $limits['market'] ?? null;
            if (! $destination || $destination === $plan['site']) {
                throw new GameException('Positioning requires a distinct destination.');
            }
            $drift = $ship;
            $drift['nav']['flightMode'] = 'DRIFT';
            $positionDeadline = $episode ? $settlement->min($episode->next_reset_at->toImmutable()->subHour()) : $settlement;
            $route = match ($limits['mode'] ?? $limits['flight_mode'] ?? 'CRUISE') {
                'DRIFT' => $router->planDrift($agent, $ship, $destination, $positionDeadline, $policy['extraction']['drift_margin_seconds'] ?? 1800),
                'CRUISE' => $this->cruiseRoute($agent, $ship, $destination, $baseReserve, $limits['deadline'] ?? null),
                default => throw new GameException('Unsupported positioning flight mode.'),
            };
            $route['configurations'] ??= ['CRUISE' => FuelPlanner::configuration($ship), 'DRIFT' => FuelPlanner::configuration($drift)];
            $plan['market'] = $destination;
            $plan['position_phase'] = $route['mode'] === 'DRIFT' ? 'switch_to_drift' : 'cruise';
            $this->assertRouteBudget($plan, $route, $route['run_seconds'] ?? ($route['seconds'] + 120), $settlement, $now);

            return array_replace($plan, ['route' => $route, 'configuration_hashes' => $route['configurations'], 'cleanup' => $route['cleanup'] ?? null]);
        }

        $strategy = $plan['strategy'];
        if (! in_array($strategy, ['siphon', 'survey', 'tender', 'mining', 'canary_transfer'], true)) {
            throw new GameException('Unsupported v8 operation strategy.');
        }
        $site = $limits['site'] ?? null;
        $buyer = $limits['buyer'] ?? $limits['market'] ?? null;
        $canary = $limits['canary_pair_id'] ?? null;
        $goods = array_values(array_unique($limits['allowed_goods'] ?? ($strategy === 'siphon' ? ExtractionSites::GAS_GIANT_YIELDS : [])));
        $canaryRole = $limits['canary_role'] ?? 'mining';
        if (! $site || ($strategy !== 'survey' && ! $buyer) || ($strategy === 'canary_transfer' && ! $canary)
            || ($canary && ($goods === [] || ! $buyer || ($limits['max_spend'] ?? 0) > 5000 || ($limits['max_loss'] ?? 0) > 5000
                || ($limits['unit_cap'] ?? 15) < 1 || ($limits['unit_cap'] ?? 15) > 15))) {
            throw new GameException('Extraction or transfer operation exceeds its approved site, buyer, goods or exposure bounds.');
        }
        if ($strategy === 'canary_transfer') {
            $strategy = $canaryRole === 'tender' ? 'tender' : 'mining';
        }
        $out = $this->cruiseRoute($agent, $ship, $site, $baseReserve, $limits['deadline'] ?? null);
        $atSite = $this->projectArrival($ship, $out);
        $in = $buyer ? $this->cruiseRoute($agent, $atSite, $buyer, $baseReserve + $out['refuel_ceiling'], $limits['deadline'] ?? null) : null;
        $hops = [...$out['hops'], ...($in['hops'] ?? [])];
        $quoteAge = $hops[array_key_last($hops)]['continuation_quote_age'] ?? 0;
        $route = ['hops' => $hops, 'approach_hop_count' => count($out['hops']), 'mode' => 'CRUISE',
            'fuel_estimate' => array_sum(array_column($hops, 'burn')),
            'quote_at' => $now->subSeconds($quoteAge)->toIso8601String(),
            'fuel_ceiling' => $out['refuel_ceiling'] + ($in['refuel_ceiling'] ?? 0) + ($in['continuation_ceiling'] ?? $out['continuation_ceiling']),
            'estimated_seconds' => $out['seconds'] + ($in['seconds'] ?? 0), 'delivery_seconds' => ($in['seconds'] ?? 0) + 120,
            'post_sale_refuel_ceiling' => ! empty($limits['refuel_after_sale']) && ($in['continuation'] ?? null) === $buyer ? $in['continuation_ceiling'] : 0];
        $seconds = $route['estimated_seconds'] + 120;
        $this->assertRouteBudget($plan, $route, $seconds, $settlement, $now);
        $latestDeparture = $settlement->subSeconds($route['delivery_seconds'])->min($acquisition);
        if ($now->addSeconds($out['seconds'])->gte($latestDeparture)) {
            throw new GameException('The extraction site cannot be reached before the latest safe departure.');
        }
        if (in_array($strategy, ['mining', 'siphon'], true)) {
            $plan['delivery_seconds'] = $route['delivery_seconds'];
            $plan['effective_acquisition_cutoff'] = $latestDeparture->toIso8601String();
        }

        return array_replace($plan, ['strategy' => $strategy, 'site' => $site, 'market' => $buyer, 'buyer' => $buyer, 'allowed_goods' => $goods,
            'route' => $route, 'canary_pair_id' => $canary, 'unit_cap' => $canary ? ($limits['unit_cap'] ?? 15) : null,
            'exposure_cap' => $canary ? 5000 : null, 'extraction_headroom' => max(1, $limits['extraction_headroom'] ?? $policy['extraction']['headroom_prior_units'] ?? 7),
            'tender_wait_seconds' => min(120, max(1, $limits['tender_wait_seconds'] ?? $policy['extraction']['tender_wait_seconds'] ?? 120)),
            'rendezvous_started_at' => $strategy === 'tender' && $out['hops'] === [] && $ship['nav']['status'] === 'IN_ORBIT' ? $now->toIso8601String() : null,
            'rendezvous_cutoff' => $latestDeparture->toIso8601String()]);
    }

    private function projectTradeV8(Agent $agent, array $ship, array $plan, CarbonImmutable $now, CarbonImmutable $acquisition, CarbonImmutable $settlement, ?CampaignEpisode $episode): array
    {
        $requested = $plan['legs'] ?? [$plan];
        if (! array_is_list($requested) || count($requested) < 1 || count($requested) > 3) {
            throw new GameException('Approve one to three ordered trade legs.');
        }
        $trade = app(CampaignTrade::class);
        $legs = [];
        $seconds = $spend = $fuel = $burn = 0;
        $proof = $episode?->grant()->revision()->body['trade']['proof'] ?? [];
        $reserve = $plan['reserve'] ?? 0;
        $minimumMargin = $this->minimumTradeMarginV8($plan);
        foreach ($requested as $leg) {
            $source = $leg['source'] ?? $leg['site'] ?? $ship['nav']['waypointSymbol'];
            $buyer = $leg['buyer'] ?? $leg['market'] ?? null;
            $good = $leg['good'] ?? $leg['symbol'] ?? null;
            $units = $leg['approvedUnits'] ?? $leg['units'] ?? 0;
            if (! $buyer || $source === $buyer || ! $good || ! is_int($units) || $units < 1 || $units > $ship['cargo']['capacity']) {
                throw new GameException('Each trade leg requires valid distinct markets, a good and bounded units.');
            }
            $startingFuel = $ship['fuel']['current'];
            $approach = $this->cruiseRoute($agent, $ship, $source, $reserve + $fuel, $plan['deadline'] ?? null);
            $delivery = $this->cruiseRoute($agent, $this->projectArrival($ship, $approach), $buyer,
                $reserve + $fuel + $approach['refuel_ceiling'], $plan['deadline'] ?? null);
            $ship = $this->projectArrival($ship, $delivery);
            $buyerAge = max(0, min(900, $leg['buyer_quote_max_age_seconds'] ?? self::buyerAge($plan) ?? 0));
            $ask = $this->quote($agent, $source, $good);
            $bid = $this->quote($agent, $buyer, $good, $buyerAge);
            $batchSize = min($ask['tradeVolume'] ?? 0, $bid['tradeVolume'] ?? 0);
            $mode = $leg['proofMode'] ?? $leg['mode'] ?? ($episode ? $trade->mode($episode, $good, $buyer) : 'proof');
            $maxProofUnits = ($proof['max_units_batches'] ?? 1) * $batchSize;
            $maxProofCost = $proof['max_credits'] ?? 60000;
            if (! empty($plan['canary_family']) || ! empty($plan['j59_canary'])) {
                $maxProofUnits = min($maxProofUnits, $batchSize);
                $maxProofCost = min($maxProofCost, 60000);
            }
            if ($batchSize < 1 || ($ask['purchasePrice'] ?? 0) < 1
                || ($mode === 'proof' && ($units > $maxProofUnits || $units * $ask['purchasePrice'] > $maxProofCost))) {
                throw new GameException('Trade lot exceeds executable volume or proof bounds.');
            }
            $fuelPrice = app(TradeFuelCost::class)->price($agent, [$source, $buyer, ...array_column($approach['hops'], 'origin'), ...array_column($delivery['hops'], 'origin')]);
            $depletion = max(0, $startingFuel - $ship['fuel']['current']);
            $expected = $approach['fuel_cost'] + $delivery['fuel_cost'] + ($depletion === 0 ? 0
                : ($fuelPrice ? TradeFuelCost::consumption($depletion, $fuelPrice) : $delivery['continuation_ceiling']));
            $prediction = $trade->prediction($units, $ask, $bid, (float) ($leg['decay'] ?? $plan['campaign_trade']['decay'] ?? 0), $expected);
            $cost = $prediction['predicted_cost'];
            $unitFloor = max(1, $leg['minSellPrice'] ?? $plan['min_sell_price'] ?? 0);
            $inventory = min($leg['maxInventory'] ?? $plan['max_inventory'] ?? PHP_INT_MAX, $plan['max_loss'] ?? PHP_INT_MAX,
                $mode === 'proof' ? $maxProofCost : PHP_INT_MAX);
            if ($bid['sellPrice'] < $unitFloor || $prediction['predicted_margin'] < $minimumMargin
                || $cost > $inventory) {
                throw new GameException('Trade leg fails approved buyer, profit or inventory bounds.');
            }
            $batches = [];
            for ($remaining = $units; $remaining > 0; $remaining -= $batchSize) {
                $batches[] = ['units' => min($remaining, $batchSize)];
            }
            $hops = [...$approach['hops'], ...$delivery['hops']];
            $approachSeconds = $approach['seconds'] + 10 * ($approach['refuel_batches'] + count($batches)) + 60;
            $deliverySeconds = $delivery['seconds'] + 10 * ($delivery['refuel_batches'] + count($batches)) + 60;
            $deliveryDeadline = $this->v8Deadline($leg['deliveryDeadline'] ?? null, $settlement)->min($settlement);
            $latest = $this->v8Deadline($leg['latestBuyAt'] ?? null, $acquisition)->min($acquisition)->min($deliveryDeadline->subSeconds($deliverySeconds));
            if ($now->addSeconds($seconds + $approachSeconds)->gte($latest)) {
                throw new GameException('The approved lot cannot finish buying before its acquisition cutoff and still settle on time.');
            }
            $seconds += $approachSeconds + $deliverySeconds;
            $ceiling = $approach['refuel_ceiling'] + $delivery['refuel_ceiling'];
            $spend += $cost;
            $fuel += $ceiling;
            $burn += array_sum(array_column($hops, 'burn'));
            $legs[] = array_replace($leg, ['source' => $source, 'buyer' => $buyer, 'good' => $good, 'approvedUnits' => $units,
                'buyBatches' => $batches, 'sellBatches' => $batches, 'quoteEvidence' => ['ask' => $ask, 'bid' => $bid, 'buyer_max_age' => $buyerAge],
                'proofMode' => $mode, 'hops' => $hops, 'approachHopCount' => count($approach['hops']),
                'expectedFuelCost' => $expected, 'fuelUnitPrice' => $fuelPrice, 'totalFuelCeiling' => $ceiling, 'remainingFuelCeiling' => $ceiling,
                'deliveryFuelCeiling' => $delivery['refuel_ceiling'], 'continuationFuelCeiling' => $delivery['continuation_ceiling'],
                'deliveryDeadline' => $deliveryDeadline->toIso8601String(), 'latestBuyAt' => $latest->toIso8601String(),
                'minSellPrice' => $unitFloor, 'maxInventory' => $inventory, 'prediction' => $prediction,
                'deliverySeconds' => $deliverySeconds, 'estimatedSeconds' => $approachSeconds + $deliverySeconds]);
        }
        // The following leg funds its own departure. Hold the unrepresented terminal continuation once.
        $last = array_key_last($legs);
        $legs[$last]['totalFuelCeiling'] += $legs[$last]['continuationFuelCeiling'];
        $legs[$last]['remainingFuelCeiling'] += $legs[$last]['continuationFuelCeiling'];
        $legs[$last]['deliveryFuelCeiling'] += $legs[$last]['continuationFuelCeiling'];
        $costs = app(TradeFuelCost::class)->chainEstimate($legs);
        $route = $costs + ['fuel_ceiling' => $costs['total_refuel_ceiling'], 'fuel_estimate' => $burn, 'estimated_seconds' => $seconds,
            'hops' => array_merge(...array_column($legs, 'hops'))];
        $this->assertRouteBudget($plan, $route, $seconds, $settlement, $now);
        if ($spend + $route['fuel_ceiling'] > ($plan['max_spend'] ?? 0) || $agent->credits < $reserve + $spend + $route['fuel_ceiling']) {
            throw new GameException('The ordered lots and delivery fuel exceed approved spending or available funds.');
        }
        $first = $legs[0];

        return array_replace($plan, $costs, ['legs' => $legs, 'site' => $first['source'], 'market' => $first['buyer'],
            'symbol' => $first['good'], 'units' => $first['approvedUnits'], 'route' => $route]);
    }

    public function cruiseRoute(Agent $agent, array $ship, string $destination, int $reserve = 0, ?float $deadline = null): array
    {
        if ($ship['nav']['waypointSymbol'] === $destination && $ship['nav']['flightMode'] === 'CRUISE') {
            return ['origin' => $destination, 'destination' => $destination, 'mode' => 'CRUISE', 'hops' => [], 'path' => [$destination],
                'configuration' => FuelPlanner::configuration($ship),
                'fuel_after' => $ship['fuel']['current'], 'seconds' => 0, 'estimated_seconds' => 0, 'fuel_estimate' => 0,
                'refuel_batches' => 0, 'refuel_ceiling' => 0, 'continuation_ceiling' => 0, 'fuel_ceiling' => 0, 'fuel_cost' => 0];
        }
        $route = app(RoutePlanner::class)->planCruise($agent, $ship, $destination, $reserve, deadline: $deadline);
        $route['hops'] = array_map(fn (array $hop) => $hop + ['mode' => 'CRUISE', 'configuration' => $route['configuration'],
            'uncertainty_reserve' => $route['uncertainty_reserve']], $route['hops']);
        $route['fuel_estimate'] = array_sum(array_column($route['hops'], 'burn'));
        $route['fuel_ceiling'] = $route['refuel_ceiling'] + $route['continuation_ceiling'];

        return $route;
    }

    private function projectArrival(array $ship, array $route): array
    {
        $ship['nav']['waypointSymbol'] = $route['destination'];
        $ship['fuel']['current'] = $route['fuel_after'];

        return $ship;
    }

    private function assertRouteBudget(array $plan, array $route, int $seconds, CarbonImmutable $settlement, CarbonImmutable $now): void
    {
        $ceiling = $route['fuel_ceiling'] ?? 0;
        if (($route['fuel_estimate'] ?? $route['outbound_fuel'] ?? 0) > ($plan['max_fuel'] ?? PHP_INT_MAX)
            || $ceiling > min($plan['max_spend'] ?? 0, $plan['max_loss'] ?? 0)
            || $now->addSeconds($seconds)->gt($settlement)) {
            throw new GameException('Travel cannot finish within the approved fuel, spending, loss and time bounds.');
        }
    }

    private function v8Deadline(string|int|CarbonInterface|null $value, CarbonImmutable $default): CarbonImmutable
    {
        return match (true) {
            $value === null => $default,
            is_int($value) => CarbonImmutable::createFromTimestampUTC($value),
            default => CarbonImmutable::parse($value),
        };
    }

    public function next(Agent $agent, array $ship, array $plan, array $totals, CarbonImmutable $now): AutomationStep
    {
        if ((($plan['plan_version'] ?? null) === 8 && ($plan['strategy'] ?? null) !== 'scout') || isset($plan['legs'])
            || in_array($plan['strategy'] ?? null, ['position', 'siphon', 'survey', 'tender', 'canary_transfer'], true)) {
            return $this->nextV8($agent, $ship, $plan, $totals, $now);
        }
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
            if (($plan['observe_kind'] ?? 'market') === 'shipyard') {
                $yard = $this->observation($agent, 'shipyard', $destination);
                $detailed = collect($yard?->data['ships'] ?? [])->contains(fn ($offer) => is_array($offer)
                    && isset($offer['type'], $offer['purchasePrice']) && ShipCommandExecutor::configurationHash($offer) !== null);

                return $yard && $yard->observed_at->gte($now->subSeconds(5)) && $detailed
                    ? AutomationStep::trip()
                    : AutomationStep::wait($now->addSeconds(5));
            }
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

    private function nextV8(Agent $agent, array $ship, array $plan, array $totals, CarbonImmutable $now): AutomationStep
    {
        if ($ship['nav']['status'] === 'IN_TRANSIT') {
            $arrival = CarbonImmutable::parse($ship['nav']['route']['arrival']);

            return AutomationStep::wait($arrival->gt($now) ? $arrival : $now->addSeconds(5));
        }

        return match ($plan['strategy']) {
            'trade' => $this->nextTradeV8($agent, $ship, $plan, $totals, $now),
            'position' => $this->nextPosition($agent, $ship, $plan, $totals, $now),
            'siphon', 'survey', 'tender', 'mining', 'canary_transfer' => $this->nextExtractionV8($agent, $ship, $plan, $totals, $now),
            default => AutomationStep::blocked('No v8 stepper is authorized for this strategy.'),
        };
    }

    private function nextTradeV8(Agent $agent, array $ship, array $plan, array $totals, CarbonImmutable $now): AutomationStep
    {
        $closed = $now->gte($this->v8Deadline($plan['acquisition_cutoff'] ?? null, $now->addSecond()));
        $index = $totals['leg_index'] ?? $plan['leg_index'] ?? 0;
        $cargo = MiningPlanner::inventory($ship);
        $leg = $plan['legs'][$index] ?? null;
        if (! $leg) {
            return $cargo ? AutomationStep::blocked('Cargo remains after the final approved leg.') : AutomationStep::trip();
        }

        $good = $leg['good'];
        $bought = $totals['leg_purchased'][$index] ?? 0;
        $sold = $totals['leg_sold'][$index] ?? 0;
        $owned = max(0, $bought - $sold);
        if ($cargo !== ($owned > 0 ? [$good => $owned] : [])) {
            return AutomationStep::blocked('Unload or reconcile the previous lot before starting a new trade leg.');
        }
        $deliveryDeadline = $this->v8Deadline($leg['deliveryDeadline'] ?? $plan['settlement_deadline'] ?? null, $now->addSecond());
        $deliveryExpired = $now->gte($deliveryDeadline);
        $legClosed = $deliveryExpired || ! empty($totals['leg_acquisition_closed'][$index]) || ($totals['phase'] ?? null) === 'deliver'
            || $now->gte($this->v8Deadline($leg['latestBuyAt'] ?? null, $now->addSecond()));
        if (($closed || $legClosed) && $owned === 0) {
            if ($closed || ! isset($plan['legs'][$index + 1])) {
                return AutomationStep::trip();
            }
            // An unsent closed leg has no cargo to settle. Preserve the next leg's own timing and route.
            $totals = array_replace($totals, ['leg_index' => $index + 1, 'hop_index' => 0, 'phase' => 'buy']);

            return $this->nextTradeV8($agent, $ship, $plan, $totals, $now);
        }
        $delivery = $closed || $legClosed || $bought >= $leg['approvedUnits'];
        if ($deliveryExpired) {
            return AutomationStep::blocked('Delivery deadline reached. Retain attributable cargo for authorized recovery.');
        }
        $here = $ship['nav']['waypointSymbol'];
        $hopIndex = $totals['hop_index'] ?? $plan['hop_index'] ?? 0;
        $hops = $leg['hops'] ?? [];
        $approach = $leg['approachHopCount'] ?? 0;
        $cost = $totals['leg_purchase_cost'][$index] ?? 0;
        $fuelRoom = max(0, ($leg['remainingFuelCeiling'] ?? 0) - ($totals['leg_fuel_cost'][$index] ?? 0));
        $hold = max(0, ($plan['route']['fuel_ceiling'] ?? $fuelRoom) - ($totals['fuel_cost'] ?? 0));
        $remainingSpend = ($plan['max_spend'] ?? PHP_INT_MAX) - ($totals['spending'] ?? 0);
        $intent = ['leg_index' => $index];
        $minimumMargin = $this->minimumTradeMarginV8($plan);
        if ($delivery) {
            if ($here !== $leg['buyer'] || $hopIndex < count($hops)) {
                return $this->nextPinnedHop($agent, $ship, $hops, max($hopIndex, $approach), min($fuelRoom, $remainingSpend),
                    $plan['reserve'] ?? 0, $totals['leg_hop_fuel_cost'][$index] ?? [], $intent + ['phase' => 'deliver']);
            }
            if ($ship['nav']['status'] !== 'DOCKED') {
                return AutomationStep::act('dock', $intent + ['phase' => 'deliver']);
            }
            $bid = $this->executionQuote($agent, $ship, $leg['buyer'], $good);
            $protectedCost = max(0, $cost - max(0, -$minimumMargin));
            $floor = max($leg['minSellPrice'] ?? 1, (int) ceil($protectedCost / max(1, $bought)));
            $units = min($owned, $bid['tradeVolume'] ?? 0, $this->v8BatchRemaining($leg['sellBatches'] ?? [], $sold, $owned));
            if ($units < 1 || $bid['sellPrice'] < $floor) {
                return AutomationStep::blocked('Pinned buyer is below the approved floor or has no volume. Retain cargo for recovery.');
            }

            return AutomationStep::act('sell', ['symbol' => $good, 'units' => $units, 'minTotal' => $units * $floor, 'phase' => 'deliver'] + $intent);
        }
        if ($here !== $leg['source'] || $hopIndex < $approach) {
            if ($hopIndex >= $approach) {
                return AutomationStep::blocked('The ship is outside the approved trade approach.');
            }

            return $this->nextPinnedHop($agent, $ship, $hops, $hopIndex, min($fuelRoom, $remainingSpend),
                $plan['reserve'] ?? 0, $totals['leg_hop_fuel_cost'][$index] ?? [], $intent);
        }
        if ($ship['nav']['status'] !== 'DOCKED') {
            return AutomationStep::act('dock', $intent);
        }

        $ask = $this->executionQuote($agent, $ship, $leg['source'], $good);
        $bid = $this->executionQuote($agent, $ship, $leg['buyer'], $good, $leg['quoteEvidence']['buyer_max_age'] ?? null);
        $remaining = $leg['approvedUnits'] - $bought;
        if ($ask['purchasePrice'] < 1 || ($bid['tradeVolume'] ?? 0) < 1 || $bid['sellPrice'] < ($leg['minSellPrice'] ?? 1)
            || $minimumMargin > $leg['approvedUnits'] * $bid['sellPrice'] - $cost - $remaining * $ask['purchasePrice'] - ($leg['expectedFuelCost'] ?? 0)) {
            return AutomationStep::blocked('The current leg no longer clears its approved spread and buyer floor.');
        }
        $reserve = ($plan['reserve'] ?? 0) + $hold;
        $room = min(($leg['maxInventory'] ?? $plan['max_inventory'] ?? PHP_INT_MAX) - $cost, $remainingSpend - $hold, $agent->credits - $reserve);
        $units = min($remaining, $ship['cargo']['capacity'] - $ship['cargo']['units'], $ask['tradeVolume'] ?? 0, $bid['tradeVolume'],
            intdiv(max(0, $room), $ask['purchasePrice']), $this->v8BatchRemaining($leg['buyBatches'] ?? [], $bought, $remaining));
        if ($units < 1) {
            return AutomationStep::blocked('No purchase fits the current leg cash, lot and cargo bounds.');
        }

        return AutomationStep::act('buy', ['symbol' => $good, 'units' => $units, 'maxTotal' => $units * $ask['purchasePrice'], 'reserve' => $plan['reserve'] ?? 0] + $intent);
    }

    private function minimumTradeMarginV8(array $plan): int
    {
        $loss = (int) ($plan['stimulus']['loss_auth'] ?? 0);

        return $loss > 0 && count($plan['legs'] ?? [$plan]) === 1 ? -$loss : max(1, $plan['min_margin'] ?? 0);
    }

    private function v8BatchRemaining(array $batches, int $confirmed, int $fallback): int
    {
        foreach ($batches as $batch) {
            $size = is_array($batch) ? ($batch['units'] ?? 0) : $batch;
            if ($confirmed < $size) {
                return $size - $confirmed;
            }
            $confirmed -= $size;
        }

        return $batches === [] ? $fallback : 0;
    }

    public function nextPinnedHop(Agent $agent, array $ship, array $hops, int $index, int $ceiling, int $reserve, array $hopSpend = [], array $intent = []): AutomationStep
    {
        $hop = $hops[$index] ?? null;
        if (! $hop || $ship['nav']['waypointSymbol'] !== $hop['origin']
            || $ship['nav']['flightMode'] !== ($hop['mode'] ?? 'CRUISE')
            || (isset($hop['configuration']) && FuelPlanner::configuration($ship) !== $hop['configuration'])) {
            return AutomationStep::blocked('The physical ship state no longer matches the approved route hop.');
        }
        $intent += ['hop_index' => $index];
        $fuelNow = $ship['fuel']['current'];
        if (($hop['topped_up'] ?? 0) > 0 && $fuelNow < $hop['fuel_before']) {
            if ($ship['nav']['status'] !== 'DOCKED') {
                return AutomationStep::act('dock', $intent);
            }
            $fuel = $this->executionQuote($agent, $ship, $hop['origin'], 'FUEL');
            $units = min($ship['fuel']['capacity'] - $fuelNow, $hop['fuel_before'] - $fuelNow, ($fuel['tradeVolume'] ?? 0) * 100);
            $ceiling = min($ceiling, ($hop['refuel_ceiling'] ?? 0) - ($hopSpend[$index] ?? 0), $agent->credits - $reserve);
            if ($units < 1 || $fuel['purchasePrice'] < 1 || TradeFuelCost::consumption($units, $fuel['purchasePrice']) > $ceiling) {
                return AutomationStep::blocked('Refuel no longer fits its approved hop ceiling and reserve.');
            }

            return AutomationStep::act('refuel', ['units' => $units, 'maxTotal' => $ceiling, 'reserve' => $reserve] + $intent);
        }
        if ($fuelNow < $hop['burn'] + ($hop['continuation_fuel'] ?? 0) + ($hop['uncertainty_reserve'] ?? ($ship['fuel']['capacity'] > 0 ? 2 : 0))
            || $agent->credits - $reserve < ($hop['continuation_ceiling'] ?? 0)) {
            return AutomationStep::blocked('The pinned hop no longer preserves its fuel-safe continuation.');
        }

        return $ship['nav']['status'] === 'DOCKED' ? AutomationStep::act('orbit', $intent)
            : AutomationStep::act('navigate', ['waypointSymbol' => $hop['destination']] + $intent);
    }

    private function nextPosition(Agent $agent, array $ship, array $plan, array $totals, CarbonImmutable $now): AutomationStep
    {
        if ($ship['cargo']['units'] !== 0) {
            return AutomationStep::blocked('Positioning requires an empty hold.');
        }
        $route = $plan['route'];
        $here = $ship['nav']['waypointSymbol'];
        $mode = $ship['nav']['flightMode'];
        $pin = $route['configurations'][$mode] ?? $plan['configuration_hashes'][$mode] ?? null;
        if ($pin && FuelPlanner::configuration($ship) !== $pin) {
            return AutomationStep::blocked('Positioning ship configuration changed.');
        }
        if ($route['mode'] === 'CRUISE') {
            return $here === $route['destination'] ? AutomationStep::trip()
                : $this->nextPinnedHop($agent, $ship, $route['hops'], $totals['hop_index'] ?? 0,
                    $route['fuel_ceiling'] - ($totals['fuel_cost'] ?? 0), $plan['reserve'] ?? 0, $totals['hop_fuel_cost'] ?? []);
        }
        $cleanup = $route['cleanup'] ?? $plan['cleanup'] ?? null;
        $safeBy = $this->v8Deadline($route['hard_deadline'] ?? null, $now)->subSeconds($route['margin_seconds'] ?? 1800);
        $refused = in_array($totals['position_phase'] ?? null, ['cleanup', 'navigate_refused', 'restore_cruise'], true)
            || ! empty($totals['position_navigation_refused']);
        if ($mode === 'DRIFT' && ($here === $route['destination'] || $refused || $now->addSeconds($route['seconds'])->gt($safeBy))) {
            return ($cleanup['mode'] ?? null) === 'CRUISE' && $now->lt($this->v8Deadline($cleanup['expires_at'] ?? null, $now))
                ? AutomationStep::act('flight_mode', ['flightMode' => 'CRUISE'])
                : AutomationStep::blocked('Approved CRUISE restoration authority is unavailable.');
        }
        if ($mode === 'CRUISE' && ($here === $route['destination'] || ($totals['position_phase'] ?? null) === 'complete')) {
            return AutomationStep::trip();
        }
        if ($here !== $route['origin'] || $now->addSeconds($route['seconds'])->gt($safeBy) || ! in_array($mode, ['DRIFT', 'CRUISE'], true)
            || $ship['fuel']['current'] < 1 + $route['uncertainty_reserve']) {
            return AutomationStep::blocked('The DRIFT origin, fuel reserve or arrival bound no longer qualifies.');
        }
        if ($mode === 'CRUISE') {
            return AutomationStep::act('flight_mode', ['flightMode' => 'DRIFT']);
        }

        return $ship['nav']['status'] === 'DOCKED' ? AutomationStep::act('orbit') : AutomationStep::act('navigate', ['waypointSymbol' => $route['destination']]);
    }

    private function nextExtractionV8(Agent $agent, array $ship, array $plan, array $totals, CarbonImmutable $now): AutomationStep
    {
        $route = $plan['route'];
        $strategy = $plan['strategy'];
        $tender = $strategy === 'tender';
        $survey = $strategy === 'survey';
        $here = $ship['nav']['waypointSymbol'];
        $site = $plan['site'];
        $buyer = $plan['buyer'] ?? $plan['market'] ?? null;
        $hopIndex = $totals['hop_index'] ?? $plan['hop_index'] ?? 0;
        $approach = $route['approach_hop_count'] ?? 0;
        $cutoff = $this->v8Deadline($plan['acquisition_cutoff'] ?? null, $now->addSecond());
        $settlement = $this->v8Deadline($plan['settlement_deadline'] ?? null, $cutoff);
        $cutoff = $this->v8Deadline($plan['effective_acquisition_cutoff'] ?? null,
            $cutoff->min($settlement->subSeconds($route['delivery_seconds'] ?? 0)));
        $held = MiningPlanner::inventory($ship);
        $allowed = $plan['allowed_goods'] ?? [];
        if ($allowed && array_diff(array_keys($held), $allowed)) {
            return AutomationStep::blocked('Owned cargo includes goods outside this operation approval.');
        }
        if ($tender && $this->tenderLeaseBlocks($agent, $ship['symbol'], $now)) {
            return AutomationStep::wait($now->addSeconds(5));
        }
        $free = min($ship['cargo']['capacity'], $plan['unit_cap'] ?? PHP_INT_MAX) - $ship['cargo']['units'];
        $headroom = $plan['extraction_headroom'] ?? 7;
        $phase = $totals['phase'] ?? null;
        $delivery = in_array($phase, ['sell', 'deliver'], true) || $hopIndex > $approach || $now->gte($cutoff)
            || (! $survey && $free < ($tender ? 1 : $headroom))
            || ($tender && ! empty($plan['canary_pair_id']) && array_sum($totals['transferred_in'] ?? []) > 0);
        if ($tender && $here === $site && $ship['nav']['status'] === 'IN_ORBIT') {
            $opened = $totals['rendezvous_started_at'] ?? $plan['rendezvous_started_at'] ?? $plan['rendezvous_opened_at'] ?? $now->toIso8601String();
            $rendezvous = $this->v8Deadline($plan['rendezvous_cutoff'] ?? null, $cutoff)->min($cutoff);
            if ($opened) {
                $rendezvous = $rendezvous->min(CarbonImmutable::parse($opened)->addSeconds($plan['tender_wait_seconds'] ?? 120));
            }
            $cutoff = $rendezvous;
            $delivery = $delivery || $now->gte($rendezvous);
        }
        $fuelRoom = min(($route['fuel_ceiling'] ?? 0) - ($totals['fuel_cost'] ?? 0),
            ($plan['max_spend'] ?? PHP_INT_MAX) - ($totals['spending'] ?? 0));
        if ($delivery) {
            if ($held === []) {
                if ($buyer === $here && ! empty($totals['sold']) && $now->lt($cutoff) && ($route['post_sale_refuel_ceiling'] ?? 0) > 0) {
                    return $this->settlementRefuel($agent, $ship, $plan, $totals, $fuelRoom);
                }

                return AutomationStep::trip();
            }
            if (! $buyer || $now->gte($settlement)) {
                return AutomationStep::blocked('Delivery requires an authorized buyer and remaining settlement time.');
            }
            if ($here !== $buyer || $hopIndex < count($route['hops'])) {
                foreach (array_keys($held) as $good) {
                    $bid = $this->executionQuote($agent, $ship, $buyer, $good);
                    if (($bid['sellPrice'] ?? 0) < 1 || ($bid['tradeVolume'] ?? 0) < 1) {
                        return AutomationStep::blocked('Pinned buyer cannot receive all held goods.');
                    }
                }

                return $this->nextPinnedHop($agent, $ship, $route['hops'], max($hopIndex, $approach), $fuelRoom,
                    $plan['reserve'] ?? 0, $totals['hop_fuel_cost'] ?? [], ['phase' => 'deliver']);
            }
            if ($ship['nav']['status'] !== 'DOCKED') {
                return AutomationStep::act('dock', ['phase' => 'deliver']);
            }
            $good = array_key_first($held);
            $quote = $this->executionQuote($agent, $ship, $buyer, $good);
            $units = min($held[$good], $quote['tradeVolume'] ?? 0);
            $floor = max(1, $plan['min_sell_price'] ?? 1);
            if ($units < 1 || $quote['sellPrice'] < $floor) {
                return AutomationStep::blocked('The pinned buyer no longer clears the cargo sale floor.');
            }

            return AutomationStep::act('sell', ['symbol' => $good, 'units' => $units, 'minTotal' => $units * $floor]);
        }
        if ($here !== $site || $hopIndex < $approach) {
            return $this->nextPinnedHop($agent, $ship, $route['hops'], $hopIndex, $fuelRoom,
                $plan['reserve'] ?? 0, $totals['hop_fuel_cost'] ?? []);
        }
        if ($ship['nav']['status'] === 'DOCKED') {
            return AutomationStep::act('orbit');
        }
        if ($tender) {
            return AutomationStep::wait(min($cutoff, $now->addSeconds(5)));
        }
        $ready = $ship['cooldown']['readyAt'] ?? $ship['cooldown']['ready_at'] ?? $ship['cooldown']['expiration'] ?? null;
        if ($ready && CarbonImmutable::parse($ready)->gt($now)) {
            return AutomationStep::wait(min($cutoff, CarbonImmutable::parse($ready)));
        }
        if ($survey) {
            return $this->nextSurvey($agent, $plan, $totals, $now);
        }
        if ($held === []) {
            foreach ($allowed as $good) {
                $bid = $this->executionQuote($agent, $ship, $buyer, $good);
                if (($bid['sellPrice'] ?? 0) < 1 || ($bid['tradeVolume'] ?? 0) < 1) {
                    return AutomationStep::blocked('The pinned buyer lacks executable bid coverage for this load.');
                }
            }
        }

        if ($strategy === 'siphon') {
            return AutomationStep::act('siphon');
        }
        $signature = $plan['survey_signature'] ?? null;
        if ($signature && empty($totals['survey_fallback_unsurveyed'])) {
            try {
                app(SurveyPool::class)->usable($agent, $signature, $site);

                return AutomationStep::act('mine_survey', ['surveySignature' => $signature, 'surveyWaypoint' => $site]);
            } catch (GameException) {
                // The survey pool may have invalidated the approved signature since the last step.
            }
        }

        return AutomationStep::act('mine');
    }

    private function tenderLeaseBlocks(Agent $agent, string $ship, CarbonImmutable $now): bool
    {
        $cluster = app(MiningCluster::class);
        foreach ($cluster->activeLeasesForShip($agent, $ship) as $lease) {
            if ($lease->state !== 'granted' || $now->lt(CarbonImmutable::parse($lease->expires_at, 'UTC'))) {
                return true;
            }
            $settled = $cluster->settleLease($agent, $lease->id, false, false, now: $now);
            if ($settled['state'] !== 'expired') {
                return true;
            }
        }

        return false;
    }

    private function settlementRefuel(Agent $agent, array $ship, array $plan, array $totals, int $fuelRoom): AutomationStep
    {
        $missing = $ship['fuel']['capacity'] - $ship['fuel']['current'];
        if ($missing < 1 || $fuelRoom < 1) {
            return AutomationStep::trip();
        }
        $market = $ship['nav']['waypointSymbol'];
        $fuel = $this->executionQuote($agent, $ship, $market, 'FUEL');
        $units = min($missing, ($fuel['tradeVolume'] ?? 0) * 100);
        $reserve = $plan['reserve'] ?? 0;
        $spent = $totals['hop_fuel_cost'][count($plan['route']['hops'])] ?? 0;
        $ceiling = min($fuelRoom, $plan['route']['post_sale_refuel_ceiling'] - $spent, $agent->credits - $reserve);
        if ($units < 1 || $fuel['purchasePrice'] < 1 || TradeFuelCost::consumption($units, $fuel['purchasePrice']) > $ceiling) {
            return AutomationStep::trip();
        }

        return $ship['nav']['status'] !== 'DOCKED' ? AutomationStep::act('dock')
            : AutomationStep::act('refuel', ['units' => $units, 'maxTotal' => $ceiling, 'reserve' => $reserve]);
    }

    private function nextSurvey(Agent $agent, array $plan, array $totals, CarbonImmutable $now): AutomationStep
    {
        $site = $plan['site'];
        $atSite = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'survey')
            ->where('data->signed->symbol', $site)->get();
        $stock = $atSite->filter(fn (GameObservation $row) => empty($row->data['exhausted']) && empty($row->data['unavailable_reason'])
            && isset($row->data['signed']['expiration']) && $now->lt($row->data['signed']['expiration'])
            && (! isset($plan['allowed_goods']) || $plan['allowed_goods'] === []
                || array_diff(array_column($row->data['signed']['deposits'], 'symbol'), $plan['allowed_goods']) === []));
        $usesPerSurvey = $plan['usable_attempts_per_survey'] ?? 3;
        $uses = $atSite->isEmpty()
            ? ($plan['remaining_compatible_uses'] ?? 0) + ($totals['surveys_created'] ?? 0) * $usesPerSurvey
            : $stock->count() * $usesPerSurvey;
        $demand = app(SurveyBroker::class)->creationDemand($plan['active_miners'] ?? 0, $plan['mining_cooldown_seconds'] ?? 80,
            $uses, $usesPerSurvey, $plan['surveys_per_action'] ?? 1);

        return $demand['writes_needed'] > 0 ? AutomationStep::act('survey') : AutomationStep::trip();
    }

    public function executionQuote(Agent $agent, array $ship, string $market, string $symbol, ?int $maxAge = null): array
    {
        try {
            return $this->quote($agent, $market, $symbol, $maxAge);
        } catch (StaleQuote $exception) {
            $observer = $ship['nav']['waypointSymbol'] === $market && $ship['nav']['status'] !== 'IN_TRANSIT' ? $ship['symbol'] : null;
            $observer ??= Ship::where('agent_id', $agent->id)->where('snapshot->nav->waypointSymbol', $market)
                ->whereIn('snapshot->nav->status', ['DOCKED', 'IN_ORBIT'])->orderBy('symbol')->value('symbol');
            if (! $observer) {
                throw $exception;
            }
            $prices = app(SpaceTradersApi::class)->market($agent, $market, fresh: true);
            app(AgentSynchronizer::class)->observe($agent, 'market', $market, $prices, observer: $observer);

            return $this->quote($agent, $market, $symbol, $maxAge);
        }
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
