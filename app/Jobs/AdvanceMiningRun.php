<?php

namespace App\Jobs;

use App\Gameplay\GameException;
use App\Gameplay\MiningRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class AdvanceMiningRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $runId)
    {
        $this->onConnection('database')->onQueue('spacetraders');
    }

    public function handle(MiningRunner $runner): void
    {
        DB::table('game_runtime')->updateOrInsert(['key' => 'worker'], ['value' => json_encode(now()->toIso8601String())]);
        try {
            $runner->advance($this->runId);
        } catch (GameException) {
            // A contending worker owns the agent. The scheduler rediscovers due work.
        }
    }
}
