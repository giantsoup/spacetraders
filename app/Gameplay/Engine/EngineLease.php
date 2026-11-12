<?php

namespace App\Gameplay\Engine;

/**
 * Process-lifetime liveness leases. A process holds an exclusive lock on an
 * empty file for its whole life; the OS releases it when the process dies.
 * Lease files never contain readable data because NTFS locks are mandatory.
 *
 * Stub: implemented in M2 task B.
 */
final class EngineLease
{
    /** {lock_path}/engine/leases/{session}-{role}.lease */
    public static function path(string $session, string $role): string
    {
        throw new \LogicException('EngineLease is not implemented yet.');
    }

    /** Acquire and hold the lease until this process exits. */
    public static function hold(string $path): void
    {
        throw new \LogicException('EngineLease is not implemented yet.');
    }

    /** True when another live process holds the lease. Never blocks. */
    public static function held(string $path): bool
    {
        throw new \LogicException('EngineLease is not implemented yet.');
    }
}
