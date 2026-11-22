<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\ReconMission;
use App\Models\Ship;
use App\Models\ShipAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Decision-local evidence and exact-pair evaluation; never a market cache or an execution authority. */
class CampaignLocalEarn
{
    public const RESERVE_SECONDS = 12;

    public const POSITION_COOLDOWN_MINUTES = 30;

    public static function policy(CampaignGrant $grant, CampaignEpisode $episode): ?array
    {
        return $grant->slot === 1 && ! $grant->arm() && $grant->available() && ! $episode->closed_at && $episode->phase === 'earn'
            && $episode->campaign_grant_id === $grant->id && hash_equals($grant->revision_hash, $grant->revision()->content_hash)
            ? ($grant->revision()->body['trade']['local_earn'] ?? null) : null;
    }

    public function reserve(float $deadline): void
    {
        if ($deadline - microtime(true) < self::RESERVE_SECONDS) {
            throw new LocalEarnBudget;
        }
    }

    /**
     * One observation snapshot, also callable with supplied local evidence for offline replay.
     * Cheap order: readiness, nominal one-batch margin, then good/buyer/source. That estimate only
     * allocates projection slots; the final order uses the existing conservative lot forecast.
     */
    public function shortlist(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $ship,
        array &$considered, array &$summary, float $deadline, ?Collection $evidence = null): array
    {
        $policy = self::policy($grant, $episode);
        if (! $policy) {
            throw new GameException('Local Earn is outside current C0 Earn authority.');
        }
        $this->reserve($deadline);
        $summary = ['selection_basis' => 'local_shortlist_bounded', 'net_basis' => 'estimate_for_projected_set',
            'projection_limit' => $policy['projection_limit'], 'max_attempts' => $policy['max_attempts'],
            'snapshot_loads' => $evidence === null ? 1 : 0, 'eligible_pairs' => 0, 'projections' => 0, 'exact_rechecks' => 0, 'exact_projections' => 0,
            'deferred_pairs' => 0, 'attempts' => [], 'endpoint_reads' => [], 'rejections' => [], 'end' => 'candidate_exhausted'];
        $evidence ??= GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->whereIn('kind', ['waypoint', 'market_listing', 'market'])->orderBy('observed_at')->orderBy('id')->get();
        $evidence = collect($evidence->all());
        $points = $evidence->where('kind', 'waypoint')->keyBy('symbol')->filter(fn ($row) => ($row->data['systemSymbol'] ?? null) === $ship['nav']['systemSymbol']);
        $listings = $evidence->where('kind', 'market_listing')->keyBy('symbol')->only($points->keys()->all());
        $markets = $evidence->where('kind', 'market')->keyBy('symbol')->only($points->keys()->all());
        $directory = [];
        $quotes = [];
        foreach ($listings as $symbol => $listing) {
            $directory[$symbol] = array_fill_keys(array_column(array_merge($listing->data['imports'] ?? [], $listing->data['exports'] ?? [], $listing->data['exchange'] ?? []), 'symbol'), true);
        }
        foreach ($markets as $symbol => $market) {
            if (empty($market->data['invalidated_by_action'])) {
                $quotes[$symbol] = array_column($market->data['tradeGoods'] ?? [], null, 'symbol');
            }
        }
        $taken = config('gameplay.campaign_claims', true) ? app(FleetClaims::class)->taken($episode) : [];
        $here = $ship['nav']['waypointSymbol'];
        $age = min($grant->revision()->body['trade']['quote_max_age_seconds'], (int) config('gameplay.snapshot_seconds'));
        $occupied = Ship::where('agent_id', $agent->id)->get()->filter(fn ($row) => ($row->snapshot['nav']['status'] ?? null) !== 'IN_TRANSIT')
            ->map(fn ($row) => $row->snapshot['nav']['waypointSymbol'])->all();
        $pairs = [];
        foreach ($directory as $source => $goods) {
            $this->reserve($deadline);
            foreach ($directory as $buyer => $buyers) {
                $this->reserve($deadline);
                if ($source === $buyer) {
                    continue;
                }
                foreach (array_intersect_key($goods, $buyers) as $good => $_) {
                    $ask = $quotes[$source][$good] ?? null;
                    $bid = $quotes[$buyer][$good] ?? null;
                    $reason = match (true) {
                        ! $ask || ! $bid => 'missing_or_invalid_detail',
                        $ask['purchasePrice'] < 1 || $bid['sellPrice'] <= $ask['purchasePrice'] || min($ask['tradeVolume'], $bid['tradeVolume']) < 1 => 'no_positive_spread_or_volume',
                        isset($taken[$good.'@'.$source]) || isset($taken[$good.'@'.$buyer]) => 'claimed',
                        $markets[$buyer]->observed_at->lt(now()->subSeconds($policy['position_evidence_max_age_seconds'])) => 'position_evidence_old',
                        $source !== $here && $markets[$source]->observed_at->lt(now()->subSeconds($policy['position_evidence_max_age_seconds'])) => 'position_evidence_old',
                        default => null,
                    };
                    if ($reason) {
                        $summary['rejections'][$reason] = ($summary['rejections'][$reason] ?? 0) + 1;

                        continue;
                    }
                    $fresh = $markets[$source]->observed_at->gt(now()->subSeconds(max(0, $age - 20))) && $markets[$buyer]->observed_at->gt(now()->subSeconds(max(0, $age - 20)));
                    $readiness = $source !== $here ? 3 : ($fresh ? 0 : ($markets[$buyer]->observed_at->gt(now()->subSeconds(max(0, $age - 20))) || in_array($buyer, $occupied, true) ? 1 : 2));
                    $pairs[] = ['good' => $good, 'source' => $source, 'market' => $buyer, 'key' => $good.'@'.$buyer,
                        'readiness' => $readiness, 'nominal_margin' => ($bid['sellPrice'] - $ask['purchasePrice']) * min($ask['tradeVolume'], $bid['tradeVolume']), 'ask' => $ask, 'bid' => $bid];
                }
            }
        }
        usort($pairs, fn ($a, $b) => $a['readiness'] <=> $b['readiness'] ?: $b['nominal_margin'] <=> $a['nominal_margin'] ?: [$a['key'], $a['source']] <=> [$b['key'], $b['source']]);
        // Give the current eligible pair a slot within its tier without displacing executable buys.
        $selected = [];
        foreach ([0, 1, 2, 3] as $tier) {
            $rows = array_values(array_filter($pairs, fn ($pair) => $pair['readiness'] === $tier));
            $room = $policy['projection_limit'] - count($selected);
            if ($room <= 0) {
                break;
            }
            $slice = array_slice($rows, 0, $room);
            $current = collect($rows)->firstWhere('key', $episode->state['current_pair'] ?? null);
            if ($current && ! in_array($current, $slice, true)) {
                $slice[count($slice) - 1] = $current;
            }
            array_push($selected, ...$slice);
        }
        $summary['eligible_pairs'] = count($pairs);
        $summary['deferred_pairs'] = count($pairs);
        $ranked = [];
        foreach ($selected as $pair) {
            $this->reserve($deadline);
            $summary['deferred_pairs']--;
            try {
                $candidate = $this->project($grant, $episode, $agent, $ship, $pair, $pair['ask'], $pair['bid'], function () use (&$summary, $deadline) {
                    $this->reserve($deadline);
                    $summary['projections']++;
                });
                $loadSeconds = ceil($candidate['units'] / $pair['ask']['tradeVolume']) * 10 + 10;
                $fresh = $markets[$pair['source']]->observed_at->addSeconds($age)->gt(now()->addSeconds($loadSeconds))
                    && $markets[$pair['market']]->observed_at->addSeconds($age)->gt(now()->addSeconds($loadSeconds));
                $candidate['readiness'] = $pair['source'] !== $here ? 3 : ($fresh ? 0 : ($markets[$pair['market']]->observed_at->addSeconds($age)->gt(now()->addSeconds($loadSeconds)) || in_array($pair['market'], $occupied, true) ? 1 : 2));
                $ranked[] = $candidate;
            } catch (LocalEarnRefusal $exception) {
                $considered[] = ['good' => $pair['good'], 'source' => $pair['source'], 'buyer' => $pair['market'], 'stage' => $exception->stage, 'rejected' => $exception->getMessage()];
            }
        }
        usort($ranked, fn ($a, $b) => $a['readiness'] <=> $b['readiness'] ?: $b['score'] <=> $a['score'] ?: [$a['key'], $a['source']] <=> [$b['key'], $b['source']]);
        $current = collect($ranked)->firstWhere('key', $episode->state['current_pair'] ?? null);
        if ($ranked && $current && $current['readiness'] === $ranked[0]['readiness'] && $ranked[0]['score'] < $current['score'] * $grant->revision()->body['trade']['switch']['better_pair_ratio']) {
            $ranked = [$current, ...array_values(array_filter($ranked, fn ($row) => $row !== $current))];
        }
        array_push($considered, ...$ranked);

        return $ranked;
    }

