<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\CampaignEpisode;
use App\Models\GameObservation;
use App\Models\Ship;
use App\Services\SpaceTradersClient;

final class MeshPoller
{
    public function demand(CampaignEpisode $episode, Agent $agent, ?string $buyer): array
    {
        $policy = CampaignMesh::policy($episode);
        $buyer ??= $episode->state['active_buyer'] ?? null;
        $symbols = array_unique(array_filter([$buyer, ...app(CampaignMesh::class)->demand($episode, $agent)]));

        return array_slice(array_values($symbols), 0, $policy['saturation']['demand_max']);
    }

    public function order(array $items, array $demand, ?string $buyer, array $read, ?int $readsPerHour = null): array
    {
        $demandRate = 0.0;
        $routineRate = 0.0;
        foreach ($items as $item) {
            $rate = 3600 / $item['interval'];
            if ($item['kind'] === 'market' && in_array($item['symbol'], $demand, true)) {
                $demandRate += $rate;
            } else {
                $routineRate += $rate;
            }
        }
        $residual = $readsPerHour === null ? INF : max(0, $readsPerHour * 0.85 - $demandRate);
        $due = [];
        foreach ($items as $item) {
            $symbol = $item['symbol'];
            $isMarket = $item['kind'] === 'market';
            $key = $isMarket ? $symbol : 'shipyard:'.$symbol;
            if (isset($read[$key]) || ! isset($item['observer'])) {
                continue;
            }
            $needed = $isMarket && in_array($symbol, $demand, true);
            $activeBuyer = $needed && $symbol === $buyer;
            $urgent = $item['age'] === null || $item['invalidated'];
            $interval = $item['interval'];
            if (! $needed && ! $urgent && $routineRate > $residual) {
                if ($residual <= 0) {
                    continue;
                }
                $interval = (int) min(31536000, ceil($interval * $routineRate / $residual));
            }
            if (! $urgent && $item['age'] < $interval) {
                continue;
            }
            $class = match (true) {
                $activeBuyer => 0,
                $needed => 1,
                $urgent => 2,
                default => 3,
            };
            $weight = $urgent ? 3 : ($activeBuyer ? 4 : ($needed || $item['purchase'] ? 2 : 1));
            $weightedAge = $weight * min(31536000, $item['age'] ?? 31536000);
            $due[] = compact('key', 'needed', 'class', 'weightedAge', 'interval') + $item;
        }
        usort($due, fn ($a, $b) => ($a['class'] <=> $b['class'])
            ?: (($b['weightedAge'] * $a['interval']) <=> ($a['weightedAge'] * $b['interval']))
            ?: ($a['key'] <=> $b['key']));

        return $due;
    }

