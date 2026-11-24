<?php

namespace App\Gameplay;

use App\Enums\RunState;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\ObservationHistory;
use App\Models\ReconMission;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Minute-0 probe bootstrap: bounded SHIP_PROBE purchases under opt-in C0 playbook authority. */
class CampaignProbeBootstrap
{
    public const PURCHASE_TYPES = ['SHIP_PROBE', 'SHIP_LIGHT_HAULER'];

    public const STRATEGIES = ['buy_probe', 'buy_hauler'];

    public const SHIPYARD_BACKOFF_MINUTES = 30;

    public const SHIPYARD_OFFER_FRESH_MINUTES = 30;

    public const MAX_SHIPYARD_READS_PER_PASS = 2;

    public static function policy(CampaignGrant $grant, CampaignEpisode $episode): ?array
    {
        if ($grant->isDraining() || $grant->slot !== 1 || $grant->arm() || ! $grant->available() || $episode->closed_at
            || $episode->campaign_grant_id !== $grant->id || CampaignGrant::pendingFor($grant->user_id, $grant->resolvedAgentSymbol())->exists() || ! hash_equals($grant->revision_hash, $grant->revision()->content_hash)
            || app(CampaignSafety::class)->windDown($episode)) {
            return null;
        }
        $policy = $grant->revision()->body['probes'] ?? null;
        if (! is_array($policy)) {
            return null;
        }

        return [
            'target' => $policy['target'],
            'max_price' => $policy['max_price'],
            'min_working_capital' => $policy['min_working_capital'],
            'pool' => (bool) ($policy['pool'] ?? true),
            'mesh' => $policy['mesh'] ?? null,
        ];
    }

    public function ownedProbeCount(Agent $agent): int
    {
        $fleet = app(CampaignFleet::class);

        return Ship::where('agent_id', $agent->id)->get()->filter(fn ($ship) => $fleet->isProbe($ship->snapshot))->count();
    }

    public function probeTarget(CampaignEpisode $episode, Agent $agent, array $policy): array
    {
        if ($policy['mesh'] ?? null) {
            $mesh = app(CampaignMesh::class)->target($episode, $agent);

            return [$mesh['pool_probe_count'], $mesh['pool_probe_target']];
        }

        return [$this->ownedProbeCount($agent), $policy['target']];
    }

    public function inFlightProbePurchases(Agent $agent, CampaignEpisode $episode, ?int $ignoreRunId = null): int
    {
        return $this->reservations($agent, $ignoreRunId, undeliveredType: 'SHIP_PROBE')->count();
    }

    public function actions(Agent $agent): Builder
    {
        return ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('command', 'buy_ship')->whereIn('arguments->shipType', self::PURCHASE_TYPES);
    }

    public function activeRuns(Agent $agent): Builder
    {
        return AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->whereIn('strategy', self::STRATEGIES)->whereNotNull('active_agent_id');
    }

    public static function deliveredShip(ShipAction $action): ?string
    {
        return $action->outcome['delivered_ship'] ?? $action->outcome['bootstrap_delivered_probe'] ?? null;
    }

    private function outstanding(Agent $agent): Collection
    {
        return $this->actions($agent)->whereIn('state', ['pending', 'sending', 'uncertain', 'acknowledged'])->orderBy('id')->get()
            ->reject(fn (ShipAction $action) => isset($action->outcome['confirmed_not_sent'])
                || ($action->state->value === 'acknowledged' && self::deliveredShip($action)));
    }

    public function reservations(Agent $agent, ?int $ignoreRunId = null, ?int $ignoreActionId = null, ?string $undeliveredType = null): Collection
    {
        $runs = $this->activeRuns($agent)->when($ignoreRunId, fn ($query) => $query->whereKeyNot($ignoreRunId))->get()->keyBy('id');
        $deliveredRuns = $undeliveredType ? $this->actions($agent)->whereIn('automation_run_id', $runs->keys())->get()
            ->filter(fn (ShipAction $action) => self::deliveredShip($action))->pluck('automation_run_id')->all() : [];
        $result = collect();
        foreach ($runs as $run) {
            $type = $run->strategy === 'buy_hauler' ? 'SHIP_LIGHT_HAULER' : 'SHIP_PROBE';
            if ($undeliveredType && ($type !== $undeliveredType || in_array($run->id, $deliveredRuns, true) || ! empty($run->totals['purchased_ships']))) {
                continue;
            }
            $result->put('run:'.$run->id, ['run_id' => $run->id, 'type' => $type]);
        }
        if ($undeliveredType === null) {
            $positioning = AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                ->whereIn('plan->mesh_work', ['yard', 'purchase'])->when($ignoreRunId, fn ($query) => $query->whereKeyNot($ignoreRunId))
                ->where(fn ($query) => $query->where('state', 'preview')->orWhereNotNull('active_agent_id'))->get();
            foreach ($positioning as $run) {
                $result->put('run:'.$run->id, ['run_id' => $run->id, 'type' => 'SHIP_PROBE']);
            }
        }
        foreach ($this->outstanding($agent) as $action) {
            $type = $action->arguments['shipType'];
            if ($action->id === $ignoreActionId || ($ignoreRunId && $action->automation_run_id === $ignoreRunId)
                || ($undeliveredType && ($type !== $undeliveredType || self::deliveredShip($action)))) {
                continue;
            }
            $runId = $action->automation_run_id;
            $key = $runs->has($runId) ? 'run:'.$runId : 'action:'.$action->id;
            $result->put($key, ['run_id' => $runId, 'action_id' => $action->id, 'type' => $type]);
        }

        return $result;
    }

    public function assertSlot(Agent $agent, ?int $ignoreRunId = null, ?int $ignoreActionId = null): void
    {
        if ($blocker = $this->reservations($agent, $ignoreRunId, $ignoreActionId)->first()) {
            throw new GameException('Shared ship purchase slot is held by '.(isset($blocker['action_id']) ? 'action '.$blocker['action_id'] : 'run '.$blocker['run_id']).'.');
        }
    }

    public static function matchesType(array $ship, string $type): bool
    {
        return $type === 'SHIP_PROBE'
            ? app(CampaignFleet::class)->isProbe($ship)
            : (($ship['type'] ?? $ship['registration']['type'] ?? null) === 'SHIP_LIGHT_HAULER'
                || ($ship['frame']['symbol'] ?? null) === 'FRAME_LIGHT_FREIGHTER');
    }

    public function plausibleUnattributed(Agent $agent, array $ship): bool
    {
        $attributed = $this->actions($agent)->get()->map(self::deliveredShip(...))->filter();
        if ($attributed->contains($ship['symbol'])) {
            return false;
        }

        return $this->outstanding($agent)->contains(fn (ShipAction $action) => self::matchesType($ship, $action->arguments['shipType'])
            && ! in_array($ship['symbol'], $action->before_state['estimate']['fleet_symbols'] ?? [], true));
    }

    public function reconcileDeliveries(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, ?float $deadline = null): void
    {
        $acknowledged = $this->outstanding($agent)->where('state.value', 'acknowledged');
        if ($acknowledged->isEmpty()) {
            return;
        }
        $this->attributeDeliveries($agent, $acknowledged);
        $acknowledged = $this->outstanding($agent)->where('state.value', 'acknowledged');
        if ($acknowledged->isEmpty()) {
            return;
        }
        $this->refreshAcknowledgedShipyards($grant, $episode, $agent, $deadline);
        $this->attributeDeliveries($agent, $acknowledged);
    }

