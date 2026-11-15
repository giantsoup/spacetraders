<?php

namespace App\Gameplay;

class HostLock
{
    private array $held = [];

    private array $nested = [];

    public function scoped(string $key, callable $work): mixed
    {
        return $this->run($key, function () use ($key, $work) {
            $previous = $this->nested[$key] ?? false;
            $this->nested[$key] = true;
            try {
                return $work();
            } finally {
                $this->nested[$key] = $previous;
            }
        });
    }

    public function run(string $key, callable $work): mixed
    {
        if (isset($this->held[$key]) && ($this->nested[$key] ?? false)) {
            return $work();
        }
        $directory = config('gameplay.lock_path');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $handle = fopen($directory.'/'.hash('sha256', $key).'.lock', 'c');
        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new GameException('Another operation owns this command boundary. Retry shortly.', retryAfter: 2);
        }
        try {
            $this->held[$key] = true;

            return $work();
        } finally {
            unset($this->held[$key]);
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
