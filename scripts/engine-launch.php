<?php

$base = dirname(__DIR__);
$storage = getenv('LARAVEL_STORAGE_PATH') ?: $base.'/storage';
$directory = (getenv('SPACETRADERS_LOCK_PATH') ?: $storage.'/framework/game-locks').'/engine';
$servicePath = $argv[1] ?? '(missing)';

function engineLauncherLog(string $directory, string $message): void
{
    @file_put_contents($directory.'/supervisor.log', '['.gmdate('c').'] '.$message.PHP_EOL, FILE_APPEND);
}

try {
    require $base.'/vendor/autoload.php';
    Dotenv\Dotenv::createUnsafeImmutable($base)->safeLoad();
    $storage = getenv('LARAVEL_STORAGE_PATH') ?: $base.'/storage';
    $directory = (getenv('SPACETRADERS_LOCK_PATH') ?: $storage.'/framework/game-locks').'/engine';
    $configured = realpath($directory);
    $path = isset($argv[1]) ? realpath($argv[1]) : false;
    if ($path === false || basename($path) !== 'service.json' || basename(dirname($path)) !== 'engine') {
        throw new RuntimeException('The engine launcher requires this checkout\'s private service.json.');
    }
    $environment = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($environment) || array_is_list($environment)) {
        throw new RuntimeException('Invalid engine service environment.');
    }
    $declared = is_string($environment['SPACETRADERS_LOCK_PATH'] ?? null) ? realpath($environment['SPACETRADERS_LOCK_PATH'].'/engine') : false;
    if (dirname($path) !== $configured && dirname($path) !== $declared) {
        throw new RuntimeException('The engine launcher requires this checkout\'s private service.json.');
    }
    $directory = dirname($path);
    $log = $directory.'/supervisor.log';
    clearstatcache(true, $log);
    if (is_file($log) && filesize($log) > 10 * 1024 * 1024
        && ((file_exists($log.'.1') && ! @unlink($log.'.1')) || ! @rename($log, $log.'.1'))) {
        engineLauncherLog($directory, 'Could not rotate supervisor.log; continuing with the existing log.');
    }
    foreach ($environment as $name => $value) {
        if (! is_string($name) || ! preg_match('/\A[A-Z][A-Z0-9_]*\z/', $name) || ! is_string($value) || str_contains($value, "\0")) {
            throw new RuntimeException('Invalid engine service environment entry.');
        }
    }
    foreach ($environment as $name => $value) {
        putenv($name.'='.$value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
    $_SERVER['argv'] = $argv = [$base.'/artisan', 'spacetraders:engine-supervise'];
    $_SERVER['argc'] = $argc = count($argv);
    require $base.'/artisan';
} catch (Throwable $error) {
    $message = $error::class.': '.$error->getMessage().' [service.json path: '.json_encode($servicePath, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).']';
    engineLauncherLog($directory, $message);
    @file_put_contents('php://stderr', $message.PHP_EOL);
    exit(1);
}
