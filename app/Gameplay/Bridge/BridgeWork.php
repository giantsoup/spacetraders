<?php

namespace App\Gameplay\Bridge;

use App\Enums\RunState;
use App\Models\AutomationRun;
use App\Models\CampaignGrant;
use App\Models\ShipAction;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class BridgeWork
{
    public static function grants(BridgeScope $scope): Collection
    {
        return collect([$scope->primary])->filter()->concat($scope->arms)->keyBy('id');
    }

    public static function draining(BridgeScope $scope, CampaignGrant $grant): ?string
    {
        return BridgeDrain::marker($grant, $scope->openEpisodes->get($grant->id));
    }

    public static function miningEnabled(BridgeScope $scope): bool
    {
        return collect($scope->revision?->body['phases'] ?? [])->pluck('activities')->flatten(1)->contains('strategy', 'mining');
    }

    public static function advancing(AutomationRun $run): bool
    {
        return $run->stop_requested_at === null && in_array($run->state, [RunState::Running, RunState::Waiting], true);
    }

    public static function unsettled(AutomationRun $run): bool
    {
        return $run->active_agent_id !== null && ($run->stop_requested_at !== null || in_array($run->state, [RunState::Blocked, RunState::Stopping], true));
    }

    public static function reviews(BridgeScope $scope, Collection $runs): Collection
    {
        if ($scope->agent === null || ! $runs->contains(fn (AutomationRun $run) => $run->state === RunState::Blocked)) {
            return collect();
        }

        return ShipAction::where('ship_actions.agent_id', $scope->agent->id)->where('ship_actions.reset_id', $scope->resetId)
            ->leftJoin('automation_runs as source_run', 'source_run.id', '=', 'ship_actions.automation_run_id')
            ->where(fn ($query) => $query->where('ship_actions.state', 'uncertain')->orWhere('review_required', true))
            ->get(['ship_actions.ship_symbol', 'ship_actions.state', 'review_required', 'source_run.campaign_episode_id']);
    }

    public static function settlementStatus(AutomationRun $run, Collection $reviews): string
    {
        if ($run->state === RunState::Blocked) {
            if ($reviews->contains(fn (ShipAction $action) => $action->state->value === 'uncertain')) {
                return 'Unsettled · command outcome uncertain';
            }
            if ($reviews->contains(fn (ShipAction $action) => $action->review_required
                && (in_array($action->ship_symbol, [$run->ship_symbol, null, ''], true)
                    || ($run->campaign_episode_id !== null && $run->campaign_episode_id === $action->campaign_episode_id)))) {
                return 'Unsettled · price review required';
            }

            return $run->blocked_reason ? 'Unsettled · '.Str::limit($run->blocked_reason, 120) : 'Unsettled · review required';
        }

        return $run->stop_requested_at !== null || $run->state === RunState::Stopping
            ? 'Stopping · settling commands'
            : 'Unsettled · settling commands';
    }
}