    private function attributeDeliveries(Agent $agent, Collection $actions): void
    {
        DB::transaction(function () use ($agent, $actions) {
            $fleet = Ship::where('agent_id', $agent->id)->orderBy('symbol')->get()->keyBy('symbol');
            $purchases = $this->actions($agent)->get();
            $outstanding = $this->outstanding($agent);
            $attributed = $purchases->map(self::deliveredShip(...))->filter()->all();
            $usedTransactions = $purchases->filter(fn (ShipAction $action) => self::deliveredShip($action))
                ->map(fn (ShipAction $action) => $action->outcome['delivery_transaction'] ?? $action->outcome['bootstrap_delivery_transaction']
                ?? $action->outcome['response']['transaction'] ?? null)->filter()->map($this->transactionKey(...))->all();
            $matches = [];
            foreach ($actions as $action) {
                $baseline = $action->before_state['estimate']['fleet_symbols'] ?? null;
                $yard = $action->arguments['waypointSymbol'] ?? null;
                if (! is_array($baseline) || ! is_string($yard) || ! $action->sent_at) {
                    continue;
                }
                $type = $action->arguments['shipType'];
                $outcome = $action->outcome ?? [];
                $field = $type === 'SHIP_PROBE' ? 'probe_delivery_candidates' : 'hauler_delivery_candidates';
                $reconciled = collect($outcome['fleet'] ?? [])->keyBy('symbol');
                $plausible = $fleet->filter(fn (Ship $ship) => self::matchesType($ship->snapshot, $type)
                    && ! in_array($ship->symbol, $baseline, true) && ! in_array($ship->symbol, $attributed, true));
                $candidates = $plausible->filter(fn (Ship $ship) => $ship->snapshot['nav']['waypointSymbol'] === $yard
                    || ($reconciled->get($ship->symbol)['nav']['waypointSymbol'] ?? null) === $yard
                    || in_array($ship->symbol, $outcome[$field] ?? [], true))->keys()->all();
                $evidence = array_values(array_unique([...($outcome[$field] ?? []), ...$candidates]));
                if ($evidence !== ($outcome[$field] ?? [])) {
                    $action->update(['outcome' => array_replace($outcome, [$field => $evidence])]);
                }
                $transactions = collect($this->transactions($agent, $action))->filter(fn ($tx) => $this->matchingTransaction($agent, $action, [$tx]) !== null)
                    ->unique($this->transactionKey(...))->reject(fn (array $tx) => in_array($this->transactionKey($tx), $usedTransactions, true));
                $matches[$action->id] = [$action, $candidates, $transactions];
            }
            foreach ($matches as [$action, $candidates, $transactions]) {
                if (count($candidates) !== 1 || $transactions->count() !== 1) {
                    continue;
                }
                $symbol = $candidates[0];
                $transaction = $transactions->first();
                $key = $this->transactionKey($transaction);
                foreach ($matches as [$other, $otherShips, $otherTransactions]) {
                    if ($other->id !== $action->id && (in_array($symbol, $otherShips, true)
                            || $otherTransactions->contains(fn (array $tx) => $this->transactionKey($tx) === $key))) {
                        continue 2;
                    }
                }
                $sameType = $outstanding->filter(fn (ShipAction $other) => $other->id !== $action->id
                    && $other->arguments['shipType'] === $action->arguments['shipType']);
                $ambiguousTransaction = $sameType
                    ->filter(fn (ShipAction $other) => ($other->arguments['waypointSymbol'] ?? null) === $action->arguments['waypointSymbol'])
                    ->contains(fn (ShipAction $other) => $this->matchingTransaction($agent, $other, [$transaction], includeUnsent: true) !== null);
                // Cross-yard ambiguity holds the slot for probes and haulers until the ship is resolved.
                // campaign:release-probe-purchase and campaign:release-hauler-purchase require an acknowledged
                // action with no remaining plausible unattributed ship of the same type.
                $ambiguousShip = $sameType->contains(function (ShipAction $other) use ($symbol) {
                    $baseline = $other->before_state['estimate']['fleet_symbols'] ?? null;

                    return ! is_array($baseline) || ! in_array($symbol, $baseline, true);
                });
                if ($ambiguousTransaction || $ambiguousShip) {
                    continue;
                }
                $pin = $action->arguments['offer_configuration_hash'] ?? null;
                $configuration = $action->before_state['estimate']['configuration'] ?? null;
                $mismatch = $pin !== null
                    ? $pin !== ShipCommandExecutor::configurationHash($fleet->get($symbol)->snapshot)
                    : (is_array($configuration) && ShipCommandExecutor::configurationHash($configuration) !== ShipCommandExecutor::configurationHash($fleet->get($symbol)->snapshot));
                $evidence = $action->arguments['shipType'] === 'SHIP_PROBE'
                    ? ['bootstrap_delivered_probe' => $symbol, 'bootstrap_delivery_transaction' => $transaction]
                    : ['delivered_ship' => $symbol, 'delivery_transaction' => $transaction];
                $evidence['delivery_attributed_at'] = now()->utc()->toIso8601String();
                if ($mismatch) {
                    $evidence['configuration_mismatch'] = true;
                }
                $action->update(['outcome' => array_replace($action->outcome ?? [], $evidence), 'review_required' => $mismatch || $action->review_required]);
                $this->recordDelivery($action);
            }
        });
    }

    private function transactionKey(array $tx): string
    {
        return json_encode([$tx['agentSymbol'] ?? null, $tx['waypointSymbol'] ?? null, $tx['shipType'] ?? $tx['shipSymbol'] ?? null,
            $tx['timestamp'] ?? null,
            $tx['price'] ?? $tx['totalPrice'] ?? null]);
    }

    private function transactions(Agent $agent, ShipAction $action): array
    {
        $yard = $action->arguments['waypointSymbol'] ?? null;
        $transactions = $action->outcome['transactions'] ?? [];
        foreach ([GameObservation::class, ObservationHistory::class] as $model) {
            foreach ($model::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                ->where('kind', 'shipyard')->where('symbol', $yard)->cursor() as $observation) {
                $transactions = array_merge($transactions, $observation->data['transactions'] ?? []);
            }
        }

        return $transactions;
    }

    public function linkage(ShipAction $action): ?array
    {
        $episodeId = $action->outcome['campaign_episode_id'] ?? $action->arguments['campaign_episode_id'] ?? $action->before_state['campaign_episode_id'] ?? null;
        if (! $action->automation_run_id && ! $episodeId && $action->origin === 'manual') {
            return null;
        }
        $run = AutomationRun::find($action->automation_run_id);
        $episode = $run ? CampaignEpisode::find($run->campaign_episode_id) : null;
        if (! $episode || ($episodeId && (int) $episodeId !== $episode->id)
            || $run->agent_id !== $action->agent_id || $run->reset_id !== $action->reset_id
            || $episode->agent_id !== $action->agent_id || $episode->reset_id !== $action->reset_id
            || ($action->arguments['shipType'] === 'SHIP_PROBE' ? $run->strategy !== 'buy_probe' : ($run->strategy !== 'buy_hauler' || $run->plan_version !== 7))) {
            throw new GameException('Campaign purchase linkage is invalid.');
        }
        $grant = CampaignGrant::find($episode->campaign_grant_id)
            ?? throw new GameException('Campaign grant not found; the purchase cannot be released.');

        $agent = Agent::find($action->agent_id);
        if (! $agent || $grant->user_id !== $agent->user_id || $grant->resolvedAgentSymbol() !== $agent->name || $grant->arm()) {
            throw new GameException('Campaign purchase grant belongs to a different operator or agent.');
        }

        return [$run, $episode, $grant];
    }

    public function recordDelivery(ShipAction $action): void
    {
        $ship = self::deliveredShip($action);
        if (! $ship || ! empty($action->outcome['configuration_mismatch']) || ! $action->automation_run_id) {
            return;
        }
        try {
            $linked = $this->linkage($action);
        } catch (GameException) {
            return;
        }
        [$run, $episode, $grant] = $linked;
        $kind = $run->strategy === 'buy_probe' ? 'probe_purchased' : 'hauler_purchased';
        $events = CampaignEvent::where('campaign_episode_id', $episode->id)->where('kind', $kind);
        if ((clone $events)->where('data->action_id', $action->id)->exists()
            || ($kind === 'probe_purchased' && (clone $events)->where('data->run_id', $run->id)->where('data->ship', $ship)->whereNull('data->action_id')->exists()
                && ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->where('state', 'confirmed')->count() === 1)) {
            return;
        }
        $tx = $action->outcome['delivery_transaction'] ?? $action->outcome['bootstrap_delivery_transaction'] ?? $action->outcome['response']['transaction'] ?? [];

        app(CampaignControl::class)->event($grant, $episode, $kind, [
            'run_id' => $run->id, 'action_id' => $action->id, 'ship' => $ship, 'shipyard' => $action->arguments['waypointSymbol'],
            'price' => $action->outcome['transaction_total'] ?? $tx['price'] ?? $tx['totalPrice'] ?? null,
            'delivered_at' => $tx['timestamp'] ?? $action->settled_at?->toIso8601String(),
        ]);
    }

    private function matchingTransaction(Agent $agent, ShipAction $action, array $transactions, bool $includeUnsent = false): ?array
    {
        $shipyard = $action->arguments['waypointSymbol'] ?? null;
        $earliest = $action->sent_at ?? ($includeUnsent ? $action->created_at : null);
        if (! is_string($shipyard) || ! $earliest) {
            return null;
        }

        return collect($transactions)->first(function ($tx) use ($agent, $shipyard, $action, $earliest) {
            if (! is_array($tx)
                || ($tx['waypointSymbol'] ?? null) !== $shipyard
                || (($tx['shipType'] ?? $tx['shipSymbol'] ?? null) !== $action->arguments['shipType'])
                || ($tx['agentSymbol'] ?? null) !== $agent->name
                || ! is_string($tx['timestamp'] ?? null)
                || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/', $tx['timestamp'])) {
                return false;
            }
            if (! checkdate((int) substr($tx['timestamp'], 5, 2), (int) substr($tx['timestamp'], 8, 2), (int) substr($tx['timestamp'], 0, 4))
                || (int) substr($tx['timestamp'], 11, 2) > 23
                || (int) substr($tx['timestamp'], 14, 2) > 59
                || (int) substr($tx['timestamp'], 17, 2) > 59) {
                return false;
            }
            try {
                return CarbonImmutable::parse($tx['timestamp'])->gte($earliest->copy()->subSeconds(5));
            } catch (\Throwable) {
                return false;
            }
        });
    }

