<?php

namespace App\Gameplay\Bridge;

use App\Models\AutomationRun;
use App\Models\CampaignEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BridgeLog
{
    private const PROBLEMS = ['pause', 'drain_pause', 'director_error', 'replacement_refused', 'freshness_hold', 'claim_collision'];

    private const ROUTES = ['load_result', 'recovery_result'];

    private const PLAN = ['approval', 'activated', 'superseded', 'phase', 'resume', 'revoked', 'registration', 'registration_intent', 'probe_purchased'];

    public static function read(BridgeScope $scope, string $filter = 'all', ?int $before = null, ?string $route = null, int $limit = 50): array
    {
        $key = $route === null ? null : BridgeResults::parseKey($route);
        $filter = $key !== null ? 'routes' : (in_array($filter, ['all', 'problems', 'routes', 'plan'], true) ? $filter : 'all');
        $page = ['rows' => [], 'next_before' => null, 'filter' => $filter, 'route' => $route];
        if ($scope->agent === null || $scope->resetId === null || ($route !== null && $key === null)) {
            return $page;
        }

        $events = BridgeResults::withLegacySource(BridgeResults::events());
        self::scopeEvents($events, $scope);

        if ($filter === 'problems') {
            $events->where(fn ($query) => $query->whereIn('event.kind', self::PROBLEMS)
                ->orWhere(fn ($query) => $query->where('event.kind', 'load_result')->whereRaw(BridgeResults::NET.' < 0')));
        } else {
            $events->whereIn('event.kind', match ($filter) {
                'routes' => self::ROUTES,
                'plan' => self::PLAN,
                default => [...self::PROBLEMS, ...self::ROUTES, ...self::PLAN],
            });
        }
        if ($key !== null) {
            BridgeResults::matchingRoute($events, $key);
        }

        $limit = max(1, min(50, $limit));
        $rows = $events->when($before !== null, fn ($query) => $query->where('event.id', '<', $before))
            ->latest('event.id')->limit($limit + 1)
            ->select(['event.id', 'event.kind', 'event.data', 'event.created_at', 'result_run.ship_symbol as legacy_ship'])
            ->selectRaw(BridgeResults::SOURCE.' AS route_source')
            ->get();
        $pageRows = $rows->take($limit)->map(fn ($row) => (new CampaignEvent)->newFromBuilder((array) $row));
        $runIds = $pageRows->filter(fn (CampaignEvent $event) => empty($event->data['ship']) && $event->legacy_ship === null)
            ->pluck('data.run_id')->filter()->unique();
        $runShips = $runIds->isEmpty() ? collect() : AutomationRun::whereIn('id', $runIds)
            ->where('reset_id', $scope->resetId)->whereIn('campaign_episode_id', $scope->episodes->keys())->pluck('ship_symbol', 'id');

        return [
            'rows' => $pageRows->map(fn (CampaignEvent $event) => self::row($scope, $event, $runShips))->all(),
            'next_before' => $rows->count() > $limit ? $pageRows->last()->id : null,
            'filter' => $filter, 'route' => $route,
        ];
    }

    private static function scopeEvents(Builder $events, BridgeScope $scope): void
    {
        $grants = DB::table('campaign_grants as grant')->where('grant.user_id', $scope->userId)->where('grant.agent_symbol', $scope->symbol);
        $firstEpisode = $scope->episodes->first();
        $end = $firstEpisode?->next_reset_at;
        $date = self::resetDate($scope->resetId);
        $prior = self::previousWipe($scope, clone $grants, $scope->resetId, $date, $firstEpisode?->opened_at);
        $priorEnd = $prior ? CarbonImmutable::parse($prior->next_reset_at, 'UTC') : null;
        $exactStart = $priorEnd !== null && $priorEnd->gte($date) ? $priorEnd : null;
        $start = $exactStart ?? $date?->addDay();
        $before = $exactStart ?? $date;
        $previousStart = $firstEpisode !== null && $prior !== null ? self::previousStart($scope, clone $grants, $prior->reset_id) : null;
        $untagged = function (Builder $query) use ($scope, $grants, $firstEpisode, $start, $end, $before, $previousStart, $priorEnd) {
            if ($start !== null) {
                $query->orWhere(fn ($query) => self::inInterval($query, clone $grants, $start, $end));
            }
            if ($firstEpisode !== null) {
                $query->orWhere(fn ($query) => self::originalApproval($query, $scope, $before, $previousStart, $priorEnd));
            }
        };

        $events->where(fn ($query) => $query->whereIn('event.campaign_episode_id', $scope->episodes->keys())
            ->orWhere(fn ($query) => $query->whereNull('event.campaign_episode_id')
                ->whereIn('event.campaign_grant_id', (clone $grants)->select('grant.id'))
                ->where(fn ($query) => $query->where('event.data->reset_id', $scope->resetId)
                    ->when($start !== null || $firstEpisode !== null, fn ($query) => $query
                        ->orWhere(fn ($query) => $query->whereNull('event.data->reset_id')->where($untagged))))));
    }

    private static function previousWipe(BridgeScope $scope, Builder $grants, string $resetId, ?CarbonImmutable $date, ?CarbonImmutable $opened = null): ?object
    {
        if ($date === null) {
            return null;
        }

        return DB::table('campaign_episodes as prior')
            ->joinSub($grants->select('grant.id'), 'scoped_grants', 'scoped_grants.id', '=', 'prior.campaign_grant_id')
            ->join('agents as prior_agent', 'prior_agent.id', '=', 'prior.agent_id')
            ->where('prior_agent.user_id', $scope->userId)->where('prior_agent.name', $scope->symbol)
            ->where('prior.reset_id', '!=', $resetId)->where('prior.opened_at', '<', $date->addDay())
            ->where('prior.next_reset_at', '<', $date->addDay())
            ->whereColumn('prior.opened_at', '<', 'prior.next_reset_at')
            ->when($opened !== null, fn ($query) => $query->where('prior.next_reset_at', '<=', $opened))
            ->latest('prior.next_reset_at')->latest('prior.id')
            ->first(['prior.reset_id', 'prior.next_reset_at']);
    }

    private static function previousStart(BridgeScope $scope, Builder $grants, string $resetId): ?CarbonImmutable
    {
        $date = self::resetDate($resetId);
        $previous = self::previousWipe($scope, $grants, $resetId, $date);
        if ($previous !== null) {
            $end = CarbonImmutable::parse($previous->next_reset_at, 'UTC');
            if ($end->gte($date)) {
                return $end;
            }
        }

        return $date?->addDay();
    }

    private static function inInterval(Builder $query, Builder $grants, CarbonImmutable $start, ?CarbonImmutable $end): void
    {
        $grants->when($end !== null, fn ($query) => $query->where('grant.created_at', '<', $end));
        foreach (['revoked_at', 'superseded_at', 'expires_at'] as $column) {
            $grants->where(fn ($query) => $query->whereNull('grant.'.$column)->orWhere('grant.'.$column, '>=', $start));
        }

        $query->where('event.created_at', '>=', $start)
            ->when($end !== null, fn ($query) => $query->where('event.created_at', '<', $end))
            ->whereIn('event.campaign_grant_id', $grants->select('grant.id'));
    }

    private static function originalApproval(Builder $query, BridgeScope $scope, ?CarbonImmutable $before, ?CarbonImmutable $previousStart, ?CarbonImmutable $previousEnd): void
    {
        // The earliest episode for this grant can explain why it was authorized before the wipe started.
        $query->where('event.kind', 'approval')
            ->when($before !== null, fn ($query) => $query->where('event.created_at', '<', $before))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('campaign_episodes as first_episode')
                ->whereIn('first_episode.id', $scope->episodes->keys())
                ->whereColumn('first_episode.campaign_grant_id', 'event.campaign_grant_id')
                ->whereColumn('event.created_at', '<=', 'first_episode.opened_at')
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('campaign_episodes as earlier')
                    ->whereColumn('earlier.campaign_grant_id', 'first_episode.campaign_grant_id')
                    ->where(fn ($query) => $query->whereColumn('earlier.opened_at', '<', 'first_episode.opened_at')
                        ->orWhere(fn ($query) => $query->whereColumn('earlier.opened_at', '=', 'first_episode.opened_at')
                            ->whereColumn('earlier.id', '<', 'first_episode.id')))))
            ->when($previousStart && $previousEnd, fn ($query) => $query->where(fn ($query) => $query
                ->where('event.created_at', '<', $previousStart)->orWhere('event.created_at', '>=', $previousEnd)));
    }

    private static function resetDate(string $resetId): ?CarbonImmutable
    {
        if (! preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $resetId, $parts) || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return CarbonImmutable::parse($resetId, 'UTC')->startOfDay();
    }

    private static function row(BridgeScope $scope, CampaignEvent $event, Collection $runShips): array
    {
        $data = $event->data;
        $symbol = ($data['ship'] ?? null) ?: ($event->legacy_ship ?? $runShips->get($data['run_id'] ?? null));
        $ship = $scope->ships->get($symbol);
        $route = $event->kind === 'load_result' ? BridgeResults::key($data['good'] ?? null, $event->route_source, $data['buyer'] ?? null) : null;
        $link = null;
        if ($route !== null) {
            [$good, $source, $buyer] = BridgeResults::parseKey($route);
            $link = route('campaign.bridge.route', ['good' => $good, 'source' => $source, 'buyer' => $buyer, 'agent' => $scope->symbol, 'from' => 'log'], absolute: false);
        } elseif ($ship !== null) {
            $link = route('campaign.bridge.ship', ['ship' => $ship->symbol, 'agent' => $scope->symbol, 'from' => 'log'], absolute: false);
        }
        $problem = in_array($event->kind, self::PROBLEMS, true) || ($event->kind === 'load_result' && ($data['net'] ?? 0) < 0);

        return [
            'id' => $event->id, 'at' => $event->created_at?->toIso8601String(), 'kind' => $event->kind,
            'severity' => $problem ? 'problem' : (in_array($event->kind, self::ROUTES, true) ? 'ok' : 'info'),
            ...self::describe($event),
            'ship' => $symbol ? ['symbol' => $symbol, 'name' => $ship?->displayName() ?? $symbol] : null,
            'route_key' => $route, 'link' => $link,
        ];
    }

    /** @return array{title: string, detail: ?string} */
    public static function describe(CampaignEvent $event): array
    {
        $data = $event->data;
        [$title, $detail] = match ($event->kind) {
            'load_result', 'recovery_result' => [
                $event->kind === 'recovery_result' ? 'Remaining cargo sold' : (($data['net'] ?? 0) < 0 ? 'Load finished at a loss' : 'Load completed'),
                self::resultDetail($data),
            ],
            'pause' => ['Work paused', self::pauseDetail($data['reason'] ?? null)],
            'drain_pause' => ['Fleet held to protect the request budget', self::pauseDetail($data['reason'] ?? null)],
            'director_error' => ['The director could not make a decision', isset($data['consecutive']) ? (int) $data['consecutive'].' failed decisions in a row' : null],
            'replacement_refused' => ['Replacement playbook refused', 'Review its approval on the PC.'],
            'freshness_hold' => ['Waiting for fresh observations', isset($data['run_id']) ? 'Run #'.(int) $data['run_id'] : null],
            'claim_collision' => ['Two ships targeted the same market', self::label($data['good'] ?? null)],
            'approval' => ['Playbook approved', 'Revision #'.(int) ($data['revision'] ?? 0).(! empty($data['pending']) ? ' · Pending safe point' : ' · Active')],
            'activated' => ['Replacement playbook activated', null],
            'superseded' => ['Previous playbook replaced', null],
            'phase' => ['Campaign phase changed', self::label($data['from'] ?? null).' → '.self::label($data['to'] ?? null)],
            'resume' => ['Work resumed', 'The next decision uses fresh state.'],
            'revoked' => ['Approval revoked', null],
            'registration' => ['Agent registered', isset($data['agent_id']) ? 'Agent #'.(int) $data['agent_id'] : null],
            'registration_intent' => ['Agent registration requested', null],
            'probe_purchased' => ['Probe purchased', ($data['shipyard'] ?? 'Unknown shipyard').' · '.number_format((int) ($data['price'] ?? 0)).' CR'],
            default => ['Campaign updated', null],
        };

        return ['title' => $title, 'detail' => $detail];
    }

    private static function pauseDetail(?string $reason): string
    {
        return match (Str::after($reason ?? '', 'drain_pause:')) {
            'operator_pause' => 'Paused by the operator.',
            'grant_expired' => 'The playbook approval expired.',
            'consecutive_losing_loads' => 'Two loads in a row lost credits.',
            'arm_loss_stop' => 'The ship reached its loss limit.',
            'uncertain_action' => 'The outcome of a command needs review.',
            'unexplained_change' => 'Credits changed without explanation.',
            'no_cargo_buyer' => 'A buyer for the cargo is unavailable.',
            'recovery_stalled' => 'The recovery sale stalled.',
            'director_error' => 'The director could not continue.',
            'host_rate_limits' => 'The host received rate-limit responses.',
            'host_operating_pressure', 'host_total_pressure' => 'The host reached its request limit.',
            'control_degradation' => 'The control campaign slowed while waiting for requests.',
            'shared_system_cannibalization' => 'Competition in the shared system reduced trade margins.',
            default => 'Review the stop on Bridge.',
        };
    }

    private static function resultDetail(array $data): string
    {
        $text = (isset($data['units']) ? number_format((int) $data['units']).' units of ' : '').self::label($data['good'] ?? null);
        if (isset($data['net'])) {
            $text .= ' · '.($data['net'] > 0 ? '+' : '').number_format($data['net']).' CR net';
        }

        return $text;
    }

    private static function label(?string $value): string
    {
        if ($value === null) {
            return 'Unknown';
        }

        return Str::headline(Str::upper($value) === $value ? Str::lower($value) : $value);
    }
}
