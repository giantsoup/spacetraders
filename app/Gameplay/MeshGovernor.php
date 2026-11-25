<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\Ship;
use App\Models\ShipAction;

final class MeshGovernor
{
    public function __construct(private RequestBudget $budget) {}

    public function evaluate(Agent $agent, CampaignEpisode $episode, ?AutomationRun $run = null): array
    {
        return $this->govern($agent, $episode, $run);
    }

    public function beginPass(Agent $agent, CampaignEpisode $episode, array $candidates = []): array
    {
        return $this->govern($agent, $episode, candidates: $candidates);
    }

    private function govern(Agent $agent, CampaignEpisode $episode, ?AutomationRun $run = null, ?array $candidates = null, int $proposed = 0): array
    {
        $body = $this->revisionBody($episode, $agent);
        if ($body === null) {
            return ['mode' => 'hold', 'reason' => 'saturation_grant_unavailable', 'cause' => 'saturation_grant_unavailable', 'room' => 0];
        }
        $policy = CampaignMesh::policy($episode)['saturation'] ?? [];
        $hostCap = max(1, (int) config('gameplay.host_tier2_4_per_minute', 96));
        $fleetPolicy = $body['fleet']['haulers'] ?? [];
        $saturation = CampaignPlaybook::saturationPolicy($body);
        $allowance = $run?->plan['mesh_allowance'] ?? null;
        try {
            $usage = $allowance === null || $saturation !== null ? $this->budget->usage(agentSymbol: $agent->name, requireTelemetry: true)
                : $this->budget->usageForMeshRun($agent->name, $agent->reset_id, $run->id, $allowance);
        } catch (RequestDeferred $exception) {
            return ['mode' => 'hold', 'reason' => 'missing_telemetry', 'room' => 0, 'cause' => $exception->delaySource ?: 'missing_telemetry',
                'retry_after' => $exception->retryAfter, 'context' => $exception->context] + ($saturation === null ? [] : [
                    'shadow_price' => array_replace($this->shadowDefaults(), app(RequestShadowPrice::class)->loadState($agent), ['hold_reason' => 'missing_telemetry']),
                ]);
        }
        $staged = RequestBudget::agentCaps() !== [];
        $hour = $staged ? ($usage['agent']['cap_per_hour'] ?? PHP_INT_MAX)
            : (int) floor(($fleetPolicy['request_headroom'] ?? 0.8) * $hostCap * 60);
        $host = intdiv(8 * $hostCap, 10);
        $arm = 0;
        $trader = PHP_INT_MAX;
        $mode = 'normal';
        $reason = $cause = null;
        $sample = [];
        if ($staged) {
            $bootstrap = app(CampaignProbeBootstrap::class);
            $sample = $bootstrap->traderBudgetEvidence($agent);
            $current = $sample['traders'];
            $committed = $current + $bootstrap->reservations($agent, undeliveredType: CampaignHaulerPurchase::TYPE)->count();
            $ceiling = $sample['formula_max_traders'] ?? null;
            if ($saturation !== null) {
                // Budget evidence includes command, armed/owned freighters and shuttles.
                $committed += $bootstrap->reservations($agent, undeliveredType: 'SHIP_LIGHT_SHUTTLE')->count();
                $sample['untagged_requests'] = app(FleetMetrics::class)->untaggedRequestCount($agent->name);
            }
            if ($ceiling === null || ! $sample['fleet_verified']) {
                $mode = 'sampling';
                $reason = $sample['purchase_hold_reason'] ?? 'missing_trader_sample';
                $cause = 'missing_trader_sample';
            } elseif ($current > $ceiling) {
                $mode = 'hold';
                $reason = $cause = 'current_traders_exceed_ceiling';
                $trader = 0;
            } else {
                $protected = $committed + 1;
                if ($protected > $ceiling) {
                    $protected = min($ceiling, $committed);
                    $mode = 'degraded';
                    $reason = $cause = 'prospective_trader_headroom';
                }
                $trader = max(0, (int) floor($hour - $sample['agent_overhead_req_hr'] - $protected * $sample['trader_only_req_per_active_trader_hr']));
                $sample['protected_traders'] = $protected;
                if (($sample['untagged_requests'] ?? 0) > 0 && $mode === 'normal') {
                    $mode = 'sampling';
                    $reason = 'untagged_request_telemetry';
                }
            }
        } else {
            $arm = app(CampaignSaturation::class)->requests($agent, $fleetPolicy + ['request_headroom' => 0.8], usage: $usage)['projected_arm_requests'];
        }
        if ($saturation !== null) {
            return $this->governSaturation($agent, $saturation, $usage, $mode, $reason, $sample, $staged, $hour, $host, $arm, $trader, $candidates, $proposed);
        }
        $used = $usage['agent']['tier2_4_last_hour'] + $arm + $usage['mesh']['outstanding'];
        $room = max(0, min($hour - $used, $trader - $usage['mesh']['used'] - $usage['mesh']['outstanding']));
        $minuteRoom = max(0, $host - $usage['host']['tier2_4_last_60_seconds'] - 1);
        if ($room === 0 || $minuteRoom === 0 || $usage['host']['rate_limits_last_15_minutes'] >= 3) {
            $mode = 'hold';
            $reason = match (true) {
                $usage['host']['rate_limits_last_15_minutes'] >= 3 => 'host_rate_limits',
                $minuteRoom === 0 => 'host_minute_headroom',
                default => 'agent_hour_or_trader_headroom',
            };
            $cause = $reason === 'agent_hour_or_trader_headroom' && $cause === 'current_traders_exceed_ceiling' ? $cause : $reason;
        } elseif (in_array($mode, ['normal', 'sampling'], true) && $used / $hour >= ($policy['governor_headroom_pct'] ?? 90) / 100) {
            $mode = 'degraded';
            $reason = $cause = 'agent_hour_threshold';
        }

        return compact('mode', 'reason', 'cause', 'room', 'minuteRoom', 'used', 'sample', 'staged') + ['limits' => compact('hour', 'host', 'arm', 'trader')];
    }

