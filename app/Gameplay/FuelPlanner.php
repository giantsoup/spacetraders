<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\GameObservation;
use App\Models\ObservationHistory;
use Illuminate\Support\Collection;

class FuelPlanner
{
    public const MODEL = 'Client CRUISE upper allowance: ceil(Euclidean distance) + 2 per distinct waypoint; not a server formula.';

    /** @param  Collection<string, GameObservation>|null  $listings  Same-agent/reset listings keyed by symbol for this planning scope. */
    public function leg(Agent $agent, array $ship, string $destination, int $creditReserve = 0, ?int $fuelReserve = null, ?string $policy = null, ?Collection $listings = null, bool $onboardOnly = false): array
    {
        $fuelReserve ??= (int) config('gameplay.fuel_reserve_units');
        $policy ??= config('gameplay.route_evidence');
        if ($ship['nav']['flightMode'] !== 'CRUISE' || ($ship['fuel']['capacity'] < 1 && ! $this->fuelFree($ship))) {
            throw new GameException('Fuel behavior for this mode or zero-capacity ship is unvalidated.');
        }
        $points = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'waypoint')->get()->keyBy('symbol');
        $from = $points->get($ship['nav']['waypointSymbol'])?->data;
        $to = $points->get($destination)?->data;
        if (! $from || ! $to || $from['systemSymbol'] !== $to['systemSymbol'] || $to['systemSymbol'] !== $ship['nav']['systemSymbol']) {
            throw new GameException('Discover a same-system route before planning travel.');
        }
        if ($this->fuelFree($ship)) {
            return ['destination' => $destination, 'continuation' => $destination, 'outbound_fuel' => 0, 'continuation_fuel' => 0,
                'required_fuel' => 0, 'refuel_ceiling' => 0, 'fuel_price_at' => now()->toIso8601String(), 'uncertainty_reserve' => 0,
                'evidence_policy' => 'fuel_free_probe', 'model' => 'Validated fuel-free CRUISE probe, same-system only.',
                'estimated_seconds' => $this->seconds($from, $to, $ship), 'configuration' => self::configuration($ship),
                'distance' => hypot($from['x'] - $to['x'], $from['y'] - $to['y']),
                'from_coordinates' => ['x' => $from['x'], 'y' => $from['y']], 'to_coordinates' => ['x' => $to['x'], 'y' => $to['y']]];
        }
        $outbound = $this->units($agent, $ship, $from, $to, $policy);
        $listings ??= $this->listings($agent);
        $candidates = [];
        if ($onboardOnly && $ship['fuel']['current'] >= $outbound + $fuelReserve) {
            $candidates[] = ['continuation' => $destination, 'continuation_fuel' => 0, 'required_fuel' => $outbound + $fuelReserve,
                'refuel_ceiling' => 0, 'fuel_price_at' => null, 'arrival_state' => 'Sell-only recovery; onboard fuel covers delivery plus reserve.'];
        }
        $quotes = $onboardOnly ? [] : GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'market')->get();
        foreach ([false, true] as $stale) {
            if ($candidates !== []) {
                break;
            }
            foreach ($quotes as $quote) {
                $fresh = $quote->observed_at->gte(now()->subSeconds(config('gameplay.snapshot_seconds')));
                if (! empty($quote->data['invalidated_by_action']) || ($stale
                    ? $fresh || $quote->observed_at->lt(now()->subSeconds(config('gameplay.fuel_quote_max_seconds', 21600)))
                    : ! $fresh)) {
                    continue;
                }
                $seller = $points->get($quote->symbol)?->data;
                $fuel = collect($quote->data['tradeGoods'] ?? [])->firstWhere('symbol', 'FUEL');
                if (! $seller || $seller['systemSymbol'] !== $to['systemSymbol'] || ! $fuel || $fuel['purchasePrice'] < 1 || $fuel['tradeVolume'] < 1) {
                    continue;
                }
                $listing = $listings->get($quote->symbol);
                if ($listing && ! in_array('FUEL', array_column(array_merge($listing->data['exports'], $listing->data['exchange']), 'symbol'), true)) {
                    continue;
                }
                try {
                    $continuation = $this->units($agent, $ship, $to, $seller, $policy);
                } catch (GameException) {
                    continue;
                }
                $required = $outbound + $continuation + $fuelReserve;
                // Whole market units and a 2x allowance bound cost, pending observed billing rules.
                $cost = (int) ceil($ship['fuel']['capacity'] / 100) * $fuel['purchasePrice'] * 2 * ($stale ? 2 : 1);
                if ($required <= $ship['fuel']['current'] && $required <= $ship['fuel']['capacity'] && $agent->credits - $cost >= $creditReserve) {
                    $candidates[] = ['continuation' => $seller['symbol'], 'continuation_fuel' => $continuation, 'required_fuel' => $required,
                        'refuel_ceiling' => $cost, 'fuel_price_at' => $quote->observed_at->toIso8601String(), 'arrival_state' => 'IN_ORBIT; dock and refresh fuel quote before refueling']
                        + ($stale ? ['fuel_price_stale' => true] : []);
                }
            }
        }
        if ($candidates === []) {
            throw new GameException('No fuel-safe continuation to a verified affordable fuel seller. Refresh prices, refuel or replan before departure.');
        }
        usort($candidates, fn ($a, $b) => [$a['required_fuel'], $a['refuel_ceiling'], $a['continuation']] <=> [$b['required_fuel'], $b['refuel_ceiling'], $b['continuation']]);

        return array_merge($candidates[0], ['destination' => $destination, 'outbound_fuel' => $outbound, 'uncertainty_reserve' => $fuelReserve,
            'evidence_policy' => $policy, 'model' => $policy === 'conservative' ? self::MODEL : 'Largest observed consumption on this directed edge for the exact configuration in this reset, plus the separate reserve.',
            'estimated_seconds' => $this->seconds($from, $to, $ship), 'time_source' => 'Community wiki CRUISE candidate; excludes docking, API waits and displaced work.',
            'distance' => hypot($from['x'] - $to['x'], $from['y'] - $to['y']),
            'from_coordinates' => ['x' => $from['x'], 'y' => $from['y']], 'to_coordinates' => ['x' => $to['x'], 'y' => $to['y']],
            'configuration' => self::configuration($ship), 'alternatives' => array_slice($candidates, 1, 3)]);
    }

    /**
     * A stationary ship at a verified fuel seller (FUEL listed and freshly priced there) tops up
     * to full before it leaves. Returns the ship as planned after that top-up, the agent funded
     * after its spending ceiling, and the ceiling itself; null when no top-up applies.
     *
     * @param  Collection<string, GameObservation>|null  $listings
     */
    public function departureRefuel(Agent $agent, array $ship, ?Collection $listings = null): ?array
    {
        if ($ship['nav']['status'] === 'IN_TRANSIT' || $ship['fuel']['capacity'] < 1 || $ship['fuel']['current'] >= $ship['fuel']['capacity']) {
            return null;
        }
        $quote = $this->sellerQuote($agent, $ship['nav']['waypointSymbol'], $listings);
        if (! $quote) {
            return null;
        }
        $ceiling = (int) ceil($ship['fuel']['capacity'] / 100) * $quote['purchasePrice'] * 2;
        $funded = clone $agent;
        $funded->credits -= $ceiling;
        $refueled = $ship;
        $refueled['fuel']['current'] = $ship['fuel']['capacity'];

        return ['ceiling' => $ceiling, 'ship' => $refueled, 'agent' => $funded, 'quote' => $quote];
    }

    /**
     * The fresh FUEL quote at a waypoint whose market listing exports or exchanges FUEL.
     *
     * @param  Collection<string, GameObservation>|null  $listings
     */
    public function sellerQuote(Agent $agent, string $waypoint, ?Collection $listings = null): ?array
    {
        if (! $this->listsFuel($agent, $waypoint, $listings)) {
            return null;
        }
        $market = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'market')->where('symbol', $waypoint)->first();
        $fuel = collect($market?->data['tradeGoods'] ?? [])->firstWhere('symbol', 'FUEL');
        if (! $market || ! $fuel || ! empty($market->data['invalidated_by_action']) || $fuel['purchasePrice'] < 1 || $fuel['tradeVolume'] < 1
            || $market->observed_at->lt(now()->subSeconds(config('gameplay.snapshot_seconds')))) {
            return null;
        }

        return $fuel;
    }

    /** @param  Collection<string, GameObservation>|null  $listings */
    public function listsFuel(Agent $agent, string $waypoint, ?Collection $listings = null): bool
    {
        $listing = ($listings ?? $this->listings($agent))->get($waypoint);

        return $listing && in_array('FUEL', array_column(array_merge($listing->data['exports'] ?? [], $listing->data['exchange'] ?? []), 'symbol'), true);
    }

    public function recoveryLeg(Agent $agent, array $ship, string $destination, int $creditReserve = 0): array
    {
        try {
            return $this->leg($agent, $ship, $destination, $creditReserve);
        } catch (GameException $exception) {
            if ($exception->retryAfter > 0) {
                throw $exception;
            }

            return $this->leg($agent, $ship, $destination, $creditReserve, onboardOnly: true);
        }
    }

    public function fuelFree(array $ship): bool
    {
        return ($ship['frame']['symbol'] ?? null) === 'FRAME_PROBE'
            && ($ship['fuel']['capacity'] ?? null) === 0 && ($ship['fuel']['current'] ?? null) === 0
            && ($ship['nav']['flightMode'] ?? null) === 'CRUISE' && ($ship['engine']['speed'] ?? 0) > 0;
    }

    public function seconds(array $from, array $to, array $ship): ?int
    {
        if ($from['symbol'] === $to['symbol']) {
            return 0;
        }
        $speed = $ship['engine']['speed'] ?? 0;

        return $speed > 0 ? (int) round(round(max(1, hypot($from['x'] - $to['x'], $from['y'] - $to['y']))) * 25 / $speed + 15) : null;
    }

    public function miningLeg(Agent $agent, array $ship, string $destination, array $plan): array
    {
        // The approval established a fuel seller and ceiling. Transit cannot renew that quote.
        // Recheck physical return feasibility here; sale/refuel still require fresh executable prices.
        if ($ship['nav']['flightMode'] !== 'CRUISE' || $ship['fuel']['capacity'] < 1 || ! in_array($destination, [$plan['site'], $plan['market']], true)) {
            throw new GameException('Mining continuation differs from the approved CRUISE route.');
        }
        $points = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'waypoint')->get()->keyBy('symbol');
        $from = $points->get($ship['nav']['waypointSymbol'])?->data;
        $to = $points->get($destination)?->data;
        $buyer = $points->get($plan['market'])?->data;
        if (! $from || ! $to || ! $buyer || $from['systemSymbol'] !== $to['systemSymbol'] || $buyer['systemSymbol'] !== $to['systemSymbol'] || $to['systemSymbol'] !== $ship['nav']['systemSymbol']) {
            throw new GameException('Mining continuation requires the approved same-system waypoints.');
        }
        $outbound = $this->units($agent, $ship, $from, $to);
        $continuation = $this->units($agent, $ship, $to, $buyer);
        $required = $outbound + $continuation + config('gameplay.fuel_reserve_units');
        $ceiling = $plan['route']['fuel_ceiling'];
        if ($required > min($ship['fuel']['current'], $ship['fuel']['capacity']) || $agent->credits < $plan['reserve'] + $ceiling) {
            throw new GameException('Insufficient fuel or protected credits for the approved mining continuation.');
        }

        return ['destination' => $destination, 'continuation' => $plan['market'], 'outbound_fuel' => $outbound,
            'continuation_fuel' => $continuation, 'required_fuel' => $required, 'refuel_ceiling' => $ceiling,
            'uncertainty_reserve' => config('gameplay.fuel_reserve_units'), 'fuel_price_at' => $plan['route']['quote_at'],
            'price_policy' => 'Approval evidence only; refresh prices at the buyer before sale or refuel.',
            'evidence_policy' => config('gameplay.route_evidence'), 'model' => config('gameplay.route_evidence') === 'conservative' ? self::MODEL : 'Largest valid observed consumption on this directed edge and configuration, plus a separate reserve.',
            'estimated_seconds' => $this->seconds($from, $to, $ship), 'distance' => hypot($from['x'] - $to['x'], $from['y'] - $to['y']),
            'from_coordinates' => ['x' => $from['x'], 'y' => $from['y']], 'to_coordinates' => ['x' => $to['x'], 'y' => $to['y']],
            'configuration' => self::configuration($ship)];
    }

    /** @param  Collection<string, GameObservation>|null  $listings  Same-agent/reset listings keyed by symbol for this planning scope. */
    public function options(Agent $agent, array $ship, string $destination, int $reserve = 0, ?Collection $listings = null): array
    {
        $listings ??= $this->listings($agent);
        $options = [];
        try {
            $direct = $this->leg($agent, $ship, $destination, $reserve, listings: $listings);
            $options[] = ['stops' => [$destination], 'fuel_units' => $direct['outbound_fuel'], 'seconds' => $direct['estimated_seconds'], 'refuel_ceiling' => $direct['refuel_ceiling'], 'continuation' => $direct['continuation']];
        } catch (GameException) {
        }
        foreach (GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'market')->get() as $market) {
            if (! empty($market->data['invalidated_by_action']) || $market->observed_at->lt(now()->subSeconds(config('gameplay.snapshot_seconds'))) || ! collect($market->data['tradeGoods'] ?? [])->contains(fn ($good) => $good['symbol'] === 'FUEL' && $good['purchasePrice'] > 0)) {
                continue;
            }
            if (in_array($market->symbol, [$ship['nav']['waypointSymbol'], $destination], true)) {
                continue;
            }
            try {
                $first = $this->leg($agent, $ship, $market->symbol, $reserve, listings: $listings);
                if ($first['continuation'] !== $market->symbol) {
                    continue;
                }
                $funded = clone $agent;
                $funded->credits -= $first['refuel_ceiling'];
                $refueled = $ship;
                $refueled['nav']['waypointSymbol'] = $market->symbol;
                $refueled['fuel']['current'] = $refueled['fuel']['capacity'];
                $second = $this->leg($funded, $refueled, $destination, $reserve, listings: $listings);
                $options[] = ['stops' => [$market->symbol, $destination], 'fuel_units' => $first['outbound_fuel'] + $second['outbound_fuel'],
                    'seconds' => $first['estimated_seconds'] !== null && $second['estimated_seconds'] !== null ? $first['estimated_seconds'] + $second['estimated_seconds'] : null,
                    'refuel_ceiling' => $first['refuel_ceiling'] + $second['refuel_ceiling'], 'continuation' => $second['continuation'],
                    'extra_costs' => 'Docking, API waits and displaced work are additional and unmeasured. A detour is not automatically better.'];
            } catch (GameException) {
            }
        }

        return $options;
    }

    /** @return Collection<string, GameObservation> */
    public function listings(Agent $agent): Collection
    {
        return GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', 'market_listing')->get()->keyBy('symbol');
    }

    public function units(Agent $agent, array $ship, array $from, array $to, ?string $policy = null): int
    {
        if ($from['symbol'] === $to['symbol']) {
            return 0;
        }
        if (($policy ?? config('gameplay.route_evidence')) === 'conservative') {
            return MiningPlanner::fuelEstimate($from, $to);
        }
        $maximum = ObservationHistory::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'travel')
            ->where('data->configuration', self::configuration($ship))->where('data->from', $from['symbol'])->where('data->to', $to['symbol'])
            ->get()->filter(fn ($sample) => self::validSample($sample->data))->max('data.actual_fuel');
        if ($maximum === null) {
            throw new GameException('Measured fuel evidence for this configuration and route is missing.');
        }

        return (int) $maximum;
    }

    public static function validSample(array $data): bool
    {
        $actual = $data['actual_fuel'] ?? null;
        if (! is_int($actual) || $actual < (($data['from'] ?? null) === ($data['to'] ?? null) ? 0 : 1)) {
            return false;
        }
        $reported = $data['reported_consumed'] ?? null;

        return $reported === null || (is_array($reported) && ($reported['amount'] ?? null) === $actual);
    }

    public static function configuration(array $ship): string
    {
        return hash('sha256', json_encode([$ship['frame'], $ship['engine'], $ship['reactor'], $ship['mounts'], $ship['modules'], $ship['fuel']['capacity'], $ship['nav']['flightMode']], JSON_THROW_ON_ERROR));
    }
}