    public function project(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $ship, array $pair, array $ask, array $bid, ?callable $projecting = null): array
    {
        $this->claims($episode, $pair['good'], $pair['source'], $pair['market']);
        if ($ask['purchasePrice'] < 1 || $bid['sellPrice'] <= $ask['purchasePrice'] || min($ask['tradeVolume'], $bid['tradeVolume']) < 1) {
            throw new LocalEarnRefusal('spread', 'Local pair no longer has a positive spread and volume.');
        }
        $trades = app(CampaignTrade::class);
        $rules = $grant->revision()->body['trade'];
        $state = $episode->refresh()->state ?? [];
        $key = $pair['good'].'@'.$pair['market'];
        $state['first_seen'][$key] ??= $bid['sellPrice'];
        if (($state['current_pair'] ?? null) === $key && $bid['sellPrice'] <= $state['first_seen'][$key] * $rules['switch']['bid_below_first_seen']) {
            throw new LocalEarnRefusal('bid', 'Switch: buyer bid at or below first-seen threshold.');
        }
        if ($projecting) {
            $projecting();
        }
        $episode->update(['state' => $state]);
        try {
            $projection = $trades->projectPair($agent, $ship, app(FuelPlanner::class)->departureRefuel($agent, $ship), $pair, $ask, $bid, $rules,
                $grant->limits['run_max_minutes'] * 60, $trades->mode($episode, $pair['good'], $pair['market']), $trades->decay($episode, $pair['good'], $pair['market']), localEarn: true);
        } catch (GameException $exception) {
            throw LocalEarnRefusal::projection($exception);
        }
        if (! isset($projection['candidate'])) {
            throw new LocalEarnRefusal('duration', 'Planned run exceeds run_max_minutes.');
        }

        return $this->contribution($projection['candidate']) + ['source_price' => $ask['purchasePrice'], 'source_volume' => $ask['tradeVolume'],
            'buyer_price' => $bid['sellPrice'], 'buyer_volume' => $bid['tradeVolume']];
    }

