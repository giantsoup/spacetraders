<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ship extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['snapshot' => 'array', 'observed_at' => 'immutable_datetime'];

    protected $appends = ['display_name', 'purpose'];

    public ?string $runtimePurpose = null;

    public function getPurposeAttribute(): ?string
    {
        return $this->runtimePurpose;
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->displayName($this->runtimePurpose);
    }

    public function displayName(?string $purpose = null): string
    {
        $nickname = is_string($this->nickname) ? trim($this->nickname) : '';
        $base = $nickname !== ''
            ? $nickname
            : self::semanticName($this->symbol, is_array($this->snapshot) ? $this->snapshot : []);

        return $purpose !== null && $purpose !== '' ? $base.' · '.$purpose : $base;
    }

    public static function semanticName(string $symbol, array $snapshot = []): string
    {
        $frameSymbol = $snapshot['frame']['symbol'] ?? null;
        $frameName = is_string($snapshot['frame']['name'] ?? null) ? trim($snapshot['frame']['name']) : '';
        $role = $snapshot['registration']['role'] ?? null;
        $mounts = array_column($snapshot['mounts'] ?? [], 'symbol');

        $kind = match ($frameSymbol) {
            'FRAME_PROBE' => 'Probe',
            'FRAME_FRIGATE' => $role === 'COMMAND' ? 'Command Frigate' : 'Frigate',
            'FRAME_SHUTTLE' => 'Light Shuttle',
            'FRAME_LIGHT_FREIGHTER' => 'Light Hauler',
            'FRAME_HEAVY_FREIGHTER' => $role === 'REFINERY' ? 'Refining Freighter' : 'Heavy Freighter',
            'FRAME_BULK_FREIGHTER' => 'Bulk Freighter',
            'FRAME_MINER' => 'Ore Hound',
            'FRAME_EXPLORER' => 'Explorer',
            'FRAME_INTERCEPTOR' => 'Interceptor',
            'FRAME_DESTROYER' => 'Destroyer',
            'FRAME_CRUISER' => 'Cruiser',
            'FRAME_CARRIER' => 'Carrier',
            'FRAME_DRONE' => match (true) {
                $role === 'SURVEYOR' => 'Surveyor Drone',
                collect($mounts)->contains(fn ($mount) => is_string($mount) && str_starts_with($mount, 'MOUNT_GAS_SIPHON_')) => 'Siphon Drone',
                $role === 'EXCAVATOR' => 'Mining Drone',
                default => 'Drone',
            },
            default => $frameName !== ''
                ? $frameName
                : (is_string($role) && $role !== ''
                    ? match ($role) {
                        'SATELLITE', 'PROBE' => 'Probe',
                        'COMMAND' => 'Command Ship',
                        'HAULER' => 'Hauler',
                        'TRANSPORT' => 'Shuttle',
                        'EXCAVATOR' => 'Miner',
                        'SURVEYOR' => 'Surveyor',
                        default => ucwords(strtolower(str_replace('_', ' ', $role))),
                    }
                    : ''),
        };

        if ($kind === '') {
            return $symbol;
        }

        if (preg_match('/-(\d+)$/', $symbol, $matches) === 1) {
            return $kind.' #'.$matches[1];
        }

        return $kind.' · '.$symbol;
    }
}
