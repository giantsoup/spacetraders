<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignSample;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CampaignReport
{
    public function build(CampaignEpisode $episode): array
    {
        $grant = $episode->grant();
        $samples = CampaignSample::where('campaign_episode_id', $episode->id)->select(['id', 'credits', 'phase', 'observed_at', 'request_budget'])->lazyById(250)->collect()->sortBy('observed_at');
        $end = $episode->closed_at ?? now()->toImmutable();
        $duration = max(0, (int) $episode->opened_at->diffInSeconds($end));
        $runs = AutomationRun::where('campaign_episode_id', $episode->id)->select(['id', 'strategy', 'state', 'totals', 'started_at', 'updated_at', 'active_agent_id', 'blocked_reason'])->lazyById(250)->map(function ($run) use ($end) {
            $finished = $run->active_agent_id ? $end : (isset($run->totals['stopped_at']) ? CarbonImmutable::parse($run->totals['stopped_at']) : $run->updated_at->toImmutable());
            $seconds = $run->started_at ? max(0, (int) $run->started_at->diffInSeconds(min($end, $finished))) : 0;
            $net = (int) DB::table('confirmed_credit_entries')->where('automation_run_id', $run->id)->sum('credits');

            return ['id' => $run->id, 'strategy' => $run->strategy, 'state' => $run->state->value, 'net_credits' => $net, 'ship_seconds' => $seconds,
                'credits_per_ship_hour' => $seconds > 0 ? round($net * 3600 / $seconds, 2) : null, 'cycles' => $run->totals['cycles'] ?? 0,
                'unsold' => $run->totals['unsold'] ?? null, 'reason' => $run->blocked_reason];
        });
        $runs = $runs->collect();
        $strategies = $runs->groupBy('strategy')->map(function ($rows) {
            $seconds = $rows->sum('ship_seconds');
            $net = $rows->sum('net_credits');

            return ['runs' => $rows->count(), 'net_credits' => $net, 'ship_seconds' => $seconds, 'credits_per_ship_hour' => $seconds > 0 ? round($net * 3600 / $seconds, 2) : null];
        })->all();
        $events = CampaignEvent::where('campaign_grant_id', $grant->id)->whereIn('kind', ['pause', 'freshness_hold'])->where(function ($query) use ($episode, $end) {
            $query->where('campaign_episode_id', $episode->id)->orWhere(fn ($query) => $query->whereNull('campaign_episode_id')->whereBetween('created_at', [$episode->opened_at, $end]));
        })->select(['id', 'kind', 'data', 'created_at'])->lazyById(250)->collect();
        $allResults = app(CampaignTrade::class)->results($episode);
        $recoveries = array_values(array_filter($allResults, fn ($result) => ! empty($result['standalone_recovery'])));
        $results = array_values(array_filter($allResults, fn ($result) => empty($result['standalone_recovery'])));
        $proofs = collect($results)->where('proof', true)->filter(fn ($result) => empty($result['shortened']) || CampaignTrade::losing($result))
            ->pluck('realized_ratio')->filter(fn ($ratio) => $ratio !== null);
        $firstProfit = collect($results)->first(fn ($result) => $result['net'] > 0);
        $metrics = ['proof_realized_ratio' => $proofs->isEmpty() ? null : $proofs->min(),
            'minutes_to_first_profit' => $firstProfit ? $episode->opened_at->diffInSeconds(CarbonImmutable::parse($firstProfit['finished_at'])) / 60 : null,
            'freshness_holds' => $events->where('kind', 'freshness_hold')->pluck('data.run_id')->unique()->count()];
        $predictions = [];
        foreach ($grant->revision()->body['predictions'] as $prediction) {
            $sample = null;
            if ($prediction['metric'] === 'credits') {
                $target = $episode->opened_at->addSeconds((int) ($prediction['at_hours'] * 3600));
                $sample = $samples->first(fn ($sample) => $sample->observed_at->gte($target));
                $actual = $sample?->credits;
            } else {
                $actual = $metrics[$prediction['metric']];
            }
            $passing = $actual !== null && (isset($prediction['gte']) ? $actual >= $prediction['gte'] : $actual <= $prediction['lte']);
            $predictions[] = $prediction + ['actual' => $actual, 'sample_at' => $sample?->observed_at->toIso8601String(),
                'result' => $actual === null ? 'not_observed' : ($passing ? 'pass' : 'fail')];
        }
        $requestCounts = $samples->map(fn ($sample) => $sample->request_budget['account']['requests'] ?? $sample->request_budget['host']['requests'] ?? null)->filter(fn ($count) => $count !== null);
        $requests = $requestCounts->isEmpty() ? null : max(0, $requestCounts->last() - $requestCounts->first());
        $json = ['episode' => $episode->id, 'grant' => $grant->id, 'revision_hash' => $grant->revision_hash, 'reset_id' => $episode->reset_id,
            'phase' => $episode->phase, 'start_credits' => $episode->start_credits, 'last_credits' => Agent::find($episode->agent_id)?->credits,
            'duration_seconds' => $duration, 'ship_seconds' => $runs->sum('ship_seconds'), 'idle_seconds' => max(0, $duration - $runs->sum('ship_seconds')),
            'requests_used_between_samples' => $requests, 'credits_over_time' => $samples->map(fn ($sample) => ['at' => $sample->observed_at->toIso8601String(), 'credits' => $sample->credits, 'phase' => $sample->phase])->all(),
            'runs' => $runs->all(), 'strategies' => $strategies, 'pauses' => $events->where('kind', 'pause')->map(fn ($event) => ['at' => $event->created_at->toIso8601String(), 'reason' => $event->data['reason']])->values()->all(),
            'predictions' => $predictions, 'load_results' => $results, 'standalone_recoveries' => $recoveries, 'learning_metrics' => $metrics,
            'measurement_notes' => 'Cash receipts minus confirmed spending, not economic profit. Load results include confirmed acquisition basis and posted fuel bills. A load is losing only when its net is negative; a forced fallback sale that covered its costs is not. Fuel bought before a load departs, or beyond what its own travel burned, refills earlier loads and is not charged to it. Request counts are shared account/host counters between samples, not campaign-only attribution. Unsold cargo, wear and incomplete cycles are not profit.'];
        $markdown = '# Campaign episode '.$episode->id."\n\nReset: ".$episode->reset_id."\n\nPhase: ".$episode->phase."\n\nStart credits: ".$episode->start_credits." CR\n\n## Credits over time\n\n| Time | Credits | Phase |\n| --- | ---: | --- |\n";
        foreach ($json['credits_over_time'] as $sample) {
            $markdown .= '| '.$sample['at'].' | '.$sample['credits'].' | '.$sample['phase']." |\n";
        }
        $markdown .= "\n## Runs\n\n| Run | Strategy | Net CR | Ship seconds | CR/ship-hour |\n| --- | --- | ---: | ---: | ---: |\n";
        foreach ($runs as $run) {
            $markdown .= '| '.$run['id'].' | '.$run['strategy'].' | '.$run['net_credits'].' | '.$run['ship_seconds'].' | '.($run['credits_per_ship_hour'] ?? 'Unknown')." |\n";
        }
        $markdown .= "\n## Strategy totals\n\n".json_encode($strategies, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n\nIdle seconds: ".$json['idle_seconds']."\n\nRequests between samples: ".($requests ?? 'Unknown')."\n\n## Pauses\n\n".json_encode($json['pauses'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n\n## Predictions\n\n".json_encode($predictions, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n\n".$json['measurement_notes']."\n";

        return ['json' => $json, 'markdown' => $markdown];
    }
}
