<?php

namespace App\Gameplay;

use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\CampaignRevision;
use App\Models\Ship;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class CampaignControl
{
    /** Decision events keep at most this many duration-only rejections, highest predicted margin first. */
    public const LONG_RUN_REJECTIONS = 5;

    public function approve(CampaignRevision $revision, User $user, ?CarbonImmutable $expires = null, ?array $replacement = null): CampaignGrant
    {
        return app(HostLock::class)->scoped('campaign', function () use ($revision, $user, $expires, $replacement) {
            app(CampaignPlaybook::class)->validate($revision->body);
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

            return DB::transaction(function () use ($revision, $user, $expires, $symbol) {
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

        return app(HostLock::class)->retry('campaign', fn () => DB::transaction(function () use ($revision, $user, $arm, $expires) {
            app(CampaignPlaybook::class)->validate($revision->body);
            if (isset($revision->body['trade']['local_earn'])) {
                throw new GameException('Local Earn is C0-specific and cannot authorize an arm.');
            }
            $symbol = strtoupper((string) $revision->body['agent']['symbol']);
            $live = CampaignGrant::liveArms($symbol)->where('user_id', $user->id)->get();
            if ($live->contains(fn ($grant) => $grant->arm()['label'] === $arm['label'])) {
                throw new GameException('Arm label '.$arm['label'].' is already live.');
            }
            if ($live->contains(fn ($grant) => $grant->arm()['ship'] === $arm['ship'])) {
                throw new GameException('Ship '.$arm['ship'].' already belongs to a live arm.');
            }
            if ($arm['follow'] !== null && ($arm['follow'] === $arm['label'] || ! $live->contains(fn ($grant) => $grant->arm()['label'] === $arm['follow']))) {
                throw new GameException('Follow label '.$arm['follow'].' is not a live arm.');
            }
            $agent = $user->agents()->where('name', $symbol)->unarchived()->latest('id')->first();
            if (! $agent || ! Ship::where('agent_id', $agent->id)->where('symbol', $arm['ship'])->exists()) {
                throw new GameException('Ship '.$arm['ship'].' is not in the agent\'s synced fleet.');
            }
            $c0 = CampaignGrant::primaryFor($user->id, $symbol)->first();
            $roles = Ship::where('agent_id', $agent->id)->get()->filter(fn ($ship) => in_array($ship->snapshot['registration']['role'] ?? null, ['COMMAND', 'SATELLITE', 'PROBE'], true)
                || ($ship->snapshot['frame']['symbol'] ?? null) === 'FRAME_PROBE')->pluck('symbol')->all();
            if (in_array($arm['ship'], $roles, true)) {
                throw new GameException('Ship '.$arm['ship'].' is a role ship of the main campaign.');
            }
            foreach ($c0 ? CampaignEpisode::where('campaign_grant_id', $c0->id)->latest('id')->limit(1)->get() : [] as $episode) {
                if (in_array($arm['ship'], app(CampaignFleet::class)->symbols($episode), true)) {
                    throw new GameException('Ship '.$arm['ship'].' is a role ship of the main campaign.');
                }
            }
            $grant = CampaignGrant::create(['campaign_revision_id' => $revision->id, 'revision_hash' => $revision->content_hash,
                'user_id' => $user->id, 'agent_symbol' => $symbol, 'limits' => $revision->body['limits'] + ['arm' => $arm], 'expires_at' => $expires, 'slot' => null]);
            $this->event($grant, null, 'approval', ['revision' => $revision->id, 'agent_symbol' => $symbol, 'arm' => $arm]);

            return $grant;
        }));
    }

    public function resumeArm(CampaignGrant $grant): void
    {
        app(HostLock::class)->retry('campaign', function () use ($grant) {
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

    public function revokeArm(CampaignGrant $grant): void
    {
        app(HostLock::class)->retry('campaign', fn () => DB::transaction(function () use ($grant) {
            $grant->refresh();
            if (! $grant->arm() || $grant->revoked_at) {
                return;
            }
            $this->close($grant);
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
        $episodes = CampaignEpisode::where('campaign_grant_id', $grant->id)->get();
        if (AutomationRun::whereIn('campaign_episode_id', $episodes->pluck('id'))->whereNotNull('active_agent_id')->exists()) {
            return true;
        }
        foreach ($episodes as $episode) {
            if (app(CampaignFleet::class)->pending($episode)->exists()) {
                return true;
            }
        }

        return false;
    }

    public function activatePending(?int $userId = null, ?string $agentSymbol = null): void
    {
        DB::transaction(function () use ($userId, $agentSymbol) {
            $pendings = CampaignGrant::where('slot', 2)
                ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
                ->when($agentSymbol !== null && $agentSymbol !== '', fn ($q) => $q->where('agent_symbol', strtoupper($agentSymbol)))
                ->orderBy('id')
                ->get();
            foreach ($pendings as $pending) {
                $symbol = $pending->resolvedAgentSymbol();
                $current = CampaignGrant::primaryFor($pending->user_id, $symbol)->first();
                if ($current && ((($current->isDraining() || $current->paused_at) && $current->pause_reason !== 'grant_expired' && ! $current->expires_at?->isPast()) || $this->busy($current))) {
                    continue;
                }
                if ($current) {
                    $this->supersede($current);
                }
                $pending->update(['slot' => 1]);
                $this->event($pending, null, 'activated', ['agent_symbol' => $symbol]);
            }
        });
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
                    && ($previous->data['local_earn'] ?? null) === ($data['local_earn'] ?? null))) {
                return $previous;
            }
        }

        return CampaignEvent::create(['campaign_grant_id' => $grant->id, 'campaign_episode_id' => $episode?->id, 'kind' => $kind, 'data' => $data]);
    }
}
