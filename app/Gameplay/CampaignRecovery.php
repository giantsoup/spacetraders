<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\GameObservation;
use App\Models\Ship;
use Carbon\CarbonImmutable;

class CampaignRecovery
{
    public function compose(Agent $agent, string $symbol, string $buyer, array $limits): array
    {
        app(AgentSynchronizer::class)->sync($agent, false);
        $ship = Ship::where('agent_id', $agent->id)->where('symbol', $symbol)->firstOrFail()->snapshot;
        if ($ship['nav']['status'] === 'IN_TRANSIT' || $ship['cargo']['units'] < 1) {
            throw new GameException('Recovery requires stationary owned cargo.');
        }
        $market = app(SpaceTradersApi::class)->market($agent, $buyer);
        app(AgentSynchronizer::class)->observe($agent, 'market', $buyer, $market);
        $good = array_key_first(MiningPlanner::inventory($ship));
        $known = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'market')->where('symbol', $buyer)->first();
        $quote = collect($known?->data['tradeGoods'] ?? [])->firstWhere('symbol', $good);
        if (! $quote || $quote['sellPrice'] < 1 || $quote['tradeVolume'] < 1) {
            throw new GameException('Recovery requires a known buyer for the cargo good.');
        }
        $cost = app(CampaignTrade::class)->averageCosts($agent, $symbol)[$good] ?? 0;
        $leg = $ship['nav']['waypointSymbol'] === $buyer
            ? ['destination' => $buyer, 'outbound_fuel' => 0, 'required_fuel' => 0, 'refuel_ceiling' => 0]
            : app(FuelPlanner::class)->leg($agent, $ship, $buyer, $limits['reserve']);
        $plan = array_merge($limits, ['strategy' => 'sell_aboard', 'recovery_good' => $good, 'sale_floor' => (int) ceil($cost), 'cargo_cost' => $cost * MiningPlanner::inventory($ship)[$good], 'sell_only' => true, 'cycles' => 1, 'site' => $ship['nav']['waypointSymbol'], 'market' => $buyer,
            'initial_credits' => $agent->credits, 'initial_inventory' => MiningPlanner::inventory($ship), 'initial_fuel' => $ship['fuel']['current'],
            'initial_waypoint' => $ship['nav']['waypointSymbol'], 'initial_status' => $ship['nav']['status'],
            'initial_configuration' => FuelPlanner::configuration($ship), 'equipment' => OpportunityEvaluator::equipment($ship),
            'participating_ships' => [$symbol], 'route' => ['fuel_ceiling' => 0, 'outbound' => $leg],
            'quote_signature' => OpportunityEvaluator::quoteSignature($market)]);
        $fingerprint = hash('sha256', json_encode(array_intersect_key($plan, array_flip(['initial_credits', 'initial_inventory', 'initial_fuel', 'initial_waypoint', 'initial_status', 'initial_configuration', 'equipment', 'quote_signature']))));

        return ['plan' => $plan, 'fingerprint' => $fingerprint];
    }

    public function next(Agent $agent, array $ship, AutomationRun $run): AutomationStep
    {
        if ($ship['nav']['status'] === 'IN_TRANSIT') {
            return AutomationStep::wait(max(CarbonImmutable::parse($ship['nav']['route']['arrival']), CarbonImmutable::now()->addSeconds(5)));
        }
        if ((MiningPlanner::inventory($ship)[$run->plan['recovery_good']] ?? 0) === 0) {
            return AutomationStep::trip();
        }
        if ($ship['nav']['waypointSymbol'] !== $run->plan['market']) {
            return $ship['nav']['status'] === 'DOCKED' ? AutomationStep::act('orbit') : AutomationStep::act('navigate', ['waypointSymbol' => $run->plan['market']]);
        }
        if ($ship['nav']['status'] !== 'DOCKED') {
            return AutomationStep::act('dock');
        }
        $good = $run->plan['recovery_good'];
        $quote = app(OperationPlanner::class)->quote($agent, $run->plan['market'], $good);
        $units = min(MiningPlanner::inventory($ship)[$good], $quote['tradeVolume']);
        if ($units < 1 || $quote['sellPrice'] < 1) {
            return AutomationStep::blocked('Recovery buyer is no longer available. Cargo retained.');
        }

        $floor = $run->plan['sale_floor'];
        if ($quote['sellPrice'] < $floor) {
            $deadline = CarbonImmutable::parse($run->plan['recovery_started_at'])->addMinutes(10);
            $minimum = $deadline->isFuture() ? $floor : $quote['sellPrice'] + 1;
            $alternatives = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'market')
                ->where('symbol', '!=', $run->plan['market'])->where('observed_at', '>=', now()->subSeconds(config('gameplay.snapshot_seconds')))->get();
            foreach ($alternatives as $alternative) {
                $bid = collect($alternative->data['tradeGoods'] ?? [])->firstWhere('symbol', $good);
                if (! $bid || $bid['sellPrice'] < $minimum || ! empty($alternative->data['invalidated_by_action'])) {
                    continue;
                }
                try {
                    app(FuelPlanner::class)->leg($agent, $ship, $alternative->symbol, 0);
                    if ($bid['sellPrice'] >= $floor) {
                        $this->floorObserved($run);
                    }

                    return AutomationStep::blocked('A better reachable buyer is known. Re-select sell-aboard recovery.');
                } catch (GameException) {
                }
            }
            if ($deadline->isFuture()) {
                return AutomationStep::wait(min($deadline, CarbonImmutable::now()->addSeconds(30)));
            }
            $run->update(['plan' => array_merge($run->plan, ['forced_sale' => true])]);
            $floor = $quote['sellPrice'];
        } else {
            $this->floorObserved($run);
        }

        return AutomationStep::act('sell', ['symbol' => $good, 'units' => $units, 'minTotal' => $units * $floor]);
    }

    private function floorObserved(AutomationRun $run): void
    {
        $at = CarbonImmutable::now()->toIso8601String();
        $run->update(['plan' => array_merge($run->plan, ['recovery_started_at' => $at])]);
        $episode = CampaignEpisode::findOrFail($run->campaign_episode_id);
        $state = $episode->state ?? [];
        $state['recovery_since'][$run->ship_symbol] = $at;
        $episode->update(['state' => $state]);
    }
}
