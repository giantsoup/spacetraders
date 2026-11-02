<?php

namespace App\Gameplay;

use App\Models\AutomationRun;
use App\Models\LocalEngine;
use App\Models\ShipAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EngineGate
{
    public function enabled(): bool
    {
        return (bool) config('gameplay.local_engine_enabled');
    }

    public function session(): ?string
    {
        if (! $this->enabled()) {
            return null;
        }
        $engine = LocalEngine::find(1);
        if (! $engine || ! $engine->gate_open || $engine->state !== 'running' || ! $engine->heartbeat_at || $engine->heartbeat_at->lt(now()->subSeconds(15)) || ! $this->processesAlive($engine)) {
            throw new GameException('Local engine is not healthy. Start the engine and create a fresh preview.');
        }

        return $engine->session_id;
    }

    // Called inside the same SQLite transaction as intent reservation or approval.
    public function check(?string $session): void
    {
        if (! $this->enabled()) {
            return;
        }
        $engine = LocalEngine::find(1);
        if (! $engine || ! $this->processesAlive($engine)) {
            $this->close('faulted', 'Owned engine processes are no longer alive. Fresh session and preview required.');
            throw new GameException('Owned engine processes are not alive.');
        }
        if (! $session || ! LocalEngine::whereKey(1)->where('session_id', $session)->where('state', 'running')->where('gate_open', true)->where('heartbeat_at', '>=', now()->subSeconds(15))->update(['updated_at' => now()])) {
            throw new GameException('Engine session closed or changed. Refresh state and approve a new preview.');
        }
    }

    private function processesAlive(LocalEngine $engine): bool
    {
        foreach (['supervisor', 'worker', 'scheduler'] as $name) {
            $pid = $engine->processes[$name] ?? null;
            if (! is_int($pid) || $pid < 1 || ! posix_kill($pid, 0)) {
                return false;
            }
        }

        return true;
    }

    public function begin(): LocalEngine
    {
        return DB::transaction(function () {
            $this->invalidate('Engine restarted. Fresh preview required.');

            return LocalEngine::updateOrCreate(['id' => 1], ['state' => 'starting', 'session_id' => (string) Str::uuid(), 'gate_open' => false,
                'stop_requested_at' => null, 'heartbeat_at' => now(), 'processes' => [], 'message' => 'Starting owned worker and scheduler.']);
        });
    }

    public function close(string $state = 'stopping', string $message = 'End session requested.'): void
    {
        DB::transaction(function () use ($state, $message) {
            LocalEngine::whereKey(1)->update(['gate_open' => false, 'state' => $state, 'stop_requested_at' => now(), 'message' => $message]);
            $this->invalidate($message);
        });
    }

    private function invalidate(string $message): void
    {
        ShipAction::whereIn('state', ['preview', 'pending'])
            ->where(fn ($query) => $query->whereNotNull('outcome->body_sha256')->orWhereNotNull('outcome->response_received_at'))
            ->update(['state' => 'uncertain', 'message' => 'A write receipt already exists. Reconcile without replay after the engine session closes.', 'settled_at' => now()]);
        ShipAction::whereIn('state', ['preview', 'pending'])->update(['state' => 'rejected', 'message' => $message, 'settled_at' => now()]);
        AutomationRun::where('state', 'preview')->update(['state' => 'stopped', 'blocked_reason' => $message]);
        AutomationRun::whereNotNull('active_agent_id')->update(['stop_requested_at' => now(), 'state' => 'stopping', 'blocked_reason' => $message, 'next_action_at' => now(), 'last_dispatched_at' => null]);
    }
}
