<?php

namespace Tests\Unit\Engine;

use App\Gameplay\Engine\EngineLease;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class EngineLeaseTest extends TestCase
{
    public function test_hold_waits_for_a_brief_exclusive_probe(): void
    {
        $path = config('gameplay.lock_path').'/probe.lease';
        mkdir(dirname($path), 0700, true);
        $probe = fopen($path, 'c+b');
        flock($probe, LOCK_EX);
        $code = 'require $argv[1]; echo "ready\n"; fflush(STDOUT); fgets(STDIN); App\Gameplay\Engine\EngineLease::hold($argv[2]); echo "held\n";';
        $process = proc_open([PHP_BINARY, '-r', $code, base_path('vendor/autoload.php'), $path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        try {
            stream_set_timeout($pipes[1], 10);
            $this->assertSame("ready\n", fgets($pipes[1]));
            fwrite($pipes[0], "go\n");
            usleep(50000);
            flock($probe, LOCK_UN);
            $this->assertSame("held\n", fgets($pipes[1]), stream_get_contents($pipes[2]));
        } finally {
            fclose($probe);
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    public function test_repeated_holds_succeed_during_concurrent_liveness_probes(): void
    {
        $directory = config('gameplay.lock_path').'/probe-race';
        mkdir($directory, 0700, true);
        $paths = [];
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $paths[] = $directory.'/'.$attempt.'.lease';
            touch($paths[$attempt]);
        }
        $code = 'require $argv[1]; $paths = json_decode($argv[2], true); file_put_contents($argv[3], "ready"); while (!is_file($argv[4])) { usleep(1000); } $failures = 0; foreach ($paths as $path) { try { App\Gameplay\Engine\EngineLease::hold($path); } catch (Throwable) { $failures++; } } echo $failures;';
        $process = proc_open([PHP_BINARY, '-r', $code, base_path('vendor/autoload.php'), json_encode($paths), $directory.'/ready', $directory.'/go'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), null, ['bypass_shell' => true]);
        $this->assertIsResource($process);
        try {
            fclose($pipes[0]);
            $deadline = microtime(true) + 10;
            while (! is_file($directory.'/ready') && microtime(true) < $deadline) {
                clearstatcache();
                usleep(1000);
            }
            $this->assertFileExists($directory.'/ready');
            touch($directory.'/go');
            do {
                foreach ($paths as $path) {
                    EngineLease::held($path);
                }
            } while (proc_get_status($process)['running'] && microtime(true) < $deadline);
            $this->assertFalse(proc_get_status($process)['running']);
            $this->assertSame('0', stream_get_contents($pipes[1]), stream_get_contents($pipes[2]));
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    public function test_cleanup_removes_only_unheld_artifacts_from_other_sessions(): void
    {
        $current = '11111111-2222-3333-4444-555555555555';
        $stale = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $live = 'ffffffff-bbbb-cccc-dddd-eeeeeeeeeeee';
        $directory = dirname(dirname(EngineLease::path($current, 'supervisor')));
        mkdir($directory.'/leases', 0700, true);
        foreach ([$current, $stale, $live] as $session) {
            foreach (['supervisor', 'worker', 'scheduler'] as $role) {
                touch(EngineLease::path($session, $role));
            }
            file_put_contents($directory.'/'.$session.'-worker.job', '{}');
            touch($directory.'/'.$session.'-worker.job.'.str_repeat('a', 32).'.lease');
            touch($directory.'/'.$session.'-worker.job.'.str_repeat('a', 16).'.tmp');
        }
        $handle = fopen(EngineLease::path($live, 'worker'), 'r+b');
        flock($handle, LOCK_EX);
        try {
            EngineLease::cleanup($current);
            foreach ([$current, $live] as $session) {
                $this->assertFileExists(EngineLease::path($session, 'worker'));
                $this->assertFileExists($directory.'/'.$session.'-worker.job');
            }
            $this->assertSame([], glob($directory.'/leases/'.$stale.'*'));
            $this->assertSame([], glob($directory.'/'.$stale.'*'));
            fclose($handle);
            $handle = null;
            EngineLease::cleanup($current);
            $this->assertSame([], glob($directory.'/leases/'.$live.'*'));
            $this->assertSame([], glob($directory.'/'.$live.'*'));
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    public function test_a_real_child_holds_a_lease_until_it_is_killed(): void
    {
        $path = EngineLease::path('11111111-2222-3333-4444-555555555555', 'worker');
        $this->assertFalse(EngineLease::held($path));
        $code = 'require $argv[1]; App\\Gameplay\\Engine\\EngineLease::hold($argv[2]); echo "ready\n"; fflush(STDOUT); sleep(30);';
        $process = proc_open([PHP_BINARY, '-r', $code, base_path('vendor/autoload.php'), $path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), null, ['bypass_shell' => true]);
        $this->assertIsResource($process);
        try {
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 10);
            $this->assertSame("ready\n", fgets($pipes[1]));
            $this->assertTrue(EngineLease::held($path));
            $this->assertSame(0, filesize($path));
            try {
                EngineLease::hold($path);
                $this->fail('An existing owner must not be replaced.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('already held', $exception->getMessage());
            }
            $started = hrtime(true);
            proc_terminate($process);
            do {
                $held = EngineLease::held($path);
                if ($held) {
                    usleep(10000);
                }
            } while ($held && hrtime(true) - $started < 1_000_000_000);
            $this->assertFalse($held, 'A killed child must release its lease within one second.');
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    #[TestWith(['../escape', 'worker'])]
    #[TestWith(['11111111-2222-3333-4444-555555555555', '../worker'])]
    #[TestWith(['11111111-2222-3333-4444-555555555555', 'other'])]
    public function test_lease_identity_rejects_path_traversal_and_unknown_roles(string $session, string $role): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EngineLease::path($session, $role);
    }
}
