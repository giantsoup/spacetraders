<?php

namespace App\Gameplay\Bridge;

use App\Enums\RunState;
use App\Gameplay\CampaignFleet;
use App\Gameplay\ExplorationPolicy;
use App\Maps\SystemMapKnowledge;
use App\Models\AutomationRun;
use App\Models\GameObservation;
use App\Models\Ship;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class BridgeFleet
{
    private const GROUPS = ['Stopped', 'Idle', 'Trading', 'Selling cargo', 'Positioning', 'Probes', 'Other work', 'Mining'];

    private const PROBE_GROUPS = ['Watching markets', 'Stationed', 'Scouting', 'On recon', 'Other'];

    public static function read(BridgeScope $scope): array
    {
        $rows = self::rows($scope, $scope->ships);
        $groups = self::groups($rows, 'group', self::GROUPS);
        $probes = $rows->where('group', 'Probes');

        return [
            'count' => $rows->count(),
            'groups' => array_map(fn ($group) => $group['name'] === 'Probes' ? array_replace($group, ['ships' => []]) : $group, $groups),
            'probes' => [
                'count' => $probes->count(),
                'groups' => array_map(fn ($group) => Arr::only($group, ['name', 'count']), self::probeGroups($probes)),
            ],
        ];
    }

    public static function probes(BridgeScope $scope): array
    {
        $ships = $scope->ships->filter(fn (Ship $ship) => app(CampaignFleet::class)->isProbe($ship->snapshot));

        return self::probeGroups(self::rows($scope, $ships)->where('group', 'Probes'));
    }

    public static function detail(BridgeScope $scope, string $ship): ?array
    {
        $record = $scope->ships->get($ship);
        if ($record === null || $scope->agent === null) {
            return null;
        }

        $row = self::rows($scope, collect([$record]))->first();
        if ($row['group'] === 'Stopped' && $row['route_key'] === null) {
            $episode = $scope->openEpisodes->get($row['grant_id']);
            $lastTrade = $episode === null ? null : AutomationRun::where('campaign_episode_id', $episode->id)
                ->where('ship_symbol', $ship)->where('strategy', 'trade')->latest('id')->first();
            if ($lastTrade !== null) {
                $row['route_key'] = BridgeResults::key(...BridgeResults::runKey($lastTrade));
            }
        }
        $events = BridgeResults::forWipe($scope)->where('event.data->ship', $ship);
        $net = BridgeResults::NET;
        $finished = BridgeResults::FINISHED;
        $loads = (clone $events)->where('event.kind', 'load_result');
        $totals = (clone $loads)->selectRaw("COUNT(*) AS loads, SUM($net) AS net")->first();
        $last = $loads->latest(DB::raw($finished))->latest('event.id')
            ->selectRaw("$finished AS last_at, $net AS last_net")->first();

        $nav = $record->snapshot['nav'] ?? [];
        $details = ['nav' => Arr::only($nav, ['status', 'waypointSymbol']) + [
            'route' => Arr::only($nav['route'] ?? [], ['departureTime', 'arrival', 'destination']),
        ]];

        return $row + [
            'totals' => [
                'loads' => $totals->loads, 'net' => $totals->net ?? 0,
                'last_at' => $last?->last_at, 'last_net' => $last?->last_net,
            ],
            'recent' => BridgeResults::recent($events->whereIn('event.kind', ['load_result', 'recovery_result']), 2),
            'report_href' => route('agents.show', ['agent' => $scope->agent->id, 'ship' => $ship], absolute: false).'#report',
            'details' => $details, 'observed_at' => $record->observed_at?->toIso8601String(), 'locations' => self::locations($scope, $record, $nav),
        ];
    }

    private static function rows(BridgeScope $scope, Collection $ships): Collection
    {
        if ($scope->agent === null || $ships->isEmpty()) {
            return collect();
        }

        $runs = $scope->activeRuns->where('reset_id', $scope->resetId)->whereIn('ship_symbol', $ships->pluck('symbol'))->keyBy('ship_symbol');
        $reviews = BridgeWork::reviews($scope, $runs);
        $grants = $scope->arms->filter(fn ($arm) => ! empty($arm->arm()['ship']))->keyBy(fn ($arm) => $arm->arm()['ship']);
        $primary = $scope->primary;
        $command = $scope->openEpisodes->get($primary?->id)?->state['role_ships']['command']
            ?? $scope->ships->first(fn (Ship $ship) => ($ship->snapshot['registration']['role'] ?? null) === 'COMMAND')?->symbol;
        if ($command !== null && $primary !== null) {
            $grants->put($command, $primary);
        }

        $fleet = app(CampaignFleet::class);
        $open = $scope->openEpisodes->keyBy('id');
        $mining = BridgeWork::miningEnabled($scope);
        $rows = $ships->map(function (Ship $ship) use ($scope, $runs, $reviews, $grants, $fleet, $open, $mining) {
            $symbol = $ship->symbol;
            $run = $runs->get($symbol);
            $grant = $grants->get($symbol);
            $episode = $open->get($run?->campaign_episode_id);
            $purpose = $scope->purposes[$symbol] ?? null;
            $snapshot = $ship->snapshot;
            $status = $snapshot['nav']['status'] ?? null;
            $inTransit = $status === 'IN_TRANSIT';
            $probe = $fleet->isProbe($snapshot);
            $draining = $grant && BridgeWork::draining($scope, $grant) !== null;
            $expired = $grant && $grant->arm() && $grant->expires_at?->lte(now());
            $stopped = $grant && ($grant->paused_at || $draining || $expired);
            $arrival = $snapshot['nav']['route']['arrival'] ?? null;
            $cargo = Arr::only($snapshot['cargo'] ?? [], ['units', 'capacity']) + ['units' => null, 'capacity' => null];
            $fuel = Arr::only($snapshot['fuel'] ?? [], ['current', 'capacity']) + ['current' => null, 'capacity' => null];

            [$group, $statusText] = match (true) {
                $run !== null && BridgeWork::unsettled($run) => ['Stopped', BridgeWork::settlementStatus($run, $reviews)],
                $stopped && $run !== null => ['Stopped', 'Unsettled · settling commands'],
                $expired => ['Stopped', 'Approval expired'],
                $stopped => ['Stopped', $draining && ! $grant->paused_at ? 'Stopping · settling cargo' : 'Paused'],
                $probe => ['Probes', in_array($purpose, [null, '', 'Standby'], true) ? 'Standby probe' : $purpose],
                $run !== null && $episode !== null && BridgeWork::advancing($run) => self::activity($run, $mining),
                $run !== null => ['Other work', ucwords(str_replace('_', ' ', $run->strategy))],
                $inTransit => ['Positioning', 'In transit'],
                default => ['Idle', 'Idle'],
            };

            return [
                'symbol' => $symbol, 'name' => $ship->displayName(), 'purpose' => $purpose,
                'group' => $group, 'status_text' => $statusText,
                'right' => $inTransit ? self::countdown($arrival) : self::cargoAmount($cargo),
                'arrival_at' => $arrival, 'waypoint' => $snapshot['nav']['waypointSymbol'] ?? null,
                'nav_status' => $status, 'cargo' => $cargo, 'fuel' => $fuel,
                'grant_id' => $grant?->id ?? $episode?->campaign_grant_id,
                'owner' => $grant ? ($grant->arm()['label'] ?? 'C0') : null,
                'route_key' => $run?->strategy === 'trade' && $episode !== null ? BridgeResults::key(...BridgeResults::runKey($run)) : null,
                'idle_since' => null,
            ];
        });

        $idle = $rows->where('group', 'Idle')->pluck('symbol');
        if ($idle->isEmpty()) {
            return $rows;
        }

        $lastRuns = AutomationRun::where('agent_id', $scope->agent->id)->where('reset_id', $scope->resetId)
            ->whereIn('ship_symbol', $idle)->groupBy('ship_symbol')
            ->selectRaw('MAX(updated_at) AS last_run_at, ship_symbol')->pluck('last_run_at', 'ship_symbol');

        return $rows->map(function ($row) use ($lastRuns) {
            $last = $lastRuns->get($row['symbol']);
            if ($last !== null) {
                $row['idle_since'] = CarbonImmutable::parse($last, 'UTC')->toIso8601String();
            }

            return $row;
        });
    }

    private static function activity(AutomationRun $run, bool $mining): array
    {
        $group = match ($run->strategy) {
            'trade' => 'Trading',
            'sell_aboard' => 'Selling cargo',
            'scout' => 'Positioning',
            'mining' => $mining ? 'Mining' : 'Other work',
            default => 'Other work',
        };

        return [$group, $run->state === RunState::Waiting ? 'Waiting' : $group];
    }

    private static function groups(Collection $rows, string $field, array $order): array
    {
        $groups = $rows->groupBy($field);

        return collect($order)->filter(fn ($name) => $groups->has($name))
            ->map(fn ($name) => ['name' => $name, 'count' => $groups->get($name)->count(), 'ships' => $groups->get($name)->values()->all()])
            ->values()->all();
    }

    private static function probeGroups(Collection $rows): array
    {
        return self::groups($rows->map(function ($row) {
            $purpose = $row['purpose'] ?? '';
            $row['probe_group'] = match (true) {
                str_starts_with($purpose, 'Market Scout'), str_starts_with($purpose, 'Buyer Watch') => 'Watching markets',
                str_starts_with($purpose, 'Stationed'), str_starts_with($purpose, 'Stationing'), str_starts_with($purpose, 'Gate Watch') => 'Stationed',
                str_starts_with($purpose, 'Scout →') => 'Scouting',
                str_starts_with($purpose, 'Recon') => 'On recon',
                default => 'Other',
            };

            return $row;
        }), 'probe_group', self::PROBE_GROUPS);
    }

    private static function locations(BridgeScope $scope, Ship $ship, array $nav): array
    {
        $symbols = array_filter([
            $nav['waypointSymbol'] ?? null,
            $nav['route']['destination']['symbol'] ?? null,
            ($nav['status'] ?? null) === 'IN_TRANSIT' ? ($nav['route']['origin']['symbol'] ?? null) : null,
        ]);
        if ($symbols === []) {
            return [];
        }

        $points = collect(app(SystemMapKnowledge::class)->forShip($scope->agent, $ship)['locations'])
            ->whereIn('symbol', $symbols)->map(fn ($point) => Arr::only($point, ['symbol', 'type', 'traits', 'description']));
        $missing = array_diff($symbols, $points->pluck('symbol')->all());
        if ($missing !== []) {
            $waypoints = GameObservation::where('agent_id', $scope->agent->id)->where('reset_id', $scope->resetId)
                ->where('kind', 'waypoint')->whereIn('symbol', $missing)->get();
            $points = $points->concat($waypoints->map(fn (GameObservation $point) => [
                'symbol' => $point->symbol,
                'type' => $point->data['type'] ?? null,
                'traits' => ExplorationPolicy::traitsKnown($point->data) ? $point->data['traits'] ?? [] : null,
                'description' => $point->data['description'] ?? null,
            ]));
        }

        return $points->values()->all();
    }

    private static function countdown(?string $arrival): ?string
    {
        if ($arrival === null) {
            return null;
        }

        $seconds = max(0, (int) ceil(now()->diffInSeconds(CarbonImmutable::parse($arrival), absolute: false)));

        return $seconds > 0 ? sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60) : 'Checking';
    }

    private static function cargoAmount(array $cargo): ?string
    {
        return isset($cargo['units'], $cargo['capacity']) ? $cargo['units'].'/'.$cargo['capacity'] : null;
    }
}
