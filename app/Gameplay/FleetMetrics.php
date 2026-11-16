<?php

namespace App\Gameplay;

use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\ShipAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Multi-ship harness metrics: per-ship request attribution, scheduler timings and the round-one
 * scorecard. Recording is best effort and never throws into the caller.
 */
class FleetMetrics
{
    private static ?string $ship = null;

    /** @var array<string, array<string, array{reads: int, writes: int}>> kind => ship => counts */
    private static array $pending = [];

    private static ?float $flushedAt = null;

    private static bool $hooked = false;

    public static function reset(): void
    {
        self::$ship = null;
        self::$pending = [];
        self::$flushedAt = null;
    }

    public static function as(?string $ship, callable $fn): mixed
    {
        $previous = self::$ship;
        self::$ship = $ship;
        try {
            return $fn();
        } finally {
            self::$ship = $previous;
        }
    }

    public static function current(): ?string
    {
        return self::$ship;
    }

    public static function count(string $method, string $path = ''): void
    {
        self::tally('requests', strtolower($method) === 'get' ? 'reads' : 'writes');
    }

    /** Counts a non-request occurrence (lock_collision, deferral) against the current ship. */
    public static function note(string $kind): void
    {
        self::tally($kind, 'reads');
    }

    /** Classifies an exception a job swallowed: host-lock contention or a budget/backoff deferral. */
    public static function observe(\Throwable $exception): void
    {
        if ($exception instanceof RequestDeferred) {
            self::note('deferral');
        } elseif (str_starts_with($exception->getMessage(), 'Another operation owns this command boundary')) {
            self::note('lock_collision');
        } elseif ($exception instanceof GameException && $exception->retryAfter > 0) {
            self::note('deferral');
        }
    }

    private static function tally(string $kind, string $field): void
    {
        try {
            $ship = self::$ship ?? 'shared';
            self::$pending[$kind][$ship] ??= ['reads' => 0, 'writes' => 0];
            self::$pending[$kind][$ship][$field]++;
            self::$flushedAt ??= (float) now()->getTimestamp();
            if (! self::$hooked) {
                self::$hooked = true;
                register_shutdown_function(fn () => self::flush());
            }
            if ((float) now()->getTimestamp() - self::$flushedAt >= 30) {
                self::flush();
            }
        } catch (\Throwable) {
        }
    }

    public static function flush(): void
    {
        $pending = self::$pending;
        self::$pending = [];
        self::$flushedAt = (float) now()->getTimestamp();
        if ($pending === []) {
            return;
        }
        try {
            $rows = [];
            $now = now()->toDateTimeString();
            foreach ($pending as $kind => $ships) {
                foreach ($ships as $ship => $counts) {
                    $rows[] = ['kind' => $kind, 'ship' => $ship, 'value' => $counts['reads'] + $counts['writes'],
                        'data' => $kind === 'requests' ? json_encode($counts) : null, 'created_at' => $now];
                }
            }
            DB::table('fleet_metrics')->insert($rows);
        } catch (\Throwable) {
            // Metrics are diagnostic; losing a batch is acceptable.
        }
    }

