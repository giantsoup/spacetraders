<?php

$base = dirname(__DIR__);
require $base.'/vendor/autoload.php';

Dotenv\Dotenv::createUnsafeImmutable($base)->safeLoad();
$storage = getenv('LARAVEL_STORAGE_PATH') ?: $base.'/storage';
$directory = realpath((getenv('SPACETRADERS_LOCK_PATH') ?: $storage.'/framework/game-locks').'/engine');
$path = isset($argv[1]) ? realpath($argv[1]) : false;
if ($path === false || basename($path) !== 'service.json' || basename(dirname($path)) !== 'engine') {
    throw new RuntimeException('The engine launcher requires this checkout\'s private service.json.');
}
$environment = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
if (! is_array($environment) || array_is_list($environment)) {
    throw new RuntimeException('Invalid engine service environment.');
}
// The installer may run with an isolated lock path that is not in this checkout's .env
// (owned fixtures). Accept the file only when it lives in the engine directory of the
// lock path it declares itself, or in the checkout's configured engine directory.
$declared = is_string($environment['SPACETRADERS_LOCK_PATH'] ?? null) ? realpath($environment['SPACETRADERS_LOCK_PATH'].'/engine') : false;
if (dirname($path) !== $directory && dirname($path) !== $declared) {
    throw new RuntimeException('The engine launcher requires this checkout\'s private service.json.');
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
