<?php

namespace App\Gameplay;

class CargoRefuel
{
    public static function preview(array $ship, array $arguments, array $contracts, int $credits, int $creditFloor): array
    {
        if ($ship['nav']['status'] !== 'DOCKED') {
            throw new GameException('Cargo refueling currently requires docking as a conservative client policy; location exceptions remain a live gate.');
        }
        $space = $ship['fuel']['capacity'] - $ship['fuel']['current'];
        $units = min($arguments['units'] ?? $space, $space);
        if ($units < 1 || $ship['fuel']['capacity'] === 0) {
            throw new GameException('No tank space is available.');
        }
        $roundingUnknown = $units % 100 !== 0;
        $exposure = $arguments['maxCargoUnits'] ?? ($roundingUnknown ? null : intdiv($units, 100));
        if ($exposure === null || $exposure < 1) {
            throw new GameException('Partial cargo conversion needs an explicit maximum cargo-unit loss for review. Server rounding is not calibrated.');
        }
        if (($arguments['maxTotal'] ?? null) !== 0 || $credits < $creditFloor) {
            throw new GameException('Cargo conversion requires a zero cash ceiling and credits at or above the protected reserve.');
        }
        $fuel = MiningPlanner::inventory($ship)['FUEL'] ?? 0;
        $committed = 0;
        foreach ($contracts as $contract) {
            if ($contract['accepted'] && ! $contract['fulfilled']) {
                foreach ($contract['terms']['deliver'] as $term) {
                    if ($term['tradeSymbol'] === 'FUEL') {
                        $committed += $term['unitsRequired'] - $term['unitsFulfilled'];
                    }
                }
            }
        }
        $protected = max($arguments['protectedFuel'] ?? 0, min($fuel, $committed));
        $cargoUnits = $roundingUnknown ? $exposure : intdiv($units, 100);
        if ($cargoUnits > $exposure) {
            throw new GameException('Expected cargo consumption exceeds the approved cargo loss threshold.');
        }
        if ($exposure > $fuel - $protected) {
            throw new GameException('Insufficient unprotected FUEL cargo. User reserve and accepted contract fuel stay aboard.');
        }

        return ['source' => 'cargo', 'rounding_unknown' => $roundingUnknown, 'omitted_units' => ! isset($arguments['units']), 'tank_units' => $units, 'cargo_units' => $roundingUnknown ? null : $cargoUnits, 'max_cargo_units' => $exposure,
            'fuel_cargo_before' => $fuel, 'user_protected_fuel' => $arguments['protectedFuel'] ?? 0, 'contract_fuel' => $committed, 'protected_fuel' => $protected,
            'tank_after' => $ship['fuel']['current'] + $units, 'tank_space_after' => $space - $units,
            'fuel_cargo_after' => $roundingUnknown ? null : $fuel - $cargoUnits, 'cargo_after' => $roundingUnknown ? null : $ship['cargo']['units'] - $cargoUnits,
            'minimum_fuel_cargo_after' => $fuel - $exposure,
            'cargo_free_after' => $roundingUnknown ? null : $ship['cargo']['capacity'] - $ship['cargo']['units'] + $cargoUnits];
    }

    public static function confirm(array $before, array $ship, int $credits, int $price): void
    {
        $allocation = $before['estimate']['cargo_refuel'];
        $expected = MiningPlanner::inventory($before['ship']);
        $consumed = ($expected['FUEL'] ?? 0) - (MiningPlanner::inventory($ship)['FUEL'] ?? 0);
        if ($allocation['rounding_unknown'] && ($consumed < 0 || $consumed > $allocation['max_cargo_units'])) {
            throw new GameException('Cargo refuel exceeded the approved cargo review threshold.', uncertain: true);
        }
        $expected['FUEL'] -= $allocation['rounding_unknown'] ? $consumed : $allocation['cargo_units'];
        $expected = array_filter($expected);
        $observed = MiningPlanner::inventory($ship);
        ksort($expected);
        ksort($observed);
        if ($expected !== $observed || $ship['cargo']['units'] !== $before['ship']['cargo']['units'] - ($allocation['rounding_unknown'] ? $consumed : $allocation['cargo_units'])
            || $ship['cargo']['capacity'] !== $before['ship']['cargo']['capacity']
            || $ship['fuel']['current'] !== $allocation['tank_after'] || $ship['fuel']['capacity'] !== $before['ship']['fuel']['capacity']
            || $credits !== $before['credits'] || $price !== 0) {
            throw new GameException('Cargo refuel response differs from the reserved tank, cargo or zero-cash conversion. Reconcile before continuing.', uncertain: true);
        }
    }
}
