<?php

namespace App\Gameplay;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class RequestBudget
{
    private static array $processPauses = [];

    private static array $windowsDirectories = [];

    private static ?string $currentAgent = null;

    public static function forAgent(?string $agentSymbol, callable $fn): mixed
    {
        $previous = self::$currentAgent;
        self::$currentAgent = $agentSymbol !== null && $agentSymbol !== '' ? strtoupper($agentSymbol) : $previous;
        try {
            return $fn();
        } finally {
            self::$currentAgent = $previous;
        }
    }

    public function drainAgent(string $symbol, bool $draining, ?string $resetId = null): void
    {
        $this->change(function (&$state) use ($symbol, $draining, $resetId) {
            $key = $this->drainKey($symbol);
            if ($draining) {
                $state['draining'][$key] = ['since' => microtime(true), 'reset_id' => $resetId, 'id' => bin2hex(random_bytes(16))];
            } else {
                unset($state['draining'][$key]);
            }
        });
    }

    /** Clear only the hold that was in place when the old agent was archived. */
    public function clearDrainBefore(string $symbol, string $resetId, float $archivedAt): void
    {
        if (! in_array(strtoupper($symbol), $this->drainingSymbols(requireTelemetry: config('gameplay.request_budget_enabled')), true)) {
            return;
        }

        $this->change(function (&$state) use ($symbol, $resetId, $archivedAt) {
            $key = $this->drainKey($symbol);
            $hold = $state['draining'][$key] ?? false;
            $this->assertDrainHold($hold);
            if ($hold === true || (is_array($hold) && $hold['since'] <= $archivedAt
                && ($hold['reset_id'] === null || $hold['reset_id'] === $resetId))) {
                unset($state['draining'][$key]);
            }
        }, requireState: true);
    }

    public function isDraining(string $symbol): bool
    {
        return in_array(strtoupper($symbol), $this->drainingSymbols(), true);
    }

    /** @return list<string> Symbols with a drain hold for this checkout. */
    public function drainingSymbols(bool $requireTelemetry = false): array
    {
        return array_map('strval', array_keys($this->drainHoldIds($requireTelemetry)));
    }

    /** @return array<string, string> The current drain hold identity for each held agent in this checkout. */
    public function drainHoldIds(bool $requireTelemetry = false): array
    {
        $file = $this->directory(false).'/budget.json';
        if (file_exists($file) && ! is_file($file)) {
            throw new RequestDeferred('Host request-budget drain state could not be read.', 10);
        }
        $state = $this->readState($file, $requireTelemetry);
        if (array_key_exists('draining', $state) && ! is_array($state['draining'])) {
            throw new RequestDeferred('Host request-budget drain state could not be read.', 10);
        }
        $prefix = hash('sha256', base_path()).':';
        $holds = [];
        foreach ($state['draining'] ?? [] as $key => $hold) {
            if (! is_string($key) || ! str_starts_with($key, $prefix)) {
                continue;
            }
            if (($id = $this->drainHoldId($hold)) !== null) {
                $holds[substr($key, strlen($prefix))] = $id;
            }
        }

        return $holds;
    }

    /** Compare and remove the reviewed hold while holding the budget file's writer lock. */
    public function clearDrainHold(string $symbol, string $expectedId): bool
    {
        return $this->change(function (&$state) use ($symbol, $expectedId) {
            if (array_key_exists('draining', $state) && ! is_array($state['draining'])) {
                throw new RequestDeferred('Host request-budget drain state could not be read.', 10);
            }
            $key = $this->drainKey($symbol);
            if ($this->drainHoldId($state['draining'][$key] ?? false) !== $expectedId) {
                return false;
            }
            unset($state['draining'][$key]);

            return true;
        }, requireState: true);
    }

    private function drainHoldId(mixed $hold): ?string
    {
        $this->assertDrainHold($hold);
        if (is_array($hold)) {
            return $hold['id'] ?? hash('sha256', json_encode([$hold['since'], $hold['reset_id']], JSON_THROW_ON_ERROR));
        }

        return $hold ? 'legacy' : null;
    }

