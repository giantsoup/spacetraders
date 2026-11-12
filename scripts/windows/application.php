<?php

use App\Models\LocalEngine;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = config('database.default');
$database = config('database.connections.'.$connection.'.database');
if (($argv[1] ?? '') === 'info') {
    $state = 'unknown';
    if ($connection === 'sqlite' && is_file($database) && Schema::hasTable('local_engines')) {
        $state = LocalEngine::find(1)?->state ?? 'off';
    }
    echo json_encode(['connection' => $connection, 'database' => $database, 'engine_state' => $state, 'config_cached' => $app->configurationIsCached()], JSON_THROW_ON_ERROR);
    exit;
}
if (($argv[1] ?? '') !== 'backup' || $connection !== 'sqlite' || ! is_file($database)) {
    throw new RuntimeException('Backup requires an existing effective SQLite database.');
}
$destination = $argv[2] ?? '';
$environment = Dotenv\Dotenv::parse(file_get_contents(dirname(__DIR__, 2).'/.env'));
if (empty($environment['APP_KEY']) || ($environment['APP_KEY'] ?? '') !== config('app.key')) {
    throw new RuntimeException('Effective APP_KEY differs from the saved environment; refusing a mismatched backup.');
}
if (! is_dir($destination) || file_exists($destination.'/database.sqlite')) {
    throw new RuntimeException('Backup destination must be a new private folder.');
}
$pdo = new PDO('sqlite:'.$database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA busy_timeout=5000');
$pdo->exec('VACUUM INTO '.$pdo->quote($destination.'/database.sqlite'));
if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
    throw new RuntimeException('Source database integrity check failed.');
}
$copy = new PDO('sqlite:'.$destination.'/database.sqlite');
if ($copy->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
    throw new RuntimeException('Backup integrity check failed.');
}
file_put_contents($destination.'/.snapshot-verified', 'SQLite snapshot integrity verified');
echo "Consistent SQLite snapshot and integrity checks completed.\n";
