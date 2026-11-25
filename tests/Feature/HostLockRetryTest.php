<?php

namespace Tests\Feature;

use App\Gameplay\GameException;
use App\Gameplay\HostLock;
use Tests\TestCase;

class HostLockRetryTest extends TestCase
{
    public function test_lock_token_spans_only_one_successful_acquisition(): void
    {
        $lock = app(HostLock::class);
        $key = 'agent-token';
        $this->assertNull($lock->token($key));
        $first = $lock->run($key, function () use ($lock, $key) {
            $first = $lock->token($key);
            $this->assertNotNull($first);
            foreach (['run', 'retry'] as $method) {
                try {
                    $args = [$key, fn () => $this->fail('Nested acquisition must fail without a scope.')];
                    if ($method === 'retry') {
                        $args[] = 0;
                    }
                    $lock->$method(...$args);
                    $this->fail('Expected a contended lock.');
                } catch (GameException $exception) {
                    $this->assertSame(2, $exception->retryAfter);
                    $this->assertSame($first, $lock->token($key));
                }
            }

            return $first;
        });
        $this->assertNull($lock->token($key));
        $second = $lock->scoped($key, function () use ($lock, $key) {
            $token = $lock->token($key);
            $this->assertSame($token, $lock->run($key, fn () => $lock->scoped($key, fn () => $lock->token($key))));
            $this->assertSame($token, $lock->token($key));

            return $token;
        });
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
        $this->assertNull($lock->token($key));
    }

    public function test_scoped_wait_retries_acquisition_and_allows_nested_boundaries(): void
    {
        $lock = new class extends HostLock
        {
            public int $attempts = 0;

            public function run(string $key, callable $work): mixed
            {
                if (++$this->attempts < 3) {
                    throw new GameException('Synthetic contention.', retryAfter: 2);
                }

                return parent::run($key, $work);
            }
        };
        $calls = 0;
        $result = $lock->scoped('agent', function () use ($lock, &$calls) {
            $calls++;

            return $lock->scoped('agent', fn () => $lock->run('agent', fn () => 'nested'));
        }, 1);
        $this->assertSame('nested', $result);
        $this->assertSame(1, $calls);
        $this->assertSame(5, $lock->attempts);
    }

    public function test_scoped_wait_does_not_replay_a_retryable_callback_or_leave_nesting_enabled(): void
    {
        $lock = app(HostLock::class);
        $calls = 0;
        try {
            $lock->scoped('agent', function () use (&$calls) {
                $calls++;
                throw new GameException('Callback refusal.', retryAfter: 2);
            }, 1);
            $this->fail('The callback must throw.');
        } catch (GameException $exception) {
            $this->assertSame('Callback refusal.', $exception->getMessage());
        }
        $this->assertSame(1, $calls);
        $lock->run('agent', function () use ($lock) {
            try {
                $lock->run('agent', fn () => $this->fail('Plain run must not inherit scoped nesting.'));
                $this->fail('Plain run must contend.');
            } catch (GameException $exception) {
                $this->assertSame(2, $exception->retryAfter);
            }
        });
        $this->assertSame('released', $lock->scoped('agent', fn () => 'released'));
    }

    public function test_scoped_persistent_contention_is_bounded_and_the_default_does_not_wait(): void
    {
        $lock = new class extends HostLock
        {
            public int $attempts = 0;

            public function run(string $key, callable $work): mixed
            {
                $this->attempts++;
                throw new GameException('Synthetic contention.', retryAfter: 2);
            }
        };
        foreach ([0, 0.15] as $seconds) {
            $before = $lock->attempts;
            $started = microtime(true);
            try {
                $lock->scoped('agent', fn () => $this->fail('Callback must not run.'), $seconds);
                $this->fail('Acquisition must fail.');
            } catch (GameException $exception) {
                $this->assertSame(2, $exception->retryAfter);
                $this->assertGreaterThanOrEqual($seconds, microtime(true) - $started);
                $this->assertLessThan(0.5, microtime(true) - $started);
                if ($seconds === 0) {
                    $this->assertSame(1, $lock->attempts - $before);
                } else {
                    $this->assertGreaterThan(1, $lock->attempts - $before);
                }
            }
        }
    }

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
