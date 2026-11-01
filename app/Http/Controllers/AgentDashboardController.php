<?php

namespace App\Http\Controllers;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\CreditAccounting;
use App\Gameplay\GameException;
use App\Gameplay\LocalEngineManager;
use App\Gameplay\MiningPlanner;
use App\Gameplay\OpportunityAssessor;
use App\Gameplay\ShipCommandExecutor;
use App\Http\Requests\GameplayRequest;
use App\Models\Agent;
use App\Models\AutomationDraft;
use App\Models\AutomationRun;
use App\Models\GameObservation;
use App\Models\OpportunityInvestigation;
use App\Models\Ship;
use App\Models\ShipAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AgentDashboardController extends Controller
{
    public function show(Request $request, Agent $agent, MiningPlanner $planner, OpportunityAssessor $assessor): Response
    {
        abort_unless($agent->user_id === $request->user()->id, 404);
        $ships = Ship::where('agent_id', $agent->id)->get();
        $observations = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->get();

        return Inertia::render('Agent/Dashboard', [
            'agent' => $agent, 'ships' => $ships,
            'capabilities' => $ships->mapWithKeys(fn ($s) => [$s->symbol => $planner->capability($s->snapshot)]),
            'observations' => $observations,
            'intelligence' => $assessor->report($agent),
            'guidedOffers' => $observations->where('kind', 'shipyard')->flatMap(fn ($yard) => collect($yard->data['ships'] ?? [])
                ->filter(fn ($offer) => $planner->capability($offer, true) === null)
                ->map(fn ($offer) => ['yard' => $yard->symbol, 'configuration' => $offer]))->values(),
            'actions' => ShipAction::where('agent_id', $agent->id)->latest('id')->limit(40)->get(),
            'runs' => AutomationRun::where('agent_id', $agent->id)->latest('id')->limit(10)->get(),
            'health' => collect(['scheduler', 'worker'])->mapWithKeys(fn ($key) => [$key => json_decode(DB::table('game_runtime')->where('key', $key)->value('value') ?? 'null', true)]),
            'automationEnabled' => (bool) config('gameplay.enabled'),
            'localAutomation' => (bool) config('gameplay.local_engine_enabled'),
            'engine' => config('gameplay.local_engine_enabled') ? app(LocalEngineManager::class)->status() : null,
            'engineOperator' => (string) $request->user()->id === (string) config('gameplay.operator_id'),
            'investigations' => OpportunityInvestigation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->latest('id')->limit(5)->get(),
            'drafts' => AutomationDraft::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->latest('id')->limit(10)->get(),
            'accounting' => app(CreditAccounting::class)->summary($agent),
            'synthetic' => (bool) config('gameplay.fixture_path'),
        ]);
    }

    public function history(Request $request, Agent $agent)
    {
        abort_unless($agent->user_id === $request->user()->id, 404);
        $input = $request->validate(['before' => ['required', 'integer', 'min:1']]);
        $actions = ShipAction::where('agent_id', $agent->id)->where('id', '<', $input['before'])->latest('id')->limit(41)->get();

        return response()->json(['actions' => $actions->take(40)->values(), 'has_more' => $actions->count() > 40])->header('Cache-Control', 'no-store');
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
