<?php

namespace App\Gameplay;

use InvalidArgumentException;

final class MeshPlanner
{
    private array $baselineCache = [];

    public function __construct(private FuelPlanner $fuel) {}

    public function route(MeshModel $model, array $ship, string $destination, int $secondsLeft): ?int
    {
        $origin = $ship['nav']['waypointSymbol'] ?? '';
        if (($ship['nav']['systemSymbol'] ?? null) !== $model->system || ! $this->fuel->fuelFree($ship)
            || ! isset($model->points[$origin], $model->points[$destination])) {
            return null;
        }
        if ($origin === $destination) {
            return 0;
        }
        $from = $model->points[$origin];
        $to = $model->points[$destination];
        $limit = min(5400, $secondsLeft) - 180;
        $distanceBound = ($limit + 1) * ($ship['engine']['speed'] / 25) + 1;
        if ($limit < 0 || abs($from['x'] - $to['x']) > $distanceBound || abs($from['y'] - $to['y']) > $distanceBound) {
            return null;
        }
        $seconds = $this->fuel->seconds($from, $to, $ship);

        return $seconds !== null && $seconds >= 0 && $seconds <= $limit ? $seconds : null;
    }

    public function restriction(array $probe, array $busy, array $backoff, array $policy, int $now, bool $steady = false): ?string
    {
        $ship = $probe['symbol'];
        if (($probe['nav']['status'] ?? null) === 'IN_TRANSIT') {
            return 'in_transit';
        }
        if (in_array($ship, $busy, true)) {
            return 'busy';
        }
        if (isset($backoff[$ship]) && $backoff[$ship] > $now - 1800) {
            return 'backoff';
        }
        if (($probe['cargo']['units'] ?? null) !== 0 || ! $this->fuel->fuelFree($probe)
            || ! in_array($probe['nav']['status'] ?? null, ['DOCKED', 'IN_ORBIT'], true)) {
            return 'configuration_or_cargo';
        }
        if (! $steady && ($probe['mesh_arrival'] ?? null) !== null && $probe['mesh_arrival'] > $now - $policy['min_dwell_seconds']) {
            return 'dwell';
        }

        return null;
    }

    public function constants(MeshModel $model, array $probes, array $policy, ?int $rowBound = null): array
    {
        $maxTravel = 0;
        foreach ($probes as $ship) {
            foreach ($model->buckets as $bucket) {
                $maxTravel = max($maxTravel, $this->route($model, $ship, $bucket['members'][0], 5400) ?? 0);
            }
        }
        $maxMarginal = 0;
        foreach ($model->buckets as $bucket) {
            $maxMarginal = max($maxMarginal, $model->marginals($bucket['members'], $policy)[0]);
        }
        $rows = $rowBound ?? count($probes);
        $q = MeshModel::product($rows, $maxTravel + $policy['hysteresis_seconds'] + $maxMarginal) + 1;

        return [1 => MeshModel::product($rows + 2, $q), 2 => MeshModel::product($rows + 1, $q)];
    }

    public function beginPass(): void
    {
        $this->baselineCache = [];
    }

    public function gain(MeshModel $model, array $probes, array $busy, array $reserved, array $backoff, array $policy, array $context, array $delivery): array
    {
        $comparisons = $context['deliveries'] ?? [$delivery];
        $context['bonuses'] ??= $this->constants($model, [...$probes, ...$comparisons], $policy, count($probes) + 1);
        $context['steady'] ??= true;
        unset($context['hold'], $context['pins'], $context['deliveries']);
        $input = [$model, $probes, $busy, $reserved, $backoff, $policy, $context];
        $key = hash('sha256', serialize($input));
        $base = $this->baselineCache[$key] ??= $this->plan(...$input);
        $delivered = $this->plan($model, [...$probes, $delivery], $busy, $reserved, $backoff, $policy, $context);

        return [
            'base' => $base['tiers'], 'delivered' => $delivered['tiers'],
            'tier1' => $delivered['tiers'][1] - $base['tiers'][1],
            'all' => array_sum($delivered['tiers']) - array_sum($base['tiers']),
            'utility' => $delivered['utility'] - $base['utility'],
        ];
    }

