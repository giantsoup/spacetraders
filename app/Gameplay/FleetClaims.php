<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\Ship;
use App\Models\ShipAction;
use Carbon\CarbonImmutable;

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
        $system = self::systemOf($candidate['buyer'] ?? $candidate['source'] ?? null);
        $claim = [
            'good' => $candidate['good'],
            'source' => $candidate['source'],
            'buyer' => $candidate['buyer'],
            'system' => $system,
            'cargo_units' => isset($candidate['units']) ? (int) $candidate['units'] : null,
            'eta' => $candidate['eta'] ?? (isset($candidate['planned_seconds']) ? now()->addSeconds((int) $candidate['planned_seconds'])->toIso8601String() : null),
            'key' => $candidate['key'] ?? $candidate['good'].'@'.$candidate['buyer'],
            'ship' => $candidate['ship'] ?? null,
            'at' => now()->toIso8601String(),
        ];
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
     * Units ("GOOD@WAYPOINT" => holder label) that other open episodes of the same reset hold
     * across ALL of this operator's agents trading in the same system, through a fresh claim or
     * the plan of an active or previewed trade run. Followers of this episode are not counted.
     *
     * @return array<string, string>
     */
    public function taken(CampaignEpisode $self, ?string $system = null): array
    {
        $taken = [];
        $mine = self::label($self);
        $selfGrant = $self->grant();
        $selfSystem = $system ?? $this->episodeSystem($self);
        $operatorAgentIds = Agent::where('user_id', $selfGrant->user_id)
            ->where('reset_id', $self->reset_id)
            ->pluck('id');
        $others = CampaignEpisode::where('reset_id', $self->reset_id)
            ->whereIn('agent_id', $operatorAgentIds)
            ->whereKeyNot($self->id)
            ->get();
        foreach ($others as $other) {
            // A follower on the same agent shares its leader's pair by design, so it never blocks the leader.
            if ($other->agent_id === $self->agent_id && (self::arm($other->grant())['follow'] ?? null) === $mine) {
                continue;
            }
            foreach ($this->pairs($other) as $pair) {
                $pairSystem = self::systemOf($pair['buyer'] ?? $pair['source'] ?? null);
                if ($other->agent_id !== $self->agent_id
                    && ($selfSystem === null || $pair['system'] !== $selfSystem || $pairSystem !== $selfSystem)) {
                    continue;
                }
                $holder = $other->agent_id === $self->agent_id
                    ? self::label($other)
                    : $other->grant()->resolvedAgentSymbol().':'.self::label($other);
                if ($pair['source']) {
                    $taken[$pair['good'].'@'.$pair['source']] ??= $holder;
                }
                $taken[$pair['good'].'@'.$pair['buyer']] ??= $holder;
            }
        }

        return $taken;
    }

    /**
     * In-flight cargo units and arrival ETAs targeting a given (good, buyer) across all operator agents
     * trading in that system, so multi-hauler fleets can project incoming buyer bid decay.
     *
     * @return list<array{holder: string, units: int, eta: ?string}>
     */
    public function inFlight(CampaignEpisode $self, string $good, string $buyer): array
    {
        $result = [];
        $agentIds = Agent::where('user_id', $self->grant()->user_id)->where('reset_id', $self->reset_id)->pluck('id');
        $runs = AutomationRun::whereIn('agent_id', $agentIds)->where('reset_id', $self->reset_id)
            ->where('campaign_episode_id', '!=', $self->id)->whereNotNull('active_agent_id')
            ->whereIn('strategy', ['trade', 'sell_aboard'])->where('plan->market', $buyer)->get();
        $seen = [];
        foreach ($runs as $run) {
            $ship = Ship::where('agent_id', $run->agent_id)->where('symbol', $run->ship_symbol)->first()?->snapshot;
            $units = collect($ship['cargo']['inventory'] ?? [])->where('symbol', $good)->sum('units');
            $key = $run->agent_id.':'.$run->ship_symbol;
            if (isset($seen[$key]) || ! $units || ($ship['nav']['status'] ?? null) !== 'IN_TRANSIT'
                || ($ship['nav']['systemSymbol'] ?? null) !== self::systemOf($buyer)
                || ($ship['nav']['route']['destination']['symbol'] ?? $ship['nav']['waypointSymbol'] ?? null) !== $buyer) {
                continue;
            }
            $seen[$key] = true;
            $other = CampaignEpisode::find($run->campaign_episode_id);
            $holder = $other ? self::label($other) : $run->ship_symbol;
            if ($run->agent_id !== $self->agent_id && $other) {
                $holder = $other->grant()->resolvedAgentSymbol().':'.$holder;
            }
            $result[] = ['holder' => $holder, 'units' => (int) $units, 'eta' => $ship['nav']['route']['arrival'] ?? null];
        }

        return $result;
    }

    public static function systemOf(?string $waypoint): ?string
    {
        if (! is_string($waypoint) || $waypoint === '') {
            return null;
        }
        $parts = explode('-', $waypoint);

        return count($parts) >= 2 ? $parts[0].'-'.$parts[1] : null;
    }

    private function episodeSystem(CampaignEpisode $episode): ?string
    {
        $shipSymbol = $episode->state['claim']['ship'] ?? $episode->state['role_ships']['command'] ?? null;
        if (is_string($shipSymbol) && $shipSymbol !== '') {
            $ship = Ship::where('agent_id', $episode->agent_id)->where('symbol', $shipSymbol)->first();
            $system = $ship?->snapshot['nav']['systemSymbol'] ?? null;
            if (is_string($system) && $system !== '') {
                return $system;
            }
        }

        $command = Ship::where('agent_id', $episode->agent_id)->where('snapshot->registration->role', 'COMMAND')->first();
        if (is_string($command?->snapshot['nav']['systemSymbol'] ?? null)) {
            return $command->snapshot['nav']['systemSymbol'];
        }

        return self::systemOf($episode->state['claim']['buyer'] ?? $episode->state['claim']['source'] ?? null);
    }

    /**
     * The pairs an episode holds: its claim while fresh, then its active or previewed trade runs.
     *
     * @return list<array{good: string, source: ?string, buyer: string, system: ?string}>
     */
    public function pairs(CampaignEpisode $episode): array
    {
        $pairs = [];
        $claim = $episode->state['claim'] ?? null;
        $claimedShip = $claim ? Ship::where('agent_id', $episode->agent_id)
            ->where('symbol', $claim['ship'] ?? $episode->state['role_ships']['command'] ?? '')->first()?->snapshot : null;
        $cargo = $claim && collect($claimedShip['cargo']['inventory'] ?? [])->contains(fn ($item) => $item['symbol'] === $claim['good'] && $item['units'] > 0);
        $active = AutomationRun::where('campaign_episode_id', $episode->id)->whereNotNull('active_agent_id')->exists();
        if ($claim && isset($claim['at']) && ($cargo || ((! $episode->closed_at && $episode->grant()->available() || $active) && now()->subMinutes(self::FRESH_MINUTES)->lt($claim['at'])))) {
            $pairs[] = ['good' => $claim['good'], 'source' => $claim['source'], 'buyer' => $claim['buyer'],
                'system' => $claimedShip['nav']['systemSymbol'] ?? null];
        }
        $runs = AutomationRun::where('campaign_episode_id', $episode->id)->whereIn('strategy', ['trade', 'sell_aboard'])
            ->where(fn ($query) => $query->whereNotNull('active_agent_id')->orWhere(fn ($preview) => $preview->where('state', 'preview')->where('expires_at', '>', now())))
            ->orderByDesc('id')->get();
        foreach ($runs as $run) {
            $ship = Ship::where('agent_id', $episode->agent_id)->where('symbol', $run->ship_symbol)->first()?->snapshot;
            $system = $ship['nav']['systemSymbol'] ?? null;
            if ($run->strategy === 'sell_aboard') {
                foreach (array_keys($run->plan['initial_inventory'] ?? []) as $good) {
                    $pairs[] = ['good' => $good, 'source' => null, 'buyer' => $run->plan['market'], 'system' => $system];
                }

                continue;
            }
            if (isset($run->plan['symbol'], $run->plan['site'], $run->plan['market'])) {
                $pairs[] = ['good' => $run->plan['symbol'], 'source' => $run->plan['site'], 'buyer' => $run->plan['market'],
                    'system' => $system];
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
        return CampaignEpisode::where('reset_id', $follower->reset_id)->where('agent_id', $follower->agent_id)->whereNull('closed_at')->whereKeyNot($follower->id)->orderByDesc('id')->get()
            ->first(function (CampaignEpisode $episode) use ($label) {
                $grant = $episode->grant();

                return ! $grant->revoked_at && ! $grant->superseded_at && (self::arm($grant)['label'] ?? 'C0') === $label;
            });
    }

    public function follow(AutomationRun $run, CampaignEpisode $episode, string $command, array $arguments): void
    {
        $label = $episode->grant()->arm()['follow'] ?? null;
        if (! $label || ! in_array($run->strategy, ['trade', 'sell_aboard'], true)) {
            return;
        }
        $plan = $run->plan;
        if (! array_key_exists('leader_claim', $plan['campaign_trade'] ?? [])) {
            $leader = $this->leader($episode, $label);
            $original = isset($plan['recovery_of']) ? AutomationRun::whereKey($plan['recovery_of'])->where('campaign_episode_id', $episode->id)->first() : null;
            $plan['campaign_trade']['leader_claim'] = $original && array_key_exists('leader_claim', $original->plan['campaign_trade'] ?? [])
                ? $original->plan['campaign_trade']['leader_claim'] : ($leader ? ['episode' => $leader->id, 'claim' => $leader->state['claim'] ?? null] : null);
            $run->update(['plan' => $plan]);
        }
        $saved = $run->plan['campaign_trade']['leader_claim'] ?? null;
        $claim = $saved['claim'] ?? null;
        $leader = isset($saved['episode']) ? CampaignEpisode::find($saved['episode']) : null;
        if (! $claim || ! $leader || $leader->closed_at || ($leader->state['claim'] ?? null) !== $claim
            || CarbonImmutable::parse($claim['at'])->addMinutes(20)->isPast()) {
            return;
        }
        $waypoint = $command === 'buy' ? $claim['source'] : $claim['buyer'];
        if ($claim['good'] !== ($arguments['symbol'] ?? null) || $waypoint !== ($command === 'buy' ? $run->plan['site'] : $run->plan['market'])) {
            return;
        }
        $confirmed = ShipAction::where('agent_id', $episode->agent_id)->where('reset_id', $episode->reset_id)
            ->where('ship_symbol', $claim['ship'] ?? $leader->state['role_ships']['command'] ?? '')
            ->whereIn('automation_run_id', AutomationRun::where('campaign_episode_id', $leader->id)->select('id'))
            ->where('state', 'confirmed')->where('command', $command)->where('arguments->symbol', $arguments['symbol'] ?? null)
            ->where('before_state->ship->nav->waypointSymbol', $waypoint)->where('settled_at', '>=', CarbonImmutable::parse($claim['at']))->exists();
        if (! $confirmed) {
            throw new GameException('Follower waiting for leader '.$command.'.', retryAfter: 30);
        }
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
