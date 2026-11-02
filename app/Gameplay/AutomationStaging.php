<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationDraft;
use App\Models\OpportunityInvestigation;
use Carbon\CarbonImmutable;

class AutomationStaging
{
    public function __construct(private OpportunityEvaluator $evaluator, private ShipCommandExecutor $executor) {}

    public function save(Agent $agent, OpportunityInvestigation $investigation, string $candidateId, array $limits, ?AutomationDraft $draft = null, ?int $revision = null): AutomationDraft
    {
        return $this->executor->locked($agent, function () use ($agent, $investigation, $candidateId, $limits, $draft, $revision) {
            if ($agent->isArchived() || $investigation->reset_id !== $agent->reset_id || ! in_array($investigation->status, ['complete', 'partial'], true) || ! $investigation->finished_at || $investigation->finished_at->lt(now()->subSeconds(config('gameplay.snapshot_seconds')))) {
                throw new GameException('Investigation is unfinished, stale or from another reset. Investigate again.');
            }
            $candidates = $this->evaluator->evaluate($investigation->observations, $investigation->strategy, CarbonImmutable::now());
            $candidate = collect($candidates)->firstWhere('id', $candidateId);
            if (! $candidate || $candidate['classification'] !== 'ready') {
                throw new GameException('This observed candidate cannot be staged. Complete its prerequisites first.');
            }
            if ($draft) {
                $draft->refresh();
                if ($draft->revision !== $revision) {
                    throw new GameException('Draft revision changed. Reload before editing.');
                }
                $draft->update(['investigation_id' => $investigation->id, 'candidate' => $candidate, 'limits' => $limits, 'revision' => $draft->revision + 1]);

                return $draft;
            }

            return AutomationDraft::create(['agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'investigation_id' => $investigation->id, 'plan_version' => 2, 'candidate' => $candidate, 'limits' => $limits]);
        });
    }
}
