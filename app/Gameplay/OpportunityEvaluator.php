<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\Ship;
use Carbon\CarbonImmutable;
use Generator;

class OpportunityEvaluator
{
    private const CANDIDATE_LIMIT = 200;

    private const OBSERVE_HINT_LIMIT = 3;

    public function __construct(private MiningPlanner $mining, private ContractPlanner $contracts) {}

    public static function equipment(array $ship): string
    {
        $parts = [$ship['frame'], $ship['engine'], $ship['reactor'], $ship['mounts'], $ship['modules']];
        foreach ($parts as &$part) {
            unset($part['condition'], $part['integrity']);
        }

        return hash('sha256', json_encode($parts));
    }

    public function investment(Agent $agent, array $case): array
    {
        $reference = AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->findOrFail($case['reference_run']);
        $owned = Ship::where('agent_id', $agent->id)->get()->keyBy('symbol');
        $alternatives = AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('state', 'completed')
            ->whereIn('ship_symbol', $owned->keys())->whereNotNull('started_at')->latest('id')->limit(config('gameplay.evidence_run_limit'))->get()
            ->filter(fn ($run) => in_array($run->totals['accounting']['state'] ?? null, ['realized_comparable', 'estimated_comparable'], true)
                && ($run->plan['equipment'] ?? null) === self::equipment($owned[$run->ship_symbol]->snapshot)
                && ($run->totals['accounting']['net_credits_per_hour'] ?? null) !== null
                && collect(['initial_waypoint', 'initial_fuel', 'initial_inventory', 'site', 'market'])->every(fn ($key) => ($run->plan[$key] ?? null) === ($reference->plan[$key] ?? null)))
            ->sortByDesc(fn ($run) => $run->totals['accounting']['net_credits_per_hour']);
        $best = $alternatives->first();
        $result = app(CycleAccounting::class)->fleetInvestment($case, $best?->totals['accounting']['net_credits_per_hour']);

        return $result + ['alternative_run' => $best?->id, 'alternatives_in_window' => $alternatives->count(), 'inputs' => $case,
            'basis' => 'Entered base and conservative fleet net must include all operating costs and wear over the same closed cycle as the reference. Baseline is the best measured current-assets result in this bounded evidence window with matching endpoints, fuel and inventory. Unobserved alternatives remain unknown. No gameplay or purchase is authorized.'];
    }

    public static function quoteSignature(array $market): string
    {
        $quotes = array_map(fn ($q) => array_intersect_key($q, array_flip(['symbol', 'purchasePrice', 'sellPrice', 'tradeVolume'])), $market['tradeGoods'] ?? []);
        usort($quotes, fn ($a, $b) => $a['symbol'] <=> $b['symbol']);

        return hash('sha256', json_encode($quotes));
    }

