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
        $agentCommand = ! $run && in_array($command, ['contract_accept', 'contract_fulfill'], true);
        if (! $agentCommand && ! Ship::where('agent_id', $agent->id)->where('symbol', $symbol)->exists()) {
            throw new GameException('Ship is not in the saved owned fleet. Synchronize first.');
        }
        $this->sync->guardReset($agent);
        $agent->update(['credits' => $this->api->agent($agent)['credits']]);
        $ship = $agentCommand ? null : $this->sync->refreshShip($agent, $symbol)->snapshot;
        if ($ship && $ship['nav']['status'] === 'IN_TRANSIT' && $command !== 'flight_mode') {
            throw new GameException('Ship is in transit. Reconcile after the reported arrival.');
        }
        if ($ship && in_array($command, ['survey', 'mine', 'mine_survey', 'siphon', 'refine', 'scan_systems', 'scan_waypoints', 'scan_ships', 'jump', 'warp'], true)) {
            $components = array_merge($ship['mounts'], $ship['modules'], [$ship['frame'], $ship['engine'], $ship['reactor']]);
            if ($ship['crew']['current'] < $ship['crew']['required'] || array_sum(array_map(fn ($item) => $item['requirements']['power'] ?? 0, $components)) > $ship['reactor']['powerOutput']) {
                throw new GameException('Crew or known equipment power requirements exceed available capacity.');
            }
        }
        $active = AutomationRun::where('agent_id', $agent->id)->whereNotNull('active_agent_id')->first();
        if ($active && ($agentCommand || $active->ship_symbol === $symbol) && $active->id !== $run?->id) {
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
        $spending = in_array($command, ['buy', 'buy_ship', 'repair', 'install_mount', 'remove_mount', 'install_module', 'remove_module', 'jump', 'refuel'], true);
        $floor = max($arguments['reserve'] ?? 0, $active?->plan['reserve'] ?? 0, ! $run && $spending ? max(1, (int) config('gameplay.manual_reserve_floor')) : 0);
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
            $contract = $this->api->contract($agent, $arguments['contractId']);
            if (now()->gte($contract['terms']['deadline']) || (! $contract['accepted'] && now()->gte($contract['deadlineToAccept'] ?? $contract['expiration']))) {
                throw new GameException('Contract deadline has passed.');
            }
        }
        $cost = 0;
        $estimate = ['credits' => $agent->credits, 'reserve' => $floor, 'cargo_free' => $ship ? $ship['cargo']['capacity'] - $ship['cargo']['units'] : null, 'quote_limit_warning' => 'Local quotes are estimates. SpaceTraders has no execution-price limit. A changed execution price pauses further work.'];
        if (($arguments['require_guaranteed_price'] ?? false)) {
            throw new GameException('A guaranteed upstream execution-price ceiling is unsupported.');
        }
        switch ($command) {
            case 'supply':
                if (empty($arguments['acknowledgeLoss']) || $arguments['waypointSymbol'] !== $ship['nav']['waypointSymbol']) {
                    throw new GameException('Acknowledge irreversible material consumption at the current site.');
                }
                $point = $this->api->waypoint($agent, $arguments['waypointSymbol']);
                if (($point['isUnderConstruction'] ?? null) !== true) {
                    throw new GameException('The waypoint is not confirmed under construction.');
                }
                $system = $point['systemSymbol'];
                $construction = $this->api->manualRead($agent, 'get-construction', ['systemSymbol' => $system, 'waypointSymbol' => $point['symbol']]);
                $material = collect($construction['data']['materials'])->firstWhere('tradeSymbol', $arguments['symbol']);
                if ($construction['data']['isComplete'] || ! $material || $arguments['units'] > $material['required'] - $material['fulfilled'] || $arguments['units'] > app(CargoAuthority::class)->available($agent, $ship, $arguments['symbol'])) {
                    throw new GameException('Contribution exceeds current outstanding demand or unprotected cargo.');
                }
                $estimate['construction'] = $construction['data'];
                $estimate['effects'] = 'Consumes the selected material irreversibly. Other agents can contribute concurrently. No payment or refund is promised; location/state rules still require live acceptance.';
                break;
            case 'jump':
            case 'warp':
                $this->orbit($ship);
                $target = $this->api->waypoint($agent, $arguments['waypointSymbol']);
                $from = $this->api->waypoint($agent, $ship['nav']['waypointSymbol']);
                $this->sync->observe($agent, 'waypoint', $target['symbol'], $target);
                if ($target['systemSymbol'] === $ship['nav']['systemSymbol']) {
                    throw new GameException('Select a destination in another system.');
                }
                $drive = $command === 'jump' ? 'MODULE_JUMP_DRIVE_' : 'MODULE_WARP_DRIVE_';
                if (! collect($ship['modules'])->contains(fn ($item) => str_starts_with($item['symbol'], $drive))) {
                    throw new GameException($command === 'jump' ? 'Jump client policy requires an observed jump-drive module; drive restrictions remain a live gate.' : 'The required warp drive is not installed.');
                }
                if ($command === 'jump') {
                    if (now()->lt($ship['cooldown']['readyAt']) || $from['type'] !== 'JUMP_GATE' || $target['type'] !== 'JUMP_GATE' || ($from['isUnderConstruction'] ?? null) !== false || ($target['isUnderConstruction'] ?? null) !== false) {
                        throw new GameException('Jump client policy requires ready cooldown and two confirmed completed jump gates.');
                    }
                    $gate = $this->api->manualRead($agent, 'get-jump-gate', ['systemSymbol' => $from['systemSymbol'], 'waypointSymbol' => $from['symbol']]);
                    if (! in_array($target['symbol'], $gate['data']['connections'], true)) {
                        throw new GameException('The destination is not a connected gate.');
                    }
                    $cost = $arguments['maxTotal'];
                    $estimate['fee_unknown'] = true;
                    $estimate['gate'] = $gate['data'];
                    $estimate['authorized_exposure'] = $cost;
                    $estimate['effects'] = 'Jump client policy requires an observed drive and completed gate endpoints. Instant gate travel buys antimatter at an unknown variable price and incurs a returned cooldown. The local ceiling cannot cap the server charge.';
                } else {
                    if (empty($arguments['acknowledgeUnknown']) || $arguments['maxFuel'] > $ship['fuel']['current']) {
                        throw new GameException('Warp requires explicit fuel/time review limits and acknowledgement of unknown mechanics.');
                    }
                    $estimate['fuel_estimate'] = $arguments['maxFuel'];
                    $estimate['route'] = ['from' => $from, 'to' => $target, 'max_seconds' => $arguments['maxSeconds'], 'calibration' => 'pending'];
                    $estimate['effects'] = 'Warp fuel/time formulas are uncalibrated. Review thresholds cannot cap the server consumption or arrival. No automatic continuation is approved.';
                }
                break;
            case 'siphon':
                $this->orbit($ship);
                $point = $this->api->waypoint($agent, $ship['nav']['waypointSymbol']);
                if ($point['type'] !== 'GAS_GIANT' || ! collect($ship['mounts'])->contains(fn ($item) => str_starts_with($item['symbol'], 'MOUNT_GAS_SIPHON_')) || ! collect($ship['modules'])->contains(fn ($item) => str_starts_with($item['symbol'], 'MODULE_GAS_PROCESSOR_'))) {
                    throw new GameException('Siphoning requires a gas giant, gas siphon mount and gas processor.');
                }
                if (now()->lt($ship['cooldown']['readyAt']) || $ship['cargo']['units'] >= $ship['cargo']['capacity']) {
                    throw new GameException('Wait for cooldown and free cargo before siphoning.');
                }
                $estimate['effects'] = 'Consumes a cooldown and adds the actual returned gas yield. Amount, condition events and duration are unknown before execution.';
                break;
            case 'refine':
                $prefix = $arguments['produce'] === 'FUEL' ? 'MODULE_FUEL_REFINERY_' : 'MODULE_ORE_REFINERY_';
                if (! collect($ship['modules'])->contains(fn ($item) => str_starts_with($item['symbol'], $prefix)) || now()->lt($ship['cooldown']['readyAt']) || $ship['cargo']['units'] < 100) {
                    throw new GameException('Refinery client policy requires suitable equipment, ready cooldown and at least 100 total cargo units; recipe calibration is pending and no override is available.');
                }
                foreach (MiningPlanner::inventory($ship) as $good => $owned) {
                    if (app(CargoAuthority::class)->available($agent, $ship, $good) !== $owned) {
                        throw new GameException('Protected cargo may not be exposed to an uncalibrated refinery recipe.');
                    }
                }
                $estimate['effects'] = 'Refinery client policy requires at least 100 cargo units; no local override is available. Requests the selected product. The API chooses inputs and batch size. Exact recipe and yield remain uncalibrated; consumed and produced cargo are recorded from the response.';
                break;
            case 'scrap':
                if (($arguments['confirmShip'] ?? null) !== $symbol || empty($arguments['acknowledgeLoss']) || $active) {
                    throw new GameException('Type the exact ship symbol, acknowledge permanent ship/cargo/equipment loss, and stop active automation before scrapping.');
                }
                app(EquipmentRules::class)->shipyard($agent, $ship);
                foreach (MiningPlanner::inventory($ship) as $good => $owned) {
                    if (app(CargoAuthority::class)->available($agent, $ship, $good) !== $owned) {
                        throw new GameException('Resolve protected contract cargo before scrapping this ship.');
                    }
                }
                $estimate['scrap_quote'] = $this->api->manualRead($agent, 'get-scrap-ship', ['shipSymbol' => $symbol]);
                $estimate['sale_estimate'] = $estimate['scrap_quote']['data']['transaction']['totalPrice'];
                if ($estimate['sale_estimate'] < ($arguments['minTotal'] ?? 0)) {
                    throw new GameException('Scrap quote is below the approved minimum.');
                }
                $estimate['effects'] = 'Permanently removes this exact ship. Cargo and equipment disposition is not promised by the API. Assume all aboard is lost. The quote reserves no price; Stop cannot reverse a sent request.';
                break;
            case 'repair':
                app(EquipmentRules::class)->shipyard($agent, $ship);
                $quote = $this->api->manualRead($agent, 'get-repair-ship', ['shipSymbol' => $symbol]);
                $cost = $quote['data']['transaction']['totalPrice'];
                $estimate['maintenance_quote'] = $quote;
                $estimate['effects'] = 'Repairs condition. Integrity is non-repairable and can decrease. This quote does not reserve a price.';
                break;
            case 'install_mount':
            case 'remove_mount':
            case 'install_module':
            case 'remove_module':
                $estimate['equipment'] = app(EquipmentRules::class)->inspect($agent, $ship, $command, $arguments['symbol']);
                $estimate['fee_unknown'] = true;
                $estimate['authorized_exposure'] = $arguments['maxTotal'];
                $cost = $arguments['maxTotal'];
                $estimate['effects'] = 'Changes installed equipment and cargo. Fee is unknown before execution. The local spending ceiling cannot cap the server fee; a discrepancy pauses further work.';
                break;
            case 'jettison':
                if (empty($arguments['acknowledgeLoss']) || $arguments['units'] > app(CargoAuthority::class)->available($agent, $ship, $arguments['symbol'])) {
                    throw new GameException('Acknowledge permanent loss and choose unprotected owned cargo.');
                }
                $estimate['effects'] = 'Permanently discards the exact approved cargo without payment. No reversal is available.';
                break;
            case 'negotiate':
                $point = $this->api->waypoint($agent, $ship['nav']['waypointSymbol']);
                if (empty($point['faction']['symbol']) || collect($this->api->contracts($agent))->contains(fn ($row) => ! $row['fulfilled'] && now()->lt($row['expiration']))) {
                    throw new GameException('Negotiation requires a faction at this waypoint and no current offered or ongoing contract.');
                }
                $estimate['effects'] = 'Creates a contract offer. Acceptance and its obligations require a separate approval.';
                break;
            case 'flight_mode':
                if (isset($arguments['flightMode']) && ! in_array($arguments['flightMode'], ['DRIFT', 'STEALTH', 'CRUISE', 'BURN'], true)) {
                    throw new GameException('Unsupported flight mode.');
                }
                $estimate['effects'] = 'Changes flight mode; returned fuel and condition events are authoritative. Travel needs separate approval.';
                break;
            case 'scan_systems':
            case 'scan_ships':
            case 'scan_waypoints':
            case 'chart':
                if (isset($arguments['question'])) {
                    $estimate['exploration'] = app(ExplorationPolicy::class)->inspect($agent, $ship, $command, $arguments, $floor);
                    break;
                }
                $this->orbit($ship);
                if (now()->lt($ship['cooldown']['readyAt'])) {
                    throw new GameException('Wait for cooldown before this manual observation.');
                }
                if ($command !== 'chart' && ! collect($ship['mounts'])->contains(fn ($item) => str_starts_with($item['symbol'], 'MOUNT_SENSOR_ARRAY_'))) {
                    throw new GameException('Scanning requires a Sensor Array mount.');
                }
                if ($command === 'chart' && $arguments['waypointSymbol'] !== $ship['nav']['waypointSymbol']) {
                    throw new GameException('Charting must name the current waypoint.');
                }
                $estimate['effects'] = 'One observation only. Scan range and duration are unknown. Orbit and cooldown readiness are conservative client policies. No strategy question, travel or follow-up is required.';
                break;
            case 'contract_accept':
                $estimate['effects'] = 'Accepts the displayed delivery obligations and deadline. Pays the displayed acceptance reward; no cargo purchase or automatic delivery is authorized.';
                if ($contract['accepted'] || $contract['fulfilled']) {
                    throw new GameException('Contract is already accepted or fulfilled.');
                }
                break;
            case 'contract_deliver':
                $estimate['effects'] = 'Transfers the selected cargo permanently toward the displayed obligation. Fulfillment and its reward require a separate approval.';
                $term = $contract['terms']['deliver'][$arguments['term_index']] ?? null;
                if (! $term || ! $contract['accepted'] || $contract['fulfilled'] || $ship['nav']['status'] !== 'DOCKED' || $ship['nav']['waypointSymbol'] !== $term['destinationSymbol'] || $arguments['symbol'] !== $term['tradeSymbol'] || $arguments['units'] > min($term['unitsRequired'] - $term['unitsFulfilled'], MiningPlanner::inventory($ship)[$arguments['symbol']] ?? 0)) {
                    throw new GameException('Delivery does not fit the approved ship, cargo, destination or remaining requirement.');
                }
                break;
            case 'contract_fulfill':
                $estimate['effects'] = 'Closes the displayed completed contract and collects its fulfillment reward. No follow-up is authorized.';
                if (! $contract['accepted'] || $contract['fulfilled'] || collect($contract['terms']['deliver'])->contains(fn ($t) => $t['unitsFulfilled'] !== $t['unitsRequired'])) {
                    throw new GameException('Contract cannot be fulfilled before every delivery is confirmed.');
                }
                break;
            case 'navigate':
                $this->orbit($ship);
                $target = $run ? $this->waypoint($agent, $arguments['waypointSymbol']) : $this->api->waypoint($agent, $arguments['waypointSymbol']);
                $from = $run ? $this->waypoint($agent, $ship['nav']['waypointSymbol']) : $this->api->waypoint($agent, $ship['nav']['waypointSymbol']);
                if (! $run) {
                    $this->sync->observe($agent, 'waypoint', $target['symbol'], $target);
                    $this->sync->observe($agent, 'waypoint', $from['symbol'], $from);
                }
                if ($target['systemSymbol'] !== $ship['nav']['systemSymbol'] || $target['symbol'] === $from['symbol']) {
                    throw new GameException('Choose a different waypoint in this system.');
                }
                if (! $run && (isset($arguments['maxFuel']) || $ship['nav']['flightMode'] !== 'CRUISE' || $ship['fuel']['capacity'] === 0)) {
                    if (empty($arguments['acknowledgeUnknown']) || ! isset($arguments['maxFuel'], $arguments['maxSeconds']) || $arguments['maxFuel'] > $ship['fuel']['current']) {
                        throw new GameException('This navigation variant needs explicit fuel/time review limits and acknowledgement of uncalibrated mechanics.');
                    }
                    $estimate['fuel_estimate'] = $arguments['maxFuel'];
                    $estimate['route'] = ['from' => $from, 'to' => $target, 'mode' => $ship['nav']['flightMode'], 'max_seconds' => $arguments['maxSeconds'], 'calibration' => 'pending', 'continuation' => 'No verified continuation for this variant. No automatic follow-up.'];
                    $estimate['effects'] = 'Fuel and elapsed-time values are operator review thresholds, not formulas or server caps. Actual server route and fuel settle this action. Uncalibrated travel can strand a ship.';
                    break;
                }
                if (in_array('MARKETPLACE', array_column($from['traits'], 'symbol'), true)) {
                    $this->sync->observe($agent, 'market', $from['symbol'], $this->api->market($agent, $from['symbol']));
                }
                try {
                    $estimate['route'] = $run?->strategy === 'mining'
                        ? app(FuelPlanner::class)->miningLeg($agent, $ship, $target['symbol'], $run->plan)
                        : app(FuelPlanner::class)->leg($agent, $ship, $target['symbol'], $floor, policy: $run ? null : 'conservative');
                } catch (GameException $e) {
                    if ($run) {
                        throw $e;
                    }
                    throw new GameException('CRUISE client policy requires fresh fuel sellers and a verified continuation: '.$e->getMessage().' Use explicit maxFuel/maxSeconds and acknowledgement to review the uncalibrated manual variant.');
                }
                $estimate['effects'] = 'CRUISE client policy requires a fresh route, known fuel sellers and a verified continuation. Explicit fuel/time review limits with acknowledgement select the uncalibrated manual variant instead.';
                $estimate['fuel_estimate'] = $estimate['route']['outbound_fuel'];
                if ($run && isset($run->plan['max_fuel']) && ($run->totals['travel_fuel'] ?? 0) + $estimate['fuel_estimate'] > $run->plan['max_fuel']) {
                    throw new GameException('Navigation exceeds the remaining approved travel-fuel budget. Replan; do not repeat a failed rescue route.');
                }
                break;
            case 'mine':
            case 'mine_survey':
                $estimate['effects'] = 'Mining client policy requires a mining laser, mineral processor, 50% condition/integrity floor and a non-hazardous asteroid. One extraction only; no strategy or automatic follow-up.';
                $this->orbit($ship);
                if ($reason = $this->planner->capability($ship)) {
                    throw new GameException('Mining client policy: '.$reason);
                }
                $point = $this->waypoint($agent, $ship['nav']['waypointSymbol']);
                $point = $this->api->waypoint($agent, $point['symbol']);
                $this->sync->observe($agent, 'waypoint', $point['symbol'], $point, 'read', $symbol);
                if ($reason = MiningPlanner::hazard($point)) {
                    throw new GameException('Mining client policy: '.$reason);
                }
                if ($run) {
                    app(FuelPlanner::class)->miningLeg($agent, $ship, $run->plan['market'], $run->plan);
                    if ($ship['cargo']['capacity'] - $ship['cargo']['units'] < ($run->plan['extraction_headroom'] ?? $ship['cargo']['capacity'])) {
                        throw new GameException('Approved extraction headroom is unavailable. Cargo retained.');
                    }
                }
                if (now()->lt($ship['cooldown']['readyAt']) || $ship['cargo']['units'] >= $ship['cargo']['capacity']) {
                    throw new GameException('Extraction requires an expired cooldown and free cargo.');
                }
                if ($command === 'mine_survey' || isset($arguments['surveySignature'])) {
                    if ($arguments['surveyWaypoint'] !== $ship['nav']['waypointSymbol']) {
                        throw new GameException('Survey location differs from the current waypoint.');
                    }
                    $signed = app(SurveyPool::class)->usable($agent, $arguments['surveySignature'], $ship['nav']['waypointSymbol']);
                    $estimate['survey_summary'] = app(SurveyPool::class)->assess($agent, $signed, $ship);
                }
                break;
            case 'survey':
                $estimate['effects'] = 'Survey client policy requires a surveyor, ready cooldown and a non-hazardous asteroid. No local override is available.';
                $this->orbit($ship);
                if (! collect($ship['mounts'])->contains(fn ($mount) => str_starts_with($mount['symbol'], 'MOUNT_SURVEYOR_')) || now()->lt($ship['cooldown']['readyAt'])) {
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
                if (now()->lt($ship['cooldown']['readyAt']) || now()->lt($target['cooldown']['readyAt'])) {
                    throw new GameException('Transfer client policy requires both cooldowns ready; no policy override is available.');
                }
                if ($arguments['units'] > (MiningPlanner::inventory($ship)[$arguments['symbol']] ?? 0) || $arguments['units'] > $target['cargo']['capacity'] - $target['cargo']['units']) {
                    throw new GameException('Transfer exceeds source inventory or receiving cargo capacity.');
                }
                $estimate['target_ship'] = $target;
                break;
            case 'buy_ship':
                $setup = isset($arguments['site'], $arguments['market']);
                if ($setup && ShipAction::where('agent_id', $agent->id)->where('command', 'buy_ship')->get()->contains(fn ($a) => ! empty($a->outcome['delivered_ship']) || ! empty($a->outcome['unresolved_adjustment']))) {
                    throw new GameException('One-time setup purchase was already executed or acknowledged. Inspect the delivered fleet; further expansion is outside this MVP.');
                }
                if ($arguments['waypointSymbol'] !== $ship['nav']['waypointSymbol']) {
                    throw new GameException('Approve scouting movement to the shipyard first.');
                }
                foreach ($setup ? $this->api->ships($agent) : [] as $owned) {
                    if ($this->planner->capability($owned) === null) {
                        throw new GameException('An operational miner already exists. Setup purchase is unnecessary.');
                    }
                }
                $estimate['fleet_symbols'] = array_column($this->api->ships($agent), 'symbol');
                $yard = $this->api->shipyard($agent, $arguments['waypointSymbol']);
                $this->sync->observe($agent, 'shipyard', $arguments['waypointSymbol'], $yard);
                $offer = collect($yard['ships'] ?? [])->firstWhere('type', $arguments['shipType']);
                if (! $offer || ! isset($offer['frame']['symbol'], $offer['reactor']['symbol'], $offer['engine']['symbol'], $offer['modules'], $offer['mounts'], $offer['crew']['capacity']) || ($setup && $this->planner->capability($offer, true) !== null)) {
                    throw new GameException('The complete ship configuration is not verified as mining capable.');
                }
                $cost = $offer['purchasePrice'];
                $estimate['configuration'] = $offer;
                $estimate['scouting_cost'] = 0;
                if (! $setup) {
                    $estimate['effects'] = 'Purchases this exact offered configuration. No mining setup or automatic follow-up is authorized.';
                    break;
                }
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
                if ($command === 'refuel') {
                    $tankUnits = min($arguments['units'] ?? ($ship['fuel']['capacity'] - $ship['fuel']['current']), $ship['fuel']['capacity'] - $ship['fuel']['current']);
                    $estimate['tank_refuel'] = ['source' => ($arguments['fromCargo'] ?? false) ? 'cargo' : 'market',
                        'tank_before' => $ship['fuel']['current'], 'tank_after' => $ship['fuel']['current'] + $tankUnits,
                        'tank_units' => $tankUnits, 'capacity' => $ship['fuel']['capacity'], 'omitted_units' => ! isset($arguments['units'])];
                }
                if ($command === 'refuel' && ($arguments['fromCargo'] ?? false)) {
                    $estimate['cargo_refuel'] = CargoRefuel::preview($ship, $arguments, $this->api->contracts($agent), $agent->credits, $floor);
                    $estimate['effects'] = 'Uses owned FUEL cargo. Whole-unit and rounding evidence are shown separately. Returned cargo, fuel and credits are authoritative.';
                    break;
                }
                if ($ship['nav']['status'] !== 'DOCKED') {
                    throw new GameException('Dock before trading or refueling.');
                }
                $market = $this->api->market($agent, $ship['nav']['waypointSymbol']);
                $this->sync->observe($agent, 'market', $ship['nav']['waypointSymbol'], $market);
                $good = collect($market['tradeGoods'] ?? [])->firstWhere('symbol', $command === 'refuel' ? 'FUEL' : $arguments['symbol']);
                if (! $good) {
                    throw new GameException('Price is unknown or this market does not trade the good.');
                }
                $units = $command === 'refuel' ? min($arguments['units'] ?? ($ship['fuel']['capacity'] - $ship['fuel']['current']), $ship['fuel']['capacity'] - $ship['fuel']['current']) : $arguments['units'];
                if ($units < 1) {
                    throw new GameException('The fuel tank is already full.');
                }
                if ($command !== 'refuel' && $units > $good['tradeVolume']) {
                    throw new GameException('Quantity exceeds the current trade volume.');
                }
                if ($command === 'buy' && $units > $ship['cargo']['capacity'] - $ship['cargo']['units']) {
                    throw new GameException('Quantity exceeds free cargo capacity.');
                }
                if ($command === 'sell' && $units > ($run ? (MiningPlanner::inventory($ship)[$arguments['symbol']] ?? 0) : app(CargoAuthority::class)->available($agent, $ship, $arguments['symbol']))) {
                    throw new GameException('Quantity exceeds owned cargo.');
                }
                if ($command === 'refuel' && ((int) ceil($units / 100) > $good['tradeVolume'])) {
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
        $estimate['cost_estimate'] = ! empty($estimate['fee_unknown']) ? null : $cost;
        if (($cost > 0 || ! empty($estimate['fee_unknown'])) && ($cost > ($arguments['maxTotal'] ?? 0) || $agent->credits - $cost < $floor)) {
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
