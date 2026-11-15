<?php

use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignHealth;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\CampaignReport;
use App\Gameplay\CampaignWatchdog;
use App\Gameplay\GameException;
use App\Gameplay\HostLock;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\CampaignRevision;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

Artisan::command('campaign:import {file}', function (CampaignPlaybook $playbooks) {
    if (! is_file($this->argument('file')) || ! is_readable($this->argument('file'))) {
        throw new GameException('Playbook file is not readable.');
    }
    $revision = $playbooks->import(file_get_contents($this->argument('file')));
    $this->info('Revision: '.$revision->id.' '.$revision->content_hash);
})->purpose('Validate and store an immutable campaign revision');

Artisan::command('campaign:approve {revision} {--user=} {--expires=}', function (CampaignControl $control) {
    $value = $this->argument('revision');
    $revision = ctype_digit($value) ? CampaignRevision::findOrFail($value) : CampaignRevision::where('content_hash', $value)->firstOrFail();
    $userId = $this->option('user') ?: config('gameplay.operator_id');
    if (! $userId && User::count() !== 1) {
        throw new GameException('Select the operator with --user=<id>.');
    }
    $user = $userId ? User::findOrFail($userId) : User::firstOrFail();
    $expires = $this->option('expires') ? CarbonImmutable::parse($this->option('expires')) : null;
    $grant = $control->approve($revision, $user, $expires);
    $this->info('Grant: '.$grant->id.($grant->slot === 2 ? ' (pending safe point)' : ' (active)'));
})->purpose('Approve a revision for unattended execution');

Artisan::command('campaign:pause', function (CampaignControl $control, HostLock $lock) {
    $lock->retry('campaign', fn () => $control->pause(CampaignGrant::where('slot', 1)->firstOrFail(), 'operator_pause'));
    $this->info('Campaign paused.');
})->purpose('Pause campaign authority and stop its runs');

Artisan::command('campaign:resume', function (CampaignControl $control) {
    $control->resume();
    $this->info('Campaign resumed; the next decision uses fresh state.');
})->purpose('Resume a paused campaign after operator review');

Artisan::command('campaign:revoke', function (CampaignControl $control) {
    $control->revoke();
    $this->info('Campaign grants revoked.');
})->purpose('Revoke active and pending campaign authority');

Artisan::command('campaign:status', function () {
    $grant = CampaignGrant::where('slot', 1)->first() ?? CampaignGrant::latest('id')->first();
    if (! $grant) {
        $this->line('No campaign grant.');

        return;
    }
    $episode = CampaignEpisode::where('campaign_grant_id', $grant->id)->latest('id')->first();
    $event = CampaignEvent::where('campaign_grant_id', $grant->id)->where('kind', 'decision')->latest('id')->first();
    $problems = app(CampaignHealth::class)->problems();
    $stale = isset($problems['engine_heartbeat_stale']);
    $this->line(json_encode(['engine_heartbeat_stale' => $stale, 'grant' => $grant->id, 'revision_hash' => $grant->revision_hash, 'operator_user' => $grant->user_id,
        'active' => $grant->available(), 'expires_at' => $grant->expires_at?->toIso8601String(), 'episode' => $episode?->id, 'phase' => $episode?->phase,
        'pause_reason' => $grant->pause_reason, 'wait_reason' => $grant->wait_reason, 'last_decision' => $event ? ['at' => $event->created_at->toIso8601String(), 'choice' => $event->data['choice'] ?? null, 'run_id' => $event->data['run_id'] ?? null, 'rejections' => $event->data['rejections'] ?? []] : null,
        'pending_grant' => CampaignGrant::where('slot', 2)->value('id')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    return $problems ? 1 : 0;
})->purpose('Show campaign authority and latest decision without contacting SpaceTraders');

Artisan::command('campaign:watchdog', function (CampaignWatchdog $watchdog) {
    $problems = $watchdog->check();
    $this->line($problems ? 'Campaign watchdog: unhealthy (alerts recorded).' : 'Campaign watchdog: healthy.');

    return $problems ? 1 : 0;
})->purpose('Check campaign health independently and alert without contacting SpaceTraders');

Artisan::command('campaign:report {episode?} {--output=}', function (CampaignReport $reports) {
    $episode = $this->argument('episode') ? CampaignEpisode::findOrFail($this->argument('episode')) : CampaignEpisode::latest('id')->firstOrFail();
    $report = $reports->build($episode);
    $directory = $this->option('output') ?: storage_path('app/campaign-reports');
    File::ensureDirectoryExists($directory);
    $base = rtrim($directory, '/\\').'/episode-'.$episode->id;
    File::put($base.'.md', $report['markdown']);
    File::put($base.'.json', json_encode($report['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    $this->line('Markdown: '.$base.'.md');
    $this->line('JSON: '.$base.'.json');
})->purpose('Write the campaign episode report as Markdown and JSON');
