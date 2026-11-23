<?php

namespace App\Gameplay;

use App\Gameplay\Bridge\BridgeDrain;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\ReconMission;
use App\Models\Ship;
use App\Models\ShipAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CampaignFleet
{
    public function probePolicy(CampaignEpisode $episode): ?array
    {
        if ($this->armShip($episode) !== null) {
            return null;
        }

        return $this->probePolicyForGrant(CampaignGrant::find($episode->campaign_grant_id));
    }

    private function probePolicyForGrant(?CampaignGrant $grant): ?array
    {
        $policy = $grant?->revision()->body['probes'] ?? null;
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
    public function probes(CampaignEpisode $episode, bool $pinRoles = true): array
    {
        if ($this->armShip($episode) !== null) {
            return [];
        }
        if (! $this->poolEnabled($episode)) {
            $single = $this->role($episode, 'probe', $pinRoles);

            return $single ? [$single] : [];
        }
        $agent = Agent::findOrFail($episode->agent_id);
        $ships = Ship::where('agent_id', $episode->agent_id)->orderBy('symbol')->get();
        $command = $this->role($episode, 'command', $pinRoles);

        return $this->eligiblePool($agent, $ships, $this->liveArmShips($agent), $command['nav']['systemSymbol'] ?? null);
    }

    private function liveArmShips(Agent $agent): array
    {
        return CampaignGrant::liveArms($agent->name)->where('user_id', $agent->user_id)->get()
            ->map(fn (CampaignGrant $grant) => $grant->arm()['ship'] ?? null)->filter()->all();
    }

    private function eligiblePool(Agent $agent, Collection $ships, array $armShips, ?string $homeSystem): array
    {
        $probes = $ships->filter(fn ($ship) => $this->isProbe($ship->snapshot)
            && empty($ship->stationed_waypoint)
            && ! in_array($ship->symbol, $armShips, true)
            && ($homeSystem === null || ($ship->snapshot['nav']['systemSymbol'] ?? null) === $homeSystem));
        $heldShips = ReconMission::heldShips($agent, $probes->pluck('symbol')->all());

        return $probes->reject(fn ($ship) => in_array($ship->symbol, $heldShips, true))
            ->map(fn ($ship) => $ship->snapshot)
            ->values()
            ->all();
    }

    public function role(CampaignEpisode $episode, string $role, bool $pinRoles = true): ?array
    {
        $ships = Ship::where('agent_id', $episode->agent_id)->orderBy('symbol')->get();
        $state = $episode->state ?? [];
        // An arm episode commands only its own ship and has no probe; it never adopts C0's ships.
        $arm = $this->armShip($episode);
        if ($arm !== null) {
            return $role === 'command' ? $ships->firstWhere('symbol', $arm)?->snapshot : null;
        }
        if ($role === 'probe' && $this->poolEnabled($episode)) {
            $pool = $this->probes($episode, $pinRoles);
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
            if ($pinRoles && ! $pinnedSymbol && ! $episode->closed_at) {
                CampaignEpisode::whereKey($episode->id)->whereNull('closed_at')->update(['state->role_ships->probe' => $chosen['symbol']]);
                $episode->refresh();
            }

            return $chosen;
        }

        return $role === 'probe'
            ? $this->legacyProbe($episode, $ships, $pinRoles)
            : $this->preferredRole($episode, $ships, $role, $pinRoles);
    }

    private function legacyProbe(CampaignEpisode $episode, Collection $ships, bool $pinRoles, ?Agent $agent = null, ?bool $draining = null): ?array
    {
        $pinned = $episode->state['role_ships']['probe'] ?? null;
        // A different empty probe cannot prove that a previously pinned probe's cargo settled.
        if (is_string($pinned) && ! $ships->contains('symbol', $pinned)
            && ($draining ?? BridgeDrain::marker($episode->grant(), $episode) !== null)) {
            return null;
        }
        $missions = ReconMission::where('agent_id', $episode->agent_id)->whereNull('closed_at')->pluck('ship_symbol')->all();
        if ($missions !== [] && ReconMission::heldShips($agent ?? Agent::findOrFail($episode->agent_id), $missions) !== []) {
            return null;
        }

        return $this->preferredRole($episode, $ships, 'probe', $pinRoles);
    }

    private function preferredRole(CampaignEpisode $episode, Collection $ships, string $role, bool $pinRoles): ?array
    {
        $pinned = $ships->firstWhere('symbol', $episode->state['role_ships'][$role] ?? null);
        if ($pinned) {
            return $pinned->snapshot;
        }
        $candidates = $ships->filter(fn ($ship) => $role === 'command'
            ? ($ship->snapshot['registration']['role'] ?? null) === 'COMMAND'
            : ($this->isProbe($ship->snapshot) && empty($ship->stationed_waypoint)));
        if ($candidates->isEmpty()) {
            return null;
        }

        // Preserve the ship already used by episodes created before role pinning existed.
        $used = AutomationRun::where('campaign_episode_id', $episode->id)->whereNotNull('started_at')
            ->whereIn('ship_symbol', $candidates->pluck('symbol'))->oldest('id')->value('ship_symbol');
        $ship = $candidates->firstWhere('symbol', $used) ?? $candidates->first();
        if ($pinRoles && $ship && ! $episode->closed_at) {
            CampaignEpisode::whereKey($episode->id)->whereNull('closed_at')->update(['state->role_ships->'.$role => $ship->symbol]);
            $episode->refresh();
        }

        return $ship?->snapshot;
    }

    /** The arm ship when the episode belongs to an arm grant, otherwise null. */
    public function armShip(CampaignEpisode $episode, ?CampaignGrant $grant = null): ?string
    {
        $pinned = $episode->state['role_ships']['command'] ?? null;
        if (isset($episode->state['arm']) && is_string($pinned)) {
            return $pinned;
        }

        return ($grant ?? CampaignGrant::find($episode->campaign_grant_id))?->arm()['ship'] ?? null;
    }

    public function symbols(CampaignEpisode $episode, bool $pinRoles = true): array
    {
        if ($this->poolEnabled($episode)) {
            $command = $this->role($episode, 'command', $pinRoles)['symbol'] ?? null;
            $probes = array_column($this->probes($episode, $pinRoles), 'symbol');

            return array_values(array_unique(array_filter([$command, ...$probes])));
        }

        return array_values(array_filter(array_map(fn ($role) => $this->role($episode, $role, $pinRoles)['symbol'] ?? null, ['command', 'probe'])));
    }

    /** @return array{ships: array<string, array|null>, commandIdentified: bool, roleSymbols: list<string>} */
    public function recoveryFleet(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, bool $draining, bool $pinRoles = true): array
    {
        $snapshots = Ship::where('agent_id', $agent->id)->orderBy('symbol')->get()->keyBy('symbol');
        $armShip = $this->armShip($episode, $grant);
        $command = $armShip !== null ? $snapshots->get($armShip)?->snapshot : $this->preferredRole($episode, $snapshots, 'command', $pinRoles);
        $commandSymbol = $command['symbol'] ?? $armShip ?? $episode->state['role_ships']['command'] ?? null;
        $ships = $commandSymbol === null ? [] : [$commandSymbol => $command];
        if ($armShip !== null) {
            return ['ships' => $ships, 'commandIdentified' => true, 'roleSymbols' => array_keys($ships)];
        }

        $pooled = (bool) ($this->probePolicyForGrant($grant)['pool'] ?? false);
        $armShips = $pooled || $draining ? $this->liveArmShips($agent) : [];
        if ($pooled) {
            $ships += array_column($this->eligiblePool($agent, $snapshots, $armShips, $command['nav']['systemSymbol'] ?? null), null, 'symbol');
        } else {
            $probe = $this->legacyProbe($episode, $snapshots, $pinRoles, $agent, $draining);
            $pinnedProbe = $episode->state['role_ships']['probe'] ?? null;
            if ($probe !== null) {
                $ships += [$probe['symbol'] => $probe];
            } elseif ($draining && is_string($pinnedProbe) && ! $snapshots->has($pinnedProbe)
                && ! in_array($pinnedProbe, $armShips, true) && ! ReconMission::holds($agent, $pinnedProbe)) {
                $ships += [$pinnedProbe => null];
            }
        }
        $roleSymbols = array_keys($ships);

        if ($draining) {
            $startedShips = AutomationRun::where('campaign_episode_id', $episode->id)->where('reset_id', $episode->reset_id)
                ->whereNotNull('started_at')->whereNotIn('ship_symbol', $armShips)->distinct()->pluck('ship_symbol');
            $startedShips = $startedShips->diff($roleSymbols);
            $startedShips = $startedShips->diff(ReconMission::heldShips($agent, $startedShips->all()));
            foreach ($startedShips as $symbol) {
                $ships[$symbol] = $snapshots->get($symbol)?->snapshot;
            }
        }

        return ['ships' => $ships, 'commandIdentified' => $commandSymbol !== null, 'roleSymbols' => $roleSymbols];
    }

    public function pending(CampaignEpisode $episode, ?array $symbols = null): Builder
    {
        $symbols ??= $this->symbols($episode);

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
