<?php

use App\Gameplay\AgentSynchronizer;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FakeSpaceTraders;

// Owns only storage/app/mvp-fixture and an otherwise absent .env. Never touches an existing environment.
require __DIR__.'/../vendor/autoload.php';

$root = dirname(__DIR__);
$directory = $root.'/storage/app/mvp-fixture';
if (file_exists($root.'/.env')) {
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
    'SPACETRADERS_FIXTURE_PATH' => $directory.'/world.json', 'SPACETRADERS_AUTOMATION_ENABLED' => 'true',
];
foreach ($values as $name => $value) {
    $line = $name.'='.$value;
    $environment = preg_match('/^'.preg_quote($name, '/').'=/m', $environment)
        ? preg_replace('/^'.preg_quote($name, '/').'=.*$/m', $line, $environment)
        : $environment."\n".$line;
}
file_put_contents($root.'/.env', $environment."\n");
chmod($root.'/.env', 0600);
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
copy($directory.'/database.sqlite', $backup.'/database.sqlite');
file_put_contents($backup.'/app-key.txt', $key);
chmod($backup.'/database.sqlite', 0600);
chmod($backup.'/app-key.txt', 0600);
copy($backup.'/database.sqlite', $directory.'/restore-check.sqlite');
$restore = new PDO('sqlite:'.$directory.'/restore-check.sqlite');
$encrypted = $restore->query('select token from agents limit 1')->fetchColumn();
if (decrypt($encrypted, false) !== 'synthetic-offline-only') {
    throw new RuntimeException('Backup restore credential round-trip failed.');
}
unlink($directory.'/restore-check.sqlite');
echo "Synthetic fixture ready. All SpaceTraders HTTP is intercepted. Database/key backup restore verified.\n";
