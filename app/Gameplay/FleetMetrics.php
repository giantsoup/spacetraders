<?php

namespace App\Gameplay;

/** W1 stub only. The integrator replaces this file with W4's FleetMetrics. */
class FleetMetrics
{
    public static ?string $ship = null;

    public static function as(?string $ship, callable $fn): mixed
    {
        $previous = self::$ship;
        self::$ship = $ship;
        try {
            return $fn();
        } finally {
            self::$ship = $previous;
        }
    }

    public function schedulerPass(array $t): void {}
}
