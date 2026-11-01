<?php

namespace App\Http\Controllers;

use App\Gameplay\Adviser;
use App\Gameplay\OpportunityInvestigator;
use App\Http\Requests\AdviserRequest;
use App\Models\AdviserPolicy;
use App\Models\Agent;
use App\Models\OpportunityInvestigation;

class AdviserController extends Controller
{
    public function history(AdviserRequest $request, Agent $agent, Adviser $adviser)
    {
        $input = $request->validate(['before_decision' => ['nullable', 'integer', 'min:1'], 'before_policy' => ['nullable', 'integer', 'min:1']]);

        return response()->json($adviser->history($agent, $input['before_decision'] ?? null, $input['before_policy'] ?? null))->header('Cache-Control', 'no-store');
    }

    public function policies(AdviserRequest $request, Agent $agent, Adviser $adviser, AgentDashboardController $response)
    {
        return $response->result(fn () => $adviser->grant($agent, $request->validated()));
    }

    public function pause(AdviserRequest $request, Agent $agent, int $policy, Adviser $adviser, AgentDashboardController $response)
    {
        $record = AdviserPolicy::where('agent_id', $agent->id)->findOrFail($policy);

        return $response->result(fn () => $adviser->pause($record));
    }

    public function assess(AdviserRequest $request, Agent $agent, Adviser $adviser, AgentDashboardController $response)
    {
        $policy = AdviserPolicy::where('agent_id', $agent->id)->findOrFail($request->validated('policy_id'));
        $investigation = $request->validated('investigation_id') ? OpportunityInvestigation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->findOrFail($request->validated('investigation_id')) : null;

        return $response->result(fn () => $adviser->assess($agent, $policy, $investigation));
    }

    public function collect(AdviserRequest $request, Agent $agent, Adviser $adviser, OpportunityInvestigator $investigator, AgentDashboardController $response)
    {
        $policy = AdviserPolicy::where('agent_id', $agent->id)->findOrFail($request->validated('policy_id'));

        return $response->result(function () use ($adviser, $agent, $policy, $investigator) {
            $adviser->requireAvailable($agent, $policy);
            $record = $investigator->create($agent, 'mining', $policy->system_symbol);
            $coverage = $record->coverage;
            $coverage['request_limit'] = min($coverage['request_limit'], $policy->terms['api_request_cap']);
            $record->update(['coverage' => $coverage]);

            return $record;
        });
    }

    public function advance(AdviserRequest $request, Agent $agent, int $investigation, OpportunityInvestigator $investigator, AgentDashboardController $response)
    {
        $record = OpportunityInvestigation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->findOrFail($investigation);

        return $response->result(fn () => $investigator->advance($agent, $record));
    }

    public function cancel(AdviserRequest $request, Agent $agent, int $investigation, OpportunityInvestigator $investigator)
    {
        $record = OpportunityInvestigation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->findOrFail($investigation);
        $investigator->cancel($record);

        return response()->json($record->fresh(), 202)->header('Cache-Control', 'no-store');
    }
}
