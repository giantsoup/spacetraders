<?php

declare(strict_types=1);

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;

final class FleetAllocator
{
    private const array CLASS_ORDER = ['trader' => 0, 'siphon' => 1, 'miner' => 2, 'surveyor' => 3];

    public function __construct(private readonly HullCatalog $hulls = new HullCatalog) {}

    /**
     * The candidate supplies delta_v (already realization-adjusted for traders), delta_q,
     * effective_cost and capital_weight. An exact type supplies the default hull cap and class.
     */
    public function evaluateCandidate(array $candidate, int $shadowPrice, float $hoursToReset, float $horizonAfterDeliveryHours, ?int $referenceRate = null, int $hurdlePermille = 100, bool $canaryMode = false): array
    {
        $type = $candidate['type'] ?? '';
        $spec = $this->hulls->spec($type);
        $class = $spec['class'] ?? $candidate['class'] ?? null;
        $cost = $candidate['effective_cost'];
        $capitalWeight = $candidate['capital_weight'] ?? $this->hulls->capitalWeight($type);
        $horizon = max(0.0, $horizonAfterDeliveryHours - 1.0);
        $gain = $candidate['delta_v'] - $shadowPrice * $candidate['delta_q'];
        $value = (int) floor($gain * $horizon) - $cost;
        $density = $cost + $capitalWeight > 0 ? (int) floor(1000 * $gain / ($cost + $capitalWeight)) : 0;
        $payback = $gain > 0 ? $cost / $gain : INF;
        $offer = $candidate['price'] ?? $cost;
        $hardCap = min($spec['hard_cap'] ?? PHP_INT_MAX, $candidate['hard_cap'] ?? PHP_INT_MAX);
        $withinCeilings = $offer <= $hardCap && $offer <= ($candidate['approved_ceiling'] ?? PHP_INT_MAX);
        $normalCeiling = $candidate['normal_price_ceiling'] ?? (isset($candidate['p_min'])
            ? $this->hulls->normalPriceCeiling($candidate['p_min'], $type, $hardCap, $candidate['inflation_cap_pct'] ?? null)
            : $hardCap);
        $overBand = $offer > $normalCeiling;
        $positive = $gain > 0 && $value > 0 && $withinCeilings;
        $paybackLimit = 0.33 * $horizon;
        $normal = $positive && $density >= ($overBand ? 2 * $hurdlePermille : $hurdlePermille) && $payback <= $paybackLimit;
        $reference = min($gain, $referenceRate ?? $gain);
        $g8 = $class !== 'trader' || ($hoursToReset >= 24.0 && $horizon > 0 && $reference > 0
            && $cost * 100 <= 33 * $reference * $horizon);
        $canary = $positive && $payback <= min(24.0, $paybackLimit)
            && ($candidate['approved_exposure'] ?? $candidate['cash_spend'] ?? $cost) <= 60000
            && (! $overBand || $density >= 2 * $hurdlePermille);

        return [
            'h_value' => $horizon, 'g' => $gain, 'nv' => $value, 'rho' => $density, 'payback' => $payback,
            'within_ceilings' => $withinCeilings, 'inflation_overrun' => $overBand,
            'normal' => $normal, 'g8' => $g8, 'canary' => $canary,
            'g8_applicable' => $class === 'trader',
            'g8_reason' => match (true) {
                $g8 => null,
                $hoursToReset < 24.0 || $horizon <= 0.0 => 'time_to_reset',
                $reference <= 0 => 'nonpositive_reference_yield',
                default => 'payback',
            },
            'pass' => $g8 && ($canaryMode ? $canary : $normal),
        ];
    }

