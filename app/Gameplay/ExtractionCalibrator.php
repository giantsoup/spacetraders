<?php

declare(strict_types=1);

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\GameObservation;
use App\Models\ObservationHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ExtractionCalibrator
{
    private const KINDS = ['mine', 'siphon', 'survey', 'transfer', 'second_laser'];

    private const SAMPLE_LIMIT = 200;

    public function recordReceipt(Agent $agent, string $kind, string $waypoint, string $configHash, array $sample): array
    {
        $sample = $this->normalizeSample($sample);

        return DB::transaction(function () use ($agent, $kind, $waypoint, $configHash, $sample) {
            Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            $query = $this->calibration($agent, $kind, $waypoint, $configHash);
            $row = $query->first();
            $samples = $row ? json_decode($row->samples, true, flags: JSON_THROW_ON_ERROR) : [];
            if (isset($sample['action_id']) && in_array($sample['action_id'], array_column($samples, 'action_id'), true)) {
                return json_decode($row->summary, true, flags: JSON_THROW_ON_ERROR);
            }

            $samples[] = $sample;
            $samples = array_slice($samples, -self::SAMPLE_LIMIT);
            $summary = $this->summarize($kind, $samples);
            $values = [
                'receipt_count' => $summary['receipt_count'],
                'samples' => json_encode($samples, JSON_THROW_ON_ERROR),
                'summary' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                'updated_at' => now(),
            ];

            if ($row) {
                $query->update($values);
            } else {
                DB::table('extraction_calibrations')->insert($values + [
                    'agent_id' => $agent->id, 'reset_id' => $agent->reset_id,
                    'kind' => $kind, 'waypoint' => $waypoint, 'config_hash' => $configHash,
                ]);
            }

            return $summary;
        }, 3);
    }

    public function summary(Agent $agent, string $kind, string $waypoint, string $configHash): array
    {
        $stored = $this->calibration($agent, $kind, $waypoint, $configHash)->value('summary');

        return $stored === null ? $this->summarize($kind, []) : json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
    }

    public function isCalibratedForScaling(Agent $agent, string $kind, string $waypoint, string $configHash, int $minReceipts = 20): bool
    {
        return $this->summary($agent, $kind, $waypoint, $configHash)['receipt_count'] >= $minReceipts;
    }

    public function gasBidGate(Agent $agent, string $market, array $bookedBidsByGas, ?CarbonImmutable $now = null, int $maxLatestAgeSeconds = 180, int $minRatioPermille = 850): array
    {
        $now ??= CarbonImmutable::now();
        $cutoff = $now->subDay();
        $scope = fn (string $model) => $model::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', 'market')->where('symbol', $market)->whereBetween('observed_at', [$cutoff, $now]);
        $polls = DB::transaction(function () use ($scope, $cutoff, $now) {
            $polls = [];
            foreach ($scope(ObservationHistory::class)->orderBy('observed_at')->orderBy('id')->cursor() as $row) {
                $this->addGasPoll($polls, $row, $cutoff, $now);
            }
            if ($latest = $scope(GameObservation::class)->first()) {
                $this->addGasPoll($polls, $latest, $cutoff, $now);
            }

            return $polls;
        });
        ksort($polls, SORT_NUMERIC);

        $series = [];
        $previous = null;
        foreach ($polls as $timestamp => $poll) {
            // Legacy saves of the two-second in-scope GET cache lack a provider timestamp.
            if ($poll['implicit_time'] && $previous && $timestamp - $previous['timestamp'] <= 2
                && $poll['fingerprint'] === $previous['fingerprint']) {
                if ($poll['invalidated']) {
                    $previous['invalidated'] = true;
                    $polls[$previous['timestamp']]['invalidated'] = true;
                }

                unset($polls[$timestamp]);

                continue;
            }
            $previous = $poll + ['timestamp' => $timestamp];
        }

        $latestPoll = null;
        foreach ($polls as $timestamp => $poll) {
            $latestPoll = $poll + ['timestamp' => $timestamp];
            foreach ($poll['bids'] as $symbol => $bid) {
                if (! $poll['invalidated']) {
                    $series[$symbol][] = $bid;
                }
            }
        }

        $medians = [];
        $counts = [];
        $reason = null;
        foreach (ExtractionSites::GAS_GIANT_YIELDS as $gas) {
            $bids = $series[$gas] ?? [];
            $counts[$gas] = count($bids);
            $median = $bids === [] ? null : (int) floor($this->median($bids));
            $medians[$gas] = $median;
            $reason ??= match (true) {
                $counts[$gas] < 3 => 'insufficient_gas_polls',
                ! $latestPoll || $latestPoll['invalidated'] || ($latestPoll['bids'][$gas] ?? 0) <= 0
                    || $latestPoll['timestamp'] < $now->getTimestamp() - $maxLatestAgeSeconds => 'gas_bid_stale',
                ! isset($bookedBidsByGas[$gas]) || $minRatioPermille * $median > $bookedBidsByGas[$gas] * 1000 => 'gas_bid_below_median_floor',
                default => null,
            };
        }

        return ['scaling_allowed' => $reason === null, 'reason' => $reason, 'medians' => $medians, 'poll_counts' => $counts];
    }

    private function addGasPoll(array &$polls, GameObservation|ObservationHistory $row, CarbonImmutable $cutoff, CarbonImmutable $now): void
    {
        $data = $row->data;
        if (empty($data['tradeGoods']) || ! empty($data['replayed']) || ! empty($data['cached']) || ! empty($data['inferred'])
            || in_array($row->source, ['cache', 'replay', 'cached', 'inferred', 'receipt', 'listing'], true)) {
            return;
        }

        $providerAt = $data['provider_read_at'] ?? $data['provider_observed_at'] ?? null;
        $at = $providerAt === null ? $row->observed_at : CarbonImmutable::make($providerAt);
        if (! $at || ! $at->betweenIncluded($cutoff, $now) || $at->gt($row->observed_at)) {
            return;
        }
        $bids = array_intersect_key(array_column($data['tradeGoods'], 'sellPrice', 'symbol'), array_flip(ExtractionSites::GAS_GIANT_YIELDS));
        $bids = array_map('intval', $bids);
        ksort($bids);
        $timestamp = $at->getTimestamp();
        $invalidated = ! empty($data['invalidated_by_action']) || ! empty($data['invalidated']);

        // The current snapshot can mark the same poll invalid after its history copy was saved.
        $polls[$timestamp] = [
            'bids' => $bids,
            'fingerprint' => hash('sha256', json_encode($data['tradeGoods'], JSON_THROW_ON_ERROR)),
            'implicit_time' => $providerAt === null,
            'invalidated' => $invalidated || ($polls[$timestamp]['invalidated'] ?? false),
        ];
    }

    private function calibration(Agent $agent, string $kind, string $waypoint, string $configHash): Builder
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Unknown extraction calibration kind.');
        }

        return DB::table('extraction_calibrations')->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', $kind)->where('waypoint', $waypoint)->where('config_hash', $configHash);
    }

    private function normalizeSample(array $sample): array
    {
        $yield = $sample['extraction']['yield'] ?? $sample['siphon']['yield'] ?? [];
        $cooldown = $sample['cooldown'] ?? null;

        return array_filter([
            'action_id' => isset($sample['action_id']) || isset($sample['ship_action_id']) ? (int) ($sample['action_id'] ?? $sample['ship_action_id']) : null,
            'yield_units' => $sample['yield_units'] ?? $sample['units'] ?? $yield['units'] ?? null,
            'headroom_units' => $sample['headroom_units'] ?? null,
            'headroom_capped' => $sample['headroom_capped'] ?? $sample['capped'] ?? false,
            'cooldown_seconds' => $sample['cooldown_seconds'] ?? (is_array($cooldown) ? ($cooldown['totalSeconds'] ?? $cooldown['remainingSeconds'] ?? null) : $cooldown),
            'symbol' => $sample['symbol'] ?? $yield['symbol'] ?? null,
            'possible_yields' => $sample['possible_yields'] ?? [],
            'survey_yield_multiplier_permille' => $sample['survey_yield_multiplier_permille'] ?? null,
            'surveyed_yield_units' => $sample['surveyed_yield_units'] ?? null,
            'unsurveyed_yield_units' => $sample['unsurveyed_yield_units'] ?? null,
            'usable_attempts' => $sample['survey_usable_attempts'] ?? $sample['usable_attempts'] ?? null,
            'transfer_cooldown_measured' => $sample['transfer_cooldown_measured'] ?? (isset($sample['source_cooldown_seconds'], $sample['receiver_cooldown_seconds'])),
            'transfer_dwell_seconds' => $sample['transfer_dwell_seconds'] ?? $sample['dwell_seconds'] ?? null,
            'second_laser_ratio' => $sample['second_laser_ratio'] ?? null,
            'single_laser_yield_units' => $sample['single_laser_yield_units'] ?? null,
            'second_laser_yield_units' => $sample['second_laser_yield_units'] ?? null,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function summarize(string $kind, array $samples): array
    {
        $count = count($samples);
        $yields = $this->values($samples, 'yield_units');
        $uncapped = array_filter($samples, fn (array $sample): bool => ! $sample['headroom_capped']);
        $headrooms = $this->values(array_map(
            fn (array $sample): array => ['headroom' => $sample['headroom_units'] ?? $sample['yield_units'] ?? null],
            $uncapped,
        ), 'headroom');
        $cooldowns = $this->values($samples, 'cooldown_seconds');
        $attempts = $this->values($samples, 'usable_attempts');
        $dwell = $this->values($samples, 'transfer_dwell_seconds');
        $symbols = array_count_values(array_filter(array_column($samples, 'symbol'), 'is_string'));
        $support = array_unique(array_merge(...array_column($samples, 'possible_yields')));
        $support = $support === [] && $kind === 'siphon' ? ExtractionSites::GAS_GIANT_YIELDS : $support;
        $mix = array_fill_keys($support, 0);
        foreach ($symbols as $symbol => $frequency) {
            $mix[$symbol] = $frequency;
        }
        $denominator = array_sum($mix) + count($mix);
        ksort($mix);

        return [
            'receipt_count' => $count,
            'yield_mean' => $yields === [] ? 5.0 : array_sum($yields) / count($yields),
            'yield_lcb_90' => $this->yieldLowerBound($yields),
            'headroom_p95' => max(count($headrooms) < 20 ? 7 : 1, (int) ceil($this->percentile($headrooms, 0.95))),
            'cooldown_median' => $cooldowns === [] ? 80 : (int) ceil($this->median($cooldowns)),
            'cooldown_lcb' => $cooldowns === [] ? 60 : (int) floor(min(count($cooldowns) < 20 ? 60 : INF, min($cooldowns))),
            'symbol_mix' => array_map(fn (int $frequency): float => ($frequency + 1) / $denominator, $mix),
            'survey_yield_multiplier_permille' => (int) floor($this->ratio($samples, 'survey_yield_multiplier_permille', 'surveyed_yield_units', 'unsurveyed_yield_units', 1000) ?? 1000),
            'survey_usable_attempts' => $attempts === [] ? 3 : (int) floor(array_sum($attempts) / count($attempts)),
            'transfer_cooldown_measured' => in_array(true, array_column($samples, 'transfer_cooldown_measured'), true),
            'transfer_dwell_seconds' => $dwell === [] ? null : (int) ceil($this->median($dwell)),
            'second_laser_ratio' => $this->ratio($samples, 'second_laser_ratio', 'second_laser_yield_units', 'single_laser_yield_units', 1),
        ];
    }

    private function values(array $samples, string $key): array
    {
        return array_map('floatval', array_values(array_filter(array_column($samples, $key), fn (mixed $value): bool => is_numeric($value) && is_finite((float) $value) && $value >= 0)));
    }

    private function ratio(array $samples, string $key, string $numerator, string $denominator, int $scale): ?float
    {
        $ratios = $this->values($samples, $key);
        foreach ($samples as $sample) {
            if (! isset($sample[$key]) && isset($sample[$numerator], $sample[$denominator]) && $sample[$denominator] > 0 && $sample[$numerator] >= 0) {
                $ratios[] = $scale * $sample[$numerator] / $sample[$denominator];
            }
        }

        return $ratios === [] ? null : array_sum($ratios) / count($ratios);
    }

    private function yieldLowerBound(array $yields): float
    {
        $n = count($yields);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($yields) / $n;
        $sumSquares = array_sum(array_map(fn (float $yield): float => ($yield - $mean) ** 2, $yields));
        // A conservative one-sided 90% Student t critical value for each sample-size band.
        $critical = match (true) {
            $n >= 31 => 1.311,
            $n >= 21 => 1.325,
            $n >= 11 => 1.373,
            $n >= 6 => 1.476,
            $n >= 4 => 1.638,
            $n === 3 => 1.886,
            default => 3.078,
        };

        return max(0.0, $mean - $critical * sqrt($sumSquares / ($n * ($n - 1))));
    }

    private function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 0 ? ($values[$middle - 1] + $values[$middle]) / 2 : $values[$middle];
    }

    private function percentile(array $values, float $p): float
    {
        sort($values, SORT_NUMERIC);

        return $values[(int) ceil(count($values) * $p) - 1] ?? 0.0;
    }
}
