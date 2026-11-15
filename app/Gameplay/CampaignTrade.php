<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\Ship;
use App\Models\ShipAction;
use Carbon\CarbonImmutable;

class CampaignTrade
{
    public function results(CampaignEpisode $episode): array
    {
        $runs = AutomationRun::where('campaign_episode_id', $episode->id)->orderBy('id')->get();
        $results = [];
        foreach ($runs as $run) {
            if ($run->active_agent_id || ! $run->started_at || ! in_array($run->state->value, ['completed', 'stopped', 'blocked'], true)) {
                continue;
            }
            $recovery = ! empty($run->plan['sell_only']);
            if ($run->strategy !== 'trade' && (! $recovery || ! empty($run->plan['recovery_of']))) {
                continue;
            }
            $good = $recovery ? array_key_first($run->plan['initial_inventory']) : $run->plan['symbol'];
            $bought = $recovery ? $run->plan['initial_inventory'][$good] : ($run->totals['purchased'][$good] ?? 0);
            if ($bought < 1) {
                continue;
            }
            $parts = $runs->filter(fn ($part) => $part->id === $run->id || ($part->plan['recovery_of'] ?? null) === $run->id);
            if ($parts->contains(fn ($part) => $part->active_agent_id) || $parts->sum(fn ($part) => $part->totals['sold'][$good] ?? 0) < $bought) {
                continue;
            }
            $cost = $recovery ? ($run->plan['cargo_cost'] ?? 0) : ($run->totals['purchase_cost'] ?? 0);
            $cashNet = $parts->sum(fn ($part) => ($part->totals['sale_proceeds'] ?? 0) - ($part->totals['fuel_cost'] ?? 0)) - $cost;
            $unrefilled = max(0, $parts->sum(fn ($part) => $part->totals['travel_fuel'] ?? 0) - $parts->sum(fn ($part) => $part->totals['fuel_units'] ?? 0));
            $fuelPrice = GameObservation::where('agent_id', $episode->agent_id)->where('reset_id', $episode->reset_id)->where('kind', 'market')
                ->whereIn('symbol', [$run->plan['site'], $run->plan['market']])->get()
                ->flatMap(fn ($row) => $row->data['tradeGoods'] ?? [])->where('symbol', 'FUEL')->max('purchasePrice');
            $fuelAllowance = $unrefilled > 0 ? ($fuelPrice ? (int) ceil($unrefilled / 100) * $fuelPrice : ($run->plan['route']['fuel_ceiling'] ?? 0)) : 0;
            $net = $cashNet - $fuelAllowance;
            $sales = ShipAction::whereIn('automation_run_id', $parts->pluck('id'))->where('command', 'sell')->where('state', 'confirmed')->get();
            $proofEligible = ! $sales->contains(fn ($sale) => ($sale->before_state['ship']['nav']['waypointSymbol'] ?? null) !== $run->plan['market']);
            $prediction = $run->plan['campaign_trade'] ?? [];
            $forced = $parts->contains(fn ($part) => ! empty($part->plan['forced_sale']));
            $results[] = ['run_id' => $run->id, 'good' => $good, 'buyer' => $run->plan['market'], 'net' => $net,
                'cash_net' => $cashNet, 'unrefilled_fuel_allowance' => $fuelAllowance, 'proof_eligible' => $proofEligible,
                'proof' => $prediction['proof'] ?? false, 'predicted_margin' => $prediction['predicted_margin'] ?? null,
                'realized_ratio' => ($prediction['predicted_margin'] ?? 0) > 0 ? $net / $prediction['predicted_margin'] : null,
                'forced_sale' => $forced, 'losing' => $net < 0 || $forced,
                'finished_at' => $parts->max('updated_at')->toIso8601String()];
        }
        usort($results, fn ($left, $right) => [$left['finished_at'], $left['run_id']] <=> [$right['finished_at'], $right['run_id']]);

        return $results;
    }

    public function recordResults(CampaignEpisode $episode): void
    {
        foreach ($this->results($episode) as $result) {
            if (! CampaignEvent::where('campaign_episode_id', $episode->id)->where('kind', 'load_result')->where('data->run_id', $result['run_id'])->exists()) {
                app(CampaignControl::class)->event($episode->grant(), $episode, 'load_result', $result);
            }
        }
    }

