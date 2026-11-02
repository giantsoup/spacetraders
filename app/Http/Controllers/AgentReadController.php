<?php

namespace App\Http\Controllers;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\ManualReads;
use App\Gameplay\ShipCommandExecutor;
use App\Gameplay\SpaceTradersApi;
use App\Models\Agent;
use Illuminate\Http\Request;

class AgentReadController extends Controller
{
    public function read(Request $request, Agent $agent, ShipCommandExecutor $executor, SpaceTradersApi $api, AgentDashboardController $responses)
    {
        abort_unless($agent->user_id === $request->user()->id, 404);
        $operation = $request->input('operation');
        $input = $request->validate(ManualReads::rules(is_string($operation) ? $operation : ''));

        return $responses->result(fn () => $executor->locked($agent, function () use ($agent, $api, $input) {
            app(AgentSynchronizer::class)->guardReset($agent);

            return $api->manualRead($agent, $input['operation'], $input['parameters']);
        }));
    }
}