    public function plan(MeshModel $model, array $probes, array $busy, array $reserved, array $backoff, array $policy, array $context = []): array
    {
        $started = hrtime(true);
        $now = $context['now'] ?? time();
        $secondsLeft = $context['seconds_left'] ?? 5400;
        $steady = $context['steady'] ?? false;
        $commitments = $context['commitments'] ?? [];
        $pins = $context['pins'] ?? [];
        $parkedOutsidePool = $context['parked_outside_pool'] ?? [];
        $bonuses = $context['bonuses'] ?? $this->constants($model, $probes, $policy);
        $busy = [...$busy, ...($steady || ! isset($context['hold']) ? [] : [$context['hold']])];
        $fixed = $restrictions = $rows = $occupied = [];
        $members = [];
        usort($probes, fn ($a, $b) => $a['symbol'] <=> $b['symbol']);

        foreach ($probes as $probe) {
            $ship = $probe['symbol'];
            $origin = $probe['nav']['waypointSymbol'] ?? '';
            if (($probe['nav']['status'] ?? null) !== 'IN_TRANSIT') {
                $occupied[$origin][] = $ship;
            }
            $commitment = $commitments[$ship] ?? $pins[$ship] ?? null;
            $reason = $this->restriction($probe, $busy, $backoff, $policy, $now, $steady);
            if ($reason === 'in_transit') {
                $commitment ??= $probe['nav']['route']['destination']['symbol'] ?? null;
            }
            $anchor = isset($model->targets[$origin]);
            $stayOnly = $anchor && ($secondsLeft < 180 || ! array_filter(
                $model->targets,
                fn (array $target) => $target['symbol'] !== $origin
                    && $this->route($model, $probe, $target['symbol'], $secondsLeft) !== null,
            ));
            // An external reservation cannot displace verified physical coverage.
            $reservedOrigin = $anchor && isset($occupied[$origin]) && in_array($ship, $occupied[$origin], true)
                && in_array($origin, $reserved, true);
            if ($commitment !== null || $reason !== null || $stayOnly || $reservedOrigin) {
                $destination = $commitment ?? ($reason === 'in_transit' ? '' : $origin);
                $restrictions[$ship] = $commitment !== null ? 'commitment' : ($reason ?? ($reservedOrigin ? 'occupied_reservation' : 'cutoff'));
                if (isset($model->targets[$destination]) && ! isset($fixed[$destination]) && ! isset($parkedOutsidePool[$destination])
                    && ($commitment !== null || $reservedOrigin || ! in_array($destination, $reserved, true))) {
                    $fixed[$destination] = $ship;
                }

                continue;
            }
            $rows[$ship] = $probe;
        }

        $slots = $counts = $marginals = [];
        $tiers = [1 => 0, 2 => 0];
        $utility = $primaryTotal = $tieTotal = 0;
        foreach ($model->buckets as $bucketId => $bucket) {
            $available = array_values(array_filter($bucket['members'], fn (string $symbol) => ! isset($parkedOutsidePool[$symbol])
                && (isset($fixed[$symbol]) || ! in_array($symbol, $reserved, true))));
            $members[$bucketId] = array_values(array_diff($available, array_keys($fixed)));
            $counts[$bucketId] = count($available) - count($members[$bucketId]);
            $marginals[$bucketId] = $model->marginals($available, $policy);
            $tier = $bucket['tier'];
            $tiers[$tier] += $counts[$bucketId];
            foreach ($available as $index => $symbol) {
                if ($index < $counts[$bucketId]) {
                    $utility += $marginals[$bucketId][$index];

                    continue;
                }
                $slots[] = ['bucket' => $bucketId, 'canonical' => $symbol, 'tier' => $tier, 'marginal' => $marginals[$bucketId][$index]];
            }
        }
        $costs = $primary = $ordinary = [];
        $rowCount = count($rows);
        $ships = array_keys($rows);
        foreach ($rows as $probe) {
            $origin = $probe['nav']['waypointSymbol'];
            $travelByBucket = [];
            $encodedRow = $primaryRow = $ordinaryRow = [];
            foreach ($slots as $column => $slot) {
                $bucketId = $slot['bucket'];
                if (! array_key_exists($bucketId, $travelByBucket)) {
                    $travelByBucket[$bucketId] = $this->bucketCost($model, $probe, $members[$bucketId], $policy, $context, $occupied, $secondsLeft);
                }
                $travel = $travelByBucket[$bucketId];
                $ordinaryRow[$column] = $travel;
                $primaryRow[$column] = $travel === null ? null : $travel - $slot['marginal'] - $bonuses[$slot['tier']];
                $encodedRow[$column] = $travel === null ? Hungarian::BIG
                    : Hungarian::encode($primaryRow[$column], (int) ($origin !== $slot['canonical']), $rowCount + 1);
            }
            $costs[] = [...$encodedRow, ...array_fill(0, $rowCount, 0)];
            $primary[] = $primaryRow;
            $ordinary[] = $ordinaryRow;
        }

        $groups = [];
        foreach (Hungarian::solve($costs) as $row => $column) {
            $ship = $ships[$row];
            if (! isset($slots[$column])) {
                $restrictions[$ship] = 'surplus_or_unreachable';

                continue;
            }
            $slot = $slots[$column];
            $groups[$slot['bucket']][$ship] = $rows[$ship];
            $counts[$slot['bucket']]++;
            $tiers[$slot['tier']]++;
            $utility += $slot['marginal'] - $ordinary[$row][$column];
            $primaryTotal += $primary[$row][$column];
            $tieTotal += (int) ($rows[$ship]['nav']['waypointSymbol'] !== $slot['canonical']);
        }

        $roles = $moves = [];
        if (! $steady) {
            foreach ($groups as $bucket => $group) {
                [$bucketRoles, $bucketMoves] = $this->roles($model, $bucket, $group, $members[$bucket], $occupied, $policy, $context, $secondsLeft);
                $roles += $bucketRoles;
                $moves = [...$moves, ...$bucketMoves];
            }
            usort($moves, fn ($a, $b) => [$a['patrol'], -$a['gain'], $a['ship'], $a['destination']] <=> [$b['patrol'], -$b['gain'], $b['ship'], $b['destination']]);
        }

        return [
            'tiers' => $tiers, 'counts' => $counts,
            'prefixes' => array_map(fn (int $count) => $count ? range(1, $count) : [], $counts),
            'utility' => $utility, 'primary' => $primaryTotal, 'tie' => $tieTotal,
            'locked' => $fixed, 'restrictions' => $restrictions, 'roles' => $roles, 'moves' => $moves,
            'dimensions' => [$rowCount, count($slots) + $rowCount], 'role_rows' => array_map('count', $groups), 'elapsed_ms' => (hrtime(true) - $started) / 1000000,
        ];
    }

