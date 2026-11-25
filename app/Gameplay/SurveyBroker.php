<?php

declare(strict_types=1);

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\GameObservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SurveyBroker
{
    private const SIZE_RANK = ['LARGE' => 3, 'MODERATE' => 2, 'SMALL' => 1];

    public function __construct(private SurveyPool $pool) {}

    public function evaluateSurvey(array $signedSurvey, array $netBidsByGood, int $yieldMultiplierPermille = 1000): int
    {
        $deposits = array_column($signedSurvey['deposits'] ?? [], 'symbol');
        if ($deposits === []) {
            return 0;
        }

        $total = array_sum(array_map(fn (string $symbol): int => (int) ($netBidsByGood[$symbol] ?? 0), $deposits));

        return intdiv($yieldMultiplierPermille * $total, 1000 * count($deposits));
    }

    /** @return list<array{survey: array, ev: int}> */
    public function rankSurveys(array $surveys, array $netBidsByGood, int $unsurveyedEv, int $marginPermille = 50, int $yieldMultiplierPermille = 1000): array
    {
        $ranked = [];
        foreach ($surveys as $survey) {
            $ev = $this->evaluateSurvey($survey, $netBidsByGood, $yieldMultiplierPermille);
            if (empty($survey['deposits']) || $ev * 1000 < $unsurveyedEv * (1000 + $marginPermille)) {
                continue;
            }
            $ranked[] = ['survey' => $survey, 'ev' => $ev];
        }

        $sortKey = fn (array $entry): array => [
            -$entry['ev'],
            -(self::SIZE_RANK[$entry['survey']['size']] ?? 0),
            CarbonImmutable::parse($entry['survey']['expiration']),
        ];
        usort($ranked, fn (array $a, array $b): int => ($sortKey($a) <=> $sortKey($b))
            ?: strcmp($a['survey']['signature'], $b['survey']['signature']));

        return $ranked;
    }

    /** Return the original signed payload, with the lease held separately. */
    public function leaseBest(Agent $agent, string $waypoint, string $shipSymbol, array $netBidsByGood, int $unsurveyedEv, CarbonImmutable $cooldownReadyAt, int $marginPermille = 50, int $yieldMultiplierPermille = 1000): ?array
    {
        return DB::transaction(function () use ($agent, $waypoint, $shipSymbol, $netBidsByGood, $unsurveyedEv, $cooldownReadyAt, $marginPermille, $yieldMultiplierPermille) {
            Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now();
            $readyAt = $cooldownReadyAt->max($now);
            $surveys = [];
            foreach (GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                ->where('kind', 'survey')->where('data->signed->symbol', $waypoint)->pluck('symbol') as $signature) {
                try {
                    $survey = $this->pool->usable($agent, $signature, $waypoint);
                } catch (GameException) {
                    continue;
                }
                if (CarbonImmutable::parse($survey['expiration'])->gt($readyAt)) {
                    $surveys[] = $survey;
                }
            }

            foreach ($this->rankSurveys($surveys, $netBidsByGood, $unsurveyedEv, $marginPermille, $yieldMultiplierPermille) as $entry) {
                $survey = $entry['survey'];
                $key = $this->leaseKey($agent, $survey['signature']);
                $lease = json_decode(DB::table('game_runtime')->where('key', $key)->value('value') ?? 'null', true, flags: JSON_THROW_ON_ERROR);
                if ($lease && $lease['ship_symbol'] !== $shipSymbol && $lease['expires_at'] > $now->getTimestamp()) {
                    continue;
                }

                DB::table('game_runtime')->upsert([[
                    'key' => $key,
                    'value' => json_encode(['ship_symbol' => $shipSymbol, 'expires_at' => $readyAt->addSeconds(30)->getTimestamp()], JSON_THROW_ON_ERROR),
                ]], ['key'], ['value']);

                return $survey;
            }

            return null;
        }, 3);
    }

    public function invalidateSignature(Agent $agent, string $signature, bool $exhausted = true): void
    {
        DB::transaction(function () use ($agent, $signature, $exhausted) {
            Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            $this->pool->invalidate($agent, $signature, $exhausted);
            DB::table('game_runtime')->where('key', $this->leaseKey($agent, $signature))->delete();
        }, 3);
    }

    public function creationDemand(int $activeMiners, int $cooldownSeconds, int $remainingCompatibleUses, int $usableAttemptsPerSurvey = 3, int $surveysPerAction = 1): array
    {
        if ($activeMiners < 0 || $remainingCompatibleUses < 0 || min($cooldownSeconds, $usableAttemptsPerSurvey, $surveysPerAction) < 1) {
            throw new InvalidArgumentException('Survey demand requires positive rates and nonnegative counts.');
        }

        $target = $this->ceilDiv($activeMiners * 600, $cooldownSeconds);
        $shortfall = max(0, $target - $remainingCompatibleUses);
        $surveys = $this->ceilDiv($shortfall, $usableAttemptsPerSurvey);

        return [
            'target' => $target,
            'shortfall' => $shortfall,
            'surveys_needed' => $surveys,
            'writes_needed' => $this->ceilDiv($surveys, $surveysPerAction),
        ];
    }

    public function purchaseUplift(int $activeMiners, float $surveyUnitsPerHour, float $surveyMeanNetBid, float $baseUnitsPerHour, float $baseMeanNetBid, float $surveyAvailableFraction, int $siteReceipts, int $matchedSurveyed, int $matchedUnsurveyed): array
    {
        if ($activeMiners < 0 || min($surveyUnitsPerHour, $baseUnitsPerHour, $surveyAvailableFraction) < 0 || $surveyAvailableFraction > 1) {
            throw new InvalidArgumentException('Survey uplift requires nonnegative throughput and a fraction between zero and one.');
        }

        $uplift = $activeMiners * $surveyAvailableFraction * ($surveyUnitsPerHour * $surveyMeanNetBid - $baseUnitsPerHour * $baseMeanNetBid);
        $evidenceReady = $siteReceipts >= 20 && $matchedSurveyed >= 10 && $matchedUnsurveyed >= 10;
        $reason = match (true) {
            ! $evidenceReady => 'insufficient_survey_receipts',
            $uplift <= 0 => 'nonpositive_survey_uplift',
            default => null,
        };

        return ['purchase_allowed' => $reason === null, 'reason' => $reason, 'gross_uplift_per_hour' => $uplift];
    }

    private function leaseKey(Agent $agent, string $signature): string
    {
        return 'survey_lease:'.$agent->id.':'.hash('sha256', json_encode([$agent->reset_id, $signature], JSON_THROW_ON_ERROR));
    }

    private function ceilDiv(int $numerator, int $denominator): int
    {
        return intdiv($numerator, $denominator) + (int) ($numerator % $denominator !== 0);
    }
}