    public function mode(CampaignEpisode $episode, string $good, string $buyer, ?array $results = null): string
    {
        $proof = null;
        $scaled = false;
        $minimum = $episode->grant()->revision()->body['trade']['proof']['min_realized_ratio'];
        foreach ($results ?? $this->results($episode) as $result) {
            if ($result['losing']) {
                $proof = null;
                $scaled = false;
            } elseif ($result['good'] === $good && $result['buyer'] === $buyer) {
                if ($result['proof']) {
                    $proof = $result['proof_eligible'] && $result['realized_ratio'] >= $minimum;
                    $scaled = false;
                } elseif ($proof) {
                    $scaled = true;
                }
            }
        }

        return ! $proof ? 'proof' : ($scaled ? 'scaled' : 'first_scaled');
    }

    public function averageCosts(Agent $agent, string $ship): array
    {
        $lots = [];
        $actions = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('ship_symbol', $ship)->where('state', 'confirmed')->orderBy('id')->get();
        foreach ($actions as $action) {
            $good = $action->arguments['symbol'] ?? $action->outcome['yield']['symbol'] ?? null;
            if (! $good) {
                continue;
            }
            $units = $action->outcome['units'] ?? $action->outcome['yield']['units'] ?? $action->arguments['units'] ?? 0;
            $lot = $lots[$good] ?? ['units' => 0, 'cost' => 0];
            if (in_array($action->command, ['buy', 'mine', 'mine_survey'], true)) {
                $lot['units'] += $units;
                $lot['cost'] += $action->command === 'buy' ? ($action->outcome['transaction_total'] ?? 0) : 0;
            } elseif ($action->command === 'sell' && $lot['units'] > 0) {
                $lot['cost'] *= max(0, $lot['units'] - $units) / $lot['units'];
                $lot['units'] = max(0, $lot['units'] - $units);
            }
            $lots[$good] = $lot;
        }

        return array_map(fn ($lot) => $lot['units'] > 0 ? $lot['cost'] / $lot['units'] : 0, $lots);
    }

    public function fuelReserve(Agent $agent, string $symbol, string $buyer): int
    {
        $ship = Ship::where('agent_id', $agent->id)->where('symbol', $symbol)->firstOrFail()->snapshot;

        return app(FuelPlanner::class)->leg($agent, $ship, $buyer, 0)['refuel_ceiling'];
    }

    public function quoteLife(Agent $agent, array $plan, array $totals): void
    {
        if (empty($plan['campaign_trade'])) {
            return;
        }
        $remaining = $plan['units'] - ($totals['purchased'][$plan['symbol']] ?? 0);
        if ($remaining <= 0) {
            return;
        }
        $ask = app(OperationPlanner::class)->quote($agent, $plan['site'], $plan['symbol']);
        $bid = app(OperationPlanner::class)->quote($agent, $plan['market'], $plan['symbol']);
        $seconds = (int) ceil($remaining / max(1, $ask['tradeVolume'])) * 10 + 10;
        foreach ([$ask, $bid] as $quote) {
            if (CarbonImmutable::parse($quote['observed_at'])->addSeconds($plan['campaign_trade']['quote_max_age_seconds'])->lte(now()->addSeconds($seconds))) {
                throw new GameException('Freshness hold: quotes would expire during planned loading.');
            }
        }
    }

    public function prediction(int $units, array $ask, array $bid, float $decay, int $fuel): array
    {
        $cost = 0;
        $revenue = 0;
        for ($remaining = $units, $batch = 0; $remaining > 0; $batch++) {
            $count = min($remaining, $ask['tradeVolume']);
            $cost += $count * (int) ceil($ask['purchasePrice'] / ((1 - $decay) ** $batch));
            $remaining -= $count;
        }
        for ($remaining = $units, $batch = 0; $remaining > 0; $batch++) {
            $count = min($remaining, $bid['tradeVolume']);
            $revenue += $count * (int) floor($bid['sellPrice'] * ((1 - $decay) ** $batch));
            $remaining -= $count;
        }

        return ['predicted_cost' => $cost, 'predicted_revenue' => $revenue, 'predicted_margin' => $revenue - $cost - $fuel];
    }

