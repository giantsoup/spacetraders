<?php

$base = dirname(__DIR__);
require $base.'/vendor/autoload.php';

Dotenv\Dotenv::createUnsafeImmutable($base)->safeLoad();
$storage = getenv('LARAVEL_STORAGE_PATH') ?: $base.'/storage';
$directory = realpath((getenv('SPACETRADERS_LOCK_PATH') ?: $storage.'/framework/game-locks').'/engine');
$path = isset($argv[1]) ? realpath($argv[1]) : false;
if ($directory === false || $path === false || dirname($path) !== $directory || basename($path) !== 'service.json') {
    throw new RuntimeException('The engine launcher requires this checkout\'s private service.json.');
}
$environment = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
if (! is_array($environment) || array_is_list($environment)) {
    throw new RuntimeException('Invalid engine service environment.');
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
