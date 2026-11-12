<?php

namespace App\Jobs;

use App\Gameplay\GameException;
use App\Gameplay\ShipCommandExecutor;
use App\Queue\RequireEngineWorker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ExecuteShipCommand implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 1;

    public int $timeout = 120;

    public function __construct(public int $actionId)
    {
        $this->onConnection('database')->onQueue('spacetraders');
    }

    public function middleware(): array
    {
        return [new RequireEngineWorker];
    }

    public function handle(ShipCommandExecutor $executor): void
    {
        DB::table('game_runtime')->upsert([['key' => 'worker', 'value' => json_encode(now()->toIso8601String())]], ['key'], ['value']);
        try {
            $executor->execute($this->actionId);
        } catch (GameException) {
            // Durable pending intent is rediscovered by the scheduler; no unsafe queue retry.
        }
    }
}