    public function refresh(CampaignEpisode $episode, Agent $agent, array &$read, float $deadline, ?string $buyer = null,
        ?string $phase = null, array $purchaseYards = [], ?int $capacity = null): array
    {
        $mesh = app(CampaignMesh::class);
        $policy = CampaignMesh::policy($episode);
        $settings = $policy['saturation'];
        $model = $mesh->model($episode, $agent);
        $buyer ??= $episode->state['active_buyer'] ?? null;
        $demand = $this->demand($episode, $agent, $buyer);
        $ships = Ship::where('agent_id', $agent->id)->orderBy('symbol')->get()
            ->filter(fn ($ship) => in_array($ship->snapshot['nav']['status'] ?? null, ['DOCKED', 'IN_ORBIT'], true))
            ->groupBy('snapshot.nav.waypointSymbol');
        $observations = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->whereIn('kind', ['market', 'shipyard'])->get()->groupBy('kind');
        $markets = $observations->get('market', collect())->keyBy('symbol');
        $yards = $observations->get('shipyard', collect())->keyBy('symbol');
        $items = [];
        $marketTargets = $model->targets + $model->stationMarkets;
        foreach ($marketTargets as $symbol => $target) {
            $quote = $markets->get($symbol);
            $interval = in_array($symbol, $demand, true) ? $policy['demand_read_interval_seconds']
                : ($target['tier'] === 1 ? $policy['read_interval_seconds'] : $settings['tier2_read_interval_seconds']);
            $items[] = [
                'symbol' => $symbol, 'kind' => 'market', 'interval' => $interval, 'purchase' => false,
                'age' => empty($quote?->data['tradeGoods']) ? null : max(0, now()->timestamp - $quote->observed_at->timestamp),
                'invalidated' => isset($quote?->data['invalidated_by_action']), 'observer' => $ships->get($symbol)?->first()?->symbol,
            ];
        }
        $selector = app(ProbeYardSelector::class);
        foreach ($mesh->yards($agent, $model->system)->keys() as $symbol) {
            $purchase = in_array($symbol, $purchaseYards, true);
            $observation = $yards->get($symbol);
            $quote = $selector->quote($agent, $symbol, $observation);
            $items[] = [
                'symbol' => $symbol, 'kind' => 'shipyard',
                'interval' => $settings[$purchase ? 'purchase_yard_read_interval_seconds' : 'yard_read_interval_seconds'],
                'purchase' => $purchase, 'age' => empty($observation?->data['ships']) ? null : $quote['age'],
                'invalidated' => $quote['invalidated'], 'observer' => $ships->get($symbol)?->first()?->symbol,
            ];
        }
        $governor = app(MeshGovernor::class);
        $status = $governor->evaluate($agent, $episode);
        $due = $this->order($items, $demand, $buyer, $read, $capacity);
        $successful = [];
        $demandReads = count(array_intersect(array_keys($read), $demand));
        foreach ($due as $item) {
            if (count($read) >= $policy['reads_per_pass'] || microtime(true) >= $deadline || $status['mode'] === 'hold') {
                break;
            }
            if ($status['mode'] === 'degraded' && count($read) >= 2) {
                break;
            }
            if ($status['mode'] !== 'normal' && (! $item['needed'] || $demandReads >= 2)) {
                continue;
            }
            if ($phase === 'demand' && ! $item['needed'] || $phase === 'urgent' && $item['class'] !== 2
                || $phase === 'purchase' && ! $item['purchase']
                || $phase === 'routine' && $item['needed']) {
                continue;
            }
            try {
                $ok = $this->read($episode, $agent, $item['symbol'], $item['kind'], $item['observer'], $item['needed'] ? 'demand' : 'read', $read);
                if ($ok) {
                    $successful[] = $item['key'];
                }
                $demandReads += (int) $item['needed'];
                $status = $governor->evaluate($agent, $episode);
            } catch (RequestDeferred) {
                break;
            }
        }

        return [
            'mode' => $status['mode'], 'hold' => $status['reason'], 'demand' => $demand,
            'uncovered' => array_values(array_diff($demand, $ships->keys()->all())), 'successful' => $successful,
            'due' => array_column($due, 'key'), 'ages' => array_column(array_map(fn ($item) => $item + [
                'key' => $item['kind'] === 'shipyard' ? 'shipyard:'.$item['symbol'] : $item['symbol'],
            ], $items), 'age', 'key'),
        ];
    }

    public function read(CampaignEpisode $episode, Agent $agent, string $symbol, string $kind, ?string $observer, string $priority, array &$read): bool
    {
        $key = $kind === 'shipyard' ? 'shipyard:'.$symbol : $symbol;
        $governor = app(MeshGovernor::class);
        $limit = CampaignMesh::policy($episode)['reads_per_pass'];
        if (isset($read[$key]) || count($read) >= $limit
            || (count($read) >= 2 && $governor->evaluate($agent, $episode)['mode'] === 'degraded')) {
            return false;
        }

        $work = function () use ($agent, $symbol, $kind, $observer, $key, &$read) {
            $read[$key] = true;
            try {
                $data = FleetMetrics::as($observer, fn () => match ($kind) {
                    'shipyard' => app(SpaceTradersApi::class)->shipyard($agent, $symbol),
                    default => app(SpaceTradersApi::class)->market($agent, $symbol),
                });
                app(AgentSynchronizer::class)->observe($agent, $kind, $symbol, $data, observer: $observer);

                return $observer === null || ! empty($data[$kind === 'shipyard' ? 'ships' : 'tradeGoods']);
            } catch (GameException $exception) {
                if ($exception->retryAfter > 0 || $observer === null) {
                    throw $exception;
                }

                return false;
            }
        };

        return $priority === 'settlement'
            ? app(SpaceTradersClient::class)->scope($work, priority: 'settlement')
            : $governor->immediate($agent, $episode, $priority, $work);
    }
}
