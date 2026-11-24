<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\OpportunityInvestigation;
use Carbon\CarbonImmutable;

class OpportunityInvestigator
{
    public function __construct(private SpaceTradersApi $api, private AgentSynchronizer $sync, private ShipCommandExecutor $executor, private OpportunityEvaluator $evaluator) {}

    public function create(Agent $agent, string $strategy, string $system, array $focus = []): OpportunityInvestigation
    {
        $focused = $focus === [] ? [] : ['focus' => $focus];

        return OpportunityInvestigation::create(['agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'strategy' => $strategy, 'system_symbol' => $system, 'status' => 'investigating',
            'coverage' => ['phase' => 'agent', 'requests' => 0, 'request_limit' => config('gameplay.investigation_requests'), 'seconds_limit' => config('gameplay.investigation_seconds'), 'markets' => [], 'limits_reached' => [], 'area' => $system] + $focused,
            'observations' => ['system' => $system, 'observed_at' => now()->toIso8601String()] + $focused, 'candidates' => []]);
    }

    // Each browser-requested step persists progress. Engine-off investigation requires no queue worker.
    public function advance(Agent $agent, OpportunityInvestigation $record, bool $campaign = false): OpportunityInvestigation
    {
        return $this->executor->locked($agent, function () use ($agent, $record, $campaign) {
            $record->refresh();
            if ($record->status !== 'investigating') {
                return $record;
            }
            if ($record->reset_id !== $agent->reset_id || $agent->isArchived()) {
                throw new GameException('Investigation belongs to an archived world.');
            }
            $observations = $record->observations;
            $coverage = $record->coverage;
            $started = microtime(true);
            $elapsed = max(0, $record->created_at->diffInSeconds(now()));
            $timeUsed = $campaign ? ($coverage['active_seconds'] ?? 0) : $elapsed;
            $deadline = $started + $coverage['seconds_limit'] - $timeUsed;
            try {
                $this->api->readOnly(function () use ($record, &$coverage, $campaign, $started, $deadline) {
                    if ($record->fresh()->cancelled_at) {
                        throw new GameException('Investigation cancelled. No further reads issued.');
                    }
                    if ($coverage['requests'] >= $coverage['request_limit'] || microtime(true) >= $deadline) {
                        throw new GameException('Investigation request or elapsed-time limit reached.');
                    }
                    $coverage['requests']++;
                    $record->update(['coverage' => $coverage]);

                    return $campaign ? min($started + 6, $deadline) : $deadline;
                }, function () use ($agent, $record, &$observations, &$coverage) {
                    switch ($coverage['phase']) {
                        case 'agent':
                            $reset = $this->api->status();
                            if ($this->sync->archiveForReset($agent, $reset)) {
                                throw new GameException('World reset. Import current credentials before investigating.');
                            }
                            $observations['credits'] = $this->api->agent($agent)['credits'];
                            $agent->update(['credits' => $observations['credits'], 'synced_at' => now()]);
                            app(CreditAccounting::class)->baseline($agent);
                            $coverage['phase'] = 'ships';
                            break;
                        case 'ships':
                            $observations['ships'] = $this->api->ships($agent);
                            foreach ($observations['ships'] as $ship) {
                                $this->sync->saveShip($agent, $ship);
                            }
                            $coverage['phase'] = 'contracts';
                            break;
                        case 'contracts':
                            $observations['contracts'] = $this->api->contracts($agent);
                            foreach ($observations['contracts'] as $contract) {
                                $this->sync->observe($agent, 'contract', $contract['id'], $contract);
                            }
                            $coverage['phase'] = $record->strategy === 'contracts' && ! collect($observations['contracts'])->contains('fulfilled', false) ? 'done' : 'waypoints';
                            break;
                        case 'waypoints':
                            $page = $coverage['waypoint_page'] ?? 1;
                            $batch = $this->api->cataloguePage($agent, $observations['system'], $page);
                            if ($page > 1 && ! isset($coverage['waypoint_total'])) {
                                $coverage['limits_reached'][] = 'Waypoint catalogue began without a recorded total. Investigate again for complete coverage.';
                            }
                            $coverage['waypoint_total'] ??= $batch['total'];
                            $coverage['waypoint_page'] = $page + 1;
                            $observations['waypoints'] ??= [];
                            $points = array_column($batch['rows'], null, 'symbol');
                            $seen = count($observations['waypoints']) + count($points);
                            $total = $coverage['waypoint_total'];
                            if ($total !== $batch['total'] || array_intersect_key($observations['waypoints'], $points) || $seen > $total) {
                                $coverage['limits_reached'][] = 'Waypoint catalogue changed or repeated records during pagination. Investigate again.';
                            } elseif ($points === [] && $seen < $total) {
                                $coverage['limits_reached'][] = 'Waypoint catalogue returned an empty page before all waypoints were observed.';
                            } else {
                                $observations['waypoints'] += $points;
                                foreach ($points as $point) {
                                    $this->sync->observe($agent, 'waypoint', $point['symbol'], $point);
                                }
                                if ($seen < $total && $page < config('gameplay.max_pages')) {
                                    break;
                                }
                                if ($seen < $total) {
                                    $coverage['limits_reached'][] = 'Waypoint catalogue is incomplete within the bounded page limit.';
                                }
                            }
                            $markets = $this->orderedMarkets($observations);
                            $limit = config('gameplay.max_observations');
                            if (count($markets) > $limit) {
                                $coverage['limits_reached'][] = 'Market observation cap reached; coverage is partial.';
                            }
                            $coverage['markets'] = array_slice($markets, 0, $limit);
                            $coverage['phase'] = 'markets';
                            break;
                        case 'markets':
                            $market = $coverage['markets'][0] ?? null;
                            if ($market) {
                                $quote = $this->api->market($agent, $market);
                                $observations['markets'][$market] = $quote;
                                $observations['market_times'][$market] = now()->toIso8601String();
                                $this->sync->observe($agent, 'market', $market, $quote);
                                array_shift($coverage['markets']);
                            }
                            if ($coverage['markets'] === []) {
                                $coverage['phase'] = 'done';
                            }
                            break;
                    }
                });
            } catch (GameException $e) {
                if (! $campaign || $e->retryAfter === 0) {
                    $coverage['limits_reached'][] = $e->getMessage();
                    $coverage['phase'] = 'done';
                }
            }
            $coverage['active_seconds'] = round(($coverage['active_seconds'] ?? 0) + microtime(true) - $started, 3);
            $coverage['elapsed_seconds'] = round($elapsed + microtime(true) - $started, 3);
            $cancelled = $record->fresh()->cancelled_at !== null;
            $done = $coverage['phase'] === 'done' || $cancelled;
            if ($done && ! $cancelled && isset($observations['focus']['buyer'])) {
                $buyer = $observations['focus']['buyer'];
                $quote = $observations['markets'][$buyer] ?? null;
                $quoteTime = $observations['market_times'][$buyer] ?? null;
                if ($quote === null) {
                    $coverage['focus_warning'] = "Focused buyer $buyer was not observed in this investigation.";
                } elseif (! MiningPlanner::hasFreshPrices($quote, $quoteTime ? CarbonImmutable::parse($quoteTime) : null, CarbonImmutable::now())) {
                    $coverage['focus_warning'] = "Focused buyer $buyer has no fresh trade prices. Have a stationary ship at the buyer and investigate again.";
                }
            }
            $complete = $done && ! $cancelled && $coverage['limits_reached'] === [] && ! isset($coverage['focus_warning']);
            $observations['history'] = app(CycleAccounting::class)->history($agent);
            $record->update(['coverage' => $coverage, 'observations' => $observations, 'status' => $cancelled ? 'cancelled' : ($done ? ($complete ? 'complete' : 'partial') : 'investigating'),
                'finished_at' => $done ? now() : null, 'candidates' => $done ? $this->evaluator->evaluate($observations, $record->strategy, CarbonImmutable::now()) : []]);

            return $record;
        });
    }

    private function orderedMarkets(array $observations): array
    {
        $markets = array_keys(array_filter($observations['waypoints'], fn ($point) => in_array('MARKETPLACE', array_column($point['traits'], 'symbol'), true)));
        $occupied = [];
        foreach ($observations['ships'] as $ship) {
            if ($ship['nav']['status'] !== 'IN_TRANSIT' && $ship['nav']['systemSymbol'] === $observations['system']) {
                $occupied[$ship['nav']['waypointSymbol']] = true;
            }
        }
        $buyer = $observations['focus']['buyer'] ?? null;
        usort($markets, fn ($a, $b) => [$a !== $buyer, ! isset($occupied[$a]), $a] <=> [$b !== $buyer, ! isset($occupied[$b]), $b]);

        return $markets;
    }

    public function cancel(OpportunityInvestigation $record): void
    {
        OpportunityInvestigation::whereKey($record->id)->where('status', 'investigating')->update(['cancelled_at' => now(), 'status' => 'cancelled', 'finished_at' => now()]);
    }
}
