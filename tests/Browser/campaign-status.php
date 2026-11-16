<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\Support\CampaignStatusFixture;

if (! in_array(PHP_SAPI, ['cli', 'cli-server'], true) || getenv('APP_ENV') !== 'testing'
    || ! getenv('CAMPAIGN_STATUS_BROWSER_DIRECTORY')) {
    throw new RuntimeException('Use the isolated campaign-status.mjs browser harness.');
}

$directory = realpath(getenv('CAMPAIGN_STATUS_BROWSER_DIRECTORY'));
if (! $directory || ! str_starts_with($directory, realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR)
    || getenv('DB_DATABASE') !== $directory.DIRECTORY_SEPARATOR.'campaign.sqlite') {
    throw new RuntimeException('Browser evidence requires a temporary SQLite database.');
}

if (PHP_SAPI === 'cli-server' && is_file(__DIR__.'/../../public'.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
config(['gameplay.local_engine_enabled' => false, 'gameplay.enabled' => false,
    'gameplay.request_budget_enabled' => false, 'gameplay.lock_path' => $directory.'/locks',
    'deployment.path' => $directory.'/deployment', 'session.files' => $directory.'/sessions',
    'view.compiled' => $directory.'/views']);
Http::preventStrayRequests();
Http::fake(fn () => throw new RuntimeException('Browser verification denies outbound HTTP.'));

if (PHP_SAPI === 'cli') {
    Artisan::call('migrate:fresh', ['--force' => true]);
    $user = User::factory()->create(['name' => 'Offline campaign operator']);
    if (($argv[1] ?? null) !== '--empty') {
        CampaignStatusFixture::seed($user, getenv('CAMPAIGN_SHIP_MATRIX') === '1');
    }
    echo "Seeded isolated browser database.\n";
    exit;
}

$user = User::firstOrFail();
config(['gameplay.operator_id' => (string) $user->id]);
Auth::onceUsingId($user->id);
$kernel = $app->make(Kernel::class);
$response = $kernel->handle($request = Request::capture());
$response->send();
$kernel->terminate($request, $response);
