<?php

namespace App\Gameplay\Engine;

use App\Models\LocalEngine;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Cooperative stop for owned engine children on platforms without signals.
 * A child stops when its local_engines session is missing or changed, a stop
 * was requested, or the supervisor lease is free (orphan watchdog).
 */
class EngineChildWatch
{
    private $jobLease = null;

    private ?string $jobAttempt = null;

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
            try {
                $stop = $watch->shouldStop();
            } catch (\Throwable $error) {
                self::warn('Cannot inspect engine worker stop state.', $error);
                $stop = true;
            }
            if ($stop) {
                app('queue.worker')->shouldQuit = true;

                return false;
            }

            return null;
        });
        Event::listen(JobProcessing::class, function () use ($watch, $path) {
            try {
                $watch->jobAttempt = bin2hex(random_bytes(16));
                $watch->jobLease = @fopen($path.'.'.$watch->jobAttempt.'.lease', 'x+b');
                if ($watch->jobLease === false || ! flock($watch->jobLease, LOCK_EX | LOCK_NB)) {
                    throw new \RuntimeException('Cannot acquire job attempt lease.');
                }
                self::publish($path, ['attempt' => $watch->jobAttempt, 'started_at' => microtime(true), 'finished_at' => null]);
            } catch (\Throwable $error) {
                $watch->releaseJob($path);
                self::warn('Cannot publish engine job watchdog marker.', $error);
            }
        });
        foreach ([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class] as $event) {
            Event::listen($event, function () use ($watch, $path) {
                try {
                    self::publish($path, ['attempt' => $watch->jobAttempt, 'started_at' => null, 'finished_at' => microtime(true)]);
                } catch (\Throwable $error) {
                    self::warn('Cannot finish engine job watchdog marker.', $error);
                } finally {
                    $watch->releaseJob($path);
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
        $attempt = is_array($marker) ? ($marker['attempt'] ?? null) : null;
        if (! is_string($attempt) || ! preg_match('/\A[0-9a-f]{32}\z/', $attempt) || $started === null) {
            return null;
        }
        try {
            if (! EngineLease::held($path.'.'.$attempt.'.lease')) {
                return null;
            }
        } catch (\Throwable $error) {
            self::warn('Cannot inspect engine job attempt lease.', $error);

            return null;
        }

        return is_numeric($started) && is_finite((float) $started) && $started > 0 ? (float) $started : null;
    }

    private function releaseJob(string $path): void
    {
        if (is_resource($this->jobLease)) {
            fclose($this->jobLease);
        }
        if ($this->jobAttempt !== null) {
            @unlink($path.'.'.$this->jobAttempt.'.lease');
        }
        $this->jobLease = null;
        $this->jobAttempt = null;
    }

    private static function publish(string $path, array $marker): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            if (@file_put_contents($temporary, json_encode($marker, JSON_THROW_ON_ERROR)) === false) {
                throw new \RuntimeException('Cannot write job marker.');
            }
            $deadline = hrtime(true) + 250_000_000;
            while (! @rename($temporary, $path)) {
                if (hrtime(true) >= $deadline) {
                    throw new \RuntimeException('Cannot replace job marker.');
                }
                usleep(2000);
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function warn(string $message, \Throwable $error): void
    {
        try {
            Log::channel('engine')->warning($message, ['exception' => $error::class]);
        } catch (\Throwable) {
        }
    }

    private static function jobPath(string $session): string
    {
        EngineLease::path($session, 'worker');

        return config('gameplay.lock_path').'/engine/'.$session.'-worker.job';
    }
}
