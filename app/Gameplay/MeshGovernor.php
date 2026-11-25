<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\Ship;

final class MeshGovernor
{
    public function __construct(private RequestBudget $budget) {}

    public function evaluate(Agent $agent, CampaignEpisode $episode, ?AutomationRun $run = null): array
    {
        $policy = CampaignMesh::policy($episode)['saturation'];
        $hostCap = max(1, (int) config('gameplay.host_tier2_4_per_minute', 96));
        $fleetPolicy = $episode->getRelationValue('policyRevision')?->body['fleet']['haulers'] ?? $episode->grant()->revision()->body['fleet']['haulers'] ?? [];
        $allowance = $run?->plan['mesh_allowance'] ?? null;
        try {
            $usage = $allowance === null ? $this->budget->usage(agentSymbol: $agent->name, requireTelemetry: true)
                : $this->budget->usageForMeshRun($agent->name, $agent->reset_id, $run->id, $allowance);
        } catch (RequestDeferred $exception) {
            return ['mode' => 'hold', 'reason' => 'missing_telemetry', 'room' => 0, 'cause' => $exception->delaySource ?: 'missing_telemetry',
                'retry_after' => $exception->retryAfter, 'context' => $exception->context];
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
            }
        } else {
            $arm = app(CampaignSaturation::class)->requests($agent, $fleetPolicy + ['request_headroom' => 0.8], usage: $usage)['projected_arm_requests'];
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
        } elseif (in_array($mode, ['normal', 'sampling'], true) && $used / $hour >= $policy['governor_headroom_pct'] / 100) {
            $mode = 'degraded';
            $reason = $cause = 'agent_hour_threshold';
        }

        return compact('mode', 'reason', 'cause', 'room', 'minuteRoom', 'used', 'sample', 'staged') + ['limits' => compact('hour', 'host', 'arm', 'trader')];
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
            'buy' => 3 * $sync + 8,
            'hauler_purchase' => 3 * $sync + 23,
            'scout' => 15 + $sync + max(0, $sync - 3),
            default => 1,
        };
    }

    public function admit(Agent $agent, CampaignEpisode $episode, string $kind, ?float $deadline = null): string
    {
        $status = $this->evaluate($agent, $episode);
        $permitted = match ($status['mode']) {
            'normal' => true,
            'sampling' => in_array($kind, ['demand', 'directory'], true),
            'degraded' => $kind === 'demand',
            default => false,
        };
        if (! $permitted) {
            throw (new RequestDeferred('Mesh request admission held: '.($status['reason'] ?? 'insufficient allowance').'.',
                $status['retry_after'] ?? 10, delaySource: $status['cause'] ?? 'mesh_admission', context: $status['context'] ?? []))
                ->withDeadline($deadline);
        }
        $charge = $this->estimate($agent, $kind);
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
        $id = $this->admit($agent, $episode, $kind, $deadline);
        try {
            return RequestBudget::forMeshAllowance($id, $work, $agent->name, $agent->reset_id);
        } finally {
            $this->release($id, $deadline);
        }
    }

    public function preview(Agent $agent, CampaignEpisode $episode, string $kind, callable $work, ?float $deadline = null): AutomationRun
    {
        $id = $this->admit($agent, $episode, $kind, $deadline);
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
        $episode = CampaignEpisode::findOrFail($run->campaign_episode_id);
        $status = $this->evaluate($agent, $episode, $run);
        $id = $run->plan['mesh_allowance'] ?? null;
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
