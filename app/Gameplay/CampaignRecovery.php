<?php

namespace App\Gameplay;

use App\Gameplay\Bridge\BridgeDrain;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\Ship;
use Carbon\CarbonImmutable;

class CampaignRecovery
{
    public function compose(Agent $agent, string $symbol, string $buyer, array $limits, ?int $verifiedBuyerAge = null): array
    {
        $sync = app(AgentSynchronizer::class);
        if ($limits['targeted_recovery'] ?? false) {
            $sync->guardReset($agent);
            $ship = $sync->refreshShip($agent, $symbol)->snapshot;
            $agent->update(['credits' => app(SpaceTradersApi::class)->agent($agent)['credits'], 'synced_at' => now()]);
        } else {
            $sync->sync($agent, false);
            $ship = Ship::where('agent_id', $agent->id)->where('symbol', $symbol)->firstOrFail()->snapshot;
        }
        if ($ship['nav']['status'] === 'IN_TRANSIT' || $ship['cargo']['units'] < 1) {
            throw new GameException('Recovery requires stationary owned cargo.');
        }
        $initialConfiguration = FuelPlanner::configuration($ship);
        $restoreCruise = $ship['nav']['flightMode'] === 'BURN';
        if ($restoreCruise) {
            // Plan the safe leg now; the run must confirm the mode write before it can navigate.
            $ship['nav']['flightMode'] = 'CRUISE';
        }
        $good = array_key_first(MiningPlanner::inventory($ship));
        $atBuyer = $ship['nav']['waypointSymbol'] === $buyer;
        $meshRecovery = $verifiedBuyerAge !== null || ! empty($limits['mesh_settlement_only']);
        if (! $meshRecovery) {
            $market = app(SpaceTradersApi::class)->market($agent, $buyer);
            $sync->observe($agent, 'market', $buyer, $market);
        }
        $known = $verifiedBuyerAge === null ? $this->buyerObservation($agent, $buyer) : $this->verifiedBuyer($agent, $buyer, $good, $verifiedBuyerAge);
        $quote = collect($known?->data['tradeGoods'] ?? [])->firstWhere('symbol', $good);
        if (! $quote || $quote['sellPrice'] < 1 || $quote['tradeVolume'] < 1) {
            throw new GameException('Recovery requires a known buyer for the cargo good.');
        }
        $cost = app(CampaignTrade::class)->averageCosts($agent, $symbol)[$good] ?? 0;
        $fuel = app(FuelPlanner::class);
        // Mesh recovery refreshes evidence through the director's shared pass budget.
        $here = $ship['nav']['waypointSymbol'];
        if (! $meshRecovery && ! $atBuyer && $ship['fuel']['current'] < $ship['fuel']['capacity']
            && $fuel->listsFuel($agent, $here) && ! $fuel->sellerQuote($agent, $here)) {
            try {
                app(AgentSynchronizer::class)->observe($agent, 'market', $here, app(SpaceTradersApi::class)->market($agent, $here));
            } catch (GameException $exception) {
                // Without the read the quote stays stale and the leg is planned from onboard fuel.
                if ($exception->retryAfter > 0) {
                    throw $exception;
                }
            }
        }
        // Docked at a verified fuel seller, the run tops up before it leaves, so the leg is planned from a full tank.
        $departure = $atBuyer ? null : $fuel->departureRefuel($agent, $ship);
        if ($departure) {
            // The top-up ceiling is capped at the credits above the reserve. Only when even that can't fill
            // the tank at the quoted price is the leg planned from onboard fuel, instead of blocking at departure.
            $ceiling = min($departure['ceiling'], $agent->credits - $limits['reserve']);
            $fill = (int) ceil(($ship['fuel']['capacity'] - $ship['fuel']['current']) / 100) * $departure['quote']['purchasePrice'];
            if ($ceiling < $fill) {
                $departure = null;
            } else {
                $departure['ceiling'] = $ceiling;
                $departure['agent']->credits = $agent->credits - $ceiling;
            }
        }
        $leg = $atBuyer
            ? ['destination' => $buyer, 'outbound_fuel' => 0, 'required_fuel' => 0, 'refuel_ceiling' => 0]
            : $fuel->recoveryLeg($departure['agent'] ?? $agent, $departure['ship'] ?? $ship, $buyer, $limits['reserve']);
        $arrived = $departure['ship'] ?? $ship;
        $arrived['nav']['waypointSymbol'] = $buyer;
        $arrived['fuel']['current'] -= $leg['outbound_fuel'];
        // Second safeguard: after the final sale, top up again if the buyer is itself a verified fuel seller.
        $postSale = $fuel->departureRefuel($agent, $arrived);
        $route = ['fuel_ceiling' => ($departure['ceiling'] ?? 0) + ($postSale['ceiling'] ?? 0), 'outbound' => $leg,
            'departure_refuel_ceiling' => $departure['ceiling'] ?? 0, 'post_sale_refuel_ceiling' => $postSale['ceiling'] ?? 0];
        $plan = array_merge($limits, ['strategy' => 'sell_aboard', 'recovery_good' => $good, 'sale_floor' => (int) ceil($cost), 'cargo_cost' => $cost * MiningPlanner::inventory($ship)[$good], 'sell_only' => true, 'cycles' => 1, 'site' => $ship['nav']['waypointSymbol'], 'market' => $buyer,
            'initial_credits' => $agent->credits, 'initial_inventory' => MiningPlanner::inventory($ship), 'initial_fuel' => $ship['fuel']['current'],
            'initial_waypoint' => $ship['nav']['waypointSymbol'], 'initial_status' => $ship['nav']['status'],
            'initial_configuration' => $initialConfiguration, 'restore_cruise' => $restoreCruise, 'equipment' => OpportunityEvaluator::equipment($ship),
            'participating_ships' => [$symbol], 'route' => $route,
            'quote_signature' => OpportunityEvaluator::quoteSignature($market ?? $known->data)]);
        $fingerprint = hash('sha256', json_encode(array_intersect_key($plan, array_flip(['initial_credits', 'initial_inventory', 'initial_fuel', 'initial_waypoint', 'initial_status', 'initial_configuration', 'equipment', 'quote_signature']))));

        return ['plan' => $plan, 'fingerprint' => $fingerprint];
    }

