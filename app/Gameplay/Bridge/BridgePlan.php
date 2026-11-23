<?php

namespace App\Gameplay\Bridge;

use App\Gameplay\CampaignFleet;
use App\Gameplay\RequestBudget;
use App\Gameplay\RequestDeferred;
use App\Models\AutomationRun;
use App\Models\ShipAction;

final class BridgePlan
{
    public static function read(BridgeScope $scope): array
    {
        $episode = $scope->openEpisodes->get($scope->primary?->id);
        $phase = $episode?->phase;
        if ($phase === null && $scope->primary) {
            $phase = self::setupStatus($scope);
        }

        return [
            'phase' => $phase,
            'phases' => array_column($scope->revision?->body['phases'] ?? [], 'id'),
            'pending' => self::pending($scope),
        ] + self::budget($scope->symbol);
    }

    public static function waitingFor(?string $reason): ?string
    {
        return match ($reason) {
            'awaiting_engine' => 'the engine is off',
            'awaiting_automation' => 'automation is disabled',
            'awaiting_registration' => 'agent registration is pending',
            'awaiting_reset_schedule' => 'the reset schedule needs confirmation',
            'awaiting_agent' => 'the new agent is unavailable',
            'awaiting_fresh_state' => 'ship data needs a refresh',
            default => null,
        };
    }

    private static function setupStatus(BridgeScope $scope): string
    {
        $blocker = match ($scope->primary->pause_reason) {
            'account_token_missing' => 'Waiting for an account token',
            'registration_unknown', 'registration_requires_review' => 'Waiting for registration review',
            default => null,
        };

        if ($blocker !== null || ! $scope->agent) {
            return $blocker ?? 'Waiting for the new wipe';
        }
        $reason = self::waitingFor($scope->primary->wait_reason);

        return $reason ? 'Waiting because '.$reason : 'Waiting for the main campaign to start';
    }

    private static function pending(BridgeScope $scope): ?string
    {
        if ($scope->primary?->pending_replacement_id === null) {
            return null;
        }

        $primary = $scope->primary;
        $expired = $primary->pause_reason === 'grant_expired' || $primary->expires_at?->lte(now());
        if ($expired && self::hasWorkToSettle($scope)) {
            return 'New playbook queued. Waiting for the main campaign to settle.';
        }
        if (! $expired && ($primary->paused_at || BridgeDrain::marker($primary, $scope->openEpisodes->get($primary->id)) !== null)) {
            return 'New playbook queued. Waiting for the main campaign to resume or expire.';
        }

        return 'New playbook queued for the next safe point';
    }

    private static function hasWorkToSettle(BridgeScope $scope): bool
    {
        $episodeIds = $scope->episodes->where('campaign_grant_id', $scope->primary->id)->pluck('id');
        if ($episodeIds->isEmpty()) {
            return false;
        }
        if ($scope->activeRuns->contains(fn (AutomationRun $run) => $episodeIds->contains($run->campaign_episode_id))) {
            return true;
        }

        $pending = ShipAction::where('agent_id', $scope->agent->id)->where('reset_id', $scope->resetId)
            ->whereIn('state', ['pending', 'sending']);
        if (! (clone $pending)->exists()) {
            return false;
        }

        $episode = $scope->openEpisodes->get($scope->primary->id);
        $symbols = $episode ? app(CampaignFleet::class)->symbols($episode, pinRoles: false) : [];

        return $pending->where(fn ($query) => $query->whereIn('ship_symbol', $symbols)
            ->orWhereIn('automation_run_id', AutomationRun::whereIn('campaign_episode_id', $episodeIds)->select('id')))->exists();
    }

    private static function budget(?string $symbol): array
    {
        $empty = ['budget_detail' => null, 'watching' => []];
        if ($symbol === null) {
            return ['budget' => ''] + $empty;
        }
        if (! config('gameplay.request_budget_enabled')) {
            return ['budget' => 'Request budget off'] + $empty;
        }

        try {
            ['host' => $host, 'agent' => $agent] = app(RequestBudget::class)->usage(agentSymbol: $symbol, requireTelemetry: true);
        } catch (RequestDeferred) {
            return ['budget' => 'Request budget unavailable'] + $empty;
        }

        $ordinary = $agent['tier2_4_last_hour'];
        $used = number_format($ordinary);
        $cap = $agent['cap_per_hour'];
        $allowance = match ($cap) {
            null => "Requests {$used} an hour (no cap)",
            0 => 'No Tier 2–4 allowance',
            default => "Requests {$used} / ".number_format($cap).' an hour',
        };

        $hostOrdinary = $host['tier2_4_last_60_seconds'];
        $hostCap = config('gameplay.host_tier2_4_per_minute');
        $rateLimits = $host['rate_limits_last_15_minutes'];
        $deferrals = $agent['deferrals_last_hour'];
        $settlement = $agent['tier1_last_hour'];
        $deferralShare = $deferrals / max(1, $ordinary + $settlement + $deferrals);
        $watching = [];
        if ($rateLimits > 0) {
            $watching[] = $rateLimits === 1
                ? 'SpaceTraders rate-limited us once in 15 min'
                : "SpaceTraders rate-limited us {$rateLimits} times in 15 min";
        }
        if ($deferralShare > 0.05) {
            $watching[] = number_format($deferralShare * 100).'% of requests waited for budget this hour';
        }
        if ($cap > 0 && $ordinary >= 0.9 * $cap) {
            $watching[] = 'Using '.number_format($ordinary / $cap * 100)."% of {$symbol}'s request allowance";
        }
        if ($hostOrdinary >= 86.4) {
            $watching[] = 'Request rate near the host limit';
        }

        $hostReserve = config('gameplay.host_tier1_reserve_per_minute');
        $rateLimitsLabel = $rateLimits === 1 ? 'rate limit' : 'rate limits';

        return [
            'budget' => "{$allowance} · host {$hostOrdinary} / {$hostCap} a minute · {$rateLimits} {$rateLimitsLabel} (15 min)",
            'budget_detail' => 'Tier 1 settlement requests '.number_format($settlement).' / '.number_format($agent['tier1_reserve_per_hour'])
                ." an hour · host {$host['tier1_last_60_seconds']} / {$hostReserve} a minute",
            'watching' => $watching,
        ];
    }
}
