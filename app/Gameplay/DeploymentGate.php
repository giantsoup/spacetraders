<?php

namespace App\Gameplay;

use Illuminate\Cache\FileStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

class DeploymentGate
{
    public const VERSION = 1;

    public const DRAIN_SECONDS = 600;

    private array $leases = [];

    private ?string $worker = null;

    public function begin(string $role): ?string
    {
        return $this->locked(function (array &$state) use ($role) {
            if ($role === 'web' && $state['phase'] !== 'open') {
                return null;
            }
            $id = $this->participant($state, $role);
            if ($state['phase'] !== 'open') {
                $this->acknowledge($state, $id, true);

                return null;
            }
            $state['participants'][$id]['busy'] = true;

            return $id;
        });
    }

    public function finish(string $id): void
    {
        $this->locked(function (array &$state) use ($id) {
            $this->acknowledge($state, $id, true);
        });
    }

    // JobPopping runs even when Laravel's persistent queue pause prevents pop().
    public function workerPolling(): void
    {
        $this->locked(function (array &$state) {
            $this->worker ??= $this->participant($state, 'worker');
            $this->acknowledge($state, $this->worker, false);
        });
    }

    public function workerStopped(): void
    {
        if ($this->worker !== null) {
            $this->finish($this->worker);
            $this->worker = null;
        }
    }

    // Serialize close with the actual SQL reservation, not just the worker loop.
    public function reserve(callable $pop): mixed
    {
        return $this->locked(function (array &$state) use ($pop) {
            $this->worker ??= $this->participant($state, 'worker');
            if ($state['phase'] !== 'open' || Queue::isPaused('database', 'spacetraders')) {
                $this->acknowledge($state, $this->worker, false);

                return null;
            }
            $state['participants'][$this->worker]['busy'] = true;
            $job = $pop();
            if ($job === null) {
                $this->acknowledge($state, $this->worker, false);
            }

            return $job;
        });
    }

    public function close(string $generation): array
    {
        return $this->locked(function (array &$state) use ($generation) {
            $this->persistentCache();
            if ($state['phase'] !== 'open') {
                $this->generation($state, $generation);

                return $state;
            }
            if ($state['generation'] === $generation) {
                throw new RuntimeException('Use a fresh generation after an open or aborted deployment.');
            }
            $state['generation'] = $generation;
            $state['phase'] = 'draining';
            $state['closed_at'] = now()->utc()->toIso8601String();
            $state['deadline'] = now()->utc()->addSeconds(self::DRAIN_SECONDS)->toIso8601String();
            $state['outcome'] = null;
            $state['required'] = array_keys($state['participants']);
            $state['queue_was_paused'] = (bool) Cache::get('illuminate:queue:paused:database:spacetraders', false);
            $state['runtime_identity'] = $this->runtimeIdentity();
            // Persist intent before changing cache. Every entrance repairs an interrupted pause.
            $this->save($state);
            Queue::pause('database', 'spacetraders');

            return $state;
        });
    }

    public function status(): array
    {
        return $this->locked(function (array &$state) {
            $blockers = [];
            $worker = false;
            $scheduler = false;
            if ($state['phase'] !== 'open') {
                foreach ($state['participants'] as $id => $participant) {
                    $alive = $this->alive($id);
                    $acked = $participant['ack'] === $state['generation'];
                    if ($participant['busy'] || (in_array($id, $state['required'], true) && ! $acked)) {
                        $blockers[] = $participant['role'].':'.$id.($alive ? ':awaiting_ack' : ':lost_without_ack');
                    }
                    $worker = $worker || ($participant['role'] === 'worker' && $alive && ! $participant['finished'] && $acked && ! $participant['busy']);
                    $scheduler = $scheduler || ($participant['role'] === 'scheduler' && $acked && ! $participant['busy']);
                }
                if (! $worker) {
                    $blockers[] = 'worker:awaiting_live_ack';
                }
                if (! $scheduler) {
                    $blockers[] = 'scheduler:awaiting_descendant_ack';
                }
                if (DB::connection(config('queue.connections.database.connection'))->table(config('queue.connections.database.table'))->where('queue', 'spacetraders')->whereNotNull('reserved_at')->exists()) {
                    $blockers[] = 'queue:reserved_jobs';
                }
            }
            if ($state['phase'] === 'draining' && $blockers === []) {
                $state['phase'] = 'closed';
            }

            return $state + ['drained' => $state['phase'] === 'closed' && $blockers === [], 'blockers' => $blockers];
        });
    }

    public function open(string $generation): array
    {
        return $this->locked(function (array &$state) use ($generation) {
            $this->generation($state, $generation);
            $this->restore($state, 'opened');

            return $state;
        });
    }

    private function generation(array $state, string $generation): void
    {
        if ($state['generation'] !== $generation) {
            throw new RuntimeException('Deployment generation does not match. Refusing a stale controller.');
        }
    }

    private function persistentCache(): void
    {
        if (! Cache::getStore() instanceof FileStore) {
            throw new RuntimeException('Deployment control requires the shared persistent file cache. Configure CACHE_STORE=file and CACHE_PATH outside releases.');
        }
    }

    private function runtimeIdentity(): string
    {
        $this->persistentCache();

        return hash('sha256', json_encode([Cache::getStore()->getDirectory(), config('database.connections.'.config('database.default').'.database'),
            config('queue.connections.database')], JSON_THROW_ON_ERROR));
    }

    private function restore(array &$state, string $outcome): void
    {
        if ($state['phase'] !== 'open') {
            $this->persistentCache();
            if (! $state['queue_was_paused']) {
                Queue::resume('database', 'spacetraders');
            }
            $state['phase'] = 'open';
            $state['outcome'] = $outcome;
        }
    }

