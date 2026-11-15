<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\OpportunityInvestigation;
use Carbon\CarbonImmutable;

class OpportunityInvestigator
{
    public function __construct(private SpaceTradersApi $api, private AgentSynchronizer $sync, private ShipCommandExecutor $executor, private OpportunityEvaluator $evaluator) {}

    public function create(Agent $agent, string $strategy, string $system): OpportunityInvestigation
    {
        return OpportunityInvestigation::create(['agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'strategy' => $strategy, 'system_symbol' => $system, 'status' => 'investigating',
            'coverage' => ['phase' => 'agent', 'requests' => 0, 'request_limit' => config('gameplay.investigation_requests'), 'seconds_limit' => config('gameplay.investigation_seconds'), 'markets' => [], 'limits_reached' => [], 'area' => $system],
            'observations' => ['system' => $system, 'observed_at' => now()->toIso8601String()], 'candidates' => []]);
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
            try {
                $this->api->readOnly(function () use ($record, &$coverage, $campaign, $started) {
                    if ($record->fresh()->cancelled_at) {
                        throw new GameException('Investigation cancelled. No further reads issued.');
                    }
                    if ($coverage['requests'] >= $coverage['request_limit'] || ($campaign ? ($coverage['active_seconds'] ?? 0) + microtime(true) - $started >= $coverage['seconds_limit'] : microtime(true) >= $record->created_at->getTimestamp() + $coverage['seconds_limit'])) {
                        throw new GameException('Investigation request or elapsed-time limit reached.');
                    }
                    $coverage['requests']++;
                    $record->update(['coverage' => $coverage]);

                    return $campaign ? $started + 6 : (float) ($record->created_at->getTimestamp() + $coverage['seconds_limit']);
                }, function () use ($agent, $record, &$observations, &$coverage, $campaign) {
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
                            if ($campaign) {
                                $page = $coverage['waypoint_page'] ?? 1;
                                $batch = $this->api->cataloguePage($agent, $observations['system'], $page);
                                $points = $batch['rows'];
                                $observations['waypoints'] = array_replace($observations['waypoints'] ?? [], array_column($points, null, 'symbol'));
                                $coverage['waypoint_page'] = $page + 1;
                            } else {
                                $points = $this->api->waypoints($agent, $observations['system']);
                                $observations['waypoints'] = array_column($points, null, 'symbol');
                            }
                            $locations = array_column(array_column($observations['ships'], 'nav'), 'waypointSymbol');
                            usort($points, fn ($a, $b) => (int) in_array($b['symbol'], $locations, true) <=> (int) in_array($a['symbol'], $locations, true));
                            foreach ($points as $point) {
                                $this->sync->observe($agent, 'waypoint', $point['symbol'], $point);
                                if (in_array('MARKETPLACE', array_column($point['traits'], 'symbol'), true)) {
                                    $coverage['markets'][] = $point['symbol'];
                                }
                            }
                            if (count($coverage['markets']) > config('gameplay.max_observations')) {
                                $coverage['markets'] = array_slice($coverage['markets'], 0, config('gameplay.max_observations'));
                                $coverage['limits_reached'][] = 'Market observation cap reached; coverage is partial.';
                            }
                            $coverage['phase'] = $campaign && $page * 20 < $batch['total'] ? 'waypoints' : 'markets';
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
            $coverage['elapsed_seconds'] = round(microtime(true) - $record->created_at->getTimestamp(), 3);
            $cancelled = $record->fresh()->cancelled_at !== null;
            $done = $coverage['phase'] === 'done' || $cancelled;
            $complete = $done && ! $cancelled && $coverage['limits_reached'] === [];
            $observations['history'] = app(CycleAccounting::class)->history($agent);
            $record->update(['coverage' => $coverage, 'observations' => $observations, 'status' => $cancelled ? 'cancelled' : ($done ? ($complete ? 'complete' : 'partial') : 'investigating'),
                'finished_at' => $done ? now() : null, 'candidates' => $done ? $this->evaluator->evaluate($observations, $record->strategy, CarbonImmutable::now()) : []]);

            return $record;
        });
    }

    public function cancel(OpportunityInvestigation $record): void
    {
        OpportunityInvestigation::whereKey($record->id)->where('status', 'investigating')->update(['cancelled_at' => now(), 'status' => 'cancelled', 'finished_at' => now()]);
    }
}
