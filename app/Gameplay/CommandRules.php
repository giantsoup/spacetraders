<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\GameObservation;
use App\Models\Ship;
use App\Models\ShipAction;

class CommandRules
{
    public function __construct(private SpaceTradersApi $api, private AgentSynchronizer $sync, private MiningPlanner $planner) {}

    public function inspect(Agent $agent, string $symbol, string $command, array $arguments, ?AutomationRun $run = null): array
    {
        if (! Ship::where('agent_id', $agent->id)->where('symbol', $symbol)->exists()) {
            throw new GameException('Ship is not in the saved owned fleet. Synchronize first.');
        }
        $this->sync->guardReset($agent);
        $agent->update(['credits' => $this->api->agent($agent)['credits']]);
        $ship = $this->sync->refreshShip($agent, $symbol)->snapshot;
        if ($ship['nav']['status'] === 'IN_TRANSIT') {
            throw new GameException('Ship is in transit. Reconcile after the reported arrival.');
        }
        $active = AutomationRun::where('agent_id', $agent->id)->whereNotNull('active_agent_id')->first();
        if ($active && $active->ship_symbol === $symbol && $active->id !== $run?->id) {
            throw new GameException('Stop automation and settle its action before manual takeover.');
        }
        if ($run) {
            $manual = (int) ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->whereNull('automation_run_id')->where('state', 'confirmed')->get()->sum(fn ($a) => $a->outcome['credit_delta'] ?? 0);
            if ($agent->credits !== $run->plan['initial_credits'] + $run->totals['credit_delta'] + $manual - ($run->plan['initial_manual_credit_delta'] ?? 0)) {
                throw new GameException('Unexplained balance change before reservation. Review before a fresh approval.');
            }
        }
        $floor = max($arguments['reserve'] ?? 0, $active?->plan['reserve'] ?? 0);
        if ($active && ! $run) {
            $floor = max($floor, $active->plan['reserve'] + max(0, $active->plan['max_spend'] - ($active->totals['spending'] ?? $active->totals['fuel_cost'] ?? 0)));
        }
        $contract = null;
        if ($run?->strategy === 'contracts') {
            $step = $run->plan['contract_plan']['actions'][$run->totals['step_index']] ?? null;
            $approved = $step['arguments'] ?? [];
            $given = $arguments;
            unset($given['reserve']);
            if (! $step || $step['command'] !== $command || $given !== $approved) {
                throw new GameException('Command differs from the exact approved contract step.');
            }
            $expectedCargo = $run->plan['initial_inventory'];
            foreach (array_slice($run->plan['contract_plan']['actions'], 0, $run->totals['step_index']) as $prior) {
                if (in_array($prior['command'], ['buy', 'contract_deliver'], true)) {
                    $good = $prior['arguments']['symbol'];
                    $expectedCargo[$good] = ($expectedCargo[$good] ?? 0) + ($prior['command'] === 'buy' ? 1 : -1) * $prior['arguments']['units'];
                }
            }
            $expectedCargo = array_filter($expectedCargo);
            ksort($expectedCargo);
            $actualCargo = MiningPlanner::inventory($ship);
            ksort($actualCargo);
            if ($expectedCargo !== $actualCargo) {
                throw new GameException('Contract cargo changed outside approved actions.');
            }
            $contract = $this->api->contract($agent, $run->plan['contract_plan']['contract']['id']);
            if ($contract !== $step['contract_before'] || $contract !== $run->totals['contract']) {
                throw new GameException('Contract terms or progress changed. Fresh approval required.');
            }
            if (now()->addSeconds(120)->gte($contract['terms']['deadline']) || (! $contract['accepted'] && now()->gte($contract['deadlineToAccept'] ?? $contract['expiration']))) {
                throw new GameException('Contract timing allowance or acceptance deadline reached.');
            }
        } elseif (str_starts_with($command, 'contract_')) {
            throw new GameException('Contract operations require an exact approved contract run.');
        }
        $cost = 0;
        $estimate = ['credits' => $agent->credits, 'reserve' => $floor, 'cargo_free' => $ship['cargo']['capacity'] - $ship['cargo']['units'], 'quote_limit_warning' => 'Local quotes are estimates. SpaceTraders has no execution-price limit. A changed execution price pauses further work.'];
        if (($arguments['require_guaranteed_price'] ?? false)) {
            throw new GameException('A guaranteed upstream execution-price ceiling is unsupported.');
        }
        switch ($command) {
            case 'contract_accept':
                if ($contract['accepted'] || $contract['fulfilled']) {
                    throw new GameException('Contract is already accepted or fulfilled.');
                }
                break;
            case 'contract_deliver':
                $term = $contract['terms']['deliver'][$arguments['term_index']] ?? null;
                if (! $term || ! $contract['accepted'] || $contract['fulfilled'] || $ship['nav']['status'] !== 'DOCKED' || $ship['nav']['waypointSymbol'] !== $term['destinationSymbol'] || $arguments['symbol'] !== $term['tradeSymbol'] || $arguments['units'] > min($term['unitsRequired'] - $term['unitsFulfilled'], MiningPlanner::inventory($ship)[$arguments['symbol']] ?? 0)) {
                    throw new GameException('Delivery does not fit the approved ship, cargo, destination or remaining requirement.');
                }
                break;
            case 'contract_fulfill':
                if (! $contract['accepted'] || $contract['fulfilled'] || collect($contract['terms']['deliver'])->contains(fn ($t) => $t['unitsFulfilled'] !== $t['unitsRequired'])) {
                    throw new GameException('Contract cannot be fulfilled before every delivery is confirmed.');
                }
                break;
            case 'navigate':
                $this->orbit($ship);
                $target = $this->waypoint($agent, $arguments['waypointSymbol']);
                $from = $this->waypoint($agent, $ship['nav']['waypointSymbol']);
                if ($target['systemSymbol'] !== $ship['nav']['systemSymbol'] || $target['symbol'] === $from['symbol']) {
                    throw new GameException('Choose a different waypoint in this system.');
                }
                if ($ship['nav']['flightMode'] !== 'CRUISE') {
                    throw new GameException('Navigation preview requires CRUISE mode.');
                }
                $estimate['fuel_estimate'] = MiningPlanner::fuelEstimate($from, $target);
                if ($ship['fuel']['current'] < $estimate['fuel_estimate']) {
                    throw new GameException('Insufficient fuel for the conservative navigation estimate.');
                }
                break;
            case 'mine':
                $this->orbit($ship);
                if ($reason = $this->planner->capability($ship)) {
                    throw new GameException($reason);
                }
                $point = $this->waypoint($agent, $ship['nav']['waypointSymbol']);
                if (! in_array($point['type'], ['ASTEROID', 'ASTEROID_FIELD', 'ENGINEERED_ASTEROID'], true)) {
                    throw new GameException('This waypoint is not an ore extraction candidate.');
                }
                if (now()->lt($ship['cooldown']['expiration']) || $ship['cargo']['units'] >= $ship['cargo']['capacity']) {
                    throw new GameException('Extraction requires an expired cooldown and free cargo.');
                }
                break;
            case 'buy_ship':
                if (ShipAction::where('agent_id', $agent->id)->where('command', 'buy_ship')->get()->contains(fn ($a) => ! empty($a->outcome['delivered_ship']) || ! empty($a->outcome['unresolved_adjustment']))) {
                    throw new GameException('One-time setup purchase was already executed or acknowledged. Inspect the delivered fleet; further expansion is outside this MVP.');
                }
                if ($arguments['waypointSymbol'] !== $ship['nav']['waypointSymbol']) {
                    throw new GameException('Approve scouting movement to the shipyard first.');
                }
                foreach ($this->api->ships($agent) as $owned) {
                    if ($this->planner->capability($owned) === null) {
                        throw new GameException('An operational miner already exists. Setup purchase is unnecessary.');
                    }
                }
                $yard = $this->api->shipyard($agent, $arguments['waypointSymbol']);
                $this->sync->observe($agent, 'shipyard', $arguments['waypointSymbol'], $yard);
                $offer = collect($yard['ships'] ?? [])->firstWhere('type', $arguments['shipType']);
                if (! $offer || $this->planner->capability($offer, true) !== null) {
                    throw new GameException('The complete ship configuration is not verified as mining capable.');
                }
                $cost = $offer['purchasePrice'];
                $estimate['configuration'] = $offer;
                $estimate['scouting_cost'] = 0;
                // Apply the same route preflight to the complete offered ship before buying it.
                $template = $offer;
                $template['cargo'] = ['capacity' => collect($offer['modules'])->filter(fn ($m) => str_starts_with($m['symbol'] ?? '', 'MODULE_CARGO_HOLD_'))->sum('capacity'), 'units' => 0, 'inventory' => []];
                $template['crew']['current'] = $offer['crew']['capacity'];
                $template['nav'] = $ship['nav'];
                $template['fuel'] = ['current' => $offer['frame']['fuelCapacity'] ?? 0, 'capacity' => $offer['frame']['fuelCapacity'] ?? 0];
                $route = $this->planner->route($agent, $template, $arguments['site'], $arguments['market']);
                $floor = max($floor, $route['minimum_reserve']);
                $estimate['route'] = $route;
                $estimate['reserve'] = $floor;
                break;
            case 'buy':
            case 'sell':
            case 'refuel':
                if ($ship['nav']['status'] !== 'DOCKED') {
                    throw new GameException('Dock before trading or refueling.');
                }
                $market = $this->api->market($agent, $ship['nav']['waypointSymbol']);
                $this->sync->observe($agent, 'market', $ship['nav']['waypointSymbol'], $market);
                $good = collect($market['tradeGoods'] ?? [])->firstWhere('symbol', $command === 'refuel' ? 'FUEL' : $arguments['symbol']);
                if (! $good) {
                    throw new GameException('Price is unknown or this market does not trade the good.');
                }
                $units = $arguments['units'];
                if ($command !== 'refuel' && $units > $good['tradeVolume']) {
                    throw new GameException('Quantity exceeds the current trade volume.');
                }
                if ($command === 'buy' && $units > $ship['cargo']['capacity'] - $ship['cargo']['units']) {
                    throw new GameException('Quantity exceeds free cargo capacity.');
                }
                if ($command === 'sell' && $units > (MiningPlanner::inventory($ship)[$arguments['symbol']] ?? 0)) {
                    throw new GameException('Quantity exceeds owned cargo.');
                }
                if ($command === 'refuel' && ($units > $ship['fuel']['capacity'] - $ship['fuel']['current'] || (int) ceil($units / 100) > $good['tradeVolume'])) {
                    throw new GameException('Refuel quantity exceeds tank space or fuel trade volume.');
                }
                $estimate['quote'] = $good;
                $estimate['quote_at'] = now()->toIso8601String();
                if ($command === 'sell') {
                    $estimate['sale_estimate'] = $units * $good['sellPrice'];
                    if ($estimate['sale_estimate'] < ($arguments['minTotal'] ?? 0)) {
                        throw new GameException('Sale quote fell below the approved minimum. Create a new preview.');
                    }
                } else {
                    $cost = ($command === 'refuel' ? (int) ceil($units / 100) : $units) * $good['purchasePrice'];
                }
                break;
            case 'orbit':
            case 'dock':
                break;
            default:
                throw new GameException('Unsupported command.');
        }
        $estimate['cost_estimate'] = $cost;
        if ($cost > 0 && ($cost > ($arguments['maxTotal'] ?? 0) || $agent->credits - $cost < $floor)) {
            throw new GameException('The quote exceeds the approved spending ceiling or protected reserve.');
        }

        if ($run && $cost > $run->plan['max_spend'] - ($run->totals['spending'] ?? $run->totals['fuel_cost'] ?? 0)) {
            throw new GameException('Remaining approved spending is insufficient.');
        }

        return ['contract' => $contract, 'ship' => $ship, 'credits' => $agent->credits, 'estimate' => $estimate];
    }

    private function waypoint(Agent $agent, string $symbol): array
    {
        $point = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'waypoint')->where('symbol', $symbol)->first();
        if (! $point || $point->observed_at->lt(now()->subHour())) {
            throw new GameException('Waypoint is unknown or stale. Synchronize first.');
        }

        return $point->data;
    }

    private function orbit(array $ship): void
    {
        if ($ship['nav']['status'] !== 'IN_ORBIT') {
            throw new GameException('Enter orbit before navigating or mining.');
        }
    }
}