    /** One scheduler pass: keys director, arms, dispatch (seconds) and lags (seconds), any subset. */
    public function schedulerPass(array $t): void
    {
        try {
            $lags = array_values(array_filter((array) ($t['lags'] ?? []), 'is_numeric'));
            $data = ['director' => $t['director'] ?? null, 'arms' => $t['arms'] ?? null, 'dispatch' => $t['dispatch'] ?? null,
                'dispatched' => count($lags), 'dispatch_lag' => self::median($lags),
                'queue_depth' => DB::table(config('queue.connections.database.table', 'jobs'))->where('queue', 'spacetraders')->count()];
            $total = array_sum(array_map('floatval', array_filter([$data['director'], $data['arms'], $data['dispatch']], 'is_numeric')));
            DB::table('fleet_metrics')->insert(['kind' => 'scheduler', 'ship' => null, 'value' => $total, 'data' => json_encode($data), 'created_at' => now()->toDateTimeString()]);
        } catch (\Throwable) {
        }
        self::flush();
    }

    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /** Scorecard over the reset's load_result events (C0 and arms) plus fleet_metrics. */
    public function scorecard(?CarbonImmutable $since = null, ?CarbonImmutable $until = null, ?string $reset = null): array
    {
        $reset ??= CampaignEpisode::latest('id')->value('reset_id');
        $until ??= CarbonImmutable::now();
        app(CampaignTrade::class)->harvestArms($reset);
        $episodes = CampaignEpisode::where('reset_id', $reset)->orderBy('id')->get();
        $grants = CampaignGrant::whereIn('id', $episodes->pluck('campaign_grant_id'))->get()->keyBy('id');
        $arms = [];
        foreach ($episodes as $episode) {
            $arm = $grants[$episode->campaign_grant_id]->limits['arm'] ?? null;
            $label = $arm['label'] ?? 'C0';
            $arms[$label] ??= ['label' => $label, 'ship' => $arm['ship'] ?? ($episode->state['role_ships']['command'] ?? null), 'episodes' => [],
                'opened_at' => CarbonImmutable::parse($episode->opened_at), 'loads' => []];
            $arms[$label]['episodes'][] = $episode->id;
            $arms[$label]['opened_at'] = min($arms[$label]['opened_at'], CarbonImmutable::parse($episode->opened_at));
        }
        foreach (CampaignEvent::whereIn('campaign_episode_id', $episodes->pluck('id'))->where('kind', 'load_result')->orderBy('id')->get() as $event) {
            $label = collect($arms)->first(fn ($arm) => in_array($event->campaign_episode_id, $arm['episodes'], true))['label'];
            if (! isset($event->data['finished_at'])) {
                continue;
            }
            $arms[$label]['loads'][] = $event->data;
            $arms[$label]['ship'] ??= $event->data['ship'] ?? null;
        }
        $t0 = $since ?? (collect($arms)->except('C0')->min('opened_at') ?? collect($arms)->min('opened_at') ?? $until);
        $blocks = [[$t0, $t0->addHours(2)], [$t0->addHours(2), $t0->addHours(4)]];
        $rows = [];
        foreach ($arms as $label => $arm) {
            $rows[$label] = $this->arm($arm, $t0, $until, $blocks);
        }
        $card = ['reset' => $reset, 'since' => $t0->toIso8601String(), 'until' => $until->toIso8601String(), 'arms' => $rows,
            'blocks' => array_map(fn ($b) => ['from' => $b[0]->toIso8601String(), 'to' => $b[1]->toIso8601String()], $blocks),
            'scheduler' => $this->scheduler($t0, $until)];
        $card['shared_pair'] = $this->sharedPair($rows);
        $card['flags'] = $this->flags($rows);

        return $card;
    }

    private function arm(array $arm, CarbonImmutable $t0, CarbonImmutable $until, array $blocks): array
    {
        $loads = collect($arm['loads'])->map(fn ($load) => $load + ['_at' => CarbonImmutable::parse($load['finished_at'])])
            ->filter(fn ($load) => $load['_at'] <= $until)->sortBy('_at')->values();
        $firstSale = $loads->first()['_at'] ?? null;
        $start = max($t0, $firstSale ?? $until, $arm['opened_at']);
        // The window opens at the first sale, so that load's own time is outside it.
        $steady = $loads->filter(fn ($load, $i) => $load['_at'] >= $start && ($firstSale < $t0 || $i > 0))->values();
        $hours = max(0, $start->diffInSeconds($until, false)) / 3600;
        $nets = $steady->map(fn ($load) => (float) ($load['net'] ?? 0))->all();
        $net = array_sum($nets);
        $perHour = $hours > 0 ? $net / $hours : null;
        $best = $steady->map(fn ($load) => self::number($load['best_unclaimed'] ?? null))->filter(fn ($v) => $v !== null);
        $price = $arm['ship'] ? ShipAction::where('command', 'buy_ship')->where('state', 'confirmed')->get()
            ->first(fn ($action) => ($action->outcome['delivered_ship'] ?? null) === $arm['ship'])?->outcome['transaction_total'] : null;
        $requests = $arm['ship'] ? DB::table('fleet_metrics')->where('kind', 'requests')->where('ship', $arm['ship'])
            ->whereBetween('created_at', [$start->toDateTimeString(), $until->toDateTimeString()])->sum('value') : 0;
        $notes = fn (string $kind) => $arm['ship'] ? (int) DB::table('fleet_metrics')->where('kind', $kind)->where('ship', $arm['ship'])
            ->whereBetween('created_at', [$t0->toDateTimeString(), $until->toDateTimeString()])->sum('value') : 0;
        $holds = $arm['ship'] ? ShipAction::where('ship_symbol', $arm['ship'])->whereBetween('created_at', [$t0, $until])
            ->where(fn ($q) => $q->where('review_required', true)->orWhere('state', 'uncertain'))->count() : 0;
        $blockRates = array_map(function ($block) use ($steady, $start, $until, $t0) {
            $from = max($block[0], $start);
            $to = min($block[1], $until);
            $in = $steady->filter(fn ($load) => $load['_at'] >= $from && ($load['_at'] > $block[0] || $block[0] == $t0) && $load['_at'] <= $to);
            $span = max(0, $from->diffInSeconds($to, false)) / 3600;

            return ['loads' => $in->count(), 'net' => $in->sum(fn ($l) => $l['net'] ?? 0), 'net_per_hour' => $span > 0 ? $in->sum(fn ($l) => $l['net'] ?? 0) / $span : null,
                'pair_ratio' => self::ratio($in->sum(fn ($l) => $l['net'] ?? 0), $in->sum(fn ($l) => self::number($l['best_unclaimed'] ?? null) ?? 0))];
        }, $blocks);
        $mix = $steady->countBy(fn ($load) => $load['limiting_factor'] ?? 'unknown')->all();
        $begin = $arm['opened_at'] > $t0 ? $arm['opened_at'] : $t0;

        return ['label' => $arm['label'], 'ship' => $arm['ship'], 'episodes' => $arm['episodes'], 'loads' => $steady->count(), 'loads_total' => $loads->count(),
            'window_start' => $start->toIso8601String(), 'hours' => round($hours, 3), 'net' => $net,
            'net_per_hour' => $perHour, 'net_per_hour_80' => $hours > 0 ? self::bootstrap($nets, $hours) : null,
            'net_per_load' => $nets ? $net / count($nets) : null, 'loads_per_hour' => $hours > 0 ? count($nets) / $hours : null,
            'pair_ratio' => self::ratio($net, $best->sum()), 'ship_price' => $price,
            'payback_hours' => $price && $perHour > 0 ? $price / $perHour : null, 'limiting_factors' => $mix,
            'first_sale_minutes' => $firstSale ? round($arm['opened_at']->diffInSeconds($firstSale, false) / 60, 1) : null,
            'requests' => (int) $requests, 'requests_per_ship_hour' => $hours > 0 ? $requests / $hours : null,
            'requests_per_load' => $nets ? $requests / count($nets) : null,
            'lock_collisions' => $notes('lock_collision'), 'deferrals' => $notes('deferral'), 'holds' => $holds,
            'stall_minutes' => $this->stall($arm, $loads, $begin, $until), 'blocks' => $blockRates];
    }

