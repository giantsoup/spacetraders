<?php

namespace Tests\Unit\Engine;

use App\Gameplay\Engine\EngineLease;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class EngineLeaseTest extends TestCase
{
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
