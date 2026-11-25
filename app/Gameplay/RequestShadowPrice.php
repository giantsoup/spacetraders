<?php

declare(strict_types=1);

namespace App\Gameplay;

use App\Models\Agent;
use Illuminate\Support\Facades\DB;

final class RequestShadowPrice
{
    public const int T0 = 0;

    public const int T1 = 1;

    public const int T2 = 2;

    public const int T3 = 3;

    public const int T4 = 4;

    public const int T5 = 5;

    public const int T6 = 6;

    public function utilizationPermille(int $actualLastHour, int $remainingAdmitted, int $proposedUnreserved, int $hardLimit): int
    {
        return intdiv(1000 * ($actualLastHour + $remainingAdmitted + $proposedUnreserved), max(1, $hardLimit));
    }

    public function ordinaryCap(int $hardLimit, int $highPermille = 900): int
    {
        return intdiv($highPermille * $hardLimit, 1000);
    }

    /**
     * @param  list<array{gross: int, requests: int}|array{int, int}>  $eligibleCandidates
     * @return array{lambda_clear: int, residual_excess: int}
     */
    public function clearingPrice(array $eligibleCandidates, int $excessRequests): array
    {
        $breakpoints = [];
        foreach ($eligibleCandidates as $candidate) {
            $gross = $candidate['gross'] ?? $candidate[0];
            $requests = $candidate['requests'] ?? $candidate[1];
            if ($gross <= 0 || $requests <= 0) {
                continue;
            }
            $price = intdiv($gross, $requests) + (int) ($gross % $requests !== 0);
            $breakpoints[$price] = ($breakpoints[$price] ?? 0) + $requests;
        }
        ksort($breakpoints, SORT_NUMERIC);
        $price = 0;
        $remaining = max(0, $excessRequests);
        foreach ($breakpoints as $price => $removable) {
            $remaining = max(0, $remaining - $removable);
            if ($remaining === 0) {
                break;
            }
        }

        return ['lambda_clear' => $price, 'residual_excess' => $remaining];
    }

    public function stepPrice(int $previousPrice, int $utilizationPermille, int $lambdaClear, int $lowPermille = 600, int $highPermille = 900, int $maxStep = 5): array
    {
        $span = max(1, $highPermille - $lowPermille);
        $target = intdiv($lambdaClear * min($span, max(0, $utilizationPermille - $lowPermille)), $span);
        $price = $previousPrice + min($maxStep, max(-$maxStep, $target - $previousPrice));

        return compact('price', 'target');
    }

    /**
     * Call per discretionary class. Supply positive_charged_score when the class no longer clears its value gate.
     */
    public function updateHysteresis(array $state, int $utilizationPermille, int $prospectiveResumeUtilizationPermille, int $highPermille = 900, int $resumePermille = 850, int $resumePasses = 3): array
    {
        $paused = ($state['paused'] ?? false) || $utilizationPermille >= $highPermille;
        $lowPasses = 0;
        if ($paused && $prospectiveResumeUtilizationPermille <= $resumePermille && ($state['positive_charged_score'] ?? true)) {
            $lowPasses = ($state['low_passes'] ?? 0) + 1;
            if ($lowPasses >= $resumePasses) {
                $paused = false;
                $lowPasses = 0;
            }
        }

        return array_replace($state, ['paused' => $paused, 'low_passes' => $lowPasses]);
    }

    /**
     * Order proven-unspent discretionary work by tier, extraction role, CR/request and stable key.
     * Candidates supply tier, key and either cr_per_request or gross and requests.
     */
    public function sheddingOrder(array $candidates): array
    {
        $candidates = array_values(array_filter($candidates, fn (array $candidate) => in_array($candidate['tier'], [self::T6, self::T5, self::T4, self::T3], true)
            && ($candidate['eligible'] ?? true) && ! ($candidate['protected'] ?? false)
            && ! ($candidate['started'] ?? false) && ! ($candidate['spent'] ?? false)
            && ($candidate['tier'] !== self::T5 || ($candidate['elective'] ?? true))));

        $key = static function (array $candidate): array {
            $extractionOrder = $candidate['tier'] === self::T4 ? match ($candidate['class'] ?? null) {
                'siphon' => 0,
                'surveyor' => 1,
                default => 2,
            } : 0;

            return [-$candidate['tier'], $extractionOrder, $candidate['cr_per_request']
                ?? ($candidate['gross'] ?? 0) / max(1, $candidate['requests'] ?? 0), $candidate['key']];
        };
        usort($candidates, fn (array $left, array $right) => $key($left) <=> $key($right));

        return $candidates;
    }

    public function recalibrationRequired(int $observedP90, int $allowance, bool $stage1J59Unavailable = false): bool
    {
        return abs($observedP90 - $allowance) * 5 > $allowance
            || ($stage1J59Unavailable && $observedP90 > $allowance);
    }

    public function loadState(Agent $agent): array
    {
        $raw = DB::table('game_runtime')->where('key', $this->stateKey($agent))->value('value');
        $state = $raw === null ? null : json_decode($raw, true);

        return is_array($state) ? $state : [];
    }

    public function saveState(Agent $agent, array $state): void
    {
        DB::table('game_runtime')->upsert([
            ['key' => $this->stateKey($agent), 'value' => json_encode($state, JSON_THROW_ON_ERROR)],
        ], ['key'], ['value']);
    }

    private function stateKey(Agent $agent): string
    {
        return 'mesh_plan:'.$agent->id.':'.$agent->reset_id.':request_shadow_price';
    }
}