    private function assertDrainHold(mixed $hold): void
    {
        if (is_bool($hold)) {
            return;
        }
        if (! is_array($hold) || ! is_numeric($hold['since'] ?? null) || $hold['since'] <= 0
            || ! array_key_exists('reset_id', $hold) || ($hold['reset_id'] !== null && ! is_string($hold['reset_id']))
            || (array_key_exists('id', $hold) && (! is_string($hold['id']) || ! preg_match('/\A[a-f0-9]{32}\z/', $hold['id'])))) {
            throw new RequestDeferred('Host request-budget drain state could not be read.', 10);
        }
    }

    private function drainKey(string $symbol): string
    {
        return hash('sha256', base_path()).':'.strtoupper($symbol);
    }

    private ?bool $ordinaryReservation = null;

    public function usedOrdinaryCapacity(string $priority): bool
    {
        return $this->ordinaryReservation ?? self::tier($priority) > 1;
    }

    public function reserve(?string $token = null, ?string $account = null, string $priority = 'discovery', float $maxWait = 0.75): void
    {
        $this->ordinaryReservation = null;
        $caps = self::agentCaps();
        $agentSymbol = self::$currentAgent ?? (isset($caps['SHARED']) ? 'SHARED' : null);
        $key = $this->storeKey();
        $localDelay = (self::$processPauses[$key] ?? 0) - $this->now();
        if ($localDelay > 0) {
            throw new RequestDeferred('SpaceTraders transport is paused after response-budget recording failed.', (int) ceil($localDelay));
        }
        unset(self::$processPauses[$key]);
        $waited = 0.0;
        do {
            $delay = $this->change(function (&$state) use ($token, $account, $priority, $waited, $maxWait, $agentSymbol, $caps) {
                $now = $this->now();
                $keys = $this->keys($token, $account);
                $tier = self::tier($priority);
                $floor = match ($tier) {
                    1 => 0,
                    2 => $priority === 'write' ? 0 : 1,
                    3 => 2,
                    default => 3,
                };
                $delay = $tier > 1 && $agentSymbol && ! empty($state['draining'][$this->drainKey($agentSymbol)]) ? 60.0 : 0.0;
                foreach ($keys as $key) {
                    $bucket = $this->refill($state['buckets'][$key] ?? [], $now);
                    $state['buckets'][$key] = $bucket;
                    $delay = max($delay, $bucket['blocked_until'] - $now);
                    if (config('gameplay.request_budget_enabled')) {
                        $needed = min($floor, $bucket['capacity'] - 1) + 1;
                        $delay = max($delay, ($needed - $bucket['tokens']) / $bucket['rate']);
                    }
                }
                $agentKey = $agentSymbol ? 'agent:'.strtoupper($agentSymbol) : null;
                if ($agentKey !== null) {
                    $state['agents'][$agentKey] = $this->pruneAgentWindow($state['agents'][$agentKey] ?? [], $now);
                }
                $state['host_tiers'] = $this->pruneHostTiers($state['host_tiers'] ?? [], $now);
                $borrow = false;
                if (config('gameplay.request_budget_enabled') && $caps !== []) {
                    $hostTier24Limit = max(1, (int) config('gameplay.host_tier2_4_per_minute', 96));
                    $hostTier1Reserve = max(1, (int) config('gameplay.host_tier1_reserve_per_minute', 18));
                    $ordinary = $state['host_tiers']['t24'];
                    $protected = $state['host_tiers']['t1'];
                    $agentOrdinary = $state['agents'][$agentKey]['t24'] ?? [];
                    $agentProtected = $state['agents'][$agentKey]['t1'] ?? [];
                    $cap = $caps[$agentSymbol] ?? (isset($caps['SHARED']) ? 0 : PHP_INT_MAX);
                    // Tiers 3-4 shed at 80% of the ordinary host envelope.
                    $hostCeiling = $tier >= 3 ? (int) floor($hostTier24Limit * 0.8) : $hostTier24Limit;
                    $ordinaryDelay = max(
                        count($ordinary) >= $hostCeiling ? max(1.0, ($ordinary[0] ?? $now) + 60.0 - $now) : 0.0,
                        count($agentOrdinary) >= $cap ? max(5.0, ($agentOrdinary[0] ?? $now) + 3600.0 - $now) : 0.0,
                    );
                    if ($tier === 1) {
                        $reserve = $agentSymbol === 'SHARED' ? 180 : max(1, (int) config('gameplay.agent_tier1_reserve_per_hour', 300));
                        $protectedDelay = max(
                            count($protected) >= $hostTier1Reserve ? max(1.0, $protected[0] + 60.0 - $now) : 0.0,
                            count($agentProtected) >= $reserve ? max(1.0, $agentProtected[0] + 3600.0 - $now) : 0.0,
                        );
                        // Settlement can borrow only when both ordinary windows have space.
                        $borrow = $protectedDelay > 0 && $ordinaryDelay === 0.0;
                        $delay = max($delay, min($protectedDelay, $ordinaryDelay));
                    } else {
                        $delay = max($delay, $ordinaryDelay);
                    }
                }
                if ($delay > 0.001) {
                    if ($delay + $waited > $maxWait) {
                        foreach ($keys as $key) {
                            $stats = &$state['buckets'][$key]['usage'];
                            $stats['deferrals']++;
                            $stats['deferred_seconds'] += $delay;
                        }
                        if ($agentKey !== null) {
                            $state['agents'][$agentKey]['deferred_at'][] = $now;
                            $state['agents'][$agentKey]['deferrals'] = ($state['agents'][$agentKey]['deferrals'] ?? 0) + 1;
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
                $this->ordinaryReservation = $tier > 1 || $borrow;
                if ($tier === 1 && ! $borrow) {
                    $state['host_tiers']['t1'][] = $now;
                    if ($agentKey !== null) {
                        $state['agents'][$agentKey]['t1'][] = $now;
                    }
                } else {
                    $state['host_tiers']['t24'][] = $now;
                    if ($agentKey !== null) {
                        $state['agents'][$agentKey]['t24'][] = $now;
                    }
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

    public static function tier(string $priority): int
    {
        return match ($priority) {
            'settlement', 'safety', 'reconcile' => 1,
            'write', 'run', 'critical' => 2,
            'director' => 3,
            default => 4,
        };
    }

    /** @return array<string, int> */
    public static function agentCaps(): array
    {
        $stage = config('gameplay.request_budget_stage', 'off');
        if ($stage !== 'off') {
            return self::stageCaps($stage);
        }

        $configured = config('gameplay.agent_request_caps', []);
        if (! is_array($configured)) {
            return [];
        }
        $caps = [];
        foreach ($configured as $symbol => $cap) {
            if (is_string($symbol) && $symbol !== '' && is_numeric($cap)) {
                $caps[strtoupper($symbol)] = max(0, (int) $cap);
            }
        }

        return $caps;
    }

    /** @return array<string, int> */
    public static function stageCaps(string $stage): array
    {
        $caps = match ($stage) {
            'stage1' => ['GIANTSOUP' => 3400, 'SOUPBURN' => 1600, 'SOUPFLEET' => 0, 'SHARED' => 760],
            'stage2' => ['GIANTSOUP' => 2880, 'SOUPBURN' => 720, 'SOUPFLEET' => 1800, 'SHARED' => 360],
            default => throw new GameException('Unknown SpaceTraders request budget stage. Set off, stage1, or stage2.'),
        };
        if (array_sum($caps) !== 5760 || $caps['GIANTSOUP'] < 2880) {
            throw new GameException('Invalid SpaceTraders request budget stage allocation.');
        }

        return $caps;
    }

    public function ordinaryCapReached(string $symbol): bool
    {
        $usage = $this->usage(agentSymbol: $symbol);

        return $usage['agent']['tier2_4_last_hour'] >= ($usage['agent']['cap_per_hour'] ?? PHP_INT_MAX)
            || $usage['host']['tier2_4_last_60_seconds'] >= (int) config('gameplay.host_tier2_4_per_minute', 96);
    }

    public function countRateLimitsSince(float $since): int
    {
        $state = $this->readState($this->directory(false).'/budget.json');
        $cutoff = max($since, $this->now() - 900);

        return count(array_filter($state['host_rate_limits'] ?? [], fn ($at) => $at > $cutoff));
    }

    private function pruneAgentWindow(array $entry, float $now): array
    {
        $entry += ['t1' => [], 't24' => [], 'deferrals' => 0, 'deferred_at' => []];
        $entry['deferred_at'] = array_values(array_filter($entry['deferred_at'], fn ($at) => $at > $now - 3600));
        $entry['t1'] = array_values(array_filter($entry['t1'], fn ($at) => $at > $now - 3600));
        $entry['t24'] = array_values(array_filter($entry['t24'], fn ($at) => $at > $now - 3600));

        return $entry;
    }

    private function pruneHostTiers(array $tiers, float $now): array
    {
        $tiers += ['t1' => [], 't24' => []];
        $tiers['t1'] = array_values(array_filter($tiers['t1'], fn ($at) => $at > $now - 60));
        $tiers['t24'] = array_values(array_filter($tiers['t24'], fn ($at) => $at > $now - 60));

        return $tiers;
    }

    public function observe(Response $response, ?string $token = null, ?string $account = null, ?string $category = null): void
    {
        $this->change(function (&$state) use ($response, $token, $account, $category) {
            $now = $this->now();
            $keys = $this->keys($token, $account);
            foreach ($keys as $key) {
                $state['buckets'][$key] = $this->refill($state['buckets'][$key] ?? [], $now);
                $stats = &$state['buckets'][$key]['usage'];
                $stats['responses']++;
                $stats['rate_limits'] += (int) ($response->status() === 429);
                $stats['server_errors'] += (int) $response->serverError();
            }
            $state['host_rate_limits'] = array_values(array_filter($state['host_rate_limits'] ?? [], fn ($at) => $at > $now - 900));
            if ($response->status() === 429) {
                $state['host_rate_limits'][] = $now;
            }
            $type = strtolower(trim($response->header('X-RateLimit-Type') ?? ''));
            $scope = $type === 'account' && count($keys) > 1 ? $keys[1] : 'host';
            $bucket = &$state['buckets'][$scope];
            if ($category !== null) {
                // Headers are only visible here: count each X-RateLimit-Type seen per endpoint category.
                $label = $type === '' ? 'unspecified' : $type;
                $bucket['usage']['rate_limit_types'][$category][$label] = ($bucket['usage']['rate_limit_types'][$category][$label] ?? 0) + 1;
            }
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

    public function usage(?string $token = null, ?string $account = null, ?string $agentSymbol = null, bool $requireTelemetry = false): array
    {
        $state = $this->readState($this->directory(false).'/budget.json', $requireTelemetry);
        try {
            return $this->usageReport($state, $token, $account, $agentSymbol);
        } catch (\TypeError $exception) {
            if (! $requireTelemetry) {
                throw $exception;
            }
            throw new RequestDeferred('Host request-budget telemetry could not be read.', 10);
        }
    }

    private function usageReport(array $state, ?string $token, ?string $account, ?string $agentSymbol): array
    {
        $now = $this->now();
        $result = [];
        foreach ($this->keys($token, $account) as $index => $key) {
            $bucket = $this->refill($state['buckets'][$key] ?? [], $now);
            $result[$index === 0 ? 'host' : 'account'] = $bucket['usage'] + [
                'requests_last_60_seconds' => count($bucket['recent']), 'requests_per_second_last_60' => count($bucket['recent']) / 60,
                'requests_per_second_last_5' => count(array_filter($bucket['recent'], fn ($at) => $at > $now - 5)) / 5,
                'sustained_per_second' => $bucket['rate'], 'burst_capacity' => $bucket['capacity'],
                'available' => $bucket['tokens'], 'blocked_until' => $bucket['blocked_until'],
                'last_response' => $bucket['last_response'] ?? null,
            ];
        }
        $tiers = $this->pruneHostTiers($state['host_tiers'] ?? [], $now);
        $result['host']['tier2_4_last_60_seconds'] = count($tiers['t24']);
        $result['host']['tier1_last_60_seconds'] = count($tiers['t1']);
        $result['host']['observed_at'] = $now;
        $result['host']['rate_limits_last_15_minutes'] = count(array_filter($state['host_rate_limits'] ?? [], fn ($at) => $at > $now - 900));
        if ($agentSymbol !== null && $agentSymbol !== '') {
            $symbol = strtoupper($agentSymbol);
            $entry = $this->pruneAgentWindow($state['agents']['agent:'.$symbol] ?? [], $now);
            $caps = self::agentCaps();
            $result['agent'] = [
                'symbol' => $symbol,
                'tier2_4_last_hour' => count($entry['t24']),
                'tier1_last_hour' => count($entry['t1']),
                // Must match reserve(): once a staged table exists, an unlisted agent has no ordinary allowance.
                'cap_per_hour' => $caps[$symbol] ?? (isset($caps['SHARED']) ? 0 : null),
                'tier1_reserve_per_hour' => $symbol === 'SHARED' ? 180 : (int) config('gameplay.agent_tier1_reserve_per_hour', 300),
                'deferrals' => $entry['deferrals'] ?? 0,
                'deferrals_last_hour' => count($entry['deferred_at']),
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
        if (! is_string($directory) || (PHP_OS_FAMILY === 'Windows'
            ? ! preg_match('~\A[A-Za-z]:[\\\\/]~', $directory)
            : ! str_starts_with($directory, '/'))) {
            throw new RequestDeferred('Host request-budget storage requires an absolute shared path.', 10);
        }
        if (is_link($directory)) {
            throw new RequestDeferred('Host request-budget storage is unsafe.', 10);
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $directory = rtrim(str_replace('\\', '/', $directory), '/');
            foreach (explode('/', substr($directory, 3)) as $component) {
                if ($component === '' || str_contains($component, ':') || rtrim($component, '. ') !== $component) {
                    throw new RequestDeferred('Host request-budget storage is unsafe.', 10);
                }
            }
            $this->windowsPathIdentity($directory);
        }
        if (! is_dir($directory)) {
            if (! $create) {
                return $directory;
            }
            if (! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RequestDeferred('Host request-budget storage is unavailable.', 10);
            }
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $this->windowsDirectory($directory, $create);
        } else {
            if (fileowner($directory) !== posix_geteuid()) {
                throw new RequestDeferred('Host request-budget storage is unavailable.', 10);
            }
            if ($create) {
                @chmod($directory, 0700);
            }
        }
        if (is_link($directory.'/budget.json') || is_link($directory.'/budget.lock')) {
            throw new RequestDeferred('Host request-budget storage is unsafe.', 10);
        }

        return $directory;
    }

    private function windowsDirectory(string $directory, bool $create): void
    {
        $identity = $this->windowsPathIdentity($directory);
        $key = strtolower($directory);
        $verified = self::$windowsDirectories[$key] ?? null;
        if ($identity !== '' && $verified && $verified['identity'] === $identity
            && $verified['until'] > microtime(true) && (! $create || $verified['secured'])) {
            return;
        }
        $script = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$path = $env:SPACETRADERS_BUDGET_DIRECTORY
$user = [System.Security.Principal.WindowsIdentity]::GetCurrent().User
$item = Get-Item -LiteralPath $path -Force
for ($ancestor = $item; $null -ne $ancestor; $ancestor = $ancestor.Parent) {
    if ($ancestor.Attributes -band [System.IO.FileAttributes]::ReparsePoint) { exit 1 }
}
foreach ($name in @('budget.json', 'budget.lock')) {
    $file = Get-Item -LiteralPath (Join-Path $path $name) -Force -ErrorAction SilentlyContinue
    if ($null -ne $file -and (($file.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -or $file.PSIsContainer)) { exit 1 }
}
$acl = Get-Acl -LiteralPath $path
if ($acl.GetOwner([System.Security.Principal.SecurityIdentifier]).Value -ne $user.Value) { exit 1 }
$allowed = @($user.Value, 'S-1-5-18', 'S-1-5-32-544')
$secured = $acl.AreAccessRulesProtected
foreach ($rule in $acl.GetAccessRules($true, $true, [System.Security.Principal.SecurityIdentifier])) {
    if ($rule.AccessControlType -eq 'Allow' -and $rule.IdentityReference.Value -notin $allowed) { $secured = $false }
}
# Rewriting an inheritable ACL propagates to existing files and can briefly block a
# concurrent replace of budget.json, so only rewrite a directory that is not yet private.
if ($env:SPACETRADERS_BUDGET_CREATE -eq '1' -and -not $secured) {
    $acl.SetAccessRuleProtection($true, $false)
    foreach ($rule in @($acl.GetAccessRules($true, $false, [System.Security.Principal.SecurityIdentifier]))) {
        $acl.RemoveAccessRuleSpecific($rule)
    }
    foreach ($sid in @($user.Value, 'S-1-5-18', 'S-1-5-32-544')) {
        $identity = New-Object System.Security.Principal.SecurityIdentifier($sid)
        $rule = New-Object System.Security.AccessControl.FileSystemAccessRule($identity, 'FullControl', 'ContainerInherit, ObjectInherit', 'None', 'Allow')
        $acl.AddAccessRule($rule)
    }
    $item.SetAccessControl($acl)
    $acl = Get-Acl -LiteralPath $path
}
foreach ($rule in $acl.GetAccessRules($true, $true, [System.Security.Principal.SecurityIdentifier])) {
    if ($rule.AccessControlType -eq 'Allow' -and $rule.IdentityReference.Value -notin @($user.Value, 'S-1-5-18', 'S-1-5-32-544')) { exit 1 }
}
exit 0
POWERSHELL;
        try {
            $process = new Process([
                (getenv('SystemRoot') ?: 'C:/Windows').'/System32/WindowsPowerShell/v1.0/powershell.exe',
                '-NoLogo', '-NoProfile', '-NonInteractive', '-EncodedCommand',
                base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8')),
            ], env: ['SPACETRADERS_BUDGET_DIRECTORY' => $directory, 'SPACETRADERS_BUDGET_CREATE' => $create ? '1' : '0'], timeout: 10);
            $process->mustRun();
        } catch (\Throwable) {
            throw new RequestDeferred('Host request-budget storage is unavailable.', 10);
        }
        self::$windowsDirectories[$key] = ['identity' => $identity, 'secured' => $create, 'until' => microtime(true) + 60];
    }

    private function windowsPathIdentity(string $directory): string
    {
        $identity = [];
        $cacheable = true;
        for ($ancestor = $directory; ; $ancestor = dirname($ancestor)) {
            clearstatcache(true, $ancestor);
            $stat = @lstat($ancestor);
            if ($stat !== false) {
                if (($stat['mode'] & 0170000) !== 0040000) {
                    throw new RequestDeferred('Host request-budget storage is unsafe.', 10);
                }
                $cacheable = $cacheable && $stat['ino'] > 0;
                $identity[] = [$stat['dev'], $stat['ino'], $stat['ctime']];
            }
            if (dirname($ancestor) === $ancestor) {
                break;
            }
        }
        foreach (['budget.json', 'budget.lock'] as $name) {
            $file = $directory.'/'.$name;
            clearstatcache(true, $file);
            $stat = @lstat($file);
            if ($stat !== false && ($stat['mode'] & 0170000) !== 0100000) {
                throw new RequestDeferred('Host request-budget storage is unsafe.', 10);
            }
        }

        return $cacheable ? serialize($identity) : '';
    }

    private function readState(string $file, bool $required = false): array
    {
        try {
            $state = is_file($file) || $required
                ? json_decode(@file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) : ['buckets' => []];
            if (! is_array($state) || ! isset($state['buckets']) || ! is_array($state['buckets'])) {
                throw new \RuntimeException('Invalid request-budget state.');
            }

            return $state;
        } catch (\Throwable) {
            throw new RequestDeferred('Host request-budget state could not be read.', 10);
        }
    }

    private function change(callable $work, bool $requireState = false): mixed
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
            $state = $this->readState($file, $requireState);
            $result = $work($state);
            $temporary = tempnam($directory, '.budget-');
            if (! $temporary || file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR)) === false || ! chmod($temporary, 0600) || ! $this->replace($temporary, $file)) {
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

    /**
     * Atomically replace the state file. Windows denies a replace while another
     * process briefly holds the destination open (unlocked safety stat calls,
     * antivirus or indexing), so retry within a short bound while the writer
     * lock is still held. POSIX rename does not have this failure mode.
     */
    private function replace(string $temporary, string $file): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return rename($temporary, $file);
        }
        $deadline = hrtime(true) + 250000000;
        while (! @rename($temporary, $file)) {
            if (hrtime(true) >= $deadline) {
                return false;
            }
            usleep(2000);
        }

        return true;
    }
}
