<?php

namespace App\Gameplay;

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
        if ($grant->paused_at) {
            $problems['campaign_paused:'.$grant->id.':'.$grant->pause_reason] = 'Campaign '.$grant->id.' is paused: '.$grant->pause_reason.'.';
        }

        return $problems;
    }
}
