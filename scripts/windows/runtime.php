<?php

$root = dirname(__DIR__, 2);
$expected = trim(file_get_contents($root.'/.php-version'));
$composer = json_decode(file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$lock = json_decode(file_get_contents($root.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
if ($expected !== PHP_VERSION
    || $composer['require']['php'] !== '~'.$expected
    || $composer['config']['platform']['php'] !== $expected
    || $lock['platform']['php'] !== '~'.$expected
    || $lock['platform-overrides']['php'] !== $expected) {
    throw new RuntimeException('PHP executable, manifest and lockfile pins must agree.');
}
$required = ['curl', 'dom', 'fileinfo', 'mbstring', 'openssl', 'pdo_sqlite', 'session', 'sqlite3', 'tokenizer', 'xml'];
foreach ($required as $extension) {
    if (! extension_loaded($extension)) {
        throw new RuntimeException('Missing PHP extension: '.$extension);
    }
}
echo 'PASS Windows PHP '.PHP_VERSION.'; Composer pins and portable extensions verified.'.PHP_EOL;
