<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\CampaignEpisode;
use App\Models\GameObservation;
use App\Models\Ship;
use Carbon\CarbonImmutable;

class CampaignDiscovery
{
    public function advance(CampaignEpisode $episode, Agent $agent, array &$reads = [], float $deadline = INF, ?array &$detailedRead = null): void
    {
        $state = $episode->state ?? [];
        $cursor = $state['discovery'] ?? ['systems' => Ship::where('agent_id', $agent->id)->get()->map(fn ($ship) => $ship->snapshot['nav']['systemSymbol'])->unique()->take(3)->values()->all(), 'system' => 0, 'page' => 1];
        if (CampaignMesh::policy($episode) && ! isset($state['discovery'])) {
            $system = app(CampaignFleet::class)->role($episode, 'command', pin: false)['nav']['systemSymbol'] ?? null;
            $catalogue = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'catalogue')
                ->where('symbol', $system)->exists();
            $cursor['systems'] = $system !== null && ! $catalogue ? [$system] : [];
        }
        $api = app(SpaceTradersApi::class);
        $sync = app(AgentSynchronizer::class);
        if (isset($cursor['systems'][$cursor['system']])) {
            $system = $cursor['systems'][$cursor['system']];
            try {
                $work = fn () => $api->cataloguePage($agent, $system, $cursor['page']);
                $page = isset(CampaignMesh::policy($episode)['saturation'])
                    ? app(MeshGovernor::class)->immediate($agent, $episode, 'directory', $work, $deadline) : $work();
            } catch (RequestDeferred $exception) {
                if (! isset(CampaignMesh::policy($episode)['saturation'])) {
                    throw $exception;
                }

                return;
            }
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
        } elseif ($mesh = CampaignMesh::policy($episode)) {
            $this->readDirectories($episode, $agent, $reads, $mesh['reads_per_pass'], $deadline, $detailedRead);

            return;
        } else {
            if ($episode->phase === 'earn' && ($cursor['market_scope'] ?? null) !== 'occupied') {
                $occupied = Ship::where('agent_id', $agent->id)->get()->filter(fn ($ship) => $ship->snapshot['nav']['status'] !== 'IN_TRANSIT')->map(fn ($ship) => $ship->snapshot['nav']['waypointSymbol'])->all();
                $cursor['markets'] = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'waypoint')->whereIn('symbol', $occupied)->get()
                    ->filter(fn ($point) => in_array('MARKETPLACE', array_column($point->data['traits'] ?? [], 'symbol'), true))->pluck('symbol')->all();
                $cursor['market'] = 0;
                $cursor['market_scope'] = 'occupied';
            }
            $cursor['markets'] ??= GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'waypoint')->get()
                ->filter(fn ($point) => in_array('MARKETPLACE', array_column($point->data['traits'] ?? [], 'symbol'), true))->pluck('symbol')->all();
            $cursor['market'] ??= 0;
            for ($count = 0; $count < 2 && isset($cursor['markets'][$cursor['market']]); $count++) {
                $symbol = $cursor['markets'][$cursor['market']];
                $present = $episode->phase !== 'earn' || Ship::where('agent_id', $agent->id)->get()->contains(fn ($ship) => $ship->snapshot['nav']['waypointSymbol'] === $symbol && $ship->snapshot['nav']['status'] !== 'IN_TRANSIT');
                if ($present) {
                    $reads[$symbol] = true;
                    $market = $api->market($agent, $symbol);
                    $sync->observe($agent, 'market', $symbol, $market);
                    if (! empty($market['tradeGoods'])) {
                        $detailedRead[$symbol] = true;
                    }
                }
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

    public function directoryGaps(CampaignEpisode $episode, Agent $agent, bool $includeBackoff = false): array
    {
        $system = app(CampaignFleet::class)->role($episode, 'command', pin: false)['nav']['systemSymbol'] ?? null;
        if ($system === null) {
            return [];
        }
        $query = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id);
        $listed = (clone $query)->where('kind', 'market_listing')->pluck('symbol')->flip();
        $failures = $episode->state['directory_failures'] ?? [];
        $gaps = [];
        foreach ((clone $query)->where('kind', 'waypoint')->where('data->systemSymbol', $system)->orderBy('symbol')->get() as $waypoint) {
            $symbol = $waypoint->symbol;
            if ($listed->has($symbol) || ! in_array('MARKETPLACE', array_column($waypoint->data['traits'] ?? [], 'symbol'), true)) {
                continue;
            }
            $failure = $failures[$symbol] ?? [];
            if ($includeBackoff || ! isset($failure['retry_at']) || CarbonImmutable::parse($failure['retry_at'])->lte(now())) {
                $gaps[] = ['waypoint' => $symbol, 'reason' => $failure['reason'] ?? 'missing_market_listing',
                    'retry_at' => $failure['retry_at'] ?? null, 'failures' => $failure['count'] ?? 0];
            }
        }

        return $gaps;
    }

    private function readDirectories(CampaignEpisode $episode, Agent $agent, array &$reads, int $budget, float $deadline, ?array &$detailedRead = null): void
    {
        $state = $episode->state ?? [];
        $failures = $state['directory_failures'] ?? [];
        $directories = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'market_listing')->pluck('symbol');
        foreach ($directories as $symbol) {
            unset($failures[$symbol]);
        }
        $gaps = $this->directoryGaps($episode, $agent);
        foreach (array_slice($gaps, 0, 2) as $gap) {
            if (count($reads) >= $budget || microtime(true) >= $deadline) {
                break;
            }
            $symbol = $gap['waypoint'];
            if (isset($reads[$symbol])) {
                continue;
            }
            try {
                if (isset(CampaignMesh::policy($episode)['saturation'])) {
                    if (! app(MeshPoller::class)->read($episode, $agent, $symbol, 'market', null, 'directory', $reads, $detailedRead, $deadline)) {
                        continue;
                    }
                } else {
                    $reads[$symbol] = true;
                    $market = app(SpaceTradersApi::class)->market($agent, $symbol);
                    app(AgentSynchronizer::class)->observe($agent, 'market', $symbol, $market);
                    if (! empty($market['tradeGoods'])) {
                        $detailedRead[$symbol] = true;
                    }
                }
                unset($failures[$symbol]);
            } catch (RequestDeferred) {
                break;
            } catch (GameException $exception) {
                if ($exception->retryAfter > 0 && ! in_array($exception->httpStatus, [400, 403, 404, 422], true)) {
                    break;
                }
                $count = ($failures[$symbol]['count'] ?? 0) + 1;
                $failures[$symbol] = ['count' => $count, 'reason' => $exception->getMessage()]
                    + ($count >= 2 ? ['retry_at' => now()->addMinutes(30)->toIso8601String()] : []);
            }
        }
        unset($state['discovery']);
        $state['directory_failures'] = $failures;
        $state['last_discovery_at'] = now()->toIso8601String();
        $episode->update(['state' => $state]);
    }
}