    public function evaluate(array $observations, string $strategy, CarbonImmutable $now): array
    {
        $fresh = isset($observations['observed_at'], $observations['credits'], $observations['ships'], $observations['waypoints'])
            && CarbonImmutable::parse($observations['observed_at'])->betweenIncluded($now->subSeconds(config('gameplay.snapshot_seconds')), $now);
        $candidates = [];
        $points = $observations['waypoints'] ?? [];
        $markets = $observations['markets'] ?? [];
        uksort($markets, fn ($a, $b) => [empty($markets[$a]['tradeGoods']) ? 1 : 0, $a] <=> [empty($markets[$b]['tradeGoods']) ? 1 : 0, $b]);
        $credits = $observations['credits'] ?? null;
        $ships = $observations['ships'] ?? [];
        $focus = $strategy === 'mining' ? ($observations['focus'] ?? []) : [];
        $rank = function ($candidate) use ($focus) {
            $ready = $candidate['classification'] === 'ready';
            $focusedBuyer = isset($focus['buyer']) && $candidate['market'] === $focus['buyer'];
            $focusRank = $ready && $focusedBuyer ? (in_array($candidate['site'], $focus['sites'] ?? [], true) ? 0 : 1) : 2;
            $surveyRequired = $ready && ($candidate['survey_required_for_departure'] ?? false);
            $tier = match (true) {
                $focusRank === 0 => 0,
                ! $surveyRequired => 1,
                default => 2,
            };

            return [$ready ? 0 : 1, $tier, (int) $surveyRequired, $focusRank, $candidate['estimated_per_hour'] === null ? 1 : 0, -($candidate['estimated_per_hour'] ?? 0),
                $candidate['working_capital'] ?? PHP_INT_MAX, $candidate['duration_seconds'] ?? PHP_INT_MAX, $candidate['id']];
        };
        $compare = fn ($a, $b) => $rank($a) <=> $rank($b);

        if ($strategy === 'mining') {
            $hints = [];
            foreach ($this->miningCandidates($observations, $ships, $points, $markets, $now, $fresh) as $candidate) {
                if (isset($candidate['preparation'])) {
                    $hints[] = $candidate;

                    continue;
                }
                $candidates[] = $candidate;
                if (count($candidates) === self::CANDIDATE_LIMIT * 2) {
                    usort($candidates, $compare);
                    $candidates = array_slice($candidates, 0, self::CANDIDATE_LIMIT);
                }
            }
            usort($candidates, $compare);
            $candidates = array_merge(array_slice($candidates, 0, self::CANDIDATE_LIMIT - count($hints)), $hints);
        }
        foreach ($strategy === 'contracts' ? $ships : [] as $ship) {
            if ($ship['nav']['systemSymbol'] !== $observations['system']) {
                continue;
            }
            foreach ($observations['contracts'] ?? [] as $contract) {
                if ($contract['fulfilled']) {
                    continue;
                }
                foreach (array_unique(array_merge(array_keys($markets), [null]), SORT_REGULAR) as $source) {
                    foreach ([false, true] as $cargo) {
                        if ($cargo && $ship['cargo']['units'] === 0) {
                            continue;
                        }
                        $candidate = $this->base('contracts', $ship, $source, $observations);
                        $candidate += ['contract_id' => $contract['id'], 'use_existing_cargo' => $cargo, 'site' => null];
                        try {
                            $candidate['contract_plan'] = $plan = $this->contracts->plan($ship, $contract, $points, $markets, $source, $cargo, $now);
                            $candidate = array_merge($candidate, array_intersect_key($plan, array_flip(['estimated_net', 'estimated_per_hour', 'duration_seconds', 'working_capital', 'fuel_estimate', 'setup_cost'])));
                            $candidate['estimate_kind'] = 'contract_cash_contribution';
                            $candidate['estimates'] = ['state' => 'partial_estimate', 'base' => ['cash_contribution' => $plan['estimated_net'], 'net_per_hour' => null],
                                'conservative' => ['cash_contribution' => $plan['fuel_adjustment_estimate'] === null ? null : -$plan['purchase_cost'] - $plan['fuel_adjustment_estimate'], 'net_per_hour' => null],
                                'unresolved' => ['Contract cash ranking is not comparable complete-cycle income. Empty return, wear, fuel normalization and inventory acquisition basis remain unresolved.']];
                            $candidate['reasons'][] = 'Unpaid contract receipts minus verified acquisitions and estimated fuel consumption. Queue and API allowance included; sleep can miss deadlines.';
                            if ($credits === null || $credits < $plan['working_capital']) {
                                throw new GameException('Insufficient observed working capital.');
                            }
                        } catch (GameException $e) {
                            $candidate['classification'] = $this->classification($e->getMessage());
                            $candidate['reasons'][] = $e->getMessage();
                        }
                        $candidates[] = $this->finish($candidate, $fresh);
                        if (count($candidates) >= self::CANDIDATE_LIMIT) {
                            break 4;
                        }
                    }
                }
            }
        }
        usort($candidates, $compare);
        $hasDepartureReady = array_any($candidates, fn ($candidate) => $candidate['classification'] === 'ready' && empty($candidate['survey_required_for_departure']));
        $recommended = false;
        foreach ($candidates as &$candidate) {
            $eligible = ! $recommended && $candidate['classification'] === 'ready' && (! $hasDepartureReady || empty($candidate['survey_required_for_departure']));
            $candidate['recommended'] = $eligible && $candidate['estimated_per_hour'] !== null;
            $candidate['suggested_trial'] = $eligible && $candidate['strategy'] === 'mining' && ($candidate['trial_required'] ?? false);
            $recommended = $recommended || $candidate['recommended'];
        }

        return $candidates;
    }