    /**
     * @param  list<array{slot: string, price: int, capital_weight: int}|array{string, int, int}>  $offers
     */
    public function slotCapitalRequirement(array $offers, int $baseCapital, int $minWorkingCapital = 200000): int
    {
        $cheapest = [];
        foreach ($offers as $offer) {
            $slot = $offer['slot'] ?? $offer[0];
            $price = $offer['price'] ?? $offer[1];
            $weight = $offer['capital_weight'] ?? $offer[2];
            $existing = $cheapest[$slot] ?? null;
            if ($existing === null || [$price + $weight, $price] < [$existing[0] + $existing[1], $existing[0]]) {
                $cheapest[$slot] = [$price, $weight];
            }
        }
        $prices = array_sum(array_column($cheapest, 0));
        $weights = array_sum(array_column($cheapest, 1));

        return $prices + max($minWorkingCapital, $baseCapital + $weights) - max($minWorkingCapital, $baseCapital);
    }

    public function shouldWaitForTrader(int $cash, int $savingRatePerHour, int $desiredCashGate, int $fallbackCashSpend, float $traderRatePerHour, float $fallbackRatePerHour, float $fallbackDeployHours, float $independentHoldHours = 0.0): bool
    {
        if ($savingRatePerHour <= 0) {
            return false;
        }
        $hold = $independentHoldHours * $savingRatePerHour;
        $shortage = $desiredCashGate - $cash;
        $wait = max(0, $hold, $shortage);
        $delayed = max(0, $hold, $shortage + $fallbackCashSpend);

        return ($delayed - $wait) * $traderRatePerHour >= max(0, $wait - $fallbackDeployHours * $savingRatePerHour) * $fallbackRatePerHour;
    }

    /**
     * @return array{permille: ?int, sample_size: int, source: ?string}
     */
    public function realizedRatioPermille(Agent $agent, ?string $market = null, ?string $good = null): array
    {
        $comparable = $pooled = [];
        $runs = AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('strategy', 'trade')->where('state', 'completed')->cursor();
        foreach ($runs as $run) {
            $plan = $run->plan;
            $symbol = $plan['symbol'] ?? '';
            $units = $plan['original_units'] ?? $plan['units'] ?? 0;
            $predicted = $plan['original_predicted_margin'] ?? $plan['campaign_trade']['predicted_margin'] ?? 0;
            $realized = $run->totals['cash_contribution'] ?? null;
            if ($units <= 0 || $units !== ($run->totals['sold'][$symbol] ?? 0) || $predicted <= 0 || $realized === null) {
                continue;
            }
            $pooled[] = $ratio = 1000 * $realized / $predicted;
            if (($market !== null || $good !== null) && ($market === null || $market === ($plan['market'] ?? null))
                && ($good === null || $good === $symbol)) {
                $comparable[] = $ratio;
            }
        }
        $sample = $comparable ?: $pooled;
        $median = FleetMetrics::median($sample);

        return [
            'permille' => $median === null ? null : min(1000, max(0, (int) floor($median))),
            'sample_size' => count($sample),
            'source' => $sample === [] ? null : ($comparable === [] ? 'pooled' : 'comparable'),
        ];
    }