    public function contribution(array $candidate): array
    {
        $net = $candidate['conservative_margin'] - $candidate['position_fuel_allowance'];
        if ($net <= 0) {
            throw new LocalEarnRefusal('contribution', 'Conservative initial contribution after positioning must be positive.');
        }

        return array_replace($candidate, ['local_earn' => true, 'conservative_initial_contribution' => $net,
            'score' => $net / max(1, $candidate['seconds'] / 60), 'selection_basis' => 'local_shortlist_bounded', 'best_margin_basis' => 'estimate_for_projected_set']);
    }

    public function claims(CampaignEpisode $episode, string $good, string $source, string $buyer): void
    {
        $taken = config('gameplay.campaign_claims', true) ? app(FleetClaims::class)->taken($episode) : [];
        if (isset($taken[$good.'@'.$source]) || isset($taken[$good.'@'.$buyer])) {
            throw new LocalEarnRefusal('claim', 'Claimed by another ship.');
        }
    }

    /** A persistent buyer reader must be stationary, unowned and outside movable live campaign roles. */
    public function stableBuyer(Agent $agent, string $buyer, string $command): bool
    {
        $roles = [];
        foreach (CampaignEpisode::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->whereNull('closed_at')->get() as $episode) {
            if ($episode->grant()->available()) {
                array_push($roles, ...app(CampaignFleet::class)->symbols($episode));
            }
        }

        return Ship::where('agent_id', $agent->id)->get()->contains(fn ($ship) => $ship->symbol !== $command
            && ($ship->snapshot['nav']['waypointSymbol'] ?? null) === $buyer && ($ship->snapshot['nav']['status'] ?? null) !== 'IN_TRANSIT'
            && ! in_array($ship->symbol, $roles, true) && ! ReconMission::holds($agent, $ship->symbol) && $this->unowned($agent, $ship->symbol));
    }

