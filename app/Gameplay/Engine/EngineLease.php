<?php

namespace App\Gameplay\Engine;

/**
 * Process-lifetime liveness leases. A process holds an exclusive lock on an
 * empty file for its whole life; the OS releases it when the process dies.
 * Lease files never contain readable data because NTFS locks are mandatory.
 */
final class EngineLease
{
    private static array $handles = [];

    /** {lock_path}/engine/leases/{session}-{role}.lease */
    public static function path(string $session, string $role): string
    {
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $session)
            || ! in_array($role, ['supervisor', 'worker', 'scheduler'], true)) {
            throw new \InvalidArgumentException('Invalid engine lease identity.');
        }

        return config('gameplay.lock_path').'/engine/leases/'.$session.'-'.$role.'.lease';
    }

    /** Acquire and hold the lease until this process exits. */
    public static function hold(string $path): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Cannot create engine lease directory.');
        }
        $key = realpath($directory).'/'.basename($path);
        if (isset(self::$handles[$key])) {
            throw new \RuntimeException('Engine lease is already held.');
        }
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open engine lease.');
        }
        $deadline = hrtime(true) + 200_000_000;
        while (! flock($handle, LOCK_EX | LOCK_NB)) {
            if (hrtime(true) >= $deadline) {
                fclose($handle);
                throw new \RuntimeException('Engine lease is already held.');
            }
            usleep(2000);
        }
        self::$handles[$key] = $handle;
    }

    /** True when another live process holds the lease. Never blocks. */
    public static function held(string $path): bool
    {
        clearstatcache(true, $path);
        if (! is_file($path)) {
            return false;
        }
        $handle = @fopen($path, 'r+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot inspect engine lease.');
        }
        $locked = flock($handle, LOCK_EX | LOCK_NB, $wouldBlock);
        fclose($handle);
        if (! $locked && ! $wouldBlock) {
            throw new \RuntimeException('Cannot inspect engine lease lock.');
        }

        return ! $locked;
    }

    public static function cleanup(string $session): void
    {
        $directory = dirname(dirname(self::path($session, 'supervisor')));
        foreach (array_merge(glob($directory.'/leases/*.lease') ?: [], glob($directory.'/*-worker.job*') ?: []) as $path) {
            if (! preg_match('/\A([0-9a-f-]{36})-(?:supervisor|worker|scheduler)\.(?:lease|job(?:\.[0-9a-f]+\.(?:lease|tmp))?)\z/i', basename($path), $match) || $match[1] === $session) {
                continue;
            }
            try {
                foreach (['supervisor', 'worker', 'scheduler'] as $role) {
                    if (self::held(self::path($match[1], $role))) {
                        continue 2;
                    }
                }
                if (str_ends_with($path, '.lease') && self::held($path)) {
                    continue;
                }
                @unlink($path);
            } catch (\Throwable) {
            }
        }
    }
}