    /**
     * Supply external authority, proof and pacing gates as eligible, and request admission as
     * request_fits. Probe rows supply tier (1 or 2) and their mesh eligibility separately.
     * Economic rows supply slot, type, price, purchaser, yard and evaluateCandidate inputs.
     * Cash spend may pin approved acquisition and delivery exposure. Capital is incremental to
     * the current working reserve. A trader-only timing hold is given as hold_hours.
     * Context supplies current cash or free_cash, base capital before the working-capital floor,
     * grant reserve, outstanding commitments and recon return when those apply.
     */
    public function rank(array $candidates, array $context): array
    {
        $result = ['selected' => null, 'ranked' => [], 'capital_binds' => false, 'slot_cash' => 0, 'wait_for_trader' => false, 'reason' => null,
            'declined' => [], 'g8_status' => null, 'g8_later_status' => null];
        if ($context['unresolved_g2'] ?? false) {
            return array_replace($result, ['reason' => 'unresolved_g2']);
        }

        $baseCapital = $context['base_capital'] ?? 0;
        $minCapital = $context['min_working_capital'] ?? 200000;
        $reserve = max($minCapital, $baseCapital) + ($context['grant_reserve'] ?? 0)
            + ($context['commitments'] ?? 0) + ($context['recon_return'] ?? 0);
        $cash = $context['cash'] ?? ($context['free_cash'] ?? 0) + $reserve;
        $freeCash = $context['free_cash'] ?? $cash - $reserve;
        $shadow = $context['shadow_price'] ?? 0;
        $reset = $context['hours_to_reset'] ?? 0.0;
        $reference = $context['reference_rate'] ?? null;
        $hurdle = $context['hurdle_permille'] ?? 100;
        $probeTiers = [1 => [], 2 => []];
        $economic = $canaries = $heldTraders = $possible = [];

        foreach ($candidates as $candidate) {
            $decline = match (true) {
                ! ($candidate['eligible'] ?? true) => 'ineligible',
                ! ($candidate['request_fits'] ?? true) => 'g7_requests',
                ! ($candidate['fundable'] ?? true) => 'unfundable_input',
                default => null,
            };
            if ($decline !== null) {
                $result['declined'][$decline] = ($result['declined'][$decline] ?? 0) + 1;

                continue;
            }
            $candidate['class'] = $this->hulls->spec($candidate['type'] ?? '')['class'] ?? $candidate['class'] ?? null;
            if ($candidate['class'] === 'probe') {
                $tier = $candidate['tier'] ?? null;
                if (isset($probeTiers[$tier])) {
                    $probeTiers[$tier][] = $candidate;
                }
            } elseif (isset(self::CLASS_ORDER[$candidate['class']])) {
                $possible[] = $candidate;
            }
        }
        $probeKey = fn (array $candidate) => [$candidate['effective'] ?? $candidate['price'] ?? 0, ...$this->tieKey($candidate)];
        foreach ([1, 2] as $tier) {
            usort($probeTiers[$tier], fn (array $left, array $right) => $probeKey($left) <=> $probeKey($right));
        }
        ksort($result['declined']);
        if ($probeTiers[1] !== []) {
            return array_replace($result, ['selected' => $probeTiers[1][0], 'ranked' => $probeTiers[1], 'reason' => 'tier_1_probe']);
        }

        foreach ($possible as $candidate) {
            $type = $candidate['type'] ?? '';
            $horizon = $candidate['horizon_after_delivery_hours'] ?? $context['horizon_after_delivery_hours'] ?? 0.0;
            $candidate['capital_weight'] ??= $this->hulls->capitalWeight($type, $context['working_capital_per_trader'] ?? 150000);
            $candidate['cash_spend'] ??= $candidate['approved_exposure'] ?? $candidate['effective_cost'];
            $candidate['cash_required'] = $candidate['cash_spend']
                + max($minCapital, $baseCapital + $candidate['capital_weight']) - max($minCapital, $baseCapital);
            $candidate['cash_gate'] = $cash - $freeCash + $candidate['cash_required'];
            $candidate['horizon_after_delivery_hours'] = $horizon;
            $canaryMode = $candidate['canary'] ?? false;
            $score = $this->evaluateCandidate($candidate, $shadow, $reset, $horizon, $reference, $hurdle, $canaryMode);
            $result['g8_status'] = $this->aggregateG8($result['g8_status'], $score);
            if (! $score['pass']) {
                $decline = $score['g8'] ? 'score_other' : 'g8';
                $result['declined'][$decline] = ($result['declined'][$decline] ?? 0) + 1;

                continue;
            }
            $candidate['score'] = $score;
            $holdHours = $candidate['hold_hours'] ?? 0.0;
            $fundable = $candidate['cash_required'] <= $freeCash;
            if ($canaryMode) {
                $class = $candidate['class'];
                if (($context['phase'] ?? 1) >= 2 && $freeCash >= 1000000 && $fundable && $holdHours <= 0
                    && in_array($class, ['miner', 'siphon'], true) && ! in_array($class, $context['canaries_used'] ?? [], true)) {
                    $canaries[] = $candidate;
                } else {
                    $result['declined']['canary_ineligible'] = ($result['declined']['canary_ineligible'] ?? 0) + 1;
                }
            } elseif ($holdHours <= 0) {
                $economic[] = $candidate;
            } else {
                $result['declined']['held'] = ($result['declined']['held'] ?? 0) + 1;
            }
            if ($candidate['class'] === 'trader' && ! $canaryMode && (! $fundable || $holdHours > 0)) {
                $heldTraders[] = $candidate;
            }
        }

        $offers = array_map(fn (array $candidate) => [$candidate['slot'], $candidate['cash_spend'], $candidate['capital_weight']], $economic);
        $slotCash = $this->slotCapitalRequirement($offers, $baseCapital, $minCapital);
        $capitalBinds = $offers !== [] && $slotCash > $freeCash;
        $fundable = array_values(array_filter($economic, fn (array $candidate) => $candidate['cash_required'] <= $freeCash));
        if (($unfundable = count($economic) - count($fundable)) > 0) {
            $result['declined']['unfundable'] = $unfundable;
        }
        $economic = $fundable;
        ksort($result['declined']);
        $scoreKey = fn (array $candidate) => [...($capitalBinds ? [-$candidate['score']['rho']] : []), -$candidate['score']['nv'], ...$this->tieKey($candidate)];
        usort($economic, fn (array $left, array $right) => $scoreKey($left) <=> $scoreKey($right));
        $canaryKey = fn (array $candidate) => [$candidate['class'] === 'miner' ? 0 : 1, -$candidate['score']['nv'], ...$this->tieKey($candidate)];
        usort($canaries, fn (array $left, array $right) => $canaryKey($left) <=> $canaryKey($right));
        $ranked = [...$canaries, ...$economic, ...$probeTiers[2]];
        $selected = $ranked[0] ?? null;

        $result = array_replace($result, ['selected' => $selected, 'ranked' => $ranked, 'capital_binds' => $capitalBinds, 'slot_cash' => $slotCash]);
        if (($context['first_trader'] ?? ($context['trader_count'] ?? 1) <= 1)
            && ($selected === null || (isset($selected['score']) && $selected['class'] !== 'trader'))) {
            $savingRate = $context['saving_rate_per_hour'] ?? 0;
            foreach ($heldTraders as $trader) {
                if ($savingRate <= 0 || ($selected !== null && $trader['score']['nv'] <= $selected['score']['nv'])) {
                    continue;
                }
                $wait = max($trader['hold_hours'] ?? 0.0, max(0, $trader['cash_gate'] - $cash) / $savingRate);
                $later = $this->evaluateCandidate($trader, $shadow, $reset - $wait, $trader['horizon_after_delivery_hours'] - $wait, $reference, $hurdle);
                $result['g8_later_status'] = $this->aggregateG8($result['g8_later_status'], $later);
                if ($later['pass'] && ($selected === null || $this->shouldWaitForTrader($cash, $savingRate, $trader['cash_gate'],
                    $selected['cash_required'], $trader['score']['g'], $selected['score']['g'],
                    $selected['deploy_hours'] ?? 0.0, $trader['hold_hours'] ?? 0.0))) {
                    return array_replace($result, ['selected' => null, 'wait_for_trader' => true, 'reason' => 'wait_for_trader']);
                }
            }
        }

        return $result;
    }

    private function aggregateG8(?string $current, array $score): ?string
    {
        if (! $score['g8_applicable']) {
            return $current;
        }
        $observed = $score['g8'] ? 'pass' : $score['g8_reason'];
        foreach (['pass', 'time_to_reset', 'nonpositive_reference_yield', 'payback'] as $status) {
            if ($current === $status || $observed === $status) {
                return $status;
            }
        }

        return $current;
    }

    private function tieKey(array $candidate): array
    {
        return [self::CLASS_ORDER[$candidate['class']] ?? PHP_INT_MAX, $candidate['price'] ?? 0,
            $candidate['yard'] ?? '', $candidate['purchaser'] ?? $candidate['ship']['symbol'] ?? ''];
    }
}
