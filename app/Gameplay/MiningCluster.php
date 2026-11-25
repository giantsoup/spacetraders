<?php

declare(strict_types=1);

namespace App\Gameplay;

use App\Enums\ActionState;
use App\Enums\RunState;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\Ship;
use App\Models\ShipAction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MiningCluster
{
    private const CANARY_FAMILY = 'phase3_transfer_canary';

    private const ACTIVE_LEASE_STATES = ['granted', 'sending', 'uncertain'];

    public function __construct(private HostLock $locks) {}

    public function admitCanaryPair(Agent $agent, string $clusterId, string $site, string $buyer, string $minerShip, string $tenderShip, int $minerRunId, int $tenderRunId, array $allowedGoods, int $unitCap = 15, int $exposureCap = 5000, bool $proofPassed = true, bool $requestsFit = true): array
    {
        $goods = array_values(array_unique($allowedGoods));
        $reason = match (true) {
            ! $proofPassed => 'proof_required',
            ! $requestsFit => 'request_capacity',
            $unitCap < 1 || $unitCap > 15 || $exposureCap < 0 || $exposureCap > 5000 => 'canary_bounds',
            $clusterId === '' || $site === '' || $buyer === '' || $minerShip === '' || $tenderShip === ''
                || $minerShip === $tenderShip || min($minerRunId, $tenderRunId) < 1 || $minerRunId === $tenderRunId
                || $goods === [] || array_diff($goods, app(MiningPlanner::class)->allYields()) !== [] => 'invalid_canary_pair',
            default => null,
        };
        if ($reason !== null) {
            return ['allowed' => false, 'reason' => $reason];
        }

        return $this->withWriter($agent, [$minerShip, $tenderShip], function () use ($agent, $clusterId, $site, $buyer, $minerShip, $tenderShip, $minerRunId, $tenderRunId, $goods, $unitCap, $exposureCap) {
            $slot = $this->canary($agent)->first();
            if ($slot?->canary_pair_id !== null || ($slot && $slot->state !== 'waiting_evidence')) {
                return ['allowed' => false, 'reason' => 'canary_slot_used'];
            }
            $ships = [$minerShip, $tenderShip];
            if (Ship::where('agent_id', $agent->id)->whereIn('symbol', $ships)->count() !== 2
                || AutomationRun::where('active_agent_id', $agent->id)->whereIn('ship_symbol', $ships)->exists()) {
                return ['allowed' => false, 'reason' => 'canary_ships_not_idle_and_owned'];
            }
            if ($this->leases($agent)->whereNotNull('canary_pair_id')->exists()
                || $this->leasesForShips($agent, $ships)->exists()) {
                return ['allowed' => false, 'reason' => 'canary_existing_lease'];
            }

            $pair = [
                'canary_pair_id' => (string) Str::uuid(),
                'cluster_id' => $clusterId, 'site' => $site, 'buyer' => $buyer,
                'source_ship' => $minerShip, 'receiver_ship' => $tenderShip,
                'source_run_id' => $minerRunId, 'receiver_run_id' => $tenderRunId,
                'allowed_goods' => $goods, 'unit_cap' => $unitCap, 'exposure_cap' => $exposureCap,
            ];
            $this->canary($agent)->updateOrInsert(
                ['agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'corridor_family' => self::CANARY_FAMILY],
                [
                    'state' => 'admitted',
                    'canary_pair_id' => $pair['canary_pair_id'],
                    'automation_run_id' => $minerRunId,
                    'evidence' => json_encode($pair, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ],
            );

            return ['allowed' => true, 'reason' => null] + $pair;
        });
    }

    public function canAdmitOrdinary(bool $measuredCooldown, bool $dwellObserved, bool $requestsFit): bool
    {
        return $measuredCooldown && $dwellObserved && $requestsFit;
    }

    /**
     * Pass the lease columns for the pinned endpoints and exact lot. Optional evidence
     * includes now, rendezvous_cutoff, receiver_reserved_units, source_attributable_units,
     * source_snapshot and receiver_snapshot. Supply ship_action_id to bind an existing intent.
     */
    public function issueLease(Agent $agent, array $params): ?array
    {
        foreach (['cluster_id', 'source_ship', 'receiver_ship', 'waypoint', 'good'] as $key) {
            if (! is_string($params[$key] ?? null) || $params[$key] === '') {
                return null;
            }
        }
        foreach (['source_run_id', 'receiver_run_id', 'units'] as $key) {
            if (! is_int($params[$key] ?? null) || $params[$key] <= 0) {
                return null;
            }
        }
        if ($params['source_ship'] === $params['receiver_ship'] || $params['source_run_id'] === $params['receiver_run_id']) {
            return null;
        }

        return $this->withWriter($agent, [$params['source_ship'], $params['receiver_ship']], function () use ($agent, $params) {
            $now = $this->time($params['now'] ?? null) ?? CarbonImmutable::now();
            $pairId = $params['canary_pair_id'] ?? null;
            $actionId = $params['ship_action_id'] ?? null;
            $costBasis = $params['cost_basis'] ?? 0;
            if (! is_int($costBasis) || $costBasis < 0 || ($pairId !== null && (! is_string($pairId) || $pairId === ''))
                || ($actionId !== null && (! is_int($actionId) || $actionId <= 0))) {
                return null;
            }

            $lease = [
                'agent_id' => $agent->id,
                'reset_id' => $agent->reset_id,
                'cluster_id' => $params['cluster_id'],
                'canary_pair_id' => $pairId,
                'source_ship' => $params['source_ship'],
                'receiver_ship' => $params['receiver_ship'],
                'source_run_id' => $params['source_run_id'],
                'receiver_run_id' => $params['receiver_run_id'],
                'waypoint' => $params['waypoint'],
                'good' => $params['good'],
                'units' => $params['units'],
                'cost_basis' => $costBasis,
            ];
            $runs = $this->matchingRuns($agent, $lease, $now);
            if (! $runs || ! $this->matchesCanary($agent, $lease, $runs, $params)) {
                return null;
            }

            $episodeId = $runs[0]->campaign_episode_id;
            $grantId = $episodeId === null ? null : $this->activeGrant($agent, $episodeId, $now)?->id;
            if ($episodeId !== null && $grantId === null) {
                return null;
            }
            if (($params['campaign_episode_id'] ?? $episodeId) !== $episodeId || ($params['campaign_grant_id'] ?? $grantId) !== $grantId) {
                return null;
            }
            $cutoff = $this->rendezvousCutoff($runs[1], $params);
            if (($cutoff && $now->gte($cutoff)) || ! $this->rendezvousEvidence($agent, $lease, $runs[0], $params, $now)) {
                return null;
            }
            $lease += ['campaign_grant_id' => $grantId, 'campaign_episode_id' => $episodeId];
            $action = $actionId === null ? null : $this->matchingAction($agent, $lease, $actionId, $now);
            if ($actionId !== null && ! $action) {
                return null;
            }

            $active = $this->leasesForShips($agent, [$lease['source_ship'], $lease['receiver_ship']])->first();
            if ($active) {
                if ($active->state !== 'granted' || $now->gte(CarbonImmutable::parse($active->expires_at, 'UTC'))
                    || array_diff_assoc($lease, (array) $active) !== []
                    || ($active->ship_action_id !== null && (int) $active->ship_action_id !== $actionId)
                    || ($action && ! $this->bindAction($action, (int) $active->id))) {
                    return null;
                }
                if ($action && $active->ship_action_id === null) {
                    $active->updated_at = $now->utc()->toDateTimeString();
                    $this->leases($agent)->where('id', $active->id)->update(['ship_action_id' => $actionId, 'updated_at' => $active->updated_at]);
                    $active->ship_action_id = $actionId;
                }

                return (array) $active;
            }

            $expiry = $now->addSeconds(60)->min($runs[0]->expires_at)->min($runs[1]->expires_at);
            foreach (array_filter([$cutoff, $action?->expires_at]) as $deadline) {
                $expiry = $expiry->min($deadline);
            }
            if ($expiry->lte($now) || ($action && (isset($action->arguments['transferLeaseId'])
                || $this->leases($agent)->where('ship_action_id', $actionId)->exists()))) {
                return null;
            }

            $lease += [
                'ship_action_id' => $actionId,
                'state' => 'granted',
                'active_source_key' => $this->shipKey($agent, $lease['source_ship']),
                'active_receiver_key' => $this->shipKey($agent, $lease['receiver_ship']),
                'expires_at' => $expiry->utc()->toDateTimeString(),
                'created_at' => $now->utc()->toDateTimeString(),
                'updated_at' => $now->utc()->toDateTimeString(),
            ];
            $id = DB::table('transfer_leases')->insertGetId($lease);
            if ($action) {
                $this->bindAction($action, $id);
            }

            return (array) $this->leases($agent)->where('id', $id)->first();
        });
    }

    public function validateForTransport(Agent $agent, int $leaseId, int $actionId, array $context, CarbonImmutable $now): array
    {
        $lease = $this->leases($agent)->where('id', $leaseId)->first();
        if (! $lease) {
            return ['ok' => false, 'reason' => 'lease_missing'];
        }

        return $this->withWriter($agent, [$lease->source_ship, $lease->receiver_ship], function () use ($agent, $leaseId, $actionId, $context, $now) {
            $query = $this->leases($agent)->where('id', $leaseId);
            $lease = $query->first();
            if (! $lease || $lease->state !== 'granted') {
                return ['ok' => false, 'reason' => 'lease_not_granted'];
            }
            if ($now->gte(CarbonImmutable::parse($lease->expires_at, 'UTC'))) {
                $query->update($this->transition('expired', $now));

                return ['ok' => false, 'reason' => 'lease_expired'];
            }

            $pinned = (array) $lease;
            $action = $this->matchingAction($agent, $pinned, $actionId, $now);
            if ((int) $lease->ship_action_id !== $actionId || $action?->state !== ActionState::Pending
                || ($action->arguments['transferLeaseId'] ?? null) !== $leaseId) {
                return ['ok' => false, 'reason' => 'lease_action_mismatch'];
            }
            foreach (['cluster_id', 'canary_pair_id', 'source_ship', 'receiver_ship', 'source_run_id', 'receiver_run_id', 'waypoint', 'good', 'units'] as $key) {
                if (array_key_exists($key, $context) && $context[$key] !== $pinned[$key]) {
                    return ['ok' => false, 'reason' => 'lease_context_mismatch'];
                }
            }
            $runs = $this->matchingRuns($agent, $pinned, $now);
            if (! $runs || ! $this->matchesCanary($agent, $pinned, $runs, $context)
                || ($lease->campaign_episode_id !== null && $this->activeGrant($agent, (int) $lease->campaign_episode_id, $now)?->id !== $lease->campaign_grant_id)) {
                return ['ok' => false, 'reason' => 'lease_authority_changed'];
            }
            $cutoff = $this->rendezvousCutoff($runs[1], $context);
            if ($cutoff && $now->gte($cutoff)) {
                $query->update($this->transition('expired', $now));

                return ['ok' => false, 'reason' => 'lease_expired'];
            }
            $snapshots = $this->rendezvousEvidence($agent, $pinned, $runs[0], $context, $now);
            if (! $snapshots) {
                return ['ok' => false, 'reason' => 'lease_rendezvous_not_ready'];
            }

            $before = $action->before_state ?? [];
            $before['ship'] = $snapshots['source'];
            $before['estimate']['target_ship'] = $snapshots['receiver'];
            $action->update(['state' => ActionState::Sending, 'sent_at' => $now, 'before_state' => $before]);
            $query->update($this->transition('sending', $now));

            return ['ok' => true, 'reason' => null];
        });
    }

    public function settleLease(Agent $agent, int $leaseId, bool $exactSourceDelta, bool $exactReceiverDelta, bool $providerRefusedNoEffect = false, ?CarbonImmutable $now = null): array
    {
        $lease = $this->leases($agent)->where('id', $leaseId)->first();
        if (! $lease) {
            return ['state' => null, 'reason' => 'lease_missing'];
        }

        return $this->withWriter($agent, [$lease->source_ship, $lease->receiver_ship], function () use ($agent, $leaseId, $exactSourceDelta, $exactReceiverDelta, $providerRefusedNoEffect, $now) {
            $now ??= CarbonImmutable::now();
            $query = $this->leases($agent)->where('id', $leaseId);
            $lease = $query->first();
            if (! $lease || ! in_array($lease->state, self::ACTIVE_LEASE_STATES, true)) {
                return ['state' => $lease?->state, 'reason' => $lease ? null : 'lease_missing'];
            }

            $next = match (true) {
                $lease->state === 'granted' && $now->gte(CarbonImmutable::parse($lease->expires_at, 'UTC')) => 'expired',
                $lease->state !== 'granted' && $exactSourceDelta && $exactReceiverDelta => 'confirmed',
                $providerRefusedNoEffect && ! $exactSourceDelta && ! $exactReceiverDelta => 'failed',
                default => 'uncertain',
            };
            $query->update($this->transition($next, $now));

            return ['state' => $next, 'reason' => null];
        });
    }

    public function activeLeasesForShip(Agent $agent, string $shipSymbol): Collection
    {
        return $this->leasesForShips($agent, [$shipSymbol])->orderBy('id')->get();
    }

    public function hasBlockingLease(Agent $agent, string $shipSymbol, ?int $excludeLeaseId = null): bool
    {
        return $this->leasesForShips($agent, [$shipSymbol])->when($excludeLeaseId !== null, fn (Builder $query) => $query->where('id', '!=', $excludeLeaseId))->exists();
    }

    public function hasUncertainLease(Agent $agent, string $shipSymbol): bool
    {
        $partners = [];
        foreach ($this->activeLeasesForShip($agent, $shipSymbol) as $lease) {
            if ($lease->state === 'uncertain') {
                return true;
            }
            $partners[] = $lease->source_ship === $shipSymbol ? $lease->receiver_ship : $lease->source_ship;
        }

        return $partners !== [] && ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->whereIn('ship_symbol', $partners)->where('state', ActionState::Uncertain->value)->exists();
    }

    public function evaluateJettison(array $cargoInventory, array $netBidsByGood, array $allowlist, int $freeHeadroom, int $p95Headroom, bool $tenderDueWithinCooldown, int $yieldUnits, int $evActive, int $shadowPrice, int $removedThisHourCredits, int $hourlyCap = 5000, bool $windDown = false, array $protectedGoods = []): ?array
    {
        $hourlyCap = min(5000, $hourlyCap);
        if ($windDown || $tenderDueWithinCooldown || $freeHeadroom >= $p95Headroom || $yieldUnits <= 0 || $evActive <= 0
            || min($freeHeadroom, $shadowPrice, $removedThisHourCredits, $hourlyCap) < 0 || $removedThisHourCredits > $hourlyCap) {
            return null;
        }

        $eligible = array_diff(array_intersect($allowlist, app(MiningPlanner::class)->allYields()), $protectedGoods);
        $inventory = array_is_list($cargoInventory) ? array_column($cargoInventory, 'units', 'symbol') : $cargoInventory;
        $candidates = [];
        foreach ($eligible as $good) {
            $units = $inventory[$good] ?? 0;
            $bid = $netBidsByGood[$good] ?? null;
            if (is_int($units) && $units > 0 && is_numeric($bid) && is_finite((float) $bid)) {
                $candidates[] = ['good' => $good, 'units' => $units, 'bid' => $bid + 0];
            }
        }
        usort($candidates, fn (array $a, array $b): int => [$a['bid'], $a['good']] <=> [$b['bid'], $b['good']]);
        if ($candidates === []) {
            return null;
        }

        ['good' => $good, 'units' => $units, 'bid' => $bid] = $candidates[0];
        $opportunityCost = $units * $bid;
        $removedCredits = (int) ceil(max(0, $opportunityCost));
        if ($opportunityCost + $shadowPrice >= min($units, $yieldUnits) * $evActive
            || $removedCredits > $hourlyCap - $removedThisHourCredits) {
            return null;
        }

        return ['good' => $good, 'units' => $units, 'opportunity_cost_credits' => $removedCredits, 'cash_delta' => 0];
    }

    private function withWriter(Agent $agent, array $ships, callable $work): mixed
    {
        sort($ships, SORT_STRING);
        $writerKey = 'agent-'.$agent->id;
        $shipKeys = array_map(fn (string $ship): string => $writerKey.'-ship-'.$ship, $ships);
        $transaction = fn () => DB::transaction(function () use ($agent, $work) {
            Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();

            return $work();
        }, 3);
        foreach (array_reverse($shipKeys) as $key) {
            $next = $transaction;
            $transaction = fn () => $this->locks->holds($key) ? $next() : $this->locks->retry($key, $next);
        }

        return $this->locks->holds($writerKey) ? $transaction() : $this->locks->retry($writerKey, $transaction);
    }

    private function canary(Agent $agent): Builder
    {
        return DB::table('corridor_canary_states')->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('corridor_family', self::CANARY_FAMILY);
    }

    private function leases(Agent $agent): Builder
    {
        return DB::table('transfer_leases')->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id);
    }

    private function leasesForShips(Agent $agent, array $ships): Builder
    {
        return $this->leases($agent)->whereIn('state', self::ACTIVE_LEASE_STATES)
            ->where(fn (Builder $query) => $query->whereIn('source_ship', $ships)->orWhereIn('receiver_ship', $ships));
    }

    private function shipKey(Agent $agent, string $ship): string
    {
        return hash('sha256', json_encode([$agent->id, $agent->reset_id, $ship], JSON_THROW_ON_ERROR));
    }

    private function activeGrant(Agent $agent, int $episodeId, CarbonImmutable $now): ?CampaignGrant
    {
        $episode = CampaignEpisode::whereKey($episodeId)->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->whereNull('closed_at')->lockForUpdate()->first();
        if (! $episode || ! empty($episode->state['pending_pause']) || $episode->phase === 'wind_down' || $now->addHour()->gte($episode->next_reset_at)) {
            return null;
        }
        $grant = CampaignGrant::whereKey($episode->campaign_grant_id)->lockForUpdate()->first();

        return $grant && $grant->available() && ! $grant->isDraining() && ($grant->expires_at === null || $now->lt($grant->expires_at)) ? $grant : null;
    }

    /** @return array{AutomationRun, AutomationRun}|null */
    private function matchingRuns(Agent $agent, array $lease, CarbonImmutable $now): ?array
    {
        $runs = AutomationRun::where('agent_id', $agent->id)->where('active_agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->whereIn('id', [$lease['source_run_id'], $lease['receiver_run_id']])->whereNull('stop_requested_at')->where('expires_at', '>', $now)
            ->whereIn('state', [RunState::Running->value, RunState::Waiting->value])->lockForUpdate()->get()->keyBy('id');
        $source = $runs->get($lease['source_run_id']);
        $receiver = $runs->get($lease['receiver_run_id']);
        if (! $source || ! $receiver || $source->ship_symbol !== $lease['source_ship'] || $receiver->ship_symbol !== $lease['receiver_ship']
            || $source->campaign_episode_id !== $receiver->campaign_episode_id
            || (array_key_exists('campaign_episode_id', $lease) && $source->campaign_episode_id !== $lease['campaign_episode_id'])) {
            return null;
        }
        foreach ([$source, $receiver] as $run) {
            if (($run->plan['canary_pair_id'] ?? null) !== $lease['canary_pair_id']
                || ($run->plan['cluster_id'] ?? $lease['cluster_id']) !== $lease['cluster_id']
                || ($run->plan['site'] ?? $lease['waypoint']) !== $lease['waypoint']) {
                return null;
            }
        }

        return [$source, $receiver];
    }

    private function matchesCanary(Agent $agent, array $lease, array $runs, array $evidence): bool
    {
        if ($lease['canary_pair_id'] === null) {
            return true;
        }
        $row = $this->canary($agent)->where('state', 'admitted')->where('canary_pair_id', $lease['canary_pair_id'])->first();
        if (! $row) {
            return false;
        }

        $pair = json_decode($row->evidence, true, flags: JSON_THROW_ON_ERROR);
        $exposure = $evidence['combined_exposure'] ?? 0;
        if (! is_int($exposure) || $exposure < 0 || $exposure > $pair['exposure_cap']) {
            return false;
        }
        foreach ($runs as $run) {
            $buyer = $run->plan['buyer'] ?? $run->plan['market'] ?? $run->plan['route']['market'] ?? $pair['buyer'];
            $pinnedExposure = $run->plan['combined_exposure'] ?? 0;
            if ($buyer !== $pair['buyer'] || ! is_int($pinnedExposure) || $pinnedExposure < 0 || $pinnedExposure > $pair['exposure_cap']) {
                return false;
            }
        }

        return $pair['cluster_id'] === $lease['cluster_id'] && $pair['site'] === $lease['waypoint']
            && $pair['source_run_id'] === $lease['source_run_id'] && $pair['receiver_run_id'] === $lease['receiver_run_id']
            && $pair['source_ship'] === $lease['source_ship'] && $pair['receiver_ship'] === $lease['receiver_ship']
            && ($evidence['buyer'] ?? $pair['buyer']) === $pair['buyer']
            && in_array($lease['good'], $pair['allowed_goods'], true)
            && $lease['units'] <= $pair['unit_cap']
            && ! $this->leases($agent)->where('canary_pair_id', $lease['canary_pair_id'])->where('state', 'confirmed')->exists();
    }

    private function rendezvousCutoff(AutomationRun $receiver, array $evidence): ?CarbonImmutable
    {
        $pinned = $this->time($receiver->plan['rendezvous_cutoff'] ?? null);
        $live = $this->time($evidence['rendezvous_cutoff'] ?? null);

        return $pinned && $live ? $pinned->min($live) : ($pinned ?? $live);
    }

    private function rendezvousEvidence(Agent $agent, array $lease, AutomationRun $sourceRun, array $evidence, CarbonImmutable $now): ?array
    {
        $ships = Ship::where('agent_id', $agent->id)->whereIn('symbol', [$lease['source_ship'], $lease['receiver_ship']])
            ->lockForUpdate()->get()->keyBy('symbol');
        foreach (['source', 'receiver'] as $side) {
            $symbol = $lease[$side.'_ship'];
            $saved = $ships->get($symbol);
            $snapshot = $evidence[$side.'_snapshot'] ?? $saved?->snapshot;
            $seenAt = $this->time($evidence[$side.'_observed_at'] ?? null) ?? $saved?->observed_at;
            $readyAt = $this->time($snapshot['cooldown']['readyAt'] ?? $snapshot['cooldown']['ready_at'] ?? null);
            if (! $saved || ! $snapshot || ! $seenAt || ! $seenAt->betweenIncluded($now->subSeconds(120), $now)
                || ($snapshot['symbol'] ?? $symbol) !== $symbol || ($snapshot['nav']['waypointSymbol'] ?? null) !== $lease['waypoint']
                || ($snapshot['nav']['status'] ?? null) !== 'IN_ORBIT' || ! $readyAt || $readyAt->gt($now)) {
                return null;
            }
            $snapshots[$side] = $snapshot;
        }

        $good = $lease['good'];
        $totals = $sourceRun->totals;
        $mined = ($totals['extracted'][$good] ?? 0) - ($totals['sold'][$good] ?? 0)
            - ($totals['transferred_out'][$good] ?? 0) - ($totals['jettisoned'][$good] ?? 0);
        $attributable = match (true) {
            isset($totals['attributable_inventory']) => $totals['attributable_inventory'][$good] ?? 0,
            isset($totals['extracted']) => $mined,
            default => $evidence['source_attributable_units'] ?? 0,
        };
        $actual = array_column($snapshots['source']['cargo']['inventory'] ?? [], 'units', 'symbol')[$good] ?? 0;
        $hold = $snapshots['receiver']['cargo'] ?? [];
        $otherHolds = $evidence['receiver_reserved_units'] ?? 0;

        $ready = is_int($attributable) && is_int($otherHolds) && $otherHolds >= 0
            && min($attributable, $actual) >= $lease['units'] && isset($hold['capacity'], $hold['units'])
            && $lease['units'] <= $hold['capacity'] - $hold['units'] - $otherHolds;

        return $ready ? $snapshots : null;
    }

    private function matchingAction(Agent $agent, array $lease, int $actionId, CarbonImmutable $now): ?ShipAction
    {
        $action = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->whereKey($actionId)->lockForUpdate()->first();
        if (! $action || ! in_array($action->state, [ActionState::Preview, ActionState::Pending], true)
            || $action->command !== 'transfer' || $action->expires_at->lte($now) || $action->ship_symbol !== $lease['source_ship']
            || $action->automation_run_id !== $lease['source_run_id'] || ($action->arguments['targetShip'] ?? null) !== $lease['receiver_ship']
            || ($action->arguments['symbol'] ?? null) !== $lease['good'] || ($action->arguments['units'] ?? null) !== $lease['units']
            || ($action->arguments['canaryPairId'] ?? $lease['canary_pair_id']) !== $lease['canary_pair_id']
            || isset($action->outcome['response_received_at']) || isset($action->outcome['body_sha256'])) {
            return null;
        }

        return $action;
    }

    private function bindAction(ShipAction $action, int $leaseId): bool
    {
        $existing = $action->arguments['transferLeaseId'] ?? null;
        if ($existing !== null) {
            return $existing === $leaseId;
        }
        $action->update(['arguments' => $action->arguments + ['transferLeaseId' => $leaseId]]);

        return true;
    }

    private function transition(string $state, CarbonImmutable $now): array
    {
        return ['state' => $state, 'updated_at' => $now] + (in_array($state, self::ACTIVE_LEASE_STATES, true) ? [] : [
            'active_source_key' => null,
            'active_receiver_key' => null,
        ]);
    }

    private function time(mixed $date): ?CarbonImmutable
    {
        return CarbonImmutable::make($date, 'UTC');
    }
}
