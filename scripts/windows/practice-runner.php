<?php

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$directory = str_replace('\\', '/', $argv[1] ?? '');
$owner = dirname($directory).'/.checkout';
if (! is_file($owner) || trim(file_get_contents($owner)) !== $root || ! is_file($directory.'/browser.json') || ! is_file($directory.'/.windows-ready')) {
    throw new RuntimeException('Practice ownership or completed fixture is missing. Run practice.ps1 Init.');
}
$values = Dotenv\Dotenv::parse(file_get_contents($directory.'/.env'));
if (($values['SPACETRADERS_FIXTURE_PATH'] ?? '') !== $directory.'/world.json'
    || ($values['DB_DATABASE'] ?? '') !== $directory.'/database.sqlite'
    || ($values['SPACETRADERS_LOCAL_ENGINE_ENABLED'] ?? '') !== 'false'
    || ($values['LARAVEL_STORAGE_PATH'] ?? '') !== $directory.'/storage'
    || ($values['APP_CONFIG_CACHE'] ?? '') !== $directory.'/no-config.php'
    || file_exists($directory.'/no-config.php')) {
    throw new RuntimeException('Practice environment isolation guard failed.');
}
foreach ($values as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
chdir($root);
define('LARAVEL_START', microtime(true));
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
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
if (config('database.default') !== 'sqlite' || config('gameplay.local_engine_enabled') || config('gameplay.fixture_path') !== $directory.'/world.json') {
    fwrite(STDERR, 'Effective practice configuration must use isolated offline SQLite with no engine.'.PHP_EOL);
    exit(1);
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
$input = new Symfony\Component\Console\Input\ArgvInput($argv);
$status = $kernel->handle($input, new Symfony\Component\Console\Output\ConsoleOutput);
$kernel->terminate($input, $status);
exit($status);
