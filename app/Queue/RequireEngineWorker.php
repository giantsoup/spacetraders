<?php

namespace App\Queue;

use App\Models\LocalEngine;
use Closure;
use Illuminate\Support\Facades\Log;

class RequireEngineWorker
{
    private static bool $warned = false;

    public function handle(object $job, Closure $next): void
    {
        if (! config('gameplay.local_engine_enabled')) {
            $next($job);

            return;
        }

        $session = getenv('SPACETRADERS_ENGINE_WORKER_SESSION');
        $engine = $session ? LocalEngine::find(1) : null;
        if ($engine && $engine->state === 'running' && $engine->session_id === $session) {
            $next($job);

            return;
        }

        $job->release(5);
        if (! self::$warned) {
            self::$warned = true;
            try {
                Log::warning('Gameplay job released: this worker does not own the current running engine session.');
            } catch (\Throwable) {
            }
        }
    }
}
