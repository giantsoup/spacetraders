<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\Ship;
use Carbon\CarbonImmutable;

class OpportunityEvaluator
{
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

    public function evaluate(array $observations, string $strategy, CarbonImmutable $now, bool $complete): array
    {
        $complete = $complete && isset($observations['observed_at']) && CarbonImmutable::parse($observations['observed_at'])->gte($now->subSeconds(180));
        $candidates = [];
        $points = $observations['waypoints'] ?? [];
        $markets = $observations['markets'] ?? [];
        $credits = $observations['credits'] ?? null;
        foreach ($observations['ships'] ?? [] as $ship) {
            if ($ship['nav']['systemSymbol'] !== $observations['system']) {
                continue;
            }
            if ($strategy === 'contracts') {
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
                            $candidates[] = $this->finish($candidate, $complete);
                            if (count($candidates) >= 200) {
                                break 3;
                            }
                        }
                    }
                }
            } else {
                $sites = array_filter($points, fn ($p) => in_array($p['type'], ['ASTEROID', 'ASTEROID_FIELD', 'ENGINEERED_ASTEROID'], true));
                foreach ($sites as $site) {
                    foreach ($markets ?: [null => []] as $market => $quote) {
                        $candidate = $this->base('mining', $ship, $market, $observations) + ['site' => $site['symbol'], 'trial_required' => true];
                        try {
                            if ($ship['cargo']['units'] !== 0 || $ship['nav']['status'] === 'IN_TRANSIT') {
                                throw new GameException('Needs preparation. Mining requires stationary empty cargo.');
                            }
                            $route = $this->mining->evaluateRoute($ship, $site['symbol'], $market, $points, $quote, isset($observations['market_times'][$market]) ? CarbonImmutable::parse($observations['market_times'][$market]) : null, $credits ?? 0, $now);
                            $candidate['quote_signature'] = self::quoteSignature($quote);
                            $candidate['route'] = $route;
                            $candidate['working_capital'] = $route['fuel_ceiling'];
                            $candidate['fuel_estimate'] = $route['fuel_estimate'];
                            $candidate['minimum_reserve'] = $route['minimum_reserve'];
                            $candidate['reasons'][] = 'Trial required. One extraction per trip; unknown yields and duration do not establish an earnings estimate.';
                            $samples = array_values(array_filter($observations['history'] ?? [], fn ($h) => $h['site'] === $site['symbol'] && $h['market'] === $market && $h['equipment'] === self::equipment($ship) && ($h['quote_signature'] ?? null) === self::quoteSignature($quote) && ($h['initial_waypoint'] ?? null) === $ship['nav']['waypointSymbol'] && ($h['initial_fuel'] ?? null) === $ship['fuel']['current'] && CarbonImmutable::parse($h['at'])->betweenIncluded($now->subDays(7), $now)));
                            $candidate['yield_history'] = ['site' => $site['symbol'], 'equipment' => self::equipment($ship), 'sample_count' => count($samples), 'oldest_at' => $samples ? min(array_column($samples, 'at')) : null];
                            $candidate['estimates'] = app(CycleAccounting::class)->forecast($samples);
                            if ($candidate['estimates']['state'] === 'estimate') {
                                $candidate['trial_required'] = false;
                                $candidate['estimated_net'] = $candidate['estimates']['base']['net'];
                                $candidate['duration_seconds'] = $candidate['estimates']['base']['seconds'];
                                $candidate['estimated_per_hour'] = $candidate['estimates']['base']['net_per_hour'];
                                $candidate['conservative_per_hour'] = $candidate['estimates']['conservative']['net_per_hour'];
                                $candidate['reasons'] = [$candidate['estimates']['basis']];
                            }
                        } catch (GameException $e) {
                            $candidate['classification'] = $this->classification($e->getMessage());
                            $candidate['reasons'][] = $e->getMessage();
                        }
                        $candidates[] = $this->finish($candidate, $complete);
                        if (count($candidates) >= 200) {
                            break 2;
                        }
                    }
                }
            }
            if (count($candidates) >= 200) {
                break;
            }
        }
        usort($candidates, fn ($a, $b) => [($a['classification'] === 'ready' ? 0 : 1), $a['estimated_per_hour'] === null ? 1 : 0, -($a['estimated_per_hour'] ?? 0), $a['working_capital'] ?? PHP_INT_MAX, $a['duration_seconds'] ?? PHP_INT_MAX, $a['id']]
            <=> [($b['classification'] === 'ready' ? 0 : 1), $b['estimated_per_hour'] === null ? 1 : 0, -($b['estimated_per_hour'] ?? 0), $b['working_capital'] ?? PHP_INT_MAX, $b['duration_seconds'] ?? PHP_INT_MAX, $b['id']]);
        $recommended = false;
        foreach ($candidates as &$candidate) {
            $candidate['recommended'] = ! $recommended && $candidate['classification'] === 'ready' && $candidate['estimated_per_hour'] !== null;
            $candidate['suggested_trial'] = ! $recommended && $candidate['classification'] === 'ready' && $candidate['strategy'] === 'mining' && ($candidate['trial_required'] ?? false);
            $recommended = $recommended || $candidate['recommended'];
        }

        return array_slice($candidates, 0, 200);
    }

    private function base(string $strategy, array $ship, ?string $market, array $observations): array
    {
        return ['strategy' => $strategy, 'ship_symbol' => $ship['symbol'], 'market' => $market, 'classification' => 'ready', 'reasons' => [], 'estimated_net' => null,
            'estimated_per_hour' => null, 'duration_seconds' => null, 'working_capital' => null, 'fuel_estimate' => null, 'setup_cost' => 0, 'observed_at' => $observations['observed_at'] ?? null,
            'estimate_kind' => 'complete_cycle_net', 'required_equipment' => $strategy === 'mining' ? ['Mining laser', 'Mineral processor', 'Cargo capacity'] : ['Operational ship', 'Cargo capacity']];
    }

    private function finish(array $candidate, bool $complete): array
    {
        if (! $complete) {
            $candidate['classification'] = 'needs_information';
            $candidate['reasons'][] = 'Investigation incomplete. Refresh before staging.';
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
