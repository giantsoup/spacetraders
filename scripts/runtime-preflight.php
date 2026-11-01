<?php

// Standalone and read-only: safe before dependencies, environment or application boot.
$root = dirname(__DIR__);
$expected = trim(file_get_contents($root.'/.php-version'));
$composer = json_decode(file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$lock = json_decode(file_get_contents($root.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
if (($composer['require']['php'] ?? null) !== '~'.$expected
    || ($composer['config']['platform']['php'] ?? null) !== $expected
    || ($lock['platform']['php'] ?? null) !== '~'.$expected
    || ($lock['platform-overrides']['php'] ?? null) !== $expected) {
    fwrite(STDERR, "PHP pins differ between .php-version, Composer and lockfile.\n");
    exit(1);
}
if ($expected !== PHP_VERSION) {
    fwrite(STDERR, 'Expected PHP '.$expected.'; got '.PHP_VERSION.". Select an explicit PHP_BIN executable.\n");
    exit(1);
}
$required = ['curl', 'dom', 'fileinfo', 'mbstring', 'openssl', 'pcntl', 'pdo_sqlite', 'posix', 'session', 'sqlite3', 'tokenizer', 'xml'];
$missing = array_values(array_filter($required, fn ($extension) => ! extension_loaded($extension)));
if ($missing !== []) {
    fwrite(STDERR, 'Missing PHP extensions: '.implode(', ', $missing)."\n");
    exit(1);
}
echo 'PASS PHP '.PHP_VERSION.' matches Composer, lockfile and CI pin; worker/SQLite extensions loaded.'.PHP_EOL;
