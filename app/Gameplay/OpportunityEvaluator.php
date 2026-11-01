<?php

namespace App\Gameplay;

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
                            $samples = array_values(array_filter($observations['history'] ?? [], fn ($h) => $h['site'] === $site['symbol'] && $h['market'] === $market && $h['equipment'] === self::equipment($ship) && ($h['quote_signature'] ?? null) === self::quoteSignature($quote) && ($h['initial_waypoint'] ?? null) === $ship['nav']['waypointSymbol'] && ($h['initial_fuel'] ?? null) === $ship['fuel']['current'] && CarbonImmutable::parse($h['at'])->gte($now->subDays(7))));
                            $candidate['yield_history'] = ['site' => $site['symbol'], 'equipment' => self::equipment($ship), 'sample_count' => count($samples), 'oldest_at' => $samples ? min(array_column($samples, 'at')) : null];
                            if (count($samples) >= 3 && array_sum(array_column($samples, 'seconds')) > 0) {
                                $candidate['trial_required'] = false;
                                $candidate['estimated_net'] = (int) floor(array_sum(array_column($samples, 'contribution')) / count($samples));
                                $candidate['duration_seconds'] = (int) ceil(array_sum(array_column($samples, 'seconds')) / count($samples));
                                $candidate['estimated_per_hour'] = (int) floor($candidate['estimated_net'] * 3600 / $candidate['duration_seconds']);
                                $candidate['reasons'] = ['Estimate uses '.count($samples).' confirmed trips at this site with this equipment within seven days. Cash contribution excludes ship wear and time.'];
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
            'required_equipment' => $strategy === 'mining' ? ['Mining laser', 'Mineral processor', 'Cargo capacity'] : ['Operational ship', 'Cargo capacity']];
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
