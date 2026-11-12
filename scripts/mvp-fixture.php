<?php

use App\Gameplay\AgentSynchronizer;
use App\Models\User;
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FakeSpaceTraders;

// Owns only storage/app/mvp-fixture and an otherwise absent .env. Never touches an existing environment.
require __DIR__.'/../vendor/autoload.php';

$root = dirname(__DIR__);
$isolated = in_array('--isolated', $argv, true);
$directory = $isolated ? sys_get_temp_dir().'/spacetraders-fixture-'.bin2hex(random_bytes(6)) : $root.'/storage/app/mvp-fixture';
$directoryOption = array_search('--directory', $argv, true);
if ($directoryOption !== false) {
    $requested = $argv[$directoryOption + 1] ?? '';
    if (! $isolated || ! preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $requested)) {
        throw new RuntimeException('--directory requires --isolated and an absolute path.');
    }
    $directory = $requested;
}
if (PHP_OS_FAMILY === 'Windows') {
    $directory = str_replace('\\', '/', $directory);
}
$environmentFile = $isolated ? $directory.'/.env' : $root.'/.env';
if (! $isolated && file_exists($environmentFile)) {
    fwrite(STDERR, "An environment already exists. Refusing to replace it. Use the documented isolated fixture.\n");
    exit(1);
}
if (is_dir($directory)) {
    fwrite(STDERR, "Fixture directory already exists. Preserve it or choose a new isolated checkout.\n");
    exit(1);
}
mkdir($directory, 0700, true);
require_once $root.'/tests/Support/FakeSpaceTraders.php';
file_put_contents($directory.'/world.json', json_encode(FakeSpaceTraders::world()));
chmod($directory.'/world.json', 0600);
touch($directory.'/database.sqlite');
chmod($directory.'/database.sqlite', 0600);
$key = 'base64:'.base64_encode(random_bytes(32));
$environment = file_get_contents($root.'/.env.example');
$values = [
    'APP_ENV' => 'local', 'APP_KEY' => $key, 'APP_DEBUG' => 'false', 'APP_NAME' => 'SpaceTradersMVPFixture',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $directory.'/database.sqlite', 'QUEUE_CONNECTION' => 'database',
    'MAIL_MAILER' => 'array', 'SESSION_COOKIE' => 'spacetraders_mvp_fixture',
    'SPACETRADERS_LOCK_PATH' => $directory.'/locks',
    'SPACETRADERS_FIXTURE_PATH' => $directory.'/world.json', 'SPACETRADERS_AUTOMATION_ENABLED' => 'true',
];
if ($isolated && PHP_OS_FAMILY === 'Windows') {
    foreach (['app', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $path) {
        mkdir($directory.'/storage/'.$path, 0700, true);
    }
    $values = array_merge($values, [
        'APP_URL' => 'http://127.0.0.1:8010', 'APP_BASE_PATH' => str_replace('\\', '/', $root),
        'DATABASE_URL' => '', 'DB_URL' => '', 'SPACETRADERS_LOCAL_ENGINE_ENABLED' => 'false',
        'SPACETRADERS_OPERATOR_ID' => '', 'SPACETRADERS_PHP_BINARY' => str_replace('\\', '/', PHP_BINARY),
        'LARAVEL_STORAGE_PATH' => $directory.'/storage', 'APP_CONFIG_CACHE' => $directory.'/no-config.php',
        'APP_ROUTES_CACHE' => $directory.'/no-routes.php', 'APP_EVENTS_CACHE' => $directory.'/no-events.php',
        'APP_SERVICES_CACHE' => $directory.'/services.php', 'APP_PACKAGES_CACHE' => $directory.'/packages.php',
        'SPACETRADERS_REQUEST_BUDGET_PATH' => $directory.'/request-budget',
    ]);
}
foreach ($values as $name => $value) {
    if (PHP_OS_FAMILY === 'Windows') {
        $value = '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }
    $line = $name.'='.$value;
    $environment = preg_match('/^'.preg_quote($name, '/').'=/m', $environment)
        ? preg_replace_callback('/^'.preg_quote($name, '/').'=.*$/m', fn () => $line, $environment)
        : $environment."\n".$line;
}
file_put_contents($environmentFile, $environment."\n");
chmod($environmentFile, 0600);
if ($isolated) {
    foreach (Dotenv::parse($environment) as $name => $value) {
        putenv($name.'='.$value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
    putenv('APP_CONFIG_CACHE='.$directory.'/no-config.php');
    $_ENV['APP_CONFIG_CACHE'] = $_SERVER['APP_CONFIG_CACHE'] = $directory.'/no-config.php';
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Artisan::call('migrate', ['--force' => true]);
$password = bin2hex(random_bytes(24));
$user = User::factory()->create(['name' => 'Synthetic Pilot', 'email' => 'mvp-pilot@example.test', 'password' => Hash::make($password)]);
$agent = $user->agents()->create([
    'name' => 'LOCAL', 'token' => 'synthetic-offline-only', 'account_id' => 'synthetic-account', 'faction' => 'COSMIC',
    'reset_id' => '2026-10-25', 'canonical_identity' => AgentSynchronizer::identity('2026-10-25', 'LOCAL'),
]);
app(AgentSynchronizer::class)->sync($agent);
file_put_contents($directory.'/browser.json', json_encode(['email' => $user->email, 'password' => $password, 'agent_id' => $agent->id]));
chmod($directory.'/browser.json', 0600);
// Verify a private database/key backup restores and decrypts on a disposable copy.
$backup = $directory.'/backup';
mkdir($backup, 0700);
$connection = DB::connection()->getPdo();
$connection->exec('VACUUM INTO '.$connection->quote($backup.'/database.sqlite'));
file_put_contents($backup.'/app-key.txt', $key);
chmod($backup.'/database.sqlite', 0600);
chmod($backup.'/app-key.txt', 0600);
copy($backup.'/database.sqlite', $directory.'/restore-check.sqlite');
$restore = new PDO('sqlite:'.$directory.'/restore-check.sqlite');
$encrypted = $restore->query('select token from agents limit 1')->fetchColumn();
if (decrypt($encrypted, false) !== 'synthetic-offline-only') {
    throw new RuntimeException('Backup restore credential round-trip failed.');
}
if (PHP_OS_FAMILY === 'Windows') {
    $restore = null;
}
unlink($directory.'/restore-check.sqlite');
if ($isolated && PHP_OS_FAMILY === 'Windows') {
    file_put_contents($directory.'/.windows-ready', 'fixture and backup verified');
}
echo "Synthetic fixture ready. All SpaceTraders HTTP is intercepted. Database/key backup restore verified.\n";

echo 'Owned fixture directory: '.$directory."\n";
