<?php

namespace App\Gameplay;

use Carbon\CarbonImmutable;

class ContractPlanner
{
    /** Compile one finite itinerary from supplied observations. No IO or game mutation. */
    public function plan(array $ship, array $contract, array $points, array $markets, ?string $source, bool $useCargo, CarbonImmutable $now): array
    {
        if ($contract['type'] !== 'PROCUREMENT' || $contract['fulfilled']) {
            throw new GameException('Only unfulfilled procurement delivery contracts are supported.');
        }
        if ($ship['nav']['status'] === 'IN_TRANSIT' || $ship['nav']['flightMode'] !== 'CRUISE') {
            throw new GameException('A stationary ship in CRUISE mode is required.');
        }
        foreach (['frame', 'engine', 'reactor'] as $part) {
            if (($ship[$part]['condition'] ?? 0) < 0.5 || ($ship[$part]['integrity'] ?? 0) < 0.5) {
                throw new GameException('Ship condition or integrity is below the operating floor.');
            }
        }
        if ($ship['crew']['current'] < $ship['crew']['required']) {
            throw new GameException('Insufficient crew.');
        }
        $terms = $contract['terms']['deliver'];
        $keys = array_map(fn ($t) => $t['tradeSymbol'].'|'.$t['destinationSymbol'], $terms);
        if (count(array_unique($keys)) !== count($keys)) {
            throw new GameException('Duplicate delivery terms are unsupported.');
        }
        if (! $contract['accepted'] && $now->gte($contract['deadlineToAccept'] ?? $contract['expiration'])) {
            throw new GameException('The acceptance deadline has passed.');
        }
        $inventory = MiningPlanner::inventory($ship);
        $protected = $inventory;
        $approvedCargo = [];
        if ($useCargo) {
            foreach ($terms as $term) {
                $good = $term['tradeSymbol'];
                $units = min($protected[$good] ?? 0, $term['unitsRequired'] - $term['unitsFulfilled']);
                if ($units > 0) {
                    $approvedCargo[$good] = ($approvedCargo[$good] ?? 0) + $units;
                    $protected[$good] -= $units;
                }
            }
        }
        $protected = array_filter($protected);
        $location = $ship['nav']['waypointSymbol'];
        $status = $ship['nav']['status'];
        $system = $ship['nav']['systemSymbol'];
        $actions = [];
        $fuel = 0;
        $travelSeconds = 0;
        $cost = 0;
        $deliveries = 0;
        $purchases = 0;
        $directSale = 0;
        $directSaleKnown = true;
        $expected = $contract;
        $append = function (string $command, array $args = []) use (&$actions, &$expected, $contract) {
            $actions[] = ['command' => $command, 'arguments' => $args, 'target_type' => str_starts_with($command, 'contract_') ? 'contract' : 'ship',
                'target_id' => str_starts_with($command, 'contract_') ? $contract['id'] : null, 'contract_before' => $expected];
        };
        $move = function (string $destination) use (&$location, &$status, &$fuel, &$travelSeconds, $points, $system, $append, $ship) {
            $from = $points[$location] ?? null;
            $to = $points[$destination] ?? null;
            if (! $from || ! $to || $to['systemSymbol'] !== $system || $from['systemSymbol'] !== $system) {
                throw new GameException('Every destination and supply route must be verified in the selected system.');
            }
            if (array_intersect(array_column($to['traits'], 'symbol'), ['UNCHARTED', 'UNSTABLE', 'DEBRIS_FIELD'])) {
                throw new GameException('The route includes an unverified or hazardous destination.');
            }
            if ($location !== $destination) {
                if ($status === 'DOCKED') {
                    $append('orbit');
                }
                $append('navigate', ['waypointSymbol' => $destination]);
                $distance = MiningPlanner::fuelEstimate($from, $to);
                $fuel += $distance;
                $speed = $ship['engine']['speed'] ?? null;
                if (! is_int($speed) || $speed < 1) {
                    throw new GameException('Engine speed is missing; travel duration cannot be estimated.');
                }
                // Conservative CRUISE estimate, with a separate scheduling allowance below.
                $travelSeconds += (int) ceil(15 + 25 * max(1, $distance) / $speed);
                $location = $destination;
                $status = 'IN_ORBIT';
            }
            if ($status !== 'DOCKED') {
                $append('dock');
                $status = 'DOCKED';
            }
        };
        if (! $contract['accepted']) {
            $append('contract_accept', ['contractId' => $contract['id']]);
            $expected['accepted'] = true;
        }
        foreach ($terms as $index => $term) {
            $remaining = $term['unitsRequired'] - $term['unitsFulfilled'];
            $good = $term['tradeSymbol'];
            $included = min($remaining, ($inventory[$good] ?? 0) - ($protected[$good] ?? 0));
            if ($included > 0) {
                $move($term['destinationSymbol']);
                $append('contract_deliver', ['contractId' => $contract['id'], 'symbol' => $good, 'units' => $included, 'term_index' => $index]);
                $expected['terms']['deliver'][$index]['unitsFulfilled'] += $included;
                $inventory[$good] -= $included;
                $remaining -= $included;
                $deliveries++;
                $quote = $markets[$source]['tradeGoods'] ?? [];
                $sale = collect($quote)->firstWhere('symbol', $good)['sellPrice'] ?? null;
                $directSaleKnown = $directSaleKnown && is_int($sale);
                $directSale += $included * ($sale ?? 0);
            }
            while ($remaining > 0) {
                if (! $source || ! isset($markets[$source])) {
                    throw new GameException('Needs scouting. No verified purchase quote for remaining goods.');
                }
                $move($source);
                $quote = collect($markets[$source]['tradeGoods'] ?? [])->firstWhere('symbol', $good);
                if (! $quote || $quote['purchasePrice'] < 1) {
                    throw new GameException('Needs scouting. Purchase price or supply is unknown.');
                }
                $free = $ship['cargo']['capacity'] - array_sum($inventory);
                if ($free < 1) {
                    throw new GameException('Protected cargo leaves no room for the approved goods.');
                }
                $load = min($remaining, $free);
                for ($left = $load; $left > 0;) {
                    $batch = min($left, $quote['tradeVolume']);
                    $price = $batch * $quote['purchasePrice'];
                    $append('buy', ['symbol' => $good, 'units' => $batch, 'maxTotal' => $price]);
                    $cost += $price;
                    $inventory[$good] = ($inventory[$good] ?? 0) + $batch;
                    $left -= $batch;
                    $purchases++;
                    if ($purchases > 40 || count($actions) > 150) {
                        throw new GameException('Unsupported: remaining work exceeds 40 purchase batches or 150 actions.');
                    }
                }
                $move($term['destinationSymbol']);
                $append('contract_deliver', ['contractId' => $contract['id'], 'symbol' => $good, 'units' => $load, 'term_index' => $index]);
                $expected['terms']['deliver'][$index]['unitsFulfilled'] += $load;
                $inventory[$good] -= $load;
                $remaining -= $load;
                $deliveries++;
                if ($deliveries > 10) {
                    throw new GameException('Unsupported: more than ten deliveries are required.');
                }
            }
        }
        $append('contract_fulfill', ['contractId' => $contract['id']]);
        if ($fuel > $ship['fuel']['current']) {
            throw new GameException('Needs preparation. Refuel separately; the entire itinerary must fit observed fuel.');
        }
        $allowance = 120 + count($actions) * 5;
        $seconds = $travelSeconds + $allowance;
        if ($now->addSeconds($seconds)->gte($contract['terms']['deadline'])) {
            throw new GameException('The remaining delivery deadline cannot fit travel and the scheduling allowance.');
        }
        $receipts = $contract['terms']['payment']['onFulfilled'] + ($contract['accepted'] ? 0 : $contract['terms']['payment']['onAccepted']);
        $fuelPrice = collect($markets[$source]['tradeGoods'] ?? [])->firstWhere('symbol', 'FUEL')['purchasePrice'] ?? null;
        $fuelAdjustment = $fuel === 0 ? 0 : (is_int($fuelPrice) ? (int) ceil($fuel / 100) * $fuelPrice : null);
        $net = $fuelAdjustment === null ? null : $receipts - $cost - $fuelAdjustment;

        return ['contract' => $contract, 'source' => $source, 'use_existing_cargo' => $useCargo, 'approved_existing_cargo' => $approvedCargo, 'protected_inventory' => $protected,
            'actions' => $actions, 'purchase_cost' => $cost, 'fuel_estimate' => $fuel, 'fuel_adjustment_estimate' => $fuelAdjustment,
            'duration_seconds' => $seconds, 'scheduling_allowance_seconds' => $allowance, 'estimated_net' => $net,
            'estimated_per_hour' => $net === null ? null : (int) floor($net * 3600 / max(1, $seconds)),
            'future_receipts' => $receipts, 'already_paid_acceptance' => $contract['accepted'] ? $contract['terms']['payment']['onAccepted'] : 0,
            'direct_sale_alternative' => $directSaleKnown ? $directSale : null,
            'deliveries' => $deliveries, 'purchase_batches' => $purchases, 'ending_location' => $location,
            'ending_inventory' => array_filter($inventory), 'ending_fuel_estimate' => $ship['fuel']['current'] - $fuel,
            'working_capital' => $cost, 'setup_cost' => 0];
    }

    public function next(array $ship, array $plan, array $totals, CarbonImmutable $now): AutomationStep
    {
        if ($ship['nav']['status'] === 'IN_TRANSIT') {
            $arrival = CarbonImmutable::parse($ship['nav']['route']['arrival']);

            return AutomationStep::wait($arrival->max($now->addSeconds(1)));
        }
        $action = $plan['contract_plan']['actions'][$totals['step_index']] ?? null;

        return $action ? AutomationStep::act($action['command'], $action['arguments']) : AutomationStep::trip();
    }
}
