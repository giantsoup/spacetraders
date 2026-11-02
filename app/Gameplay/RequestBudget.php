<?php

namespace App\Gameplay;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

class RequestBudget
{
    private static array $processPauses = [];

    public function reserve(?string $token = null, ?string $account = null, string $priority = 'discovery', float $maxWait = 0.75): void
    {
        $key = $this->storeKey();
        $localDelay = (self::$processPauses[$key] ?? 0) - $this->now();
        if ($localDelay > 0) {
            throw new RequestDeferred('SpaceTraders transport is paused after response-budget recording failed.', (int) ceil($localDelay));
        }
        unset(self::$processPauses[$key]);
        $waited = 0.0;
        do {
            $delay = $this->change(function (&$state) use ($token, $account, $priority, $waited, $maxWait) {
                $now = $this->now();
                $keys = $this->keys($token, $account);
                $floor = match ($priority) {
                    'write' => 0, 'critical' => 1, default => 3
                };
                $delay = 0.0;
                foreach ($keys as $key) {
                    $bucket = $this->refill($state['buckets'][$key] ?? [], $now);
                    $state['buckets'][$key] = $bucket;
                    $delay = max($delay, $bucket['blocked_until'] - $now);
                    if (config('gameplay.request_budget_enabled')) {
                        $needed = min($floor, $bucket['capacity'] - 1) + 1;
                        $delay = max($delay, ($needed - $bucket['tokens']) / $bucket['rate']);
                    }
                }
                if ($delay > 0.001) {
                    if ($delay + $waited > $maxWait) {
                        foreach ($keys as $key) {
                            $stats = &$state['buckets'][$key]['usage'];
                            $stats['deferrals']++;
                            $stats['deferred_seconds'] += $delay;
                        }
                    }

                    return $delay;
                }
                foreach ($keys as $key) {
                    $bucket = &$state['buckets'][$key];
                    $bucket['tokens'] = max(0, $bucket['tokens'] - 1);
                    $bucket['usage']['requests']++;
                    $bucket['usage']['priorities'][$priority] = ($bucket['usage']['priorities'][$priority] ?? 0) + 1;
                    $bucket['usage']['wait_seconds'] += $waited;
                    $bucket['recent'][] = $now;
                }

                return 0.0;
            });
            if ($delay <= 0.001) {
                return;
            }
            if ($delay + $waited > $maxWait) {
                throw new RequestDeferred('SpaceTraders request budget is cooling down.', max(1, (int) ceil($delay)));
            }
            $this->pause($delay);
            $waited += $delay;
        } while (true);
    }

    public function observe(Response $response, ?string $token = null, ?string $account = null): void
    {
        $this->change(function (&$state) use ($response, $token, $account) {
            $now = $this->now();
            $keys = $this->keys($token, $account);
            foreach ($keys as $key) {
                $state['buckets'][$key] = $this->refill($state['buckets'][$key] ?? [], $now);
                $stats = &$state['buckets'][$key]['usage'];
                $stats['responses']++;
                $stats['rate_limits'] += (int) ($response->status() === 429);
                $stats['server_errors'] += (int) $response->serverError();
            }
            $type = strtolower(trim($response->header('X-RateLimit-Type') ?? ''));
            $scope = $type === 'account' && count($keys) > 1 ? $keys[1] : 'host';
            $bucket = &$state['buckets'][$scope];
            $rate = $response->header('X-RateLimit-Limit-Per-Second');
            $burst = $response->header('X-RateLimit-Limit-Burst');
            if (is_numeric($rate) && (float) $rate > 0) {
                $bucket['rate'] = min($bucket['rate'], max(0.05, (float) $rate * 0.95));
            }
            if (is_numeric($burst) && (float) $burst >= 1) {
                $bucket['capacity'] = min($bucket['capacity'], max(1, (int) $burst - 1));
            }
            $remaining = $response->header('X-RateLimit-Remaining');
            $bucket['last_response'] = [
                'observed_at' => $now, 'status' => $response->status(),
                'scope' => match ($type) {
                    'account' => 'Account', 'ip address' => 'IP address', default => 'Unspecified'
                },
                'per_second' => is_numeric($rate) && is_finite((float) $rate) && (float) $rate > 0 ? (float) $rate : null,
                'burst' => is_numeric($burst) && is_finite((float) $burst) && (float) $burst >= 1 ? (float) $burst : null,
                'remaining' => is_numeric($remaining) && is_finite((float) $remaining) && (float) $remaining >= 0 ? (float) $remaining : null,
            ];
            // Live 2xx responses report Remaining=1 beside a separate burst=30 allowance.
            // A positive value is advisory, not the size of our sustained/burst budget.
            // Explicit exhaustion still enforces Retry-After/Reset below.
            $bucket['tokens'] = min($bucket['tokens'], $bucket['capacity']);
            $exhausted = is_numeric($remaining) && (float) $remaining <= 0;
            if ($response->status() === 429 || $response->serverError() || $exhausted) {
                // Unknown cloud limits and 5xx affect the host. Explicit account limits affect only that account.
                if ($response->serverError() || ($response->status() === 429 && $type !== 'account')) {
                    $scope = 'host';
                    $bucket = &$state['buckets'][$scope];
                }
                $bucket['blocked_until'] = max($bucket['blocked_until'], $this->pauseUntil($response));
                if (! $response->successful()) {
                    $bucket['tokens'] = 0;
                    $bucket['updated_at'] = $bucket['blocked_until'];
                }
            }
        });
    }

