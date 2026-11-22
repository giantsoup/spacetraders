<?php

namespace App\Console\Commands;

use App\Gameplay\CampaignControl;
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
use App\Models\CampaignGrant;
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
            $attempted = $turn === 'arms' ? $this->tickArms($director) : $this->tickDirector($director);
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

    private function tickDirector(CampaignDirector $director): bool
    {
        try {
            $attempted = $director->tick();
            if ($attempted) {
                $rows = [['key' => 'campaign_director_failures', 'value' => '0']];
                if ($director->primaryGrantId !== null) {
                    $rows[] = ['key' => 'campaign_director_failures:'.$director->primaryGrantId, 'value' => '0'];
                }
                DB::table('game_runtime')->upsert($rows, ['key'], ['value']);
            }

            return $attempted;
        } catch (\Throwable $exception) {
            $message = 'Campaign director failed; exception details redacted.';
            Log::error($message, ['exception_class' => get_class($exception), 'grant' => $director->primaryGrantId]);
            try {
                if ($director->primaryGrantId !== null) {
                    $grant = CampaignGrant::find($director->primaryGrantId);
                } else {
                    // A failure before selection has no safe target when multiple primaries exist.
                    $primaries = CampaignGrant::allPrimaries()->limit(2)->get();
                    $grant = $primaries->count() === 1 ? $primaries->first() : null;
                }
                $key = $grant ? 'campaign_director_failures:'.$grant->id : 'campaign_director_failures';
                $failures = (int) DB::table('game_runtime')->where('key', $key)->value('value') + 1;
                DB::table('game_runtime')->upsert([['key' => $key, 'value' => (string) $failures]], ['key'], ['value']);
                if ($grant) {
                    $control = app(CampaignControl::class);
                    $control->event($grant, null, 'director_error', ['exception_class' => get_class($exception), 'message' => $message, 'consecutive' => $failures, 'agent_symbol' => $grant->resolvedAgentSymbol()]);
                    if ($failures >= 5) {
                        $control->pause($grant, 'director_error');
                    }
                }
            } catch (\Throwable) {
            }

            return true;
        }
    }

    /**
     * Arms get their own failure streak per grant: five consecutive failures pause that arm only,
     * never the main campaign, and never touch the main campaign's streak.
     */
    private function tickArms(CampaignDirector $director): bool
    {
        try {
            if ($director->tickArms()) {
                DB::table('game_runtime')->upsert([['key' => 'arm_director_failures:'.$director->armGrantId, 'value' => '0']], ['key'], ['value']);

                return true;
            }

            return false;
        } catch (\Throwable $exception) {
            $message = 'Arm director failed; exception details redacted.';
            Log::error($message, ['exception_class' => get_class($exception), 'grant' => $director->armGrantId]);
            try {
                $grant = $director->armGrantId ? CampaignGrant::find($director->armGrantId) : null;
                if ($grant) {
                    $key = 'arm_director_failures:'.$grant->id;
                    $failures = (int) DB::table('game_runtime')->where('key', $key)->value('value') + 1;
                    DB::table('game_runtime')->upsert([['key' => $key, 'value' => (string) $failures]], ['key'], ['value']);
                    $control = app(CampaignControl::class);
                    $control->event($grant, null, 'director_error', ['exception_class' => get_class($exception), 'message' => $message, 'consecutive' => $failures, 'arm' => $grant->arm()['label'] ?? null]);
                    if ($failures >= 5) {
                        $control->pause($grant, 'director_error');
                    }
                }
            } catch (\Throwable) {
            }

            return true;
        }
    }

    private function metrics(array $timing): void
    {
        try {
            app(FleetMetrics::class)->schedulerPass($timing);
        } catch (\Throwable) {
        }
    }
}
