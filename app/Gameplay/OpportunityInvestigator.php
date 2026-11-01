<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
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
    public function advance(Agent $agent, OpportunityInvestigation $record): OpportunityInvestigation
    {
        return $this->executor->locked($agent, function () use ($agent, $record) {
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
                $this->api->readOnly(function () use ($record, &$coverage) {
                    if ($record->fresh()->cancelled_at) {
                        throw new GameException('Investigation cancelled. No further reads issued.');
                    }
                    if ($coverage['requests'] >= $coverage['request_limit'] || microtime(true) >= $record->created_at->getTimestamp() + $coverage['seconds_limit']) {
                        throw new GameException('Investigation request or elapsed-time limit reached.');
                    }
                    $coverage['requests']++;
                    $record->update(['coverage' => $coverage]);

                    return (float) ($record->created_at->getTimestamp() + $coverage['seconds_limit']);
                }, function () use ($agent, &$observations, &$coverage) {
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
                            $coverage['phase'] = 'waypoints';
                            break;
                        case 'waypoints':
                            $points = $this->api->waypoints($agent, $observations['system']);
                            $observations['waypoints'] = array_column($points, null, 'symbol');
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
                            $coverage['phase'] = 'markets';
                            break;
                        case 'markets':
                            $market = array_shift($coverage['markets']);
                            if ($market) {
                                $quote = $this->api->market($agent, $market);
                                $observations['markets'][$market] = $quote;
                                $observations['market_times'][$market] = now()->toIso8601String();
                                $this->sync->observe($agent, 'market', $market, $quote);
                            }
                            if ($coverage['markets'] === []) {
                                $coverage['phase'] = 'done';
                            }
                            break;
                    }
                });
            } catch (GameException $e) {
                $coverage['limits_reached'][] = $e->getMessage();
                $coverage['phase'] = 'done';
            }
            $coverage['active_seconds'] = round(($coverage['active_seconds'] ?? 0) + microtime(true) - $started, 3);
            $coverage['elapsed_seconds'] = round(microtime(true) - $record->created_at->getTimestamp(), 3);
            $cancelled = $record->fresh()->cancelled_at !== null;
            $done = $coverage['phase'] === 'done' || $cancelled;
            $complete = $done && ! $cancelled && $coverage['limits_reached'] === [];
            $observations['history'] = AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('strategy', 'mining')->where('state', 'completed')->where('updated_at', '>=', now()->subDays(7))->limit(100)->get()
                ->filter(fn ($run) => isset($run->plan['equipment'], $run->totals['elapsed_seconds'], $run->totals['cash_contribution']) && ($run->totals['cycles'] ?? 0) === 1 && ($run->plan['max_extractions'] ?? 1) === 1)
                ->map(fn ($run) => ['site' => $run->plan['site'], 'market' => $run->plan['market'], 'equipment' => $run->plan['equipment'], 'quote_signature' => $run->plan['market_quote_signature'] ?? null, 'initial_waypoint' => $run->plan['initial_waypoint'], 'initial_fuel' => $run->plan['initial_fuel'], 'seconds' => $run->totals['elapsed_seconds'], 'contribution' => $run->totals['cash_contribution'], 'at' => $run->updated_at->toIso8601String()])->values()->all();
            $record->update(['coverage' => $coverage, 'observations' => $observations, 'status' => $cancelled ? 'cancelled' : ($done ? ($complete ? 'complete' : 'partial') : 'investigating'),
                'finished_at' => $done ? now() : null, 'candidates' => $done ? $this->evaluator->evaluate($observations, $record->strategy, CarbonImmutable::now(), $complete) : []]);

            return $record;
        });
    }

    public function cancel(OpportunityInvestigation $record): void
    {
        OpportunityInvestigation::whereKey($record->id)->where('status', 'investigating')->update(['cancelled_at' => now(), 'status' => 'cancelled', 'finished_at' => now()]);
    }
}
