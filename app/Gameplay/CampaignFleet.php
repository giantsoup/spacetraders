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
    public function probePolicy(CampaignEpisode $episode): ?array
    {
        if ($this->armShip($episode) !== null) {
            return null;
        }
        $policy = CampaignGrant::find($episode->campaign_grant_id)?->revision()->body['probes'] ?? null;
        if (! is_array($policy)) {
            return null;
        }

        return [
            'target' => $policy['target'],
            'max_price' => $policy['max_price'],
            'min_working_capital' => $policy['min_working_capital'],
            'pool' => (bool) ($policy['pool'] ?? true),
        ];
    }

    public function poolEnabled(CampaignEpisode $episode): bool
    {
        return (bool) ($this->probePolicy($episode)['pool'] ?? false);
    }

    public function isProbe(array $ship): bool
    {
        return in_array($ship['registration']['role'] ?? null, ['SATELLITE', 'PROBE'], true)
            || ($ship['frame']['symbol'] ?? null) === 'FRAME_PROBE';
    }

    /**
     * All C0 probes available to the episode. When probes.pool is enabled, returns every unheld
     * same-system probe (excluding live arm ships and ships held by a ReconMission). Otherwise
     * returns the single legacy probe role ship (or empty when none is available).
     *
     * @return list<array>
     */
    public function probes(CampaignEpisode $episode): array
    {
        if ($this->armShip($episode) !== null) {
            return [];
        }
        if (! $this->poolEnabled($episode)) {
            $single = $this->role($episode, 'probe');

            return $single ? [$single] : [];
        }
        $agent = Agent::findOrFail($episode->agent_id);
        $ships = Ship::where('agent_id', $episode->agent_id)->orderBy('symbol')->get();
        $armShips = CampaignGrant::liveArms()->get()->map(fn ($grant) => $grant->arm()['ship'] ?? null)->filter()->all();
        $command = $this->role($episode, 'command');
        $homeSystem = $command['nav']['systemSymbol'] ?? null;

        return $ships->filter(fn ($ship) => $this->isProbe($ship->snapshot)
            && ! in_array($ship->symbol, $armShips, true)
            && ($homeSystem === null || ($ship->snapshot['nav']['systemSymbol'] ?? null) === $homeSystem)
            && ! ReconMission::holds($agent, $ship->symbol))
            ->map(fn ($ship) => $ship->snapshot)
            ->values()
            ->all();
    }

    public function role(CampaignEpisode $episode, string $role): ?array
    {
        $ships = Ship::where('agent_id', $episode->agent_id)->orderBy('symbol')->get();
        $state = $episode->state ?? [];
        // An arm episode commands only its own ship and has no probe; it never adopts C0's ships.
        $arm = $this->armShip($episode);
        if ($arm !== null) {
            return $role === 'command' ? $ships->firstWhere('symbol', $arm)?->snapshot : null;
        }
        if ($role === 'probe' && $this->poolEnabled($episode)) {
            $pool = $this->probes($episode);
            if ($pool === []) {
                return null;
            }
            $pinnedSymbol = $state['role_ships']['probe'] ?? null;
            $activeShips = AutomationRun::where('active_agent_id', $episode->agent_id)->pluck('ship_symbol')->all();
            $idle = array_values(array_filter($pool, fn ($ship) => ($ship['nav']['status'] ?? null) !== 'IN_TRANSIT'
                && ! in_array($ship['symbol'], $activeShips, true)
                && ! $this->pendingForShip($episode, $ship['symbol'])));
            $chosen = collect($idle)->firstWhere('symbol', $pinnedSymbol)
                ?? ($idle[0] ?? null)
                ?? collect($pool)->firstWhere('symbol', $pinnedSymbol)
                ?? $pool[0];
            if (! $pinnedSymbol && ! $episode->closed_at) {
                CampaignEpisode::whereKey($episode->id)->whereNull('closed_at')->update(['state->role_ships->probe' => $chosen['symbol']]);
                $episode->refresh();
            }

            return $chosen;
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
            : $this->isProbe($ship->snapshot));
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
        if ($this->poolEnabled($episode)) {
            $command = $this->role($episode, 'command')['symbol'] ?? null;
            $probes = array_column($this->probes($episode), 'symbol');

            return array_values(array_unique(array_filter([$command, ...$probes])));
        }

        return array_values(array_filter(array_map(fn ($role) => $this->role($episode, $role)['symbol'] ?? null, ['command', 'probe'])));
    }

    public function pending(CampaignEpisode $episode): Builder
    {
        $symbols = $this->symbols($episode);

        return ShipAction::where('agent_id', $episode->agent_id)->where('reset_id', $episode->reset_id)->whereIn('state', ['pending', 'sending'])
            ->where(fn ($query) => $query->whereIn('ship_symbol', $symbols)
                ->orWhereIn('automation_run_id', AutomationRun::where('campaign_episode_id', $episode->id)->select('id')));
    }

    public function pendingForShip(CampaignEpisode $episode, string $shipSymbol): bool
    {
        return ShipAction::where('agent_id', $episode->agent_id)->where('reset_id', $episode->reset_id)->whereIn('state', ['pending', 'sending'])
            ->where(fn ($query) => $query->where('ship_symbol', $shipSymbol)
                ->orWhereIn('automation_run_id', AutomationRun::where('campaign_episode_id', $episode->id)->where('ship_symbol', $shipSymbol)->select('id')))
            ->exists();
    }
}
