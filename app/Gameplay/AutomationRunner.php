<?php

namespace App\Gameplay;

use App\Enums\RunState;
use App\Jobs\AdvanceAutomationRun;
use App\Jobs\ExecuteShipCommand;
use App\Models\Agent;
use App\Models\AutomationDraft;
use App\Models\AutomationRun;
use App\Models\GameObservation;
use App\Models\LocalEngine;
use App\Models\Ship;
use App\Models\ShipAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AutomationRunner
{
    public function __construct(private ShipCommandExecutor $executor, private AgentSynchronizer $sync, private MiningPlanner $planner, private SpaceTradersApi $api, private EngineGate $gate, private ContractPlanner $contracts) {}

    public function preview(Agent $agent, string $symbol, array $limits): AutomationRun
    {
        if ($this->gate->enabled() && ($limits['strategy'] ?? 'mining') === 'mining') {
            throw new GameException('Stage an investigated opportunity and create a versioned preview.');
        }

        return $this->executor->locked($agent, function () use ($agent, $symbol, $limits) {
            $this->executor->clear($agent);
            if (ShipAction::where('agent_id', $agent->id)->where('command', 'buy_ship')->get()->contains(fn ($a) => ! empty($a->outcome['configuration_mismatch']))) {
                throw new GameException('Delivered miner configuration differs from its approval. Inspect the setup purchase before automation.');
            }
            $this->sync->sync($agent, false);
            $ship = Ship::where('agent_id', $agent->id)->where('symbol', $symbol)->firstOrFail()->snapshot;
            if ($ship['nav']['status'] === 'IN_TRANSIT' || $ship['cargo']['units'] !== 0) {
                throw new GameException('Begin with a stationary ship and empty cargo. Existing cargo remains protected.');
            }
            $limits['strategy'] ??= 'mining';
            $limits = $this->prepare($agent, $ship, $limits);
            if ($this->gate->enabled() && (app(CreditAccounting::class)->summary($agent)['unexplained_change'] ?? 0) !== 0) {
                throw new GameException('Unexplained balance change blocks attribution. Review before previewing.');
            }
            $route = $limits['route'];

            return AutomationRun::create([
                'engine_session_id' => $this->gate->enabled() ? LocalEngine::find(1)?->session_id : null,
                'strategy' => $limits['strategy'], 'plan_version' => $limits['strategy'] === 'mining' ? 1 : 3,
                'agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'ship_symbol' => $symbol, 'state' => RunState::Preview,
                'expires_at' => now()->addSeconds(config('gameplay.preview_seconds')),
                'plan' => array_merge($limits, ['route' => $route, 'initial_inventory' => MiningPlanner::inventory($ship), 'initial_credits' => $agent->credits,
                    'initial_configuration' => FuelPlanner::configuration($ship), 'equipment' => OpportunityEvaluator::equipment($ship), 'initial_quote_signatures' => $this->quoteSignatures($agent, $limits), 'initial_fuel' => $ship['fuel']['current'], 'initial_waypoint' => $ship['nav']['waypointSymbol'], 'initial_status' => $ship['nav']['status']]),
                'totals' => ['phase' => match ($limits['strategy']) {
                    'scout' => 'observe', 'trade' => 'buy', default => 'mine'
                }, 'purchased' => [], 'purchase_cost' => 0, 'travel_fuel' => 0, 'visits' => 0, 'cycles' => 0, 'attempts' => 0, 'no_progress' => 0, 'extracted' => [], 'sold' => [], 'sale_proceeds' => 0, 'fuel_cost' => 0, 'fuel_units' => 0, 'credit_delta' => 0],
            ]);
        });
    }

    public function start(Agent $agent, AutomationRun $run, bool $acceptContract = false): AutomationRun
    {
        return $this->executor->locked($agent, function () use ($agent, $run, $acceptContract) {
            $run->refresh();
            if ($run->state !== RunState::Preview) {
                if (in_array($run->state, [RunState::Stopped, RunState::Blocked], true)) {
                    throw new GameException('This preview was invalidated or stopped. Create a fresh preview.');
                }

                return $run;
            }
            if (! config('gameplay.enabled')) {
                throw new GameException('Automation is disabled. Enable it only after local migration, backup and credential checks.');
            }
            $this->gate->check($run->engine_session_id);
            $this->executor->clear($agent);
            if ($run->expires_at->isPast() || $run->reset_id !== $agent->reset_id || AutomationRun::where('active_agent_id', $agent->id)->exists()) {
                $this->rejectPreview($run, 'Preview expired, reset or another run already owns this agent.');
            }
            if (! in_array($run->plan_version, [1, 2, 3], true) || ($this->gate->enabled() && ! in_array($run->plan_version, [2, 3], true)) || ($run->plan_version === 3 && ! in_array($run->strategy, ['scout', 'trade'], true))) {
                throw new GameException('Unsupported or historical plan version. Create a fresh staged preview.');
            }
            if ($run->plan_version === 2) {
                $draft = AutomationDraft::where('agent_id', $agent->id)->findOrFail($run->draft_id);
                if ($draft->revision !== $run->draft_revision || $draft->reset_id !== $agent->reset_id) {
                    $this->rejectPreview($run, 'Draft revision changed. Create a fresh preview.');
                }
                try {
                    $fresh = $this->compose($agent, $draft);
                } catch (GameException $e) {
                    $this->rejectPreview($run, $e->getMessage());
                }
                if (! hash_equals($run->fingerprint, $fresh['fingerprint'])) {
                    $this->rejectPreview($run, 'Ship, cargo, credits, equipment, prices, route or contract terms changed. Create a fresh preview.');
                }
                if ($run->strategy === 'contracts' && ! $run->plan['contract_plan']['contract']['accepted'] && ! $acceptContract) {
                    throw new GameException('Explicitly acknowledge contract acceptance and its continuing obligation.');
                }
                $plan = $run->plan;
            } else {
                $this->sync->sync($agent, false);
                $ship = Ship::where('agent_id', $agent->id)->where('symbol', $run->ship_symbol)->firstOrFail()->snapshot;
                $plan = $run->plan;
                $fresh = $this->prepare($agent, $ship, $plan);
                if ($agent->credits !== $plan['initial_credits'] || MiningPlanner::inventory($ship) !== $plan['initial_inventory'] || $ship['fuel']['current'] !== $plan['initial_fuel'] || $ship['nav']['waypointSymbol'] !== $plan['initial_waypoint'] || $ship['nav']['status'] !== $plan['initial_status'] || FuelPlanner::configuration($ship) !== ($plan['initial_configuration'] ?? FuelPlanner::configuration($ship)) || (isset($plan['initial_quote_signatures']) && $plan['initial_quote_signatures'] !== $this->quoteSignatures($agent, $fresh))) {
                    $this->rejectPreview($run, 'Ship state, equipment or quotes changed. Create a fresh operation preview.');
                }
                $plan['route'] = $fresh['route'];
            }
            $plan['initial_manual_credit_delta'] = $this->manualCreditDelta($agent);
            DB::transaction(function () use ($run, $agent, $plan) {
                $this->gate->check($run->engine_session_id);
                $run->update(['plan' => $plan, 'active_agent_id' => $agent->id, 'state' => RunState::Running, 'started_at' => now(), 'expires_at' => now()->addMinutes($plan['minutes']), 'next_action_at' => now(), 'last_dispatched_at' => now()]);
                AdvanceAutomationRun::dispatch($run->id)->afterCommit();
            });

            return $run;
        });
    }

    public function stop(AutomationRun $run): void
    {
        AutomationRun::whereKey($run->id)->whereNotNull('active_agent_id')->update(['stop_requested_at' => now(), 'state' => 'stopping', 'next_action_at' => now(), 'last_dispatched_at' => null]);
    }

    public function advance(int $id): void
    {
        $run = AutomationRun::find($id);
        if (! $run || ! $run->active_agent_id) {
            return;
        }
        $agent = Agent::find($run->agent_id);
        $this->executor->locked($agent, function () use ($run, $agent) {
            $run->refresh();
            if (! $run->active_agent_id) {
                return;
            }
            $run->update(['heartbeat_at' => now()]);
            if (ShipAction::where('agent_id', $agent->id)->where(fn ($q) => $q->whereIn('state', ['sending', 'uncertain'])->orWhere('review_required', true))->exists()) {
                $this->executor->block($run, 'An action is uncertain or requires price review. Reconcile before takeover.');

                return;
            }
            if (ShipAction::where('agent_id', $agent->id)->where('state', 'pending')->exists()) {
                return;
            }
            if ($run->stop_requested_at || $run->expires_at->isPast() || ! config('gameplay.enabled')) {
                $this->finishStop($run, $run->stop_requested_at ? 'Stop acknowledged. Manual control is available after transit.' : 'Duration reached or automation disabled.');

                return;
            }
            if ($run->state === RunState::Blocked || ($run->next_action_at && $run->next_action_at->isFuture())) {
                return;
            }
            try {
                $this->gate->check($run->engine_session_id);
                $this->sync->guardReset($agent);
                $ship = $this->sync->refreshShip($agent, $run->ship_symbol)->snapshot;
                $credits = $this->api->agent($agent)['credits'];
                $agent->update(['credits' => $credits]);
                $otherDelta = $this->manualCreditDelta($agent) - ($run->plan['initial_manual_credit_delta'] ?? 0);
                if ($credits !== $run->plan['initial_credits'] + $run->totals['credit_delta'] + $otherDelta) {
                    throw new GameException('Agent credits changed outside this run. Create a new preview after reconciliation.');
                }
                if ($credits < $run->plan['reserve'] || $run->totals['credit_delta'] < -$run->plan['max_loss']) {
                    throw new GameException('Credit reserve or loss limit reached.');
                }
                $totals = $run->totals;
                $totals['other_credit_delta'] = $otherDelta;
                $totals['agent_balance_delta'] = $credits - $run->plan['initial_credits'];
                $run->update(['totals' => $totals]);
                if ($run->strategy === 'contracts') {
                    $this->assertContractState($agent, $run, $ship);
                    $step = $this->contracts->next($ship, $run->plan, $run->totals, now()->toImmutable());
                } else {
                    $this->refreshBuyer($agent, $run->plan['market']);
                    if (($run->plan['strategy'] ?? 'mining') !== 'mining') {
                        $this->refreshBuyer($agent, $run->plan['site']);
                    }
                    $market = [];
                    if (($run->plan['strategy'] ?? 'mining') === 'scout' && $run->totals['phase'] === 'observe' && ($run->totals['travel_fuel'] ?? 0) === 0 && $ship['nav']['status'] !== 'IN_TRANSIT') {
                        $this->sync->observe($agent, 'market', $run->plan['market'], $this->api->market($agent, $run->plan['market']));
                    }
                    if ($ship['nav']['waypointSymbol'] === $run->plan['market'] && $ship['nav']['status'] !== 'IN_TRANSIT') {
                        $market = $this->api->market($agent, $run->plan['market']);
                        $this->sync->observe($agent, 'market', $run->plan['market'], $market);
                    }
                    $step = ($run->plan['strategy'] ?? 'mining') === 'mining'
                        ? $this->planner->next($ship, $run->plan, $run->totals, $market, now()->toImmutable())
                        : app(OperationPlanner::class)->next($agent, $ship, $run->plan, $run->totals, now()->toImmutable());
                }
                match ($step->kind) {
                    'blocked' => $this->executor->block($run, $step->reason),
                    'wait' => $run->update(['state' => RunState::Waiting, 'next_action_at' => $step->until]),
                    'trip' => $run->strategy === 'contracts' ? $this->completeContract($run, $ship) : $this->completeTrip($run, $ship),
                    'depart' => $run->update(['totals' => array_merge($run->totals, ['phase' => 'sell']), 'next_action_at' => now(), 'state' => RunState::Running]),
                    'act' => $this->enqueue($run, $step),
                };
            } catch (GameException $e) {
                if ($e->retryAfter > 0 && $e->apiCode === null && $agent->connection_state === 'connected') {
                    $run->update(['state' => RunState::Waiting, 'next_action_at' => now()->addSeconds(min(180, $e->retryAfter)), 'blocked_reason' => $e->getMessage()]);
                } else {
                    $this->executor->block($run, $e->getMessage());
                }
            }
        });
    }

    private function prepare(Agent $agent, array $ship, array $limits): array
    {
        if (($limits['strategy'] ?? 'mining') !== 'mining') {
            $this->refreshBuyer($agent, $ship['nav']['waypointSymbol']);
            $this->refreshBuyer($agent, $limits['strategy'] === 'scout' ? $limits['destination'] : $limits['market']);
            if ($limits['strategy'] === 'scout') {
                $this->sync->observe($agent, 'market', $limits['destination'], $this->api->market($agent, $limits['destination']));
            }

            return app(OperationPlanner::class)->preview($agent, $ship, $limits);
        }
        $limits['max_extractions'] ??= 1;
        $limits['extraction_headroom'] ??= $ship['cargo']['capacity'];
        $limits['site_seconds'] ??= 300;
        if ($limits['extraction_headroom'] < 1 || $limits['extraction_headroom'] > $ship['cargo']['capacity']) {
            throw new GameException('Extraction headroom must be within cargo capacity.');
        }
        if (! empty($limits['survey_signature'])) {
            app(SurveyPool::class)->usable($agent, $limits['survey_signature'], $limits['site']);
        }
        $point = $this->api->waypoint($agent, $limits['site']);
        $this->sync->observe($agent, 'waypoint', $limits['site'], $point, 'read');
        $this->refreshBuyer($agent, $limits['market']);
        $route = $this->planner->route($agent, $ship, $limits['site'], $limits['market']);
        if ($limits['reserve'] < $route['minimum_reserve'] || $agent->credits < $limits['reserve'] + $limits['max_spend'] || $limits['max_spend'] < $route['fuel_ceiling']) {
            throw new GameException('This route requires a reserve of at least '.$route['minimum_reserve'].' CR and a fuel spending ceiling of at least '.$route['fuel_ceiling'].' CR. Their sum must fit the available '.$agent->credits.' CR.');
        }

        return array_merge($limits, ['route' => $route]);
    }

    private function quoteSignatures(Agent $agent, array $limits): array
    {
        $signatures = [];
        foreach (array_unique(array_filter([$limits['site'] ?? null, $limits['market'] ?? null, $limits['destination'] ?? null])) as $symbol) {
            $quote = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'market')->where('symbol', $symbol)->first();
            $signatures[$symbol] = OpportunityEvaluator::quoteSignature($quote?->data ?? []);
        }
        ksort($signatures);

        return $signatures;
    }

    private function manualCreditDelta(Agent $agent): int
    {
        return (int) ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->whereNull('automation_run_id')->where('state', 'confirmed')
            ->get()->sum(fn ($action) => $action->outcome['credit_delta'] ?? 0);
    }

    private function refreshBuyer(Agent $agent, string $market): void
    {
        $observer = Ship::where('agent_id', $agent->id)->get()->contains(fn ($ship) => $ship->snapshot['nav']['waypointSymbol'] === $market && $ship->snapshot['nav']['status'] !== 'IN_TRANSIT');
        if ($observer) {
            $this->sync->observe($agent, 'market', $market, $this->api->market($agent, $market));
        }
    }

    private function enqueue(AutomationRun $run, AutomationStep $step): void
    {
        DB::transaction(function () use ($run, $step) {
            $this->gate->check($run->engine_session_id);
            if (! AutomationRun::whereKey($run->id)->whereNull('stop_requested_at')->whereNotNull('active_agent_id')->where('expires_at', '>', now())->update(['state' => 'running', 'next_action_at' => now()->addSeconds(5)])) {
                return;
            }
            $action = ShipAction::create([
                'engine_session_id' => $run->engine_session_id,
                'agent_id' => $run->agent_id, 'automation_run_id' => $run->id, 'key' => (string) Str::uuid(),
                'reset_id' => $run->reset_id, 'ship_symbol' => $run->ship_symbol, 'origin' => 'automation',
                'target_type' => str_starts_with($step->command, 'contract_') ? 'contract' : 'ship', 'target_id' => str_starts_with($step->command, 'contract_') ? $run->plan['contract_plan']['contract']['id'] : $run->ship_symbol,
                'command' => $step->command, 'arguments' => array_merge($step->arguments, ['reserve' => $run->plan['reserve']]),
                'state' => 'pending', 'last_dispatched_at' => now(), 'expires_at' => min($run->expires_at, now()->addMinutes(3)),
            ]);
            ExecuteShipCommand::dispatch($action->id)->afterCommit();
        });
    }

    private function completeTrip(AutomationRun $run, array $ship): void
    {
        $totals = $run->totals;
        if (($run->plan['strategy'] ?? 'mining') !== 'mining' && $totals['phase'] !== 'return') {
            $totals['phase'] = 'return';
            $totals['visits'] += $ship['nav']['waypointSymbol'] === $run->plan['market'] ? 1 : 0;
            $run->update(['totals' => $totals, 'next_action_at' => now(), 'state' => RunState::Running, 'last_dispatched_at' => null]);

            return;
        }
        $totals['cycles']++;
        $totals['ending_fuel'] = $ship['fuel']['current'];
        $totals['unsold'] = MiningPlanner::inventory($ship);
        $totals['cash_contribution'] = $totals['sale_proceeds'] - $totals['fuel_cost'] - ($totals['purchase_cost'] ?? 0);
        $totals['elapsed_seconds'] = (int) $run->started_at->diffInSeconds(now());
        $totals['accounting'] = app(CycleAccounting::class)->summarize(
            ['waypoint' => $run->plan['initial_waypoint'], 'fuel' => $run->plan['initial_fuel'], 'inventory' => $run->plan['initial_inventory']],
            ['waypoint' => $ship['nav']['waypointSymbol'], 'fuel' => $ship['fuel']['current'], 'inventory' => MiningPlanner::inventory($ship)],
            ['sales' => $totals['sale_proceeds'], 'fuel' => $totals['fuel_cost'], 'goods' => $totals['purchase_cost'] ?? 0], $totals['elapsed_seconds']);
        $lastContribution = $totals['cash_contribution'] - ($totals['previous_cash_contribution'] ?? 0);
        $totals['last_cycle_cash_contribution'] = $lastContribution;
        $totals['last_cycle_contribution'] = $lastContribution;
        $totals['previous_cash_contribution'] = $totals['cash_contribution'];
        if ($totals['cycles'] >= $run->plan['cycles'] || $lastContribution <= 0) {
            $run->update(['totals' => $totals, 'state' => RunState::Completed, 'active_agent_id' => null, 'next_action_at' => null,
                'blocked_reason' => ($run->plan['strategy'] ?? 'mining') === 'scout' ? 'Observation complete. No earnings were assumed.' : ($lastContribution <= 0 ? 'Trip finished without positive cash contribution. A new preview is required.' : null)]);

            return;
        }
        // Observed economics support another explicitly bounded trip. Revalidate route each cycle.
        $agent = Agent::findOrFail($run->agent_id);
        $this->planner->route($agent, $ship, $run->plan['site'], $run->plan['market']);
        $totals['phase'] = 'mine';
        $totals['load_attempts'] = 0;
        unset($totals['load_started_at']);
        $run->update(['totals' => $totals, 'next_action_at' => now(), 'last_dispatched_at' => null, 'state' => RunState::Running]);
    }

    public function previewDraft(Agent $agent, AutomationDraft $draft): AutomationRun
    {
        return $this->executor->locked($agent, function () use ($agent, $draft) {
            $this->executor->clear($agent);
            $draft->refresh();
            $composed = $this->compose($agent, $draft);
            $plan = $composed['plan'];

            return AutomationRun::create([
                'agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'strategy' => $draft->candidate['strategy'], 'ship_symbol' => $draft->candidate['ship_symbol'],
                'state' => RunState::Preview, 'plan_version' => 2, 'engine_session_id' => $this->gate->enabled() ? LocalEngine::find(1)?->session_id : null,
                'investigation_id' => $draft->investigation_id, 'draft_id' => $draft->id, 'draft_revision' => $draft->revision, 'fingerprint' => $composed['fingerprint'],
                'expires_at' => now()->addSeconds(config('gameplay.preview_seconds')), 'plan' => $plan,
                'totals' => array_merge(['phase' => 'mine', 'cycles' => 0, 'attempts' => 0, 'no_progress' => 0, 'extracted' => [], 'sold' => [],
                    'sale_proceeds' => 0, 'fuel_cost' => 0, 'fuel_units' => 0, 'credit_delta' => 0, 'receipts' => 0, 'spending' => 0,
                    'step_index' => 0, 'deliveries' => 0, 'acquisition_cost' => 0], $draft->candidate['strategy'] === 'contracts' ? ['contract' => $plan['contract_plan']['contract']] : []),
            ]);
        });
    }

    private function compose(Agent $agent, AutomationDraft $draft): array
    {
        if ($draft->reset_id !== $agent->reset_id || $draft->plan_version !== 2) {
            throw new GameException('Draft reset or version is unsupported. Investigate again.');
        }
        $this->sync->sync($agent, false);
        $candidate = $draft->candidate;
        $ship = Ship::where('agent_id', $agent->id)->where('symbol', $candidate['ship_symbol'])->firstOrFail()->snapshot;
        $points = $this->api->waypoints($agent, $ship['nav']['systemSymbol']);
        foreach ($points as $point) {
            $this->sync->observe($agent, 'waypoint', $point['symbol'], $point);
        }
        $limits = $draft->limits;
        if ($candidate['strategy'] === 'mining') {
            $limits += ['max_extractions' => 1, 'extraction_headroom' => $ship['cargo']['capacity'], 'site_seconds' => 300];
            if ($limits['extraction_headroom'] < 1 || $limits['extraction_headroom'] > $ship['cargo']['capacity']) {
                throw new GameException('Extraction headroom must be within cargo capacity.');
            }
            if (! empty($limits['survey_signature'])) {
                app(SurveyPool::class)->usable($agent, $limits['survey_signature'], $candidate['site']);
            }
        }
        $market = $candidate['market'] ? $this->api->market($agent, $candidate['market']) : [];
        if ($candidate['market']) {
            $this->sync->observe($agent, 'market', $candidate['market'], $market);
        }
        $plan = array_merge($limits, ['initial_credits' => $agent->credits, 'initial_inventory' => MiningPlanner::inventory($ship), 'initial_fuel' => $ship['fuel']['current'],
            'initial_waypoint' => $ship['nav']['waypointSymbol'], 'initial_status' => $ship['nav']['status'], 'site' => $candidate['site'], 'market' => $candidate['market'],
            'equipment' => OpportunityEvaluator::equipment($ship), 'market_quote_signature' => OpportunityEvaluator::quoteSignature($market), 'setup_cost' => 0]);
        if ($candidate['strategy'] === 'mining') {
            if ($ship['cargo']['units'] !== 0 || $ship['nav']['status'] === 'IN_TRANSIT') {
                throw new GameException('Mining requires stationary empty cargo.');
            }
            $plan['route'] = $route = $this->planner->route($agent, $ship, $candidate['site'], $candidate['market']);
            if ($limits['reserve'] < $route['minimum_reserve'] || $limits['max_spend'] < $route['fuel_ceiling']) {
                throw new GameException('Mining reserve or fuel spending ceiling is below the verified route requirement.');
            }
            $plan['ordered_actions'] = ['Orbit and travel to the selected extraction site if needed', 'Up to '.$limits['max_extractions'].' '.($limits['max_extractions'] === 1 ? 'extraction' : 'extractions').' per trip within approved headroom and site-time limits', 'Depart for the approved buyer when an extraction, headroom or site-time limit is reached; travel can overlap the remaining extraction cooldown', 'Dock and sell only attributable yields in fresh-quote batches', 'Refuel within approved bounds; check each cycle contribution before any approved repeat'];
            $sameQuote = ($candidate['quote_signature'] ?? null) === $plan['market_quote_signature'];
            $plan['estimated_net'] = $sameQuote ? $candidate['estimated_net'] : null;
            $plan['trial_required'] = ! $sameQuote || $candidate['trial_required'];
        } else {
            $contract = $this->api->contract($agent, $candidate['contract_id']);
            $this->sync->observe($agent, 'contract', $contract['id'], $contract);
            $plan['contract_plan'] = $route = $this->contracts->plan($ship, $contract, array_column($points, null, 'symbol'), $candidate['market'] ? [$candidate['market'] => $market] : [], $candidate['market'], $candidate['use_existing_cargo'], now()->toImmutable());
            if ($route['purchase_cost'] > $limits['max_spend'] || $route['purchase_cost'] > $limits['max_loss'] || $route['deliveries'] > $limits['delivery_limit'] || $route['purchase_batches'] > $limits['purchase_limit'] || $route['duration_seconds'] > $limits['minutes'] * 60) {
                throw new GameException('Contract acquisition, loss, delivery, purchase or timing bounds cannot cover the remaining work.');
            }
            $plan['estimated_net'] = $route['estimated_net'];
        }
        if ($agent->credits < $limits['reserve'] + $limits['max_spend']) {
            throw new GameException('Protected reserve plus spending ceiling exceeds observed credits.');
        }
        $summary = app(CreditAccounting::class)->summary($agent);
        if (($summary['unexplained_change'] ?? 0) !== 0) {
            throw new GameException('Unexplained balance change blocks attribution. Review the balance before approval.');
        }
        $stableShip = $ship;
        $stableShip['nav'] = array_intersect_key($ship['nav'], array_flip(['systemSymbol', 'waypointSymbol', 'status', 'flightMode']));
        unset($stableShip['cooldown']['remainingSeconds']);
        $economics = $route;
        unset($economics['quote_at']);
        $quotes = $market['tradeGoods'] ?? [];
        usort($quotes, fn ($a, $b) => $a['symbol'] <=> $b['symbol']);

        return ['plan' => $plan, 'fingerprint' => hash('sha256', json_encode([$agent->reset_id, $agent->credits, $stableShip, $economics, $quotes, $limits]))];
    }

    private function assertContractState(Agent $agent, AutomationRun $run, array $ship): void
    {
        $plan = $run->plan;
        $totals = $run->totals;
        $contract = $this->api->contract($agent, $plan['contract_plan']['contract']['id']);
        $this->sync->observe($agent, 'contract', $contract['id'], $contract);
        if ($contract !== $totals['contract']) {
            throw new GameException('Contract progress or terms changed outside this run. Fresh preview required.');
        }
        $expected = $plan['initial_inventory'];
        foreach (array_slice($plan['contract_plan']['actions'], 0, $totals['step_index']) as $step) {
            if (in_array($step['command'], ['buy', 'contract_deliver'], true)) {
                $good = $step['arguments']['symbol'];
                $expected[$good] = ($expected[$good] ?? 0) + ($step['command'] === 'buy' ? 1 : -1) * $step['arguments']['units'];
            }
        }
        $expected = array_filter($expected);
        ksort($expected);
        $actual = MiningPlanner::inventory($ship);
        ksort($actual);
        if ($expected !== $actual) {
            throw new GameException('Cargo changed outside the approved contract. Protected cargo retained.');
        }
        $remainingActions = count($plan['contract_plan']['actions']) - $totals['step_index'];
        if (now()->addSeconds(120 + $remainingActions * 5)->gte($contract['terms']['deadline'])) {
            throw new GameException('Remaining scheduling allowance cannot fit the delivery deadline.');
        }
    }

    private function completeContract(AutomationRun $run, array $ship): void
    {
        if (! $run->totals['contract']['fulfilled']) {
            throw new GameException('Fulfillment has not been confirmed.');
        }
        $totals = $run->totals;
        $totals['ending_fuel'] = $ship['fuel']['current'];
        $totals['unsold'] = MiningPlanner::inventory($ship);
        $totals['elapsed_seconds'] = (int) $run->started_at->diffInSeconds(now());
        $run->update(['state' => RunState::Completed, 'active_agent_id' => null, 'next_action_at' => null, 'totals' => $totals]);
    }

    public function finishStop(AutomationRun $run, string $reason): void
    {
        $run->refresh();
        $ship = Ship::where('agent_id', $run->agent_id)->where('symbol', $run->ship_symbol)->first()?->snapshot;
        $totals = $run->totals;
        $totals['elapsed_seconds'] = $run->started_at ? (int) $run->started_at->diffInSeconds(now()) : 0;
        $totals['cash_contribution'] = $totals['credit_delta'];
        $totals['ending_fuel'] = $ship['fuel']['current'] ?? null;
        $totals['unsold'] = $ship ? MiningPlanner::inventory($ship) : null;
        $totals['stopped_at'] = now()->toIso8601String();
        $run->update(['state' => RunState::Stopped, 'active_agent_id' => null, 'next_action_at' => null, 'totals' => $totals, 'blocked_reason' => $reason]);
    }

    private function rejectPreview(AutomationRun $run, string $reason): never
    {
        $run->update(['state' => RunState::Stopped, 'blocked_reason' => $reason]);
        throw new GameException($reason);
    }
}
