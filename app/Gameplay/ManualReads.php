<?php

namespace App\Gameplay;

use Illuminate\Validation\Rule;

final class ManualReads
{
    public const OPERATIONS = [
        'get-status' => ['Server status', [], false],
        'get-systems' => ['Systems', [], true],
        'get-system' => ['System detail', ['systemSymbol'], false],
        'get-system-waypoints' => ['Waypoints', ['systemSymbol'], true],
        'get-waypoint' => ['Waypoint detail', ['systemSymbol', 'waypointSymbol'], false],
        'get-market' => ['Market', ['systemSymbol', 'waypointSymbol'], false],
        'get-shipyard' => ['Shipyard', ['systemSymbol', 'waypointSymbol'], false],
        'get-jump-gate' => ['Jump gate connections', ['systemSymbol', 'waypointSymbol'], false],
        'get-construction' => ['Construction materials', ['systemSymbol', 'waypointSymbol'], false],
        'get-factions' => ['Factions', [], true],
        'get-faction' => ['Faction detail', ['factionSymbol'], false],
        'get-my-agent' => ['My agent profile', [], false],
        'get-agents' => ['Public agents', [], true],
        'get-agent' => ['Public agent detail', ['agentSymbol'], false],
        'get-contracts' => ['Contracts', [], true],
        'get-contract' => ['Contract terms', ['contractId'], false],
        'get-my-ships' => ['Owned fleet', [], true],
        'get-my-ship' => ['Owned ship detail', ['shipSymbol'], false],
        'get-my-ship-cargo' => ['Ship cargo', ['shipSymbol'], false],
        'get-ship-cooldown' => ['Ship cooldown', ['shipSymbol'], false],
        'get-ship-nav' => ['Ship navigation', ['shipSymbol'], false],
        'get-mounts' => ['Installed mounts', ['shipSymbol'], false],
        'get-ship-modules' => ['Installed modules', ['shipSymbol'], false],
        'get-repair-ship' => ['Repair quote', ['shipSymbol'], false],
        'get-scrap-ship' => ['Scrap quote', ['shipSymbol'], false],
        'get-supply-chain' => ['Supply chain', [], false],
    ];

    public static function rules(string $operation): array
    {
        $definition = self::OPERATIONS[$operation] ?? null;
        if (! $definition) {
            return ['operation' => ['required', Rule::in(array_keys(self::OPERATIONS))]];
        }
        $fields = $definition[1];
        $rules = ['operation' => ['required', Rule::in(array_keys(self::OPERATIONS))]];
        if ($definition[2]) {
            $fields = array_merge($fields, ['page', 'limit']);
            $rules['parameters.page'] = ['sometimes', 'integer', 'min:1', 'max:100000000'];
            $rules['parameters.limit'] = ['sometimes', 'integer', 'between:1,20'];
        }
        foreach ($definition[1] as $field) {
            $rules['parameters.'.$field] = ['required', 'string', 'max:80', $field === 'contractId' ? 'regex:/^[a-zA-Z0-9_-]+$/' : 'regex:/^[A-Z0-9_-]+$/'];
        }
        if ($operation === 'get-system-waypoints') {
            $fields = array_merge($fields, ['type', 'traits']);
            $rules['parameters.type'] = ['sometimes', Rule::in(config('foundation.enums.WaypointType'))];
            $rules['parameters.traits'] = ['sometimes', function ($attribute, $value, $fail) {
                $traits = is_array($value) ? $value : [$value];
                if (count($traits) > 100 || array_filter($traits, fn ($trait) => ! is_string($trait) || ! in_array($trait, config('foundation.enums.WaypointTraitSymbol'), true))) {
                    $fail('Choose published waypoint traits.');
                }
            }];
        }
        $rules['parameters'] = ['present', 'array:'.implode(',', $fields)];

        return $rules;
    }
}
