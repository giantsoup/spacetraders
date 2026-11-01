<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\ObservationHistory;

class EvidenceAnalysis
{
    public function report(Agent $agent): array
    {
        $travelQuery = ObservationHistory::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'travel');
        $totalTravel = (clone $travelQuery)->count();
        $travel = $travelQuery->latest('id')->limit(config('gameplay.evidence_travel_limit'))->get()->reverse();
        $groups = $travel->groupBy(fn ($sample) => json_encode([$sample->data['configuration'], $sample->data['from'], $sample->data['to']]));
        $routes = [];
        foreach ($groups as $samples) {
            $first = $samples->first();
            $data = $first->data;
            $valid = $samples->filter(fn ($sample) => $sample->data['actual_fuel'] >= 0);
            $fuel = $valid->map(fn ($sample) => $sample->data['actual_fuel']);
            $errors = $valid->filter(fn ($sample) => isset($sample->data['prediction']))->map(fn ($sample) => $sample->data['actual_fuel'] - $sample->data['prediction']);
            $duration = $samples->map(fn ($sample) => $sample->data['server_duration_seconds'] ?? null)->filter(fn ($value) => $value !== null);
            $durationErrors = $samples->filter(fn ($sample) => isset($sample->data['server_duration_seconds'], $sample->data['predicted_seconds']))
                ->map(fn ($sample) => $sample->data['server_duration_seconds'] - $sample->data['predicted_seconds']);
            $routes[] = ['configuration' => $data['configuration'], 'from' => $data['from'], 'to' => $data['to'], 'mode' => $data['flight_mode'] ?? 'unknown',
                'distance' => $data['distance'] ?? null, 'engine_speed' => $data['ship_configuration']['engine']['speed'] ?? null, 'tank_capacity' => $data['tank_capacity'] ?? null,
                'sample_count' => $samples->count(), 'invalid_fuel_samples' => $samples->count() - $valid->count(), 'first_at' => $first->observed_at->toIso8601String(), 'last_at' => $samples->last()->observed_at->toIso8601String(),
                'actual_fuel_min' => $fuel->min(), 'actual_fuel_max' => $fuel->max(), 'actual_fuel_mean' => $fuel->avg(),
                'fuel_error_mean' => $errors->isEmpty() ? null : $errors->avg(), 'fuel_overruns' => $errors->filter(fn ($error) => $error > 0)->count(),
                'server_duration_samples' => $duration->count(), 'server_duration_mean' => $duration->isEmpty() ? null : $duration->avg(),
                'duration_error_mean' => $durationErrors->isEmpty() ? null : $durationErrors->avg()];
        }
        $runQuery = AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('strategy', 'mining')->whereNotNull('started_at');
        $totalRuns = (clone $runQuery)->count();
        $runs = $runQuery->latest('id')->limit(config('gameplay.evidence_run_limit'))->get()->reverse();
        $loads = [];
        foreach ($runs->groupBy(fn ($run) => json_encode([$run->plan['site'], $run->plan['market'], $run->plan['equipment'] ?? $run->plan['initial_configuration'] ?? 'legacy',
            $run->plan['initial_waypoint'], $run->plan['initial_fuel'], $run->plan['max_extractions'] ?? 1, $run->plan['extraction_headroom'] ?? null, $run->plan['site_seconds'] ?? null])) as $group) {
            $first = $group->first();
            $seconds = $group->sum(fn ($run) => $run->totals['elapsed_seconds'] ?? 0);
            $timed = $group->filter(fn ($run) => isset($run->totals['elapsed_seconds']) && $run->totals['elapsed_seconds'] > 0);
            $cash = $group->sum(fn ($run) => $run->totals['credit_delta']);
            $comparable = $group->every(fn ($run) => ($run->totals['accounting']['state'] ?? null) === 'realized_comparable');
            $loads[] = ['site' => $first->plan['site'], 'market' => $first->plan['market'], 'configuration' => $first->plan['equipment'] ?? $first->plan['initial_configuration'] ?? 'legacy',
                'initial_waypoint' => $first->plan['initial_waypoint'], 'initial_fuel' => $first->plan['initial_fuel'], 'max_extractions' => $first->plan['max_extractions'] ?? 1,
                'headroom' => $first->plan['extraction_headroom'] ?? null, 'site_seconds' => $first->plan['site_seconds'] ?? null,
                'runs' => $group->count(), 'states' => $group->countBy(fn ($run) => $run->state->value)->all(),
                'completed_trips' => $group->sum(fn ($run) => $run->totals['cycles'] ?? 0), 'extractions' => $group->sum(fn ($run) => $run->totals['attempts'] ?? 0),
                'cash_contribution' => $cash, 'sale_proceeds' => $group->sum(fn ($run) => $run->totals['sale_proceeds'] ?? 0), 'fuel_cost' => $group->sum(fn ($run) => $run->totals['fuel_cost'] ?? 0),
                'timed_runs' => $timed->count(), 'elapsed_seconds' => $seconds, 'runs_retaining_cargo' => $group->filter(fn ($run) => ! empty($run->totals['unsold']))->count(),
                'cash_per_elapsed_hour' => $timed->count() === $group->count() && $seconds > 0 ? $cash * 3600 / $seconds : null,
                'comparable_net_per_hour' => $comparable && $seconds > 0 ? $group->sum(fn ($run) => $run->totals['accounting']['cash_contribution']) * 3600 / $seconds : null,
                'first_at' => $first->started_at->toIso8601String(), 'last_at' => $group->last()->updated_at->toIso8601String()];
        }

        return ['reset_id' => $agent->reset_id, 'travel' => $routes, 'mining_loads' => $loads,
            'travel_samples_in_window' => $travel->count(), 'total_travel_samples' => $totalTravel, 'mining_runs_in_window' => $runs->count(), 'total_mining_runs' => $totalRuns,
            'policy' => 'Conservative CRUISE stays the default. Samples do not automatically fit or promote a fuel formula or change a departure policy. Review representative configurations and distances before opting into measured edges.',
            'travel_limits' => 'Fuel comes from confirmed tank changes. Duration uses server departure/arrival timestamps when supplied, not delayed polling time. Legacy samples without inputs remain visibly unknown.',
            'mining_limits' => 'Compare matching sites, buyers, starting position/fuel and equipment. Count blocked, stopped and retained-cargo runs alongside successes. Cash rates exclude unresolved fuel, inventory and wear adjustments; they are not economic profit.'];
    }
}