    /** Minutes with no load in progress and no active run: approximate idle-and-empty time. */
    private function stall(array $arm, $loads, CarbonImmutable $from, CarbonImmutable $until): ?float
    {
        if ($from >= $until) {
            return null;
        }
        $spans = [];
        $runs = AutomationRun::whereIn('campaign_episode_id', $arm['episodes'])->whereNotNull('started_at')->get(['id', 'started_at', 'updated_at', 'active_agent_id']);
        foreach ($loads as $load) {
            $begin = isset($load['started_at']) ? CarbonImmutable::parse($load['started_at']) : ($runs->firstWhere('id', $load['run_id'] ?? null)?->started_at);
            if ($begin) {
                $spans[] = [CarbonImmutable::parse($begin), $load['_at']];
            }
        }
        foreach ($runs as $run) {
            $spans[] = [CarbonImmutable::parse($run->started_at), $run->active_agent_id ? $until : CarbonImmutable::parse($run->updated_at)];
        }
        usort($spans, fn ($a, $b) => $a[0] <=> $b[0]);
        $idle = 0;
        $cursor = $from;
        foreach ($spans as [$begin, $end]) {
            $begin = max($begin, $from);
            $end = min($end, $until);
            if ($end <= $cursor) {
                continue;
            }
            if ($begin > $cursor) {
                $idle += $cursor->diffInSeconds($begin);
            }
            $cursor = $end;
        }
        if ($cursor < $until) {
            $idle += $cursor->diffInSeconds($until);
        }

        return round($idle / 60, 1);
    }

    private function scheduler(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $rows = DB::table('fleet_metrics')->where('kind', 'scheduler')->whereBetween('created_at', [$from->toDateTimeString(), $until->toDateTimeString()])->pluck('data')
            ->map(fn ($data) => json_decode($data, true) ?: []);
        $lags = $rows->pluck('dispatch_lag')->filter(fn ($v) => is_numeric($v))->values()->all();
        $shared = (int) DB::table('fleet_metrics')->where('kind', 'requests')->where('ship', 'shared')
            ->whereBetween('created_at', [$from->toDateTimeString(), $until->toDateTimeString()])->sum('value');

        return ['passes' => $rows->count(), 'median_dispatch_lag' => self::median($lags),
            'median_director' => self::median($rows->pluck('director')->filter(fn ($v) => is_numeric($v))->values()->all()),
            'median_arms' => self::median($rows->pluck('arms')->filter(fn ($v) => is_numeric($v))->values()->all()),
            'max_queue_depth' => $rows->max('queue_depth'), 'shared_requests' => $shared];
    }

