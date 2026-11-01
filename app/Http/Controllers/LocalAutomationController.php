<?php

namespace App\Http\Controllers;

use App\Gameplay\AutomationRunner;
use App\Gameplay\AutomationStaging;
use App\Gameplay\CreditAccounting;
use App\Gameplay\GameException;
use App\Gameplay\LocalEngineManager;
use App\Gameplay\OpportunityInvestigator;
use App\Http\Requests\LocalAutomationRequest;
use App\Models\Agent;
use App\Models\AutomationDraft;
use App\Models\OpportunityInvestigation;
use App\Models\Ship;

class LocalAutomationController extends Controller
{
    public function investigate(LocalAutomationRequest $request, Agent $agent, OpportunityInvestigator $investigator, AgentDashboardController $response)
    {
        abort_unless(Ship::where('agent_id', $agent->id)->get()->contains(fn ($s) => $s->snapshot['nav']['systemSymbol'] === $request->validated('system')), 422, 'Choose a system in the owned observed fleet.');

        return $response->result(fn () => $investigator->create($agent, $request->validated('strategy'), $request->validated('system')));
    }

    public function advance(LocalAutomationRequest $request, Agent $agent, int $investigation, OpportunityInvestigator $investigator, AgentDashboardController $response)
    {
        $record = $this->investigation($agent, $investigation);

        return $response->result(fn () => $investigator->advance($agent, $record));
    }

    public function cancel(LocalAutomationRequest $request, Agent $agent, int $investigation, OpportunityInvestigator $investigator)
    {
        $record = $this->investigation($agent, $investigation);
        $investigator->cancel($record);

        return response()->json($record->fresh(), 202);
    }

    public function stage(LocalAutomationRequest $request, Agent $agent, AutomationStaging $staging, AgentDashboardController $response, ?int $draft = null)
    {
        $investigation = $this->investigation($agent, $request->validated('investigation_id'));
        $record = $draft ? AutomationDraft::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->findOrFail($draft) : null;
        $limits = $request->validated('limits');
        foreach ($limits as $key => $value) {
            if ($key !== 'survey_signature' && $value !== null) {
                $limits[$key] = (int) $value;
            }
        }

        return $response->result(function () use ($record, $request, $staging, $agent, $investigation, $limits) {
            return $staging->save($agent, $investigation, $request->validated('candidate_id'), $limits, $record, $request->validated('revision'));
        });
    }

    public function preview(LocalAutomationRequest $request, Agent $agent, int $draft, AutomationRunner $runner, AgentDashboardController $response)
    {
        $record = AutomationDraft::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->findOrFail($draft);

        return $response->result(function () use ($request, $record, $agent, $runner) {
            if ($record->revision !== (int) $request->validated('revision')) {
                throw new GameException('Draft revision changed. Reload before previewing.');
            }

            return $runner->previewDraft($agent, $record);
        });
    }

    public function engineStart(LocalAutomationRequest $request, Agent $agent, LocalEngineManager $manager, AgentDashboardController $response)
    {
        $manager->authorize($request);

        return $response->result(fn () => $manager->start());
    }

    public function engineEnd(LocalAutomationRequest $request, Agent $agent, LocalEngineManager $manager, AgentDashboardController $response)
    {
        $manager->authorize($request);

        return $response->result(fn () => $manager->end());
    }

    public function engineForceStop(LocalAutomationRequest $request, Agent $agent, LocalEngineManager $manager, AgentDashboardController $response)
    {
        $manager->authorize($request);

        return $response->result(fn () => $manager->forceStop());
    }

    public function reviewBalance(LocalAutomationRequest $request, Agent $agent, CreditAccounting $accounting, AgentDashboardController $response)
    {
        return $response->result(fn () => $accounting->review($agent, (int) $request->validated('observed_balance'), $request->validated('note'), $request->validated('starting_balance') === null ? null : (int) $request->validated('starting_balance')));
    }

    private function investigation(Agent $agent, int $id): OpportunityInvestigation
    {
        return OpportunityInvestigation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->findOrFail($id);
    }
}
