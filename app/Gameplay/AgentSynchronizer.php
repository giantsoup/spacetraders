<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\GameObservation;
use App\Models\ObservationHistory;
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
            $this->api->catalogue($agent, $system, function (array $rows, array $coverage) use ($agent, $system) {
                foreach ($rows as $waypoint) {
                    $this->observe($agent, 'waypoint', $waypoint['symbol'], $waypoint, 'listing');
                }
                if ($coverage['complete']) {
                    GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'waypoint')->get()
                        ->filter(fn ($point) => $point->data['systemSymbol'] === $system && ! in_array($point->symbol, $coverage['listed_symbols'], true))
                        ->each->delete();
                }
                $this->observe($agent, 'catalogue', $system, $coverage, 'listing');
            });
        }
        $locations = array_column(array_column($fleet, 'nav'), 'waypointSymbol');
        $saved = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->get();
        $tasks = [];
        foreach ($saved->where('kind', 'waypoint') as $waypoint) {
            foreach (['MARKETPLACE' => 'market', 'SHIPYARD' => 'shipyard'] as $trait => $kind) {
                if (in_array($trait, array_column($waypoint->data['traits'], 'symbol'), true)) {
                    $last = $saved->first(fn ($row) => $row->symbol === $waypoint->symbol && $row->kind === ($kind === 'market' ? 'market_listing' : $kind));
                    $tasks[] = ['symbol' => $waypoint->symbol, 'kind' => $kind, 'occupied' => in_array($waypoint->symbol, $locations, true), 'last' => $last?->observed_at->getTimestamp() ?? 0];
                }
            }
        }
        usort($tasks, fn ($a, $b) => [! $a['occupied'], $a['last'], $a['symbol'], $a['kind']] <=> [! $b['occupied'], $b['last'], $b['symbol'], $b['kind']]);
        $coverage = ['observations' => 0, 'candidates' => count($tasks), 'complete' => false];
        $this->observe($agent, 'discovery', 'local', $coverage, 'listing');
        foreach (array_slice($tasks, 0, config('gameplay.max_observations')) as $task) {
            $kind = $task['kind'];
            $this->observe($agent, $kind, $task['symbol'], $this->api->$kind($agent, $task['symbol']));
            $coverage['observations']++;
            $this->observe($agent, 'discovery', 'local', $coverage, 'listing');
        }
        $coverage['complete'] = $coverage['observations'] === $coverage['candidates'];
        $this->observe($agent, 'discovery', 'local', $coverage, 'listing');
    }

    public function refreshShip(Agent $agent, string $symbol): Ship
    {
        return $this->saveShip($agent, $this->api->ship($agent, $symbol));
    }

    public function saveShip(Agent $agent, array $data): Ship
    {
        return Ship::updateOrCreate(['agent_id' => $agent->id, 'symbol' => $data['symbol']], ['snapshot' => $data, 'observed_at' => now()]);
    }

    public function invalidateMarket(Agent $agent, string $symbol, int $action): void
    {
        $quote = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'market')->where('symbol', $symbol)->first();
        if ($quote) {
            $quote->update(['data' => array_merge($quote->data, ['invalidated_by_action' => $action])]);
        }
    }

    public function observe(Agent $agent, string $kind, string $symbol, array $data, string $source = 'read', ?string $observer = null): GameObservation
    {
        return DB::transaction(function () use ($agent, $kind, $symbol, $data, $source, $observer) {
            if ($kind === 'waypoint' && in_array($source, ['listing', 'scan', 'chart'], true)) {
                $this->observe($agent, 'waypoint_'.$source, $symbol, $data, $source, $observer);
            }
            if ($kind === 'waypoint') {
                $known = ExplorationPolicy::traitsKnown($data) || in_array($source, ['scan', 'chart'], true);
                $old = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'waypoint')->where('symbol', $symbol)->first();
                if (! $known && $old && ExplorationPolicy::traitsKnown($old->data)) {
                    foreach (['traits', 'traits_observed', 'traits_observed_at', 'traits_source'] as $field) {
                        if (array_key_exists($field, $old->data)) {
                            $data[$field] = $old->data[$field];
                        }
                    }
                } else {
                    $data['traits_observed'] = $known;
                    $data['traits_observed_at'] = $known ? now()->toIso8601String() : null;
                    $data['traits_source'] = $known ? $source : null;
                }
            }
            if ($kind === 'market') {
                $listing = array_intersect_key($data, array_flip(['symbol', 'imports', 'exports', 'exchange']));
                $this->observe($agent, 'market_listing', $symbol, $listing, $source, $observer);
                if (empty($data['tradeGoods'])) {
                    $old = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'market')->where('symbol', $symbol)->first();
                    if ($old && ! empty($old->data['tradeGoods'])) {
                        return $old;
                    }
                } else {
                    $observer ??= Ship::where('agent_id', $agent->id)->get()->first(fn ($ship) => $ship->snapshot['nav']['waypointSymbol'] === $symbol && $ship->snapshot['nav']['status'] !== 'IN_TRANSIT')?->symbol;
                }
            }
            $values = ['reset_id' => $agent->reset_id, 'data' => $data, 'observed_at' => now(), 'source' => $source, 'observer_ship' => $observer];
            $key = ['agent_id' => $agent->id, 'kind' => $kind, 'symbol' => $symbol];
            ObservationHistory::create(array_merge($key, $values));

            return GameObservation::updateOrCreate($key, $values);
        });
    }
}