    private function revisionBody(CampaignEpisode $episode, Agent $agent): ?array
    {
        if ($revision = $episode->getRelationValue('policyRevision')) {
            return $revision->body;
        }
        $grant = CampaignGrant::find($episode->campaign_grant_id);
        if ($grant?->arm()) {
            return app(CampaignSafety::class)->saturationGrant($episode, $agent)?->revision()->body;
        }

        return $grant?->revision()->body ?? [];
    }

    private function shadowDefaults(): array
    {
        return ['lambda_req' => 0, 'lambda_target' => 0, 'lambda_clear' => 0, 'residual_excess' => 0,
            'utilization_permille' => null, 'paused_tiers' => [], 'hold_reason' => null];
    }

    private function governSaturation(Agent $agent, array $policy, array $usage, string $mode, ?string $reason, array $sample, bool $staged,
        int $hardLimit, int $host, int $arm, int $trader, ?array $candidates, int $proposed): array
    {
        $price = app(RequestShadowPrice::class);
        $lambda = $policy['lambda'] ?? [];
        $high = $lambda['high_permille'] ?? 900;
        $hour = $price->ordinaryCap($hardLimit, $high);
        $actual = $usage['agent']['tier2_4_last_hour'];
        $remaining = $usage['mesh']['outstanding'];
        // The projected arm minimum is a sustained gate. Only explicit proposed work belongs in F.
        $used = $actual + $remaining;
        $utilization = $price->utilizationPermille($actual, $remaining, $proposed, $hardLimit);
        $room = max(0, $hour - $used);
        $minuteRoom = max(0, $host - $usage['host']['tier2_4_last_60_seconds'] - 1);
        $hostLimited = $usage['host']['rate_limits_last_15_minutes'] >= 3 || $minuteRoom === 0;
        if ($hostLimited || $room === 0) {
            $reason = match (true) {
                $usage['host']['rate_limits_last_15_minutes'] >= 3 => 'host_rate_limits',
                $minuteRoom === 0 => 'host_minute_headroom',
                $mode === 'hold' => $reason,
                default => 'agent_hour_headroom',
            };
            $mode = 'hold';
        }
        $state = $price->loadState($agent) + $this->shadowDefaults();
        $candidates = $candidates === null ? null : $price->sheddingOrder($candidates);
        $excess = max(0, $used + $proposed - $hour);
        if ($candidates !== null) {
            $passProposed = array_sum(array_column($candidates, 'requests'));
            $utilization = $price->utilizationPermille($actual, $remaining, $passProposed, $hardLimit);
            $excess = max(0, $used + $passProposed - $hour);
            $clear = $price->clearingPrice($candidates, $excess);
            $step = $price->stepPrice($state['lambda_req'], $utilization, $clear['lambda_clear'], $lambda['low_permille'] ?? 600, $high, $lambda['max_step_credits'] ?? 5);
            $tiers = $state['tiers'] ?? [];
            $nextWork = [RequestShadowPrice::T3 => 'trade', RequestShadowPrice::T4 => 'mining', RequestShadowPrice::T5 => 'buy_fleet', RequestShadowPrice::T6 => 'read'];
            foreach ($nextWork as $tier => $kind) {
                $class = array_filter($candidates, fn (array $candidate) => $candidate['tier'] === $tier);
                $classRequests = $class === [] ? $this->estimate($agent, $kind) : array_sum(array_column($class, 'requests'));
                $prospective = $price->utilizationPermille($actual, $remaining, $classRequests, $hardLimit);
                $prior = $tiers[$tier] ?? [];
                // Economic work gets an individual positive-value check when it is offered.
                $prior['positive_charged_score'] = $class === [] || collect($class)->contains(fn (array $candidate) => $candidate['gross'] > $step['price'] * $candidate['requests']);
                $tiers[$tier] = $price->updateHysteresis($prior, $utilization, $prospective, $high, $lambda['resume_permille'] ?? 850, $lambda['resume_passes'] ?? 3);
            }
            $state = array_replace($state, $clear, ['lambda_req' => $step['price'], 'lambda_target' => $step['target'],
                'tiers' => $tiers, 'paused_tiers' => array_keys(array_filter($tiers, fn (array $tier) => $tier['paused'])),
                'utilization_permille' => $utilization]);
            $state['hold_reason'] = $this->shadowHold($reason, $state);
            $price->saveState($agent, $state);
        }
        $shadow = array_intersect_key($state, $this->shadowDefaults());
        $shadow['utilization_permille'] = $utilization;
        if ($candidates === null) {
            $shadow['residual_excess'] = max($excess, $shadow['residual_excess']);
        }
        $shadow['hold_reason'] = $this->shadowHold($reason, $shadow);
        $limits = compact('hour', 'host') + ['arm' => 0, 'trader' => PHP_INT_MAX];

        return compact('mode', 'reason', 'room', 'minuteRoom', 'used', 'sample', 'staged', 'limits')
            + ['shadow_price' => $shadow, 'hard_limit' => $hardLimit, 'sustained_arm_requests' => $arm, 'legacy_trader_room' => $trader,
                'rolling_usage' => ['actual' => $actual, 'remaining' => $remaining, 'proposed' => $candidates === null ? $proposed : $passProposed]];
    }

