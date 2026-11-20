<?php

namespace App\Gameplay;

use App\Enums\ActionState;
use App\Enums\RunState;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\ReconMission;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class GateRecon
{
    public function __construct(private SpaceTradersApi $api, private AgentSynchronizer $sync) {}

    private function locked(Agent $agent, callable $work): mixed
    {
        return app(HostLock::class)->run('campaign', fn () => app(HostLock::class)->scoped('agent-'.$agent->id,
            fn () => app(ShipCommandExecutor::class)->locked($agent, $work)));
    }

    public function approve(Agent $agent, User $user, string $symbol, array $overrides = [], bool $dryRun = false): ReconMission
    {
        return $this->locked($agent, function () use ($agent, $user, $symbol, $overrides, $dryRun) {
            $this->enabled();
            if ((string) $agent->user_id !== (string) $user->id) {
                throw new GameException('The operator must own this agent.');
            }
            $this->sync->guardReset($agent);
            $agent->update(['credits' => $this->api->agent($agent)['credits']]);
            $ship = $this->sync->refreshShip($agent, $symbol)->snapshot;
            if (! app(FuelPlanner::class)->fuelFree($ship)) {
                throw new GameException('Recon requires an owned fuel-free probe.');
            }
            $status = $this->api->manualRead($agent, 'get-status', [])['data'];
            $reset = CarbonImmutable::parse($status['serverResets']['next'] ?? throw new GameException('Next reset time is unknown.'));
            if (array_intersect(array_keys($overrides), ['equipment', 'next_reset'])) {
                throw new GameException('Equipment and reset are observed facts, not operator-overridable terms.');
            }
            $terms = array_replace_recursive(['cash_floor' => 100000, 'envelope' => max(0, $agent->credits - ($overrides['cash_floor'] ?? 100000)),
                'jump_exposure' => 7500, 'explore_until' => $reset->subHours(6)->toIso8601String(), 'return_deadline' => $reset->subHours(2)->toIso8601String(),
                'next_reset' => $reset->toIso8601String(), 'cooldown_estimate_s' => 1800, 'max_dwell_minutes' => 120, 'market_reads_per_visit' => 10,
                'catalogue_pages_per_system' => (int) config('gameplay.max_pages', 10), 'cash_wait_seconds' => 300, 'ask_recheck_seconds' => 900,
                'evaluation_retry_seconds' => 900, 'evaluation_retry_limit' => 2, 'module_less_trial' => true,
                'qualify' => ['scaled_score_min' => 250, 'scaled_margin_min' => 2000, 'confirm_max_age_min' => 60],
                'equipment' => OpportunityEvaluator::equipment($ship)], $overrides);
            $this->validateTerms($terms, $reset, $agent);
            $system = $ship['nav']['systemSymbol'];
            $gates = $this->api->manualRead($agent, 'get-system-waypoints', ['systemSymbol' => $system, 'type' => 'JUMP_GATE', 'page' => 1, 'limit' => 20]);
            $gate = $gates['data'][0]['symbol'] ?? throw new GameException('No origin jump gate discovered.');
            $mission = new ReconMission(['agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'ship_symbol' => $symbol,
                'origin_system' => $system, 'origin_gate' => $gate, 'terms' => $terms, 'terms_hash' => ReconMission::hash($terms),
                'approved_by' => $user->id, 'approved_at' => now(), 'state' => 'approved', 'graph' => [$system => ['gate' => $gate]],
                'visits' => [], 'capability' => [], 'return_reservation' => [], 'homeward_authorizations' => [], 'start_window_until' => now()->addHour()]);
            $this->readGate($agent, $mission, $system, false);
            $this->readGate($agent, $mission, $system, true);
            if (! ($mission->graph[$system]['complete'] ?? false)) {
                throw new GameException('Origin gate is not verified complete.');
            }
            if ($dryRun) {
                foreach ($mission->graph[$system]['connections'] ?? [] as $otherGate) {
                    $other = implode('-', array_slice(explode('-', $otherGate), 0, 2));
                    $this->readGate($agent, $mission, $other, false);
                    if ($mission->graph[$other]['complete']) {
                        $this->readGate($agent, $mission, $other, true);
                        if ($mission->path($system, $other)) {
                            while ($this->catalogueStep($agent, $mission, $other)) {
                                // Bounded by the approved page limit and shared request budget.
                            }
                        }
                    }
                }
            }
            if (! $dryRun) {
                $mission->save();
                $this->event($mission, 'probe_lent');
            }

            return $mission;
        });
    }

    private function validateTerms(array $terms, CarbonImmutable $reset, Agent $agent): void
    {
        $integers = ['cash_floor' => 0, 'envelope' => 1, 'jump_exposure' => 1, 'cooldown_estimate_s' => 0, 'max_dwell_minutes' => 1,
            'market_reads_per_visit' => 4, 'catalogue_pages_per_system' => 1, 'cash_wait_seconds' => 1, 'ask_recheck_seconds' => 900,
            'evaluation_retry_seconds' => 1, 'evaluation_retry_limit' => 0];
        if (array_diff(array_keys($terms), [...array_keys($integers), 'explore_until', 'return_deadline', 'next_reset', 'module_less_trial', 'qualify', 'equipment'])) {
            throw new GameException('Unknown recon term.');
        }
        foreach ($integers as $key => $minimum) {
            if (! is_int($terms[$key]) || $terms[$key] < $minimum) {
                throw new GameException('Invalid recon term: '.$key);
            }
        }
        if ($terms['envelope'] > $agent->credits - $terms['cash_floor'] || CarbonImmutable::parse($terms['explore_until'])->isPast()
            || CarbonImmutable::parse($terms['explore_until'])->gte($terms['return_deadline']) || CarbonImmutable::parse($terms['return_deadline'])->gt($reset->subMinutes(30))
            || $terms['equipment'] === [] || ! is_bool($terms['module_less_trial'])) {
            throw new GameException('Recon cash, equipment or absolute deadline terms are invalid.');
        }
        foreach (['scaled_score_min', 'scaled_margin_min', 'confirm_max_age_min'] as $key) {
            if (! is_numeric($terms['qualify'][$key] ?? null) || $terms['qualify'][$key] < 1) {
                throw new GameException('Invalid qualification term: '.$key);
            }
        }
    }

    public function start(ReconMission $mission): AutomationRun
    {
        $agent = Agent::findOrFail($mission->agent_id);

        return $this->locked($agent, function () use ($mission, $agent) {
            $mission->refresh();
            $mission->update(['start_window_until' => now()->addHour()]);
            $this->sync->guardReset($agent);
            $ship = $this->sync->refreshShip($agent, $mission->ship_symbol)->snapshot;
            $agent->update(['credits' => $this->api->agent($agent)['credits']]);
            $this->reconcileSettlement($mission);
            $mission->refresh();
            $this->enabled();
            if ($mission->closed_at || $mission->reset_id !== $agent->reset_id) {
                throw new GameException('Mission is closed or from another reset.');
            }
            if ($this->rejectedEquipment($agent, $ship) && $ship['nav']['systemSymbol'] === $mission->origin_system) {
                $this->close($mission, 'capability_rejected');
                throw new GameException('This equipment already had a jump capability rejection in this reset.');
            }
            if ($mission->actions()->where(fn ($q) => $q->whereIn('state', ['pending', 'sending', 'uncertain'])->orWhere('review_required', true))->exists()
                || ShipAction::where('agent_id', $agent->id)->where('ship_symbol', $mission->ship_symbol)->where(fn ($q) => $q->whereIn('state', ['pending', 'sending', 'uncertain'])->orWhere('review_required', true))->exists()
                || AutomationRun::where('active_agent_id', $agent->id)->where('ship_symbol', $mission->ship_symbol)->exists()
                || $ship['nav']['status'] === 'IN_TRANSIT') {
                throw new GameException('Probe has an active run, unresolved action or transit. Settle it; no takeover is permitted.');
            }
            app(ShipCommandExecutor::class)->clear($agent, defer: true, ship: $mission->ship_symbol);
            if (($mission->hold['reason'] ?? null) === 'overcharge') {
                $this->transition($mission, ['state' => 'returning', 'outcome' => $this->returnOutcome($mission), 'hold' => null]);
            } elseif ($mission->state === 'review') {
                throw new GameException('Mission requires operator review or explicit homeward authorization.');
            }
            if (($mission->state !== 'returning' || $mission->outcome === 'cash_wait') && now()->gte($mission->terms['explore_until'])) {
                if ($ship['nav']['systemSymbol'] === $mission->origin_system) {
                    $this->close($mission, 'time_exhausted');
                    throw new GameException('Exploration deadline reached.');
                }
                $this->transition($mission, ['state' => 'returning', 'outcome' => 'time_exhausted']);
            }
            $mode = $mission->state === 'returning' ? 'returning' : 'exploring';
            // Ordinary cash waits may continue after reaching home; reviewed returns cannot.
            $returnOnly = $mode === 'returning' && $mission->outcome !== 'cash_wait';
            $authority = $returnOnly ? $mission->authorization()['remaining_total'] : $mission->ledger()['remaining'];
            $deadline = $this->deadline($mission);
            if ($deadline->isPast() || ($mode === 'returning' && $authority < 1)) {
                $this->hold($mission, 'stranded', null, true);
                throw new GameException('Homeward authority or deadline is exhausted. Explicit return approval required.');
            }
            if ((app(CreditAccounting::class)->summary($agent)['unexplained_change'] ?? 0) !== 0 || MiningPlanner::inventory($ship) !== []
                || OpportunityEvaluator::equipment($ship) !== $mission->terms['equipment']) {
                throw new GameException('Balance, cargo or equipment changed. Reconcile before starting.');
            }
            $this->transition($mission, ['state' => $mode, 'hold' => null]);
            $plan = ['strategy' => 'gate_recon', 'mission_id' => $mission->id, 'terms_hash' => $mission->terms_hash, 'outbound_basis' => $mission->outboundBasis(),
                'reserve' => 0, 'max_spend' => $authority, 'max_loss' => $authority, 'minutes' => max(1, (int) now()->diffInMinutes($deadline)), 'return_deadline' => $deadline->toIso8601String(),
                'initial_credits' => $agent->credits, 'initial_inventory' => MiningPlanner::inventory($ship), 'initial_fuel' => $ship['fuel']['current'],
                'initial_waypoint' => $ship['nav']['waypointSymbol'], 'initial_status' => $ship['nav']['status'], 'equipment' => OpportunityEvaluator::equipment($ship),
                'initial_configuration' => FuelPlanner::configuration($ship), 'participating_ships' => [$ship['symbol']],
                'capital' => app(CycleAccounting::class)->capital($agent, [$ship['symbol']])];
            $run = AutomationRun::create(['agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'ship_symbol' => $ship['symbol'], 'strategy' => 'gate_recon',
                'plan_version' => 5, 'engine_session_id' => app(EngineGate::class)->session(), 'state' => RunState::Preview,
                'expires_at' => min($deadline, now()->addSeconds(config('gameplay.preview_seconds'))), 'plan' => $plan,
                'totals' => ['phase' => 'recon', 'purchased' => [], 'purchase_cost' => 0, 'travel_fuel' => 0, 'visits' => 0, 'cycles' => 0,
                    'attempts' => 0, 'no_progress' => 0, 'extracted' => [], 'sold' => [], 'sale_proceeds' => 0, 'fuel_cost' => 0, 'fuel_units' => 0,
                    'credit_delta' => 0, 'receipts' => 0, 'spending' => 0, 'jump_cost' => 0, 'jumps' => 0]]);

            return app(AutomationRunner::class)->start($agent, $run);
        });
    }

    public function validateStart(Agent $agent, AutomationRun $run): array
    {
        $mission = $this->mission($agent, $run);
        $ship = $this->sync->refreshShip($agent, $run->ship_symbol)->snapshot;
        $agent->update(['credits' => $this->api->agent($agent)['credits']]);
        $plan = $run->plan;
        if (! in_array($mission->state, ['exploring', 'returning'], true) || $this->deadline($mission)->isPast()
            || $ship['nav']['status'] === 'IN_TRANSIT' || $agent->credits !== $plan['initial_credits']
            || MiningPlanner::inventory($ship) !== $plan['initial_inventory'] || $ship['fuel']['current'] !== $plan['initial_fuel']
            || $ship['nav']['waypointSymbol'] !== $plan['initial_waypoint'] || $ship['nav']['status'] !== $plan['initial_status']
            || OpportunityEvaluator::equipment($ship) !== $plan['equipment'] || FuelPlanner::configuration($ship) !== $plan['initial_configuration']
            || (app(CreditAccounting::class)->summary($agent)['unexplained_change'] ?? 0) !== 0) {
            throw new GameException('Recon preview state or authority changed. Create a fresh run.');
        }

        return $plan;
    }

    private function mission(Agent $agent, AutomationRun $run): ReconMission
    {
        $this->enabled();
        $mission = ReconMission::find($run->plan['mission_id'] ?? null);
        if (! $mission || $run->plan_version !== 5 || $run->campaign_episode_id || $mission->closed_at || $mission->agent_id !== $agent->id
            || $mission->reset_id !== $agent->reset_id || $mission->ship_symbol !== $run->ship_symbol
            || ! hash_equals($mission->outboundBasis(), $run->plan['outbound_basis'] ?? $mission->terms_hash)
            || ! hash_equals($mission->terms_hash, $run->plan['terms_hash'] ?? '') || ! hash_equals($mission->terms_hash, ReconMission::hash($mission->terms))) {
            throw new GameException('Recon mission authority does not match this run.');
        }

        return $mission;
    }

    private function enabled(): void
    {
        if (! config('gameplay.gate_recon_enabled')) {
            throw new GameException('Gate reconnaissance is disabled.');
        }
    }

    public function deadline(ReconMission $mission): CarbonImmutable
    {
        $authorizations = $mission->homeward_authorizations;

        return CarbonImmutable::parse($authorizations ? end($authorizations)['until'] : $mission->terms['return_deadline']);
    }

    private function transition(ReconMission $mission, array $changes): void
    {
        DB::transaction(function () use ($mission, $changes) {
            $query = ReconMission::whereKey($mission->id)->where('state', $mission->state)->whereNull('closed_at');
            if (array_key_exists('outbound_authorizations', $changes)) {
                $old = $mission->outbound_authorizations ?? [];
                $next = $changes['outbound_authorizations'];
                $mission->verifyOutboundAppend($old, $next);
                if (count($next) !== count($old) + 1) {
                    throw new GameException('Exactly one outbound authorization must be appended.');
                }
                $raw = $mission->getRawOriginal('outbound_authorizations');
                $raw === null ? $query->whereNull('outbound_authorizations') : $query->where('outbound_authorizations', $raw);
            }
            $values = $changes;
            foreach (['hold', 'graph', 'visits', 'capability', 'return_reservation', 'homeward_authorizations', 'outbound_authorizations'] as $field) {
                if (isset($values[$field])) {
                    $values[$field] = json_encode($values[$field], JSON_THROW_ON_ERROR);
                }
            }
            if (! $query->update($values)) {
                throw new GameException('Recon mission changed during transition.');
            }
            $mission->refresh();
        });
    }

    private function hold(ReconMission $mission, string $reason, ?CarbonImmutable $next = null, bool $review = false, array $cause = []): AutomationStep
    {
        if ($next && in_array($reason, ['cash_wait', 'ask_over_cap_home'], true) && $mission->state !== 'returning') {
            $next = min($next, CarbonImmutable::parse($mission->terms['explore_until']));
        }
        $this->transition($mission, ['state' => $review ? 'review' : $mission->state, 'hold' => ['reason' => $reason, 'since' => now()->toIso8601String(),
            'next_check_at' => $next?->toIso8601String(), 'until' => $this->deadline($mission)->toIso8601String(), 'cause' => $cause]]);

        return $review ? AutomationStep::blocked('Recon '.$reason.'. Operator review required.') : AutomationStep::wait($next ?? now()->toImmutable()->addSeconds(300));
    }

    private function event(ReconMission $mission, string $kind): void
    {
        $episode = $this->episode($mission);
        if ($episode) {
            app(CampaignControl::class)->event($episode->grant(), $episode, $kind, ['mission_id' => $mission->id, 'ship' => $mission->ship_symbol]);
        }
    }

    public function episode(ReconMission $mission): ?CampaignEpisode
    {
        return CampaignEpisode::where('agent_id', $mission->agent_id)->where('reset_id', $mission->reset_id)->whereNull('closed_at')
            ->whereIn('campaign_grant_id', CampaignGrant::where('slot', 1)->whereNull('limits->arm')->select('id'))->latest('id')->first();
    }

    private function close(ReconMission $mission, string $outcome): void
    {
        $this->transition($mission, ['state' => 'closed', 'outcome' => $outcome, 'hold' => null, 'closed_at' => now()]);
        $this->event($mission, 'probe_returned');
    }

    public function cancel(ReconMission $mission): void
    {
        $agent = Agent::findOrFail($mission->agent_id);
        $this->locked($agent, function () use ($mission) {
            $mission->refresh();
            $this->reconcileSettlement($mission);
            if ($mission->fresh()->closed_at) {
                return;
            }
            $this->transition($mission, ['state' => 'returning', 'outcome' => 'cancelled']);
            $ship = Ship::where('agent_id', $mission->agent_id)->where('symbol', $mission->ship_symbol)->firstOrFail()->snapshot;
            if ($ship['nav']['systemSymbol'] === $mission->origin_system && ! $mission->actions()->where(fn ($q) => $q->whereIn('state', ['pending', 'sending', 'uncertain'])->orWhere('review_required', true))->exists()) {
                foreach ($mission->runs()->whereNotNull('active_agent_id')->get() as $run) {
                    app(AutomationRunner::class)->finishStop($run, 'Recon cancelled at home.');
                }
                $this->close($mission, 'cancelled');
            }
        });
    }

    public function rejectedEquipment(Agent $agent, array $ship): bool
    {
        return ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('command', 'jump')->whereIn('api_code', [4210, 4229])->get()
            ->contains(fn ($action) => isset($action->before_state['ship']) && OpportunityEvaluator::equipment($action->before_state['ship']) === OpportunityEvaluator::equipment($ship));
    }

    public static function neverSent(ShipAction $action): bool
    {
        $outcome = $action->outcome ?? [];

        return $action->state === ActionState::Rejected && isset($outcome['local_refusal']) && $action->api_code === null
            && ! isset($outcome['body_sha256'], $outcome['response_received_at']) && ! array_key_exists('body_sha256', $outcome)
            && ! array_key_exists('response_received_at', $outcome) && ! array_key_exists('response', $outcome)
            && ($outcome['transport_reservations'] ?? 0) === ($outcome['pretransport_deferrals'] ?? 0);
    }

    public function reconcileSettlement(ReconMission $mission): void
    {
        $mission->refresh();
        if ($mission->closed_at) {
            return;
        }
        $shipRow = Ship::where('agent_id', $mission->agent_id)->where('symbol', $mission->ship_symbol)->first();
        $home = $shipRow && $shipRow->snapshot['nav']['systemSymbol'] === $mission->origin_system && $shipRow->snapshot['nav']['status'] !== 'IN_TRANSIT';
        foreach ($mission->actions()->orderBy('id')->get() as $action) {
            if (in_array($action->state, [ActionState::Pending, ActionState::Sending], true)) {
                return;
            }
            if ($action->command === 'jump' && $action->state === ActionState::Acknowledged && ! isset($action->outcome['transaction_total'])) {
                $returnApproval = collect($mission->homeward_authorizations)->contains(fn ($record) => ($record['cursor'] ?? 0) >= $action->id
                    && isset($action->outcome['reconciled_at'], $action->outcome['reviewed_at'])
                    && CarbonImmutable::parse($record['at'])->gte($action->outcome['reviewed_at']));
                if (! $returnApproval) {
                    $this->hold($mission, 'unresolved_adjustment', null, true, ['action_id' => $action->id]);

                    return;
                }
            }
            if ($action->state === ActionState::Uncertain) {
                $this->hold($mission, 'uncertain', null, true, ['action_id' => $action->id]);

                return;
            }
            if ($action->command === 'jump' && $action->state === ActionState::Confirmed && isset($action->outcome['recon_return_reservation']) && $action->id > ($mission->return_reservation['cursor'] ?? 0)) {
                $this->transition($mission, ['return_reservation' => $action->outcome['recon_return_reservation']]);
            }
            if ($action->review_required) {
                $this->hold($mission, 'overcharge', null, true, ['action_id' => $action->id]);

                return;
            }
            if ($action->command === 'jump' && $action->state === ActionState::Confirmed && empty($mission->capability)) {
                $this->transition($mission, ['capability' => ['equipment' => $mission->terms['equipment'], 'action_id' => $action->id, 'confirmed' => true]]);
            }
            if ($action->state !== ActionState::Rejected || ($action->outcome['recon_reconciled'] ?? false)) {
                continue;
            }
            $run = AutomationRun::find($action->automation_run_id);
            if ($run && ! $mission->actions()->where('automation_run_id', $run->id)->whereIn('state', ['pending', 'sending', 'uncertain'])->exists()) {
                app(AutomationRunner::class)->finishStop($run, 'Recon action settled. Explicit new-run resume required.');
            }
            if (self::neverSent($action)) {
                $this->transition($mission, ['hold' => ['reason' => 'restart_needed', 'since' => now()->toIso8601String(), 'next_check_at' => null,
                    'until' => $mission->terms['explore_until'], 'cause' => ['action_id' => $action->id] + $action->outcome['local_refusal']], 'start_window_until' => now()->addHour()]);
            } elseif ($action->command === 'jump' && in_array((int) $action->api_code, [4210, 4229], true) && $home
                && isset($action->before_state['ship']) && $action->before_state['ship']['nav']['systemSymbol'] === $mission->origin_system
                && $shipRow->snapshot['nav']['waypointSymbol'] === $action->before_state['ship']['nav']['waypointSymbol']) {
                $this->transition($mission, ['capability' => ['equipment' => $mission->terms['equipment'], 'action_id' => $action->id, 'api_code' => $action->api_code]]);
                $this->close($mission, 'capability_rejected');
            } else {
                $this->hold($mission, $home ? 'rejected' : 'stranded_rejected', null, true, ['action_id' => $action->id]);
            }
            ShipAction::whereKey($action->id)->update(['outcome->recon_reconciled' => true]);

            return;
        }
        foreach ($mission->runs()->whereIn('state', ['blocked', 'stopping', 'stopped'])->whereNotNull('active_agent_id')->get() as $old) {
            if (! $mission->actions()->where('automation_run_id', $old->id)->where(fn ($q) => $q->whereIn('state', ['pending', 'sending', 'uncertain'])->orWhere('review_required', true))->exists()) {
                app(AutomationRunner::class)->finishStop($old, 'Settled recon run finalized. Resume requires a new run.');
            }
        }
        if (! $mission->closed_at && now()->gte($this->deadline($mission))) {
            if ($home) {
                $this->close($mission, 'time_exhausted');
            } else {
                $this->hold($mission, 'stranded', null, true);
            }
        }
    }

    private function readGate(Agent $agent, ReconMission $mission, string $system, bool $connections): void
    {
        $graph = $mission->graph;
        $node = $graph[$system];
        if ($connections) {
            $data = $this->api->manualRead($agent, 'get-jump-gate', ['systemSymbol' => $system, 'waypointSymbol' => $node['gate']])['data'];
            $node['connections'] = $data['connections'];
            $node['connections_at'] = now()->toIso8601String();
            foreach ($node['connections'] as $gate) {
                $other = implode('-', array_slice(explode('-', $gate), 0, 2));
                $graph[$other] ??= ['gate' => $gate];
                if (! empty($graph[$other]['excluded'])) {
                    unset($graph[$other]['waypoint_at'], $graph[$other]['connections_at']);
                }
            }
        } else {
            $data = $this->api->waypoint($agent, $node['gate']);
            $this->sync->observe($agent, 'waypoint', $node['gate'], $data);
            $node['complete'] = $data['type'] === 'JUMP_GATE' && ($data['isUnderConstruction'] ?? null) === false;
            $node['isUnderConstruction'] = $data['isUnderConstruction'] ?? null;
            $node['waypoint_at'] = now()->toIso8601String();
            $node['excluded'] = $node['complete'] ? null : 'Gate incomplete or construction unknown.';
        }
        unset($node['read_failure'], $node['retry_at'], $node['read_failure_count']);
        $node['exclusion_reasons'] = [];
        if ($connections) {
            foreach ($graph as $otherSystem => $other) {
                if (in_array($node['gate'], $other['connections'] ?? [], true) && ! in_array($other['gate'], $node['connections'], true)) {
                    $node['exclusion_reasons'][$otherSystem] = 'Missing back-link.';
                }
            }
        }
        $graph[$system] = $node;
        if ($mission->exists) {
            $this->transition($mission, ['graph' => $graph]);
        } else {
            $mission->graph = $graph;
        }
    }

    private function graphStep(Agent $agent, ReconMission $mission, string $current): bool
    {
        $node = $mission->graph[$current] ?? null;
        if (! $node) {
            throw new GameException('Current gate is not in the mission graph.');
        }
        $systems = [$current];
        foreach ($node['connections'] ?? [] as $gate) {
            $systems[] = implode('-', array_slice(explode('-', $gate), 0, 2));
        }
        foreach ($mission->graph as $system => $entry) {
            if (! empty($entry['read_failure'])) {
                $systems[] = $system;
            }
        }
        foreach (array_unique($systems) as $system) {
            $entry = $mission->graph[$system];
            if ((! empty($entry['retry_at']) && now()->lt($entry['retry_at']))
                || (! empty($entry['read_failure']) && ($entry['read_failure_count'] ?? 0) > $mission->terms['evaluation_retry_limit'])) {
                continue;
            }
            $connections = ! empty($entry['waypoint_at']);
            if ($connections && (! ($entry['complete'] ?? false) || ! empty($entry['connections_at']))) {
                continue;
            }
            try {
                $this->readGate($agent, $mission, $system, $connections);
            } catch (RequestDeferred $e) {
                throw $e;
            } catch (GameException $e) {
                $graph = $mission->graph;
                $graph[$system]['read_failure'] = $e->getMessage();
                $attempts = ($graph[$system]['read_failure_count'] ?? 0) + 1;
                $graph[$system]['read_failure_count'] = $attempts;
                $graph[$system]['retry_at'] = $attempts <= $mission->terms['evaluation_retry_limit']
                    ? now()->addSeconds($mission->terms['evaluation_retry_seconds'])->toIso8601String() : null;
                $this->transition($mission, ['graph' => $graph]);
            }

            return true;
        }

        return false;
    }

    private function catalogueStep(Agent $agent, ReconMission $mission, string $system): bool
    {
        $node = $mission->graph[$system];
        $catalogue = $node['catalogue'] ?? ['page' => 0, 'complete' => false, 'marketplaces' => []];
        if ($catalogue['complete'] || $catalogue['page'] >= $mission->terms['catalogue_pages_per_system']) {
            return false;
        }
        $page = $catalogue['page'] + 1;
        $result = $this->api->manualRead($agent, 'get-system-waypoints', ['systemSymbol' => $system, 'page' => $page, 'limit' => 20]);
        foreach ($result['data'] as $point) {
            $this->sync->observe($agent, 'waypoint', $point['symbol'], $point, 'listing');
            if (in_array('MARKETPLACE', array_column($point['traits'] ?? [], 'symbol'), true)) {
                $catalogue['marketplaces'][] = $point['symbol'];
            }
        }
        $catalogue['marketplaces'] = array_values(array_unique($catalogue['marketplaces']));
        $catalogue['page'] = $page;
        $catalogue['total'] = $result['meta']['total'];
        $catalogue['complete'] = $page * 20 >= $result['meta']['total'];
        $graph = $mission->graph;
        $graph[$system]['catalogue'] = $catalogue;
        if ($mission->exists) {
            $this->transition($mission, ['graph' => $graph]);
        } else {
            $mission->graph = $graph;
        }

        return true;
    }

    public function next(Agent $agent, array $ship, AutomationRun $run): AutomationStep
    {
        $mission = ReconMission::findOrFail($run->plan['mission_id']);
        $this->reconcileSettlement($mission);
        $mission->refresh();
        if ($mission->closed_at) {
            return AutomationStep::trip();
        }
        $system = $ship['nav']['systemSymbol'];
        $home = $system === $mission->origin_system;
        if (! config('gameplay.gate_recon_enabled')) {
            return $this->hold($mission, $home ? 'flag_off_home' : 'flag_off_abroad');
        }
        $this->mission($agent, $run);
        if ($mission->state === 'review' || ($mission->hold['reason'] ?? null) === 'restart_needed') {
            return AutomationStep::blocked('Recon requires explicit review or recon:start.');
        }
        if ($ship['nav']['status'] === 'IN_TRANSIT') {
            return AutomationStep::wait(CarbonImmutable::parse($ship['nav']['route']['arrival']));
        }
        if (now()->gte($this->deadline($mission))) {
            return $home ? $this->ending($mission, 'time_exhausted') : $this->hold($mission, 'stranded', null, true);
        }
        if (($mission->state !== 'returning' || $mission->outcome === 'cash_wait') && now()->gte($mission->terms['explore_until'])) {
            $this->transition($mission, ['state' => 'returning', 'outcome' => 'time_exhausted', 'hold' => null]);
        }
        if ($mission->state === 'returning') {
            if ($home) {
                if ($mission->outcome === 'cash_wait' && now()->lt($mission->terms['explore_until'])) {
                    $this->transition($mission, ['state' => 'exploring', 'outcome' => null]);
                } else {
                    return $this->ending($mission, $mission->outcome ?? 'cancelled');
                }
            } else {
                return $this->depart($agent, $mission, $ship, $run, $mission->origin_system, true);
            }
        }
        $outbound = $mission->outbound();
        if ($outbound && $outbound['status'] === 'available') {
            return $this->departOutbound($agent, $mission, $ship, $run, $outbound);
        }
        if ($this->graphStep($agent, $mission, $system)) {
            return AutomationStep::wait(now()->toImmutable());
        }
        if (! $home) {
            if ($this->catalogueStep($agent, $mission, $system)) {
                return AutomationStep::wait(now()->toImmutable());
            }
            $sample = $this->sample($agent, $mission, $ship);
            if ($sample) {
                return $sample;
            }
            if (($mission->visits[$system]['classification'] ?? null) === 'promising') {
                $this->transition($mission, ['state' => 'returning', 'outcome' => 'found']);

                return $this->depart($agent, $mission, $ship, $run, $mission->origin_system, true);
            }
        }
        $candidates = [];
        $unresolved = collect($mission->graph)->contains(fn ($node) => ! empty($node['read_failure']));
        $pendingAt = collect($mission->graph)->filter(fn ($node) => ! empty($node['read_failure'])
            && ! empty($node['retry_at']) && now()->lt($node['retry_at']))
            ->map(fn ($node) => CarbonImmutable::parse($node['retry_at']))->values()->all();
        foreach ($mission->graph as $target => $node) {
            if ($target === $mission->origin_system || ! $mission->path($system, $target)) {
                continue;
            }
            if ($this->catalogueStep($agent, $mission, $target)) {
                return AutomationStep::wait(now()->toImmutable());
            }
            $visit = $mission->visits[$target] ?? [];
            $classification = $visit['classification'] ?? 'unvisited';
            $class = array_search($classification, ['unconfirmed', 'unvisited', 'partial', 'evidence_expired', 'route_unmeasured'], true);
            if (in_array($classification, ['evidence_expired', 'route_unmeasured', 'evidence_incomplete'], true) || ! ($node['catalogue']['complete'] ?? false)) {
                $unresolved = true;
            }
            if ($class === false) {
                continue;
            }
            if ($class >= 3 && ! empty($visit['retry_at']) && now()->lt($visit['retry_at'])) {
                $pendingAt[] = CarbonImmutable::parse($visit['retry_at']);

                continue;
            }
            $path = $mission->path($system, $target);
            $candidates[] = ['system' => $target, 'key' => $this->rankingKey($mission, $target, $class, count($path) - 1)];
        }
        usort($candidates, fn ($a, $b) => $a['key'] <=> $b['key']);
        $cash = $budget = $time = false;
        foreach ($candidates as $candidate) {
            $target = $candidate['system'];
            $R = count($mission->path($system, $target)) - 1;
            $H = count($mission->path($target, $mission->origin_system)) - 1;
            if ($R === 0) {
                $this->retryVisit($mission, $system);

                return AutomationStep::wait(now()->toImmutable());
            }
            $required = $mission->terms['jump_exposure'] * ($R + $H);
            if ($mission->ledger()['remaining'] < $required) {
                $budget = true;

                continue;
            }
            if (! $this->timeFits($mission, $R + $H)) {
                $time = true;

                continue;
            }
            if ($agent->credits - $required < $mission->terms['cash_floor']) {
                $cash = true;

                continue;
            }

            return $this->depart($agent, $mission, $ship, $run, $target, false);
        }
        if ($cash) {
            if ($home) {
                return $this->hold($mission, 'cash_wait', now()->toImmutable()->addSeconds($mission->terms['cash_wait_seconds']));
            }
            $this->transition($mission, ['state' => 'returning', 'outcome' => 'cash_wait']);
        } elseif ($pendingAt && ! $budget && ! $time) {
            if ($step = $this->travel($agent, $ship, $mission->graph[$system]['gate'])) {
                return $step;
            }

            return $this->hold($mission, 'evidence_wait', min($pendingAt));
        } else {
            $outcome = $budget ? 'budget_exhausted' : ($time ? 'time_exhausted' : ($unresolved ? 'evidence_incomplete' : 'frontier_exhausted'));
            $this->transition($mission, ['state' => 'returning', 'outcome' => $outcome]);
        }

        return $home ? $this->ending($mission, $mission->outcome) : $this->depart($agent, $mission, $ship, $run, $mission->origin_system, true);
    }

    private function returnOutcome(ReconMission $mission): string
    {
        return $mission->outcome && $mission->outcome !== 'cash_wait' ? $mission->outcome : 'cancelled';
    }

    private function ending(ReconMission $mission, string $outcome): AutomationStep
    {
        $this->close($mission, $outcome);

        return AutomationStep::trip();
    }

    private function cooldownEstimate(ReconMission $mission): int
    {
        $observed = $mission->actions()->where('command', 'jump')->where('state', 'confirmed')->get()
            ->filter(fn ($action) => isset($action->outcome['response']['cooldown']['totalSeconds']))
            ->map(fn ($action) => (int) $action->outcome['response']['cooldown']['totalSeconds']);

        return $observed->isEmpty() ? $mission->terms['cooldown_estimate_s'] : (int) ceil(1.25 * $observed->max());
    }

    private function timeFits(ReconMission $mission, int $hops): bool
    {
        return now()->addSeconds(($this->cooldownEstimate($mission) + 60) * $hops + 900)->lte($this->deadline($mission));
    }

    private function travel(Agent $agent, array $ship, string $waypoint): ?AutomationStep
    {
        if ($ship['nav']['waypointSymbol'] === $waypoint) {
            return null;
        }
        if ($ship['nav']['status'] === 'DOCKED') {
            return AutomationStep::act('orbit');
        }
        app(FuelPlanner::class)->leg($agent, $ship, $waypoint);

        return AutomationStep::act('navigate', ['waypointSymbol' => $waypoint]);
    }

    private function depart(Agent $agent, ReconMission $mission, array $ship, AutomationRun $run, string $target, bool $homeward): AutomationStep
    {
        $system = $ship['nav']['systemSymbol'];
        $path = $homeward ? ($mission->authorization()['remaining_path'] ?? []) : $mission->path($system, $target);
        if (! $path || $path[0] !== $system || end($path) !== $target || count($path) < 2
            || ($homeward && ! $this->verifiedRoute($mission, $path, $system))) {
            return $this->hold($mission, 'return_authorization_needed', null, true);
        }
        $gate = $mission->graph[$system]['gate'];
        if ($step = $this->travel($agent, $ship, $gate)) {
            return $step;
        }
        if ($ship['nav']['status'] === 'DOCKED') {
            return AutomationStep::act('orbit');
        }
        $cap = $mission->terms['jump_exposure'];
        $reserve = $mission->terms['cash_floor'] + $cap * (count($path) - 2 + count($mission->path($target, $mission->origin_system)) - 1);
        if ($homeward) {
            $auth = $mission->authorization();
            if ($path !== ($auth['remaining_path'] ?? []) || count($auth['remaining_caps']) < count($path) - 1 || $auth['remaining_total'] < array_sum($auth['remaining_caps'])) {
                return $this->hold($mission, 'return_authorization_needed', null, true);
            }
            $cap = $auth['remaining_caps'][0];
            $reserve = array_sum(array_slice($auth['remaining_caps'], 1));
        }
        $quote = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'market')->where('symbol', $gate)->first();
        $ask = collect($quote?->data['tradeGoods'] ?? [])->firstWhere('symbol', 'ANTIMATTER');
        $over = $ask && $ask['purchasePrice'] > $cap;
        $cadence = $over ? $mission->terms['ask_recheck_seconds'] : config('gameplay.snapshot_seconds');
        if (! $quote || $quote->observed_at->lte(now()->subSeconds($cadence))) {
            $this->marketRead($agent, $mission, $system, $gate, false);

            return AutomationStep::wait(now()->toImmutable());
        }
        if (! $ask || $over) {
            return $this->hold($mission, $homeward ? 'ask_over_cap_abroad' : 'ask_over_cap_home', $quote->observed_at->addSeconds($mission->terms['ask_recheck_seconds']));
        }
        if (now()->lt($ship['cooldown']['readyAt'])) {
            return AutomationStep::wait(CarbonImmutable::parse($ship['cooldown']['readyAt']));
        }
        $arguments = ['waypointSymbol' => $mission->graph[$path[1]]['gate'], 'maxTotal' => $cap, 'reserve' => $reserve,
            'recon_homeward' => $homeward, 'recon_path' => $path, 'recon_target' => $target,
            'recon_inflight_exposure' => $cap * max(count($mission->path($system, $mission->origin_system) ?? []) - 1, count($mission->path($path[1], $mission->origin_system) ?? []) - 1)];
        $run->update(['plan' => array_replace($run->plan, ['recon_leg' => $arguments])]);
        try {
            $this->authorizeJump($agent, $run, $ship, $mission->graph[$system], $mission->graph[$path[1]], $arguments);
        } catch (GameException $e) {
            if (str_contains($e->getMessage(), 'live cash')) {
                return $this->hold($mission, 'cash_wait', now()->toImmutable()->addSeconds($mission->terms['cash_wait_seconds']));
            }
            throw $e;
        }

        return AutomationStep::act('jump', $arguments);
    }

    public function bindIntent(AutomationRun $run, ShipAction $action): void
    {
        if ($action->command !== 'jump') {
            return;
        }
        $mission = ReconMission::findOrFail($run->plan['mission_id']);
        if ($mission->actions()->where('command', 'jump')->whereKeyNot($action->id)->whereIn('state', ['pending', 'sending', 'uncertain'])->exists()) {
            throw new GameException('Another mission jump is unsettled.');
        }
        $plan = $run->plan;
        $plan['recon_intent_id'] = $action->id;
        $run->update(['plan' => $plan]);
        if (empty($action->arguments['recon_homeward'])) {
            $target = $action->arguments['recon_path'][1];
            if (isset($action->arguments['recon_outbound_hash'])) {
                $grant = $mission->outbound();
                if (! $grant || $grant['status'] !== 'bound/consumed' || $grant['intent_id'] !== $action->id) {
                    throw new GameException('Outbound grant is already consumed or expired.');
                }
                $agent = Agent::findOrFail($run->agent_id);
                $ship = Ship::where('agent_id', $agent->id)->where('symbol', $run->ship_symbol)->firstOrFail()->snapshot;
                $this->authorizeJump($agent, $run, $ship, $mission->graph[$grant['from']], $mission->graph[$target], $action->arguments);
            }
            $path = $action->arguments['recon_return_path'] ?? $mission->path($target, $mission->origin_system);
            $caps = array_fill(0, count($path) - 1, $mission->terms['jump_exposure']);
            ShipAction::whereKey($action->id)->update(['outcome->recon_return_reservation' => ['path' => $path, 'caps' => $caps, 'total' => array_sum($caps), 'cursor' => $action->id, 'outbound_action_id' => $action->id]]);
        }
    }

    public function authorizeJump(Agent $agent, AutomationRun $run, array $ship, array $from, array $target, array $arguments): void
    {
        $mission = $this->mission($agent, $run);
        $homeward = (bool) ($arguments['recon_homeward'] ?? false);
        if (! in_array($mission->state, ['exploring', 'returning'], true) || ($mission->state === 'returning' && ! $homeward)
            || ! $mission->terms['module_less_trial'] || OpportunityEvaluator::equipment($ship) !== $mission->terms['equipment'] || $this->rejectedEquipment($agent, $ship)) {
            throw new GameException('Recon jump capability or mission mode is not authorized.');
        }
        $leg = $run->plan['recon_leg'] ?? [];
        foreach (['waypointSymbol', 'maxTotal', 'reserve', 'recon_homeward', 'recon_path', 'recon_target', 'recon_outbound_hash', 'recon_return_path', 'recon_commitments', 'recon_inflight_exposure'] as $key) {
            if (($arguments[$key] ?? null) !== ($leg[$key] ?? null)) {
                throw new GameException('Recon command differs from the approved current leg.');
            }
        }
        $path = $arguments['recon_path'];
        $system = $ship['nav']['systemSymbol'];
        $to = implode('-', array_slice(explode('-', $arguments['waypointSymbol']), 0, 2));
        if ($path[0] !== $system || ($path[1] ?? null) !== $to || $ship['nav']['waypointSymbol'] !== $mission->graph[$system]['gate']) {
            throw new GameException('Recon hop is not the next current gate leg.');
        }
        foreach (array_slice($path, 1) as $index => $node) {
            if (! $mission->edge($path[$index], $node)) {
                throw new GameException('Recon path is not bidirectionally verified.');
            }
        }
        $intentId = $run->plan['recon_intent_id'] ?? null;
        $intent = $intentId ? $mission->actions()->find($intentId) : null;
        if ($intent && (! in_array($intent->state, [ActionState::Pending, ActionState::Sending], true) || $intent->arguments !== $arguments)) {
            $intentId = null;
        }
        if ($mission->actions()->where('command', 'jump')->whereIn('state', ['pending', 'sending', 'uncertain'])->when($intentId, fn ($q) => $q->whereKeyNot($intentId))->exists()) {
            throw new GameException('Another recon jump is unsettled.');
        }
        $this->requireDepartureQuote($agent, $ship['nav']['waypointSymbol'], $arguments['maxTotal']);
        if ($homeward) {
            $auth = $mission->authorization();
            if ($mission->state !== 'returning' || $path !== $auth['remaining_path'] || $arguments['maxTotal'] !== ($auth['remaining_caps'][0] ?? null)
                || $auth['remaining_total'] < array_sum($auth['remaining_caps']) || now()->gte($this->deadline($mission))
                || $arguments['reserve'] !== array_sum(array_slice($auth['remaining_caps'], 1))) {
                throw new GameException('Homeward path, remaining cap or deadline needs explicit authorization.');
            }
        } elseif (isset($arguments['recon_outbound_hash'])) {
            $grant = $mission->outbound();
            if (! $grant || $grant['hash'] !== $arguments['recon_outbound_hash'] || $grant['status'] === 'expired'
                || ($grant['intent_id'] !== null && $grant['intent_id'] !== $intentId)
                || ($grant['intent_id'] !== null && (! $intent || $intent->arguments !== $arguments))
                || $homeward || $mission->state !== 'exploring' || $path !== [$grant['from'], $grant['target']]
                || $arguments['waypointSymbol'] !== $grant['target_gate'] || $ship['nav']['waypointSymbol'] !== $grant['from_gate']
                || $arguments['maxTotal'] !== $grant['cap'] || $arguments['recon_return_path'] !== $grant['path']) {
                throw new GameException('Outbound grant does not authorize this exact intent.');
            }
            $this->validateOutboundRoutes($mission, $grant, $system);
            $funding = $this->outboundFunding($agent, $mission, $grant['cap'], $grant['path'], $intentId);
            if ($arguments['recon_inflight_exposure'] !== $funding['protected_return']
                || ! is_int($arguments['recon_commitments'] ?? null)
                || $arguments['reserve'] !== $mission->terms['cash_floor'] + $arguments['recon_commitments'] + $funding['protected_return']
                || $agent->credits - $grant['cap'] < max($arguments['reserve'], $funding['reserve'])) {
                throw new GameException('Outbound live cash or persisted protected return reserve changed: '.json_encode($funding));
            }
        } else {
            if (($mission->outbound()['status'] ?? null) === 'available') {
                throw new GameException('Only the sealed outbound edge is authorized while its grant is available.');
            }
            $R = count($path) - 1;
            $home = $mission->path($arguments['recon_target'], $mission->origin_system);
            if (! $home) {
                throw new GameException('No verified return path.');
            }
            $H = count($home) - 1;
            $cap = $mission->terms['jump_exposure'];
            $ledger = $mission->ledger($intentId);
            if ($arguments['maxTotal'] !== $cap || $mission->terms['envelope'] < $ledger['spent'] + $ledger['unknown'] + $cap * ($R + $H)
                || now()->gte($mission->terms['explore_until']) || ! $this->timeFits($mission, $R + $H)
                || $arguments['reserve'] !== $mission->terms['cash_floor'] + $cap * ($R - 1 + $H)) {
                throw new GameException('Recon envelope, return reservation or absolute time is exhausted.');
            }
        }
        if ($agent->credits - $arguments['maxTotal'] < $arguments['reserve']) {
            throw new GameException('Recon live cash cannot fund the hop and its reserved path.');
        }
    }

    private function requireDepartureQuote(Agent $agent, string $gate, int $cap): void
    {
        $quote = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'market')->where('symbol', $gate)->first();
        $ask = collect($quote?->data['tradeGoods'] ?? [])->firstWhere('symbol', 'ANTIMATTER');
        if (! $quote || $quote->observed_at->lt(now()->subSeconds(config('gameplay.snapshot_seconds'))) || ! empty($quote->data['invalidated_by_action'])
            || ! $ask || $ask['purchasePrice'] < 1 || $ask['purchasePrice'] > $cap) {
            throw new GameException('Recon departure quote is stale, missing or above the authorized cap.');
        }
    }

    private function marketRead(Agent $agent, ReconMission $mission, string $system, string $market, bool $confirmation): void
    {
        $data = $this->api->market($agent, $market);
        $quote = $this->sync->observe($agent, 'market', $market, $data, 'read', $mission->ship_symbol);
        $visits = $mission->visits;
        $visit = $visits[$system] ?? [];
        $sample = $visit['markets'][$market] ?? [];
        $sample['first_at'] ??= now()->toIso8601String();
        $sample['observation_id'] = $quote->id;
        $sample['observed_at'] = $quote->observed_at->toIso8601String();
        $sample['data'] = $data;
        if ($confirmation) {
            $sample['confirmed_at'] = now()->toIso8601String();
        }
        $visit['markets'][$market] = $sample;
        $visit['reads'] = ($visit['reads'] ?? 0) + 1;
        $visit['refresh_queue'] = array_values(array_diff($visit['refresh_queue'] ?? [], [$market]));
        $visits[$system] = $visit;
        $this->transition($mission, ['visits' => $visits]);
    }

    private function sample(Agent $agent, ReconMission $mission, array $ship): ?AutomationStep
    {
        $system = $ship['nav']['systemSymbol'];
        $visits = $mission->visits;
        $visit = $visits[$system] ?? [];
        $terminal = ['no_markets', 'below_bar', 'no_viable_pair', 'evidence_incomplete', 'promising'];
        if (in_array($visit['classification'] ?? null, $terminal, true) || ! empty($visit['budget_spent'])) {
            return null;
        }
        if (empty($visit['arrival'])) {
            $visit += ['arrival' => now()->toIso8601String(), 'reads' => 0, 'markets' => [], 'retries' => 0, 'arrivals' => []];
            $visit['arrivals'][] = now()->toIso8601String();
            $visits[$system] = $visit;
            $this->transition($mission, ['visits' => $visits]);
        }
        $gate = $mission->graph[$system]['gate'];
        if (empty($visit['gate_read'])) {
            if ($step = $this->travel($agent, $ship, $gate)) {
                return $step;
            }
            $this->marketRead($agent, $mission, $system, $gate, false);
            $this->readGate($agent, $mission, $system, true);
            $visits = $mission->visits;
            $visits[$system]['gate_read'] = true;
            $this->transition($mission, ['visits' => $visits]);

            return AutomationStep::wait(now()->toImmutable());
        }
        $catalogue = $mission->graph[$system]['catalogue'];
        $markets = array_values(array_diff($catalogue['marketplaces'], [$gate]));
        $unsampled = array_values(array_diff($markets, array_keys($visit['markets'])));
        $evaluation = app(ReconProjection::class)->evaluate($agent, $mission, $system);
        $visit['evaluation'] = $evaluation;
        $visit['unsampled'] = $unsampled;
        $classification = $evaluation['classification'];
        $H = count($mission->path($system, $mission->origin_system) ?? []) - 1;
        $leaveBy = min(CarbonImmutable::parse($visit['arrival'])->addMinutes($mission->terms['max_dwell_minutes']),
            $this->deadline($mission)->subSeconds(($this->cooldownEstimate($mission) + 60) * max(0, $H) + 900));
        $room = $visit['reads'] < $mission->terms['market_reads_per_visit'] - 1 && now()->lt($leaveBy);
        $target = null;
        $confirmation = false;
        $waitUntil = null;
        if ($room && ! $unsampled && $evaluation['qualifying']) {
            foreach ($evaluation['qualifying'] as $pair) {
                if ($pair['confirmed']) {
                    $classification = 'promising';
                    break;
                }
                foreach ([$pair['source'], $pair['buyer']] as $market) {
                    $sample = $visit['markets'][$market] ?? [];
                    if (! empty($sample['confirmed_at']) && CarbonImmutable::parse($sample['confirmed_at'])->gte(now()->subMinutes($mission->terms['qualify']['confirm_max_age_min']))) {
                        continue;
                    }
                    $ready = CarbonImmutable::parse($sample['first_at'])->addMinutes(15);
                    if ($ready->gt($leaveBy)) {
                        continue;
                    }
                    $target = $market;
                    $confirmation = true;
                    $waitUntil = $ready->isFuture() ? $ready : null;
                    break 2;
                }
            }
        }
        if ($classification !== 'promising' && $target === null && $room) {
            $targets = array_values(array_unique([...$unsampled, ...($visit['refresh_queue'] ?? [])]));
            usort($targets, function ($a, $b) use ($agent, $ship) {
                return app(FuelPlanner::class)->leg($agent, $ship, $a)['estimated_seconds'] <=> app(FuelPlanner::class)->leg($agent, $ship, $b)['estimated_seconds'] ?: $a <=> $b;
            });
            $target = $targets[0] ?? null;
        }
        if ($classification !== 'promising' && $target !== null) {
            $travel = app(FuelPlanner::class)->leg($agent, $ship, $target)['estimated_seconds'];
            $there = $ship;
            $there['nav']['waypointSymbol'] = $target;
            $back = app(FuelPlanner::class)->leg($agent, $there, $gate)['estimated_seconds'];
            if (max(now()->timestamp + $travel, $waitUntil?->timestamp ?? 0) + $back + 30 > $leaveBy->timestamp) {
                $room = false;
                $target = null;
            }
        }
        $visit['classification'] = $classification;
        if ($classification !== 'promising' && ($target === null || ! $room)) {
            if (! $catalogue['complete'] || $unsampled || in_array($classification, ['unconfirmed', 'evidence_expired', 'route_unmeasured', 'evidence_incomplete'], true)) {
                $visit['budget_spent'] = true;
                $version = ReconMission::hash(array_map(fn ($sample) => $sample['data']['tradeGoods'] ?? [], $visit['markets']));
                if (($visit['evidence_version'] ?? null) !== $version) {
                    $visit['retries'] = 0;
                    $visit['evidence_version'] = $version;
                }
                if (($visit['retries'] ?? 0) >= $mission->terms['evaluation_retry_limit']) {
                    $visit['classification'] = 'evidence_incomplete';
                } else {
                    $visit['classification'] = $classification === 'route_unmeasured' ? 'route_unmeasured' : 'evidence_expired';
                    $visit['retry_at'] = now()->addSeconds($mission->terms['evaluation_retry_seconds'])->toIso8601String();
                }
            }
        }
        $visits = $mission->visits;
        $visits[$system] = $visit;
        $this->transition($mission, ['visits' => $visits]);
        if ($classification === 'promising' || ! $target || ! $room) {
            return null;
        }
        if ($step = $this->travel($agent, $ship, $target)) {
            return $step;
        }
        if ($waitUntil) {
            return AutomationStep::wait($waitUntil);
        }
        $this->marketRead($agent, $mission, $system, $target, $confirmation);

        return AutomationStep::wait(now()->toImmutable());
    }

    private function retryVisit(ReconMission $mission, string $system): void
    {
        $visits = $mission->visits;
        $visit = $visits[$system];
        $visit['retries'] = ($visit['retries'] ?? 0) + 1;
        $visit['budget_spent'] = false;
        $visit['arrival'] = now()->toIso8601String();
        $visit['arrivals'][] = now()->toIso8601String();
        $visit['reads'] = 0;
        $visit['refresh_queue'] = ! empty($visit['unsampled']) ? [] : array_values(array_diff(array_keys($visit['markets'] ?? []), [$mission->graph[$system]['gate']]));
        $visit['gate_read'] = false;
        $visit['classification'] = 'partial';
        $visits[$system] = $visit;
        $this->transition($mission, ['visits' => $visits]);
    }

    public function complete(AutomationRun $run): void
    {
        $totals = $run->fresh()->totals;
        $totals['cash_contribution'] = $totals['credit_delta'];
        $totals['elapsed_seconds'] = (int) $run->started_at->diffInSeconds(now());
        $run->update(['state' => RunState::Completed, 'active_agent_id' => null, 'totals' => $totals,
            'blocked_reason' => 'Reconnaissance complete. No earnings were assumed.', 'next_action_at' => null]);
        app(CycleAccounting::class)->record($run, Ship::where('agent_id', $run->agent_id)->where('symbol', $run->ship_symbol)->first()?->snapshot);
    }

    private function rankingKey(ReconMission $mission, string $target, int $class, int $hops): array
    {
        return [$class, $hops, -count($mission->graph[$target]['catalogue']['marketplaces'] ?? []), $target];
    }

    private function verifiedRoute(ReconMission $mission, array $path, string $from): bool
    {
        if (! $path || $path[0] !== $from || end($path) !== $mission->origin_system || count(array_unique($path)) !== count($path)) {
            return false;
        }
        foreach (array_slice($path, 1) as $index => $system) {
            if (! $mission->edge($path[$index], $system)) {
                return false;
            }
        }

        return true;
    }

    private function validateOutboundRoutes(ReconMission $mission, array $grant, string $current): void
    {
        $saved = $mission->authorization();
        if ($current !== $grant['from'] || ! $mission->edge($current, $grant['target'])
            || $mission->graph[$current]['gate'] !== $grant['from_gate'] || $mission->graph[$grant['target']]['gate'] !== $grant['target_gate']
            || $grant['target'] === $mission->origin_system || isset($mission->visits[$grant['target']])
            || ! $this->verifiedRoute($mission, $saved['remaining_path'], $current)
            || count($saved['remaining_caps']) !== count($saved['remaining_path']) - 1 || $saved['remaining_total'] < $saved['exposure']
            || ! $this->verifiedRoute($mission, $grant['path'], $grant['target']) || in_array($current, $grant['path'], true)
            || count($grant['path']) >= count($saved['remaining_path'])
            || $grant['caps'] !== array_fill(0, count($grant['path']) - 1, $mission->terms['jump_exposure'])
            || ! $this->timeFits($mission, count($grant['path']))) {
            throw new GameException('Sealed outbound edge, saved/prospective return path, eligibility or time no longer fits.');
        }
    }

    private function outboundFunding(Agent $agent, ReconMission $mission, int $cap, array $path, ?int $intentId = null, bool $requireFunded = true): array
    {
        $commitments = $this->liveCommitments($agent);
        $protected = max($mission->authorization()['exposure'], $mission->terms['jump_exposure'] * (count($path) - 1));
        $required = $cap + $protected;
        $facts = ['credits' => $agent->credits, 'commitments' => $commitments, 'protected_return' => $protected,
            'required' => $required, 'ledger_remaining' => $mission->ledger($intentId)['remaining'],
            'cash_available' => $agent->credits - $mission->terms['cash_floor'] - $commitments,
            'reserve' => $mission->terms['cash_floor'] + $commitments + $protected];
        $facts['envelope_fits'] = $facts['ledger_remaining'] >= $required;
        $facts['cash_fits'] = $facts['cash_available'] >= $required;
        if ($requireFunded && (! $facts['envelope_fits'] || ! $facts['cash_fits'])) {
            throw new GameException('Outbound cash/envelope cannot cover cap and protected return: '.json_encode($facts));
        }

        return $facts;
    }

    public function authorizeOutbound(ReconMission $mission, User $owner, ?string $target, int $cap, string $expectedBasis, bool $dryRun = false): array
    {
        $agent = Agent::findOrFail($mission->agent_id);

        return $this->locked($agent, function () use ($agent, $mission, $owner, $target, $cap, $expectedBasis, $dryRun) {
            $agent->refresh();
            $mission->refresh();
            $authority = clone $mission;
            $basis = $mission->outboundBasis();
            if ($owner->id !== $agent->user_id || $owner->id !== $mission->approved_by || $cap <= $mission->terms['jump_exposure'] || $cap > 20000
                || (! $dryRun && ($target === null || $target === ''))) {
                throw new GameException('Outbound approval requires the owning operator, exact target and an integer cap above baseline and at most 20000.');
            }
            // Replay is an identity lookup, not a new approval or lifecycle operation.
            foreach ($mission->outbound_authorizations ?? [] as $record) {
                if ($record['prior_basis'] === $expectedBasis) {
                    if ($record['user_id'] !== $owner->id || $record['target'] !== $target || $record['cap'] !== $cap) {
                        throw new GameException('Stale outbound basis was applied to a different approval identity.');
                    }
                    $intent = $mission->actions()->where('arguments->recon_outbound_hash', $record['hash'])->orderBy('id')->first();

                    return ['record' => $record, 'replayed' => true, 'intent_id' => $intent?->id, 'intent_state' => $intent?->state->value,
                        'status' => $record['hash'] === $basis ? $mission->outbound()['status'] : 'superseded'];
                }
            }
            $this->enabled();
            if (! config('gameplay.enabled') || $basis !== $expectedBasis || $mission->closed_at || $mission->reset_id !== $agent->reset_id
                || $mission->state !== 'exploring' || $mission->homeward_authorizations || now()->gte($mission->terms['explore_until'])
                || now()->gte($mission->terms['return_deadline']) || ($mission->outbound()['status'] ?? null) === 'available') {
                throw new GameException('Outbound approval requires a fresh basis and open same-reset exploring mission with no unused grant or rescue.');
            }
            if ($agent->isArchived() || $this->api->status() !== $mission->reset_id) {
                throw new GameException('World reset detected. Outbound approval cannot change this mission or its run.');
            }
            $ship = $this->sync->refreshShip($agent, $mission->ship_symbol)->snapshot;
            $agent->update(['credits' => $this->api->agent($agent)['credits']]);
            $busy = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                ->where(fn ($q) => $q->where(fn ($q) => $q->whereIn('state', ['pending', 'sending', 'uncertain'])
                    ->where(fn ($q) => $q->where('ship_symbol', $mission->ship_symbol)->orWhereNull('ship_symbol')))
                    ->orWhere(fn ($q) => ShipCommandExecutor::reviewFor($q, $mission->ship_symbol)))->exists();
            if ($busy || $mission->actions()->where(fn ($q) => $q->whereIn('state', ['pending', 'sending', 'uncertain'])->orWhere('review_required', true)
                ->orWhere(fn ($q) => $q->where('command', 'jump')->where('state', 'acknowledged')->whereNull('outcome->transaction_total')))->exists()
                || AutomationRun::where('active_agent_id', $agent->id)->where('ship_symbol', $mission->ship_symbol)
                    ->whereNotIn('id', $mission->runs()->select('id'))->exists()
                || $ship['nav']['status'] === 'IN_TRANSIT' || MiningPlanner::inventory($ship) !== []
                || OpportunityEvaluator::equipment($ship) !== $mission->terms['equipment'] || $this->rejectedEquipment($agent, $ship)
                || (app(CreditAccounting::class)->summary($agent)['unexplained_change'] ?? 0) !== 0) {
                throw new GameException('Probe must be stationary, settled, empty, unchanged and free of conflicting work; reconcile first.');
            }
            $current = $ship['nav']['systemSymbol'];
            if ($ship['nav']['waypointSymbol'] !== ($mission->graph[$current]['gate'] ?? null)) {
                throw new GameException('Probe must be at its authorized departure gate.');
            }
            // Refresh route endpoints through the ordinary metered read paths. Work is bounded;
            // successful catalogue pages remain available for a repeated dry run after deferral.
            $reads = 0;
            $refreshed = [];
            $refresh = function (array $systems) use ($agent, $mission, &$reads, &$refreshed): void {
                foreach (array_unique($systems) as $system) {
                    if (isset($refreshed[$system])) {
                        continue;
                    }
                    if ($reads + 2 > 40) {
                        throw new GameException('Outbound evidence read limit reached; rerun dry-run after the normal read budget recovers.');
                    }
                    $this->readGate($agent, $mission, $system, false);
                    $this->readGate($agent, $mission, $system, true);
                    $reads += 2;
                    $refreshed[$system] = true;
                }
            };
            $refresh([$current]);
            $neighbors = array_map(fn ($gate) => implode('-', array_slice(explode('-', $gate), 0, 2)), $mission->graph[$current]['connections'] ?? []);
            $refresh($neighbors);
            $saved = $mission->authorization();
            $refresh($saved['remaining_path']);
            if (! $this->verifiedRoute($mission, $saved['remaining_path'], $current)) {
                throw new GameException('Saved exact return path is broken; operator review is required.');
            }
            $candidates = [];
            foreach ($neighbors as $candidate) {
                if ($candidate === $mission->origin_system || isset($mission->visits[$candidate]) || ! $mission->edge($current, $candidate)) {
                    continue;
                }
                $path = $mission->path($candidate, $mission->origin_system, [$current]);
                if (! $path || count($path) >= count($saved['remaining_path'])) {
                    continue;
                }
                $refresh($path);
                if (! $this->verifiedRoute($mission, $path, $candidate)) {
                    throw new GameException('Prospective return path changed; rerun dry-run with refreshed evidence.');
                }
                for (; $reads < 40 && $this->catalogueStep($agent, $mission, $candidate); $reads++) {
                    // Shared provider read budget also applies to each catalogue page.
                }
                if (! ($mission->graph[$candidate]['catalogue']['complete'] ?? false)) {
                    throw new GameException('Candidate catalogue incomplete for '.$candidate.'; rerun dry-run or review the original catalogue page limit.');
                }
                $candidates[] = ['system' => $candidate, 'path' => $path, 'key' => $this->rankingKey($mission, $candidate, 1, 1)];
            }
            usort($candidates, fn ($a, $b) => $a['key'] <=> $b['key']);
            $selected = $candidates[0] ?? throw new GameException('No eligible unvisited neighbor has a verified shorter return avoiding the departure.');
            if ($target !== null && $target !== $selected['system']) {
                throw new GameException('Requested target is not the highest-ranked eligible neighbor: '.$selected['system']);
            }
            $record = ['terms_hash' => $mission->terms_hash, 'prior_basis' => $basis, 'user_id' => $owner->id, 'at' => now()->toIso8601String(),
                'cursor' => (int) ($mission->actions()->max('id') ?? 0), 'from' => $current, 'from_gate' => $mission->graph[$current]['gate'],
                'target' => $selected['system'], 'target_gate' => $mission->graph[$selected['system']]['gate'], 'cap' => $cap,
                'reset_id' => $mission->reset_id, 'until' => $mission->terms['explore_until'], 'path' => $selected['path'],
                'caps' => array_fill(0, count($selected['path']) - 1, $mission->terms['jump_exposure'])];
            $ship = $this->sync->refreshShip($agent, $mission->ship_symbol)->snapshot;
            $agent->update(['credits' => $this->api->agent($agent)['credits']]);
            if ($ship['nav']['status'] === 'IN_TRANSIT' || $ship['nav']['waypointSymbol'] !== $record['from_gate']
                || MiningPlanner::inventory($ship) !== [] || OpportunityEvaluator::equipment($ship) !== $mission->terms['equipment']
                || (app(CreditAccounting::class)->summary($agent)['unexplained_change'] ?? 0) !== 0
                || now()->gte($record['until'])) {
                throw new GameException('Probe, balance or exploration cutoff changed during approval reads.');
            }
            $this->validateOutboundRoutes($mission, $record, $ship['nav']['systemSymbol']);
            $record['funding'] = $this->outboundFunding($agent, $mission, $cap, $record['path'], requireFunded: ! $dryRun);
            $record['hash'] = ReconMission::hash($record);
            if (! $dryRun) {
                DB::transaction(function () use ($mission, $authority, $record) {
                    // Observation transitions refresh $mission. Keep the lock-entry raw
                    // authority snapshot for this append's conditional update.
                    $this->transition($authority, ['outbound_authorizations' => [...($authority->outbound_authorizations ?? []), $record], 'hold' => null]);
                    foreach ($mission->runs()->whereNotNull('active_agent_id')->get() as $old) {
                        app(AutomationRunner::class)->finishStop($old, 'Outbound authority approved. Use recon:start for a fresh run.');
                    }
                });
                $mission->refresh();
            }

            return ['record' => $record, 'candidates' => $candidates, 'selected' => $selected['system'], 'dry_run' => $dryRun,
                'status' => $dryRun ? 'proposed' : 'available'];
        });
    }

    private function departOutbound(Agent $agent, ReconMission $mission, array $ship, AutomationRun $run, array $grant): AutomationStep
    {
        try {
            $this->validateOutboundRoutes($mission, $grant, $ship['nav']['systemSymbol']);
            $funding = $this->outboundFunding($agent, $mission, $grant['cap'], $grant['path']);
            if ($ship['nav']['waypointSymbol'] !== $grant['from_gate'] || MiningPlanner::inventory($ship) !== []
                || OpportunityEvaluator::equipment($ship) !== $mission->terms['equipment']
                || (app(CreditAccounting::class)->summary($agent)['unexplained_change'] ?? 0) !== 0) {
                throw new GameException('Sealed departure, balance, cargo or equipment changed.');
            }
            if ($ship['nav']['status'] === 'DOCKED') {
                return AutomationStep::act('orbit');
            }
            $quote = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'market')->where('symbol', $grant['from_gate'])->first();
            $ask = collect($quote?->data['tradeGoods'] ?? [])->firstWhere('symbol', 'ANTIMATTER');
            $cadence = $ask && $ask['purchasePrice'] > $grant['cap'] ? $mission->terms['ask_recheck_seconds'] : config('gameplay.snapshot_seconds');
            if (! $quote || ! empty($quote->data['invalidated_by_action']) || $quote->observed_at->lte(now()->subSeconds($cadence))) {
                $this->marketRead($agent, $mission, $grant['from'], $grant['from_gate'], false);

                return AutomationStep::wait(now()->toImmutable());
            }
            if (! $ask || $ask['purchasePrice'] < 1 || $ask['purchasePrice'] > $grant['cap']) {
                throw new GameException('Sealed outbound ask is missing or above the approved cap.');
            }
            if (now()->lt($ship['cooldown']['readyAt'])) {
                return AutomationStep::wait(CarbonImmutable::parse($ship['cooldown']['readyAt']));
            }
            $arguments = ['waypointSymbol' => $grant['target_gate'], 'maxTotal' => $grant['cap'], 'reserve' => $funding['reserve'],
                'recon_homeward' => false, 'recon_path' => [$grant['from'], $grant['target']], 'recon_target' => $grant['target'],
                'recon_outbound_hash' => $grant['hash'], 'recon_return_path' => $grant['path'],
                'recon_commitments' => $funding['commitments'], 'recon_inflight_exposure' => $funding['protected_return']];
            $run->update(['plan' => array_replace($run->plan, ['recon_leg' => $arguments])]);
            $this->authorizeJump($agent, $run, $ship, $mission->graph[$grant['from']], $mission->graph[$grant['target']], $arguments);

            return AutomationStep::act('jump', $arguments);
        } catch (RequestDeferred $e) {
            throw $e;
        } catch (GameException $e) {
            return $this->hold($mission, 'outbound_wait', now()->toImmutable()->addSeconds($mission->terms['cash_wait_seconds']), cause: ['detail' => $e->getMessage()]);
        }
    }

    public function validateOutboundIntent(AutomationRun $run, ShipAction $action): void
    {
        if (! isset($action->arguments['recon_outbound_hash'])) {
            return;
        }
        $agent = Agent::findOrFail($run->agent_id);
        $mission = $this->mission($agent, $run);
        $grant = $mission->outbound();
        if (! $grant || $grant['status'] !== 'bound/consumed' || $grant['intent_id'] !== $action->id
            || ! in_array($grant['intent_state'], [ActionState::Pending->value, ActionState::Sending->value], true)
            || ($run->plan['recon_intent_id'] ?? null) !== $action->id || ($run->plan['recon_leg'] ?? null) !== $action->arguments) {
            throw new GameException('Only the exact bound outbound intent may send; fresh operator approval is required.');
        }
        $this->requireDepartureQuote($agent, $grant['from_gate'], $grant['cap']);
        $now = now();
        if ($now->gte($action->expires_at) || $now->gte($run->expires_at) || $now->gte($grant['until']) || $now->gte($mission->terms['return_deadline'])) {
            throw new GameException('Outbound intent or authority expired before transport. Fresh operator approval is required.');
        }
    }

    private function liveCommitments(Agent $agent): int
    {
        $commitments = 0;
        foreach (AutomationRun::where('active_agent_id', $agent->id)->where('strategy', '!=', 'gate_recon')->get() as $other) {
            if ($other->campaign_episode_id) {
                $carrying = Ship::where('agent_id', $agent->id)->where('symbol', $other->ship_symbol)->first()?->snapshot;
                if (($carrying['cargo']['units'] ?? 0) > 0 && isset($other->plan['market'])) {
                    $commitments += app(CampaignTrade::class)->fuelReserve($agent, $other->ship_symbol, $other->plan['market']);
                }
            } else {
                $commitments += $other->plan['reserve'] + max(0, $other->plan['max_spend'] - ($other->totals['spending'] ?? 0));
            }
        }

        return $commitments;
    }

    public function authorizeReturn(ReconMission $mission, User $user, int $cap, int $total, CarbonImmutable $until, bool $dryRun = false): array
    {
        $agent = Agent::findOrFail($mission->agent_id);

        return $this->locked($agent, function () use ($agent, $mission, $user, $cap, $total, $until, $dryRun) {
            $this->enabled();
            $mission->refresh();
            $this->sync->guardReset($agent);
            $ship = $this->sync->refreshShip($agent, $mission->ship_symbol)->snapshot;
            $agent->update(['credits' => $this->api->agent($agent)['credits']]);
            $this->reconcileSettlement($mission);
            app(ShipCommandExecutor::class)->clear($agent, defer: true, ship: $mission->ship_symbol);
            if ($mission->closed_at || $mission->reset_id !== $agent->reset_id || $user->id !== $agent->user_id || $cap < 1 || $total < 1
                || $until->isPast() || $until->gt(CarbonImmutable::parse($mission->terms['next_reset'])->subMinutes(30))
                || $mission->actions()->whereIn('state', ['pending', 'sending', 'uncertain'])->exists()) {
                throw new GameException('Return approval requires an open same-reset settled mission, operator and bounded deadline.');
            }
            $current = $ship['nav']['systemSymbol'];
            $this->readGate($agent, $mission, $current, false);
            $this->readGate($agent, $mission, $current, true);
            $this->readGate($agent, $mission, $mission->origin_system, false);
            $this->readGate($agent, $mission, $mission->origin_system, true);
            for ($reads = 0; $reads < 20 && $this->graphStep($agent, $mission, $current); $reads++) {
                // Every successful observation is durable before a request-budget deferral.
            }
            // Refresh every endpoint on the existing home path before approving its replacement.
            $path = $mission->path($current, $mission->origin_system);
            if (! $path || count($path) < 2) {
                throw new GameException('A verified homeward path is required.');
            }
            foreach ($path as $system) {
                $this->readGate($agent, $mission, $system, false);
                $this->readGate($agent, $mission, $system, true);
            }
            $path = $mission->path($current, $mission->origin_system);
            if (! $path || $total < $cap * (count($path) - 1)) {
                throw new GameException('Return exposure does not cover the verified path.');
            }
            $commitments = $this->liveCommitments($agent);
            if ($agent->credits - $total - $commitments < $mission->terms['cash_floor']) {
                throw new GameException('Return approval must preserve the original cash floor and other live commitments.');
            }
            $record = ['user_id' => $user->id, 'at' => now()->toIso8601String(), 'cursor' => (int) ($mission->actions()->max('id') ?? 0),
                'path' => $path, 'caps' => array_fill(0, count($path) - 1, $cap), 'total' => $total, 'cash_floor' => $mission->terms['cash_floor'], 'until' => $until->toIso8601String()];
            $record['hash'] = ReconMission::hash($record);
            if (! $dryRun) {
                $this->transition($mission, ['homeward_authorizations' => [...$mission->homeward_authorizations, $record], 'state' => 'returning',
                    'outcome' => $this->returnOutcome($mission), 'hold' => null]);
                foreach ($mission->runs()->whereNotNull('active_agent_id')->get() as $oldRun) {
                    app(AutomationRunner::class)->finishStop($oldRun, 'Homeward authority approved. Use recon:start for a new run with the remaining return limit.');
                }
            }

            return $record;
        });
    }

    public function status(ReconMission $mission): array
    {
        $agent = Agent::findOrFail($mission->agent_id);
        $ship = Ship::where('agent_id', $agent->id)->where('symbol', $mission->ship_symbol)->first();
        $abroad = $ship && $ship->snapshot['nav']['systemSymbol'] !== $mission->origin_system;
        $outbound = $mission->outbound();
        $homeward = $mission->state === 'returning' ? $mission->authorization() : null;
        $exceptional = ! $homeward && $outbound && ($outbound['status'] === 'available'
            || ($outbound['status'] === 'bound/consumed' && in_array($outbound['intent_state'], ['pending', 'sending'], true)));

        return ['mission' => $mission->id, 'state' => $mission->state, 'outcome' => $mission->outcome, 'terms_hash' => $mission->terms_hash,
            'outbound_basis' => $mission->outboundBasis(), 'outbound' => $outbound,
            'outbound_authorizations' => array_map(fn ($record) => $record + ['status' => $record['hash'] === $outbound['hash'] ? $outbound['status'] : 'superseded'], $mission->outbound_authorizations ?? []),
            'active_jump_cap' => $homeward ? ($homeward['remaining_caps'][0] ?? 0) : ($exceptional ? $outbound['cap'] : $mission->terms['jump_exposure']),
            'jump_cap_source' => $homeward ? ($homeward['hash'] ?? $mission->terms_hash) : ($exceptional ? $outbound['hash'] : $mission->terms_hash), 'baseline_jump_cap' => $mission->terms['jump_exposure'],
            'terms' => $mission->terms, 'credits' => $agent->credits, 'ledger' => $mission->ledger(), 'return_exposure' => $mission->exposure(),
            'hold' => $abroad && ! config('gameplay.gate_recon_enabled') ? ['reason' => 'flag_off_abroad'] : $mission->hold,
            'abroad_without_run' => $abroad && ! $mission->runs()->whereNotNull('active_agent_id')->exists(), 'visits' => $mission->visits, 'graph' => $mission->graph,
            'review_effect' => 'Acknowledging a known overcharge stops ALL active agent runs. C0 then needs a fresh preview or cargo recovery.'];
    }
}
