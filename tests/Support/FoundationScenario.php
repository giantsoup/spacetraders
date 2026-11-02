<?php

namespace Tests\Support;

use App\Gameplay\ManualCommands;

class FoundationScenario
{
    public static function make(string $command, string $variant = 'standard'): array
    {
        $w = FakeSpaceTraders::world();
        $w['foundation'] = true;
        $a = ['reserve' => 2000];
        $orbit = in_array($command, ['mine', 'mine_survey', 'survey', 'siphon', 'navigate', 'scan_systems', 'scan_waypoints', 'scan_ships', 'chart', 'jump', 'warp']);
        if ($orbit) {
            $w['ships'][0]['nav']['status'] = 'IN_ORBIT';
        }
        $w['waypoints'][0]['faction'] = ['symbol' => 'COSMIC'];
        switch ($command) {
            case 'buy_ship': $w['offer']['type'] = 'SHIP_LIGHT_HAULER';
                $w['offer']['mounts'] = [];
                $a += ['shipType' => 'SHIP_LIGHT_HAULER', 'waypointSymbol' => 'X1-TEST-ROCK', 'maxTotal' => 20000];
                break;
            case 'buy': case 'sell': case 'jettison': case 'transfer': case 'supply':
                $w['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 3, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 3]]];
                $a += ['symbol' => 'IRON_ORE', 'units' => 1, 'maxTotal' => 30, 'minTotal' => 0, 'acknowledgeLoss' => true, 'targetShip' => 'LOCAL-2', 'waypointSymbol' => 'X1-TEST-ROCK'];
                if ($command === 'transfer') {
                    $w['ships'][] = Tests\Support\FakeSpaceTraders::ship('LOCAL-2');
                }
                if ($command === 'supply') {
                    $w['waypoints'][0]['isUnderConstruction'] = true;
                } break;
            case 'contract_accept': case 'contract_deliver': case 'contract_fulfill':
                $a += ['contractId' => 'contract-1', 'symbol' => 'IRON_ORE', 'units' => 1, 'term_index' => 0];
                if ($command !== 'contract_accept') {
                    $w['contracts'][0]['accepted'] = true;
                }
                if ($command === 'contract_deliver') {
                    $w['ships'][0]['nav']['waypointSymbol'] = 'X1-TEST-PORT';
                    $w['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 10, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 10]]];
                }
                if ($command === 'contract_fulfill') {
                    $w['contracts'][0]['terms']['deliver'][0]['unitsFulfilled'] = 10;
                } break;
            case 'negotiate': $w['contracts'] = [];
                break;
            case 'scrap': $a += ['confirmShip' => 'LOCAL-1', 'acknowledgeLoss' => true, 'minTotal' => 0];
                break;
            case 'repair': $w['ships'][0]['frame']['condition'] = 0.5;
                $a += ['maxTotal' => 100];
                break;
            case 'install_mount': case 'remove_mount': case 'install_module': case 'remove_module':
                $symbol = str_ends_with($command, 'mount') ? 'MOUNT_MINING_LASER_I' : ($command === 'remove_module' ? 'MODULE_MINERAL_PROCESSOR_I' : 'MODULE_GAS_PROCESSOR_I');
                if (str_starts_with($command, 'install')) {
                    $w['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 1, 'inventory' => [['symbol' => $symbol, 'units' => 1]]];
                }
                $a += ['symbol' => $symbol, 'maxTotal' => 100];
                break;
            case 'survey': $w['ships'][0]['mounts'][] = ['symbol' => 'MOUNT_SURVEYOR_I', 'requirements' => []];
                break;
            case 'mine_survey': case 'mine': $a += ['surveySignature' => 'browser-signed', 'surveyWaypoint' => 'X1-TEST-ROCK'];
                break;
            case 'siphon': $w['waypoints'][0]['type'] = 'GAS_GIANT';
                $w['ships'][0]['mounts'][] = ['symbol' => 'MOUNT_GAS_SIPHON_I', 'requirements' => []];
                $w['ships'][0]['modules'][] = ['symbol' => 'MODULE_GAS_PROCESSOR_I', 'requirements' => []];
                break;
            case 'refine': $w['ships'][0]['modules'][] = ['symbol' => 'MODULE_ORE_REFINERY_I', 'requirements' => []];
                $w['ships'][0]['cargo'] = ['capacity' => 200, 'units' => 100, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 100]]];
                $a += ['produce' => 'IRON'];
                break;
            case 'flight_mode': $a += ['flightMode' => 'BURN'];
                break;
            case 'navigate': $a += ['waypointSymbol' => 'X1-TEST-PORT', 'maxFuel' => 5, 'maxSeconds' => 60, 'acknowledgeUnknown' => true];
                break;
            case 'refuel': $w['ships'][0]['fuel']['current'] = 0;
                $w['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 2, 'inventory' => [['symbol' => 'FUEL', 'units' => 2]]];
                $a += ['units' => 37, 'fromCargo' => true, 'protectedFuel' => 0, 'maxCargoUnits' => 1, 'maxTotal' => 0];
                break;
            case 'scan_systems': case 'scan_waypoints': case 'scan_ships': $w['ships'][0]['mounts'][] = ['symbol' => 'MOUNT_SENSOR_ARRAY_I', 'requirements' => []];
                $a += ['maxCooldownSeconds' => 300];
                break;
            case 'chart': $w['waypoints'][0]['traits'] = [['symbol' => 'UNCHARTED']];
                $a += ['waypointSymbol' => 'X1-TEST-ROCK'];
                break;
            case 'jump': case 'warp':
                $w['waypoints'][0]['type'] = 'JUMP_GATE';
                $w['waypoints'][0]['isUnderConstruction'] = false;
                $w['waypoints'][] = ['symbol' => 'X1-NEXT-GATE', 'systemSymbol' => 'X1-NEXT', 'type' => 'JUMP_GATE', 'x' => 10, 'y' => 10, 'traits' => [], 'isUnderConstruction' => false];
                $w['ships'][0]['modules'][] = ['symbol' => $command === 'jump' ? 'MODULE_JUMP_DRIVE_I' : 'MODULE_WARP_DRIVE_I', 'requirements' => []];
                $a += ['waypointSymbol' => 'X1-NEXT-GATE', 'maxTotal' => 100, 'maxFuel' => 10, 'maxSeconds' => 60, 'acknowledgeUnknown' => true];
                break;
        }

        if ($variant === 'plain_extract') {
            $a = [];
        }
        if ($variant === 'market_refuel') {
            $a = ['reserve' => 2000, 'maxTotal' => 100, 'fromCargo' => false];
        }
        if ($variant === 'cruise') {
            $a = ['waypointSymbol' => 'X1-TEST-PORT'];
        }
        if ($variant === 'unchanged_mode') {
            $a = [];
        }
        if ($variant === 'transit_contract') {
            $w['ships'][0]['nav']['status'] = 'IN_TRANSIT';
            $w['ships'][0]['nav']['route']['arrival'] = now()->addHour()->toIso8601String();
        }

        return [$w, array_intersect_key($a, array_flip(ManualCommands::OPERATIONS[$command][2]))];
    }
}