    public function usage(?string $token = null, ?string $account = null): array
    {
        $state = $this->readState($this->directory(false).'/budget.json');
        $result = [];
        foreach ($this->keys($token, $account) as $index => $key) {
            $bucket = $this->refill($state['buckets'][$key] ?? [], $this->now());
            $result[$index === 0 ? 'host' : 'account'] = $bucket['usage'] + [
                'requests_last_60_seconds' => count($bucket['recent']), 'requests_per_second_last_60' => count($bucket['recent']) / 60,
                'requests_per_second_last_5' => count(array_filter($bucket['recent'], fn ($at) => $at > $this->now() - 5)) / 5,
                'sustained_per_second' => $bucket['rate'], 'burst_capacity' => $bucket['capacity'],
                'available' => $bucket['tokens'], 'blocked_until' => $bucket['blocked_until'],
                'last_response' => $bucket['last_response'] ?? null,
            ];
        }

        return $result;
    }

    public function status(?string $token = null, ?string $account = null): array
    {
        $usage = $this->usage($token, $account);

        return [
            'as_of' => $this->now(),
            'mode' => config('gameplay.fixture_path') ? 'offline' : 'live',
            'pacing_enabled' => (bool) config('gameplay.request_budget_enabled'),
            'buckets' => $usage,
        ];
    }

    private function keys(?string $token, ?string $account): array
    {
        return $token === null ? ['host'] : ['host', hash('sha256', $account ? 'account:'.$account : 'token:'.$token)];
    }

    private function refill(array $bucket, float $now): array
    {
        $bucket += ['tokens' => 10.0, 'capacity' => 10.0, 'rate' => 1.9, 'updated_at' => $now, 'blocked_until' => 0.0, 'recent' => [],
            'usage' => ['requests' => 0, 'responses' => 0, 'rate_limits' => 0, 'server_errors' => 0, 'deferrals' => 0, 'deferred_seconds' => 0.0, 'wait_seconds' => 0.0, 'priorities' => []]];
        $bucket['tokens'] = min($bucket['capacity'], $bucket['tokens'] + max(0, $now - $bucket['updated_at']) * $bucket['rate']);
        $bucket['updated_at'] = max($now, $bucket['updated_at']);
        unset($bucket['server_remaining'], $bucket['server_reset_at']);
        $bucket['recent'] = array_values(array_filter($bucket['recent'], fn ($at) => $at > $now - 60));

        return $bucket;
    }

    protected function now(): float
    {
        return microtime(true);
    }

    protected function pause(float $seconds): void
    {
        usleep((int) ceil($seconds * 1000000));
    }

