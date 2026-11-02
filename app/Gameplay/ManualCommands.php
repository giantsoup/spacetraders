<?php

namespace App\Gameplay;

use Illuminate\Validation\Rule;

final class ManualCommands
{
    // Native forms name domain commands, never an upstream URL or caller-selected HTTP method.
    public const OPERATIONS = [
        'orbit' => ['Enter orbit', 'Fleet', []], 'dock' => ['Dock', 'Fleet', []],
        'buy_ship' => ['Purchase offered ship', 'Fleet', ['shipType', 'waypointSymbol', 'maxTotal', 'reserve']],
        'buy' => ['Purchase cargo', 'Cargo', ['symbol', 'units', 'maxTotal', 'reserve']],
        'sell' => ['Sell cargo', 'Cargo', ['symbol', 'units', 'minTotal', 'reserve']],
        'transfer' => ['Transfer cargo', 'Cargo', ['symbol', 'units', 'targetShip']],
        'jettison' => ['Jettison cargo permanently', 'Cargo', ['symbol', 'units', 'acknowledgeLoss']],
        'contract_accept' => ['Accept contract', 'Contracts', ['contractId']],
        'contract_deliver' => ['Deliver contract goods', 'Contracts', ['contractId', 'symbol', 'units', 'term_index']],
        'contract_fulfill' => ['Fulfill contract', 'Contracts', ['contractId']],
        'negotiate' => ['Negotiate a contract offer', 'Contracts', []],
        'repair' => ['Repair condition', 'Maintenance', ['maxTotal', 'reserve']],
        'install_mount' => ['Install mount from cargo', 'Outfitting', ['symbol', 'maxTotal', 'reserve']],
        'remove_mount' => ['Remove installed mount', 'Outfitting', ['symbol', 'maxTotal', 'reserve']],
        'install_module' => ['Install module from cargo', 'Outfitting', ['symbol', 'maxTotal', 'reserve']],
        'remove_module' => ['Remove installed module', 'Outfitting', ['symbol', 'maxTotal', 'reserve']],
        'flight_mode' => ['Change flight mode', 'Navigation', ['flightMode']],
        'navigate' => ['Navigate to waypoint', 'Navigation', ['waypointSymbol', 'reserve', 'maxFuel', 'maxSeconds', 'acknowledgeUnknown']],
        'refuel' => ['Refuel tank', 'Navigation', ['units', 'fromCargo', 'protectedFuel', 'maxCargoUnits', 'maxTotal', 'reserve']],
        'siphon' => ['Siphon gas', 'Resources', []],
        'refine' => ['Refine cargo', 'Resources', ['produce']],
        'survey' => ['Create signed surveys', 'Resources', []], 'mine' => ['Extract resources', 'Resources', ['surveySignature', 'surveyWaypoint']],
        'mine_survey' => ['Extract with signed survey', 'Resources', ['surveySignature', 'surveyWaypoint']],
        'scan_systems' => ['Scan systems', 'Scans', ['maxCooldownSeconds']],
        'scan_ships' => ['Scan nearby ships', 'Scans', ['maxCooldownSeconds']],
        'jump' => ['Jump to connected gate', 'Navigation', ['waypointSymbol', 'maxTotal', 'reserve']],
        'warp' => ['Warp to another system', 'Navigation', ['waypointSymbol', 'maxFuel', 'maxSeconds', 'acknowledgeUnknown', 'reserve']],
        'supply' => ['Supply construction materials', 'Construction', ['waypointSymbol', 'symbol', 'units', 'acknowledgeLoss']],
        'scan_waypoints' => ['Scan waypoints', 'Scans', ['maxCooldownSeconds']],
        'chart' => ['Chart current waypoint', 'Scans', ['waypointSymbol']],
    ];

    public static function rules(string $command): array
    {
        $required = self::OPERATIONS[$command][2] ?? [];
        $fields = ['units', 'symbol', 'waypointSymbol', 'shipType', 'maxTotal', 'minTotal', 'reserve', 'site', 'market', 'require_guaranteed_price', 'targetShip', 'surveySignature', 'surveyWaypoint', 'question', 'maxCooldownSeconds', 'flightMode', 'contractId', 'term_index', 'acknowledgeLoss', 'produce', 'fromCargo', 'protectedFuel', 'maxCargoUnits', 'maxFuel', 'maxSeconds', 'acknowledgeUnknown'];
        $rules = [
            'key' => ['required', 'uuid'], 'ship_symbol' => [Rule::requiredIf(! in_array($command, ['contract_accept', 'contract_fulfill'], true)), 'nullable', 'string', 'max:80', 'regex:/^[A-Z0-9_-]+$/'],
            'command' => ['required', Rule::in(array_keys(self::OPERATIONS))], 'arguments' => ['present', 'array:'.implode(',', $fields)],
        ];
        foreach ($fields as $field) {
            $type = match ($field) {
                'units' => ['integer', 'between:1,100000'], 'term_index' => ['integer', 'between:0,19'],
                'maxTotal', 'minTotal', 'reserve' => ['integer', 'between:0,1000000000'],
                'maxSeconds' => ['integer', 'between:1,86400'],
                'maxFuel', 'protectedFuel', 'maxCargoUnits' => ['integer', 'between:0,100000'],
                'fromCargo', 'acknowledgeUnknown' => ['boolean'],
                'produce' => [Rule::in(['IRON', 'COPPER', 'SILVER', 'GOLD', 'ALUMINUM', 'PLATINUM', 'URANITE', 'MERITIUM', 'FUEL'])],
                'maxCooldownSeconds' => ['integer', 'between:0,900'],
                'acknowledgeLoss' => ['accepted'], 'require_guaranteed_price' => ['boolean'],
                'flightMode' => [Rule::in(['DRIFT', 'STEALTH', 'CRUISE', 'BURN'])],
                'shipType' => [Rule::in(config('foundation.enums.ShipType'))],
                'symbol' => [Rule::in(config('foundation.enums.TradeSymbol'))],
                'question' => [Rule::in(['MARKETPLACE', 'SHIPYARD'])],
                'contractId' => ['string', 'max:80', 'regex:/^[a-zA-Z0-9_-]+$/'],
                'surveySignature' => ['string', 'max:255'],
                default => ['string', 'max:80', 'regex:/^[A-Z0-9_-]+$/'],
            };
            $rules['arguments.'.$field] = array_merge([in_array($field, $required, true) && $field !== 'reserve' ? 'required' : 'sometimes'], $type);
        }
        foreach (['surveySignature', 'surveyWaypoint'] as $field) {
            $rules['arguments.'.$field][0] = $command === 'mine_survey' ? 'required' : 'sometimes';
            $rules['arguments.'.$field][] = 'required_with:arguments.'.($field === 'surveySignature' ? 'surveyWaypoint' : 'surveySignature');
        }
        foreach (['units', 'fromCargo', 'protectedFuel', 'maxCargoUnits', 'maxFuel', 'maxSeconds', 'acknowledgeUnknown'] as $field) {
            if (in_array($command, ['refuel', 'navigate'], true)) {
                $rules['arguments.'.$field][0] = 'sometimes';
            }
        }
        if ($command === 'scan_waypoints') {
            $rules['arguments.question'][0] = 'required_with:arguments.waypointSymbol';
        }
        // The official PATCH body permits an empty object. Omitting the mode is an intentional no-change variant.
        $rules['arguments.flightMode'][0] = 'sometimes';
        $rules['arguments.site'][] = 'required_with:arguments.market';
        $rules['arguments.market'][] = 'required_with:arguments.site';

        return $rules;
    }
}
