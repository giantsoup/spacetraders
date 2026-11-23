<?php

namespace App\Gameplay\Bridge;

use App\Gameplay\CampaignHealth;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\LocalEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class BridgeAttention
{
    private const SCOPES = ['agent' => 0, 'main campaign' => 1, 'one ship' => 2];

    private const SAFETY_REASONS = ['unexplained_change', 'uncertain_action'];

    private const REGISTRATION_REASONS = ['registration_unknown', 'registration_requires_review', 'account_token_missing'];

    public static function read(BridgeScope $scope, array $plan): array
    {
        $engine = LocalEngine::find(1);
        $heartbeat = $engine?->heartbeat_at;
        $stale = ! $heartbeat || $heartbeat->lt(now()->subMinutes(5));
        $cards = $stale ? [[
            'code' => 'engine_heartbeat_stale',
            'scope' => 'engine',
            'title' => "Engine isn't responding",
            'detail' => $heartbeat ? 'Last heartbeat '.$heartbeat->shortAbsoluteDiffForHumans().' ago.' : 'No engine heartbeat has been recorded.',
            'href' => self::flightDeck($scope),
        ]] : [];
        $watching = $plan['watching'] ?? [];
        $agents = [];
        if ($scope->primary) {
            $holds = app(BridgeDrain::class)->budgetHolds() ?? [];
            foreach ($scope->primaries as $primary) {
                $symbol = $primary->agent_symbol;
                $agent = $scope->currentAgents->get($symbol);
                $arms = $scope->allArms->get($symbol, collect());
                $stops = self::stops($primary, $arms, $scope->allOpenEpisodes, in_array($symbol, $holds, true));
                $agents[] = [
                    'symbol' => $symbol,
                    'credits' => $agent?->credits,
                    'state' => self::state($primary, $stops, $agent !== null, $scope->allOpenEpisodes->has($primary->id)),
                    'needs_you' => count($stops),
                ];
                if ($symbol === $scope->symbol) {
                    array_push($cards, ...self::cards($scope, $stops));
                }
            }
            array_push($watching, ...self::campaignWatching($scope, ! $stale && $engine?->state === 'running'));
        }

        return [
            'cards' => $cards,
            'watching' => $watching,
            'agents' => collect($agents)->sortByDesc(fn (array $agent) => $agent['needs_you'] > 0)->values()->all(),
            'engine' => [
                'state' => $engine?->state ?? 'off',
                'heartbeat_at' => $heartbeat?->toIso8601String(),
                'heartbeat_stale' => $stale,
            ],
        ];
    }

    private static function stops(CampaignGrant $primary, Collection $arms, Collection $episodes, bool $held): array
    {
        $stops = [];
        $draining = [];
        $fleetPause = $primary->paused_at && $primary->pause_reason === 'operator_pause'
            && $arms->contains(fn (CampaignGrant $arm) => $arm->paused_at && $arm->pause_reason === 'operator_pause');
        foreach (collect([$primary])->concat($arms) as $grant) {
            $episode = $episodes->get($grant->id);
            $marker = BridgeDrain::marker($grant, $episode);
            if ($marker !== null) {
                $draining[] = ['grant' => $grant, 'reason' => $marker, 'settling' => BridgeDrain::incomplete($grant, $episode)];
            }

            $reason = $grant->paused_at ? ($grant->pause_reason ?: 'paused') : null;
            if (in_array($reason, self::SAFETY_REASONS, true)) {
                if (! isset($stops[$reason]) || $grant->paused_at->gt($stops[$reason]['at'])) {
                    $stops[$reason] = ['code' => $reason, 'scope' => 'agent', 'grant' => $primary, 'at' => $grant->paused_at];
                }
                $reason = null;
            }
            if (str_starts_with($reason ?? '', 'drain_pause:') || ($fleetPause && $grant !== $primary && $reason === 'operator_pause')) {
                $reason = null;
            }

            $at = $grant->paused_at;
            if ($grant !== $primary && BridgeGuards::lossStop($grant, (int) $episode?->recorded_load_net)) {
                $reason = 'arm_loss_stop';
                if ($grant->pause_reason !== $reason && $episode?->last_load_at) {
                    $at = CarbonImmutable::parse($episode->last_load_at);
                }
            } elseif ($grant->expires_at?->lte(now()) && ! in_array($reason, self::REGISTRATION_REASONS, true)) {
                $reason = 'grant_expired';
                $at = $grant->expires_at;
            }
            if ($reason !== null) {
                $kind = $grant === $primary ? 'main campaign' : 'one ship';
                if ($fleetPause && $reason === 'operator_pause') {
                    $kind = 'agent';
                }
                $stops[] = ['code' => $reason, 'scope' => $kind, 'grant' => $grant, 'at' => $at ?? $grant->updated_at];
            }
        }
        if ($held || $draining !== []) {
            $at = collect($draining)->max(fn (array $target) => $target['grant']->updated_at) ?? $primary->updated_at;
            $stops[] = ['code' => 'drain_pause', 'scope' => 'agent', 'grant' => $primary, 'at' => $at, 'targets' => $draining];
        }

        return collect($stops)->sortBy(fn (array $stop) => [
            self::SCOPES[$stop['scope']], -($stop['at']?->timestamp ?? 0), -$stop['grant']->id,
        ])->values()->all();
    }

    private static function state(CampaignGrant $primary, array $stops, bool $hasAgent, bool $hasEpisode): string
    {
        $drain = collect($stops)->firstWhere('code', 'drain_pause');
        if ($drain) {
            return $drain['targets'] === [] || collect($drain['targets'])->contains('settling', true) ? 'draining' : 'paused';
        }
        if ($primary->paused_at || $primary->expires_at?->lte(now()) || in_array('agent', array_column($stops, 'scope'), true)) {
            return 'paused';
        }
        if (! $hasAgent || $primary->wait_reason) {
            return 'waiting';
        }

        return $hasEpisode ? 'running' : 'idle';
    }

    private static function cards(BridgeScope $scope, array $stops): array
    {
        $errors = self::directorErrors($stops);
        $drained = collect($stops)->contains('code', 'drain_pause');

        return array_map(function (array $stop) use ($scope, $drained, $errors) {
            $grant = $stop['grant'];
            $code = $stop['code'];
            $episode = $scope->openEpisodes->get($grant->id);
            $net = $episode?->recorded_load_net === null ? null : (int) $episode->recorded_load_net;
            $ship = $grant->arm()['ship'] ?? null;
            $name = $scope->ships->get($ship)?->displayName() ?? $ship;
            $card = [
                'code' => $code, 'scope' => $stop['scope'], 'grant_id' => $grant->id, 'at' => $stop['at']?->toIso8601String(),
            ];
            if ($ship) {
                $card['ship'] = $ship;
            }

            if ($code === 'drain_pause') {
                [$title, $detail] = self::drainText($scope, $stop['targets']);
            } else {
                [$title, $detail] = self::cardText($scope, $stop, $name, $net, $errors->get($grant->id));
            }
            $card += compact('title', 'detail');

            $href = match ($code) {
                'unexplained_change', 'uncertain_action' => self::flightDeck($scope),
                'registration_unknown', 'registration_requires_review', 'account_token_missing' => route('agents.index', ['agent' => $scope->symbol]),
                'no_cargo_buyer', 'recovery_stalled' => self::cargoLink($scope),
                default => null,
            };
            if ($href) {
                $card['href'] = $href;
            }

            $review = self::review($scope, $stop, $net ?? 0, $drained);
            if ($review) {
                $card['review'] = $review;
            }

            return $card;
        }, $stops);
    }

    private static function review(BridgeScope $scope, array $stop, int $net, bool $drained): ?string
    {
        $grant = $stop['grant'];
        $code = $stop['code'];
        if (! $scope->agent || in_array($code, self::REGISTRATION_REASONS, true)) {
            return null;
        }
        if ($grant->arm() && BridgeGuards::retireEligible($grant, $net) && ($code !== 'director_error' || $drained)) {
            return 'arm_retire';
        }
        if ($code === 'grant_expired') {
            return null;
        }
        if ($drained || $stop['scope'] === 'agent') {
            return 'fleet_resume';
        }

        return $grant->arm() ? 'arm_resume' : 'primary_resume';
    }

    private static function cardText(BridgeScope $scope, array $stop, ?string $name, ?int $net, ?CampaignEvent $error): array
    {
        $command = "After fixing it, run campaign:resume --agent={$scope->symbol} on the PC.";
        $retire = $scope->agent
            ? 'After retirement, approve a new arm on the PC.'
            : 'On the PC, revoke the arm with campaign:arm-revoke before approving a new one.';
        $losses = $scope->revision?->body['trade']['losses']['pause_after_consecutive_losing_loads'] ?? 2;
        $subject = $stop['scope'] === 'agent' ? 'fleet' : 'main campaign';
        $failure = $error?->data['message'] ?? null;

        return match ($stop['code']) {
            'unexplained_change' => ['Credits changed without explanation', 'Review the balance on the flight deck before resuming the fleet.'],
            'uncertain_action' => ['A command needs review', 'The autopilot could not confirm a command or its price. Reconcile it on the flight deck.'],
            'consecutive_losing_loads' => $name
                ? ["{$name} paused after losing loads", 'Review the recent loads before resuming this ship.']
                : ["Main campaign paused after {$losses} losing loads", 'Review the recent loads before resuming the main campaign.'],
            'arm_loss_stop' => ["{$name} hit its loss limit", $net === null ? $retire : 'Recorded net '.number_format($net).' credits. '.$retire],
            'grant_expired' => $name
                ? ["{$name}'s approval expired", $retire]
                : ["This week's playbook approval expired", "Approve the week's playbook on the PC with campaign:approve."],
            'registration_unknown', 'registration_requires_review' => [
                "Couldn't confirm the new agent after the reset", 'Check the registration in Agents. '.$command,
            ],
            'account_token_missing' => ['The account token is missing', 'Save the account token in Agents. '.$command],
            'no_cargo_buyer' => ['Cargo has no buyer', 'Review the command ship’s cargo before resuming the main campaign.'],
            'recovery_stalled' => ['Cargo recovery stalled', 'Review the command ship’s cargo before resuming the main campaign.'],
            'director_error' => $name
                ? ["{$name} stopped after 5 failed decisions", $failure ?? 'Review the last failure before resuming or retiring this ship.']
                : ["Autopilot hit an error it couldn't handle", $failure ?? 'Review the last failure before resuming the main campaign.'],
            'state_requires_review', 'episode_already_closed' => [
                'Autopilot needs a review', 'Review the campaign’s state before resuming the main campaign.',
            ],
            'operator_pause' => [
                $name ? "You paused {$name}" : "You paused the {$subject}",
                'Review the pause before resuming.',
            ],
            default => [
                ($name ?? 'Main campaign').' paused',
                'Recorded reason '.Str::headline($stop['code']).'.',
            ],
        };
    }

    private static function drainText(BridgeScope $scope, array $targets): array
    {
        $title = "{$scope->symbol} held back";
        if ($targets === []) {
            return [$title, 'The request budget is holding this fleet. No individual grants are marked.'];
        }

        $reasons = collect($targets)->pluck('reason')->unique();
        if ($reasons->count() === 1) {
            $title .= ' for '.self::drainReason($reasons->first());
        }
        $detail = [];
        foreach ($targets as $target) {
            $ship = $target['grant']->arm()['ship'] ?? null;
            $name = $ship ? ($scope->ships->get($ship)?->displayName() ?? $ship) : 'Main campaign';
            $state = $target['settling'] ? 'settling' : 'paused';
            $detail[] = "{$name} ({$state}, ".self::drainReason($target['reason']).')';
        }

        return [$title, implode(' · ', $detail)];
    }

    private static function drainReason(string $reason): string
    {
        return match ($reason) {
            'host_operating_pressure' => 'high host request use',
            'host_total_pressure' => 'high total host request use',
            'host_rate_limits' => 'host rate limits',
            'control_degradation' => 'slower control fleet trades',
            'shared_system_cannibalization' => 'competition in the control fleet’s system',
            default => 'request budget pressure',
        };
    }

    private static function directorErrors(array $stops): Collection
    {
        $grantIds = collect($stops)->where('code', 'director_error')->pluck('grant.id');
        if ($grantIds->isEmpty()) {
            return collect();
        }

        return CampaignEvent::whereIn('id', CampaignEvent::whereIn('campaign_grant_id', $grantIds)
            ->where('kind', 'director_error')->groupBy('campaign_grant_id')->selectRaw('MAX(id)'))
            ->get()->keyBy('campaign_grant_id');
    }

    private static function flightDeck(BridgeScope $scope): string
    {
        return $scope->agent
            ? route('agents.show', $scope->agent).'?'.http_build_query(['agent' => $scope->symbol])
            : route('agents.index', array_filter(['agent' => $scope->symbol]));
    }

    private static function cargoLink(BridgeScope $scope): string
    {
        $episode = $scope->openEpisodes->get($scope->primary->id);
        $command = $episode?->state['role_ships']['command'] ?? null;

        return $scope->ships->has($command)
            ? route('campaign.bridge.ship', ['ship' => $command, 'agent' => $scope->symbol])
            : self::flightDeck($scope);
    }

    private static function campaignWatching(BridgeScope $scope, bool $engineAlive): array
    {
        $primary = $scope->primary;
        $watching = [];
        $wait = BridgePlan::waitingFor($primary->wait_reason);
        if ($wait && ! $primary->paused_at) {
            $watching[] = 'Autopilot waiting because '.$wait;
        }
        if (! $engineAlive || ! $scope->agent || $primary->paused_at) {
            return $watching;
        }

        $episode = $scope->openEpisodes->get($primary->id);
        $problems = app(CampaignHealth::class)->problems($primary);
        if (isset($problems['campaign_decisions_stalled:'.$primary->id])) {
            $watching[] = "Autopilot hasn't decided in 15 min";
        }
        if ($episode && isset($problems['campaign_progress_stalled:'.$primary->id])) {
            $watching[] = 'No run started or load sold in 60 min';
        }
        if ($episode && isset($problems['command_ship_stalled:'.$primary->id])) {
            $command = $episode->state['role_ships']['command'] ?? null;
            $name = $scope->ships->get($command)?->displayName() ?? $command ?? 'Command ship';
            $watching[] = "{$name} has been idle for at least ".CampaignHealth::COMMAND_STALL_MINUTES.' min';
        }

        return $watching;
    }
}
