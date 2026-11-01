<?php

namespace App\Console\Commands;

use App\Gameplay\EngineGate;
use App\Gameplay\GameException;
use App\Gameplay\ShipCommandExecutor;
use App\Jobs\AdvanceAutomationRun;
use App\Jobs\ExecuteShipCommand;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\ShipAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchDueSpaceTraders extends Command
{
    protected $signature = 'spacetraders:dispatch-due';

    protected $description = 'Recover durable pending actions and dispatch due bounded mining steps';

    public function handle(ShipCommandExecutor $executor): int
    {
        DB::table('game_runtime')->updateOrInsert(['key' => 'scheduler'], ['value' => json_encode(now()->toIso8601String())]);
        foreach (ShipAction::where('state', 'sending')->where('sent_at', '<', now()->subSeconds(180))->limit(50)->get() as $action) {
            try {
                $executor->locked(Agent::findOrFail($action->agent_id), function () use ($action) {
                    ShipAction::whereKey($action->id)->where('state', 'sending')->update(['state' => 'uncertain', 'message' => 'Sender disappeared before confirmation. Reconcile; this intent will not be replayed.']);
                });
            } catch (GameException) {
                // The sender still holds its OS lock. Do not steal command ownership.
            }
        }
        if (app(EngineGate::class)->enabled()) {
            try {
                app(EngineGate::class)->session();
            } catch (GameException) {
                return self::SUCCESS;
            }
        }
        foreach (ShipAction::where('state', 'pending')->where(function ($q) {
            $q->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<=', now()->subSeconds(30));
        })->limit(50)->get() as $action) {
            if (ShipAction::whereKey($action->id)->where(function ($q) {
                $q->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<=', now()->subSeconds(30));
            })->update(['last_dispatched_at' => now()])) {
                ExecuteShipCommand::dispatch($action->id);
            }
        }
        foreach (AutomationRun::whereNotNull('active_agent_id')->where('next_action_at', '<=', now())->where(function ($q) {
            $q->whereIn('state', ['running', 'waiting', 'stopping'])->orWhereNotNull('stop_requested_at');
        })->where(function ($q) {
            $q->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<=', now()->subSeconds(30));
        })->limit(50)->get() as $run) {
            if (AutomationRun::whereKey($run->id)->where(function ($q) {
                $q->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<=', now()->subSeconds(30));
            })->update(['last_dispatched_at' => now()])) {
                AdvanceAutomationRun::dispatch($run->id);
            }
        }

        return self::SUCCESS;
    }
}
