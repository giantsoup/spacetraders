<?php

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
require_once __DIR__.'/PracticeEnvironment.php';

use App\Gameplay\Engine\HostCommands;
use App\Gameplay\Engine\Windows\WindowsEngineHost;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use SpaceTraders\Windows\PracticeEnvironment;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

$directory = str_replace('\\', '/', $argv[1] ?? '');
$owner = dirname($directory).'/.checkout';
if (! is_file($owner) || trim(file_get_contents($owner)) !== $root || ! is_file($directory.'/browser.json') || ! is_file($directory.'/.windows-ready')) {
    throw new RuntimeException('Practice ownership or completed fixture is missing. Run practice.ps1 Init.');
}
$values = Dotenv\Dotenv::parse(file_get_contents($directory.'/.env'));
if (($values['SPACETRADERS_FIXTURE_PATH'] ?? '') !== $directory.'/world.json'
    || ($values['DB_DATABASE'] ?? '') !== $directory.'/database.sqlite'
    || ($values['SPACETRADERS_LOCAL_ENGINE_ENABLED'] ?? '') !== (PracticeEnvironment::mode($directory) === 'engine' ? 'true' : 'false')
    || ($values['LARAVEL_STORAGE_PATH'] ?? '') !== $directory.'/storage'
    || ($values['APP_CONFIG_CACHE'] ?? '') !== $directory.'/no-config.php'
    || ! empty($values['DATABASE_URL']) || ! empty($values['DB_URL'])
    || file_exists($directory.'/no-config.php')) {
    throw new RuntimeException('Practice environment isolation guard failed.');
}
$main = require $root.'/bootstrap/app.php';
$main->bootstrapWith([LoadEnvironmentVariables::class, LoadConfiguration::class]);
$mainTask = (new WindowsEngineHost(new HostCommands))->serviceName();
PracticeEnvironment::assertMode($directory, $values['SPACETRADERS_LOCAL_ENGINE_ENABLED'] === 'true', $argv[2] ?? '');
foreach ($values as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
chdir($root);
define('LARAVEL_START', microtime(true));
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
try {
    $kernel->bootstrap();
} catch (Throwable $error) {
    fwrite(STDERR, 'Practice bootstrap failed: '.$error->getMessage().PHP_EOL);
    exit(1);
}
$practice = str_replace('\\', '/', realpath($directory));
$inside = function ($path) use ($practice): bool {
    if (! is_string($path) || ! preg_match('~^(?:[A-Za-z]:/|/)~', $path = str_replace('\\', '/', $path)) || in_array('..', explode('/', $path), true)) {
        return false;
    }
    $suffix = '';
    while (! file_exists($path)) {
        $parent = dirname($path);
        if ($parent === $path) {
            return false;
        }
        $suffix = '/'.basename($path).$suffix;
        $path = $parent;
    }
    $resolved = realpath($path);
    if ($resolved === false) {
        return false;
    }
    $resolved = str_replace('\\', '/', $resolved).$suffix;

    return str_starts_with(strtolower($resolved), strtolower($practice).'/');
};
$paths = [
    'database' => config('database.connections.sqlite.database'),
    'fixture' => config('gameplay.fixture_path'),
    'locks' => config('gameplay.lock_path'),
    'request_budget' => config('gameplay.request_budget_path'),
    'deployment' => config('deployment.path'),
    'cache' => config('cache.stores.file.path'),
    'sessions' => config('session.files'),
    'views' => config('view.compiled'),
    'files' => config('filesystems.disks.local.root'),
    'public_files' => config('filesystems.disks.public.root'),
    'log' => config('logging.channels.single.path'),
    'engine_log' => config('logging.channels.engine.path'),
    'storage' => storage_path(),
    'config_cache' => $app->getCachedConfigPath(),
    'routes_cache' => $app->getCachedRoutesPath(),
    'events_cache' => $app->getCachedEventsPath(),
    'services_cache' => $app->getCachedServicesPath(),
    'packages_cache' => $app->getCachedPackagesPath(),
];
foreach ($paths as $name => $path) {
    if (! $inside($path)) {
        fwrite(STDERR, 'Effective practice configuration escaped its directory: '.$name.'.'.PHP_EOL);
        exit(1);
    }
}
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.url') || config('gameplay.fixture_path') !== $directory.'/world.json') {
    fwrite(STDERR, 'Effective practice configuration must use isolated offline SQLite.'.PHP_EOL);
    exit(1);
}
try {
    PracticeEnvironment::assertMode($directory, (bool) config('gameplay.local_engine_enabled'), $argv[2] ?? '');
    if (config('gameplay.local_engine_enabled')) {
        PracticeEnvironment::assertEngineIdentity($mainTask);
    }
    if (($argv[2] ?? '') === '--configure-engine') {
        PracticeEnvironment::configureEngine($directory);
        exit;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Practice engine guard failed: '.$error->getMessage().PHP_EOL);
    exit(1);
}
if (str_starts_with($argv[2] ?? '', '--engine-')) {
    try {
        $status = match ($argv[2]) {
            '--engine-status' => PracticeEnvironment::snapshot(),
            '--engine-end' => PracticeEnvironment::end(in_array('--force', $argv, true)),
            '--engine-verify' => PracticeEnvironment::verify(),
            default => throw new RuntimeException('Unknown practice engine operation.'),
        };
        echo json_encode($status, JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (Throwable $error) {
        fwrite(STDERR, 'Practice engine operation failed: '.$error->getMessage().PHP_EOL);
        exit(1);
    }
    exit;
}
if (($argv[2] ?? '') === '--verify-worker') {
    try {
        require __DIR__.'/verify-practice.php';
    } catch (Throwable $error) {
        fwrite(STDERR, 'Practice verification failed: '.$error->getMessage().PHP_EOL);
        exit(1);
    }
    exit;
}
$argv = array_merge([$root.'/artisan'], array_slice($argv, 2));
$_SERVER['argv'] = $argv;
$_SERVER['argc'] = count($argv);
$input = new ArgvInput($argv);
$status = $kernel->handle($input, new ConsoleOutput);
$kernel->terminate($input, $status);
exit($status);
