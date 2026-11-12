<?php

namespace App\Gameplay\Engine;

/**
 * Cooperative stop for owned engine children on platforms without signals.
 * A child stops when its local_engines session is missing or changed, a stop
 * was requested, or the supervisor lease is free (orphan watchdog).
 *
 * Stub: implemented in M2 task B.
 */
class EngineChildWatch
{
    /** True when the current owned child should finish its work and exit. Throttled database reads. */
    public function shouldStop(): bool
    {
        throw new \LogicException('EngineChildWatch is not implemented yet.');
    }

    /** Register queue listeners (Looping stop, job watchdog markers) for an owned worker child. */
    public static function listen(): void
    {
        throw new \LogicException('EngineChildWatch is not implemented yet.');
    }
}
