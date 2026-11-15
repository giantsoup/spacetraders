<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\CampaignEpisode;
use App\Models\GameObservation;
use App\Models\Ship;

class CampaignDiscovery
{
    public function advance(CampaignEpisode $episode, Agent $agent): void
    {
        $state = $episode->state ?? [];
        $cursor = $state['discovery'] ?? ['systems' => Ship::where('agent_id', $agent->id)->get()->map(fn ($ship) => $ship->snapshot['nav']['systemSymbol'])->unique()->take(3)->values()->all(), 'system' => 0, 'page' => 1];
        $api = app(SpaceTradersApi::class);
        $sync = app(AgentSynchronizer::class);
        if (isset($cursor['systems'][$cursor['system']])) {
            $system = $cursor['systems'][$cursor['system']];
            $page = $api->cataloguePage($agent, $system, $cursor['page']);
            foreach ($page['rows'] as $point) {
                $sync->observe($agent, 'waypoint', $point['symbol'], $point, 'listing');
            }
            if ($page['total'] <= $cursor['page'] * 20 || $cursor['page'] >= config('gameplay.max_pages')) {
                $sync->observe($agent, 'catalogue', $system, ['complete' => $page['total'] <= $cursor['page'] * 20, 'total' => $page['total']], 'listing');
                $cursor['system']++;
                $cursor['page'] = 1;
            } else {
                $cursor['page']++;
            }
        } else {
            $cursor['markets'] ??= GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'waypoint')->get()
                ->filter(fn ($point) => in_array('MARKETPLACE', array_column($point->data['traits'] ?? [], 'symbol'), true))->pluck('symbol')->all();
            $cursor['market'] ??= 0;
            for ($reads = 0; $reads < 2 && isset($cursor['markets'][$cursor['market']]); $reads++) {
                $symbol = $cursor['markets'][$cursor['market']];
                $sync->observe($agent, 'market', $symbol, $api->market($agent, $symbol));
                $cursor['market']++;
                $state['discovery'] = $cursor;
                $episode->update(['state' => $state]);
            }
            if ($cursor['market'] >= count($cursor['markets'])) {
                unset($state['discovery']);
                $state['last_discovery_at'] = now()->toIso8601String();
                $episode->update(['state' => $state]);

                return;
            }
        }
        $state['discovery'] = $cursor;
        $episode->update(['state' => $state]);
    }
}
