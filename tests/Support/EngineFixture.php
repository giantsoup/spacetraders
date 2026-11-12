<?php

namespace Tests\Support;

use App\Gameplay\Engine\EngineHost;
use App\Models\LocalEngine;
use Illuminate\Support\Str;

class EngineFixture
{
    public static function healthy(): LocalEngine
    {
        $host = app(EngineHost::class);
        if (! $host instanceof FakeEngineHost) {
            throw new \LogicException('Bind FakeEngineHost before creating an engine fixture.');
        }
        $host->alive = ['supervisor' => true, 'worker' => true, 'scheduler' => true];

        return LocalEngine::updateOrCreate(['id' => 1], ['state' => 'running', 'session_id' => (string) Str::uuid(), 'gate_open' => true, 'heartbeat_at' => now(), 'stop_requested_at' => null, 'processes' => ['supervisor' => 10001, 'worker' => 10002, 'scheduler' => 10003, 'identity' => []]]);
    }

    public static function kill(string $role): void
    {
        $host = app(EngineHost::class);
        if (! $host instanceof FakeEngineHost || ! array_key_exists($role, $host->alive)) {
            throw new \LogicException('Create a healthy engine fixture before killing an owned role.');
        }
        $host->alive[$role] = false;
    }
}
