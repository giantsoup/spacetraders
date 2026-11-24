<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\GameObservation;
use Carbon\CarbonImmutable;

class MiningPlanner
{
    public const SITE_TYPES = ['ASTEROID', 'ASTEROID_FIELD', 'ENGINEERED_ASTEROID'];

    public const FUEL_BLOCKER = 'Insufficient fuel for the conservative route estimate. Refuel manually first.';

    public function capability(array $ship, bool $template = false): ?string
    {
        $mounts = $ship['mounts'] ?? [];
        $modules = $ship['modules'] ?? [];
        if (! collect($mounts)->contains(fn ($m) => str_starts_with($m['symbol'] ?? '', 'MOUNT_MINING_LASER_'))) {
            return 'Missing mining laser.';
        }
        if (! collect($modules)->contains(fn ($m) => str_starts_with($m['symbol'] ?? '', 'MODULE_MINERAL_PROCESSOR_'))) {
            return 'Missing mineral processor.';
        }
        $capacity = $template ? collect($modules)->filter(fn ($m) => str_starts_with($m['symbol'] ?? '', 'MODULE_CARGO_HOLD_'))->sum('capacity') : ($ship['cargo']['capacity'] ?? 0);
        if ($capacity < 1) {
            return 'No usable cargo capacity.';
        }
        if (array_sum(array_map(fn ($m) => $m['requirements']['slots'] ?? 0, $modules)) > ($ship['frame']['moduleSlots'] ?? -1) || count($mounts) > ($ship['frame']['mountingPoints'] ?? -1)) {
            return 'Module slots or mounting points exceed the frame capacity.';
        }
        $parts = array_merge($mounts, $modules, [$ship['engine'] ?? [], $ship['frame'] ?? [], $ship['reactor'] ?? []]);
        $power = array_sum(array_map(fn ($p) => $p['requirements']['power'] ?? 0, $parts));
        $crew = max($ship['crew']['required'] ?? PHP_INT_MAX, array_sum(array_map(fn ($p) => $p['requirements']['crew'] ?? 0, $parts)));
        if ($power > ($ship['reactor']['powerOutput'] ?? -1) || $crew > ($ship['crew'][$template ? 'capacity' : 'current'] ?? -1)) {
            return 'Insufficient crew or reactor power.';
        }
        foreach (['frame', 'engine', 'reactor'] as $part) {
            if (($ship[$part]['condition'] ?? 0) < 0.5 || ($ship[$part]['integrity'] ?? 0) < 0.5) {
                return 'Ship condition or integrity below the 50% operating floor.';
            }
        }

        return null;
    }

