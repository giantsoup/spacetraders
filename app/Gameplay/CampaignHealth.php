<?php

namespace App\Gameplay;

use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\LocalEngine;

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
            $last = CampaignEvent::where('campaign_grant_id', $grant->id)->latest('id')->first();
            if (($last?->created_at ?? $grant->created_at)->lt(now()->subMinutes(15))) {
                $problems['campaign_decisions_stalled:'.$grant->id] = 'Campaign has recorded no event for fifteen minutes while the engine is alive.';
            }
            $kind = $grant->wait_reason ? 'wait' : 'decision';
            $choice = CampaignEvent::where('campaign_grant_id', $grant->id)->where('kind', $kind)->latest('id')->first();
            if ($choice && $choice->created_at->lte(now()->subMinutes(60))
                && ($grant->wait_reason ? ($choice->data['reason'] ?? null) === $grant->wait_reason : ($choice->data['choice'] ?? null) === 'idle')) {
                $problems['campaign_choice_stalled:'.$grant->id.':'.($grant->wait_reason ?? 'idle')] = 'Campaign has kept the same wait or idle decision for sixty minutes.';
            }
        }
        if ($grant->paused_at) {
            $problems['campaign_paused:'.$grant->id.':'.$grant->pause_reason] = 'Campaign '.$grant->id.' is paused: '.$grant->pause_reason.'.';
        }

        return $problems;
    }
}
