<?php

namespace App\Gameplay\Bridge;

use App\Enums\RunState;
use App\Gameplay\CampaignFleet;
use App\Models\AutomationRun;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use WeakMap;

final class BridgeRoutes
{
    /** @var WeakMap<BridgeScope, Collection>|null */
    private static ?WeakMap $tradeRoutesByScope = null;

    public static function read(BridgeScope $scope): array
    {
        $routes = self::tradeRoutes($scope);
        $running = $routes->where('status', 'Running');
        $stopped = $routes->where('status', 'Paused');
        $ended = $routes->where('status', 'Ended');

        return [
            'running' => $running->values()->all(),
            'stopped' => $stopped->values()->all(),
            'settling' => $routes->whereNotIn('status', ['Running', 'Paused', 'Ended'])->values()->all(),
            'mining' => self::miningRoutes($scope)->values()->all(),
            'ended' => ['count' => $ended->count(), 'net' => $ended->sum('net')],
            'rate' => $routes->sum('rate'),
        ];
    }

    public static function ended(BridgeScope $scope): array
    {
        return self::tradeRoutes($scope)->where('status', 'Ended')->sortByDesc('net')->values()->all();
    }

    public static function detail(BridgeScope $scope, string $good, string $source, string $buyer): ?array
    {
        $key = BridgeResults::key($good, $source, $buyer);
        if ($key === null || $scope->agent === null || $scope->resetId === null) {
            return null;
        }

        $route = self::tradeRoutes($scope)->get($key) ?? self::miningRoutes($scope)->get($key);
        if ($route === null) {
            return null;
        }

        $quotes = GameObservation::where('agent_id', $scope->agent->id)->where('reset_id', $scope->resetId)
            ->where('kind', 'market')->whereIn('symbol', [$source, $buyer])->get()->keyBy('symbol');

        return $route + [
            'prices' => [
                'source' => self::price($quotes->get($source), $good, 'purchasePrice'),
                'buyer' => self::price($quotes->get($buyer), $good, 'sellPrice'),
            ],
            'first_seen' => self::firstSeen($scope, $route),
            'recent' => BridgeResults::recent(BridgeResults::matchingRoute(
                BridgeResults::withLegacySource(BridgeResults::forWipe($scope)), [$good, $source, $buyer],
            ), 5),
        ];
    }

    private static function tradeRoutes(BridgeScope $scope): Collection
    {
        // The route detail response also includes the list, using the same ledger snapshot.
        self::$tradeRoutesByScope ??= new WeakMap;

        return self::$tradeRoutesByScope[$scope] ??= self::collectTradeRoutes($scope);
    }

    private static function collectTradeRoutes(BridgeScope $scope): Collection
    {
        $current = self::currentTradeRoutes($scope);
        $totals = self::totals($scope);

        return $current->map(fn ($row, $bucket) => Arr::only($row, ['status', 'ships']) + ($totals->get($bucket) ?? $row))
            ->union($totals->map(fn ($total) => ['status' => 'Ended', 'ships' => []] + $total));
    }

    private static function currentTradeRoutes(BridgeScope $scope): Collection
    {
        $grants = BridgeWork::grants($scope);
        $open = $scope->openEpisodes->keyBy('id');
        $stopped = $open->filter(function ($episode) use ($scope, $grants) {
            $grant = $grants->get($episode->campaign_grant_id);

            return $grant && ($grant->paused_at || BridgeWork::draining($scope, $grant) !== null);
        });

        $latest = collect();
        if ($stopped->isNotEmpty()) {
            $runIds = AutomationRun::whereIn('campaign_episode_id', $stopped->keys())->where('strategy', 'trade')
                ->selectRaw('MAX(id)')->groupBy('campaign_episode_id');

            $latest = AutomationRun::whereIn('id', $runIds)->orderBy('id')->get();
        }

        $active = $scope->activeRuns->where('strategy', 'trade')->where('reset_id', $scope->resetId)->filter(
            fn (AutomationRun $run) => $open->has($run->campaign_episode_id)
                || ($scope->episodes->has($run->campaign_episode_id) && BridgeWork::unsettled($run))
        );
        $reviews = BridgeWork::reviews($scope, $active);
        $historicalGrantIds = collect($active)
            ->map(fn (AutomationRun $run) => $scope->episodes->get($run->campaign_episode_id)?->campaign_grant_id)
            ->filter()->unique()->diff($grants->keys());
        if ($historicalGrantIds->isNotEmpty()) {
            $grants = $grants->union(CampaignGrant::whereIn('id', $historicalGrantIds)->get()->keyBy('id'));
        }
        $latestIds = $latest->keyBy('id');

        $rows = $active->concat($latest)->unique('id')->map(function (AutomationRun $run) use ($scope, $open, $stopped, $grants, $latestIds, $reviews) {
            $episode = $open->get($run->campaign_episode_id) ?? $scope->episodes->get($run->campaign_episode_id);
            $grant = $grants->get($episode->campaign_grant_id);
            if (BridgeWork::unsettled($run)) {
                $status = BridgeWork::settlementStatus($run, $reviews);
            } elseif ($run->active_agent_id !== null && $stopped->has($episode->id)) {
                $status = 'Unsettled · settling commands';
            } elseif ($run->active_agent_id !== null && BridgeWork::advancing($run)) {
                $status = $run->state === RunState::Waiting ? 'Waiting' : 'Running';
            } elseif ($latestIds->has($run->id)) {
                $status = 'Paused';
            } else {
                return null;
            }

            return self::runRow($scope, $run, $grant, $episode->campaign_grant_id, $status);
        });

        return self::groupRuns($rows);
    }

