<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\Ship;
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

    private static ?string $agent = null;

    private static bool $directing = false;

    /** @var array<string, array<string, array{reads: int, writes: int, categories?: array<string, int>, agent_symbol?: ?string}>> kind => key => counts */
    private static array $pending = [];

    /** @var list<array{kind: string, ship: string, agent_symbol: ?string, scope: string, value: float, data: string, created_at: string}> advance and director-pass outcome rows */
    private static array $events = [];

    /** Requests counted by this process so far; callers diff it to learn what one unit of work used. */
    private static int $total = 0;

    /**
     * Endpoint categories, derived from method and path only. GET: status (/), agent (/my/agent), ship (/my/ships/{s}),
     * ships_page (/my/ships), market, shipyard, waypoint (a waypoint or a system's waypoint list), system (/systems and
     * /systems/{s}), other_read for any other GET. Writes are write:<command> from the path's last segment (the names
     * below), write:flight_mode (PATCH nav), write:buy_ship (POST /my/ships), else write:other.
     */
    private const WRITE_COMMANDS = ['navigate', 'dock', 'orbit', 'purchase', 'sell', 'refuel', 'extract', 'survey', 'siphon', 'jettison', 'transfer', 'jump', 'warp',
        'refine', 'repair', 'scrap', 'accept', 'deliver', 'fulfill', 'supply', 'install', 'remove', 'contract', 'register'];

    private static ?float $flushedAt = null;

    private static bool $hooked = false;

    public static function reset(): void
    {
        self::$ship = null;
        self::$agent = null;
        self::$directing = false;
        self::$pending = [];
        self::$events = [];
        self::$total = 0;
        self::$flushedAt = null;
    }

    public static function category(string $method, string $path): string
    {
        $segments = array_values(array_filter(explode('/', strtok($path, '?') ?: ''), fn ($part) => $part !== ''));
        $count = count($segments);
        $last = $segments[$count - 1] ?? '';
        if (strtolower($method) === 'get') {
            $systems = ($segments[0] ?? null) === 'systems';

            return match (true) {
                $count === 0 => 'status',
                $segments === ['my', 'agent'] => 'agent',
                $segments === ['my', 'ships'] => 'ships_page',
                $count === 3 && $segments[0] === 'my' && $segments[1] === 'ships' => 'ship',
                $systems && $last === 'market' => 'market',
                $systems && $last === 'shipyard' => 'shipyard',
                $systems && ($count === 4 || ($count === 3 && $last === 'waypoints')) => 'waypoint',
                $systems && $count <= 2 => 'system',
                default => 'other_read',
            };
        }
        if (strtolower($method) === 'patch' && $last === 'nav') {
            return 'write:flight_mode';
        }
        if ($segments === ['my', 'ships']) {
            return 'write:buy_ship';
        }

        return 'write:'.(in_array($last, self::WRITE_COMMANDS, true) ? $last : 'other');
    }

    public static function requestTotal(): int
    {
        return self::$total;
    }

    /**
     * One AutomationRunner::advance. Outcomes: wrote (enqueued a write), waited (planner wait, trip or depart bookkeeping,
     * or an action still settling), held (freshness, price, follower or exposure hold), deferred (RequestDeferred or another
     * retryAfter that re-queues the run), blocked (run blocked: uncertain action, planner or fatal error), idle (nothing due:
     * stopped, inactive, blocked earlier or next_action_at in the future).
     */
    public static function advance(?string $ship, string $outcome, int $requests): void
    {
        self::event('advance', $ship, $outcome, $requests);
    }

    /** One director pass. Outcomes: started (a run was started), awaiting_run (a run is active), waiting (anything else: wait, pause, idle). */
    public static function directorPass(?string $ship, string $outcome, int $requests, array $extra = []): void
    {
        self::event('director_pass', $ship, $outcome, $requests, $extra);
    }

    private static function event(string $kind, ?string $ship, string $outcome, int $requests, array $extra = []): void
    {
        try {
            $agentSymbol = self::resolveAgentSymbol($ship, self::$agent);
            self::$events[] = ['kind' => $kind, 'ship' => $ship ?? 'shared', 'agent_symbol' => $agentSymbol, 'scope' => 'agent',
                'value' => $requests, 'data' => json_encode(['outcome' => $outcome, 'requests' => $requests] + $extra), 'created_at' => now()->toDateTimeString()];
            self::touch();
        } catch (\Throwable) {
        }
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

    public static function forAgent(?string $agentSymbol, callable $fn): mixed
    {
        $previous = self::$agent;
        self::$agent = $agentSymbol !== null && $agentSymbol !== '' ? strtoupper($agentSymbol) : null;
        try {
            return $fn();
        } finally {
            self::$agent = $previous;
        }
    }

    public static function duringDirector(callable $fn): mixed
    {
        $previous = self::$directing;
        self::$directing = true;
        try {
            return $fn();
        } finally {
            self::$directing = $previous;
        }
    }

    public static function current(): ?string
    {
        return self::$ship;
    }

    public static function currentAgent(): ?string
    {
        return self::$agent;
    }

    public static function count(string $method, string $path = '', ?string $category = null, ?string $agentSymbol = null, bool $host = false): void
    {
        self::$total++;
        try {
            $category ??= self::category($method, $path);
        } catch (\Throwable) {
            $category = null;
        }
        self::tally('requests', strtolower($method) === 'get' ? 'reads' : 'writes', $category, $agentSymbol, $host);
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

    private static function resolveAgentSymbol(?string $ship, ?string $explicit): ?string
    {
        if (is_string($explicit) && $explicit !== '') {
            return strtoupper($explicit);
        }
        if (is_string($ship) && $ship !== '' && ! in_array($ship, ['shared', 'director'], true) && preg_match('/\A([A-Z0-9_]+)-[0-9A-F]+\z/i', $ship, $match)) {
            return strtoupper($match[1]);
        }

        return null;
    }

    private static function tally(string $kind, string $field, ?string $category = null, ?string $agentSymbol = null, bool $host = false): void
    {
        try {
            $ship = self::$ship ?? 'shared';
            $resolvedAgent = $host ? null : self::resolveAgentSymbol($ship, $agentSymbol ?? self::$agent);
            $scope = $host ? 'host' : 'agent';
            $workload = self::$directing ? 'director' : null;
            $bucketKey = implode('|', [$ship, $resolvedAgent, $scope, $workload]);
            self::$pending[$kind][$bucketKey] ??= ['ship' => $ship, 'agent_symbol' => $resolvedAgent, 'scope' => $scope, 'workload' => $workload, 'reads' => 0, 'writes' => 0];
            self::$pending[$kind][$bucketKey][$field]++;
            if ($category !== null) {
                self::$pending[$kind][$bucketKey]['categories'][$category] = (self::$pending[$kind][$bucketKey]['categories'][$category] ?? 0) + 1;
            }
            self::touch();
        } catch (\Throwable) {
        }
    }

    private static function touch(): void
    {
        self::$flushedAt ??= (float) now()->getTimestamp();
        if (! self::$hooked) {
            self::$hooked = true;
            register_shutdown_function(fn () => self::flush());
        }
        if ((float) now()->getTimestamp() - self::$flushedAt >= 30) {
            self::flush();
        }
    }

    public static function flush(): void
    {
        $pending = self::$pending;
        $events = self::$events;
        self::$pending = [];
        self::$events = [];
        self::$flushedAt = (float) now()->getTimestamp();
        if ($pending === [] && $events === []) {
            return;
        }
        try {
            $rows = $events;
            $now = now()->toDateTimeString();
            foreach ($pending as $kind => $buckets) {
                foreach ($buckets as $counts) {
                    $ship = $counts['ship'];
                    $agentSymbol = $counts['agent_symbol'] ?? null;
                    $payload = ['reads' => $counts['reads'], 'writes' => $counts['writes']];
                    if (isset($counts['categories'])) {
                        $payload['categories'] = $counts['categories'];
                    }
                    if ($counts['workload']) {
                        $payload['workload'] = $counts['workload'];
                    }
                    $rows[] = ['kind' => $kind, 'ship' => $ship, 'agent_symbol' => $agentSymbol, 'scope' => $counts['scope'],
                        'value' => $counts['reads'] + $counts['writes'],
                        'data' => $kind === 'requests' ? json_encode($payload) : null, 'created_at' => $now];
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
                'dispatched' => count($lags), 'dispatch_lag' => self::median($lags), 'dispatch_lags' => $lags,
                'pre_dispatched' => $t['pre_dispatched'] ?? 0, 'arms_directed' => $t['arms_directed'] ?? 0,
                'pre_dispatch' => $t['pre_dispatch'] ?? null, 'handover_refresh_seconds' => $t['handover_refresh_seconds'] ?? 0,
                'queue_depth' => DB::table(config('queue.connections.database.table', 'jobs'))->where('queue', 'spacetraders')->count()];
            $total = array_sum(array_map('floatval', array_filter([$data['director'], $data['arms'], $data['dispatch'], $data['pre_dispatch']], 'is_numeric')));
            DB::table('fleet_metrics')->insert(['kind' => 'scheduler', 'ship' => null, 'agent_symbol' => null, 'scope' => 'host',
                'value' => $total, 'data' => json_encode($data), 'created_at' => now()->toDateTimeString()]);
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
        $agents = Agent::whereIn('id', $episodes->pluck('agent_id')->unique())->get()->keyBy('id');
        $distinctAgents = $episodes->pluck('agent_id')->unique()->count();
        $arms = [];
        foreach ($episodes as $episode) {
            $grant = $grants[$episode->campaign_grant_id];
            $arm = $grant->limits['arm'] ?? null;
            $agentSymbol = strtoupper((string) ($agents[$episode->agent_id]?->name ?? $grant->revision()->body['agent']['symbol'] ?? 'UNKNOWN'));
            $baseLabel = $arm['label'] ?? 'C0';
            $label = $distinctAgents > 1 ? $agentSymbol.':'.$baseLabel : $baseLabel;
            $arms[$label] ??= ['label' => $label, 'agent_symbol' => $agentSymbol, 'base_label' => $baseLabel,
                'ship' => $arm['ship'] ?? ($episode->state['role_ships']['command'] ?? null), 'episodes' => [],
                'agent_id' => $episode->agent_id, 'reset_id' => $episode->reset_id, 'opened_at' => CarbonImmutable::parse($episode->opened_at), 'loads' => []];
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
        $t0 = $since ?? (collect($arms)->filter(fn ($arm) => $arm['base_label'] !== 'C0')->min('opened_at') ?? collect($arms)->min('opened_at') ?? $until);
        $blocks = [[$t0, $t0->addHours(2)], [$t0->addHours(2), $t0->addHours(4)]];
        $rows = [];
        foreach ($arms as $label => $arm) {
            $rows[$label] = $this->arm($arm, $t0, $until, $blocks) + [
                'agent_symbol' => $arm['agent_symbol'],
                'qualified_label' => $arm['agent_symbol'].':'.$arm['base_label'],
            ];
        }
        $card = ['reset' => $reset, 'since' => $t0->toIso8601String(), 'until' => $until->toIso8601String(), 'arms' => $rows,
            'blocks' => array_map(fn ($b) => ['from' => $b[0]->toIso8601String(), 'to' => $b[1]->toIso8601String()], $blocks),
            'scheduler' => $this->scheduler($t0, $until)];
        $card['budget'] = $this->budget($rows, $t0, $until);
        $card['by_agent'] = $this->byAgent($rows, $t0, $until);
        $card['shared_pair'] = $this->sharedPair($rows);
        $card['flags'] = $this->flags($rows);

        return $card;
    }

    /**
     * Per-agent request-budget metrics and formula-derived trader ceiling.
     *
     * Rollout gate uses all_in_req_per_active_trader_hr (probes counted in numerator only).
     * Fleet purchase formula uses trader_only_req_per_active_trader_hr and subtracts overhead once;
     * purchases hold whenever active trader hours < 1.0 or agent-scoped attribution is incomplete.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    public function byAgent(array $rows, CarbonImmutable $t0, CarbonImmutable $until): array
    {
        $from = max($t0, $until->subHours(6));
        $between = [$from->toDateTimeString(), $until->toDateTimeString()];
        $windowHours = max(0.0, $from->diffInSeconds($until, false) / 3600);
        $caps = RequestBudget::agentCaps();
        $requestRows = DB::table('fleet_metrics')
            ->where('kind', 'requests')
            ->where('scope', '!=', 'host')
            ->whereBetween('created_at', $between)
            ->get(['ship', 'agent_symbol', 'value', 'data', 'created_at']);

        $unattributedAgentRows = (int) $requestRows->filter(fn ($row) => empty($row->agent_symbol))->sum('value');
        $byAgent = [];
        $grouped = collect($rows)->groupBy('agent_symbol');
        foreach ($grouped as $symbol => $agentRows) {
            if (! is_string($symbol) || $symbol === '') {
                continue;
            }
            $episodes = CampaignEpisode::whereIn('id', $agentRows->pluck('episodes')->flatten()->unique())->get();
            $sampleComplete = $windowHours >= 6.0 && $episodes->min('opened_at') <= $from && $episodes->isNotEmpty();
            $trading = $this->tradingIntervals($episodes->pluck('agent_id')->unique()->all(), $episodes->first()?->reset_id, $from, $until);
            $activeTraderHours = array_sum(array_map(fn ($intervals) => array_sum(array_map(fn ($span) => $span[1] - $span[0], $intervals)), $trading)) / 3600;
            $agentRequests = $requestRows->where('agent_symbol', $symbol);
            $allInRequests = (int) $agentRequests->sum('value');
            $traderOnlyRequests = (int) $agentRequests->sum(function ($row) use ($trading) {
                if (! isset($trading[$row->ship]) || (json_decode($row->data ?? '{}', true)['workload'] ?? null) === 'director') {
                    return 0;
                }
                $at = CarbonImmutable::parse($row->created_at)->timestamp;
                foreach ($trading[$row->ship] as [$start, $end]) {
                    if ($at >= $start && $at <= $end) {
                        return (int) $row->value;
                    }
                }

                return 0;
            });
            $overheadRequests = max(0, $allInRequests - $traderOnlyRequests);
            $allInPerTraderHr = $activeTraderHours > 0 ? $allInRequests / $activeTraderHours : null;
            $traderOnlyPerTraderHr = $activeTraderHours > 0 ? $traderOnlyRequests / $activeTraderHours : null;
            $overheadReqHr = $windowHours > 0 ? $overheadRequests / $windowHours : null;
            $attributionComplete = $unattributedAgentRows === 0;
            $cap = $caps[strtoupper($symbol)] ?? null;
            $hold = match (true) {
                ! $attributionComplete => 'incomplete_agent_attribution',
                ! $sampleComplete => 'insufficient_sample_window',
                $activeTraderHours < 1.0 => 'insufficient_trader_hours',
                $traderOnlyPerTraderHr === null || $traderOnlyPerTraderHr <= 0 => 'missing_trader_rate',
                default => null,
            };
            $maxTraders = $cap !== null && $hold === null
                ? max(0, (int) floor(($cap - $overheadReqHr) / $traderOnlyPerTraderHr))
                : null;

            $byAgent[$symbol] = [
                'agent_symbol' => $symbol,
                'arms' => $agentRows->mapWithKeys(fn ($row) => [$row['qualified_label'] => $row])->all(),
                'active_trader_hours' => round($activeTraderHours, 3),
                'all_in_requests' => $allInRequests,
                'trader_only_requests' => $traderOnlyRequests,
                'overhead_requests' => $overheadRequests,
                'all_in_req_per_active_trader_hr' => $allInPerTraderHr,
                'trader_only_req_per_active_trader_hr' => $traderOnlyPerTraderHr,
                'agent_overhead_req_hr' => $overheadReqHr,
                'attribution_complete' => $attributionComplete,
                'unattributed_agent_requests' => $unattributedAgentRows,
                'cap_req_hr' => $cap,
                'formula_max_traders' => $maxTraders,
                'purchase_hold_reason' => $hold,
            ];
        }

        return $byAgent;
    }

    /** @return array<string, list<array{int, int}>> Union of observed trade-run lifetimes per ship. */
    private function tradingIntervals(array $agents, ?string $reset, CarbonImmutable $from, CarbonImmutable $until): array
    {
        if ($agents === [] || $reset === null) {
            return [];
        }
        $probes = Ship::whereIn('agent_id', $agents)->get()
            ->filter(fn ($ship) => app(CampaignFleet::class)->isProbe($ship->snapshot))->pluck('symbol')->all();
        $runs = AutomationRun::whereIn('agent_id', $agents)->where('reset_id', $reset)
            ->whereIn('strategy', ['trade', 'sell_aboard'])->whereNotIn('ship_symbol', $probes)
            ->whereNotNull('started_at')->where('started_at', '<', $until)->where('updated_at', '>', $from)
            ->orderBy('started_at')->get();
        $trading = [];
        foreach ($runs as $run) {
            $start = max($from->timestamp, $run->started_at->timestamp);
            $observedEnd = isset($run->totals['stopped_at'])
                ? CarbonImmutable::parse($run->totals['stopped_at'])
                : ($run->heartbeat_at && $run->active_agent_id ? $run->heartbeat_at : $run->updated_at);
            $end = min($until->timestamp, $observedEnd->timestamp);
            if ($end <= $start) {
                continue;
            }
            $intervals = &$trading[$run->ship_symbol];
            $intervals ??= [];
            $last = array_key_last($intervals);
            if ($last !== null && $intervals[$last][1] >= $start) {
                $intervals[$last][1] = max($intervals[$last][1], $end);
            } else {
                $intervals[] = [$start, $end];
            }
            unset($intervals);
        }

        return $trading;
    }

    /** SpaceTraders allows 2 requests per second per IP; the fleet may use 90% of that hour. */
    public const HOURLY_BUDGET = 7200;

    /**
     * Request budget view over fleet_metrics only (no API calls). All-in counts every request in the window from the
     * earliest arm window start (all ships, including shared and director rows), divided by the arms' summed active
     * hours. Max useful fleet = 0.9 x 7,200 / all-in requests per active ship-hour.
     */
    private function budget(array $rows, CarbonImmutable $t0, CarbonImmutable $until): array
    {
        $active = array_filter($rows, fn ($row) => $row['hours'] > 0);
        $from = $active ? min(array_map(fn ($row) => CarbonImmutable::parse($row['window_start']), $active)) : $t0;
        $between = [$from->toDateTimeString(), $until->toDateTimeString()];
        $hours = array_sum(array_column($active, 'hours'));
        $loads = array_sum(array_column($rows, 'loads'));
        $total = 0;
        $unattributed = 0;
        $categories = [];
        foreach (DB::table('fleet_metrics')->where('kind', 'requests')->whereBetween('created_at', $between)->get(['ship', 'value', 'data']) as $row) {
            $total += (int) $row->value;
            $unattributed += in_array($row->ship, ['shared', 'director'], true) ? (int) $row->value : 0;
            foreach ((json_decode($row->data ?? '', true)['categories'] ?? []) as $category => $count) {
                $categories[$category] = ($categories[$category] ?? 0) + (int) $count;
            }
        }
        ksort($categories);
        $outcomes = [];
        foreach (['advance', 'director_pass'] as $kind) {
            $outcomes[$kind] = [];
            foreach (DB::table('fleet_metrics')->where('kind', $kind)->whereBetween('created_at', $between)->pluck('data') as $data) {
                $data = json_decode($data ?? '', true) ?: [];
                $outcome = $data['outcome'] ?? 'unknown';
                $outcomes[$kind][$outcome] ??= ['count' => 0, 'requests' => 0];
                $outcomes[$kind][$outcome]['count']++;
                $outcomes[$kind][$outcome]['requests'] += (int) ($data['requests'] ?? 0);
            }
            ksort($outcomes[$kind]);
        }
        $perHour = $hours > 0 ? $total / $hours : null;
        $advances = array_sum(array_column($outcomes['advance'], 'count'));

        return ['window_from' => $from->toIso8601String(), 'requests' => $total, 'unattributed_requests' => $unattributed,
            'active_ship_hours' => round($hours, 3), 'requests_per_ship_hour' => $perHour, 'loads' => $loads,
            'requests_per_load' => $loads > 0 ? $total / $loads : null, 'by_category' => $categories, 'by_outcome' => $outcomes,
            'advances' => $advances, 'advances_without_write' => $advances - ($outcomes['advance']['wrote']['count'] ?? 0),
            'max_useful_fleet' => $perHour > 0 ? 0.9 * self::HOURLY_BUDGET / $perHour : null];
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
        $price = $arm['ship'] ? ShipAction::where('agent_id', $arm['agent_id'])->where('reset_id', $arm['reset_id'])->where('command', 'buy_ship')->where('state', 'confirmed')->get()
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
