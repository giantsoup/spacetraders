<?php

namespace App\Gameplay\Bridge;

use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignFleet;
use App\Gameplay\CampaignSafety;
use App\Gameplay\CampaignTrade;
use App\Gameplay\GameException;
use App\Gameplay\HostLock;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\Ship;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BridgeActions
{
    private const STALE = 'This changed since you opened the review. Review it again.';

    private const FLEET_REVIEW = 'Review and resume the whole fleet to resolve this agent-wide stop.';

    public function __construct(private BridgeGuards $guards, private BridgeDrain $drain) {}

    public function reviews(int $userId, string $symbol, ?string $kind = null, ?int $grantId = null): array
    {
        $scope = $this->scope($userId, $symbol, $this->agent($userId, $symbol));
        $reviews = ['fleet_pause' => null, 'fleet_resume' => null, 'primary_resume' => null, 'arms' => []];
        if (in_array($kind, ['arm_resume', 'arm_retire'], true)) {
            if ($grantId === null || ! ($scope['grants'][$grantId] ?? null)?->arm()) {
                return $reviews;
            }
        } else {
            $grantId = null;
        }
        $agent = $scope['agent'];
        $primary = $scope['primary'];
        $grants = $scope['grants'];
        $reviewGrants = $grantId === null ? $grants : $grants->only([$grantId]);
        $ships = $agent ? Ship::where('agent_id', $agent->id)->whereIn('symbol',
            $reviewGrants->map(fn ($grant) => $grant->arm()['ship'] ?? null)->filter()->all())->get()->keyBy('symbol')->all() : [];
        if ($kind === null || $kind === 'fleet_pause') {
            $reviews['fleet_pause'] = $this->row($scope, 'Pause the whole fleet?', 'Pause all campaign authority for '.$symbol.'. Active runs receive stop requests and cargo stays aboard.',
                'Pause the whole fleet', 'Cancel', 'fleet.pause', $grants->keys()->all(),
                $grants->map(fn ($grant) => $this->label($grant, $ships))->values()->all(), $this->pauseBlockers($scope));
            if ($kind === 'fleet_pause') {
                return $reviews;
            }
        }
        if (! $this->isDrained($scope) && ! $reviewGrants->contains(fn ($grant) => $grant->paused_at || BridgeGuards::retireEligible($grant, $this->net($scope, $grant)))) {
            return $reviews;
        }

        $singleId = $kind === 'primary_resume' ? $primary->id : $grantId;
        $checks = $kind === 'arm_retire' ? ['reset' => $this->resetBlockers($scope)] : $this->resumeChecks($scope, $singleId);
        if ($kind === null || $kind === 'fleet_resume') {
            $candidates = $this->fleetCandidates($scope, $checks);
            $staying = $candidates['staying'];
            if ($candidates['ids'] !== [] || $staying !== [] || $this->isDrained($scope)) {
                $facts = [];
                foreach ($candidates['ids'] as $id) {
                    $grant = $grants[$id];
                    $facts[] = $this->pauseFact($grant, $scope, $ships);
                }
                foreach ($staying as $id => $blockers) {
                    $facts[] = $this->label($grants[$id], $ships).' stays stopped. '.$blockers[0]['message'];
                }
                if ($scope['flag'] && $scope['markers'] === []) {
                    $facts[] = 'Release the budget hold. '.count($candidates['ids']).' grants will change.';
                }
                $blockers = $candidates['blockers'];
                if (! $candidates['ids'] && ! $this->isDrained($scope) && ! $blockers) {
                    $blockers[] = ['code' => 'nothing_to_resume', 'message' => 'No campaign grants are eligible to resume.'];
                }
                $reviews['fleet_resume'] = $this->row($scope, 'Resume the fleet?', 'Review the main campaign and any listed arm ships for '.$symbol.'.',
                    'Resume the fleet', 'Keep paused', 'fleet.resume', $candidates['ids'], $facts, $blockers, 'fleet');
            }
            if ($kind === 'fleet_resume') {
                return $reviews;
            }
        }
        if (($kind === null || $kind === 'primary_resume') && ($primary->paused_at || isset($scope['markers'][$primary->id]))) {
            $blockers = $checks['targets'][$primary->id] ?? [];
            if ($this->fleetRequired($scope, $checks)) {
                array_unshift($blockers, $this->fleetBlocker($symbol));
            }
            $facts = [$this->pauseFact($primary, $scope, $ships), ...$this->pauseEvidence($primary, $scope)];
            $reviews['primary_resume'] = $this->row($scope, 'Resume the main campaign?', 'Resume the main campaign of '.$symbol.' using fresh state.',
                'Resume the main campaign', 'Keep paused', 'fleet.resume', [$primary->id], $facts, $blockers, 'primary');
        }
        if ($kind === 'primary_resume') {
            return $reviews;
        }
        foreach ($reviewGrants as $id => $grant) {
            if (! $arm = $grant->arm()) {
                continue;
            }
            $paused = $grant->paused_at || isset($scope['markers'][$id]);
            $eligible = BridgeGuards::retireEligible($grant, $this->net($scope, $grant));
            if (! $paused && ! $eligible) {
                continue;
            }
            $ship = $ships[$arm['ship']] ?? null;
            $name = $ship?->displayName() ?? $arm['ship'];
            $facts = [$this->pauseFact($grant, $scope, $ships), ...$this->pauseEvidence($grant, $scope)];
            if (isset($arm['max_net_loss'])) {
                $facts[] = 'Recorded net '.number_format($this->net($scope, $grant)).' credits. Loss limit '.number_format($arm['max_net_loss']).' credits.';
            }
            $resume = null;
            if ($kind !== 'arm_retire' && $paused && ! BridgeGuards::lossStop($grant, $this->net($scope, $grant)) && ! $grant->expires_at?->lte(now()) && $grant->pause_reason !== 'grant_expired') {
                $blockers = $checks['targets'][$id] ?? [];
                if ($this->fleetRequired($scope, $checks)) {
                    array_unshift($blockers, $this->fleetBlocker($symbol));
                }
                $resume = $this->row($scope, 'Resume '.$name.'?', 'Resume this ship only using fresh state.',
                    'Resume '.$name, 'Keep paused', 'arm.resume', [$id], $facts, $blockers);
            }
            $retire = null;
            if ($kind !== 'arm_resume' && $eligible) {
                $cargo = $ship?->snapshot['cargo']['units'] ?? null;
                $cargoFact = is_numeric($cargo) ? number_format($cargo).' units of cargo stay aboard.' : 'Any cargo stays aboard.';
                $blockers = $checks['reset'] ?: $this->guards->retireBlockers($grant, $scope['episodes'][$id] ?? null, $agent, $this->net($scope, $grant));
                $retire = $this->row($scope, 'Retire '.$name.'?', 'This ship leaves campaign control. Approve it again with campaign:arm on the PC.',
                    'Retire '.$name, 'Cancel', 'arm.retire', [$id], [...$facts, $cargoFact], $blockers);
            }
            $reviews['arms'][$id] = ['resume' => $resume, 'retire' => $retire];
        }

        return $reviews;
    }

    private function pauseEvidence(CampaignGrant $grant, array $scope): array
    {
        if ($grant->pause_reason === 'director_error') {
            $failure = CampaignEvent::where('campaign_grant_id', $grant->id)->where('kind', 'director_error')->latest('id')->first(['data']);
            $attempts = $failure?->data['consecutive'] ?? null;
            $message = $failure?->data['message'] ?? null;
            $facts = $attempts ? ['The director reported '.number_format($attempts).' consecutive failures.'] : [];
            if (is_string($message) && trim($message) !== '') {
                $facts[] = $message;
            }

            return $facts;
        }
        $episode = $scope['episodes'][$grant->id] ?? null;
        if ($grant->pause_reason !== 'consecutive_losing_loads' || ! $episode) {
            return [];
        }

        return CampaignEvent::where('campaign_episode_id', $episode->id)->where('kind', 'load_result')
            ->orderByDesc('data->finished_at')->orderByDesc('id')->limit(2)->pluck('data')
            ->filter(fn ($load) => ($load['net'] ?? 0) < 0)
            ->map(fn ($load) => ($load['good'] ?? 'Load').' lost '.number_format(-$load['net']).' credits.')->values()->all();
    }

    public function execute(int $userId, string $symbol, string $action, ?int $grantId, ?string $resetId, array $expected, ?string $intent, ?string $expectedHoldId): string
    {
        $this->assertAction($action, $grantId, $intent);
        $agent = $this->agent($userId, $symbol);
        $lock = app(HostLock::class);
        $deadline = microtime(true) + 10;

        return $lock->scoped('campaign', function () use ($lock, $deadline, $agent, $userId, $symbol, $action, $grantId, $resetId, $expected, $intent, $expectedHoldId) {
            $this->assertIdentity($agent, $this->agent($userId, $symbol));
            $work = function () use ($agent, $userId, $symbol, $action, $grantId, $resetId, $expected, $intent, $expectedHoldId) {
                $current = $this->agent($userId, $symbol);
                $this->assertIdentity($agent, $current);
                $scope = $this->scope($userId, $symbol, $current);
                $this->assertTarget($scope, $grantId);
                if ($scope['agent']?->reset_id !== $resetId) {
                    throw new GameException(self::STALE);
                }
                if ($action === 'fleet_pause') {
                    return $this->pauseFleet($scope, $expected);
                }

                return $this->changeAuthority($scope, $action, $grantId, $intent, $expected, $expectedHoldId);
            };

            return $agent ? $lock->scoped('agent-'.$agent->id, $work, max(0, $deadline - microtime(true))) : $work();
        }, max(0, $deadline - microtime(true)));
    }

    private function assertAction(string $action, ?int $grantId, ?string $intent): void
    {
        $valid = match ($action) {
            'arm_resume', 'arm_retire' => $grantId !== null && ($intent === null || $intent === 'arm'),
            'fleet_pause' => $grantId === null && ($intent === null || $intent === 'fleet'),
            'fleet_resume' => $grantId === null && in_array($intent, ['primary', 'fleet'], true),
            default => false,
        };
        if (! $valid) {
            throw new GameException('Choose a valid campaign action and scope.');
        }
    }

    private function assertTarget(array $scope, ?int $grantId): void
    {
        abort_if($grantId !== null && ! ($scope['grants'][$grantId] ?? null)?->arm(), 404);
    }

    private function agent(int $userId, string $symbol): ?Agent
    {
        return Agent::where('user_id', $userId)->where('name', $symbol)->unarchived()->latest('id')->first();
    }

    private function assertIdentity(?Agent $initial, ?Agent $current): void
    {
        if ($initial?->id !== $current?->id || $initial?->reset_id !== $current?->reset_id || $initial?->canonical_identity !== $current?->canonical_identity) {
            throw new GameException(self::STALE);
        }
    }

    private function scope(int $userId, string $symbol, ?Agent $agent): array
    {
        $primary = CampaignGrant::primaryFor($userId, $symbol)->firstOrFail();
        $liveArms = CampaignGrant::liveArms($symbol)->where('user_id', $userId)->orderBy('id')->get();
        $armResets = app(CampaignFleet::class)->armResets($liveArms, $agent?->reset_id ?: null);
        $arms = $agent?->reset_id ? $liveArms->filter(fn ($arm) => $armResets[$arm->id] === $agent->reset_id) : $liveArms;
        $unknownDrain = $liveArms->contains(fn ($arm) => $armResets[$arm->id] === null && BridgeDrain::marker($arm, null) !== null);
        $grants = collect([$primary])->merge($arms)->keyBy('id');
        $episodes = $agent?->reset_id ? CampaignEpisode::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->whereIn('campaign_grant_id', $grants->keys())->whereNull('closed_at')->orderByDesc('id')->get()->keyBy('campaign_grant_id') : collect();
        $markers = $grants->map(fn ($grant) => BridgeDrain::marker($grant, $episodes[$grant->id] ?? null))
            ->filter(fn ($marker) => $marker !== null)->all();
        $holds = $this->drain->budgetHoldIds();
        $holdUnknown = $holds === null;
        $holdId = $holds[$symbol] ?? null;
        $flag = $holdId !== null;
        $authorityEvents = CampaignEvent::whereIn('campaign_grant_id', $grants->keys())
            ->whereIn('kind', ['pause', 'drain_pause', 'resume'])
            ->selectRaw('campaign_grant_id, max(id) as event_id')->groupBy('campaign_grant_id')->pluck('event_id', 'campaign_grant_id')->all();
        $armEpisodes = $episodes->filter(fn ($episode, $id) => $id !== $primary->id)->pluck('id');
        $nets = $armEpisodes->isEmpty() ? [] : CampaignEvent::whereIn('campaign_episode_id', $armEpisodes)->where('kind', 'load_result')
            ->selectRaw("campaign_episode_id, coalesce(sum(json_extract(data, '$.net')), 0) as net")
            ->groupBy('campaign_episode_id')->pluck('net', 'campaign_episode_id')->all();

        return compact('symbol', 'agent', 'primary', 'grants', 'episodes', 'markers', 'unknownDrain', 'flag', 'holdId', 'holdUnknown', 'authorityEvents', 'nets');
    }

    private function net(array $scope, CampaignGrant $grant): int
    {
        $episode = $scope['episodes'][$grant->id] ?? null;

        return (int) ($scope['nets'][$episode?->id] ?? 0);
    }

    private function isDrained(array $scope): bool
    {
        return $scope['markers'] !== [] || $scope['unknownDrain'] || $scope['flag'];
    }

    private function resetBlockers(array $scope): array
    {
        $episode = $scope['episodes']->filter(fn ($episode) => $episode->next_reset_at !== null)
            ->sortBy('next_reset_at')->first();

        return BridgeGuards::resetConfirmed($scope['agent'], $episode) ? [] : [$this->guards->resetBlocker($scope['primary'])];
    }

    private function holdBlockers(array $scope): array
    {
        if ($scope['unknownDrain']) {
            return [[
                'code' => 'drain_reset_unknown',
                'message' => 'An arm has a drain marker and its wipe is unconfirmed. Confirm its wipe before resuming the fleet.',
            ]];
        }

        return $scope['holdUnknown'] ? [[
            'code' => 'drain_pressure',
            'message' => 'Request budget hold status is unavailable. Reopen this review once it can be read.',
        ]] : [];
    }

    private function resumeChecks(array $scope, ?int $singleId = null): array
    {
        $agent = $scope['agent'];
        $reset = $this->resetBlockers($scope);
        $shared = $reset ?: ($this->holdBlockers($scope) ?: $this->guards->agentBlockers($agent));
        $grants = $scope['grants']->filter(fn ($grant, $id) => $singleId !== null
            ? $id === $singleId : $grant->paused_at || isset($scope['markers'][$id]));
        $arms = $grants->filter(fn ($grant) => $grant->arm() !== null);
        $inspect = ! $reset && ! $scope['holdUnknown'];
        $evidence = $inspect && $arms->isNotEmpty() ? new BridgeArmEvidence($agent, $arms) : null;
        $targets = [];
        $priceScoped = [];
        foreach ($grants as $id => $grant) {
            $local = $inspect ? $this->guards->resumeBlockers($grant, $scope['episodes'][$id] ?? null, $agent, $this->net($scope, $grant), $evidence) : [];
            $targets[$id] = [...$shared, ...$local];
            $priceScoped[$id] = in_array('uncertain_action', array_column($local, 'code'), true);
        }
        $drain = [];
        if (! $reset && $this->isDrained($scope) && $singleId === null) {
            $drain = $this->guards->pressureBlockers($scope['primary']);
            if ($scope['flag'] && $scope['markers'] === []) {
                array_push($drain, ...$this->guards->standaloneDrainBlockers($agent));
            }
        }

        return compact('reset', 'shared', 'targets', 'priceScoped', 'drain');
    }

    private function fleetCandidates(array $scope, array $checks): array
    {
        $ids = [];
        $staying = [];
        $blockers = $this->isDrained($scope) ? [...$checks['shared'], ...$checks['drain']] : [];
        foreach ($checks['targets'] as $id => $targetBlockers) {
            if ($id === $scope['primary']->id || isset($scope['markers'][$id])) {
                $ids[] = $id;
                foreach ($targetBlockers as $blocker) {
                    if (! in_array($blocker, $blockers, true)) {
                        $blockers[] = $blocker;
                    }
                }
            } elseif ($targetBlockers) {
                $staying[$id] = $targetBlockers;
            } else {
                $ids[] = $id;
            }
        }
        if (! $ids && $staying && $checks['shared']) {
            $blockers = $checks['shared'];
        }

        return compact('ids', 'staying', 'blockers');
    }

    private function fleetRequired(array $scope, array $checks): bool
    {
        $reasons = ['uncertain_action', 'unexplained_change'];
        if ($this->isDrained($scope) || array_intersect($reasons, array_column($checks['shared'], 'code'))) {
            return true;
        }

        return $scope['grants']->contains(function ($grant) use ($scope, $checks) {
            if (! $grant->paused_at || ! in_array($grant->pause_reason, ['uncertain_action', 'unexplained_change'], true)) {
                return false;
            }
            if ($grant->pause_reason === 'unexplained_change' || ! $scope['agent'] || $checks['reset']) {
                return true;
            }

            $priceReview = $checks['priceScoped'][$grant->id] ?? app(CampaignSafety::class)
                ->priceReview($grant, $scope['episodes'][$grant->id] ?? null, $scope['agent']);

            return ! $priceReview;
        });
    }

    private function fleetBlocker(string $symbol): array
    {
        return ['code' => 'fleet_review', 'message' => self::FLEET_REVIEW, 'href' => route('campaign.bridge.brief', ['agent' => $symbol], absolute: false)];
    }

    private function changeAuthority(array $scope, string $action, ?int $grantId, ?string $intent, array $expected, ?string $expectedHoldId): string
    {
        $fleet = $action === 'fleet_resume' && $intent === 'fleet';
        $retire = $action === 'arm_retire';
        $targetId = $grantId ?? $scope['primary']->id;
        $reset = $this->resetBlockers($scope);
        $this->refuse($reset);
        if (! $retire) {
            $this->refuse($this->holdBlockers($scope));
        }
        $this->assertHoldExpected($scope, $expectedHoldId);
        $checks = $fleet ? $this->resumeChecks($scope) : null;
        if ($fleet) {
            $this->refuse($checks['shared']);
        }
        $initial = $fleet ? $this->fleetCandidates($scope, $checks)['ids'] : [$targetId];
        $this->assertExpected($scope, $initial, $expected);
        if ($fleet && ! $initial && ! $this->isDrained($scope)) {
            throw new GameException(self::STALE);
        }
        if (! $retire) {
            // Harvest in campaign → agent → results → SQLite order. The results survive a refused resume.
            foreach ($initial as $id) {
                if ($scope['grants'][$id]->arm() && isset($scope['episodes'][$id])) {
                    app(CampaignTrade::class)->recordResults($scope['episodes'][$id]);
                }
            }
        }

        try {
            [$message, $reconcile] = DB::transaction(function () use ($scope, $fleet, $retire, $targetId, $initial, $expected, $grantId, $expectedHoldId) {
                $agent = $this->agent($scope['primary']->user_id, $scope['symbol']);
                $this->assertIdentity($scope['agent'], $agent);
                $scope = $this->scope($scope['primary']->user_id, $scope['symbol'], $agent);
                $this->assertTarget($scope, $grantId);
                $this->refuse($this->resetBlockers($scope));
                if (! $retire) {
                    $this->refuse($this->holdBlockers($scope));
                }
                $this->assertHoldExpected($scope, $expectedHoldId);
                if ($retire) {
                    $this->assertExpected($scope, [$targetId], $expected);
                    $grant = $scope['grants'][$targetId];
                    $this->refuse($this->guards->retireBlockers($grant, $scope['episodes'][$targetId] ?? null, $agent, $this->net($scope, $grant)));
                    app(CampaignControl::class)->revokeArm($grant, reconcileDrain: false, resetId: $agent->reset_id);

                    return [$this->targetName($grant, $agent).' retired. Active runs received stop requests. Cargo stays aboard.', isset($scope['markers'][$targetId])];
                }
                $checks = $this->resumeChecks($scope, $fleet ? null : $targetId);
                if ($fleet) {
                    return $this->resumeFleet($scope, $checks, $initial, $expected);
                }
                $this->assertExpected($scope, [$targetId], $expected);
                if ($this->fleetRequired($scope, $checks)) {
                    throw new GameException(self::FLEET_REVIEW);
                }
                $grant = $scope['grants'][$targetId];
                $this->refuse($checks['targets'][$targetId]);
                $name = $this->targetName($grant, $agent);
                if (! $grant->paused_at) {
                    return [$name.' is already running.', false];
                }
                $this->resume($grant, $agent->reset_id);

                return [$name.' resumed. Its next decision uses fresh state.', false];
            });
        } catch (GameException $exception) {
            throw $exception;
        } catch (ModelNotFoundException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::warning('Bridge authority write failed.', ['exception_class' => $exception::class]);
            throw new GameException('Campaign state could not be saved. Reopen the review and try again.');
        }

        if (! $reconcile) {
            return $message;
        }
        if ($expectedHoldId !== null) {
            return $this->reconcile($scope['symbol'], $message, $expectedHoldId, $fleet, $fleet && $initial === []);
        }

        return $scope['holdUnknown'] ? $message.' The budget hold could not be reconciled. Reopen the fleet review and try again.' : $message;
    }

    private function resumeFleet(array $scope, array $checks, array $initial, array $expected): array
    {
        $fleet = $this->fleetCandidates($scope, $checks);
        // A newly recorded loss may move a reviewed arm from eligible to staying stopped.
        $stillReviewed = array_unique([...$fleet['ids'], ...array_intersect($initial, array_keys($fleet['staying']))]);
        $this->assertExpected($scope, $stillReviewed, $expected);
        $this->refuse($fleet['blockers']);
        $drained = $this->isDrained($scope);
        $agent = $scope['agent'];
        $resumed = [];
        $staying = [];
        foreach ($fleet['staying'] as $id => $blockers) {
            $staying[] = $this->targetName($scope['grants'][$id], $agent).' stayed stopped. '.$blockers[0]['message'];
        }
        foreach ($fleet['ids'] as $id) {
            $grant = $scope['grants'][$id];
            $name = $this->targetName($grant, $agent);
            try {
                $this->resume($grant, $agent->reset_id);
                $resumed[] = $name;
            } catch (\Throwable $exception) {
                if ($drained || ! $grant->arm()) {
                    throw $exception;
                }
                $staying[] = $name.' could not be resumed. Reopen the review and try again.';
                Log::warning('Bridge arm resume failed.', ['grant' => $id, 'exception_class' => $exception::class]);
            }
        }
        $message = $resumed ? 'Resumed '.implode(', ', $resumed).'. The next decision uses fresh state.' : 'No campaign grants changed.';
        if ($staying !== []) {
            $message .= ' '.implode(' ', $staying);
        }

        return [$message, $drained];
    }

    private function resume(CampaignGrant $grant, string $resetId): void
    {
        $control = app(CampaignControl::class);
        if ($grant->arm()) {
            $control->resumeArm($grant, harvestResults: false, reconcileDrain: false, resetId: $resetId);
        } else {
            $control->resume($grant, reconcileDrain: false, resetId: $resetId);
        }
    }

    private function pauseFleet(array $scope, array $expected): string
    {
        $agent = $scope['agent'];
        $grants = $scope['grants'];
        $this->refuse($this->pauseBlockers($scope));
        $control = app(CampaignControl::class);
        $results = [];
        foreach ($grants as $id => $grant) {
            $name = $this->targetName($grant, $agent);
            if (! isset($expected[$id])) {
                $results[] = $name.' joined the fleet since review.';
            } elseif ($this->normalized($expected[$id]) !== $this->expected($scope, $id)) {
                $results[] = $name.' changed since review.';
            }
            try {
                $results[] = DB::transaction(function () use ($scope, $agent, $grant, $name, $control) {
                    $this->assertIdentity($agent, $this->agent($grant->user_id, $scope['symbol']));
                    $grant->refresh();
                    $episode = BridgeDrain::episode($grant, $agent);
                    if (BridgeDrain::incomplete($grant, $episode)) {
                        return $name.' is draining and keeps its settlement authority.';
                    }
                    if ($grant->paused_at) {
                        return $name.' was already paused.';
                    }
                    $runs = $this->activeRuns([$grant->id])->count();
                    $control->pause($grant, 'operator_pause', $episode, resetId: $agent?->reset_id);

                    return $name.' paused.'.($runs ? ' Stop requested for '.$runs.' '.($runs === 1 ? 'run.' : 'runs.') : '');
                });
            } catch (\Throwable $exception) {
                Log::warning('Bridge fleet pause target failed.', ['grant' => $id, 'exception_class' => $exception::class]);
                $results[] = $name.' could not be paused. Reopen the review and try again.';
            }
        }
        foreach (array_diff(array_keys($expected), $grants->keys()->all()) as $id) {
            $results[] = 'Grant #'.$id.' left the fleet since review.';
        }

        return implode(' ', $results);
    }

    private function pauseBlockers(array $scope): array
    {
        $agent = $scope['agent'];
        if ($agent ? ! $agent->reset_id : $this->activeRuns($scope['grants']->keys()->all())->exists()) {
            return [['code' => 'reset_unconfirmed', 'message' => 'Wait for agent registration to settle, then reopen this review.']];
        }

        return [];
    }

    private function activeRuns(array $grantIds): Builder
    {
        return AutomationRun::whereNotNull('active_agent_id')->whereIn('campaign_episode_id',
            CampaignEpisode::whereIn('campaign_grant_id', $grantIds)->select('id'));
    }

    private function reconcile(string $symbol, string $message, string $reviewedHoldId, bool $reportHold, bool $standalone): string
    {
        try {
            $released = app(CampaignControl::class)->releaseDrainIfUnused($symbol, $reviewedHoldId);
        } catch (\Throwable $exception) {
            Log::warning('Bridge budget hold reconciliation failed.', ['agent' => $symbol, 'exception_class' => $exception::class]);

            return $message.' The budget hold could not be reconciled. Reopen the fleet review and try again.';
        }

        if (! $released && $standalone) {
            throw new GameException(self::STALE);
        }

        return $reportHold ? $message.($released ? ' The budget hold cleared.' : ' The budget hold remains. Reopen the fleet review to check its current state.') : $message;
    }

    private function refuse(array $blockers): void
    {
        if ($blockers !== []) {
            throw new GameException(implode(' ', array_unique(array_column($blockers, 'message'))));
        }
    }

    private function assertHoldExpected(array $scope, ?string $expectedHoldId): void
    {
        if ($scope['holdId'] !== $expectedHoldId) {
            throw new GameException(self::STALE);
        }
    }

    private function assertExpected(array $scope, array $ids, array $expected): void
    {
        $actual = [];
        foreach ($ids as $id) {
            $actual[$id] = $this->expected($scope, $id);
        }
        $expected = array_map($this->normalized(...), $expected);
        ksort($actual);
        ksort($expected);
        if ($expected !== $actual) {
            throw new GameException(self::STALE);
        }
    }

    private function normalized(mixed $state): ?array
    {
        $keys = ['authority_event_id', 'paused_at', 'pause_reason', 'drain_reason', 'drain_stage', 'budget_hold'];
        if (! is_array($state) || array_diff($keys, array_keys($state)) || count($keys) !== count($state)) {
            return null;
        }

        return array_replace(array_fill_keys($keys, null), $state);
    }

    private function expected(array $scope, int $id): array
    {
        $grant = $scope['grants'][$id];
        $drain = $scope['markers'][$id] ?? null;

        return [
            'authority_event_id' => $scope['authorityEvents'][$id] ?? null,
            'paused_at' => $grant->paused_at?->toIso8601String(),
            'pause_reason' => $grant->pause_reason,
            'drain_reason' => $drain,
            'drain_stage' => $drain === null ? null : (BridgeDrain::incomplete($grant, $scope['episodes'][$id] ?? null) ? 'settling' : 'paused'),
            'budget_hold' => $scope['flag'],
        ];
    }

    private function row(array $scope, string $title, string $description, string $confirm, string $cancel, string $route, array $ids, array $facts, array $blockers, ?string $intent = null): array
    {
        $params = ['agent' => $scope['symbol']];
        if (str_starts_with($route, 'arm.')) {
            $params['grant'] = $ids[0];
        }
        $action = route('campaign.bridge.'.$route, $params, absolute: false);
        $reset_id = $scope['agent']?->reset_id;
        $budget_hold_id = $scope['holdId'];
        $facts = array_map(fn (string|array $fact) => is_string($fact) ? ['text' => $fact, 'at' => null] : $fact, $facts);
        $expected = (object) array_combine($ids, array_map(fn ($id) => $this->expected($scope, $id), $ids));

        return compact('title', 'description', 'confirm', 'cancel', 'action', 'intent', 'reset_id', 'budget_hold_id', 'expected', 'facts', 'blockers');
    }

    private function label(CampaignGrant $grant, array $ships): string
    {
        $arm = $grant->arm();
        if (! $arm) {
            return 'Main campaign';
        }
        $ship = $ships[$arm['ship']] ?? null;

        return ($ship ? $ship->displayName().' · ' : '').$arm['ship'].' (arm '.$arm['label'].')';
    }

    private function targetName(CampaignGrant $grant, ?Agent $agent): string
    {
        $arm = $grant->arm();
        if (! $arm) {
            return 'Main campaign';
        }
        $ship = $agent ? Ship::where('agent_id', $agent->id)->where('symbol', $arm['ship'])->first() : null;

        return ($ship?->displayName() ?? $arm['ship']).' ('.$arm['label'].')';
    }

    private function pauseFact(CampaignGrant $grant, array $scope, array $ships): array
    {
        $reason = $grant->pause_reason ?? $scope['markers'][$grant->id] ?? null;
        $reasonLabel = match ($reason) {
            'operator_pause' => 'Paused by the operator',
            'consecutive_losing_loads' => 'Paused after consecutive losing loads',
            'arm_loss_stop' => 'Loss limit exceeded',
            'director_error' => 'Director failed repeatedly',
            'grant_expired' => 'Approval expired',
            'uncertain_action' => 'Uncertain command or price review',
            'unexplained_change' => 'Unexplained credit change',
            null => null,
            default => ucfirst(str_replace(['drain_pause:', '_'], ['', ' '], $reason)),
        };

        $at = $reasonLabel ? $grant->paused_at?->toIso8601String() : null;
        $text = $this->label($grant, $ships).($reasonLabel ? '. '.$reasonLabel : '');

        return ['text' => $at ? $text : $text.'.', 'at' => $at];
    }
}