    public function settlementOnly(CampaignEpisode $episode, bool $requested = false): bool
    {
        $grant = $episode->grant();

        return $requested || BridgeDrain::marker($grant, $episode) !== null
            || ($episode->state['pending_pause'] ?? null) === 'consecutive_losing_loads'
            || app(CampaignSafety::class)->windDown($episode)
            || CampaignGrant::pendingFor($grant->user_id, $grant->resolvedAgentSymbol())->exists();
    }

    public function verifiedBuyerAge(CampaignEpisode $episode, array $plan = []): ?int
    {
        return isset(CampaignMesh::policy($episode)['saturation']) && ! $this->settlementOnly($episode, $plan['mesh_settlement_only'] ?? false)
            ? $episode->grant()->revision()->body['trade']['quote_max_age_seconds']
            : null;
    }

    private function buyerObservation(Agent $agent, string $buyer): ?GameObservation
    {
        return GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', 'market')->where('symbol', $buyer)->first();
    }

    public function verifiedBuyer(Agent $agent, string $buyer, string $good, int $verifiedBuyerAge): GameObservation
    {
        $known = $this->buyerObservation($agent, $buyer);
        $quote = collect($known?->data['tradeGoods'] ?? [])->firstWhere('symbol', $good);
        if (! $known?->observer_ship || ! empty($known->data['invalidated_by_action'])
            || $known->observed_at->lt(now()->subSeconds($verifiedBuyerAge))
            || ($quote['sellPrice'] ?? 0) < 1 || ($quote['tradeVolume'] ?? 0) < 1) {
            throw new GameException('Recovery requires a fresh verified buyer quote before departure.');
        }

        return $known;
    }

