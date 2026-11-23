<?php

namespace App\Gameplay\Bridge;

use App\Gameplay\RequestBudget;
use App\Gameplay\RequestDeferred;
use App\Models\Agent;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;

class BridgeDrain
{
    /** The caller supplies only the grant's matching current open episode. */
    public static function marker(CampaignGrant $grant, ?CampaignEpisode $episode): ?string
    {
        foreach ([$grant->wait_reason, $grant->pause_reason] as $reason) {
            if (is_string($reason) && str_starts_with($reason, 'drain_pause:')) {
                return substr($reason, strlen('drain_pause:'));
            }
        }
        $reason = $episode?->state['draining'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    public static function incomplete(CampaignGrant $grant, ?CampaignEpisode $episode): bool
    {
        return self::marker($grant, $episode) !== null
            && ($grant->isDraining() || ! empty($episode?->state['draining']) || ! $grant->paused_at);
    }

    /** @return list<string>|null Null when a hold cannot be ruled out. */
    public function budgetHolds(): ?array
    {
        $holds = $this->budgetHoldIds();

        return $holds === null ? null : array_map('strval', array_keys($holds));
    }

    /** @return array<string, string>|null Null when the hold state cannot be determined. */
    public function budgetHoldIds(): ?array
    {
        try {
            return app(RequestBudget::class)->drainHoldIds(requireTelemetry: config('gameplay.request_budget_enabled'));
        } catch (RequestDeferred) {
            return null;
        }
    }

    public static function episode(CampaignGrant $grant, ?Agent $agent): ?CampaignEpisode
    {
        return $agent?->reset_id ? CampaignEpisode::where('campaign_grant_id', $grant->id)
            ->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->whereNull('closed_at')->first() : null;
    }
}
