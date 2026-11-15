<?php

use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignPlaybook;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\CampaignSample;
use App\Models\LocalEngine;
use App\Models\ShipAction;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = realpath(config('database.connections.sqlite.database'));
$root = realpath(base_path());
if (! app()->environment(['local', 'testing']) || DB::getDriverName() !== 'sqlite' || ! $database
    || ! str_starts_with(strtolower(str_replace('\\', '/', $database)), strtolower(str_replace('\\', '/', $root)).'/')
    || str_contains(strtolower(str_replace('\\', '/', $root)), '/herd/')
    || config('gameplay.local_engine_enabled') || (LocalEngine::find(1) && LocalEngine::find(1)->state !== 'off')
    || AutomationRun::whereNotNull('active_agent_id')->exists() || ShipAction::whereIn('state', ['pending', 'sending'])->exists()) {
    throw new RuntimeException('Smoke check requires this development checkout SQLite database, engine disabled/off, and no active work.');
}
Http::preventStrayRequests();
Http::fake(fn () => throw new RuntimeException('The campaign smoke check denies HTTP.'));
if (! config('app.key')) {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
}
DB::beginTransaction();
try {
    CampaignGrant::whereNotNull('slot')->update(['slot' => null]);
    $user = User::factory()->create(['name' => 'Offline campaign smoke']);
    $revision = app(CampaignPlaybook::class)->import(file_get_contents(base_path('campaigns/weekly-default.yaml')));
    $grant = app(CampaignControl::class)->approve($revision, $user);
    $agent = $user->agents()->create(['name' => 'OFFLINE', 'token' => 'synthetic-not-a-credential', 'reset_id' => 'offline-demo', 'faction' => 'VOID', 'credits' => 115000]);
    $episode = CampaignEpisode::create(['campaign_grant_id' => $grant->id, 'agent_id' => $agent->id, 'reset_id' => 'offline-demo',
        'start_credits' => 100000, 'phase' => 'earn', 'opened_at' => now()->subDay(), 'next_reset_at' => now()->addDay()]);
    foreach ([[now()->subDay(), 100000], [now(), 115000]] as [$at, $credits]) {
        CampaignSample::create(['campaign_episode_id' => $episode->id, 'credits' => $credits, 'phase' => 'earn', 'ships' => [],
            'request_budget' => ['account' => ['requests' => $credits === 100000 ? 0 : 12]], 'observed_at' => $at]);
    }
    LocalEngine::whereKey(1)->update(['heartbeat_at' => now()->subMinutes(6)]);
    $status = Artisan::call('campaign:status');
    if ($status !== 1) {
        throw new RuntimeException('An off engine without a fresh heartbeat must report unhealthy.');
    }
    echo Artisan::output();
    Artisan::call('campaign:report', ['episode' => $episode->id, '--output' => storage_path('app/campaign-smoke')]);
    echo Artisan::output();
    echo "OK: fake episode status and report; no HTTP or engine startup. Database changes rolled back.\n";
} finally {
    DB::rollBack();
}
