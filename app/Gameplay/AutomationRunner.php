<?php

namespace App\Gameplay;

use App\Enums\ActionState;
use App\Enums\RunState;
use App\Jobs\AdvanceAutomationRun;
use App\Jobs\ExecuteShipCommand;
use App\Models\Agent;
use App\Models\AutomationDraft;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\LocalEngine;
use App\Models\ReconMission;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Services\SpaceTradersClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AutomationRunner
{
    public function __construct(private ShipCommandExecutor $executor, private AgentSynchronizer $sync, private MiningPlanner $planner, private SpaceTradersApi $api, private EngineGate $gate, private ContractPlanner $contracts) {}

    public function preview(Agent $agent, string $symbol, array $limits, ?CampaignEpisode $meshEpisode = null): AutomationRun
    {
        if (($limits['plan_version'] ?? null) === 8) {
            if (! $meshEpisode) {
                throw new GameException('Saturation preview requires campaign authority.');
            }

            return $this->previewSaturation($agent, $symbol, $limits, $meshEpisode);
        }
        if ($meshEpisode === null && (isset($limits['mesh_episode']) || isset($limits['mesh_work']))) {
            throw new GameException('Mesh authority requires an internal campaign scout.');
        }
        if ($this->gate->enabled() && ($limits['strategy'] ?? 'mining') === 'mining') {
            throw new GameException('Stage an investigated opportunity and create a versioned preview.');
        }

        return $this->executor->locked($agent, function () use ($agent, $symbol, $limits, $meshEpisode) {
            $this->executor->clear($agent, defer: true, ship: $symbol);
            $this->executor->assertConfigurationReviewed($agent, $symbol);
            $meshWork = isset($limits['mesh_work']);
            $reuseFleet = $meshWork || isset($limits['campaign_trade']);
            $this->sync->sync($agent, false, freshFleet: $meshWork,
                reuseLockedSeconds: $reuseFleet ? (float) config('gameplay.locked_fleet_reuse_seconds', 30) : 0);
            $ship = Ship::where('agent_id', $agent->id)->where('symbol', $symbol)->firstOrFail()->snapshot;
            if ($ship['nav']['status'] === 'IN_TRANSIT' || $ship['cargo']['units'] !== 0) {
                throw new GameException('Begin with a stationary ship and empty cargo. Existing cargo remains protected.');
            }
            $limits['strategy'] ??= 'mining';
            $limits = $this->prepare($agent, $ship, $limits, $meshEpisode);
            if (isset($limits['mesh_episode'])) {
                $episode = $meshEpisode;
                $duration = array_diff_key($limits, ['minutes' => true]);
                $limits['minutes'] = app(CampaignMesh::class)->scoutMinutes($episode->grant(), $episode, $agent, $ship, $duration);
            }
            if ($this->gate->enabled() && (app(CreditAccounting::class)->summary($agent)['unexplained_change'] ?? 0) !== 0) {
                throw new GameException('Unexplained balance change blocks attribution. Review before previewing.');
            }
            $route = $limits['route'];

            return AutomationRun::create([
                'engine_session_id' => $this->gate->enabled() ? LocalEngine::find(1)?->session_id : null,
                'strategy' => $limits['strategy'], 'plan_version' => $limits['strategy'] === 'mining' ? 1 : 3,
                'agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'ship_symbol' => $symbol, 'state' => RunState::Preview,
                'campaign_episode_id' => isset($limits['mesh_work']) ? $meshEpisode?->id : null,
                'expires_at' => now()->addSeconds(config('gameplay.preview_seconds')),
                'plan' => array_merge($limits, ['route' => $route, 'initial_inventory' => MiningPlanner::inventory($ship), 'initial_credits' => $agent->credits,
                    'participating_ships' => [$symbol], 'capital' => app(CycleAccounting::class)->capital($agent, [$symbol]),
                    'initial_configuration' => FuelPlanner::configuration($ship), 'equipment' => OpportunityEvaluator::equipment($ship), 'initial_quote_signatures' => $this->quoteSignatures($agent, $limits), 'initial_fuel' => $ship['fuel']['current'], 'initial_waypoint' => $ship['nav']['waypointSymbol'], 'initial_status' => $ship['nav']['status']]),
                'totals' => ['phase' => match ($limits['strategy']) {
                    'scout' => 'observe', 'trade' => 'buy', default => 'mine'
                }, 'purchased' => [], 'purchase_cost' => 0, 'travel_fuel' => 0, 'visits' => 0, 'cycles' => 0, 'attempts' => 0, 'no_progress' => 0, 'extracted' => [], 'sold' => [], 'sale_proceeds' => 0, 'fuel_cost' => 0, 'fuel_units' => 0, 'credit_delta' => 0],
            ]);
        });
    }

    private function previewSaturation(Agent $agent, string $symbol, array $limits, CampaignEpisode $episode): AutomationRun
    {
        return app(HostLock::class)->lockedAgentAndShips($agent->id, [$symbol], writer: true, work: function () use ($agent, $symbol, $limits, $episode) {
            $agent->refresh();
            $grant = $episode->grant();
            $safety = app(CampaignSafety::class);
            $authority = $safety->saturationGrant($episode, $agent);
            $policy = CampaignPlaybook::saturationPolicy($authority?->revision()->body ?? []);
            if (! $policy || ! $grant->available() || $episode->closed_at || $episode->agent_id !== $agent->id || $episode->reset_id !== $agent->reset_id) {
                throw new GameException('Saturation preview exceeds campaign authority.');
            }
            $this->executor->assertConfigurationReviewed($agent, $symbol);
            $ship = Ship::where('agent_id', $agent->id)->where('symbol', $symbol)->firstOrFail()->snapshot;
            $route = $limits['route'] ?? [];
            if ($limits['strategy'] === 'position' && ($limits['mode'] ?? $limits['flight_mode'] ?? null) === 'DRIFT' && $route === []) {
                $hard = $episode->next_reset_at->subHour();
                $hard = $grant->expires_at ? $hard->min($grant->expires_at) : $hard;
                $route = app(RoutePlanner::class)->planDrift($agent, $ship, $limits['destination'] ?? $limits['market'],
                    $hard, $policy['extraction']['drift_margin_seconds'] ?? 1800);
            }
            $limits = $this->pinSaturationTiming($agent, $limits);
            $bounds = $this->saturationBounds($limits, $episode, $policy, $route);
            $limits = array_replace($limits, $bounds);
            $fingerprint = null;
            if ($limits['strategy'] === 'buy_fleet') {
                $purchase = app(CampaignHaulerPurchase::class)->composeFleetPurchase($agent, $symbol, $limits['shipyard'], $limits['ship_type'], $limits, $episode);
                $plan = array_replace($limits, $purchase['plan']);
                $fingerprint = $purchase['fingerprint'];
            } elseif ($limits['strategy'] === 'sell_aboard') {
                $recovery = app(CampaignRecovery::class);
                $age = $recovery->verifiedBuyerAge($episode, $limits);
                $plan = array_replace($limits, $recovery->compose($agent, $symbol, $limits['market'], $limits, $age)['plan']);
            } elseif ($limits['strategy'] === 'scout') {
                $plan = app(OperationPlanner::class)->preview($agent, $ship, $limits, $episode);
            } else {
                $plan = app(OperationPlanner::class)->projectV8($agent, $ship, $limits, $episode);
            }
            $plan = array_replace($plan, [
                'plan_version' => 8, 'grant_id' => $grant->id, 'revision_hash' => $grant->revision_hash, 'campaign_episode_id' => $episode->id,
                'saturation_grant_id' => $authority->id, 'saturation_revision_hash' => $authority->revision_hash,
                'initial_credits' => $agent->credits, 'initial_inventory' => MiningPlanner::inventory($ship),
                'initial_fuel' => $ship['fuel']['current'], 'initial_waypoint' => $ship['nav']['waypointSymbol'],
                'initial_status' => $ship['nav']['status'], 'initial_configuration' => FuelPlanner::configuration($ship),
                'equipment' => OpportunityEvaluator::equipment($ship),
                'participating_ships' => [$symbol], 'capital' => app(CycleAccounting::class)->capital($agent, [$symbol]),
            ]);
            if (($plan['route']['mode'] ?? null) === 'DRIFT') {
                $hard = $grant->expires_at ? $episode->next_reset_at->min($grant->expires_at) : $episode->next_reset_at;
                $plan['route']['cleanup']['expires_at'] = $hard->toIso8601String();
                $plan['cleanup'] = $plan['route']['cleanup'];
            }
            $strategy = $plan['strategy'];
            $totals = [
                'phase' => match ($strategy) {
                    'trade' => 'buy', 'mining' => 'mine', 'siphon' => 'siphon', 'sell_aboard' => 'sell',
                    'scout' => 'observe', default => 'position',
                },
                'leg_index' => 0, 'hop_index' => 0, 'credit_delta' => 0, 'purchase_cost' => 0, 'sale_proceeds' => 0, 'fuel_cost' => 0,
                'spending' => 0, 'fuel_units' => 0, 'travel_fuel' => 0, 'cycles' => 0, 'visits' => 0, 'attempts' => 0,
                'no_progress' => 0, 'unsold' => $plan['initial_inventory'], 'purchased' => [], 'sold' => [], 'extracted' => [],
            ];
            $run = AutomationRun::create([
                'engine_session_id' => $this->gate->enabled() ? LocalEngine::find(1)?->session_id : null,
                'agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'ship_symbol' => $symbol, 'campaign_episode_id' => $episode->id,
                'plan_version' => 8, 'strategy' => $strategy, 'state' => RunState::Preview,
                'plan' => $plan, 'totals' => $totals, 'expires_at' => now()->addSeconds(config('gameplay.preview_seconds')),
                'fingerprint' => $fingerprint,
            ]);
            app(CampaignSafety::class)->check($run, $agent);

            return $run;
        }, seconds: 0);
    }

    private function saturationBounds(array $plan, CampaignEpisode $episode, array $policy, array $route): array
    {
        $safety = app(CampaignSafety::class);
        $grant = $episode->grant();
        $now = now()->toImmutable();
        $hard = ($plan['strategy'] ?? null) === 'sell_aboard' ? $episode->next_reset_at : $episode->next_reset_at->subHour();
        if ($grant->expires_at) {
            $hard = $hard->min($grant->expires_at);
        }
        $seconds = $grant->limits['run_max_minutes'] * 60;
        if (($plan['strategy'] ?? null) === 'position' && ($route['mode'] ?? null) === 'DRIFT') {
            $seconds = max($seconds, ($route['run_seconds'] ?? 0) + ($policy['stop_overhead_seconds'] ?? 30));
        }
        $settlement = $hard->min($now->addSeconds($seconds));
        if ($approved = $safety->planTime($plan['settlement_deadline'] ?? null)) {
            $settlement = $settlement->min($approved);
        }
        $planned = $route['estimated_seconds'] ?? $route['seconds'] ?? ($plan['minutes'] ?? 1) * 60;
        $acquisitionSeconds = isset($plan['elapsed_p90_seconds'])
            ? (int) ceil(1.25 * $plan['elapsed_p90_seconds'])
            : max($policy['acquisition_default_floor_seconds'] ?? 300, (int) ceil(1.25 * $planned));
        if (($plan['strategy'] ?? null) === 'trade') {
            $acquisitionSeconds = min(5400, $policy['run_hard_cap_seconds'] ?? 5400, $acquisitionSeconds);
        }
        $acquisitionSeconds = ($route['mode'] ?? null) === 'DRIFT' ? max($acquisitionSeconds, ($route['run_seconds'] ?? 0) + ($policy['stop_overhead_seconds'] ?? 30)) : $acquisitionSeconds;
        $cutoff = $settlement->min($now->addSeconds($acquisitionSeconds));
        if ($approved = $safety->planTime($plan['acquisition_cutoff'] ?? null)) {
            $cutoff = $cutoff->min($approved);
        }
        if ($settlement->lte($now) || $cutoff->lte($now)) {
            throw new GameException('Campaign settlement or acquisition authority has expired.');
        }

        return ['acquisition_cutoff' => $cutoff->toIso8601String(), 'settlement_deadline' => $settlement->toIso8601String(), 'cycles' => 1];
    }

    private function pinSaturationTiming(Agent $agent, array $plan): array
    {
        if (! empty($plan['pipeline_preselected'])) {
            $plan['pipeline_selected_at'] ??= now()->toIso8601String();
        }
        if (isset($plan['elapsed_p90_seconds']) || ! in_array($plan['strategy'], ['trade', 'mining', 'siphon'], true)) {
            return $plan;
        }

        $samples = AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('strategy', $plan['strategy'])->where('plan_version', 8)->where('state', RunState::Completed)
            ->whereNotNull('totals->elapsed_seconds')->get(['plan', 'totals'])
            ->filter(function (AutomationRun $other) use ($plan) {
                $totals = $other->totals;
                if (($totals['cycles'] ?? 0) < 1 || ! empty($totals['empty_cutoff_exit']) || ! empty($totals['acquisition_closed_at'])
                    || ! empty($totals['leg_acquisition_closed']) || isset($other->plan['original_units'])) {
                    return false;
                }
                if ($plan['strategy'] !== 'trade') {
                    return true;
                }
                $legs = $other->plan['legs'] ?? null;
                if (count($legs ?? [1]) !== count($plan['legs'] ?? [1])) {
                    return false;
                }
                foreach ($legs ?? [] as $index => $leg) {
                    if (($totals['leg_sold'][$index] ?? 0) < ($leg['approvedUnits'] ?? PHP_INT_MAX)) {
                        return false;
                    }
                }

                return true;
            })
            ->pluck('totals')
            ->map(fn (array $totals) => $totals['elapsed_seconds'] ?? null)
            ->filter(fn ($seconds) => is_int($seconds) && $seconds > 0)->sort()->values();
        if ($samples->count() >= 5) {
            $plan['elapsed_p90_seconds'] = $samples[(int) ceil(0.9 * $samples->count()) - 1];
        }

        return $plan;
    }

    public function start(Agent $agent, AutomationRun $run, bool $acceptContract = false, ?float $deadline = null): AutomationRun
    {
        return $this->executor->locked($agent, function () use ($agent, $run, $acceptContract, $deadline) {
            $agent->refresh();
            $run->refresh();
            if ($run->state !== RunState::Preview) {
                if (in_array($run->state, [RunState::Stopped, RunState::Blocked], true)) {
                    throw new GameException('This preview was invalidated or stopped. Create a fresh preview.');
                }

                return $run;
            }
            if (isset($run->plan['mesh_work']) && (! RequestBudget::currentMeshAllowance()
                || RequestBudget::currentMeshAllowance() !== ($run->plan['mesh_allowance'] ?? null))) {
                throw (new RequestDeferred('Mesh preview start requires its current request allowance.', 10, delaySource: 'mesh_allowance'))->withDeadline($deadline);
            }
            if (microtime(true) >= ($deadline ?? INF)) {
                throw (new RequestDeferred('Mesh start exceeded the pass deadline.', 5, delaySource: 'pass_deadline', deadlineCapped: true))->withDeadline($deadline);
            }
            if (! config('gameplay.enabled')) {
                throw new GameException('Automation is disabled. Enable it only after local migration, backup and credential checks.');
            }
            $this->gate->check($run->engine_session_id);
            $this->executor->clear($agent, defer: true, ship: $run->ship_symbol);
            $this->executor->assertConfigurationReviewed($agent, $run->ship_symbol);
            if ($run->expires_at->isPast() || $run->reset_id !== $agent->reset_id || AutomationRun::where('active_agent_id', $agent->id)->where('ship_symbol', $run->ship_symbol)->exists()) {
                $this->rejectPreview($run, 'Preview expired, reset or another run already owns this ship.');
            }
            if (! in_array($run->plan_version, [1, 2, 3, 4, 5, 6, 7, 8], true) || ($this->gate->enabled() && ! in_array($run->plan_version, [2, 3, 4, 5, 6, 7, 8], true)) || ($run->plan_version === 3 && ! in_array($run->strategy, ['scout', 'trade'], true)) || ($run->plan_version === 4 && (! $run->campaign_episode_id || $run->strategy !== 'sell_aboard')) || ($run->plan_version === 6 && (! $run->campaign_episode_id || $run->strategy !== 'buy_probe')) || ($run->plan_version === 7 && (! $run->campaign_episode_id || $run->strategy !== 'buy_hauler'))) {
                throw new GameException('Unsupported or historical plan version. Create a fresh staged preview.');
            }
            $v8 = $run->plan_version === 8;
            if ($v8) {
                $plan = $this->startSaturation($agent, $run, $deadline);
            } elseif (in_array($run->plan_version, [6, 7], true)) {
                $episode = CampaignEpisode::findOrFail($run->campaign_episode_id);
                $purchase = $run->strategy === 'buy_hauler' ? app(CampaignHaulerPurchase::class) : app(CampaignProbeBootstrap::class);
                $fresh = $purchase->compose($agent, $run->ship_symbol, $run->plan['shipyard'], $run->plan, $episode, $run->id, deadline: $deadline);
                if (! hash_equals($run->fingerprint, $fresh['fingerprint'])) {
                    $this->rejectPreview($run, 'Purchase ship, balance, fleet count, route or shipyard offer changed. Create a fresh preview.');
                }
                $plan = $fresh['plan'];
            } elseif ($run->plan_version === 5) {
                if ($run->strategy !== 'gate_recon') {
                    throw new GameException('Version 5 is reserved for gate reconnaissance.');
                }
                $plan = app(GateRecon::class)->validateStart($agent, $run);
            } elseif ($run->plan_version === 4) {
                $episode = CampaignEpisode::findOrFail($run->campaign_episode_id);
                $recovery = app(CampaignRecovery::class);
                $age = $recovery->verifiedBuyerAge($episode, $run->plan);
                $limits = $this->recoveryLimits($episode, $run->plan, $age);
                $fresh = $recovery->compose($agent, $run->ship_symbol, $run->plan['market'], $limits, $age);
                if (! hash_equals($run->fingerprint, $fresh['fingerprint'])) {
                    $this->rejectPreview($run, 'Recovery ship, balance or quote changed. Create a fresh preview.');
                }
                $plan = $fresh['plan'];
            } elseif ($run->plan_version === 2) {
                $draft = AutomationDraft::where('agent_id', $agent->id)->findOrFail($run->draft_id);
                if ($draft->revision !== $run->draft_revision || $draft->reset_id !== $agent->reset_id) {
                    $this->rejectPreview($run, 'Draft revision changed. Create a fresh preview.');
                }
                try {
                    $fresh = $this->compose($agent, $draft);
                } catch (GameException $e) {
                    if ($run->campaign_episode_id && $e->retryAfter > 0) {
                        throw $e;
                    }
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
                $meshWork = isset($run->plan['mesh_work']);
                $reuseFleet = $meshWork || isset($run->plan['campaign_trade']);
                $this->sync->sync($agent, false, freshFleet: $meshWork,
                    reuseLockedSeconds: $reuseFleet ? (float) config('gameplay.locked_fleet_reuse_seconds', 30) : 0);
                $ship = Ship::where('agent_id', $agent->id)->where('symbol', $run->ship_symbol)->firstOrFail()->snapshot;
                $plan = $run->plan;
                $episode = isset($plan['mesh_episode']) ? CampaignEpisode::findOrFail($run->campaign_episode_id) : null;
                $fresh = $this->prepare($agent, $ship, $plan, $episode);
                if ($episode) {
                    app(CampaignMesh::class)->scoutMinutes($episode->grant(), $episode, $agent, $ship, $fresh);
                }
                if (isset($plan['campaign_local_earn'])) {
                    app(CampaignLocalEarn::class)->checkStart($agent, $run, CampaignEpisode::findOrFail($run->campaign_episode_id), $ship);
                }
                if ($agent->credits !== $plan['initial_credits'] || MiningPlanner::inventory($ship) !== $plan['initial_inventory'] || $ship['fuel']['current'] !== $plan['initial_fuel'] || $ship['nav']['waypointSymbol'] !== $plan['initial_waypoint'] || $ship['nav']['status'] !== $plan['initial_status'] || FuelPlanner::configuration($ship) !== ($plan['initial_configuration'] ?? FuelPlanner::configuration($ship)) || (isset($plan['initial_quote_signatures']) && $plan['initial_quote_signatures'] !== $this->quoteSignatures($agent, $fresh))) {
                    $this->rejectPreview($run, 'Ship state, equipment or quotes changed. Create a fresh operation preview.');
                }
                $plan['route'] = $fresh['route'];
            }
            $plan['initial_manual_credit_delta'] = $this->manualCreditDelta($agent);
            $plan['initial_external_credit_delta'] = (int) ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('state', 'confirmed')
                ->where(fn ($query) => $query->whereNull('automation_run_id')->orWhere('automation_run_id', '!=', $run->id))
                ->get()->sum(fn ($action) => $action->outcome['credit_delta'] ?? 0);
            app(CampaignSafety::class)->check($run, $agent);
            if (isset($plan['mesh_work'])) {
                $episode = CampaignEpisode::findOrFail($run->campaign_episode_id);
                $destination = $plan['destination'] ?? $plan['shipyard'] ?? $plan['market'] ?? null;
                $ship = Ship::where('agent_id', $agent->id)->where('symbol', $run->ship_symbol)->firstOrFail()->snapshot;
                if ((! $v8 || $run->strategy === 'scout') && ($ship['nav']['waypointSymbol'] !== $destination || $plan['mesh_work'] !== 'purchase')) {
                    app(CampaignMesh::class)->assertDeparture($episode, $agent, $ship, $destination, $run->id, CampaignMesh::isYardMove($plan));
                }
                if (microtime(true) >= ($deadline ?? INF)) {
                    throw (new RequestDeferred('Mesh start exceeded the pass deadline.', 5, delaySource: 'pass_deadline', deadlineCapped: true))->withDeadline($deadline);
                }
                $duration = $v8 ? (int) now()->diffInSeconds($plan['settlement_deadline'], false) : $plan['minutes'] * 60;
                app(RequestBudget::class)->bindMesh($plan['mesh_allowance'], $run->id, max(1, $duration), $deadline);
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                throw (new RequestDeferred('Pass deadline reached before mesh run could start.', 10, delaySource: 'pass_deadline', deadlineCapped: true))->withDeadline($deadline);
            }
            DB::transaction(function () use ($run, $agent, $plan, $deadline) {
                $this->gate->check($run->engine_session_id);
                if ($deadline !== null && microtime(true) >= $deadline) {
                    throw (new RequestDeferred('Pass deadline reached before mesh run could start.', 10, delaySource: 'pass_deadline', deadlineCapped: true))->withDeadline($deadline);
                }
                $expires = match ($run->plan_version) {
                    5 => CarbonImmutable::parse($plan['return_deadline']),
                    8 => CarbonImmutable::parse($plan['settlement_deadline']),
                    default => now()->addMinutes($plan['minutes']),
                };
                if ($run->strategy !== 'sell_aboard') {
                    $snapshot = Ship::where('agent_id', $agent->id)->where('symbol', $run->ship_symbol)->first()?->snapshot ?? [];
                    app(StateChain::class)->releaseRecoveryFuelHoldIfEmpty($agent, $run->ship_symbol, $snapshot);
                }
                if ($run->plan_version === 8) {
                    $this->commitSaturationStart($agent, $run, $plan);
                } elseif ($run->strategy === 'sell_aboard') {
                    $this->claimRecoveryFuel($agent, $run);
                }
                $run->update(['plan' => $plan, 'active_agent_id' => $agent->id, 'state' => RunState::Running, 'started_at' => now(), 'expires_at' => $expires, 'next_action_at' => now(), 'last_dispatched_at' => now()]);
                AdvanceAutomationRun::dispatch($run->id)->afterCommit();
            });

            return $run;
        }, priority: $run->strategy === 'sell_aboard' && $this->usesRecoveryReserve($run) ? 'settlement' : 'critical');
    }

    private function startSaturation(Agent $agent, AutomationRun $run, ?float $deadline): array
    {
        $episode = CampaignEpisode::findOrFail($run->campaign_episode_id);
        $grant = $episode->grant();
        $safety = app(CampaignSafety::class);
        $policy = $safety->saturationPolicy($run);
        $plan = $this->pinSaturationTiming($agent, $run->plan);
        $safety->check($run, $agent);
        $this->sync->guardReset($agent);
        $rules = app(CommandRules::class);
        $rules->refreshCredits($agent, $run, true, reconcile: true);
        $ship = $rules->shipForRun($agent, $run, force: true);
        $initial = $plan['initial_inventory'];
        $inventory = MiningPlanner::inventory($ship);
        ksort($initial);
        ksort($inventory);
        if ($ship['nav']['waypointSymbol'] !== $plan['initial_waypoint'] || $ship['nav']['status'] !== $plan['initial_status']
            || FuelPlanner::configuration($ship) !== $plan['initial_configuration'] || OpportunityEvaluator::equipment($ship) !== $plan['equipment']
            || $inventory !== $initial || $ship['fuel']['current'] < $this->minimumStartFuel($plan)
            || $safety->unexplainedBalance($agent)) {
            $this->rejectPreview($run, 'Ship location, cargo, fuel, equipment or credit attribution changed. Create a fresh preview.');
        }
        $plan = array_replace($plan, $this->saturationBounds($plan, $episode, $policy, $plan['route'] ?? []));
        if (in_array($run->strategy, ['mining', 'siphon'], true)) {
            $plan['delivery_seconds'] ??= $plan['route']['delivery_seconds'] ?? 0;
            $cutoff = CarbonImmutable::parse($plan['acquisition_cutoff'])
                ->min(CarbonImmutable::parse($plan['settlement_deadline'])->subSeconds($plan['delivery_seconds']));
            if ($approved = $safety->planTime($plan['effective_acquisition_cutoff'] ?? null)) {
                $cutoff = $cutoff->min($approved);
            }
            if (! $cutoff->isFuture()) {
                $this->rejectPreview($run, 'Extraction acquisition authority has expired.');
            }
            $plan['effective_acquisition_cutoff'] = $cutoff->toIso8601String();
        }
        if ($run->strategy === 'buy_fleet') {
            $safety->assertFleetPurchaseSlot($agent, $run->id);
            $purchase = app(CampaignHaulerPurchase::class)->composeFleetPurchase($agent, $run->ship_symbol, $plan['shipyard'], $plan['ship_type'], $plan + ['ignore_run_id' => $run->id], $episode, $deadline);
            if ($purchase['fingerprint'] !== $run->fingerprint) {
                $this->rejectPreview($run, 'Fleet purchase authority, configuration or offer changed.');
            }
        }
        if ($run->strategy === 'trade') {
            $plan = $this->startTradeV8($agent, $run, $ship, $plan, $episode, $grant, $policy);
        }
        $exposure = app(StateChain::class)->outstandingExposure($agent,
            $run->strategy === 'sell_aboard' ? 'recovery:'.$run->ship_symbol : 'run:'.$run->id);
        $floor = $plan['reserve'] + max(0, $exposure - ($plan['reserve_parts']['commitments'] ?? 0));
        if ($agent->credits < $floor + ($plan['route']['fuel_ceiling'] ?? 0)) {
            $this->rejectPreview($run, 'Available balance no longer covers protected delivery and fuel commitments.');
        }
        $plan['initial_credits'] = $agent->credits;
        $plan['initial_reviewed_adjustment'] = app(CreditAccounting::class)->summary($agent)['reviewed_adjustments'];
        $plan['initial_fuel'] = $ship['fuel']['current'];

        return $plan;
    }

    private function minimumStartFuel(array $plan): int
    {
        $route = $plan['route'] ?? [];
        if (($route['mode'] ?? null) === 'DRIFT') {
            return 1 + max(2, $route['uncertainty_reserve'] ?? 2);
        }
        $hop = $route['hops'][0] ?? null;
        if ($hop && empty($hop['topped_up'])) {
            return $hop['burn'] + ($hop['continuation_fuel'] ?? 0) + ($hop['uncertainty_reserve'] ?? 0);
        }

        return $hop ? 0 : ($plan['initial_fuel'] ?? 0);
    }

    private function startTradeV8(Agent $agent, AutomationRun $run, array $ship, array $plan, CampaignEpisode $episode, CampaignGrant $grant, array $policy): array
    {
        $planner = app(OperationPlanner::class);
        $pct = $policy['start_band_price_pct'] ?? 5;
        $profitPct = $policy['start_band_profit_pct'] ?? 10;
        $profitFloor = $policy['start_band_profit_min_credits'] ?? 2000;
        $age = $policy['corridor']['stale_seconds'] ?? 900;
        $softMiss = false;
        $projectedMargin = 0;
        $cost = 0;
        foreach ($plan['legs'] as $index => $leg) {
            $ask = $planner->quote($agent, $leg['source'], $leg['good'], $age);
            $bid = $planner->quote($agent, $leg['buyer'], $leg['good'], $age);
            if (now()->subSeconds($age)->gt($ask['observed_at']) || now()->subSeconds($age)->gt($bid['observed_at']) || $ask['purchasePrice'] < 1 || $bid['sellPrice'] < 1) {
                $this->rejectPreview($run, 'A planned trade endpoint lacks current ranked quote evidence.');
            }
            $margin = app(CampaignTrade::class)->prediction($leg['approvedUnits'], $ask, $bid,
                $leg['decay'] ?? $plan['campaign_trade']['decay'] ?? 0, $leg['expectedFuelCost'] ?? 0);
            $projectedMargin += $margin['predicted_margin'];
            $cost += $margin['predicted_cost'];
            $plan['legs'][$index]['acquisition_cash'] = $margin['predicted_cost'];
            $prior = $leg['prediction']['predicted_margin'];
            $softMiss = $softMiss || $ask['purchasePrice'] > ceil($leg['quoteEvidence']['ask']['purchasePrice'] * (100 + $pct) / 100)
                || $bid['sellPrice'] < floor($leg['quoteEvidence']['bid']['sellPrice'] * (100 - $pct) / 100)
                || $margin['predicted_margin'] < $prior - max((int) ceil($prior * $profitPct / 100), $profitFloor);
        }
        $fuel = $plan['route']['fuel_ceiling'] ?? 0;
        $reserved = app(StateChain::class)->outstandingExposure($agent, 'run:'.$run->id);
        $arm = $grant->arm() ? app(CampaignTrade::class)->allowance($grant, $agent, $run) : PHP_INT_MAX;
        $budget = min($plan['max_spend'], $agent->credits - $reserved - $plan['reserve'], $arm);
        $stimulus = count($plan['legs']) === 1 ? ($plan['stimulus'] ?? null) : null;
        if ($projectedMargin + (int) ($stimulus['bonus'] ?? 0) <= ($plan['request_shadow_cost'] ?? 0)
            || ($stimulus !== null && $projectedMargin < -(int) ($stimulus['loss_auth'] ?? 0))) {
            $this->rejectPreview($run, 'Trade no longer has a positive charged value.');
        }
        if (! $softMiss && $cost + $fuel <= $budget) {
            return $plan;
        }
        if ($agent->credits - $reserved - $plan['reserve'] < $plan['legs'][0]['acquisition_cash'] + $fuel) {
            $this->rejectPreview($run, 'Insufficient unreserved cash for the approved trade lot and delivery fuel.');
        }
        if ($stimulus !== null) {
            $this->rejectPreview($run, 'Stimulated trade changed outside the pinned start bands or spending authority.');
        }

        $considered = [];
        $ranked = app(CampaignTrade::class)->rank($grant, $episode, $agent, $ship, $considered, persist: false);
        $attempts = [$plan];
        $same = $plan['legs'][0];
        $unitRoom = $cost > 0 ? min(1, max(0, ($budget - $fuel) / $cost)) : 1;
        foreach ($attempts[0]['legs'] as $index => &$leg) {
            $leg['approvedUnits'] = min($leg['approvedUnits'], (int) floor($leg['approvedUnits'] * $unitRoom));
            $candidate = collect($ranked)->first(fn ($row) => [$row['source'], $row['buyer'], $row['good']] === [$leg['source'], $leg['buyer'], $leg['good']]);
            if ($candidate) {
                $leg['approvedUnits'] = min($leg['approvedUnits'], $candidate['units']);
            }
        }
        unset($leg);
        if (count($plan['legs']) === 1 && empty($plan['canary_family']) && empty($plan['stimulus_entry_id'])) {
            $alternate = collect($ranked)->first(fn ($row) => [$row['source'], $row['buyer'], $row['good']] !== [$same['source'], $same['buyer'], $same['good']]);
            if ($alternate) {
                $attempts[] = array_replace($plan, ['legs' => [[
                    'source' => $alternate['source'], 'buyer' => $alternate['buyer'], 'good' => $alternate['good'],
                    'approvedUnits' => min($same['approvedUnits'], $alternate['units']),
                    'proofMode' => $alternate['mode'], 'decay' => $alternate['decay'],
                    'maxInventory' => min($same['maxInventory'], $alternate['max_inventory']),
                ]]]);
            }
        }
        foreach ($attempts as $attempt) {
            try {
                $replacement = $planner->projectV8($agent, $ship, $attempt, $episode);
            } catch (GameException) {
                continue;
            }
            if ($this->tradeRequestShape($replacement) > $this->tradeRequestShape($plan) || $replacement['route']['fuel_estimate'] > $plan['max_fuel']
                || array_sum(array_map(fn ($leg) => $leg['prediction']['predicted_cost'], $replacement['legs'])) + $replacement['route']['fuel_ceiling'] > $budget) {
                continue;
            }
            $orders = $this->recomposeCorridorOrders($agent, $run, $replacement, $policy);
            if ($orders === null) {
                continue;
            }
            foreach ($replacement['legs'] as &$leg) {
                $leg['acquisition_cash'] = $leg['prediction']['predicted_cost'];
            }
            unset($leg);
            $replacement['recomposed_orders'] = $orders;
            $replacement['start_recomposed_at'] = now()->toIso8601String();
            $replacement['plan_revision'] = ($plan['plan_revision'] ?? 0) + 1;

            return $replacement;
        }
        $this->rejectPreview($run, 'Trade changed outside the start bands and no bounded local recomposition qualified.');
    }

    private function tradeRequestShape(array $plan): int
    {
        return array_sum(array_map(fn ($leg) => 3 * count($leg['hops']) + 2 * (count($leg['buyBatches']) + count($leg['sellBatches'])
            + count(array_filter($leg['hops'], fn ($hop) => ! empty($hop['topped_up'])))), $plan['legs']));
    }

    private function recomposeCorridorOrders(Agent $agent, AutomationRun $run, array $plan, array $policy): ?array
    {
        $book = app(CorridorBook::class);
        $active = $book->activeEvents($agent);
        $ours = fn (array $event) => $event['owner_type'] === 'run' && $event['owner_id'] === (string) $run->id;
        if (! array_filter($active, $ours)) {
            return [];
        }
        $retained = array_values(array_filter($active, fn ($event) => ! $ours($event)));
        $orders = $quotes = [];
        $landed = now()->timestamp;
        foreach ($plan['legs'] as $index => $leg) {
            $delivery = $leg['deliverySeconds'];
            $forBuy = $landed + $leg['estimatedSeconds'] - $delivery;
            $landed += $leg['estimatedSeconds'];
            foreach (['buy' => [$leg['source'], $leg['buyBatches'], $leg['quoteEvidence']['ask'], $forBuy],
                'sell' => [$leg['buyer'], $leg['sellBatches'], $leg['quoteEvidence']['bid'], $landed]] as $side => [$market, $batches, $quote, $at]) {
                $quotes[$market.'|'.$leg['good'].'|'.$side] = $quote;
                foreach ($batches as $batchIndex => $batch) {
                    $orders[] = ['owner_type' => 'run', 'owner_id' => (string) $run->id,
                        'side' => $side, 'market' => $market, 'good' => $leg['good'], 'units' => $batch['units'],
                        'leg_index' => $index, 'batch_index' => $batchIndex, 'landing_at' => $at + 10 * $batchIndex,
                        'tie_key' => implode('|', [$run->ship_symbol, $run->id, $index, $batchIndex, $side])];
                }
            }
        }
        $corridor = $policy['corridor'] ?? [];
        $prices = $book->priceAndValidate($retained, $orders, $quotes, depthBatches: $corridor['side_depth_batches'] ?? 7,
            recoverySeconds: $corridor['recovery_seconds_prior'] ?? 1800);
        if ($prices === null) {
            return null;
        }
        $cost = $income = 0;
        foreach ($orders as $index => &$order) {
            $order['guard_price'] = $prices[$index];
            if ($order['side'] === 'buy') {
                $cost += $order['units'] * $prices[$index];
            } else {
                $income += $order['units'] * $prices[$index];
            }
        }
        unset($order);
        if ($income - $cost <= ($plan['route']['expected_fuel_cost'] ?? 0) + ($plan['request_shadow_cost'] ?? 0)
            || $cost + ($plan['route']['fuel_ceiling'] ?? 0) > $plan['max_spend']) {
            return null;
        }

        return $orders;
    }

    private function commitSaturationStart(Agent $agent, AutomationRun $run, array &$plan): void
    {
        if ($run->strategy === 'sell_aboard') {
            $this->claimRecoveryFuel($agent, $run);
        }
        $fuel = (int) ($plan['route']['fuel_ceiling'] ?? 0);
        if ($fuel > 0) {
            app(StateChain::class)->reserveCredit($agent, 'run:'.$run->id, 'delivery_fuel', 'refuel', $fuel, runId: $run->id, shipSymbol: $run->ship_symbol);
        }
        if ($run->strategy === 'trade' && ! $this->reserveTradeLot($agent, $run, $plan, 0)) {
            throw new GameException('Insufficient unreserved cash for the approved trade lot.');
        }
        if (! empty($plan['recomposed_orders'])) {
            $book = app(CorridorBook::class);
            $book->releaseOwner($agent, 'run', (string) $run->id);
            $book->reserveOrders($agent, $plan['recomposed_orders']);
        }
        unset($plan['recomposed_orders']);
    }

    public function reserveTradeLot(Agent $agent, AutomationRun $run, array $plan, int $index): bool
    {
        $leg = $plan['legs'][$index] ?? [];
        $cash = (int) ($leg['acquisition_cash'] ?? $leg['prediction']['predicted_cost'] ?? 0);
        if ($cash <= 0) {
            return true;
        }
        $chain = app(StateChain::class);
        $owner = 'run:'.$run->id;
        $outstanding = $chain->outstandingExposure($agent, $owner, 'trade_lot');
        if ($agent->credits - $plan['reserve'] - $outstanding < $cash || $chain->hasUnresolvedCreditAction($agent)) {
            return false;
        }
        $chain->reserveCredit($agent, $owner, 'trade_lot', 'buy', $cash, runId: $run->id, shipSymbol: $run->ship_symbol);

        return true;
    }

    private function claimRecoveryFuel(Agent $agent, AutomationRun $run): void
    {
        DB::table('credit_reservations')->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('owner_key', 'recovery:'.$run->ship_symbol)->where('purpose', 'delivery_fuel')
            ->whereNull('ship_action_id')->where('state', 'reserved')
            ->update(['owner_key' => 'run:'.$run->id, 'automation_run_id' => $run->id, 'updated_at' => now()]);
    }

    public function stop(AutomationRun $run): void
    {
        AutomationRun::whereKey($run->id)->whereNotNull('active_agent_id')->update(['stop_requested_at' => now(), 'state' => 'stopping', 'next_action_at' => now(), 'last_dispatched_at' => null]);
    }

    public function startCampaign(Agent $agent, AutomationRun $run, CampaignEpisode $episode, ?float $deadline = null): AutomationRun
    {
        return app(HostLock::class)->scoped('agent-'.$agent->id, function () use ($agent, $run, $episode, $deadline) {
            $grant = $episode->grant();
            $agent->refresh();
            $episode->refresh();
            $v8 = $run->plan_version === 8;
            $drift = $v8 && $run->strategy === 'position' && ($run->plan['route']['mode'] ?? null) === 'DRIFT';
            $extended = isset($run->plan['mesh_episode']) && (! $v8 || $run->strategy === 'scout');
            if ($extended) {
                $ship = Ship::where('agent_id', $agent->id)->where('symbol', $run->ship_symbol)->firstOrFail()->snapshot;
                if ($run->plan['mesh_episode'] !== $episode->id) {
                    throw new GameException('Positioning is bound to its original campaign episode.');
                }
                app(CampaignMesh::class)->scoutMinutes($grant, $episode, $agent, $ship, $run->plan);
            }
            if (! $grant->available() || $episode->agent_id !== $agent->id || $episode->closed_at || $run->agent_id !== $agent->id || $episode->reset_id !== $agent->reset_id
                || ($grant->arm() && CampaignDirector::armReset($grant, $agent->reset_id, CampaignDirector::cachedSchedule()) !== $agent->reset_id)
                || ($grant->isDraining() && $run->strategy !== 'sell_aboard')
                || ! hash_equals($grant->revision_hash, $grant->revision()->content_hash)
                || ! in_array($run->strategy, ['scout', 'trade', 'mining', 'sell_aboard', 'buy_probe', 'buy_hauler', ...($v8 ? ['siphon', 'survey', 'position', 'tender', 'buy_fleet'] : [])], true)
                || ($run->strategy === 'buy_probe' && ! CampaignProbeBootstrap::policy($grant, $episode))
                || ($run->strategy === 'buy_hauler' && ! app(CampaignHaulerPurchase::class)->policy($grant, $episode, $agent))
                || (empty($run->plan['sell_only']) && $grant->slot === 1 && CampaignGrant::pendingFor($grant->user_id, $grant->resolvedAgentSymbol())->exists())
                || $run->plan['reserve'] < $grant->limits['reserve_credits'] || (! $extended && ! $drift && ($run->plan['minutes'] ?? 0) > $grant->limits['run_max_minutes'])
                || $run->plan['cycles'] !== 1
                || (in_array($run->strategy, ['trade', 'mining', 'buy_probe', 'buy_hauler'], true) && app(CampaignSafety::class)->windDown($episode))) {
                throw new GameException('Preview exceeds campaign authority.');
            }
            if ($v8 && ($run->campaign_episode_id !== $episode->id || ($run->plan['campaign_episode_id'] ?? null) !== $episode->id)) {
                throw new GameException('Saturation preview belongs to a different campaign episode.');
            }
            $run->update(['campaign_episode_id' => $episode->id]);
            if ($run->strategy === 'trade' && ! $v8) {
                app(CampaignTrade::class)->authorize($agent, $run, $episode);
            }

            if (CampaignLocalEarn::policy($grant, $episode)) {
                app(CampaignTrade::class)->recordResults($episode);
                app(CampaignSafety::class)->check($run, $agent);
            }
            $current = $run->fresh();
            if ($current->state === RunState::Preview && $deadline !== null && microtime(true) >= $deadline) {
                throw (new RequestDeferred('Mesh start exceeded the pass deadline.', 5, delaySource: 'pass_deadline', deadlineCapped: true))->withDeadline($deadline);
            }
            $alreadyStarted = $current->started_at !== null;
            $started = app(MeshGovernor::class)->run($agent, $run, fn () => $this->start($agent, $run, deadline: $deadline), $deadline);
            if (! $alreadyStarted && CampaignLocalEarn::policy($grant, $episode)) {
                app(CampaignLocalEarn::class)->started($started, $episode);
            }

            return $started;
        });
    }

    public function previewProbePurchase(Agent $agent, string $symbol, string $shipyard, array $limits, CampaignEpisode $episode, ?float $deadline = null): AutomationRun
    {
        $work = fn () => $this->previewPurchase($agent, $symbol, $shipyard, $limits, $episode, 'buy_probe', $deadline);
        if (isset(CampaignMesh::policy($episode)['saturation']) && ! RequestBudget::currentMeshAllowance()) {
            return app(MeshGovernor::class)->preview($agent, $episode, 'buy', $work, deadline: $deadline);
        }

        return $work();
    }

    public function previewHaulerPurchase(Agent $agent, string $symbol, string $shipyard, array $limits, CampaignEpisode $episode, ?float $deadline = null, ?array &$saturation = null): AutomationRun
    {
        $work = function () use ($agent, $symbol, $shipyard, $limits, $episode, $deadline, &$saturation) {
            return $this->previewPurchase($agent, $symbol, $shipyard, $limits, $episode, 'buy_hauler', $deadline, $saturation);
        };
        $ship = Ship::where('agent_id', $agent->id)->where('symbol', $symbol)->first()?->snapshot;
        if ($ship && app(CampaignProbeBootstrap::class)->requiresHaulerMeshMovement($episode, $ship, $shipyard) && ! RequestBudget::currentMeshAllowance()) {
            return app(MeshGovernor::class)->preview($agent, $episode, 'hauler_purchase', $work, deadline: $deadline);
        }

        return $work();
    }

    private function previewPurchase(Agent $agent, string $symbol, string $shipyard, array $limits, CampaignEpisode $episode, string $strategy, ?float $deadline = null, ?array &$saturation = null): AutomationRun
    {
        return $this->executor->locked($agent, function () use ($agent, $symbol, $shipyard, $limits, $episode, $strategy, $deadline, &$saturation) {
            $this->executor->clear($agent, defer: true, ship: $symbol);
            $this->executor->assertConfigurationReviewed($agent, $symbol);
            $purchase = $strategy === 'buy_hauler' ? app(CampaignHaulerPurchase::class) : app(CampaignProbeBootstrap::class);
            $fresh = $purchase->compose($agent, $symbol, $shipyard, $limits, $episode, deadline: $deadline);
            $saturation = $fresh['saturation'] ?? null;

            $run = AutomationRun::create([
                'agent_id' => $agent->id, 'campaign_episode_id' => $episode->id, 'reset_id' => $agent->reset_id,
                'engine_session_id' => $this->gate->enabled() ? LocalEngine::find(1)?->session_id : null,
                'ship_symbol' => $symbol, 'strategy' => $strategy, 'plan_version' => $strategy === 'buy_hauler' ? 7 : 6, 'state' => RunState::Preview,
                'expires_at' => now()->addSeconds(config('gameplay.preview_seconds')), 'plan' => $fresh['plan'], 'fingerprint' => $fresh['fingerprint'],
                'totals' => ['phase' => $strategy, 'purchased_ships' => [], 'cycles' => 0, 'visits' => 0, 'attempts' => 0, 'no_progress' => 0,
                    'extracted' => [], 'sold' => [], 'sale_proceeds' => 0, 'fuel_cost' => 0, 'fuel_units' => 0, 'travel_fuel' => 0, 'credit_delta' => 0],
            ]);
            if (isset($run->plan['mesh_work'])) {
                app(RequestBudget::class)->bindMesh($run->plan['mesh_allowance'], $run->id, (int) now()->diffInSeconds($run->expires_at, false), $deadline);
            }

            return $run;
        });
    }

    public function previewMeshScout(Agent $agent, array $ship, string $destination, array $limits, CampaignEpisode $episode, string $observe = 'market', ?float $deadline = null): AutomationRun
    {
        $saturation = isset(CampaignMesh::policy($episode)['saturation']);
        $work = function () use ($agent, $ship, $destination, $limits, $episode, $observe, $saturation, $deadline) {
            $limits = array_replace($limits, ['strategy' => 'scout', 'destination' => $destination, 'max_fuel' => 0,
                'max_spend' => 0, 'max_loss' => 0, 'one_way' => true, 'observe_kind' => $observe, 'mesh_episode' => $episode->id]);
            if ($saturation) {
                $limits['mesh_work'] = $observe === 'shipyard' ? 'yard' : 'market';
                $limits['mesh_allowance'] = RequestBudget::currentMeshAllowance();
            }
            $route = app(OperationPlanner::class)->project($agent, $ship, $limits, episode: $episode)['route'];
            app(CampaignMesh::class)->scoutMinutes($episode->grant(), $episode, $agent, $ship, array_diff_key($limits, ['minutes' => true]) + ['route' => $route]);
            $run = $this->preview($agent, $ship['symbol'], $limits, $episode);
            if ($saturation) {
                try {
                    app(RequestBudget::class)->bindMesh($run->plan['mesh_allowance'], $run->id, (int) now()->diffInSeconds($run->expires_at, false), $deadline);
                } catch (\Throwable $exception) {
                    try {
                        app(CampaignMesh::class)->invalidatePreview($agent, $run, $deadline);
                    } catch (\Throwable) {
                    }
                    throw $exception;
                }
            } else {
                $run->update(['campaign_episode_id' => $episode->id]);
            }

            return $run;
        };

        return $saturation && ! RequestBudget::currentMeshAllowance()
            ? app(MeshGovernor::class)->preview($agent, $episode, 'scout', $work, deadline: $deadline) : $work();
    }

    public function previewRecovery(Agent $agent, string $symbol, string $buyer, array $limits, CampaignEpisode $episode): AutomationRun
    {
        $recovery = app(CampaignRecovery::class);
        $age = $recovery->verifiedBuyerAge($episode, $limits);
        $limits = $this->recoveryLimits($episode, $limits, $age);

        $safety = app(CampaignSafety::class);
        $policy = CampaignPlaybook::saturationPolicy($safety->saturationGrant($episode, $agent)?->revision()->body ?? []);
        $origin = isset($limits['recovery_of']) ? AutomationRun::find($limits['recovery_of']) : null;
        if ($policy && (($limits['plan_version'] ?? null) === 8 || $origin?->plan_version === 8)) {
            return $this->previewSaturation($agent, $symbol, array_replace($limits, ['strategy' => 'sell_aboard', 'plan_version' => 8, 'market' => $buyer]), $episode);
        }

        return $this->executor->locked($agent, function () use ($agent, $symbol, $buyer, $limits, $episode, $recovery, $age) {
            $this->executor->clear($agent, defer: true, ship: $symbol);
            $this->executor->assertConfigurationReviewed($agent, $symbol);
            $fresh = $recovery->compose($agent, $symbol, $buyer, $limits, $age);

            return AutomationRun::create(['agent_id' => $agent->id, 'campaign_episode_id' => $episode->id, 'reset_id' => $agent->reset_id,
                'engine_session_id' => $this->gate->enabled() ? LocalEngine::find(1)?->session_id : null,
                'ship_symbol' => $symbol, 'strategy' => 'sell_aboard', 'plan_version' => 4, 'state' => RunState::Preview,
                'expires_at' => now()->addSeconds(config('gameplay.preview_seconds')), 'plan' => $fresh['plan'], 'fingerprint' => $fresh['fingerprint'],
                'totals' => ['phase' => 'sell', 'cycles' => 0, 'visits' => 0, 'attempts' => 0, 'no_progress' => 0, 'extracted' => [], 'sold' => [],
                    'sale_proceeds' => 0, 'fuel_cost' => 0, 'fuel_units' => 0, 'travel_fuel' => 0, 'credit_delta' => 0]]);
        }, priority: $limits['targeted_recovery'] ? 'settlement' : 'critical');
    }

    private function recoveryLimits(CampaignEpisode $episode, array $limits, ?int $verifiedBuyerAge): array
    {
        $limits['mesh_settlement_only'] = $verifiedBuyerAge === null && isset(CampaignMesh::policy($episode)['saturation']);
        $limits['targeted_recovery'] = $limits['mesh_settlement_only'] || RequestBudget::agentCaps() !== [] || $episode->grant()->isDraining();

        return $limits;
    }

    private function usesRecoveryReserve(AutomationRun $run): bool
    {
        return ! empty($run->plan['targeted_recovery']) || RequestBudget::agentCaps() !== []
            || ($run->campaign_episode_id && CampaignEpisode::find($run->campaign_episode_id)?->grant()->isDraining());
    }

    public static function hasTradeCargo(AutomationRun $run): bool
    {
        if (! in_array($run->strategy, ['trade', 'mining', 'siphon', 'tender', 'sell_aboard'], true)) {
            return false;
        }
        $totals = $run->totals;
        if (isset($totals['attributable_inventory'])) {
            return array_sum($totals['attributable_inventory']) > 0;
        }

        return array_sum($totals['unsold'] ?? []) > array_sum($run->strategy === 'sell_aboard' ? [] : ($run->plan['initial_inventory'] ?? []));
    }

    public function advance(int $id): void
    {
        $run = AutomationRun::find($id);
        if (! $run || ! $run->active_agent_id) {
            return;
        }
        $agent = Agent::find($run->agent_id);
        FleetMetrics::withWorkload($run->strategy, fn () => $run->plan_version === 8
            ? $this->advanceSaturation($agent, $run)
            : $this->advanceLegacy($agent, $run));
    }

    private function advanceLegacy(Agent $agent, AutomationRun $run): void
    {
        // Outcome mapping (recorded once per advance that took the agent lock, with the requests it used):
        // wrote = a write was enqueued; waited = planner wait, trip/depart bookkeeping or an action still settling;
        // held = freshness/price/follower/exposure hold; deferred = RequestDeferred or another retryAfter re-queue;
        // blocked = uncertain action, planner block or fatal error; idle = nothing due (inactive, stopping, blocked, not yet due).
        $outcome = null;
        $requestsBefore = FleetMetrics::requestTotal();
        $send = null;
        $split = app(CampaignSafety::class)->perShipLocksEnabled($agent);
        $this->executor->locked($agent, function () use ($run, $agent, $split, &$outcome, &$send) {
            $outcome = 'idle';
            $run->refresh();
            if (! $run->active_agent_id) {
                return;
            }
            if ($run->strategy === 'gate_recon') {
                app(GateRecon::class)->reconcileSettlement(ReconMission::findOrFail($run->plan['mission_id']));
                $run->refresh();
                if (! $run->active_agent_id) {
                    return;
                }
            }
            $run->update(['heartbeat_at' => now()]);
            if (! $split && ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where(fn ($q) => $q->where('state', 'uncertain')->orWhere(fn ($q) => ShipCommandExecutor::reviewFor($q, $run->ship_symbol)))->exists()) {
                if ($run->campaign_episode_id && ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('state', 'uncertain')->exists()) {
                    $episode = CampaignEpisode::findOrFail($run->campaign_episode_id);
                    app(CampaignControl::class)->pause($episode->grant(), 'uncertain_action', $episode);
                }
                $this->executor->block($run, 'An action is uncertain or requires price review. Reconcile before takeover.');
                $outcome = 'blocked';

                return;
            }
            if (ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                ->when($split, fn ($q) => $q->where('automation_run_id', $run->id))->whereIn('state', ['pending', 'sending'])->exists()) {
                $run->update(['next_action_at' => now()->addSeconds(5)]);
                $outcome = 'waited';

                return;
            }
            if ($run->stop_requested_at || $run->expires_at->isPast() || ! config('gameplay.enabled')) {
                $this->finishStop($run, $run->stop_requested_at ? 'Stop acknowledged. Manual control is available after transit.' : 'Duration reached or automation disabled.');

                return;
            }
            if ($run->state === RunState::Blocked || ($run->next_action_at && $run->next_action_at->isFuture())) {
                return;
            }
            $budget = app(RequestBudget::class);
            $paced = RequestBudget::agentCaps() !== [];
            $loaded = $paced && $run->strategy === 'trade' && self::hasTradeCargo($run);
            $buyPhase = ($run->totals['phase'] ?? null) === 'buy';
            $finishLoading = $loaded && $buyPhase && $budget->ordinaryCapReached($agent->name);
            $recovery = ! empty($run->plan['sell_only']) && (! empty($run->plan['mesh_settlement_only']) || $paced || $budget->isDraining($agent->name));
            $priority = $recovery || ($loaded && (! $buyPhase || $finishLoading)) ? 'settlement' : ($paced ? 'run' : 'critical');
            $work = function () use ($run, $agent, $finishLoading, $split, &$outcome, &$ship, &$send) {
                $this->gate->check($run->engine_session_id);
                $lastActionId = (int) (ShipAction::where('agent_id', $agent->id)->whereIn('state', ['confirmed', 'uncertain'])->max('id') ?? 0);
                $observedAt = now();
                $this->sync->guardReset($agent);
                $ship = $this->sync->refreshShip($agent, $run->ship_symbol, fresh: isset($run->plan['mesh_work']))->snapshot;
                $safety = app(CampaignSafety::class);
                $agent->refresh();
                $unresolved = $split && (app(StateChain::class)->hasUnresolvedCreditAction($agent) || $safety->uncertainCredit($agent)
                    || ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('state', 'sending')
                        ->whereNotIn('command', [...CampaignSafety::SHIP_COMMANDS, 'transfer'])->exists());
                if (! $unresolved) {
                    $agent->update(['credits' => $this->api->agent($agent, fresh: isset($run->plan['mesh_work']))['credits'], 'synced_at' => now()]);
                }
                $safety->check($run, $agent);
                if ($unresolved) {
                    throw new GameException('A credit action is unresolved. Retry after reconciliation.', retryAfter: 5);
                }
                $credits = $agent->credits;
                $otherDelta = app(CreditAccounting::class)->outsideRunDelta($agent, $run);
                if ($credits !== $run->plan['initial_credits'] + $run->totals['credit_delta'] + $otherDelta) {
                    throw new GameException('Agent credits changed outside this run. Create a new preview after reconciliation.');
                }
                $purchaseDelivered = in_array($run->strategy, CampaignProbeBootstrap::STRATEGIES, true) && ! empty($run->totals['purchased_ships']);
                if (! $purchaseDelivered && ($credits < $run->plan['reserve'] || $run->totals['credit_delta'] < -$run->plan['max_loss'])) {
                    throw new GameException('Credit reserve or loss limit reached.');
                }
                $totals = $run->totals;
                $totals['other_credit_delta'] = $otherDelta;
                $totals['agent_balance_delta'] = $credits - $run->plan['initial_credits'];
                $run->update(['totals' => $totals]);
                if ($finishLoading) {
                    $trade = app(CampaignTrade::class);
                    $bought = $run->totals['purchased'][$run->plan['symbol']] ?? 0;
                    if ($bought > 0 && $bought < $run->plan['units']) {
                        $trade->shortenLoad($run, $bought, $run->totals['purchase_cost'], 'request_budget');
                    }
                    $run->update(['totals' => array_replace($run->totals, ['phase' => 'sell'])]);
                }
                $prefetchedMarket = null;
                $prefetchedMarketSymbol = null;
                $market = [];
                if ($run->strategy === 'gate_recon') {
                    $step = app(GateRecon::class)->next($agent, $ship, $run);
                } elseif (in_array($run->strategy, CampaignProbeBootstrap::STRATEGIES, true)) {
                    if (($run->plan['route']['departure_refuel_ceiling'] ?? 0) > 0 && ($run->totals['travel_fuel'] ?? 0) === 0
                        && $run->plan['site'] !== $run->plan['shipyard'] && $ship['fuel']['current'] < $ship['fuel']['capacity']) {
                        $this->refreshOrigin($agent, $run->plan['site'], $run->ship_symbol);
                    }
                    $buyer = $run->strategy === 'buy_hauler' ? app(CampaignHaulerPurchase::class) : app(CampaignProbeBootstrap::class);
                    $step = $buyer->next($agent, $ship, $run);
                } elseif ($run->strategy === 'contracts') {
                    $this->assertContractState($agent, $run, $ship);
                    $step = $this->contracts->next($ship, $run->plan, $run->totals, now()->toImmutable());
                } elseif (! empty($run->plan['sell_only']) && ! empty($run->plan['restore_cruise']) && $ship['nav']['flightMode'] === 'BURN') {
                    $step = app(CampaignRecovery::class)->next($agent, $ship, $run);
                } elseif (! empty($run->plan['sell_only'])) {
                    $this->refreshBuyer($agent, $run->plan['market'], $run->ship_symbol);
                    if (($run->plan['route']['departure_refuel_ceiling'] ?? 0) > 0 && ($run->totals['travel_fuel'] ?? 0) === 0 && $run->plan['site'] !== $run->plan['market']
                        && $ship['fuel']['current'] < $ship['fuel']['capacity']) {
                        $this->refreshOrigin($agent, $run->plan['site'], $run->ship_symbol);
                    }
                    $step = app(CampaignRecovery::class)->next($agent, $ship, $run);
                } else {
                    $yardScout = ($run->plan['observe_kind'] ?? 'market') === 'shipyard';
                    $mining = $run->strategy === 'mining';
                    $meshMarketScout = ! $yardScout && $run->strategy === 'scout' && isset($run->plan['mesh_work']);
                    if (! $yardScout && ! $mining && ! $meshMarketScout) {
                        $this->refreshBuyer($agent, $run->plan['market'], $run->ship_symbol);
                    }
                    if (! $mining
                        && (! isset($run->plan['mesh_work']) || ($ship['nav']['waypointSymbol'] !== $run->plan['market']
                            && ! $this->recentOrigin($agent, $run->plan['site'])))) {
                        $siteMarket = $this->refreshOrigin($agent, $run->plan['site'], $run->ship_symbol);
                        if ($siteMarket !== null && $ship['nav']['waypointSymbol'] === $run->plan['site']) {
                            $prefetchedMarket = $siteMarket;
                            $prefetchedMarketSymbol = $run->plan['site'];
                        }
                    }
                    if (! $yardScout && ! isset($run->plan['mesh_episode']) && ($run->plan['strategy'] ?? 'mining') === 'scout' && $run->totals['phase'] === 'observe' && ($run->totals['travel_fuel'] ?? 0) === 0 && $ship['nav']['status'] !== 'IN_TRANSIT') {
                        $this->sync->observe($agent, 'market', $run->plan['market'], $this->api->market($agent, $run->plan['market']));
                    }
                    if ($ship['nav']['waypointSymbol'] === $run->plan['market'] && $ship['nav']['status'] !== 'IN_TRANSIT' && $yardScout) {
                        $this->sync->observe($agent, 'shipyard', $run->plan['market'], $this->api->shipyard($agent, $run->plan['market']));
                    } elseif ($ship['nav']['waypointSymbol'] === $run->plan['market'] && $ship['nav']['status'] !== 'IN_TRANSIT') {
                        $market = $this->api->market($agent, $run->plan['market'], fresh: $mining);
                        $this->sync->observe($agent, 'market', $run->plan['market'], $market);
                        $prefetchedMarket = $market;
                        $prefetchedMarketSymbol = $run->plan['market'];
                    }
                    if ($run->strategy === 'trade') {
                        $expected = $run->totals['unsold'] ?? $run->plan['initial_inventory'];
                        $actual = MiningPlanner::inventory($ship);
                        ksort($expected);
                        ksort($actual);
                        if ($expected !== $actual) {
                            throw new GameException('Cargo changed outside the approved operation. Retain cargo and reconcile before continuing.');
                        }
                    }
                    app(CampaignTrade::class)->finishLoading($agent, $run);
                    $step = $mining
                        ? $this->planner->next($ship, $run->plan, $run->totals, $market, now()->toImmutable())
                        : app(OperationPlanner::class)->next($agent, $ship, $run->plan, $run->totals, now()->toImmutable());
                    if ($mining && MiningPlanner::startsLoad($ship, $run->plan, $run->totals, $step->command)) {
                        $buyer = $run->plan['market'];
                        if ($prefetchedMarketSymbol !== $buyer) {
                            $prefetchedMarket = $this->api->market($agent, $buyer, fresh: true);
                            $this->sync->observe($agent, 'market', $buyer, $prefetchedMarket);
                            $prefetchedMarketSymbol = $buyer;
                        }
                        $this->planner->assertDepartureBids($prefetchedMarket, $buyer, $this->planner->requiredDepartureYields($agent, $run->plan));
                    }
                }
                $enqueuedAction = null;
                match ($step->kind) {
                    'blocked' => $this->executor->block($run, $step->reason),
                    'wait' => $run->update(['state' => RunState::Waiting, 'next_action_at' => $step->until]),
                    'trip' => match ($run->strategy) {
                        'gate_recon' => app(GateRecon::class)->complete($run),
                        'buy_probe', 'buy_hauler' => app(CampaignProbeBootstrap::class)->complete($run, $ship),
                        'contracts' => $this->completeContract($run, $ship),
                        default => $this->completeTrip($run, $ship, $market),
                    },
                    'depart' => $run->update(['totals' => array_merge($run->totals, ['phase' => 'sell']), 'next_action_at' => now(), 'state' => RunState::Running]),
                    'act' => $enqueuedAction = $this->enqueue($run, $step),
                };
                if ($enqueuedAction !== null && config('gameplay.inline_execute', true)) {
                    // Phase 1: execute immediately after enqueue's DB transaction commits, outside any transaction,
                    // reusing verified pre-write reads (<=10s old, zero writes since read). Exceptions stay inside executeLocked.
                    $prefetched = [
                        'observed_at' => $observedAt,
                        'last_confirmed_action_id' => $lastActionId,
                        'reset_verified' => true,
                        'agent_credits' => $credits,
                        'ship' => $ship,
                        'market' => $prefetchedMarket,
                        'market_symbol' => $prefetchedMarketSymbol,
                    ];
                    if ($split) {
                        $send = [$enqueuedAction, $prefetched];
                    } else {
                        $this->executor->executeLocked($agent->fresh(), $enqueuedAction, $prefetched);
                        $run->refresh();
                        $ship = Ship::where('agent_id', $agent->id)->where('symbol', $run->ship_symbol)->first()?->snapshot ?? $ship;
                    }
                }
                $outcome = match ($step->kind) {
                    'blocked' => self::isHold($step->reason) ? 'held' : 'blocked',
                    'trip' => $run->state === RunState::Blocked ? (self::isHold($run->blocked_reason) ? 'held' : 'blocked') : 'waited',
                    'act' => 'wrote',
                    default => 'waited',
                };
            };
            try {
                FleetMetrics::forAgent($agent->name, fn () => app(MeshGovernor::class)->run($agent, $run,
                    fn () => app(SpaceTradersClient::class)->scope($work, priority: $priority)));
            } catch (GameException $e) {
                FleetMetrics::forAgent($agent->name, fn () => FleetMetrics::as($run->ship_symbol, fn () => FleetMetrics::observe($e)));
                if ($e->apiCode === 4113 || ($agent->fresh()?->connection_state === 'invalid_credential')) {
                    try {
                        $this->sync->guardReset($agent->fresh(), fresh: true);
                    } catch (GameException) {
                    }
                }
                if ($e->retryAfter > 0 && $e->apiCode === null && $agent->fresh()?->connection_state === 'connected' && (! $e instanceof ReadStateUnavailable || $run->campaign_episode_id)) {
                    $run->update(['state' => RunState::Waiting, 'next_action_at' => now()->addSeconds(min(180, $e->retryAfter)), 'blocked_reason' => $e->getMessage()]);
                    $outcome = self::isHold($e->getMessage()) ? 'held' : 'deferred';
                } else {
                    $this->executor->block($run, $e->getMessage());
                    $outcome = self::isHold($e->getMessage()) ? 'held' : 'blocked';
                }
            }
            app(CycleAccounting::class)->record($run->fresh() ?? $run, $ship ?? null);
        });
        if ($send) {
            $this->executor->dispatchLocked($agent, ...$send);
        }
        if ($outcome !== null) {
            FleetMetrics::forAgent($agent->name, fn () => FleetMetrics::advance($run->ship_symbol, $outcome, FleetMetrics::requestTotal() - $requestsBefore));
        }
    }

    private function advanceSaturation(Agent $agent, AutomationRun $run): void
    {
        FleetMetrics::forAgent($agent->name, function () use ($agent, $run) {
            $before = FleetMetrics::requestTotal();
            $outcome = 'idle';
            try {
                [$outcome, $action] = $this->planSaturationStep($agent, $run);
                if ($action && config('gameplay.inline_execute', true)) {
                    $this->executor->dispatchLocked($agent, $action);
                }
            } catch (GameException $e) {
                FleetMetrics::as($run->ship_symbol, fn () => FleetMetrics::observe($e));
                if (str_starts_with($e->getMessage(), 'Another operation owns this command boundary')) {
                    throw $e;
                }
                $outcome = $e->retryAfter > 0 ? 'deferred' : 'blocked';
                if ($run->fresh()?->active_agent_id === $agent->id) {
                    if ($e->retryAfter > 0) {
                        $run->update(['state' => RunState::Waiting, 'next_action_at' => now()->addSeconds(min(180, $e->retryAfter)), 'blocked_reason' => $e->getMessage()]);
                    } else {
                        $this->executor->block($run, $e->getMessage());
                    }
                }
            }
            FleetMetrics::advance($run->ship_symbol, $outcome, FleetMetrics::requestTotal() - $before);
        }, $agent->reset_id);
    }

    private function planSaturationStep(Agent $agent, AutomationRun $run): array
    {
        $partner = $this->tenderFor($run);
        $ships = $partner ? [$run->ship_symbol, $partner->ship_symbol] : [$run->ship_symbol];

        return app(HostLock::class)->lockedAgentAndShips($agent->id, $ships, writer: true, work: function () use ($agent, $run, $partner) {
            $agent->refresh();
            $run->refresh();
            if ($run->active_agent_id !== $agent->id) {
                return ['idle', null];
            }
            $safety = app(CampaignSafety::class);
            $run->update(['heartbeat_at' => now()]);
            $owned = ShipAction::where('agent_id', $agent->id)->where('reset_id', $run->reset_id)->where('automation_run_id', $run->id);
            if ((clone $owned)->whereIn('state', ['sending', 'uncertain'])->exists()) {
                return ['held', null];
            }
            $cleanup = $this->positionCleanupDeadline($run, $agent);
            $stopping = $run->stop_requested_at || ! config('gameplay.enabled')
                || ((! $run->expires_at->isFuture() || ! $safety->settlementDeadline($run)?->isFuture()) && ! $cleanup);
            if ($stopping) {
                if (! $safety->hasShipUncertaintyHold($agent, $run->ship_symbol)) {
                    $this->retireSaturationIntents($agent, $run);
                    $episode = CampaignEpisode::find($run->campaign_episode_id);
                    if (self::hasTradeCargo($run) && ! $run->stop_requested_at && (! $episode || $episode->closed_at
                        || $episode->reset_id !== $agent->reset_id || $episode->next_reset_at->isPast() || ! $episode->grant()->available())) {
                        $this->executor->block($run, 'Settlement authority ended. Cargo is held for review.');

                        return ['held', null];
                    }
                    $this->finishStop($run, $run->stop_requested_at ? 'Stop acknowledged. Retain attributable cargo for authorized recovery.' : 'Settlement authority ended. Retain attributable cargo for authorized recovery.');
                }

                return ['held', null];
            }
            $phase = $run->totals['phase'] ?? null;
            if (! in_array($phase, ['deliver', 'sell'], true) && $this->executor->closeAcquisitionUnsent($agent, $run)) {
                $run->refresh();
                if (! $run->active_agent_id) {
                    $this->releaseSaturationHolds($agent, $run);
                    app(MeshGovernor::class)->release($run->plan['mesh_allowance'] ?? null);

                    return ['waited', null];
                }
            }
            if ((clone $owned)->where('state', 'pending')->exists()) {
                return ['waited', null];
            }
            if ($safety->uncertainCredit($agent) || $safety->creditReview($agent) || $safety->unexplainedBalance($agent)) {
                $safety->check($run, $agent);
            }
            $this->executor->promoteCreditIntent($agent);
            if ((clone $owned)->whereIn('state', ['preview', 'pending'])->exists()) {
                return ['waited', null];
            }
            if ($run->state === RunState::Blocked || $run->next_action_at?->isFuture()) {
                return ['idle', null];
            }
            if ($safety->hasShipUncertaintyHold($agent, $run->ship_symbol)) {
                return ['held', null];
            }
            $this->gate->check($run->engine_session_id);
            $loaded = self::hasTradeCargo($run);
            $delivery = in_array($run->totals['phase'] ?? null, ['sell', 'deliver'], true);
            $priority = ($loaded && $delivery) || $cleanup ? 'settlement' : 'run';

            $work = fn () => app(SpaceTradersClient::class)->scope(function () use ($agent, $run, $safety, $partner) {
                $this->sync->guardReset($agent);
                $safety->check($run, $agent);
                $ship = app(CommandRules::class)->shipForRun($agent, $run);
                if ($ship['nav']['status'] === 'IN_TRANSIT') {
                    $wake = CarbonImmutable::parse($ship['nav']['route']['arrival'])->addSecond()->max(now()->toImmutable()->addSecond());
                    $run->update(['state' => RunState::Waiting, 'next_action_at' => $wake]);

                    return ['waited', null];
                }
                if ($partner) {
                    if ($transfer = $this->stageTransfer($agent, $run, $partner, $ship)) {
                        return ['wrote', $transfer];
                    }
                    if (($ship['nav']['waypointSymbol'] ?? null) === ($run->plan['site'] ?? null) && $ship['nav']['status'] === 'IN_ORBIT'
                        && $ship['cargo']['units'] > 0 && empty($run->totals['acquisition_closed_at'])
                        && ($ready = $safety->planTime($ship['cooldown']['readyAt']))?->isFuture()
                        && ($rendezvous = $this->tenderCutoff($partner)) && $ready->lt($rendezvous)) {
                        $run->update(['state' => RunState::Waiting, 'next_action_at' => $ready]);

                        return ['waited', null];
                    }
                }
                $kind = $run->strategy === 'buy_fleet' ? 'shipyard' : 'market';
                $priceRow = GameObservation::where('agent_id', $agent->id)->where('reset_id', $run->reset_id)
                    ->where('symbol', $ship['nav']['waypointSymbol'])->where('kind', $kind);
                $previousQuote = (clone $priceRow)->first();
                $index = (int) ($run->totals['leg_index'] ?? 0);
                if ($run->strategy === 'trade' && empty($run->totals['leg_purchased'][$index])
                    && ! $this->reserveTradeLot($agent, $run, $run->plan, $index)) {
                    throw new GameException('Insufficient unreserved cash for the next approved trade lot.', retryAfter: 5);
                }
                $step = $this->nextSaturationStep($agent, $run, $ship);
                $freshQuote = null;
                if (in_array($step->command, ['buy', 'sell', 'refuel', 'buy_ship'], true)) {
                    $read = $priceRow->first();
                    if ($read && ($read->data['observation_version'] ?? null) !== ($previousQuote?->data['observation_version'] ?? null)) {
                        $freshQuote = $read;
                    }
                }
                $action = match ($step->kind) {
                    'blocked' => $this->executor->block($run, $step->reason),
                    'wait' => $run->update(['state' => RunState::Waiting, 'next_action_at' => $step->until]),
                    'trip' => $this->completeSaturation($agent, $run, $ship),
                    'depart' => $run->update(['totals' => array_replace($run->totals, ['phase' => 'deliver']), 'state' => RunState::Running, 'next_action_at' => now()]),
                    'act' => $this->enqueue($run, $step, $freshQuote),
                };
                app(CycleAccounting::class)->record($run->fresh(), $ship);

                return [$step->kind === 'act' ? 'wrote' : ($step->kind === 'blocked' ? 'blocked' : 'waited'), $action instanceof ShipAction ? $action : null];
            }, priority: $priority);

            return $cleanup ? $work() : app(MeshGovernor::class)->run($agent, $run, $work);
        }, seconds: 0);
    }

    private function positionCleanupDeadline(AutomationRun $run, Agent $agent): ?CarbonImmutable
    {
        if ($run->strategy !== 'position' || ($run->plan['route']['mode'] ?? null) !== 'DRIFT') {
            return null;
        }
        $safety = app(CampaignSafety::class);
        $deadline = $safety->planTime($run->plan['route']['cleanup']['expires_at'] ?? null);
        $ship = Ship::where('agent_id', $agent->id)->where('symbol', $run->ship_symbol)->first()?->snapshot;

        return $deadline?->isFuture() && ($ship['nav']['flightMode'] ?? null) === 'DRIFT' ? $deadline : null;
    }

    private function tenderFor(AutomationRun $run): ?AutomationRun
    {
        if (! in_array($run->strategy, ['mining', 'siphon'], true) || empty($run->plan['stationary']) || empty($run->plan['cluster_id'])) {
            return null;
        }

        return AutomationRun::where('active_agent_id', $run->agent_id)->where('reset_id', $run->reset_id)
            ->where('campaign_episode_id', $run->campaign_episode_id)->where('plan_version', 8)->where('strategy', 'tender')
            ->where('plan->cluster_id', $run->plan['cluster_id'])->where('plan->canary_pair_id', $run->plan['canary_pair_id'] ?? null)
            ->where('plan->site', $run->plan['site'])->whereNull('stop_requested_at')->whereIn('state', ['running', 'waiting'])->first();
    }

    private function tenderCutoff(AutomationRun $receiver): ?CarbonImmutable
    {
        $safety = app(CampaignSafety::class);
        $cutoff = $safety->planTime($receiver->plan['rendezvous_cutoff'] ?? null);
        $opened = $safety->planTime($receiver->totals['rendezvous_ready_at'] ?? null);
        if (! $opened) {
            return $cutoff;
        }
        $dwell = $opened->addSeconds($receiver->plan['tender_wait_seconds'] ?? 120);

        return $cutoff ? $cutoff->min($dwell) : $dwell;
    }

    private function stageTransfer(Agent $agent, AutomationRun $run, AutomationRun $receiver, array $source): ?ShipAction
    {
        $receiver->refresh();
        $plan = $run->plan;
        $receiverPlan = $receiver->plan;
        $site = $plan['site'];
        $episode = CampaignEpisode::findOrFail($run->campaign_episode_id);
        $safety = app(CampaignSafety::class);
        $policy = $safety->saturationPolicy($run);
        if (empty($policy['extraction']['stationary']) || $safety->windDown($episode) || ($source['nav']['waypointSymbol'] ?? null) !== $site
            || $source['nav']['status'] !== 'IN_ORBIT' || now()->lt($source['cooldown']['readyAt']) || $source['cargo']['units'] < 1
            || ! $safety->acquisitionDeadline($run)?->isFuture()
            || $receiver->active_agent_id !== $agent->id || ($plan['buyer'] ?? null) !== ($receiverPlan['buyer'] ?? null)
            || ! empty($run->totals['acquisition_closed_at'])
            || in_array($receiver->totals['phase'] ?? null, ['sell', 'deliver'], true)) {
            return null;
        }
        $cluster = app(MiningCluster::class);
        $pair = $plan['canary_pair_id'] ?? null;
        if (! $pair) {
            $calibration = app(ExtractionCalibrator::class)->summary($agent, 'transfer', $site, FuelPlanner::configuration($source));
            if (! $cluster->canAdmitOrdinary($calibration['transfer_cooldown_measured'], $calibration['transfer_dwell_seconds'] !== null, true)) {
                return null;
            }
        }
        if ($cluster->hasBlockingLease($agent, $run->ship_symbol) || $cluster->hasBlockingLease($agent, $receiver->ship_symbol)
            || ($pair && DB::table('transfer_leases')->where('agent_id', $agent->id)->where('reset_id', $run->reset_id)->where('canary_pair_id', $pair)->where('state', 'confirmed')->exists())) {
            return null;
        }
        $saved = Ship::where('agent_id', $agent->id)->where('symbol', $receiver->ship_symbol)->first()?->snapshot;
        if (($saved['nav']['waypointSymbol'] ?? null) !== $site || ($saved['nav']['status'] ?? null) !== 'IN_ORBIT' || now()->lt($saved['cooldown']['readyAt'])) {
            return null;
        }
        $cutoff = $this->tenderCutoff($receiver);
        if (! $cutoff?->isFuture()) {
            return null;
        }
        $target = app(CommandRules::class)->shipForRun($agent, $receiver);
        if ($target['nav']['waypointSymbol'] !== $site || $target['nav']['status'] !== 'IN_ORBIT' || now()->lt($target['cooldown']['readyAt'])) {
            return null;
        }
        $ready = $receiver->totals['rendezvous_ready_at'] ?? now()->toIso8601String();
        $cutoff = $cutoff->min(CarbonImmutable::parse($ready)->addSeconds($receiverPlan['tender_wait_seconds'] ?? 120));
        $free = min($target['cargo']['capacity'], $receiverPlan['unit_cap'] ?? PHP_INT_MAX) - $target['cargo']['units'];
        $goods = array_intersect($plan['allowed_goods'] ?? [], $receiverPlan['allowed_goods'] ?? []);
        $totals = $run->totals;
        foreach ($goods as $good) {
            $attributable = $totals['attributable_inventory'][$good] ?? (($totals['extracted'][$good] ?? 0)
                - ($totals['transferred_out'][$good] ?? 0) - ($totals['sold'][$good] ?? 0) - ($totals['jettisoned'][$good] ?? 0));
            $units = min($free, $attributable, MiningPlanner::inventory($source)[$good] ?? 0, $plan['unit_cap'] ?? PHP_INT_MAX);
            if ($units > 0) {
                break;
            }
        }
        if (empty($units) || $units < 1 || $safety->hasShipUncertaintyHold($agent, $receiver->ship_symbol)) {
            return null;
        }
        $this->executor->clear($agent, defer: true, ship: $run->ship_symbol, command: 'transfer', targetShip: $receiver->ship_symbol);
        $exposure = max($plan['combined_exposure'] ?? 0, $receiverPlan['combined_exposure'] ?? 0, $plan['max_spend'] + $receiverPlan['max_spend']);
        if ($pair && $exposure > 5000) {
            throw new GameException('Combined transfer canary exposure exceeds the approved cap.');
        }
        $basis = (app(CampaignTrade::class)->averageCosts($agent, $run->ship_symbol)[$good] ?? 0) * $units;

        return DB::transaction(function () use ($agent, $run, $receiver, $site, $cutoff, $plan, $pair, $source, $target, $units, $good, $basis, $exposure, $cluster, $safety, $ready) {
            $this->gate->check($run->engine_session_id);
            $action = ShipAction::create([
                'agent_id' => $agent->id, 'reset_id' => $run->reset_id, 'automation_run_id' => $run->id, 'ship_symbol' => $run->ship_symbol,
                'engine_session_id' => $run->engine_session_id, 'key' => (string) Str::uuid(), 'origin' => 'automation', 'target_type' => 'ship',
                'target_id' => $receiver->ship_symbol, 'command' => 'transfer', 'state' => ActionState::Pending,
                'arguments' => ['targetShip' => $receiver->ship_symbol, 'symbol' => $good, 'units' => $units, 'rendezvous_ready_at' => $ready],
                'expires_at' => $cutoff->min(now()->toImmutable()->addMinute()), 'last_dispatched_at' => now(),
            ]);
            $lease = $cluster->issueLease($agent, [
                'cluster_id' => $plan['cluster_id'], 'canary_pair_id' => $pair, 'source_ship' => $run->ship_symbol,
                'receiver_ship' => $receiver->ship_symbol, 'source_run_id' => $run->id, 'receiver_run_id' => $receiver->id,
                'waypoint' => $site, 'good' => $good, 'units' => $units, 'cost_basis' => (int) round($basis), 'ship_action_id' => $action->id,
                'buyer' => $plan['buyer'], 'combined_exposure' => $exposure, 'rendezvous_cutoff' => $cutoff,
                'source_snapshot' => $source, 'receiver_snapshot' => $target,
            ]);
            if (! $lease) {
                $action->delete();

                return null;
            }
            $action->refresh();
            $action->update(['arguments' => $action->arguments + ['transfer_lease_id' => (int) $lease['id']]]);
            $safety->check($run, $agent, 'transfer', $action->arguments, boundaryOnly: true);
            $receiver->update(['totals' => array_replace($receiver->totals, ['rendezvous_ready_at' => $ready])]);
            $run->update(['next_action_at' => now()->addSeconds(5)]);
            ExecuteShipCommand::dispatch($action->id)->afterCommit();

            return $action;
        });
    }

    private function nextSaturationStep(Agent $agent, AutomationRun $run, array $ship): AutomationStep
    {
        if ($run->strategy === 'buy_fleet') {
            return app(CampaignHaulerPurchase::class)->next($agent, $ship, $run);
        }
        if ($run->strategy === 'sell_aboard') {
            return app(CampaignRecovery::class)->next($agent, $ship, $run);
        }
        if ($run->strategy === 'scout') {
            $destination = $run->plan['destination'] ?? $run->plan['market'];
            if ($ship['nav']['waypointSymbol'] === $destination) {
                $kind = ($run->plan['observe_kind'] ?? 'market') === 'shipyard' ? 'shipyard' : 'market';
                $this->sync->observe($agent, $kind, $destination, $this->api->$kind($agent, $destination, fresh: true), observer: $run->ship_symbol);

                return AutomationStep::trip();
            }
            $plan = $run->plan;
            unset($plan['plan_version']);

            return app(OperationPlanner::class)->next($agent, $ship, $plan, $run->totals, now()->toImmutable());
        }
        $planner = app(OperationPlanner::class);
        $plan = $run->plan;
        $totals = $run->totals;
        if ($run->strategy === 'tender' && ! empty($plan['cluster_id'])) {
            unset($plan['rendezvous_started_at']);
            $totals['rendezvous_started_at'] = $totals['rendezvous_ready_at'] ?? null;
        }
        try {
            return $planner->next($agent, $ship, $plan, $totals, now()->toImmutable());
        } catch (StaleQuote $e) {
            $here = $ship['nav']['waypointSymbol'];
            $row = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'market')->where('symbol', $here)->first();
            if (! $row || ! str_contains($e->getMessage(), 'at '.$here.' for ')) {
                throw $e;
            }
            $quote = $this->api->market($agent, $here, fresh: true);
            $this->sync->observe($agent, 'market', $here, $quote, observer: $run->ship_symbol);

            return $planner->next($agent, $ship, $plan, $totals, now()->toImmutable());
        }
    }

    private function retireSaturationIntents(Agent $agent, AutomationRun $run): void
    {
        DB::transaction(function () use ($agent, $run) {
            $pending = ShipAction::where('agent_id', $agent->id)->where('reset_id', $run->reset_id)->where('automation_run_id', $run->id)
                ->whereIn('state', ['preview', 'pending'])->get();
            foreach ($pending as $action) {
                if (isset($action->outcome['response_received_at']) || isset($action->outcome['body_sha256'])) {
                    throw new GameException('A retained write receipt needs reconciliation before stop.');
                }
                app(StateChain::class)->releaseReservation($action->id);
                $action->update(['state' => ActionState::Rejected, 'message' => 'Run stopped before transport.', 'settled_at' => now(),
                    'outcome' => array_replace($action->outcome ?? [], ['confirmed_not_sent' => true])]);
            }
        });
    }

    private function completeSaturation(Agent $agent, AutomationRun $run, array $ship): void
    {
        app(CommandRules::class)->refreshCredits($agent, $run, true, reconcile: true);
        if (app(CampaignSafety::class)->unexplainedBalance($agent)) {
            throw new GameException('Unexplained balance change after settlement.');
        }
        DB::transaction(function () use ($agent, $run, $ship) {
            $totals = array_replace($run->totals, ['cycles' => 1, 'ending_fuel' => $ship['fuel']['current'],
                'unsold' => MiningPlanner::inventory($ship), 'finished_at' => now()->toIso8601String(),
                'elapsed_seconds' => max(1, (int) $run->started_at->diffInSeconds(now()))]);
            $run->update(['totals' => $totals, 'active_agent_id' => null, 'state' => RunState::Completed, 'next_action_at' => null]);
            $this->releaseSaturationHolds($agent, $run);
        });
        app(MeshGovernor::class)->release($run->plan['mesh_allowance'] ?? null);
    }

    private function releaseSaturationHolds(Agent $agent, AutomationRun $run): void
    {
        app(CorridorBook::class)->releaseOwner($agent, 'run', (string) $run->id, keepUnresolved: true);
        app(StateChain::class)->releaseRunHolds($agent, $run->id);
    }

    public function settleStoppedRunHolds(Agent $agent, AutomationRun $run, bool $hasCargo): void
    {
        if ($hasCargo) {
            app(StateChain::class)->retainRunDeliveryFuel($agent, $run);
        }
        $this->releaseSaturationHolds($agent, $run);
    }

    /** Freshness or price holds, follower waits and exposure shortening: planned pauses, not failures. */
    private static function isHold(?string $message): bool
    {
        return $message !== null && (bool) preg_match('/freshness hold|unknown or stale|mining departure held|arm exposure|follower waiting/i', $message);
    }

    private function prepare(Agent $agent, array $ship, array $limits, ?CampaignEpisode $episode = null): array
    {
        if (($limits['strategy'] ?? 'mining') !== 'mining') {
            if (! isset($limits['mesh_episode'])) {
                $this->refreshOrigin($agent, $ship['nav']['waypointSymbol']);
                if (($limits['observe_kind'] ?? 'market') !== 'shipyard') {
                    $this->refreshBuyer($agent, $limits['strategy'] === 'scout' ? $limits['destination'] : $limits['market']);
                    if ($limits['strategy'] === 'scout') {
                        $this->sync->observe($agent, 'market', $limits['destination'], $this->api->market($agent, $limits['destination']));
                    }
                }
            }

            return app(OperationPlanner::class)->preview($agent, $ship, $limits, $episode);
        }
        $limits['max_extractions'] ??= 1;
        $limits['extraction_headroom'] ??= $ship['cargo']['capacity'];
        $limits['site_seconds'] ??= 300;
        if ($limits['extraction_headroom'] < 1 || $limits['extraction_headroom'] > $ship['cargo']['capacity']) {
            throw new GameException('Extraction headroom must be within cargo capacity.');
        }
        $point = $this->api->waypoint($agent, $limits['site']);
        $this->sync->observe($agent, 'waypoint', $limits['site'], $point, 'read');
        $this->refreshBuyer($agent, $limits['market']);
        $route = $this->planner->route($agent, $ship, $limits['site'], $limits['market'], $this->planner->surveyYields($agent, $limits));
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

    private function recentOrigin(Agent $agent, string $waypoint): bool
    {
        $quote = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', 'market')->where('symbol', $waypoint)->where('observed_at', '>', now()->subSeconds(30))->first();

        return ! empty($quote?->data['tradeGoods']) && empty($quote->data['invalidated_by_action']);
    }

    private function refreshOrigin(Agent $agent, string $waypoint, ?string $ship = null): ?array
    {
        $points = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('symbol', $waypoint);
        $market = (clone $points)->where('kind', 'market_listing')->exists()
            || in_array('MARKETPLACE', array_column((clone $points)->where('kind', 'waypoint')->first()?->data['traits'] ?? [], 'symbol'), true);

        return $market ? $this->refreshBuyer($agent, $waypoint, $ship) : null;
    }

    private function refreshBuyer(Agent $agent, string $market, ?string $ship = null): ?array
    {
        $observer = Ship::where('agent_id', $agent->id)->get()->contains(fn ($ship) => $ship->snapshot['nav']['waypointSymbol'] === $market && $ship->snapshot['nav']['status'] !== 'IN_TRANSIT');
        if ($observer) {
            // Attributed to the run's ship; without one (previews) the caller's current attribution applies.
            $read = function () use ($agent, $market) {
                $data = $this->api->market($agent, $market);
                $this->sync->observe($agent, 'market', $market, $data);

                return $data;
            };

            return $ship === null ? $read() : FleetMetrics::as($ship, $read);
        }

        return null;
    }

    private function enqueue(AutomationRun $run, AutomationStep $step, ?GameObservation $freshQuote = null): ?ShipAction
    {
        $agent = Agent::findOrFail($run->agent_id);
        $shortened = null;
        $v8 = $run->plan_version === 8;
        if (! $v8 && $step->command === 'buy' && $run->campaign_episode_id) {
            $grant = CampaignEpisode::findOrFail($run->campaign_episode_id)->grant();
            if ($grant->arm()) {
                $price = (int) ceil($step->arguments['maxTotal'] / $step->arguments['units']);
                $units = min($step->arguments['units'], (int) floor(app(CampaignTrade::class)->allowance($grant, $agent, $run) / $price));
                if ($units < 1) {
                    throw new GameException('Buy skipped: arm exposure requires a smaller batch.', retryAfter: 30);
                }
                if ($units < $step->arguments['units']) {
                    $bought = $run->totals['purchased'][$run->plan['symbol']] ?? 0;
                    $shortened = [$bought + $units, ($run->totals['purchase_cost'] ?? 0) + $units * $price];
                }
                $step = AutomationStep::act('buy', array_replace($step->arguments, ['units' => $units, 'maxTotal' => $units * $price]));
            }
        }
        if (! $v8 && $step->command === 'navigate' && isset($run->plan['mesh_work'])) {
            $arguments = $step->arguments + [
                'maxFuel' => 0,
                'maxSeconds' => min((int) now()->diffInSeconds($run->expires_at, false) - 5,
                    $run->plan['route']['outbound']['estimated_seconds'] + 180),
            ];
            $step = AutomationStep::act('navigate', $arguments);
        }
        $safety = app(CampaignSafety::class);
        $v8Credit = $v8 && CampaignSafety::isCreditCommand($step->command);
        if ($safety->perShipLocksEnabled($agent)) {
            $this->executor->clear($agent, defer: true, ship: $run->ship_symbol, command: $step->command,
                targetShip: $step->arguments['targetShip'] ?? null, reservationOnly: $v8Credit);
        } elseif ($v8) {
            $this->executor->clear($agent, defer: true, ship: $run->ship_symbol);
        }
        $safety->check($run, $agent, $step->command, $step->arguments);
        $cleanup = $v8 && $step->command === 'flight_mode' && ($step->arguments['flightMode'] ?? null) === 'CRUISE' ? $this->positionCleanupDeadline($run, $agent) : null;
        $created = null;
        DB::transaction(function () use ($agent, $run, $step, $shortened, $v8, $v8Credit, $cleanup, $freshQuote, &$created) {
            $this->gate->check($run->engine_session_id);
            if (! AutomationRun::whereKey($run->id)->whereNull('stop_requested_at')->whereNotNull('active_agent_id')->when(! $cleanup, fn ($query) => $query->where('expires_at', '>', now()))->update(['state' => 'running', 'next_action_at' => now()->addSeconds(5)])) {
                return;
            }
            if ($shortened) {
                app(CampaignTrade::class)->shortenLoad($run, $shortened[0], $shortened[1], 'exposure');
            }
            if ($run->strategy === 'buy_hauler' && $step->command === 'buy_ship') {
                $pin = $step->arguments['offer_configuration_hash'];
                $run->refresh();
                if (($run->totals['offer_configuration_hash'] ?? $pin) !== $pin) {
                    throw new GameException('Hauler configuration was already pinned at arrival.');
                }
                $run->update(['totals' => array_replace($run->totals, ['offer_configuration_hash' => $pin])]);
            }
            $action = ShipAction::create([
                'engine_session_id' => $run->engine_session_id,
                'agent_id' => $run->agent_id, 'automation_run_id' => $run->id, 'key' => (string) Str::uuid(),
                'reset_id' => $run->reset_id, 'ship_symbol' => $run->ship_symbol, 'origin' => 'automation',
                'target_type' => str_starts_with($step->command, 'contract_') ? 'contract' : ($step->command === 'buy_ship' ? 'fleet' : 'ship'),
                'target_id' => str_starts_with($step->command, 'contract_') ? $run->plan['contract_plan']['contract']['id'] : ($step->command === 'buy_ship' ? $step->arguments['waypointSymbol'] : $run->ship_symbol),
                'command' => $step->command, 'arguments' => array_merge($step->arguments, ['reserve' => ($v8 || (in_array($run->plan_version, [5, 6, 7], true) && in_array($run->strategy, ['gate_recon', 'buy_probe', 'buy_hauler'], true))) ? max($run->plan['reserve'], $step->arguments['reserve'] ?? 0) : $run->plan['reserve']]),
                'before_state' => $step->command === 'navigate' && isset($run->plan['mesh_work'])
                    ? ['ship' => ['nav' => ['waypointSymbol' => $run->plan['initial_waypoint'] ?? $run->plan['site']]]]
                    : ($freshQuote ? ['price_preflight' => ['id' => $freshQuote->id, 'version' => $freshQuote->data['observation_version']]] : null),
                'state' => $v8Credit ? 'preview' : 'pending', 'last_dispatched_at' => $v8Credit ? null : now(), 'expires_at' => min($cleanup ?? $run->expires_at, now()->addMinutes(3)),
            ]);
            if ($run->strategy === 'gate_recon' && $step->command === 'jump') {
                app(GateRecon::class)->bindIntent($run, $action);
            }
            if ($v8Credit) {
                $this->executor->reserveCreditIntent($agent, $run, $action);
                $this->bindCorridorOrder($agent, $run, $action);
                $this->executor->promoteCreditIntent($agent);
                $action->refresh();
            } else {
                ExecuteShipCommand::dispatch($action->id)->afterCommit();
            }
            $created = $action->state === ActionState::Pending ? $action : null;
        });

        return $created;
    }

    private function bindCorridorOrder(Agent $agent, AutomationRun $run, ShipAction $action): void
    {
        if ($run->strategy !== 'trade' || ! in_array($action->command, ['buy', 'sell'], true)) {
            return;
        }
        $index = $action->arguments['leg_index'] ?? 0;
        $leg = $run->plan['legs'][$index];
        $market = $leg[$action->command === 'buy' ? 'source' : 'buyer'];
        $query = DB::table('corridor_book_events')->where('agent_id', $agent->id)->where('reset_id', $run->reset_id)
            ->where('owner_type', 'run')->where('owner_id', (string) $run->id)->where('leg_index', $index)
            ->where('market', $market)->where('good', $leg['good'])->where('side', $action->command)->where('state', 'reserved')->whereNull('ship_action_id');
        $event = (clone $query)->orderBy('batch_index')->orderBy('id')->first();
        if (! $event) {
            return;
        }
        if ($event->units < $action->arguments['units']) {
            throw new GameException('Trade batch exceeds its reserved corridor depth.');
        }
        (clone $query)->where('id', $event->id)->update(['ship_action_id' => $action->id, 'updated_at' => now()]);
    }

    private function completeTrip(AutomationRun $run, array $ship, array $market): void
    {
        $totals = $run->totals;
        if (empty($run->plan['sell_only']) && empty($run->plan['one_way']) && ($run->plan['strategy'] ?? 'mining') !== 'mining' && $totals['phase'] !== 'return') {
            $totals['phase'] = 'return';
            $totals['visits'] += $ship['nav']['waypointSymbol'] === $run->plan['market'] ? 1 : 0;
            $run->update(['totals' => $totals, 'next_action_at' => now(), 'state' => RunState::Running, 'last_dispatched_at' => null]);

            return;
        }
        $totals['cycles']++;
        $totals['ending_fuel'] = $ship['fuel']['current'];
        $totals['unsold'] = MiningPlanner::inventory($ship);
        $totals['cash_contribution'] = $totals['sale_proceeds'] - $totals['fuel_cost'] - ($totals['purchase_cost'] ?? 0);
        $totals['elapsed_seconds'] = max(1, (int) $run->started_at->diffInSeconds(now()));
        $lastContribution = $totals['cash_contribution'] - ($totals['previous_cash_contribution'] ?? 0);
        $totals['last_cycle_cash_contribution'] = $lastContribution;
        $totals['last_cycle_contribution'] = $lastContribution;
        $totals['previous_cash_contribution'] = $totals['cash_contribution'];
        if ($totals['cycles'] >= $run->plan['cycles'] || $lastContribution <= 0) {
            $totals['finished_at'] = now()->toIso8601String();
            DB::transaction(function () use ($run, $totals, $lastContribution) {
                $run->update(['totals' => $totals, 'state' => RunState::Completed, 'active_agent_id' => null, 'next_action_at' => null,
                    'blocked_reason' => ($run->plan['strategy'] ?? 'mining') === 'scout' ? 'Observation complete. No earnings were assumed.' : ($lastContribution <= 0 ? 'Trip finished without positive cash contribution. A new preview is required.' : null)]);
                if ($run->strategy === 'sell_aboard') {
                    app(StateChain::class)->releaseRunHolds(Agent::findOrFail($run->agent_id), $run->id, 'delivery_fuel');
                }
            });
            app(MeshGovernor::class)->release($run->plan['mesh_allowance'] ?? null);

            return;
        }
        // Observed economics support another explicitly bounded trip. Revalidate route each cycle.
        $agent = Agent::findOrFail($run->agent_id);
        $surveyYields = null;
        if ($run->strategy === 'mining') {
            try {
                $surveyYields = $this->planner->surveyYields($agent, $run->plan);
                if ($run->plan['site'] !== $run->plan['market']) {
                    $this->planner->assertDepartureBids($market, $run->plan['market'], $surveyYields ?? $this->planner->allYields());
                }
            } catch (GameException $e) {
                $run->update(['totals' => $totals, 'state' => RunState::Blocked, 'blocked_reason' => $e->getMessage()]);

                return;
            }
        }
        $this->planner->route($agent, $ship, $run->plan['site'], $run->plan['market'], $surveyYields);
        $totals['phase'] = 'mine';
        $totals['load_attempts'] = 0;
        unset($totals['load_started_at']);
        $run->update(['totals' => $totals, 'next_action_at' => now(), 'last_dispatched_at' => null, 'state' => RunState::Running]);
    }

    public function previewDraft(Agent $agent, AutomationDraft $draft): AutomationRun
    {
        return $this->executor->locked($agent, function () use ($agent, $draft) {
            $this->executor->clear($agent, defer: true, ship: $draft->candidate['ship_symbol'] ?? null);
            $this->executor->assertConfigurationReviewed($agent, $draft->candidate['ship_symbol'] ?? '');
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
        $mining = $candidate['strategy'] === 'mining';
        $symbols = [$ship['nav']['waypointSymbol']];
        $contract = null;
        if ($mining) {
            $symbols[] = $candidate['site'];
        } else {
            $contract = $this->api->contract($agent, $candidate['contract_id']);
            $this->sync->observe($agent, 'contract', $contract['id'], $contract);
            $remaining = array_filter($contract['terms']['deliver'], fn ($term) => $term['unitsFulfilled'] < $term['unitsRequired']);
            $symbols = array_merge($symbols, array_column($remaining, 'destinationSymbol'));
        }
        if ($candidate['market'] !== null) {
            $symbols[] = $candidate['market'];
        }
        $points = [];
        foreach (array_unique($symbols) as $symbol) {
            $points[$symbol] = $this->api->waypoint($agent, $symbol);
            $this->sync->observe($agent, 'waypoint', $symbol, $points[$symbol]);
        }
        $limits = $draft->limits;
        if ($mining) {
            $limits += ['max_extractions' => 1, 'extraction_headroom' => $ship['cargo']['capacity'], 'site_seconds' => 300];
            if ($limits['extraction_headroom'] < 1 || $limits['extraction_headroom'] > $ship['cargo']['capacity']) {
                throw new GameException('Extraction headroom must be within cargo capacity.');
            }
        }
        $market = $candidate['market'] ? $this->api->market($agent, $candidate['market'], fresh: $mining) : [];
        if ($candidate['market']) {
            $this->sync->observe($agent, 'market', $candidate['market'], $market);
        }
        $plan = array_merge($limits, ['initial_credits' => $agent->credits, 'initial_inventory' => MiningPlanner::inventory($ship), 'initial_fuel' => $ship['fuel']['current'],
            'participating_ships' => [$ship['symbol']], 'capital' => app(CycleAccounting::class)->capital($agent, [$ship['symbol']]),
            'initial_waypoint' => $ship['nav']['waypointSymbol'], 'initial_status' => $ship['nav']['status'], 'site' => $candidate['site'], 'market' => $candidate['market'],
            'equipment' => OpportunityEvaluator::equipment($ship), 'market_quote_signature' => OpportunityEvaluator::quoteSignature($market), 'setup_cost' => 0]);
        if ($mining) {
            if ($ship['cargo']['units'] !== 0 || $ship['nav']['status'] === 'IN_TRANSIT') {
                throw new GameException('Mining requires stationary empty cargo.');
            }
            $surveyYields = $this->planner->surveyYields($agent, $plan);
            if ($ship['nav']['waypointSymbol'] !== $candidate['site']) {
                $this->planner->assertDepartureBids($market, $candidate['market'], $surveyYields ?? $this->planner->allYields());
            }
            $now = CarbonImmutable::now();
            $plan['route'] = $route = $this->planner->evaluateRoute($ship, $candidate['site'], $candidate['market'], $points, $market, $now, $agent->credits, $now, $agent, $surveyYields);
            if ($limits['reserve'] < $route['minimum_reserve'] || $limits['max_spend'] < $route['fuel_ceiling']) {
                throw new GameException('Mining reserve or fuel spending ceiling is below the verified route requirement.');
            }
            $plan['ordered_actions'] = ['Orbit and travel to the selected extraction site if needed', 'Up to '.$limits['max_extractions'].' '.($limits['max_extractions'] === 1 ? 'extraction' : 'extractions').' per trip within approved headroom and site-time limits', 'Depart for the approved buyer when an extraction, headroom or site-time limit is reached; travel can overlap the remaining extraction cooldown', 'Dock and sell only attributable yields in fresh-quote batches', 'Refuel within approved bounds; check each cycle contribution before any approved repeat'];
            $sameQuote = ($candidate['quote_signature'] ?? null) === $plan['market_quote_signature'];
            $plan['estimated_net'] = $sameQuote ? $candidate['estimated_net'] : null;
            $plan['trial_required'] = ! $sameQuote || $candidate['trial_required'];
        } else {
            $plan['contract_plan'] = $route = $this->contracts->plan($ship, $contract, $points, $candidate['market'] ? [$candidate['market'] => $market] : [], $candidate['market'], $candidate['use_existing_cargo'], now()->toImmutable());
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
        if ($ship) {
            $totals['ending_fuel'] = $ship['fuel']['current'] ?? $totals['ending_fuel'] ?? null;
            $totals['unsold'] = MiningPlanner::inventory($ship);
        }
        $totals['stopped_at'] = now()->toIso8601String();
        DB::transaction(function () use ($run, $totals, $reason) {
            $run->update(['state' => RunState::Stopped, 'active_agent_id' => null, 'next_action_at' => null, 'totals' => $totals, 'blocked_reason' => $reason]);
            if (($run->plan_version === 8 || $run->strategy === 'sell_aboard') && $agent = Agent::find($run->agent_id)) {
                $this->settleStoppedRunHolds($agent, $run, self::hasTradeCargo($run) || array_sum($totals['unsold'] ?? []) > 0);
            }
        });
        app(CycleAccounting::class)->record($run, $ship);
        app(MeshGovernor::class)->release($run->plan['mesh_allowance'] ?? null);
    }

    private function rejectPreview(AutomationRun $run, string $reason): never
    {
        $run->update(['state' => RunState::Stopped, 'blocked_reason' => $reason]);
        throw new GameException($reason);
    }
}
