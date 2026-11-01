<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\GameObservation;
use App\Models\ObservationHistory;
use App\Models\Ship;
use Illuminate\Support\Collection;

class OpportunityAssessor
{
    public function __construct(private MiningPlanner $mining, private FuelPlanner $fuel) {}

    public function report(Agent $agent): array
    {
        $observations = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->get();
        $listings = $observations->where('kind', 'market_listing')->keyBy('symbol');
        $points = $observations->where('kind', 'waypoint');
        $markets = $observations->where('kind', 'market_listing')->map(function ($listing) use ($observations) {
            $quote = $observations->first(fn ($row) => $row->kind === 'market' && $row->symbol === $listing->symbol && ! empty($row->data['tradeGoods']));

            return ['symbol' => $listing->symbol, 'goods' => array_values(array_unique(array_column(array_merge($listing->data['imports'], $listing->data['exports'], $listing->data['exchange']), 'symbol'))),
                'state' => ! $quote ? 'prices_unknown' : (! empty($quote->data['invalidated_by_action']) || $quote->observed_at->lt(now()->subSeconds(config('gameplay.snapshot_seconds'))) ? 'priced_stale' : 'recently_observed'),
                'listing_at' => $listing->observed_at->toIso8601String(), 'price_at' => $quote?->observed_at->toIso8601String(), 'observer_ship' => $quote?->observer_ship,
                'prices' => $quote?->data['tradeGoods'] ?? []];
        })->values();
        foreach ($points as $point) {
            if (in_array('MARKETPLACE', array_column($point->data['traits'], 'symbol'), true) && ! $markets->contains('symbol', $point->symbol)) {
                $markets->push(['symbol' => $point->symbol, 'goods' => [], 'state' => 'directory_unknown', 'listing_at' => null, 'price_at' => null, 'observer_ship' => null, 'prices' => []]);
            }
        }
        $opportunities = [];
        $questions = [];
        $cargoOptions = [];
        $observationGaps = [];
        if (! $agent->isArchived()) {
            foreach (Ship::where('agent_id', $agent->id)->get() as $owned) {
                $ship = $owned->snapshot;
                if (! isset($ship['nav']['flightMode'], $ship['nav']['systemSymbol'], $ship['nav']['waypointSymbol'], $ship['fuel']['current'], $ship['fuel']['capacity'], $ship['cargo']['units'], $ship['cargo']['capacity']) || ! is_array($ship['cargo']['inventory'] ?? null)) {
                    $observationGaps[] = ['ship' => $owned->symbol, 'reason' => 'Ship observations are incomplete. Missing values remain Unknown. Synchronize before planning.'];

                    continue;
                }
                $cargoOptions = array_merge($cargoOptions, $this->cargo($agent, $ship, $markets->all(), $listings));
                foreach ($points->where('data.systemSymbol', $ship['nav']['systemSymbol']) as $point) {
                    if (in_array('UNCHARTED', array_column($point->data['traits'], 'symbol'), true)) {
                        $questions[] = ['ship' => $owned->symbol, 'destination' => $point->symbol, 'fact' => 'Hidden traits',
                            'purpose' => 'Reveal whether this hidden waypoint offers a buyer, fuel or an extraction site. Re-read remotely before planning a scan or chart.', 'benefit' => 'Unknown', 'stop' => 'Stop when traits are observed or approved visit/time/fuel limits are exhausted.'];
                    }
                }
                foreach ($markets as $market) {
                    if ($market['state'] !== 'recently_observed') {
                        $missingDirectory = $market['state'] === 'directory_unknown';
                        $questions[] = ['ship' => $owned->symbol, 'destination' => $market['symbol'], 'fact' => $missingDirectory ? 'Missing goods directory' : ($market['state'] === 'prices_unknown' ? 'Unknown prices' : 'Stale prices'),
                            'purpose' => $missingDirectory ? 'Synchronize again to fetch this directory remotely before assigning travel. Missing directories rotate ahead of old unoccupied reads within the same cap.' : 'Observe '.implode(', ', $market['goods']).' prices to compare sale, trade and fuel options.', 'benefit' => 'Unknown until prices and displaced earnings are measured.', 'stop' => 'Stop after a detailed quote or the approved budget. A goods directory alone does not answer the question.'];
                    }
                    if ($this->mining->capability($ship) !== null) {
                        continue;
                    }
                    foreach ($points as $site) {
                        if (count($opportunities) >= config('gameplay.opportunity_limit')) {
                            break;
                        }
                        if (! in_array($site->data['type'], ['ASTEROID', 'ASTEROID_FIELD', 'ENGINEERED_ASTEROID'], true)) {
                            continue;
                        }
                        $record = ['strategy' => 'mining', 'ship' => $owned->symbol, 'site' => $site->symbol, 'market' => $market['symbol'],
                            'state' => 'unresolved', 'net_credits_per_hour' => null, 'conservative_net_per_hour' => null];
                        try {
                            $record['route'] = $this->mining->route($agent, $ship, $site->symbol, $market['symbol']);
                            $record['continuation'] = $this->fuel->leg($agent, $ship, $site->symbol, listings: $listings);
                            $samples = ObservationHistory::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'extraction')->where('symbol', $site->symbol)->get()
                                ->filter(fn ($sample) => ($sample->data['configuration'] ?? null) === FuelPlanner::configuration($ship));
                            $record['samples'] = $samples->count();
                            $record['reason'] = 'Feasible trial candidate. Yield mix, complete-cycle costs and elapsed returns need comparable measurements.';
                        } catch (GameException $e) {
                            $record['reason'] = $e->getMessage();
                        }
                        $opportunities[] = $record;
                    }
                }
                foreach ($markets as $source) {
                    foreach ($markets as $buyer) {
                        if ($source['symbol'] === $buyer['symbol']) {
                            continue;
                        }
                        foreach (array_intersect($source['goods'], $buyer['goods']) as $symbol) {
                            if (count($opportunities) >= config('gameplay.opportunity_limit')) {
                                break 3;
                            }
                            $opportunities[] = $this->trade($agent, $ship, $source, $buyer, $symbol, $listings);
                        }
                    }
                }
            }
        }
        usort($opportunities, fn ($a, $b) => [$a['strategy'], $a['ship'], $a['market']] <=> [$b['strategy'], $b['ship'], $b['market']]);
        $fleet = Ship::where('agent_id', $agent->id)->get()->keyBy('symbol');
        foreach ($questions as &$question) {
            $question['route_options'] = $this->fuel->options($agent, $fleet[$question['ship']]->snapshot, $question['destination'], listings: $listings);
            $question['ready_for_bounded_preview'] = $question['route_options'] !== [] && ! in_array($question['fact'], ['Hidden traits', 'Missing goods directory'], true);
            $question['api_estimate'] = 'One remote directory read before departure, a detailed read on arrival, and approximately 3-5 preflight reads per command. Shared throttling and actual waits still apply.';
        }
        unset($question);

