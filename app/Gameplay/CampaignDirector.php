<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\CampaignSample;
use App\Models\GameObservation;
use App\Models\OpportunityInvestigation;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use App\Services\SpaceTradersClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CampaignDirector
{
    /**
     * Wall-clock budget for one decision's SpaceTraders requests. The shared request budget paces
     * calls about one per second, and a trade or cargo-recovery decision re-syncs the agent and ships
     * and then reads several markets. At 12 seconds those decisions ran out of time on every tick,
     * so the campaign waited forever without starting a run.
     */
    public const DEADLINE_SECONDS = 45;

    /** The arm grant tickArms() picked this pass, for the scheduler's per-arm failure counter. */
    public ?int $armGrantId = null;

    /** The ranked candidate behind the run trade() returned, claimed once that run starts. */
    private ?array $candidate = null;

    public function tick(): bool
    {
        $attempted = false;
        try {
            app(HostLock::class)->run('campaign', function () use (&$attempted) {
                $control = app(CampaignControl::class);
                $control->activatePending();
                $grant = CampaignGrant::where('slot', 1)->first();
                if (! $grant || $grant->paused_at || ($grant->last_directed_at && $grant->last_directed_at->gt(now()->subSeconds(30)))) {
                    return;
                }
                $attempted = true;
                try {
                    $this->directGrant($grant);
                } finally {
                    $grant->update(['last_directed_at' => now()]);
                }
            });
        } catch (GameException) {
        }

        return $attempted;
    }

    /**
     * Directs one live, unpaused arm grant per pass, least recently directed first. That rotation is
     * also the claim priority rotation between arms.
     */
    public function tickArms(): bool
    {
        $this->armGrantId = null;
        try {
            app(HostLock::class)->run('campaign', function () {
                app(CampaignTrade::class)->harvestArms();
                $grant = CampaignGrant::liveArms()->whereNull('paused_at')
                    ->where(fn ($query) => $query->whereNull('last_directed_at')->orWhere('last_directed_at', '<=', now()->subSeconds(30)))
                    ->orderByRaw('last_directed_at is not null')->orderBy('last_directed_at')->orderBy('id')->first();
                if (! $grant) {
                    return;
                }
                $this->armGrantId = $grant->id;
                try {
                    $this->directGrant($grant);
                } finally {
                    $grant->update(['last_directed_at' => now()]);
                }
            });
        } catch (GameException) {
        }

        return $this->armGrantId !== null;
    }

    private function directGrant(CampaignGrant $grant): void
    {
        $control = app(CampaignControl::class);
        $grant->update(['last_directed_at' => now()]);
        if ($grant->expires_at?->isPast()) {
            $control->pause($grant, 'grant_expired');

            return;
        }
        try {
            if (! app(EngineGate::class)->enabled()) {
                $this->wait($grant, 'awaiting_engine');

                return;
            }
            app(EngineGate::class)->session();
        } catch (GameException) {
            $this->wait($grant, 'awaiting_engine');

            return;
        }
        if (! config('gameplay.enabled')) {
            $this->wait($grant, 'awaiting_automation');

            return;
        }
        try {
            app(SpaceTradersClient::class)->scope(fn () => $this->direct($grant), deadline: microtime(true) + self::DEADLINE_SECONDS);
        } catch (ValidationException) {
            $control->pause($grant, 'registration_requires_review');
        } catch (GameException $exception) {
            if ($exception->retryAfter > 0) {
                $this->wait($grant, 'awaiting_fresh_state');
            } else {
                $control->pause($grant, 'state_requires_review');
            }
        }
    }

    private function wait(CampaignGrant $grant, string $reason): void
    {
        $grant->update(['wait_reason' => $reason]);
        app(CampaignControl::class)->event($grant, null, 'wait', ['reason' => $reason]);
    }

    private function direct(CampaignGrant $grant): void
    {
        $schedule = app(SpaceTradersApi::class)->resetSchedule();
        $previous = CampaignEpisode::where('campaign_grant_id', $grant->id)->whereNull('closed_at')->first();
        try {
            $next = is_string($schedule['next_reset']) ? CarbonImmutable::parse($schedule['next_reset']) : null;
            $resetDate = CarbonImmutable::parse($schedule['reset_id']);
        } catch (\Throwable) {
            $next = null;
            $resetDate = null;
        }
        if (! $next || ! $resetDate || $resetDate->isFuture() || $next->isPast() || ($previous && $previous->reset_id === $schedule['reset_id'] && $previous->next_reset_at->isPast())) {
            $this->wait($grant, 'awaiting_reset_schedule');

            return;
        }
        $user = User::findOrFail($grant->user_id);
        $spec = $grant->revision()->body['agent'];
        $arm = $grant->arm();
        $agent = $user->agents()->where('name', $spec['symbol'])->where('reset_id', $schedule['reset_id'])->unarchived()->first();
        if (! $arm) {
            app(AgentSynchronizer::class)->archivePreviousWorlds($user->agents()->unarchived()->whereNotNull('reset_id')->get(), $schedule['reset_id']);
        }
        CampaignEpisode::where('campaign_grant_id', $grant->id)->whereNull('closed_at')->where('reset_id', '!=', $schedule['reset_id'])->update(['closed_at' => now()]);
        if (! $agent && $arm) {
            // Arms never register or archive worlds; C0 owns the agent's lifecycle.
            $this->wait($grant, 'awaiting_agent');

            return;
        }
        if (! $agent) {
            $intent = CampaignEvent::where('kind', 'registration_intent')->where('data->user_id', $user->id)->where('data->reset_id', $schedule['reset_id'])->latest('id')->first();
            $attempt = $intent?->data['attempt'] ?? 1;
            if ($intent && ($intent->data['outcome'] ?? null) !== 'not_sent') {
                if (($intent->data['attempt'] ?? 1) >= 2 || ($intent->data['outcome'] ?? null) === 'conflict') {
                    app(CampaignControl::class)->pause($grant, 'registration_unknown');

                    return;
                }
                $retryAt = CarbonImmutable::parse($intent->data['retry_at'] ?? $intent->created_at->addSeconds(60)->toIso8601String());
                if ($retryAt->isFuture()) {
                    $this->wait($grant, 'awaiting_registration');

                    return;
                }
                $attempt = 2;
            }
            $token = $user->savedAccountToken();
            if (! $token) {
                app(CampaignControl::class)->pause($grant, 'account_token_missing');

                return;
            }
            app(EngineGate::class)->check(app(EngineGate::class)->session());
            $intent = app(CampaignControl::class)->event($grant, null, 'registration_intent', ['user_id' => $user->id, 'reset_id' => $schedule['reset_id'], 'symbol' => $spec['symbol'], 'attempt' => $attempt]);
            try {
                $agent = app(AgentRegistration::class)->register($user, $spec + ['email' => $user->agents()->latest('id')->value('email')], $token, $schedule['reset_id'], true, $user->getRawOriginal('spacetraders_account_token'), campaign: true);
            } catch (CampaignRegistrationFailure $exception) {
                $intent->update(['data' => array_merge($intent->data, ['outcome' => $exception->outcome, 'retry_at' => now()->addSeconds(60)->toIso8601String()])]);
                if ($exception->outcome === 'conflict' || ($attempt === 2 && $exception->outcome === 'ambiguous')) {
                    app(CampaignControl::class)->pause($grant, 'registration_unknown');
                } else {
                    $this->wait($grant, 'awaiting_registration');
                }

                return;
            }
            app(CampaignControl::class)->event($grant, null, 'registration', ['agent_id' => $agent->id, 'reset_id' => $schedule['reset_id']]);
        }
        if ($arm) {
            app(FleetMetrics::class)->as($arm['ship'], fn () => $this->episode($grant, $agent, $schedule, $next));
        } else {
            $episode = CampaignEpisode::where('campaign_grant_id', $grant->id)->where('reset_id', $agent->reset_id)->latest('id')->first();
            $ship = $episode?->state['role_ships']['command'] ?? Ship::where('agent_id', $agent->id)->get()
                ->first(fn ($ship) => ($ship->snapshot['registration']['role'] ?? null) === 'COMMAND')?->symbol;
            FleetMetrics::as($ship, fn () => $this->episode($grant, $agent, $schedule, $next));
        }
    }

    private function episode(CampaignGrant $grant, Agent $agent, array $schedule, CarbonImmutable $next): void
    {
        $arm = $grant->arm();
        app(HostLock::class)->scoped('agent-'.$agent->id, function () use ($grant, $agent, $schedule, $next, $arm) {
            $episode = CampaignEpisode::where('campaign_grant_id', $grant->id)->where('reset_id', $schedule['reset_id'])->whereNull('closed_at')->first();
            if ($episode && $this->startRetainedPreview($grant, $episode, $agent)) {
                return;
            }
            $sync = app(AgentSynchronizer::class);
            $sync->sync($agent, false);
            $episode = CampaignEpisode::firstOrCreate(['campaign_grant_id' => $grant->id, 'reset_id' => $schedule['reset_id']],
                ['agent_id' => $agent->id, 'start_credits' => $agent->credits, 'phase' => $arm ? 'earn' : $grant->revision()->body['phases'][0]['id'], 'opened_at' => now(), 'next_reset_at' => $next]
                    + ($arm ? ['state' => ['arm' => $arm['label'], 'role_ships' => ['command' => $arm['ship']]]] : []));
            if ($episode->closed_at) {
                app(CampaignControl::class)->pause($grant, 'episode_already_closed', $episode);

                return;
            }
            $grant->update(['wait_reason' => null]);
            $this->sample($episode, $agent);
            app(CampaignTrade::class)->recordResults($episode);
            $reason = app(CampaignSafety::class)->reason($grant, $episode, $agent);
            if ($reason && $reason !== 'consecutive_losing_loads') {
                app(CampaignControl::class)->pause($grant, $reason, $episode);

                return;
            }
            if ($reason === 'consecutive_losing_loads') {
                $state = $episode->state ?? [];
                $state['pending_pause'] = $reason;
                $episode->update(['state' => $state]);
            }
            $run = AutomationRun::where('active_agent_id', $agent->id)->where('campaign_episode_id', $episode->id)->first();
            if ($run) {
                $cargo = Ship::where('agent_id', $agent->id)->where('symbol', $run->ship_symbol)->first()?->snapshot;
                $freshness = str_contains(strtolower($run->blocked_reason ?? ''), 'fresh') || str_contains(strtolower($run->blocked_reason ?? ''), 'stale');
                $handoff = $run->campaign_episode_id && ($run->state->value === 'blocked' || $freshness || $run->stop_requested_at
                    || ($reason && empty($run->plan['sell_only'])) || (app(CampaignSafety::class)->windDown($episode) && empty($run->plan['sell_only'])));
                if ($handoff) {
                    if ($run->state->value === 'blocked' && ! $freshness && $run->strategy === 'scout' && ! empty($run->plan['one_way']) && ($this->role($episode, 'probe')['symbol'] ?? null) === $run->ship_symbol) {
                        $episode->update(['state' => array_merge($episode->state ?? [], ['probe_position_failed' => now()->toIso8601String()])]);
                    }
                    app(AutomationRunner::class)->stop($run);
                    if (($cargo['cargo']['units'] ?? 0) === 0) {
                        app(FleetClaims::class)->release($episode, 'handoff');
                    }
                    if ($freshness) {
                        app(CampaignControl::class)->event($grant, $episode, 'freshness_hold', ['run_id' => $run->id]);
                    }
                    if (! ShipAction::where('automation_run_id', $run->id)->whereIn('state', ['pending', 'sending', 'uncertain'])->exists()
                        && ($cargo['nav']['status'] ?? null) !== 'IN_TRANSIT') {
                        app(AutomationRunner::class)->finishStop($run, 'Campaign handoff: derive a fresh plan or sell aboard.');
                        $run = null;
                    }
                }
                if ($run) {
                    if (! $arm && $episode->phase === 'earn' && ! app(CampaignSafety::class)->windDown($episode)) {
                        $this->discoverIfDue($episode, $agent);
                        $this->refreshParkedMarket($episode, $agent);
                    }
                    $this->decision($grant, $episode, $agent, [], 'awaiting_run');

                    return;
                }
            }
            if (AutomationRun::where('active_agent_id', $agent->id)->whereIn('ship_symbol', app(CampaignFleet::class)->symbols($episode))->exists()) {
                $this->decision($grant, $episode, $agent, [], 'role_ship_busy');

                return;
            }
            if (app(CampaignFleet::class)->pending($episode)->exists()) {
                $this->decision($grant, $episode, $agent, [], 'awaiting_action');

                return;
            }
            AutomationRun::where('campaign_episode_id', $episode->id)->where('state', 'preview')->update(['state' => 'stopped', 'blocked_reason' => 'Campaign derives a fresh preview each decision.']);
            if ($this->recover($grant, $episode, $agent)) {
                return;
            }
            // No active run and empty role ships: drop this episode's claim before re-ranking.
            if (! empty($episode->refresh()->state['claim'])) {
                app(FleetClaims::class)->release($episode, 'idle');
            }
            if ($reason) {
                app(CampaignControl::class)->pause($grant, $reason, $episode);

                return;
            }
            $phases = $grant->revision()->body['phases'];
            $index = array_search($episode->phase, array_column($phases, 'id'), true);
            if (app(CampaignSafety::class)->windDown($episode)) {
                $windDown = array_search('wind_down', array_column($phases, 'id'), true);
                if ($windDown !== false && $episode->phase !== 'wind_down') {
                    app(CampaignControl::class)->event($grant, $episode, 'phase', ['from' => $episode->phase, 'to' => 'wind_down']);
                    $episode->update(['phase' => 'wind_down']);
                    $index = $windDown;
                }
            }
            while (isset($phases[$index + 1], $phases[$index]['exit']) && app(CampaignPlaybook::class)->passes($phases[$index]['exit'], app(CampaignSafety::class)->metrics($episode, $agent, $phases[$index]['exit']))) {
                $from = $episode->phase;
                $episode->update(['phase' => $phases[++$index]['id']]);
                app(CampaignControl::class)->event($grant, $episode, 'phase', ['from' => $from, 'to' => $episode->phase]);
            }
            $considered = [];
            if (! app(CampaignSafety::class)->windDown($episode)) {
                if (! $arm && $episode->phase !== 'earn' && $this->discoverIfDue($episode, $agent)) {
                    $this->decision($grant, $episode, $agent, [], 'refreshing_discovery');

                    return;
                }
                foreach (['command', 'probe'] as $role) {
                    $observer = $this->role($episode, $role);
                    if ($observer && $observer['nav']['status'] !== 'IN_TRANSIT') {
                        $market = $observer['nav']['waypointSymbol'];
                        $point = GameObservation::where('agent_id', $agent->id)->where('reset_id', $episode->reset_id)->where('kind', 'waypoint')->where('symbol', $market)->first();
                        if (in_array('MARKETPLACE', array_column($point?->data['traits'] ?? [], 'symbol'), true)) {
                            $sync->observe($agent, 'market', $market, app(SpaceTradersApi::class)->market($agent, $market));
                        }
                    }
                }
                if (! $arm && $episode->phase === 'earn') {
                    $this->refreshParkedMarket($episode, $agent);
                }
            }
            $tradeAttempted = false;
            $miningTurn = ! empty($episode->state['mining_deferred']);
            if ($miningTurn) {
                $state = $episode->state;
                unset($state['mining_deferred']);
                $episode->update(['state' => $state]);
            }
            foreach ($phases[$index]['activities'] as $activity) {
                if (isset($activity['when']) && ! app(CampaignPlaybook::class)->passes($activity['when'], app(CampaignSafety::class)->metrics($episode, $agent, $activity['when']))) {
                    $considered[] = $activity + ['rejected' => 'Activity gate has not passed.'];

                    continue;
                }
                if (app(CampaignSafety::class)->windDown($episode) && $activity['strategy'] !== 'sell_aboard') {
                    $considered[] = $activity + ['rejected' => 'Wind-down only sells aboard.'];

                    continue;
                }
                $ship = $this->role($episode, $activity['role']);
                if (! $ship || $ship['nav']['status'] === 'IN_TRANSIT') {
                    $considered[] = $activity + ['rejected' => 'Role ship missing or in transit.'];

                    continue;
                }
                if ($activity['strategy'] === 'trade' && $miningTurn) {
                    continue;
                }
                if ($activity['strategy'] === 'mining' && $arm) {
                    $considered[] = $activity + ['rejected' => 'Arms do not mine.'];

                    continue;
                }
                if ($activity['strategy'] === 'mining' && $tradeAttempted) {
                    $episode->update(['state' => array_merge($episode->state ?? [], ['mining_deferred' => true])]);

                    continue;
                }
                $tradeAttempted = $tradeAttempted || $activity['strategy'] === 'trade';
                try {
                    $this->candidate = null;
                    $run = match ($activity['strategy']) {
                        'trade' => $this->trade($grant, $episode, $agent, $ship, $activity, $considered),
                        'mining' => $this->mining($grant, $episode, $agent, $ship, $considered),
                        'scout' => $this->scout($grant, $episode, $agent, $ship, $considered, $activity['role'] === 'command'),
                        'sell_aboard' => null,
                    };
                    if ($run) {
                        if (! $run->active_agent_id) {
                            app(AutomationRunner::class)->startCampaign($agent, $run, $episode);
                        }
                        if ($activity['strategy'] === 'trade' && $this->candidate) {
                            app(FleetClaims::class)->claim($episode->refresh(), $this->candidate);
                        }
                        if ($run->strategy === 'trade') {
                            $episode->refresh();
                            $episode->update(['state' => array_merge($episode->state ?? [], ['current_pair' => $run->plan['campaign_trade']['key']])]);
                        }
                        $this->decision($grant, $episode, $agent, $considered, $activity['strategy'], $run->id,
                            $run->strategy === 'trade' ? ['value_floor' => $run->plan['campaign_trade']['value_floor'] ?? null] : []);

                        return;
                    }
                } catch (GameException $exception) {
                    if ($exception->retryAfter > 0) {
                        $this->wait($grant, 'awaiting_run_start');

                        return;
                    }
                    $considered[] = $activity + ['rejected' => $exception->getMessage()];
                }
            }
            if (! $arm && $episode->phase === 'earn' && ! app(CampaignSafety::class)->windDown($episode)) {
                $this->discoverIfDue($episode, $agent);
            }
            $this->decision($grant, $episode, $agent, $considered, 'idle');
        });
    }

    private function startRetainedPreview(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent): bool
    {
        if (AutomationRun::where('active_agent_id', $agent->id)->where(fn ($query) => $query->where('campaign_episode_id', $episode->id)->orWhereIn('ship_symbol', app(CampaignFleet::class)->symbols($episode)))->exists()) {
            return false;
        }
        $run = AutomationRun::where('campaign_episode_id', $episode->id)->where('agent_id', $agent->id)->where('state', 'preview')->latest('id')->first();
        if (! $run) {
            return false;
        }
        try {
            if (app(CampaignSafety::class)->windDown($episode) && empty($run->plan['sell_only'])) {
                throw new GameException('Wind-down invalidates a retained buying or positioning preview.');
            }
            app(AutomationRunner::class)->startCampaign($agent, $run, $episode);
            $grant->update(['wait_reason' => null]);
            if ($run->strategy === 'trade') {
                app(FleetClaims::class)->claim($episode->refresh(), $run->plan['campaign_trade']);
                $episode->refresh();
                $episode->update(['state' => array_merge($episode->state ?? [], ['current_pair' => $run->plan['campaign_trade']['key']])]);
            }
            $this->decision($grant, $episode, $agent, [], $run->strategy, $run->id);
        } catch (GameException $exception) {
            if ($exception->retryAfter > 0) {
                $this->wait($grant, 'awaiting_run_start');
            } else {
                AutomationRun::whereKey($run->id)->where('state', 'preview')->update(['state' => 'stopped', 'blocked_reason' => $exception->getMessage()]);
                $this->decision($grant, $episode, $agent, [['strategy' => $run->strategy, 'rejected' => $exception->getMessage()]], 'preview_discarded');
            }
        }

        return true;
    }

    private function discoverIfDue(CampaignEpisode $episode, Agent $agent): bool
    {
        $catalogue = GameObservation::where('agent_id', $agent->id)->where('reset_id', $episode->reset_id)->where('kind', 'catalogue')->latest('observed_at')->first();
        $last = $episode->state['last_discovery_at'] ?? $catalogue?->observed_at?->toIso8601String();
        $interval = $episode->phase === 'discover' ? 120 : 900;
        if (! isset($episode->state['discovery']) && $last && CarbonImmutable::parse($last)->gte(now()->subSeconds($interval))) {
            return false;
        }
        app(CampaignDiscovery::class)->advance($episode, $agent);

        return true;
    }

    private function role(CampaignEpisode $episode, string $role): ?array
    {
        return app(CampaignFleet::class)->role($episode, $role);
    }

    private function limits(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent): array
    {
        return ['reserve' => 0, 'max_loss' => PHP_INT_MAX,
            'max_spend' => max(0, $agent->credits), 'minutes' => min($grant->limits['run_max_minutes'], max(1, (int) now()->diffInMinutes($episode->next_reset_at, false))),
            'cycles' => 1];
    }

    private function decision(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $considered, string $choice, ?int $run = null, array $extra = []): void
    {
        app(CampaignControl::class)->event($grant, $episode, 'decision', ['credits' => $agent->credits, 'phase' => $episode->phase, 'candidates' => $considered, 'choice' => $choice, 'run_id' => $run] + $extra);
    }

    private function sample(CampaignEpisode $episode, Agent $agent): void
    {
        if (CampaignSample::where('campaign_episode_id', $episode->id)->where('observed_at', '>', now()->subMinutes(15))->exists()) {
            return;
        }
        CampaignSample::create(['campaign_episode_id' => $episode->id, 'credits' => $agent->credits, 'phase' => $episode->phase,
            'ships' => Ship::where('agent_id', $agent->id)->get()->map(fn ($ship) => ['symbol' => $ship->symbol, 'nav' => $ship->snapshot['nav'], 'cargo' => $ship->snapshot['cargo'], 'fuel' => $ship->snapshot['fuel']])->all(),
            'request_budget' => app(RequestBudget::class)->usage($agent->credential(), $agent->account_id), 'observed_at' => now()]);
    }

    private function trade(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $ship, array $activity, array &$considered): ?AutomationRun
    {
        if (app(CampaignSafety::class)->windDown($episode)) {
            $considered[] = ['strategy' => 'trade', 'rejected' => 'Wind-down blocks new trade buys.'];

            return null;
        }
        $trades = app(CampaignTrade::class);
        $ranked = $trades->rank($grant, $episode, $agent, $ship, $considered);
        if (! $ranked) {
            $considered[] = ['strategy' => 'trade', 'rejected' => 'No profitable observed pair fits the playbook and verified route bounds.'];
        }
        foreach ($ranked as $candidate) {
            $this->candidate = $candidate;
            try {
                if ($candidate['source'] !== $ship['nav']['waypointSymbol']) {
                    return $this->position($grant, $episode, $agent, $ship, $candidate['source']);
                }
                $probe = $this->role($episode, 'probe');
                if ((empty($episode->state['probe_position_failed']) || ! is_string($episode->state['probe_position_failed']) || CarbonImmutable::parse($episode->state['probe_position_failed'])->lt(now()->subMinutes(30))) && $probe && $probe['symbol'] !== $ship['symbol'] && ! $this->parked($agent, $candidate['buyer']) && $probe['nav']['status'] !== 'IN_TRANSIT') {
                    $position = null;
                    try {
                        $position = $this->position($grant, $episode, $agent, $probe, $candidate['buyer']);

                        return app(AutomationRunner::class)->startCampaign($agent, $position, $episode);
                    } catch (\Throwable $exception) {
                        if ($exception instanceof GameException && $exception->retryAfter > 0) {
                            throw $exception;
                        }
                        if ($exception instanceof GameException && $exception->retryAfter === 0 && ! $exception->uncertain && $exception->apiCode === null) {
                            $episode->update(['state' => array_merge($episode->state ?? [], ['probe_position_failed' => now()->toIso8601String()])]);
                        }
                        if ($position?->fresh()->active_agent_id) {
                            return $position->fresh();
                        }
                        $considered[] = ['strategy' => 'probe', 'rejected' => 'Probe unavailable; refresh buyer directly.'];
                    }
                }
                $api = app(SpaceTradersApi::class);
                $sync = app(AgentSynchronizer::class);
                foreach ([$candidate['source'], $candidate['buyer']] as $market) {
                    $sync->observe($agent, 'market', $market, $api->market($agent, $market));
                }
                $refreshed = $trades->rank($grant, $episode, $agent, $ship, $considered);
                $fresh = $refreshed[0] ?? null;
                if (! $fresh || $fresh['key'] !== $candidate['key'] || $fresh['source'] !== $candidate['source']) {
                    $considered[] = $candidate + ['rejected' => 'Refreshed quotes changed the preferred pair; replan next tick.'];

                    return null;
                }
                // Shadow only: flag a low value-per-minute choice without changing it.
                $candidate = $fresh + ['value_floor' => $trades->valueFloor($episode, $fresh)];
                $this->candidate = $candidate;
                $limits = $this->limits($grant, $episode, $agent);
                $limits['max_spend'] = min($limits['max_spend'], $candidate['max_inventory'] + $candidate['fuel'] + $candidate['departure_refuel']);
                $plan = $limits + ['strategy' => 'trade', 'market' => $candidate['buyer'], 'symbol' => $candidate['good'], 'units' => $candidate['units'],
                    'min_sell_price' => (int) ceil($candidate['predicted_cost'] / $candidate['units']), 'min_margin' => 1,
                    'max_inventory' => $candidate['max_inventory'], 'max_fuel' => $ship['fuel']['capacity'],
                    'campaign_trade' => $candidate + ['quote_max_age_seconds' => $grant->revision()->body['trade']['quote_max_age_seconds']]];
                $plan['site'] = $candidate['source'];
                $trades->quoteLife($agent, $plan, []);

                return app(AutomationRunner::class)->preview($agent, $ship['symbol'], $plan);
            } catch (GameException $exception) {
                if ($exception->retryAfter > 0) {
                    throw $exception;
                }
                $considered[] = $candidate + ['rejected' => $exception->getMessage()];
            }
        }
        $this->candidate = null;

        return null;
    }

    private function position(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $ship, string $destination): AutomationRun
    {
        return app(AutomationRunner::class)->preview($agent, $ship['symbol'], $this->limits($grant, $episode, $agent) + [
            'strategy' => 'scout', 'destination' => $destination, 'max_fuel' => $ship['fuel']['capacity'], 'one_way' => true, 'departure_refuel' => true]);
    }

    /** Any of our ships, role or not, that sits at the waypoint (not in transit) reads its full prices. */
    private function parked(Agent $agent, string $waypoint): bool
    {
        return Ship::where('agent_id', $agent->id)->get()->contains(fn ($ship) => $ship->snapshot['nav']['waypointSymbol'] === $waypoint && $ship->snapshot['nav']['status'] !== 'IN_TRANSIT');
    }

    /**
     * Keeps rank() inputs fresh where extra (non-role) ships are parked: one market read per tick,
     * round-robin, skipping markets read within the quote age. It never blocks the decision.
     */
    private function refreshParkedMarket(CampaignEpisode $episode, Agent $agent): void
    {
        $roles = app(CampaignFleet::class)->symbols($episode);
        $occupied = Ship::where('agent_id', $agent->id)->get()
            ->filter(fn ($ship) => ! in_array($ship->symbol, $roles, true) && $ship->snapshot['nav']['status'] !== 'IN_TRANSIT')
            ->map(fn ($ship) => $ship->snapshot['nav']['waypointSymbol'])->unique()->values()->all();
        $markets = GameObservation::where('agent_id', $agent->id)->where('reset_id', $episode->reset_id)->where('kind', 'waypoint')->whereIn('symbol', $occupied)->get()
            ->filter(fn ($point) => in_array('MARKETPLACE', array_column($point->data['traits'] ?? [], 'symbol'), true))->pluck('symbol')->sort()->values();
        if ($markets->isEmpty()) {
            return;
        }
        $read = GameObservation::where('agent_id', $agent->id)->where('reset_id', $episode->reset_id)->where('kind', 'market')->whereIn('symbol', $markets)->get()->keyBy('symbol');
        $last = $episode->state['parked_refresh'] ?? null;
        $start = $last === null ? 0 : ($markets->search(fn ($symbol) => strcmp($symbol, $last) > 0) ?: 0);
        for ($offset = 0; $offset < $markets->count(); $offset++) {
            $symbol = $markets[($start + $offset) % $markets->count()];
            $quote = $read->get($symbol);
            if ($quote && ! empty($quote->data['tradeGoods']) && empty($quote->data['invalidated_by_action']) && $quote->observed_at->gte(now()->subSeconds(config('gameplay.snapshot_seconds')))) {
                continue;
            }
            CampaignEpisode::whereKey($episode->id)->update(['state->parked_refresh' => $symbol]);
            $episode->refresh();
            try {
                app(AgentSynchronizer::class)->observe($agent, 'market', $symbol, app(SpaceTradersApi::class)->market($agent, $symbol));
            } catch (GameException) {
            }

            return;
        }
    }

    private function mining(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $ship, array &$considered): ?AutomationRun
    {
        $investigator = app(OpportunityInvestigator::class);
        $record = OpportunityInvestigation::find($episode->state['mining_investigation_id'] ?? null);
        if (! $record || $record->reset_id !== $agent->reset_id || ($record->status !== 'investigating' && $record->finished_at?->lt(now()->subSeconds(config('gameplay.snapshot_seconds'))))) {
            $record = $investigator->create($agent, 'mining', $ship['nav']['systemSymbol']);
            $episode->update(['state' => array_merge($episode->state ?? [], ['mining_investigation_id' => $record->id])]);
        }
        $record = $investigator->advance($agent, $record, campaign: true);
        $observations = $record->observations;
        $observations['ships'] = Ship::where('agent_id', $agent->id)->get()->pluck('snapshot')->all();
        $observations['credits'] = $agent->credits;
        $observations['observed_at'] = now()->toIso8601String();
        $record->update(['observations' => $observations]);
        $candidates = app(OpportunityEvaluator::class)->evaluate($record->observations, 'mining', CarbonImmutable::now());
        foreach ($candidates as $candidate) {
            if ($candidate['ship_symbol'] !== $ship['symbol']) {
                continue;
            }
            if ($candidate['classification'] !== 'ready') {
                $considered[] = ['strategy' => 'mining', 'candidate' => $candidate['id'], 'rejected' => $candidate['reasons']];

                continue;
            }
            try {
                $limits = $this->limits($grant, $episode, $agent);
                $limits['reserve'] = $candidate['minimum_reserve'];
                $limits['max_spend'] = min(max(0, $agent->credits - $limits['reserve']), $candidate['route']['fuel_ceiling']);
                if ($record->status === 'investigating') {
                    $record->update(['status' => 'partial', 'finished_at' => now()]);
                }
                $draft = app(AutomationStaging::class)->save($agent, $record, $candidate['id'], $limits);

                return app(AutomationRunner::class)->previewDraft($agent, $draft);
            } catch (GameException $exception) {
                $considered[] = ['strategy' => 'mining', 'candidate' => $candidate['id'], 'rejected' => $exception->getMessage()];
            }
        }

        return null;
    }

    /**
     * A local scout (the command ship's) only reads never-priced markets (unknown prices, or no goods
     * directory yet) at its own coordinates (distance 0), and skips any market the role probe is
     * heading to or has a run for. It never crosses the system, and never hops between nearby
     * stations just to refresh stale prices: with no such market it yields nothing.
     */
    private function scout(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $ship, array &$considered, bool $local = false): ?AutomationRun
    {
        $report = app(OpportunityAssessor::class)->report($agent);
        $tasks = collect($report['observation_tasks'])->where('ship', $ship['symbol']);
        if ($local) {
            // A market with no goods directory is never priced either. The scout preview reads the
            // directory remotely before planning, so it needs only a verified route, not a ready task.
            $nearby = $this->colocated($agent, $ship);
            $claimed = $this->probeTargets($episode);
            $tasks = $tasks->filter(fn ($task) => in_array($task['destination'], $nearby, true) && ! in_array($task['destination'], $claimed, true)
                && (($task['fact'] === 'Unknown prices' && $task['ready_for_bounded_preview']) || ($task['fact'] === 'Missing goods directory' && $task['route_options'] !== [])));
            if ($tasks->isEmpty()) {
                $considered[] = ['strategy' => 'scout', 'role' => 'command', 'rejected' => 'No unclaimed market at the command ship\'s coordinates needs a read.'];

                return null;
            }
        } else {
            $tasks = $tasks->where('ready_for_bounded_preview', true);
        }
        foreach (self::scoutOrder($tasks, collect($report['markets'])) as $task) {
            if ($task['destination'] === $ship['nav']['waypointSymbol']) {
                continue;
            }
            try {
                // One way: the scout parks at each market it prices and starts the next hop from there,
                // instead of flying home between markets. A local (command) scout tops up first at a fuel seller.
                return app(AutomationRunner::class)->preview($agent, $ship['symbol'], $this->limits($grant, $episode, $agent) + [
                    'strategy' => 'scout', 'destination' => $task['destination'], 'max_fuel' => $ship['fuel']['capacity'], 'one_way' => true]
                    + ($local ? ['departure_refuel' => true] : []));
            } catch (GameException $exception) {
                $considered[] = ['strategy' => 'scout', 'destination' => $task['destination'], 'rejected' => $exception->getMessage()];
            }
        }

        return null;
    }

    /** @return list<string> Other waypoints in the ship's system at exactly its coordinates. */
    private function colocated(Agent $agent, array $ship): array
    {
        $points = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'waypoint')->get()->keyBy('symbol');
        $here = $points->get($ship['nav']['waypointSymbol'])?->data;
        if (! $here || $ship['nav']['status'] === 'IN_TRANSIT') {
            return [];
        }

        return $points->filter(fn ($point) => $point->symbol !== $here['symbol'] && ($point->data['systemSymbol'] ?? null) === $here['systemSymbol']
            && ($point->data['x'] ?? null) === $here['x'] && ($point->data['y'] ?? null) === $here['y'])->keys()->all();
    }

    /** @return list<string> Markets the role probe is flying to or has a preview or active run for. */
    private function probeTargets(CampaignEpisode $episode): array
    {
        $probe = $this->role($episode, 'probe');
        if (! $probe) {
            return [];
        }
        $targets = AutomationRun::where('agent_id', $episode->agent_id)->where('ship_symbol', $probe['symbol'])
            ->where(fn ($query) => $query->where('state', 'preview')->orWhereNotNull('active_agent_id'))->get()
            ->map(fn ($run) => $run->plan['destination'] ?? $run->plan['market'] ?? null)->filter()->all();
        if ($probe['nav']['status'] === 'IN_TRANSIT') {
            $targets[] = $probe['nav']['route']['destination']['symbol'] ?? $probe['nav']['waypointSymbol'];
        }

        return array_values(array_unique($targets));
    }

    /**
     * Never-priced markets come first, then the market priced longest ago. Insertion order alone
     * sent the probe back to the same stale market every tick while most markets stayed unpriced.
     */
    public static function scoutOrder(Collection $tasks, Collection $markets): Collection
    {
        $pricedAt = $markets->pluck('price_at', 'symbol');

        return $tasks->sortBy([
            fn ($a, $b) => ($a['fact'] !== 'Unknown prices') <=> ($b['fact'] !== 'Unknown prices'),
            fn ($a, $b) => (string) ($pricedAt[$a['destination']] ?? '') <=> (string) ($pricedAt[$b['destination']] ?? ''),
            fn ($a, $b) => $a['destination'] <=> $b['destination'],
        ])->values();
    }

    private function refreshRecoveryEvidence(CampaignEpisode $episode, Agent $agent, array $ship): void
    {
        $state = $episode->state ?? [];
        $markets = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->whereIn('kind', ['market', 'market_listing'])->get()->unique('symbol')->sortBy('symbol')->values();
        $offset = (int) ($state['recovery_refresh'] ?? 0);
        for ($reads = 0; $reads < min(2, $markets->count()); $reads++) {
            $market = $markets[($offset + $reads) % $markets->count()];
            try {
                app(AgentSynchronizer::class)->observe($agent, 'market', $market->symbol, app(SpaceTradersApi::class)->market($agent, $market->symbol));
            } catch (GameException) {
            } finally {
                $state['recovery_refresh'] = ($offset + $reads + 1) % $markets->count();
                $episode->update(['state' => $state]);
            }
        }
    }

    private function positionRecoveryObserver(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $ship, string $buyer): bool
    {
        if ($ship['nav']['waypointSymbol'] === $buyer || app(CampaignSafety::class)->windDown($episode)) {
            return false;
        }
        $markets = GameObservation::where('agent_id', $agent->id)->where('reset_id', $episode->reset_id)->where('kind', 'market')->orderBy('symbol')->get();
        $fresh = fn ($row) => $row && empty($row->data['invalidated_by_action']) && $row->observed_at->gte(now()->subSeconds(config('gameplay.snapshot_seconds')));
        $fuel = $markets->filter(fn ($row) => collect($row->data['tradeGoods'] ?? [])->contains(fn ($good) => $good['symbol'] === 'FUEL' && $good['purchasePrice'] > 0 && $good['tradeVolume'] > 0));
        $destination = ! $fresh($markets->firstWhere('symbol', $buyer)) ? $buyer : ($fuel->contains($fresh) ? null : $fuel->first()?->symbol);
        if (! $destination) {
            return false;
        }
        // One of our ships already at the destination (the role probe or a parked extra one) reads it
        // directly, before any wait on or trip by the role probe.
        if ($this->parked($agent, $destination)) {
            try {
                app(AgentSynchronizer::class)->observe($agent, 'market', $destination, app(SpaceTradersApi::class)->market($agent, $destination));
            } catch (GameException $exception) {
                if ($exception->retryAfter > 0) {
                    throw $exception;
                }
            }

            return false;
        }
        $probe = $this->role($episode, 'probe');
        $failed = $episode->state['probe_position_failed'] ?? null;
        if (! $probe || ! app(FuelPlanner::class)->fuelFree($probe) || $probe['cargo']['units'] > 0
            || (is_string($failed) && CarbonImmutable::parse($failed)->gte(now()->subMinutes(30)))) {
            return false;
        }
        if ($probe['nav']['status'] === 'IN_TRANSIT') {
            $this->decision($grant, $episode, $agent, [], 'awaiting_recovery_observer');

            return true;
        }
        $position = null;
        try {
            $position = $this->position($grant, $episode, $agent, $probe, $destination);
            app(AutomationRunner::class)->startCampaign($agent, $position, $episode);
            $this->decision($grant, $episode, $agent, [], 'recovery_evidence', $position->id);

            return true;
        } catch (GameException $exception) {
            if ($exception->retryAfter > 0) {
                throw $exception;
            }
            if (! $exception->uncertain && $exception->apiCode === null) {
                $episode->update(['state' => array_merge($episode->state ?? [], ['probe_position_failed' => now()->toIso8601String()])]);
            }

            return (bool) $position?->fresh()->active_agent_id;
        }
    }

    private function recover(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent): bool
    {
        foreach (['command', 'probe'] as $role) {
            $ship = $this->role($episode, $role);
            if (! $ship || $ship['cargo']['units'] === 0) {
                continue;
            }
            if ($ship['nav']['status'] === 'IN_TRANSIT') {
                $this->decision($grant, $episode, $agent, [], 'awaiting_cargo_arrival');

                return true;
            }
            $state = $episode->state ?? [];
            // Checked before any reads. Attempts count from their own marker, which an operator resume clears, so a review gets a fresh window.
            $state['recovery_attempts_since'][$ship['symbol']] ??= now()->toIso8601String();
            $episode->update(['state' => $state]);
            $attemptLimit = max(1, (int) config('gameplay.campaign_recovery_attempts', 5));
            $attempts = AutomationRun::where('campaign_episode_id', $episode->id)->where('ship_symbol', $ship['symbol'])->where('strategy', 'sell_aboard')
                ->where('started_at', '>=', CarbonImmutable::parse($state['recovery_attempts_since'][$ship['symbol']]))->latest('id')->limit($attemptLimit)->get();
            $fruitless = $attempts->takeWhile(fn ($run) => in_array($run->state->value, ['stopped', 'blocked'], true)
                && ($run->totals['sale_proceeds'] ?? 0) <= 0 && ($run->totals['credit_delta'] ?? 0) <= 0)->count();
            if ($fruitless >= $attemptLimit) {
                app(CampaignControl::class)->pause($grant, 'recovery_stalled', $episode);

                return true;
            }
            $this->refreshRecoveryEvidence($episode, $agent, $ship);
            $good = array_key_first(MiningPlanner::inventory($ship));
            $buyers = GameObservation::where('agent_id', $agent->id)->where('reset_id', $episode->reset_id)->where('kind', 'market')->get()
                ->map(fn ($row) => ['buyer' => $row->symbol, 'bid' => collect($row->data['tradeGoods'] ?? [])->firstWhere('symbol', $good)['sellPrice'] ?? 0])
                ->filter(fn ($row) => $row['bid'] > 0)->sort(fn ($left, $right) => $right['bid'] <=> $left['bid'] ?: $left['buyer'] <=> $right['buyer']);
            if ($buyers->isEmpty()) {
                app(CampaignControl::class)->pause($grant, 'no_cargo_buyer', $episode);

                return true;
            }
            $state = $episode->state ?? [];
            $state['recovery_since'][$ship['symbol']] ??= now()->toIso8601String();
            $episode->update(['state' => $state]);
            $previous = AutomationRun::where('campaign_episode_id', $episode->id)->where('ship_symbol', $ship['symbol'])->where('strategy', 'trade')->latest('id')->first();
            $considered = [];
            foreach ($buyers as $candidate) {
                if ($this->positionRecoveryObserver($grant, $episode, $agent, $ship, $candidate['buyer'])) {
                    return true;
                }
                try {
                    $limits = $this->limits($grant, $episode, $agent) + ['recovery_started_at' => $state['recovery_since'][$ship['symbol']],
                        'recovery_of' => $previous && ! empty($previous->totals['unsold'][$good]) ? $previous->id : null];
                    $run = app(AutomationRunner::class)->previewRecovery($agent, $ship['symbol'], $candidate['buyer'], $limits, $episode);
                    app(AutomationRunner::class)->startCampaign($agent, $run, $episode);
                    $this->decision($grant, $episode, $agent, $considered, 'sell_aboard', $run->id);

                    return true;
                } catch (GameException $exception) {
                    if ($exception->retryAfter > 0) {
                        throw $exception;
                    }
                    $considered[] = ['strategy' => 'sell_aboard', 'buyer' => $candidate['buyer'], 'rejected' => $exception->getMessage()];
                }
            }
            $this->decision($grant, $episode, $agent, $considered, 'awaiting_cargo_buyer_quote_or_route');

            return true;
        }
        $state = $episode->state ?? [];
        unset($state['recovery_since'], $state['recovery_attempts_since']);
        $episode->update(['state' => $state]);

        return false;
    }
}
