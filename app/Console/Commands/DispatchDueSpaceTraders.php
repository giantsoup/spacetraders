<?php

namespace App\Console\Commands;

use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignHealth;
use App\Gameplay\DeploymentGate;
use App\Gameplay\EngineGate;
use App\Gameplay\FleetMetrics;
use App\Gameplay\GameException;
use App\Gameplay\GateRecon;
use App\Gameplay\GateWatch;
use App\Gameplay\HostLock;
use App\Gameplay\ShipCommandExecutor;
use App\Jobs\AdvanceAutomationRun;
use App\Jobs\ExecuteShipCommand;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\ReconMission;
use App\Models\ShipAction;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatchDueSpaceTraders extends Command
{
    protected $signature = 'spacetraders:dispatch-due';

    protected $description = 'Recover durable pending actions and dispatch due bounded mining steps';

    public function handle(ShipCommandExecutor $executor): int
    {
        $gate = app(DeploymentGate::class);
        $id = $gate->begin('dispatch');
        if ($id === null) {
            return self::SUCCESS;
        }
        try {
            // OS ownership survives cache TTL expiry, and releases on a crash.
            return app(HostLock::class)->run('dispatch-due:v1', fn () => $this->dispatchDue($executor));
        } catch (GameException) {
            return self::SUCCESS;
        } finally {
            $gate->finish($id);
        }
    }

    private function dispatchDue(ShipCommandExecutor $executor): int
    {
        $deadline = hrtime(true) + 15_000_000_000;
        DB::table('game_runtime')->upsert([['key' => 'scheduler', 'value' => json_encode(now()->toIso8601String())]], ['key'], ['value']);
        foreach (ShipAction::where('state', 'sending')->where('sent_at', '<', now()->subSeconds(180))->limit(50)->get() as $action) {
            if (hrtime(true) >= $deadline) {
                break;
            }
            try {
                $executor->locked(Agent::findOrFail($action->agent_id), function () use ($action) {
                    ShipAction::whereKey($action->id)->where('state', 'sending')->update(['state' => 'uncertain', 'message' => 'Sender disappeared before confirmation. Reconcile; this intent will not be replayed.']);
                });
            } catch (GameException) {
                // The sender still holds its OS lock. Do not steal command ownership.
            }
        }
        foreach (ReconMission::whereNull('closed_at')->limit(50)->get() as $mission) {
            if (hrtime(true) >= $deadline) {
                break;
            }
            try {
                $executor->locked(Agent::findOrFail($mission->agent_id), fn () => app(GateRecon::class)->reconcileSettlement($mission));
            } catch (GameException) {
                // The owner is still working; retry on the next bounded sweep.
            }
        }
        try {
            app(CampaignHealth::class)->evaluateKillSwitch();
        } catch (GameException) {
            // Another pass owns campaign authority, or local budget telemetry is unavailable.
        } catch (\Throwable $exception) {
            Log::error('Campaign kill switch evaluation failed.', ['exception_class' => get_class($exception)]);
        }
        $timing = ['lags' => [], 'director' => 0, 'arms' => 0];
        $director = app(CampaignDirector::class);
        $turns = DB::table('game_runtime')->where('key', 'director_turn')->value('value') === 'arms' ? ['arms', 'director'] : ['director', 'arms'];
        foreach ($turns as $turn) {
            $started = microtime(true);
            $attempted = $director->dispatch($turn === 'arms');
            $timing[$turn] = microtime(true) - $started;
            if ($attempted) {
                DB::table('game_runtime')->upsert([['key' => 'director_turn', 'value' => $turn === 'arms' ? 'director' : 'arms']], ['key'], ['value']);
                break;
            }
        }
        $started = microtime(true);
        // A campaign decision can take most of a minute. Give dispatch its own budget after it.
        $deadline = hrtime(true) + 15_000_000_000;
        if (app(EngineGate::class)->enabled()) {
            try {
                app(EngineGate::class)->session();
            } catch (GameException) {
                $this->metrics($timing + ['dispatch' => microtime(true) - $started]);

                return self::SUCCESS;
            }
        }
        $pendingActions = $this->fairSlice(
            ShipAction::where('state', 'pending')->where(function ($q) {
                $q->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<=', now()->subSeconds(30));
            }),
            'agent_id',
        );
        foreach ($pendingActions as $action) {
            if (hrtime(true) >= $deadline) {
                break;
            }
            if (ShipAction::whereKey($action->id)->where(function ($q) {
                $q->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<=', now()->subSeconds(30));
            })->update(['last_dispatched_at' => now()])) {
                ExecuteShipCommand::dispatch($action->id);
            }
        }
        $dueRuns = $this->fairSlice(
            AutomationRun::whereNotNull('active_agent_id')->where('next_action_at', '<=', now())->where(function ($q) {
                $q->whereIn('state', ['running', 'waiting', 'stopping'])->orWhereNotNull('stop_requested_at');
            })->where(function ($q) {
                $q->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<=', now()->subSeconds(30));
            }),
            'active_agent_id',
        );
        foreach ($dueRuns as $run) {
            if (hrtime(true) >= $deadline) {
                break;
            }
            if (AutomationRun::whereKey($run->id)->where(function ($q) {
                $q->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<=', now()->subSeconds(30));
            })->update(['last_dispatched_at' => now()])) {
                $timing['lags'][] = max(0, $run->next_action_at->diffInSeconds(now(), false));
                AdvanceAutomationRun::dispatch($run->id);
            }
        }
        $this->metrics($timing + ['dispatch' => microtime(true) - $started]);
        $this->watchGate();

        return self::SUCCESS;
    }

    private function watchGate(): void
    {
        $waypoint = config('campaign.gate_watch.waypoint');
        if (! is_string($waypoint) || $waypoint === '') {
            return;
        }
        $agent = Agent::where('user_id', config('gameplay.operator_id'))->where('name', 'GIANTSOUP')
            ->unarchived()->whereNotNull('reset_id')->latest('id')->first();
        if ($agent) {
            try {
                app(GateWatch::class)->pollGate($agent, $waypoint, config('campaign.gate_watch.progress'));
            } catch (GameException) {
                // Another sweep owns the poll, the request was shed, or the waypoint is unavailable.
            }
        }
    }

    /** Rank before the limit so arbitrarily large backlogs cannot hide another agent. */
    private function fairSlice(Builder $query, string $agentKey): Collection
    {
        $ranked = $query->select('*')->selectRaw('ROW_NUMBER() OVER (PARTITION BY '.$agentKey.' ORDER BY id) AS dispatch_round');

        return $query->getModel()->newQuery()->fromSub($ranked, $query->getModel()->getTable())
            ->orderBy('dispatch_round')->orderBy('id')->limit(50)->get();
    }

    private function metrics(array $timing): void
    {
        try {
            app(FleetMetrics::class)->schedulerPass($timing);
        } catch (\Throwable) {
        }
    }
}
