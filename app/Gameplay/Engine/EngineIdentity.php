<?php

namespace App\Gameplay\Engine;

final class EngineIdentity
{
    public static function label(): string
    {
        return 'io.spacetraders.engine.'.substr(hash('sha256', realpath(base_path()).'|'.config('database.connections.sqlite.database')), 0, 24);
    }

    public static function directory(): string
    {
        return config('gameplay.lock_path').'/engine';
    }
}
