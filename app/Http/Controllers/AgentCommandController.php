<?php

namespace App\Http\Controllers;

use App\Gameplay\ShipCommandExecutor;
use App\Http\Requests\GameplayRequest;
use App\Models\Agent;
use App\Models\ShipAction;

class AgentCommandController extends Controller
{
    public function preview(GameplayRequest $request, Agent $agent, ShipCommandExecutor $executor, AgentDashboardController $responses)
    {
        $data = $request->validated();
        foreach (['units', 'maxTotal', 'minTotal', 'reserve', 'maxCooldownSeconds', 'term_index', 'maxFuel', 'maxSeconds', 'protectedFuel', 'maxCargoUnits'] as $field) {
            if (isset($data['arguments'][$field])) {
                $data['arguments'][$field] = (int) $data['arguments'][$field];
            }
        }

        foreach (['fromCargo', 'require_guaranteed_price', 'acknowledgeLoss', 'acknowledgeUnknown'] as $field) {
            if (isset($data['arguments'][$field])) {
                $data['arguments'][$field] = (bool) $data['arguments'][$field];
            }
        }

        return $responses->result(fn () => $executor->preview($agent, $data['ship_symbol'] ?? '', $data['command'], $data['arguments'], $data['key']));
    }

    public function approve(GameplayRequest $request, Agent $agent, int $action, ShipCommandExecutor $executor, AgentDashboardController $responses)
    {
        $intent = ShipAction::where('agent_id', $agent->id)->findOrFail($action);

        return $responses->result(fn () => $executor->approve($agent, $intent));
    }

    public function stop(GameplayRequest $request, Agent $agent, int $action, ShipCommandExecutor $executor, AgentDashboardController $responses)
    {
        $intent = ShipAction::where('agent_id', $agent->id)->findOrFail($action);

        return $responses->result(fn () => $executor->stopManual($intent));
    }

    public function reconcile(GameplayRequest $request, Agent $agent, int $action, ShipCommandExecutor $executor, AgentDashboardController $responses)
    {
        $intent = ShipAction::where('agent_id', $agent->id)->findOrFail($action);

        return $responses->result(fn () => $executor->reconcile($agent, $intent));
    }

    public function acknowledge(GameplayRequest $request, Agent $agent, int $action, ShipCommandExecutor $executor, AgentDashboardController $responses)
    {
        $intent = ShipAction::where('agent_id', $agent->id)->findOrFail($action);

        return $responses->result(fn () => $executor->acknowledgeUncertain($agent, $intent, $request->validated('note')));
    }
}
