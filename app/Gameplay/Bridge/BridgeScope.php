<?php

namespace App\Gameplay\Bridge;

use App\Gameplay\CampaignFleet;
use App\Gameplay\CampaignProbeBootstrap;
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
        public readonly bool $primaryExpired = false,
        public readonly array $primaryRoleSymbols = [],
        public readonly Collection $handoverRuns = new Collection,
        public readonly ?Collection $handoverActions = null,
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
        $primaryExpired = $primary->pause_reason === 'grant_expired' || $primary->expires_at?->lte(now());

        $currentAgents = Agent::where('user_id', $userId)->unarchived()
            ->whereIn('name', $symbols)->whereNotNull('reset_id')->where('reset_id', '!=', '')
            ->orderBy('id')->get()->keyBy('name');
        $agent = $currentAgents->get($symbol);

        $firstReset = CampaignEpisode::select('reset_id')->whereColumn('campaign_grant_id', 'campaign_grants.id')->oldest('id')->limit(1);
        $allLiveArms = CampaignGrant::liveArms()->where('user_id', $userId)->whereIn('agent_symbol', $symbols)
            ->select('campaign_grants.*')->selectSub($firstReset, 'first_episode_reset_id')
            ->orderBy('id')->get()->groupBy('agent_symbol');
        $fleet = app(CampaignFleet::class);
        $allArms = $allLiveArms->map(function (Collection $grants, string $symbol) use ($currentAgents, $fleet) {
            $agent = $currentAgents->get($symbol);

            return $agent ? $fleet->currentArms($agent, $grants) : $grants;
        });
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
        $handoverRuns = collect();
        $handoverActions = null;
        $roles = ['command' => null, 'probes' => []];
        if ($agent) {
            $episodes = CampaignEpisode::where('reset_id', $agent->reset_id)
                ->whereIn('agent_id', Agent::where('user_id', $userId)->where('name', $symbol)->select('id'))
                ->whereIn('campaign_grant_id', CampaignGrant::where('user_id', $userId)->where('agent_symbol', $symbol)->select('id'))
                ->orderBy('opened_at')->orderBy('id')->get()->keyBy('id');
            $openEpisodes = $allOpenEpisodes->where('agent_id', $agent->id);
            $handover = $primary->pending_replacement_id !== null && $primaryExpired;
            $runs = AutomationRun::where('reset_id', $agent->reset_id)->whereNotNull('active_agent_id');
            if ($handover) {
                $handoverRuns = $runs->where(fn ($query) => $query->where('active_agent_id', $agent->id)
                    ->orWhere(fn ($query) => $query->where('agent_id', $agent->id)->whereIn('strategy', CampaignProbeBootstrap::STRATEGIES)))
                    ->orderBy('id')->get();
                $activeRuns = $handoverRuns->where('active_agent_id', $agent->id)->where('agent_id', $agent->id);
                $handoverActions = BridgeWork::actions($agent, includePending: true);
            } else {
                $activeRuns = $runs->where('active_agent_id', $agent->id)->where('agent_id', $agent->id)->orderBy('id')->get();
            }
            $ships = Ship::where('agent_id', $agent->id)->orderBy('symbol')->get()->keyBy('symbol');
            $episode = $openEpisodes->get($primary->id);
            if ($ships->isNotEmpty() && ($episode || $handover)) {
                $roles = $fleet->primaryRoleSymbols($agent, $episode ?? new CampaignEpisode([
                    'campaign_grant_id' => $primary->id, 'agent_id' => $agent->id, 'reset_id' => $agent->reset_id,
                ]), $primary, $revision, $ships, $allLiveArms->get($symbol, collect()));
            }
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
            purposes: $agent ? app(ShipPurpose::class)->forAgent($agent, $ships, $primary, $openEpisodes, $activeRuns, $arms, $roles['probes']) : [],
            currentAgents: $currentAgents,
            allArms: $allArms,
            allOpenEpisodes: $allOpenEpisodes,
            primaryExpired: $primaryExpired,
            primaryRoleSymbols: array_values(array_unique(array_filter([$roles['command'], ...$roles['probes']]))),
            handoverRuns: $handoverRuns,
            handoverActions: $handoverActions,
        );
    }
}
