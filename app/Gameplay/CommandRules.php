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
            foreach (['frame', 'engine', 'reactor'] as $part) {
                if ($ship[$part]['condition'] < 0.5 || $ship[$part]['integrity'] < 0.5) {
                    throw new GameException('Ship condition or integrity fell below the operating floor.');
                }
            }
            if ($ship['crew']['current'] < $ship['crew']['required'] || $ship['nav']['flightMode'] !== 'CRUISE') {
                throw new GameException('Crew or flight mode changed outside the approved run.');
            }
            $expected = $run->totals['expected_state'] ?? ['waypoint' => $run->plan['initial_waypoint'], 'status' => $run->plan['initial_status'], 'fuel' => $run->plan['initial_fuel']];
            $expectedStatus = $expected['status'] === 'IN_TRANSIT' ? 'IN_ORBIT' : $expected['status'];
            if ($ship['nav']['waypointSymbol'] !== $expected['waypoint'] || $ship['nav']['status'] !== $expectedStatus || $ship['fuel']['current'] !== $expected['fuel'] || (isset($run->plan['equipment']) && OpportunityEvaluator::equipment($ship) !== $run->plan['equipment'])) {
                throw new GameException('Ship location, fuel or equipment changed outside confirmed run actions. Fresh preview required.');
            }
            $expectedInventory = $run->totals['unsold'] ?? $run->plan['initial_inventory'];
            ksort($expectedInventory);
            $actualInventory = MiningPlanner::inventory($ship);
            ksort($actualInventory);
            if ($expectedInventory !== $actualInventory) {
                throw new GameException('Cargo changed outside this run. Reconcile protected inventory before continuing.');
            }
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
            case 'scan_waypoints':
            case 'chart':
                $estimate['exploration'] = app(ExplorationPolicy::class)->inspect($agent, $ship, $command, $arguments, $floor);
                break;
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
                if (in_array('MARKETPLACE', array_column($from['traits'], 'symbol'), true)) {
                    $this->sync->observe($agent, 'market', $from['symbol'], $this->api->market($agent, $from['symbol']));
                }
                $estimate['route'] = $run?->strategy === 'mining'
                    ? app(FuelPlanner::class)->miningLeg($agent, $ship, $target['symbol'], $run->plan)
                    : app(FuelPlanner::class)->leg($agent, $ship, $target['symbol'], $floor, policy: $run ? null : 'conservative');
                $estimate['fuel_estimate'] = $estimate['route']['outbound_fuel'];
                if ($run && isset($run->plan['max_fuel']) && ($run->totals['travel_fuel'] ?? 0) + $estimate['fuel_estimate'] > $run->plan['max_fuel']) {
                    throw new GameException('Navigation exceeds the remaining approved travel-fuel budget. Replan; do not repeat a failed rescue route.');
                }
                break;
            case 'mine':
            case 'mine_survey':
                $this->orbit($ship);
                if ($reason = $this->planner->capability($ship)) {
                    throw new GameException($reason);
                }
                $point = $this->waypoint($agent, $ship['nav']['waypointSymbol']);
                $point = $this->api->waypoint($agent, $point['symbol']);
                $this->sync->observe($agent, 'waypoint', $point['symbol'], $point, 'read', $symbol);
                if ($reason = MiningPlanner::hazard($point)) {
                    throw new GameException($reason);
                }
                if ($run) {
                    app(FuelPlanner::class)->miningLeg($agent, $ship, $run->plan['market'], $run->plan);
                    if ($ship['cargo']['capacity'] - $ship['cargo']['units'] < ($run->plan['extraction_headroom'] ?? $ship['cargo']['capacity'])) {
                        throw new GameException('Approved extraction headroom is unavailable. Cargo retained.');
                    }
                }
                if (now()->lt($ship['cooldown']['expiration']) || $ship['cargo']['units'] >= $ship['cargo']['capacity']) {
                    throw new GameException('Extraction requires an expired cooldown and free cargo.');
                }
                if ($command === 'mine_survey') {
                    if ($arguments['surveyWaypoint'] !== $ship['nav']['waypointSymbol']) {
                        throw new GameException('Survey location differs from the current waypoint.');
                    }
                    $signed = app(SurveyPool::class)->usable($agent, $arguments['surveySignature'], $ship['nav']['waypointSymbol']);
                    $estimate['survey_summary'] = app(SurveyPool::class)->assess($agent, $signed, $ship);
                }
                break;
            case 'survey':
                $this->orbit($ship);
                if (! collect($ship['mounts'])->contains(fn ($mount) => str_starts_with($mount['symbol'], 'MOUNT_SURVEYOR_')) || now()->lt($ship['cooldown']['expiration'])) {
                    throw new GameException('Survey creation requires a Surveyor mount and expired cooldown.');
                }
                $point = $this->api->waypoint($agent, $ship['nav']['waypointSymbol']);
                $this->sync->observe($agent, 'waypoint', $point['symbol'], $point, 'read', $symbol);
                if ($reason = MiningPlanner::hazard($point)) {
                    throw new GameException($reason);
                }
                break;
            case 'transfer':
                $target = Ship::where('agent_id', $agent->id)->where('symbol', $arguments['targetShip'])->first();
                if (! $target || $target->symbol === $symbol || ($active && $active->ship_symbol === $target->symbol && $active->id !== $run?->id)) {
                    throw new GameException('Transfer target must be another owned ship available for manual control.');
                }
                $target = $this->sync->refreshShip($agent, $target->symbol)->snapshot;
                if ($target['nav']['waypointSymbol'] !== $ship['nav']['waypointSymbol'] || $target['nav']['status'] !== $ship['nav']['status'] || $target['nav']['status'] === 'IN_TRANSIT') {
                    throw new GameException('Transfers require the same waypoint and matching docked or orbiting states.');
                }
                if (now()->lt($ship['cooldown']['expiration']) || now()->lt($target['cooldown']['expiration'])) {
                    throw new GameException('Wait for both cooldowns before transferring.');
                }
                if ($arguments['units'] > (MiningPlanner::inventory($ship)[$arguments['symbol']] ?? 0) || $arguments['units'] > $target['cargo']['capacity'] - $target['cargo']['units']) {
                    throw new GameException('Transfer exceeds source inventory or receiving cargo capacity.');
                }
                $estimate['target_ship'] = $target;
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
                if ($run?->strategy === 'trade' && $command === 'buy') {
                    $observer = Ship::where('agent_id', $agent->id)->get()->contains(fn ($owned) => $owned->snapshot['nav']['waypointSymbol'] === $run->plan['market'] && $owned->snapshot['nav']['status'] !== 'IN_TRANSIT');
                    if ($observer) {
                        $this->sync->observe($agent, 'market', $run->plan['market'], $this->api->market($agent, $run->plan['market']));
                    }
                    $next = app(OperationPlanner::class)->next($agent, $ship, $run->plan, $run->totals, now()->toImmutable());
                    if ($next->command !== 'buy' || $units > ($next->arguments['units'] ?? 0)) {
                        throw new GameException($next->reason ?? 'Trade batch changed. Reassess the remaining cargo before buying.');
                    }
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
        if ($run && $cost > 0 && (($run->totals['purchase_cost'] ?? 0) + $run->totals['fuel_cost'] + $cost > $run->plan['max_spend'] || -$run->plan['max_loss'] > $run->totals['credit_delta'] - $cost)) {
            throw new GameException('Dispatch would exceed the remaining run spending or cash-loss limit.');
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
