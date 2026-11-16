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
    /**
     * The probe's own runs hide a stranded command ship from the sixty-minute check. The longest
     * normal command-ship gap observed was about nineteen minutes.
     */
    public const COMMAND_STALL_MINUTES = 25;

    /** No command-ship alert while a new episode starts up. */
    public const COMMAND_STALL_GRACE_MINUTES = 30;

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
            if ($episode && $this->commandStalled($grant, $episode)) {
                $problems['command_ship_stalled:'.$grant->id] = 'The command ship has not started a run for '.self::COMMAND_STALL_MINUTES.' minutes in earn.';
            }
        }
        if ($grant->paused_at) {
            $problems['campaign_paused:'.$grant->id.':'.$grant->pause_reason] = 'Campaign '.$grant->id.' is paused: '.$grant->pause_reason.'.';
        }

        return $problems;
    }

    /**
     * In earn, outside wind-down and reset, the timer runs from the latest of the command ship's
     * last run start (any strategy), the move into earn, and the last resume, so paused time is
     * not counted. Runs by the probe or by extra non-role ships do not reset it.
     */
    private function commandStalled(CampaignGrant $grant, CampaignEpisode $episode): bool
    {
        $command = $episode->state['role_ships']['command'] ?? null;
        if (! $command || $episode->phase !== 'earn' || app(CampaignSafety::class)->windDown($episode)
            || $episode->opened_at->gt(now()->subMinutes(self::COMMAND_STALL_GRACE_MINUTES))) {
            return false;
        }
        $started = AutomationRun::where('campaign_episode_id', $episode->id)->where('ship_symbol', $command)->whereNotNull('started_at')->max('started_at');
        // An episode whose playbook starts in earn has no phase event; it entered earn when it opened.
        $earn = CampaignEvent::where('campaign_episode_id', $episode->id)->where('kind', 'phase')->where('data->to', 'earn')->latest('id')->value('created_at') ?? $episode->opened_at;
        $resumed = CampaignEvent::where('campaign_grant_id', $grant->id)->where('kind', 'resume')->latest('id')->value('created_at');
        $since = collect([$started ? CarbonImmutable::parse($started) : null, $earn, $resumed])->filter()->max();

        return $since->lte(now()->subMinutes(self::COMMAND_STALL_MINUTES));
    }
}
