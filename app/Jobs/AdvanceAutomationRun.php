<?php

namespace App\Jobs;

use App\Gameplay\AutomationRunner;
use App\Gameplay\FleetMetrics;
use App\Gameplay\GameException;
use App\Models\AutomationRun;
use App\Queue\RequireEngineWorker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class AdvanceAutomationRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 1;

    public bool $countCrashesAsExceptions = true;

    public int $timeout = 120;

    public function __construct(public int $runId)
    {
        $this->onConnection('database')->onQueue('spacetraders');
    }

    public function middleware(): array
    {
        return [new RequireEngineWorker];
    }

    public function handle(AutomationRunner $runner): void
    {
        DB::table('game_runtime')->upsert([['key' => 'worker', 'value' => json_encode(now()->toIso8601String())]], ['key'], ['value']);
        FleetMetrics::as(AutomationRun::whereKey($this->runId)->value('ship_symbol') ?: null, function () use ($runner) {
            try {
                $runner->advance($this->runId);
            } catch (GameException $exception) {
                // A contending worker owns the agent. The scheduler rediscovers due work.
                FleetMetrics::observe($exception);
                if (str_starts_with($exception->getMessage(), 'Another operation owns this command boundary')) {
                    AutomationRun::whereKey($this->runId)->update(['last_dispatched_at' => null]);
                }
            }
        });
    }
}