    private function participant(array &$state, string $role): string
    {
        $id = bin2hex(random_bytes(16));
        $lease = fopen($this->path().'/'.$id.'.lease', 'c+');
        if ($lease === false || ! flock($lease, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Cannot acquire deployment process lease.');
        }
        $this->leases[$id] = $lease;
        $state['participants'][$id] = ['role' => $role, 'pid' => getmypid(), 'release' => config('deployment.release'),
            'busy' => false, 'finished' => false, 'ack' => null, 'observed_at' => now()->utc()->toIso8601String()];

        return $id;
    }

    private function acknowledge(array &$state, string $id, bool $finished): void
    {
        $state['participants'][$id]['busy'] = false;
        $state['participants'][$id]['finished'] = $finished;
        $state['participants'][$id]['observed_at'] = now()->utc()->toIso8601String();
        $state['participants'][$id]['ack'] = $state['phase'] === 'open' ? null : $state['generation'];
        if ($finished && isset($this->leases[$id])) {
            fclose($this->leases[$id]);
            unset($this->leases[$id]);
        }
    }

    private function alive(string $id): bool
    {
        if (isset($this->leases[$id])) {
            return true;
        }
        $file = fopen($this->path().'/'.$id.'.lease', 'c+');
        if ($file === false) {
            throw new RuntimeException('Cannot inspect deployment lease.');
        }
        $alive = ! flock($file, LOCK_EX | LOCK_NB);
        fclose($file);

        return $alive;
    }

    private function path(): string
    {
        $path = config('deployment.path');
        if (! is_dir($path) && ! @mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('Cannot create private deployment directory.');
        }

        return $path;
    }

    private function save(array $state): void
    {
        $path = $this->path();
        $temporary = $path.'/state-'.bin2hex(random_bytes(8)).'.tmp';
        if (file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Cannot persist deployment state.');
        }
        chmod($temporary, 0600);
        $file = fopen($temporary, 'r+');
        try {
            if (! fsync($file) || ! rename($temporary, $path.'/state.json')) {
                throw new RuntimeException('Cannot commit deployment state.');
            }
        } finally {
            fclose($file);
        }
    }

    private function locked(callable $work): mixed
    {
        $path = $this->path();
        $lock = fopen($path.'/admission.lock', 'c+');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('Cannot lock deployment admission.');
        }
        try {
            $state = is_file($path.'/state.json') ? json_decode(file_get_contents($path.'/state.json'), true, flags: JSON_THROW_ON_ERROR) :
                ['version' => self::VERSION, 'phase' => 'open', 'generation' => null, 'participants' => [], 'required' => [], 'outcome' => null];
            if (($state['version'] ?? null) !== self::VERSION || ! in_array($state['phase'] ?? null, ['open', 'draining', 'closed'], true)
                || ! is_array($state['participants'] ?? null) || ! is_array($state['required'] ?? null)
                || ! array_key_exists('generation', $state) || ! array_key_exists('outcome', $state)) {
                throw new RuntimeException('Unsupported deployment protocol. Keep gameplay gated and use compatible release code.');
            }
            if ($state['phase'] !== 'open' && (($state['runtime_identity'] ?? null) !== $this->runtimeIdentity()
                || ! is_string($state['generation']) || ! is_string($state['deadline'] ?? null) || ! is_bool($state['queue_was_paused'] ?? null))) {
                throw new RuntimeException('Deployment runtime identity or closed contract differs. Keep gameplay gated and restore shared paths.');
            }
            foreach ($state['participants'] as $id => $participant) {
                if (! preg_match('/\A[0-9a-f]{32}\z/', $id) || ! is_array($participant)
                    || ! in_array($participant['role'] ?? null, ['web', 'worker', 'scheduler', 'dispatch'], true)
                    || ! is_bool($participant['busy'] ?? null) || ! is_bool($participant['finished'] ?? null)
                    || ! array_key_exists('ack', $participant)) {
                    throw new RuntimeException('Invalid deployment participant contract. Keep gameplay gated.');
                }
            }
            foreach ($state['required'] as $id) {
                if (! is_string($id) || ! preg_match('/\A[0-9a-f]{32}\z/', $id)
                    || ($state['phase'] !== 'open' && ! isset($state['participants'][$id]))) {
                    throw new RuntimeException('Invalid deployment acknowledgement contract. Keep gameplay gated.');
                }
            }
            if ($state['phase'] === 'draining' && now()->gte($state['deadline'])) {
                $this->restore($state, 'aborted_timeout');
                $this->save($state);
            }
            if ($state['phase'] !== 'open') {
                $this->persistentCache();
                Queue::pause('database', 'spacetraders');
                // Keep the latest completed scheduler observation and all required acknowledgements.
                $latest = [];
                foreach ($state['participants'] as $id => $participant) {
                    if ($participant['finished'] && ! in_array($id, $state['required'], true)) {
                        $role = $participant['role'];
                        if (isset($latest[$role])) {
                            unset($state['participants'][$latest[$role]]);
                            @unlink($path.'/'.$latest[$role].'.lease');
                        }
                        $latest[$role] = $id;
                    }
                }
            } else {
                foreach ($state['participants'] as $id => $participant) {
                    if ($participant['finished'] || ! $this->alive($id)) {
                        unset($state['participants'][$id]);
                        @unlink($path.'/'.$id.'.lease');
                    }
                }
            }
            $result = $work($state);
            $this->save($state);

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
