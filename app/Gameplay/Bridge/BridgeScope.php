<?php

namespace App\Gameplay\Bridge;

use App\Gameplay\ShipPurpose;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\CampaignRevision;
use App\Models\Ship;
use Illuminate\Support\Collection;

final class BridgeScope
{
    private function __construct(
        public readonly int $userId,
        public readonly Collection $primaries,
        public readonly ?string $symbol = null,
        public readonly ?CampaignGrant $primary = null,
        public readonly ?CampaignRevision $revision = null,
        public readonly ?Agent $agent = null,
        public readonly ?string $resetId = null,
        public readonly Collection $episodes = new Collection,
        public readonly Collection $openEpisodes = new Collection,
        public readonly Collection $arms = new Collection,
        public readonly Collection $activeRuns = new Collection,
        public readonly Collection $ships = new Collection,
        public readonly array $purposes = [],
        public readonly Collection $currentAgents = new Collection,
        public readonly Collection $allArms = new Collection,
        public readonly Collection $allOpenEpisodes = new Collection,
    ) {}

    public static function resolve(int $userId, ?string $symbol): self
    {
        $replacement = CampaignGrant::from('campaign_grants as replacement')->select('replacement.id')
            ->whereColumn('replacement.user_id', 'campaign_grants.user_id')
            ->whereColumn('replacement.agent_symbol', 'campaign_grants.agent_symbol')
            ->where('replacement.slot', 2)->limit(1);
        $primaries = CampaignGrant::allPrimaries($userId)->select('campaign_grants.*')
            ->selectSub($replacement, 'pending_replacement_id')->orderBy('id')->get();
        if ($primaries->isEmpty()) {
            return new self($userId, $primaries);
        }

        $requested = strtoupper($symbol ?? '');
        $primary = $requested === ''
            ? $primaries->first()
            : $primaries->first(fn (CampaignGrant $grant) => $grant->agent_symbol === $requested);
        abort_unless($primary, 404);
        $symbol = $primary->agent_symbol;
        $symbols = $primaries->pluck('agent_symbol');
        $revision = $primary->revision();

        $currentAgents = Agent::where('user_id', $userId)->unarchived()
            ->whereIn('name', $symbols)->whereNotNull('reset_id')->where('reset_id', '!=', '')
            ->orderBy('id')->get()->keyBy('name');
        $agent = $currentAgents->get($symbol);

        $allArms = CampaignGrant::liveArms()->where('user_id', $userId)
            ->whereIn('agent_symbol', $symbols)->orderBy('id')->get()->groupBy('agent_symbol');
        $arms = $allArms->get($symbol, collect());

        $allOpenEpisodes = $currentAgents->isEmpty() ? collect() : CampaignEpisode::query()
            ->join('agents', 'agents.id', '=', 'campaign_episodes.agent_id')
            ->join('campaign_grants', 'campaign_grants.id', '=', 'campaign_episodes.campaign_grant_id')
            ->leftJoin('campaign_events as loads', fn ($join) => $join
                ->on('loads.campaign_episode_id', '=', 'campaign_episodes.id')
                ->where('loads.kind', 'load_result')->whereNull('campaign_grants.slot'))
            ->whereIn('campaign_episodes.agent_id', $currentAgents->pluck('id'))
            ->whereIn('campaign_episodes.campaign_grant_id', $primaries->pluck('id')->concat($allArms->flatten(1)->pluck('id')))
            ->whereColumn('campaign_episodes.reset_id', 'agents.reset_id')
            ->whereColumn('campaign_grants.agent_symbol', 'agents.name')
            ->whereNull('campaign_episodes.closed_at')
            ->groupBy('campaign_episodes.id')->orderBy('campaign_episodes.id')
            ->select('campaign_episodes.*')
            ->selectRaw("SUM(json_extract(loads.data, '$.net')) as recorded_load_net, MAX(loads.created_at) as last_load_at")
            ->get()->keyBy('campaign_grant_id');

        $episodes = collect();
        $openEpisodes = collect();
        $activeRuns = collect();
        $ships = collect();
        if ($agent) {
            $episodes = CampaignEpisode::where('reset_id', $agent->reset_id)
                ->whereIn('agent_id', Agent::where('user_id', $userId)->where('name', $symbol)->select('id'))
                ->whereIn('campaign_grant_id', CampaignGrant::where('user_id', $userId)->where('agent_symbol', $symbol)->select('id'))
                ->orderBy('opened_at')->orderBy('id')->get()->keyBy('id');
            $openEpisodes = $allOpenEpisodes->where('agent_id', $agent->id);
            $activeRuns = AutomationRun::where('active_agent_id', $agent->id)->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->orderBy('id')->get();
            $ships = Ship::where('agent_id', $agent->id)->orderBy('symbol')->get()->keyBy('symbol');
        }

        return new self(
            userId: $userId,
            primaries: $primaries,
            symbol: $symbol,
            primary: $primary,
            revision: $revision,
            agent: $agent,
            resetId: $agent?->reset_id,
            episodes: $episodes,
            openEpisodes: $openEpisodes,
            arms: $arms,
            activeRuns: $activeRuns,
            ships: $ships,
            purposes: $agent ? app(ShipPurpose::class)->forAgent($agent, $ships) : [],
            currentAgents: $currentAgents,
            allArms: $allArms,
            allOpenEpisodes: $allOpenEpisodes,
        );
    }
}
