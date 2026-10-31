<?php

namespace App\Http\Controllers;

use App\Enums\ActionState;
use App\Gameplay\GameException;
use App\Gameplay\ShipCommandExecutor;
use App\Http\Requests\GameplayRequest;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\ShipAction;

class AgentCommandController extends Controller
{
    public function preview(GameplayRequest $request, Agent $agent, ShipCommandExecutor $executor, AgentDashboardController $responses)
    {
        $data = $request->validated();

        return $responses->result(fn () => $executor->preview($agent, $data['ship_symbol'], $data['command'], $data['arguments'], $data['key']));
    }

    public function approve(GameplayRequest $request, Agent $agent, int $action, ShipCommandExecutor $executor, AgentDashboardController $responses)
    {
        $intent = ShipAction::where('agent_id', $agent->id)->findOrFail($action);

        return $responses->result(fn () => $executor->approve($agent, $intent));
    }

    public function reconcile(GameplayRequest $request, Agent $agent, int $action, ShipCommandExecutor $executor, AgentDashboardController $responses)
    {
        $intent = ShipAction::where('agent_id', $agent->id)->findOrFail($action);

        return $responses->result(fn () => $executor->reconcile($agent, $intent));
    }

    public function acknowledge(GameplayRequest $request, Agent $agent, int $action, ShipCommandExecutor $executor, AgentDashboardController $responses)
    {
        $intent = ShipAction::where('agent_id', $agent->id)->findOrFail($action);

        return $responses->result(fn () => $executor->locked($agent, function () use ($request, $intent) {
            $intent->refresh();
            if ($intent->state !== ActionState::Uncertain || empty($intent->outcome['reconciled_at']) || now()->subMinutes(3)->greaterThan($intent->outcome['reconciled_at'])) {
                throw new GameException('Reconcile fresh upstream evidence first.');
            }
            $intent->update(['state' => ActionState::Rejected, 'message' => 'Operator acknowledged unresolved outcome; never replay this intent.',
                'outcome' => array_merge($intent->outcome, ['operator_note' => $request->validated('note'), 'unresolved_adjustment' => true])]);
            AutomationRun::where('agent_id', $intent->agent_id)->whereNotNull('active_agent_id')->update(['state' => 'stopped', 'stop_requested_at' => now(), 'active_agent_id' => null, 'blocked_reason' => 'Unresolved adjustment acknowledged. New preview required.']);

            return $intent;
        }));
    }
}
