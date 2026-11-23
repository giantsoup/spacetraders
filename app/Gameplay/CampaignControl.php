<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\CampaignRevision;
use App\Models\ReconMission;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CampaignControl
{
    /** Decision events keep at most this many duration-only rejections, highest predicted margin first. */
    public const LONG_RUN_REJECTIONS = 5;

    public function approve(CampaignRevision $revision, User $user, ?CarbonImmutable $expires = null, ?array $replacement = null): CampaignGrant
    {
        return app(HostLock::class)->scoped('campaign', function () use ($revision, $user, $expires, $replacement) {
            app(CampaignPlaybook::class)->validate($revision->body);
            if (isset($revision->body['fleet']['auto_arm'])) {
                $this->armRevision($revision);
            }
            $symbol = strtoupper((string) $revision->body['agent']['symbol']);
            $current = CampaignGrant::primaryFor($user->id, $symbol)->first();
            if (isset($revision->body['trade']['local_earn']) && $current) {
                try {
                    return app(CampaignLocalEarnReplacement::class)->approve($this, $current, $revision, $user, $expires, $replacement);
                } catch (GameException $exception) {
                    $this->event($current, null, 'replacement_refused', ['revision' => $revision->id, 'reason' => $exception->getMessage()]);
                    throw $exception;
                }
            }
            if ($expires && $expires->isPast()) {
                throw new GameException('Grant expiry must be in the future.');
            }
            if (isset($revision->body['trade']['local_earn']) && (! $expires || ($revision->body['phases'][0]['id'] ?? null) !== 'earn')) {
                throw new GameException('Local Earn approval requires an Earn-first revision and an explicit expiry.');
            }

            $approve = fn () => DB::transaction(function () use ($revision, $user, $expires, $symbol) {
                app(CampaignPlaybook::class)->validate($revision->body);
                CampaignGrant::pendingFor($user->id, $symbol)->get()->each(fn ($grant) => $grant->update(['slot' => null, 'superseded_at' => now()]));
                $current = CampaignGrant::primaryFor($user->id, $symbol)->first();
                $busy = $current && ($current->isDraining() || str_starts_with($current->pause_reason ?? '', 'drain_pause:') || $this->busy($current));
                if ($current && ! $busy) {
                    $this->supersede($current);
                }
                $grant = CampaignGrant::create(['campaign_revision_id' => $revision->id, 'revision_hash' => $revision->content_hash,
                    'user_id' => $user->id, 'agent_symbol' => $symbol, 'limits' => $revision->body['limits'], 'expires_at' => $expires, 'slot' => $busy ? 2 : 1]);
                $this->event($grant, null, 'approval', ['revision' => $revision->id, 'agent_symbol' => $symbol, 'pending' => $busy]);

                return $grant;
            });
            $agent = $this->agent($user->id, $symbol);

            return $agent ? app(HostLock::class)->retry('agent-'.$agent->id, $approve) : $approve();
        }, 15);
    }

    /**
     * Approves one arm: a slot-less grant whose own episode directs exactly one test ship.
     * $arm: label, ship, and optional follow, max_net_loss, max_load_spend, exposure_share.
     */
    public function approveArm(CampaignRevision $revision, User $user, array $arm, CarbonImmutable $expires): CampaignGrant
    {
        if ($expires->isPast()) {
            throw new GameException('Grant expiry must be in the future.');
        }
        $arm = ['label' => (string) ($arm['label'] ?? ''), 'ship' => (string) ($arm['ship'] ?? ''), 'follow' => ($arm['follow'] ?? null) ?: null,
            'max_net_loss' => (int) ($arm['max_net_loss'] ?? 50000), 'max_load_spend' => isset($arm['max_load_spend']) ? (int) $arm['max_load_spend'] : null,
            'exposure_share' => (float) ($arm['exposure_share'] ?? 0.5)];
        if ($arm['label'] === 'C0' || ! preg_match('/\A[A-Za-z0-9_-]{1,20}\z/', $arm['label']) || $arm['max_net_loss'] < 0 || ($arm['max_load_spend'] ?? 0) < 0 || $arm['exposure_share'] <= 0 || $arm['exposure_share'] > 1) {
            throw new GameException('Arm needs a short label, a nonnegative loss stop and load cap, and an exposure share in (0, 1].');
        }

        return app(HostLock::class)->scoped('campaign', function () use ($revision, $user, $arm, $expires) {
            $agent = $this->agent($user->id, $revision->body['agent']['symbol'])
                ?? throw new GameException('Ship '.$arm['ship'].' is not in the agent\'s synced fleet.');

            return app(HostLock::class)->scoped('agent-'.$agent->id, fn () => DB::transaction(
                fn () => $this->createArm($revision, $agent->refresh(), $arm, $expires)
            ));
        }, 15);
    }

    public function agent(int $user, string $symbol): ?Agent
    {
        return Agent::where('user_id', $user)->where('name', strtoupper($symbol))->unarchived()->whereNotNull('reset_id')->latest('id')->first();
    }

    private function armRevision(CampaignRevision $revision): CampaignRevision
    {
        $hash = strtolower($revision->body['fleet']['auto_arm']['revision_hash']);
        $arm = CampaignRevision::where('content_hash', $hash)->first()
            ?? throw new GameException('Import the pinned auto-arm revision before approval.');
        app(CampaignPlaybook::class)->validate($arm->body);
        if (! hash_equals($hash, hash('sha256', $arm->source)) || isset($arm->body['trade']['local_earn']) || $arm->body['agent']['symbol'] !== $revision->body['agent']['symbol']) {
            throw new GameException('Auto-arm revision must match its imported hash and authorize ordinary trade for the same agent.');
        }

        return $arm;
    }

    private function createArm(CampaignRevision $revision, Agent $agent, array $arm, CarbonImmutable $expires, ?string $prefix = null): CampaignGrant
    {
        app(CampaignPlaybook::class)->validate($revision->body);
        if (isset($revision->body['trade']['local_earn'])) {
            throw new GameException('Local Earn is C0-specific and cannot authorize an arm.');
        }
        $symbol = $revision->body['agent']['symbol'];
        $schedule = CampaignDirector::cachedSchedule();
        $lock = app(HostLock::class);
        if (! $lock->holds('campaign') || ! $lock->holds('agent-'.$agent->id) || ! $agent->reset_id || $agent->isArchived()
            || $agent->name !== $symbol || ($schedule && $agent->reset_id !== $schedule['reset_id']) || ! $expires->isFuture()) {
            throw new GameException('Arm authority requires the current agent and reset under both locks.');
        }
        $history = CampaignGrant::where('user_id', $agent->user_id)->where('agent_symbol', $symbol)->whereNotNull('limits->arm')->get();
        $resets = $history->mapWithKeys(fn (CampaignGrant $grant) => [$grant->id => CampaignDirector::armReset($grant, $agent->reset_id, $schedule)]);
        $live = $history->filter(fn (CampaignGrant $grant) => ! $grant->revoked_at && ! $grant->superseded_at && (! $grant->expires_at || $grant->expires_at->isFuture()));
        $current = $live->filter(fn (CampaignGrant $grant) => $resets[$grant->id] === $agent->reset_id);
        $reserved = $live->filter(fn (CampaignGrant $grant) => in_array($resets[$grant->id], [$agent->reset_id, null], true));
        if ($prefix !== null) {
            $maxSuffix = $history->filter(fn (CampaignGrant $grant) => $resets[$grant->id] === $agent->reset_id)
                ->map(fn (CampaignGrant $grant) => preg_match('/\A'.preg_quote($prefix, '/').'(\d+)\z/', $grant->arm()['label'], $parts) ? (int) $parts[1] : 0)->max() ?? 0;
            $suffix = $maxSuffix + 1;
            while ($reserved->contains(fn (CampaignGrant $grant) => $grant->arm()['label'] === $prefix.$suffix)) {
                $suffix++;
            }
            $arm['label'] = $prefix.$suffix;
        }
        if ($reserved->contains(fn (CampaignGrant $grant) => $grant->arm()['label'] === $arm['label'])) {
            throw new GameException('Arm label '.$arm['label'].' is already live.');
        }
        if ($reserved->contains(fn (CampaignGrant $grant) => $grant->arm()['ship'] === $arm['ship'])) {
            throw new GameException('Ship '.$arm['ship'].' already belongs to a live arm.');
        }
        if (($arm['follow'] ?? null) !== null && ($arm['follow'] === $arm['label'] || ! $current->contains(fn (CampaignGrant $grant) => $grant->arm()['label'] === $arm['follow']))) {
            throw new GameException('Follow label '.$arm['follow'].' is not a live arm.');
        }
        $ship = Ship::where('agent_id', $agent->id)->where('symbol', $arm['ship'])->first();
        if (! $ship) {
            throw new GameException('Ship '.$arm['ship'].' is not in the agent\'s synced fleet.');
        }
        $fleet = app(CampaignFleet::class);
        $primary = CampaignGrant::primaryFor($agent->user_id, $symbol)->first();
        $episode = $primary ? $this->currentEpisode($primary, $agent) : null;
        if ($fleet->isProbe($ship->snapshot) || ($ship->snapshot['registration']['role'] ?? null) === 'COMMAND'
            || ($episode && in_array($ship->symbol, $fleet->symbols($episode, pin: false), true))) {
            throw new GameException('Ship '.$arm['ship'].' is a role ship of the main campaign.');
        }
        $arm['reset_id'] = $agent->reset_id;
        $grant = CampaignGrant::create(['campaign_revision_id' => $revision->id, 'revision_hash' => $revision->content_hash,
            'user_id' => $agent->user_id, 'agent_symbol' => $symbol, 'limits' => $revision->body['limits'] + ['arm' => $arm], 'expires_at' => $expires, 'slot' => null]);
        $this->event($grant, null, 'approval', ['revision' => $revision->id, 'agent_symbol' => $symbol, 'arm' => $arm]);

        return $grant;
    }

    public function autoArmCandidates(CampaignEpisode $episode, Agent $agent, bool $includeUnresolvedConflicts = false): Collection
    {
        $revision = $episode->getRelationValue('policyRevision') ?? CampaignGrant::find($episode->campaign_grant_id)?->revision();
        $policy = $revision?->body['fleet']['auto_arm'] ?? null;
        $command = app(CampaignFleet::class)->role($episode, 'command', pin: false);
        if (! $policy || ! $command || $episode->reset_id !== $agent->reset_id) {
            return collect();
        }
        $schedule = CampaignDirector::cachedSchedule();
        $grants = CampaignGrant::where('user_id', $agent->user_id)->where('agent_symbol', $agent->name)->whereNotNull('limits->arm')->get();
        $assigned = $grants->filter(function (CampaignGrant $grant) use ($schedule, $agent, $includeUnresolvedConflicts) {
            $reset = CampaignDirector::armReset($grant, $agent->reset_id, $schedule);
            $live = ! $grant->revoked_at && ! $grant->superseded_at;

            return ($reset === $agent->reset_id && ($live || ($grant->arm()['automatic'] ?? false)))
                || ($reset === null && $live && ! $includeUnresolvedConflicts);
        })->map(fn (CampaignGrant $grant) => $grant->arm()['ship'])->all();
        $runs = AutomationRun::where('active_agent_id', $agent->id)->where('reset_id', $agent->reset_id)->pluck('ship_symbol')->all();
        $actions = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->whereIn('state', ['pending', 'sending', 'uncertain'])->pluck('ship_symbol')->all();
        $buy = app(CampaignProbeBootstrap::class);
        $mismatched = $buy->actions($agent)->where(fn ($query) => $query->where('review_required', true)->orWhere('outcome->configuration_mismatch', true))
            ->get()->map(CampaignProbeBootstrap::deliveredShip(...))->all();
        $excluded = array_merge([$command['symbol']], $assigned, $runs, $actions, $mismatched);
        $fleet = app(CampaignFleet::class);

        return Ship::where('agent_id', $agent->id)->whereNull('stationed_waypoint')->orderBy('symbol')->get()
            ->filter(fn (Ship $ship) => ! in_array($ship->symbol, $excluded, true)
                && in_array($ship->snapshot['frame']['symbol'] ?? null, $policy['frames'], true) && ! $fleet->isProbe($ship->snapshot)
                && $ship->snapshot['nav']['systemSymbol'] === $command['nav']['systemSymbol'] && $ship->snapshot['nav']['status'] !== 'IN_TRANSIT'
                && $ship->snapshot['cargo']['units'] === 0 && ! ReconMission::holds($agent, $ship->symbol) && ! $buy->plausibleUnattributed($agent, $ship->snapshot));
    }

    public function autoArm(CampaignGrant $primary, CampaignEpisode $episode, Agent $agent): void
    {
        $lock = app(HostLock::class);
        if (! $lock->holds('campaign') || ! $lock->holds('agent-'.$agent->id)) {
            throw new GameException('Auto-arm requires the campaign and agent locks.');
        }
        $policy = $primary->revision()->body['fleet']['auto_arm'] ?? null;
        if (! $policy || CampaignSaturation::authorityReason($primary->refresh(), $episode->refresh(), $agent->refresh()) !== null) {
            return;
        }
        foreach ($this->autoArmCandidates($episode, $agent, includeUnresolvedConflicts: true) as $ship) {
            try {
                DB::transaction(function () use ($primary, $episode, $agent, $policy, $ship) {
                    if (CampaignSaturation::authorityReason($primary->refresh(), $episode->refresh(), $agent->refresh()) !== null
                        || ! $this->autoArmCandidates($episode, $agent, includeUnresolvedConflicts: true)->contains('id', $ship->id)) {
                        return;
                    }
                    $revision = $this->armRevision($primary->revision());
                    $purchase = app(CampaignProbeBootstrap::class)->actions($agent)->where('arguments->shipType', CampaignHaulerPurchase::TYPE)
                        ->whereIn('state', ['confirmed', 'acknowledged'])
                        ->whereIn('automation_run_id', AutomationRun::where('strategy', 'buy_hauler')->where('plan_version', 7)->select('id'))->get()
                        ->first(fn (ShipAction $action) => CampaignProbeBootstrap::deliveredShip($action) === $ship->symbol);
                    $terms = ['ship' => $ship->symbol, 'follow' => null, 'max_net_loss' => $policy['max_net_loss'], 'max_load_spend' => null,
                        'exposure_share' => $policy['exposure_share'], 'automatic' => true, 'purchase_action_id' => $purchase?->id];
                    $expires = ($primary->expires_at ?? $episode->next_reset_at)->min($episode->next_reset_at);
                    $grant = $this->createArm($revision, $agent, $terms, $expires, $policy['label_prefix']);
                    $this->event($primary, $episode, 'auto_arm', ['grant_id' => $grant->id, 'ship' => $ship->symbol, 'label' => $grant->arm()['label'],
                        'purchase_action_id' => $purchase?->id, 'revision_hash' => $revision->content_hash]);
                    $state = $episode->state ?? [];
                    if (isset($state['auto_arm_failures'][$ship->symbol])) {
                        unset($state['auto_arm_failures'][$ship->symbol]);
                        $episode->update(['state' => $state]);
                    }
                });
            } catch (\Throwable $exception) {
                $reason = $exception instanceof GameException ? $exception->getMessage() : 'Auto-arm transaction failed.';
                $state = $episode->refresh()->state ?? [];
                if (($state['auto_arm_failures'][$ship->symbol] ?? null) !== $reason) {
                    DB::transaction(function () use ($episode, $primary, $ship, $reason, $state) {
                        $state['auto_arm_failures'][$ship->symbol] = $reason;
                        $episode->update(['state' => $state]);
                        $this->event($primary, $episode, 'auto_arm_conflict', ['ship' => $ship->symbol, 'reason' => $reason]);
                    });
                }
            }
        }
    }

    public function resumeArm(CampaignGrant $grant): void
    {
        app(HostLock::class)->scoped('campaign', function () use ($grant) {
            $grant->refresh();
            if (! $grant->arm() || $grant->revoked_at || $grant->superseded_at) {
                throw new GameException('Not a live arm grant.');
            }
            if ($grant->expires_at?->isPast()) {
                throw new GameException('Arm grant expired. Approve a new arm.');
            }
            $episode = CampaignEpisode::where('campaign_grant_id', $grant->id)->whereNull('closed_at')->first();
            if ($episode) {
                app(CampaignTrade::class)->recordResults($episode);
                $net = CampaignEvent::where('campaign_episode_id', $episode->id)->where('kind', 'load_result')->pluck('data')->sum(fn ($data) => $data['net'] ?? 0);
                if ($net < -($grant->arm()['max_net_loss'] ?? INF)) {
                    throw new GameException('Arm remains past its loss stop. Approve a new arm after review.');
                }
            }
            $wasDraining = $grant->isDraining() || str_starts_with($grant->pause_reason ?? '', 'drain_pause:');
            $grant->update(['paused_at' => null, 'pause_reason' => null, 'wait_reason' => null, 'last_directed_at' => null]);
            if ($wasDraining) {
                $this->releaseDrainIfUnused($grant->resolvedAgentSymbol());
            }
            foreach (CampaignEpisode::where('campaign_grant_id', $grant->id)->whereNull('closed_at')->get() as $episode) {
                $state = $episode->state ?? [];
                unset($state['pending_pause'], $state['recovery_attempts_since']);
                $state['losses_reviewed_at'] = now()->toIso8601String();
                $episode->update(['state' => $state]);
            }
            DB::table('game_runtime')->upsert([['key' => 'arm_director_failures:'.$grant->id, 'value' => '0']], ['key'], ['value']);
            $this->event($grant, null, 'resume', []);
        });
    }

    public function revokeArm(CampaignGrant $grant, bool $ackPacing = false): void
    {
        app(HostLock::class)->scoped('campaign', fn () => DB::transaction(function () use ($grant, $ackPacing) {
            $grant->refresh();
            if ($ackPacing) {
                $action = ShipAction::find($grant->arm()['purchase_action_id'] ?? null)
                    ?? throw new GameException('The exact arm grant has no linked automatic hauler purchase.');
                app(CampaignHaulerPurchase::class)->acknowledgePacing($action, User::findOrFail($grant->user_id), arm: $grant,
                    revoke: fn () => $grant->revoked_at ? null : $this->close($grant));
            } elseif ($grant->arm() && ! $grant->revoked_at) {
                $this->close($grant);
            }
        }));
    }

    private function close(CampaignGrant $grant): void
    {
        $wasDraining = $grant->isDraining() || str_starts_with($grant->pause_reason ?? '', 'drain_pause:');
        $grant->update(['slot' => null, 'revoked_at' => now()]);
        if ($wasDraining) {
            $this->releaseDrainIfUnused($grant->resolvedAgentSymbol());
        }
        $this->stopRuns($grant);
        CampaignEpisode::where('campaign_grant_id', $grant->id)->whereNull('closed_at')->update(['closed_at' => now()]);
        $this->event($grant, null, 'revoked', []);
    }

    public function busy(CampaignGrant $grant): bool
    {
        return $this->busyReason($grant, includePause: false) !== null;
    }

    public function currentEpisode(CampaignGrant $grant, Agent $agent): ?CampaignEpisode
    {
        return CampaignEpisode::where('campaign_grant_id', $grant->id)->where('agent_id', $agent->id)
            ->where('reset_id', $agent->reset_id)->whereNull('closed_at')->latest('id')->first();
    }

    public function busyReason(CampaignGrant $grant, bool $includePause = true): ?array
    {
        $agent = $this->agent($grant->user_id, $grant->resolvedAgentSymbol());
        if ($agent) {
            $episode = $this->currentEpisode($grant, $agent) ?? new CampaignEpisode([
                'campaign_grant_id' => $grant->id, 'agent_id' => $agent->id, 'reset_id' => $agent->reset_id,
            ]);
            $symbols = app(CampaignFleet::class)->symbols($episode, pin: false);
            foreach (Ship::where('agent_id', $agent->id)->whereIn('symbol', $symbols)->orderBy('symbol')->get() as $ship) {
                if (($ship->snapshot['cargo']['units'] ?? 0) > 0) {
                    return ['reason' => 'cargo', 'ship' => $ship->symbol, 'operator_settlement_required' => ! $grant->available()];
                }
                if (($ship->snapshot['nav']['status'] ?? null) === 'IN_TRANSIT') {
                    $arrival = $ship->snapshot['nav']['route']['arrival'] ?? null;

                    return ['reason' => $arrival && CarbonImmutable::parse($arrival)->lte(now()) ? 'awaiting_arrival_evidence' : 'transit',
                        'ship' => $ship->symbol, 'arrival' => $arrival];
                }
            }
            $purchases = app(CampaignProbeBootstrap::class);
            if ($action = $purchases->actions($agent)->whereIn('state', ['pending', 'sending', 'uncertain'])->first()) {
                return ['reason' => 'unsettled_purchase', 'action_id' => $action->id, 'ship' => $action->ship_symbol];
            }
            if ($run = $purchases->activeRuns($agent)->first()) {
                return ['reason' => 'unsettled_purchase', 'run_id' => $run->id, 'ship' => $run->ship_symbol];
            }
            if ($run = AutomationRun::where('active_agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                ->where(fn ($query) => $query->whereIn('ship_symbol', $symbols)
                    ->when($episode->exists, fn ($query) => $query->orWhere('campaign_episode_id', $episode->id)))->first()) {
                return ['reason' => 'role_work', 'run_id' => $run->id, 'ship' => $run->ship_symbol];
            }
            if ($action = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->whereIn('state', ['pending', 'sending', 'uncertain'])
                ->where(fn ($query) => $query->whereIn('ship_symbol', $symbols)
                    ->when($episode->exists, fn ($query) => $query->orWhereIn('automation_run_id', AutomationRun::where('campaign_episode_id', $episode->id)->select('id'))))->first()) {
                return ['reason' => 'role_work', 'action_id' => $action->id, 'ship' => $action->ship_symbol];
            }
        }
        if ($includePause && ($grant->isDraining() || $grant->paused_at) && $grant->pause_reason !== 'grant_expired' && ! $grant->expires_at?->isPast()) {
            return ['reason' => $grant->isDraining() ? 'draining' : 'paused', 'pause_reason' => $grant->pause_reason];
        }

        return null;
    }

    public function activatePending(?int $userId = null, ?string $agentSymbol = null): void
    {
        $pendings = CampaignGrant::where('slot', 2)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->when($agentSymbol !== null && $agentSymbol !== '', fn ($q) => $q->where('agent_symbol', strtoupper($agentSymbol)))
            ->orderBy('id')->get();
        foreach ($pendings as $pending) {
            $symbol = $pending->resolvedAgentSymbol();
            $agent = $this->agent($pending->user_id, $symbol);
            $activate = fn () => DB::transaction(function () use ($pending, $symbol) {
                $current = CampaignGrant::primaryFor($pending->user_id, $symbol)->first();
                if ($pending->refresh()->slot !== 2 || ($current && $this->busyReason($current))) {
                    return;
                }
                if ($current) {
                    $this->supersede($current);
                }
                $pending->update(['slot' => 1]);
                $this->event($pending, null, 'activated', ['agent_symbol' => $symbol]);
            });
            try {
                $agent ? app(HostLock::class)->run('agent-'.$agent->id, $activate) : $activate();
            } catch (GameException $exception) {
                if ($exception->retryAfter === 0) {
                    throw $exception;
                }
            }
        }
    }

    private function supersede(CampaignGrant $grant): void
    {
        $grant->update(['slot' => null, 'superseded_at' => now()]);
        CampaignEpisode::where('campaign_grant_id', $grant->id)->whereNull('closed_at')->update(['closed_at' => now()]);
        $this->event($grant, null, 'superseded', []);
    }

    /**
     * Cargo-safe drain pause for host/budget pressure or cannibalization: immediately stops new
     * Tiers 2-4 exposure (buys, scouts, positioning, ship buys) while keeping active Tier-1
     * sell_aboard recovery alive until cargo aboard and unsettled actions finish settling.
     */
    public function drainPause(CampaignGrant $grant, string $reason, ?CampaignEpisode $episode = null): void
    {
        DB::transaction(function () use ($grant, $reason, $episode) {
            $grant->refresh();
            if ($grant->paused_at) {
                return;
            }
            app(RequestBudget::class)->drainAgent($grant->resolvedAgentSymbol(), true);
            $episode ??= CampaignEpisode::where('campaign_grant_id', $grant->id)->whereNull('closed_at')->latest('id')->first();
            if (! $grant->isDraining()) {
                $grant->update(['wait_reason' => 'drain_pause:'.$reason]);
                if ($episode) {
                    $state = $episode->state ?? [];
                    $state['draining'] = $reason;
                    $episode->update(['state' => $state]);
                }
                $this->event($grant, $episode, 'drain_pause', ['reason' => $reason, 'agent_symbol' => $grant->resolvedAgentSymbol()]);
            }
            // Stop non-sell-only runs immediately; preserve active sell_aboard recovery runs.
            $episodes = CampaignEpisode::where('campaign_grant_id', $grant->id)->select('id');
            AutomationRun::whereIn('campaign_episode_id', $episodes)->where('state', 'preview')
                ->where('strategy', '!=', 'sell_aboard')
                ->update(['state' => 'stopped', 'blocked_reason' => 'Drain pause: stopping new exposure.']);
            foreach (AutomationRun::whereIn('campaign_episode_id', $episodes)->whereNotNull('active_agent_id')->get() as $run) {
                if (empty($run->plan['sell_only'])) {
                    app(AutomationRunner::class)->stop($run);
                }
            }
        });
    }

    public function pause(CampaignGrant $grant, string $reason, ?CampaignEpisode $episode = null): void
    {
        DB::transaction(function () use ($grant, $reason, $episode) {
            $grant->refresh();
            if (! $grant->paused_at) {
                $releaseDrain = $grant->isDraining() && $reason !== $grant->wait_reason;
                $grant->update(['paused_at' => now(), 'pause_reason' => $reason, 'wait_reason' => null]);
                if ($releaseDrain) {
                    $this->releaseDrainIfUnused($grant->resolvedAgentSymbol());
                }
                $this->event($grant, $episode, 'pause', ['reason' => $reason, 'start_credits' => $episode?->start_credits, 'limits' => $grant->limits]);
            }
            $this->stopRuns($grant);
        });
    }

    private function releaseDrainIfUnused(string $symbol): void
    {
        $stillDraining = CampaignGrant::where('agent_symbol', $symbol)
            ->whereNull('revoked_at')->whereNull('superseded_at')
            ->where(fn ($q) => $q->where('slot', 1)->orWhere(fn ($q) => $q->whereNull('slot')->whereNotNull('limits->arm')))
            ->where(fn ($q) => $q->where('wait_reason', 'like', 'drain_pause:%')->orWhere('pause_reason', 'like', 'drain_pause:%'))
            ->get()->contains(fn (CampaignGrant $grant) => $grant->isDraining() || str_starts_with($grant->pause_reason ?? '', 'drain_pause:'));
        if (! $stillDraining) {
            app(RequestBudget::class)->drainAgent($symbol, false);
        }
    }

    /** Resolve a single primary grant for an operator, requiring explicit agent symbol when >1 primary (including paused) exists. */
    public function resolvePrimary(?int $userId = null, ?string $agentSymbol = null): CampaignGrant
    {
        $query = CampaignGrant::allPrimaries($userId);
        if ($agentSymbol !== null && $agentSymbol !== '') {
            return (clone $query)->where('agent_symbol', strtoupper($agentSymbol))->firstOrFail();
        }
        $primaries = $query->orderBy('id')->get();
        if ($primaries->count() > 1) {
            $names = $primaries->map(fn ($g) => $g->resolvedAgentSymbol())->implode(', ');
            throw new GameException('Multiple primary campaigns exist ('.$names.'). Specify --agent=<symbol>.');
        }

        if ($primaries->isEmpty()) {
            throw (new ModelNotFoundException)->setModel(CampaignGrant::class);
        }

        return $primaries->first();
    }

    public function resume(?CampaignGrant $grant = null, ?int $userId = null, ?string $agentSymbol = null): void
    {
        app(HostLock::class)->retry('campaign', function () use ($grant, $userId, $agentSymbol) {
            $grant = $grant ? $grant->fresh() : $this->resolvePrimary($userId, $agentSymbol);
            if ($grant->expires_at?->isPast()) {
                throw new GameException('Grant expired. Approve a new grant.');
            }
            $wasDraining = $grant->isDraining() || str_starts_with($grant->pause_reason ?? '', 'drain_pause:');
            $grant->update(['paused_at' => null, 'pause_reason' => null, 'wait_reason' => null, 'last_directed_at' => null]);
            if ($wasDraining) {
                $this->releaseDrainIfUnused($grant->resolvedAgentSymbol());
            }
            foreach (CampaignEpisode::where('campaign_grant_id', $grant->id)->whereNull('closed_at')->get() as $episode) {
                $state = $episode->state ?? [];
                unset($state['pending_pause'], $state['recovery_attempts_since'], $state['draining']);
                $state['losses_reviewed_at'] = now()->toIso8601String();
                $episode->update(['state' => $state]);
            }
            DB::table('game_runtime')->upsert([
                ['key' => 'campaign_director_failures', 'value' => '0'],
                ['key' => 'campaign_director_failures:'.$grant->id, 'value' => '0'],
            ], ['key'], ['value']);
            $this->event($grant, null, 'resume', ['agent_symbol' => $grant->resolvedAgentSymbol()]);
        });
    }

    public function revoke(?CampaignGrant $grant = null, ?int $userId = null, ?string $agentSymbol = null, bool $all = false): void
    {
        app(HostLock::class)->retry('campaign', fn () => DB::transaction(function () use ($grant, $userId, $agentSymbol, $all) {
            if ($all) {
                $slotted = CampaignGrant::whereNotNull('slot')->when($userId !== null, fn ($q) => $q->where('user_id', $userId))->get();
                $arms = CampaignGrant::liveArms()->when($userId !== null, fn ($q) => $q->where('user_id', $userId))->get();
                foreach ($slotted->merge($arms) as $item) {
                    $this->close($item);
                }

                return;
            }
            if ($grant === null && ($agentSymbol === null || $agentSymbol === '')) {
                $primaries = CampaignGrant::allPrimaries($userId)->get();
                if ($primaries->count() > 1) {
                    $names = $primaries->map(fn ($g) => $g->resolvedAgentSymbol())->implode(', ');
                    throw new GameException('Multiple primary campaigns exist ('.$names.'). Specify --agent=<symbol> or --all.');
                }
                $slotted = CampaignGrant::whereNotNull('slot')->when($userId !== null, fn ($q) => $q->where('user_id', $userId))->get();
                $arms = CampaignGrant::liveArms()->when($userId !== null, fn ($q) => $q->where('user_id', $userId))->get();
                foreach ($slotted->merge($arms) as $item) {
                    $this->close($item);
                }

                return;
            }
            $target = $grant ? $grant->fresh() : $this->resolvePrimary($userId, $agentSymbol);
            $symbol = $target->resolvedAgentSymbol();
            $toClose = CampaignGrant::where('user_id', $target->user_id)->where('agent_symbol', $symbol)
                ->where(fn ($q) => $q->whereNotNull('slot')->orWhere(fn ($arms) => $arms->whereNull('slot')->whereNotNull('limits->arm')->whereNull('revoked_at')->whereNull('superseded_at')))
                ->get();
            foreach ($toClose as $item) {
                $this->close($item);
            }
        }));
    }

    private function stopRuns(CampaignGrant $grant): void
    {
        $episodes = CampaignEpisode::where('campaign_grant_id', $grant->id)->select('id');
        AutomationRun::whereIn('campaign_episode_id', $episodes)->where('state', 'preview')->update(['state' => 'stopped', 'blocked_reason' => 'Campaign authority closed.']);
        foreach (AutomationRun::whereIn('campaign_episode_id', $episodes)->whereNotNull('active_agent_id')->get() as $run) {
            app(AutomationRunner::class)->stop($run);
        }
    }

    public function event(CampaignGrant $grant, ?CampaignEpisode $episode, string $kind, array $data): CampaignEvent
    {
        if ($kind === 'decision') {
            $summary = [];
            foreach ($data['candidates'] ?? [] as $candidate) {
                foreach ((array) ($candidate['rejected'] ?? []) as $reason) {
                    $summary[$reason] = ($summary[$reason] ?? 0) + 1;
                }
            }
            ksort($summary);
            $data['rejections'] = $summary;
            $candidates = $data['candidates'] ?? [];
            // Shadow data: pairs ruled out only by run_max_minutes, kept apart and capped so events stay small.
            // Sorted before deduplication, so each pair keeps its best row.
            $long = collect($candidates)->filter(fn ($candidate) => ! empty($candidate['long_run']))->sortByDesc('predicted_margin')
                ->unique(fn ($candidate) => $candidate['good'].'@'.$candidate['source'].'>'.$candidate['buyer'])
                ->take(self::LONG_RUN_REJECTIONS)->values()->all();
            $candidates = array_values(array_filter($candidates, fn ($candidate) => empty($candidate['long_run'])));
            usort($candidates, fn ($left, $right) => ($right['score'] ?? -PHP_FLOAT_MAX) <=> ($left['score'] ?? -PHP_FLOAT_MAX));
            $data['candidates'] = array_slice($candidates, 0, 10);
            if ($long) {
                $data['long_run_rejections'] = $long;
            }
        }
        if (in_array($kind, ['wait', 'decision'], true)) {
            $previous = CampaignEvent::where('campaign_grant_id', $grant->id)->whereIn('kind', ['wait', 'decision'])->latest('id')->first();
            if ($previous && $previous->kind === $kind && $previous->campaign_episode_id === $episode?->id && ($kind === 'wait' ? ($previous->data['reason'] ?? null) === ($data['reason'] ?? null)
                : ($previous->data['choice'] ?? null) === ($data['choice'] ?? null) && ($previous->data['rejections'] ?? []) === $data['rejections']
                    && ($previous->data['run_id'] ?? null) === ($data['run_id'] ?? null)
                    && ($previous->data['local_earn'] ?? null) === ($data['local_earn'] ?? null)
                    && ($previous->data['saturation_reasons'] ?? null) === ($data['saturation_reasons'] ?? null)
                    && ($previous->data['saturation']['hold']['reason'] ?? null) === ($data['saturation']['hold']['reason'] ?? null))) {
                return $previous;
            }
        }

        return CampaignEvent::create(['campaign_grant_id' => $grant->id, 'campaign_episode_id' => $episode?->id, 'kind' => $kind, 'data' => $data]);
    }
}