    public function recordingFailed(Response $response): void
    {
        $key = $this->storeKey();
        self::$processPauses[$key] = max(self::$processPauses[$key] ?? 0, $this->now() + 10, $this->pauseUntil($response));
        try {
            Log::build(['driver' => 'single', 'path' => storage_path('logs/gameplay.log'), 'permission' => 0600])
                ->warning('SpaceTraders response budget recording failed. Process transport paused.', ['http_status' => $response->status()]);
        } catch (\Throwable) {
            // Logging is best-effort; it must not discard an upstream result either.
        }
    }

    private function storeKey(): string
    {
        return hash('sha256', serialize(config('gameplay.request_budget_path')));
    }

    private function pauseUntil(Response $response): float
    {
        $remaining = $response->header('X-RateLimit-Remaining');
        $exhausted = is_numeric($remaining) && (float) $remaining <= 0;
        if ($response->status() !== 429 && ! $response->serverError() && ! $exhausted) {
            return 0;
        }
        $now = $this->now();
        $retry = $response->header('Retry-After');
        $delay = is_numeric($retry) ? max(0, (float) $retry) : max(0, (strtotime($retry ?: '') ?: 0) - $now);

        $resetDelay = $this->resetAt($response) - $now;
        $minimum = $response->status() === 502 ? 180 : 5;
        if ($response->successful() && ($delay > 0 || $resetDelay > 0)) {
            $minimum = 0;
        }

        return $now + min(3600, max($minimum, $delay, $resetDelay)) + 0.25;
    }

    private function resetAt(Response $response): float
    {
        $reset = $response->header('X-RateLimit-Reset');

        return is_numeric($reset)
            ? ((float) $reset >= 1000000000 && (float) $reset < 10000000000 ? (float) $reset : 0)
            : (float) (strtotime($reset ?: '') ?: 0);
    }

    private function directory(bool $create): string
    {
        $directory = config('gameplay.request_budget_path');
        if (! is_string($directory) || ! str_starts_with($directory, '/')) {
            throw new RequestDeferred('Host request-budget storage requires an absolute shared path.', 10);
        }
        if (is_link($directory)) {
            throw new RequestDeferred('Host request-budget storage is unsafe.', 10);
        }
        if (! is_dir($directory)) {
            if (! $create) {
                return $directory;
            }
            if (! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RequestDeferred('Host request-budget storage is unavailable.', 10);
            }
        }
        if (fileowner($directory) !== posix_geteuid()) {
            throw new RequestDeferred('Host request-budget storage is unavailable.', 10);
        }
        if ($create) {
            @chmod($directory, 0700);
        }
        if (is_link($directory.'/budget.json') || is_link($directory.'/budget.lock')) {
            throw new RequestDeferred('Host request-budget storage is unsafe.', 10);
        }

        return $directory;
    }

    private function readState(string $file): array
    {
        try {
            $state = is_file($file) ? json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) : ['buckets' => []];
            if (! is_array($state) || ! isset($state['buckets']) || ! is_array($state['buckets'])) {
                throw new \RuntimeException('Invalid request-budget state.');
            }

            return $state;
        } catch (\Throwable) {
            throw new RequestDeferred('Host request-budget state could not be read.', 10);
        }
    }

    private function change(callable $work): mixed
    {
        $directory = $this->directory(true);
        $file = $directory.'/budget.json';
        $lock = $directory.'/budget.lock';
        $handle = @fopen($lock, 'c');
        if (! $handle) {
            throw new RequestDeferred('Host request-budget storage is unavailable.', 10);
        }
        $deadline = hrtime(true) + 1500000000;
        while (! flock($handle, LOCK_EX | LOCK_NB)) {
            if (hrtime(true) >= $deadline) {
                fclose($handle);
                throw new RequestDeferred('Host request-budget storage is busy.', 1);
            }
            usleep(5000);
        }
        try {
            @chmod($lock, 0600);
            $state = $this->readState($file);
            $result = $work($state);
            $temporary = tempnam($directory, '.budget-');
            if (! $temporary || file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR)) === false || ! chmod($temporary, 0600) || ! rename($temporary, $file)) {
                throw new \RuntimeException('Cannot persist request budget.');
            }

            return $result;
        } catch (\Throwable) {
            if (isset($temporary) && $temporary && is_file($temporary)) {
                @unlink($temporary);
            }
            throw new RequestDeferred('Host request-budget state could not be persisted.', 10);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
