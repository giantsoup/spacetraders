<?php

namespace App\Gameplay;

class HostLock
{
    public function run(string $key, callable $work): mixed
    {
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
            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
