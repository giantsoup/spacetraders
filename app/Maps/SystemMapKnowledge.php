<?php

namespace App\Maps;

use App\Gameplay\ExplorationPolicy;
use App\Models\Agent;
use App\Models\GameObservation;
use App\Models\Ship;
use Illuminate\Support\Arr;

/** A projection of saved knowledge. This class never resolves an API client or writes observations. */
class SystemMapKnowledge
{
    public function forShip(Agent $agent, ?Ship $ship): array
    {
        $system = $ship?->snapshot['nav']['systemSymbol'] ?? null;
        if (! $system) {
            return ['system' => null, 'locations' => [], 'catalogue' => null, 'ship' => null];
        }
        $saved = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->whereIn('kind', ['waypoint', 'system', 'manual_read', 'market', 'market_listing', 'shipyard', 'catalogue'])
            ->orderBy('observed_at')->orderBy('id')->get();
        $points = [];
        $systemInfo = null;
        foreach ($saved as $observation) {
            $data = $observation->data;
            if ($observation->kind === 'manual_read') {
                $operation = $data['operation'] ?? null;
                $systems = match ($operation) {
                    'get-system' => [$data['data'] ?? []],
                    'get-systems' => $data['data'] ?? [],
                    default => [],
                };
                foreach ($systems as $row) {
                    if (($row['symbol'] ?? null) === $system) {
                        $systemInfo = array_replace($systemInfo ?? [], $this->system($row, $observation));
                        foreach ($row['waypoints'] ?? [] as $point) {
                            $this->merge($points, $point + ['systemSymbol' => $system], $observation, $system);
                        }
                    }
                }
            } elseif ($observation->kind === 'system' && $observation->symbol === $system) {
                $systemInfo = array_replace($systemInfo ?? [], $this->system($data, $observation));
                foreach ($data['waypoints'] ?? [] as $point) {
                    $this->merge($points, $point + ['systemSymbol' => $system], $observation, $system);
                }
            } elseif ($observation->kind === 'waypoint') {
                $this->merge($points, $data + ['symbol' => $observation->symbol], $observation, $system);
            }
        }
        foreach ($saved->whereIn('kind', ['market', 'market_listing', 'shipyard']) as $service) {
            if (! isset($points[$service->symbol])) {
                $this->merge($points, ['symbol' => $service->symbol], $service, $system);
            }
        }
        $nav = $ship->snapshot['nav'];
        $transit = ($nav['status'] ?? null) === 'IN_TRANSIT';
        $position = $transit ? ($nav['route']['origin'] ?? []) : ['symbol' => $nav['waypointSymbol'] ?? null];
        if (! empty($position['symbol']) && str_starts_with($position['symbol'], $system.'-') && ! isset($points[$position['symbol']])) {
            $this->merge($points, Arr::only($position, ['symbol', 'type', 'x', 'y']) + ['systemSymbol' => $system], $ship, $system);
        }
        $byKind = $saved->groupBy('kind')->map(fn ($rows) => $rows->keyBy('symbol'));
        $locations = [];
        foreach ($points as $symbol => $point) {
            $data = $point['data'];
            $known = ExplorationPolicy::traitsKnown($data);
            $traits = $known ? ($data['traits'] ?? []) : null;
            $market = $byKind->get('market')?->get($symbol);
            $listing = $byKind->get('market_listing')?->get($symbol);
            $yard = $byKind->get('shipyard')?->get($symbol);
            $locations[] = [
                'symbol' => $symbol, 'type' => $data['type'] ?? null,
                'x' => $this->coordinate($data['x'] ?? null), 'y' => $this->coordinate($data['y'] ?? null),
                'description' => $data['description'] ?? null, 'traits' => $traits,
                'traits_observed_at' => $known ? ($data['traits_observed_at'] ?? $point['observed_at']) : null,
                'modifiers' => $data['modifiers'] ?? null, 'faction' => $data['faction'] ?? null,
                'orbits' => $data['orbits'] ?? null, 'orbitals' => $data['orbitals'] ?? null,
                'isUnderConstruction' => $data['isUnderConstruction'] ?? null,
                'observed_at' => $point['observed_at'], 'source' => $point['source'],
                'market' => [
                    'presence' => $this->presence($traits, 'MARKETPLACE', $market || $listing),
                    'directory' => $this->observation($listing ?? $market, ['imports', 'exports', 'exchange']),
                    'prices' => $this->observation($market, ['tradeGoods', 'invalidated_by_action']),
                ],
                'shipyard' => ['presence' => $this->presence($traits, 'SHIPYARD', (bool) $yard),
                    'offers' => $this->observation($yard, ['shipTypes', 'ships', 'modificationsFee'])],
            ];
        }
        usort($locations, fn ($a, $b) => strcmp($a['symbol'], $b['symbol']));
        $coverage = $byKind->get('catalogue')?->get($system);

        return [
            'system' => $system, 'system_info' => $systemInfo, 'locations' => $locations,
            'catalogue' => $coverage ? ['complete' => ($coverage->data['complete'] ?? false) === true,
                'observed_at' => $coverage->observed_at?->toIso8601String(), 'total' => $coverage->data['total'] ?? null] : null,
            'ship' => ['symbol' => $ship->symbol, 'status' => $nav['status'] ?? null,
                'waypoint' => $nav['waypointSymbol'] ?? null, 'position' => $position['symbol'] ?? null,
                'in_transit' => $transit, 'observed_at' => $ship->observed_at?->toIso8601String()],
        ];
    }

    private function merge(array &$points, array $data, GameObservation|Ship $observation, string $system): void
    {
        $symbol = $data['symbol'] ?? null;
        if (! is_string($symbol) || ! str_starts_with($symbol, $system.'-') || ($data['systemSymbol'] ?? $system) !== $system) {
            return;
        }
        if (array_key_exists('traits', $data) && ! is_array($data['traits'])) {
            unset($data['traits']);
        }
        $old = $points[$symbol]['data'] ?? [];
        if (! ExplorationPolicy::traitsKnown($data) && ExplorationPolicy::traitsKnown($old)) {
            foreach (['traits', 'traits_observed', 'traits_observed_at', 'traits_source'] as $field) {
                if (array_key_exists($field, $old)) {
                    $data[$field] = $old[$field];
                }
            }
            $data['traits_observed_at'] ??= $points[$symbol]['observed_at'];
        }
        $points[$symbol] = ['data' => array_replace($old, $data),
            'observed_at' => $observation->observed_at?->toIso8601String(), 'source' => $observation->source ?? 'ship'];
    }

    private function coordinate(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private function presence(?array $traits, string $symbol, bool $observed): string
    {
        return $observed || ($traits !== null && in_array($symbol, array_column($traits, 'symbol'), true))
            ? 'observed' : ($traits === null ? 'unknown' : 'absent');
    }

    private function observation(?GameObservation $observation, array $fields): ?array
    {
        return $observation ? ['data' => Arr::only($observation->data, $fields),
            'observed_at' => $observation->observed_at?->toIso8601String(), 'source' => $observation->source] : null;
    }

    private function system(array $data, GameObservation $observation): array
    {
        return Arr::only($data, ['type', 'sectorSymbol', 'factions', 'x', 'y']) + ['observed_at' => $observation->observed_at?->toIso8601String()];
    }
}