    public function releasePurchase(ShipAction $action, User $user, bool $dryRun = false, string $type = 'SHIP_PROBE'): array
    {
        return app(HostLock::class)->scoped('agent-'.$action->agent_id, fn () => DB::transaction(function () use ($action, $user, $dryRun, $type) {
            $action->refresh();
            $agent = $user->agents()->find($action->agent_id);
            if (! $agent) {
                throw new GameException('This operator does not operate the action agent.');
            }
            if ($agent->isArchived() || ! in_array($type, self::PURCHASE_TYPES, true) || $action->reset_id !== $agent->reset_id || $action->command !== 'buy_ship'
                || ($action->arguments['shipType'] ?? null) !== $type || $action->state->value !== 'acknowledged') {
                throw new GameException('Only an acknowledged '.$type.' buy_ship action in the current reset can be released.');
            }
            if (isset($action->outcome['confirmed_not_sent'])) {
                throw new GameException('This purchase was already released.');
            }
            if (! empty($action->outcome['delivered_ship']) || ! empty($action->outcome['bootstrap_delivered_probe'])) {
                throw new GameException('The purchase already has delivery evidence.');
            }
            // Successful write receipt markers are stored before validation, even when sanitized data is empty.
            $outcome = $action->outcome ?? [];
            if (array_key_exists('response', $outcome) || array_key_exists('body_sha256', $outcome)
                || array_key_exists('response_received_at', $outcome) || array_key_exists('http_status', $outcome)
                || array_key_exists('response_validated', $outcome)) {
                throw new GameException('The slot stays held because stored receipt evidence shows the purchase may have happened. Syncing cannot clear this evidence.');
            }
            $beforeFleet = $action->before_state['estimate']['fleet_symbols'] ?? null;
            $shipyard = $action->arguments['waypointSymbol'] ?? null;
            if (! is_array($beforeFleet) || ! is_string($shipyard) || ! $action->sent_at) {
                throw new GameException('Purchase baseline is incomplete; cannot rule out a delivery. Sync the agent first.');
            }
            if ($this->matchingTransaction($agent, $action, $this->transactions($agent, $action))) {
                throw new GameException('A matching shipyard transaction was recorded; the slot stays held because stored evidence shows the purchase may have happened. Syncing cannot clear this evidence.');
            }
            $attributed = $this->actions($agent)->get()->map(self::deliveredShip(...))->filter()->all();
            $candidates = array_merge($action->outcome['probe_delivery_candidates'] ?? [], $action->outcome['hauler_delivery_candidates'] ?? []);
            foreach ($action->outcome['fleet'] ?? [] as $snapshot) {
                if (isset($snapshot['symbol']) && self::matchesType($snapshot, $type)) {
                    $candidates[] = $snapshot['symbol'];
                }
            }
            foreach (Ship::where('agent_id', $agent->id)->get() as $ship) {
                if (self::matchesType($ship->snapshot, $type)) {
                    $candidates[] = $ship->symbol;
                }
            }
            $name = $type === 'SHIP_PROBE' ? 'probe' : 'hauler';
            if ($new = array_values(array_diff($candidates, $beforeFleet, $attributed))[0] ?? null) {
                throw new GameException('Unattributed new '.$name.' '.$new.' may be this delivery. Sync the agent first.');
            }
            $link = $this->linkage($action);
            $confirmation = ['user_id' => $user->id, 'at' => now()->toIso8601String(), 'reason' => 'operator release'];
            if (! $dryRun) {
                $action->update(['outcome' => array_merge($action->outcome ?? [], ['confirmed_not_sent' => $confirmation])]);
                if ($link) {
                    [, $episode, $grant] = $link;
                    app(CampaignControl::class)->event($grant, $episode, $name.'_purchase_released', ['action_id' => $action->id] + $confirmation);
                }
            }

            return ['action_id' => $action->id, 'released' => ! $dryRun, 'dry_run' => $dryRun, 'confirmed_not_sent' => $confirmation];
        }));
    }

    public function effectiveReserve(Agent $agent, CampaignGrant $grant, array $policy, ?int $ignoreRunId = null): int
    {
        return array_sum($this->reserveParts($agent, $grant, $policy, $ignoreRunId));
    }

