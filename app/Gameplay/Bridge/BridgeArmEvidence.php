<?php

namespace App\Gameplay\Bridge;

use App\Gameplay\CampaignFleet;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\Ship;
use App\Models\ShipAction;
use Illuminate\Support\Collection;

class BridgeArmEvidence
{
    private Collection $episodes;

    private Collection $runs;

    private Collection $actions;

    private array $ships;

    public function __construct(Agent $agent, Collection $arms)
    {
        $this->episodes = CampaignEpisode::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->whereIn('campaign_grant_id', $arms->pluck('id'))->pluck('campaign_grant_id', 'id');
        $this->runs = AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->whereIn('campaign_episode_id', $this->episodes->keys())
            ->where(fn ($query) => $query->whereNotNull('active_agent_id')->orWhere('state', 'preview'))
            ->oldest('id')->get();
        $this->actions = ShipAction::where('ship_actions.agent_id', $agent->id)->where('ship_actions.reset_id', $agent->reset_id)
            ->where(fn ($query) => $query->whereIn('ship_actions.state', ['pending', 'sending', 'uncertain'])->orWhere('review_required', true))
            ->leftJoin('automation_runs as run', fn ($join) => $join->on('run.id', '=', 'ship_actions.automation_run_id')
                ->where('run.agent_id', $agent->id)->where('run.reset_id', $agent->reset_id))
            ->orderBy('ship_actions.id')->get(['ship_actions.*', 'run.campaign_episode_id as related_episode_id']);
        $this->ships = Ship::where('agent_id', $agent->id)->pluck('snapshot', 'symbol')->all();
    }

    public function forGrant(CampaignGrant $grant, ?CampaignEpisode $episode, bool $draining): array
    {
        $approvedShip = $grant->arm()['ship'];
        $command = $episode ? app(CampaignFleet::class)->armShip($episode, $grant) : $approvedShip;
        $belongs = fn ($episodeId) => $episode ? $episodeId === $episode->id : $this->episodes->get($episodeId) === $grant->id;
        $runs = $this->runs->filter(fn ($run) => $belongs($run->campaign_episode_id));
        $run = $runs->first(fn ($run) => $run->active_agent_id && ($draining || $run->stop_requested_at));
        $runActions = $run ? $this->actions->filter(fn ($action) => $action->automation_run_id === $run->id || $action->ship_symbol === $run->ship_symbol) : collect();
        $heldPrice = $this->actions->contains(fn ($action) => $action->review_required
            && (in_array($action->ship_symbol, [null, '', $command], true) || $belongs($action->related_episode_id)));
        $pending = $draining ? $this->actions->first(fn ($action) => in_array($action->state, ['pending', 'sending'], true)
            && ($action->ship_symbol === $approvedShip || $belongs($action->related_episode_id))) : null;
        $preview = $draining && $episode ? $runs->first(fn ($run) => $run->state->value === 'preview') : null;
        $recovery = ['ships' => [$command => $this->ships[$command] ?? null], 'commandIdentified' => true];

        return compact('run', 'runActions', 'heldPrice', 'pending', 'preview', 'recovery');
    }
}