    public function route(Agent $agent, array $ship, string $site, string $market, ?array $surveyDeposits = null): array
    {
        $points = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'waypoint')->get()->mapWithKeys(fn ($o) => [$o->symbol => $o->data])->all();
        $quote = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'market')->where('symbol', $market)->first();

        return $this->evaluateRoute($ship, $site, $market, $points, $quote?->data ?? [], $quote?->observed_at, $agent->credits, CarbonImmutable::now(), $agent, $surveyDeposits);
    }

    public function evaluateRoute(array $ship, string $site, string $market, array $points, array $quote, ?CarbonImmutable $observedAt, int $credits, CarbonImmutable $now, ?Agent $agent = null, ?array $surveyDeposits = null): array
    {
        if ($reason = $this->capability($ship)) {
            throw new GameException($reason);
        }
        if ($ship['nav']['flightMode'] !== 'CRUISE') {
            throw new GameException('Select a ship already in CRUISE mode. Flight-mode management is outside this MVP.');
        }
        $start = $points[$ship['nav']['waypointSymbol']] ?? null;
        $mine = $points[$site] ?? null;
        $buyer = $points[$market] ?? null;
        if (! $start || ! $mine || ! $buyer || $mine['systemSymbol'] !== $ship['nav']['systemSymbol'] || $buyer['systemSymbol'] !== $mine['systemSymbol']) {
            throw new GameException('Discover a complete same-system route first.');
        }
        if ($reason = self::hazard($mine)) {
            throw new GameException($reason);
        }
        $fuel = $this->routeFuel($ship, $start, $mine, $buyer, $agent);
        if ($fuel === null) {
            throw new GameException(self::FUEL_BLOCKER);
        }

        return $this->quotedRoute($site, $market, $quote, $observedAt, $credits, $now, $ship['fuel']['capacity'], $fuel, $this->possibleYields($ship), $surveyDeposits);
    }

    public function possibleYields(array $ship): array
    {
        $deposits = [];
        foreach ($ship['mounts'] as $mount) {
            if (str_starts_with($mount['symbol'] ?? '', 'MOUNT_MINING_LASER_')) {
                $deposits = array_merge($deposits, empty($mount['deposits']) ? $this->allYields() : $mount['deposits']);
            }
        }

        return array_values(array_unique($deposits));
    }

    public function allYields(): array
    {
        return json_decode(file_get_contents(resource_path('data/mining-yields.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function routeFuel(array $ship, array $start, array $mine, array $buyer, ?Agent $agent = null): ?int
    {
        $units = fn ($from, $to) => $agent ? app(FuelPlanner::class)->units($agent, $ship, $from, $to) : self::fuelEstimate($from, $to);
        $outbound = $units($mine, $buyer) + config('gameplay.fuel_reserve_units');
        $initial = $units($start, $mine) + $outbound;
        $repeat = $units($buyer, $mine) + $outbound;

        return $ship['fuel']['capacity'] >= max($initial, $repeat) && $ship['fuel']['current'] >= $initial ? $initial : null;
    }

    public static function hasFreshPrices(array $quote, ?CarbonImmutable $observedAt, CarbonImmutable $now): bool
    {
        return $observedAt && $observedAt->betweenIncluded($now->subSeconds(config('gameplay.snapshot_seconds')), $now)
            && empty($quote['invalidated_by_action']) && ! empty($quote['tradeGoods']);
    }

    public function quotedRoute(string $site, string $market, array $quote, ?CarbonImmutable $observedAt, int $credits, CarbonImmutable $now, int $capacity, int $fuel, array $possibleYields, ?array $surveyDeposits = null): array
    {
        if (! self::hasFreshPrices($quote, $observedAt, $now)) {
            throw new GameException('Buyer prices are unknown or stale. Synchronize at that market; approve a manual scouting navigation first if no ship is there.');
        }
        $goods = collect($quote['tradeGoods'])->keyBy('symbol');
        $positiveBid = fn ($symbol) => ($goods[$symbol]['sellPrice'] ?? 0) > 0;
        $deposits = $surveyDeposits ?? $possibleYields;
        $knownBuyers = array_values(array_filter($deposits, $positiveBid));
        if ($knownBuyers === [] || ($surveyDeposits !== null && array_filter($possibleYields, $positiveBid) === [])) {
            throw new GameException("No verified buyer for this laser's possible yields.");
        }
        $fuelPrice = $goods['FUEL']['purchasePrice'] ?? null;
        if (! is_int($fuelPrice) || $fuelPrice < 1) {
            throw new GameException('The buyer must offer observed fuel for a safe return.');
        }
        $fuelCeiling = (int) ceil($capacity / 100) * $fuelPrice * 2;

        return [
            'site' => $site, 'market' => $market, 'possible_yields' => $deposits,
            'known_buyers' => $knownBuyers,
            'unknown_yields' => array_values(array_diff($deposits, $knownBuyers)),
            'fuel_estimate' => $fuel, 'fuel_ceiling' => $fuelCeiling, 'minimum_reserve' => max($fuelCeiling, (int) ceil($credits * 0.01)),
            'quote_at' => $observedAt->toIso8601String(),
        ];
    }

    public function surveyYields(Agent $agent, array $plan): ?array
    {
        return empty($plan['survey_signature'])
            ? null
            : app(SurveyPool::class)->depositSymbols($agent, $plan['survey_signature'], $plan['site']);
    }

    public function requiredDepartureYields(Agent $agent, array $plan): array
    {
        return $this->surveyYields($agent, $plan) ?? $this->allYields();
    }

    public function missingBids(array $market, array $required): array
    {
        $bids = array_column($market['tradeGoods'] ?? [], 'sellPrice', 'symbol');

        return array_values(array_filter($required, fn ($symbol) => ($bids[$symbol] ?? 0) <= 0));
    }

    public function assertDepartureBids(array $market, string $buyer, array $required): void
    {
        if (empty($market['tradeGoods'])) {
            throw new GameException("Mining departure held: $buyer has no fresh trade prices.");
        }
        $missing = $this->missingBids($market, $required);
        if ($required === [] || $missing !== []) {
            $detail = $missing ? 'missing positive bids for '.implode(', ', $missing) : 'required yields could not be established';
            throw new GameException("Mining departure held at $buyer: $detail.");
        }
    }

    public static function startsLoad(array $ship, array $plan, array $totals, ?string $command): bool
    {
        return $totals['phase'] === 'mine' && ($totals['load_attempts'] ?? 0) === 0
            && $ship['nav']['waypointSymbol'] !== $plan['site']
            && in_array($command, ['orbit', 'navigate'], true);
    }

    public function next(array $ship, array $plan, array $totals, array $market, CarbonImmutable $now): AutomationStep
    {
        if ($reason = $this->capability($ship)) {
            return AutomationStep::blocked($reason);
        }
        if ($ship['nav']['status'] === 'IN_TRANSIT') {
            $arrival = CarbonImmutable::parse($ship['nav']['route']['arrival']);

            return AutomationStep::wait($arrival->greaterThan($now) ? $arrival : $now->addSeconds(5));
        }
        if ($totals['no_progress'] >= 1) {
            return AutomationStep::blocked('Extraction produced no usable cargo. No further attempts are approved.');
        }
        $inventory = self::inventory($ship);
        $expected = $plan['initial_inventory'];
        foreach ($totals['extracted'] as $symbol => $units) {
            $expected[$symbol] = ($expected[$symbol] ?? 0) + $units - ($totals['sold'][$symbol] ?? 0);
        }
        $expected = array_filter($expected);
        ksort($expected);
        ksort($inventory);
        if ($expected !== $inventory) {
            return AutomationStep::blocked('Cargo changed outside this run. Reconcile retained inventory before continuing.');
        }
        $phase = $totals['phase'];
        $loadAttempts = $totals['load_attempts'] ?? 0;
        $limit = $plan['max_extractions'] ?? 1;
        if ($phase === 'mine' && $loadAttempts > 0 && array_diff(array_keys($inventory), $plan['route']['known_buyers']) !== []) {
            return AutomationStep::blocked('An extraction output has no verified buyer. Retain cargo; further extraction is not justified.');
        }
        $headroom = $plan['extraction_headroom'] ?? $ship['cargo']['capacity'];
        $siteExpired = isset($totals['load_started_at']) && $now->gte(CarbonImmutable::parse($totals['load_started_at'])->addSeconds($plan['site_seconds'] ?? 300));
        if ($phase === 'mine' && $loadAttempts > 0 && ($loadAttempts >= $limit || $ship['cargo']['capacity'] - $ship['cargo']['units'] < $headroom || $siteExpired)) {
            return AutomationStep::depart();
        }
        $destination = $phase === 'mine' ? $plan['route']['site'] : $plan['route']['market'];
        if ($ship['nav']['waypointSymbol'] !== $destination) {
            return $ship['nav']['status'] === 'DOCKED' ? AutomationStep::act('orbit') : AutomationStep::act('navigate', ['waypointSymbol' => $destination]);
        }
        if ($phase === 'mine') {
            if ($ship['nav']['status'] === 'DOCKED') {
                return AutomationStep::act('orbit');
            }
            $ready = CarbonImmutable::parse($ship['cooldown']['readyAt']);
            if ($ready->greaterThan($now)) {
                return AutomationStep::wait($ready);
            }
            if ($loadAttempts === 0 && $ship['cargo']['units'] !== 0) {
                return AutomationStep::blocked('Mining starts with empty cargo to reserve full capacity for an unknown yield. Sell or move existing cargo manually.');
            }

            if ($ship['cargo']['capacity'] - $ship['cargo']['units'] < $headroom) {
                return AutomationStep::blocked('Approved extraction headroom is unavailable. Cargo retained.');
            }

            return isset($plan['survey_signature']) && $plan['survey_signature'] !== ''
                ? AutomationStep::act('mine_survey', ['surveySignature' => $plan['survey_signature'], 'surveyWaypoint' => $plan['route']['site']])
                : AutomationStep::act('mine');
        }
        if ($ship['nav']['status'] !== 'DOCKED') {
            return AutomationStep::act('dock');
        }
        $quotes = collect($market['tradeGoods'] ?? [])->keyBy('symbol');
        foreach ($totals['extracted'] as $symbol => $units) {
            $remaining = $units - ($totals['sold'][$symbol] ?? 0);
            if ($remaining > 0) {
                $quote = $quotes[$symbol] ?? null;
                if (! $quote || $quote['sellPrice'] < 1) {
                    return AutomationStep::blocked('No verified buyer for '.$symbol.'. Cargo retained.');
                }
                $units = min($remaining, $quote['tradeVolume']);

                return AutomationStep::act('sell', ['symbol' => $symbol, 'units' => $units, 'minTotal' => $units * $quote['sellPrice']]);
            }
        }
        $missingFuel = $ship['fuel']['capacity'] - $ship['fuel']['current'];
        if ($missingFuel > 0) {
            $fuel = $quotes['FUEL'] ?? null;
            if (! $fuel) {
                return AutomationStep::blocked('Refueling quote is unavailable.');
            }
            $units = min($missingFuel, $fuel['tradeVolume'] * 100);
            $ceiling = min($plan['route']['fuel_ceiling'], $plan['max_spend'] - $totals['fuel_cost']);
            if ((int) ceil($units / 100) * $fuel['purchasePrice'] > $ceiling) {
                return AutomationStep::blocked('Refueling exceeds the approved remaining spending ceiling.');
            }

            return AutomationStep::act('refuel', ['units' => $units, 'maxTotal' => $ceiling, 'reserve' => $plan['reserve']]);
        }

        return AutomationStep::trip();
    }

    public static function fuelEstimate(array $from, array $to): int
    {
        return $from['symbol'] === $to['symbol'] ? 0 : (int) ceil(hypot($from['x'] - $to['x'], $from['y'] - $to['y'])) + 2;
    }

    public static function hazard(array $waypoint): ?string
    {
        $warnings = array_intersect(array_column(array_merge($waypoint['traits'] ?? [], $waypoint['modifiers'] ?? []), 'symbol'), ['UNCHARTED', 'UNSTABLE', 'CRITICAL_LIMIT', 'STRIPPED', 'DEBRIS_FIELD']);
        if (! in_array($waypoint['type'], self::SITE_TYPES, true) || $warnings !== []) {
            return 'Mining site is unverified, unsafe or inaccessible'.($warnings ? ': '.implode(', ', $warnings) : '.');
        }

        return null;
    }

    public static function inventory(array $ship): array
    {
        return array_column($ship['cargo']['inventory'], 'units', 'symbol');
    }
}