    public function decay(CampaignEpisode $episode, string $good, string $buyer): float
    {
        $sales = ShipAction::whereIn('automation_run_id', AutomationRun::where('campaign_episode_id', $episode->id)->select('id'))->where('command', 'sell')->where('state', 'confirmed')->orderBy('id')->get();
        $previous = null;
        $drops = [];
        foreach ($sales as $sale) {
            if (($sale->arguments['symbol'] ?? null) !== $good || ($sale->before_state['ship']['nav']['waypointSymbol'] ?? null) !== $buyer) {
                continue;
            }
            $units = $sale->outcome['units'] ?? 0;
            if ($units < 1 || (isset($sale->before_state['estimate']['quote']['tradeVolume']) && $units !== $sale->before_state['estimate']['quote']['tradeVolume'])) {
                $previous = null;

                continue;
            }
            $price = $sale->outcome['transaction_total'] / $units;
            if ($previous && $previous['run'] === $sale->automation_run_id && $previous['units'] === $units && $previous['price'] > 0) {
                $drops[] = max(0, min(0.95, 1 - $price / $previous['price']));
            }
            $previous = ['run' => $sale->automation_run_id, 'units' => $units, 'price' => $price];
        }

        return $drops ? array_sum($drops) / count($drops) : $episode->grant()->revision()->body['trade']['lot']['default_batch_decay'];
    }

