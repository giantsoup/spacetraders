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
}