    private function miningCandidates(array $observations, array $ships, array $points, array $markets, CarbonImmutable $now, bool $fresh): Generator
    {
        $system = $observations['system'];
        $sites = array_filter($points, fn ($site) => $site['systemSymbol'] === $system && MiningPlanner::hazard($site) === null);
        $markets = array_filter($markets, fn ($market) => ($points[$market]['systemSymbol'] ?? null) === $system, ARRAY_FILTER_USE_KEY);
        $quoteTimes = array_map(fn ($time) => CarbonImmutable::parse($time), $observations['market_times'] ?? []);
        $quoteSignatures = array_map(self::quoteSignature(...), $markets);
        $history = array_filter($observations['history'] ?? [], fn ($sample) => CarbonImmutable::parse($sample['at'])->betweenIncluded($now->subDays(7), $now));
        $capability = [];
        $ships = array_values(array_filter($ships, fn ($ship) => $ship['nav']['systemSymbol'] === $system));
        foreach ($ships as $ship) {
            $capability[$ship['symbol']] = $this->mining->capability($ship);
        }
        usort($ships, fn ($a, $b) => [$capability[$a['symbol']] !== null, $a['symbol']] <=> [$capability[$b['symbol']] !== null, $b['symbol']]);

        $allYields = $this->mining->allYields();
        $missingDepartureBids = array_map(fn ($quote) => $this->mining->missingBids($quote, $allYields), $markets);
        $observeHints = [];
        $focusedBuyer = $observations['focus']['buyer'] ?? null;
        foreach ($ships as $ship) {
            $start = $points[$ship['nav']['waypointSymbol']] ?? null;
            $blocker = $capability[$ship['symbol']] ?? match (true) {
                $ship['cargo']['units'] !== 0 || $ship['nav']['status'] === 'IN_TRANSIT' => 'Mining requires stationary empty cargo.',
                $ship['nav']['flightMode'] !== 'CRUISE' => 'Select a ship already in CRUISE mode.',
                ! $start || ! $sites || ! $markets => 'Missing observations for a complete same-system route with a safe mining site and buyer.',
                default => null,
            };
            if ($blocker !== null) {
                yield $this->miningBlocker($ship, $observations, $blocker, $fresh);

                continue;
            }

            $anyFuelFit = false;
            $deposits = $this->mining->possibleYields($ship);
            $equipment = self::equipment($ship);
            $shipHistory = array_filter($history, fn ($sample) => $sample['equipment'] === $equipment
                && ($sample['initial_waypoint'] ?? null) === $ship['nav']['waypointSymbol']
                && ($sample['initial_fuel'] ?? null) === $ship['fuel']['current']);
            $quoteBlockers = [];
            foreach ($sites as $site => $point) {
                foreach ($markets as $market => $quote) {
                    $fuel = $this->mining->routeFuel($ship, $start, $point, $points[$market]);
                    if ($fuel === null) {
                        continue;
                    }
                    $anyFuelFit = true;
                    $candidate = $this->base('mining', $ship, $market, $observations) + ['site' => $site, 'trial_required' => true];
                    try {
                        $route = $this->mining->quotedRoute($site, $market, $quote, $quoteTimes[$market] ?? null, $observations['credits'] ?? 0, $now, $ship['fuel']['capacity'], $fuel, $deposits);
                    } catch (GameException $e) {
                        $reason = $e->getMessage();
                        if (isset($quoteBlockers[$market])) {
                            continue;
                        }
                        $quoteBlockers[$market] = true;
                        $candidate['classification'] = $this->classification($reason);
                        $candidate['reasons'][] = $reason;
                        if ($focusedBuyer === null && count($observeHints) < self::OBSERVE_HINT_LIMIT && ! isset($observeHints[$market])
                            && str_starts_with($reason, 'Buyer prices are unknown or stale.')) {
                            $candidate['preparation'] = ['kind' => 'observe_market', 'ship_symbol' => $ship['symbol'], 'market' => $market, 'at_market' => $ship['nav']['waypointSymbol'] === $market];
                            $observeHints[$market] = true;
                        }
                        yield $this->finish($candidate, $fresh);

                        continue;
                    }

                    $signature = $quoteSignatures[$market];
                    $samples = array_values(array_filter($shipHistory, fn ($sample) => $sample['site'] === $site && $sample['market'] === $market && ($sample['quote_signature'] ?? null) === $signature));
                    $candidate = $this->withMiningEstimate($candidate, $route, $signature, $equipment, $samples);
                    if ($ship['nav']['waypointSymbol'] !== $site && $missingDepartureBids[$market] !== []) {
                        $candidate['survey_required_for_departure'] = true;
                        $candidate['reasons'][] = 'Departure requires a compatible signed survey. Missing positive bids for '.implode(', ', $missingDepartureBids[$market]).'.';
                    }
                    yield $this->finish($candidate, $fresh);
                }
            }
            if (! $anyFuelFit) {
                yield $this->miningBlocker($ship, $observations, MiningPlanner::FUEL_BLOCKER, $fresh);
            }
        }
    }

