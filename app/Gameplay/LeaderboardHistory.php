<?php

namespace App\Gameplay;

use App\Models\LeaderboardSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Sleep;

/**
 * Saves public leaderboard snapshots so each wipe's final standings survive the reset.
 * The status endpoint only shows the current wipe, so the last snapshot before a reset is the record.
 */
class LeaderboardHistory
{
    /** Capture hourly, and every five minutes in the final two hours before a reset. */
    public const ROUTINE_MINUTES = 60;

    public const FINAL_WINDOW_MINUTES = 120;

    public const FINAL_WINDOW_INTERVAL_MINUTES = 5;

    public const MAX_DIRECTORY_PAGES = 100;

    private const MAX_DEFERRALS = 5;

    public function __construct(private SpaceTradersApi $api) {}

    /** Why a capture is due now, or null. Uses saved state only; no API call. */
    public function dueReason(?CarbonImmutable $now = null): ?string
    {
        $now ??= CarbonImmutable::now();
        $latest = LeaderboardSnapshot::query()->latest('captured_at')->latest('id')->first();
        if (! $latest) {
            return 'no snapshot saved yet';
        }
        $age = $latest->captured_at->diffInMinutes($now);
        $next = $latest->next_reset_at;
        if ($next && $now->gte($next)) {
            return 'the saved next reset time has passed';
        }
        if ($next && $now->diffInMinutes($next) <= self::FINAL_WINDOW_MINUTES) {
            return $age >= self::FINAL_WINDOW_INTERVAL_MINUTES ? 'final hours before the reset' : null;
        }

        return $age >= self::ROUTINE_MINUTES ? 'routine hourly capture' : null;
    }

    /** Read the status leaderboards and, optionally, the whole public agent directory. */
    public function capture(bool $directory = true): array
    {
        $status = $this->retrying(fn () => $this->api->publicStatus());
        $capturedAt = CarbonImmutable::now();
        $agents = null;
        $total = null;
        $warning = null;
        if ($directory) {
            try {
                [$agents, $total] = $this->directory();
            } catch (GameException $e) {
                $warning = 'Agent directory not saved: '.$e->getMessage();
            }
        }
        $snapshot = $this->store($status, $capturedAt, 'api', $agents, $total);

        return ['snapshot' => $snapshot, 'warning' => $warning];
    }

    /** Import a saved GET /v2 status body, such as a manual capture from before this command existed. */
    public function import(array $status, CarbonImmutable $capturedAt): LeaderboardSnapshot
    {
        $rules = [
            'resetDate' => 'required|string|max:80',
            'leaderboards.mostCredits' => 'present|array',
            'leaderboards.mostCredits.*.agentSymbol' => 'required|string|max:80',
            'leaderboards.mostCredits.*.credits' => 'required|integer',
            'leaderboards.mostSubmittedCharts' => 'present|array',
            'serverResets.next' => 'nullable|date',
        ];
        if (Validator::make($status, $rules)->fails()) {
            throw new GameException('The file is not a SpaceTraders status response with leaderboards.');
        }

        return $this->store($status, $capturedAt, 'import', null, null);
    }

    private function directory(): array
    {
        $bySymbol = [];
        $total = null;
        for ($page = 1; $page <= self::MAX_DIRECTORY_PAGES; $page++) {
            $result = $this->retrying(fn () => $this->api->publicAgentsPage($page));
            $total ??= $result['total'];
            foreach ($result['agents'] as $agent) {
                $bySymbol[$agent['symbol']] = $agent;
            }
            if ($result['agents'] === [] || count($bySymbol) >= $total) {
                break;
            }
        }
        if (count($bySymbol) < $total) {
            throw new GameException('collected '.count($bySymbol).' of '.$total.' agents.');
        }

        return [array_values($bySymbol), $total];
    }

    private function store(array $status, CarbonImmutable $capturedAt, string $source, ?array $agents, ?int $total): LeaderboardSnapshot
    {
        $next = $status['serverResets']['next'] ?? null;

        return LeaderboardSnapshot::create([
            'reset_date' => $status['resetDate'],
            'captured_at' => $capturedAt->utc(),
            'next_reset_at' => $next ? CarbonImmutable::parse($next)->utc() : null,
            'source' => $source,
            'most_credits' => array_values($status['leaderboards']['mostCredits']),
            'most_submitted_charts' => array_values($status['leaderboards']['mostSubmittedCharts']),
            'stats' => $status['stats'] ?? null,
            'agents' => $agents,
            'agents_total' => $total,
        ]);
    }

    /** Wait out local request-budget deferrals so a capture does not starve or race the engine. */
    private function retrying(callable $read): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $read();
            } catch (RequestDeferred $e) {
                if ($attempt >= self::MAX_DEFERRALS) {
                    throw $e;
                }
                Sleep::for(min(15, max(1, $e->retryAfter)))->seconds();
            }
        }
    }
}
