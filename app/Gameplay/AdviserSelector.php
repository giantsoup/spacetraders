<?php

namespace App\Gameplay;

use Carbon\CarbonImmutable;

class AdviserSelector
{
    public const VERSION = 2;

    public static function hash(array $value): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $sort($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR));
    }

    public function evaluate(array $evidence): array
    {
        $policy = $evidence['policy'];
        $terms = $policy['terms'];
        $ship = $evidence['ship'];
        $now = CarbonImmutable::parse($evidence['as_of']);
        $candidates = [];
        $make = fn ($activity, $target = []) => ['activity' => $activity, 'ship_symbol' => $policy['ship_symbol'], 'target' => $target,
            'classification' => 'feasible', 'reasons' => [], 'max_debit' => 0, 'max_loss' => 0, 'fuel_units' => 0,
            'duration_seconds' => null, 'max_seconds' => $terms['max_seconds'], 'api_requests' => null,
            'base_net' => null, 'conservative_net' => null, 'conservative_seconds' => null, 'question' => null, 'priority' => 9];
        $wait = $make('wait', ['until' => $this->deadline($ship, $now)]);
        $wait['reasons'][] = 'Wait for the observed deadline, or user intervention. Waiting does not cancel travel or reserved work.';
        $wait['api_requests'] = 0;
        $candidates[] = $wait;
        $read = $make('read', ['kind' => 'fleet_and_balance']);
        $read['api_requests'] = 2;
        $read['question'] = 'What are this ship\'s current state and the confirmed agent balance? Refresh before creating any command preview.';
        $read['reasons'][] = 'Supported observation requests only. Reading never authorizes movement.';
        $candidates[] = $read;
        $rows = collect($evidence['observations']);
        $local = $rows->first(fn ($o) => $o['kind'] === 'market' && $o['symbol'] === ($ship['nav']['waypointSymbol'] ?? null));
        $fresh = $local && empty($local['data']['invalidated_by_action']) && $this->fresh($local['observed_at'], $now, $evidence['parameters']['snapshot_seconds']);
        $goods = collect($local['data']['tradeGoods'] ?? []);
        $inventory = isset($ship['cargo']['inventory']) ? MiningPlanner::inventory($ship) : [];
        ksort($inventory);
        $allowedCargo = array_column($terms['cargo'], 'units', 'symbol');
        foreach ($inventory as $symbol => $units) {
            $quote = $goods->firstWhere('symbol', $symbol);
            $sale = $make('sale', ['symbol' => $symbol, 'market' => $ship['nav']['waypointSymbol'], 'units' => min($units, $allowedCargo[$symbol] ?? 0, $quote['tradeVolume'] ?? $units)]);
            if (! isset($allowedCargo[$symbol])) {
                $sale['classification'] = 'blocked';
                $sale['reasons'][] = 'Starting cargo is protected. This symbol has no explicit sale allowance.';
            } elseif (! $fresh || ! $quote || $quote['sellPrice'] < 1 || $quote['tradeVolume'] < 1) {
                $sale['classification'] = 'missing_information';
                $sale['reasons'][] = 'A fresh positive local sale quote and trade volume are required.';
            } else {
                $sale['base_net'] = $sale['target']['units'] * $quote['sellPrice'];
                $sale['quote'] = $quote;
                $sale['quote_at'] = $local['observed_at'];
                $sale['reasons'][] = 'One permitted batch, dock first if needed. Base is remaining-cost cash receipts, not profit. Acquisition cost and future execution price are unknown; conservative income stays unknown.';
            }
            $candidates[] = $sale;
        }
        $refuel = $make('refuel', ['market' => $ship['nav']['waypointSymbol'] ?? null, 'units' => min($terms['refuel_units'], max(0, ($ship['fuel']['capacity'] ?? 0) - ($ship['fuel']['current'] ?? 0)))]);
        $fuel = $goods->firstWhere('symbol', 'FUEL');
        if ($refuel['target']['units'] === 0) {
            $refuel['classification'] = 'blocked';
            $refuel['reasons'][] = 'No permitted tank quantity is needed or allowed.';
        } elseif (! $fresh || ! $fuel || $fuel['purchasePrice'] < 1 || $fuel['tradeVolume'] < (int) ceil($refuel['target']['units'] / 100)) {
            $refuel['classification'] = 'missing_information';
            $refuel['reasons'][] = 'Refresh the local fuel price and available trade volume.';
        } else {
            $refuel['max_debit'] = $refuel['max_loss'] = (int) ceil($refuel['target']['units'] / 100) * $fuel['purchasePrice'] * 2;
            $refuel['base_net'] = -(int) ceil($refuel['target']['units'] / 100) * $fuel['purchasePrice'];
            $refuel['quote'] = $fuel;
            $refuel['quote_at'] = $local['observed_at'];
            $refuel['reasons'][] = 'Dock if needed; refresh before refueling. A 2x quote allowance is a local spending bound, not an upstream price guarantee. No income is assumed.';
        }
        $candidates[] = $refuel;

        $investigation = $evidence['investigation'];
        if ($evidence['mining_candidates'] !== null) {
            foreach ($evidence['mining_candidates'] as $source) {
                $mining = $make('mining', ['site' => $source['site'], 'market' => $source['market'], 'extractions' => 1, 'cycles' => 1]);
                $mining['classification'] = match ($source['classification']) {
                    'ready' => 'feasible', 'needs_information' => 'missing_information', 'unsupported' => 'unsupported', default => 'blocked',
                };
                $mining['source_candidate_id'] = $source['id'];
                $mining['source'] = $source;
                $mining['reasons'] = $source['reasons'];
                $mining['max_debit'] = $mining['max_loss'] = $source['working_capital'];
                $mining['fuel_units'] = $source['fuel_estimate'];
                $mining['base_net'] = $source['estimated_net'];
                $mining['duration_seconds'] = $source['duration_seconds'];
                $mining['trial'] = $source['trial_required'] ?? true;
                if (! $mining['trial']) {
                    $forecast = $source['estimates'];
                    if ($forecast['sample_count'] >= 3) {
                        $mining['conservative_net'] = $forecast['conservative']['net'];
                        $mining['conservative_seconds'] = $forecast['conservative']['seconds'];
                        $mining['reasons'][] = 'Conservative comparison uses comparable closed-cycle net after wear. Historical bounds are estimates, not guarantees.';
                    }
                    if ($mining['conservative_net'] === null || $mining['conservative_net'] <= 0) {
                        $mining['classification'] = 'blocked';
                        $mining['reasons'][] = 'At least three comparable closed cycles with positive conservative net after wear are required. Refresh or review the evidence.';
                    }
                } elseif (! $terms['allow_trial']) {
                    $mining['classification'] = 'blocked';
                    $mining['reasons'][] = 'Unmeasured yields require explicit bounded-trial permission. Earnings remain unknown.';
                }
                if (isset($source['route']) && ($source['route']['unknown_yields'] ?? []) !== []) {
                    $mining['classification'] = 'missing_information';
                    $mining['reasons'][] = 'Every possible yield needs a buyer before policy selection; retain unmatched cargo.';
                }
                if (isset($ship['cooldown']['readyAt']) && $now->lt($ship['cooldown']['readyAt'])) {
                    $mining['classification'] = 'blocked';
                    $mining['reasons'][] = 'Wait for the observed cooldown deadline and refresh readiness.';
                }
                $candidates[] = $mining;
            }
        } else {
            $mining = $make('mining');
            $mining['classification'] = 'missing_information';
            $mining['reasons'][] = 'Collect a bounded investigation before evaluating a mining route.';
            $candidates[] = $mining;
        }

        $possibleYields = $evidence['possible_yields'];
        foreach ($rows->where('kind', 'market_listing')->take(24) as $listing) {
            $market = $rows->first(fn ($o) => $o['kind'] === 'market' && $o['symbol'] === $listing['symbol']);
            if ($market && ! empty($market['data']['tradeGoods']) && empty($market['data']['invalidated_by_action']) && $this->fresh($market['observed_at'], $now, $evidence['parameters']['snapshot_seconds'])) {
                continue;
            }
            $listed = array_column(array_merge($listing['data']['imports'], $listing['data']['exports'], $listing['data']['exchange']), 'symbol');
            $relevant = array_values(array_intersect($listed, array_unique(array_merge(array_keys($allowedCargo), $possibleYields, ['FUEL']))));
            sort($relevant);
            if ($relevant === []) {
                continue;
            }
            $question = 'What are the prices and trade volumes for '.implode(', ', $relevant).' at '.$listing['symbol'].'? They can change the cargo buyer, mining route or recovery fuel choice.';
            if ($listing['symbol'] === ($ship['nav']['waypointSymbol'] ?? null)) {
                $read = $make('read', ['kind' => 'market', 'symbol' => $listing['symbol']]);
                $read['question'] = $question;
                $read['api_requests'] = 1;
                $candidates[] = $read;

                continue;
            }
            $scout = $make('scout', ['destination' => $listing['symbol'], 'return' => $ship['nav']['waypointSymbol'] ?? null, 'max_visits' => 1]);
            $scout['question'] = $question;
            $scout['question_id'] = 'prices:'.$listing['symbol'];
            $scout['priority'] = array_intersect($relevant, array_keys($allowedCargo)) ? 0 : (array_intersect($relevant, $possibleYields) ? 1 : 2);
            $route = $evidence['scout_plans'][$listing['symbol']] ?? [];
            if (! isset($route['plan'])) {
                $scout['classification'] = 'missing_information';
                $scout['reasons'][] = $route['reason'] ?? 'A verified fuel-safe round trip is missing.';
            } else {
                $scout['plan'] = $route['plan'];
                $scout['max_debit'] = $scout['max_loss'] = $route['plan']['route']['fuel_ceiling'];
                $scout['fuel_units'] = $route['plan']['route']['fuel_estimate'];
                $seconds = [$route['plan']['route']['outbound']['estimated_seconds'], $route['plan']['route']['return']['estimated_seconds']];
                $scout['duration_seconds'] = in_array(null, $seconds, true) ? null : array_sum($seconds) + $evidence['parameters']['scheduling_allowance_seconds'];
                $scout['base_net'] = -$scout['max_debit'];
                $scout['reasons'][] = 'One detailed quote then return to the verified home fuel seller. Estimated time includes a scheduling allowance; API waits and information value remain unknown. No future receipts fund departure.';
            }
            $exploration = $terms['exploration'];
            $due = ! $evidence['last_exploration_at'] || $now->gte(CarbonImmutable::parse($evidence['last_exploration_at'])->addSeconds($exploration['interval_seconds']));
            if (! $exploration['enabled'] || ! $due || $scout['max_debit'] > $exploration['max_cost'] || $scout['fuel_units'] > $exploration['max_fuel'] || $scout['duration_seconds'] === null || $scout['duration_seconds'] > $exploration['max_seconds']) {
                $scout['classification'] = 'blocked';
                $scout['reasons'][] = 'Exploration is off, not due, or exceeds its explicit credit/fuel/time allowance. No unchosen allowance is inferred.';
            }
            $scout['max_seconds'] = $exploration['max_seconds'];
            $candidates[] = $scout;
        }
        $unsupported = $make('contracts');
        $unsupported['classification'] = 'unsupported';
        $unsupported['reasons'][] = 'Contract execution is outside the first adviser release. Existing manual contract investigation and approval remain separate.';
        $candidates[] = $unsupported;

        foreach ($candidates as &$candidate) {
            $candidate = $this->constrain($candidate, $evidence, $now);
            $candidate['id'] = substr(self::hash([$candidate['activity'], $candidate['ship_symbol'], $candidate['target']]), 0, 24);
            $candidate['plan_hash'] = self::hash(['selector_version' => self::VERSION, 'grant_hash' => $policy['grant_hash'], 'evidence_hash' => self::hash($evidence), 'candidate' => $candidate]);
        }
        unset($candidate);
        usort($candidates, fn ($a, $b) => $a['id'] <=> $b['id']);
        $selection = $this->select($candidates);

        return ['candidates' => $candidates, 'selection' => $selection + ['execution_enabled' => false, 'model_status' => 'disabled', 'selector_version' => self::VERSION]];
    }

    private function constrain(array $candidate, array $evidence, CarbonImmutable $now): array
    {
        $terms = $evidence['policy']['terms'];
        $block = function (string $reason, string $classification = 'blocked') use (&$candidate) {
            if ($candidate['classification'] !== 'unsupported') {
                $candidate['classification'] = $classification;
            }
            $candidate['reasons'][] = $reason;
        };
        if (! in_array($candidate['activity'], $terms['activities'], true) && $candidate['activity'] !== 'wait') {
            $block('Activity is outside this grant.');
        }
        if ($candidate['activity'] === 'read' && $candidate['api_requests'] > $terms['api_request_cap']) {
            $block('The observation request count exceeds this grant.');
        }
        if (in_array($candidate['activity'], ['wait', 'read', 'contracts'], true)) {
            return $candidate;
        }
        if (! $evidence['ship'] || ($evidence['ship']['nav']['systemSymbol'] ?? null) !== $evidence['policy']['system_symbol']) {
            $block('The selected ship is absent or outside the permitted system.');
        }
        if (! $this->fresh($evidence['ship_observed_at'], $now, $evidence['parameters']['snapshot_seconds']) || ! $this->fresh($evidence['balance_observed_at'], $now, $evidence['parameters']['snapshot_seconds']) || $evidence['credits'] === null) {
            $block('Ship or balance evidence is missing or stale. Refresh before previewing.', 'missing_information');
        }
        if ($evidence['unsettled'] !== [] || $evidence['active_run']) {
            $block('An agent activity owns credits or an action is pending, uncertain or requires review. Stop and reconcile before a new start.');
        }
        if (($evidence['accounting']['unexplained_change'] ?? 0) !== 0) {
            $block('Unexplained balance changes require review before selection.');
        }
        if ($candidate['activity'] === 'scout' && ($evidence['ship']['cargo']['units'] ?? null) !== 0) {
            $block('Scouting currently requires empty cargo. Retain starting inventory until a separate sale is approved.');
        }
        if ($candidate['activity'] === 'mining' && isset($candidate['source']['minimum_reserve']) && $evidence['credits'] !== null && max($terms['reserve'], $candidate['source']['minimum_reserve']) > $evidence['credits'] - ($candidate['max_debit'] ?? 0)) {
            $block('Mining recovery and planner minimum reserve must both remain funded.');
        }
        if (($evidence['ship']['nav']['status'] ?? null) === 'IN_TRANSIT') {
            $block('Wait for arrival and refresh; travel continues upstream.');
        }
        if ($candidate['max_debit'] === null || $candidate['fuel_units'] === null) {
            $block('Maximum debit or fuel use is unknown.', 'missing_information');
        } elseif ($candidate['max_debit'] > $terms['max_spend'] || $candidate['max_loss'] > $terms['max_loss'] || $candidate['fuel_units'] > $terms['max_fuel']) {
            $block('Candidate exceeds the activity spending, loss or fuel bound.');
        } elseif ($candidate['max_debit'] > 0 && ($evidence['credits'] === null || $terms['reserve'] > $evidence['credits'] - $candidate['max_debit'])) {
            $block('Confirmed credits cannot fund the maximum debit and recovery above the protected balance. Unconfirmed receipts are excluded.');
        }
        if ($candidate['duration_seconds'] !== null && $candidate['duration_seconds'] > $candidate['max_seconds']) {
            $block('Estimated duration exceeds the activity time bound.');
        }

        return $candidate;
    }

    public function select(array $candidates): array
    {
        $eligible = array_values(array_filter($candidates, fn ($c) => $c['classification'] === 'feasible'));
        $tier = fn ($c) => match (true) {
            $c['activity'] === 'scout' => 0,
            $c['conservative_net'] !== null && $c['conservative_net'] > 0 && $c['conservative_seconds'] > 0 => 1,
            $c['activity'] === 'sale' => 2,
            $c['activity'] === 'mining' && ($c['trial'] ?? false) => 3,
            $c['activity'] === 'refuel' => 4,
            $c['activity'] === 'read' => 5,
            default => 6,
        };
        usort($eligible, function ($a, $b) use ($tier) {
            $order = $tier($a) <=> $tier($b);
            if ($order === 0 && $tier($a) === 0) {
                $order = $a['priority'] <=> $b['priority'];
            }
            if ($order === 0 && $tier($a) === 1) {
                $order = ($b['conservative_net'] * $a['conservative_seconds']) <=> ($a['conservative_net'] * $b['conservative_seconds']);
            }

            return $order ?: ([$a['max_debit'], $a['duration_seconds'] ?? $a['max_seconds'], $a['id']] <=> [$b['max_debit'], $b['duration_seconds'] ?? $b['max_seconds'], $b['id']]);
        });
        $winner = $eligible[0];
        $rule = ['due_exploration', 'historical_conservative_income_rate', 'permitted_cargo_recovery', 'explicit_unknown_income_trial', 'bounded_refuel_preparation', 'read_only_refresh', 'wait'][$tier($winner)];

        return ['candidate_id' => $winner['id'], 'plan_hash' => $winner['plan_hash'], 'rule' => $rule,
            'components' => ['tier' => $tier($winner), 'question_priority' => $winner['priority'], 'base_net' => $winner['base_net'], 'conservative_net' => $winner['conservative_net'],
                'conservative_seconds' => $winner['conservative_seconds'], 'committed_capital' => $winner['max_debit'], 'duration_seconds' => $winner['duration_seconds'], 'hard_time_limit' => $winner['max_seconds']],
            'tie_breaks' => ['lower_committed_capital', 'shorter_estimated_or_bounded_time', 'stable_candidate_id'],
            'reason' => 'Feasible and permitted choices only. Due configured exploration precedes supported historical income, permitted cargo recovery, explicit trials, preparation, reads and waiting. Unknown earnings never become a numeric score.'];
    }

    private function fresh(?string $at, CarbonImmutable $now, int $seconds): bool
    {
        return $at && CarbonImmutable::parse($at)->betweenIncluded($now->subSeconds($seconds), $now);
    }

    private function deadline(?array $ship, CarbonImmutable $now): ?string
    {
        $dates = array_filter([($ship['nav']['status'] ?? null) === 'IN_TRANSIT' ? ($ship['nav']['route']['arrival'] ?? null) : null, $ship['cooldown']['readyAt'] ?? null], fn ($date) => $date && CarbonImmutable::parse($date)->gt($now));
        sort($dates);

        return $dates[0] ?? null;
    }
}