    private function miningBlocker(array $ship, array $observations, string $reason, bool $fresh): array
    {
        $candidate = $this->base('mining', $ship, null, $observations) + ['site' => null];
        $candidate['classification'] = $this->classification($reason);
        $candidate['reasons'][] = $reason;

        return $this->finish($candidate, $fresh);
    }

    private function withMiningEstimate(array $candidate, array $route, string $signature, string $equipment, array $samples): array
    {
        $candidate['quote_signature'] = $signature;
        $candidate['route'] = $route;
        $candidate['working_capital'] = $route['fuel_ceiling'];
        $candidate['fuel_estimate'] = $route['fuel_estimate'];
        $candidate['minimum_reserve'] = $route['minimum_reserve'];
        $candidate['reasons'][] = 'Trial required. One extraction per trip; unknown yields and duration do not establish an earnings estimate.';
        $candidate['yield_history'] = ['site' => $candidate['site'], 'equipment' => $equipment, 'sample_count' => count($samples), 'oldest_at' => $samples ? min(array_column($samples, 'at')) : null];
        $candidate['estimates'] = app(CycleAccounting::class)->forecast($samples);
        if ($candidate['estimates']['state'] === 'estimate') {
            $candidate['trial_required'] = false;
            $candidate['estimated_net'] = $candidate['estimates']['base']['net'];
            $candidate['duration_seconds'] = $candidate['estimates']['base']['seconds'];
            $candidate['estimated_per_hour'] = $candidate['estimates']['base']['net_per_hour'];
            $candidate['conservative_per_hour'] = $candidate['estimates']['conservative']['net_per_hour'];
            $candidate['reasons'] = [$candidate['estimates']['basis']];
        }

        return $candidate;
    }

    private function base(string $strategy, array $ship, ?string $market, array $observations): array
    {
        return ['strategy' => $strategy, 'ship_symbol' => $ship['symbol'], 'market' => $market, 'classification' => 'ready', 'reasons' => [], 'estimated_net' => null,
            'estimated_per_hour' => null, 'duration_seconds' => null, 'working_capital' => null, 'fuel_estimate' => null, 'setup_cost' => 0, 'observed_at' => $observations['observed_at'] ?? null,
            'estimate_kind' => 'complete_cycle_net', 'required_equipment' => $strategy === 'mining' ? ['Mining laser', 'Mineral processor', 'Cargo capacity'] : ['Operational ship', 'Cargo capacity']];
    }

    private function finish(array $candidate, bool $fresh): array
    {
        if (! $fresh && $candidate['classification'] === 'ready') {
            $candidate['classification'] = 'needs_information';
            $candidate['reasons'][] = 'Required ship, credit or route observations are missing or stale. Investigate again before staging.';
        }
        $candidate['id'] = substr(hash('sha256', json_encode([$candidate['strategy'], $candidate['ship_symbol'], $candidate['site'] ?? null, $candidate['market'], $candidate['contract_id'] ?? null, $candidate['use_existing_cargo'] ?? false])), 0, 24);

        return $candidate;
    }

    private function classification(string $reason): string
    {
        return match (true) {
            str_contains($reason, 'Missing mining laser'), str_contains($reason, 'Missing mineral processor') => 'needs_preparation',
            str_contains(strtolower($reason), 'unsupported'), str_contains($reason, 'Only unfulfilled'), str_contains($reason, 'same-system'), str_contains($reason, 'selected system') => 'unsupported',
            str_contains(strtolower($reason), 'unknown'), str_contains(strtolower($reason), 'scouting'), str_contains(strtolower($reason), 'missing'), str_contains(strtolower($reason), 'stale') => 'needs_information',
            default => 'needs_preparation',
        };
    }
}