    private static function miningRoutes(BridgeScope $scope): Collection
    {
        if (! BridgeWork::miningEnabled($scope)) {
            return collect();
        }

        $open = $scope->openEpisodes->keyBy('id');
        $grants = BridgeWork::grants($scope);

        return self::groupRuns($scope->activeRuns->where('strategy', 'mining')->where('reset_id', $scope->resetId)
            ->filter(fn (AutomationRun $run) => $open->has($run->campaign_episode_id) && BridgeWork::advancing($run))
            ->map(function (AutomationRun $run) use ($scope, $open, $grants) {
                $grantId = $open->get($run->campaign_episode_id)->campaign_grant_id;

                return self::runRow($scope, $run, $grants->get($grantId), $grantId, 'Running', allowPartial: true);
            }));
    }

    private static function runRow(BridgeScope $scope, AutomationRun $run, ?CampaignGrant $grant, int $grantId, string $status, bool $allowPartial = false): ?array
    {
        [$good, $source, $buyer] = BridgeResults::runKey($run);
        $key = BridgeResults::key($good, $source, $buyer);
        $ship = $scope->ships->get($run->ship_symbol);
        if ((! $allowPartial && $key === null) || ($ship && app(CampaignFleet::class)->isProbe($ship->snapshot))) {
            return null;
        }

        return [
            'key' => $key, 'good' => $good, 'source' => $source, 'buyer' => $buyer,
            'ship' => [
                'symbol' => $run->ship_symbol, 'name' => $ship?->displayName() ?? $run->ship_symbol,
                'owner' => $grant?->arm()['label'] ?? 'C0', 'grant_id' => $grantId, 'status' => $status,
            ],
        ];
    }

    private static function groupRuns(Collection $rows): Collection
    {
        return $rows->filter()->groupBy(fn ($row) => self::bucket($row['good'], $row['source'], $row['buyer']))
            ->map(function (Collection $rows) {
                $ships = $rows->pluck('ship')->unique('symbol')->values();
                $status = $ships->whereIn('status', ['Running', 'Waiting'])->isNotEmpty()
                    ? 'Running'
                    : ($ships->pluck('status')->first(fn ($status) => str_starts_with($status, 'Unsettled'))
                        ?? $ships->pluck('status')->first(fn ($status) => str_starts_with($status, 'Stopping'))
                        ?? 'Paused');

                return Arr::except($rows->first(), ['ship']) + [
                    'status' => $status, 'ships' => $ships->all(),
                    'net' => 0, 'loads' => 0, 'rate' => null, 'last_at' => null, 'last_net' => null,
                ];
            });
    }

    private static function totals(BridgeScope $scope): Collection
    {
        if ($scope->episodes->isEmpty()) {
            return collect();
        }

        $good = BridgeResults::GOOD;
        $source = BridgeResults::SOURCE;
        $buyer = BridgeResults::BUYER;
        $net = BridgeResults::NET;
        $finished = BridgeResults::FINISHED;
        $loads = BridgeResults::withLegacySource(BridgeResults::forWipe($scope))
            ->where('event.kind', 'load_result')
            ->selectRaw("$good AS good, $source AS source, $buyer AS buyer, $net AS net, $finished AS finished_at")
            ->selectRaw("ROW_NUMBER() OVER (PARTITION BY $good, $source, $buyer ORDER BY $finished DESC, event.id DESC) AS recent_rank");

        return DB::query()->fromSub($loads, 'loads')
            ->select('good', 'source', 'buyer')
            ->selectRaw('COUNT(*) AS load_count, SUM(net) AS net, MAX(finished_at) AS last_at, MAX(CASE WHEN recent_rank = 1 THEN net END) AS last_net')
            ->selectRaw('SUM(CASE WHEN finished_at >= ? THEN net END) AS net_2h', [now()->subHours(2)->utc()->toIso8601String()])
            ->groupBy('good', 'source', 'buyer')->get()
            ->mapWithKeys(fn ($row) => [self::bucket($row->good, $row->source, $row->buyer) => [
                'key' => BridgeResults::key($row->good, $row->source, $row->buyer),
                'good' => $row->good, 'source' => $row->source, 'buyer' => $row->buyer,
                'net' => $row->net ?? 0, 'loads' => $row->load_count,
                'rate' => $row->net_2h !== null ? $row->net_2h / 2 : null,
                'last_at' => $row->last_at, 'last_net' => $row->last_net,
            ]]);
    }

    private static function bucket(?string $good, ?string $source, ?string $buyer): string
    {
        return implode('|', [$good, $source, $buyer]);
    }

    private static function price(?GameObservation $market, string $good, string $field): ?array
    {
        $price = collect($market?->data['tradeGoods'] ?? [])->firstWhere('symbol', $good)[$field] ?? null;

        return $price !== null ? ['price' => $price, 'observed_at' => $market->observed_at?->toIso8601String()] : null;
    }

    private static function firstSeen(BridgeScope $scope, array $route): int|float|null
    {
        $primaryId = $scope->primary?->id;
        $ids = collect($route['ships'])->pluck('grant_id')->unique()->sort();
        if ($ids->contains($primaryId)) {
            $ids = collect([$primaryId])->concat($ids)->unique();
        } else {
            $ids->push($primaryId);
        }
        $key = $route['good'].'@'.$route['buyer'];

        return $ids->filter()->map(fn ($id) => $scope->openEpisodes->get($id)?->state['first_seen'][$key] ?? null)
            ->first(fn ($price) => $price !== null);
    }
}
