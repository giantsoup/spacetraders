<?php

use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\CampaignReport;
use App\Gameplay\CampaignStatus;
use App\Gameplay\CampaignWatchdog;
use App\Gameplay\GameException;
use App\Gameplay\GateRecon;
use App\Gameplay\HostLock;
use App\Gameplay\ReconReport;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\CampaignRevision;
use App\Models\ReconMission;
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

Artisan::command('campaign:approve {revision} {--user=} {--expires=} {--replace-grant=} {--replace-hash=} {--replace-episode=}', function (CampaignControl $control) {
    $value = $this->argument('revision');
    $revision = ctype_digit($value) ? CampaignRevision::findOrFail($value) : CampaignRevision::where('content_hash', $value)->firstOrFail();
    $userId = $this->option('user') ?: config('gameplay.operator_id');
    if (! $userId && User::count() !== 1) {
        throw new GameException('Select the operator with --user=<id>.');
    }
    $user = $userId ? User::findOrFail($userId) : User::firstOrFail();
    $expires = $this->option('expires') ? CarbonImmutable::parse($this->option('expires')) : null;
    $grant = $control->approve($revision, $user, $expires,
        $this->option('replace-grant') !== null ? ['grant' => (int) $this->option('replace-grant'), 'hash' => (string) $this->option('replace-hash'), 'episode' => (int) $this->option('replace-episode')] : null);
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
            'minutes_since_decision' => $decision ? round($decision->created_at->diffInMinutes(now()), 1) : null,
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

Artisan::command('recon:approve {--agent=} {--ship=} {--user=} {--dry-run} {--cash-floor=} {--envelope=} {--jump-exposure=} {--explore-until=} {--return-deadline=} {--cooldown-estimate-s=} {--max-dwell-minutes=} {--market-reads-per-visit=} {--catalogue-pages-per-system=} {--cash-wait-seconds=} {--ask-recheck-seconds=} {--evaluation-retry-seconds=} {--evaluation-retry-limit=} {--module-less-trial=} {--scaled-score-min=} {--scaled-margin-min=} {--confirm-max-age-min=}', function (GateRecon $recon) {
    if (! $this->option('user')) {
        throw new GameException('Select the approving operator with --user=<id>.');
    }
    $user = User::findOrFail($this->option('user'));
    $agent = $user->agents()->where('name', $this->option('agent') ?: 'MACSOUP')->firstOrFail();
    $ship = $this->option('ship') ?: $agent->name.'-2';
    $terms = [];
    foreach (['cash-floor', 'envelope', 'jump-exposure', 'cooldown-estimate-s', 'max-dwell-minutes', 'market-reads-per-visit', 'catalogue-pages-per-system', 'cash-wait-seconds', 'ask-recheck-seconds', 'evaluation-retry-seconds', 'evaluation-retry-limit'] as $flag) {
        if ($this->option($flag) !== null) {
            if (! ctype_digit((string) $this->option($flag))) {
                throw new GameException('Expected a nonnegative integer for --'.$flag);
            }
            $terms[str_replace('-', '_', $flag)] = (int) $this->option($flag);
        }
    }
    foreach (['explore-until', 'return-deadline'] as $flag) {
        if ($this->option($flag) !== null) {
            $terms[str_replace('-', '_', $flag)] = CarbonImmutable::parse($this->option($flag))->toIso8601String();
        }
    }
    foreach (['scaled-score-min', 'scaled-margin-min', 'confirm-max-age-min'] as $flag) {
        if ($this->option($flag) !== null) {
            if (! is_numeric($this->option($flag))) {
                throw new GameException('Expected a number for --'.$flag);
            }
            $terms['qualify'][str_replace('-', '_', $flag)] = (float) $this->option($flag);
        }
    }
    if ($this->option('module-less-trial') !== null) {
        $value = filter_var($this->option('module-less-trial'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw new GameException('Use true or false for --module-less-trial.');
        }
        $terms['module_less_trial'] = $value;
    }
    $mission = $recon->approve($agent, $user, $ship, $terms, (bool) $this->option('dry-run'));
    $order = array_values(array_filter(array_keys($mission->graph), fn ($system) => $system !== $mission->origin_system && $mission->path($mission->origin_system, $system)));
    usort($order, fn ($a, $b) => [count($mission->path($mission->origin_system, $a)), -count($mission->graph[$a]['catalogue']['marketplaces'] ?? []), $a]
        <=> [count($mission->path($mission->origin_system, $b)), -count($mission->graph[$b]['catalogue']['marketplaces'] ?? []), $b]);
    $this->line(json_encode(['mission' => $mission->id, 'dry_run' => (bool) $this->option('dry-run'), 'terms_hash' => $mission->terms_hash,
        'terms' => $mission->terms, 'graph' => $mission->graph, 'candidate_order' => $order], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
})->purpose('Approve immutable bounded probe reconnaissance; dry-run creates no mission');

Artisan::command('recon:start {mission}', function (GateRecon $recon) {
    $run = $recon->start(ReconMission::findOrFail($this->argument('mission')));
    $this->info('Recon run: '.$run->id.' (v5, absolute deadline '.$run->expires_at->toIso8601String().')');
})->purpose('Start or explicitly resume recon using a fresh run and the same mission ledger');

Artisan::command('recon:status {mission?}', function (GateRecon $recon) {
    $missions = ReconMission::when($this->argument('mission'), fn ($q) => $q->whereKey($this->argument('mission')), fn ($q) => $q->whereNull('closed_at'))->get();
    $this->line(json_encode($missions->map(fn ($mission) => $recon->status($mission))->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
})->purpose('Show recon holds, return funds and evidence without game requests');

Artisan::command('recon:cancel {mission}', function (GateRecon $recon) {
    $recon->cancel(ReconMission::findOrFail($this->argument('mission')));
    $this->info('Exploration cancelled. An abroad probe retains its homeward hold and authorization.');
})->purpose('Cancel exploration; retain the loan and return funds while abroad');

Artisan::command('recon:report {mission}', function (ReconReport $report) {
    $mission = ReconMission::findOrFail($this->argument('mission'));
    $directory = storage_path('app/recon');
    File::ensureDirectoryExists($directory, 0700);
    $path = $directory.'/mission-'.$mission->id.'.md';
    File::put($path, $report->build($mission));
    chmod($path, 0600);
    $this->line('Report: '.$path);
    $this->line('Outcome: '.($mission->outcome ?: $mission->state).'; spent '.$mission->ledger()['spent'].' CR; return exposure '.$mission->exposure().' CR.');
})->purpose('Write the provisional reconnaissance evidence and accounting report');

Artisan::command('recon:authorize-return {mission} {--jump-cap=} {--total-exposure=} {--until=} {--user=} {--dry-run}', function (GateRecon $recon) {
    foreach (['jump-cap', 'total-exposure', 'until', 'user'] as $required) {
        if ($this->option($required) === null) {
            throw new GameException('Explicit return approval requires --'.$required);
        }
    }
    foreach (['jump-cap', 'total-exposure'] as $integer) {
        if (! ctype_digit((string) $this->option($integer))) {
            throw new GameException('Expected a positive integer for --'.$integer);
        }
    }
    $record = $recon->authorizeReturn(ReconMission::findOrFail($this->argument('mission')), User::findOrFail($this->option('user')),
        (int) $this->option('jump-cap'), (int) $this->option('total-exposure'), CarbonImmutable::parse($this->option('until')), (bool) $this->option('dry-run'));
    $this->line(json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
})->purpose('Explicitly approve a bounded homeward-only rescue on the same mission');

Artisan::command('recon:authorize-outbound {mission} {--jump-cap=} {--expect-basis=} {--user=} {--target=} {--dry-run}', function (GateRecon $recon) {
    foreach (['jump-cap', 'expect-basis', 'user'] as $required) {
        if ($this->option($required) === null) {
            throw new GameException('Outbound approval requires --'.$required);
        }
    }
    if (! ctype_digit((string) $this->option('jump-cap')) || (int) $this->option('jump-cap') > 20000) {
        throw new GameException('Outbound cap must be an integer at most 20000 CR.');
    }
    $result = $recon->authorizeOutbound(ReconMission::findOrFail($this->argument('mission')), User::findOrFail($this->option('user')),
        $this->option('target'), (int) $this->option('jump-cap'), $this->option('expect-basis'), (bool) $this->option('dry-run'));
    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
})->purpose('Seal one exact outbound recon edge, preserving original return caps and mission limits');
