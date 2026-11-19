<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\ReconMission;
use App\Models\Ship;
use App\Models\ShipAction;
use Illuminate\Database\Eloquent\Builder;

class CampaignFleet
{
    public function role(CampaignEpisode $episode, string $role): ?array
    {
        $ships = Ship::where('agent_id', $episode->agent_id)->orderBy('symbol')->get();
        $state = $episode->state ?? [];
        // An arm episode commands only its own ship and has no probe; it never adopts C0's ships.
        $arm = $this->armShip($episode);
        if ($arm !== null) {
            return $role === 'command' ? $ships->firstWhere('symbol', $arm)?->snapshot : null;
        }
        $pinned = $ships->firstWhere('symbol', $state['role_ships'][$role] ?? null);
        if ($role === 'probe' && ReconMission::where('agent_id', $episode->agent_id)->whereNull('closed_at')->get()->contains(fn ($mission) => ReconMission::holds(Agent::findOrFail($episode->agent_id), $mission->ship_symbol))) {
            return null;
        }
        if ($pinned) {
            return $pinned->snapshot;
        }
        $candidates = $ships->filter(fn ($ship) => $role === 'command'
            ? ($ship->snapshot['registration']['role'] ?? null) === 'COMMAND'
            : (in_array($ship->snapshot['registration']['role'] ?? null, ['SATELLITE', 'PROBE'], true) || ($ship->snapshot['frame']['symbol'] ?? null) === 'FRAME_PROBE'));
        // Preserve the ship already used by episodes created before role pinning existed.
        $used = AutomationRun::where('campaign_episode_id', $episode->id)->whereNotNull('started_at')
            ->whereIn('ship_symbol', $candidates->pluck('symbol'))->oldest('id')->value('ship_symbol');
        $ship = $candidates->firstWhere('symbol', $used) ?? $candidates->first();
        if ($ship && ! $episode->closed_at) {
            CampaignEpisode::whereKey($episode->id)->whereNull('closed_at')->update(['state->role_ships->'.$role => $ship->symbol]);
            $episode->refresh();
        }

        return $ship?->snapshot;
    }

    /** The arm ship when the episode belongs to an arm grant, otherwise null. */
    public function armShip(CampaignEpisode $episode): ?string
    {
        $pinned = $episode->state['role_ships']['command'] ?? null;
        if (isset($episode->state['arm']) && is_string($pinned)) {
            return $pinned;
        }

        return CampaignGrant::find($episode->campaign_grant_id)?->arm()['ship'] ?? null;
    }

    public function symbols(CampaignEpisode $episode): array
    {
        return array_values(array_filter(array_map(fn ($role) => $this->role($episode, $role)['symbol'] ?? null, ['command', 'probe'])));
    }

    public function pending(CampaignEpisode $episode): Builder
    {
        $symbols = $this->symbols($episode);

        return ShipAction::where('agent_id', $episode->agent_id)->where('reset_id', $episode->reset_id)->whereIn('state', ['pending', 'sending'])
            ->where(fn ($query) => $query->whereIn('ship_symbol', $symbols)
                ->orWhereIn('automation_run_id', AutomationRun::where('campaign_episode_id', $episode->id)->select('id')));
    }
}
