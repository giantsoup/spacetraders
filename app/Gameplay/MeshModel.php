<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\GameObservation;
use App\Models\Ship;
use InvalidArgumentException;

final readonly class MeshModel
{
    private const MINING_OUTPUTS = [
        'IRON_ORE', 'COPPER_ORE', 'ALUMINUM_ORE', 'SILVER_ORE', 'GOLD_ORE', 'PLATINUM_ORE',
        'URANITE_ORE', 'MERITIUM_ORE', 'ICE_WATER', 'AMMONIA_ICE', 'QUARTZ_SAND', 'SILICON_CRYSTALS',
        'PRECIOUS_STONES', 'DIAMONDS', 'HYDROCARBON', 'LIQUID_HYDROGEN', 'LIQUID_NITROGEN',
    ];

    private function __construct(
        public ?string $system,
        public array $points,
        public array $targets,
        public array $buckets,
        public array $stations,
        public array $stationMarkets,
    ) {}

    public static function evidence(Agent $agent): array
    {
        $observations = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->whereIn('kind', ['waypoint', 'market_listing'])->get()->groupBy('kind');
        $stations = Ship::where('agent_id', $agent->id)->whereNotNull('stationed_waypoint')->get()
            ->filter(fn (Ship $ship) => app(CampaignFleet::class)->isProbe($ship->snapshot)
                && in_array($ship->snapshot['nav']['status'] ?? null, ['DOCKED', 'IN_ORBIT'], true)
                && $ship->stationed_waypoint === ($ship->snapshot['nav']['waypointSymbol'] ?? null))
            ->pluck('stationed_waypoint')->unique()->all();

        return [
            $observations->get('waypoint', collect())->pluck('data', 'symbol')->all(),
            $observations->get('market_listing', collect())->pluck('data', 'symbol')->all(),
            $stations,
        ];
    }

    public static function build(?string $system, array $points, array $listings, array $stations, array $policy): self
    {
        $points = array_filter($points, fn (array $point, string $symbol) => $system !== null
            && ($point['systemSymbol'] ?? null) === $system && FleetClaims::systemOf($symbol) === $system
            && ($point['symbol'] ?? null) === $symbol && is_int($point['x'] ?? null) && is_int($point['y'] ?? null), ARRAY_FILTER_USE_BOTH);
        ksort($points, SORT_STRING);
        $goods = [];
        $maxGoods = 1;
        foreach (array_intersect_key($listings, $points) as $symbol => $listing) {
            $distinct = array_unique(array_column([...($listing['imports'] ?? []), ...($listing['exports'] ?? []), ...($listing['exchange'] ?? [])], 'symbol'));
            $goods[$symbol] = count(array_diff($distinct, ['FUEL', 'ANTIMATTER']));
            $maxGoods = max($maxGoods, $goods[$symbol]);
        }

        $targets = $buckets = $stationMarkets = [];
        foreach ($points as $symbol => $point) {
            $traits = array_column($point['traits'] ?? [], 'symbol');
            $knownTraits = isset($point['traits']) && ($point['traits_observed'] ?? true);
            $provisional = ! isset($goods[$symbol]);
            if (in_array('UNCHARTED', $traits, true)
                || ($provisional && (! $knownTraits || ! in_array('MARKETPLACE', $traits, true)))) {
                continue;
            }
            $tier = ($goods[$symbol] ?? 1) > 0 ? 1 : 2;
            if ($tier === 2 && ! $policy['tier2']) {
                continue;
            }
            $buyers = array_column([...($listings[$symbol]['imports'] ?? []), ...($listings[$symbol]['exchange'] ?? [])], 'symbol');
            $weight = $tier === 2 ? 250 : 1000 + intdiv(1000 * ($goods[$symbol] ?? 0), $maxGoods);
            $weight += $knownTraits && in_array('SHIPYARD', $traits, true) ? 500 : 0;
            $weight += array_intersect($buyers, self::MINING_OUTPUTS) ? 500 : 0;
            $cluster = $point['x'].','.$point['y'];
            $bucket = $cluster.':'.$tier;
            $target = compact('symbol', 'tier', 'provisional', 'weight', 'cluster', 'bucket');
            if (in_array($symbol, $stations, true)) {
                $stationMarkets[$symbol] = $target;

                continue;
            }
            $targets[$symbol] = $target;
            $buckets[$bucket]['tier'] = $tier;
            $buckets[$bucket]['cluster'] = $cluster;
            $buckets[$bucket]['members'][] = $symbol;
        }

        ksort($buckets, SORT_STRING);
        foreach ($buckets as &$bucket) {
            usort($bucket['members'], fn ($a, $b) => [-$targets[$a]['weight'], $a] <=> [-$targets[$b]['weight'], $b]);
        }
        unset($bucket);

        return new self($system, $points, $targets, $buckets, $stations, $stationMarkets);
    }

    public function marginals(array $members, array $policy): array
    {
        if ($members === []) {
            return [];
        }
        $weights = array_map(fn (string $symbol) => $this->targets[$symbol]['weight'], $members);
        $value = $policy['value_seconds'];
        $quality = $policy['patrol_quality_pct'];
        $siblings = array_slice($weights, 1);
        $first = 100 * $weights[0] + self::product($quality, array_sum($siblings));

        return [intdiv(self::product($value, $first), 100000), ...array_map(
            fn (int $weight) => intdiv(self::product($value, (100 - $quality) * $weight), 100000),
            $siblings,
        )];
    }

    public static function product(int $a, int $b): int
    {
        if ($a < 0 || $b < 0 || ($a !== 0 && $b > intdiv(Hungarian::BIG - 1, $a))) {
            throw new InvalidArgumentException('Mesh arithmetic exceeds the safe integer bound.');
        }

        return $a * $b;
    }
}
