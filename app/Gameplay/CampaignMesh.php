<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\Ship;
use Illuminate\Support\Collection;

class CampaignMesh
{
    public static function policy(CampaignEpisode $episode): ?array
    {
        return app(CampaignFleet::class)->probePolicy($episode)['mesh'] ?? null;
    }

    public function hubs(Agent $agent, ?string $system): Collection
    {
        $observations = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id);
        $prices = (clone $observations)->where('kind', 'market')->get()->keyBy('symbol');
        $yards = $this->yards($agent, $system);

        return (clone $observations)->where('kind', 'market_listing')->orderBy('symbol')->get()
            ->filter(fn (GameObservation $row) => $system !== null && FleetClaims::systemOf($row->symbol) === $system)
            ->mapWithKeys(function (GameObservation $row) use ($prices, $yards) {
                $goods = array_diff(array_unique(array_column(array_merge(
                    $row->data['imports'] ?? [], $row->data['exports'] ?? [], $row->data['exchange'] ?? [],
                ), 'symbol')), ['FUEL', 'ANTIMATTER']);
                $quote = $prices->get($row->symbol);

                return [$row->symbol => ['symbol' => $row->symbol, 'goods' => count($goods), 'shipyard' => $yards->has($row->symbol),
                    'prices_unknown' => empty($quote?->data['tradeGoods']), 'quote' => $quote]];
            })->filter(fn (array $hub) => $hub['goods'] > 0);
    }

    public function yards(Agent $agent, ?string $system): Collection
    {
        return GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'waypoint')
            ->orderBy('symbol')->get()
            ->filter(fn (GameObservation $point) => $system !== null && ($point->data['systemSymbol'] ?? null) === $system
                && in_array('SHIPYARD', array_column($point->data['traits'] ?? [], 'symbol'), true))->keyBy('symbol');
    }

    public function target(CampaignEpisode $episode, Agent $agent): array
    {
        $fleet = app(CampaignFleet::class);
        $command = $fleet->role($episode, 'command', pin: false);
        $system = $command['nav']['systemSymbol'] ?? null;
        $probes = collect($fleet->probes($episode, pin: false));
        $hubs = $this->hubs($agent, $system);
        $yards = $this->yards($agent, $system);
        $policy = self::policy($episode);
        $traders = app(CampaignSaturation::class)->traderSymbols($episode, $agent);
        $hubTarget = min($hubs->count(), ($policy['base'] ?? 0) + ($policy['per_trader'] ?? 0) * max(0, count($traders) - 1));
        $parked = $probes->reject(fn (array $probe) => $probe['nav']['status'] === 'IN_TRANSIT')
            ->groupBy('nav.waypointSymbol');
        $stations = Ship::where('agent_id', $agent->id)->whereNotNull('stationed_waypoint')->get()
            ->filter(fn (Ship $ship) => $fleet->isProbe($ship->snapshot) && $ship->snapshot['nav']['status'] !== 'IN_TRANSIT'
                && $ship->stationed_waypoint === $ship->snapshot['nav']['waypointSymbol'] && $hubs->has($ship->stationed_waypoint))
            ->pluck('stationed_waypoint')->unique()->values();
        $offset = $stations->reject(fn ($symbol) => $yards->has($symbol))->count();
        $coverage = max(0, $hubTarget - $offset);
        $usableYards = $this->eligibleYards($agent, $yards);
        if ($usableYards->count() === 1 && ! $hubs->has($usableYards->first()->symbol)) {
            $coverage++;
        } elseif ($usableYards->keys()->contains(fn ($symbol) => $hubs->has($symbol) && ! $parked->has($symbol))) {
            $coverage = max(1, $coverage);
        }
        $covered = $hubs->keys()->filter(fn ($symbol) => $parked->has($symbol) || $stations->contains($symbol))->values();

        return ['system' => $system, 'hubs' => $hubs, 'yards' => $usableYards, 'parked' => $parked, 'probes' => $probes,
            'traders' => $traders, 'covered' => $covered, 'hub_target' => $hubTarget, 'stationed_hubs' => $offset,
            'pool_probe_target' => min($policy['max_probes'] ?? 0, $coverage), 'pool_probe_count' => $probes->count()];
    }

    private function eligibleYards(Agent $agent, Collection $yards): Collection
    {
        $offers = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'shipyard')->get()->keyBy('symbol');

        return $yards->filter(function (GameObservation $point) use ($offers) {
            $yard = $offers->get($point->symbol);

            return ! $yard || collect(CampaignProbeBootstrap::PURCHASE_TYPES)->contains(
                fn ($type) => app(CampaignProbeBootstrap::class)->offers($yard->data, $type));
        });
    }

    public function demand(CampaignEpisode $episode, Agent $agent): array
    {
        $active = [];
        $ranked = [];
        $claims = app(FleetClaims::class);
        foreach (CampaignEpisode::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->whereNull('closed_at')->get() as $other) {
            foreach ($claims->pairs($other) as $pair) {
                $active[] = $pair['source'] ?? null;
                $active[] = $pair['buyer'] ?? null;
            }
            $decision = CampaignEvent::where('campaign_episode_id', $other->id)->where('kind', 'decision')
                ->whereNotNull('data->candidates')->latest('id')->first();
            foreach (collect($decision?->data['candidates'] ?? [])->filter(fn ($pair) => isset($pair['source'], $pair['buyer'], $pair['score']))->take(3) as $candidate) {
                $ranked[] = $candidate['source'];
                $ranked[] = $candidate['buyer'];
            }
        }
        $active[] = $episode->state['active_buyer'] ?? null;

        return array_values(array_unique(array_filter([...$active, ...$ranked])));
    }

    public function refresh(CampaignEpisode $episode, Agent $agent, array &$read, float $deadline, ?string $buyer = null): void
    {
        $target = $this->target($episode, $agent);
        $policy = self::policy($episode);
        $demand = array_filter([...$this->demand($episode, $agent), $buyer]);
        $occupied = Ship::where('agent_id', $agent->id)->get()
            ->reject(fn (Ship $ship) => $ship->snapshot['nav']['status'] === 'IN_TRANSIT')
            ->groupBy('snapshot.nav.waypointSymbol');
        $due = [];
        foreach ($target['hubs'] as $symbol => $hub) {
            if (isset($read[$symbol]) || ! $occupied->has($symbol)) {
                continue;
            }
            $quote = $hub['quote'];
            $needed = in_array($symbol, $demand, true);
            $urgent = $hub['prices_unknown'] || isset($quote?->data['invalidated_by_action']);
            $age = $quote ? $quote->observed_at->diffInSeconds(now(), false) : INF;
            if ($urgent || $age >= $policy[$needed ? 'demand_read_interval_seconds' : 'read_interval_seconds']) {
                $due[] = ['symbol' => $symbol, 'priority' => $urgent ? 0 : ($needed ? 1 : 2), 'age' => -$age];
            }
        }
        usort($due, fn ($a, $b) => [$a['priority'], $a['age'], $a['symbol']] <=> [$b['priority'], $b['age'], $b['symbol']]);
        foreach ($due as $entry) {
            if (count($read) >= $policy['reads_per_pass'] || microtime(true) >= $deadline) {
                return;
            }
            $symbol = $entry['symbol'];
            $read[$symbol] = true;
            try {
                $observer = $occupied->get($symbol)->sortBy('symbol')->first()->symbol;
                $data = FleetMetrics::as($observer, fn () => app(SpaceTradersApi::class)->market($agent, $symbol));
                app(AgentSynchronizer::class)->observe($agent, 'market', $symbol, $data);
            } catch (GameException $exception) {
                if ($exception->retryAfter > 0) {
                    return;
                }
            }
        }
    }

    public function assignments(CampaignEpisode $episode, Agent $agent, array $busy, array $reserved, ?string $buyer): array
    {
        $mesh = $this->target($episode, $agent);
        $policy = self::policy($episode);
        $hubs = $mesh['hubs'];
        $parked = $mesh['parked'];
        $demand = array_unique(array_filter([$buyer, ...$this->demand($episode, $agent)]));
        $discovering = $episode->phase === 'discover' || $hubs->contains('prices_unknown', true);
        $purchaser = $mesh['yards']->keys()->map(fn ($yard) => $parked->get($yard)?->first())->filter()->first()['symbol'] ?? null;
        foreach ($mesh['probes'] as $probe) {
            if ($probe['nav']['status'] === 'IN_TRANSIT') {
                $reserved[] = $probe['nav']['route']['destination']['symbol'] ?? null;
            }
        }
        $anchors = $parked->filter(fn ($rows, $symbol) => $hubs->has($symbol))->map(fn ($rows) => $rows->first()['symbol'])->all();
        $targets = $hubs->filter(fn ($hub, $symbol) => ! in_array($symbol, $reserved, true)
            && ($discovering ? $hub['prices_unknown'] && ! $parked->has($symbol) : ! $mesh['covered']->contains($symbol)))
            ->sortBy(fn ($hub) => [array_search($hub['symbol'], $demand, true) !== false ? array_search($hub['symbol'], $demand, true) : PHP_INT_MAX,
                ! $hub['shipyard'], -$hub['goods'], $hub['symbol']]);
        if ($buyer && $mesh['covered']->count() < $hubs->count() && $hubs->has($buyer) && ! in_array($buyer, $reserved, true)
            && ! $mesh['covered']->contains($buyer)) {
            $targets = collect([$buyer => $hubs->get($buyer)])->union($targets);
        }
        $available = $mesh['probes']->filter(fn (array $probe) => $probe['nav']['status'] !== 'IN_TRANSIT' && $probe['cargo']['units'] === 0
            && $probe['symbol'] !== $purchaser && ! in_array($probe['symbol'], $busy, true));
        $points = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'waypoint')->get()->keyBy('symbol');
        $assignments = [];
        foreach ($targets as $symbol => $hub) {
            if (count($assignments) >= $policy['dispatches_per_pass']) {
                break;
            }
            $movable = $available->filter(function (array $probe) use ($anchors, $hubs, $demand, $buyer, $symbol, $discovering) {
                $origin = $probe['nav']['waypointSymbol'];
                if ($origin === $symbol) {
                    return false;
                }
                if (($anchors[$origin] ?? null) !== $probe['symbol']) {
                    return true;
                }

                return ($discovering || $symbol === $buyer) && ! ($hubs->get($origin)['shipyard'] ?? false)
                    && ($discovering ? $origin !== $buyer : ! in_array($origin, $demand, true)) && ! ($hubs->get($origin)['prices_unknown'] ?? false);
            });
            $probe = $movable->sortBy(function (array $probe) use ($points, $symbol, $anchors, $hubs) {
                $origin = $probe['nav']['waypointSymbol'];
                $from = $points->get($origin)?->data;
                $to = $points->get($symbol)?->data;
                $anchored = ($anchors[$origin] ?? null) === $probe['symbol'];
                $distance = $from && $to ? hypot($from['x'] - $to['x'], $from['y'] - $to['y']) : INF;

                return [$anchored, $anchored ? ($hubs->get($origin)['goods'] ?? 0) : 0, $distance, $probe['symbol']];
            })->first();
            if ($probe) {
                $assignments[] = [$probe, $symbol];
                $available = $available->reject(fn ($ship) => $ship['symbol'] === $probe['symbol']);
            }
        }

        return $assignments;
    }

    public function scoutMinutes(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $ship, array $plan): int
    {
        $destination = $plan['destination'] ?? null;
        $seconds = $plan['route']['outbound']['estimated_seconds'] ?? null;
        $eligible = self::policy($episode) && ($plan['strategy'] ?? null) === 'scout' && ! empty($plan['one_way'])
            && app(FuelPlanner::class)->fuelFree($ship) && is_string($destination)
            && FleetClaims::systemOf($destination) === $ship['nav']['systemSymbol'];
        $observeYard = ($plan['observe_kind'] ?? 'market') === 'shipyard';
        if (! $eligible || ! is_numeric($seconds) || $seconds < 0
            || ! ($observeYard ? $this->yards($agent, $ship['nav']['systemSymbol']) : $this->hubs($agent, $ship['nav']['systemSymbol']))->has($destination)) {
            throw new GameException('Mesh positioning requires a verified one-way fuel-free route to a hub or shipyard.');
        }
        $required = (int) ceil(($seconds + 180) / 60);
        $windDown = $episode->next_reset_at->subHour();
        $cutoff = $grant->expires_at ? $windDown->min($grant->expires_at) : $windDown;
        $minutes = (int) floor(now()->diffInSeconds($cutoff, false) / 60);
        $cap = min(90, max($grant->limits['run_max_minutes'], $required), $minutes);
        if ($required > $cap || (isset($plan['minutes']) && ($plan['minutes'] < $required || $plan['minutes'] > $cap))) {
            throw new GameException('Verified positioning duration exceeds available time before campaign cutoff.');
        }

        return $required;
    }
}
