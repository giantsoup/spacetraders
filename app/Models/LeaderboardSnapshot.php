<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaderboardSnapshot extends Model
{
    protected $guarded = [];

    protected $casts = [
        'captured_at' => 'immutable_datetime',
        'next_reset_at' => 'immutable_datetime',
        'most_credits' => 'array',
        'most_submitted_charts' => 'array',
        'stats' => 'array',
        'agents' => 'array',
    ];

    /** The latest snapshot of each wipe, newest wipe first. */
    public static function finals()
    {
        $latest = static::query()->selectRaw('max(id) as id')->groupBy('reset_date');

        return static::query()->whereIn('id', $latest)->orderByDesc('reset_date')->get();
    }

    /** Credit standings: the full directory when collected, otherwise the status top list. */
    public function standings(): array
    {
        $rows = $this->agents !== null
            ? array_map(fn ($a) => ['symbol' => $a['symbol'], 'credits' => $a['credits'], 'ships' => $a['shipCount'] ?? null], $this->agents)
            : array_map(fn ($a) => ['symbol' => $a['agentSymbol'], 'credits' => $a['credits'], 'ships' => null], $this->most_credits);
        usort($rows, fn ($a, $b) => [$b['credits'], $a['symbol']] <=> [$a['credits'], $b['symbol']]);
        // Competition ranking: equal credits share a rank (1, 2, 2, 4).
        for ($i = 0; $i < count($rows); $i++) {
            $rows[$i]['rank'] = $i > 0 && $rows[$i - 1]['credits'] === $rows[$i]['credits'] ? $rows[$i - 1]['rank'] : $i + 1;
        }

        return $rows;
    }
}
