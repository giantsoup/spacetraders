<?php

namespace Tests\Feature;

use App\Gameplay\LeaderboardHistory;
use App\Models\LeaderboardSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LeaderboardHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        CarbonImmutable::setTestNow('2026-11-21T10:00:00Z');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function statusBody(string $reset = '2026-11-15', array $credits = [['agentSymbol' => 'LEADER', 'credits' => 9_000_000_000]]): array
    {
        return [
            'status' => 'online', 'version' => 'v2.3.0', 'resetDate' => $reset,
            'stats' => ['agents' => 3, 'ships' => 10],
            'leaderboards' => ['mostCredits' => $credits, 'mostSubmittedCharts' => [['agentSymbol' => 'LEADER', 'chartCount' => 12]]],
            'serverResets' => ['next' => '2026-11-22T13:00:00.000Z', 'frequency' => 'weekly'],
        ];
    }

    private function fakeApi(array $status, array $pages, int $total): void
    {
        Http::fake(function (Request $request) use ($status, $pages, $total) {
            $url = parse_url($request->url());
            if ($url['path'] === '/v2/agents') {
                parse_str($url['query'] ?? '', $query);

                return Http::response(['data' => $pages[(int) $query['page'] - 1] ?? [], 'meta' => ['total' => $total, 'page' => (int) $query['page'], 'limit' => 20]]);
            }

            return $url['path'] === '/v2' || $url['path'] === '/v2/' ? Http::response($status) : Http::response([], 404);
        });
    }

    private function agent(string $symbol, int $credits): array
    {
        return ['symbol' => $symbol, 'credits' => $credits, 'shipCount' => 2, 'headquarters' => 'X1-A1-A1', 'startingFaction' => 'COSMIC', 'accountId' => 'private'];
    }

    public function test_capture_saves_status_leaderboards_and_the_full_agent_directory(): void
    {
        $this->fakeApi($this->statusBody(), [[$this->agent('SMALL', 100), $this->agent('LEADER', 9_000_000_000)], [$this->agent('MIDDLE', 5_000)]], 3);

        $this->artisan('leaderboard:capture')->expectsOutputToContain('Leader: LEADER with 9,000,000,000 credits.')->expectsOutputToContain('Agent directory: 3 agents.')->assertExitCode(0);

        $snapshot = LeaderboardSnapshot::sole();
        $this->assertSame('2026-11-15', $snapshot->reset_date);
        $this->assertSame('2026-11-22 13:00:00', $snapshot->next_reset_at->format('Y-m-d H:i:s'));
        $this->assertSame('api', $snapshot->source);
        $this->assertSame(3, $snapshot->agents_total);
        $this->assertSame(12, $snapshot->most_submitted_charts[0]['chartCount']);
        $this->assertArrayNotHasKey('accountId', $snapshot->agents[0]);
        $this->assertSame(['LEADER', 'MIDDLE', 'SMALL'], array_column($snapshot->standings(), 'symbol'));
        Http::assertSentCount(3);
    }

    public function test_incomplete_directory_keeps_the_status_leaderboards(): void
    {
        $this->fakeApi($this->statusBody(), [[$this->agent('LEADER', 9_000_000_000)]], 5);

        $this->artisan('leaderboard:capture')->expectsOutputToContain('Agent directory not saved: collected 1 of 5 agents.')->assertExitCode(0);

        $snapshot = LeaderboardSnapshot::sole();
        $this->assertNull($snapshot->agents);
        $this->assertSame('LEADER', $snapshot->standings()[0]['symbol']);
    }

    public function test_top_only_skips_the_directory(): void
    {
        $this->fakeApi($this->statusBody(), [], 0);

        $this->artisan('leaderboard:capture --top-only')->expectsOutputToContain('Agent directory: not saved.')->assertExitCode(0);

        Http::assertSentCount(1);
    }

    public function test_invalid_status_saves_nothing_and_fails(): void
    {
        Http::fake(['*' => Http::response(['resetDate' => '2026-11-15', 'leaderboards' => ['mostCredits' => [['agentSymbol' => 'X']]]])]);

        $this->artisan('leaderboard:capture --top-only')->expectsOutputToContain('Leaderboard capture failed')->assertExitCode(1);

        $this->assertSame(0, LeaderboardSnapshot::count());
    }

    public function test_due_schedule_is_hourly_then_every_five_minutes_before_reset(): void
    {
        $history = app(LeaderboardHistory::class);
        $this->assertSame('no snapshot saved yet', $history->dueReason());

        $this->snapshot('2026-11-15', '2026-11-21T09:30:00Z');
        $this->assertNull($history->dueReason());
        $this->assertSame('routine hourly capture', $history->dueReason(CarbonImmutable::parse('2026-11-21T10:31:00Z')));

        $this->snapshot('2026-11-15', '2026-11-22T11:30:00Z');
        $this->assertNull($history->dueReason(CarbonImmutable::parse('2026-11-22T11:33:00Z')));
        $this->assertSame('final hours before the reset', $history->dueReason(CarbonImmutable::parse('2026-11-22T11:35:00Z')));
        $this->assertSame('the saved next reset time has passed', $history->dueReason(CarbonImmutable::parse('2026-11-22T13:01:00Z')));
    }

    public function test_if_due_makes_no_request_when_a_recent_snapshot_exists(): void
    {
        Http::fake();
        $this->snapshot('2026-11-15', '2026-11-21T09:45:00Z');

        $this->artisan('leaderboard:capture --if-due')->expectsOutput('Leaderboard capture not due.')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_history_lists_the_last_snapshot_of_each_wipe(): void
    {
        $this->snapshot('2026-11-08', '2026-11-15T10:00:00Z', [['agentSymbol' => 'EARLY', 'credits' => 1]]);
        $this->snapshot('2026-11-08', '2026-11-15T12:55:00Z', [['agentSymbol' => 'WINNER', 'credits' => 8_000_000_000]]);
        $this->snapshot('2026-11-15', '2026-11-21T09:00:00Z', [['agentSymbol' => 'NOW', 'credits' => 5]], '2026-11-22T13:00:00Z');

        $finals = LeaderboardSnapshot::finals();
        $this->assertSame(['2026-11-15', '2026-11-08'], $finals->pluck('reset_date')->all());
        $this->assertSame('WINNER', $finals[1]->standings()[0]['symbol']);

        $this->artisan('leaderboard:history')->expectsOutputToContain('WINNER')->doesntExpectOutputToContain('EARLY')->assertExitCode(0);
        $this->artisan('leaderboard:history 2026-11-08')->expectsOutputToContain('0h 05m before reset')->assertExitCode(0);
        $this->artisan('leaderboard:history 2026-10-01')->assertExitCode(1);
    }

    public function test_standings_use_competition_ranks_for_ties(): void
    {
        $snapshot = $this->snapshot('2026-11-15', '2026-11-21T09:00:00Z', [
            ['agentSymbol' => 'C', 'credits' => 5], ['agentSymbol' => 'A', 'credits' => 10], ['agentSymbol' => 'B', 'credits' => 5], ['agentSymbol' => 'D', 'credits' => 1],
        ]);

        $this->assertSame([[1, 'A'], [2, 'B'], [2, 'C'], [4, 'D']], array_map(fn ($r) => [$r['rank'], $r['symbol']], $snapshot->standings()));
    }

    public function test_import_saves_a_status_file_with_its_capture_time(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'status');
        file_put_contents($file, json_encode($this->statusBody('2026-11-08')));

        $this->artisan('leaderboard:import', ['file' => $file, '--captured-at' => '2026-11-15T01:27:43Z'])->assertExitCode(0);
        $this->artisan('leaderboard:import', ['file' => $file])->assertExitCode(1);
        unlink($file);

        $snapshot = LeaderboardSnapshot::sole();
        $this->assertSame(['2026-11-08', 'import', '2026-11-15 01:27'], [$snapshot->reset_date, $snapshot->source, $snapshot->captured_at->format('Y-m-d H:i')]);
    }

    private function snapshot(string $reset, string $capturedAt, array $credits = [['agentSymbol' => 'LEADER', 'credits' => 1]], string $next = '2026-11-15T13:00:00Z'): LeaderboardSnapshot
    {
        $next = $reset === '2026-11-15' && $next === '2026-11-15T13:00:00Z' ? '2026-11-22T13:00:00Z' : $next;

        return LeaderboardSnapshot::create(['reset_date' => $reset, 'captured_at' => $capturedAt, 'next_reset_at' => $next, 'most_credits' => $credits, 'most_submitted_charts' => []]);
    }
}
