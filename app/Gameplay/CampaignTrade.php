<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\ReconMission;
use App\Models\Ship;
use App\Models\ShipAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CampaignTrade
{
    /** Shadow value floor (not enforced): absolute planned CR per minute. */
    public const FLOOR_CR_PER_MINUTE = 1500;

    /** Shadow value floor (not enforced): share of the trailing median realized CR per minute. */
    public const FLOOR_MEDIAN_SHARE = 0.3;

    public const FLOOR_TRAILING_LOADS = 10;

    public function results(CampaignEpisode $episode): array
    {
        $saved = CampaignEvent::where('campaign_episode_id', $episode->id)->whereIn('kind', ['load_result', 'recovery_result'])->orderBy('id')->pluck('data')->all();
        $known = CampaignEvent::where('campaign_episode_id', $episode->id)->whereIn('kind', ['load_result', 'recovery_result'])->select('data->run_id');
        $runs = AutomationRun::where('campaign_episode_id', $episode->id)->whereIn('strategy', ['trade', 'sell_aboard'])
            ->whereNotNull('started_at')->whereNotIn('id', $known)->where(fn ($query) => $query->whereNull('plan->recovery_of')->orWhereNotIn('plan->recovery_of', $known))->orderBy('id')->get();
        $results = $saved;
        $label = $runs->isEmpty() ? 'C0' : FleetClaims::label($episode);
        foreach ($runs as $run) {
            if ($run->active_agent_id || ! $run->started_at || ! in_array($run->state->value, ['completed', 'stopped', 'blocked'], true)) {
                continue;
            }
            $recovery = ! empty($run->plan['sell_only']);
            if ($run->strategy !== 'trade' && (! $recovery || ! empty($run->plan['recovery_of']))) {
                continue;
            }
            $good = $recovery ? array_key_first($run->plan['initial_inventory']) : $run->plan['symbol'];
            $bought = $recovery ? $run->plan['initial_inventory'][$good] : ($run->totals['purchased'][$good] ?? 0);
            if ($bought < 1) {
                continue;
            }
            $parts = $runs->filter(fn ($part) => $part->id === $run->id || ($part->plan['recovery_of'] ?? null) === $run->id);
            if ($parts->contains(fn ($part) => $part->active_agent_id) || $parts->sum(fn ($part) => $part->totals['sold'][$good] ?? 0) < $bought) {
                continue;
            }
            $cost = $recovery ? ($run->plan['cargo_cost'] ?? 0) : ($run->totals['purchase_cost'] ?? 0);
            $fuel = $this->loadFuel($parts);
            $cashNet = $parts->sum(fn ($part) => $part->totals['sale_proceeds'] ?? 0) - $fuel['cost'] - $cost;
            $unrefilled = max(0, $fuel['travel'] - $fuel['units']);
            $fuelPrice = GameObservation::where('agent_id', $episode->agent_id)->where('reset_id', $episode->reset_id)->where('kind', 'market')
                ->whereIn('symbol', [$run->plan['site'], $run->plan['market']])->orderByDesc('observed_at')->orderByDesc('id')->get()->unique('symbol')
                ->flatMap(fn ($row) => $row->data['tradeGoods'] ?? [])->where('symbol', 'FUEL')->max('purchasePrice');
            $fuelAllowance = $unrefilled > 0 ? ($fuelPrice ? TradeFuelCost::consumption($unrefilled, $fuelPrice) : ($run->plan['route']['fuel_ceiling'] ?? 0)) : 0;
            $net = $cashNet - $fuelAllowance;
            $sales = ShipAction::whereIn('automation_run_id', $parts->pluck('id'))->where('command', 'sell')->where('state', 'confirmed')->get();
            $proofEligible = ! $sales->contains(fn ($sale) => ($sale->before_state['ship']['nav']['waypointSymbol'] ?? null) !== $run->plan['market']);
            $prediction = $run->plan['campaign_trade'] ?? [];
            $forced = $parts->contains(fn ($part) => ! empty($part->plan['forced_sale']));
            $elapsed = $parts->sum(fn ($part) => $part->totals['elapsed_seconds'] ?? (int) $part->started_at->diffInSeconds($part->updated_at));
            $results[] = ['run_id' => $run->id, 'good' => $good, 'buyer' => $run->plan['market'], 'net' => $net,
                'cash_net' => $cashNet, 'unrefilled_fuel_allowance' => $fuelAllowance, 'fuel_cost' => $fuel['cost'],
                'departure_fuel_cost' => $fuel['departure_cost'], 'proof_eligible' => $proofEligible,
                'proof' => $prediction['proof'] ?? false, 'predicted_margin' => $prediction['predicted_margin'] ?? null,
                'realized_ratio' => ($prediction['predicted_margin'] ?? 0) > 0 ? $net / $prediction['predicted_margin'] : null,
                'standalone_recovery' => $recovery, 'forced_sale' => $forced, 'losing' => $net < 0,
                'elapsed_seconds' => $elapsed, 'realized_cr_per_minute' => $elapsed > 0 ? round($net / ($elapsed / 60), 2) : null,
                'units' => $bought, 'lot_bound' => $prediction['lot_bound'] ?? null, 'shadow_lot_bound' => $prediction['shadow_lot_bound'] ?? null,
                'optimal_units' => $prediction['optimal_units'] ?? null, 'optimal_margin' => $prediction['optimal_margin'] ?? null,
                'below_value_floor' => $prediction['value_floor']['below_floor'] ?? null,
                'finished_at' => $parts->max('updated_at')->toIso8601String(),
                'ship' => $run->ship_symbol, 'arm' => $label, 'source' => $run->plan['site'] ?? null,
                'purchase_cost' => $cost, 'sale_proceeds' => $parts->sum(fn ($part) => $part->totals['sale_proceeds'] ?? 0),
                'started_at' => $parts->min('started_at')->toIso8601String(), 'seconds' => (int) $parts->min('started_at')->diffInSeconds($parts->max('updated_at'), true),
                'best_margin_basis' => $prediction['best_margin_basis'] ?? 'forecast', 'selection_basis' => $prediction['selection_basis'] ?? null,
                'best_available_score' => $prediction['best_available_score'] ?? null, 'best_unclaimed_score' => $prediction['best_unclaimed_score'] ?? null,
                'best_available' => $prediction['best_available'] ?? null, 'best_unclaimed' => $prediction['best_unclaimed'] ?? null,
                'limiting_factor' => $prediction['limiting_factor'] ?? null, 'shortened' => isset($run->plan['original_units']),
                'shortened_reason' => $run->plan['shortened_reason'] ?? null, 'ask_over_prediction' => $run->plan['ask_over_prediction'] ?? null];
        }
        usort($results, fn ($left, $right) => [$left['finished_at'], $left['run_id']] <=> [$right['finished_at'], $right['run_id']]);

        return $results;
    }

    /**
     * Fuel a load is charged for: bills after each part's first navigate, capped at the fuel that
     * the load's own travel burned. A top-up before departure, or past the load's own burn, refills
     * fuel burned earlier (by earlier loads, which already recorded it as their unrefilled
     * allowance, or by positioning, sell-aboard or blocked runs), so it is not charged here. Run
     * totals and credit accounting still carry every bill; departure_cost reports the
     * pre-departure part for reconciliation.
     *
     * @return array{cost: int, units: int, travel: int, departure_cost: int}
     */
    private function loadFuel($parts): array
    {
        $actions = ShipAction::whereIn('automation_run_id', $parts->pluck('id'))->whereIn('command', ['navigate', 'refuel'])
            ->where('state', 'confirmed')->orderBy('id')->get()->groupBy('automation_run_id');
        $travel = $parts->sum(fn ($part) => $part->totals['travel_fuel'] ?? 0);
        $cost = 0;
        $units = 0;
        $departure = 0;
        foreach ($parts as $part) {
            $refuels = collect();
            $navigate = null;
            foreach ($actions->get($part->id, collect()) as $action) {
                if ($action->command === 'navigate') {
                    $navigate = $action;
                    break;
                }
                $refuels->push($action);
            }
            $departureCost = $refuels->sum(fn ($action) => $action->outcome['transaction_total'] ?? 0);
            // Units are the actual tank change, as credit accounting counts them: nothing burns fuel
            // before the first navigate, so the tank at that navigate less the tank before the first
            // top-up is what the top-ups added. With no navigate, every top-up is pre-departure.
            $before = $refuels->first()?->before_state['ship']['fuel']['current'] ?? null;
            $after = $navigate?->before_state['ship']['fuel']['current'] ?? null;
            $departureUnits = match (true) {
                $refuels->isEmpty() => 0,
                ! $navigate => $part->totals['fuel_units'] ?? 0,
                $before !== null && $after !== null => max(0, $after - $before),
                default => $refuels->sum(fn ($action) => $action->arguments['units'] ?? 0),
            };
            $departure += $departureCost;
            $cost += max(0, ($part->totals['fuel_cost'] ?? 0) - $departureCost);
            $units += max(0, ($part->totals['fuel_units'] ?? 0) - $departureUnits);
        }
        if ($units > $travel && $units > 0) {
            $cost = (int) round($cost * $travel / $units);
            $units = $travel;
        }

        return ['cost' => $cost, 'units' => $units, 'travel' => $travel, 'departure_cost' => $departure];
    }

    public function recordResults(CampaignEpisode $episode): void
    {
        app(HostLock::class)->retry('campaign-results-'.$episode->id, fn () => DB::transaction(function () use ($episode) {
            $known = array_fill_keys(CampaignEvent::where('campaign_episode_id', $episode->id)->whereIn('kind', ['load_result', 'recovery_result'])->pluck('data')->map(fn ($data) => $data['run_id'])->all(), true);
            foreach ($this->results($episode) as $result) {
                $kind = ! empty($result['standalone_recovery']) ? 'recovery_result' : 'load_result';
                if (! isset($known[$result['run_id']])) {
                    app(CampaignControl::class)->event($episode->grant(), $episode, $kind, $result);
                }
            }
        }));
    }

    public function harvestArms(?string $reset = null): void
    {
        $reset ??= CampaignEpisode::latest('id')->value('reset_id');
        $grants = CampaignGrant::whereNotNull('limits->arm')->select('id');
        foreach (CampaignEpisode::where('reset_id', $reset)->whereIn('campaign_grant_id', $grants)->get() as $episode) {
            $this->recordResults($episode);
        }
    }

    public function mode(CampaignEpisode $episode, string $good, string $buyer, ?array $results = null): string
    {
        $proof = null;
        $scaled = false;
        $minimum = $episode->grant()->revision()->body['trade']['proof']['min_realized_ratio'];
        foreach ($results ?? $this->learningResults($episode) as $result) {
            // A losing load re-proves only its own good/buyer pair.
            if (! empty($result['standalone_recovery']) || $result['good'] !== $good || $result['buyer'] !== $buyer
                || (! empty($result['shortened']) && ! self::losing($result))) {
                continue;
            }
            if (self::losing($result)) {
                $proof = null;
                $scaled = false;
            } elseif ($result['proof']) {
                $proof = $result['proof_eligible'] && $result['realized_ratio'] >= $minimum;
                $scaled = false;
            } elseif ($proof) {
                $scaled = true;
            }
        }

        return ! $proof ? 'proof' : ($scaled ? 'scaled' : 'first_scaled');
    }

    /**
     * Results that teach an episode proof and losses: its own, and for an arm also C0's open
     * episode of the same reset, read only, merged in finish order. C0 never reads arm results.
     */
    public function learningResults(CampaignEpisode $episode): array
    {
        $results = $this->results($episode);
        $reference = FleetClaims::arm($episode->grant()) ? app(FleetClaims::class)->reference($episode) : null;
        if (! $reference) {
            return $results;
        }
        $results = array_merge($this->results($reference), $results);
        usort($results, fn ($left, $right) => [$left['finished_at'], $left['run_id']] <=> [$right['finished_at'], $right['run_id']]);

        return $results;
    }

    /**
     * A load loses only when its net, unrefilled fuel allowance included, is negative. A forced
     * sale that still covered its costs is not a loss. Saved results recorded before this rule
     * flagged every forced sale, so the stored flag is re-derived from net.
     */
    public static function losing(array $result): bool
    {
        return ($result['net'] ?? 0) < 0;
    }

    public function averageCosts(Agent $agent, string $ship): array
    {
        $lots = [];
        $actions = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('ship_symbol', $ship)->where('state', 'confirmed')->orderBy('id')->get();
        foreach ($actions as $action) {
            $good = $action->arguments['symbol'] ?? $action->outcome['yield']['symbol'] ?? null;
            if (! $good) {
                continue;
            }
            $units = $action->outcome['units'] ?? $action->outcome['yield']['units'] ?? $action->arguments['units'] ?? 0;
            $lot = $lots[$good] ?? ['units' => 0, 'cost' => 0];
            if (in_array($action->command, ['buy', 'mine', 'mine_survey'], true)) {
                $lot['units'] += $units;
                $lot['cost'] += $action->command === 'buy' ? ($action->outcome['transaction_total'] ?? 0) : 0;
            } elseif ($action->command === 'sell' && $lot['units'] > 0) {
                $lot['cost'] *= max(0, $lot['units'] - $units) / $lot['units'];
                $lot['units'] = max(0, $lot['units'] - $units);
            }
            $lots[$good] = $lot;
        }

        return array_map(fn ($lot) => $lot['units'] > 0 ? $lot['cost'] / $lot['units'] : 0, $lots);
    }

    public function fuelReserve(Agent $agent, string $symbol, string $buyer): int
    {
        $ship = Ship::where('agent_id', $agent->id)->where('symbol', $symbol)->firstOrFail()->snapshot;

        return app(FuelPlanner::class)->leg($agent, $ship, $buyer, 0)['refuel_ceiling'];
    }

    public function quoteLife(Agent $agent, array $plan, array $totals): void
    {
        if (empty($plan['campaign_trade'])) {
            return;
        }
        $remaining = $plan['units'] - ($totals['purchased'][$plan['symbol']] ?? 0);
        if ($remaining <= 0) {
            return;
        }
        $buyerAge = OperationPlanner::buyerAge($plan);
        $ask = app(OperationPlanner::class)->quote($agent, $plan['site'], $plan['symbol']);
        $bid = app(OperationPlanner::class)->quote($agent, $plan['market'], $plan['symbol'], $buyerAge);
        $seconds = (int) ceil($remaining / max(1, $ask['tradeVolume'])) * 10 + 10;
        // A committed arm trade may load against an older buyer quote, up to its bounded age; the
        // source quote, read where the ship stands, keeps the playbook's age.
        foreach ([[$ask, $plan['campaign_trade']['quote_max_age_seconds']], [$bid, $buyerAge ?? $plan['campaign_trade']['quote_max_age_seconds']]] as [$quote, $age]) {
            if (CarbonImmutable::parse($quote['observed_at'])->addSeconds($age)->lte(now()->addSeconds($seconds))) {
                throw new StaleQuote('Freshness hold: quotes would expire during planned loading.');
            }
        }
    }

    public function prediction(int $units, array $ask, array $bid, float $decay, int $fuel): array
    {
        $cost = 0;
        $revenue = 0;
        for ($remaining = $units, $batch = 0; $remaining > 0; $batch++) {
            $count = min($remaining, $ask['tradeVolume']);
            $cost += $count * (int) ceil($ask['purchasePrice'] / ((1 - $decay) ** $batch));
            $remaining -= $count;
        }
        for ($remaining = $units, $batch = 0; $remaining > 0; $batch++) {
            $count = min($remaining, $bid['tradeVolume']);
            $revenue += $count * (int) floor($bid['sellPrice'] * ((1 - $decay) ** $batch));
            $remaining -= $count;
        }

        return ['predicted_cost' => $cost, 'predicted_revenue' => $revenue, 'predicted_margin' => $revenue - $cost - $fuel];
    }

    /**
     * Shadow lot sizing: the unit count, up to the lot rank() buys, with the best predicted margin
     * per planned minute under the pair's decay. Recorded only; it does not change the purchase.
     *
     * @param  callable(int): (int|float)  $duration  Planned run seconds for a unit count.
     * @return array{units: int, margin: int, seconds: int|float, score: float}
     */
    public function optimalLot(int $units, array $ask, array $bid, float $decay, int $fuel, callable $duration): array
    {
        $best = ['units' => 0, 'margin' => 0, 'seconds' => 0, 'score' => 0.0];
        for ($count = 1; $count <= $units; $count++) {
            $margin = $this->prediction($count, $ask, $bid, $decay, $fuel)['predicted_margin'];
            $seconds = $duration($count);
            $score = $margin / max(1, $seconds / 60);
            if ($best['units'] === 0 || $score >= $best['score']) {
                $best = ['units' => $count, 'margin' => $margin, 'seconds' => $seconds, 'score' => $score];
            }
        }

        return $best;
    }

    /**
     * Shadow value floor for the chosen candidate: flags planned CR/min below an absolute floor or
     * below a share of the trailing median realized CR/min of recent loads. Never enforced.
     */
    public function valueFloor(CampaignEpisode $episode, array $candidate, ?array $results = null): array
    {
        $planned = $candidate['score'] ?? $candidate['predicted_margin'] / max(1, $candidate['seconds'] / 60);
        $recent = collect($results ?? $this->results($episode))
            ->filter(fn ($result) => empty($result['standalone_recovery']) && isset($result['realized_cr_per_minute']))
            ->take(-self::FLOOR_TRAILING_LOADS)->pluck('realized_cr_per_minute');
        $median = $recent->isEmpty() ? null : (float) $recent->median();
        $reasons = [];
        if ($planned < self::FLOOR_CR_PER_MINUTE) {
            $reasons[] = 'below_absolute_floor';
        }
        if ($median !== null && $median > 0 && $planned < $median * self::FLOOR_MEDIAN_SHARE) {
            $reasons[] = 'below_trailing_median_share';
        }

        return ['planned_cr_per_minute' => round($planned, 2), 'trailing_median_cr_per_minute' => $median === null ? null : round($median, 2),
            'trailing_loads' => $recent->count(), 'below_floor' => $reasons !== [], 'reasons' => $reasons];
    }

    public function decay(CampaignEpisode $episode, string $good, string $buyer): float
    {
        $episodes = [$episode->id];
        if (FleetClaims::arm($episode->grant()) && $reference = app(FleetClaims::class)->reference($episode)) {
            $episodes[] = $reference->id;
        }
        $sales = ShipAction::whereIn('automation_run_id', AutomationRun::whereIn('campaign_episode_id', $episodes)->select('id'))->where('command', 'sell')->where('state', 'confirmed')->orderBy('id')->get();
        $previous = null;
        $drops = [];
        foreach ($sales as $sale) {
            if (($sale->arguments['symbol'] ?? null) !== $good || ($sale->before_state['ship']['nav']['waypointSymbol'] ?? null) !== $buyer) {
                continue;
            }
            $units = $sale->outcome['units'] ?? 0;
            if ($units < 1 || (isset($sale->before_state['estimate']['quote']['tradeVolume']) && $units !== $sale->before_state['estimate']['quote']['tradeVolume'])) {
                $previous = null;

                continue;
            }
            $price = $sale->outcome['transaction_total'] / $units;
            if ($previous && $previous['run'] === $sale->automation_run_id && $previous['units'] === $units && $previous['price'] > 0) {
                $drops[] = max(0, min(0.95, 1 - $price / $previous['price']));
            }
            $previous = ['run' => $sale->automation_run_id, 'units' => $units, 'price' => $price];
        }

        return $drops ? array_sum($drops) / count($drops) : $episode->grant()->revision()->body['trade']['lot']['default_batch_decay'];
    }

    public function rank(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $ship, array &$considered): array
    {
        $rules = $grant->revision()->body['trade'];
        $markets = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'market')->orderBy('observed_at')->orderBy('id')->get()->keyBy('symbol');
        $pairs = app(OpportunityAssessor::class)->report($agent, 'trade', $ship['symbol'], true)['opportunities'];
        $state = $episode->state ?? [];
        $ranked = [];
        $results = $this->learningResults($episode);
        $learning = [];
        $claims = app(FleetClaims::class);
        $arm = FleetClaims::arm($grant);
        $taken = $arm || config('gameplay.campaign_claims', true) ? $claims->taken($episode) : [];
        // A follower works only its leader's current pair, claims aside, and waits without one.
        if ($arm['follow'] ?? null) {
            $leader = $claims->leader($episode, $arm['follow']);
            $lead = $leader ? ($claims->pairs($leader)[0] ?? null) : null;
            if (! $lead) {
                $considered[] = ['strategy' => 'trade', 'rejected' => 'Follower waiting for leader claim.'];

                return [];
            }
            $taken = [];
            $pairs = array_values(array_filter($pairs, fn ($pair) => [$pair['good'], $pair['source'], $pair['market']] === [$lead['good'], $lead['source'], $lead['buyer']]));
        }
        $exposure = $arm ? $this->allowance($grant, $agent, ship: $ship['symbol']) : INF;
        $available = null;
        $unclaimed = null;
        $compare = fn (array $left, array $right) => $right['score'] <=> $left['score'] ?: [$left['key'], $left['source']] <=> [$right['key'], $right['source']];
        // Docked at a verified fuel seller, the run tops up first, so legs start from a full tank
        // and the refuel ceiling comes out of the credits available for cargo.
        $departure = app(FuelPlanner::class)->departureRefuel($agent, $ship);
        foreach ($pairs as $pair) {
            $key = $pair['good'].'@'.$pair['market'];
            $ask = collect($markets->get($pair['source'])?->data['tradeGoods'] ?? [])->firstWhere('symbol', $pair['good']);
            $bid = collect($markets->get($pair['market'])?->data['tradeGoods'] ?? [])->firstWhere('symbol', $pair['good']);
            if (! $ask || ! $bid || $ask['purchasePrice'] < 1 || $bid['sellPrice'] <= $ask['purchasePrice'] || min($ask['tradeVolume'], $bid['tradeVolume']) < 1) {
                $considered[] = $pair + ['rejected' => 'No observed positive spread.'];

                continue;
            }
            $state['first_seen'][$key] ??= $bid['sellPrice'];
            try {
                $learning[$key] ??= ['mode' => $this->mode($episode, $pair['good'], $pair['market'], $results),
                    'decay' => $this->decay($episode, $pair['good'], $pair['market'])];
                $projection = $this->projectPair($agent, $ship, $departure, $pair, $ask, $bid, $rules,
                    $grant->limits['run_max_minutes'] * 60, $learning[$key]['mode'], $learning[$key]['decay'], $exposure, (bool) $arm);
                if (isset($projection['long_run'])) {
                    $considered[] = $projection['long_run'];

                    continue;
                }
                $candidate = $projection['candidate'];
                if (! $available || $compare($candidate, $available) < 0) {
                    $available = $candidate;
                }
                if (isset($taken[$pair['good'].'@'.$pair['source']]) || isset($taken[$pair['good'].'@'.$pair['market']])) {
                    $considered[] = $candidate + ['rejected' => 'Claimed by another ship.', 'claimed_by' => $taken[$pair['good'].'@'.$pair['source']] ?? $taken[$pair['good'].'@'.$pair['market']]];

                    continue;
                }
                if (! $unclaimed || $compare($candidate, $unclaimed) < 0) {
                    $unclaimed = $candidate;
                }
                if (($state['current_pair'] ?? null) === $key && $bid['sellPrice'] <= $state['first_seen'][$key] * $rules['switch']['bid_below_first_seen']) {
                    $considered[] = $candidate + ['rejected' => 'Switch: buyer bid at or below first-seen threshold.'];

                    continue;
                }
                $ranked[] = $candidate;
            } catch (GameException $exception) {
                $considered[] = $pair + ['rejected' => $exception->getMessage()];
            }
        }
        usort($ranked, $compare);
        if ($ranked) {
            $current = collect($ranked)->firstWhere('key', $state['current_pair'] ?? null);
            if ($current && $ranked[0]['key'] !== $current['key'] && $ranked[0]['score'] < $current['score'] * $rules['switch']['better_pair_ratio']) {
                $ranked = array_values(array_filter($ranked, fn ($row) => $row !== $current));
                array_unshift($ranked, $current);
            }
        }
        $episode->update(['state' => $state]);
        $best = ['best_margin_basis' => 'forecast', 'selection_basis' => 'conservative_score',
            'best_available_score' => $available['score'] ?? null, 'best_unclaimed_score' => $unclaimed['score'] ?? null, 'best_available' => $available['predicted_margin'] ?? null, 'best_available_key' => $available['key'] ?? null,
            'best_unclaimed' => $unclaimed['predicted_margin'] ?? null, 'best_unclaimed_key' => $unclaimed['key'] ?? null];
        $ranked = array_map(fn ($candidate) => $candidate + $best, $ranked);
        foreach ($ranked as $candidate) {
            $considered[] = $candidate;
        }

        return $ranked;
    }

    public function projectPair(Agent $agent, array $ship, ?array $departure, array $pair, array $ask, array $bid,
        array $rules, int $runMaxSeconds, string $mode, float $decay, float $exposure = INF, bool $arm = false, bool $localEarn = false): array
    {
        $key = $pair['good'].'@'.$pair['market'];
        $planned = $departure['ship'] ?? $ship;
        $funded = $departure['agent'] ?? $agent;
        $refuel = $departure['ceiling'] ?? 0;
        $atSource = $planned;
        $position = app(FuelPlanner::class)->leg($funded, $planned, $pair['source'], 0);
        $atSource['nav']['waypointSymbol'] = $pair['source'];
        $atSource['fuel']['current'] -= $position['outbound_fuel'];
        $outbound = app(FuelPlanner::class)->leg($funded, $atSource, $pair['market'], 0);
        $atBuyer = $atSource;
        $atBuyer['nav']['waypointSymbol'] = $pair['market'];
        $atBuyer['fuel']['current'] -= $outbound['outbound_fuel'];
        $return = app(FuelPlanner::class)->leg($funded, $atBuyer, $pair['source'], 0);
        $costs = app(TradeFuelCost::class)->estimate($agent, $outbound['outbound_fuel'] + $return['outbound_fuel'], [$pair['source'], $pair['market']], $return['refuel_ceiling']);
        $fuel = $costs['cycle_fuel_allowance'];
        $positionCosts = app(TradeFuelCost::class)->estimate($agent, $position['outbound_fuel'], [$ship['nav']['waypointSymbol'], $pair['source']], $position['refuel_ceiling']);
        $batches = $mode === 'first_scaled' ? $rules['lot']['first_scaled_max_buyer_batches'] : $rules['lot']['max_buyer_batches'];
        $ceiling = max(0, $agent->credits - ReconMission::returnExposure($agent) - $refuel - max($outbound['refuel_ceiling'], $costs['fuel_safety_ceiling']));
        $caps = ['hold' => $ship['cargo']['capacity'], 'buyer_batches' => $batches * $bid['tradeVolume']];
        $creditBound = 'credits';
        if ($mode === 'proof') {
            $caps['proof_units'] = $rules['proof']['max_units_batches'] * min($ask['tradeVolume'], $bid['tradeVolume']);
            if ($rules['proof']['max_credits'] < $ceiling) {
                [$ceiling, $creditBound] = [$rules['proof']['max_credits'], 'proof_credits'];
            }
        }
        if ($exposure < $ceiling) {
            [$ceiling, $creditBound] = [(int) floor($exposure), 'exposure'];
        }
        $units = min($caps);
        $low = 0;
        $high = $units;
        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            if ($this->prediction($middle, $ask, $bid, $decay, $fuel)['predicted_cost'] <= $ceiling) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }
        // Which limit set the lot: the credit ceiling (proof, exposure or cash), else the smallest unit cap.
        $bound = $low < $units ? $creditBound : array_search($units, $caps, true);
        $limit = ['buyer_batches' => 'lot', 'proof_units' => 'proof', 'proof_credits' => 'proof'][$bound] ?? $bound;
        $units = $low;
        $prediction = $this->prediction($units, $ask, $bid, $decay, $costs['expected_fuel_cost']);
        $acceptanceMargin = $prediction['predicted_margin'] + $costs['expected_fuel_cost'] - $fuel;
        $travel = ($position['estimated_seconds'] ?? 0) + ($outbound['estimated_seconds'] ?? 0) + ($return['estimated_seconds'] ?? 0);
        $duration = fn (int $count) => $travel + (ceil($count / $ask['tradeVolume']) + ceil($count / $bid['tradeVolume'])) * 10 + 120;
        $seconds = $duration($units);
        if ($units < 1 || $acceptanceMargin <= 0) {
            throw new GameException('No profitable lot fits cargo, fuel, proof and duration bounds.');
        }
        if ($seconds > $runMaxSeconds) {
            // Shadow data: profitable pairs that only the run duration rules out.
            return ['long_run' => ['good' => $pair['good'], 'source' => $pair['source'], 'buyer' => $pair['market'], 'units' => $units,
                'predicted_margin' => $prediction['predicted_margin'], 'planned_minutes' => round($seconds / 60, 1), 'long_run' => true,
                'rejected' => 'Planned run exceeds run_max_minutes.']];
        }
        $optimal = $this->optimalLot($units, $ask, $bid, $decay, $fuel, $duration);
        $candidate = $costs + ['position_cost' => $positionCosts['expected_fuel_cost'], 'initial_contribution' => $prediction['predicted_margin'] - $positionCosts['expected_fuel_cost'],
            'conservative_margin' => $acceptanceMargin, 'key' => $key, 'source' => $pair['source'], 'buyer' => $pair['market'], 'good' => $pair['good'], 'units' => $units,
            'mode' => $mode, 'proof' => $mode === 'proof', 'decay' => $decay, 'bid' => $bid['sellPrice'], 'departure_refuel' => $refuel,
            'fuel_reserve' => $outbound['refuel_ceiling'], 'max_inventory' => $arm ? min($ceiling, (int) ceil($prediction['predicted_cost'] * 1.05)) : $ceiling, 'seconds' => $seconds,
            'limiting_factor' => $limit, 'ship' => $ship['symbol'], 'position_seconds' => $position['estimated_seconds'] ?? 0,
            'score' => $acceptanceMargin / max(1, $seconds / 60), 'lot_bound' => $bound,
            'shadow_lot_bound' => $optimal['units'] < $units ? 'optimal_size' : $bound, 'optimal_units' => $optimal['units'],
            'optimal_margin' => $optimal['margin'], 'optimal_seconds' => $optimal['seconds'], 'optimal_score' => $optimal['score']] + $prediction;

        if ($localEarn) {
            $candidate['position_fuel_allowance'] = $positionCosts['cycle_fuel_allowance'];
        }

        return ['candidate' => $candidate];
    }

    public function authorize(Agent $agent, AutomationRun $run, CampaignEpisode $episode): void
    {
        $rules = $episode->grant()->revision()->body['trade'];
        $plan = $run->plan;
        if (isset($plan['campaign_trade']['local_earn'])) {
            if (! CampaignLocalEarn::policy($episode->grant(), $episode) || ($plan['campaign_trade']['conservative_initial_contribution'] ?? 0) <= 0) {
                throw new GameException('Local Earn requires current C0 Earn authority and positive conservative contribution.');
            }
        }
        if (! isset($plan['campaign_trade']['expected_fuel_cost'], $plan['campaign_trade']['cycle_fuel_allowance'], $plan['campaign_trade']['fuel_safety_ceiling'])) {
            throw new GameException('Historical trade fuel economics require a fresh campaign preview.');
        }
        if ($plan['campaign_trade']['expected_fuel_cost'] !== ($plan['route']['expected_fuel_cost'] ?? null)
            || $plan['campaign_trade']['cycle_fuel_allowance'] !== ($plan['route']['cycle_fuel_allowance'] ?? null)) {
            throw new GameException('Fuel economics changed since ranking. Rerank before authorizing a fresh campaign preview.');
        }
        $ask = app(OperationPlanner::class)->quote($agent, $plan['site'], $plan['symbol']);
        $bid = app(OperationPlanner::class)->quote($agent, $plan['market'], $plan['symbol'], OperationPlanner::buyerAge($plan));
        $mode = $this->mode($episode, $plan['symbol'], $plan['market']);
        $batches = $mode === 'first_scaled' ? $rules['lot']['first_scaled_max_buyer_batches'] : $rules['lot']['max_buyer_batches'];
        $cap = $batches * $bid['tradeVolume'];
        if ($mode === 'proof') {
            $cap = min($cap, $rules['proof']['max_units_batches'] * min($ask['tradeVolume'], $bid['tradeVolume']));
            if ($plan['max_inventory'] > $rules['proof']['max_credits']) {
                throw new GameException('Proof spending exceeds immutable campaign terms.');
            }
        }
        if (($plan['campaign_trade']['mode'] ?? null) !== $mode || $plan['units'] > $cap || $plan['max_spend'] > $agent->credits) {
            throw new GameException('Trade preview exceeds immutable campaign terms.');
        }
        $this->quoteLife($agent, $plan, $run->totals);
        $arm = $episode->grant()->arm();
        if ($arm && $plan['max_inventory'] > $this->allowance($episode->grant(), $agent, $run)) {
            throw new GameException('Buy skipped: arm exposure requires a smaller batch.', retryAfter: 30);
        }
        if ($arm['follow'] ?? null) {
            $leader = app(FleetClaims::class)->leader($episode, $arm['follow']);
            $plan['campaign_trade']['leader_claim'] = $leader ? ['episode' => $leader->id, 'claim' => $leader->state['claim'] ?? null] : null;
            $run->update(['plan' => $plan]);
        }
    }

    public function allowance(CampaignGrant $grant, Agent $agent, ?AutomationRun $run = null, ?string $ship = null): float
    {
        $arm = $grant->arm();
        if (! $arm) {
            return INF;
        }
        $grants = CampaignGrant::whereNotNull('limits->arm')->get()->keyBy('id');
        $episodes = CampaignEpisode::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->whereIn('campaign_grant_id', $grants->keys())->get();
        $ships = $episodes->map(fn ($episode) => $grants[$episode->campaign_grant_id]->arm()['ship'])->unique();
        $held = 0;
        foreach (Ship::where('agent_id', $agent->id)->whereIn('symbol', $ships)->get() as $other) {
            $costs = $this->averageCosts($agent, $other->symbol);
            foreach ($other->snapshot['cargo']['inventory'] ?? [] as $item) {
                $held += ($costs[$item['symbol']] ?? 0) * $item['units'];
            }
        }
        $committed = AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->whereIn('campaign_episode_id', $episodes->pluck('id'))
            ->where('strategy', 'trade')->where('totals->phase', 'buy')->when($run, fn ($query) => $query->whereKeyNot($run->id))
            ->when($ship, fn ($query) => $query->where('ship_symbol', '!=', $ship))
            ->where(fn ($query) => $query->whereNotNull('active_agent_id')->orWhere(fn ($query) => $query->where('state', 'preview')->where('expires_at', '>', now())))
            ->get()->sum(fn ($other) => max(0, ($other->plan['max_inventory'] ?? 0) - ($other->totals['purchase_cost'] ?? 0)));

        // Buying moves cash into cargo without changing this basis. Exclude this run's commitment:
        // projected cargo plus other arms' unspent commitments plus this buy must fit the cap.
        return max(0, min(($arm['max_load_spend'] ?? INF) - ($run?->totals['purchase_cost'] ?? 0),
            ($arm['exposure_share'] ?? 0.5) * ($agent->credits + $held) - $held - $committed));
    }

    public function finishLoading(Agent $agent, AutomationRun $run, ?array $buy = null): bool
    {
        $bought = $run->totals['purchased'][$run->plan['symbol'] ?? ''] ?? 0;
        if ($run->strategy !== 'trade' || ! $run->campaign_episode_id || ($run->totals['phase'] ?? null) !== 'buy'
            || $bought < 1 || $bought >= $run->plan['units']) {
            return false;
        }
        $grant = CampaignEpisode::findOrFail($run->campaign_episode_id)->grant();
        if (! $grant->arm()) {
            return false;
        }
        $price = app(OperationPlanner::class)->quote($agent, $run->plan['site'], $run->plan['symbol'])['purchasePrice'];
        $lastBuy = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy')->where('state', 'confirmed')->latest('id')->first();
        $timeout = ($lastBuy?->settled_at ?? $run->started_at)->lte(now()->subMinutes(2));
        $inventory = $price > $run->plan['max_inventory'] - $run->totals['purchase_cost'];
        $exposure = $this->allowance($grant, $agent, $run) < $price;
        $priceChanged = $buy && $price * $buy['units'] > $buy['maxTotal'];
        if (! $exposure && ! $timeout && ! $inventory && ! $priceChanged) {
            return false;
        }
        $reason = match (true) {
            $timeout => 'timeout', $inventory => 'inventory', $exposure => 'exposure', default => 'price',
        };
        $predictedAsk = ($run->plan['campaign_trade']['predicted_cost'] ?? 0) / ($run->plan['original_units'] ?? $run->plan['units']);
        DB::transaction(function () use ($run, $bought, $reason, $price, $predictedAsk, $inventory) {
            $this->shortenLoad($run, $bought, $run->totals['purchase_cost'], $reason);
            $plan = $run->plan;
            if ($inventory) {
                $plan['ask_over_prediction'] = $predictedAsk > 0 ? $price / $predictedAsk : null;
            }
            $run->update(['plan' => $plan, 'totals' => array_merge($run->totals, ['phase' => 'sell']), 'next_action_at' => now(), 'blocked_reason' => null]);
        });

        return true;
    }

    public function shortenLoad(AutomationRun $run, int $units, int $inventory, string $reason): void
    {
        $run->update(['plan' => array_replace($run->plan, ['units' => $units, 'max_inventory' => $inventory,
            'original_units' => $run->plan['original_units'] ?? $run->plan['units'],
            'original_predicted_margin' => $run->plan['original_predicted_margin'] ?? $run->plan['campaign_trade']['predicted_margin'] ?? null,
            'shortened_reason' => $reason])]);
    }
}
