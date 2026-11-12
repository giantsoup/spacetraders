<?php

namespace App\Gameplay\Engine;

use App\Models\LocalEngine;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;

/**
 * Cooperative stop for owned engine children on platforms without signals.
 * A child stops when its local_engines session is missing or changed, a stop
 * was requested, or the supervisor lease is free (orphan watchdog).
 */
class EngineChildWatch
{
    private ?int $checkedAt = null;

    private bool $stopped = false;

    private readonly string $session;

    public function __construct()
    {
        $this->session = getenv('SPACETRADERS_ENGINE_SESSION') ?: '';
    }

    /** True when the current owned child should finish its work and exit. Throttled database reads. */
    public function shouldStop(): bool
    {
        if ($this->session === '') {
            return false;
        }
        if ($this->stopped || ! EngineLease::held(EngineLease::path($this->session, 'supervisor'))) {
            return true;
        }
        $now = hrtime(true);
        if ($this->checkedAt === null || $now - $this->checkedAt >= 1_000_000_000) {
            $this->checkedAt = $now;
            $engine = LocalEngine::find(1);
            $this->stopped = ! $engine || $engine->session_id !== $this->session || $engine->stop_requested_at !== null;
        }

        return $this->stopped;
    }

    /** Register queue listeners (Looping stop, job watchdog markers) for an owned worker child. */
    public static function listen(): void
    {
        $session = getenv('SPACETRADERS_ENGINE_SESSION');
        if (! $session || getenv('SPACETRADERS_ENGINE_ROLE') !== 'worker') {
            return;
        }
        $watch = new self;
        $path = self::jobPath($session);
        Event::listen(Looping::class, function () use ($watch) {
            if ($watch->shouldStop()) {
                app('queue.worker')->shouldQuit = true;

                return false;
            }

            return null;
        });
        Event::listen(JobProcessing::class, function () use ($path) {
            $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
            try {
                if (file_put_contents($temporary, json_encode(['started_at' => microtime(true)], JSON_THROW_ON_ERROR)) === false
                    || ! rename($temporary, $path)) {
                    throw new \RuntimeException('Cannot publish engine job watchdog marker.');
                }
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        });
        foreach ([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class] as $event) {
            Event::listen($event, function () use ($path) {
                if (is_file($path) && ! unlink($path)) {
                    throw new \RuntimeException('Cannot clear engine job watchdog marker.');
                }
            });
        }
    }

    public static function jobStartedAt(string $session): ?float
    {
        $path = self::jobPath($session);
        if (! is_file($path)) {
            return null;
        }
        $contents = @file_get_contents($path);
        $marker = $contents === false ? null : json_decode($contents, true);
        $started = is_array($marker) ? ($marker['started_at'] ?? null) : null;

        return is_numeric($started) && is_finite((float) $started) && $started > 0 ? (float) $started : null;
    }

    private static function jobPath(string $session): string
    {
        EngineLease::path($session, 'worker');

        return config('gameplay.lock_path').'/engine/'.$session.'-worker.job';
    }
}