    public function next(Agent $agent, array $ship, AutomationRun $run): AutomationStep
    {
        if ($ship['nav']['status'] === 'IN_TRANSIT') {
            return AutomationStep::wait(max(CarbonImmutable::parse($ship['nav']['route']['arrival']), CarbonImmutable::now()->addSeconds(5)));
        }
        if (! empty($run->plan['restore_cruise']) && $ship['nav']['flightMode'] === 'BURN') {
            return AutomationStep::act('flight_mode', ['flightMode' => 'CRUISE']);
        }
        $fuelCost = $run->totals['fuel_cost'] ?? 0;
        $missing = $ship['fuel']['capacity'] - $ship['fuel']['current'];
        if ((MiningPlanner::inventory($ship)[$run->plan['recovery_good']] ?? 0) === 0) {
            $windDown = app(CampaignSafety::class)->windDown(CampaignEpisode::findOrFail($run->campaign_episode_id));
            $quote = ! $windDown && $ship['nav']['status'] === 'DOCKED' && $missing > 0 ? app(FuelPlanner::class)->sellerQuote($agent, $ship['nav']['waypointSymbol']) : null;
            if ($quote) {
                $step = $this->refuel($run, $missing, $quote, ($run->plan['route']['fuel_ceiling'] ?? 0) - $fuelCost);
                if ($step) {
                    return $step;
                }
            }

            return AutomationStep::trip();
        }
        if ($ship['nav']['waypointSymbol'] !== $run->plan['market']) {
            $departure = $run->plan['route']['departure_refuel_ceiling'] ?? 0;
            if ($departure > 0 && $missing > 0 && $ship['nav']['waypointSymbol'] === $run->plan['site'] && ($run->totals['travel_fuel'] ?? 0) === 0) {
                $step = $this->refuel($run, $missing, app(OperationPlanner::class)->quote($agent, $run->plan['site'], 'FUEL'), $departure - $fuelCost);
                if (! $step) {
                    // The leg was planned from a full tank; never depart on a partial one.
                    return AutomationStep::blocked('Departure refuel exceeds its approved ceiling. Cargo retained; replan recovery.');
                }

                return $ship['nav']['status'] === 'DOCKED' ? $step : AutomationStep::act('dock');
            }

            return $ship['nav']['status'] === 'DOCKED' ? AutomationStep::act('orbit') : AutomationStep::act('navigate', ['waypointSymbol' => $run->plan['market']]);
        }
        if ($ship['nav']['status'] !== 'DOCKED') {
            return AutomationStep::act('dock');
        }
        if (! empty($run->plan['restored_cruise']) && $missing > 0
            && ! app(CampaignSafety::class)->windDown(CampaignEpisode::findOrFail($run->campaign_episode_id))) {
            $quote = app(FuelPlanner::class)->sellerQuote($agent, $run->plan['market']);
            $room = min(($run->plan['route']['fuel_ceiling'] ?? 0) - $fuelCost, $agent->credits - $run->plan['reserve']);
            if ($quote && ($step = $this->refuel($run, $missing, $quote, $room))) {
                return $step;
            }
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
                    app(FuelPlanner::class)->recoveryLeg($agent, $ship, $alternative->symbol);
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

    private function refuel(AutomationRun $run, int $missing, array $quote, int $ceiling): ?AutomationStep
    {
        $units = min($missing, $quote['tradeVolume'] * 100);
        $ceiling = min($ceiling, $run->plan['max_spend'] - ($run->totals['fuel_cost'] ?? 0));
        if ($units < 1 || (int) ceil($units / 100) * $quote['purchasePrice'] > $ceiling) {
            return null;
        }

        return AutomationStep::act('refuel', ['units' => $units, 'maxTotal' => $ceiling, 'reserve' => $run->plan['reserve']]);
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
