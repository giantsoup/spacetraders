<?php

namespace App\Queue;

use App\Models\LocalEngine;

class EngineWorkerGate
{
    private string|false $session = false;

    private int $checkedAt = 0;

    private bool $allowed = false;

    public function allowsPolling(): bool
    {
        if (! config('gameplay.local_engine_enabled')) {
            return true;
        }

        $session = getenv('SPACETRADERS_ENGINE_WORKER_SESSION');
        $now = hrtime(true);
        if ($session !== $this->session || $now - $this->checkedAt >= 1000000000) {
            $this->allowed = $session && LocalEngine::whereKey(1)->where('state', 'running')->where('session_id', $session)->exists();
            $this->session = $session;
            $this->checkedAt = $now;
        }

        return $this->allowed;
    }

    /**
     * Whether this process is the worker spawned for the current engine session,
     * whatever state that session is in. Used to tell the engine's own worker
     * apart from foreign workers while the session is still starting or stopping,
     * when polling is not yet (or no longer) allowed.
     */
    public function ownsSession(): bool
    {
        if (! config('gameplay.local_engine_enabled')) {
            return true;
        }
        $session = getenv('SPACETRADERS_ENGINE_WORKER_SESSION');

        return $session && LocalEngine::whereKey(1)->where('session_id', $session)->exists();
    }
}
