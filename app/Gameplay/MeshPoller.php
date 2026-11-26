<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\CampaignEpisode;
use App\Models\GameObservation;
use App\Models\Ship;
use App\Services\SpaceTradersClient;
use Illuminate\Support\Collection;

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
        ?string $phase = null, array $purchaseYards = [], ?int $capacity = null, ?array &$detailedRead = null, ?array &$exceptionReport = null): array
    {
        $started = microtime(true);
        $readsBefore = count($read);
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
        $exit = ['reason' => 'completed'];
        $stopIndex = null;
        $stoppedAt = null;
        $attempted = 0;
        $filtered = ['mode' => 0, 'phase' => 0];
        $readFalse = $outcomes = [];
        $error = null;
        foreach ($due as $index => $item) {
            $key = $item['key'];
            $reason = match (true) {
                count($read) >= $policy['reads_per_pass'] => 'read_cap',
                microtime(true) >= $deadline => 'deadline',
                $status['mode'] === 'hold' => 'governor_hold',
                $status['mode'] === 'degraded' && count($read) >= 2 => 'degraded_cap',
                default => null,
            };
            if ($reason !== null) {
                $exit = ['reason' => $reason];
                $stopIndex = $index;
                $stoppedAt = $this->targetSummary($item);
                $outcomes[$key] = 'stopped';
                break;
            }
            if (! $this->allowedByMode($item, $status, $demandReads)) {
                $filtered['mode']++;
                $outcomes[$key] = 'mode';

                continue;
            }
            if (! $this->matchesPhase($item, $phase)) {
                $filtered['phase']++;
                $outcomes[$key] = 'phase';

                continue;
            }
            $attempted++;
            try {
                $ok = $this->read($episode, $agent, $item['symbol'], $item['kind'], $item['observer'], $item['needed'] ? 'demand' : 'read', $read, $detailedRead, $deadline);
                $outcomes[$key] = $ok ? 'ok' : 'read_false';
                if ($ok) {
                    $successful[] = $key;
                } elseif (count($readFalse) < 5) {
                    $readFalse[] = $this->targetSummary($item);
                }
                $demandReads += (int) $item['needed'];
                $status = $governor->evaluate($agent, $episode);
            } catch (RequestDeferred $exception) {
                $stopIndex = $index;
                $stoppedAt = $this->targetSummary($item);
                $outcomes[$key] = 'deferred';
                $exit = ['reason' => 'request_deferred', 'defer_source' => $exception->delaySource, 'retry_after' => $exception->retryAfter,
                    'deadline_capped' => $exception->deadlineCapped, 'defer_context' => $exception->context];
                break;
            } catch (GameException $exception) {
                $stopIndex = $index;
                $stoppedAt = $this->targetSummary($item);
                $outcomes[$key] = 'exception';
                $exit = ['reason' => 'exception', 'exception_class' => $exception::class, 'retry_after' => $exception->retryAfter,
                    'api_code' => $exception->apiCode, 'http_status' => $exception->httpStatus, 'message' => $exception->getMessage(),
                    'partial_successful' => $successful];
                $error = $exception;
                break;
            }
        }

        $result = $this->buildResult($status, $demand, $ships, $successful, $due, $items, $exit, $phase, $stopIndex, $stoppedAt,
            $started, $deadline, $readsBefore, count($read), $policy['reads_per_pass'], $attempted, $filtered, $readFalse, $outcomes);
        if ($error !== null) {
            $exceptionReport = $result['exit'];
            throw $error;
        }

        return $result;
    }

    private function targetSummary(array $item): array
    {
        return array_intersect_key($item, array_flip(['key', 'kind', 'class', 'age', 'interval', 'needed']));
    }

    private function matchesPhase(array $item, ?string $phase): bool
    {
        return match ($phase) {
            'demand' => $item['needed'],
            'urgent' => $item['class'] === 2,
            'purchase' => $item['purchase'],
            'routine' => ! $item['needed'],
            default => true,
        };
    }

    private function allowedByMode(array $item, array $status, int $demandReads): bool
    {
        return $status['mode'] === 'normal' || ($item['needed'] && $demandReads < 2);
    }

    private function buildResult(array $status, array $demand, Collection $ships, array $successful, array $due, array $items,
        array $exit, ?string $phase, ?int $stopIndex, ?array $stoppedAt, float $started, float $deadline, int $readsBefore,
        int $readsAfter, int $readsCap, int $attempted, array $filtered, array $readFalse, array $outcomes): array
    {
        foreach (array_slice($due, 0, 16) as $item) {
            $outcomes[$item['key']] ??= 'not_reached';
        }
        $next = null;
        if ($stopIndex !== null) {
            foreach (array_slice($due, $stopIndex) as $item) {
                if ($this->matchesPhase($item, $phase)) {
                    $next = $this->targetSummary($item);
                    break;
                }
            }
        }
        $finished = microtime(true);

        return [
            'mode' => $status['mode'], 'hold' => $status['reason'], 'demand' => $demand,
            'uncovered' => array_values(array_diff($demand, $ships->keys()->all())), 'successful' => $successful,
            'due' => array_column($due, 'key'), 'ages' => array_column(array_map(fn ($item) => $item + [
                'key' => $item['kind'] === 'shipyard' ? 'shipyard:'.$item['symbol'] : $item['symbol'],
            ], $items), 'age', 'key'),
            'exit' => $exit + [
                'stage' => $phase, 'stopped_at' => $stoppedAt, 'next' => $next,
                'elapsed_ms' => (int) round(($finished - $started) * 1000),
                'remaining_ms' => is_finite($deadline) ? max(0, (int) round(($deadline - $finished) * 1000)) : null,
                'reads_before' => $readsBefore, 'reads_after' => $readsAfter, 'reads_cap' => $readsCap,
                'attempted' => $attempted, 'due_count' => count($due), 'filtered' => $filtered, 'read_false' => $readFalse,
                'outcomes' => $outcomes, 'governor' => array_intersect_key($status, array_flip(['mode', 'reason', 'cause', 'room', 'minuteRoom'])),
            ],
        ];
    }

    public function read(CampaignEpisode $episode, Agent $agent, string $symbol, string $kind, ?string $observer, string $priority, array &$read, ?array &$detailedRead = null, ?float $deadline = null): bool
    {
        $key = $kind === 'shipyard' ? 'shipyard:'.$symbol : $symbol;
        $governor = app(MeshGovernor::class);
        $limit = CampaignMesh::policy($episode)['reads_per_pass'];
        if (isset($read[$key]) || count($read) >= $limit
            || (count($read) >= 2 && $governor->evaluate($agent, $episode)['mode'] === 'degraded')) {
            return false;
        }

        $work = function () use ($agent, $symbol, $kind, $observer, $key, &$read, &$detailedRead) {
            $read[$key] = true;
            try {
                $data = FleetMetrics::as($observer, fn () => match ($kind) {
                    'shipyard' => app(SpaceTradersApi::class)->shipyard($agent, $symbol),
                    default => app(SpaceTradersApi::class)->market($agent, $symbol),
                });
                app(AgentSynchronizer::class)->observe($agent, $kind, $symbol, $data, observer: $observer);
                if ($kind === 'market' && ! empty($data['tradeGoods'])) {
                    $detailedRead[$symbol] = true;
                }

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
            : $governor->immediate($agent, $episode, $priority, $work, $deadline);
    }
}
