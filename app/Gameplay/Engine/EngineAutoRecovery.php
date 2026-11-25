<?php

namespace App\Gameplay\Engine;

use App\Console\Commands\SuperviseLocalEngine;
use App\Gameplay\EngineGate;
use App\Gameplay\LocalEngineManager;
use App\Models\LocalEngine;
use App\Models\ShipAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Restarts a local engine whose supervisor faulted only because SQLite contention starved its
 * heartbeat. A fresh session invalidates old previews as any start does. It refuses when an
 * owned process survives, when any action is sending, uncertain or awaiting review, or when the
 * rolling six-hour restart limit is spent; those still need the operator.
 */
class EngineAutoRecovery
{
    public const RUNTIME_KEY = 'engine_contention_restarts';

    public function attempt(): ?string
    {
        if (! config('gameplay.engine_contention_auto_restart') || ! app(EngineGate::class)->enabled()) {
            return null;
        }
        $engine = LocalEngine::find(1);
        if (! $engine || $engine->state !== 'faulted' || $engine->message !== SuperviseLocalEngine::CONTENTION_FAULT_MESSAGE) {
            return null;
        }
        $manager = app(LocalEngineManager::class);
        foreach (['supervisor', 'worker', 'scheduler'] as $role) {
            if ($manager->alive($engine, $role)) {
                return $this->skip("the owned {$role} is still alive");
            }
        }
        if (ShipAction::whereIn('state', ['sending', 'uncertain'])->orWhere('review_required', true)->exists()) {
            return $this->skip('an action is sending, uncertain or awaiting review');
        }
        $now = now()->timestamp;
        $recent = json_decode((string) DB::table('game_runtime')->where('key', self::RUNTIME_KEY)->value('value'), true);
        $recent = array_values(array_filter(is_array($recent) ? $recent : [], fn ($at) => is_int($at) && $at > $now - 6 * 3600));
        $limit = max(0, (int) config('gameplay.engine_contention_auto_restart_limit'));
        if (count($recent) >= $limit) {
            return $this->skip("the limit of {$limit} restarts in six hours is spent");
        }
        $recent[] = $now;
        DB::table('game_runtime')->upsert([['key' => self::RUNTIME_KEY, 'value' => json_encode($recent)]], ['key'], ['value']);
        $manager->start();
        Log::channel('engine')->warning('Watchdog restarted the engine after a SQLite contention fault.', ['restarts_in_six_hours' => count($recent)]);

        return 'restarted';
    }

    private function skip(string $reason): string
    {
        Log::channel('engine')->warning('Watchdog left the contention-faulted engine stopped: '.$reason.'.');

        return 'skipped: '.$reason;
    }
}
