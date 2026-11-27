<?php

namespace App\Gameplay\Bridge;

use App\Enums\ActionState;
use App\Enums\RunState;
use App\Gameplay\CampaignProbeBootstrap;
use App\Models\Agent;
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
        if ($scope->agent === null || ! $runs->contains(self::unsettled(...))) {
            return collect();
        }

        return $scope->handoverActions?->filter(fn (ShipAction $action) => $action->state === ActionState::Uncertain || $action->review_required)
            ?? self::actions($scope->agent);
    }

    public static function actions(Agent $agent, bool $includePending = false): Collection
    {
        return ShipAction::where('ship_actions.agent_id', $agent->id)->where('ship_actions.reset_id', $agent->reset_id)
            ->leftJoin('automation_runs as source_run', 'source_run.id', '=', 'ship_actions.automation_run_id')
            ->where(fn ($query) => $query->whereIn('ship_actions.state', $includePending ? ['pending', 'sending', 'uncertain'] : ['uncertain'])
                ->orWhere('review_required', true))
            ->get(['ship_actions.ship_symbol', 'ship_actions.state', 'review_required', 'source_run.campaign_episode_id',
                'ship_actions.command', 'ship_actions.arguments']);
    }

    public static function hasHandoverWork(BridgeScope $scope): bool
    {
        if ($scope->agent === null) {
            return false;
        }

        $symbols = $scope->primaryRoleSymbols;
        foreach ($symbols as $symbol) {
            $snapshot = $scope->ships->get($symbol)?->snapshot;
            if (($snapshot['cargo']['units'] ?? 0) > 0 || ($snapshot['nav']['status'] ?? null) === 'IN_TRANSIT') {
                return true;
            }
        }

        $agentId = $scope->agent->id;
        $openId = $scope->openEpisodes->get($scope->primary->id)?->id;
        $episodeIds = $scope->episodes->where('campaign_grant_id', $scope->primary->id)->pluck('id');
        if ($scope->handoverRuns->contains(function (AutomationRun $run) use ($agentId, $openId, $episodeIds, $symbols) {
            $purchase = $run->agent_id === $agentId && in_array($run->strategy, CampaignProbeBootstrap::STRATEGIES, true);
            $roleWork = in_array($run->ship_symbol, $symbols, true) || ($openId !== null && $run->campaign_episode_id === $openId);
            $campaignWork = $run->agent_id === $agentId && $episodeIds->containsStrict($run->campaign_episode_id);

            return $purchase || ($run->active_agent_id === $agentId && ($roleWork || $campaignWork));
        })) {
            return true;
        }

        return $scope->handoverActions?->contains(function (ShipAction $action) use ($openId, $episodeIds, $symbols) {
            $pending = in_array($action->state, [ActionState::Pending, ActionState::Sending], true);

            return ($pending || $action->state === ActionState::Uncertain)
                && (in_array($action->ship_symbol, $symbols, true)
                    || ($openId !== null && $action->campaign_episode_id === $openId)
                    || ($pending && $episodeIds->containsStrict($action->campaign_episode_id))
                    || ($action->command === 'buy_ship' && in_array($action->arguments['shipType'] ?? null, CampaignProbeBootstrap::PURCHASE_TYPES, true)));
        }) ?? false;
    }

    public static function settlementStatus(AutomationRun $run, Collection $reviews): string
    {
        if (self::unsettled($run)) {
            if ($reviews->contains(fn (ShipAction $action) => $action->state->value === 'uncertain')) {
                return 'Unsettled · command outcome uncertain';
            }
            if ($reviews->contains(fn (ShipAction $action) => $action->review_required
                && (in_array($action->ship_symbol, [$run->ship_symbol, null, ''], true)
                    || ($run->campaign_episode_id !== null && $run->campaign_episode_id === $action->campaign_episode_id)))) {
                return 'Unsettled · price review required';
            }
        }

        if ($run->state === RunState::Blocked) {
            return $run->blocked_reason ? 'Unsettled · '.Str::limit($run->blocked_reason, 120) : 'Unsettled · review required';
        }

        return $run->stop_requested_at !== null || $run->state === RunState::Stopping
            ? 'Stopping · settling commands'
            : 'Unsettled · settling commands';
    }
}
