<?php

namespace App\Gameplay;

use App\Enums\ActionState;
use App\Enums\RunState;
use App\Jobs\ExecuteShipCommand;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\LocalEngine;
use App\Models\ReconMission;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Services\SpaceTradersClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShipCommandExecutor
{
    public function __construct(private HostLock $lock, private CommandRules $rules, private SpaceTradersApi $api, private AgentSynchronizer $sync, private EngineGate $gate) {}

    public function locked(Agent $agent, callable $work, string $priority = 'critical'): mixed
    {
        return $this->lock->run('agent-'.$agent->id, fn () => app(SpaceTradersClient::class)->scope($work, priority: $priority));
    }

    public function preview(Agent $agent, string $ship, string $command, array $arguments, string $key): ShipAction
    {
        if (in_array($command, ['contract_accept', 'contract_fulfill'], true)) {
            $ship = '';
        }

        return $this->locked($agent, function () use ($agent, $ship, $command, $arguments, $key) {
            $existing = ShipAction::where('agent_id', $agent->id)->where('key', $key)->first();
            if ($existing) {
                if ($existing->ship_symbol !== $ship || $existing->command !== $command || $existing->arguments !== $arguments) {
                    throw new GameException('This submission key already belongs to another intent.');
                }

                return $existing;
            }
            $this->clear($agent);
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
        return $this->locked($agent, function () use ($agent, $action) {
            $agent->refresh();
            $action->refresh();
            if ($action->state !== ActionState::Preview) {
                return $action;
            }
            $this->clear($agent);
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

    public function clear(Agent $agent, ?int $except = null, bool $defer = false, ?string $ship = null): void
    {
        $others = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->when($except, fn ($q) => $q->whereKeyNot($except));
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

    public function assertConfigurationReviewed(Agent $agent, string $ship): void
    {
        $mismatches = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('command', 'buy_ship')->where('outcome->configuration_mismatch', true)->get();
        foreach ($mismatches as $action) {
            $delivered = CampaignProbeBootstrap::deliveredShip($action);
            if (! self::reviewedHaulerMismatch($action) || $delivered === $ship) {
                throw new GameException('Delivered ship configuration differs from approval. Reconcile and review the purchase before using this ship.');
            }
        }
    }

    public static function reviewedHaulerMismatch(ShipAction $action): bool
    {
        if (($action->arguments['shipType'] ?? null) !== 'SHIP_LIGHT_HAULER' || $action->review_required
            || ! in_array($action->state, [ActionState::Confirmed, ActionState::Acknowledged], true)
            || ! CampaignProbeBootstrap::deliveredShip($action)
            || empty($action->outcome['operator_note']) || empty($action->outcome['reviewed_at']) || empty($action->outcome['reconciled_at'])) {
            return false;
        }
        try {
            $review = CarbonImmutable::parse($action->outcome['reviewed_at']);
            $reconciled = CarbonImmutable::parse($action->outcome['reviewed_reconciled_at'] ?? $action->outcome['reconciled_at']);
            $linkage = app(CampaignProbeBootstrap::class)->linkage($action);

            return $linkage && $linkage[0]->plan_version === 7 && $review->gte($reconciled) && $review->lte($reconciled->addMinutes(3))
                && ($action->state === ActionState::Confirmed || isset($action->outcome['delivery_transaction']));
        } catch (\Throwable) {
            return false;
        }
    }

    private function assertManualPurchaseSlot(Agent $agent, ShipAction $action): void
    {
        if (! $action->automation_run_id && $action->command === 'buy_ship'
            && in_array($action->arguments['shipType'] ?? null, CampaignProbeBootstrap::PURCHASE_TYPES, true)) {
            app(CampaignProbeBootstrap::class)->assertSlot($agent, ignoreActionId: $action->id);
        }
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
        app(CampaignSafety::class)->check($run, $agent, $action->command, $action->arguments, boundaryOnly: true);
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
        $this->locked($agent, fn () => $this->executeLocked($agent, $action));
    }

    /**
     * Core execution path. Caller must hold the agent lock and must NOT be inside a DB transaction.
     * Optional $prefetched carries verified pre-write reads from AutomationRunner::advance (<=10s old).
     */
    public function executeLocked(Agent $agent, ShipAction $action, ?array $prefetched = null): void
    {
        $action->refresh();
        if ($action->state !== ActionState::Pending) {
            return;
        }
        $run = $action->automation_run_id ? AutomationRun::find($action->automation_run_id) : null;
        if (isset($action->outcome['body_sha256']) || isset($action->outcome['response_received_at'])) {
            $action->update(['state' => ActionState::Uncertain, 'message' => 'A write receipt already exists on this pending intent. Reconcile without replay.', 'settled_at' => now()]);
            if ($run) {
                $this->block($run, 'Retained write receipt requires reconciliation before further work.');
            }

            return;
        }
        $received = false;
        $reserved = false;
        $recon = $run?->strategy === 'gate_recon';
        $outbound = $recon && isset($action->arguments['recon_outbound_hash']);
        $refusedBeforeTransport = false;
        $paced = $run && RequestBudget::agentCaps() !== [];
        $loaded = $paced && AutomationRunner::hasTradeCargo($run);
        $buyPhase = ($run?->totals['phase'] ?? null) === 'buy';
        $budget = app(RequestBudget::class);
        if ($loaded && $buyPhase && in_array($action->command, ['buy', 'dock', 'refuel'], true) && $budget->ordinaryCapReached($agent->name)) {
            $action->update(['state' => ActionState::Rejected, 'message' => 'Request budget ended loading; unsent intent released for cargo settlement.', 'settled_at' => now()]);

            return;
        }
        $recovery = $run && (! empty($run->plan['sell_only']) || $action->command === 'sell');
        $settlementScope = ($recovery && (! empty($run->plan['mesh_settlement_only']) || $paced || $budget->isDraining($agent->name)))
            || ($loaded && ! $buyPhase && in_array($action->command, ['orbit', 'navigate', 'dock', 'refuel'], true));
        $guardedMovement = $run?->campaign_episode_id && (isset($run->plan['mesh_work'])
            || in_array($run->strategy, ['scout', 'sell_aboard', ...CampaignProbeBootstrap::STRATEGIES], true))
            && in_array($action->command, ['navigate', 'orbit', 'dock', 'refuel'], true);
        $work = function () use ($agent, $action, $run, $prefetched, &$received, &$reserved, $recon, $outbound, $guardedMovement, &$refusedBeforeTransport) {
            try {
                $this->gate->check($action->engine_session_id);
                $this->clear($agent, $action->id, defer: $run !== null, ship: $run ? $action->ship_symbol : null);
                $this->assertManualPurchaseSlot($agent, $action);
                if ($action->reset_id !== $agent->reset_id || $action->expires_at->isPast() || ($run && ($run->stop_requested_at || $run->expires_at->isPast() || ! $run->active_agent_id))) {
                    throw new GameException('Intent expired, reset or stopped before execution.');
                }
                if ($outbound) {
                    app(GateRecon::class)->validateOutboundIntent($run, $action);
                }
                $fresh = $this->rules->refresh($agent, $action->ship_symbol, $action->command, $action->arguments, $run, $prefetched);
                if ($run && $action->command === 'buy') {
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
                DB::transaction(function () use ($action, $run, $before, $agent, $outbound) {
                    $this->gate->check($action->engine_session_id);
                    $this->assertManualPurchaseSlot($agent->fresh(), $action);
                    if ($outbound) {
                        app(GateRecon::class)->validateOutboundIntent($run, $action);
                    }
                    if ($run) {
                        app(CampaignSafety::class)->check($run, $agent, $action->command, $action->arguments, boundaryOnly: true, preflightPrice: $before['estimate']['purchasePrice'] ?? null);
                    }
                    if ($action->expires_at->isPast()) {
                        throw new GameException('Intent expired during preflight. Create a fresh preview.');
                    }
                    if ($run && ! AutomationRun::whereKey($run->id)->whereNull('stop_requested_at')->whereNotNull('active_agent_id')->where('expires_at', '>', now())->update(['heartbeat_at' => now()])) {
                        throw new GameException('Run stopped or expired before this action was reserved.');
                    }
                    if (! ShipAction::whereKey($action->id)->where('state', 'pending')->update(['state' => 'sending', 'before_state' => json_encode($before), 'sent_at' => now()] + ($run?->strategy === 'gate_recon' ? ['outcome->transport_reservations' => (int) ($action->fresh()->outcome['transport_reservations'] ?? 0) + 1] : []))) {
                        throw new GameException('Action was already reserved.');
                    }
                });
                $reserved = true;
                $action->refresh();
                // The provider request budget may wait after the action is reserved.
                $beforeTransport = match (true) {
                    $outbound => function () use ($run, $action, &$refusedBeforeTransport) {
                        $refusedBeforeTransport = true;
                        app(GateRecon::class)->validateOutboundIntent($run, $action);
                        $refusedBeforeTransport = false;
                    },
                    $action->command === 'buy_ship' && ($run || in_array($action->arguments['shipType'] ?? null, CampaignProbeBootstrap::PURCHASE_TYPES, true)) => function () use ($run, $agent, $action, &$refusedBeforeTransport) {
                        $refusedBeforeTransport = true;
                        $this->checkPurchaseTransport($agent, $action, $run);
                        $refusedBeforeTransport = false;
                    },
                    $guardedMovement => function () use ($run, $agent, $action, &$refusedBeforeTransport) {
                        $refusedBeforeTransport = true;
                        $this->checkCampaignTransport($agent, $action, $run);
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
            } catch (RequestDeferred $e) {
                if ($refusedBeforeTransport && ($action->command === 'buy_ship' || $guardedMovement)) {
                    $this->rejectUnsentPurchase($action, $run, $e->getMessage());

                    return;
                }
                FleetMetrics::as($action->ship_symbol ?: null, fn () => FleetMetrics::observe($e));
                $action->refresh();
                if ($received) {
                    $action->update(['state' => ActionState::Uncertain, 'message' => 'Write response retained; post-write refresh was deferred by the request budget. Reconcile without replay.', 'settled_at' => now()]);
                    if ($run) {
                        $this->block($run, 'Post-write refresh deferred. Reconcile the retained response before further work.');
                    }

                    return;
                }
                $markers = $recon && $reserved ? ['outcome->pretransport_deferrals' => (int) ($action->outcome['pretransport_deferrals'] ?? 0) + 1] : [];
                // Only a budget deferral before the write starts can return to pending.
                if ($action->expires_at->isFuture() && ! ExplorationPolicy::command($action->command) && empty($action->fresh()->outcome['stop_requested_at'])) {
                    $action->update(['state' => ActionState::Pending, 'sent_at' => null, 'message' => $e->getMessage()] + $markers);
                } else {
                    $action->update(['state' => ActionState::Rejected, 'message' => ExplorationPolicy::command($action->command) ? 'Exploration stopped at the request budget. Create a fresh preview; no automatic retry.' : 'Intent expired while waiting for the request budget.', 'settled_at' => now()] + $markers + ($recon ? $this->localRefusal('budget', $e->getMessage()) : []));
                    if ($run) {
                        $this->block($run, ExplorationPolicy::command($action->command) ? 'Exploration stopped at the request budget. Create a fresh preview; no automatic retry.' : 'Intent expired while waiting for the request budget.');
                    }
                }
            } catch (GameException $e) {
                if ($refusedBeforeTransport && ($action->command === 'buy_ship' || $guardedMovement)) {
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
                if (in_array($action->command, ['mine', 'mine_survey'], true) && isset($action->arguments['surveySignature']) && in_array($e->apiCode, [4221, 4224], true)) {
                    app(SurveyPool::class)->invalidate($agent, $action->arguments['surveySignature'], $e->apiCode === 4224);
                }
                // Rate-budget failure before transport is safe to retry only while pending.
                if ($action->state === ActionState::Pending && ! ExplorationPolicy::command($action->command) && $e->retryAfter > 0 && $action->expires_at->isFuture() && $e->apiCode !== 4113 && $agent->fresh()?->connection_state !== 'invalid_credential') {
                    $action->update(['message' => $e->getMessage()]);

                    return;
                }
                $uncertain = $action->state === ActionState::Sending && ($received || $e->uncertain);
                $localRefusal = [];
                if ($refusedBeforeTransport) {
                    $localRefusal = $this->localRefusal('pretransport', $e->getMessage()) + [
                        'sent_at' => null,
                        'outcome->pretransport_deferrals' => (int) ($action->outcome['pretransport_deferrals'] ?? 0) + 1,
                    ];
                } elseif (($recon || isset($run?->plan['mesh_work'])) && $action->state === ActionState::Pending) {
                    $localRefusal = $this->localRefusal('preflight', $e->getMessage());
                }
                $action->update(['state' => $uncertain ? ActionState::Uncertain : ActionState::Rejected,
                    'message' => $e->getMessage(), 'api_code' => $e->apiCode, 'settled_at' => now()] + $localRefusal);
                if ($run) {
                    $this->block($run, $e->getMessage());
                }
            } catch (\Throwable $e) {
                if ($refusedBeforeTransport && ($action->command === 'buy_ship' || $guardedMovement)) {
                    $this->rejectUnsentPurchase($action, $run, 'Purchase authority could not be verified before transport.');

                    return;
                }
                Log::build(['driver' => 'single', 'path' => storage_path('logs/gameplay.log'), 'permission' => 0600])->error('Ship command local execution failed.', [
                    'action_id' => $action->id, 'exception_class' => get_class($e),
                    'source' => str_replace(base_path().'/', '', $e->getFile()), 'line' => $e->getLine(),
                ]);
                $action->refresh();
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
        $execute = function () use ($work, $settlementScope, $paced) {
            if ($settlementScope) {
                app(SpaceTradersClient::class)->scope($work, priority: 'settlement');
            } elseif ($paced) {
                app(SpaceTradersClient::class)->scope($work, priority: 'run');
            } else {
                $work();
            }
        };
        if (isset($run?->plan['mesh_work'])) {
            try {
                app(MeshGovernor::class)->run($agent, $run, $execute);
            } catch (RequestDeferred $exception) {
                FleetMetrics::observe($exception);
                $action->refresh();
                $run->refresh();
                if ($action->state !== ActionState::Pending || $action->sent_at) {
                    return;
                }
                $expired = ! $action->expires_at->isFuture() || ! $run->expires_at->isFuture();
                $allowance = $run->plan['mesh_allowance'] ?? null;
                try {
                    $lost = ! $expired && ! app(RequestBudget::class)->hasMesh($allowance, $agent->name, $agent->reset_id, $run->id);
                } catch (RequestDeferred) {
                    $lost = false;
                }
                if ($expired || $lost || $run->stop_requested_at || ! $run->active_agent_id || $action->reset_id !== $agent->reset_id) {
                    DB::transaction(function () use ($action, $run, $expired) {
                        $this->rejectUnsentPurchase($action, $run, $expired ? 'Mesh intent or run expired before transport.' : 'Mesh run lost its authority or request allowance before transport.');
                        if ($run->fresh()->state === RunState::Blocked) {
                            $run->update(['active_agent_id' => null, 'next_action_at' => null]);
                        }
                    });
                    app(MeshGovernor::class)->release($allowance);

                    return;
                }
                $action->update(['message' => $exception->getMessage()]);
            }
        } else {
            $execute();
        }
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

    private function confirm(Agent $agent, ShipAction $action, array $data, array $before, ?AutomationRun $run): void
    {
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
        if ($action->command === 'transfer') {
            $target = $before['estimate']['target_ship'];
            $expected = MiningPlanner::inventory($target);
            $expected[$symbol] = ($expected[$symbol] ?? 0) + $units;
            $target['cargo'] = $this->api->ship($agent, $target['symbol'])['cargo'];
            $target = $this->api->shipData($target);
            $target['cooldown'] = $before['estimate']['target_ship']['cooldown'];
            $observed = MiningPlanner::inventory($target);
            ksort($expected);
            ksort($observed);
            if ($expected !== $observed) {
                throw new GameException('Receiving cargo does not match the reserved transfer.', uncertain: true);
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
            $fresh = $this->api->ship($agent, $action->ship_symbol);
            foreach (['frame', 'engine', 'reactor'] as $component) {
                $actualShip[$component] = $fresh[$component];
            }
        }
        $configurationMismatch = $action->command === 'buy_ship' && self::configurationHash($actualShip) !== self::configurationHash($before['estimate']['configuration']);
        $credits = $data['agent']['credits'] ?? $before['credits'];
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
        $cooldownDiscrepancy = in_array($action->command, ['scan_waypoints', 'scan_systems', 'scan_ships'], true) && ($data['cooldown']['totalSeconds'] ?? $data['cooldown']['remainingSeconds']) > ($action->arguments['maxCooldownSeconds'] ?? 900);
        $discrepancy = $discrepancy || $cooldownDiscrepancy;
        $discrepancy = $discrepancy || (in_array($action->command, ['navigate', 'warp'], true) && isset($action->arguments['maxSeconds']) && isset($actualShip['nav']['route']['departureTime']) && CarbonImmutable::parse($actualShip['nav']['route']['departureTime'])->diffInSeconds(CarbonImmutable::parse($actualShip['nav']['route']['arrival'])) > $action->arguments['maxSeconds']);
        $discrepancy = $discrepancy || ($action->command === 'refuel' && $actualShip['fuel']['current'] <= $before['ship']['fuel']['current']);
        $fuelDiscrepancy = false;
        if (in_array($action->command, ['navigate', 'warp'], true)) {
            $consumed = $before['ship']['fuel']['current'] - $actualShip['fuel']['current'];
            $fuelDiscrepancy = $consumed < 0 || $consumed > $before['estimate']['fuel_estimate'];
            $discrepancy = $discrepancy || $fuelDiscrepancy;
        }
        if (in_array($action->command, ['buy', 'sell'], true) && (($data['transaction']['units'] ?? null) !== $action->arguments['units'] || ($data['transaction']['tradeSymbol'] ?? null) !== $action->arguments['symbol'])) {
            throw new GameException('Trade response does not match the reserved intent.', uncertain: true);
        }
        DB::transaction(function () use ($agent, $action, $data, $before, $actualShip, $credits, $price, $delta, $discrepancy, $fuelDiscrepancy, $configurationMismatch, $cooldownDiscrepancy, $run, $target) {
            if ($actualShip) {
                $this->sync->saveShip($agent, $actualShip);
            }
            if ($target) {
                $this->sync->saveShip($agent, $target);
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
            // Phase 3 agent freshness: stamp synced_at only when the response contains an authoritative agent object.
            $agentUpdate = ['credits' => $credits];
            if (isset($data['agent'])) {
                $agentUpdate['synced_at'] = now();
            }
            $agent->update($agentUpdate);
            if (in_array($action->command, ['buy', 'sell', 'refuel'], true) && ! ($action->command === 'refuel' && ($action->arguments['fromCargo'] ?? false))) {
                $this->sync->invalidateMarket($agent, $actualShip['nav']['waypointSymbol'], $action->id);
            }
            $outcome = ['produced' => $data['produced'] ?? null, 'consumed' => $data['consumed'] ?? null, 'events' => $data['events'] ?? [], 'cargo_refuel' => $before['estimate']['cargo_refuel'] ?? null, 'contract' => $data['contract'] ?? null, 'credit_delta' => $delta, 'transaction_total' => $price, 'yield' => $data['extraction']['yield'] ?? $data['siphon']['yield'] ?? null,
                'units' => $data['transaction']['units'] ?? null, 'price_discrepancy' => $discrepancy && ! $fuelDiscrepancy && ! $cooldownDiscrepancy, 'cooldown_discrepancy' => $cooldownDiscrepancy, 'fuel_discrepancy' => $fuelDiscrepancy, 'delivered_ship' => $data['ship']['symbol'] ?? null, 'configuration_mismatch' => $configurationMismatch];
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
            $reviewRequired = $discrepancy || ($configurationMismatch && in_array($run?->strategy, CampaignProbeBootstrap::STRATEGIES, true));
            $action->update(['state' => ActionState::Confirmed, 'review_required' => $reviewRequired, 'outcome' => array_merge($action->fresh()->outcome ?? [], $outcome), 'settled_at' => now(), 'message' => $fuelDiscrepancy ? 'Actual fuel differed from the approved prediction. Reconcile and replan before further commands.' : ($discrepancy ? (ExplorationPolicy::command($action->command) ? 'Exploration exceeded the cooldown review threshold or changed credits unexpectedly. Review before further work.' : 'Execution differed from approved quote or reserve. Further work is paused.') : 'Confirmed by SpaceTraders.')]);
            app(CreditAccounting::class)->record($agent, $action, $delta);
            if (isset($data['contract'])) {
                $this->sync->observe($agent, 'contract', $data['contract']['id'], $data['contract']);
            }
            if ($run) {
                app(CreditAccounting::class)->apply($run, $action, $action->command === 'buy_ship' ? $before['ship'] : $actualShip, $outcome, $delta, $price);
                if ($action->command === 'buy_ship') {
                    app(CampaignProbeBootstrap::class)->recordDelivery($action);
                }
            }
        });
        if ($action->command === 'buy_ship' && ($configurationMismatch || (isset($action->arguments['site']) && app(MiningPlanner::class)->capability($actualShip) !== null))) {
            $action->update(['message' => 'Delivered configuration differs from the approved offer or is not operational for the selected setup. Inspect the delivered ship.']);
        }
    }

    private function confirmScrap(Agent $agent, ShipAction $action, array $data, array $before): void
    {
        $credits = $data['agent']['credits'];
        $price = $data['transaction']['totalPrice'];
        $delta = $credits - $before['credits'];
        $review = $delta !== $price || $price < ($action->arguments['minTotal'] ?? 0) || $credits < $before['estimate']['reserve'];
        DB::transaction(function () use ($agent, $action, $data, $credits, $price, $delta, $review, $before) {
            Ship::where('agent_id', $agent->id)->where('symbol', $action->ship_symbol)->delete();
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
                $action->update(['state' => ActionState::Uncertain]);
            }
            $this->sync->sync($agent, false, freshReset: true);
            $fleet = $this->api->ships($agent);
            $ship = collect($fleet)->firstWhere('symbol', $action->ship_symbol);
            if ($ship) {
                $ship = $this->sync->refreshShip($agent, $action->ship_symbol)->snapshot;
            }
            $evidence = ['reconciled_at' => now()->toIso8601String(), 'credits' => $agent->credits,
                'ship' => $ship, 'actor_present' => $ship !== null, 'fleet' => $fleet, 'transactions' => []];
            if ($ship && in_array($action->command, ['sell', 'buy', 'refuel'], true)) {
                $evidence['transactions'] = $this->api->market($agent, $ship['nav']['waypointSymbol'])['transactions'] ?? [];
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

    public function block(AutomationRun $run, string $reason): void
    {
        $run->update(['state' => RunState::Blocked, 'blocked_reason' => $reason]);
        $ship = Ship::where('agent_id', $run->agent_id)->where('symbol', $run->ship_symbol)->first()?->snapshot;
        app(CycleAccounting::class)->record($run, $ship);
    }
}
