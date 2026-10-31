<?php

namespace App\Gameplay;

use App\Enums\RunState;
use App\Jobs\AdvanceMiningRun;
use App\Jobs\ExecuteShipCommand;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\Ship;
use App\Models\ShipAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MiningRunner
{
    public function __construct(private ShipCommandExecutor $executor, private AgentSynchronizer $sync, private MiningPlanner $planner, private SpaceTradersApi $api) {}

    public function preview(Agent $agent, string $symbol, array $limits): AutomationRun
    {
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
            $route = $this->planner->route($agent, $ship, $limits['site'], $limits['market']);
            if ($limits['reserve'] < $route['minimum_reserve'] || $agent->credits < $limits['reserve'] + $limits['max_spend'] || $limits['max_spend'] < $route['fuel_ceiling']) {
                throw new GameException('Reserve and spending limits must cover the displayed fuel reserve and fit current credits.');
            }

            return AutomationRun::create([
                'agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'ship_symbol' => $symbol, 'state' => RunState::Preview,
                'expires_at' => now()->addSeconds(config('gameplay.preview_seconds')),
                'plan' => array_merge($limits, ['route' => $route, 'initial_inventory' => MiningPlanner::inventory($ship), 'initial_credits' => $agent->credits,
                    'initial_fuel' => $ship['fuel']['current'], 'initial_waypoint' => $ship['nav']['waypointSymbol'], 'initial_status' => $ship['nav']['status']]),
                'totals' => ['phase' => 'mine', 'cycles' => 0, 'attempts' => 0, 'no_progress' => 0, 'extracted' => [], 'sold' => [], 'sale_proceeds' => 0, 'fuel_cost' => 0, 'fuel_units' => 0, 'credit_delta' => 0],
            ]);
        });
    }

    public function start(Agent $agent, AutomationRun $run): AutomationRun
    {
        return $this->executor->locked($agent, function () use ($agent, $run) {
            $run->refresh();
            if ($run->state !== RunState::Preview) {
                return $run;
            }
            if (! config('gameplay.enabled')) {
                throw new GameException('Automation is disabled. Enable it only after local migration, backup and credential checks.');
            }
            $this->executor->clear($agent);
            if ($run->expires_at->isPast() || $run->reset_id !== $agent->reset_id || AutomationRun::where('active_agent_id', $agent->id)->exists()) {
                throw new GameException('Preview expired or another run already owns this agent.');
            }
            $this->sync->sync($agent, false);
            $ship = Ship::where('agent_id', $agent->id)->where('symbol', $run->ship_symbol)->firstOrFail()->snapshot;
            $plan = $run->plan;
            $route = $this->planner->route($agent, $ship, $plan['site'], $plan['market']);
            if ($agent->credits !== $plan['initial_credits'] || MiningPlanner::inventory($ship) !== $plan['initial_inventory'] || $ship['fuel']['current'] !== $plan['initial_fuel'] || $ship['nav']['waypointSymbol'] !== $plan['initial_waypoint'] || $ship['nav']['status'] !== $plan['initial_status'] || $plan['reserve'] < $route['minimum_reserve'] || $plan['max_spend'] < $route['fuel_ceiling']) {
                throw new GameException('State or route economics changed. Create a new mining preview.');
            }
            $run->update(['active_agent_id' => $agent->id, 'state' => RunState::Running, 'started_at' => now(), 'expires_at' => now()->addMinutes($plan['minutes']), 'next_action_at' => now(), 'last_dispatched_at' => now()]);
            AdvanceMiningRun::dispatch($run->id)->afterCommit();

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
            if (ShipAction::where('agent_id', $agent->id)->whereIn('state', ['sending', 'uncertain'])->exists()) {
                $this->executor->block($run, 'An action is uncertain. Reconcile before takeover.');

                return;
            }
            if (ShipAction::where('agent_id', $agent->id)->where('state', 'pending')->exists()) {
                return;
            }
            if ($run->stop_requested_at || $run->expires_at->isPast() || ! config('gameplay.enabled')) {
                $run->update(['state' => RunState::Stopped, 'active_agent_id' => null, 'blocked_reason' => $run->stop_requested_at ? 'Stop acknowledged. Manual control is available after transit.' : 'Duration reached or automation disabled.']);

                return;
            }
            if ($run->state === RunState::Blocked || ($run->next_action_at && $run->next_action_at->isFuture())) {
                return;
            }
            try {
                $this->sync->guardReset($agent);
                $ship = $this->sync->refreshShip($agent, $run->ship_symbol)->snapshot;
                $credits = $this->api->agent($agent)['credits'];
                $agent->update(['credits' => $credits]);
                if ($credits !== $run->plan['initial_credits'] + $run->totals['credit_delta']) {
                    throw new GameException('Agent credits changed outside this run. Create a new preview after reconciliation.');
                }
                if ($credits < $run->plan['reserve'] || $run->totals['credit_delta'] < -$run->plan['max_loss']) {
                    throw new GameException('Credit reserve or loss limit reached.');
                }
                $market = [];
                if ($ship['nav']['waypointSymbol'] === $run->plan['market'] && $ship['nav']['status'] !== 'IN_TRANSIT') {
                    $market = $this->api->market($agent, $run->plan['market']);
                    $this->sync->observe($agent, 'market', $run->plan['market'], $market);
                }
                $step = $this->planner->next($ship, $run->plan, $run->totals, $market, now()->toImmutable());
                match ($step->kind) {
                    'blocked' => $this->executor->block($run, $step->reason),
                    'wait' => $run->update(['state' => RunState::Waiting, 'next_action_at' => $step->until]),
                    'trip' => $this->completeTrip($run, $ship),
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

    private function enqueue(AutomationRun $run, MiningStep $step): void
    {
        DB::transaction(function () use ($run, $step) {
            if (! AutomationRun::whereKey($run->id)->whereNull('stop_requested_at')->whereNotNull('active_agent_id')->update(['state' => 'running', 'next_action_at' => now()->addSeconds(5)])) {
                return;
            }
            $action = ShipAction::create([
                'agent_id' => $run->agent_id, 'automation_run_id' => $run->id, 'key' => (string) Str::uuid(),
                'reset_id' => $run->reset_id, 'ship_symbol' => $run->ship_symbol, 'origin' => 'automation',
                'command' => $step->command, 'arguments' => array_merge($step->arguments, ['reserve' => $run->plan['reserve']]),
                'state' => 'pending', 'last_dispatched_at' => now(), 'expires_at' => min($run->expires_at, now()->addMinutes(3)),
            ]);
            ExecuteShipCommand::dispatch($action->id)->afterCommit();
        });
    }

    private function completeTrip(AutomationRun $run, array $ship): void
    {
        $totals = $run->totals;
        $totals['cycles']++;
        $totals['ending_fuel'] = $ship['fuel']['current'];
        $totals['unsold'] = MiningPlanner::inventory($ship);
        $totals['cash_contribution'] = $totals['sale_proceeds'] - $totals['fuel_cost'];
        $totals['elapsed_seconds'] = (int) $run->started_at->diffInSeconds(now());
        if ($totals['cycles'] >= $run->plan['cycles'] || $totals['cash_contribution'] <= 0) {
            $run->update(['totals' => $totals, 'state' => RunState::Completed, 'active_agent_id' => null, 'next_action_at' => null,
                'blocked_reason' => $totals['cash_contribution'] <= 0 ? 'Trip finished without positive cash contribution. A new preview is required.' : null]);

            return;
        }
        // Observed economics support another explicitly bounded trip. Revalidate route each cycle.
        $agent = Agent::findOrFail($run->agent_id);
        $this->planner->route($agent, $ship, $run->plan['site'], $run->plan['market']);
        $totals['phase'] = 'mine';
        $run->update(['totals' => $totals, 'next_action_at' => now(), 'last_dispatched_at' => null, 'state' => RunState::Running]);
    }
}