    private function sharedPair(array $rows): ?array
    {
        if (! isset($rows['T1a'], $rows['T3'], $rows['T1b'], $rows['T1c'])) {
            return null;
        }
        $sum = function (array $labels, ?int $block = null) use ($rows) {
            $values = array_map(fn ($label) => $block === null ? $rows[$label]['net_per_hour'] : $rows[$label]['blocks'][$block]['net_per_hour'], $labels);

            return in_array(null, $values, true) ? null : array_sum($values);
        };
        $unit = $sum(['T1a', 'T3']);
        $pair = $sum(['T1b', 'T1c']);

        return ['unit_net_per_hour' => $unit, 'independent_net_per_hour' => $pair, 'ratio' => self::ratio($unit, $pair),
            'blocks' => array_map(fn ($i) => ['unit' => $sum(['T1a', 'T3'], $i), 'independent' => $sum(['T1b', 'T1c'], $i),
                'ratio' => self::ratio($sum(['T1a', 'T3'], $i), $sum(['T1b', 'T1c'], $i))], [0, 1])];
    }

    /**
     * Pre-registered flag rule: a difference vs the T1 reference (T1b/T1c mean) is flagged when it is
     * at least 1.5x, outside the T1b-T1c range, and in the same direction in both 2-hour blocks.
     * T1a and T3 are excluded; with T1b alone the range comes from its two blocks.
     */
    private function flags(array $rows): array
    {
        $reference = array_values(array_filter([$rows['T1b'] ?? null, $rows['T1c'] ?? null]));
        if ($reference === []) {
            return [];
        }
        $flags = [];
        foreach (['net_per_hour', 'pair_ratio'] as $metric) {
            $values = array_map(fn ($row) => $row[$metric], $reference);
            if (in_array(null, $values, true)) {
                continue;
            }
            $range = count($reference) > 1 ? $values : array_map(fn ($b) => $b[$metric], $reference[0]['blocks']);
            if (in_array(null, $range, true)) {
                continue;
            }
            $ref = array_sum($values) / count($values);
            $blockRefs = array_map(function ($index) use ($reference, $metric) {
                $values = array_column(array_column($reference, 'blocks'), $index);
                $values = array_column($values, $metric);

                return in_array(null, $values, true) ? null : array_sum($values) / count($values);
            }, [0, 1]);
            foreach ($rows as $label => $row) {
                if (in_array($label, ['T1a', 'T3', 'T1b', 'T1c'], true) || $row[$metric] === null || $ref == 0) {
                    continue;
                }
                $ratio = $row[$metric] / $ref;
                $up = $ratio >= 1;
                $big = $up ? $ratio >= 1.5 : $ratio <= 1 / 1.5;
                $outside = $row[$metric] < min($range) || $row[$metric] > max($range);
                $consistent = collect([0, 1])->every(fn ($i) => $blockRefs[$i] !== null && $row['blocks'][$i][$metric] !== null && ($up ? $row['blocks'][$i][$metric] > $blockRefs[$i] : $row['blocks'][$i][$metric] < $blockRefs[$i]));
                $flags[] = ['label' => $label, 'metric' => $metric, 'ratio' => round($ratio, 3), 'at_least_1_5x' => $big, 'outside_t1_range' => $outside,
                    'same_direction_both_blocks' => $consistent, 'enough_loads' => $row['loads'] >= 12, 'flagged' => $row['loads'] >= 12 && $big && $outside && $consistent];
            }
        }

        return $flags;
    }

    /** 80% bootstrap interval of net per hour over loads (seeded, 2000 resamples). */
    public static function bootstrap(array $nets, float $hours, int $resamples = 2000, int $seed = 20261117): ?array
    {
        $n = count($nets);
        if ($n === 0 || $hours <= 0) {
            return null;
        }
        $random = new Randomizer(new Mt19937($seed));
        $totals = [];
        for ($r = 0; $r < $resamples; $r++) {
            $sum = 0;
            for ($i = 0; $i < $n; $i++) {
                $sum += $nets[$random->getInt(0, $n - 1)];
            }
            $totals[] = $sum / $hours;
        }
        sort($totals);

        return [$totals[(int) floor(0.1 * ($resamples - 1))], $totals[(int) ceil(0.9 * ($resamples - 1))]];
    }

    private static function ratio(float|int|null $top, float|int|null $bottom): ?float
    {
        return $top === null || ! $bottom ? null : $top / $bottom;
    }

    /** Builder-2 fields may be a number or a candidate summary array. */
    private static function number(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (is_array($value)) {
            foreach (['predicted_margin', 'margin', 'net', 'value'] as $key) {
                if (isset($value[$key]) && is_numeric($value[$key])) {
                    return (float) $value[$key];
                }
            }
        }

        return null;
    }
}
