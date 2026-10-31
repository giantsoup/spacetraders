<?php

namespace App\Gameplay;

use Illuminate\Support\Facades\DB;

class RequestBudget
{
    public function reserve(): void
    {
        try {
            app(HostLock::class)->run('upstream-budget', function () {
                $next = (float) json_decode(DB::table('game_runtime')->where('key', 'request_at')->value('value') ?? '0', true);
                $delay = $next - microtime(true);
                if ($delay > 1) {
                    throw new RequestDeferred('SpaceTraders request budget is cooling down.', (int) ceil($delay));
                }
                if ($delay > 0) {
                    usleep((int) ($delay * 1000000));
                }
                DB::table('game_runtime')->updateOrInsert(['key' => 'request_at'], [
                    'value' => json_encode(microtime(true) + config('gameplay.request_spacing_ms') / 1000),
                ]);
            });
        } catch (GameException $e) {
            throw new RequestDeferred($e->getMessage(), max(2, $e->retryAfter));
        }
    }

    public function defer(int $seconds): void
    {
        app(HostLock::class)->run('upstream-budget', function () use ($seconds) {
            $previous = (float) json_decode(DB::table('game_runtime')->where('key', 'request_at')->value('value') ?? '0', true);
            DB::table('game_runtime')->updateOrInsert(['key' => 'request_at'], [
                'value' => json_encode(max($previous, microtime(true) + $seconds)),
            ]);
        });
    }
}
