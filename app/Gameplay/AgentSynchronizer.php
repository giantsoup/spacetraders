<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\GameObservation;
use App\Models\Ship;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AgentSynchronizer
{
    public function __construct(private SpaceTradersApi $api, private HostLock $lock) {}

    public static function identity(string $reset, string $symbol): string
    {
        return hash('sha256', 'https://api.spacetraders.io/v2|'.$reset.'|'.$symbol);
    }

    public function guardReset(Agent $agent): void
    {
        if ($agent->isArchived()) {
            throw new GameException('World reset. This agent is archived. Register or import an agent for the current world.');
        }
        $reset = $this->api->status();
        if ($this->archiveForReset($agent, $reset)) {
            throw new GameException('World reset detected. Import current credentials; old intentions cannot run.');
        }
        if (! $agent->reset_id) {
            $identity = self::identity($reset, $agent->name);
            if (Agent::where('canonical_identity', $identity)->whereKeyNot($agent->id)->exists()) {
                throw new GameException('This upstream agent already has a local authority. Use that record.');
            }
            $agent->update(['reset_id' => $reset, 'canonical_identity' => $identity]);
        }
    }

    /** @param Collection<int, Agent> $candidates Records captured before the status request. */
    public function archivePreviousWorlds(Collection $candidates, string $reset): void
    {
        foreach ($candidates as $agent) {
            if ($agent->reset_id === $reset) {
                continue;
            }
            $savedReset = $agent->reset_id;
            $this->lock->run('agent-'.$agent->id, function () use ($agent, $reset, $savedReset) {
                $agent->refresh();
                if ($agent->reset_id === $savedReset) {
                    $this->archiveForReset($agent, $reset);
                }
            });
        }
    }

    // Caller holds the agent lock; keep snapshots and intentions as historical records.
    public function archiveForReset(Agent $agent, string $reset): bool
    {
        if ($agent->reset_id === null || $agent->reset_id === $reset) {
            return false;
        }
        DB::transaction(function () use ($agent) {
            $agent->update(['connection_state' => 'reset']);
            AutomationRun::where('agent_id', $agent->id)->whereNotNull('active_agent_id')->whereNull('stop_requested_at')->update([
                'state' => 'blocked', 'stop_requested_at' => now(), 'blocked_reason' => 'World reset. Agent archived; old intentions cannot run.',
            ]);
        });

        return true;
    }

    // Caller holds the agent lock for synchronization, previews and gameplay writes.
    public function sync(Agent $agent, bool $discover = true): void
    {
        $this->guardReset($agent);
        $balance = $this->api->agent($agent);
        $fleet = $this->api->ships($agent);
        DB::transaction(function () use ($agent, $balance, $fleet) {
            foreach ($fleet as $ship) {
                $this->saveShip($agent, $ship);
            }
            Ship::where('agent_id', $agent->id)->whereNotIn('symbol', array_column($fleet, 'symbol'))->delete();
            $agent->update(['credits' => $balance['credits'], 'connection_state' => 'connected', 'synced_at' => now()]);
        });
        app(CreditAccounting::class)->baseline($agent);
        if (! $discover) {
            return;
        }
        $systems = array_unique(array_column(array_column($fleet, 'nav'), 'systemSymbol'));
        if (count($systems) > 3) {
            throw new GameException('Discovery is limited to three local systems.');
        }
        foreach ($systems as $system) {
            foreach ($this->api->waypoints($agent, $system) as $waypoint) {
                $this->observe($agent, 'waypoint', $waypoint['symbol'], $waypoint);
            }
        }
        $observations = 0;
        $locations = array_column(array_column($fleet, 'nav'), 'waypointSymbol');
        $points = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'waypoint')->get()
            ->sortByDesc(fn ($point) => in_array($point->symbol, $locations, true));
        foreach ($points as $waypoint) {
            foreach (['MARKETPLACE' => 'market', 'SHIPYARD' => 'shipyard'] as $trait => $kind) {
                if (in_array($trait, array_column($waypoint->data['traits'], 'symbol'), true) && $observations++ < config('gameplay.max_observations')) {
                    $this->observe($agent, $kind, $waypoint->symbol, $this->api->$kind($agent, $waypoint->symbol));
                }
            }
        }
    }

    public function refreshShip(Agent $agent, string $symbol): Ship
    {
        return $this->saveShip($agent, $this->api->ship($agent, $symbol));
    }

    public function saveShip(Agent $agent, array $data): Ship
    {
        return Ship::updateOrCreate(['agent_id' => $agent->id, 'symbol' => $data['symbol']], ['snapshot' => $data, 'observed_at' => now()]);
    }

    public function observe(Agent $agent, string $kind, string $symbol, array $data): GameObservation
    {
        return GameObservation::updateOrCreate(['agent_id' => $agent->id, 'kind' => $kind, 'symbol' => $symbol], [
            'reset_id' => $agent->reset_id, 'data' => $data, 'observed_at' => now(),
        ]);
    }
}
