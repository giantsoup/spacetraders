<?php

use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\CampaignReport;
use App\Gameplay\CampaignStatus;
use App\Gameplay\CampaignWatchdog;
use App\Gameplay\GameException;
use App\Gameplay\HostLock;
use App\Models\AutomationRun;
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

Artisan::command('campaign:status', function (CampaignStatus $status) {
    ['grant' => $grant, 'episode' => $episode, 'decision' => $event, 'problems' => $problems, 'pending_grant' => $pending] = $status->authority();
    if (! $grant) {
        $this->line('No campaign grant.');

        return;
    }
    $stale = isset($problems['engine_heartbeat_stale']);
    $this->line(json_encode(['engine_heartbeat_stale' => $stale, 'grant' => $grant->id, 'revision_hash' => $grant->revision_hash, 'operator_user' => $grant->user_id,
        'active' => $grant->available(), 'expires_at' => $grant->expires_at?->toIso8601String(), 'episode' => $episode?->id, 'phase' => $episode?->phase,
        'pause_reason' => $grant->pause_reason, 'wait_reason' => $grant->wait_reason, 'last_decision' => $event ? ['at' => $event->created_at->toIso8601String(), 'choice' => $event->data['choice'] ?? null, 'run_id' => $event->data['run_id'] ?? null, 'rejections' => $event->data['rejections'] ?? []] : null,
        'pending_grant' => $pending], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

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

/** Live arm grants matching a label, or all of them with --all. */
$arms = function ($command) {
    $label = $command->argument('label');
    if (! $label && ! $command->option('all')) {
        throw new GameException('Name an arm label or pass --all.');
    }
    $grants = CampaignGrant::liveArms()->orderBy('id')->get()->filter(fn ($grant) => $command->option('all') || $grant->arm()['label'] === $label)->values();
    if ($grants->isEmpty()) {
        throw new GameException('No live arm matches.');
    }

    return $grants;
};

Artisan::command('campaign:arm {revision} {ship} {label} {--follow=} {--hours=12} {--max-loss=50000} {--max-load=} {--user=}', function (CampaignControl $control) {
    $value = (string) $this->argument('revision');
    $revision = ctype_digit($value) ? CampaignRevision::findOrFail($value) : CampaignRevision::where('content_hash', $value)->firstOrFail();
    $userId = $this->option('user') ?: config('gameplay.operator_id');
    if (! $userId && User::count() !== 1) {
        throw new GameException('Select the operator with --user=<id>.');
    }
    $user = $userId ? User::findOrFail($userId) : User::firstOrFail();
    $grant = $control->approveArm($revision, $user, ['label' => $this->argument('label'), 'ship' => $this->argument('ship'), 'follow' => $this->option('follow'),
        'max_net_loss' => (int) $this->option('max-loss'), 'max_load_spend' => $this->option('max-load') !== null ? (int) $this->option('max-load') : null],
        CarbonImmutable::now()->addMinutes((int) round(60 * (float) $this->option('hours'))));
    $this->info('Arm grant: '.$grant->id.' '.$grant->arm()['label'].' on '.$grant->arm()['ship'].' until '.$grant->expires_at->toIso8601String());
})->purpose('Approve one arm ship under its own campaign grant and episode');

Artisan::command('campaign:arms', function () {
    $rows = CampaignGrant::liveArms()->orderBy('id')->get()->map(function ($grant) {
        $episode = CampaignEpisode::where('campaign_grant_id', $grant->id)->whereNull('closed_at')->latest('id')->first();
        $decision = CampaignEvent::where('campaign_grant_id', $grant->id)->where('kind', 'decision')->latest('id')->first();
        $run = $episode ? AutomationRun::where('campaign_episode_id', $episode->id)->whereNotNull('active_agent_id')->first() : null;

        return ['label' => $grant->arm()['label'], 'ship' => $grant->arm()['ship'], 'follow' => $grant->arm()['follow'], 'grant' => $grant->id,
            'active' => $grant->available(), 'expires_at' => $grant->expires_at?->toIso8601String(), 'pause_reason' => $grant->pause_reason, 'wait_reason' => $grant->wait_reason,
            'episode' => $episode?->id, 'phase' => $episode?->phase,
            'last_decision' => $decision ? ['at' => $decision->created_at->toIso8601String(), 'choice' => $decision->data['choice'] ?? null, 'run_id' => $decision->data['run_id'] ?? null, 'rejections' => $decision->data['rejections'] ?? []] : null,
            'active_run' => $run ? ['id' => $run->id, 'strategy' => $run->strategy, 'state' => $run->state->value, 'symbol' => $run->plan['symbol'] ?? null, 'market' => $run->plan['market'] ?? null] : null];
    });
    $this->line(json_encode($rows->values()->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
})->purpose('List live arm grants without contacting SpaceTraders');

Artisan::command('campaign:arm-pause {label?} {--all}', function (CampaignControl $control, HostLock $lock) use ($arms) {
    foreach ($arms($this) as $grant) {
        $lock->retry('campaign', fn () => $control->pause($grant, 'operator_pause'));
        $this->info('Arm '.$grant->arm()['label'].' paused.');
    }
})->purpose('Pause arm authority and stop its runs');

Artisan::command('campaign:arm-resume {label?} {--all}', function (CampaignControl $control) use ($arms) {
    foreach ($arms($this) as $grant) {
        try {
            $control->resumeArm($grant);
            $this->info('Arm '.$grant->arm()['label'].' resumed.');
        } catch (GameException $exception) {
            $this->error('Arm '.$grant->arm()['label'].': '.$exception->getMessage());
        }
    }
})->purpose('Resume paused arms after operator review');

Artisan::command('campaign:arm-revoke {label?} {--all}', function (CampaignControl $control) use ($arms) {
    foreach ($arms($this) as $grant) {
        $control->revokeArm($grant);
        $this->info('Arm '.$grant->arm()['label'].' revoked.');
    }
})->purpose('Revoke arm authority, stop its runs and close its episode');
