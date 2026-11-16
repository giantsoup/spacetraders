<?php

namespace App\Gameplay;

use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\LocalEngine;
use Carbon\CarbonImmutable;

class CampaignHealth
{
    public function problems(): array
    {
        $grant = CampaignGrant::where('slot', 1)->first();
        if (! $grant) {
            return [];
        }
        $problems = [];
        $engine = LocalEngine::find(1);
        if (! $engine?->heartbeat_at || $engine->heartbeat_at->lt(now()->subMinutes(5))) {
            $problems['engine_heartbeat_stale'] = 'Engine heartbeat is missing or older than five minutes.';
        }
        $alive = $engine?->state === 'running' && ! isset($problems['engine_heartbeat_stale']);
        if ($alive && ! $grant->paused_at) {
            $resumed = $grant->last_directed_at ? null : CampaignEvent::where('campaign_grant_id', $grant->id)->where('kind', 'resume')->latest('id')->value('created_at');
            $seen = $grant->last_directed_at ?? $resumed ?? $grant->created_at;
            if ($seen->lt(now()->subMinutes(15))) {
                $problems['campaign_decisions_stalled:'.$grant->id] = 'Campaign director has not run for fifteen minutes while the engine is alive.';
            }
            $episode = CampaignEpisode::where('campaign_grant_id', $grant->id)->whereNull('closed_at')->latest('id')->first();
            $started = AutomationRun::whereIn('campaign_episode_id', CampaignEpisode::where('campaign_grant_id', $grant->id)->select('id'))->where('strategy', '!=', 'sell_aboard')->max('started_at');
            $result = CampaignEvent::where('campaign_grant_id', $grant->id)->whereIn('kind', ['load_result', 'recovery_result'])->latest('id')->value('created_at');
            $progress = collect([$episode?->opened_at ?? $grant->created_at, $started ? CarbonImmutable::parse($started) : null, $result])->filter()->max();
            if ($episode?->phase !== 'wind_down' && $progress->lte(now()->subMinutes(60))) {
                $problems['campaign_progress_stalled:'.$grant->id] = 'Campaign has not started a run or recorded a load or recovery result for sixty minutes.';
            }
        }
        if ($grant->paused_at) {
            $problems['campaign_paused:'.$grant->id.':'.$grant->pause_reason] = 'Campaign '.$grant->id.' is paused: '.$grant->pause_reason.'.';
        }

        return $problems;
    }
}
