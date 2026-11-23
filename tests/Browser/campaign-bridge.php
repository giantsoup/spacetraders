<?php

use App\Gameplay\RequestBudget;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Support\CampaignBridgeFixture;
use Tests\Support\FakeSpaceTraders;

if (! in_array(PHP_SAPI, ['cli', 'cli-server'], true) || ! in_array(getenv('APP_ENV'), ['local', 'testing'], true)) {
    throw new RuntimeException('Use the isolated campaign-bridge.mjs browser harness.');
}
$directory = realpath(getenv('CAMPAIGN_BRIDGE_FIXTURE_DIRECTORY') ?: '.');
if (! $directory || ! str_starts_with($directory, realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'campaign-bridge-')
    || getenv('DB_DATABASE') !== $directory.DIRECTORY_SEPARATOR.'campaign.sqlite') {
    throw new RuntimeException('Bridge evidence requires a temporary SQLite database.');
}

if (PHP_SAPI === 'cli-server' && is_file(__DIR__.'/../../public'.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require __DIR__.'/../../vendor/autoload.php';
if (PHP_SAPI === 'cli') {
    $world = FakeSpaceTraders::world();
    $world['reset'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-2 days')->format('Y-m-d');
    $world['next_reset'] = (new DateTimeImmutable('+126 hours', new DateTimeZone('UTC')))->format(DATE_ATOM);
    file_put_contents($directory.'/world.json', json_encode($world, JSON_THROW_ON_ERROR));
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
Http::preventStrayRequests();
Http::fake(fn () => throw new RuntimeException('Bridge browser verification denies outbound HTTP.'));

if (PHP_SAPI === 'cli') {
    Artisan::call('migrate', ['--force' => true]);
    $password = bin2hex(random_bytes(24));
    $operator = User::factory()->create([
        'name' => 'Synthetic Bridge Operator', 'email' => 'bridge@example.test', 'password' => Hash::make($password),
    ]);
    $fixture = CampaignBridgeFixture::seed($operator, $world['reset']);
    app(RequestBudget::class)->drainAgent('SOUPFLEET', true, $world['reset']);
    file_put_contents($directory.'/credentials.json', json_encode([
        'email' => $operator->email, 'password' => $password, 'operator' => $operator->id,
        'arm' => $fixture['arm']['grant']->id, 'reset' => $fixture['agent']->reset_id,
    ], JSON_THROW_ON_ERROR));
    chmod($directory.'/credentials.json', 0600);

    exit;
}

$kernel = $app->make(Kernel::class);
$response = $kernel->handle($request = Request::capture());
$response->send();
$kernel->terminate($request, $response);
