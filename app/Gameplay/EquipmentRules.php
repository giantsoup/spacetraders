<?php

namespace App\Gameplay;

use App\Models\Agent;

final class EquipmentRules
{
    public function inspect(Agent $agent, array $ship, string $command, string $symbol): array
    {
        $mount = str_ends_with($command, 'mount');
        $field = $mount ? 'mounts' : 'modules';
        $install = str_starts_with($command, 'install_');
        $installed = $ship[$field];
        if (! str_starts_with($symbol, $mount ? 'MOUNT_' : 'MODULE_')) {
            throw new GameException('Select a matching equipment symbol.');
        }
        if ($mount) {
            $this->shipyard($agent, $ship);
        }
        $item = collect($installed)->firstWhere('symbol', $symbol);
        if ($install) {
            if (app(CargoAuthority::class)->available($agent, $ship, $symbol) < 1) {
                throw new GameException('Installation requires an unprotected matching component in cargo.');
            }
            // Offered configurations are an available official source for requirement data, not a new equipment catalogue.
            $point = app(SpaceTradersApi::class)->waypoint($agent, $ship['nav']['waypointSymbol']);
            $yard = in_array('SHIPYARD', array_column($point['traits'], 'symbol'), true) ? app(SpaceTradersApi::class)->shipyard($agent, $ship['nav']['waypointSymbol']) : [];
            $item ??= collect($yard['ships'] ?? [])->flatMap(fn ($offer) => $offer[$field] ?? [])->firstWhere('symbol', $symbol);
            if ($mount && count($installed) >= $ship['frame']['mountingPoints']) {
                throw new GameException('No free mounting point.');
            }
            $candidate = array_merge($installed, [$item ?? ['symbol' => $symbol]]);
        } else {
            if (! $item) {
                throw new GameException('The selected equipment is not installed.');
            }
            $candidate = $installed;
            foreach ($candidate as $index => $component) {
                if ($component['symbol'] === $symbol) {
                    unset($candidate[$index]);
                    break;
                }
            }
            $capacity = $ship['cargo']['capacity'];
            if (str_starts_with($symbol, 'MODULE_CARGO_HOLD_') && isset($item['capacity'])) {
                $capacity -= $item['capacity'];
            }
            if ($ship['cargo']['units'] + 1 > $capacity) {
                throw new GameException('Removal needs cargo room, including the capacity lost with a cargo hold.');
            }
            if (str_starts_with($symbol, 'MODULE_CREW_QUARTERS_') && isset($item['capacity']) && $ship['crew']['current'] > $ship['crew']['capacity'] - $item['capacity']) {
                throw new GameException('Removing crew quarters would leave occupied crew capacity.');
            }
        }
        $all = array_merge($candidate, $ship[$mount ? 'modules' : 'mounts'], [$ship['frame'], $ship['engine'], $ship['reactor']]);
        foreach (['power' => $ship['reactor']['powerOutput'], 'crew' => $ship['crew']['current']] as $requirement => $capacity) {
            if (array_sum(array_map(fn ($component) => $component['requirements'][$requirement] ?? 0, $all)) > $capacity) {
                throw new GameException('Known equipment requirements exceed available '.$requirement.'.');
            }
        }
        $modules = $mount ? $ship['modules'] : $candidate;
        if (array_sum(array_map(fn ($component) => $component['requirements']['slots'] ?? 0, $modules)) > $ship['frame']['moduleSlots']) {
            throw new GameException('Known module slot requirements exceed the frame.');
        }

        return ['item' => $item, 'installed_before' => array_count_values(array_column($installed, 'symbol')),
            'unknown_requirements' => $item === null || collect($all)->contains(fn ($component) => ! isset($component['requirements']['power'], $component['requirements']['crew'])),
            'policy' => 'Known requirement totals are lower bounds when fields are absent. The server validates the final fit. Duplicate-symbol selection, fees and module location rules require live acceptance.',
            'cargo_effect' => $install ? 'Consumes one selected component from cargo.' : 'Returns selected equipment to cargo; capacities require a fresh full ship read.'];
    }

    public function shipyard(Agent $agent, array $ship): void
    {
        $point = app(SpaceTradersApi::class)->waypoint($agent, $ship['nav']['waypointSymbol']);
        if ($ship['nav']['status'] !== 'DOCKED' || ! in_array('SHIPYARD', array_column($point['traits'], 'symbol'), true)) {
            throw new GameException('Dock at a shipyard for this operation.');
        }
    }
}
