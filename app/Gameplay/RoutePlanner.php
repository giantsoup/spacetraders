<?php

declare(strict_types=1);

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\GameObservation;
use App\Models\ObservationHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use SplPriorityQueue;

class RoutePlanner
{
    public function planCruise(Agent $agent, array $ship, string $destination, int $creditReserve = 0, ?int $fuelReserve = null, ?string $policy = null, ?Collection $listings = null, ?float $deadline = null, bool $forceOriginTopUp = false, ?array $graph = null): array
    {
        $fuel = new FuelPlanner;
        $probe = $fuel->fuelFree($ship);
        $tank = $ship['fuel']['capacity'] ?? 0;
        $speed = $ship['engine']['speed'] ?? 0;
        if (($ship['nav']['flightMode'] ?? null) !== 'CRUISE' || $speed < 1 || ($tank < 1 && ! $probe)) {
            throw new GameException('Fuel behavior for this mode or ship is unvalidated.');
        }

        $origin = $ship['nav']['waypointSymbol'];
        $system = $ship['nav']['systemSymbol'];
        $policy ??= (string) config('gameplay.route_evidence', 'conservative');
        $fuelReserve ??= (int) config('gameplay.fuel_reserve_units', 2);
        $points = $graph['points'] ?? $this->points($agent, $system);
        $points = array_filter($points, fn (array $point) => ($point['systemSymbol'] ?? $system) === $system);
        if (! isset($points[$origin], $points[$destination])) {
            throw new GameException('Discover a same-system route before planning travel.');
        }

        $configuration = FuelPlanner::configuration($ship);
        if ($probe) {
            $hops = $origin === $destination ? [] : [[
                'origin' => $origin, 'destination' => $destination,
                'seconds' => $this->cruiseSeconds($this->distance($points[$origin], $points[$destination]), $speed),
                'burn' => 0, 'fuel_before' => 0, 'fuel_after' => 0,
                'topped_up' => 0, 'refuel_batches' => 0, 'fuel_cost' => 0, 'refuel_ceiling' => 0,
                'seller' => null, 'quote_age' => null, 'stale_fallback' => false,
                'continuation' => $destination, 'continuation_fuel' => 0, 'continuation_ceiling' => 0,
            ]];
            $route = $this->result([$hops[0]['seconds'] ?? 0, 0, $hops ? [$origin, $destination] : [$origin], 0, 0], $hops, 0);
            $policy = 'fuel_free_probe';
        } else {
            $sellers = $graph['sellers'] ?? $this->sellers($agent, $points, $listings ?? $fuel->listings($agent));
            $nodes = array_intersect_key($points, $sellers + [$origin => true, $destination => true]);
            $defaults = $policy === 'conservative' ? [] : array_fill_keys(array_keys($nodes), array_fill_keys(array_keys($nodes), null));
            $overrides = isset($graph['points'], $graph['sellers'])
                ? array_replace_recursive($defaults, $graph['edge_burn_overrides'] ?? [])
                : $this->burnOverrides($agent, $configuration, $nodes, $policy, $defaults);
            $route = $this->searchCruise($origin, $destination, $ship['fuel']['current'], $tank, $speed, $points, $sellers, $agent->credits - $creditReserve, $fuelReserve, forceOriginTopUp: $forceOriginTopUp, edgeBurnOverrides: $overrides, deadline: $deadline);
        }
        if ($route === null || ($deadline !== null && microtime(true) >= $deadline)) {
            throw new GameException('No fuel-safe CRUISE route to the destination on the available evidence and cash.');
        }

        return $route + ['origin' => $origin, 'destination' => $destination, 'mode' => 'CRUISE',
            'configuration' => $configuration, 'evidence_policy' => $policy, 'uncertainty_reserve' => $probe ? 0 : $fuelReserve];
    }

