<?php

declare(strict_types=1);

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\ShipAction;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StateChain
{
    public const SHIP_FIELDS = ['nav', 'cargo', 'fuel', 'cooldown', 'config', 'mounts', 'modules'];

    public const RECEIPT_EFFECTS = [
        'orbit' => ['nav'],
        'dock' => ['nav'],
        'navigate' => ['nav', 'fuel'],
        'flight_mode' => ['nav', 'fuel'],
        'buy' => ['cargo'],
        'sell' => ['cargo'],
        'jettison' => ['cargo'],
        'refuel' => ['fuel'],
        'mine' => ['cargo', 'cooldown'],
        'mine_survey' => ['cargo', 'cooldown'],
        'siphon' => ['cargo', 'cooldown'],
        'survey' => ['cooldown'],
        'transfer' => ['cargo', 'cooldown'],
        'buy_ship' => self::SHIP_FIELDS,
    ];

    public function recordFullShip(Agent $agent, array $ship, ?CarbonImmutable $at = null, string $source = 'ship_get'): array
    {
        if (! isset($ship['config']) && isset($ship['frame'], $ship['engine'], $ship['reactor'])) {
            $ship['config'] = [
                'frame' => $ship['frame'],
                'engine' => $ship['engine'],
                'reactor' => $ship['reactor'],
            ];
        }

        return $this->writeFields($agent, $ship['symbol'], $ship, self::SHIP_FIELDS, $at ?? CarbonImmutable::now(), $source);
    }

    public function mergeReceipt(Agent $agent, string $shipSymbol, string $command, array $receiptFields, ?CarbonImmutable $at = null, bool $fromCargo = false, bool $damageEvent = false): array
    {
        $mayChange = self::RECEIPT_EFFECTS[$command] ?? self::SHIP_FIELDS;
        if ($command === 'refuel' && $fromCargo) {
            $mayChange[] = 'cargo';
        }
        $invalidated = $damageEvent ? ['config', 'mounts', 'modules'] : [];

        return $this->writeFields($agent, $shipSymbol, $receiptFields, $mayChange, $at ?? CarbonImmutable::now(), 'receipt:'.$command, $invalidated);
    }

    public function invalidateShipFields(Agent $agent, string $shipSymbol, array $fields = self::SHIP_FIELDS): void
    {
        $this->writeFields($agent, $shipSymbol, [], $fields, CarbonImmutable::now(), 'invalidation');
    }

    public function staleFields(Agent $agent, string $shipSymbol, ?CarbonImmutable $now = null, int $maxAgeSeconds = 120, bool $physicalArrival = false): array
    {
        $now ??= CarbonImmutable::now();
        $fields = $this->readFields($agent, $shipSymbol);
        $stale = [];
        foreach (self::SHIP_FIELDS as $field) {
            if (! empty($fields[$field]['invalidated']) || ! $this->isFresh($fields[$field]['observed_at'] ?? null, $now, $maxAgeSeconds)) {
                $stale[] = $field;
            }
        }

        $nav = $fields['nav']['value'] ?? null;
        $arrivalDue = $physicalArrival && (is_array($nav) ? ($nav['status'] ?? null) : $nav) === 'IN_TRANSIT';

        return ['needs_refresh' => $arrivalDue || $stale !== [], 'stale_fields' => $stale, 'arrival_due' => $arrivalDue, 'fields' => $fields];
    }

    public function generation(Agent $agent, string $kind, string $symbol): int
    {
        return (int) $this->scope('observation_generations', $agent)->where(['kind' => $kind, 'symbol' => $symbol])->value('generation');
    }

    public function bumpGeneration(Agent $agent, string $kind, string $symbol, ?int $actionId = null): int
    {
        if (! $agent->reset_id) {
            return 0;
        }

        return DB::transaction(function () use ($agent, $kind, $symbol, $actionId) {
            $key = $this->identity($agent) + ['kind' => $kind, 'symbol' => $symbol];
            DB::table('observation_generations')->insertOrIgnore($key);
            $query = DB::table('observation_generations')->where($key);
            $query->increment('generation', 1, ['last_action_id' => $actionId, 'updated_at' => now()]);

            return (int) $query->value('generation');
        }, 3);
    }

    public function validatePriceEvidence(array $evidence, CarbonImmutable $now, int $currentGeneration, string $expectedEndpoint, ?int $maxPrice = null, ?int $minPrice = null, int $maxAgeSeconds = 10): array
    {
        $price = $evidence['price'] ?? $evidence['total'] ?? null;
        $expiresAt = $this->observationTime($evidence['expires_at'] ?? null);
        $reason = match (true) {
            ($evidence['endpoint'] ?? null) !== $expectedEndpoint => 'wrong_endpoint',
            ! empty($evidence['invalidated']) || ($evidence['generation'] ?? null) !== $currentGeneration => 'invalidated',
            isset($evidence['expires_at']) && ($expiresAt === null || $expiresAt->lte($now)) => 'expired_quote',
            ($evidence['fresh'] ?? true) !== true || ($evidence['detailed'] ?? true) !== true => 'missing_preflight',
            ! $this->isFresh($evidence['observed_at'] ?? $evidence['observed'] ?? null, $now, $maxAgeSeconds) => 'stale_quote',
            ! is_int($price) || $price < 0 => 'missing_price',
            $maxPrice !== null && $price > $maxPrice => 'above_max_price',
            $minPrice !== null && $price < $minPrice => 'below_min_price',
            default => null,
        };

        return ['ok' => $reason === null, 'reason' => $reason];
    }

    public function needsOriginFuelRead(Agent $agent, string $origin, ?array $evidence, CarbonImmutable $now, int $maxAgeSeconds = 900, bool $isMarket = true): bool
    {
        if (! $isMarket) {
            return false;
        }

        $data = $evidence['data'] ?? $evidence ?? [];
        $fuel = $data['fuel'] ?? collect($data['tradeGoods'] ?? [])->firstWhere('symbol', 'FUEL');
        if (($evidence['origin'] ?? $evidence['endpoint'] ?? $evidence['symbol'] ?? null) !== $origin
            || (string) ($evidence['agent_id'] ?? '') !== (string) $agent->id
            || ($evidence['reset_id'] ?? null) !== $agent->reset_id
            || ! empty($evidence['invalidated']) || ! empty($data['invalidated_by_action'])
            || ($evidence['detailed'] ?? true) !== true
            || isset($fuel['type']) && ! in_array($fuel['type'], ['EXPORT', 'EXCHANGE'], true)
            || ($fuel['purchasePrice'] ?? $fuel['ask'] ?? 0) < 1
            || ($fuel['tradeVolume'] ?? $fuel['volume'] ?? 0) < 1
            || ! $this->isFresh($evidence['observed_at'] ?? null, $now, min(900, $maxAgeSeconds, (int) config('gameplay.fuel_quote_max_seconds', 21600)))) {
            return true;
        }

        return ($evidence['generation'] ?? null) !== $this->generation($agent, 'market', $origin);
    }

    /**
     * Action reservations wait for promotion to the pending transport state.
     * A zero exposure closes an existing standalone owner/purpose hold.
     * The caller owns the agent writer lock and the credit checks.
     */
    public function reserveCredit(Agent $agent, string $ownerKey, string $purpose, string $command, int $exposure, ?int $actionId = null, ?int $runId = null, ?string $shipSymbol = null): int
    {
        if ($exposure < 0) {
            throw new InvalidArgumentException('Credit exposure must be nonnegative.');
        }

        return DB::transaction(function () use ($agent, $ownerKey, $purpose, $command, $exposure, $actionId, $runId, $shipSymbol) {
            Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            $query = $this->scope('credit_reservations', $agent);
            $existing = $actionId === null
                ? (clone $query)->whereNull('ship_action_id')->where(['owner_key' => $ownerKey, 'purpose' => $purpose, 'state' => 'reserved'])->first()
                : (clone $query)->where('ship_action_id', $actionId)->first();
            if ($existing && $actionId !== null && ($existing->owner_key !== $ownerKey || $existing->purpose !== $purpose
                || $existing->command !== $command || (int) $existing->exposure !== $exposure)) {
                throw new GameException('The existing credit reservation differs from the action exposure.');
            }
            if ($existing && $actionId === null && $exposure === 0) {
                $query->where('id', $existing->id)->update(['state' => 'released', 'updated_at' => now()]);

                return (int) $existing->id;
            }
            if ($existing && ($actionId !== null || $existing->exposure >= $exposure)) {
                return (int) $existing->id;
            }
            if ($this->hasUnresolvedCreditAction($agent)) {
                throw new GameException('Resolve the sending or uncertain credit action before reserving more credits.');
            }
            if ($existing) {
                $query->where('id', $existing->id)->update(['exposure' => $exposure, 'updated_at' => now()]);

                return (int) $existing->id;
            }

            return (int) DB::table('credit_reservations')->insertGetId($this->identity($agent) + [
                'owner_key' => $ownerKey,
                'purpose' => $purpose,
                'command' => $command,
                'exposure' => $exposure,
                'ship_action_id' => $actionId,
                'automation_run_id' => $runId,
                'ship_symbol' => $shipSymbol,
                'state' => 'reserved',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
    }

    public function promoteOldestPending(Agent $agent): ?object
    {
        return DB::transaction(function () use ($agent) {
            Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            $query = $this->scope('credit_reservations', $agent)->whereNotNull('ship_action_id');
            if ($this->hasUnresolvedCreditAction($agent) || (clone $query)->where('state', 'pending')->exists()) {
                return null;
            }
            $pending = (clone $query)->where('state', 'reserved')->orderBy('created_at')->orderBy('ship_action_id')->first();
            if (! $pending) {
                return null;
            }

            $pending->state = 'pending';
            $pending->updated_at = now();
            $query->where('id', $pending->id)->where('state', 'reserved')->update([
                'state' => $pending->state,
                'updated_at' => $pending->updated_at,
            ]);

            return $pending;
        }, 3);
    }

    public function markSending(int $actionId): void
    {
        $updated = DB::table('credit_reservations')->where('ship_action_id', $actionId)->where('state', 'pending')
            ->update(['state' => 'sending', 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new GameException('The credit action has no pending transport reservation.');
        }
    }

    /** Return true once, allowing the caller to apply the authoritative receipt delta in its transaction. */
    public function settleReservation(int $actionId, int $delta): bool
    {
        return DB::table('credit_reservations')->where('ship_action_id', $actionId)
            ->whereIn('state', ['pending', 'reserved', 'sending', 'uncertain'])
            ->update(['state' => 'settled', 'settled_delta' => $delta, 'updated_at' => now()]) === 1;
    }

    public function releaseReservation(int $actionId): bool
    {
        return DB::table('credit_reservations')->where('ship_action_id', $actionId)->whereIn('state', ['pending', 'reserved'])
            ->update(['state' => 'released', 'updated_at' => now()]) === 1;
    }

    public function releaseRunHolds(Agent $agent, int $runId, ?string $purpose = null): int
    {
        if (! $agent->reset_id) {
            return 0;
        }

        return DB::table('credit_reservations')
            ->where('agent_id', $agent->id)
            ->where('reset_id', $agent->reset_id)
            ->whereNull('ship_action_id')
            ->where(function (Builder $query) use ($runId) {
                $query->where('automation_run_id', $runId)
                    ->orWhere('owner_key', 'run:'.$runId);
            })
            ->when($purpose !== null, fn (Builder $query) => $query->where('purpose', $purpose))
            ->whereIn('state', ['reserved', 'pending'])
            ->update(['state' => 'released', 'updated_at' => now()]);
    }

    public function retainRunDeliveryFuel(Agent $agent, AutomationRun $run): void
    {
        if ($agent->reset_id !== $run->reset_id) {
            return;
        }

        $holds = $this->scope('credit_reservations', $agent)->where('purpose', 'delivery_fuel')
            ->whereNull('ship_action_id')->where('state', 'reserved');
        $runHold = (clone $holds)->where('owner_key', 'run:'.$run->id)->orderByDesc('exposure')->orderBy('id')->first();
        if (! $runHold) {
            return;
        }

        $recoveryOwner = 'recovery:'.$run->ship_symbol;
        $recoveryHolds = (clone $holds)->where('owner_key', $recoveryOwner);
        $recoveryHold = (clone $recoveryHolds)->orderByDesc('exposure')->orderBy('id')->first();
        $hold = $recoveryHold ?? $runHold;
        (clone $holds)->where('id', $hold->id)->update([
            'owner_key' => $recoveryOwner, 'automation_run_id' => null, 'ship_symbol' => $run->ship_symbol,
            'exposure' => max($hold->exposure, $runHold->exposure), 'updated_at' => now(),
        ]);
        $recoveryHolds->where('id', '!=', $hold->id)->update(['state' => 'released', 'updated_at' => now()]);
    }

    public function markUncertain(int $actionId): void
    {
        DB::table('credit_reservations')->where('ship_action_id', $actionId)->whereIn('state', ['pending', 'sending'])
            ->update(['state' => 'uncertain', 'updated_at' => now()]);
    }

    public function releaseRecoveryFuelHoldIfEmpty(Agent $agent, string $shipSymbol, array $shipSnapshot): int
    {
        if (($shipSnapshot['cargo']['units'] ?? null) !== 0) {
            return 0;
        }

        return $this->releaseRecoveryFuelHold($agent, $shipSymbol);
    }

    public function releaseRecoveryFuelHold(Agent $agent, string $shipSymbol): int
    {
        if (! $agent->reset_id) {
            return 0;
        }

        return $this->scope('credit_reservations', $agent)
            ->where('owner_key', 'recovery:'.$shipSymbol)->where('purpose', 'delivery_fuel')
            ->whereNull('ship_action_id')->where('state', 'reserved')
            ->whereNotExists(AutomationRun::where('active_agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                ->where('ship_symbol', $shipSymbol)->where('strategy', 'sell_aboard')->selectRaw('1')->toBase())
            ->whereNotExists(ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                ->where('ship_symbol', $shipSymbol)->whereIn('state', ['pending', 'sending', 'uncertain'])
                ->whereNotIn('command', array_diff(CampaignSafety::SHIP_COMMANDS, ['jettison']))->selectRaw('1')->toBase())
            ->update(['state' => 'released', 'updated_at' => now()]);
    }

    public function outstandingExposure(Agent $agent, ?string $excludeOwnerKey = null, ?string $excludePurpose = null, ?int $excludeActionId = null): int
    {
        $query = $this->scope('credit_reservations', $agent)->whereIn('state', ['pending', 'reserved', 'sending', 'uncertain']);
        if ($excludeActionId !== null) {
            $query->where(fn (Builder $rows) => $rows->whereNull('ship_action_id')->orWhere('ship_action_id', '!=', $excludeActionId));
        }
        if ($excludeOwnerKey !== null || $excludePurpose !== null) {
            $query->whereNot(fn (Builder $rows) => $rows
                ->when($excludeOwnerKey !== null, fn (Builder $q) => $q->where('owner_key', $excludeOwnerKey))
                ->when($excludePurpose !== null, fn (Builder $q) => $q->where('purpose', $excludePurpose)));
        }

        return (int) $query->get()
            ->groupBy(fn (object $row) => json_encode([$row->owner_key, $row->purpose], JSON_THROW_ON_ERROR))
            ->sum(fn ($rows) => max(
                $rows->whereNull('ship_action_id')->max('exposure') ?? 0,
                $rows->whereNotNull('ship_action_id')->sum('exposure'),
            ));
    }

    public function hasUnresolvedCreditAction(Agent $agent, ?int $excludeActionId = null): bool
    {
        return $this->scope('credit_reservations', $agent)->whereIn('state', ['sending', 'uncertain'])
            ->when($excludeActionId !== null, fn (Builder $query) => $query->where('ship_action_id', '!=', $excludeActionId))->exists();
    }

    private function readFields(Agent $agent, string $shipSymbol): array
    {
        $fields = $this->scope('ship_state_chains', $agent)->where('ship_symbol', $shipSymbol)->value('fields');

        return $fields === null ? [] : json_decode($fields, true, flags: JSON_THROW_ON_ERROR);
    }

    private function writeFields(Agent $agent, string $shipSymbol, array $values, array $mayChange, CarbonImmutable $at, string $source, array $invalidated = []): array
    {
        if (! $agent->reset_id || $shipSymbol === '') {
            return [];
        }

        return DB::transaction(function () use ($agent, $shipSymbol, $values, $mayChange, $at, $source, $invalidated) {
            Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            $fields = $this->readFields($agent, $shipSymbol);
            foreach (self::SHIP_FIELDS as $field) {
                $lastChange = $fields[$field]['invalidated_at'] ?? $fields[$field]['observed_at'] ?? null;
                if ($lastChange !== null && $at->lt(CarbonImmutable::parse($lastChange))) {
                    continue;
                }
                if (isset($values[$field])) {
                    $fields[$field] = ['value' => $values[$field], 'observed_at' => $at->toISOString(), 'source' => $source, 'invalidated' => false];
                }
                if (in_array($field, $invalidated, true) || (in_array($field, $mayChange, true) && ! isset($values[$field]))) {
                    $fields[$field] = array_replace($fields[$field] ?? [], ['invalidated' => true, 'invalidated_at' => $at->toISOString()]);
                }
            }
            DB::table('ship_state_chains')->updateOrInsert($this->identity($agent) + ['ship_symbol' => $shipSymbol], [
                'fields' => json_encode($fields, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

            return $fields;
        }, 3);
    }

    private function isFresh(mixed $observedAt, CarbonImmutable $now, int $maxAgeSeconds): bool
    {
        $observed = $this->observationTime($observedAt);

        return $observed !== null && $observed->lte($now) && $observed->gte($now->subSeconds($maxAgeSeconds));
    }

    private function observationTime(mixed $observedAt): ?CarbonImmutable
    {
        if (is_int($observedAt)) {
            return CarbonImmutable::createFromTimestampUTC($observedAt);
        }
        if ($observedAt instanceof DateTimeInterface) {
            return CarbonImmutable::instance($observedAt);
        }
        if (! is_string($observedAt) || preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/D', $observedAt) !== 1) {
            return null;
        }
        try {
            return CarbonImmutable::parse($observedAt);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function identity(Agent $agent): array
    {
        return ['agent_id' => $agent->id, 'reset_id' => $agent->reset_id];
    }

    private function scope(string $table, Agent $agent): Builder
    {
        return DB::table($table)->where($this->identity($agent));
    }
}
