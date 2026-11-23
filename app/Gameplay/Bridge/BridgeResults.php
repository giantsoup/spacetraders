<?php

namespace App\Gameplay\Bridge;

use App\Models\AutomationRun;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class BridgeResults
{
    public const GOOD = "json_extract(event.data, '$.good')";

    public const BUYER = "json_extract(event.data, '$.buyer')";

    public const SOURCE = "COALESCE(json_extract(event.data, '$.source'), json_extract(result_run.plan, '$.site'))";

    public const NET = "json_extract(event.data, '$.net')";

    public const FINISHED = "json_extract(event.data, '$.finished_at')";

    public static function events(): Builder
    {
        return DB::table('campaign_events as event');
    }

    public static function withLegacySource(Builder $events): Builder
    {
        return $events->leftJoin('automation_runs as result_run', fn ($join) => $join
            ->on('result_run.id', '=', DB::raw("json_extract(event.data, '$.run_id')"))
            ->where('event.kind', 'load_result')
            ->whereNull(DB::raw("json_extract(event.data, '$.source')")));
    }

    public static function forWipe(BridgeScope $scope): Builder
    {
        return self::events()->whereIn('event.campaign_episode_id', $scope->episodes->keys());
    }

    /** @return array{?string, ?string, ?string} */
    public static function runKey(AutomationRun $run): array
    {
        return [$run->plan['symbol'] ?? null, $run->plan['site'] ?? null, $run->plan['market'] ?? null];
    }

    public static function key(?string $good, ?string $source, ?string $buyer): ?string
    {
        $key = implode('|', [$good, $source, $buyer]);

        return self::parseKey($key) !== null ? $key : null;
    }

    /** @return array{string, string, string}|null */
    public static function parseKey(string $key): ?array
    {
        if (preg_match('/\A([A-Z0-9_]+)\|([A-Z0-9-]+)\|([A-Z0-9-]+)\z/', $key, $parts) !== 1) {
            return null;
        }

        return [$parts[1], $parts[2], $parts[3]];
    }

    /** @param list<string> $key */
    public static function matchingRoute(Builder $events, array $key): Builder
    {
        return $events->where('event.kind', 'load_result')
            ->whereRaw(self::GOOD.' = ?', [$key[0]])
            ->whereRaw(self::SOURCE.' = ?', [$key[1]])
            ->whereRaw(self::BUYER.' = ?', [$key[2]]);
    }

    public static function recent(Builder $events, int $limit): array
    {
        return $events->latest(DB::raw(self::FINISHED))->latest('event.id')->limit($limit)
            ->get(['event.id', 'event.kind', 'event.data'])
            ->map(function ($event) {
                $data = json_decode($event->data, true, flags: JSON_THROW_ON_ERROR);

                return [
                    'id' => $event->id, 'kind' => $event->kind, 'at' => $data['finished_at'] ?? null,
                    'ship' => $data['ship'] ?? null, 'good' => $data['good'] ?? null,
                    'units' => $data['units'] ?? null, 'net' => $data['net'] ?? null,
                ];
            })->all();
    }
}