    public function positionEvidence(CampaignGrant $grant, Agent $agent, array $ship, array $candidate): bool
    {
        $policy = $grant->revision()->body['trade']['local_earn'];
        $quotes = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'market')
            ->whereIn('symbol', [$candidate['source'], $candidate['buyer']])->orderBy('observed_at')->orderBy('id')->get()->keyBy('symbol');
        foreach ([$candidate['source'], $candidate['buyer']] as $symbol) {
            $quote = $quotes->get($symbol);
            if (! $quote || ! empty($quote->data['invalidated_by_action']) || $quote->observed_at->lt(now()->subSeconds($policy['position_evidence_max_age_seconds']))) {
                return false;
            }
        }
        $ask = collect($quotes[$candidate['source']]->data['tradeGoods'] ?? [])->firstWhere('symbol', $candidate['good']);
        $bid = collect($quotes[$candidate['buyer']]->data['tradeGoods'] ?? [])->firstWhere('symbol', $candidate['good']);
        if (! $ask || ! $bid || [$ask['purchasePrice'], $ask['tradeVolume'], $bid['sellPrice'], $bid['tradeVolume']]
            !== [$candidate['source_price'], $candidate['source_volume'], $candidate['buyer_price'], $candidate['buyer_volume']]
            || $candidate['conservative_initial_contribution'] <= 0) {
            return false;
        }
        $loading = ceil($candidate['units'] / max(1, $ask['tradeVolume'])) * 10 + 10;
        $age = min($grant->revision()->body['trade']['quote_max_age_seconds'], (int) config('gameplay.snapshot_seconds'));

