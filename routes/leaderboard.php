<?php

use App\Gameplay\GameException;
use App\Gameplay\LeaderboardHistory;
use App\Models\LeaderboardSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

Artisan::command('leaderboard:capture {--if-due : Capture only when the schedule says one is due} {--top-only : Skip the full public agent directory}', function (LeaderboardHistory $history) {
    if ($this->option('if-due')) {
        $reason = $history->dueReason();
        if (! $reason) {
            $this->line('Leaderboard capture not due.');

            return 0;
        }
        $this->line('Capture due: '.$reason.'.');
    }
    try {
        $result = $history->capture(directory: ! $this->option('top-only'));
    } catch (GameException $e) {
        $this->error('Leaderboard capture failed: '.$e->getMessage());

        return 1;
    }
    $snapshot = $result['snapshot'];
    $leader = $snapshot->standings()[0] ?? null;
    $this->line(sprintf('Saved snapshot %d for wipe %s at %s UTC.', $snapshot->id, $snapshot->reset_date, $snapshot->captured_at->format('Y-m-d H:i')));
    $this->line($leader ? sprintf('Leader: %s with %s credits.', $leader['symbol'], number_format($leader['credits'])) : 'No agents on the leaderboard yet.');
    $this->line($snapshot->agents !== null ? 'Agent directory: '.count($snapshot->agents).' agents.' : 'Agent directory: not saved.');
    if ($result['warning']) {
        $this->warn($result['warning']);
    }

    return 0;
})->purpose('Save the public SpaceTraders leaderboard for the current wipe');

Artisan::command('leaderboard:history {wipe? : A reset date, such as 2026-11-08} {--limit=10}', function () {
    $limit = max(1, (int) $this->option('limit'));
    $before = function (LeaderboardSnapshot $s) {
        if (! $s->next_reset_at) {
            return 'unknown';
        }
        $minutes = (int) round($s->captured_at->diffInMinutes($s->next_reset_at));

        return $minutes < 0 ? 'after reset' : sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
    };
    if ($wipe = $this->argument('wipe')) {
        $snapshot = LeaderboardSnapshot::where('reset_date', $wipe)->latest('captured_at')->latest('id')->first();
        if (! $snapshot) {
            $this->error('No snapshot saved for wipe '.$wipe.'.');

            return 1;
        }
        $this->line(sprintf('Wipe %s, last snapshot %s UTC (%s before reset, %s).', $wipe, $snapshot->captured_at->format('Y-m-d H:i'), $before($snapshot), $snapshot->agents !== null ? 'full directory' : 'status top list only'));
        $this->table(['Rank', 'Agent', 'Credits', 'Ships'], array_map(fn ($r) => [$r['rank'], $r['symbol'], number_format($r['credits']), $r['ships'] ?? '-'], array_slice($snapshot->standings(), 0, $limit)));

        return 0;
    }
    $finals = LeaderboardSnapshot::finals();
    if ($finals->isEmpty()) {
        $this->line('No leaderboard snapshots saved yet.');

        return 0;
    }
    $this->table(['Wipe', 'Last snapshot (UTC)', 'Before reset', 'Leader', 'Credits', 'Source'], $finals->map(function (LeaderboardSnapshot $s) use ($before) {
        $leader = $s->standings()[0] ?? null;

        return [$s->reset_date, $s->captured_at->format('Y-m-d H:i'), $before($s), $leader['symbol'] ?? '-', $leader ? number_format($leader['credits']) : '-', $s->source];
    })->all());

    return 0;
})->purpose('Show each wipe\'s last saved leaderboard, or one wipe\'s standings');

Artisan::command('leaderboard:import {file : A saved GET /v2 status JSON file} {--captured-at= : When the file was captured, ISO 8601}', function (LeaderboardHistory $history) {
    if (! $this->option('captured-at')) {
        $this->error('Pass --captured-at with the time the file was saved.');

        return 1;
    }
    $status = json_decode((string) @file_get_contents($this->argument('file')), true);
    if (! is_array($status)) {
        $this->error('Could not read JSON from '.$this->argument('file').'.');

        return 1;
    }
    try {
        $snapshot = $history->import($status, CarbonImmutable::parse($this->option('captured-at')));
    } catch (GameException $e) {
        $this->error($e->getMessage());

        return 1;
    }
    $this->line(sprintf('Imported snapshot %d for wipe %s captured %s UTC.', $snapshot->id, $snapshot->reset_date, $snapshot->captured_at->format('Y-m-d H:i')));

    return 0;
})->purpose('Import a saved status response as a leaderboard snapshot');
