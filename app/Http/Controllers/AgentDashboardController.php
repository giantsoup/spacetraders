<?php

namespace App\Http\Controllers;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\GameException;
use App\Gameplay\MiningPlanner;
use App\Gameplay\ShipCommandExecutor;
use App\Http\Requests\GameplayRequest;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\GameObservation;
use App\Models\Ship;
use App\Models\ShipAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AgentDashboardController extends Controller
{
    public function show(Request $request, Agent $agent, MiningPlanner $planner): Response
    {
        abort_unless($agent->user_id === $request->user()->id, 404);
        $ships = Ship::where('agent_id', $agent->id)->get();

        return Inertia::render('Agent/Dashboard', [
            'agent' => $agent, 'ships' => $ships,
            'capabilities' => $ships->mapWithKeys(fn ($s) => [$s->symbol => $planner->capability($s->snapshot)]),
            'observations' => GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->get(),
            'actions' => ShipAction::where('agent_id', $agent->id)->latest('id')->limit(40)->get(),
            'runs' => AutomationRun::where('agent_id', $agent->id)->latest('id')->limit(10)->get(),
            'health' => collect(['scheduler', 'worker'])->mapWithKeys(fn ($key) => [$key => json_decode(DB::table('game_runtime')->where('key', $key)->value('value') ?? 'null', true)]),
            'automationEnabled' => (bool) config('gameplay.enabled'),
        ]);
    }

    public function sync(GameplayRequest $request, Agent $agent, ShipCommandExecutor $executor, AgentSynchronizer $sync)
    {
        return $this->result(fn () => $executor->locked($agent, function () use ($agent, $sync) {
            $sync->sync($agent);

            return ['message' => 'Fleet and bounded local observations synchronized.'];
        }));
    }

    public function result(callable $work)
    {
        try {
            return response()->json($work(), 202)->header('Cache-Control', 'no-store');
        } catch (GameException $e) {
            return response()->json(['message' => $e->getMessage(), 'api_code' => $e->apiCode, 'retry_after' => $e->retryAfter], 422)->header('Cache-Control', 'no-store');
        }
    }
}
