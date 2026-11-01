<?php

namespace App\Gameplay;

use App\Enums\ActionState;
use App\Enums\RunState;
use App\Jobs\ExecuteShipCommand;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\LocalEngine;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Services\SpaceTradersClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShipCommandExecutor
{
    public function __construct(private HostLock $lock, private CommandRules $rules, private SpaceTradersApi $api, private AgentSynchronizer $sync, private EngineGate $gate) {}

    public function locked(Agent $agent, callable $work): mixed
    {
        return $this->lock->run('agent-'.$agent->id, fn () => app(SpaceTradersClient::class)->scope($work));
    }

    public function preview(Agent $agent, string $ship, string $command, array $arguments, string $key): ShipAction
    {
        return $this->locked($agent, function () use ($agent, $ship, $command, $arguments, $key) {
            $existing = ShipAction::where('agent_id', $agent->id)->where('key', $key)->first();
            if ($existing) {
                if ($existing->ship_symbol !== $ship || $existing->command !== $command || $existing->arguments !== $arguments) {
                    throw new GameException('This submission key already belongs to another intent.');
                }

                return $existing;
            }
            $this->clear($agent);
            $before = $this->rules->inspect($agent, $ship, $command, $arguments);

            return ShipAction::create([
                'agent_id' => $agent->id, 'key' => $key, 'reset_id' => $agent->reset_id, 'ship_symbol' => $ship,
                'engine_session_id' => $this->gate->enabled() ? LocalEngine::find(1)?->session_id : null,
                'origin' => 'manual', 'command' => $command, 'arguments' => $arguments, 'before_state' => $before,
                'state' => ActionState::Preview, 'expires_at' => now()->addSeconds(config('gameplay.preview_seconds')),
            ]);
        });
    }

    public function approve(Agent $agent, ShipAction $action): ShipAction
    {
        return $this->locked($agent, function () use ($agent, $action) {
            $action->refresh();
            if ($action->state !== ActionState::Preview) {
                return $action;
            }
            $this->clear($agent);
            if ($action->expires_at->isPast() || $action->reset_id !== $agent->reset_id) {
                throw new GameException('Preview expired. Create a fresh preview.');
            }
            $this->rules->inspect($agent, $action->ship_symbol, $action->command, $action->arguments);
            DB::transaction(function () use ($action) {
                $this->gate->check($action->engine_session_id);
                $action->update(['state' => ActionState::Pending, 'last_dispatched_at' => now()]);
            });
            ExecuteShipCommand::dispatch($action->id)->afterCommit();

            return $action;
        });
    }

    public function clear(Agent $agent, ?int $except = null): void
    {
        if (ShipAction::where('agent_id', $agent->id)->when($except, fn ($q) => $q->whereKeyNot($except))->where(fn ($q) => $q->whereIn('state', ['pending', 'sending', 'uncertain'])->orWhere('review_required', true))->exists()) {
            throw new GameException('An action is pending, uncertain or requires review. Settle it before another command.');
        }
        if (! $agent->canonical_identity || Agent::where('name', $agent->name)->where('reset_id', $agent->reset_id)->whereKeyNot($agent->id)->exists()) {
            throw new GameException('Agent identity is not unique. Synchronize or resolve duplicate imports first.');
        }
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
        $this->locked($agent, function () use ($action, $agent) {
            $action->refresh();
            if ($action->state !== ActionState::Pending) {
                return;
            }
            $run = $action->automation_run_id ? AutomationRun::find($action->automation_run_id) : null;
            $received = false;
            try {
                $this->gate->check($action->engine_session_id);
                $this->clear($agent, $action->id);
                if ($action->reset_id !== $agent->reset_id || $action->expires_at->isPast() || ($run && ($run->stop_requested_at || $run->expires_at->isPast() || ! $run->active_agent_id))) {
                    throw new GameException('Intent expired, reset or stopped before execution.');
                }
                $before = $this->rules->inspect($agent, $action->ship_symbol, $action->command, $action->arguments, $run);
                // Stop and reservation contend on a database transaction, independently of the host lock.
                DB::transaction(function () use ($action, $run, $before) {
                    $this->gate->check($action->engine_session_id);
                    if ($action->expires_at->isPast()) {
                        throw new GameException('Intent expired during preflight. Create a fresh preview.');
                    }
                    if ($run && ! AutomationRun::whereKey($run->id)->whereNull('stop_requested_at')->whereNotNull('active_agent_id')->where('expires_at', '>', now())->update(['heartbeat_at' => now()])) {
                        throw new GameException('Run stopped or expired before this action was reserved.');
                    }
                    if (! ShipAction::whereKey($action->id)->where('state', 'pending')->update(['state' => 'sending', 'before_state' => json_encode($before), 'sent_at' => now()])) {
                        throw new GameException('Action was already reserved.');
                    }
                });
                $action->refresh();
                $data = $this->api->mutate($agent, $action->ship_symbol, $action->command, $action->arguments);
                $received = true;
                $this->confirm($agent, $action, $data, $before, $run);
            } catch (RequestDeferred $e) {
                // This exception is emitted only before HTTP transport starts. Returning to pending cannot replay a write.
                if ($action->expires_at->isFuture()) {
                    $action->update(['state' => ActionState::Pending, 'sent_at' => null, 'message' => $e->getMessage()]);
                } else {
                    $action->update(['state' => ActionState::Rejected, 'message' => 'Intent expired while waiting for the request budget.', 'settled_at' => now()]);
                    if ($run) {
                        $this->block($run, 'Intent expired while waiting for the request budget.');
                    }
                }
            } catch (GameException $e) {
                $action->refresh();
                if ($action->command === 'mine_survey' && in_array($e->apiCode, [4221, 4224], true)) {
                    app(SurveyPool::class)->invalidate($agent, $action->arguments['surveySignature'], $e->apiCode === 4224);
                }
                // Rate-budget failure before transport is safe to retry only while pending.
                if ($action->state === ActionState::Pending && $e->retryAfter > 0 && $action->expires_at->isFuture()) {
                    $action->update(['message' => $e->getMessage()]);

                    return;
                }
                $uncertain = $action->state === ActionState::Sending && ($received || $e->uncertain);
                $action->update(['state' => $uncertain ? ActionState::Uncertain : ActionState::Rejected,
                    'message' => $e->getMessage(), 'api_code' => $e->apiCode, 'settled_at' => now()]);
                if ($run) {
                    $this->block($run, $e->getMessage());
                }
            } catch (\Throwable $e) {
                Log::build(['driver' => 'single', 'path' => storage_path('logs/gameplay.log'), 'permission' => 0600])->error('Ship command local execution failed.', [
                    'action_id' => $action->id, 'exception_class' => get_class($e),
                    'source' => str_replace(base_path().'/', '', $e->getFile()), 'line' => $e->getLine(),
                ]);
                $action->refresh();
                $action->update(['state' => $action->state === ActionState::Sending ? ActionState::Uncertain : ActionState::Rejected,
                    'message' => 'Local execution failed. Reconcile before further commands.', 'settled_at' => now()]);
                if ($run) {
                    $this->block($run, 'Local execution failed. Reconciliation required.');
                }
            }
        });
    }

    private function confirm(Agent $agent, ShipAction $action, array $data, array $before, ?AutomationRun $run): void
    {
        $ship = $before['ship'];
        foreach (['nav', 'fuel', 'cargo', 'cooldown'] as $field) {
            if (isset($data[$field])) {
                $ship[$field] = $data[$field];
            }
        }
        if (isset($data['cooldown']) && ! isset($data['cooldown']['expiration'])) {
            $ship['cooldown']['expiration'] = now()->addSeconds($data['cooldown']['remainingSeconds'])->toIso8601String();
        }
        $actualShip = $action->command === 'buy_ship' ? $this->api->shipData($data['ship']) : $this->api->shipData($ship);
        if (in_array($action->command, ['mine', 'mine_survey', 'buy', 'sell', 'transfer', 'contract_deliver'], true)) {
            $expected = MiningPlanner::inventory($before['ship']);
            $mining = in_array($action->command, ['mine', 'mine_survey'], true);
            $symbol = $mining ? $data['extraction']['yield']['symbol'] : $action->arguments['symbol'];
            $units = $mining ? $data['extraction']['yield']['units'] : $action->arguments['units'];
            $expected[$symbol] = ($expected[$symbol] ?? 0) + (in_array($action->command, ['sell', 'transfer', 'contract_deliver'], true) ? -$units : $units);
            $expected = array_filter($expected);
            $observed = MiningPlanner::inventory($actualShip);
            ksort($expected);
            ksort($observed);
            if ($expected !== $observed) {
                throw new GameException('Cargo response does not match the reserved trade or extraction yield.', uncertain: true);
            }
        }
        $target = null;
        if ($action->command === 'transfer') {
            $target = $before['estimate']['target_ship'];
            $expected = MiningPlanner::inventory($target);
            $expected[$symbol] = ($expected[$symbol] ?? 0) + $units;
            $target['cargo'] = $data['targetCargo'];
            $target = $this->api->shipData($target);
            $observed = MiningPlanner::inventory($target);
            ksort($expected);
            ksort($observed);
            if ($expected !== $observed) {
                throw new GameException('Receiving cargo does not match the reserved transfer.', uncertain: true);
            }
        }
        if ($action->command === 'navigate' && ($actualShip['nav']['waypointSymbol'] !== $action->arguments['waypointSymbol'] || $actualShip['nav']['systemSymbol'] !== $before['ship']['nav']['systemSymbol'])) {
            throw new GameException('Navigation response does not match the approved destination.', uncertain: true);
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
        $configurationMismatch = $action->command === 'buy_ship' && $this->configuration($actualShip) !== $this->configuration($before['estimate']['configuration']);
        $credits = $data['agent']['credits'] ?? $before['credits'];
        $price = $data['transaction']['totalPrice'] ?? $data['transaction']['price'] ?? 0;
        $delta = $credits - $before['credits'];
        $discrepancy = in_array($action->command, ['buy', 'buy_ship', 'refuel'], true)
            ? $price > ($action->arguments['maxTotal'] ?? 0) || $credits < $before['estimate']['reserve'] || $delta !== -$price
            : ($action->command === 'sell' && ($price < ($action->arguments['minTotal'] ?? 0) || $delta !== $price));
        if (in_array($action->command, ['contract_accept', 'contract_fulfill'], true)) {
            $payment = $before['contract']['terms']['payment'][$action->command === 'contract_accept' ? 'onAccepted' : 'onFulfilled'];
            $discrepancy = $delta !== $payment || $credits < $before['estimate']['reserve'];
        }
        $discrepancy = $discrepancy || ($action->command === 'refuel' && $actualShip['fuel']['current'] <= $before['ship']['fuel']['current']);
        $fuelDiscrepancy = false;
        if ($action->command === 'navigate') {
            $consumed = $before['ship']['fuel']['current'] - $actualShip['fuel']['current'];
            $fuelDiscrepancy = $consumed < 0 || $consumed > $before['estimate']['fuel_estimate'];
            $discrepancy = $discrepancy || $fuelDiscrepancy;
        }
        if (in_array($action->command, ['buy', 'sell'], true) && (($data['transaction']['units'] ?? null) !== $action->arguments['units'] || ($data['transaction']['tradeSymbol'] ?? null) !== $action->arguments['symbol'])) {
            throw new GameException('Trade response does not match the reserved intent.', uncertain: true);
        }
        DB::transaction(function () use ($agent, $action, $data, $actualShip, $credits, $price, $delta, $discrepancy, $fuelDiscrepancy, $configurationMismatch, $run, $target) {
            $this->sync->saveShip($agent, $actualShip);
            if ($target) {
                $this->sync->saveShip($agent, $target);
            }
            if ($action->command === 'survey') {
                foreach ($data['surveys'] as $signed) {
                    app(SurveyPool::class)->save($agent, $signed, $actualShip['symbol']);
                }
            }
            $agent->update(['credits' => $credits, 'synced_at' => now()]);
            if (in_array($action->command, ['buy', 'sell', 'refuel'], true)) {
                $this->sync->invalidateMarket($agent, $actualShip['nav']['waypointSymbol'], $action->id);
            }
            $outcome = ['contract' => $data['contract'] ?? null, 'credit_delta' => $delta, 'transaction_total' => $price, 'yield' => $data['extraction']['yield'] ?? null,
                'units' => $data['transaction']['units'] ?? null, 'price_discrepancy' => $discrepancy && ! $fuelDiscrepancy, 'fuel_discrepancy' => $fuelDiscrepancy, 'delivered_ship' => $data['ship']['symbol'] ?? null, 'configuration_mismatch' => $configurationMismatch];
            if (in_array($action->command, ['mine', 'mine_survey'], true)) {
                $this->sync->observe($agent, 'extraction', $actualShip['nav']['waypointSymbol'], [
                    'action_id' => $action->id, 'configuration' => FuelPlanner::configuration($actualShip), 'yield' => $outcome['yield'],
                    'cooldown' => $actualShip['cooldown'], 'cargo' => $actualShip['cargo'], 'events' => $data['events'] ?? [],
                    'survey_signature' => $action->arguments['surveySignature'] ?? null,
                ], 'extraction', $actualShip['symbol']);
            }
            if ($action->command === 'navigate') {
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
            $action->update(['state' => ActionState::Confirmed, 'review_required' => $discrepancy, 'outcome' => $outcome, 'settled_at' => now(), 'message' => $fuelDiscrepancy ? 'Actual fuel differed from the approved prediction. Reconcile and replan before further commands.' : ($discrepancy ? 'Execution differed from approved quote or reserve. Further work is paused.' : 'Confirmed by SpaceTraders.')]);
            app(CreditAccounting::class)->record($agent, $action, $delta);
            if (isset($data['contract'])) {
                $this->sync->observe($agent, 'contract', $data['contract']['id'], $data['contract']);
            }
            if ($run) {
                app(CreditAccounting::class)->apply($run, $action, $actualShip, $outcome, $delta, $price);
            }
        });
        if ($action->command === 'buy_ship' && ($configurationMismatch || app(MiningPlanner::class)->capability($actualShip) !== null)) {
            $action->update(['message' => 'Delivered configuration differs from the approved miner or is not operational. Automation remains blocked.']);
        }
    }

    private function configuration(array $ship): array
    {
        $modules = array_column($ship['modules'], 'symbol');
        $mounts = array_column($ship['mounts'], 'symbol');
        sort($modules);
        sort($mounts);

        return [$ship['frame']['symbol'], $ship['reactor']['symbol'], $ship['engine']['symbol'], $modules, $mounts];
    }

    public function reconcile(Agent $agent, ShipAction $action): ShipAction
    {
        return $this->locked($agent, function () use ($agent, $action) {
            $action->refresh();
            if (! $action->review_required && ! in_array($action->state, [ActionState::Uncertain, ActionState::Sending], true)) {
                return $action;
            }
            // Holding the process lock proves no local sender remains in flight.
            $knownOutcome = $action->state === ActionState::Confirmed && $action->review_required;
            if (! $knownOutcome) {
                $action->update(['state' => ActionState::Uncertain]);
            }
            $this->sync->sync($agent, false);
            $ship = $this->sync->refreshShip($agent, $action->ship_symbol)->snapshot;
            $evidence = ['reconciled_at' => now()->toIso8601String(), 'credits' => $agent->credits,
                'ship' => $ship, 'fleet' => $this->api->ships($agent), 'transactions' => []];
            if (in_array($action->command, ['sell', 'buy', 'refuel'], true)) {
                $evidence['transactions'] = $this->api->market($agent, $ship['nav']['waypointSymbol'])['transactions'] ?? [];
            }
            $contractId = $action->arguments['contractId'] ?? ($action->automation_run_id ? AutomationRun::find($action->automation_run_id)?->plan['contract_plan']['contract']['id'] ?? null : null);
            if ($contractId) {
                $evidence['contract'] = $this->api->contract($agent, $contractId);
            }
            if ($action->command === 'buy_ship') {
                $evidence['transactions'] = $this->api->shipyard($agent, $action->arguments['waypointSymbol'])['transactions'] ?? [];
            }
            $idempotentSettled = ($action->command === 'dock' && $ship['nav']['status'] === 'DOCKED') || ($action->command === 'orbit' && $ship['nav']['status'] === 'IN_ORBIT');
            $action->update(['state' => $knownOutcome || $idempotentSettled ? ActionState::Confirmed : ActionState::Uncertain,
                'outcome' => array_merge($action->outcome ?? [], $evidence), 'message' => $knownOutcome ? 'Confirmed transaction retained. Review the price discrepancy and fresh evidence before takeover.' : ($idempotentSettled ? 'Idempotent navigation state reconciled.' : 'Fresh state recorded. Outcome cannot be proven automatically; inspect evidence and acknowledge before takeover.'), 'settled_at' => now()]);

            return $action;
        });
    }

    public function block(AutomationRun $run, string $reason): void
    {
        $run->update(['state' => RunState::Blocked, 'blocked_reason' => $reason]);
        $ship = Ship::where('agent_id', $run->agent_id)->where('symbol', $run->ship_symbol)->first()?->snapshot;
        app(CycleAccounting::class)->record($run, $ship);
    }
}
