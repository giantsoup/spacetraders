<?php

namespace App\Gameplay;

use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;

/**
 * Market claims between campaign episodes that share a reset. A claim is the (good, source,
 * buyer) pair an episode's ship is working, kept in that episode's own state['claim']. Other
 * episodes treat the claimed good at either waypoint, and the plan of any active or previewed
 * trade run, as taken, so two ships never chase the same ask or bid.
 */
class FleetClaims
{
    public const FRESH_MINUTES = 30;

    public function claim(CampaignEpisode $episode, array $candidate): void
    {
        $claim = ['good' => $candidate['good'], 'source' => $candidate['source'], 'buyer' => $candidate['buyer'],
            'key' => $candidate['key'] ?? $candidate['good'].'@'.$candidate['buyer'], 'ship' => $candidate['ship'] ?? null, 'at' => now()->toIso8601String()];
        $taken = $this->taken($episode);
        $overlap = array_values(array_filter([$claim['good'].'@'.$claim['source'], $claim['good'].'@'.$claim['buyer']], fn ($unit) => isset($taken[$unit])));
        $this->write($episode, fn (array $state) => ['claim' => $claim] + $state);
        $control = app(CampaignControl::class);
        $control->event($episode->grant(), $episode, 'claim', $claim);
        if ($overlap) {
            $control->event($episode->grant(), $episode, 'claim_collision', $claim + ['units' => $overlap, 'held_by' => array_values(array_unique(array_map(fn ($unit) => $taken[$unit], $overlap)))]);
        }
    }

    public function release(CampaignEpisode $episode, string $reason): void
    {
        $claim = $episode->fresh()?->state['claim'] ?? null;
        if (! $claim) {
            return;
        }
        $this->write($episode, function (array $state) {
            unset($state['claim']);

            return $state;
        });
        app(CampaignControl::class)->event($episode->grant(), $episode, 'claim_release', $claim + ['reason' => $reason]);
    }

    /**
     * Units ("GOOD@WAYPOINT" => holder label) that other open episodes of the same reset hold,
     * through a fresh claim or the plan of an active or previewed trade run. Followers of this
     * episode are not counted.
     *
     * @return array<string, string>
     */
    public function taken(CampaignEpisode $self): array
    {
        $taken = [];
        $mine = self::label($self);
        $others = CampaignEpisode::where('reset_id', $self->reset_id)->whereNull('closed_at')->whereKeyNot($self->id)->get();
        foreach ($others as $other) {
            // A follower shares its leader's pair by design, so it never blocks the leader.
            if ((self::arm($other->grant())['follow'] ?? null) === $mine) {
                continue;
            }
            foreach ($this->pairs($other) as $pair) {
                $taken[$pair['good'].'@'.$pair['source']] ??= self::label($other);
                $taken[$pair['good'].'@'.$pair['buyer']] ??= self::label($other);
            }
        }

        return $taken;
    }

    /**
     * The pairs an episode holds: its claim while fresh, then its active or previewed trade runs.
     *
     * @return list<array{good: string, source: string, buyer: string}>
     */
    public function pairs(CampaignEpisode $episode): array
    {
        $pairs = [];
        $claim = $episode->state['claim'] ?? null;
        if ($claim && isset($claim['at']) && now()->subMinutes(self::FRESH_MINUTES)->lt($claim['at'])) {
            $pairs[] = ['good' => $claim['good'], 'source' => $claim['source'], 'buyer' => $claim['buyer']];
        }
        $runs = AutomationRun::where('campaign_episode_id', $episode->id)->where('strategy', 'trade')
            ->where(fn ($query) => $query->whereNotNull('active_agent_id')->orWhere(fn ($preview) => $preview->where('state', 'preview')->where('expires_at', '>', now())))
            ->orderByDesc('id')->get();
        foreach ($runs as $run) {
            if (isset($run->plan['symbol'], $run->plan['site'], $run->plan['market'])) {
                $pairs[] = ['good' => $run->plan['symbol'], 'source' => $run->plan['site'], 'buyer' => $run->plan['market']];
            }
        }

        return $pairs;
    }

    public static function arm(CampaignGrant $grant): ?array
    {
        return $grant->limits['arm'] ?? null;
    }

    public static function label(CampaignEpisode $episode): string
    {
        return self::arm($episode->grant())['label'] ?? 'C0';
    }

    /** The open episode of the grant that a follower arm follows ('C0' or another arm's label). */
    public function leader(CampaignEpisode $follower, string $label): ?CampaignEpisode
    {
        return CampaignEpisode::where('reset_id', $follower->reset_id)->whereNull('closed_at')->whereKeyNot($follower->id)->orderByDesc('id')->get()
            ->first(function (CampaignEpisode $episode) use ($label) {
                $grant = $episode->grant();

                return ! $grant->revoked_at && ! $grant->superseded_at && (self::arm($grant)['label'] ?? 'C0') === $label;
            });
    }

    /** C0's open episode for the same reset: the newest open non-arm episode other than this one. */
    public function reference(CampaignEpisode $episode): ?CampaignEpisode
    {
        return CampaignEpisode::where('reset_id', $episode->reset_id)->where('agent_id', $episode->agent_id)->whereNull('closed_at')
            ->whereKeyNot($episode->id)->orderByDesc('id')->get()->first(fn (CampaignEpisode $other) => ! self::arm($other->grant()));
    }

    private function write(CampaignEpisode $episode, callable $change): void
    {
        $fresh = $episode->fresh();
        $state = $change($fresh->state ?? []);
        $fresh->update(['state' => $state]);
        $episode->state = $state;
        $episode->syncOriginalAttribute('state');
    }
}
