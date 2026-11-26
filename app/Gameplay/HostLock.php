<?php

namespace App\Gameplay;

// The macOS deploy lock holder requires this file without bootstrapping Laravel.
class HostLock
{
    public const LEADERBOARD_CAPTURE_KEY = 'leaderboard-capture';

    public static function leaderboardCaptureLockPath(string $checkoutRoot): string
    {
        return $checkoutRoot.'/storage/framework/game-locks/'.hash('sha256', self::LEADERBOARD_CAPTURE_KEY).'.lock';
    }

    private array $held = [];

    private array $nested = [];

    private array $tokens = [];

    public function token(string $key): ?string
    {
        return $this->tokens[$key] ?? null;
    }

    public function holds(string $key): bool
    {
        return isset($this->held[$key]);
    }

    public function holdsExclusive(string $key): bool
    {
        return ($this->held[$key] ?? null) === LOCK_EX;
    }

    public function holdsShared(string $key): bool
    {
        return ($this->held[$key] ?? null) === LOCK_SH;
    }

    public function shared(string $key, callable $work, float $seconds = 0): mixed
    {
        return $this->scopeMode($key, $work, $seconds, LOCK_SH);
    }

    public function exclusive(string $key, callable $work, float $seconds = 0): mixed
    {
        return $this->scoped($key, $work, $seconds);
    }

    public function lockedAgentAndShips(int $agentId, array $shipSymbols, bool $writer, callable $work, float $seconds = 15): mixed
    {
        $symbols = array_unique($shipSymbols);
        sort($symbols, SORT_STRING);
        $agentKey = 'agent-'.$agentId;
        $deadline = microtime(true) + $seconds;
        foreach (array_reverse($symbols) as $symbol) {
            $next = $work;
            $work = fn () => $this->scopeMode('ship-'.$agentId.'-'.$symbol, $next, max(0, $deadline - microtime(true)), LOCK_EX, reuse: true);
        }

        return $this->scopeMode($agentKey, $work, $seconds, $writer ? LOCK_EX : LOCK_SH, reuse: true);
    }

    public function scoped(string $key, callable $work, float $seconds = 0): mixed
    {
        return $this->scopeMode($key, $work, $seconds, LOCK_EX);
    }

    private function scopeMode(string $key, callable $work, float $seconds, int $mode, bool $reuse = false): mixed
    {
        $scope = function () use ($key, $work) {
            $previous = $this->nested[$key] ?? false;
            $this->nested[$key] = true;
            try {
                return $work();
            } finally {
                $this->nested[$key] = $previous;
            }
        };

        return $mode === LOCK_EX && ! $reuse ? $this->retry($key, $scope, $seconds) : $this->retryMode($key, $scope, $seconds, $mode, $reuse);
    }

    public function retry(string $key, callable $work, float $seconds = 15): mixed
    {
        return $this->retryMode($key, $work, $seconds, LOCK_EX);
    }

    private function retryMode(string $key, callable $work, float $seconds, int $mode, bool $reuse = false): mixed
    {
        $deadline = microtime(true) + $seconds;
        do {
            $entered = false;
            try {
                $attempt = function () use ($work, &$entered) {
                    $entered = true;

                    return $work();
                };

                return $mode === LOCK_EX && ! $reuse ? $this->run($key, $attempt) : $this->runMode($key, $attempt, $mode, $reuse);
            } catch (GameException $exception) {
                if ($entered || $exception->retryAfter === 0 || microtime(true) >= $deadline) {
                    throw $exception;
                }
                usleep((int) min(100000, max(0, ($deadline - microtime(true)) * 1000000)));
            }
        } while (true);
    }

    public function run(string $key, callable $work): mixed
    {
        return $this->runMode($key, $work, LOCK_EX);
    }

    private function runMode(string $key, callable $work, int $mode, bool $reuse = false): mixed
    {
        if ($mode === LOCK_EX && $this->holdsShared($key)) {
            throw new GameException('A shared command boundary cannot be upgraded to exclusive.');
        }
        $held = $this->holds($key);
        if ($held && ($reuse || ($this->nested[$key] ?? false))) {
            return $work();
        }
        $path = $key === self::LEADERBOARD_CAPTURE_KEY
            ? self::leaderboardCaptureLockPath(base_path())
            : config('gameplay.lock_path').'/'.hash('sha256', $key).'.lock';
        $directory = dirname($path);
        if (! is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }
        $handle = fopen($path, 'c');
        if ($handle === false || ! flock($handle, $mode | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new GameException('Another operation owns this command boundary. Retry shortly.', retryAfter: 2);
        }
        try {
            if (! $held) {
                $this->tokens[$key] = bin2hex(random_bytes(8));
                $this->held[$key] = $mode;
            }

            return $work();
        } finally {
            if (! $held) {
                unset($this->held[$key], $this->tokens[$key]);
            }
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