    public function rank(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $ship, array &$considered): array
    {
        $rules = $grant->revision()->body['trade'];
        $markets = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'market')->get()->keyBy('symbol');
        $pairs = app(OpportunityAssessor::class)->report($agent, 'trade', $ship['symbol'], true)['opportunities'];
        $state = $episode->state ?? [];
        $ranked = [];
        $results = $this->results($episode);
        $learning = [];
        foreach ($pairs as $pair) {
            $key = $pair['good'].'@'.$pair['market'];
            $ask = collect($markets->get($pair['source'])?->data['tradeGoods'] ?? [])->firstWhere('symbol', $pair['good']);
            $bid = collect($markets->get($pair['market'])?->data['tradeGoods'] ?? [])->firstWhere('symbol', $pair['good']);
            if (! $ask || ! $bid || $ask['purchasePrice'] < 1 || $bid['sellPrice'] <= $ask['purchasePrice'] || min($ask['tradeVolume'], $bid['tradeVolume']) < 1) {
                $considered[] = $pair + ['rejected' => 'No observed positive spread.'];

                continue;
            }
            $state['first_seen'][$key] ??= $bid['sellPrice'];
            try {
                $atSource = $ship;
                $position = app(FuelPlanner::class)->leg($agent, $ship, $pair['source'], 0);
                $atSource['nav']['waypointSymbol'] = $pair['source'];
                $atSource['fuel']['current'] -= $position['outbound_fuel'];
                $outbound = app(FuelPlanner::class)->leg($agent, $atSource, $pair['market'], 0);
                $atBuyer = $atSource;
                $atBuyer['nav']['waypointSymbol'] = $pair['market'];
                $atBuyer['fuel']['current'] -= $outbound['outbound_fuel'];
                $return = app(FuelPlanner::class)->leg($agent, $atBuyer, $pair['source'], 0);
                $fuel = $return['refuel_ceiling'];
                $learning[$key] ??= ['mode' => $this->mode($episode, $pair['good'], $pair['market'], $results),
                    'decay' => $this->decay($episode, $pair['good'], $pair['market'])];
                $mode = $learning[$key]['mode'];
                $batches = $mode === 'first_scaled' ? $rules['lot']['first_scaled_max_buyer_batches'] : $rules['lot']['max_buyer_batches'];
                $ceiling = max(0, $agent->credits - max($outbound['refuel_ceiling'], $fuel));
                $units = min($ship['cargo']['capacity'], $batches * $bid['tradeVolume']);
                if ($mode === 'proof') {
                    $units = min($units, $rules['proof']['max_units_batches'] * min($ask['tradeVolume'], $bid['tradeVolume']));
                    $ceiling = min($ceiling, $rules['proof']['max_credits']);
                }
                $decay = $learning[$key]['decay'];
                $low = 0;
                $high = $units;
                while ($low < $high) {
                    $middle = intdiv($low + $high + 1, 2);
                    if ($this->prediction($middle, $ask, $bid, $decay, $fuel)['predicted_cost'] <= $ceiling) {
                        $low = $middle;
                    } else {
                        $high = $middle - 1;
                    }
                }
                $units = $low;
                $prediction = $this->prediction($units, $ask, $bid, $decay, $fuel);
                $seconds = ($position['estimated_seconds'] ?? 0) + ($outbound['estimated_seconds'] ?? 0) + ($return['estimated_seconds'] ?? 0)
                    + (ceil($units / $ask['tradeVolume']) + ceil($units / $bid['tradeVolume'])) * 10 + 120;
                if ($units < 1 || $prediction['predicted_margin'] <= 0 || $seconds > $grant->limits['run_max_minutes'] * 60) {
                    throw new GameException('No profitable lot fits cargo, fuel, proof and duration bounds.');
                }
                $candidate = ['key' => $key, 'source' => $pair['source'], 'buyer' => $pair['market'], 'good' => $pair['good'], 'units' => $units,
                    'mode' => $mode, 'proof' => $mode === 'proof', 'decay' => $decay, 'bid' => $bid['sellPrice'], 'fuel' => $fuel,
                    'fuel_reserve' => $outbound['refuel_ceiling'], 'max_inventory' => $ceiling, 'seconds' => $seconds,
                    'score' => $prediction['predicted_margin'] / max(1, $seconds / 60)] + $prediction;
                if (($state['current_pair'] ?? null) === $key && $bid['sellPrice'] <= $state['first_seen'][$key] * $rules['switch']['bid_below_first_seen']) {
                    $considered[] = $candidate + ['rejected' => 'Switch: buyer bid at or below first-seen threshold.'];

                    continue;
                }
                $ranked[] = $candidate;
            } catch (GameException $exception) {
                $considered[] = $pair + ['rejected' => $exception->getMessage()];
            }
        }
        usort($ranked, fn ($left, $right) => $right['score'] <=> $left['score'] ?: [$left['key'], $left['source']] <=> [$right['key'], $right['source']]);
        if ($ranked) {
            $current = collect($ranked)->firstWhere('key', $state['current_pair'] ?? null);
            if ($current && $ranked[0]['key'] !== $current['key'] && $ranked[0]['score'] < $current['score'] * $rules['switch']['better_pair_ratio']) {
                $ranked = array_values(array_filter($ranked, fn ($row) => $row !== $current));
                array_unshift($ranked, $current);
            }
        }
        $episode->update(['state' => $state]);
        foreach ($ranked as $candidate) {
            $considered[] = $candidate;
        }

        return $ranked;
    }

    public function authorize(Agent $agent, AutomationRun $run, CampaignEpisode $episode): void
    {
        $rules = $episode->grant()->revision()->body['trade'];
        $plan = $run->plan;
        $ask = app(OperationPlanner::class)->quote($agent, $plan['site'], $plan['symbol']);
        $bid = app(OperationPlanner::class)->quote($agent, $plan['market'], $plan['symbol']);
        $mode = $this->mode($episode, $plan['symbol'], $plan['market']);
        $batches = $mode === 'first_scaled' ? $rules['lot']['first_scaled_max_buyer_batches'] : $rules['lot']['max_buyer_batches'];
        $cap = $batches * $bid['tradeVolume'];
        if ($mode === 'proof') {
            $cap = min($cap, $rules['proof']['max_units_batches'] * min($ask['tradeVolume'], $bid['tradeVolume']));
            if ($plan['max_inventory'] > $rules['proof']['max_credits']) {
                throw new GameException('Proof spending exceeds immutable campaign terms.');
            }
        }
        if (($plan['campaign_trade']['mode'] ?? null) !== $mode || $plan['units'] > $cap || $plan['max_spend'] > $agent->credits) {
            throw new GameException('Trade preview exceeds immutable campaign terms.');
        }
        $this->quoteLife($agent, $plan, $run->totals);
    }
}
