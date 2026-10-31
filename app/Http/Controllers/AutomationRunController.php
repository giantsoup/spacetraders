<?php

namespace App\Http\Controllers;

use App\Gameplay\MiningRunner;
use App\Http\Requests\GameplayRequest;
use App\Models\Agent;
use App\Models\AutomationRun;

class AutomationRunController extends Controller
{
    public function preview(GameplayRequest $request, Agent $agent, MiningRunner $runner, AgentDashboardController $responses)
    {
        return $responses->result(fn () => $runner->preview($agent, $request->validated('ship_symbol'), $request->safe()->except('ship_symbol')));
    }

    public function start(GameplayRequest $request, Agent $agent, int $run, MiningRunner $runner, AgentDashboardController $responses)
    {
        $record = AutomationRun::where('agent_id', $agent->id)->findOrFail($run);

        return $responses->result(fn () => $runner->start($agent, $record));
    }

    public function stop(GameplayRequest $request, Agent $agent, int $run, MiningRunner $runner, AgentDashboardController $responses)
    {
        $record = AutomationRun::where('agent_id', $agent->id)->findOrFail($run);
        $runner->stop($record);

        return response()->json(['message' => 'Stop recorded. Any reserved action must settle before takeover.', 'run' => $record->fresh()], 202);
    }
}
