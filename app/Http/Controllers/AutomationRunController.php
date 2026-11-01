<?php

namespace App\Http\Controllers;

use App\Gameplay\AutomationRunner;
use App\Http\Requests\GameplayRequest;
use App\Models\Agent;
use App\Models\AutomationRun;

class AutomationRunController extends Controller
{
    public function preview(GameplayRequest $request, Agent $agent, AutomationRunner $runner, AgentDashboardController $responses)
    {
        $limits = $request->safe()->except('ship_symbol');
        foreach (['reserve', 'max_spend', 'max_loss', 'minutes', 'cycles', 'max_fuel', 'max_visits', 'units', 'max_inventory', 'min_margin', 'min_sell_price', 'max_extractions', 'extraction_headroom', 'site_seconds'] as $key) {
            if (isset($limits[$key])) {
                $limits[$key] = (int) $limits[$key];
            }
        }

        return $responses->result(fn () => $runner->preview($agent, $request->validated('ship_symbol'), $limits));
    }

    public function start(GameplayRequest $request, Agent $agent, int $run, AutomationRunner $runner, AgentDashboardController $responses)
    {
        $record = AutomationRun::where('agent_id', $agent->id)->findOrFail($run);

        return $responses->result(fn () => $runner->start($agent, $record, (bool) $request->validated('accept_contract', false)));
    }

    public function stop(GameplayRequest $request, Agent $agent, int $run, AutomationRunner $runner, AgentDashboardController $responses)
    {
        $record = AutomationRun::where('agent_id', $agent->id)->findOrFail($run);
        $runner->stop($record);

        return response()->json(['message' => 'Stop recorded. Any reserved action must settle before takeover.', 'run' => $record->fresh()], 202);
    }
}
