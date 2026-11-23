<?php

namespace App\Gameplay\Bridge;

use App\Models\CampaignSample;
use Illuminate\Database\Eloquent\Builder;

final class BridgeCredits
{
    private const MAX_POINTS = 240;

    private const GAP_SECONDS = 45 * 60;

    public static function read(BridgeScope $scope): array
    {
        $now = now();
        $baseline = $scope->episodes->first();
        $credits = $scope->agent?->credits;
        $summary = null;
        if ($baseline) {
            $samples = self::samples($scope);
            $hourSample = (clone $samples)
                ->whereBetween('observed_at', [$now->copy()->subMinutes(75), $now->copy()->subHour()])
                ->orderByDesc('observed_at')->orderByDesc('id')->select('credits')->limit(1);
            $summary = $samples->selectRaw('MAX(id) as latest_sample_id')->selectSub($hourSample, 'hour_credits')->first();
        }
        $hourCredits = $summary?->hour_credits;
        $latestSampleId = $summary?->latest_sample_id;

        return [
            'credits' => $credits,
            'wipe_delta' => $baseline && $credits !== null ? $credits - $baseline->start_credits : null,
            'hour_delta' => $hourCredits !== null && $credits !== null ? $credits - (int) $hourCredits : null,
            'day' => $baseline ? (int) floor($baseline->opened_at->diffInSeconds($now) / 86400) + 1 : null,
            'days' => $baseline ? (int) ceil($baseline->opened_at->diffInSeconds($baseline->next_reset_at) / 86400) : null,
            'data_as_of' => $scope->agent?->synced_at?->toIso8601String(),
            'latest_sample_id' => $latestSampleId === null ? null : (int) $latestSampleId,
        ];
    }

    public static function chart(BridgeScope $scope): array
    {
        if ($scope->episodes->isEmpty()) {
            return [];
        }

        $points = [];
        foreach (self::samples($scope)->orderBy('observed_at')->orderBy('id')->get(['id', 'observed_at', 'credits']) as $sample) {
            $at = $sample->observed_at;
            $points[$at->getTimestamp()] = ['at' => $at->toIso8601String(), 'credits' => $sample->credits];
        }

        $segments = [];
        $previous = null;
        foreach ($points as $time => $point) {
            if ($previous === null || $time - $previous > self::GAP_SECONDS) {
                $segments[] = [];
            }
            $segments[array_key_last($segments)][] = $point + ['time' => $time];
            $previous = $time;
        }

        if ($segments === []) {
            return [];
        }

        return array_map(fn (array $segment) => array_map(fn (array $point) => [
            'at' => $point['at'],
            'credits' => $point['credits'],
        ], $segment), self::downsample($segments));
    }

    private static function samples(BridgeScope $scope): Builder
    {
        return CampaignSample::whereIn('campaign_episode_id', $scope->episodes->keys());
    }

    private static function downsample(array $segments): array
    {
        $edges = array_map(fn (array $segment) => min(2, count($segment)), $segments);
        $edgeCount = array_sum($edges);
        // When edges alone exceed the budget, keep the first segment and as many recent gaps as fit.
        for ($index = 1; $edgeCount > self::MAX_POINTS; $index++) {
            $edgeCount -= $edges[$index];
            unset($segments[$index], $edges[$index]);
        }

        $interiors = array_sum(array_map('count', $segments)) - $edgeCount;
        $slots = min($interiors, self::MAX_POINTS - $edgeCount);
        $interiorsSeen = 0;
        $slotsAssigned = 0;
        $result = [];
        foreach ($segments as $index => $segment) {
            $interiorsSeen += count($segment) - $edges[$index];
            $allocation = $interiors > 0 ? intdiv($interiorsSeen * $slots, $interiors) : 0;
            $result[] = self::largestTriangles($segment, $edges[$index] + $allocation - $slotsAssigned);
            $slotsAssigned = $allocation;
        }

        return $result;
    }

    private static function largestTriangles(array $points, int $limit): array
    {
        $count = count($points);
        if ($count <= $limit) {
            return $points;
        }
        if ($limit === 2) {
            return [$points[0], $points[$count - 1]];
        }

        $sampled = [$points[0]];
        $anchor = $points[0];
        $interiors = $count - 2;
        $buckets = $limit - 2;
        for ($bucket = 0; $bucket < $buckets; $bucket++) {
            $start = intdiv($bucket * $interiors, $buckets) + 1;
            $end = intdiv(($bucket + 1) * $interiors, $buckets) + 1;
            $averageEnd = min(intdiv(($bucket + 2) * $interiors, $buckets) + 1, $count);
            $averageTime = 0;
            $averageCredits = 0;
            for ($index = $end; $index < $averageEnd; $index++) {
                $averageTime += $points[$index]['time'];
                $averageCredits += $points[$index]['credits'];
            }
            $averageTime /= $averageEnd - $end;
            $averageCredits /= $averageEnd - $end;

            $largestArea = -1;
            $chosen = $points[$start];
            for ($index = $start; $index < $end; $index++) {
                $point = $points[$index];
                $area = abs(($anchor['time'] - $averageTime) * ($point['credits'] - $anchor['credits'])
                    - ($anchor['time'] - $point['time']) * ($averageCredits - $anchor['credits']));
                if ($area > $largestArea) {
                    $largestArea = $area;
                    $chosen = $point;
                }
            }
            $sampled[] = $chosen;
            $anchor = $chosen;
        }
        $sampled[] = $points[$count - 1];

        return $sampled;
    }
}
