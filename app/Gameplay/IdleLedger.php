<?php

declare(strict_types=1);

namespace App\Gameplay;

use App\Enums\ActionState;
use App\Enums\RunState;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\ShipAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class IdleLedger
{
    public function classifyReason(?string $reason): string
    {
        return match ($reason) {
            'awaiting_agent_lock', 'awaiting_run_start', 'awaiting_fresh_state', 'preview_invalidated', 'handoff', 'freshness_hold' => 'friction',
            'no_positive_spread', 'no_corridor_slot' => 'structural',
            'request_deferred', 'request_governor', 'g7_hold' => 'capacity',
            default => 'unattributed',
        };
    }

    /**
     * Key each arm by label. Use Unix seconds for from/to and [start, stop] pairs for busy,
     * excluded, and pipelined_gaps. Reasons use [start, stop, reason]. The most recently
     * opened reason owns overlapping idle time. Busy and excluded time take precedence.
     */
    public function evaluate(array $armIntervals, int $pipelineGraceSeconds = 60, float $maxIdleShare = 0.25, float $maxStructuralShare = 0.10, float $maxCapacityShare = 0.10): array
    {
        $byArm = [];
        $eligible = $structural = $capacity = 0;
        $maxIdle = $maxFriction = 0.0;
        foreach ($armIntervals as $label => $arm) {
            if ($arm['to'] <= $arm['from']) {
                continue;
            }
            $seconds = $this->armSeconds($arm, $pipelineGraceSeconds);
            $armEligible = array_sum($seconds) - $seconds['excluded'];
            if ($armEligible <= 0) {
                continue;
            }
            $idle = $armEligible - $seconds['busy'];
            $idleShare = $idle / $armEligible;
            $frictionShare = $seconds['friction'] / $armEligible;
            $byArm[$label] = [
                'eligible_seconds' => $armEligible, 'busy_seconds' => $seconds['busy'], 'idle_seconds' => $idle,
                'idle_share' => $idleShare, 'friction_share' => $frictionShare,
                'structural_share' => $seconds['structural'] / $armEligible, 'capacity_share' => $seconds['capacity'] / $armEligible,
                'unattributed_share' => $seconds['unattributed'] / $armEligible,
            ];
            $eligible += $armEligible;
            $structural += $seconds['structural'];
            $capacity += $seconds['capacity'];
            $maxIdle = max($maxIdle, $idleShare);
            $maxFriction = max($maxFriction, $frictionShare);
        }
        $structuralShare = $eligible > 0 ? $structural / $eligible : 0.0;
        $capacityShare = $eligible > 0 ? $capacity / $eligible : 0.0;

        return [
            'by_arm' => $byArm, 'eligible_seconds' => $eligible, 'max_idle' => $maxIdle, 'max_friction' => $maxFriction,
            'structural_share' => $structuralShare, 'capacity_share' => $capacityShare,
            'pass' => $maxIdle < $maxIdleShare && $structuralShare < $maxStructuralShare && $capacityShare < $maxCapacityShare,
        ];
    }

    public function evaluateAgent(Agent $agent, Collection $arms, int $windowSeconds = 3600, int $pipelineGraceSeconds = 60): array
    {
        $now = CarbonImmutable::now();
        $to = $now->getTimestamp();
        $from = $to - $windowSeconds;
        $intervals = [];
        foreach ($arms as $grant) {
            $arm = $grant->arm();
            $episode = CampaignEpisode::where('campaign_grant_id', $grant->id)->where('agent_id', $agent->id)
                ->where('reset_id', $agent->reset_id)->whereNull('closed_at')->first();
            if ($arm === null || $episode === null || $episode->opened_at->getTimestamp() > $from) {
                continue;
            }

            $runs = AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                ->where('campaign_episode_id', $episode->id)->where('ship_symbol', $arm['ship'])
                ->whereNotNull('started_at')->where('started_at', '<=', $now)->orderBy('started_at')->orderBy('id')->get();
            $busy = $excluded = $pipelinedGaps = $reasonChanges = [];
            $previousCompletion = null;
            foreach ($runs as $run) {
                $start = $run->started_at->getTimestamp();
                $stop = $run->active_agent_id ? $to : $this->finishedAt($run);
                $reasonChanges[$start] = null;
                if (! empty($run->plan['sell_only'])) {
                    $excluded[] = [$start, $stop];
                } else {
                    $busy[] = [$start, $stop];
                    if ($previousCompletion !== null && $start > $previousCompletion && ($run->plan['pipeline_preselected'] ?? false) === true) {
                        $pipelinedGaps[] = [$previousCompletion, $start];
                    }
                }
                $previousCompletion = $run->state === RunState::Completed && empty($run->plan['sell_only']) ? $stop : null;
            }

            $actions = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                ->where('ship_symbol', $arm['ship'])->where('created_at', '<=', $now)->where('state', '!=', 'preview')
                ->where(fn ($query) => $query->whereIn('state', ['pending', 'sending'])
                    ->orWhere('settled_at', '>=', $now->subSeconds($windowSeconds))
                    ->orWhere(fn ($query) => $query->whereNull('settled_at')->where('updated_at', '>=', $now->subSeconds($windowSeconds))))->cursor();
            foreach ($actions as $action) {
                $open = in_array($action->state, [ActionState::Pending, ActionState::Sending], true);
                $busy[] = [$action->created_at->getTimestamp(), $open ? $to : ($action->settled_at ?? $action->updated_at)->getTimestamp()];
            }

            $events = CampaignEvent::where('campaign_grant_id', $grant->id)
                ->where(fn ($query) => $query->where('campaign_episode_id', $episode->id)->orWhereNull('campaign_episode_id'))
                ->whereBetween('created_at', [$episode->opened_at, $now])
                ->whereIn('kind', ['wait', 'decision', 'pause', 'drain_pause', 'resume', 'arm_recovery_started', 'arm_recovery_ended',
                    'freshness_hold', 'preview_invalidated', 'claim_release', 'handoff'])
                ->orderBy('created_at')->orderBy('id')->cursor();
            $open = [];
            foreach ($events as $event) {
                $eventReset = $event->data['reset_id'] ?? null;
                if ($eventReset !== null && $eventReset !== $agent->reset_id) {
                    continue;
                }
                $time = $event->created_at->getTimestamp();
                switch ($event->kind) {
                    case 'pause':
                    case 'drain_pause':
                        $open['pause'] ??= $time;
                        break;
                    case 'arm_recovery_started':
                        $open['recovery'] ??= $time;
                        break;
                    case 'resume':
                        $this->closeExclusion($open, $excluded, 'pause', $time);
                        $reasonChanges[$time] = null;
                        break;
                    case 'arm_recovery_ended':
                        $this->closeExclusion($open, $excluded, 'recovery', $time);
                        $reasonChanges[$time] = null;
                        break;
                    case 'claim_release':
                        if (($event->data['reason'] ?? null) === 'handoff') {
                            $reasonChanges[$time] = 'handoff';
                        }
                        break;
                    default:
                        $reason = $event->data['reason'] ?? $event->data['choice'] ?? $event->kind;
                        $reasonChanges[$time] = $reason === 'preview_discarded' ? 'preview_invalidated' : $reason;
                        break;
                }
            }
            if ($grant->paused_at !== null) {
                $open['pause'] ??= $grant->paused_at->getTimestamp();
            }
            if (! empty($episode->state['recovering_since'])) {
                $open['recovery'] ??= CarbonImmutable::parse($episode->state['recovering_since'])->getTimestamp();
            }
            foreach ($open as $start) {
                $excluded[] = [$start, $to];
            }
            ksort($reasonChanges, SORT_NUMERIC);
            $reasons = [];
            $start = $from;
            $previous = null;
            foreach ($reasonChanges as $time => $reason) {
                $reasons[] = [$start, $time, $previous];
                $start = $time;
                $previous = $reason;
            }
            $reasons[] = [$start, $to, $previous];
            $intervals[$arm['label']] = compact('from', 'to', 'busy', 'excluded', 'reasons') + ['pipelined_gaps' => $pipelinedGaps];
        }

        return $this->evaluate($intervals, $pipelineGraceSeconds);
    }

    private function finishedAt(AutomationRun $run): int
    {
        $finished = $run->totals['finished_at'] ?? $run->totals['stopped_at'] ?? null;

        return $finished ? CarbonImmutable::parse($finished)->getTimestamp() : $run->updated_at->getTimestamp();
    }

    private function closeExclusion(array &$open, array &$excluded, string $kind, int $time): void
    {
        if (isset($open[$kind])) {
            $excluded[] = [$open[$kind], $time];
            unset($open[$kind]);
        }
    }

    private function armSeconds(array $arm, int $pipelineGraceSeconds): array
    {
        $from = $arm['from'];
        $to = $arm['to'];
        $changes = [$from => [], $to => []];
        $busy = $arm['busy'] ?? [];
        foreach ($arm['pipelined_gaps'] ?? [] as [$start, $stop]) {
            if ($stop - $start <= $pipelineGraceSeconds) {
                $busy[] = [$start, $stop];
            }
        }
        foreach (['busy' => $busy, 'excluded' => $arm['excluded'] ?? [], 'reason' => $arm['reasons'] ?? []] as $kind => $intervals) {
            foreach ($intervals as $index => $interval) {
                $start = max($from, $interval[0]);
                $stop = min($to, $interval[1]);
                if ($start < $stop) {
                    // The unclipped start keeps overlapping reasons ordered at the window boundary.
                    $changes[$start][] = [$kind, $index, $interval[0], $interval[2] ?? null, true];
                    $changes[$stop][] = [$kind, $index, $interval[0], null, false];
                }
            }
        }
        ksort($changes, SORT_NUMERIC);
        $seconds = ['busy' => 0, 'excluded' => 0, 'friction' => 0, 'structural' => 0, 'capacity' => 0, 'unattributed' => 0];
        $active = ['busy' => [], 'excluded' => [], 'reason' => []];
        $last = $from;
        foreach ($changes as $time => $events) {
            if ($time > $last) {
                $reason = $active['reason'] === [] ? null : max($active['reason'])[2];
                $bucket = match (true) {
                    $active['excluded'] !== [] => 'excluded',
                    $active['busy'] !== [] => 'busy',
                    default => $this->classifyReason($reason),
                };
                $seconds[$bucket] += $time - $last;
            }
            foreach ($events as [$kind, $index, $start, $reason, $opening]) {
                if ($opening) {
                    $active[$kind][$index] = [$start, $index, $reason];
                } else {
                    unset($active[$kind][$index]);
                }
            }
            $last = $time;
        }

        return $seconds;
    }
}