        return ['valid_for_planning' => ! $agent->isArchived(), 'reset_id' => $agent->reset_id,
            'catalogues' => $observations->where('kind', 'catalogue')->values(), 'discovery' => $observations->firstWhere('kind', 'discovery')?->data,
            'hidden_waypoints' => $points->filter(fn ($point) => in_array('UNCHARTED', array_column($point->data['traits'], 'symbol'), true))->pluck('symbol')->values(),
            'markets' => $markets, 'opportunities' => $opportunities, 'observation_tasks' => $questions,
            'cargo_options' => $cargoOptions, 'observation_gaps' => $observationGaps, 'candidate_limit_reached' => count($opportunities) >= config('gameplay.opportunity_limit'),
            'evidence' => app(EvidenceAnalysis::class)->report($agent),
            'limits' => 'Local evidence only. Quote age is a client policy. Feasible estimates require a new bounded preview; they do not authorize movement, purchases or profit claims.',
            'fleet_gate' => 'Probes, surveys, hauling and refining require measured incremental benefit, loadouts and separate capital approval. Cargo refueling and other flight modes remain unvalidated.'];
    }

    private function cargo(Agent $agent, array $ship, array $markets, Collection $listings): array
    {
        $options = [];
        foreach (MiningPlanner::inventory($ship) as $symbol => $units) {
            $choices = [['action' => 'retain', 'reason' => 'Default while a run is paused. No disposal or purchase is authorized.', 'estimated_proceeds' => null]];
            foreach ($markets as $market) {
                if ($market['state'] !== 'recently_observed') {
                    continue;
                }
                $bid = collect($market['prices'])->firstWhere('symbol', $symbol);
                if (! $bid || $bid['sellPrice'] < 1) {
                    continue;
                }
                try {
                    $route = $this->fuel->leg($agent, $ship, $market['symbol'], listings: $listings);
                    $choices[] = ['action' => $ship['nav']['waypointSymbol'] === $market['symbol'] ? 'sell_locally' : 'travel_to_buyer', 'buyer' => $market['symbol'],
                        'batch_units' => min($units, $bid['tradeVolume']), 'estimated_proceeds' => $units * $bid['sellPrice'],
                        'remaining_fuel_ceiling' => $route['outbound_fuel'] > 0 ? $route['refuel_ceiling'] : 0, 'quote_at' => $market['price_at'],
                        'reason' => 'Remaining-cost estimate only. Acquisition is already paid; refresh every batch. Unsold inventory is not realized income.'];
                } catch (GameException) {
                }
            }
            $options[] = ['ship' => $ship['symbol'], 'good' => $symbol, 'units' => $units, 'choices' => $choices];
        }

        return $options;
    }

    private function trade(Agent $agent, array $ship, array $source, array $buyer, string $symbol, Collection $listings): array
    {
        $record = ['strategy' => 'trade', 'ship' => $ship['symbol'], 'source' => $source['symbol'], 'market' => $buyer['symbol'], 'good' => $symbol,
            'source_at' => $source['price_at'], 'buyer_at' => $buyer['price_at'], 'state' => 'goods_match_only', 'net_credits_per_hour' => null, 'conservative_net_per_hour' => null];
        if ($source['state'] !== 'recently_observed' || $buyer['state'] !== 'recently_observed') {
            return $record + ['reason' => 'Source or buyer prices are unknown or stale. Observe both endpoints before buying.'];
        }
        $ask = collect($source['prices'])->firstWhere('symbol', $symbol);
        $bid = collect($buyer['prices'])->firstWhere('symbol', $symbol);
        if (! $ask || ! $bid || $ask['purchasePrice'] < 1) {
            return $record + ['reason' => 'Detailed price is missing.'];
        }
        try {
            $positioning = $this->fuel->leg($agent, $ship, $source['symbol'], listings: $listings);
            $atSource = $ship;
            $atSource['nav']['waypointSymbol'] = $source['symbol'];
            $atSource['fuel']['current'] -= $positioning['outbound_fuel'];
            $outbound = $this->fuel->leg($agent, $atSource, $buyer['symbol'], listings: $listings);
            $atBuyer = $atSource;
            $atBuyer['nav']['waypointSymbol'] = $buyer['symbol'];
            $atBuyer['fuel']['current'] -= $outbound['outbound_fuel'];
            $return = $this->fuel->leg($agent, $atBuyer, $source['symbol'], listings: $listings);
            $fuelCost = $positioning['refuel_ceiling'] + $outbound['refuel_ceiling'] + $return['refuel_ceiling'];
            $units = min($ship['cargo']['capacity'] - $ship['cargo']['units'], $ask['tradeVolume'], $bid['tradeVolume'], intdiv(max(0, $agent->credits - $fuelCost), $ask['purchasePrice']));
            $margin = $units * ($bid['sellPrice'] - $ask['purchasePrice']) - $fuelCost;
            $record = array_merge($record, ['state' => $units > 0 && $margin > 0 ? 'estimated_candidate' : 'rejected', 'units' => $units,
                'inventory_exposure' => $units * $ask['purchasePrice'], 'estimated_cash_margin' => $margin,
                'conservative_cash_margin' => -$units * $ask['purchasePrice'] - $fuelCost, 'fuel_ceiling' => $fuelCost,
                'fuel_units' => $positioning['outbound_fuel'] + $outbound['outbound_fuel'] + $return['outbound_fuel'],
                'empty_return_included' => true, 'reason' => 'One-batch estimate includes positioning, an empty return and conservative refueling. Adverse case assumes cargo remains unsold. Wear, queue time, future prices and operator exposure limits remain unresolved.']);
        } catch (GameException $e) {
            $record['state'] = 'rejected';
            $record['reason'] = $e->getMessage();
        }

        return $record;
    }
}