    /**
     * Points are keyed by symbol and carry x/y (or a coordinate pair). Sellers carry
     * ask/volume (or a pair), optional quote_age, and stale_fallback. Burn overrides
     * are keyed by origin and destination, with null forbidding a directed edge.
     */
    public function searchCruise(string $origin, string $destination, int $currentFuel, int $tank, int $speed, array $points, array $sellers, int $availableCash, int $reserve = 2, int $stopSeconds = 30, bool $forceOriginTopUp = false, ?array $edgeBurnOverrides = null, ?float $deadline = null): ?array
    {
        if ($speed < 1 || $tank < 1 || $currentFuel < 0 || $currentFuel > $tank || $reserve < 0 || $stopSeconds < 0
            || ! isset($points[$origin], $points[$destination])) {
            return null;
        }
        $quotes = [];
        foreach ($sellers as $symbol => $seller) {
            $ask = (int) ($seller['ask'] ?? $seller['purchasePrice'] ?? $seller[0] ?? 0);
            $volume = (int) ($seller['volume'] ?? $seller['tradeVolume'] ?? $seller[1] ?? 0);
            if (isset($points[$symbol]) && $ask > 0 && $volume > 0) {
                $stale = (bool) ($seller['stale_fallback'] ?? false);
                $quotes[$symbol] = ['ask' => $ask, 'volume' => $volume, 'stale_fallback' => $stale,
                    'quote_age' => $seller['quote_age'] ?? null, 'ceiling' => ($stale ? 4 : 2) * intdiv($tank + 99, 100) * $ask];
            }
        }

        $nodes = array_unique([...array_keys($quotes), $destination]);
        sort($nodes, SORT_STRING);
        $bounds = [];
        $edges = [];
        $returns = [];
        foreach (array_unique([$origin, ...$nodes]) as $from) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                return null;
            }
            $bounds[$from] = (int) floor($this->distance($points[$from], $points[$destination]) * 25 / $speed);
            foreach ($nodes as $to) {
                $distance = $this->distance($points[$from], $points[$to]);
                $overrides = $edgeBurnOverrides[$from] ?? [];
                $burn = match (true) {
                    $from === $to => 0,
                    array_key_exists($to, $overrides) => $overrides[$to],
                    default => (int) ceil($distance) + 2,
                };
                if ($burn === null || $burn < 0 || $burn + $reserve > $tank) {
                    continue;
                }
                if (isset($quotes[$to])) {
                    $returns[$from][] = [$quotes[$to]['stale_fallback'], $burn, $to, $quotes[$to]['ceiling']];
                }
                if ($from !== $to) {
                    $edges[$from][$to] = [$burn, $this->cruiseSeconds($distance, $speed)];
                }
            }
            if (isset($returns[$from])) {
                sort($returns[$from]);
            }
        }

        // Ignore branches that cannot reach the destination even from a full tank.
        $reachable = [$destination => true];
        do {
            if ($deadline !== null && microtime(true) >= $deadline) {
                return null;
            }
            $count = count($reachable);
            foreach ($edges as $from => $outbound) {
                if (isset($reachable[$from])) {
                    continue;
                }
                foreach (array_intersect_key($outbound, $reachable) as $to => [$burn]) {
                    if ($this->continuation($returns[$to] ?? [], $tank - $burn - $reserve, $availableCash) !== null) {
                        $reachable[$from] = true;
                        break;
                    }
                }
            }
        } while (count($reachable) > $count);
        if (! isset($reachable[$origin])) {
            return null;
        }
        $queue = new class extends SplPriorityQueue
        {
            public function compare(mixed $priority1, mixed $priority2): int
            {
                // Reverse the comparison to get a min-heap, and compare paths lexically.
                [$a, $b] = [$priority2, $priority1];
                $result = [$a[0], $a[1][0], $a[1][1]] <=> [$b[0], $b[1][0], $b[1][1]];
                if ($result !== 0) {
                    return $result;
                }
                foreach ($a[1][2] as $index => $symbol) {
                    $result = strcmp($symbol, $b[1][2][$index] ?? '');
                    if ($result !== 0) {
                        return $result;
                    }
                }

                return [$a[1][3], $a[1][4], $a[2]] <=> [$b[1][3], $b[1][4], $b[2]];
            }
        };
        $rank = [0, 0, [$origin], 0, 0];
        $sequence = 0;
        $queue->insert([$rank, $currentFuel, []], [$bounds[$origin], $rank, $sequence]);

        $seen = [];
        while (! $queue->isEmpty()) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                return null;
            }
            [$rank, $fuel, $hops] = $queue->extract();
            [$elapsed, $stops, $path, $batches, $ceiling] = $rank;
            $node = $path[array_key_last($path)];
            if ($node === $destination) {
                return $this->result($rank, $hops, $fuel);
            }
            if (isset($seen[$node][$fuel][$ceiling])) {
                continue;
            }
            $seen[$node][$fuel][$ceiling] = true;

            $seller = $quotes[$node] ?? null;
            $cash = $availableCash - $ceiling;
            $choices = $seller && $fuel < $tank && $cash >= $seller['ceiling'] ? [false, true] : [false];
            if ($forceOriginTopUp && $hops === [] && $fuel < $tank) {
                $choices = count($choices) === 2 ? [true] : [];
            }
            foreach (array_intersect_key($edges[$node] ?? [], $reachable) as $turn => [$burn, $seconds]) {
                foreach ($choices as $topUp) {
                    $before = $topUp ? $tank : $fuel;
                    $after = $before - $burn;
                    $hold = $topUp ? $seller['ceiling'] : 0;
                    $continuation = $this->continuation($returns[$turn] ?? [], $after - $reserve, $cash - $hold);
                    if ($continuation === null) {
                        continue;
                    }

                    $added = $before - $fuel;
                    $refuels = $topUp ? intdiv($added - 1, 100 * $seller['volume']) + 1 : 0;
                    $intermediate = (int) ($turn !== $destination);
                    $newRank = [
                        $elapsed + $seconds + $stopSeconds * $intermediate,
                        $stops + $intermediate,
                        [...$path, $turn],
                        $batches + $refuels,
                        $ceiling + $hold,
                    ];
                    $hop = [
                        'origin' => $node, 'destination' => $turn, 'seconds' => $seconds, 'burn' => $burn,
                        'fuel_before' => $before, 'fuel_after' => $after, 'topped_up' => $added, 'refuel_batches' => $refuels,
                        'fuel_cost' => $topUp ? intdiv($added + 99, 100) * $seller['ask'] : 0,
                        'refuel_ceiling' => $hold, 'seller' => $topUp ? $node : null,
                        'quote_age' => $topUp ? $seller['quote_age'] : null, 'stale_fallback' => $topUp && $seller['stale_fallback'],
                        'continuation' => $continuation[2], 'continuation_fuel' => $continuation[1],
                        'continuation_ceiling' => $continuation[3],
                        'continuation_quote_age' => $quotes[$continuation[2]]['quote_age'],
                        'continuation_stale_fallback' => $continuation[0],
                    ];
                    $queue->insert([$newRank, $after, [...$hops, $hop]], [$newRank[0] + $bounds[$turn], $newRank, ++$sequence]);
                }
            }
        }

        return null;
    }

    public function planDrift(Agent $agent, array $ship, string $destination, CarbonImmutable $hardDeadline, int $marginSeconds = 1800, ?int $fuelReserve = null, ?array $validatedUpperSeconds = null): array
    {
        $reserve = max(2, $fuelReserve ?? (int) config('gameplay.fuel_reserve_units', 2));
        if (($ship['cargo']['units'] ?? null) !== 0 || ! in_array($ship['nav']['status'] ?? null, ['DOCKED', 'IN_ORBIT'], true)) {
            throw new GameException('DRIFT positioning requires an empty hull at a verified stationary origin.');
        }
        if (($ship['fuel']['current'] ?? 0) - 1 < $reserve) {
            throw new GameException('DRIFT positioning requires one fuel plus the separate recovery reserve.');
        }

        $origin = $ship['nav']['waypointSymbol'];
        $points = $this->points($agent, $ship['nav']['systemSymbol']);
        if ($origin === $destination || ! isset($points[$origin], $points[$destination])) {
            throw new GameException('Discover a distinct same-system destination before planning DRIFT positioning.');
        }

        $configurations = [];
        foreach (['CRUISE', 'DRIFT'] as $mode) {
            $ship['nav']['flightMode'] = $mode;
            $configurations[$mode] = FuelPlanner::configuration($ship);
        }
        $upper = $validatedUpperSeconds[$origin][$destination] ?? $validatedUpperSeconds[$origin.'>'.$destination]
            ?? $validatedUpperSeconds[$destination] ?? $this->observedDriftUpper($agent, $configurations['DRIFT'], $origin, $destination);
        $seconds = $this->driftEstimate($points[$origin], $points[$destination], $ship['engine']['speed'], $upper);
        $now = CarbonImmutable::now();
        $arrival = $now->addSeconds($seconds);
        $marginSeconds = max(0, $marginSeconds);
        if ($arrival->gt($hardDeadline->subSeconds($marginSeconds))) {
            throw new GameException('The conservative DRIFT arrival exceeds the authorized deadline less margin.');
        }

        return [
            'origin' => $origin, 'destination' => $destination, 'mode' => 'DRIFT',
            'path' => [$origin, $destination], 'seconds' => $seconds, 'estimated_seconds' => $seconds,
            'estimated_arrival_at' => $arrival->toISOString(), 'hard_deadline' => $hardDeadline->toISOString(),
            'margin_seconds' => $marginSeconds, 'run_seconds' => $seconds + $marginSeconds,
            'outbound_fuel' => 1, 'fuel_after' => $ship['fuel']['current'] - 1, 'uncertainty_reserve' => $reserve,
            'configurations' => $configurations,
            'cleanup' => ['command' => 'flight_mode', 'mode' => 'CRUISE', 'expires_at' => $hardDeadline->toISOString(),
                'configuration' => $configurations['CRUISE']],
        ];
    }

    public function driftEstimate(array $from, array $to, int $speed, ?int $validatedUpper = null): int
    {
        if ($speed < 1) {
            throw new InvalidArgumentException('DRIFT requires a positive engine speed.');
        }

        return max($validatedUpper ?? 0, (int) round(round(max(1.0, $this->distance($from, $to))) * 250 / $speed + 15));
    }

    private function points(Agent $agent, string $system): array
    {
        return GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'waypoint')
            ->where('data->systemSymbol', $system)->get()->mapWithKeys(fn (GameObservation $row) => [$row->symbol => $row->data])->all();
    }

    private function sellers(Agent $agent, array $points, Collection $listings): array
    {
        $now = CarbonImmutable::now();
        $maxAge = (int) config('gameplay.fuel_quote_max_seconds', 21600);
        $snapshotAge = (int) config('gameplay.snapshot_seconds', 180);
        $fuel = new FuelPlanner;
        $sellers = [];
        $markets = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', 'market')->whereIn('symbol', array_keys($points))->where('observed_at', '>=', $now->subSeconds(max($maxAge, $snapshotAge)))
            ->orderByDesc('observed_at')->orderByDesc('id')->get()->unique('symbol');
        foreach ($markets as $market) {
            if (! empty($market->data['invalidated_by_action']) || $market->observed_at->gt($now)) {
                continue;
            }
            $age = $market->observed_at->diffInSeconds($now);
            $quote = collect($market->data['tradeGoods'] ?? [])->firstWhere('symbol', 'FUEL');
            $listed = $listings->has($market->symbol)
                ? $fuel->listsFuel($agent, $market->symbol, $listings)
                : in_array($quote['type'] ?? null, ['EXPORT', 'EXCHANGE'], true);
            if ($listed && ($quote['purchasePrice'] ?? 0) > 0 && ($quote['tradeVolume'] ?? 0) > 0
                && ($age <= $snapshotAge || $age <= $maxAge)) {
                $sellers[$market->symbol] = ['ask' => $quote['purchasePrice'], 'volume' => $quote['tradeVolume'],
                    'quote_age' => (int) $age, 'stale_fallback' => $age > $snapshotAge];
            }
        }

        return $sellers;
    }

    private function burnOverrides(Agent $agent, string $configuration, array $nodes, string $policy, array $overrides): array
    {
        $history = ObservationHistory::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', 'travel')->where('data->configuration', $configuration)->get();
        foreach ($history as $sample) {
            $data = $sample->data;
            $from = $data['from'] ?? null;
            $to = $data['to'] ?? null;
            if (! isset($nodes[$from], $nodes[$to]) || ! FuelPlanner::validSample($data)) {
                continue;
            }
            $baseline = $policy === 'conservative' && $from !== $to
                ? (int) ceil($this->distance($nodes[$from], $nodes[$to])) + 2
                : 0;
            $overrides[$from][$to] = max($overrides[$from][$to] ?? $baseline, $data['actual_fuel']);
        }

        return $overrides;
    }

    private function observedDriftUpper(Agent $agent, string $configuration, string $origin, string $destination): ?int
    {
        $maximum = ObservationHistory::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', 'travel')->where('data->configuration', $configuration)
            ->where('data->flight_mode', 'DRIFT')->where('data->from', $origin)->where('data->to', $destination)
            ->get()->filter(fn (ObservationHistory $sample) => FuelPlanner::validSample($sample->data)
                && $sample->data['actual_fuel'] === 1 && is_numeric($sample->data['server_duration_seconds'] ?? null))
            ->max('data.server_duration_seconds');

        return $maximum === null ? null : (int) ceil((float) $maximum);
    }

    private function continuation(array $sellers, int $availableFuel, int $cash): ?array
    {
        foreach ($sellers as $seller) {
            if ($seller[1] <= $availableFuel && $seller[3] <= $cash) {
                return $seller;
            }
        }

        return null;
    }

    private function result(array $rank, array $hops, int $fuel): array
    {
        [$elapsed, $stops, $path, $batches, $ceiling] = $rank;
        $last = $hops[array_key_last($hops)] ?? [];

        return [
            'rank' => $rank, 'path' => $path, 'hops' => $hops, 'seconds' => $elapsed, 'estimated_seconds' => $elapsed,
            'intermediate_stops' => $stops, 'refuel_batches' => $batches, 'refuel_ceiling' => $ceiling, 'total_ceiling' => $ceiling,
            'fuel_cost' => array_sum(array_column($hops, 'fuel_cost')), 'fuel_after' => $fuel,
            'continuation' => $last['continuation'] ?? null, 'continuation_ceiling' => $last['continuation_ceiling'] ?? 0,
        ];
    }

    private function distance(array $from, array $to): float
    {
        return hypot(($from['x'] ?? $from[0]) - ($to['x'] ?? $to[0]), ($from['y'] ?? $from[1]) - ($to['y'] ?? $to[1]));
    }

    private function cruiseSeconds(float $distance, int $speed): int
    {
        return (int) round(round(max(1.0, $distance)) * 25 / $speed + 15);
    }
}
