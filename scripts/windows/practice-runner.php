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
require $root.'/artisan';
