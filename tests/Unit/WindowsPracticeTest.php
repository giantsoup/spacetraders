<?php

namespace Tests\Unit;

use App\Gameplay\Engine\HostCommands;
use App\Gameplay\Engine\HostResult;
use Dotenv\Dotenv;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

#[RequiresOperatingSystemFamily('Windows')]
class WindowsPracticeTest extends TestCase
{
    private string $directory;

    private array $environment;

    protected function setUp(): void
    {
        parent::setUp();
        $home = str_replace('\\', '/', config('gameplay.lock_path')).'/practice';
        $this->directory = $home.'/environment';
        foreach (['app', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $path) {
            mkdir($this->directory.'/storage/'.$path, 0700, true);
        }
        file_put_contents($home.'/.checkout', base_path());
        file_put_contents($this->directory.'/browser.json', '{}');
        file_put_contents($this->directory.'/world.json', '{}');
        touch($this->directory.'/.windows-ready');
        touch($this->directory.'/database.sqlite');
        $this->environment = [
            'APP_ENV' => 'local', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'APP_BASE_PATH' => str_replace('\\', '/', base_path()),
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->directory.'/database.sqlite', 'DB_URL' => '', 'DATABASE_URL' => '',
            'SPACETRADERS_LOCAL_ENGINE_ENABLED' => 'false', 'SPACETRADERS_FIXTURE_PATH' => $this->directory.'/world.json',
            'SPACETRADERS_LOCK_PATH' => $this->directory.'/locks', 'SPACETRADERS_REQUEST_BUDGET_PATH' => $this->directory.'/budget',
            'SPACETRADERS_DEPLOYMENT_PATH' => $this->directory.'/deployment', 'LARAVEL_STORAGE_PATH' => $this->directory.'/storage',
            'CACHE_PATH' => $this->directory.'/cache', 'APP_CONFIG_CACHE' => $this->directory.'/no-config.php',
            'APP_ROUTES_CACHE' => $this->directory.'/no-routes.php', 'APP_EVENTS_CACHE' => $this->directory.'/no-events.php',
            'APP_SERVICES_CACHE' => $this->directory.'/services.php', 'APP_PACKAGES_CACHE' => $this->directory.'/packages.php',
            'VIEW_COMPILED_PATH' => $this->directory.'/storage/framework/views',
            'SPACETRADERS_ENGINE_LOG' => $this->directory.'/storage/logs/engine.log',
            'SPACETRADERS_ENGINE_SESSION' => '', 'SPACETRADERS_ENGINE_ROLE' => '', 'SPACETRADERS_ENGINE_LEASE' => '',
        ];
    }

    private function runPractice(array $overrides = []): HostResult
    {
        $lines = [];
        foreach (array_replace($this->environment, $overrides) as $name => $value) {
            $lines[] = $name.'="'.$value.'"';
        }
        file_put_contents($this->directory.'/.env', implode("\n", $lines)."\n");

        return (new HostCommands)->run([PHP_BINARY, base_path('scripts/windows/practice-runner.php'), $this->directory, 'env'], 15);
    }

    public function test_runner_boots_with_owned_effective_paths(): void
    {
        $result = $this->runPractice();
        $this->assertSame(0, $result->exit, $result->out.$result->err);
        $this->assertStringContainsString('local', $result->out);
    }

    public function test_fixture_overrides_inherited_mutable_paths_and_passes_the_booted_guard(): void
    {
        $directory = str_replace('\\', '/', config('gameplay.lock_path')).'/fresh/environment';
        mkdir(dirname($directory), 0700, true);
        file_put_contents(dirname($directory).'/.checkout', base_path());
        $inherited = [];
        foreach (['SPACETRADERS_DEPLOYMENT_PATH', 'CACHE_PATH', 'SPACETRADERS_LOCK_PATH', 'SPACETRADERS_REQUEST_BUDGET_PATH', 'LARAVEL_STORAGE_PATH', 'APP_CONFIG_CACHE'] as $key) {
            $inherited[$key] = $directory.'-outside/'.$key;
        }
        $result = (new HostCommands)->run([PHP_BINARY, base_path('scripts/mvp-fixture.php'), '--isolated', '--directory', $directory], 60, $inherited);
        $this->assertSame(0, $result->exit, $result->out.$result->err);
        $values = Dotenv::parse(file_get_contents($directory.'/.env'));
        foreach (array_keys($inherited) as $key) {
            $this->assertStringStartsWith($directory.'/', $values[$key], $key);
            $this->assertFileDoesNotExist($inherited[$key]);
        }
        $result = (new HostCommands)->run([PHP_BINARY, base_path('scripts/windows/practice-runner.php'), $directory, 'env'], 15, $inherited);
        $this->assertSame(0, $result->exit, $result->out.$result->err);
    }

    #[TestWith(['SPACETRADERS_DEPLOYMENT_PATH', 'deployment'])]
    #[TestWith(['CACHE_PATH', 'cache'])]
    #[TestWith(['SPACETRADERS_LOCK_PATH', 'locks'])]
    #[TestWith(['SPACETRADERS_REQUEST_BUDGET_PATH', 'request_budget'])]
    #[TestWith(['VIEW_COMPILED_PATH', 'views'])]
    #[TestWith(['SPACETRADERS_ENGINE_LOG', 'engine_log'])]
    public function test_runner_refuses_effective_paths_outside_practice(string $key, string $name): void
    {
        $outside = $this->directory.'-not-practice/'.$name;
        $result = $this->runPractice([$key => $outside]);
        $this->assertNotSame(0, $result->exit, $result->out.$result->err);
        $this->assertStringContainsString('Effective practice configuration escaped its directory: '.$name, $result->out.$result->err);
        $this->assertFileDoesNotExist($outside);
    }

    public function test_runner_refuses_parent_traversal_even_with_an_owned_prefix(): void
    {
        $result = $this->runPractice(['SPACETRADERS_DEPLOYMENT_PATH' => $this->directory.'/../escaped']);
        $this->assertNotSame(0, $result->exit, $result->out.$result->err);
        $this->assertStringContainsString('Effective practice configuration escaped its directory: deployment', $result->out.$result->err);
    }

    public function test_engine_practice_boots_with_isolated_paths_and_refuses_plain_workers(): void
    {
        $directory = str_replace('\\', '/', config('gameplay.lock_path')).'/engine-fixture/environment';
        mkdir(dirname($directory), 0700, true);
        file_put_contents(dirname($directory).'/.checkout', base_path());
        $commands = new HostCommands;
        $result = $commands->run([PHP_BINARY, base_path('scripts/mvp-fixture.php'), '--isolated', '--directory', $directory], 60);
        $this->assertSame(0, $result->exit, $result->out.$result->err);
        $runner = [PHP_BINARY, base_path('scripts/windows/practice-runner.php'), $directory];
        $result = $commands->run([...$runner, '--configure-engine'], 15);
        $this->assertSame(0, $result->exit, $result->out.$result->err);
        $this->assertSame('engine', file_get_contents($directory.'/.practice-mode'));
        $result = $commands->run([...$runner, '--engine-status'], 15);
        $this->assertSame(0, $result->exit, $result->out.$result->err);
        $status = json_decode($result->out, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('off', $status['state']);
        $this->assertFalse($status['registered']);
        $this->assertSame(['supervisor' => false, 'worker' => false, 'scheduler' => false], $status['leases']);
        $result = $commands->run([...$runner, 'queue:work', '--once'], 15);
        $this->assertNotSame(0, $result->exit, $result->out.$result->err);
        $this->assertStringContainsString('Plain workers and schedulers are forbidden', $result->out.$result->err);
        $mainConfig = dirname($directory).'/main-config.php';
        file_put_contents($mainConfig, '<?php return '.var_export([
            'app' => ['env' => 'local', 'timezone' => 'UTC'],
            'database' => ['connections' => ['sqlite' => ['database' => $directory.'/database.sqlite']]],
        ], true).';');
        $result = $commands->run([...$runner, '--engine-status'], 15, ['APP_CONFIG_CACHE' => $mainConfig]);
        $this->assertNotSame(0, $result->exit, $result->out.$result->err);
        $this->assertStringContainsString('Practice must never use the main checkout engine task', $result->out.$result->err);
    }

    public function test_runner_refuses_database_url_overrides(): void
    {
        $result = $this->runPractice(['DATABASE_URL' => 'sqlite:///outside.sqlite']);
        $this->assertNotSame(0, $result->exit, $result->out.$result->err);
        $this->assertStringContainsString('Practice environment isolation guard failed', $result->out.$result->err);
    }
}
