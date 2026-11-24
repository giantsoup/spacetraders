<?php

namespace App\Gameplay;

use App\Gameplay\Bridge\BridgeDrain;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\CampaignSample;
use App\Models\GameObservation;
use App\Models\OpportunityInvestigation;
use App\Models\ReconMission;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use App\Services\SpaceTradersClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    /** The primary grant tick() picked this pass, for the scheduler's per-agent failure counter. */
    public ?int $primaryGrantId = null;

    /** The arm grant tickArms() picked this pass, for the scheduler's per-arm failure counter. */
    public ?int $armGrantId = null;

    public float $handoverRefreshSeconds = 0;

    private array $sectionSeconds = [];

    /** Outcome of the pass in progress; see directGrant(). */
    private string $passOutcome = 'waiting';

    /** The ranked candidate behind the run trade() returned, claimed once that run starts. */
    private ?array $candidate = null;

    /** Set when an arm's committed pair or C0's top pair waits for a usable buyer quote this pass. */
    private bool $awaitingQuote = false;

    /** Buyer waypoint C0 is waiting to observe this pass, so pool probes hold or target it. */
    private ?string $awaitedBuyer = null;

    private float $deadline = INF;

    private array $localSummary = [];

    private array $meshPurchaseYards = [];

    private ?array $meshPassEvidence = null;

    private array $meshPolling = [];

    private array $marketReads = [];

    private bool $meshDiscoveryAttempted = false;

    private int $localActionBefore = 0;

    private int $localRunIdBefore = 0;

    public function dispatch(bool $arm): bool
    {
        try {
            return app(HostLock::class)->scoped('campaign', function () use ($arm) {
                try {
                    return $arm ? $this->tickArms() : $this->tick();
                } catch (GameException) {
                    return false;
                } catch (\Throwable $exception) {
                    $id = $arm ? $this->armGrantId : $this->primaryGrantId;
                    $this->recordDirectorFailure($arm, $id ? CampaignGrant::find($id) : null, $exception);

                    return true;
                }
            });
        } catch (GameException) {
            return false;
        }
    }

    public function tick(): bool
    {
        $this->primaryGrantId = null;
        $this->handoverRefreshSeconds = 0;
        try {
            return app(HostLock::class)->run('campaign', function () {
                try {
                    $control = app(CampaignControl::class);
                    $refreshed = $this->refreshPendingArrivals($control);
                    $control->activatePending();
                    $grant = CampaignGrant::allPrimaries()->whereNotIn('id', $refreshed)
                        ->whereNull('paused_at')
                        ->where(fn ($query) => $query->whereNull('last_directed_at')->orWhere('last_directed_at', '<=', now()->subSeconds(30)))
                        ->orderByRaw('last_directed_at is not null')
                        ->orderBy('last_directed_at')
                        ->orderBy('id')
                        ->first();
                } catch (GameException) {
                    return false;
                } catch (\Throwable $exception) {
                    $this->recordDirectorFailure(false, null, $exception);

                    return true;
                }
                if (! $grant) {
                    return false;
                }
                $this->primaryGrantId = $grant->id;

                return $this->trackDecision($grant, false);
            });
        } catch (GameException) {
            return false;
        }
    }

    private function refreshPendingArrivals(CampaignControl $control): array
    {
        $targets = [];
        $fleet = app(CampaignFleet::class);
        foreach (CampaignGrant::where('slot', 2)->orderBy('id')->get() as $pending) {
            $outgoing = CampaignGrant::primaryFor($pending->user_id, $pending->resolvedAgentSymbol())->first();
            if (! $outgoing || (! $outgoing->expires_at?->isPast() && $outgoing->pause_reason !== 'grant_expired')) {
                continue;
            }
            $agent = $control->agent($outgoing->user_id, $outgoing->resolvedAgentSymbol());
            if (! $agent) {
                continue;
            }
            $episode = $control->currentEpisode($outgoing, $agent);
            if (! $episode) {
                continue;
            }
            foreach (Ship::where('agent_id', $agent->id)->whereIn('symbol', $fleet->symbols($episode, pin: false))->orderBy('symbol')->get() as $ship) {
                $arrival = $ship->snapshot['nav']['route']['arrival'] ?? null;
                if ($ship->snapshot['nav']['status'] === 'IN_TRANSIT' && $ship->snapshot['cargo']['units'] === 0
                    && $arrival && CarbonImmutable::parse($arrival)->lte(now()) && ! $fleet->pendingForShip($episode, $ship->symbol)) {
                    $targets[sprintf('%020d:%s', $pending->id, $ship->symbol)] = [$agent, $ship->symbol, $pending->id];
                }
            }
        }
        if ($targets === []) {
            return [];
        }
        $key = 'handover_arrival_cursor';
        $cursor = DB::table('game_runtime')->where('key', $key)->value('value') ?? '';
        $rotated = array_filter($targets, fn ($targetKey) => $targetKey > $cursor, ARRAY_FILTER_USE_KEY) + $targets;
        $started = microtime(true);
        $deadline = $started + 3.0;
        $refreshed = [];
        try {
            app(SpaceTradersClient::class)->scope(function () use ($rotated, $key, $deadline, &$refreshed) {
                foreach (array_slice($rotated, 0, 2, true) as $targetKey => [$agent, $symbol, $pending]) {
                    if (microtime(true) >= $deadline) {
                        break;
                    }
                    $refreshed[] = $pending;
                    try {
                        app(HostLock::class)->run('agent-'.$agent->id, fn () => app(AgentSynchronizer::class)->refreshShip($agent, $symbol));
                    } catch (GameException) {
                        // An overdue ETA is never treated as proof of arrival.
                    } finally {
                        DB::table('game_runtime')->upsert([['key' => $key, 'value' => $targetKey]], ['key'], ['value']);
                    }
                }
            }, priority: 'safety', deadline: $deadline);
        } finally {
            $this->handoverRefreshSeconds = microtime(true) - $started;
        }

        return array_unique($refreshed);
    }

    public function tickArms(): bool
    {
        return $this->directArms(1, null, fn (CampaignGrant $grant, callable $direct) => $direct()) > 0;
    }

    public function tickArmsBatch(?callable $runner = null): int
    {
        return $this->directArms(
            max(0, (int) config('gameplay.arm_pass_max_arms')),
            microtime(true) + max(0, (int) config('gameplay.arm_pass_seconds')),
            $runner ?? fn (CampaignGrant $grant, callable $direct) => $direct(),
        );
    }

    private function directArms(int $limit, ?float $batchDeadline, callable $runner): int
    {
        $this->armGrantId = null;
        $directed = 0;
        try {
            app(HostLock::class)->run('campaign', function () use ($limit, $batchDeadline, $runner, &$directed) {
                $grants = CampaignGrant::liveArms()->orderByRaw('last_directed_at is not null')
                    ->orderBy('last_directed_at')->orderBy('id')->get();
                if ($limit === 0 || $grants->isEmpty() || microtime(true) >= ($batchDeadline ?? INF)) {
                    return;
                }
                $schedule = null;
                try {
                    $single = $grants->count() === 1 ? $grants->first() : null;
                    $schedule = FleetMetrics::forAgent($single?->resolvedAgentSymbol(), fn () => FleetMetrics::as($single?->arm()['ship'] ?? 'director',
                        fn () => FleetMetrics::duringDirector(fn () => app(SpaceTradersClient::class)->scope(
                            fn () => app(SpaceTradersApi::class)->resetSchedule(),
                            deadline: min(microtime(true) + 3.0, $batchDeadline ?? INF),
                        ))));
                } catch (\Throwable) {
                    // A shared schedule failure has no individual arm to charge.
                }
                $schedule = self::verifiedSchedule($schedule);
                if ($schedule) {
                    CampaignGrant::loadFirstEpisodeResets($grants);
                }
                $eligible = collect();
                $expired = false;
                foreach ($grants as $grant) {
                    $matches = $schedule && $this->checkArmReset($grant, $schedule);
                    if ($grant->paused_at) {
                        continue;
                    }
                    if ($grant->expires_at?->isPast()) {
                        app(CampaignControl::class)->pause($grant, 'grant_expired');
                        $expired = true;

                        continue;
                    }
                    if (! $matches || $grant->last_directed_at?->gt(now()->subSeconds(30))) {
                        continue;
                    }
                    $busy = $batchDeadline !== null && $this->healthyBusyArm($grant, $schedule['reset_id']);
                    $revisit = max(30, (int) config('gameplay.arm_busy_revisit_seconds'));
                    if (! $busy || ! $grant->last_directed_at?->gt(now()->subSeconds($revisit))) {
                        $eligible->push([$grant, $busy]);
                    }
                }
                if ($schedule) {
                    app(CampaignTrade::class)->harvestArms($schedule['reset_id']);
                }
                if ($expired && $batchDeadline === null) {
                    $directed = 1;

                    return;
                }
                foreach ($eligible->sortBy(fn (array $entry) => $entry[1]) as [$grant]) {
                    $remaining = ($batchDeadline ?? INF) - microtime(true);
                    if ($directed >= $limit || $remaining <= 0) {
                        break;
                    }
                    $this->armGrantId = $grant->id;
                    $seconds = min(self::DEADLINE_SECONDS, max(5, $remaining));
                    $directed++;
                    $runner($grant, fn () => $this->trackDecision($grant, true, $seconds, $schedule), $seconds);
                }
            });
        } catch (GameException) {
        }

        return $directed;
    }

    private function healthyBusyArm(CampaignGrant $grant, string $reset): bool
    {
        if ($grant->isDraining()) {
            return false;
        }
        $episode = CampaignEpisode::where('campaign_grant_id', $grant->id)->where('reset_id', $reset)->whereNull('closed_at')->first();
        if (! $episode || ! empty($episode->state['pending_pause'])) {
            return false;
        }

        return AutomationRun::where('campaign_episode_id', $episode->id)->where('active_agent_id', $episode->agent_id)
            ->whereIn('state', ['running', 'waiting'])->whereNull('stop_requested_at')->where('expires_at', '>', now())->exists()
            && ! ShipAction::where('agent_id', $episode->agent_id)->where('reset_id', $reset)
                ->where('ship_symbol', $grant->arm()['ship'])->whereIn('state', ['pending', 'sending'])->exists();
    }

    public static function cachedSchedule(): ?array
    {
        $data = DB::table('game_runtime')->where('key', 'reset_schedule:'.hash('sha256', SpaceTradersClient::BASE_URL))->value('value');

        return self::verifiedSchedule(is_string($data) ? json_decode($data, true) : null);
    }

    private static function verifiedSchedule(?array $schedule): ?array
    {
        if (! is_string($schedule['reset_id'] ?? null) || ! is_string($schedule['next_reset'] ?? null)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}(?:T.+)?$/D', $schedule['reset_id'])) {
            return null;
        }
        try {
            $reset = CarbonImmutable::parse($schedule['reset_id'], 'UTC');
            $next = CarbonImmutable::parse($schedule['next_reset'], 'UTC');

            return $reset->isFuture() || ! $next->isFuture() ? null : $schedule;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Null indicates unresolved ownership. An empty string proves prior ownership, with an unknown reset ID. */
    public static function armReset(CampaignGrant $grant, string $activeReset, ?array $schedule = null): ?string
    {
        $stamp = $grant->arm()['reset_id'] ?? (array_key_exists('first_episode_reset_id', $grant->getAttributes())
            ? $grant->first_episode_reset_id
            : CampaignEpisode::where('campaign_grant_id', $grant->id)->oldest('id')->value('reset_id'));
        if (is_string($stamp) && $stamp !== '') {
            return $stamp;
        }
        if (! $grant->created_at || ! preg_match('/^\d{4}-\d{2}-\d{2}(?:T.+)?$/D', $activeReset)) {
            return null;
        }
        try {
            $date = CarbonImmutable::parse($activeReset, 'UTC');
            if (strlen($activeReset) > 10) {
                return $grant->created_at->lt($date) ? '' : $activeReset;
            }
            if (($schedule['reset_id'] ?? null) === $activeReset) {
                $start = CarbonImmutable::parse($schedule['next_reset'], 'UTC')->utc()->subWeek();
                if ($start->toDateString() === $activeReset) {
                    return $grant->created_at->lt($start) ? '' : $activeReset;
                }
            }
            // A date-only ID cannot settle ownership within that day.
            if ($grant->created_at->gte($date->addDay())) {
                return $activeReset;
            }

            return $schedule && $grant->created_at->lt($date) ? '' : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function checkArmReset(CampaignGrant $grant, array $schedule): bool
    {
        $reset = self::armReset($grant, $schedule['reset_id'], $schedule);
        if ($reset === $schedule['reset_id']) {
            return $grant->pause_reason !== 'reset_mismatch';
        }
        if ($reset !== null && $grant->pause_reason !== 'reset_mismatch') {
            DB::transaction(function () use ($grant, $reset, $schedule) {
                $prior = $grant->pause_reason;
                $grant->update(['paused_at' => $grant->paused_at ?? now(), 'pause_reason' => 'reset_mismatch', 'wait_reason' => null]);
                $control = app(CampaignControl::class);
                $control->pause($grant, 'reset_mismatch');
                $control->event($grant, null, 'pause', ['reason' => 'reset_mismatch', 'owning_reset_id' => $reset ?: null,
                    'active_reset_id' => $schedule['reset_id'], 'prior_pause_reason' => $prior]);
            });
        }

        return false;
    }

    private function trackDecision(CampaignGrant $grant, bool $arm, float $seconds = self::DEADLINE_SECONDS, ?array $schedule = null): bool
    {
        try {
            try {
                $this->directGrant($grant, $seconds, $schedule);
            } finally {
                $grant->update(['last_directed_at' => now()]);
            }
            $rows = [['key' => $this->failureKey($arm, $grant), 'value' => '0']];
            if (! $arm) {
                $rows[] = ['key' => 'campaign_director_failures', 'value' => '0'];
            }
            DB::table('game_runtime')->upsert($rows, ['key'], ['value']);
        } catch (GameException) {
            // A deferred decision does not change the grant's failure streak.
        } catch (\Throwable $exception) {
            $this->recordDirectorFailure($arm, $grant, $exception);
        }

        return true;
    }

    private function failureKey(bool $arm, ?CampaignGrant $grant): string
    {
        return ($arm ? 'arm_director_failures' : 'campaign_director_failures').($grant ? ':'.$grant->id : '');
    }

    private function recordDirectorFailure(bool $arm, ?CampaignGrant $grant, \Throwable $exception): void
    {
        $message = ($arm ? 'Arm director' : 'Campaign director').' failed; exception details redacted.';
        $exceptionClass = get_class($exception);
        Log::error($message, ['exception_class' => $exceptionClass, 'grant' => $grant?->id]);
        try {
            if (! $grant && ! $arm) {
                $primaries = CampaignGrant::allPrimaries()->limit(2)->get();
                $grant = $primaries->count() === 1 ? $primaries->first() : null;
            }
            if (! $grant && $arm) {
                return;
            }
            $record = fn () => DB::transaction(function () use ($arm, $grant, $exceptionClass, $message) {
                $key = $this->failureKey($arm, $grant);
                $failures = (int) DB::table('game_runtime')->where('key', $key)->value('value') + 1;
                DB::table('game_runtime')->upsert([['key' => $key, 'value' => (string) $failures]], ['key'], ['value']);
                if ($grant) {
                    $control = app(CampaignControl::class);
                    $data = ['exception_class' => $exceptionClass, 'message' => $message, 'consecutive' => $failures];
                    $scope = $arm ? ['arm' => $grant->arm()['label'] ?? null] : ['agent_symbol' => $grant->resolvedAgentSymbol()];
                    $control->event($grant, null, 'director_error', $data + $scope);
                    if ($failures >= 5) {
                        $control->pause($grant, 'director_error');
                    }
                }
            });
            $agent = $grant ? Agent::where('user_id', $grant->user_id)->where('name', $grant->resolvedAgentSymbol())->unarchived()->latest('id')->first() : null;
            if ($agent) {
                app(HostLock::class)->run('agent-'.$agent->id, $record);
            } else {
                $record();
            }
        } catch (\Throwable) {
            // Keep dispatching after a failed report or a concurrent sender.
        }
    }

    /**
     * One director pass. Outcome mapping: started = a run was started (decision with a run); awaiting_run = an active run
     * exists (decision awaiting_run); waiting = everything else (wait reasons, pauses, busy or idle decisions).
     */
    private function directGrant(CampaignGrant $grant, float $seconds = self::DEADLINE_SECONDS, ?array $schedule = null): void
    {
        $started = microtime(true);
        $this->sectionSeconds = array_fill_keys(['sync', 'mesh_refresh', 'mesh_dispatch', 'purchases', 'trade'], 0.0);
        $this->passOutcome = 'waiting';
        $this->localSummary = [];
        $this->marketReads = [];
        $this->meshPurchaseYards = [];
        $this->meshPolling = [];
        $this->meshPassEvidence = null;
        app(CampaignMesh::class)->beginPass();
        $this->meshDiscoveryAttempted = false;
        $this->awaitedBuyer = null;
        $before = FleetMetrics::requestTotal();
        $agentSymbol = null;
        try {
            $agentSymbol = $grant->resolvedAgentSymbol() ?: ($grant->revision()->body['agent']['symbol'] ?? null);
        } catch (\Throwable) {
        }
        try {
            FleetMetrics::forAgent($agentSymbol, fn () => FleetMetrics::duringDirector(fn () => RequestBudget::forAgent($agentSymbol, fn () => $this->runDirectGrant($grant, $seconds, $schedule))));
        } finally {
            try {
                $ship = $grant->arm()['ship'] ?? 'director';
            } catch (\Throwable) {
                $ship = 'director';
            }
            $timings = $this->sectionSeconds + ['total' => microtime(true) - $started];
            FleetMetrics::forAgent($agentSymbol, fn () => FleetMetrics::directorPass($ship, $this->passOutcome, FleetMetrics::requestTotal() - $before, ['timings' => $timings] + ($this->meshPolling ? ['mesh_polling' => $this->meshPolling, 'mesh_pass_evidence' => $this->meshPassEvidence] : [])));
        }
    }

    private function measure(string $section, callable $work): mixed
    {
        $started = microtime(true);
        try {
            return $work();
        } finally {
            $this->sectionSeconds[$section] = ($this->sectionSeconds[$section] ?? 0.0) + microtime(true) - $started;
        }
    }

    private function runDirectGrant(CampaignGrant $grant, float $seconds, ?array $schedule): void
    {
        if ($grant->arm()) {
            $schedule ??= self::cachedSchedule();
            if (! $schedule || ! $this->checkArmReset($grant, $schedule)) {
                return;
            }
        }
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
            $deadline = $this->deadline = microtime(true) + $seconds;
            $primaries = CampaignGrant::allPrimaries($grant->user_id)->get()->map(fn (CampaignGrant $primary) => $primary->resolvedAgentSymbol())->unique();
            $multiAgent = $primaries->count() > 1 && $primaries->contains(fn ($symbol) => $symbol !== $grant->resolvedAgentSymbol());
            $caps = RequestBudget::agentCaps();
            if (! $grant->isDraining() && $multiAgent
                && (! config('gameplay.request_budget_enabled') || ! isset($caps['SHARED'])
                    || $primaries->contains(fn ($symbol) => ($caps[$symbol] ?? 0) < 1))) {
                $reason = 'multi_agent_budget_requires_stage';
                if ($grant->wait_reason !== $reason) {
                    Log::warning('Multi-agent campaign planning requires a request budget stage covering every primary.', ['user_id' => $grant->user_id]);
                    $this->wait($grant, $reason);
                }

                return;
            }
            app(SpaceTradersClient::class)->scope(fn () => $this->direct($grant, $deadline, $schedule),
                priority: $grant->isDraining() || $multiAgent || $caps !== [] ? 'director' : 'critical', deadline: $deadline);
        } catch (LocalEarnBudget) {
            $this->wait($grant, 'local_budget_deferred');
        } catch (ValidationException) {
            $control->pause($grant, 'registration_requires_review');
        } catch (DirectorAgentLockTimeout) {
            $this->wait($grant, 'awaiting_agent_lock');
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
        $this->passOutcome = 'waiting';
        $grant->update(['wait_reason' => $grant->isDraining() ? $grant->wait_reason : $reason]);
        app(CampaignControl::class)->event($grant, null, 'wait', ['reason' => $reason]);
    }

    private function direct(CampaignGrant $grant, float $deadline, ?array $schedule = null): void
    {
        app(AgentSynchronizer::class)->retryArchivedDrains($grant->user_id);
        $arm = $grant->arm();
        if ($arm) {
            $schedule ??= self::cachedSchedule();
            if (! $schedule || ! $this->checkArmReset($grant, $schedule)) {
                return;
            }
        }
        $previous = CampaignEpisode::where('campaign_grant_id', $grant->id)->whereNull('closed_at')->first();
        if ($grant->isDraining() && $previous && $previous->next_reset_at->gt(now()->addMinutes(10))) {
            $agent = Agent::find($previous->agent_id);
            if ($agent && ! $agent->isArchived() && (! $arm || $schedule['reset_id'] === $previous->reset_id)) {
                // Pending writes and advances keep these snapshots current. A new recovery validates
                // reset, ship, balance and buyer before taking authority.
                $this->episode($grant, $agent, $schedule ?? ['reset_id' => $previous->reset_id], $previous->next_reset_at, $deadline);

                return;
            }
        }
        $readSchedule = fn () => FleetMetrics::as($grant->arm()['ship'] ?? 'director', fn () => app(SpaceTradersApi::class)->resetSchedule());
        $schedule ??= $grant->isDraining()
            ? RequestBudget::forAgent('SHARED', fn () => app(SpaceTradersClient::class)->scope($readSchedule, priority: 'safety'))
            : $readSchedule();
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
        $agent = $user->agents()->where('name', $spec['symbol'])->where('reset_id', $schedule['reset_id'])->unarchived()->first();
        if (! $arm) {
            app(AgentSynchronizer::class)->archivePreviousWorlds($user->agents()->unarchived()->whereNotNull('reset_id')->get(), $schedule['reset_id']);
        }
        CampaignEpisode::where('campaign_grant_id', $grant->id)->whereNull('closed_at')->where('reset_id', '!=', $schedule['reset_id'])->update(['closed_at' => now()]);
        if (! $agent && $grant->isDraining()) {
            app(CampaignControl::class)->pause($grant, $grant->wait_reason);

            return;
        }
        if (! $agent && $arm) {
            // Arms never register or archive worlds; C0 owns the agent's lifecycle.
            $this->wait($grant, 'awaiting_agent');

            return;
        }
        if (! $agent) {
            $intent = CampaignEvent::where('kind', 'registration_intent')
                ->where('data->user_id', $user->id)
                ->where('data->reset_id', $schedule['reset_id'])
                ->where('data->symbol', $spec['symbol'])
                ->latest('id')
                ->first();
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
            $intent = app(CampaignControl::class)->event($grant, null, 'registration_intent', ['user_id' => $user->id, 'reset_id' => $schedule['reset_id'], 'symbol' => $spec['symbol'], 'grant_id' => $grant->id, 'attempt' => $attempt]);
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
            app(FleetMetrics::class)->as($arm['ship'], fn () => $this->episode($grant, $agent, $schedule, $next, $deadline));
        } else {
            $episode = CampaignEpisode::where('campaign_grant_id', $grant->id)->where('reset_id', $agent->reset_id)->latest('id')->first();
            $ship = $episode?->state['role_ships']['command'] ?? Ship::where('agent_id', $agent->id)->get()
                ->first(fn ($ship) => ($ship->snapshot['registration']['role'] ?? null) === 'COMMAND')?->symbol;
            FleetMetrics::as($ship, fn () => $this->episode($grant, $agent, $schedule, $next, $deadline));
        }
    }

    private function episode(CampaignGrant $grant, Agent $agent, array $schedule, CarbonImmutable $next, float $deadline): void
    {
        $arm = $grant->arm();
        $entered = false;
        $work = function () use ($grant, $agent, $schedule, $next, $arm, &$entered) {
            $entered = true;
            // Another worker may have confirmed a trade between loading the agent and acquiring this lock.
            $agent->refresh();
            if ($arm && (! $this->checkArmReset($grant, $schedule) || $agent->reset_id !== $schedule['reset_id'] || $agent->isArchived())) {
                return;
            }
            $episode = CampaignEpisode::where('campaign_grant_id', $grant->id)->where('reset_id', $schedule['reset_id'])->whereNull('closed_at')->first();
            $pendingHandover = ! $arm && CampaignGrant::pendingFor($grant->user_id, $grant->resolvedAgentSymbol())->exists();
            if ($episode && isset(CampaignMesh::policy($episode)['saturation'])) {
                app(CampaignMesh::class)->cleanRetained($episode, $agent);
            }
            if ($episode && $pendingHandover) {
                AutomationRun::where('campaign_episode_id', $episode->id)->where('state', 'preview')->whereNull('plan->mesh_work')
                    ->where(fn ($query) => $query->whereNull('plan->sell_only')->orWhere('plan->sell_only', '!=', true))
                    ->update(['state' => 'stopped', 'blocked_reason' => 'Awaiting pending campaign activation.']);
            }
            if ($episode && $this->startRetainedPreview($grant, $episode, $agent, $pendingHandover)) {
                return;
            }
            $sync = app(AgentSynchronizer::class);
            $this->measure('sync', function () use ($episode, $grant, $arm, $agent, $sync) {
                if ($episode && $grant->isDraining()) {
                    // The draining director uses stored evidence. Only cargo recovery and execution read upstream.
                } elseif ($arm) {
                    // Phase 3: arm passes avoid full fleet pagination. When a live run exists, stay local
                    // (at most 1 fresh GET /my/agent if synced_at is >60s old). When starting a run, read
                    // only the agent and this arm's ship, reconciling the full fleet only on shipCount drift or every 5 min.
                    $hasLiveRun = $episode !== null && (
                        AutomationRun::where('active_agent_id', $agent->id)->where('campaign_episode_id', $episode->id)
                            ->where(fn ($q) => $q->whereIn('state', ['running', 'waiting', 'stopping'])->orWhereNotNull('stop_requested_at'))
                            ->exists()
                        || ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('ship_symbol', $arm['ship'])->whereIn('state', ['pending', 'sending'])->exists()
                    );
                    $freshSeconds = max(1, (int) config('gameplay.agent_balance_fresh_seconds', 60));
                    $balanceStale = ! $agent->synced_at || $agent->synced_at->lt(now()->subSeconds($freshSeconds));
                    if ($hasLiveRun) {
                        if ($balanceStale) {
                            $balance = app(SpaceTradersApi::class)->agent($agent);
                            $agent->update(['credits' => $balance['credits'], 'connection_state' => 'connected', 'synced_at' => now()]);
                            if (isset($balance['shipCount']) && (int) $balance['shipCount'] !== Ship::where('agent_id', $agent->id)->count()) {
                                $sync->sync($agent, false);
                            }
                        }
                    } else {
                        $balance = app(SpaceTradersApi::class)->agent($agent);
                        $agent->update(['credits' => $balance['credits'], 'connection_state' => 'connected', 'synced_at' => now()]);
                        $lastFleetSync = DB::table('game_runtime')->where('key', 'fleet_sync_at:'.$agent->id)->value('value');
                        $fleetSyncDue = ! is_string($lastFleetSync) || CarbonImmutable::parse($lastFleetSync)->lt(now()->subSeconds((int) config('gameplay.fleet_sync_interval_seconds', 300)));
                        $countDrift = isset($balance['shipCount']) && (int) $balance['shipCount'] !== Ship::where('agent_id', $agent->id)->count();
                        $shipKnown = Ship::where('agent_id', $agent->id)->where('symbol', $arm['ship'])->exists();
                        if ($countDrift || ! $shipKnown || $fleetSyncDue) {
                            $sync->sync($agent, false);
                        } else {
                            $sync->refreshShip($agent, $arm['ship']);
                        }
                    }
                } else {
                    $sync->sync($agent, false);
                }
            });
            $episode = CampaignEpisode::firstOrCreate(['campaign_grant_id' => $grant->id, 'reset_id' => $schedule['reset_id']],
                ['agent_id' => $agent->id, 'start_credits' => $agent->credits, 'phase' => $arm ? 'earn' : $grant->revision()->body['phases'][0]['id'], 'opened_at' => now(), 'next_reset_at' => $next]
                    + ($arm ? ['state' => ['arm' => $arm['label'], 'role_ships' => ['command' => $arm['ship']]]] : []));
            if ($episode->closed_at) {
                app(CampaignControl::class)->pause($grant, 'episode_already_closed', $episode);

                return;
            }
            $draining = BridgeDrain::marker($grant, $episode);
            if (! $draining) {
                $grant->update(['wait_reason' => null]);
            }
            $this->sample($episode, $agent);
            app(CampaignTrade::class)->recordResults($episode);
            $mesh = ! $arm && CampaignMesh::policy($episode);
            $saturation = ! $arm && isset($grant->revision()->body['fleet']['haulers']);
            if (! $arm && $draining === null) {
                try {
                    $this->measure('purchases', fn () => app(CampaignProbeBootstrap::class)->reconcileDeliveries($grant, $episode, $agent, $this->deadline));
                } catch (GameException $exception) {
                    FleetMetrics::observe($exception);
                }
            }
            $reason = app(CampaignSafety::class)->reason($grant, $episode, $agent);
            if ($saturation && ($reason || $pendingHandover || $draining !== null)) {
                $this->recordSaturation($grant, $episode, $agent);
            }
            if ($reason && $reason !== 'consecutive_losing_loads') {
                app(CampaignControl::class)->pause($grant, $reason, $episode);

                return;
            }
            if ($reason === 'consecutive_losing_loads') {
                $state = $episode->state ?? [];
                $state['pending_pause'] = $reason;
                $episode->update(['state' => $state]);
            }
            $fleet = app(CampaignFleet::class);
            $pool = $fleet->poolEnabled($episode);
            $activeRuns = AutomationRun::where('active_agent_id', $agent->id)->where('campaign_episode_id', $episode->id)->orderBy('id')->get();
            $poolProbeSymbols = array_column($fleet->probes($episode), 'symbol');
            $remainingRuns = collect();
            foreach ($activeRuns as $run) {
                $cargo = Ship::where('agent_id', $agent->id)->where('symbol', $run->ship_symbol)->first()?->snapshot;
                $freshness = ! in_array($run->strategy, CampaignProbeBootstrap::STRATEGIES, true) && (str_contains(strtolower($run->blocked_reason ?? ''), 'fresh') || str_contains(strtolower($run->blocked_reason ?? ''), 'stale'));
                $handoff = $run->campaign_episode_id && ($run->state->value === 'blocked' || $freshness || $run->stop_requested_at
                    || ($reason && empty($run->plan['sell_only'])) || ($draining !== null && empty($run->plan['sell_only']))
                    || (app(CampaignSafety::class)->windDown($episode) && empty($run->plan['sell_only'])));
                if ($handoff) {
                    if ($run->state->value === 'blocked' && ! $freshness && $run->strategy === 'scout' && ! empty($run->plan['one_way']) && in_array($run->ship_symbol, $poolProbeSymbols, true)) {
                        if (isset(CampaignMesh::policy($episode)['saturation'])) {
                            if (CampaignMesh::dispatchFailure($run->blocked_reason ?? '')
                                && ! ShipAction::where('automation_run_id', $run->id)->whereIn('state', ['pending', 'sending', 'uncertain'])->exists()) {
                                app(CampaignMesh::class)->failure($episode, $run->ship_symbol, $run->plan['destination'] ?? $run->plan['market'], $run->id);
                            }
                        } else {
                            $episode->update(['state' => array_merge($episode->state ?? [], ['probe_position_failed' => now()->toIso8601String()])]);
                        }
                    }
                    app(AutomationRunner::class)->stop($run);
                    if (($cargo['cargo']['units'] ?? 0) === 0 && ($run->ship_symbol === ($fleet->role($episode, 'command')['symbol'] ?? null) || ! $pool)) {
                        app(FleetClaims::class)->release($episode, 'handoff');
                    }
                    if ($freshness) {
                        app(CampaignControl::class)->event($grant, $episode, 'freshness_hold', ['run_id' => $run->id]);
                    }
                    if (! ShipAction::where('automation_run_id', $run->id)->whereIn('state', ['pending', 'sending', 'uncertain'])->exists()
                        && ($cargo['nav']['status'] ?? null) !== 'IN_TRANSIT') {
                        app(AutomationRunner::class)->finishStop($run, 'Campaign handoff: derive a fresh plan or sell aboard.');

                        continue;
                    }
                }
                $remainingRuns->push($run);
            }
            if (! $pool && ! $pendingHandover) {
                if ($remainingRuns->isNotEmpty()) {
                    if (! $arm && $draining === null && $episode->phase === 'earn' && ! app(CampaignSafety::class)->windDown($episode)) {
                        $this->discoverIfDue($episode, $agent);
                        $this->refreshParkedMarket($episode, $agent);
                    }
                    $this->decision($grant, $episode, $agent, [], 'awaiting_run');

                    return;
                }
                if (AutomationRun::where('active_agent_id', $agent->id)->where('reset_id', $episode->reset_id)->whereIn('ship_symbol', $fleet->symbols($episode))->exists()) {
                    $this->decision($grant, $episode, $agent, [], 'role_ship_busy');

                    return;
                }
                if ($fleet->pending($episode)->exists()) {
                    $this->decision($grant, $episode, $agent, [], 'awaiting_action');

                    return;
                }
            }
            $busyShips = $this->busyShips($agent, $episode);
            $command = $fleet->role($episode, 'command');
            $commandBusy = $command !== null && in_array($command['symbol'], $busyShips, true);
            if ($pool && ($commandBusy || (($reason || $draining !== null) && $remainingRuns->isNotEmpty()))) {
                if ($reason || $draining !== null) {
                    $this->decision($grant, $episode, $agent, [], 'awaiting_run');

                    return;
                }
                if ($pendingHandover) {
                    $recovery = $fleet->recoveryFleet($grant, $episode, $agent, false);
                    if (! $this->recover($grant, $episode, $agent, $recovery, false, $busyShips, true)) {
                        $this->decision($grant, $episode, $agent, [], 'awaiting_pending_activation');
                    }

                    return;
                }
                $considered = [];
                $startedPool = false;
                if (! $arm && ! app(CampaignSafety::class)->windDown($episode)) {
                    if ($mesh || $episode->phase === 'earn') {
                        $this->discoverIfDue($episode, $agent);
                        if (! $mesh) {
                            $this->refreshParkedMarket($episode, $agent);
                        }
                    }
                    $this->refreshRoleMarkets($grant, $episode, $agent, $sync, $busyShips, includeCommand: false);
                    $startedPool = $this->dispatchPurchases($grant, $episode, $agent, $busyShips, $considered);
                    if ($mesh) {
                        $assigned = $this->probeTargets($episode);
                        $startedPool = $this->dispatchPoolProbes($grant, $episode, $agent, $busyShips, $assigned, $considered) || $startedPool;
                    }
                    $phases = $grant->revision()->body['phases'];
                    $phaseIndex = array_search($episode->phase, array_column($phases, 'id'), true);
                    if (! $mesh && $phaseIndex !== false) {
                        $assigned = $this->probeTargets($episode);
                        foreach ($phases[$phaseIndex]['activities'] as $activity) {
                            if ($activity['role'] !== 'probe' || $activity['strategy'] !== 'scout') {
                                continue;
                            }
                            if (isset($activity['when']) && ! app(CampaignPlaybook::class)->passes($activity['when'], app(CampaignSafety::class)->metrics($episode, $agent, $activity['when']))) {
                                continue;
                            }
                            $startedPool = $this->dispatchPoolProbes($grant, $episode, $agent, $busyShips, $assigned, $considered) || $startedPool;
                        }
                    }
                }
                if (! $startedPool) {
                    $this->decision($grant, $episode, $agent, $considered, $remainingRuns->isNotEmpty() ? 'awaiting_run' : 'role_ship_busy');
                }

                return;
            }
            AutomationRun::where('campaign_episode_id', $episode->id)->where('state', 'preview')->whereNull('plan->mesh_work')
                ->when($busyShips !== [], fn ($query) => $query->whereNotIn('ship_symbol', $busyShips))
                ->update(['state' => 'stopped', 'blocked_reason' => 'Campaign derives a fresh preview each decision.']);
            $recovery = $fleet->recoveryFleet($grant, $episode, $agent, $draining !== null);
            if ($this->recover($grant, $episode, $agent, $recovery, $draining !== null, $busyShips, $pendingHandover)) {
                $this->markArmRecovery($grant, $episode, true);

                return;
            }
            $this->markArmRecovery($grant, $episode, false);
            // No active run and empty role ships: drop this episode's claim before re-ranking, unless an
            // arm has just positioned at its claimed source; then trade() tries the committed pair first.
            $commit = $arm && ! $reason && $draining === null && ! app(CampaignSafety::class)->windDown($episode) ? $this->commitment($grant, $episode) : null;
            if (! $commit && ! empty($episode->refresh()->state['commit'])) {
                $this->endCommit($grant, $episode, $reason ? 'paused' : ($draining !== null ? 'drain_pause' : 'wind_down'));
            }
            if (! $commit && ! empty($episode->refresh()->state['claim'])) {
                app(FleetClaims::class)->release($episode, 'idle');
            }
            if ($draining !== null) {
                if ($remainingRuns->isNotEmpty()) {
                    $this->decision($grant, $episode, $agent, [], 'awaiting_run');

                    return;
                }
                if ($fleet->pending($episode, array_keys($recovery['ships']))->exists()) {
                    $this->decision($grant, $episode, $agent, [], 'awaiting_action');

                    return;
                }
                $state = $episode->refresh()->state ?? [];
                unset($state['draining']);
                $episode->update(['state' => $state]);
                app(CampaignControl::class)->pause($grant, 'drain_pause:'.$draining, $episode);

                return;
            }
            if ($reason) {
                if ($remainingRuns->isNotEmpty()) {
                    $this->decision($grant, $episode, $agent, [], 'awaiting_run');

                    return;
                }
                app(CampaignControl::class)->pause($grant, $reason, $episode);

                return;
            }
            if ($pendingHandover) {
                $this->decision($grant, $episode, $agent, [], 'awaiting_pending_activation');

                return;
            }
            if ($saturation && app(CampaignSafety::class)->windDown($episode)) {
                $this->recordSaturation($grant, $episode, $agent);
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
                if ($mesh) {
                    $this->discoverIfDue($episode, $agent);
                }
                if (! $mesh && ! $arm && $episode->phase !== 'earn' && $this->discoverIfDue($episode, $agent)) {
                    $this->decision($grant, $episode, $agent, [], 'refreshing_discovery');

                    return;
                }
                $this->refreshRoleMarkets($grant, $episode, $agent, $sync, $busyShips, includeCommand: true);
                if (! $mesh && ! $arm && $episode->phase === 'earn') {
                    if (CampaignLocalEarn::policy($grant, $episode)) {
                        app(CampaignLocalEarn::class)->reserve($this->deadline);
                    }
                    $this->refreshParkedMarket($episode, $agent);
                }
            }
            $startedAny = false;
            if (! $arm && ! app(CampaignSafety::class)->windDown($episode)) {
                if ($this->dispatchPurchases($grant, $episode, $agent, $busyShips, $considered)) {
                    $startedAny = true;
                    if (! $pool) {
                        return;
                    }
                }
            }
            $tradeAttempted = false;
            $this->awaitingQuote = false;
            $this->awaitedBuyer = null;
            $miningTurn = ! empty($episode->state['mining_deferred']);
            if ($miningTurn) {
                $state = $episode->state;
                unset($state['mining_deferred']);
                $episode->update(['state' => $state]);
            }
            $assignedTargets = $pool ? $this->probeTargets($episode) : [];
            foreach ($phases[$index]['activities'] as $activity) {
                if (isset($activity['when']) && ! app(CampaignPlaybook::class)->passes($activity['when'], app(CampaignSafety::class)->metrics($episode, $agent, $activity['when']))) {
                    $considered[] = $activity + ['rejected' => 'Activity gate has not passed.'];

                    continue;
                }
                if (app(CampaignSafety::class)->windDown($episode) && $activity['strategy'] !== 'sell_aboard') {
                    $considered[] = $activity + ['rejected' => 'Wind-down only sells aboard.'];

                    continue;
                }
                if ($pool && $activity['role'] === 'probe' && $activity['strategy'] === 'scout') {
                    if (! $mesh && $this->dispatchPoolProbes($grant, $episode, $agent, $busyShips, $assignedTargets, $considered)) {
                        $startedAny = true;
                    }

                    continue;
                }
                $ship = $this->role($episode, $activity['role']);
                if (! $ship || $ship['nav']['status'] === 'IN_TRANSIT' || in_array($ship['symbol'], $busyShips, true)) {
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
                $localEarn = CampaignLocalEarn::policy($grant, $episode);
                try {
                    if ($localEarn) {
                        app(CampaignLocalEarn::class)->reserve($this->deadline);
                    }
                    $this->candidate = null;
                    $run = match ($activity['strategy']) {
                        'trade' => $this->measure('trade', function () use ($grant, $episode, $agent, $ship, $activity, &$considered, $busyShips) {
                            return $this->trade($grant, $episode, $agent, $ship, $activity, $considered, $busyShips);
                        }),
                        'mining' => $this->mining($grant, $episode, $agent, $ship, $considered),
                        'scout' => $this->scout($grant, $episode, $agent, $ship, $considered, $activity['role'] === 'command', $assignedTargets),
                        'sell_aboard' => null,
                    };
                    if ($run) {
                        if (! $run->active_agent_id) {
                            app(AutomationRunner::class)->startCampaign($agent, $run, $episode);
                        }
                        if ($activity['strategy'] === 'trade' && $this->candidate) {
                            app(FleetClaims::class)->claim($episode->refresh(), $this->candidate);
                            if ($arm && $run->strategy === 'scout') {
                                $this->commit($grant, $episode, $this->candidate, $run);
                            } elseif ($arm && ! empty($episode->refresh()->state['commit'])) {
                                $this->endCommit($grant, $episode, 'trade_started', ['run_id' => $run->id]);
                            }
                        }
                        if ($run->strategy === 'trade') {
                            $episode->refresh();
                            $episode->update(['state' => array_merge($episode->state ?? [], ['current_pair' => $run->plan['campaign_trade']['key']])]);
                        }
                        $this->decision($grant, $episode, $agent, $considered, $activity['strategy'], $run->id,
                            $run->strategy === 'trade' ? ['value_floor' => $run->plan['campaign_trade']['value_floor'] ?? null] : []);
                        $busyShips[] = $run->ship_symbol;
                        if ($run->strategy === 'scout' && isset($run->plan['destination'])) {
                            $assignedTargets[] = $run->plan['destination'];
                        }
                        $startedAny = true;
                        if (! $pool) {
                            return;
                        }
                    }
                } catch (LocalEarnBudget) {
                    $this->localSummary['end'] = 'local_budget_deferred';
                    $this->decision($grant, $episode, $agent, $considered, 'local_budget_deferred');
                    $this->wait($grant, 'local_budget_deferred');

                    return;
                } catch (GameException $exception) {
                    if ($activity['strategy'] === 'trade' && $localEarn) {
                        $this->localSummary['end'] = 'closed_stop';
                        $considered[] = $activity + ['rejected' => $exception->getMessage()];
                        $this->decision($grant, $episode, $agent, $considered, 'local_stopped');
                        if (! $this->pauseForLocalStartFailure($grant, $episode, $agent, $exception) && ! $grant->refresh()->paused_at) {
                            $this->wait($grant, $exception->retryAfter > 0 ? 'awaiting_fresh_state' : 'local_preflight_refused');
                        }

                        return;
                    }
                    if ($exception->retryAfter > 0) {
                        $this->wait($grant, 'awaiting_run_start');

                        return;
                    }
                    $considered[] = $activity + ['rejected' => $exception->getMessage()];
                }
            }
            if ($mesh && ! app(CampaignSafety::class)->windDown($episode)) {
                $startedAny = $this->dispatchPoolProbes($grant, $episode, $agent, $busyShips, $assignedTargets, $considered) || $startedAny;
            }
            if ($startedAny) {
                return;
            }
            if (! $arm && $episode->phase === 'earn' && ! app(CampaignSafety::class)->windDown($episode)) {
                if (CampaignLocalEarn::policy($grant, $episode)) {
                    app(CampaignLocalEarn::class)->reserve($this->deadline);
                }
                $this->discoverIfDue($episode, $agent);
            }
            $this->decision($grant, $episode, $agent, $considered, $this->awaitingQuote ? 'awaiting_buyer_quote' : ($remainingRuns->isNotEmpty() ? 'awaiting_run' : 'idle'));
        };
        try {
            app(HostLock::class)->scoped('agent-'.$agent->id, $work, min(10, max(0, $deadline - microtime(true))));
        } catch (GameException $exception) {
            if (! $entered && $exception->retryAfter > 0) {
                throw new DirectorAgentLockTimeout;
            }
            throw $exception;
        }
    }

    /**
     * Records that an arm flies a one-way positioning run to the source of its claimed pair. The
     * commitment keeps the claim through arrival, so trade() tries that pair before re-ranking.
     */
    private function commit(CampaignGrant $grant, CampaignEpisode $episode, array $candidate, AutomationRun $run): void
    {
        if ((int) config('gameplay.campaign_commit_minutes', 10) < 1) {
            return;
        }
        $commit = ['key' => $candidate['key'], 'good' => $candidate['good'], 'source' => $candidate['source'], 'buyer' => $candidate['buyer'],
            'ship' => $run->ship_symbol, 'score' => $candidate['score'] ?? null, 'run_id' => $run->id, 'at' => now()->toIso8601String()];
        $episode->refresh();
        $episode->update(['state' => array_merge($episode->state ?? [], ['commit' => $commit])]);
        app(CampaignControl::class)->event($grant, $episode, 'commit', $commit);
    }

    /**
     * The arm's commitment while it holds: its claim is unchanged, its ship stands empty at the
     * committed source, and it arrived at most campaign_commit_minutes ago (a ship that never arrives
     * lets go after run_max_minutes plus that). Otherwise it ends and normal ranking resumes.
     */
    private function commitment(CampaignGrant $grant, CampaignEpisode $episode): ?array
    {
        $state = $episode->refresh()->state ?? [];
        $commit = $state['commit'] ?? null;
        if (! $commit) {
            return null;
        }
        $claim = $state['claim'] ?? null;
        $ship = $this->role($episode, 'command');
        $minutes = (int) config('gameplay.campaign_commit_minutes', 10);
        $reason = match (true) {
            $minutes < 1 => 'disabled',
            ! $claim || [$claim['good'], $claim['source'], $claim['buyer']] !== [$commit['good'], $commit['source'], $commit['buyer']] => 'claim_changed',
            isset($commit['arrived_at']) && CarbonImmutable::parse($commit['arrived_at'])->addMinutes($minutes)->isPast() => 'expired',
            ! isset($commit['arrived_at']) && CarbonImmutable::parse($commit['at'])->addMinutes($grant->limits['run_max_minutes'] + $minutes)->isPast() => 'expired',
            ! $ship || $ship['symbol'] !== $commit['ship'] || $ship['nav']['status'] === 'IN_TRANSIT' || $ship['nav']['waypointSymbol'] !== $commit['source'] => 'not_at_source',
            ($ship['cargo']['units'] ?? 0) !== 0 => 'cargo_aboard',
            default => null,
        };
        if ($reason) {
            $this->endCommit($grant, $episode, $reason);

            return null;
        }
        if (! isset($commit['arrived_at'])) {
            $commit['arrived_at'] = now()->toIso8601String();
            $episode->update(['state' => array_merge($state, ['commit' => $commit])]);
        }

        return $commit;
    }

    private function endCommit(CampaignGrant $grant, CampaignEpisode $episode, string $reason, array $extra = []): void
    {
        $state = $episode->refresh()->state ?? [];
        $commit = $state['commit'] ?? null;
        if (! $commit) {
            return;
        }
        unset($state['commit']);
        $episode->update(['state' => $state]);
        app(CampaignControl::class)->event($grant, $episode, 'commit_end', $commit + ['reason' => $reason] + $extra);
    }

    /**
     * Puts the committed pair first unless it no longer ranks (unprofitable, out of bounds or claimed)
     * or another pair scores at least better_pair_ratio times it. Scores include each pair's
     * positioning leg from where the ship stands, so a switch pays for its repositioning.
     */
    private function preferCommitted(CampaignGrant $grant, CampaignEpisode $episode, array $ranked): array
    {
        $commit = $episode->state['commit'] ?? null;
        if (! $commit) {
            return $ranked;
        }
        $same = fn (array $row) => $row['key'] === $commit['key'] && $row['source'] === $commit['source'];
        $committed = collect($ranked)->first($same);
        if (! $committed) {
            $this->endCommit($grant, $episode, 'pair_not_ranked');

            return $ranked;
        }
        $best = $ranked[0];
        $ratio = $grant->revision()->body['trade']['switch']['better_pair_ratio'];
        if (! $same($best) && $best['score'] >= $committed['score'] * $ratio) {
            $this->endCommit($grant, $episode, 'better_pair', ['better_key' => $best['key'], 'better_source' => $best['source'],
                'better_score' => $best['score'], 'committed_score' => $committed['score'], 'ratio' => $ratio]);

            return $ranked;
        }

        return [$committed, ...array_values(array_filter($ranked, fn ($row) => ! $same($row)))];
    }

    /** The oldest buyer quote a committed arm buy accepts; never below the snapshot age. */
    private function commitQuoteLimit(): int
    {
        return max((int) config('gameplay.snapshot_seconds'), (int) config('gameplay.campaign_commit_quote_max_age_seconds', 900));
    }

    /** One of our ships is at the buyer (not in transit) or flying to it, so the buyer quote can be refreshed. */
    private function buyerObservable(Agent $agent, string $buyer): bool
    {
        return Ship::where('agent_id', $agent->id)->get()->contains(fn ($ship) => ($ship->snapshot['nav']['status'] ?? null) === 'IN_TRANSIT'
            ? ($ship->snapshot['nav']['route']['destination']['symbol'] ?? $ship->snapshot['nav']['waypointSymbol'] ?? null) === $buyer
            : ($ship->snapshot['nav']['waypointSymbol'] ?? null) === $buyer);
    }

    /** Seconds since the buyer's detailed quote for the good, or null when it is missing or invalidated. */
    private function buyerQuoteAge(Agent $agent, string $buyer, string $good): ?int
    {
        $quote = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'market')->where('symbol', $buyer)->first();
        if (! $quote || ! empty($quote->data['invalidated_by_action']) || ! collect($quote->data['tradeGoods'] ?? [])->contains('symbol', $good)) {
            return null;
        }

        return max(0, (int) $quote->observed_at->diffInSeconds(now(), true));
    }

    private function startRetainedPreview(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, bool $settlementOnly = false): bool
    {
        $fleet = app(CampaignFleet::class);
        $pool = $fleet->poolEnabled($episode);
        if (! $pool && AutomationRun::where('active_agent_id', $agent->id)->where('reset_id', $episode->reset_id)->where(fn ($query) => $query->where('campaign_episode_id', $episode->id)->orWhereIn('ship_symbol', $fleet->symbols($episode)))->exists()) {
            return false;
        }
        $busy = $pool ? $this->busyShips($agent, $episode) : [];
        $run = AutomationRun::where('campaign_episode_id', $episode->id)->where('agent_id', $agent->id)->where('state', 'preview')->whereNull('plan->mesh_work')
            ->when($settlementOnly, fn ($query) => $query->where('plan->sell_only', true))
            ->when($pool && $busy !== [], fn ($query) => $query->whereNotIn('ship_symbol', $busy))
            ->latest('id')->first();
        if (! $run) {
            return false;
        }
        $localEarn = CampaignLocalEarn::policy($grant, $episode);
        if ($localEarn) {
            $this->localActionBefore = (int) ShipAction::where('agent_id', $agent->id)->max('id');
            $this->localRunIdBefore = (int) AutomationRun::where('agent_id', $agent->id)->max('id');
        }
        try {
            if ($localEarn) {
                app(CampaignTrade::class)->recordResults($episode);
                app(CampaignSafety::class)->check($run, $agent);
            }
            if (app(CampaignSafety::class)->windDown($episode) && empty($run->plan['sell_only'])) {
                throw new GameException('Wind-down invalidates a retained buying or positioning preview.');
            }
            if (($grant->isDraining() || ! empty($episode->state['draining'])) && empty($run->plan['sell_only'])) {
                throw new GameException('Drain pause invalidates a retained buying or positioning preview.');
            }
            if (ReconMission::holds($agent, $run->ship_symbol) || ! in_array($run->ship_symbol, $fleet->symbols($episode), true)) {
                throw new GameException('Retained preview ship is no longer eligible for this campaign episode.');
            }
            app(AutomationRunner::class)->startCampaign($agent, $run, $episode);
            if (! $grant->isDraining()) {
                $grant->update(['wait_reason' => null]);
            }
            if ($run->strategy === 'trade') {
                app(FleetClaims::class)->claim($episode->refresh(), $run->plan['campaign_trade']);
                $episode->refresh();
                $episode->update(['state' => array_merge($episode->state ?? [], ['current_pair' => $run->plan['campaign_trade']['key']])]);
            }
            $this->decision($grant, $episode, $agent, [], $run->strategy, $run->id);
        } catch (GameException $exception) {
            $rejection = [['strategy' => $run->strategy, 'rejected' => $exception->getMessage()]];
            if ($localEarn && $this->pauseForLocalStartFailure($grant, $episode, $agent, $exception, $run->ship_symbol)) {
                $this->localSummary['end'] = 'closed_stop';
                $this->decision($grant, $episode, $agent, $rejection, 'local_stopped');
            } elseif ($exception->retryAfter > 0) {
                $this->wait($grant, 'awaiting_run_start');
            } else {
                AutomationRun::whereKey($run->id)->where('state', 'preview')->update(['state' => 'stopped', 'blocked_reason' => $exception->getMessage()]);
                $this->decision($grant, $episode, $agent, $rejection, 'preview_discarded');
            }
        }

        return true;
    }

    private function discoverIfDue(CampaignEpisode $episode, Agent $agent): bool
    {
        if (CampaignMesh::policy($episode)) {
            if (isset(CampaignMesh::policy($episode)['saturation']) && ! isset($this->meshPolling['demand'])) {
                $this->pollMesh($episode, $agent, 'demand');
            }
            $discovery = app(CampaignDiscovery::class);
            $system = app(CampaignFleet::class)->role($episode, 'command', pin: false)['nav']['systemSymbol'] ?? null;
            if ($this->meshDiscoveryAttempted || ! $system || microtime(true) >= $this->deadline
                || (! isset($episode->state['discovery']) && $discovery->directoryGaps($episode, $agent) === []
                    && GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'catalogue')->where('symbol', $system)->exists())) {
                return false;
            }
            $this->meshDiscoveryAttempted = true;
            $this->measure('mesh_refresh', function () use ($discovery, $episode, $agent) {
                $discovery->advance($episode, $agent, $this->marketReads, $this->deadline);
            });

            return true;
        }
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
        $this->passOutcome = $run !== null ? 'started' : ($choice === 'awaiting_run' ? 'awaiting_run' : 'waiting');
        $gates = collect($considered)->last(fn ($candidate) => isset($candidate['gates']));
        if ($gates) {
            $failures = array_filter(array_map(fn ($gate) => $gate['pass'] ? null : $gate['reason'], $gates['gates']));
            $extra += ['saturation' => ['gates' => $gates['gates'], 'hold' => $gates['hold']], 'saturation_reasons' => $failures];
            $considered = array_values(array_filter($considered, fn ($candidate) => ! isset($candidate['gates'])));
        }
        app(CampaignControl::class)->event($grant, $episode, 'decision', ['credits' => $agent->credits, 'phase' => $episode->phase, 'candidates' => $considered, 'choice' => $choice, 'run_id' => $run] + $extra + ($this->localSummary ? ['local_earn' => $this->localSummary] : []));
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

    private function trade(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $ship, array $activity, array &$considered, array $busyShips = []): ?AutomationRun
    {
        if (app(CampaignSafety::class)->windDown($episode)) {
            $considered[] = ['strategy' => 'trade', 'rejected' => 'Wind-down blocks new trade buys.'];

            return null;
        }
        if (CampaignLocalEarn::policy($grant, $episode)) {
            return $this->localTrade($grant, $episode, $agent, $ship, $considered);
        }
        $trades = app(CampaignTrade::class);
        $ranked = $trades->rank($grant, $episode, $agent, $ship, $considered);
        if (! $ranked) {
            $considered[] = ['strategy' => 'trade', 'rejected' => 'No profitable observed pair fits the playbook and verified route bounds.'];
        }
        $arm = (bool) FleetClaims::arm($grant);
        if ($arm) {
            $ranked = $this->preferCommitted($grant, $episode, $ranked);
        }
        $blockedAtCurrentSource = false;
        foreach ($ranked as $candidate) {
            $this->candidate = $candidate;
            $commit = $arm ? ($episode->state['commit'] ?? null) : null;
            $committed = $commit && $candidate['key'] === $commit['key'] && $candidate['source'] === $commit['source'] && $candidate['source'] === $ship['nav']['waypointSymbol'];
            try {
                if ($candidate['source'] !== $ship['nav']['waypointSymbol']) {
                    if (! $arm && $blockedAtCurrentSource) {
                        $considered[] = $candidate + ['rejected' => 'Awaiting buyer quote for higher-ranked pair at current source.'];

                        continue;
                    }
                    // An arm flies to a source only if it can buy there: the buyer quote will still be
                    // within the commitment bound on arrival, or one of our ships can read the buyer.
                    if ($arm && ! $this->buyerObservable($agent, $candidate['buyer'])) {
                        $age = $this->buyerQuoteAge($agent, $candidate['buyer'], $candidate['good']);
                        if ($age === null || $age + ($candidate['position_seconds'] ?? 0) > $this->commitQuoteLimit()) {
                            $considered[] = $candidate + ['rejected' => 'Buyer quote at '.$candidate['buyer'].' would be past the '.$this->commitQuoteLimit()
                                .' s commitment bound on arrival, and no ship of ours can read it.', 'buyer_quote_age' => $age];

                            continue;
                        }
                    }

                    return $this->position($grant, $episode, $agent, $ship, $candidate['source']);
                }
                $pool = app(CampaignFleet::class)->poolEnabled($episode);
                if ($pool && ! $this->parked($agent, $candidate['buyer']) && in_array($candidate['buyer'], $this->probeTargets($episode), true)) {
                    $this->awaitingQuote = true;
                    $this->awaitedBuyer ??= $candidate['buyer'];
                    $blockedAtCurrentSource = true;
                    $this->candidate = null;
                    $considered[] = $candidate + ['rejected' => 'Probe already en route to buyer '.$candidate['buyer'].'.'];

                    continue;
                }
                $probe = $this->role($episode, 'probe');
                $mesh = CampaignMesh::policy($episode);
                if ($mesh && ! $this->parked($agent, $candidate['buyer']) && app(CampaignFleet::class)->probes($episode) !== []) {
                    $age = $this->buyerQuoteAge($agent, $candidate['buyer'], $candidate['good']);
                    if ($age === null || $age >= $grant->revision()->body['trade']['quote_max_age_seconds']) {
                        $this->awaitingQuote = true;
                        $this->awaitedBuyer ??= $candidate['buyer'];
                        $blockedAtCurrentSource = true;
                        $this->candidate = null;
                        $considered[] = $candidate + ['rejected' => 'Awaiting a positioned buyer quote.'];

                        continue;
                    }
                }
                if (! $mesh && (empty($episode->state['probe_position_failed']) || ! is_string($episode->state['probe_position_failed']) || CarbonImmutable::parse($episode->state['probe_position_failed'])->lt(now()->subMinutes(30))) && $probe && $probe['symbol'] !== $ship['symbol'] && ! in_array($probe['symbol'], $busyShips, true) && ! $this->parked($agent, $candidate['buyer']) && $probe['nav']['status'] !== 'IN_TRANSIT') {
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
                if ($committed) {
                    // Refreshed quotes end a commitment only when its pair stops ranking or another clears the switch ratio.
                    $refreshed = $this->preferCommitted($grant, $episode, $refreshed);
                }
                $fresh = $refreshed[0] ?? null;
                if (! $fresh || $fresh['key'] !== $candidate['key'] || $fresh['source'] !== $candidate['source']) {
                    $considered[] = $candidate + ['rejected' => 'Refreshed quotes changed the preferred pair; replan next tick.'];

                    return null;
                }
                $buyerAge = null;
                if ($committed) {
                    // The buyer was just read (fresh if any of our ships is parked there). Otherwise a
                    // committed buy may use a buyer quote up to the commitment bound. Past it, the arm
                    // waits only while one of our ships is at or flying to the buyer; else it lets go now.
                    $age = $this->buyerQuoteAge($agent, $fresh['buyer'], $fresh['good']);
                    $limit = $this->commitQuoteLimit();
                    if ($age === null || $age > $limit) {
                        $message = 'Committed pair: buyer quote at '.$fresh['buyer'].' is '.($age === null ? 'missing' : $age.' s old').', past the '.$limit.' s commitment bound.';
                        if (! $this->buyerObservable($agent, $fresh['buyer'])) {
                            $this->endCommit($grant, $episode, 'buyer_quote_unavailable', ['buyer_quote_age' => $age]);
                            $considered[] = $fresh + ['rejected' => $message.' No ship of ours can read it.'];

                            continue;
                        }
                        $this->awaitingQuote = true;
                        $this->candidate = null;
                        $considered[] = $fresh + ['rejected' => $message.' Awaiting a buyer quote.'];

                        return null;
                    }
                    // Every committed plan carries the bound, so a quote crossing the snapshot age mid-pass still buys.
                    $buyerAge = $limit;
                }
                // Shadow only: flag a low value-per-minute choice without changing it.
                $candidate = $fresh + ['value_floor' => $trades->valueFloor($episode, $fresh)];
                $this->candidate = $candidate;
                $limits = $this->limits($grant, $episode, $agent);
                $limits['max_spend'] = min($limits['max_spend'], $candidate['max_inventory'] + $candidate['fuel_safety_ceiling'] + $candidate['departure_refuel']);
                $plan = $limits + ['strategy' => 'trade', 'market' => $candidate['buyer'], 'symbol' => $candidate['good'], 'units' => $candidate['units'],
                    'min_sell_price' => (int) ceil($candidate['predicted_cost'] / $candidate['units']), 'min_margin' => 1,
                    'max_inventory' => $candidate['max_inventory'], 'max_fuel' => $ship['fuel']['capacity'],
                    'campaign_trade' => $candidate + ['quote_max_age_seconds' => $grant->revision()->body['trade']['quote_max_age_seconds']]
                        + ($buyerAge ? ['buyer_quote_max_age_seconds' => $buyerAge] : [])];
                $plan['site'] = $candidate['source'];
                $trades->quoteLife($agent, $plan, []);

                return app(AutomationRunner::class)->preview($agent, $ship['symbol'], $plan);
            } catch (GameException $exception) {
                if ($exception->retryAfter > 0) {
                    throw $exception;
                }
                $considered[] = $candidate + ['rejected' => $exception->getMessage()];
                if (! $arm && $exception instanceof StaleQuote
                    && ($this->buyerObservable($agent, $candidate['buyer']) || app(CampaignFleet::class)->probes($episode) !== [])) {
                    $this->awaitingQuote = true;
                    $this->awaitedBuyer ??= $candidate['buyer'];
                    $blockedAtCurrentSource = true;
                }
                if ($committed && ! empty($episode->refresh()->state['commit'])) {
                    // A stale-quote failure waits on the committed pair while a ship can read the buyer;
                    // any other failure ends the commitment and the next candidate is tried.
                    if ($exception instanceof StaleQuote && $this->buyerObservable($agent, $candidate['buyer'])) {
                        $this->awaitingQuote = true;
                        $this->candidate = null;

                        return null;
                    }
                    $this->endCommit($grant, $episode, $exception instanceof StaleQuote ? 'buyer_quote_unavailable' : 'rejected', ['message' => $exception->getMessage()]);
                }
            }
        }
        $this->candidate = null;

        return null;
    }

    private function localTrade(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $ship, array &$considered): ?AutomationRun
    {
        $this->localActionBefore = (int) ShipAction::where('agent_id', $agent->id)->max('id');
        $this->localRunIdBefore = (int) AutomationRun::where('agent_id', $agent->id)->max('id');
        $local = app(CampaignLocalEarn::class);
        $policy = CampaignLocalEarn::policy($grant, $episode);
        $ranked = $local->shortlist($grant, $episode, $agent, $ship, $considered, $this->localSummary, $this->deadline);
        $this->localSummary['prior_endpoint_reads'] = array_keys($this->marketReads);
        $positioning = $local->positioning($episode);
        $this->localSummary['positioning'] = $positioning + ['cap' => $policy['max_positionings_without_trade']];
        $attempts = 0;
        $rejectedForObservation = [];
        foreach ($ranked as $candidate) {
            if ($candidate['source'] !== $ship['nav']['waypointSymbol'] || $candidate['readiness'] > 1 || $attempts >= $policy['max_attempts']) {
                continue;
            }
            $local->reserve($this->deadline);
            $attempts++;
            $run = null;
            $attempt = ['good' => $candidate['good'], 'source' => $candidate['source'], 'buyer' => $candidate['buyer'], 'reads' => 0];
            try {
                foreach (array_unique([$candidate['source'], $candidate['buyer']]) as $market) {
                    $local->reserve($this->deadline);
                    if (! isset($this->marketReads[$market]) && $this->parked($agent, $market)) {
                        $this->marketReads[$market] = true;
                        $attempt['reads']++;
                        $this->localSummary['endpoint_reads'][] = $market;
                        app(AgentSynchronizer::class)->observe($agent, 'market', $market, app(SpaceTradersApi::class)->market($agent, $market));
                    }
                }
                $ask = app(OperationPlanner::class)->quote($agent, $candidate['source'], $candidate['good']);
                $bid = app(OperationPlanner::class)->quote($agent, $candidate['buyer'], $candidate['good']);
                $local->reserve($this->deadline);
                $this->localSummary['exact_rechecks']++;
                $candidate = $local->project($grant, $episode, $agent, $ship,
                    ['good' => $candidate['good'], 'source' => $candidate['source'], 'market' => $candidate['buyer']], $ask, $bid, function () use ($local) {
                        $local->reserve($this->deadline);
                        $this->localSummary['exact_projections']++;
                    });
                $candidate['value_floor'] = app(CampaignTrade::class)->valueFloor($episode, $candidate);
                $limits = $this->limits($grant, $episode, $agent);
                $limits['max_spend'] = min($limits['max_spend'], $candidate['max_inventory'] + $candidate['fuel_safety_ceiling'] + $candidate['departure_refuel']);
                $plan = $limits + ['strategy' => 'trade', 'site' => $candidate['source'], 'market' => $candidate['buyer'], 'symbol' => $candidate['good'],
                    'units' => $candidate['units'], 'min_sell_price' => (int) ceil($candidate['predicted_cost'] / $candidate['units']), 'min_margin' => 1,
                    'max_inventory' => $candidate['max_inventory'], 'max_fuel' => $ship['fuel']['capacity'],
                    'campaign_trade' => $candidate + ['quote_max_age_seconds' => $grant->revision()->body['trade']['quote_max_age_seconds']]];
                app(CampaignTrade::class)->quoteLife($agent, $plan, []);
                $local->reserve($this->deadline);
                $run = app(AutomationRunner::class)->preview($agent, $ship['symbol'], $plan);
                // Link even a deferred native preview to its authority for the normal resume mechanism.
                $run->update(['campaign_episode_id' => $episode->id]);
                $local->reserve($this->deadline);
                app(AutomationRunner::class)->startCampaign($agent, $run, $episode);
                $attempt['outcome'] = 'trade_started';
                $this->localSummary['attempts'][] = $attempt;
                $this->localSummary['end'] = 'trade_started';
                $this->candidate = $candidate;

                return $run;
            } catch (GameException $exception) {
                $attempt += ['outcome' => 'refused', 'stage' => $exception instanceof LocalEarnRefusal ? $exception->stage : ($exception instanceof StaleQuote ? 'quote' : 'closed_stop'), 'reason' => $exception->getMessage()];
                $this->localSummary['attempts'][] = $attempt;
                if (! $this->localRefusal($exception, $agent, $episode, $run)) {
                    throw $exception;
                }
                if (! $exception instanceof StaleQuote) {
                    $rejectedForObservation[$candidate['good'].'@'.$candidate['source'].'>'.$candidate['buyer']] = true;
                }
                $considered[] = $candidate + ['rejected' => $exception->getMessage(), 'stage' => $attempt['stage']];
            }
        }
        // Saturation observations are assigned by the governed mesh.
        $probe = isset(CampaignMesh::policy($episode)['saturation']) ? null : $this->role($episode, 'probe');
        $backoff = $episode->state['probe_position_failed'] ?? null;
        if ($probe && $probe['symbol'] !== $ship['symbol'] && $probe['nav']['status'] !== 'IN_TRANSIT'
            && app(FuelPlanner::class)->fuelFree($probe) && ! ReconMission::holds($agent, $probe['symbol'])
            && $local->unowned($agent, $probe['symbol']) && (! is_string($backoff) || CarbonImmutable::parse($backoff)->addMinutes(30)->lte(now()))) {
            $probeTargets = $this->probeTargets($episode);
            foreach ($ranked as $candidate) {
                $local->reserve($this->deadline);
                if ($candidate['source'] !== $ship['nav']['waypointSymbol'] || $this->parked($agent, $candidate['buyer'])
                    || in_array($candidate['buyer'], $probeTargets, true)
                    || isset($rejectedForObservation[$candidate['good'].'@'.$candidate['source'].'>'.$candidate['buyer']])) {
                    continue;
                }
                try {
                    app(CampaignTrade::class)->quoteLife($agent, ['site' => $candidate['source'], 'market' => $candidate['buyer'], 'symbol' => $candidate['good'], 'units' => $candidate['units'],
                        'campaign_trade' => ['quote_max_age_seconds' => $grant->revision()->body['trade']['quote_max_age_seconds']]], []);

                    continue;
                } catch (StaleQuote) {
                    // Only a missing loading-life buyer quote justifies this separate observation.
                }
                $local->reserve($this->deadline);
                $run = null;
                try {
                    $run = $this->localPosition($grant, $episode, $agent, $probe, $candidate, true);
                    app(AutomationRunner::class)->startCampaign($agent, $run, $episode);
                    $this->localSummary['end'] = 'free_observation_started';
                    $this->candidate = null;

                    return $run;
                } catch (GameException $exception) {
                    if (! $this->localRefusal($exception, $agent, $episode, $run)) {
                        throw $exception;
                    }
                    $episode->update(['state' => array_merge($episode->refresh()->state ?? [], ['probe_position_failed' => now()->toIso8601String()])]);
                    $considered[] = ['strategy' => 'probe', 'stage' => 'free_observation', 'rejected' => $exception->getMessage()];
                    break;
                }
            }
        }
        if ($positioning['count'] < $policy['max_positionings_without_trade']) {
            foreach ($ranked as $candidate) {
                $local->reserve($this->deadline);
                if ($candidate['source'] === $ship['nav']['waypointSymbol'] || ! $local->positionEvidence($grant, $agent, $ship, $candidate)) {
                    continue;
                }
                $local->reserve($this->deadline);
                $run = null;
                try {
                    $local->claims($episode, $candidate['good'], $candidate['source'], $candidate['buyer']);
                    $run = $this->localPosition($grant, $episode, $agent, $ship, $candidate, false);
                    app(AutomationRunner::class)->startCampaign($agent, $run, $episode);
                    $this->localSummary['end'] = 'paid_position_started';
                    $this->candidate = $candidate;

                    return $run;
                } catch (GameException $exception) {
                    if (! $this->localRefusal($exception, $agent, $episode, $run)) {
                        throw $exception;
                    }
                    $considered[] = $candidate + ['stage' => 'position', 'rejected' => $exception->getMessage()];
                    break;
                }
            }
        }
        $this->candidate = null;
        $local->reserve($this->deadline);

        return null;
    }

    /** Closed allowlist. Never continue after a request failure, action creation, or active owner. */
    private function localRefusal(GameException $exception, Agent $agent, CampaignEpisode $episode, ?AutomationRun $run): bool
    {
        if ($exception->apiCode !== null || $exception->uncertain || $exception->retryAfter > 0
            || ! ($exception instanceof LocalEarnRefusal || $exception instanceof StaleQuote)
            || ! CampaignLocalEarn::policy($episode->grant(), $episode) || app(CampaignSafety::class)->windDown($episode)
            || app(CampaignSafety::class)->reason($episode->grant(), $episode->refresh(), $agent)
            || $this->localOwnsWork($agent, $episode)
            || ($run && (ShipAction::where('automation_run_id', $run->id)->exists() || $run->fresh()->started_at))) {
            return false;
        }
        if ($run) {
            app(ShipCommandExecutor::class)->locked($agent, function () use ($run, $exception) {
                $run->refresh();
                if ($run->active_agent_id || $run->started_at || ShipAction::where('automation_run_id', $run->id)->exists()) {
                    throw new GameException('Local preview acquired work; candidate fallback is closed.');
                }
                $run->update(['state' => 'stopped', 'blocked_reason' => $exception->getMessage()]);
            });
        }

        return true;
    }

    private function localOwnsWork(Agent $agent, CampaignEpisode $episode, ?string $targetShip = null): bool
    {
        if (app(CampaignFleet::class)->poolEnabled($episode)) {
            $ship = $targetShip ?? (app(CampaignFleet::class)->role($episode, 'command')['symbol'] ?? null);

            return ShipAction::where('agent_id', $agent->id)->where('id', '>', $this->localActionBefore)
                ->where(fn ($q) => $q->when($ship !== null, fn ($inner) => $inner->where('ship_symbol', $ship))
                    ->orWhereIn('automation_run_id', AutomationRun::where('agent_id', $agent->id)->where('id', '>', $this->localRunIdBefore)->select('id')))
                ->exists()
                || AutomationRun::where('active_agent_id', $agent->id)
                    ->where(fn ($q) => $q->when($ship !== null, fn ($inner) => $inner->where('ship_symbol', $ship))
                        ->orWhere(fn ($inner) => $inner->where('campaign_episode_id', $episode->id)->where('id', '>', $this->localRunIdBefore)))
                    ->exists();
        }

        return ShipAction::where('agent_id', $agent->id)->where('id', '>', $this->localActionBefore)->exists()
            || AutomationRun::where('active_agent_id', $agent->id)->where(fn ($q) => $q->where('campaign_episode_id', $episode->id)->orWhereIn('ship_symbol', app(CampaignFleet::class)->symbols($episode)))->exists();
    }

    private function pauseForLocalStartFailure(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, GameException $exception, ?string $targetShip = null): bool
    {
        $reason = app(CampaignSafety::class)->reason($grant, $episode->refresh(), $agent);
        if (! $reason || $reason === 'consecutive_losing_loads') {
            if (! $exception->uncertain && ! $this->localOwnsWork($agent, $episode, $targetShip)) {
                return false;
            }
            $reason = 'state_requires_review';
        }
        app(CampaignControl::class)->pause($grant, $reason, $episode);

        return true;
    }

    private function localPosition(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $ship, array $candidate, bool $free): AutomationRun
    {
        $limits = $this->limits($grant, $episode, $agent);
        $limits['reserve'] = max($limits['reserve'], ReconMission::returnExposure($agent));
        $limits['max_spend'] = $free ? 0 : max(0, $agent->credits - $limits['reserve']);
        $run = app(AutomationRunner::class)->preview($agent, $ship['symbol'], $limits + [
            'strategy' => 'scout', 'destination' => $free ? $candidate['buyer'] : $candidate['source'], 'max_fuel' => $ship['fuel']['capacity'], 'one_way' => true,
            'departure_refuel' => ! $free, 'campaign_local_earn' => ['kind' => $free ? 'free_observation' : 'paid_position', 'candidate' => $candidate]]);
        $run->update(['campaign_episode_id' => $episode->id]);
        app(CampaignLocalEarn::class)->reserve($this->deadline);

        return $run;
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
                $this->marketReads[$symbol] = true;
                app(AgentSynchronizer::class)->observe($agent, 'market', $symbol, app(SpaceTradersApi::class)->market($agent, $symbol));
            } catch (LocalEarnBudget $exception) {
                throw $exception;
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
     *
     * @param  list<string>  $claimedTargets
     */
    private function scout(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $ship, array &$considered, bool $local = false, array $claimedTargets = []): ?AutomationRun
    {
        $report = app(OpportunityAssessor::class)->report($agent);
        $tasks = collect($report['observation_tasks'])->where('ship', $ship['symbol']);
        $waypoints = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', 'waypoint')->get()->keyBy('symbol');
        $origin = $waypoints->get($ship['nav']['waypointSymbol'])?->data;
        if ($local) {
            // A market with no goods directory is never priced either. The scout preview reads the
            // directory remotely before planning, so it needs only a verified route, not a ready task.
            $nearby = $this->colocated($agent, $ship);
            $claimed = array_values(array_unique([...$this->probeTargets($episode), ...$claimedTargets]));
            $tasks = $tasks->filter(fn ($task) => in_array($task['destination'], $nearby, true) && ! in_array($task['destination'], $claimed, true)
                && (($task['fact'] === 'Unknown prices' && $task['ready_for_bounded_preview']) || ($task['fact'] === 'Missing goods directory' && $task['route_options'] !== [])));
            if ($tasks->isEmpty()) {
                $considered[] = ['strategy' => 'scout', 'role' => 'command', 'rejected' => 'No unclaimed market at the command ship\'s coordinates needs a read.'];

                return null;
            }
        } else {
            $tasks = $tasks->where('ready_for_bounded_preview', true)
                ->when($claimedTargets !== [], fn ($rows) => $rows->reject(fn ($task) => in_array($task['destination'], $claimedTargets, true)));
        }
        $hasProbesPolicy = app(CampaignFleet::class)->probePolicy($episode) !== null;
        foreach (self::scoutOrder($tasks, collect($report['markets']), $hasProbesPolicy ? $waypoints : null, $hasProbesPolicy ? $origin : null) as $task) {
            if ($task['destination'] === $ship['nav']['waypointSymbol']) {
                continue;
            }
            try {
                if (CampaignLocalEarn::policy($grant, $episode)) {
                    app(CampaignLocalEarn::class)->reserve($this->deadline);
                }

                // One way: the scout parks at each market it prices and starts the next hop from there,
                // instead of flying home between markets. A local (command) scout tops up first at a fuel seller.
                return app(AutomationRunner::class)->preview($agent, $ship['symbol'], $this->limits($grant, $episode, $agent) + [
                    'strategy' => 'scout', 'destination' => $task['destination'], 'max_fuel' => $ship['fuel']['capacity'], 'one_way' => true]
                    + ($local ? ['departure_refuel' => true] : []));
            } catch (GameException $exception) {
                if ($exception instanceof LocalEarnBudget || $exception->retryAfter > 0) {
                    throw $exception;
                }
                $considered[] = ['strategy' => 'scout', 'destination' => $task['destination'], 'rejected' => $exception->getMessage()];
            }
        }

        return null;
    }

    /** @return list<string> Ships with an active run or a pending/sending action. */
    private function busyShips(Agent $agent, CampaignEpisode $episode): array
    {
        $busy = AutomationRun::where('active_agent_id', $agent->id)->where('reset_id', $episode->reset_id)->pluck('ship_symbol')->all();
        $fleet = app(CampaignFleet::class);
        foreach ($fleet->symbols($episode) as $symbol) {
            if ($fleet->pendingForShip($episode, $symbol)) {
                $busy[] = $symbol;
            }
        }

        return array_values(array_unique($busy));
    }

    /** @param list<string> $busyShips */
    private function refreshRoleMarkets(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, AgentSynchronizer $sync, array $busyShips, bool $includeCommand): void
    {
        $this->measure('mesh_refresh', function () use ($grant, $episode, $agent, $sync, $busyShips, $includeCommand) {
            if (CampaignMesh::policy($episode)) {
                if (isset(CampaignMesh::policy($episode)['saturation'])) {
                    $this->pollMesh($episode, $agent, 'urgent');
                } else {
                    app(CampaignMesh::class)->refresh($episode, $agent, $this->marketReads, $this->deadline, $this->activeBuyer($episode));
                }

                return;
            }
            $fleet = app(CampaignFleet::class);
            $observers = $fleet->probes($episode);
            if ($includeCommand) {
                $command = $fleet->role($episode, 'command');
                if ($command) {
                    array_unshift($observers, $command);
                }
            }
            $seenMarkets = [];
            foreach ($observers as $observer) {
                if (($observer['nav']['status'] ?? null) === 'IN_TRANSIT' || in_array($observer['symbol'], $busyShips, true)) {
                    continue;
                }
                $market = $observer['nav']['waypointSymbol'];
                if (isset($seenMarkets[$market])) {
                    continue;
                }
                $seenMarkets[$market] = true;
                $point = GameObservation::where('agent_id', $agent->id)->where('reset_id', $episode->reset_id)->where('kind', 'waypoint')->where('symbol', $market)->first();
                if (! in_array('MARKETPLACE', array_column($point?->data['traits'] ?? [], 'symbol'), true)) {
                    continue;
                }
                if (CampaignLocalEarn::policy($grant, $episode)) {
                    app(CampaignLocalEarn::class)->reserve($this->deadline);
                    $quote = GameObservation::where('agent_id', $agent->id)->where('reset_id', $episode->reset_id)->where('kind', 'market')->where('symbol', $market)->first();
                    if ($quote && empty($quote->data['invalidated_by_action']) && ! empty($quote->data['tradeGoods']) && $quote->observed_at->gt(now()->subSeconds(20))) {
                        continue;
                    }
                }
                $this->marketReads[$market] = true;
                $sync->observe($agent, 'market', $market, app(SpaceTradersApi::class)->market($agent, $market));
            }
        });
    }

    /** @param list<string> $busyShips */
    private function dispatchPurchases(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array &$busyShips, array &$considered): bool
    {
        return $this->measure('purchases', function () use ($grant, $episode, $agent, &$busyShips, &$considered) {
            $saturation = isset($grant->revision()->body['fleet']['haulers']);
            if ($saturation) {
                app(CampaignControl::class)->autoArm($grant, $episode, $agent);
            }
            try {
                $run = app(CampaignProbeBootstrap::class)->plan($grant, $episode, $agent, $busyShips, $considered, $this->deadline,
                    meshReads: $this->marketReads, purchaseYards: $this->meshPurchaseYards);
                if ($run && $saturation) {
                    $this->recordSaturation($grant, $episode, $agent);
                }
                if (! $run && $saturation && microtime(true) < $this->deadline) {
                    $run = app(CampaignHaulerPurchase::class)->plan($grant, $episode, $agent, $busyShips, $considered, $this->deadline, $this->marketReads);
                }
                if (! $run) {
                    return false;
                }
                if (! $run->started_at && ! $run->active_agent_id) {
                    app(AutomationRunner::class)->startCampaign($agent, $run, $episode);
                }
                $busyShips[] = $run->ship_symbol;
                $this->decision($grant, $episode, $agent, $considered, $run->strategy, $run->id, ['shipyard' => $run->plan['shipyard'] ?? $run->plan['destination'] ?? null]);

                return true;
            } catch (LocalEarnBudget) {
                throw new LocalEarnBudget;
            } catch (GameException $exception) {
                if ($exception->retryAfter > 0) {
                    $this->wait($grant, 'awaiting_run_start');

                    return false;
                }
                $considered[] = ['strategy' => 'buy_probe', 'rejected' => $exception->getMessage()];

                return false;
            }
        });
    }

    private function recordSaturation(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent): void
    {
        $saturation = app(CampaignSaturation::class);
        $saturation->record($grant, $episode, $saturation->evaluate($grant, $episode, $agent, offerIndependentOnly: true));
    }

    private function markArmRecovery(CampaignGrant $grant, CampaignEpisode $episode, bool $recovering): void
    {
        if (! $grant->arm()) {
            return;
        }
        $state = $episode->refresh()->state ?? [];
        if ($recovering === isset($state['recovering_since'])) {
            return;
        }
        if ($recovering) {
            $state['recovering_since'] = now()->toIso8601String();
        } else {
            unset($state['recovering_since']);
        }
        $episode->update(['state' => $state]);
        app(CampaignControl::class)->event($grant, $episode, $recovering ? 'arm_recovery_started' : 'arm_recovery_ended', []);
    }

    /**
     * The buyer waypoint C0 is currently trading, positioning for, or awaiting a quote on in earn.
     * Pool mode keeps one probe stationed here so buyer quotes stay fresh across trade loads.
     */
    private function activeBuyer(CampaignEpisode $episode): ?string
    {
        if ($episode->phase !== 'earn' || app(CampaignSafety::class)->windDown($episode)) {
            return null;
        }
        $command = app(CampaignFleet::class)->role($episode, 'command')['symbol'] ?? null;
        if ($command !== null) {
            $tradeBuyer = AutomationRun::where('active_agent_id', $episode->agent_id)
                ->where('campaign_episode_id', $episode->id)
                ->where('ship_symbol', $command)
                ->where('strategy', 'trade')
                ->first()?->plan['market'] ?? null;
            if (is_string($tradeBuyer) && $tradeBuyer !== '') {
                return $tradeBuyer;
            }
        }
        $claimBuyer = $episode->state['claim']['buyer'] ?? null;
        if (is_string($claimBuyer) && $claimBuyer !== '') {
            return $claimBuyer;
        }

        return $this->awaitedBuyer;
    }

    /**
     * Dispatches every idle, stationary pool probe to a distinct market, while holding one probe at
     * C0's active earn buyer (or routing an idle probe there first) so trade quotes stay fresh.
     *
     * @param  list<string>  $busyShips
     * @param  list<string>  $assignedTargets
     */
    private function dispatchPoolProbes(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array &$busyShips, array &$assignedTargets, array &$considered): bool
    {
        return $this->measure('mesh_dispatch', function () use ($grant, $episode, $agent, &$busyShips, &$assignedTargets, &$considered) {
            if (CampaignMesh::policy($episode)) {
                return $this->dispatchMesh($grant, $episode, $agent, $busyShips, $assignedTargets, $considered);
            }
            $started = false;
            $localEarn = CampaignLocalEarn::policy($grant, $episode);
            $probes = app(CampaignFleet::class)->probes($episode);
            $activeBuyer = $this->activeBuyer($episode);
            $heldProbe = null;
            $buyerCovered = $activeBuyer === null;
            $buyerTargeted = $activeBuyer !== null && in_array($activeBuyer, $assignedTargets, true);
            if ($activeBuyer !== null) {
                foreach ($probes as $probe) {
                    if (($probe['nav']['status'] ?? null) !== 'IN_TRANSIT'
                        && ($probe['nav']['waypointSymbol'] ?? null) === $activeBuyer
                        && ($probe['cargo']['units'] ?? 0) === 0
                        && ! in_array($probe['symbol'], $busyShips, true)) {
                        $heldProbe = $probe['symbol'];
                        $buyerCovered = true;
                        if (! in_array($activeBuyer, $assignedTargets, true)) {
                            $assignedTargets[] = $activeBuyer;
                        }
                        break;
                    }
                }
            }
            $probeBackoff = $episode->state['probe_position_failed'] ?? null;
            $buyerQuote = $activeBuyer !== null
                ? GameObservation::where('agent_id', $agent->id)->where('reset_id', $episode->reset_id)->where('kind', 'market')->where('symbol', $activeBuyer)->first()
                : null;
            $buyerNeedsRefresh = $activeBuyer !== null
                && (! $buyerQuote || ! empty($buyerQuote->data['invalidated_by_action']) || empty($buyerQuote->data['tradeGoods'])
                    || $buyerQuote->observed_at->lte(now()->subSeconds(min(60, (int) config('gameplay.snapshot_seconds')))));
            $canPositionBuyer = ! $buyerCovered && ! $buyerTargeted && $buyerNeedsRefresh
                && ! $this->parked($agent, $activeBuyer)
                && (! is_string($probeBackoff) || CarbonImmutable::parse($probeBackoff)->lt(now()->subMinutes(30)));
            foreach ($probes as $probe) {
                if (($probe['nav']['status'] ?? null) === 'IN_TRANSIT'
                    || ($probe['cargo']['units'] ?? 0) !== 0
                    || in_array($probe['symbol'], $busyShips, true)
                    || $probe['symbol'] === $heldProbe) {
                    continue;
                }
                try {
                    if ($localEarn) {
                        app(CampaignLocalEarn::class)->reserve($this->deadline);
                    }
                    $run = null;
                    if ($canPositionBuyer && app(FuelPlanner::class)->fuelFree($probe) && ($probe['nav']['waypointSymbol'] ?? null) !== $activeBuyer) {
                        try {
                            $run = $this->position($grant, $episode, $agent, $probe, $activeBuyer);
                            $canPositionBuyer = false;
                            $buyerTargeted = true;
                        } catch (GameException $exception) {
                            if ($exception instanceof LocalEarnBudget || $exception->retryAfter > 0) {
                                throw $exception;
                            }
                            if (! $exception->uncertain && $exception->apiCode === null) {
                                $episode->update(['state' => array_merge($episode->refresh()->state ?? [], ['probe_position_failed' => now()->toIso8601String()])]);
                                $canPositionBuyer = false;
                            }
                            $considered[] = ['role' => 'probe', 'strategy' => 'scout', 'ship' => $probe['symbol'], 'destination' => $activeBuyer, 'rejected' => $exception->getMessage()];
                        }
                    }
                    $run ??= $this->scout($grant, $episode, $agent, $probe, $considered, false, $assignedTargets);
                    if (! $run) {
                        continue;
                    }
                    if (! $run->active_agent_id) {
                        app(AutomationRunner::class)->startCampaign($agent, $run, $episode);
                    }
                    $busyShips[] = $run->ship_symbol;
                    if (isset($run->plan['destination'])) {
                        $assignedTargets[] = $run->plan['destination'];
                    }
                    $this->decision($grant, $episode, $agent, $considered, 'scout', $run->id);
                    $started = true;
                } catch (LocalEarnBudget) {
                    if (! $started) {
                        $this->localSummary['end'] = 'local_budget_deferred';
                        $this->decision($grant, $episode, $agent, $considered, 'local_budget_deferred');
                        $this->wait($grant, 'local_budget_deferred');
                    }

                    break;
                } catch (GameException $exception) {
                    if ($exception->retryAfter > 0) {
                        if (! $started) {
                            $this->wait($grant, 'awaiting_run_start');
                        }

                        break;
                    }
                    $considered[] = ['role' => 'probe', 'strategy' => 'scout', 'ship' => $probe['symbol'], 'rejected' => $exception->getMessage()];
                }
            }

            return $started;
        });
    }

    private function dispatchMesh(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array &$busy, array &$reserved, array &$considered): bool
    {
        if (isset(CampaignMesh::policy($episode)['saturation'])) {
            try {
                return $this->dispatchSaturationMesh($grant, $episode, $agent, $busy, $reserved, $considered);
            } finally {
                app(CampaignMesh::class)->clearPurchaseHold($episode);
            }
        }
        $failed = $episode->state['probe_position_failed'] ?? null;
        if ($failed && CarbonImmutable::parse($failed)->gt(now()->subMinutes(30))) {
            return false;
        }
        $started = false;
        $buyer = $this->activeBuyer($episode);
        foreach (app(CampaignMesh::class)->assignments($episode, $agent, $busy, $reserved, $buyer) as [$ship, $destination]) {
            if (microtime(true) >= $this->deadline) {
                break;
            }
            try {
                $limits = array_replace($this->limits($grant, $episode, $agent), ['reserve' => $grant->limits['reserve_credits']]);
                $runner = app(AutomationRunner::class);
                $run = $runner->previewMeshScout($agent, $ship, $destination, $limits, $episode);
                $runner->startCampaign($agent, $run, $episode);
                $busy[] = $ship['symbol'];
                $reserved[] = $destination;
                $this->decision($grant, $episode, $agent, $considered, 'scout', $run->id);
                $started = true;
            } catch (GameException $exception) {
                if ($exception->retryAfter > 0) {
                    $this->wait($grant, 'awaiting_run_start');

                    break;
                }
                if (! $exception->uncertain && $exception->apiCode === null) {
                    $episode->update(['state' => array_merge($episode->refresh()->state ?? [], ['probe_position_failed' => now()->toIso8601String()])]);
                }
                $considered[] = ['strategy' => 'scout', 'ship' => $ship['symbol'], 'destination' => $destination, 'rejected' => $exception->getMessage()];
            }
        }

        return $started;
    }

    private function pollMesh(CampaignEpisode $episode, Agent $agent, string $stage): void
    {
        if (microtime(true) >= $this->deadline) {
            $this->meshPolling[$stage] = ['hold' => 'pass_deadline'];

            return;
        }
        $policy = CampaignMesh::policy($episode);
        $this->meshPassEvidence ??= app(FleetMetrics::class)->meshPassEvidence($agent->name);
        $passes = $this->meshPassEvidence['eligible'];
        $capacity = $episode->opened_at->lte(now()->subHour()) && $passes > 0 ? $passes * $policy['reads_per_pass'] : null;
        $this->meshPolling[$stage] = app(MeshPoller::class)->refresh($episode, $agent, $this->marketReads, $this->deadline,
            $this->activeBuyer($episode), $stage === 'routine' ? 'routine' : $stage, $this->meshPurchaseYards, $capacity);
    }

    private function dispatchSaturationMesh(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array &$busy, array &$reserved, array &$considered): bool
    {
        $mesh = app(CampaignMesh::class);
        $budget = CampaignMesh::policy($episode)['dispatches_per_pass'];
        $pins = [];
        $started = 0;
        $plan = [];
        $stop = false;
        $held = [];
        for ($iteration = 0; $iteration <= 2 && $started < $budget && microtime(true) < $this->deadline; $iteration++) {
            $plan = $mesh->dispatchPlan($episode, $agent, $busy, $reserved, $this->activeBuyer($episode), $pins);
            $probes = collect($mesh->probes($episode, $agent))->keyBy('symbol');
            $freshPins = [];
            $floor = $mesh->departureEvidence($episode, $agent);
            $plan['verified_hubs'] = $floor['observers'];
            $plan['queued_departures'] = $floor['departures'];
            // Test proposed departures before their backfills can consume the exact occupied destinations.
            foreach ($plan['moves'] as $move) {
                if (microtime(true) >= $this->deadline) {
                    break;
                }
                $projection = $mesh->floorAfter($floor, $probes[$move['ship']]);
                if (! $projection['pass']) {
                    $freshPins[$move['ship']] = $move['origin'];
                    $held[] = ['ship' => $move['ship'], 'destination' => $move['destination'], 'hold' => 'G3_floor', 'coverage' => $projection];
                }
            }
            foreach ($plan['moves'] as $move) {
                if (microtime(true) >= $this->deadline || $started >= $budget) {
                    break;
                }
                if (isset($freshPins[$move['ship']]) || in_array($move['destination'], $freshPins, true)) {
                    continue;
                }
                if ($move['patrol'] && ! isset($this->meshPolling['routine'])) {
                    $this->pollMesh($episode, $agent, 'routine');
                    if (in_array($move['destination'], $this->meshPolling['routine']['successful'] ?? [], true)) {
                        continue;
                    }
                }
                $ship = $probes[$move['ship']];
                try {
                    $mesh->assertDeparture($episode, $agent, $ship, $move['destination']);
                    $run = $this->launchMeshScout($grant, $episode, $agent, $ship, $move['destination']);
                    $busy[] = $ship['symbol'];
                    $reserved[] = $move['destination'];
                    $started++;
                    if ($move['patrol']) {
                        $state = $episode->refresh()->state ?? [];
                        $state['mesh_patrol_at'][$move['bucket']] = now()->timestamp;
                        $episode->update(['state' => $state]);
                    }
                    $this->decision($grant, $episode, $agent, $considered, 'scout', $run->id);
                } catch (GameException $exception) {
                    $held[] = ['ship' => $ship['symbol'], 'destination' => $move['destination'], 'hold' => $exception->getMessage()];
                    if (str_starts_with($exception->getMessage(), 'Mesh G3 floor')) {
                        $freshPins[$ship['symbol']] = $move['origin'];
                    }
                    if ($exception->retryAfter > 0 || $exception->uncertain) {
                        $stop = true;
                        break;
                    }
                }
            }
            if ($stop || array_diff_key($freshPins, $pins) === []) {
                break;
            }
            $pins += $freshPins;
        }
        if (! isset($this->meshPolling['routine'])) {
            $this->pollMesh($episode, $agent, 'routine');
        }
        $considered = [...$considered, ...$held];
        $this->recordMeshPlan($grant, $episode, $plan, $pins, $held);

        return $started > 0;
    }

    private function launchMeshScout(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $ship, string $destination): AutomationRun
    {
        $run = null;
        try {
            $runner = app(AutomationRunner::class);
            $limits = ['minutes' => $grant->limits['run_max_minutes'], 'reserve' => $grant->limits['reserve_credits']];
            $run = $runner->previewMeshScout($agent, $ship, $destination, $limits, $episode);

            return $runner->startCampaign($agent, $run, $episode, $this->deadline);
        } catch (GameException $exception) {
            $mesh = app(CampaignMesh::class);
            $unsent = ! $exception->uncertain && (! $run || $mesh->invalidatePreview($agent, $run));
            if ($unsent && $exception->retryAfter === 0 && $exception->apiCode === null && CampaignMesh::dispatchFailure($exception->getMessage())) {
                $mesh->failure($episode, $ship['symbol'], $destination, $run?->id);
            }
            throw $exception;
        }
    }

    private function recordMeshPlan(CampaignGrant $grant, CampaignEpisode $episode, array $plan, array $pins, array $holds): void
    {
        $state = $episode->refresh()->state ?? [];
        $failures = array_filter($state['mesh_dispatch_failed'] ?? [], fn ($failure) => CarbonImmutable::parse($failure['at'])->gt(now()->subMinutes(30)));
        $payload = array_diff_key($plan, ['elapsed_ms' => true]) + ['pins' => $pins, 'holds' => $holds];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        if (($state['mesh_plan_hash'] ?? null) !== $hash) {
            app(CampaignControl::class)->event($grant, $episode, 'mesh_plan', $payload + ['elapsed_ms' => $plan['elapsed_ms'] ?? null]);
            $state['mesh_plan_hash'] = $hash;
        }
        if (isset($state['mesh_dispatch_failed'])) {
            $state['mesh_dispatch_failed'] = $failures;
        }
        if ($state !== ($episode->state ?? [])) {
            $episode->update(['state' => $state]);
        }
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

    /** @return list<string> Markets any episode ship is flying to or has a preview or active run for. */
    private function probeTargets(CampaignEpisode $episode): array
    {
        if (isset(CampaignMesh::policy($episode)['saturation'])) {
            $agent = Agent::findOrFail($episode->agent_id);

            return app(CampaignMesh::class)->context($episode, $agent)['reserved'];
        }
        $shipSymbols = array_values(array_unique([
            ...app(CampaignFleet::class)->symbols($episode),
            ...AutomationRun::where('campaign_episode_id', $episode->id)->pluck('ship_symbol')->all(),
        ]));
        $probeSymbols = array_column(app(CampaignFleet::class)->probes($episode), 'symbol');
        $targets = AutomationRun::where('agent_id', $episode->agent_id)->where('reset_id', $episode->reset_id)
            ->where(fn ($query) => $query->whereIn('ship_symbol', $probeSymbols)
                ->orWhere(fn ($scouts) => $scouts->whereIn('ship_symbol', $shipSymbols)->where('strategy', 'scout')))
            ->where(fn ($query) => $query->where('state', 'preview')->orWhereNotNull('active_agent_id'))->get()
            ->map(fn ($run) => $run->plan['destination'] ?? $run->plan['market'] ?? $run->plan['shipyard'] ?? null)->filter()->all();
        foreach (Ship::where('agent_id', $episode->agent_id)->whereIn('symbol', $shipSymbols)->get() as $ship) {
            if (($ship->snapshot['nav']['status'] ?? null) === 'IN_TRANSIT') {
                $targets[] = $ship->snapshot['nav']['route']['destination']['symbol'] ?? $ship->snapshot['nav']['waypointSymbol'];
            }
        }

        return array_values(array_unique($targets));
    }

    /**
     * Never-priced markets come first, prioritizing non-FUEL_STATION trade hubs and closer markets
     * when waypoint coordinates are provided, then the market priced longest ago.
     */
    public static function scoutOrder(Collection $tasks, Collection $markets, ?Collection $waypoints = null, ?array $origin = null): Collection
    {
        $pricedAt = $markets->pluck('price_at', 'symbol');
        $isFuelStation = static fn (string $symbol): int => ($waypoints?->get($symbol)?->data['type'] ?? null) === 'FUEL_STATION' ? 1 : 0;
        $distance = static function (string $symbol) use ($waypoints, $origin): float {
            if (! $waypoints || ! $origin) {
                return 0.0;
            }
            $point = $waypoints->get($symbol)?->data;
            if (! $point || ! isset($point['x'], $point['y'], $origin['x'], $origin['y'])) {
                return INF;
            }

            return hypot($point['x'] - $origin['x'], $point['y'] - $origin['y']);
        };

        return $tasks->sortBy([
            fn ($a, $b) => ($a['fact'] !== 'Unknown prices') <=> ($b['fact'] !== 'Unknown prices'),
            fn ($a, $b) => ($waypoints && $a['fact'] === 'Unknown prices' && $b['fact'] === 'Unknown prices')
                ? ($isFuelStation($a['destination']) <=> $isFuelStation($b['destination']))
                : 0,
            fn ($a, $b) => ($waypoints && $origin && $a['fact'] === 'Unknown prices' && $b['fact'] === 'Unknown prices')
                ? ($distance($a['destination']) <=> $distance($b['destination']))
                : 0,
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

    private function positionRecoveryObserver(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $ship, string $buyer, bool $settlementOnly): bool
    {
        $mesh = isset(CampaignMesh::policy($episode)['saturation']);
        $atBuyer = $ship['nav']['waypointSymbol'] === $buyer;
        if (! $mesh && ($atBuyer || $settlementOnly)) {
            return false;
        }
        $markets = GameObservation::where('agent_id', $agent->id)->where('reset_id', $episode->reset_id)->where('kind', 'market')->orderBy('symbol')->get();
        $quoteAge = $mesh ? $grant->revision()->body['trade']['quote_max_age_seconds'] : config('gameplay.snapshot_seconds');
        $fresh = fn ($row) => $row && (! $mesh || $row->observer_ship) && empty($row->data['invalidated_by_action'])
            && $row->observed_at->gte(now()->subSeconds($quoteAge));
        $fuel = $markets->filter(fn ($row) => collect($row->data['tradeGoods'] ?? [])->contains(fn ($good) => $good['symbol'] === 'FUEL' && $good['purchasePrice'] > 0 && $good['tradeVolume'] > 0));
        $destination = ! $fresh($markets->firstWhere('symbol', $buyer)) ? $buyer
            : ($atBuyer || $fuel->contains($fresh) ? null : $fuel->first()?->symbol);
        if (! $destination) {
            return false;
        }
        // One of our ships already at the destination (the role probe or a parked extra one) reads it
        // directly, before any wait on or trip by the role probe.
        if ($mesh) {
            return $this->positionMeshRecoveryObserver($grant, $episode, $agent, $destination, $settlementOnly);
        }
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

    private function positionMeshRecoveryObserver(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, string $destination, bool $settlementOnly): bool
    {
        if (microtime(true) >= $this->deadline) {
            return false;
        }
        $settlementOnly = app(CampaignRecovery::class)->settlementOnly($episode, $settlementOnly);
        $observer = Ship::where('agent_id', $agent->id)->orderBy('symbol')->get()->first(fn (Ship $row) => ($row->snapshot['nav']['waypointSymbol'] ?? null) === $destination && ($row->snapshot['nav']['status'] ?? null) !== 'IN_TRANSIT');
        if ($observer) {
            try {
                app(MeshPoller::class)->read($episode, $agent, $destination, 'market', $observer->symbol, $settlementOnly ? 'settlement' : 'demand', $this->marketReads);
            } catch (RequestDeferred $exception) {
                FleetMetrics::observe($exception);
            }

            return false;
        }
        if ($settlementOnly) {
            return false;
        }
        $mesh = app(CampaignMesh::class);
        $model = $mesh->model($episode, $agent);
        $context = $mesh->context($episode, $agent);
        if (! isset($model->targets[$destination])) {
            return false;
        }
        $probes = $mesh->probes($episode, $agent);
        if (in_array($destination, $context['reserved'], true)) {
            $waiting = $mesh->hasRecoveryObserver($episode, $agent, $model, $probes, $destination, $context['seconds_left']);
            if ($waiting) {
                $this->decision($grant, $episode, $agent, [], 'awaiting_recovery_observer');
            }

            return $waiting;
        }
        [$observers] = $mesh->coverage($episode, $agent);
        $planner = app(MeshPlanner::class);
        $policy = CampaignMesh::policy($episode)['saturation'];
        $eligible = [];
        foreach ($probes as $ship) {
            $origin = $ship['nav']['waypointSymbol'];
            if (count($observers[$origin] ?? []) === 1
                || $planner->restriction($ship, $context['busy'], $context['backoff'], $policy, $context['now']) !== null) {
                continue;
            }
            $seconds = $planner->route($model, $ship, $destination, $context['seconds_left']);
            if ($seconds !== null) {
                $eligible[] = [$seconds, $ship['symbol'], $ship];
            }
        }
        usort($eligible, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        foreach ($eligible as [,, $ship]) {
            if (microtime(true) >= $this->deadline) {
                break;
            }
            try {
                $mesh->assertDeparture($episode, $agent, $ship, $destination);
                $run = $this->launchMeshScout($grant, $episode, $agent, $ship, $destination);
                $this->decision($grant, $episode, $agent, [], 'recovery_evidence', $run->id);

                return true;
            } catch (GameException $exception) {
                FleetMetrics::observe($exception);
                if ($exception->retryAfter > 0 || $exception->uncertain) {
                    break;
                }
            }
        }

        return false;
    }

    /** @param list<string> $busyShips */
    private function recover(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $recovery, bool $draining, array $busyShips, bool $settlementOnly): bool
    {
        $mesh = isset(CampaignMesh::policy($episode)['saturation']);
        $settlementOnly = app(CampaignRecovery::class)->settlementOnly($episode, $draining || $settlementOnly);
        $missingCargoEvidence = ! $recovery['commandIdentified'];
        foreach ($recovery['ships'] as $ship) {
            $units = $ship['cargo']['units'] ?? null;
            if (! is_numeric($units) || (float) $units < 0) {
                $missingCargoEvidence = true;

                continue;
            }
            if ((float) $units === 0.0) {
                continue;
            }
            if (in_array($ship['symbol'], $busyShips, true)) {
                if ($draining) {
                    return true;
                }

                continue;
            }
            if ($draining && $ship['nav']['status'] === 'IN_TRANSIT'
                && isset($ship['nav']['route']['arrival']) && CarbonImmutable::parse($ship['nav']['route']['arrival'])->lte(now())) {
                $ship = app(SpaceTradersClient::class)->scope(
                    fn () => app(AgentSynchronizer::class)->refreshShip($agent, $ship['symbol'])->snapshot,
                    priority: 'settlement',
                );
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
            if (! $draining && ! $mesh) {
                $this->refreshRecoveryEvidence($episode, $agent, $ship);
            }
            $good = array_key_first(MiningPlanner::inventory($ship));
            $buyerQuotes = fn () => GameObservation::where('agent_id', $agent->id)->where('reset_id', $episode->reset_id)->where('kind', 'market')->get()
                ->map(fn ($row) => ['buyer' => $row->symbol, 'bid' => collect($row->data['tradeGoods'] ?? [])->firstWhere('symbol', $good)['sellPrice'] ?? 0])
                ->filter(fn ($row) => $row['bid'] > 0)->sort(fn ($left, $right) => $right['bid'] <=> $left['bid'] ?: $left['buyer'] <=> $right['buyer']);
            $buyers = $buyerQuotes();
            if ($buyers->isEmpty() && $draining && ! $mesh) {
                app(SpaceTradersClient::class)->scope(fn () => $this->refreshRecoveryEvidence($episode, $agent, $ship), priority: 'settlement');
                $buyers = $buyerQuotes();
            }
            if ($mesh) {
                $listedBuyers = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                    ->where('kind', 'market_listing')->orderBy('symbol')->get()
                    ->filter(fn (GameObservation $row) => collect([...($row->data['imports'] ?? []), ...($row->data['exchange'] ?? [])])->contains('symbol', $good))
                    ->reject(fn (GameObservation $row) => $buyers->contains('buyer', $row->symbol))
                    ->map(fn (GameObservation $row) => ['buyer' => $row->symbol, 'bid' => 0]);
                $buyers = $buyers->concat($listedBuyers);
            }
            if ($buyers->isEmpty()) {
                if ($mesh && ! $settlementOnly) {
                    $this->decision($grant, $episode, $agent, [], 'awaiting_cargo_buyer_quote_or_route');
                } else {
                    app(CampaignControl::class)->pause($grant, 'no_cargo_buyer', $episode);
                }

                return true;
            }
            $state = $episode->state ?? [];
            $state['recovery_since'][$ship['symbol']] ??= now()->toIso8601String();
            $episode->update(['state' => $state]);
            $previous = AutomationRun::where('campaign_episode_id', $episode->id)->where('ship_symbol', $ship['symbol'])->where('strategy', 'trade')->latest('id')->first();
            $considered = [];
            foreach ($buyers as $candidate) {
                if ($this->positionRecoveryObserver($grant, $episode, $agent, $ship, $candidate['buyer'], $settlementOnly)) {
                    return true;
                }
                try {
                    $origin = $ship['nav']['waypointSymbol'];
                    $fuel = app(FuelPlanner::class);
                    if ($mesh && $origin !== $candidate['buyer'] && $ship['fuel']['current'] < $ship['fuel']['capacity']
                        && $fuel->listsFuel($agent, $origin) && ! $fuel->sellerQuote($agent, $origin)) {
                        $this->positionMeshRecoveryObserver($grant, $episode, $agent, $origin, $settlementOnly);
                    }
                    $limits = $this->limits($grant, $episode, $agent) + ['recovery_started_at' => $state['recovery_since'][$ship['symbol']],
                        'recovery_of' => $previous && ! empty($previous->totals['unsold'][$good]) ? $previous->id : null,
                        'mesh_settlement_only' => $mesh && $settlementOnly];
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
        if ($draining && $missingCargoEvidence) {
            $this->decision($grant, $episode, $agent, [], 'awaiting_cargo_snapshot');

            return true;
        }
        $state = $episode->state ?? [];
        unset($state['recovery_since'], $state['recovery_attempts_since']);
        $episode->update(['state' => $state]);

        return false;
    }
}