    private function shadowHold(?string $reason, array $state): ?string
    {
        return $reason ?? match (true) {
            $state['residual_excess'] > 0 => 'residual_excess',
            $state['paused_tiers'] !== [] => 'request_hysteresis',
            default => null,
        };
    }

    private function sustainedFits(Agent $agent, array $status, int $proposed): bool
    {
        if ($proposed <= 0) {
            return true;
        }
        $sample = $status['sample'];
        $rates = app(FleetMetrics::class)->workloadRates($agent->name, $status['staged'] ? 21600 : 3600);
        $traders = $status['staged'] ? ($sample['protected_traders'] ?? $sample['traders']) * ($sample['trader_only_req_per_active_trader_hr'] ?? 0)
            : max($status['sustained_arm_requests'], $rates['workloads']['trade']['ordinary_per_hour'] ?? 0);
        $active = AutomationRun::where('active_agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->whereIn('strategy', ['mining', 'siphon', 'survey', 'tender', 'buy_fleet'])->get();
        $committed = $active->sum(fn (AutomationRun $run) => ($run->plan['hull_class'] ?? null) === 'trader'
            ? 0 : (int) ($run->plan['sustained_req_hr'] ?? $run->plan['allocation_candidate']['delta_q'] ?? 0));

        return $rates['overhead']['ordinary_per_hour'] + $traders + $committed + $proposed <= $status['hard_limit'];
    }

    public function fleetSync(Agent $agent): int
    {
        $ships = Ship::where('agent_id', $agent->id)->count();

        return 1 + max(1, intdiv($ships + 19, 20));
    }

    public function estimate(Agent $agent, string $kind): int
    {
        $sync = $this->fleetSync($agent);

        return match ($kind) {
            'buy', 'buy_probe' => 3 * $sync + 8,
            'hauler_purchase', 'buy_fleet' => 3 * $sync + 23,
            'scout', 'proof_scout', 'coverage', 'yard' => 15 + $sync + max(0, $sync - 3),
            'proof', 'trade' => 4 * $sync + 24,
            'mining', 'siphon', 'survey', 'tender', 'position' => 3 * $sync + 24,
            default => 1,
        };
    }

    public function admit(Agent $agent, CampaignEpisode $episode, string $kind, int|float|null $requests = null, int $sustainedRequests = 0, ?float $deadline = null): string
    {
        // The previous positional argument carried a microtime deadline.
        if (is_float($requests)) {
            $deadline ??= $requests;
            $requests = null;
        }
        $charge = max($this->estimate($agent, $kind), $requests ?? 0);
        $status = $this->govern($agent, $episode, proposed: $charge);
        $saturation = isset($status['shadow_price']);
        $permitted = match ($status['mode']) {
            'normal' => true,
            'sampling' => in_array($kind, $saturation ? ['demand', 'directory', 'proof', 'proof_scout'] : ['demand', 'directory'], true),
            'degraded' => in_array($kind, $saturation ? ['demand', 'coverage', 'trade'] : ['demand'], true),
            default => false,
        };
        if ($permitted && $saturation) {
            $tier = match ($kind) {
                'demand', 'directory', 'coverage', 'proof_scout', 'proof' => RequestShadowPrice::T2,
                'trade' => RequestShadowPrice::T3,
                'mining', 'siphon', 'survey', 'tender', 'position', 'site' => RequestShadowPrice::T4,
                'read', 'scout' => RequestShadowPrice::T6,
                default => RequestShadowPrice::T5,
            };
            $shadow = $status['shadow_price'];
            $permitted = $tier === RequestShadowPrice::T2 || ($shadow['residual_excess'] === 0 && ! in_array($tier, $shadow['paused_tiers'], true));
            if ($tier === RequestShadowPrice::T4 && ($this->estimate($agent, 'coverage') > $status['room'] - $charge)
                && app(CampaignMesh::class)->coverageDependencies($episode, $agent, requireMove: true) !== []) {
                $permitted = false;
                $status['reason'] = 'tier_1_coverage_allowance';
            }
            if (in_array($kind, ['proof', 'proof_scout'], true)) {
                $permitted = ! AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                    ->where(fn ($query) => $query->where('plan->fleet_saturation_proof', $kind)->orWhere('plan->mesh_work', $kind))
                    ->where(fn ($query) => $query->whereNotNull('active_agent_id')->orWhere(fn ($preview) => $preview
                        ->where('state', 'preview')->where('expires_at', '>', now())))->exists();
            }
            if ($permitted && ! $this->sustainedFits($agent, $status, $sustainedRequests)) {
                $permitted = false;
                $status['reason'] = 'sustained_request_capacity';
            }
        }
        if (! $permitted) {
            throw (new RequestDeferred('Mesh request admission held: '.($status['reason'] ?? $status['shadow_price']['hold_reason'] ?? 'insufficient allowance').'.',
                $status['retry_after'] ?? 10, delaySource: $status['cause'] ?? $status['reason'] ?? $status['shadow_price']['hold_reason'] ?? 'mesh_admission', context: $status['context'] ?? []))
                ->withDeadline($deadline);
        }
        if ($status['room'] < $charge) {
            throw (new RequestDeferred('Mesh request admission held: insufficient allowance.', 10, delaySource: 'mesh_headroom',
                context: ['room' => $status['room'], 'charge' => $charge, 'kind' => $kind]))->withDeadline($deadline);
        }
        $id = $this->budget->admitMesh($agent->name, $agent->reset_id, $status['limits'], $charge, 120, $deadline, $denial);
        if ($id === null) {
            throw (new RequestDeferred('Mesh request admission held: '.($status['reason'] ?? 'insufficient allowance').'.',
                $denial['retry_after'] ?? 10, delaySource: $denial['cause'] ?? 'mesh_admission'))->withDeadline($deadline);
        }

        return $id;
    }

    public function immediate(Agent $agent, CampaignEpisode $episode, string $kind, callable $work, ?float $deadline = null): mixed
    {
        $id = $this->admit($agent, $episode, $kind, deadline: $deadline);
        try {
            $workload = match ($kind) {
                'demand', 'coverage', 'proof_scout', 'yard' => 'coverage',
                'directory', 'site' => 'discovery',
                default => FleetMetrics::currentWorkload() ?? 'director',
            };

            return FleetMetrics::withWorkload($workload, fn () => RequestBudget::forMeshAllowance($id, $work, $agent->name, $agent->reset_id));
        } finally {
            $this->release($id, $deadline);
        }
    }

    public function preview(Agent $agent, CampaignEpisode $episode, string $kind, callable $work, ?int $requests = null, int $sustainedRequests = 0, ?float $deadline = null): AutomationRun
    {
        $id = $this->admit($agent, $episode, $kind, $requests, $sustainedRequests, $deadline);
        try {
            $run = RequestBudget::forMeshAllowance($id, $work, $agent->name, $agent->reset_id);
            if (($run->plan['mesh_allowance'] ?? null) !== $id) {
                $this->release($id, $deadline);
            }

            return $run;
        } catch (\Throwable $exception) {
            try {
                $this->release($id, $deadline);
            } catch (\Throwable) {
                // Keep the preview failure as the caller's deferral.
            }
            throw $exception;
        }
    }

    public function run(Agent $agent, AutomationRun $run, callable $work, ?float $deadline = null): mixed
    {
        if (! isset($run->plan['mesh_work'])) {
            return $work();
        }
        if ($run->plan_version === 8 && (! empty($run->plan['sell_only'])
            || (AutomationRunner::hasTradeCargo($run) && in_array($run->totals['phase'] ?? null, ['sell', 'deliver'], true)))) {
            // T0 can use the existing protected budget and its ordinary borrowing path.
            return $work();
        }
        $episode = CampaignEpisode::findOrFail($run->campaign_episode_id);
        $status = $this->evaluate($agent, $episode, $run);
        $id = $run->plan['mesh_allowance'] ?? null;
        if (isset($status['shadow_price']) && in_array($run->strategy, ['buy_fleet', 'buy_hauler', 'buy_probe'], true)
            && $status['mode'] !== 'normal' && ! ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->whereNotNull('sent_at')->exists()) {
            throw (new RequestDeferred('Fleet purchase admission held: '.($status['reason'] ?? 'request headroom').'.', $status['retry_after'] ?? 10,
                delaySource: $status['cause'] ?? $status['reason'] ?? 'mesh_admission', context: $status['context'] ?? []))->withDeadline($deadline);
        }
        if ($run->plan['mesh_work'] === 'hauler_purchase' && $status['mode'] === 'hold') {
            throw (new RequestDeferred('Mesh request admission held: '.$status['reason'].'.', $status['retry_after'] ?? 10,
                delaySource: $status['cause'] ?? $status['reason'] ?? 'mesh_admission', context: $status['context'] ?? []))->withDeadline($deadline);
        }
        if (! isset($status['limits'])) {
            throw (new RequestDeferred('Mesh run lacks a current request allowance.', $status['retry_after'] ?? 10,
                delaySource: $status['cause'] ?? 'missing_telemetry', context: $status['context'] ?? []))->withDeadline($deadline);
        }
        if (! $id || ! $this->budget->refreshMesh($id, $agent->name, $agent->reset_id, $run->id, $status['limits'], $deadline)) {
            throw (new RequestDeferred('Mesh run lacks a current request allowance.', 10, delaySource: 'mesh_allowance'))->withDeadline($deadline);
        }

        return RequestBudget::forMeshAllowance($id, $work, $agent->name, $agent->reset_id, $run->id);
    }

    public function release(?string $id, ?float $deadline = null): bool
    {
        try {
            $entry = $this->budget->releaseMesh($id, $deadline);
            if ($entry !== []) {
                FleetMetrics::meshAllowance($entry['used'], $entry['remaining']);
            }

            return true;
        } catch (RequestDeferred $exception) {
            FleetMetrics::observe($exception);

            return false;
        }
    }
}
