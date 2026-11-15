<?php

namespace Tests\Feature;

use App\Gameplay\GameException;
use App\Gameplay\HostLock;
use Tests\TestCase;

class HostLockRetryTest extends TestCase
{
    public function test_lock_contention_is_retried_before_the_callback_runs(): void
    {
        $lock = new class extends HostLock
        {
            public int $attempts = 0;

            public function run(string $key, callable $work): mixed
            {
                if (++$this->attempts < 3) {
                    throw new GameException('Synthetic lock contention.', retryAfter: 2);
                }

                return $work();
            }
        };
        $calls = 0;
        $result = $lock->retry('campaign', function () use (&$calls) {
            return ++$calls;
        }, 1);
        $this->assertSame(1, $result);
        $this->assertSame(1, $calls);
        $this->assertSame(3, $lock->attempts);
    }

    public function test_a_retryable_error_inside_the_callback_is_not_replayed(): void
    {
        $calls = 0;
        try {
            app(HostLock::class)->retry('campaign', function () use (&$calls) {
                $calls++;
                throw new GameException('Synthetic callback refusal.', retryAfter: 2);
            }, 0.1);
            $this->fail('Callback failure must propagate.');
        } catch (GameException $exception) {
            $this->assertSame('Synthetic callback refusal.', $exception->getMessage());
        }
        $this->assertSame(1, $calls);
    }

    public function test_persistent_lock_contention_stops_at_the_retry_deadline(): void
    {
        $lock = new class extends HostLock
        {
            public function run(string $key, callable $work): mixed
            {
                throw new GameException('Synthetic lock contention.', retryAfter: 2);
            }
        };
        $started = microtime(true);
        try {
            $lock->retry('campaign', fn () => $this->fail('Contended callback must not run.'), 0.15);
            $this->fail('Persistent contention must fail.');
        } catch (GameException) {
            $this->assertGreaterThanOrEqual(0.15, microtime(true) - $started);
            $this->assertLessThan(0.5, microtime(true) - $started);
        }
    }
}
