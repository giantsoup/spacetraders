<?php

namespace App\Gameplay;

use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\CampaignRevision;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CampaignControl
{
    /** Decision events keep at most this many duration-only rejections, highest predicted margin first. */
    public const LONG_RUN_REJECTIONS = 5;

    public function approve(CampaignRevision $revision, User $user, ?CarbonImmutable $expires = null): CampaignGrant
    {
        if ($expires && $expires->isPast()) {
            throw new GameException('Grant expiry must be in the future.');
        }

        return app(HostLock::class)->retry('campaign', fn () => DB::transaction(function () use ($revision, $user, $expires) {
            app(CampaignPlaybook::class)->validate($revision->body);
            CampaignGrant::where('slot', 2)->get()->each(fn ($grant) => $grant->update(['slot' => null, 'superseded_at' => now()]));
            $current = CampaignGrant::where('slot', 1)->first();
            $busy = $current && $this->busy($current);
            if ($current && ! $busy) {
                $this->supersede($current);
            }
            $grant = CampaignGrant::create(['campaign_revision_id' => $revision->id, 'revision_hash' => $revision->content_hash,
                'user_id' => $user->id, 'limits' => $revision->body['limits'], 'expires_at' => $expires, 'slot' => $busy ? 2 : 1]);
            $this->event($grant, null, 'approval', ['revision' => $revision->id, 'pending' => $busy]);

            return $grant;
        }));
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

    public function activatePending(): void
    {
        DB::transaction(function () {
            $pending = CampaignGrant::where('slot', 2)->first();
            $current = CampaignGrant::where('slot', 1)->first();
            if (! $pending || ($current && (($current->paused_at && $current->pause_reason !== 'grant_expired' && ! $current->expires_at?->isPast()) || $this->busy($current)))) {
                return;
            }
            if ($current) {
                $this->supersede($current);
            }
            $pending->update(['slot' => 1]);
            $this->event($pending, null, 'activated', []);
        });
    }

    private function supersede(CampaignGrant $grant): void
    {
        $grant->update(['slot' => null, 'superseded_at' => now()]);
        CampaignEpisode::where('campaign_grant_id', $grant->id)->whereNull('closed_at')->update(['closed_at' => now()]);
        $this->event($grant, null, 'superseded', []);
    }

    public function pause(CampaignGrant $grant, string $reason, ?CampaignEpisode $episode = null): void
    {
        DB::transaction(function () use ($grant, $reason, $episode) {
            $grant->refresh();
            if (! $grant->paused_at) {
                $grant->update(['paused_at' => now(), 'pause_reason' => $reason, 'wait_reason' => null]);
                $this->event($grant, $episode, 'pause', ['reason' => $reason, 'start_credits' => $episode?->start_credits, 'limits' => $grant->limits]);
            }
            $this->stopRuns($grant);
        });
    }

    public function resume(): void
    {
        app(HostLock::class)->retry('campaign', function () {
            $grant = CampaignGrant::where('slot', 1)->firstOrFail();
            if ($grant->expires_at?->isPast()) {
                throw new GameException('Grant expired. Approve a new grant.');
            }
            $grant->update(['paused_at' => null, 'pause_reason' => null, 'wait_reason' => null, 'last_directed_at' => null]);
            foreach (CampaignEpisode::where('campaign_grant_id', $grant->id)->whereNull('closed_at')->get() as $episode) {
                $state = $episode->state ?? [];
                unset($state['pending_pause'], $state['recovery_attempts_since']);
                $state['losses_reviewed_at'] = now()->toIso8601String();
                $episode->update(['state' => $state]);
            }
            DB::table('game_runtime')->upsert([['key' => 'campaign_director_failures', 'value' => '0']], ['key'], ['value']);
            $this->event($grant, null, 'resume', []);
        });
    }

    public function revoke(): void
    {
        app(HostLock::class)->retry('campaign', fn () => DB::transaction(function () {
            foreach (CampaignGrant::whereNotNull('slot')->get() as $grant) {
                $grant->update(['slot' => null, 'revoked_at' => now()]);
                $this->stopRuns($grant);
                CampaignEpisode::where('campaign_grant_id', $grant->id)->whereNull('closed_at')->update(['closed_at' => now()]);
                $this->event($grant, null, 'revoked', []);
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
            $long = collect($candidates)->filter(fn ($candidate) => ! empty($candidate['long_run']))
                ->unique(fn ($candidate) => $candidate['good'].'@'.$candidate['source'].'>'.$candidate['buyer'])
                ->sortByDesc('predicted_margin')->take(self::LONG_RUN_REJECTIONS)->values()->all();
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
                    && ($previous->data['run_id'] ?? null) === ($data['run_id'] ?? null))) {
                return $previous;
            }
        }

        return CampaignEvent::create(['campaign_grant_id' => $grant->id, 'campaign_episode_id' => $episode?->id, 'kind' => $kind, 'data' => $data]);
    }
}
