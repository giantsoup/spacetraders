<?php

namespace Tests\Unit\Engine;

use App\Gameplay\Engine\HostCommands;
use App\Gameplay\Engine\LaunchdEngineHost;
use App\Gameplay\Engine\Windows\WindowsEngineHost;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkerEnvironmentTest extends TestCase
{
    #[DataProvider('hostsAndRoles')]
    public function test_only_worker_children_receive_the_current_worker_session(string $hostClass, string $role): void
    {
        if ($hostClass === WindowsEngineHost::class && PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows child requires php-win.exe.');
        }
        $directory = config('gameplay.lock_path').'/child-fixture';
        mkdir($directory, 0700, true);
        mkdir(config('gameplay.lock_path').'/engine', 0700, true);
        file_put_contents($directory.'/artisan', <<<'PHP'
<?php
file_put_contents($argv[1], json_encode([
    'worker_session' => getenv('SPACETRADERS_ENGINE_WORKER_SESSION'),
    'watch_session' => getenv('SPACETRADERS_ENGINE_SESSION'),
    'watch_role' => getenv('SPACETRADERS_ENGINE_ROLE'),
]));
PHP);
        config(['gameplay.php_binary' => PHP_BINARY]);
        $previous = getenv('SPACETRADERS_ENGINE_WORKER_SESSION');
        $base = base_path();
        $session = (string) Str::uuid();
        putenv('SPACETRADERS_ENGINE_WORKER_SESSION=inherited-stale-session');
        $this->app->setBasePath($directory);
        try {
            $host = new $hostClass(new HostCommands);
            $output = $directory.'/environment.json';
            $child = $host->spawn($session, $role, [$output]);
            $deadline = microtime(true) + 10;
            while ($child->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFalse($child->isRunning());
            $this->assertFileExists($output);
            $env = json_decode(file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($role === 'worker' ? $session : false, $env['worker_session']);
            if ($hostClass === LaunchdEngineHost::class) {
                $this->assertFalse($env['watch_session']);
                $this->assertFalse($env['watch_role']);
            } else {
                $this->assertSame($session, $env['watch_session']);
                $this->assertSame($role, $env['watch_role']);
            }
        } finally {
            $this->app->setBasePath($base);
            putenv($previous === false ? 'SPACETRADERS_ENGINE_WORKER_SESSION' : 'SPACETRADERS_ENGINE_WORKER_SESSION='.$previous);
        }
    }

    public static function hostsAndRoles(): array
    {
        return [
            'launchd worker' => [LaunchdEngineHost::class, 'worker'],
            'launchd scheduler' => [LaunchdEngineHost::class, 'scheduler'],
            'Windows worker' => [WindowsEngineHost::class, 'worker'],
            'Windows scheduler' => [WindowsEngineHost::class, 'scheduler'],
        ];
    }
}