        return $this->stableBuyer($agent, $candidate['buyer'], $ship['symbol'])
            || $quotes[$candidate['buyer']]->observed_at->addSeconds($age)->gt(now()->addSeconds($candidate['position_seconds'] + $loading));
    }

    public function unowned(Agent $agent, string $ship): bool
    {
        return ! AutomationRun::where('agent_id', $agent->id)->where('ship_symbol', $ship)
            ->where(fn ($q) => $q->whereNotNull('active_agent_id')->orWhere(fn ($q) => $q->where('state', 'preview')->where('expires_at', '>', now())))->exists()
            && ! ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('ship_symbol', $ship)
                ->where(fn ($q) => $q->whereIn('state', ['pending', 'sending', 'uncertain'])->orWhere('review_required', true))->exists();
    }

    public function positioning(CampaignEpisode $episode): array
    {
        $state = $episode->refresh()->state ?? [];
        $position = $state['local_earn_positioning'] ?? ['count' => 0, 'last_started_at' => null];
        $runs = AutomationRun::where('campaign_episode_id', $episode->id)->where('plan->campaign_local_earn->kind', 'paid_position');
        $settled = ! (clone $runs)->where(fn ($q) => $q->whereNotNull('active_agent_id')->orWhere(fn ($q) => $q->where('state', 'preview')->where('expires_at', '>', now())))->exists()
            && ! ShipAction::whereIn('automation_run_id', (clone $runs)->select('id'))->where(fn ($q) => $q->whereIn('state', ['pending', 'sending', 'uncertain'])->orWhere('review_required', true))->exists()
            && ! Ship::where('agent_id', $episode->agent_id)->whereIn('symbol', (clone $runs)->select('ship_symbol'))->where('snapshot->nav->status', 'IN_TRANSIT')->exists();
        if ($position['last_started_at'] && CarbonImmutable::parse($position['last_started_at'])->addMinutes(self::POSITION_COOLDOWN_MINUTES)->lte(now()) && $settled) {
            $position = ['count' => 0, 'last_started_at' => null];
            $episode->update(['state' => array_merge($state, ['local_earn_positioning' => $position])]);
        }

        return $position + ['settled' => $settled, 'cooldown_minutes' => self::POSITION_COOLDOWN_MINUTES];
    }

    /** Revalidate the bound movement after native preflight reads, including retained previews. */
    public function checkStart(Agent $agent, AutomationRun $run, CampaignEpisode $episode, array $ship): void
    {
        $local = $run->plan['campaign_local_earn'];
        $pair = $local['candidate'];
        $grant = $episode->grant();
        $policy = self::policy($grant, $episode);
        if (! $policy || app(CampaignSafety::class)->windDown($episode)) {
            throw new GameException('Local Earn movement is outside current authority.');
        }
        if ($run->strategy !== 'scout' || empty($run->plan['one_way']) || $run->plan['destination'] !== ($local['kind'] === 'paid_position' ? $pair['source'] : $pair['buyer'])) {
            throw new GameException('Local Earn movement must preserve its exact candidate and one-way observation plan.');
        }
        if ($run->plan['reserve'] < ReconMission::returnExposure($agent)) {
            throw new GameException('Local Earn movement must protect the current recon return reserve.');
        }
        $command = app(CampaignFleet::class)->role($episode, 'command');
        if ($local['kind'] === 'paid_position') {
            $count = $this->positioning($episode)['count'];
            if ($count >= $policy['max_positionings_without_trade'] || $run->ship_symbol !== ($command['symbol'] ?? null)) {
                throw new GameException('Local Earn paid positioning cap or command ownership blocks movement.');
            }
            $planner = app(OperationPlanner::class);
            $age = $policy['position_evidence_max_age_seconds'];
            $fresh = $this->project($grant, $episode, $agent, $ship, ['good' => $pair['good'], 'source' => $pair['source'], 'market' => $pair['buyer']],
                $planner->quote($agent, $pair['source'], $pair['good'], $age), $planner->quote($agent, $pair['buyer'], $pair['good'], $age));
            if (! $this->positionEvidence($grant, $agent, $ship, $fresh)) {
                throw new LocalEarnRefusal('position_evidence', 'Local positioning evidence or buyer observability changed.');
            }

            return;
        }
        $fleet = app(CampaignFleet::class);
        $validProbe = $fleet->poolEnabled($episode)
            ? in_array($run->ship_symbol, array_column($fleet->probes($episode), 'symbol'), true)
            : $run->ship_symbol === ($fleet->role($episode, 'probe')['symbol'] ?? null);
        if ($local['kind'] !== 'free_observation' || ($command['nav']['waypointSymbol'] ?? null) !== $pair['source']
            || $run->ship_symbol === ($command['symbol'] ?? null) || ! $validProbe || ! app(FuelPlanner::class)->fuelFree($ship)
            || ReconMission::holds($agent, $run->ship_symbol) || $run->plan['max_spend'] !== 0 || $run->plan['route']['fuel_estimate'] !== 0) {
            throw new GameException('Local Earn buyer observation requires an available fuel-free probe with no spending.');
        }
        $this->claims($episode, $pair['good'], $pair['source'], $pair['buyer']);
    }

    public function started(AutomationRun $run, CampaignEpisode $episode): void
    {
        $kind = $run->plan['campaign_local_earn']['kind'] ?? null;
        if ($run->strategy !== 'trade' && $kind !== 'paid_position') {
            return;
        }
        $state = $episode->refresh()->state ?? [];
        $previous = $state['local_earn_positioning'] ?? ['count' => 0, 'last_started_at' => null];
        $state['local_earn_positioning'] = $run->strategy === 'trade' ? ['count' => 0, 'last_started_at' => null]
            : ['count' => $previous['count'] + 1, 'last_started_at' => now()->toIso8601String()];
        $episode->update(['state' => $state]);
    }
}
