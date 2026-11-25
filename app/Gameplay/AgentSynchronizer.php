<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\GameObservation;
use App\Models\ObservationHistory;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Services\SpaceTradersClient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AgentSynchronizer
{
    private static array $lockedFleetMemo = [];

    public static function clearLockedFleetMemo(): void
    {
        self::$lockedFleetMemo = [];
    }

    private static function forgetLockedFleet(int $agentId): void
    {
        foreach (array_keys(self::$lockedFleetMemo) as $key) {
            if (str_starts_with($key, $agentId.':')) {
                unset(self::$lockedFleetMemo[$key]);
            }
        }
    }

    public function __construct(private SpaceTradersApi $api, private HostLock $lock) {}

    public static function identity(string $reset, string $symbol): string
    {
        return hash('sha256', 'https://api.spacetraders.io/v2|'.$reset.'|'.$symbol);
    }

    public function guardReset(Agent $agent, bool $fresh = false): void
    {
        if ($agent->isArchived()) {
            throw new GameException('World reset. This agent is archived. Register or import an agent for the current world.');
        }
        $reset = $this->api->status($fresh || ! $agent->reset_id);
        if (($agent->reset_id === null || $agent->reset_id !== $reset) && $this->lock->holdsShared('agent-'.$agent->id)) {
            throw new GameException('World reset requires exclusive synchronization before another command.', retryAfter: 5);
        }
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
        $key = 'archived_budget_hold:'.$agent->user_id.':'.$agent->id;
        if ($agent->isArchived()) {
            $value = DB::table('game_runtime')->where('key', $key)->value('value');
            if ($value !== null) {
                $this->reconcileArchivedDrain($key, $value);
            }

            return false;
        }

        $value = DB::transaction(function () use ($agent, $key) {
            $agent->update(['connection_state' => 'reset']);
            AutomationRun::where('agent_id', $agent->id)->whereNotNull('active_agent_id')->whereNull('stop_requested_at')->update([
                'state' => 'blocked', 'stop_requested_at' => now(), 'blocked_reason' => 'World reset. Agent archived; old intentions cannot run.',
            ]);
            $value = json_encode(['symbol' => $agent->name, 'reset_id' => $agent->reset_id, 'before' => microtime(true)], JSON_THROW_ON_ERROR);
            DB::table('game_runtime')->upsert([['key' => $key, 'value' => $value]], ['key'], ['value']);

            return $value;
        });
        $this->reconcileArchivedDrain($key, $value);

        return true;
    }

    public function retryArchivedDrains(int $userId): void
    {
        $pending = DB::table('game_runtime')->where('key', 'like', 'archived_budget_hold:'.$userId.':%')->pluck('value', 'key');
        foreach ($pending as $key => $value) {
            $this->reconcileArchivedDrain($key, $value);
        }
    }

    private function reconcileArchivedDrain(string $key, string $value): void
    {
        $pending = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        try {
            app(RequestBudget::class)->clearDrainBefore($pending['symbol'], $pending['reset_id'], $pending['before']);
        } catch (RequestDeferred $exception) {
            Log::warning('Archived agent budget hold could not be reconciled.', ['agent' => $pending['symbol'], 'reason' => $exception->getMessage()]);

            return;
        }

        DB::table('game_runtime')->where('key', $key)->where('value', $value)->delete();
    }

    // Caller holds the agent lock for synchronization, previews and gameplay writes.
    public function sync(Agent $agent, bool $discover = true, bool $freshReset = false, bool $freshFleet = false, float $reuseLockedSeconds = 0): int
    {
        $lockKey = 'agent-'.$agent->id;
        $guarded = false;
        try {
            if ($reuseLockedSeconds > 0 && ! $discover && $this->lock->holdsExclusive($lockKey)) {
                $this->guardReset($agent, $freshReset);
                $guarded = true;
                $token = $this->lock->token($lockKey);
                $memo = self::$lockedFleetMemo["{$agent->id}:{$agent->reset_id}"] ?? null;
                if ($token !== null && $memo !== null && $memo['token'] === $token && $memo['writes'] === SpaceTradersClient::writeCount()
                    && $memo['reset_id'] === $agent->reset_id && microtime(true) - $memo['fetched_at'] <= $reuseLockedSeconds) {
                    $agent->refresh();

                    return $memo['credits'];
                }
            }
            $tokenBefore = $this->lock->holdsExclusive($lockKey) ? $this->lock->token($lockKey) : null;
            $priorKey = "{$agent->id}:{$agent->reset_id}";
            $priorMemo = $tokenBefore !== null ? (self::$lockedFleetMemo[$priorKey] ?? null) : null;
            if (! $guarded) {
                $this->guardReset($agent, $freshReset);
            }
            $hitsBefore = SpaceTradersClient::cacheHitCount();
            $writesBefore = SpaceTradersClient::writeCount();
            $balance = $this->api->agent($agent, fresh: $freshFleet);
            $fleet = $this->api->ships($agent, fresh: $freshFleet);
            $fetchedAt = microtime(true);
            $transportFresh = SpaceTradersClient::cacheHitCount() === $hitsBefore && SpaceTradersClient::writeCount() === $writesBefore;
            DB::transaction(function () use ($agent, $balance, $fleet) {
                foreach ($fleet as $ship) {
                    $this->saveFullShip($agent, $ship, 'fleet_get');
                }
                Ship::where('agent_id', $agent->id)->whereNotIn('symbol', array_column($fleet, 'symbol'))->delete();
                $sending = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                    ->where('state', 'sending')->whereNotIn('command', [...CampaignSafety::SHIP_COMMANDS, 'transfer'])->exists();
                if ($sending || app(CampaignSafety::class)->uncertainCredit($agent) || app(StateChain::class)->hasUnresolvedCreditAction($agent)) {
                    DB::table('game_runtime')->upsert([['key' => 'unresolved_credit_observation:'.$agent->id.':'.$agent->reset_id,
                        'value' => json_encode(['credits' => $balance['credits'], 'observed_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR)]], ['key'], ['value']);
                } else {
                    $agent->update(['credits' => $balance['credits'], 'connection_state' => 'connected', 'synced_at' => now()]);
                    app(CreditAccounting::class)->baseline($agent);
                }
            });
            DB::table('game_runtime')->upsert([['key' => 'fleet_sync_at:'.$agent->id, 'value' => now()->toIso8601String()]], ['key'], ['value']);
            if ($discover) {
                $this->discover($agent, $fleet);
            }

            $token = $this->lock->holdsExclusive($lockKey) ? $this->lock->token($lockKey) : null;
            $writesAfter = SpaceTradersClient::writeCount();
            $key = "{$agent->id}:{$agent->reset_id}";
            $maxReuse = (float) config('gameplay.locked_fleet_reuse_seconds', 30);
            self::forgetLockedFleet($agent->id);
            if ($token !== null && $transportFresh && $writesAfter === $writesBefore) {
                self::$lockedFleetMemo[$key] = ['token' => $token, 'writes' => $writesAfter, 'reset_id' => $agent->reset_id, 'fetched_at' => $fetchedAt, 'credits' => $balance['credits']];
            } elseif ($token !== null && ! $transportFresh && $priorMemo !== null && $key === $priorKey
                && $priorMemo['token'] === $token && $priorMemo['writes'] === $writesAfter && $priorMemo['reset_id'] === $agent->reset_id
                && microtime(true) - $priorMemo['fetched_at'] <= $maxReuse) {
                self::$lockedFleetMemo[$key] = $priorMemo;
            }

            return $balance['credits'];
        } catch (\Throwable $exception) {
            self::forgetLockedFleet($agent->id);
            throw $exception;
        }
    }

    private function discover(Agent $agent, array $fleet): void
    {
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

    public function refreshShip(Agent $agent, string $symbol, bool $fresh = false): Ship
    {
        return $this->saveFullShip($agent, $this->api->ship($agent, $symbol, fresh: $fresh), 'ship_get');
    }

    public function ships(Agent $agent, bool $fresh = false): array
    {
        $fleet = $this->api->ships($agent, fresh: $fresh);
        foreach ($fleet as $ship) {
            $this->saveFullShip($agent, $ship, 'fleet_get');
        }

        return $fleet;
    }

    private function saveFullShip(Agent $agent, array $snapshot, string $source): Ship
    {
        return DB::transaction(function () use ($agent, $snapshot, $source) {
            $ship = $this->saveShip($agent, $snapshot, full: false);
            $chain = app(StateChain::class);
            $chain->recordFullShip($agent, $snapshot, $ship->observed_at->toImmutable(), $source);
            $chain->releaseRecoveryFuelHoldIfEmpty($agent, $ship->symbol, $snapshot);

            return $ship;
        });
    }

    public function saveShip(Agent $agent, array $data, bool $full = true): Ship
    {
        if ($full && $agent->reset_id) {
            return $this->saveFullShip($agent, $data, 'ship_get');
        }

        return Ship::updateOrCreate(['agent_id' => $agent->id, 'symbol' => $data['symbol']], ['snapshot' => $data, 'observed_at' => now()]);
    }

    public function invalidateMarket(Agent $agent, string $symbol, int $action): void
    {
        $this->invalidateQuote($agent, 'market', $symbol, $action);
    }

    public function invalidateShipyard(Agent $agent, string $symbol, int $action): void
    {
        $this->invalidateQuote($agent, 'shipyard', $symbol, $action);
    }

    private function invalidateQuote(Agent $agent, string $kind, string $symbol, int $action): void
    {
        DB::transaction(function () use ($agent, $kind, $symbol, $action) {
            $last = DB::table('observation_generations')->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                ->where('kind', $kind)->where('symbol', $symbol)->value('last_action_id');
            if ($last !== $action) {
                app(StateChain::class)->bumpGeneration($agent, $kind, $symbol, $action);
            }
            $quote = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', $kind)->where('symbol', $symbol)->first();
            if ($quote) {
                $quote->update(['data' => array_merge($quote->data, ['invalidated_by_action' => $action])]);
            }
        });
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
            if (in_array($kind, ['market', 'shipyard'], true)) {
                $data['observation_generation'] = app(StateChain::class)->generation($agent, $kind, $symbol);
            }
            $values = ['reset_id' => $agent->reset_id, 'data' => $data, 'observed_at' => now(), 'source' => $source, 'observer_ship' => $observer];
            $key = ['agent_id' => $agent->id, 'kind' => $kind, 'symbol' => $symbol];
            $history = ObservationHistory::create(array_merge($key, $values));
            if (in_array($kind, ['market', 'shipyard'], true)) {
                $values['data']['observation_version'] = $history->id;
            }
            if ($kind === 'market') {
                foreach (array_unique(array_column($data['tradeGoods'] ?? [], 'symbol')) as $good) {
                    app(CorridorBook::class)->recordObservation($agent, $symbol, $good, $history->id);
                }
            }

            return GameObservation::updateOrCreate($key, $values);
        });
    }
}
