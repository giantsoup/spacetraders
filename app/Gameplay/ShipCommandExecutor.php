<?php

namespace App\Gameplay;

use App\Enums\ActionState;
use App\Enums\RunState;
use App\Jobs\ExecuteShipCommand;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\LocalEngine;
use App\Models\ReconMission;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Services\SpaceTradersClient;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShipCommandExecutor
{
    private const SHIP_SYMBOL_PATTERN = '/\A[A-Z0-9_-]+\z/';

    private const WAYPOINT_SYMBOL_PATTERN = '/\A[A-Z0-9-]+\z/';

    private const TRADE_SYMBOL_PATTERN = '/\A[A-Z0-9_]+\z/';

    private const NAVIGATION_RECEIPT_CLOCK_SKEW_SECONDS = 5;

    public function __construct(private HostLock $lock, private CommandRules $rules, private SpaceTradersApi $api, private AgentSynchronizer $sync, private EngineGate $gate) {}

    public function locked(Agent $agent, callable $work, string $priority = 'critical'): mixed
    {
        return $this->lock->run('agent-'.$agent->id, fn () => app(SpaceTradersClient::class)->scope($work, priority: $priority));
    }

    public function lockedForAction(Agent $agent, ?string $command, array $shipSymbols, callable $work, string $priority = 'critical', ?AutomationRun $run = null): mixed
    {
        $split = app(CampaignSafety::class)->perShipLocksEnabled($agent);
        if (! $split && ! ($command === 'transfer' && $run?->plan_version === 8)) {
            return $this->locked($agent, $work, $priority);
        }
        $ships = array_values(array_filter($shipSymbols));
        $reader = $split && $run?->campaign_episode_id && count($ships) === 1 && ! isset($run->plan['mesh_episode'])
            && ! in_array($run->strategy, ['scout', 'buy_fleet', ...CampaignProbeBootstrap::STRATEGIES], true)
            && in_array($command, CampaignSafety::SHIP_COMMANDS, true);

        $scope = fn () => app(SpaceTradersClient::class)->scope($work, priority: $priority);
        try {
            return $this->lock->lockedAgentAndShips($agent->id, $ships, ! $reader, function () use ($agent, $reader, $scope) {
                if ($reader && ! app(CampaignSafety::class)->perShipLocksEnabled($agent)) {
                    throw new CommandLockModeChanged('Command lock authority changed.');
                }

                return $scope();
            }, seconds: 0);
        } catch (CommandLockModeChanged) {
            return $this->lock->lockedAgentAndShips($agent->id, $ships, true, $scope, seconds: 0);
        }
    }

    public function preview(Agent $agent, string $ship, string $command, array $arguments, string $key): ShipAction
    {
        if (in_array($command, ['contract_accept', 'contract_fulfill'], true)) {
            $ship = '';
        }

        return $this->lockedForAction($agent, $command, [$ship, $arguments['targetShip'] ?? ''], function () use ($agent, $ship, $command, $arguments, $key) {
            $existing = ShipAction::where('agent_id', $agent->id)->where('key', $key)->first();
            if ($existing) {
                if ($existing->ship_symbol !== $ship || $existing->command !== $command || $existing->arguments !== $arguments) {
                    throw new GameException('This submission key already belongs to another intent.');
                }

                return $existing;
            }
            $this->clear($agent, ship: app(CampaignSafety::class)->perShipLocksEnabled($agent) ? $ship : null, command: $command, targetShip: $arguments['targetShip'] ?? null);
            $this->assertConfigurationReviewed($agent, $ship);
            $before = $this->rules->inspect($agent, $ship, $command, $arguments);
            $before['operation'] = $this->api->commandMetadata($command, $ship, $arguments);

            return ShipAction::create([
                'agent_id' => $agent->id, 'key' => $key, 'reset_id' => $agent->reset_id, 'ship_symbol' => $ship,
                'engine_session_id' => $this->gate->enabled() ? LocalEngine::find(1)?->session_id : null,
                'target_type' => str_starts_with($command, 'contract_') ? 'contract' : ($command === 'supply' ? 'waypoint' : ($command === 'buy_ship' ? 'fleet' : 'ship')),
                'target_id' => $arguments['contractId'] ?? ($command === 'supply' ? $arguments['waypointSymbol'] : $ship),
                'origin' => 'manual', 'command' => $command, 'arguments' => $arguments, 'before_state' => $before,
                'outcome' => ['preview' => $before],
                'state' => ActionState::Preview, 'expires_at' => now()->addSeconds(config('gameplay.preview_seconds')),
            ]);
        });
    }

    public function approve(Agent $agent, ShipAction $action): ShipAction
    {
        return $this->lockedForAction($agent, $action->command, [$action->ship_symbol, $action->arguments['targetShip'] ?? ''], function () use ($agent, $action) {
            $agent->refresh();
            $action->refresh();
            if ($action->automation_run_id !== null) {
                throw new GameException('Automation intents cannot be approved manually.');
            }
            if ($action->state !== ActionState::Preview) {
                return $action;
            }
            $this->clearForAction($agent, $action);
            if ($action->expires_at->isPast() || $action->reset_id !== $agent->reset_id) {
                throw new GameException('Preview expired. Create a fresh preview.');
            }
            try {
                $this->assertConfigurationReviewed($agent, $action->ship_symbol);
                $this->assertManualPurchaseSlot($agent, $action);
                $before = $this->rules->inspect($agent, $action->ship_symbol, $action->command, $action->arguments);
                $this->unchangedApproval($action, $before);
                DB::transaction(function () use ($agent, $action) {
                    $this->assertManualPurchaseSlot($agent, $action);
                    $this->gate->check($action->engine_session_id);
                    if (! ShipAction::whereKey($action->id)->where('state', 'preview')->update(['state' => 'pending', 'last_dispatched_at' => now()])) {
                        throw new GameException('Preview was stopped or already approved.');
                    }
                    $action->refresh();
                });
            } catch (\Throwable $e) {
                if (ExplorationPolicy::command($action->command) && isset($action->arguments['question'])) {
                    ShipAction::whereKey($action->id)->where('state', 'preview')->update(['state' => 'rejected', 'message' => 'Exploration approval preflight failed. Create a fresh preview; no automatic retry.', 'settled_at' => now()]);
                }
                throw $e;
            }
            ExecuteShipCommand::dispatch($action->id)->afterCommit();

            return $action;
        });
    }

    private function unchangedApproval(ShipAction $action, array $before): void
    {
        if ($action->origin !== 'manual') {
            return;
        }
        if (($action->before_state['contract'] ?? null) !== ($before['contract'] ?? null)) {
            throw new GameException('Contract terms or progress changed. Create a fresh preview.');
        }
        $saved = $action->before_state['ship'];
        if ($saved === null || in_array($action->command, ['contract_accept', 'contract_fulfill'], true)) {
            return;
        }
        $current = $before['ship'];
        foreach (['systemSymbol', 'waypointSymbol', 'status', 'flightMode'] as $field) {
            if ($saved['nav'][$field] !== $current['nav'][$field]) {
                throw new GameException('Ship location, fuel or equipment changed. Create a fresh preview.');
            }
        }
        foreach (['fuel', 'cargo', 'frame', 'engine', 'reactor', 'mounts', 'modules'] as $field) {
            if ($saved[$field] !== $current[$field]) {
                throw new GameException('Ship fuel, cargo or equipment changed. Create a fresh preview.');
            }
        }
        if (isset($action->before_state['estimate']['configuration']) && $action->before_state['estimate']['configuration'] !== ($before['estimate']['configuration'] ?? null)) {
            throw new GameException('The observed ship offer changed. Create a fresh preview.');
        }
    }

    public function stopManual(ShipAction $action): ShipAction
    {
        $action->refresh();
        if ($action->origin !== 'manual' || $action->automation_run_id) {
            throw new GameException('Stop the owning run for automated actions.');
        }
        // Conditional state and JSON-path updates are atomic; never replace a concurrently saved receipt.
        $stop = ['outcome->stop_requested_at' => now()->utc()->toIso8601String()];
        if ((isset($action->outcome['body_sha256']) || isset($action->outcome['response_received_at']))
            && ShipAction::whereKey($action->id)->whereIn('state', ['preview', 'pending'])->update($stop + ['state' => 'uncertain', 'message' => 'A write receipt already exists. Stop cannot cancel a completed send; reconcile without replay.', 'settled_at' => now()])) {
            return $action->refresh();
        }
        $stopped = ShipAction::whereKey($action->id)->where('origin', 'manual')->whereNull('automation_run_id')
            ->whereIn('state', ['preview', 'pending'])->update($stop + [
                'state' => 'rejected', 'message' => 'Stopped before reservation. No manual request was sent.', 'settled_at' => now(),
            ]);
        if (! $stopped) {
            ShipAction::whereKey($action->id)->where('origin', 'manual')->whereNull('automation_run_id')->where('state', 'sending')
                ->update($stop + ['message' => 'Stop requested after reservation. This action may settle; no follow-up is authorized.']);
        }

        return $action->refresh();
    }

    public function clear(Agent $agent, ?int $except = null, bool $defer = false, ?string $ship = null, ?string $command = null, ?string $targetShip = null, ?int $leaseId = null, bool $reservationOnly = false): void
    {
        $others = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->when($except, fn ($q) => $q->whereKeyNot($except));
        $safety = app(CampaignSafety::class);
        if ($safety->perShipLocksEnabled($agent) && $ship !== null) {
            $nonCredit = [...CampaignSafety::SHIP_COMMANDS, 'transfer'];
            $targets = array_values(array_filter([$ship, $command === 'transfer' ? $targetShip : null], fn ($symbol) => $symbol !== null));
            $primary = CampaignGrant::primaryFor($agent->user_id, $agent->name)->first();
            if ($safety->uncertainCredit($agent) || $safety->creditReview($agent) || $safety->unexplainedBalance($agent, $except)
                || (! $reservationOnly && CampaignSafety::isCreditCommand($command) && app(StateChain::class)->hasUnresolvedCreditAction($agent, $except))
                || ($primary && $safety->purchaseConfigurationMismatch($primary, $agent))
                || (clone $others)->where(fn ($q) => self::reviewFor($q, $ship))->exists()) {
                throw new GameException('An agent action is uncertain or requires review. Settle it before another command.');
            }
            foreach ($targets as $target) {
                if ($safety->hasShipUncertaintyHold($agent, $target)
                    || app(MiningCluster::class)->hasBlockingLease($agent, $target, $command === 'transfer' ? $leaseId : null)) {
                    throw new GameException('This ship or its transfer partner has an unresolved action or lease.', retryAfter: $defer ? 5 : 0);
                }
            }
            $others->where(function ($query) use ($targets, $command, $nonCredit, $reservationOnly) {
                $query->whereIn('ship_symbol', $targets);
                if (! $reservationOnly && CampaignSafety::isCreditCommand($command)) {
                    $query->orWhereNotIn('command', $nonCredit);
                }
            });
        }
        if ((clone $others)->where(fn ($q) => $q->where('state', 'uncertain')->orWhere(fn ($q) => self::reviewFor($q, $ship)))->exists()) {
            throw new GameException('An action is pending, uncertain or requires review. Settle it before another command.');
        }
        if ((clone $others)->whereIn('state', ['pending', 'sending'])->exists()) {
            throw new GameException($defer ? 'An action is pending or sending. Wait for it to settle before another command.' : 'An action is pending, uncertain or requires review. Settle it before another command.', retryAfter: $defer ? 5 : 0);
        }
        if (! $agent->canonical_identity || Agent::where('name', $agent->name)->where('reset_id', $agent->reset_id)->whereKeyNot($agent->id)->exists()) {
            throw new GameException('Agent identity is not unique. Synchronize or resolve duplicate imports first.');
        }
    }

    private function clearForAction(Agent $agent, ShipAction $action, ?AutomationRun $run = null, bool $reservationOnly = false): void
    {
        $scoped = app(CampaignSafety::class)->perShipLocksEnabled($agent);
        $this->clear($agent, $action->id, defer: $run !== null, ship: $run || $scoped ? $action->ship_symbol : null,
            command: $action->command, targetShip: $action->arguments['targetShip'] ?? null,
            leaseId: $action->arguments['transfer_lease_id'] ?? $action->arguments['transferLeaseId'] ?? null, reservationOnly: $reservationOnly);
    }

    public function assertConfigurationReviewed(Agent $agent, string $ship): void
    {
        $mismatches = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('command', 'buy_ship')->where('outcome->configuration_mismatch', true)->get();
        foreach ($mismatches as $action) {
            $delivered = CampaignProbeBootstrap::deliveredShip($action);
            if (! self::reviewedPurchaseMismatch($action)
                || ($delivered === $ship && AutomationRun::whereKey($action->automation_run_id)->value('plan_version') !== 8)) {
                throw new GameException('Delivered ship configuration differs from approval. Reconcile and review the purchase before using this ship.');
            }
        }
    }

    public static function reviewedHaulerMismatch(ShipAction $action): bool
    {
        return ($action->arguments['shipType'] ?? null) === 'SHIP_LIGHT_HAULER' && self::reviewedPurchaseMismatch($action);
    }

    public static function reviewedPurchaseMismatch(ShipAction $action): bool
    {
        $type = $action->arguments['shipType'] ?? null;
        if (! in_array($type, ['SHIP_LIGHT_HAULER', 'SHIP_LIGHT_SHUTTLE'], true) || $action->review_required
            || ! in_array($action->state, [ActionState::Confirmed, ActionState::Acknowledged], true)
            || ! CampaignProbeBootstrap::deliveredShip($action)
            || empty($action->outcome['operator_note']) || empty($action->outcome['reviewed_at']) || empty($action->outcome['reconciled_at'])) {
            return false;
        }
        try {
            $review = CarbonImmutable::parse($action->outcome['reviewed_at']);
            $reconciled = CarbonImmutable::parse($action->outcome['reviewed_reconciled_at'] ?? $action->outcome['reconciled_at']);
            $linkage = app(CampaignProbeBootstrap::class)->linkage($action);
            $version = $linkage[0]->plan_version ?? null;

            return ($version === 8 || ($version === 7 && $type === 'SHIP_LIGHT_HAULER')) && $review->gte($reconciled) && $review->lte($reconciled->addMinutes(3))
                && ($action->state === ActionState::Confirmed || isset($action->outcome['delivery_transaction']));
        } catch (\Throwable) {
            return false;
        }
    }

    private function assertManualPurchaseSlot(Agent $agent, ShipAction $action): void
    {
        if (! $action->automation_run_id && $this->purchaseUsesSharedSlot($agent, $action)) {
            app(CampaignSafety::class)->assertFleetPurchaseSlot($agent, actionId: $action->id);
        }
    }

    private function purchaseUsesSharedSlot(Agent $agent, ShipAction $action): bool
    {
        if ($action->command !== 'buy_ship') {
            return false;
        }
        $type = $action->arguments['shipType'] ?? null;
        if (in_array($type, CampaignProbeBootstrap::PURCHASE_TYPES, true)) {
            return true;
        }
        $primary = CampaignGrant::primaryFor($agent->user_id, $agent->name)->first();

        return CampaignPlaybook::saturationPolicy($primary?->revision()->body ?? []) && in_array($type, app(HullCatalog::class)->allTypes(), true);
    }

    private function checkPurchaseTransport(Agent $agent, ShipAction $action, ?AutomationRun $run): void
    {
        $agent->refresh();
        $action->refresh();
        if ($action->state !== ActionState::Sending || ! $action->expires_at->isFuture() || $action->reset_id !== $agent->reset_id
            || ! empty($action->outcome['stop_requested_at'])) {
            throw new GameException('Purchase intent expired, reset or stopped before transport.');
        }
        $this->gate->check($action->engine_session_id);
        if (app(CampaignSafety::class)->perShipLocksEnabled($agent)) {
            $this->clearForAction($agent, $action, $run);
        }
        $this->assertManualPurchaseSlot($agent, $action);
        if ($action->automation_run_id) {
            $run = $run?->fresh();
            if (! $run || $run->active_agent_id !== $agent->id || $run->stop_requested_at || ! $run->expires_at->isFuture()
                || ! in_array($run->state, [RunState::Running, RunState::Waiting], true)) {
                throw new GameException('Purchase run stopped or expired before transport.');
            }
            $this->gate->check($run->engine_session_id);
            $price = $action->before_state['estimate']['purchasePrice'] ?? null;
            app(CampaignSafety::class)->check($run, $agent, $action->command, $action->arguments, boundaryOnly: true, preflightPrice: $price);
        }
    }

    private function checkCampaignTransport(Agent $agent, ShipAction $action, AutomationRun $run): void
    {
        $agent->refresh();
        $action->refresh();
        $run->refresh();
        if ($action->state !== ActionState::Sending || $action->expires_at->isPast() || $action->reset_id !== $agent->reset_id
            || ! empty($action->outcome['stop_requested_at']) || $run->active_agent_id !== $agent->id
            || $run->stop_requested_at || $run->expires_at->isPast() || ! in_array($run->state, [RunState::Running, RunState::Waiting], true)) {
            throw new GameException('Campaign action authority expired or stopped before transport.');
        }
        $this->gate->check($action->engine_session_id);
        $safety = app(CampaignSafety::class);
        if ($safety->perShipLocksEnabled($agent)) {
            $this->clearForAction($agent, $action, $run);
        }
        $safety->check($run, $agent, $action->command, $action->arguments, boundaryOnly: true);
    }

    // A price review holds only its own ship for automation; a review without a ship (contracts) or a manual command holds the agent.
    public static function reviewFor($query, ?string $ship): void
    {
        $query->where('review_required', true)->when($ship !== null, fn ($q) => $q->where(fn ($q) => $q->where('ship_symbol', $ship)->orWhereNull('ship_symbol')->orWhere('ship_symbol', '')));
    }

    public function execute(int $id): void
    {
        $action = ShipAction::find($id);
        if (! $action || $action->state !== ActionState::Pending) {
            return;
        }
        $agent = Agent::find($action->agent_id);
        if (! $agent) {
            return;
        }
        $this->dispatchLocked($agent, $action);
    }

    public function dispatchLocked(Agent $agent, ShipAction $action, ?array $prefetched = null): void
    {
        $run = AutomationRun::find($action->automation_run_id);
        $work = fn () => $this->lockedForAction($agent, $action->command,
            [$action->ship_symbol, $action->arguments['targetShip'] ?? ''], fn () => $this->executeLocked($agent, $action, $prefetched), run: $run);
        try {
            $work();
        } catch (AcquisitionDeadlineReached $e) {
            $this->closeAcquisitionUnsent($agent, $run, $action, $e->legIndex);
        }
    }

    /**
     * Core execution path. Caller must hold the agent lock and must NOT be inside a DB transaction.
     * Optional $prefetched carries verified pre-write reads from AutomationRunner::advance (<=10s old).
     */
    public function executeLocked(Agent $agent, ShipAction $action, ?array $prefetched = null): void
    {
        $agent->refresh();
        $action->refresh();
        if ($action->state !== ActionState::Pending) {
            return;
        }
        $run = $action->automation_run_id ? AutomationRun::find($action->automation_run_id) : null;
        $workload = $run?->strategy === 'gate_recon' ? 'recon' : ($run?->strategy ?? 'manual');
        try {
            FleetMetrics::forAgent($agent->name, fn () => FleetMetrics::withWorkload($workload,
                fn () => $this->executeWithWorkload($agent, $action, $run, $prefetched)), $agent->reset_id);
        } catch (AcquisitionDeadlineReached $e) {
            $writer = $this->lock->holdsExclusive('agent-'.$agent->id);
            if ($writer && $this->closeAcquisitionUnsent($agent, $run, $action, $e->legIndex)) {
                return;
            }
            $this->rejectUnsentRunAction($agent, $action, $run, $e->getMessage());
            if (! $writer) {
                throw $e;
            }
        }
    }

    private function executeWithWorkload(Agent $agent, ShipAction $action, ?AutomationRun $run, ?array $prefetched): void
    {
        if (isset($action->outcome['body_sha256']) || isset($action->outcome['response_received_at'])) {
            $this->retainUncertainty($agent, $action);
            $action->update(['state' => ActionState::Uncertain, 'message' => 'A write receipt already exists on this pending intent. Reconcile without replay.', 'settled_at' => now()]);
            if ($run) {
                $this->block($run, 'Retained write receipt requires reconciliation before further work.');
            }

            return;
        }
        if ($run && in_array($run->state, [RunState::Stopped, RunState::Completed], true)) {
            $reason = $run->state === RunState::Stopped ? 'Run stopped before transport.' : 'Run completed before transport.';
            $this->rejectUnsentRunAction($agent, $action, $run, $reason);

            return;
        }
        $v8 = $run?->plan_version === 8;
        $cleanup = $v8 && $action->command === 'flight_mode' && ($action->arguments['flightMode'] ?? null) === 'CRUISE'
            && app(CampaignSafety::class)->positionModeAllowed($run, $agent, 'CRUISE');
        if ($v8 && $run->strategy === 'trade' && $action->command === 'buy' && isset($action->arguments['leg_index'])
            && $action->arguments['leg_index'] !== ($run->totals['leg_index'] ?? 0)) {
            $this->rejectUnsentRunAction($agent, $action, $run, 'The acquisition intent belongs to a closed trade leg.');

            return;
        }
        $credit = $v8 && CampaignSafety::isCreditCommand($action->command);
        $leaseId = $v8 && $action->command === 'transfer' ? ($action->arguments['transferLeaseId'] ?? $action->arguments['transfer_lease_id'] ?? null) : null;
        $scoped = app(CampaignSafety::class)->perShipLocksEnabled($agent);
        $received = false;
        $reserved = false;
        $recon = $run?->strategy === 'gate_recon';
        $outbound = $recon && isset($action->arguments['recon_outbound_hash']);
        $refusedBeforeTransport = false;
        $paced = $run && RequestBudget::agentCaps() !== [];
        $loaded = ($paced || $v8) && $run && ($v8 || $run->strategy === 'trade') && AutomationRunner::hasTradeCargo($run);
        $buyPhase = in_array($run?->totals['phase'] ?? null, ['buy', 'acquire', 'mine'], true);
        $budget = app(RequestBudget::class);
        if (! $v8 && $loaded && $buyPhase && in_array($action->command, ['buy', 'dock', 'refuel'], true) && $budget->ordinaryCapReached($agent->name)) {
            $action->update(['state' => ActionState::Rejected, 'message' => 'Request budget ended loading; unsent intent released for cargo settlement.', 'settled_at' => now()]);

            return;
        }
        $recovery = $run && (! empty($run->plan['sell_only']) || $action->command === 'sell');
        $settlementScope = $cleanup || ($recovery && (! empty($run->plan['mesh_settlement_only']) || $paced || $budget->isDraining($agent->name)))
            || ($loaded && ($v8 || ! $buyPhase) && in_array($action->command, ['orbit', 'navigate', 'dock', 'refuel', 'sell'], true)
                && (! $v8 || ($action->arguments['phase'] ?? null) === 'deliver' || in_array($run->totals['phase'] ?? null, ['deliver', 'sell'], true) || $action->command === 'sell'));
        $guardedMovement = $run?->campaign_episode_id && (isset($run->plan['mesh_work'])
            || in_array($run->strategy, ['scout', 'sell_aboard', ...CampaignProbeBootstrap::STRATEGIES], true))
            && in_array($action->command, ['navigate', 'orbit', 'dock', 'refuel'], true);
        $work = function () use ($agent, $action, $run, $prefetched, &$received, &$reserved, $recon, $outbound, $guardedMovement, $v8, $credit, $leaseId, $scoped, $cleanup, &$refusedBeforeTransport) {
            try {
                $this->gate->check($action->engine_session_id);
                if ($v8) {
                    $this->assertAcquisitionOpen($run, $action);
                }
                if ($credit && ! $this->creditReservationReady($agent, $action, $run)) {
                    return;
                }
                $this->clearForAction($agent, $action, $run);
                $this->assertManualPurchaseSlot($agent, $action);
                if ($action->reset_id !== $agent->reset_id || $action->expires_at->isPast() || ($run && ($run->stop_requested_at || (! $cleanup && $run->expires_at->isPast()) || ! $run->active_agent_id))) {
                    throw new GameException('Intent expired, reset or stopped before execution.');
                }
                if ($outbound) {
                    app(GateRecon::class)->validateOutboundIntent($run, $action);
                }
                $reads = $prefetched ?? $action->before_state;
                $fresh = $this->rules->refresh($agent, $action->ship_symbol, $action->command, $action->arguments, $run, $reads);
                if (! $v8 && $run && $action->command === 'buy') {
                    app(CampaignSafety::class)->check($run, $agent);
                    $finished = DB::transaction(function () use ($agent, $run, $action) {
                        if (! app(CampaignTrade::class)->finishLoading($agent, $run, $action->arguments)) {
                            return false;
                        }
                        $action->update(['state' => ActionState::Rejected, 'message' => 'Partial arm load complete; unsent buy released.', 'settled_at' => now()]);

                        return true;
                    });
                    if ($finished) {
                        return;
                    }
                }
                $before = $this->rules->validate($agent, $action->ship_symbol, $action->command, $action->arguments, $fresh, $run);
                if ($run) {
                    app(CampaignSafety::class)->check($run, $agent, $action->command, $action->arguments, preflightPrice: $before['estimate']['purchasePrice'] ?? null);
                }
                $this->unchangedApproval($action, $before);
                // Stop and reservation contend on a database transaction, independently of the host lock.
                DB::transaction(function () use ($action, $run, $before, $agent, $outbound, $leaseId, $v8, $cleanup) {
                    $this->gate->check($action->engine_session_id);
                    $this->assertManualPurchaseSlot($agent->fresh(), $action);
                    if ($outbound) {
                        app(GateRecon::class)->validateOutboundIntent($run, $action);
                    }
                    if ($run) {
                        app(CampaignSafety::class)->check($run, $agent, $action->command, $action->arguments, boundaryOnly: true, preflightPrice: $before['estimate']['purchasePrice'] ?? null);
                    }
                    if ($v8) {
                        $this->assertAcquisitionOpen($run, $action);
                    }
                    if ($action->expires_at->isPast()) {
                        throw new GameException('Intent expired during preflight. Create a fresh preview.');
                    }
                    if ($run && ! AutomationRun::whereKey($run->id)->whereNull('stop_requested_at')->whereNotNull('active_agent_id')->when(! $cleanup, fn ($query) => $query->where('expires_at', '>', now()))->update(['heartbeat_at' => now()])) {
                        throw new GameException('Run stopped or expired before this action was reserved.');
                    }
                    if (! ShipAction::whereKey($action->id)->where('state', 'pending')->update(['state' => $leaseId ? 'pending' : 'sending', 'before_state' => json_encode($before), 'sent_at' => $leaseId ? null : now()] + ($run?->strategy === 'gate_recon' ? ['outcome->transport_reservations' => (int) ($action->fresh()->outcome['transport_reservations'] ?? 0) + 1] : []))) {
                        throw new GameException('Action was already reserved.');
                    }
                });
                $reserved = true;
                $action->refresh();
                // The provider request budget may wait after the action is reserved.
                $beforeTransport = match (true) {
                    $v8 => function () use ($run, $agent, $action, &$before, $credit, $leaseId, &$refusedBeforeTransport) {
                        $refusedBeforeTransport = true;
                        $this->checkSaturationTransport($agent, $action, $run, $before, $credit, $leaseId);
                        $refusedBeforeTransport = false;
                    },
                    $outbound => function () use ($run, $action, &$refusedBeforeTransport) {
                        $refusedBeforeTransport = true;
                        app(GateRecon::class)->validateOutboundIntent($run, $action);
                        $refusedBeforeTransport = false;
                    },
                    $action->command === 'buy_ship' && ($run || $this->purchaseUsesSharedSlot($agent, $action)) => function () use ($run, $agent, $action, &$refusedBeforeTransport) {
                        $refusedBeforeTransport = true;
                        $this->checkPurchaseTransport($agent, $action, $run);
                        $refusedBeforeTransport = false;
                    },
                    $guardedMovement => function () use ($run, $agent, $action, &$refusedBeforeTransport) {
                        $refusedBeforeTransport = true;
                        $this->checkCampaignTransport($agent, $action, $run);
                        $refusedBeforeTransport = false;
                    },
                    $scoped => function () use ($agent, $action, $run, &$refusedBeforeTransport) {
                        $refusedBeforeTransport = true;
                        if ($run) {
                            $this->checkCampaignTransport($agent, $action, $run);
                        } else {
                            $agent->refresh();
                            $action->refresh();
                            if ($action->state !== ActionState::Sending || $action->expires_at->isPast() || ! empty($action->outcome['stop_requested_at'])) {
                                throw new GameException('Manual action expired or stopped before transport.');
                            }
                            $this->gate->check($action->engine_session_id);
                        }
                        $this->clearForAction($agent, $action, $run);
                        $refusedBeforeTransport = false;
                    },
                    default => null,
                };
                $data = $this->api->mutate($agent, $action->ship_symbol, $action->command, $action->arguments, function (array $receipt) use ($action, &$received) {
                    $received = true;
                    $action->update(['outcome' => array_merge($action->fresh()->outcome ?? [], $receipt, ['response_received_at' => now()->utc()->toIso8601String()])]);
                }, beforeTransport: $beforeTransport);
                $received = true;
                $action->update(['outcome' => array_merge($action->fresh()->outcome ?? [], ['response' => $data, 'response_validated' => true, 'response_received_at' => now()->utc()->toIso8601String()])]);
                $this->confirm($agent, $action, $data, $before, $run);
            } catch (AcquisitionDeadlineReached $e) {
                throw $e;
            } catch (RequestDeferred $e) {
                if ($v8 && ! $received) {
                    $run->refresh();
                    $this->assertAcquisitionOpen($run, $action);
                }
                if (! $v8 && $refusedBeforeTransport && ($action->command === 'buy_ship' || $guardedMovement)) {
                    $this->rejectUnsentPurchase($action, $run, $e->getMessage());

                    return;
                }
                FleetMetrics::as($action->ship_symbol ?: null, fn () => FleetMetrics::observe($e));
                $action->refresh();
                if ($received) {
                    $this->retainUncertainty($agent, $action);
                    $action->update(['state' => ActionState::Uncertain, 'message' => 'Write response retained; post-write refresh was deferred by the request budget. Reconcile without replay.', 'settled_at' => now()]);
                    if ($run) {
                        $this->block($run, 'Post-write refresh deferred. Reconcile the retained response before further work.');
                    }

                    return;
                }
                $markers = $recon && $reserved ? ['outcome->pretransport_deferrals' => (int) ($action->outcome['pretransport_deferrals'] ?? 0) + 1] : [];
                if ($v8 && $this->meshAllowanceLost($agent, $run)) {
                    $this->rejectLostMeshIntent($agent, $action, $run, 'Mesh run lost its request allowance before transport.');

                    return;
                }
                // Only a budget deferral before the write starts can return to pending.
                if ($action->expires_at->isFuture() && ! ExplorationPolicy::command($action->command) && empty($action->outcome['stop_requested_at'])
                    && (! $v8 || ($run->active_agent_id && ! $run->stop_requested_at && $run->expires_at->isFuture()))) {
                    $action->update(['state' => ActionState::Pending, 'sent_at' => null, 'message' => $e->getMessage()] + $markers);
                } else {
                    $reason = ExplorationPolicy::command($action->command) ? 'Exploration stopped at the request budget. Create a fresh preview; no automatic retry.' : 'Intent expired while waiting for the request budget.';
                    if ($v8) {
                        $this->rejectUnsentRunAction($agent, $action, $run, $reason, block: true);
                    } else {
                        $action->update(['state' => ActionState::Rejected, 'message' => $reason, 'settled_at' => now(), 'sent_at' => null] + $markers + ($recon ? $this->localRefusal('budget', $e->getMessage()) : []));
                        if ($run) {
                            $this->block($run, $reason);
                        }
                    }
                }
            } catch (GameException $e) {
                if ($v8 && $refusedBeforeTransport && $e->retryAfter > 0 && $action->expires_at->isFuture()) {
                    $action->update(['state' => ActionState::Pending, 'sent_at' => null, 'message' => $e->getMessage()] + $this->localRefusal('pretransport', $e->getMessage()));

                    return;
                }
                if (! $v8 && $refusedBeforeTransport && ($action->command === 'buy_ship' || $guardedMovement)) {
                    $this->rejectUnsentPurchase($action, $run, $e->getMessage());

                    return;
                }
                FleetMetrics::as($action->ship_symbol ?: null, fn () => FleetMetrics::observe($e));
                if ($e->apiCode === 4113 || ($agent->fresh()?->connection_state === 'invalid_credential')) {
                    try {
                        $this->sync->guardReset($agent->fresh(), fresh: true);
                    } catch (GameException) {
                    }
                }
                $action->refresh();
                $surveyRefused = in_array($action->command, ['mine', 'mine_survey'], true)
                    && isset($action->arguments['surveySignature']) && in_array($e->apiCode, [4221, 4224], true);
                $surveyFallback = $surveyRefused && $v8 && $run->strategy === 'mining';
                if ($surveyRefused && ! $surveyFallback) {
                    app(SurveyPool::class)->invalidate($agent, $action->arguments['surveySignature'], $e->apiCode === 4224);
                }
                // Rate-budget failure before transport is safe to retry only while pending.
                if ($action->state === ActionState::Pending && ! ExplorationPolicy::command($action->command) && $e->retryAfter > 0 && $action->expires_at->isFuture() && $e->apiCode !== 4113 && $agent->fresh()?->connection_state !== 'invalid_credential') {
                    $action->update(['message' => $e->getMessage()]);

                    return;
                }
                $uncertain = $action->state === ActionState::Sending && ! $refusedBeforeTransport && ($received || $e->uncertain);
                $localRefusal = [];
                if ($refusedBeforeTransport) {
                    $localRefusal = $this->localRefusal('pretransport', $e->getMessage()) + [
                        'sent_at' => null,
                        'outcome->pretransport_deferrals' => (int) ($action->outcome['pretransport_deferrals'] ?? 0) + 1,
                    ];
                } elseif (($recon || isset($run?->plan['mesh_work'])) && $action->state === ActionState::Pending) {
                    $localRefusal = $this->localRefusal('preflight', $e->getMessage());
                }
                if ($uncertain) {
                    $this->retainUncertainty($agent, $action);
                } elseif ($v8 && $action->state === ActionState::Sending && ! $refusedBeforeTransport) {
                    $this->releaseUnsent($agent, $action);
                }
                $outcome = ['state' => $uncertain ? ActionState::Uncertain : ActionState::Rejected,
                    'message' => $e->getMessage(), 'api_code' => $e->apiCode, 'settled_at' => now()] + $localRefusal;
                if ($surveyFallback && ! $uncertain && ! $refusedBeforeTransport && $action->state === ActionState::Sending) {
                    DB::transaction(function () use ($agent, $action, $run, $outcome, $e) {
                        app(SurveyBroker::class)->invalidateSignature($agent, $action->arguments['surveySignature'], $e->apiCode === 4224);
                        $action->update($outcome);
                        $run->refresh();
                        $plan = $run->plan;
                        $totals = $run->totals;
                        unset($plan['survey_signature'], $totals['active_survey_signature']);
                        $totals['survey_fallback_unsurveyed'] = true;
                        AutomationRun::whereKey($run->id)->where('active_agent_id', $agent->id)->whereNull('stop_requested_at')
                            ->update(['plan' => json_encode($plan, JSON_THROW_ON_ERROR), 'totals' => json_encode($totals, JSON_THROW_ON_ERROR),
                                'state' => RunState::Running, 'next_action_at' => now(), 'last_dispatched_at' => null, 'blocked_reason' => null]);
                    });

                    return;
                }
                if ($v8 && ! $uncertain && ($refusedBeforeTransport || $action->state === ActionState::Pending)) {
                    $this->rejectUnsentRunAction($agent, $action, $run, $e->getMessage(), $outcome);
                } else {
                    $action->update($outcome);
                }
                if ($v8 && ! $uncertain && $run->strategy === 'position' && $action->command === 'navigate'
                    && ($before['ship']['nav']['flightMode'] ?? $action->before_state['ship']['nav']['flightMode'] ?? 'DRIFT') === 'DRIFT'
                    && ($run->plan['route']['mode'] ?? null) === 'DRIFT') {
                    $run->update(['totals' => array_replace($run->totals, ['position_phase' => 'cleanup']), 'next_action_at' => now()]);
                } elseif ($run) {
                    $this->block($run, $e->getMessage());
                }
            } catch (\Throwable $e) {
                Log::build(['driver' => 'single', 'path' => storage_path('logs/gameplay.log'), 'permission' => 0600])->error('Ship command local execution failed.', [
                    'action_id' => $action->id, 'exception_class' => get_class($e),
                    'source' => str_replace(base_path().'/', '', $e->getFile()), 'line' => $e->getLine(),
                ]);
                if ($v8 && ! $received && ($refusedBeforeTransport || ! $reserved)) {
                    $this->rejectUnsentRunAction($agent, $action, $run, 'Local validation failed before transport.', block: true);

                    return;
                }
                if ($refusedBeforeTransport && ($action->command === 'buy_ship' || $guardedMovement)) {
                    $this->rejectUnsentPurchase($action, $run, 'Purchase authority could not be verified before transport.');

                    return;
                }
                $action->refresh();
                if ($action->state === ActionState::Sending) {
                    $this->retainUncertainty($agent, $action);
                } elseif ($v8) {
                    $this->releaseUnsent($agent, $action);
                }
                $action->update(['state' => $action->state === ActionState::Sending ? ActionState::Uncertain : ActionState::Rejected,
                    'message' => 'Local execution failed. Reconcile before further commands.', 'settled_at' => now()] + ($recon && $action->state === ActionState::Pending ? $this->localRefusal('preflight', 'Local execution failed.') : []));
                if ($run) {
                    $this->block($run, 'Local execution failed. Reconciliation required.');
                }
            } finally {
                if ($recon && $mission = ReconMission::find($run->plan['mission_id'] ?? null)) {
                    app(GateRecon::class)->reconcileSettlement($mission);
                }
            }
        };
        $execute = function () use ($work, $settlementScope, $paced, $v8) {
            if ($settlementScope) {
                $settle = fn () => app(SpaceTradersClient::class)->scope($work, priority: 'settlement');
                FleetMetrics::withWorkload(FleetMetrics::currentWorkload(), $settle, borrowed: $v8);
            } elseif ($paced) {
                app(SpaceTradersClient::class)->scope($work, priority: 'run');
            } else {
                $work();
            }
        };
        if (isset($run?->plan['mesh_work']) && ! $cleanup) {
            try {
                app(MeshGovernor::class)->run($agent, $run, $execute);
            } catch (RequestDeferred $exception) {
                if ($v8) {
                    $this->assertAcquisitionOpen($run, $action);
                }
                FleetMetrics::observe($exception);
                $action->refresh();
                $run->refresh();
                if ($action->state !== ActionState::Pending || $action->sent_at) {
                    return;
                }
                $expired = ! $action->expires_at->isFuture() || ! $run->expires_at->isFuture();
                $allowance = $run->plan['mesh_allowance'] ?? null;
                $lost = ! $expired && $this->meshAllowanceLost($agent, $run);
                if ($expired || $lost || $run->stop_requested_at || ! $run->active_agent_id || $action->reset_id !== $agent->reset_id) {
                    $reason = $expired ? 'Mesh intent or run expired before transport.' : 'Mesh run lost its authority or request allowance before transport.';
                    if ($v8) {
                        $this->rejectLostMeshIntent($agent, $action, $run, $reason);
                    } else {
                        DB::transaction(function () use ($action, $run, $reason) {
                            $this->rejectUnsentPurchase($action, $run, $reason);
                            if ($run->fresh()->state === RunState::Blocked) {
                                $run->update(['active_agent_id' => null, 'next_action_at' => null]);
                            }
                        });
                        app(MeshGovernor::class)->release($allowance);
                    }

                    return;
                }
                $action->update(['message' => $exception->getMessage()]);
            }
        } else {
            $execute();
        }
    }

    private function meshAllowanceLost(Agent $agent, AutomationRun $run): bool
    {
        if (! isset($run->plan['mesh_work']) || (AutomationRunner::hasTradeCargo($run)
            && in_array($run->totals['phase'] ?? null, ['sell', 'deliver'], true)) || ! empty($run->plan['sell_only'])) {
            return false;
        }
        try {
            return ! app(RequestBudget::class)->hasMesh($run->plan['mesh_allowance'] ?? null, $agent->name, $agent->reset_id, $run->id);
        } catch (RequestDeferred) {
            return false;
        }
    }

    private function rejectLostMeshIntent(Agent $agent, ShipAction $action, AutomationRun $run, string $reason): void
    {
        DB::transaction(function () use ($agent, $action, $run, $reason) {
            if ($this->rejectUnsentRunAction($agent, $action, $run, $reason, block: true) && $run->refresh()->state === RunState::Blocked) {
                $run->update(['active_agent_id' => null, 'next_action_at' => null]);
                $this->releaseEmptyRunHolds($agent, $run);
            }
        });
        app(MeshGovernor::class)->release($run->plan['mesh_allowance'] ?? null);
    }

    private function checkSaturationTransport(Agent $agent, ShipAction $action, AutomationRun $run, array &$before, bool $credit, ?int $leaseId): void
    {
        $action->refresh();
        $run->refresh();
        $agent->refresh();
        $this->assertAcquisitionOpen($run, $action);
        $safety = app(CampaignSafety::class);
        $policy = $safety->saturationPolicy($run);
        $checkAuthority = function () use ($agent, $action, $run, $leaseId, $safety, &$before) {
            $action->refresh();
            $run->refresh();
            $this->assertAcquisitionOpen($run, $action);
            $cleanup = $action->command === 'flight_mode' && ($action->arguments['flightMode'] ?? null) === 'CRUISE'
                && $safety->positionModeAllowed($run, $agent, 'CRUISE');
            if ($action->state !== ($leaseId ? ActionState::Pending : ActionState::Sending) || ! $action->expires_at->isFuture()
                || $action->reset_id !== $agent->reset_id || $run->active_agent_id !== $agent->id || $run->stop_requested_at
                || (! $cleanup && ! $safety->settlementDeadline($run)?->isFuture()) || ! empty($action->outcome['stop_requested_at'])
                || ! in_array($run->state, [RunState::Running, RunState::Waiting], true)) {
                throw new GameException('Campaign action authority expired or stopped before transport.');
            }
            $this->gate->check($run->engine_session_id);
            $this->gate->check($action->engine_session_id);
            $this->clearForAction($agent, $action, $run);
            $this->assertConfigurationReviewed($agent, $action->ship_symbol);
            $safety->check($run, $agent, $action->command, $action->arguments, boundaryOnly: true, preflightPrice: $before['estimate']['purchasePrice'] ?? null);
        };
        $checkAuthority();

        $chain = app(StateChain::class);
        if (! empty($policy['compression']) && $before['ship']
            && $chain->staleFields($agent, $action->ship_symbol, now()->toImmutable(), $policy['chain_max_age_seconds'] ?? 120)['needs_refresh']) {
            $current = $this->rules->shipForRun($agent, $run, force: true);
            $saved = $before['ship'];
            if (array_intersect_key($current['nav'], array_flip(['status', 'waypointSymbol', 'systemSymbol', 'flightMode']))
                !== array_intersect_key($saved['nav'], array_flip(['status', 'waypointSymbol', 'systemSymbol', 'flightMode']))
                || $current['fuel']['current'] !== $saved['fuel']['current'] || MiningPlanner::inventory($current) !== MiningPlanner::inventory($saved)
                || FuelPlanner::configuration($current) !== FuelPlanner::configuration($saved) || OpportunityEvaluator::equipment($current) !== OpportunityEvaluator::equipment($saved)
                || $current['crew']['current'] < $current['crew']['required']
                || collect(['frame', 'engine', 'reactor'])->contains(fn ($part) => min($current[$part]['condition'], $current[$part]['integrity']) < 0.5)
                || (in_array($action->command, ['mine', 'mine_survey', 'siphon', 'survey', 'transfer'], true) && now()->lt($current['cooldown']['readyAt']))) {
                throw new GameException('Ship state changed during the request budget wait.');
            }
            $before['ship'] = $current;
        }
        if ($credit) {
            $this->rules->refreshCredits($agent, $run, true, except: $action->id);
            if ($agent->credits !== $before['credits']) {
                throw new GameException('The authoritative credit balance changed before transport.');
            }
        }
        if (isset($before['estimate']['site_version']) && now()->subSeconds(120)->gt($before['estimate']['site_observed_at'])) {
            $before['estimate'] = array_replace($before['estimate'], $this->rules->refreshExtractionSite($agent, $run, $before['ship'], $policy));
        }
        $checkAuthority();
        $estimate = $before['estimate'];
        if (in_array($action->command, ['buy', 'sell', 'buy_ship'], true) || ($action->command === 'refuel' && empty($action->arguments['fromCargo']))) {
            if (! isset($estimate['price_evidence'])) {
                throw new GameException('A fresh executable quote is required.');
            }
            $evidence = $estimate['price_evidence'];
            $yard = $action->command === 'buy_ship';
            $endpoint = $yard ? $action->arguments['waypointSymbol'] : $before['ship']['nav']['waypointSymbol'];
            $kind = $yard ? 'shipyard' : 'market';
            $result = $chain->validatePriceEvidence($evidence, now()->toImmutable(), $chain->generation($agent, $kind, $endpoint), $endpoint,
                $action->arguments['maxTotal'] ?? null, $action->arguments['minTotal'] ?? null, min(10, $policy['price_preflight_max_seconds'] ?? 10));
            if (! $result['ok'] || $evidence['reset_id'] !== $agent->reset_id || $evidence['agent_id'] !== $agent->id || $evidence['kind'] !== $kind) {
                throw new GameException('Executable quote must be refreshed before transport.', retryAfter: 1);
            }
        }
        if (! empty($estimate['origin_fuel_evidence'])) {
            $origin = $before['ship']['nav']['waypointSymbol'];
            if ($chain->needsOriginFuelRead($agent, $origin, $estimate['origin_fuel_evidence'], now()->toImmutable())) {
                throw new GameException('Origin FUEL evidence must be refreshed before transport.', retryAfter: 1);
            }
        }
        if ($action->command === 'navigate' && isset($estimate['route']['continuation_generation'])) {
            $this->rules->assertCruiseContinuation($agent, $run, $before['ship'], $estimate['route'],
                max($run->plan['reserve'] ?? 0, $action->arguments['reserve'] ?? 0), $estimate['route']['continuation_generation']);
            $checkAuthority();
        }
        if ($action->command === 'buy_ship') {
            $episode = CampaignEpisode::findOrFail($run->campaign_episode_id);
            $plan = $run->plan;
            $result = app(CampaignSaturation::class)->rankFleetPurchases($episode->grant(), $episode, $agent, [$plan['allocation_candidate']], $run->id,
                exact: [$plan['ship_type'], $plan['shipyard'], $run->ship_symbol], deliveryFuel: $plan['route']['continuation_ceiling'] ?? 0,
                approvedCeiling: $action->arguments['maxTotal']);
            if (! $result['selected']) {
                throw new GameException('The fleet purchase no longer clears its allocation and working-capital gates.');
            }
            $checkAuthority();
            $evidence = $estimate['price_evidence'];
            if (! $chain->validatePriceEvidence($evidence, now()->toImmutable(), $chain->generation($agent, 'shipyard', $evidence['endpoint']),
                $evidence['endpoint'], $action->arguments['maxTotal'], maxAgeSeconds: min(10, $policy['price_preflight_max_seconds'] ?? 10))['ok']) {
                throw new GameException('Executable yard offer must be refreshed before transport.', retryAfter: 1);
            }
        }
        if (($estimate['route']['mode'] ?? null) === 'DRIFT') {
            $bound = $safety->planTime($estimate['route']['hard_deadline'])->subSeconds($estimate['route']['margin_seconds']);
            if (now()->addSeconds($estimate['route']['estimated_seconds'])->gt($bound)) {
                throw new GameException('The DRIFT edge can no longer arrive within its approved window.');
            }
        }
        if ($leaseId) {
            $before['estimate']['target_ship'] = $this->rules->refreshLeasedReceiver($agent, $run, $action, $before);
            $checkAuthority();
            $context = ['receiver_snapshot' => $before['estimate']['target_ship'],
                'receiver_reserved_units' => $estimate['receiver_reserved_units'] ?? 0];
            $result = app(MiningCluster::class)->validateForTransport($agent, $leaseId, $action->id, $context, now()->toImmutable());
            if (! $result['ok']) {
                throw new GameException('Transfer was refused before transport: '.$result['reason']);
            }
            $before = $action->refresh()->before_state;

            return;
        }
        if ($credit && $this->creditExceedsAvailable($agent, $run, $action)) {
            throw new GameException('Credit reservations exceed the available command budget.');
        }
        DB::transaction(function () use ($agent, $action, $run, $before, $credit, $chain, $estimate, $policy) {
            if (isset($estimate['site_version'])) {
                $sites = app(ExtractionSites::class);
                $waypoint = $before['ship']['nav']['waypointSymbol'];
                $status = $sites->siteStatus($agent, $waypoint, $policy['extraction']['allow_debris'] ?? false);
                if (! $status['allowed'] || $status['version'] !== $estimate['site_version']
                    || now()->subSeconds(120)->gt($estimate['site_observed_at'])) {
                    throw new GameException('Fresh site modifier evidence is required.', retryAfter: 1);
                }
                $cap = $sites->rollingCap($policy['extraction']['max_extractions_per_minute'] ?? 8, $policy['extraction']['safety_permille'] ?? 750, $status['reduced_rate']);
                if (! $sites->admitSend($agent, $waypoint, $action->ship_symbol, $action->id, now()->timestamp, $cap)['allowed']) {
                    throw new GameException('Extraction site is at its rolling send cap.', retryAfter: 5);
                }
            }
            if ($credit) {
                $chain->markSending($action->id);
            }
            if ($action->command === 'jettison') {
                $used = $this->rules->jettisonValueThisHour($agent, $run, except: $action->id);
                if ($used + $estimate['jettison']['opportunity_cost_credits'] > min(5000, $policy['extraction']['jettison_cap_credits_per_hour'] ?? 0)) {
                    throw new GameException('Jettison exceeds the rolling hourly value cap.');
                }
            }
            app(CorridorBook::class)->markSending($agent, $action->id);
            $action->update(['before_state' => $before, 'sent_at' => now()]);
        });
    }

    public function reserveCreditIntent(Agent $agent, AutomationRun $run, ShipAction $action): void
    {
        if ($this->creditExceedsAvailable($agent, $run, $action)) {
            throw new GameException('Credit reservations exceed the available command budget.', retryAfter: 5);
        }
        app(StateChain::class)->reserveCredit($agent, 'run:'.$run->id, $this->creditPurpose($run, $action), $action->command,
            (int) ($action->arguments['maxTotal'] ?? 0), $action->id, $run->id, $action->ship_symbol);
    }

    private function creditExceedsAvailable(Agent $agent, AutomationRun $run, ShipAction $action): bool
    {
        $exposure = (int) ($action->arguments['maxTotal'] ?? 0);
        if ($exposure === 0) {
            return false;
        }
        $purpose = $this->creditPurpose($run, $action);
        $chain = app(StateChain::class);
        $outstanding = $chain->outstandingExposure($agent, 'run:'.$run->id, $purpose, $action->id);
        if ($run->strategy === 'buy_fleet') {
            $outstanding = max(0, $outstanding - ($run->plan['reserve_parts']['commitments'] ?? 0));
        }
        $floor = max($run->plan['reserve'], $action->arguments['reserve'] ?? 0);
        $spent = $run->totals['spending'] ?? 0;

        return $agent->credits - $floor - $outstanding < $exposure
            || $spent + $exposure > $run->plan['max_spend'] || $exposure > $run->plan['max_loss'] + $run->totals['credit_delta'];
    }

    private function creditPurpose(AutomationRun $run, ShipAction $action): string
    {
        return match ($action->command) {
            'buy' => $run->strategy === 'trade' ? 'trade_lot' : 'acquisition',
            'refuel' => 'delivery_fuel',
            'buy_ship' => 'purchase',
            default => $action->command,
        };
    }

    private function creditReservationReady(Agent $agent, ShipAction $action, AutomationRun $run): bool
    {
        $query = DB::table('credit_reservations')->where('ship_action_id', $action->id);
        if (! (clone $query)->exists()) {
            $this->clearForAction($agent, $action, $run, reservationOnly: true);
            $this->reserveCreditIntent($agent, $run, $action);
        }
        if ($query->value('state') === 'pending' || $this->promoteCreditIntent($agent) === $action->id) {
            return $action->refresh()->state === ActionState::Pending;
        }

        $run->update(['next_action_at' => now()->addSeconds(2)]);

        return false;
    }

    public function promoteCreditIntent(Agent $agent): ?int
    {
        $safety = app(CampaignSafety::class);
        if ($safety->uncertainCredit($agent) || $safety->creditReview($agent)) {
            return null;
        }

        return DB::transaction(function () use ($agent) {
            $queued = DB::table('credit_reservations')->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->whereNotNull('ship_action_id');
            foreach ((clone $queued)->whereIn('state', ['reserved', 'pending'])->get() as $reservation) {
                $id = (int) $reservation->ship_action_id;
                $action = ShipAction::find($id);
                $owner = $action ? AutomationRun::find($action->automation_run_id) : null;
                $unsent = ! $action?->sent_at && ! isset($action?->outcome['body_sha256']) && ! isset($action?->outcome['response_received_at']);
                if (! $unsent) {
                    continue;
                }
                if (! $action || ! in_array($action->state, [ActionState::Preview, ActionState::Pending], true)
                    || ! $owner || ! $owner->active_agent_id || $owner->stop_requested_at || ! $owner->expires_at->isFuture() || ! $action->expires_at->isFuture()) {
                    $reason = 'Queued reservation expired before transport.';
                    if ($action && in_array($action->state, [ActionState::Preview, ActionState::Pending], true)) {
                        if ($owner) {
                            $this->rejectUnsentRunAction($agent, $action, $owner, $reason);
                        } else {
                            $action->update(['state' => ActionState::Rejected, 'sent_at' => null, 'settled_at' => now(), 'message' => $reason,
                                'outcome' => array_replace($action->outcome ?? [], ['confirmed_not_sent' => true])]);
                        }
                    }
                    app(StateChain::class)->releaseReservation($id);
                } elseif ($reservation->state === 'reserved' && $action->state === ActionState::Pending) {
                    $action->update(['state' => ActionState::Preview, 'last_dispatched_at' => null]);
                }
            }
            if (ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                ->whereIn('state', [ActionState::Pending, ActionState::Sending])->whereNotIn('command', [...CampaignSafety::SHIP_COMMANDS, 'transfer'])
                ->whereNotIn('id', (clone $queued)->select('ship_action_id'))->exists()) {
                return null;
            }
            $promoted = app(StateChain::class)->promoteOldestPending($agent);
            if (! $promoted) {
                return null;
            }
            $id = (int) $promoted->ship_action_id;
            if (ShipAction::whereKey($id)->where('state', 'preview')->update(['state' => 'pending', 'last_dispatched_at' => now()])) {
                ExecuteShipCommand::dispatch($id)->afterCommit();
            }

            return $id;
        });
    }

    private function invalidateActionQuote(Agent $agent, ShipAction $action, ?array $ship): void
    {
        if ($action->command === 'buy_ship') {
            $this->sync->invalidateShipyard($agent, $action->arguments['waypointSymbol'], $action->id);
        } elseif (isset($ship['nav']['waypointSymbol']) && (in_array($action->command, ['buy', 'sell'], true)
            || ($action->command === 'refuel' && empty($action->arguments['fromCargo'])))) {
            $this->sync->invalidateMarket($agent, $ship['nav']['waypointSymbol'], $action->id);
        }
    }

    private function retainUncertainty(Agent $agent, ShipAction $action): void
    {
        DB::transaction(function () use ($agent, $action) {
            app(StateChain::class)->markUncertain($action->id);
            if ($action->ship_symbol !== '') {
                app(StateChain::class)->invalidateShipFields($agent, $action->ship_symbol);
            }
            if ($action->command === 'transfer' && isset($action->arguments['targetShip'])) {
                app(StateChain::class)->invalidateShipFields($agent, $action->arguments['targetShip'], ['cargo', 'cooldown']);
            }
            $this->invalidateActionQuote($agent, $action, $action->before_state['ship'] ?? null);
            app(ExtractionSites::class)->markSendResult($action->id, 'uncertain');
            DB::table('corridor_book_events')->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                ->where('ship_action_id', $action->id)->where('state', 'sending')->update(['state' => 'uncertain', 'updated_at' => now()]);
        });
        $lease = $action->arguments['transferLeaseId'] ?? $action->arguments['transfer_lease_id'] ?? null;
        if ($lease) {
            app(MiningCluster::class)->settleLease($agent, $lease, false, false);
        }
    }

    private function releaseUnsent(Agent $agent, ShipAction $action, bool $keepCorridor = true): void
    {
        DB::transaction(function () use ($agent, $action, $keepCorridor) {
            DB::table('credit_reservations')->where('ship_action_id', $action->id)->where('state', 'sending')->update(['state' => 'pending', 'updated_at' => now()]);
            app(StateChain::class)->releaseReservation($action->id);
            app(ExtractionSites::class)->markSendResult($action->id, 'released');
            DB::table('corridor_book_events')->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                ->where('ship_action_id', $action->id)->whereIn('state', ['reserved', 'sending'])
                ->update(['state' => $keepCorridor ? 'reserved' : 'released', 'ship_action_id' => null, 'updated_at' => now()]);
        });
        $lease = $action->arguments['transferLeaseId'] ?? $action->arguments['transfer_lease_id'] ?? null;
        if ($lease) {
            app(MiningCluster::class)->settleLease($agent, $lease, false, false, providerRefusedNoEffect: true);
        }
    }

    private function rejectUnsentRunAction(Agent $agent, ShipAction $action, AutomationRun $run, string $reason, array $attributes = [], bool $block = false): bool
    {
        return DB::transaction(function () use ($agent, $action, $run, $reason, $attributes, $block) {
            $action->refresh();
            if ($action->automation_run_id !== $run->id
                || ! in_array($action->state, [ActionState::Preview, ActionState::Pending, ActionState::Sending], true)
                || isset($action->outcome['body_sha256']) || isset($action->outcome['response_received_at'])) {
                return false;
            }
            $run->refresh();
            $this->releaseUnsent($agent, $action, keepCorridor: ! in_array($run->state, [RunState::Stopped, RunState::Completed], true));
            $action->update(['state' => ActionState::Rejected, 'sent_at' => null, 'settled_at' => now(), 'message' => $reason]
                + $attributes + ['outcome->confirmed_not_sent' => true] + $this->localRefusal('pretransport', $reason));
            if ($block && ! $run->stop_requested_at && in_array($run->state, [RunState::Running, RunState::Waiting], true)) {
                $this->block($run, $reason);
            }
            $this->releaseEmptyRunHolds($agent, $run);

            return true;
        });
    }

    private function releaseEmptyRunHolds(Agent $agent, AutomationRun $run): void
    {
        if ((! $run->active_agent_id || $run->stop_requested_at || ! $run->expires_at->isFuture()) && ! AutomationRunner::hasTradeCargo($run)) {
            app(StateChain::class)->releaseRunHolds($agent, $run->id);
            app(CorridorBook::class)->releaseOwner($agent, 'run', (string) $run->id, keepUnresolved: true);
        }
    }

    private function assertAcquisitionOpen(AutomationRun $run, ShipAction $action): void
    {
        $index = (int) ($action->arguments['leg_index'] ?? $run->totals['leg_index'] ?? 0);
        $safety = app(CampaignSafety::class);
        if (! in_array($action->command, CampaignSafety::ACQUISITION_COMMANDS, true)) {
            $deadline = $safety->acquisitionDeadline($run, $index);
            if ($this->isTradeApproach($run, $action, $index) && $deadline && now()->gte($deadline)) {
                throw new AcquisitionDeadlineReached($index);
            }

            return;
        }
        $deadline = $safety->acquisitionDeadline($run, $index);
        if ($deadline && now()->gte($deadline)) {
            throw new AcquisitionDeadlineReached($index);
        }
        if (! empty($run->totals['acquisition_closed_at']) || ! empty($run->totals['leg_acquisition_closed'][$index])
            || ($run->strategy === 'trade' && $index !== ($run->totals['leg_index'] ?? 0))) {
            throw new GameException('The acquisition intent no longer belongs to the active lot.');
        }
    }

    private function isTradeApproach(AutomationRun $run, ShipAction $action, int $index): bool
    {
        return $run->strategy === 'trade' && ($action->arguments['leg_index'] ?? null) === $index
            && ($action->arguments['phase'] ?? null) !== 'deliver' && ! in_array($run->totals['phase'] ?? null, ['sell', 'deliver'], true)
            && in_array($action->command, ['orbit', 'dock', 'navigate', 'refuel'], true);
    }

    public function closeAcquisitionUnsent(Agent $agent, AutomationRun $run, ?ShipAction $action = null, ?int $legIndex = null): bool
    {
        return $this->lock->lockedAgentAndShips($agent->id, [$run->ship_symbol], writer: true, work: fn () => DB::transaction(function () use ($agent, $run, $action, $legIndex) {
            $run->refresh();
            $totals = $run->totals;
            $index = (int) ($totals['leg_index'] ?? 0);
            if ($run->plan_version !== 8 || $run->active_agent_id !== $agent->id || $run->reset_id !== $agent->reset_id || ($legIndex !== null && $index !== $legIndex)) {
                return false;
            }
            $safety = app(CampaignSafety::class);
            $deadline = $safety->acquisitionDeadline($run, $index);
            $cutoff = $safety->planTime($run->plan['acquisition_cutoff'] ?? null);
            $runClosed = $cutoff && now()->gte($cutoff);
            $chain = count($run->plan['legs'] ?? []) > 1;
            if (! $deadline || now()->lt($deadline) || ! empty($totals['acquisition_closed_at'])
                || (! $runClosed && ! empty($totals['leg_acquisition_closed'][$index]))) {
                return false;
            }
            $intents = ShipAction::where('agent_id', $agent->id)->where('reset_id', $run->reset_id)->where('automation_run_id', $run->id);
            if ((clone $intents)->whereIn('state', ['sending', 'uncertain'])->when($action, fn ($query) => $query->whereKeyNot($action->id))->exists()) {
                return false;
            }
            $action?->refresh();
            $alreadyRejected = $action?->state === ActionState::Rejected && ! empty($action->outcome['confirmed_not_sent']);
            if ($action && ($action->automation_run_id !== $run->id || (! $alreadyRejected && ! in_array($action->state, [ActionState::Pending, ActionState::Sending], true))
                || isset($action->outcome['body_sha256']) || isset($action->outcome['response_received_at']))) {
                return false;
            }
            $pending = (clone $intents)->whereIn('state', ['preview', 'pending'])->get()->filter(
                fn (ShipAction $intent) => (in_array($intent->command, CampaignSafety::ACQUISITION_COMMANDS, true)
                    && ($intent->arguments['leg_index'] ?? $index) === $index) || $this->isTradeApproach($run, $intent, $index),
            );
            if ($action && ! $alreadyRejected && ! $pending->contains('id', $action->id)) {
                $pending->push($action);
            }
            foreach ($pending as $intent) {
                if (isset($intent->outcome['body_sha256']) || isset($intent->outcome['response_received_at'])) {
                    return false;
                }
            }
            if ($alreadyRejected) {
                $action->update(['outcome->acquisition_deadline_closed' => true]);
            }
            foreach ($pending as $intent) {
                $this->rejectUnsentRunAction($agent, $intent, $run, 'Acquisition deadline reached before transport.',
                    ['outcome->acquisition_deadline_closed' => true]);
            }
            $loaded = AutomationRunner::hasTradeCargo($run);
            $book = app(CorridorBook::class);
            $credit = app(StateChain::class);
            if ($run->strategy === 'trade') {
                $units = $totals['leg_purchased'][$index] ?? array_sum($totals['purchased'] ?? []);
                $cost = $totals['leg_purchase_cost'][$index] ?? $totals['purchase_cost'] ?? 0;
                if ($loaded && isset($run->plan['legs'][$index])) {
                    app(CampaignTrade::class)->shortenLoad($run, $units, $cost, 'acquisition_deadline', $index);
                    $totals = $run->refresh()->totals;
                } elseif (! $loaded) {
                    $book->releaseOwner($agent, 'run', (string) $run->id, $index);
                }
                $credit->releaseRunHolds($agent, $run->id, 'trade_lot');
                if ($units === 0) {
                    $totals['empty_cutoff_exit'] = true;
                }
                $totals['leg_acquisition_closed'][$index] ??= ['reason' => 'acquisition_deadline', 'units' => $units, 'purchase_cost' => $cost];
                if ($runClosed && $chain) {
                    for ($later = $index + 1; $later < count($run->plan['legs']); $later++) {
                        $book->releaseOwner($agent, 'run', (string) $run->id, $later);
                    }
                }
            }
            if ($runClosed || ! $chain) {
                $totals['acquisition_closed_at'] ??= now()->toIso8601String();
                $credit->releaseRunHolds($agent, $run->id, 'acquisition');
            }
            if (! $loaded && ! $runClosed && isset($run->plan['legs'][$index + 1])) {
                app(AutomationRunner::class)->reserveTradeLot($agent, $run, $run->plan, $index + 1);
                $totals = array_replace($totals, ['leg_index' => $index + 1, 'hop_index' => 0, 'phase' => 'buy']);
                $run->update(['totals' => $totals, 'state' => RunState::Running, 'next_action_at' => now(), 'last_dispatched_at' => null, 'blocked_reason' => null]);

                return true;
            }
            if (! $loaded) {
                if (array_sum($totals['purchased'] ?? []) + array_sum($totals['extracted'] ?? []) === 0) {
                    $totals['empty_cutoff_exit'] = true;
                }
                $totals['elapsed_seconds'] = max(1, (int) ($run->started_at?->diffInSeconds(now()) ?? 1));
                $run->update(['totals' => $totals, 'state' => RunState::Completed, 'active_agent_id' => null, 'next_action_at' => null]);
                $this->releaseEmptyRunHolds($agent, $run);

                return true;
            }
            $totals['phase'] = isset($run->plan['legs']) || $run->strategy !== 'trade' ? 'deliver' : 'sell';
            $run->update(['totals' => $totals, 'state' => RunState::Running, 'next_action_at' => now(), 'last_dispatched_at' => null, 'blocked_reason' => null]);

            return true;
        }));
    }

    private function rejectUnsentPurchase(ShipAction $action, ?AutomationRun $run, string $reason): void
    {
        $action->refresh();
        if ((isset($action->outcome['response_received_at']) || isset($action->outcome['body_sha256']))
            || in_array($action->state, [ActionState::Confirmed, ActionState::Uncertain, ActionState::Acknowledged], true)) {
            return;
        }
        $action->update(['state' => ActionState::Rejected, 'sent_at' => null, 'settled_at' => $action->settled_at ?? now(),
            'message' => $action->state === ActionState::Rejected && $action->message ? $action->message : $reason] + $this->localRefusal('pretransport', $reason));
        $run = $run?->fresh();
        if ($run && ! $run->stop_requested_at && in_array($run->state, [RunState::Running, RunState::Waiting], true)) {
            $this->block($run, $reason);
        }
    }

    private function localRefusal(string $stage, string $message): array
    {
        return ['outcome->local_refusal' => ['at' => now()->utc()->toIso8601String(), 'stage' => $stage, 'message' => $message]];
    }

    private function confirm(Agent $agent, ShipAction $action, array $data, array $before, ?AutomationRun $run, ?array $transferEvidence = null): void
    {
        $v8 = $run?->plan_version === 8;
        if ($action->command === 'scrap') {
            $this->confirmScrap($agent, $action, $data, $before);

            return;
        }
        $ship = $action->command === 'repair' ? $data['ship'] : $before['ship'];
        foreach (['nav', 'fuel', 'cargo', 'cooldown', 'mounts', 'modules'] as $field) {
            if (isset($data[$field])) {
                $ship[$field] = $data[$field];
            }
        }
        $actualShip = $action->command === 'buy_ship' ? $this->api->shipData($data['ship']) : ($ship ? $this->api->shipData($ship) : null);
        if ($actualShip && ! isset($data['cooldown']) && ! in_array($action->command, ['repair', 'buy_ship'], true)) {
            $actualShip['cooldown'] = $before['ship']['cooldown'];
        }
        if ($action->command === 'buy_ship' && in_array($actualShip['symbol'], $before['estimate']['fleet_symbols'] ?? [], true)) {
            throw new GameException('Purchase returned an existing ship identity. Reconcile without buying again.', uncertain: true);
        }
        if (in_array($action->command, ['flight_mode', 'dock', 'orbit'], true)) {
            if ($actualShip['nav']['systemSymbol'] !== $before['ship']['nav']['systemSymbol'] || $actualShip['nav']['waypointSymbol'] !== $before['ship']['nav']['waypointSymbol'] || ($action->command === 'flight_mode' && $actualShip['nav']['status'] !== $before['ship']['nav']['status'])) {
                throw new GameException('Stationary command unexpectedly changed location or navigation state.', uncertain: true);
            }
        }
        if ($action->command === 'survey' && collect($data['surveys'])->contains(fn ($survey) => $survey['symbol'] !== $before['ship']['nav']['waypointSymbol'])) {
            throw new GameException('Survey response names a different waypoint.', uncertain: true);
        }
        if (in_array($action->command, ['install_mount', 'remove_mount', 'install_module', 'remove_module'], true)) {
            $field = str_ends_with($action->command, 'mount') ? 'mounts' : 'modules';
            $expected = array_count_values(array_column($before['ship'][$field], 'symbol'));
            $symbol = $action->arguments['symbol'];
            $expected[$symbol] = ($expected[$symbol] ?? 0) + (str_starts_with($action->command, 'install_') ? 1 : -1);
            $expected = array_filter($expected);
            $observed = array_count_values(array_column($data[$field], 'symbol'));
            ksort($expected);
            ksort($observed);
            if ($expected !== $observed) {
                throw new GameException('Equipment multiplicity differs from the reserved action.', uncertain: true);
            }
            // Only a fresh full ship can establish capacities and all dependent capabilities after outfitting.
            $actualShip = $this->api->ship($agent, $action->ship_symbol);
            if ($actualShip[$field] !== $data[$field] || $actualShip['cargo'] !== $data['cargo']) {
                throw new GameException('Fresh ship and equipment response disagree. Retained response requires review.', uncertain: true);
            }
        }
        if (in_array($action->command, ['mine', 'mine_survey', 'siphon', 'buy', 'sell', 'transfer', 'jettison', 'supply', 'contract_deliver'], true)) {
            $expected = MiningPlanner::inventory($before['ship']);
            $mining = in_array($action->command, ['mine', 'mine_survey', 'siphon'], true);
            $resource = $action->command === 'siphon' ? 'siphon' : 'extraction';
            $symbol = $mining ? $data[$resource]['yield']['symbol'] : $action->arguments['symbol'];
            $units = $mining ? $data[$resource]['yield']['units'] : $action->arguments['units'];
            $expected[$symbol] = ($expected[$symbol] ?? 0) + (in_array($action->command, ['sell', 'transfer', 'jettison', 'supply', 'contract_deliver'], true) ? -$units : $units);
            $expected = array_filter($expected);
            $observed = MiningPlanner::inventory($actualShip);
            ksort($expected);
            ksort($observed);
            if ($expected !== $observed) {
                throw new GameException('Cargo response does not match the reserved trade or extraction yield.', uncertain: true);
            }
        }
        if ($action->command === 'refine') {
            $expected = MiningPlanner::inventory($before['ship']);
            foreach (['consumed' => -1, 'produced' => 1] as $field => $sign) {
                foreach ($data[$field] as $item) {
                    $expected[$item['tradeSymbol']] = ($expected[$item['tradeSymbol']] ?? 0) + $sign * $item['units'];
                }
            }
            $expected = array_filter($expected);
            $observed = MiningPlanner::inventory($actualShip);
            ksort($expected);
            ksort($observed);
            if ($expected !== $observed || collect($data['produced'])->contains(fn ($item) => $item['tradeSymbol'] !== $action->arguments['produce'])) {
                throw new GameException('Refinery cargo differs from the returned consumed and produced goods.', uncertain: true);
            }
        }
        if ($action->command === 'refuel' && ($action->arguments['fromCargo'] ?? false)) {
            CargoRefuel::confirm($before, $actualShip, $data['agent']['credits'], $data['transaction']['totalPrice']);
        }
        $target = null;
        $sourceTransferSample = null;
        if ($action->command === 'transfer') {
            $target = $before['estimate']['target_ship'];
            $expected = MiningPlanner::inventory($target);
            $expected[$symbol] = ($expected[$symbol] ?? 0) + $units;
            if ($v8) {
                $target = $transferEvidence['target_ship'] ?? $this->api->ship($agent, $target['symbol'], fresh: true);
            } else {
                $target['cargo'] = $this->api->ship($agent, $target['symbol'])['cargo'];
                $target = $this->api->shipData($target);
                $target['cooldown'] = $before['estimate']['target_ship']['cooldown'];
            }
            $observed = MiningPlanner::inventory($target);
            ksort($expected);
            ksort($observed);
            if ($expected !== $observed) {
                throw new GameException('Receiving cargo does not match the reserved transfer.', uncertain: true);
            }
            if ($v8 && ($transferEvidence || ! empty($run->plan['canary_pair_id']))) {
                $sourceTransferSample = $transferEvidence['ship'] ?? $this->api->ship($agent, $action->ship_symbol, fresh: true);
                if ($sourceTransferSample['cargo'] !== $actualShip['cargo']) {
                    throw new GameException('Source cargo read disagrees with the transfer receipt.', uncertain: true);
                }
                $actualShip = $sourceTransferSample;
            }
        }
        if ($action->command === 'flight_mode' && $actualShip['nav']['flightMode'] !== ($action->arguments['flightMode'] ?? $before['ship']['nav']['flightMode'])) {
            throw new GameException('Flight mode response differs from approval.', uncertain: true);
        }
        if (in_array($action->command, ['navigate', 'warp', 'jump'], true)) {
            $targetSystem = implode('-', array_slice(explode('-', $action->arguments['waypointSymbol']), 0, 2));
            if ($actualShip['nav']['waypointSymbol'] !== $action->arguments['waypointSymbol'] || $actualShip['nav']['systemSymbol'] !== $targetSystem || ($action->command === 'navigate' && $targetSystem !== $before['ship']['nav']['systemSymbol'])) {
                throw new GameException('Travel response does not match the approved waypoint and system.', uncertain: true);
            }
        }
        if ($action->command === 'supply') {
            $old = collect($before['estimate']['construction']['materials'])->firstWhere('tradeSymbol', $action->arguments['symbol']);
            $new = collect($data['construction']['materials'])->firstWhere('tradeSymbol', $action->arguments['symbol']);
            if (! $new || $new['required'] !== $old['required'] || $new['fulfilled'] < $old['fulfilled'] + $action->arguments['units'] || $new['fulfilled'] > $new['required']) {
                throw new GameException('Construction progress does not corroborate the reserved contribution.', uncertain: true);
            }
        }
        if (str_starts_with($action->command, 'contract_')) {
            $expectedContract = $before['contract'];
            match ($action->command) {
                'contract_accept' => $expectedContract['accepted'] = true,
                'contract_fulfill' => $expectedContract['fulfilled'] = true,
                'contract_deliver' => $expectedContract['terms']['deliver'][$action->arguments['term_index']]['unitsFulfilled'] += $action->arguments['units'],
            };
            if ($expectedContract !== $data['contract']) {
                throw new GameException('Contract response does not match the exact reserved transition.', uncertain: true);
            }
        }
        if (! empty($data['events'])) {
            if ($v8 && $action->command === 'navigate') {
                $this->assertNavigationReceipt($action, $data, $before);
            }
            try {
                $fresh = $this->api->ship($agent, $action->ship_symbol);
                foreach (['frame', 'engine', 'reactor'] as $component) {
                    $actualShip[$component] = $fresh[$component];
                }
            } catch (RequestDeferred $e) {
                if (! $v8 || $action->command !== 'navigate' || ($action->outcome['response_validated'] ?? null) !== true) {
                    throw $e;
                }
                // The damage event invalidates configuration even when its refresh must wait.
                FleetMetrics::as($action->ship_symbol, fn () => FleetMetrics::observe($e));
            }
        }
        $configurationMismatch = $action->command === 'buy_ship' && self::configurationHash($actualShip) !== self::configurationHash($before['estimate']['configuration']);
        $creditReceipt = isset($data['agent']) && CampaignSafety::isCreditCommand($action->command);
        $credits = $creditReceipt ? $data['agent']['credits'] : $before['credits'];
        $price = $data['transaction']['totalPrice'] ?? $data['transaction']['price'] ?? 0;
        $delta = $credits - $before['credits'];
        $discrepancy = in_array($action->command, ['buy', 'buy_ship', 'refuel', 'repair', 'install_mount', 'remove_mount', 'install_module', 'remove_module', 'jump'], true)
            ? $price > ($action->arguments['maxTotal'] ?? 0) || $credits < $before['estimate']['reserve'] || $delta !== -$price
            : ($action->command === 'sell' && ($price < ($action->arguments['minTotal'] ?? 0) || $delta !== $price));
        if (in_array($action->command, ['contract_accept', 'contract_fulfill'], true)) {
            $payment = $before['contract']['terms']['payment'][$action->command === 'contract_accept' ? 'onAccepted' : 'onFulfilled'];
            $discrepancy = $delta !== $payment || $credits < $before['estimate']['reserve'];
        }
        $discrepancy = $discrepancy || ($action->command === 'chart' && $delta < 0);
        if ($v8 && isset($before['estimate']['price_evidence']['price'])) {
            $quoted = $before['estimate']['price_evidence']['price'];
            $pct = app(CampaignSafety::class)->saturationPolicy($run)['reprice_tolerance_pct'] ?? 5;
            $discrepancy = $discrepancy || ($action->command === 'sell' ? $price * 100 < $quoted * (100 - $pct) : $price * 100 > $quoted * (100 + $pct));
        }
        $lateDrift = false;
        $cooldownDiscrepancy = in_array($action->command, ['scan_waypoints', 'scan_systems', 'scan_ships'], true) && ($data['cooldown']['totalSeconds'] ?? $data['cooldown']['remainingSeconds']) > ($action->arguments['maxCooldownSeconds'] ?? 900);
        $discrepancy = $discrepancy || $cooldownDiscrepancy;
        $discrepancy = $discrepancy || (in_array($action->command, ['navigate', 'warp'], true) && isset($action->arguments['maxSeconds']) && isset($actualShip['nav']['route']['departureTime']) && CarbonImmutable::parse($actualShip['nav']['route']['departureTime'])->diffInSeconds(CarbonImmutable::parse($actualShip['nav']['route']['arrival'])) > $action->arguments['maxSeconds']);
        $discrepancy = $discrepancy || ($action->command === 'refuel' && $actualShip['fuel']['current'] <= $before['ship']['fuel']['current']);
        $fuelDiscrepancy = false;
        if (in_array($action->command, ['navigate', 'warp'], true)) {
            $consumed = $before['ship']['fuel']['current'] - $actualShip['fuel']['current'];
            $fuelDiscrepancy = $consumed < 0 || $consumed > $before['estimate']['fuel_estimate'];
            if ($v8 && ($before['estimate']['route']['mode'] ?? null) === 'DRIFT') {
                $route = $before['estimate']['route'];
                $fuelDiscrepancy = $consumed !== 1 || $actualShip['fuel']['current'] < max(2, $route['uncertainty_reserve'] ?? 2);
                $lateDrift = CarbonImmutable::parse($actualShip['nav']['route']['arrival'])
                    ->gt(CarbonImmutable::parse($route['hard_deadline'])->subSeconds($route['margin_seconds']));
            }
            $discrepancy = $discrepancy || $fuelDiscrepancy;
        }
        if (in_array($action->command, ['buy', 'sell'], true) && (($data['transaction']['units'] ?? null) !== $action->arguments['units'] || ($data['transaction']['tradeSymbol'] ?? null) !== $action->arguments['symbol'])) {
            throw new GameException('Trade response does not match the reserved intent.', uncertain: true);
        }
        DB::transaction(function () use ($agent, $action, $data, $before, $actualShip, $credits, $price, $delta, $discrepancy, $fuelDiscrepancy, $configurationMismatch, $cooldownDiscrepancy, $run, $target, $creditReceipt, $v8, $lateDrift, $sourceTransferSample) {
            $action->refresh();
            if ($action->state === ActionState::Confirmed) {
                return;
            }
            $chain = app(StateChain::class);
            if ($v8 && CampaignSafety::isCreditCommand($action->command) && ! $chain->settleReservation($action->id, $delta)) {
                throw new GameException('Credit settlement requires the action reservation.', uncertain: true);
            }
            $receiptAt = isset($action->outcome['response_received_at']) ? CarbonImmutable::parse($action->outcome['response_received_at']) : now()->toImmutable();
            if ($actualShip) {
                $this->sync->saveShip($agent, $actualShip, full: false);
                if ($sourceTransferSample) {
                    $chain->recordFullShip($agent, $actualShip, now()->toImmutable(), 'transfer_source_get');
                } elseif (in_array($action->command, ['buy_ship', 'repair', 'install_mount', 'remove_mount', 'install_module', 'remove_module'], true)) {
                    $chain->recordFullShip($agent, $actualShip, $receiptAt, 'receipt:'.$action->command);
                } else {
                    $fields = array_intersect_key($actualShip, $data);
                    $chain->mergeReceipt($agent, $actualShip['symbol'], $action->command, $fields, $receiptAt,
                        fromCargo: ! empty($action->arguments['fromCargo']), damageEvent: ! empty($data['events']));
                }
            }
            if ($target) {
                $this->sync->saveShip($agent, $target, full: false);
                if ($v8) {
                    $chain->recordFullShip($agent, $target, now()->toImmutable(), 'transfer_receiver_get');
                    $leaseId = $before['estimate']['transfer_lease_id'];
                    $result = app(MiningCluster::class)->settleLease($agent, $leaseId, true, true);
                    if ($result['state'] !== 'confirmed') {
                        throw new GameException('Verified cargo transfer could not settle its lease.', uncertain: true);
                    }
                    $ready = $action->arguments['rendezvous_ready_at'] ?? null;
                    app(ExtractionCalibrator::class)->recordReceipt($agent, 'transfer', $actualShip['nav']['waypointSymbol'],
                        FuelPlanner::configuration($actualShip), [
                            'action_id' => $action->id, 'units' => $action->arguments['units'], 'symbol' => $action->arguments['symbol'],
                            'source_cooldown_seconds' => $sourceTransferSample['cooldown']['remainingSeconds'] ?? $data['cooldown']['remainingSeconds'] ?? null,
                            'receiver_cooldown_seconds' => $target['cooldown']['remainingSeconds'] ?? null,
                            'transfer_dwell_seconds' => $ready ? max(0, (int) CarbonImmutable::parse($ready)->diffInSeconds($receiptAt, false)) : null,
                        ]);
                } else {
                    $chain->mergeReceipt($agent, $target['symbol'], 'transfer', ['cargo' => $target['cargo']], $receiptAt);
                }
            }
            if ($action->command === 'flight_mode' && $run?->strategy === 'sell_aboard' && ! empty($run->plan['restore_cruise'])
                && $actualShip['nav']['flightMode'] === 'CRUISE') {
                $plan = $run->fresh()->plan;
                $plan['initial_configuration'] = FuelPlanner::configuration($actualShip);
                $plan['restore_cruise'] = false;
                $plan['restored_cruise'] = true;
                $run->update(['plan' => $plan]);
            }
            if ($action->command === 'survey') {
                foreach ($data['surveys'] as $signed) {
                    app(SurveyPool::class)->save($agent, $signed, $actualShip['symbol']);
                }
            }
            if ($creditReceipt) {
                $agent->update(['credits' => $credits, 'synced_at' => now()]);
            }
            $this->invalidateActionQuote($agent, $action, $before['ship']);
            if ($v8 && in_array($action->command, ['buy', 'sell'], true)) {
                $this->settleCorridorBatch($agent, $action, $data['transaction']['units'], $receiptAt);
            }
            if (($v8 || $run?->strategy === 'sell_aboard') && $action->command === 'refuel') {
                $this->consumeRunCreditHold($agent, $run, 'delivery_fuel', $price);
            }
            if ($v8 && in_array($action->command, ['mine', 'mine_survey', 'siphon', 'survey'], true)) {
                $kind = $action->command === 'mine_survey' ? 'mine' : $action->command;
                app(ExtractionCalibrator::class)->recordReceipt($agent, $kind, $actualShip['nav']['waypointSymbol'], FuelPlanner::configuration($actualShip),
                    $data + ['action_id' => $action->id, 'headroom_units' => $before['estimate']['cargo_free']]);
                $sites = app(ExtractionSites::class);
                $sites->markSendResult($action->id, 'confirmed');
                if (isset($data['modifiers'])) {
                    $sites->recordModifiers($agent, $actualShip['nav']['waypointSymbol'], $data['modifiers'], $receiptAt, 'extraction_receipt');
                }
            }
            $outcome = ['produced' => $data['produced'] ?? null, 'consumed' => $data['consumed'] ?? null, 'events' => $data['events'] ?? [], 'cargo_refuel' => $before['estimate']['cargo_refuel'] ?? null, 'contract' => $data['contract'] ?? null, 'credit_delta' => $delta, 'transaction_total' => $price, 'yield' => $data['extraction']['yield'] ?? $data['siphon']['yield'] ?? null,
                'units' => $data['transaction']['units'] ?? null, 'price_discrepancy' => $discrepancy && ! $fuelDiscrepancy && ! $cooldownDiscrepancy, 'cooldown_discrepancy' => $cooldownDiscrepancy, 'fuel_discrepancy' => $fuelDiscrepancy, 'delivered_ship' => $data['ship']['symbol'] ?? null, 'configuration_mismatch' => $configurationMismatch];
            if ($lateDrift) {
                $outcome['drift_arrival_review'] = true;
            }
            if ($action->command === 'survey') {
                $outcome['surveys_created'] = count($data['surveys']);
            }
            if (ExplorationPolicy::command($action->command) && isset($action->arguments['question'])) {
                $source = $action->command === 'chart' ? 'chart' : 'scan';
                $points = $source === 'chart' ? [$data['waypoint']] : $data['waypoints'];
                foreach ($points as $point) {
                    $this->sync->observe($agent, 'waypoint', $point['symbol'], $point, $source, $actualShip['symbol']);
                }
                $target = collect($points)->firstWhere('symbol', $action->arguments['waypointSymbol']);
                $outcome['exploration'] = ['question' => ExplorationPolicy::QUESTIONS[$action->arguments['question']],
                    'target' => $action->arguments['waypointSymbol'], 'coverage' => 'Only returned waypoints; no catalogue completeness claim.',
                    'returned_symbols' => array_column($points, 'symbol'), 'answered' => $target !== null,
                    'trait_present' => $target === null ? null : in_array($action->arguments['question'], array_column($target['traits'], 'symbol'), true),
                    'cooldown' => isset($data['cooldown']) ? $actualShip['cooldown'] : null, 'chart' => $data['chart'] ?? null,
                    'next' => $target ? 'Question answered. Reassess directories and routes under a new approval.' : 'Target was not returned. Traits remain unknown. Stop and reassess; no automatic rescan.'];
            }
            if (in_array($action->command, ['scan_systems', 'scan_waypoints', 'scan_ships', 'chart'], true) && ! isset($action->arguments['question'])) {
                $field = match ($action->command) {
                    'scan_systems' => 'systems', 'scan_ships' => 'ships', 'scan_waypoints' => 'waypoints', default => 'waypoint'
                };
                $rows = $action->command === 'chart' ? [$data['waypoint']] : $data[$field];
                foreach ($rows as $row) {
                    $kind = match ($field) {
                        'systems' => 'scanned_system', 'ships' => 'scanned_ship', default => 'waypoint'
                    };
                    $this->sync->observe($agent, $kind, $row['symbol'], $row, $action->command === 'chart' ? 'chart' : 'scan', $actualShip['symbol']);
                }
                $outcome['observation_count'] = count($rows);
            }
            if ($action->command === 'supply') {
                $this->sync->observe($agent, 'construction', $data['construction']['symbol'], $data['construction'], 'contribution', $actualShip['symbol']);
            }
            if (in_array($action->command, ['mine', 'mine_survey'], true)) {
                $this->sync->observe($agent, 'extraction', $actualShip['nav']['waypointSymbol'], [
                    'action_id' => $action->id, 'configuration' => FuelPlanner::configuration($actualShip), 'yield' => $outcome['yield'],
                    'cooldown' => $actualShip['cooldown'], 'cargo' => $actualShip['cargo'], 'events' => $data['events'] ?? [],
                    'survey_signature' => $action->arguments['surveySignature'] ?? null,
                ], 'extraction', $actualShip['symbol']);
            }
            if (in_array($action->command, ['navigate', 'warp'], true)) {
                $serverRoute = $actualShip['nav']['route'];
                $seconds = isset($serverRoute['departureTime']) ? CarbonImmutable::parse($serverRoute['departureTime'])->diffInSeconds(CarbonImmutable::parse($serverRoute['arrival'])) : null;
                $outcome['travel'] = ['from' => $action->before_state['ship']['nav']['waypointSymbol'], 'to' => $actualShip['nav']['waypointSymbol'],
                    'configuration' => FuelPlanner::configuration($actualShip), 'prediction' => $action->before_state['estimate']['fuel_estimate'] ?? null,
                    'flight_mode' => $action->before_state['ship']['nav']['flightMode'], 'tank_capacity' => $action->before_state['ship']['fuel']['capacity'],
                    'ship_configuration' => array_intersect_key($action->before_state['ship'], array_flip(['frame', 'engine', 'reactor', 'mounts', 'modules'])),
                    'distance' => $action->before_state['estimate']['route']['distance'] ?? null,
                    'from_coordinates' => $action->before_state['estimate']['route']['from_coordinates'] ?? null, 'to_coordinates' => $action->before_state['estimate']['route']['to_coordinates'] ?? null,
                    'predicted_seconds' => $action->before_state['estimate']['route']['estimated_seconds'] ?? null,
                    'server_duration_seconds' => $seconds !== null && $seconds >= 0 ? $seconds : null,
                    'actual_fuel' => $action->before_state['ship']['fuel']['current'] - $actualShip['fuel']['current'],
                    'reported_consumed' => $data['fuel']['consumed'] ?? null, 'server_route' => $actualShip['nav']['route'], 'events' => $data['events'] ?? []];
                $this->sync->observe($agent, 'travel', $actualShip['symbol'], $outcome['travel'], 'navigation', $actualShip['symbol']);
            }
            $positionReview = $v8 && $run->strategy === 'position' && ($lateDrift || ($fuelDiscrepancy && ($before['estimate']['route']['mode'] ?? null) === 'DRIFT'));
            $reviewRequired = ($discrepancy && ! $positionReview) || ($configurationMismatch && in_array($run?->strategy, [...CampaignProbeBootstrap::STRATEGIES, 'buy_fleet'], true));
            $action->update(['state' => ActionState::Confirmed, 'review_required' => $reviewRequired, 'outcome' => array_merge($action->fresh()->outcome ?? [], $outcome), 'settled_at' => now(), 'message' => $fuelDiscrepancy ? 'Actual fuel differed from the approved prediction. Reconcile and replan before further commands.' : ($discrepancy ? (ExplorationPolicy::command($action->command) ? 'Exploration exceeded the cooldown review threshold or changed credits unexpectedly. Review before further work.' : 'Execution differed from approved quote or reserve. Further work is paused.') : 'Confirmed by SpaceTraders.')]);
            if ($actualShip) {
                $chain->releaseRecoveryFuelHoldIfEmpty($agent, $actualShip['symbol'], $actualShip);
            }
            app(CreditAccounting::class)->record($agent, $action, $delta);
            if (isset($data['contract'])) {
                $this->sync->observe($agent, 'contract', $data['contract']['id'], $data['contract']);
            }
            if ($run) {
                app(CreditAccounting::class)->apply($run, $action, $action->command === 'buy_ship' ? $before['ship'] : $actualShip, $outcome, $delta, $price);
                if ($v8 && $run->strategy === 'trade') {
                    $index = (int) ($action->arguments['leg_index'] ?? 0);
                    if ($action->command === 'buy') {
                        $this->consumeRunCreditHold($agent, $run, 'trade_lot', $price);
                        if (($run->totals['leg_purchased'][$index] ?? 0) >= ($run->plan['legs'][$index]['approvedUnits'] ?? PHP_INT_MAX)) {
                            $chain->releaseRunHolds($agent, $run->id, 'trade_lot');
                        }
                    } elseif ($action->command === 'sell' && ($run->totals['leg_index'] ?? 0) > $index) {
                        $chain->releaseRunHolds($agent, $run->id, 'trade_lot');
                        app(AutomationRunner::class)->reserveTradeLot($agent, $run, $run->plan, $index + 1);
                    }
                }
                if ($action->command === 'buy_ship') {
                    app(CampaignProbeBootstrap::class)->recordDelivery($action);
                }
                if ($v8 && $action->command === 'navigate' && $actualShip['nav']['status'] === 'IN_TRANSIT') {
                    $run->update(['next_action_at' => CarbonImmutable::parse($actualShip['nav']['route']['arrival'])->addSecond()]);
                }
                if ($positionReview) {
                    $run->refresh();
                    $run->update(['totals' => array_replace($run->totals, ['position_phase' => 'cleanup', 'position_arrival_review_action' => $action->id]),
                        'state' => RunState::Running, 'blocked_reason' => null]);
                }
                if ($v8 && $action->command === 'flight_mode' && $actualShip['nav']['flightMode'] === 'CRUISE'
                    && ($reviewId = $run->fresh()->totals['position_arrival_review_action'] ?? null)) {
                    ShipAction::whereKey($reviewId)->update(['review_required' => true, 'message' => 'DRIFT travel exceeded its approved fuel or arrival bounds. CRUISE restored.']);
                    $run->update(['state' => RunState::Blocked, 'blocked_reason' => 'DRIFT receipt requires review. CRUISE restored.']);
                }
            }
        });
        if ($action->command === 'buy_ship' && ($configurationMismatch || (isset($action->arguments['site']) && app(MiningPlanner::class)->capability($actualShip) !== null))) {
            $action->update(['message' => 'Delivered configuration differs from the approved offer or is not operational for the selected setup. Inspect the delivered ship.']);
        }
    }

    private function assertNavigationReceipt(ShipAction $action, array $data, array $before): void
    {
        $route = $data['nav']['route'];
        $origin = $before['ship']['nav']['waypointSymbol'];
        $departure = $this->parseStrictTimestamp($route['departureTime'] ?? null);
        $arrival = $this->parseStrictTimestamp($route['arrival'] ?? null);
        $receivedAt = $this->parseStrictTimestamp($action->outcome['response_received_at'] ?? null);
        $skew = self::NAVIGATION_RECEIPT_CLOCK_SKEW_SECONDS;
        if (($route['origin']['symbol'] ?? null) !== $origin
            || ($route['destination']['symbol'] ?? null) !== $action->arguments['waypointSymbol']
            || ! $departure || ! $arrival || ! $receivedAt || ! $action->sent_at
            || $departure->lt($action->sent_at->subSeconds($skew)) || $departure->gt($receivedAt->addSeconds($skew))
            || $arrival->lte($departure)) {
            throw new GameException('Navigation receipt does not match the approved hop and transport time.', uncertain: true);
        }
    }

    private function consumeRunCreditHold(Agent $agent, AutomationRun $run, string $purpose, int $price): void
    {
        $hold = DB::table('credit_reservations')->where('agent_id', $agent->id)->where('reset_id', $run->reset_id)
            ->where('owner_key', 'run:'.$run->id)->where('purpose', $purpose)->whereNull('ship_action_id')->where('state', 'reserved');
        $remaining = max(0, (int) (clone $hold)->max('exposure') - $price);
        $hold->update(['exposure' => $remaining, 'state' => $remaining > 0 ? 'reserved' : 'released', 'updated_at' => now()]);
    }

    private function settleCorridorBatch(Agent $agent, ShipAction $action, int $units, CarbonImmutable $at): void
    {
        $event = DB::table('corridor_book_events')->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('ship_action_id', $action->id)->whereIn('state', ['sending', 'uncertain'])->first();
        if ($event && $event->units > $units) {
            $remainder = (array) $event;
            unset($remainder['id']);
            DB::table('corridor_book_events')->insert(array_replace($remainder, [
                'units' => $event->units - $units, 'state' => 'reserved', 'ship_action_id' => null,
                'tie_key' => $event->tie_key.'|remaining:'.$action->id, 'updated_at' => now(),
            ]));
        }
        app(CorridorBook::class)->settleReceipt($agent, $action->id, $units, $at->timestamp);
    }

    private function confirmScrap(Agent $agent, ShipAction $action, array $data, array $before): void
    {
        $credits = $data['agent']['credits'];
        $price = $data['transaction']['totalPrice'];
        $delta = $credits - $before['credits'];
        $review = $delta !== $price || $price < ($action->arguments['minTotal'] ?? 0) || $credits < $before['estimate']['reserve'];
        DB::transaction(function () use ($agent, $action, $data, $credits, $price, $delta, $review, $before) {
            $chain = app(StateChain::class);
            Ship::where('agent_id', $agent->id)->where('symbol', $action->ship_symbol)->delete();
            $chain->invalidateShipFields($agent, $action->ship_symbol);
            ShipAction::where('agent_id', $agent->id)->where('ship_symbol', $action->ship_symbol)->where('state', 'preview')->update(['state' => 'rejected', 'settled_at' => now(), 'message' => 'Ship was scrapped. This preview cannot be approved.']);
            // Phase 3 agent freshness: stamp synced_at only when the response contains an authoritative agent object.
            $agentUpdate = ['credits' => $credits];
            if (isset($data['agent'])) {
                $agentUpdate['synced_at'] = now();
            }
            $agent->update($agentUpdate);
            $this->sync->observe($agent, 'scrap', $action->ship_symbol, ['transaction' => $data['transaction'], 'removed_ship' => $action->ship_symbol, 'cargo_before' => $before['ship']['cargo'], 'equipment_before' => ['mounts' => $before['ship']['mounts'], 'modules' => $before['ship']['modules']]], 'scrap', $action->ship_symbol);
            $action->update(['state' => ActionState::Confirmed, 'review_required' => $review, 'settled_at' => now(),
                'outcome' => array_merge($action->fresh()->outcome ?? [], ['credit_delta' => $delta, 'transaction_total' => $price, 'removed_ship' => $action->ship_symbol, 'price_discrepancy' => $review]),
                'message' => $review ? 'Scrap confirmed. Returned credits differ from the approved quote or reserve; review before further work.' : 'Scrap confirmed by the authoritative transaction. Ship history is retained.']);
            $chain->releaseRecoveryFuelHold($agent, $action->ship_symbol);
            app(CreditAccounting::class)->record($agent, $action, $delta);
        });
    }

    public static function configurationHash(array $ship): ?string
    {
        if (! isset($ship['frame']['symbol'], $ship['reactor']['symbol'], $ship['engine']['symbol']) || ! is_array($ship['modules'] ?? null) || ! is_array($ship['mounts'] ?? null)) {
            return null;
        }
        $modules = array_column($ship['modules'], 'symbol');
        $mounts = array_column($ship['mounts'], 'symbol');
        if (count($modules) !== count($ship['modules']) || count($mounts) !== count($ship['mounts'])) {
            return null;
        }
        sort($modules, SORT_STRING);
        sort($mounts, SORT_STRING);

        return hash('sha256', json_encode([$ship['frame']['symbol'], $ship['reactor']['symbol'], $ship['engine']['symbol'], $modules, $mounts], JSON_THROW_ON_ERROR));
    }

    public function reconcile(Agent $agent, ShipAction $action): ShipAction
    {
        $budget = app(RequestBudget::class);
        $priority = RequestBudget::agentCaps() !== [] || $budget->isDraining($agent->name) ? 'settlement' : 'critical';

        return $this->locked($agent, function () use ($agent, $action) {
            $action->refresh();
            if (! $action->review_required && ! in_array($action->state, [ActionState::Uncertain, ActionState::Sending], true)) {
                return $action;
            }
            // Holding the process lock proves no local sender remains in flight.
            $knownOutcome = in_array($action->state, [ActionState::Confirmed, ActionState::Acknowledged], true) && $action->review_required;
            if (! $knownOutcome) {
                $run = AutomationRun::find($action->automation_run_id);
                if ($action->command === 'transfer' && $lease = $this->linkedTransferLease($agent, $action)) {
                    return $this->lock->lockedAgentAndShips($agent->id, [$lease->source_ship, $lease->receiver_ship], writer: true,
                        work: fn () => $this->reconcileLeasedTransfer($agent, $action, $run, $lease), seconds: 0);
                }
                if ($run?->plan_version === 8 && ! empty($action->outcome['response_validated']) && is_array($action->outcome['response'] ?? null)) {
                    try {
                        $this->confirm($agent, $action, $action->outcome['response'], $action->before_state, $run);

                        return $action->refresh();
                    } catch (GameException) {
                        // Keep unresolved exposure while collecting independent evidence.
                    }
                }
                $this->retainUncertainty($agent, $action);
                $action->update(['state' => ActionState::Uncertain]);
            }
            $balance = $this->sync->sync($agent, false, freshReset: true, freshFleet: true);
            $fleet = $this->api->ships($agent);
            $ship = collect($fleet)->firstWhere('symbol', $action->ship_symbol);
            if ($ship) {
                $ship = $this->sync->refreshShip($agent, $action->ship_symbol)->snapshot;
            }
            $evidence = ['reconciled_at' => now()->toIso8601String(), 'credits' => $balance, 'reconciled_agent_credits' => $balance,
                'ship' => $ship, 'actor_present' => $ship !== null, 'fleet' => $fleet, 'transactions' => []];
            if ($ship && in_array($action->command, ['sell', 'buy', 'refuel'], true)) {
                $marketData = $this->api->market($agent, $ship['nav']['waypointSymbol']);
                $evidence['transactions'] = array_key_exists('transactions', $marketData) ? $marketData['transactions'] : [];
                // Keep object/list changes visible to Eloquent's associative dirty check.
                $evidence['market_history_is_object'] = is_object($evidence['transactions']);
            }
            if ((ExplorationPolicy::command($action->command) || in_array($action->command, ['navigate', 'jump', 'warp'], true)) && isset($action->arguments['waypointSymbol'])) {
                $evidence['waypoint'] = $this->api->waypoint($agent, $action->arguments['waypointSymbol']);
                $this->sync->observe($agent, 'waypoint', $action->arguments['waypointSymbol'], $evidence['waypoint'], 'read', $ship['symbol'] ?? null);
            }
            $contractId = $action->arguments['contractId'] ?? ($action->automation_run_id ? AutomationRun::find($action->automation_run_id)?->plan['contract_plan']['contract']['id'] ?? null : null);
            if ($contractId) {
                $evidence['contract'] = $this->api->contract($agent, $contractId);
            }
            if ($action->command === 'buy_ship') {
                $evidence['transactions'] = $this->api->shipyard($agent, $action->arguments['waypointSymbol'])['transactions'] ?? [];
            }
            if ($action->command === 'negotiate') {
                $evidence['contracts'] = $this->api->contracts($agent);
            }
            if ($action->command === 'supply') {
                $point = $action->arguments['waypointSymbol'];
                $evidence['construction'] = $this->api->manualRead($agent, 'get-construction', ['systemSymbol' => implode('-', array_slice(explode('-', $point), 0, 2)), 'waypointSymbol' => $point]);
            }
            if ($ship && in_array($action->command, ['install_mount', 'remove_mount', 'install_module', 'remove_module'], true)) {
                $evidence['installed'] = $this->api->manualRead($agent, str_ends_with($action->command, 'mount') ? 'get-mounts' : 'get-ship-modules', ['shipSymbol' => $action->ship_symbol]);
            }
            $idempotentSettled = ($ship && $action->command === 'dock' && $ship['nav']['status'] === 'DOCKED') || ($ship && $action->command === 'orbit' && $ship['nav']['status'] === 'IN_ORBIT');
            $action->update(['state' => $knownOutcome ? $action->state : ($idempotentSettled ? ActionState::Confirmed : ActionState::Uncertain),
                'outcome' => array_merge($action->outcome ?? [], $evidence), 'message' => $knownOutcome ? 'Confirmed transaction retained. Review the price discrepancy and fresh evidence before takeover.' : ($idempotentSettled ? 'Idempotent navigation state reconciled.' : 'Fresh state recorded. Outcome cannot be proven automatically; inspect evidence and acknowledge before takeover.'), 'settled_at' => now()]);

            return $action;
        }, priority: $priority);
    }

    public function acknowledgeUncertain(Agent $agent, ShipAction $action, string $note = ''): ShipAction
    {
        $budget = app(RequestBudget::class);
        $priority = RequestBudget::agentCaps() !== [] || $budget->isDraining($agent->name) ? 'settlement' : 'critical';

        return $this->locked($agent, function () use ($agent, $action, $note) {
            $agent->refresh();
            $action->refresh();
            $reconciledAt = $action->outcome['reconciled_at'] ?? null;
            if ($action->agent_id !== $agent->id || $action->reset_id !== $agent->reset_id
                || (! $action->review_required && $action->state !== ActionState::Uncertain)
                || ! $reconciledAt || now()->subMinutes(3)->greaterThan($reconciledAt)) {
                throw new GameException('Reconcile fresh upstream evidence first.');
            }
            $confirmed = $action->state === ActionState::Confirmed;
            $reservation = $action->state === ActionState::Uncertain && DB::table('credit_reservations')
                ->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('ship_action_id', $action->id)
                ->whereIn('state', ['reserved', 'pending', 'sending', 'uncertain'])->exists();
            $balance = null;
            if ($reservation) {
                $this->sync->guardReset($agent, fresh: true);
                $balance = $this->api->agent($agent, fresh: true)['credits'];
            }
            $reconciledBalance = ! $reservation && $action->state === ActionState::Uncertain
                && CampaignSafety::isCreditCommand($action->command)
                && is_int($action->outcome['reconciled_agent_credits'] ?? null)
                ? (int) $action->outcome['reconciled_agent_credits']
                : null;

            return DB::transaction(function () use ($agent, $action, $note, $reconciledAt, $confirmed, $balance, $reconciledBalance) {
                // Read the original JSON so acknowledging evidence preserves object/list shape.
                $outcome = (array) json_decode($action->getRawOriginal('outcome') ?? '');
                $review = ['operator_note' => $note, 'unresolved_adjustment' => ! $confirmed, 'reviewed_at' => now()->toIso8601String(),
                    'reviewed_reconciled_at' => $reconciledAt];
                if ($balance !== null) {
                    $accounting = app(CreditAccounting::class);
                    $accounting->baseline($agent);
                    $delta = $balance - $agent->credits;
                    $agent->update(['credits' => $balance, 'connection_state' => 'connected', 'synced_at' => now()]);
                    $label = "Action {$action->id} acknowledgement. ";
                    $accounting->recordBalanceReview($agent, $label.mb_substr($note, 0, 500 - strlen($label)), recordZero: true);
                    app(StateChain::class)->settleReservation($action->id, $delta);
                    $review += ['reviewed_agent_credits' => $balance, 'observed_credit_delta' => $delta];
                } elseif ($reconciledBalance !== null) {
                    $agent->update(['credits' => $reconciledBalance, 'connection_state' => 'connected', 'synced_at' => now()]);
                }
                if (! $confirmed && in_array($action->command, ['buy', 'sell'], true)) {
                    // Use the reconciliation time as a conservative landing time.
                    app(CorridorBook::class)->acknowledgeAction($agent, $action->id, $this->reconciledTradeUnits($action, $outcome), CarbonImmutable::parse($reconciledAt)->timestamp);
                }
                $action->update(['state' => $confirmed ? ActionState::Confirmed : ActionState::Acknowledged, 'review_required' => false,
                    'message' => $confirmed ? 'Operator reviewed the confirmed transaction discrepancy.' : 'Operator acknowledged unresolved outcome; never replay this intent.',
                    'outcome' => array_replace($outcome, $review)]);
                $ship = $action->ship_symbol !== ''
                    ? Ship::where('agent_id', $agent->id)->where('symbol', $action->ship_symbol)->first()
                    : null;
                $reviewedHauler = ! empty($action->outcome['configuration_mismatch']) && self::reviewedHaulerMismatch($action);
                $runs = AutomationRun::where('agent_id', $action->agent_id)->where('reset_id', $action->reset_id)->whereNotNull('active_agent_id')
                    ->when($reviewedHauler, fn ($query) => $query->where(fn ($runs) => $runs->whereKey($action->automation_run_id)
                        ->orWhere('ship_symbol', CampaignProbeBootstrap::deliveredShip($action))));
                $stoppedRuns = $runs->lockForUpdate()->get();
                $allowances = $stoppedRuns->pluck('plan.mesh_allowance')->filter()->unique();
                $runs->update(['state' => 'stopped', 'stop_requested_at' => now(), 'active_agent_id' => null, 'blocked_reason' => 'Unresolved adjustment acknowledged. New preview required.']);
                DB::afterCommit(function () use ($allowances) {
                    foreach ($allowances as $allowance) {
                        app(MeshGovernor::class)->release($allowance);
                    }
                });
                $runner = app(AutomationRunner::class);
                foreach ($stoppedRuns as $run) {
                    if ($run->plan_version !== 8 && $run->strategy !== 'sell_aboard') {
                        continue;
                    }
                    $run->refresh();
                    $isReconciledShip = $run->ship_symbol === $action->ship_symbol;
                    $runShip = $isReconciledShip ? $ship : Ship::where('agent_id', $agent->id)->where('symbol', $run->ship_symbol)->first();
                    $cargoUnits = $runShip?->snapshot['cargo']['units'] ?? null;
                    $hasCargo = $isReconciledShip
                        ? ($runShip ? $cargoUnits !== 0 : ($action->outcome['actor_present'] ?? null) !== false)
                        : $runShip && ($cargoUnits > 0 || AutomationRunner::hasTradeCargo($run));
                    $runner->settleStoppedRunHolds($agent, $run, $hasCargo);
                }
                if ($ship) {
                    app(StateChain::class)->releaseRecoveryFuelHoldIfEmpty($agent, $action->ship_symbol, $ship->snapshot);
                } elseif ($action->ship_symbol !== '' && ($action->outcome['actor_present'] ?? null) === false) {
                    app(StateChain::class)->releaseRecoveryFuelHold($agent, $action->ship_symbol);
                }

                return $action;
            });
        }, priority: $priority);
    }

    private function reconciledTradeUnits(ShipAction $action, array $outcome): ?int
    {
        $before = json_decode($action->getRawOriginal('before_state') ?? '')->ship ?? null;
        $after = $outcome['ship'] ?? null;
        $good = $action->arguments['symbol'] ?? null;
        $requested = $action->arguments['units'] ?? null;
        $market = $before->nav->waypointSymbol ?? null;
        if (! $action->sent_at || ! $this->isTradeEvidenceSymbol($good, self::TRADE_SYMBOL_PATTERN)
            || ! $this->isTradeEvidenceSymbol($market, self::WAYPOINT_SYMBOL_PATTERN)
            || ! $this->isTradeEvidenceSymbol($action->ship_symbol, self::SHIP_SYMBOL_PATTERN) || ! is_int($requested) || $requested <= 0
            || (property_exists($before, 'symbol') && $before->symbol !== $action->ship_symbol)
            || ($after->symbol ?? null) !== $action->ship_symbol || ($after->nav->waypointSymbol ?? null) !== $market) {
            return null;
        }
        $beforeInventory = $this->reconciliationInventory($before->cargo ?? null);
        $afterInventory = $this->reconciliationInventory($after->cargo ?? null);
        if ($beforeInventory === null || $afterInventory === null) {
            return null;
        }
        $delta = ($afterInventory[$good] ?? 0) - ($beforeInventory[$good] ?? 0);
        $cargoUnits = $action->command === 'sell' ? -$delta : $delta;
        unset($beforeInventory[$good], $afterInventory[$good]);
        ksort($beforeInventory);
        ksort($afterInventory);
        if ($beforeInventory !== $afterInventory || $cargoUnits < 0 || $cargoUnits > $requested) {
            return null;
        }

        $expectedType = $action->command === 'buy' ? 'PURCHASE' : 'SELL';
        $hasStoredResponse = array_key_exists('response', $outcome)
            || array_key_exists('body_sha256', $outcome) || array_key_exists('response_received_at', $outcome)
            || array_key_exists('response_validated', $outcome);
        if ($hasStoredResponse) {
            $bodyHash = $outcome['body_sha256'] ?? null;
            $receipt = $outcome['response']->transaction ?? null;
            if (($outcome['response_validated'] ?? null) !== true
                || (array_key_exists('body_sha256', $outcome) && (! is_string($bodyHash) || preg_match('/\A[0-9a-f]{64}\z/', $bodyHash) !== 1))
                || (array_key_exists('response_received_at', $outcome) && $this->parseStrictTimestamp($outcome['response_received_at']) === null)
                || ($receipt->waypointSymbol ?? null) !== $market || ($receipt->tradeSymbol ?? null) !== $good
                || ($receipt->type ?? null) !== $expectedType || ($receipt->shipSymbol ?? null) !== $action->ship_symbol
                || $cargoUnits <= 0 || ($receipt->units ?? null) !== $cargoUnits
                || $this->parseStrictTimestamp($receipt->timestamp ?? null) === null) {
                return null;
            }
        }

        $transactions = array_key_exists('transactions', $outcome) ? $outcome['transactions'] : [];
        if (! is_array($transactions) || ! array_is_list($transactions)
            || ($outcome['market_history_is_object'] ?? false) !== false) {
            return null;
        }
        $matched = false;
        foreach ($transactions as $transaction) {
            if (! is_object($transaction) || ! in_array($transaction->type ?? null, ['PURCHASE', 'SELL'], true)
                || ! $this->isTradeEvidenceSymbol($transaction->shipSymbol ?? null, self::SHIP_SYMBOL_PATTERN)
                || ! $this->isTradeEvidenceSymbol($transaction->waypointSymbol ?? null, self::WAYPOINT_SYMBOL_PATTERN)
                || ! $this->isTradeEvidenceSymbol($transaction->tradeSymbol ?? null, self::TRADE_SYMBOL_PATTERN)) {
                return null;
            }
            if ($transaction->shipSymbol !== $action->ship_symbol
                || $transaction->waypointSymbol !== $market || $transaction->tradeSymbol !== $good
                || $transaction->type !== $expectedType) {
                continue;
            }
            $timestamp = $this->parseStrictTimestamp($transaction->timestamp ?? null);
            if ($timestamp === null) {
                return null;
            }
            if ($timestamp->lt($action->sent_at)) {
                continue;
            }
            $units = $transaction->units ?? null;
            if ($matched || ! is_int($units) || $units <= 0 || $units !== $cargoUnits) {
                return null;
            }
            $matched = true;
        }

        return $cargoUnits;
    }

    private function reconciliationInventory(mixed $cargo): ?array
    {
        $inventory = $cargo->inventory ?? null;
        if (! is_array($inventory) || ! array_is_list($inventory)) {
            return null;
        }
        $unitsBySymbol = [];
        foreach ($inventory as $item) {
            $symbol = $item->symbol ?? null;
            $units = $item->units ?? null;
            if (! $this->isTradeEvidenceSymbol($symbol, self::TRADE_SYMBOL_PATTERN) || ! is_int($units) || $units < 0 || isset($unitsBySymbol[$symbol])) {
                return null;
            }
            $unitsBySymbol[$symbol] = $units;
        }
        $total = array_sum($unitsBySymbol);
        if (! is_int($total) || (property_exists($cargo, 'units') && $cargo->units !== $total)) {
            return null;
        }

        return $unitsBySymbol;
    }

    private function isTradeEvidenceSymbol(mixed $value, string $pattern): bool
    {
        return is_string($value) && strlen($value) <= 80 && preg_match($pattern, $value) === 1;
    }

    private function parseStrictTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,9})?(Z|[+-]\d{2}:\d{2})\z/', $value, $parts)) {
            return null;
        }
        [, $year, $month, $day, $hour, $minute, $second, $offset] = $parts;
        if (! checkdate((int) $month, (int) $day, (int) $year) || (int) $hour > 23 || (int) $minute > 59 || (int) $second > 59
            || ($offset !== 'Z' && ((int) substr($offset, 1, 2) > 23 || (int) substr($offset, 4, 2) > 59))) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    private function linkedTransferLease(Agent $agent, ShipAction $action): ?object
    {
        $arguments = $action->arguments;
        $leaseId = $arguments['transfer_lease_id'] ?? $arguments['transferLeaseId'] ?? null;

        return DB::table('transfer_leases')->where('agent_id', $agent->id)->where('reset_id', $action->reset_id)
            ->where('source_run_id', $action->automation_run_id)->where('source_ship', $action->ship_symbol)
            ->where('receiver_ship', $arguments['targetShip'] ?? null)->where('good', $arguments['symbol'] ?? null)->where('units', $arguments['units'] ?? null)
            ->where(fn ($query) => $query->where('ship_action_id', $action->id)->when($leaseId !== null,
                fn ($query) => $query->orWhere(fn ($unbound) => $unbound->where('id', $leaseId)->whereNull('ship_action_id'))))
            ->first();
    }

    private function reconcileLeasedTransfer(Agent $agent, ShipAction $action, ?AutomationRun $run, object $lease): ShipAction
    {
        $cluster = app(MiningCluster::class);
        if ($action->state === ActionState::Sending || $lease->state === 'sending') {
            DB::transaction(function () use ($agent, $action, $cluster, $lease) {
                $cluster->settleLease($agent, $lease->id, false, false);
                $action->update(['state' => ActionState::Uncertain]);
            });
        }
        $this->sync->guardReset($agent, fresh: true);
        if ($action->reset_id !== $agent->reset_id) {
            return $action;
        }
        $before = $action->before_state;
        $sourceBefore = $before['ship'] ?? [];
        $targetBefore = $before['estimate']['target_ship'] ?? $before['target_ship'] ?? [];
        $source = $this->sync->refreshShip($agent, $action->ship_symbol, fresh: true)->snapshot;
        $target = $this->sync->refreshShip($agent, $lease->receiver_ship, fresh: true)->snapshot;
        $good = $lease->good;
        $units = $lease->units;
        $sourceDelta = isset($sourceBefore['cargo']['inventory'])
            ? (MiningPlanner::inventory($source)[$good] ?? 0) - (MiningPlanner::inventory($sourceBefore)[$good] ?? 0) : null;
        $receiverDelta = isset($targetBefore['cargo']['inventory'])
            ? (MiningPlanner::inventory($target)[$good] ?? 0) - (MiningPlanner::inventory($targetBefore)[$good] ?? 0) : null;
        $exactSource = $sourceDelta === -$units;
        $exactReceiver = $receiverDelta === $units;
        $evidence = ['reconciled_at' => now()->toIso8601String(), 'ship' => $source, 'target_ship' => $target,
            'transfer_deltas' => ['source' => $sourceDelta, 'receiver' => $receiverDelta]];
        if ($exactSource && $exactReceiver && $run?->plan_version === 8 && $lease->ship_action_id === $action->id
            && ($before['estimate']['transfer_lease_id'] ?? null) === $lease->id && ! empty($action->outcome['response_validated'])
            && is_array($action->outcome['response'] ?? null)) {
            try {
                $this->confirm($agent, $action, $action->outcome['response'], $before, $run, $evidence);

                return $action->refresh();
            } catch (GameException) {
                // Both exact lot deltas can still settle independently of the retained response.
            }
        }

        return DB::transaction(function () use ($action, $agent, $run, $lease, $cluster, $source, $exactSource, $exactReceiver, $evidence) {
            $action->refresh();
            if ($action->state !== ActionState::Uncertain) {
                return $action;
            }
            $result = $cluster->settleLease($agent, $lease->id, $exactSource && $run !== null, $exactReceiver);
            $confirmed = $exactSource && $exactReceiver && $run && $result['state'] === 'confirmed';
            $outcome = array_replace($action->outcome ?? [], $evidence);
            $action->update(['state' => $confirmed ? ActionState::Confirmed : ActionState::Uncertain, 'outcome' => $outcome,
                'message' => $confirmed ? 'Transfer confirmed by both ship inventories.' : 'Transfer remains uncertain. Both exact cargo changes are required.', 'settled_at' => now()]);
            if ($confirmed) {
                app(StateChain::class)->releaseRecoveryFuelHoldIfEmpty($agent, $action->ship_symbol, $source);
                if ($lease->ship_action_id === null) {
                    DB::table('transfer_leases')->where('id', $lease->id)->whereNull('ship_action_id')->update(['ship_action_id' => $action->id]);
                }
                if (! isset($action->arguments['transfer_lease_id']) && ! isset($action->arguments['transferLeaseId'])) {
                    $action->update(['arguments' => $action->arguments + ['transfer_lease_id' => $lease->id]]);
                }
                app(CreditAccounting::class)->apply($run, $action, $source, $outcome + ['units' => $lease->units], 0, 0);
            }

            return $action;
        });
    }

    public function block(AutomationRun $run, string $reason): void
    {
        if (! AutomationRun::whereKey($run->id)->whereNotIn('state', [RunState::Stopped, RunState::Completed])
            ->update(['state' => RunState::Blocked, 'blocked_reason' => $reason])) {
            return;
        }
        $ship = Ship::where('agent_id', $run->agent_id)->where('symbol', $run->ship_symbol)->first()?->snapshot;
        app(CycleAccounting::class)->record($run, $ship);
    }
}

class AcquisitionDeadlineReached extends GameException
{
    public function __construct(public readonly int $legIndex)
    {
        parent::__construct('The acquisition deadline was reached before transport.');
    }
}

class CommandLockModeChanged extends GameException {}
