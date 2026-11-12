<?php

namespace Tests\Feature;

use App\Gameplay\DeploymentGate;
use App\Gameplay\Engine\Windows\PowerShell;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeploymentFileTest extends TestCase
{
    public function test_state_writes_leave_no_temporary_files(): void
    {
        $gate = new DeploymentGate;
        $id = $gate->begin('web');
        $gate->finish($id);
        $gate->status();

        $state = json_decode(file_get_contents(config('deployment.path').'/state.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('open', $state['phase']);
        $this->assertSame([], $state['participants']);
        $this->assertSame([], glob(config('deployment.path').'/state-*.tmp'));
    }

    public function test_windows_replacement_waits_for_another_process_to_release_the_destination(): void
    {
        $this->withHeldDestination(true, function (DeploymentGate $gate, string $path) {
            $id = $gate->begin('web');
            $state = json_decode(file_get_contents($path.'/state.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertTrue($state['participants'][$id]['busy']);
            $this->assertSame([], glob($path.'/state-*.tmp'));
            $gate->finish($id);
        });
    }

    public function test_windows_replacement_failure_preserves_state_and_removes_the_temporary_file(): void
    {
        $this->withHeldDestination(false, function (DeploymentGate $gate, string $path) {
            $before = file_get_contents($path.'/state.json');
            try {
                $gate->begin('web');
                $this->fail('A destination held beyond the retry deadline must reject the commit.');
            } catch (RuntimeException $error) {
                $this->assertSame('Cannot commit deployment state.', $error->getMessage());
            }
            $this->assertSame($before, file_get_contents($path.'/state.json'));
            $this->assertSame([], glob($path.'/state-*.tmp'));
        });
    }

    public function test_cleanup_failure_does_not_replace_the_original_commit_error(): void
    {
        $gate = new class extends DeploymentGate
        {
            public bool $failCleanup = false;

            protected function remove(string $file): bool
            {
                return ! $this->failCleanup && parent::remove($file);
            }
        };
        $logger = Log::getFacadeRoot();
        Log::spy();
        try {
            $this->withHeldDestination(false, function (DeploymentGate $gate, string $path) {
                $before = file_get_contents($path.'/state.json');
                $gate->failCleanup = true;
                try {
                    $gate->begin('web');
                    $this->fail('A held destination must reject the commit.');
                } catch (RuntimeException $error) {
                    $this->assertSame('Cannot commit deployment state.', $error->getMessage());
                }
                $this->assertSame($before, file_get_contents($path.'/state.json'));
                $this->assertCount(1, glob($path.'/state-*.tmp'));
                Log::shouldHaveReceived('warning')->once()->with('Cannot remove temporary deployment state.', \Mockery::any());
            }, $gate);
        } finally {
            Log::swap($logger);
        }
    }

    private function withHeldDestination(bool $briefly, callable $work, ?DeploymentGate $gate = null): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows sharing violations require a second process holding the destination open.');
        }
        $gate ??= new DeploymentGate;
        $gate->status();
        $path = config('deployment.path');
        $script = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$handle = [IO.File]::Open($env:ST_STATE, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::ReadWrite)
try {
    [IO.File]::WriteAllText($env:ST_READY, 'ready')
    $deadline = [DateTime]::UtcNow.AddSeconds(10)
    if ($env:ST_BRIEF -eq 'yes') {
        # Release on a fixed timer from "ready" rather than after polling for "go", and use
        # Thread.Sleep: Windows PowerShell 5.1 can spend 150+ ms loading the module behind the
        # first Test-Path/Start-Sleep, which alone exceeds the writer's 250 ms retry window.
        [Threading.Thread]::Sleep(120)
    } else {
        while (-not (Test-Path -LiteralPath $env:ST_GO)) {
            if ([DateTime]::UtcNow -gt $deadline) { throw 'Timed out waiting for writer.' }
            Start-Sleep -Milliseconds 2
        }
        while (-not (Test-Path -LiteralPath $env:ST_STOP)) {
            if ([DateTime]::UtcNow -gt $deadline) { throw 'Timed out waiting for cleanup.' }
            Start-Sleep -Milliseconds 2
        }
    }
} finally {
    $handle.Dispose()
}
POWERSHELL;
        $process = new Process([PowerShell::executable('WindowsPowerShell/v1.0/powershell.exe'), '-NoLogo', '-NoProfile', '-NonInteractive', '-WindowStyle', 'Hidden', '-Command', $script], base_path(), [
            'ST_STATE' => $path.'/state.json', 'ST_READY' => $path.'/ready', 'ST_GO' => $path.'/go', 'ST_STOP' => $path.'/stop', 'ST_BRIEF' => $briefly ? 'yes' : 'no',
        ]);
        $process->setTimeout(15)->start();
        try {
            $deadline = microtime(true) + 10;
            while (! is_file($path.'/ready') && $process->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
                clearstatcache(true, $path.'/ready');
            }
            $this->assertFileExists($path.'/ready', $process->getErrorOutput());
            $this->assertFalse(@rename($path.'/state.json', $path.'/probe.json'));
            touch($path.'/go');
            $work($gate, $path);
        } finally {
            touch($path.'/go');
            touch($path.'/stop');
            $process->wait();
        }
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }
}