    private function bucketCost(MeshModel $model, array $probe, array $members, array $policy, array $context, array $occupied, int $secondsLeft): ?int
    {
        $origin = $probe['nav']['waypointSymbol'];
        $point = $model->points[$origin] ?? null;
        $target = $model->points[$members[0]];
        if (! $point) {
            return null;
        }
        $sameCluster = $point['x'] === $target['x'] && $point['y'] === $target['y'];
        $occupants = count($occupied[$origin] ?? []) + (int) isset($context['parked_outside_pool'][$origin]);
        $anchor = isset($model->targets[$origin]) && $occupants === 1;
        if (! $sameCluster && $anchor && ! empty($context['protect_anchors']) && empty($context['steady'])) {
            return null;
        }
        foreach ($members as $member) {
            if ($this->route($model, $probe, $member, $secondsLeft) === null) {
                return null;
            }
        }

        return $sameCluster ? 0 : $this->route($model, $probe, $members[0], $secondsLeft) + ($anchor ? $policy['hysteresis_seconds'] : 0);
    }

    private function roles(MeshModel $model, string $bucket, array $group, array $members, array $occupied, array $policy, array $context, int $secondsLeft): array
    {
        $count = count($group);
        $partial = $count < count($members);
        $stationary = array_slice($members, 0, $partial ? $count - 1 : $count);
        $patrol = $partial ? array_slice($members, $count - 1) : [];
        $priority = function (string $symbol) use ($context): array {
            $demand = array_search($symbol, $context['demand'] ?? [], true);
            $age = $context['ages'][$symbol] ?? PHP_INT_MAX;

            return [$demand === false ? PHP_INT_MAX : $demand, -$age, $symbol];
        };
        usort($patrol, fn ($a, $b) => $priority($a) <=> $priority($b));
        $columns = array_map(fn (string $symbol) => [$symbol], $stationary);
        if ($partial) {
            $columns[] = $patrol;
        }
        $costs = $destinations = [];
        foreach (array_values($group) as $row => $probe) {
            $origin = $probe['nav']['waypointSymbol'];
            foreach ($columns as $column => $options) {
                $isPatrol = $partial && $column === $count - 1;
                $destination = in_array($origin, $options, true) ? $origin : $options[0];
                if ($isPatrol && $destination !== $origin) {
                    $open = array_filter($options, fn (string $member) => ! isset($occupied[$member])
                        && $this->route($model, $probe, $member, $secondsLeft) !== null);
                    $destination = reset($open) ?: $origin;
                }
                $travel = $this->route($model, $probe, $destination, $secondsLeft);
                $destinations[$row][$column] = $destination;
                $costs[$row][$column] = $travel === null ? Hungarian::BIG : Hungarian::encode($travel, $isPatrol ? $count - 1 - $row : 0, $count);
            }
        }

        $roles = $moves = [];
        $ships = array_keys($group);
        $now = $context['now'] ?? time();
        foreach (Hungarian::solve($costs) as $row => $column) {
            $ship = $ships[$row];
            $origin = $group[$ship]['nav']['waypointSymbol'];
            $destination = $destinations[$row][$column];
            $isPatrol = $partial && $column === $count - 1;
            $hop = $isPatrol && $destination === $origin && in_array($origin, $patrol, true);
            if ($hop && ($context['cooldowns'][$bucket] ?? 0) <= $now - $policy['patrol_cooldown_seconds']) {
                foreach ($patrol as $sibling) {
                    if ($priority($sibling) < $priority($origin) && ! isset($occupied[$sibling])
                        && ($context['ages'][$sibling] ?? PHP_INT_MAX) >= $policy['patrol_quote_min_age_seconds']
                        && $this->route($model, $group[$ship], $sibling, $secondsLeft) !== null) {
                        $destination = $sibling;
                        break;
                    }
                }
            }
            $roles[$ship] = ['destination' => $destination, 'patrol' => $isPatrol ? $patrol : []];
            if ($destination !== $origin) {
                $travel = $this->route($model, $group[$ship], $destination, $secondsLeft);
                if ($travel === null) {
                    throw new InvalidArgumentException('Mesh role selected an unavailable route.');
                }
                $gain = $model->targets[$destination]['weight'] - ($model->targets[$origin]['weight'] ?? 0) - $travel;
                $moves[] = ['ship' => $ship, 'origin' => $origin, 'destination' => $destination, 'bucket' => $bucket, 'patrol' => $hop, 'gain' => $gain];
            }
        }

        return [$roles, $moves];
    }
}