    public function reserveParts(Agent $agent, CampaignGrant $grant, array $policy, ?int $ignoreRunId = null): array
    {
        $commitments = AutomationRun::where('active_agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('strategy', '!=', 'gate_recon')
            ->when($ignoreRunId !== null, fn ($query) => $query->where('id', '!=', $ignoreRunId))
            ->get()
            ->sum(function ($run) use ($agent) {
                if ($run->strategy === 'trade' && ($run->totals['phase'] ?? null) === 'buy') {
                    return max(0, (int) ($run->plan['max_spend'] ?? 0) - (int) ($run->totals['spending'] ?? 0));
                }
                $ship = Ship::where('agent_id', $agent->id)->where('symbol', $run->ship_symbol)->first()?->snapshot;
                if (($ship['cargo']['units'] ?? 0) < 1 || ! isset($run->plan['market'])) {
                    return 0;
                }
                try {
                    return app(CampaignTrade::class)->fuelReserve($agent, $run->ship_symbol, $run->plan['market']);
                } catch (GameException $e) {
                    // As with manual spending, stale delivery quotes preserve the approved cash ceilings.
                    $planned = $run->plan['campaign_trade'] ?? [];
                    if (! isset($planned['fuel_reserve']) && ! isset($planned['fuel_safety_ceiling'])
                        && ! isset($run->plan['route']['fuel_ceiling'])) {
                        throw new GameException('Ship '.$run->ship_symbol.' carries cargo and its delivery fuel reserve cannot be verified right now ('.$e->getMessage().'). Retry once its fuel quotes are re-read.');
                    }

                    return (int) max($planned['fuel_reserve'] ?? 0, $planned['fuel_safety_ceiling'] ?? 0, $run->plan['route']['fuel_ceiling'] ?? 0);
                }
            });

        return ['grant' => $grant->limits['reserve_credits'], 'working_capital' => $policy['min_working_capital'],
            'commitments' => $commitments, 'recon_return' => ReconMission::returnExposure($agent)];
    }

    /**
     * Plans a single bounded buy_probe preview if the agent is below target probes and an eligible
     * idle ship can safely reach a SHIP_PROBE shipyard within price and working-capital bounds.
     *
     * @param  list<string>  $busyShips
     */
    public function plan(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $busyShips, array &$considered, ?float $deadline = null, string $type = 'SHIP_PROBE', ?array &$meshReads = null, ?array &$purchaseYards = null): ?AutomationRun
    {
        if (microtime(true) >= ($deadline ?? INF)) {
            return null;
        }
        $hauler = $type === 'SHIP_LIGHT_HAULER';
        $strategy = $hauler ? 'buy_hauler' : 'buy_probe';
        $policy = $this->purchasePolicy($grant, $episode, $agent, $type);
        $saturation = app(CampaignSaturation::class);
        if (! $hauler && isset($policy['mesh']['saturation'])) {
            $meshReads ??= [];
            $purchaseYards = [];

            return $this->planMesh($grant, $episode, $agent, $policy, $busyShips, $considered, $deadline ?? INF, $meshReads, $purchaseYards);
        }
        if ($hauler) {
            $evaluation = $saturation->evaluate($grant, $episode, $agent, offerIndependentOnly: true);
            if (! $evaluation['enabled']) {
                return null;
            }
            if (! $policy || ! $evaluation['can_purchase']) {
                $saturation->record($grant, $episode, $evaluation);
                $considered[] = ['strategy' => $strategy, 'gates' => $evaluation['gates'], 'hold' => $evaluation['hold']];

                return null;
            }
        } elseif ($policy) {
            [$owned, $target] = $this->probeTarget($episode, $agent, $policy);
            if ($owned + $this->inFlightProbePurchases($agent, $episode) >= $target) {
                return null;
            }
        }
        if (! $policy || $this->reservations($agent)->isNotEmpty() || microtime(true) >= ($deadline ?? INF)) {
            return null;
        }
        $fleet = app(CampaignFleet::class);
        $command = $fleet->role($episode, 'command');
        $probes = $fleet->probes($episode);
        $system = $command['nav']['systemSymbol'] ?? ($probes[0]['nav']['systemSymbol'] ?? null);
        if (! $system) {
            $considered[] = ['strategy' => $strategy, 'rejected' => 'No home system ship available for '.$type.' purchase.'];

            return null;
        }

        $eligibleShips = $this->eligibleShips($agent, $episode, $command, $probes, $busyShips, $system);
        if ($eligibleShips === []) {
            return null;
        }

        $reserve = $this->effectiveReserve($agent, $grant, $policy);
        if ($agent->credits <= $reserve) {
            $considered[] = ['strategy' => $strategy, 'rejected' => 'Credits do not clear working-capital reserve of '.$reserve.' CR.'];

            return null;
        }

        $shipyards = $this->discoverShipyards($grant, $episode, $agent, $system, $eligibleShips, $deadline, $type, $meshReads);
        if (! $hauler && $shipyards === []) {
            $considered[] = ['strategy' => $strategy, 'rejected' => 'No '.$type.' shipyard observed yet in '.$system.'.'];

            return null;
        }

        $points = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', 'waypoint')->get()->keyBy('symbol');
        $candidates = $this->rankCandidates($episode, $agent, $policy, $reserve, $shipyards, $eligibleShips, $points, $considered, $type, $deadline);
        $fuelByYard = [];
        foreach ($candidates as $candidate) {
            if (microtime(true) >= ($deadline ?? INF)) {
                return null;
            }
            try {
                $ship = $candidate['ship'];
                $yard = $candidate['shipyard'];
                $run = null;
                $selected = null;
                if ($hauler) {
                    $offer = $this->freshHaulerOffer($shipyards[$yard]);
                    $route = $this->purchaseRoute($agent, $ship, $yard, $reserve);
                    $fuel = $route['fuel_ceiling'];
                    $fuelByYard[$yard] = min($fuelByYard[$yard] ?? $fuel, $fuel);
                    if ($offer && $agent->credits - $reserve < $offer['purchasePrice'] + $fuel) {
                        $considered[] = ['strategy' => $strategy, 'ship' => $ship['symbol'], 'shipyard' => $yard,
                            'rejected' => 'Observed '.$type.' price and fuel breach the working-capital reserve.'];

                        continue;
                    }
                }
                if (CampaignLocalEarn::policy($grant, $episode) && $deadline !== null) {
                    app(CampaignLocalEarn::class)->reserve($deadline);
                }
                $limits = [
                    'reserve' => $reserve,
                    'max_loss' => $policy['max_price'],
                    'max_spend' => min(max(0, $agent->credits - $reserve), $policy['max_price']),
                    'minutes' => min($grant->limits['run_max_minutes'], max(1, (int) now()->diffInMinutes($episode->next_reset_at, false))),
                    'cycles' => 1,
                ];
                if ($policy['mesh'] ?? $hauler) {
                    $leg = $ship['nav']['waypointSymbol'] === $yard ? null
                        : ($hauler ? $route['outbound'] : app(FuelPlanner::class)->leg($agent, $ship, $yard, $reserve));
                    if ($leg && $leg['estimated_seconds'] + 180 > $limits['minutes'] * 60 && app(FuelPlanner::class)->fuelFree($ship)) {
                        if ($hauler) {
                            // A complete over-cap remote quote blocks long scouts until it ages past SHIPYARD_OFFER_FRESH_MINUTES.
                            $selected = $saturation->assertPurchase($grant, $episode, $agent, $yard, $offer, fuel: $fuel, deadline: $deadline);
                        }
                        $run = app(AutomationRunner::class)->previewMeshScout($agent, $ship, $yard, $limits, $episode, 'shipyard');
                    }
                }

                $run ??= $hauler
                    ? app(AutomationRunner::class)->previewHaulerPurchase($agent, $ship['symbol'], $yard, $limits, $episode, $deadline, $selected)
                    : app(AutomationRunner::class)->previewProbePurchase($agent, $ship['symbol'], $yard, $limits, $episode);
                if ($selected !== null) {
                    $saturation->record($grant, $episode, $selected);
                    $considered[] = ['strategy' => $strategy, 'gates' => $selected['gates'], 'hold' => $selected['hold']];
                }

                return $run;
            } catch (GameException $exception) {
                if ($exception instanceof LocalEarnBudget || $exception->retryAfter > 0) {
                    throw $exception;
                }
                $considered[] = ['strategy' => $strategy, 'ship' => $candidate['ship']['symbol'], 'shipyard' => $candidate['shipyard'], 'rejected' => $exception->getMessage()];
            }
        }

        if ($hauler && microtime(true) < ($deadline ?? INF)) {
            $directory = $points->filter(fn (GameObservation $point) => ($point->data['systemSymbol'] ?? null) === $system
                && in_array('SHIPYARD', array_column($point->data['traits'] ?? [], 'symbol'), true))->keys();
            $observed = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                ->where('kind', 'shipyard')->whereIn('symbol', $directory)->pluck('symbol');
            $evaluation = $saturation->evaluate($grant, $episode, $agent,
                candidateYards: array_unique(array_column($candidates, 'shipyard')), deadline: $deadline, fuelByYard: $fuelByYard);
            if ($evaluation['search_complete']) {
                if ($directory->diff($observed)->isEmpty()) {
                    $saturation->record($grant, $episode, $evaluation);
                }
                $considered[] = ['strategy' => $strategy, 'gates' => $evaluation['gates'], 'hold' => $evaluation['hold']];
            }
        }

        return null;
    }

    private function planMesh(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $policy, array $busy,
        array &$considered, float $deadline, array &$reads, array &$purchaseYards): ?AutomationRun
    {
        $selector = app(ProbeYardSelector::class);
        $mesh = app(CampaignMesh::class);
        $mesh->clearPurchaseHold($episode);
        $model = $mesh->model($episode, $agent);
        [$owned, $target] = $this->probeTarget($episode, $agent, $policy);
        if ($owned + $this->inFlightProbePurchases($agent, $episode) >= $target
            || $this->reservations($agent)->isNotEmpty() || ! $model->system
            || $selector->cutoff($episode, $grant, $policy)->lte(now())) {
            return null;
        }
        $governor = app(MeshGovernor::class);
        $status = $governor->evaluate($agent, $episode);
        if (! in_array($status['mode'], ['normal', 'sampling'], true)) {
            $considered[] = ['strategy' => 'buy_probe', 'hold' => $status['reason']];

            return null;
        }
        $this->discoverShipyards($grant, $episode, $agent, $model->system, [], $deadline, 'SHIP_PROBE', $reads);
        if ($status['mode'] !== 'normal' || microtime(true) >= $deadline) {
            return null;
        }
        $this->assertPurchaseBudget($agent);
        $runner = app(AutomationRunner::class);
        $tried = [];
        $yield = null;
        $poll = true;
        while (microtime(true) < $deadline) {
            $agent->refresh();
            $episode->refresh();
            if (! self::policy($grant->refresh(), $episode) || $this->reservations($agent)->isNotEmpty()) {
                return null;
            }
            $reserve = $this->effectiveReserve($agent, $grant, $policy);
            $ranked = $selector->rank($episode, $agent, $policy, $busy, $reserve, $deadline, $considered, $purchaseYards);
            if ($poll && $purchaseYards !== []) {
                $poll = false;
                $refreshed = app(MeshPoller::class)->refresh($episode, $agent, $reads, $deadline, phase: 'purchase', purchaseYards: $purchaseYards);
                if ($refreshed['successful'] !== []) {
                    continue;
                }
            }
            $candidate = null;
            foreach ($ranked as $entry) {
                $key = $entry['yard'].':'.$entry['ship']['symbol'];
                if (! isset($tried[$key])) {
                    $candidate = $entry;
                    $tried[$key] = true;
                    break;
                }
            }
            if ($candidate === null || microtime(true) >= $deadline) {
                return null;
            }
            if ($candidate['tier'] === 2 && ! collect($ranked)->contains(fn (array $entry) => $entry['tier'] === 1
                && ! isset($tried[$entry['yard'].':'.$entry['ship']['symbol']]))
                && isset($grant->revision()->body['fleet']['haulers'])) {
                $yield ??= app(CampaignSaturation::class)->evaluate($grant, $episode, $agent, deadline: $deadline);
                if ($yield['can_purchase']) {
                    $considered[] = ['strategy' => 'buy_probe', 'hold' => 'tier2_yields_to_hauler', 'hauler_capital' => $yield['gates']['G5']];
                    $purchaseYards = [];

                    return null;
                }
            }

            $ship = $candidate['ship'];
            $yard = $candidate['yard'];
            $limits = ['minutes' => $grant->limits['run_max_minutes'], 'reserve' => $grant->limits['reserve_credits']];
            $run = null;
            try {
                $started = $mesh->holdPurchase($episode, $ship['symbol'], function () use ($runner, $selector, $episode, $agent, $policy, $busy,
                    $reserve, $deadline, $candidate, $ship, $yard, $limits, $tried, $key, &$considered, &$run) {
                    $run = $candidate['position']
                        ? $runner->previewMeshScout($agent, $ship, $yard, $limits, $episode, 'shipyard')
                        : $runner->previewProbePurchase($agent, $ship['symbol'], $yard, $limits, $episode, $deadline);
                    if (! $candidate['position']) {
                        $ranked = $selector->rank($episode, $agent, $policy, $busy, $reserve, $deadline, $considered, ignoreRun: $run->id);
                        $winner = collect($ranked)->first(fn (array $entry) => ! $entry['recovery']
                            && ($key === $entry['yard'].':'.$entry['ship']['symbol'] || ! isset($tried[$entry['yard'].':'.$entry['ship']['symbol']])));
                        if (! $winner || $key !== $winner['yard'].':'.$winner['ship']['symbol']) {
                            throw new GameException('Fresh preview changed the ranked yard or purchaser.');
                        }
                    }

                    return $runner->startCampaign($agent, $run, $episode, $deadline);
                });
                if ($candidate['position']) {
                    $this->recordMeshYardAttempt($episode, $yard);
                }

                return $started;
            } catch (GameException $exception) {
                $considered[] = ['strategy' => $candidate['position'] ? 'scout' : 'buy_probe', 'ship' => $ship['symbol'], 'yard' => $yard,
                    'rejected' => $exception->getMessage()];
                if ($exception->uncertain || ($run && ! $mesh->invalidatePreview($agent, $run))) {
                    $purchaseYards = [];

                    return null;
                }
                if ($exception->retryAfter > 0) {
                    $purchaseYards = [];
                    throw $exception;
                }
                if ($exception->apiCode !== null || str_contains($exception->getMessage(), 'Shared ship purchase slot')
                    || str_contains($exception->getMessage(), 'campaign authority')) {
                    $purchaseYards = [];

                    return null;
                }
                if ($candidate['position'] && CampaignMesh::dispatchFailure($exception->getMessage())) {
                    $mesh->failure($episode, $ship['symbol'], $yard, $run?->id);
                    if ($candidate['recovery']) {
                        $this->recordMeshYardAttempt($episode, $yard);
                    }
                }
            }
        }

        return null;
    }

    private function recordMeshYardAttempt(CampaignEpisode $episode, string $yard): void
    {
        $state = $episode->refresh()->state ?? [];
        $state['mesh_yard_attempt_at'][$yard] = now()->timestamp;
        $episode->update(['state' => $state]);
    }

    private function purchasePolicy(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, string $type): ?array
    {
        if ($agent->id !== $episode->agent_id || $agent->reset_id !== $episode->reset_id) {
            return null;
        }

        return $type === 'SHIP_LIGHT_HAULER'
            ? app(CampaignHaulerPurchase::class)->policy($grant, $episode, $agent)
            : self::policy($grant, $episode);
    }

    public function assertPurchaseBudget(Agent $agent, int $additionalTraders = 0): void
    {
        if (RequestBudget::agentCaps() === []) {
            return;
        }

        $symbol = strtoupper($agent->name);
        $row = $this->traderBudgetEvidence($agent);
        if (isset($row['purchase_hold_reason']) || ! isset($row['formula_max_traders'])) {
            throw new GameException('Fleet purchase held for '.$symbol.': '.($row['purchase_hold_reason'] ?? 'missing_request_budget_ceiling').'.');
        }
        if (! $row['fleet_verified']) {
            throw new GameException('Fleet purchase held for '.$symbol.': missing verified fleet.');
        }
        $traders = $row['traders'] + $additionalTraders;
        if ($traders > $row['formula_max_traders']) {
            throw new GameException('Fleet purchase held for '.$symbol.': '.$traders.' traders exceed trader ceiling '.$row['formula_max_traders'].'.');
        }
    }

    public function traderBudgetEvidence(Agent $agent): array
    {
        $symbol = strtoupper($agent->name);
        $episodes = CampaignEpisode::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->pluck('id')->all();
        $until = now()->toImmutable();
        $row = app(FleetMetrics::class)->byAgent([
            ['agent_symbol' => $symbol, 'qualified_label' => $symbol.':C0', 'episodes' => $episodes],
        ], $until->subHours(6), $until)[$symbol] ?? [];

        $ships = Ship::where('agent_id', $agent->id)->get();
        $traded = AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('strategy', 'trade')->whereNotNull('started_at')->distinct()->pluck('ship_symbol')->flip();
        $fleet = app(CampaignFleet::class);
        $traders = $ships->filter(function (Ship $ship) use ($fleet, $traded) {
            $snapshot = $ship->snapshot;

            return ! $fleet->isProbe($snapshot) && ($traded->has($ship->symbol)
                || in_array($snapshot['registration']['role'] ?? null, ['COMMAND', 'HAULER', 'TRANSPORT'], true)
                || in_array($snapshot['frame']['symbol'] ?? null, ['FRAME_SHUTTLE', 'FRAME_LIGHT_FREIGHTER', 'FRAME_HEAVY_FREIGHTER', 'FRAME_BULK_FREIGHTER'], true));
        })->count();

        return $row + ['traders' => $traders, 'fleet_verified' => $ships->isNotEmpty()];
    }

    private function assertTarget(Agent $agent, CampaignEpisode $episode, array $policy, string $type, ?int $ignoreRunId): int
    {
        $hauler = $type === 'SHIP_LIGHT_HAULER';
        [$owned, $target] = $hauler
            ? [app(CampaignHaulerPurchase::class)->ownedHaulers($episode, $agent), $policy['max_haulers']]
            : $this->probeTarget($episode, $agent, $policy);
        if ($owned + $this->reservations($agent, $ignoreRunId, undeliveredType: $type)->count() >= $target) {
            throw new GameException(($hauler ? 'Hauler' : 'Probe').' target of '.$target.' is already satisfied or in flight.');
        }

        return $owned;
    }

    public function requiresHaulerMeshMovement(CampaignEpisode $episode, array $ship, string $yard): bool
    {
        return isset(CampaignMesh::policy($episode)['saturation'])
            && app(CampaignFleet::class)->isProbe($ship) && ($ship['nav']['waypointSymbol'] ?? null) !== $yard;
    }

    public function compose(Agent $agent, string $symbol, string $shipyard, array $limits, CampaignEpisode $episode, ?int $ignoreRunId = null, string $type = 'SHIP_PROBE', ?float $deadline = null): array
    {
        $grant = $episode->grant();
        $hauler = $type === 'SHIP_LIGHT_HAULER';
        if (microtime(true) >= ($deadline ?? INF)) {
            throw new RequestDeferred('Mesh purchase exceeded the pass deadline.', 5);
        }
        $label = $hauler ? 'Hauler' : 'Probe';
        $policy = $this->purchasePolicy($grant, $episode, $agent, $type);
        if (! $policy) {
            throw new GameException($label.' purchase is outside current C0 campaign authority.');
        }
        $meshPurchase = ! $hauler && isset($policy['mesh']['saturation']);
        if ($meshPurchase && (! RequestBudget::currentMeshAllowance() || app(ProbeYardSelector::class)->cutoff($episode, $grant, $policy)->lte(now()))) {
            throw new GameException('Mesh purchase lacks current admission or is outside the purchase cutoff.');
        }
        $this->assertSlot($agent, $ignoreRunId);
        $saturation = app(CampaignSaturation::class);
        if ($hauler) {
            $saturation->assertGates($grant, $episode, $saturation->evaluate($grant, $episode, $agent, ignoreRun: $ignoreRunId, offerIndependentOnly: true));
        } else {
            $this->assertPurchaseBudget($agent);
            $this->assertTarget($agent, $episode, $policy, $type, $ignoreRunId);
        }
        app(AgentSynchronizer::class)->sync($agent, false, freshFleet: $meshPurchase || RequestBudget::currentMeshAllowance() !== null);
        if (! $hauler) {
            $this->assertPurchaseBudget($agent);
        }
        $ship = Ship::where('agent_id', $agent->id)->where('symbol', $symbol)->firstOrFail()->snapshot;
        if ($ship['nav']['status'] === 'IN_TRANSIT' || $ship['cargo']['units'] !== 0) {
            throw new GameException($label.' purchase requires a stationary ship with empty cargo.');
        }
        if (ReconMission::holds($agent, $symbol)) {
            throw new GameException('Ship is held by a gate-recon mission.');
        }
        if (Ship::where('agent_id', $agent->id)->where('symbol', $symbol)->whereNotNull('stationed_waypoint')->exists()) {
            throw new GameException('Campaign pool authority changed: operator stationed this probe.');
        }
        $owned = $this->assertTarget($agent, $episode, $policy, $type, $ignoreRunId);
        $point = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', 'waypoint')->where('symbol', $shipyard)->first();
        if (! $point || ($point->data['systemSymbol'] ?? null) !== $ship['nav']['systemSymbol']
            || ! in_array('SHIPYARD', array_column($point->data['traits'] ?? [], 'symbol'), true)) {
            throw new GameException($label.' purchase requires an observed same-system shipyard.');
        }

        $reserve = max($limits['reserve'] ?? 0, $this->effectiveReserve($agent, $grant, $policy, $ignoreRunId));
        if ($agent->credits <= $reserve) {
            throw new GameException($type.' purchase requires credits above working-capital reserve '.$reserve.' CR.');
        }
        $atYard = $ship['nav']['waypointSymbol'] === $shipyard;
        $meshHaulerMovement = $hauler && $this->requiresHaulerMeshMovement($episode, $ship, $shipyard);
        if ($meshHaulerMovement) {
            if (! RequestBudget::currentMeshAllowance()) {
                throw new GameException('Remote mesh hauler purchase requires current movement admission.');
            }
            app(CampaignMesh::class)->assertDeparture($episode, $agent, $ship, $shipyard, $ignoreRunId, yard: true);
        }
        if ($meshPurchase) {
            app(CampaignMesh::class)->assertPool($episode, $agent, $ship);
            if (! $atYard) {
                throw new GameException('Position the mesh pool purchaser at the selected shipyard before previewing a buy.');
            }
        }
        $yardObs = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', 'shipyard')->where('symbol', $shipyard)->first();
        if ($atYard || ! $yardObs) {
            $yardData = app(SpaceTradersApi::class)->shipyard($agent, $shipyard, fresh: ($hauler || $meshPurchase) && $atYard);
            $yardObs = app(AgentSynchronizer::class)->observe($agent, 'shipyard', $shipyard, $yardData);
        }
        if (! $this->offers($yardObs->data, $type)) {
            throw new GameException('Shipyard '.$shipyard.' does not list '.$type.'.');
        }

        $route = $this->purchaseRoute($agent, $ship, $shipyard, $reserve);
        $leg = $route['outbound'];
        $fuelCeiling = $route['fuel_ceiling'];

        $offer = $hauler ? $this->freshHaulerOffer($yardObs) : $this->offer($yardObs->data, $type);
        $minutes = $limits['minutes'];
        if ($hauler || ($policy['mesh'] ?? null)) {
            $cutoff = $meshPurchase ? app(ProbeYardSelector::class)->cutoff($episode, $grant, $policy) : $episode->next_reset_at->subHour();
            $minutes = min($minutes, $grant->limits['run_max_minutes'], (int) floor(now()->diffInSeconds($grant->expires_at ? $cutoff->min($grant->expires_at) : $cutoff, false) / 60));
            if ($minutes < 1 || (! $atYard && (! isset($leg['estimated_seconds']) || $minutes * 60 < $leg['estimated_seconds'] + 180))) {
                throw new GameException('Purchase route cannot complete within the approved duration.');
            }
        }
        if ($hauler) {
            if ($atYard && ! $offer) {
                throw new GameException('A complete verified hauler offer is required at the yard.');
            }
            $evaluation = $saturation->assertPurchase($grant, $episode, $agent, $shipyard, $offer, $ignoreRunId, $fuelCeiling, deadline: $deadline);
        }
        if ($offer !== null) {
            $price = $offer['purchasePrice'];
            if ($price > $policy['max_price']) {
                $this->recordShipyardRejection($episode, $shipyard, 'max_price', $price, $type);
                throw new GameException($type.' price '.$price.' CR exceeds max_price '.$policy['max_price'].' CR.');
            }
            if ($agent->credits - $price - $fuelCeiling < $reserve) {
                $this->recordShipyardRejection($episode, $shipyard, 'reserve', $price, $type);
                throw new GameException($type.' purchase at '.$price.' CR breaches working-capital reserve '.$reserve.' CR.');
            }
        } elseif ($agent->credits - $fuelCeiling <= $reserve) {
            throw new GameException('Insufficient credits above working-capital reserve for '.$type.' purchase.');
        }

        $maxPrice = $policy['max_price'];
        if ($meshPurchase) {
            $meshPrice = app(ProbeYardSelector::class)->check($episode, $agent, $ship, $shipyard, $policy, $offer['purchasePrice'] ?? 0, $reserve,
                $ignoreRunId, remainingFuel: $fuelCeiling, approved: $limits['mesh_purchase']['ceiling'] ?? null);
            $maxPrice = $meshPrice['ceiling'];
        }
        $maxSpend = min(max(0, $agent->credits - $reserve), $maxPrice + $fuelCeiling);
        if ($offer !== null && $maxSpend < $offer['purchasePrice'] + $fuelCeiling) {
            throw new GameException('Approved spending bound cannot cover '.$type.' purchase and fuel.');
        }

        $plan = array_merge($limits, [
            'strategy' => $hauler ? 'buy_hauler' : 'buy_probe',
            'minutes' => $minutes,
            'shipyard' => $shipyard,
            'ship_type' => $type,
            'max_price' => $maxPrice,
            'min_working_capital' => $policy['min_working_capital'],
            'reserve' => $reserve,
            'max_spend' => $maxSpend,
            'max_loss' => $maxSpend,
            'max_fuel' => $ship['fuel']['capacity'],
            'cycles' => 1,
            'site' => $ship['nav']['waypointSymbol'],
            'market' => $shipyard,
            'route' => $route,
            $hauler ? 'owned_haulers' : 'owned_probes' => $owned,
            'observed_price' => $offer['purchasePrice'] ?? null,
            'initial_credits' => $agent->credits,
            'initial_inventory' => MiningPlanner::inventory($ship),
            'initial_fuel' => $ship['fuel']['current'],
            'initial_waypoint' => $ship['nav']['waypointSymbol'],
            'initial_status' => $ship['nav']['status'],
            'initial_configuration' => FuelPlanner::configuration($ship),
            'equipment' => OpportunityEvaluator::equipment($ship),
            'participating_ships' => [$symbol],
        ]);
        if ($hauler) {
            if ($meshHaulerMovement) {
                $plan['mesh_work'] = 'hauler_purchase';
                $plan['mesh_allowance'] = RequestBudget::currentMeshAllowance();
            }
            $plan['offer_configuration_hash'] = CampaignHaulerPurchase::offerProfile($offer)['hash'] ?? null;
            $plan['grant_id'] = $grant->id;
            $plan['revision_hash'] = $grant->revision_hash;
            $plan['campaign_episode_id'] = $episode->id;
            $plan['purchaser'] = $symbol;
            $plan['initial_nav'] = $ship['nav'];
            $plan['initial_cargo'] = $ship['cargo'];
            $plan['initial_tank'] = $ship['fuel'];
            $plan['purchaser_configuration_hash'] = ShipCommandExecutor::configurationHash($ship);

            $approved = $plan;
            unset($approved['route']['outbound']['fuel_price_at']);
            ksort($approved);

            return ['plan' => $plan, 'fingerprint' => hash('sha256', json_encode([$agent->reset_id, $approved], JSON_THROW_ON_ERROR)), 'saturation' => $evaluation];
        }
        if ($meshPurchase) {
            $plan['mesh_work'] = 'purchase';
            $plan['mesh_allowance'] = RequestBudget::currentMeshAllowance();
            $plan['mesh_purchase'] = [
                'ceiling' => $maxPrice, 'tier' => $meshPrice['gain']['tier'], 'gain' => $meshPrice['gain'],
                'cash' => $meshPrice['cash'], 'cutoff' => $cutoff->timestamp, 'purchaser' => $symbol, 'yard' => $shipyard,
                'reset' => $agent->reset_id, 'grant' => $grant->id, 'revision' => $grant->revision_hash,
            ];
        }
        $fingerprint = hash('sha256', json_encode([
            $agent->reset_id,
            $plan['initial_credits'],
            $plan['initial_inventory'],
            $plan['initial_fuel'],
            $plan['initial_waypoint'],
            $plan['initial_status'],
            $plan['initial_configuration'],
            $plan['shipyard'],
            $plan['max_price'],
            $plan['reserve'],
            $plan['owned_probes'],
            $plan['observed_price'],
            ...($meshPurchase ? [$plan['mesh_purchase']] : []),
        ]));

        return ['plan' => $plan, 'fingerprint' => $fingerprint];
    }

    private function purchaseRoute(Agent $agent, array $ship, string $shipyard, int $reserve): array
    {
        $site = $ship['nav']['waypointSymbol'];
        $departureCeiling = 0;
        if ($site === $shipyard) {
            $leg = ['destination' => $shipyard, 'continuation' => $shipyard, 'outbound_fuel' => 0, 'required_fuel' => 0, 'refuel_ceiling' => 0];
        } else {
            $fuel = app(FuelPlanner::class);
            $listings = $fuel->listings($agent);
            $departure = $fuel->departureRefuel($agent, $ship, $listings);
            if ($departure) {
                $ceiling = min($departure['ceiling'], max(0, $agent->credits - $reserve));
                $fill = (int) ceil(($ship['fuel']['capacity'] - $ship['fuel']['current']) / 100) * $departure['quote']['purchasePrice'];
                if ($ceiling < $fill) {
                    $departure = null;
                } else {
                    $departure['agent']->credits = $agent->credits - $ceiling;
                    $departureCeiling = $ceiling;
                }
            }
            $leg = $fuel->leg($departure['agent'] ?? $agent, $departure['ship'] ?? $ship, $shipyard, $reserve, listings: $listings);
        }

        return ['site' => $site, 'market' => $shipyard, 'outbound' => $leg, 'departure_refuel_ceiling' => $departureCeiling,
            'fuel_estimate' => $leg['outbound_fuel'], 'fuel_ceiling' => $departureCeiling + $leg['refuel_ceiling']];
    }

    public function next(Agent $agent, array $ship, AutomationRun $run): AutomationStep
    {
        if ($ship['nav']['status'] === 'IN_TRANSIT') {
            $arrival = CarbonImmutable::parse($ship['nav']['route']['arrival']);

            return AutomationStep::wait($arrival->greaterThan(now()) ? $arrival : CarbonImmutable::now()->addSeconds(5));
        }
        if (! empty($run->totals['purchased_ships'])) {
            return AutomationStep::trip();
        }
        $episode = CampaignEpisode::findOrFail($run->campaign_episode_id);
        $type = $run->plan['ship_type'] ?? 'SHIP_PROBE';
        $hauler = $type === 'SHIP_LIGHT_HAULER';
        $label = $hauler ? 'Hauler' : 'Probe';
        $existing = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->latest('id')->first();
        if ($existing) {
            if (self::deliveredShip($existing) && empty($existing->outcome['configuration_mismatch'])) {
                return AutomationStep::trip();
            }
            $this->recordShipyardRejection($episode, $run->plan['shipyard'], 'failed', null, $type);

            return AutomationStep::blocked(! empty($existing->outcome['configuration_mismatch'])
                ? 'Delivered ship configuration differed from the approved offer. Review required.'
                : $label.' purchase already attempted for this run.');
        }

        $grant = $episode->grant();
        $policy = $this->purchasePolicy($grant, $episode, $agent, $type);
        if (! $policy) {
            return AutomationStep::blocked($label.' purchase authority is no longer active.');
        }

        $shipyard = $run->plan['shipyard'];
        if ($ship['nav']['waypointSymbol'] !== $shipyard) {
            $departure = $run->plan['route']['departure_refuel_ceiling'] ?? 0;
            $missing = $ship['fuel']['capacity'] - $ship['fuel']['current'];
            if ($departure > 0 && $missing > 0 && $ship['nav']['waypointSymbol'] === $run->plan['site'] && ($run->totals['travel_fuel'] ?? 0) === 0) {
                $fuel = app(OperationPlanner::class)->quote($agent, $run->plan['site'], 'FUEL');
                $units = min($missing, $fuel['tradeVolume'] * 100);
                $reserve = max($run->plan['reserve'], $this->effectiveReserve($agent, $grant, $policy, $run->id));
                $ceiling = min($departure - ($run->totals['fuel_cost'] ?? 0), $run->plan['max_spend'] - ($run->totals['fuel_cost'] ?? 0), max(0, $agent->credits - $reserve));
                $fuelCost = (int) ceil($units / 100) * $fuel['purchasePrice'];
                if ($fuelCost > $ceiling || $agent->credits - $fuelCost < $reserve) {
                    return AutomationStep::blocked('Departure refuel exceeds approved ceiling or current additive reserve for '.$type.' purchase.');
                }

                return $ship['nav']['status'] === 'DOCKED'
                    ? AutomationStep::act('refuel', ['units' => $units, 'maxTotal' => $ceiling, 'reserve' => $reserve])
                    : AutomationStep::act('dock');
            }

            return $ship['nav']['status'] === 'DOCKED'
                ? AutomationStep::act('orbit')
                : AutomationStep::act('navigate', ['waypointSymbol' => $shipyard]);
        }

        [$owned, $target] = $hauler
            ? [app(CampaignHaulerPurchase::class)->ownedHaulers($episode, $agent), $policy['max_haulers']]
            : $this->probeTarget($episode, $agent, $policy);
        if ($owned + $this->reservations($agent, $run->id, undeliveredType: $type)->count() >= $target) {
            return AutomationStep::blocked(($hauler ? 'Hauler' : 'Probe').' target already reached before purchase.');
        }
        $this->assertSlot($agent, $run->id);

        $yard = app(SpaceTradersApi::class)->shipyard($agent, $shipyard, fresh: true);
        app(AgentSynchronizer::class)->observe($agent, 'shipyard', $shipyard, $yard);
        $offer = $this->offer($yard, $type);
        if (! $offer) {
            $this->recordShipyardRejection($episode, $shipyard, 'unavailable', null, $type);

            return AutomationStep::blocked('Shipyard '.$shipyard.' does not currently offer '.$type.'.');
        }

        $pin = null;
        if ($hauler) {
            $pin = CampaignHaulerPurchase::offerProfile($offer)['hash'] ?? null;
            $known = $run->plan['offer_configuration_hash'] ?? $run->totals['offer_configuration_hash'] ?? null;
            if ($pin === null) {
                return AutomationStep::blocked('A complete verified hauler offer is required at arrival.');
            }
            if ($known !== null && ! hash_equals($known, $pin)) {
                return AutomationStep::blocked('Arrival offer configuration differs from approved hauler configuration.');
            }
        }

        $price = $offer['purchasePrice'];
        $reserve = max($run->plan['reserve'], $this->effectiveReserve($agent, $grant, $policy, $run->id));
        $remainingSpend = $run->plan['max_spend'] - ($run->totals['fuel_cost'] ?? 0);
        if ($price > $policy['max_price']) {
            $this->recordShipyardRejection($episode, $shipyard, 'max_price', $price, $type);

            return AutomationStep::blocked($type.' price '.$price.' CR exceeds max_price '.$policy['max_price'].' CR.');
        }
        if ($agent->credits - $price < $reserve) {
            $this->recordShipyardRejection($episode, $shipyard, 'reserve', $price, $type);

            return AutomationStep::blocked($type.' price '.$price.' CR breaches working-capital reserve '.$reserve.' CR.');
        }
        if ($price > $remainingSpend) {
            return AutomationStep::blocked($type.' price '.$price.' CR exceeds remaining run spending cap '.$remainingSpend.' CR.');
        }

        if ($hauler) {
            app(CampaignSaturation::class)->assertPurchase($grant, $episode, $agent, $shipyard, $offer, $run->id,
                max(0, $run->plan['route']['fuel_ceiling'] - ($run->totals['fuel_cost'] ?? 0)), persist: true);
        }

        $maxTotal = $price;
        if (isset($run->plan['mesh_purchase'])) {
            $paid = (int) ($run->totals['fuel_cost'] ?? 0);
            $approved = $run->plan['mesh_purchase']['ceiling'];
            $current = app(ProbeYardSelector::class)->check($episode, $agent, $ship, $shipyard, $policy, $price, $reserve, $run->id,
                $paid, max(0, $run->plan['route']['fuel_ceiling'] - $paid), $approved);
            $maxTotal = min($current['ceiling'], $remainingSpend, $run->plan['max_loss'] + ($run->totals['credit_delta'] ?? 0));
        }

        return AutomationStep::act('buy_ship', [
            'shipType' => $type,
            'waypointSymbol' => $shipyard,
            'maxTotal' => $maxTotal,
            'reserve' => $reserve,
        ] + ($pin ? ['offer_configuration_hash' => $pin] : []));
    }

    public function complete(AutomationRun $run, array $ship): void
    {
        $totals = $run->totals;
        $totals['cycles'] = ($totals['cycles'] ?? 0) + 1;
        $totals['ending_fuel'] = $ship['fuel']['current'];
        $totals['unsold'] = MiningPlanner::inventory($ship);
        $totals['elapsed_seconds'] = $run->started_at ? (int) $run->started_at->diffInSeconds(now()) : 0;
        $totals['finished_at'] = now()->toIso8601String();
        $run->update([
            'totals' => $totals,
            'state' => RunState::Completed,
            'active_agent_id' => null,
            'next_action_at' => null,
            'blocked_reason' => null,
        ]);
        $action = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->whereIn('state', ['confirmed', 'acknowledged'])->first();
        if ($action) {
            DB::transaction(fn () => $this->recordDelivery($action));
        }
        app(MeshGovernor::class)->release($run->plan['mesh_allowance'] ?? null);
    }

    /**
     * @param  list<array>  $probes
     * @param  list<string>  $busyShips
     * @return list<array>
     */
    private function eligibleShips(Agent $agent, CampaignEpisode $episode, ?array $command, array $probes, array $busyShips, string $system): array
    {
        $fuel = app(FuelPlanner::class);
        $eligible = [];
        $seen = [];
        foreach ([...$probes, ...($command ? [$command] : [])] as $ship) {
            $symbol = $ship['symbol'];
            if (isset($seen[$symbol]) || in_array($symbol, $busyShips, true)) {
                continue;
            }
            $seen[$symbol] = true;
            if (($ship['nav']['systemSymbol'] ?? null) !== $system
                || ($ship['nav']['status'] ?? null) === 'IN_TRANSIT'
                || ($ship['cargo']['units'] ?? 0) !== 0
                || ReconMission::holds($agent, $symbol)
                || app(CampaignFleet::class)->pendingForShip($episode, $symbol)) {
                continue;
            }
            $eligible[] = $ship + ['_fuel_free' => $fuel->fuelFree($ship)];
        }

        return $eligible;
    }

    /**
     * @param  list<array>  $eligibleShips
     * @return array<string, GameObservation>
     */
    private function discoverShipyards(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, string $system, array $eligibleShips, ?float $deadline, string $type, ?array &$meshReads = null): array
    {
        $waypoints = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', 'waypoint')->get()
            ->filter(fn ($point) => ($point->data['systemSymbol'] ?? null) === $system
                && in_array('SHIPYARD', array_column($point->data['traits'] ?? [], 'symbol'), true))
            ->pluck('symbol')->sort()->values();
        if ($waypoints->isEmpty()) {
            return [];
        }

        $existing = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', 'shipyard')->whereIn('symbol', $waypoints)->get()->keyBy('symbol');
        $occupied = array_unique(array_map(fn ($ship) => $ship['nav']['waypointSymbol'], $eligibleShips));
        $api = app(SpaceTradersApi::class);
        $sync = app(AgentSynchronizer::class);
        $reads = 0;
        $saturation = CampaignMesh::policy($episode)['saturation'] ?? null;
        $mesh = $saturation !== null;
        if ($mesh) {
            $meshReads ??= [];
        }
        $recoveryCooldown = $saturation['yard_recovery_cooldown_seconds'] ?? 0;
        $attempts = $episode->state['mesh_yard_attempt_at'] ?? [];

        // First refresh any shipyard where an eligible ship is already parked (to see live prices),
        // then inspect unobserved shipyards up to MAX_SHIPYARD_READS_PER_PASS.
        $toRead = [];
        foreach ($waypoints as $symbol) {
            $obs = $existing->get($symbol);
            if ($mesh && $type === CampaignHaulerPurchase::TYPE && $obs && ! $this->offers($obs->data, $type)) {
                continue;
            }
            if (in_array($symbol, $occupied, true) && (! $obs || empty($obs->data['ships']) || $obs->observed_at->lt(now()->subSeconds(config('gameplay.snapshot_seconds'))))) {
                $toRead[] = $symbol;
            }
        }
        foreach ($waypoints as $symbol) {
            if (! $existing->has($symbol) && ! in_array($symbol, $toRead, true)
                && (! $mesh || ($attempts[$symbol] ?? 0) <= now()->timestamp - $recoveryCooldown)) {
                $toRead[] = $symbol;
            }
        }
        foreach ($toRead as $symbol) {
            if ($reads >= self::MAX_SHIPYARD_READS_PER_PASS || microtime(true) >= ($deadline ?? INF)) {
                break;
            }
            if (CampaignLocalEarn::policy($grant, $episode) && $deadline !== null) {
                app(CampaignLocalEarn::class)->reserve($deadline);
            }
            $reads++;
            try {
                if ($mesh) {
                    app(MeshPoller::class)->read($episode, $agent, $symbol, 'shipyard', null, 'directory', $meshReads);
                } else {
                    $sync->observe($agent, 'shipyard', $symbol, $api->shipyard($agent, $symbol));
                }
            } catch (GameException $exception) {
                if ($mesh && $type === CampaignHaulerPurchase::TYPE && $exception instanceof RequestDeferred) {
                    break;
                }
                if ($mesh && in_array($exception->httpStatus, [400, 403, 404, 422], true)) {
                    $this->recordMeshYardAttempt($episode, $symbol);

                    continue;
                }
                if ($exception instanceof LocalEarnBudget || $exception->retryAfter > 0) {
                    throw $exception;
                }
            }
        }

        return GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', 'shipyard')->whereIn('symbol', $waypoints)->get()
            ->filter(fn ($obs) => $this->offers($obs->data ?? [], $type))
            ->keyBy('symbol')->all();
    }

    /**
     * @param  array<string, GameObservation>  $shipyards
     * @param  list<array>  $eligibleShips
     * @return list<array{ship: array, shipyard: string, tier: int, distance: float}>
     */
    private function rankCandidates(CampaignEpisode $episode, Agent $agent, array $policy, int $reserve, array $shipyards, array $eligibleShips, $points, array &$considered, string $type, ?float $deadline): array
    {
        $hauler = $type === 'SHIP_LIGHT_HAULER';
        $strategy = $hauler ? 'buy_hauler' : 'buy_probe';
        $rejected = $episode->refresh()->state[$hauler ? 'hauler_shipyard_rejected' : 'probe_shipyard_rejected'] ?? [];
        $mesh = ($hauler || ($policy['mesh'] ?? null)) ? app(CampaignMesh::class)->target($episode, $agent) : null;
        $anchored = $mesh ? $mesh['parked']->filter(fn ($ships, $waypoint) => $mesh['hubs']->has($waypoint))
            ->map(fn ($ships) => $ships->first()['symbol'])->all() : [];
        $reserved = $mesh ? $mesh['probes']->filter(fn ($ship) => $ship['nav']['status'] === 'IN_TRANSIT')
            ->map(fn ($ship) => $ship['nav']['route']['destination']['symbol'] ?? null)->filter()->all() : [];
        if ($mesh) {
            $positioning = AutomationRun::where('active_agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('strategy', 'scout')
                ->where('plan->observe_kind', 'shipyard')->pluck('plan')->map(fn ($plan) => $plan['destination'] ?? null)->filter()->all();
            $reserved = array_merge($reserved, $positioning);
        }
        $candidates = [];
        foreach ($shipyards as $symbol => $obs) {
            if (in_array($symbol, $reserved, true)) {
                continue;
            }
            $rejection = $rejected[$symbol] ?? null;
            if (is_array($rejection) && isset($rejection['at'])
                && CarbonImmutable::parse($rejection['at'])->addMinutes(self::SHIPYARD_BACKOFF_MINUTES)->isFuture()) {
                $canRetryReserve = ($rejection['reason'] ?? null) === 'reserve'
                    && isset($rejection['price']) && is_int($rejection['price'])
                    && $agent->credits - $rejection['price'] >= $reserve;
                if (! $canRetryReserve) {
                    $considered[] = ['strategy' => $strategy, 'shipyard' => $symbol, 'rejected' => 'Shipyard in backoff ('.$rejection['reason'].').'];

                    continue;
                }
            }

            if (! $hauler && ($offer = $this->offer($obs->data, $type)) !== null) {
                if ($offer['purchasePrice'] > $policy['max_price']) {
                    $considered[] = ['strategy' => $strategy, 'shipyard' => $symbol, 'rejected' => 'Observed '.$type.' price '.$offer['purchasePrice'].' CR exceeds max_price.'];

                    continue;
                }
                if ($agent->credits - $offer['purchasePrice'] < $reserve) {
                    $considered[] = ['strategy' => $strategy, 'shipyard' => $symbol, 'rejected' => 'Observed '.$type.' price '.$offer['purchasePrice'].' CR breaches reserve.'];

                    continue;
                }
            }

            $yardPoint = $points->get($symbol)?->data;
            foreach ($eligibleShips as $ship) {
                if (microtime(true) >= ($deadline ?? INF)) {
                    return [];
                }
                $atYard = $ship['nav']['waypointSymbol'] === $symbol;
                if (! $atYard && in_array($ship['symbol'], $anchored, true)) {
                    continue;
                }
                $fuelFree = $ship['_fuel_free'];
                $tier = match (true) {
                    $atYard && $fuelFree => 0,
                    $atYard => 1,
                    $fuelFree => 2,
                    default => 3,
                };
                $shipPoint = $points->get($ship['nav']['waypointSymbol'])?->data;
                $distance = ($yardPoint && $shipPoint)
                    ? hypot($yardPoint['x'] - $shipPoint['x'], $yardPoint['y'] - $shipPoint['y'])
                    : INF;
                $candidates[] = ['ship' => $ship, 'shipyard' => $symbol, 'tier' => $tier, 'distance' => $distance];
            }
        }
        usort($candidates, fn ($a, $b) => $a['tier'] <=> $b['tier'] ?: $a['distance'] <=> $b['distance'] ?: [$a['shipyard'], $a['ship']['symbol']] <=> [$b['shipyard'], $b['ship']['symbol']]);

        return $candidates;
    }

    public function offers(array $yard, string $type): bool
    {
        return in_array($type, array_column($yard['shipTypes'] ?? [], 'type'), true)
            || $this->offer($yard, $type) !== null;
    }

    public function offer(array $yard, string $type): ?array
    {
        $offer = collect($yard['ships'] ?? [])->firstWhere('type', $type);

        return is_array($offer) && isset($offer['purchasePrice']) ? $offer : null;
    }

    public function freshHaulerOffer(?GameObservation $observation): ?array
    {
        if (! $observation || $observation->observed_at->lt(now()->subMinutes(self::SHIPYARD_OFFER_FRESH_MINUTES))) {
            return null;
        }
        $offer = $this->offer($observation->data, CampaignHaulerPurchase::TYPE);

        return CampaignHaulerPurchase::offerProfile($offer) === null ? null : $offer;
    }

    private function refreshAcknowledgedShipyards(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, ?float $deadline): void
    {
        $occupied = Ship::where('agent_id', $agent->id)->get()->reject(fn (Ship $ship) => $ship->snapshot['nav']['status'] === 'IN_TRANSIT')
            ->map(fn (Ship $ship) => $ship->snapshot['nav']['waypointSymbol'])->all();
        $yards = $this->outstanding($agent)->where('state.value', 'acknowledged')
            ->map(fn (ShipAction $action) => $action->arguments['waypointSymbol'] ?? null)
            ->filter(fn ($symbol) => ! CampaignMesh::policy($episode) || in_array($symbol, $occupied, true))
            ->unique()->values()->take(self::MAX_SHIPYARD_READS_PER_PASS);
        foreach ($yards as $shipyard) {
            if (CampaignLocalEarn::policy($grant, $episode) && $deadline !== null) {
                app(CampaignLocalEarn::class)->reserve($deadline);
            }
            try {
                app(AgentSynchronizer::class)->observe($agent, 'shipyard', $shipyard, app(SpaceTradersApi::class)->shipyard($agent, $shipyard));
            } catch (GameException $exception) {
                if ($exception instanceof LocalEarnBudget || $exception->retryAfter > 0) {
                    throw $exception;
                }
            }
        }
    }

    private function recordShipyardRejection(CampaignEpisode $episode, string $shipyard, string $reason, ?int $price, string $type): void
    {
        $state = $episode->refresh()->state ?? [];
        $state[$type === 'SHIP_PROBE' ? 'probe_shipyard_rejected' : 'hauler_shipyard_rejected'][$shipyard] = [
            'reason' => $reason,
            'price' => $price,
            'at' => now()->toIso8601String(),
        ];
        $episode->update(['state' => $state]);
    }
}
