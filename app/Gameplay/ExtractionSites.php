<?php

declare(strict_types=1);

namespace App\Gameplay;

use App\Models\Agent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ExtractionSites
{
    public const ASTEROID_TYPES = MiningPlanner::SITE_TYPES;

    public const GAS_GIANT_TYPE = 'GAS_GIANT';

    public const GEOLOGICAL_PRIORS = [
        'COMMON_METAL_DEPOSITS' => ['IRON_ORE', 'COPPER_ORE', 'ALUMINUM_ORE'],
        'MINERAL_DEPOSITS' => ['QUARTZ_SAND', 'SILICON_CRYSTALS', 'ICE_WATER', 'AMMONIA_ICE', 'PRECIOUS_STONES'],
        'PRECIOUS_METAL_DEPOSITS' => ['GOLD_ORE', 'SILVER_ORE', 'PLATINUM_ORE'],
        'RARE_METAL_DEPOSITS' => ['URANITE_ORE', 'MERITIUM_ORE'],
    ];

    public const GAS_GIANT_YIELDS = ['HYDROCARBON', 'LIQUID_HYDROGEN', 'LIQUID_NITROGEN'];

    private const HOLD_MODIFIERS = ['UNSTABLE', 'CRITICAL_LIMIT'];

    private const BLOCKING_MODIFIERS = ['UNCHARTED', 'UNSTABLE_COMPOSITION', 'RADIOACTIVE', 'DEBRIS_FIELD'];

    public function recordModifiers(Agent $agent, string $waypoint, array $modifiers, CarbonImmutable $observedAt, string $source = 'waypoint', bool $modifiersPresent = true): array
    {
        return DB::transaction(function () use ($agent, $waypoint, $modifiers, $observedAt, $source, $modifiersPresent) {
            Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            $query = $this->site($agent, $waypoint);
            $previous = $query->first();
            $observedAt = $observedAt->utc()->startOfSecond();
            $previousAt = $previous ? CarbonImmutable::parse($previous->observed_at, 'UTC') : null;
            $symbols = array_values(array_unique(array_map(
                fn (array|string $modifier): string => is_array($modifier) ? $modifier['symbol'] : $modifier,
                $modifiers,
            )));
            sort($symbols);
            $observedHazards = array_intersect(self::HOLD_MODIFIERS, $symbols);
            $previousModifiers = $previous ? json_decode($previous->modifiers, true, flags: JSON_THROW_ON_ERROR) : [];
            $previousSymbols = $previousModifiers['symbols'] ?? $previousModifiers;
            $older = $previousAt && $observedAt->lt($previousAt);
            if ($older && (! $modifiersPresent || $symbols === $previousSymbols)
                && array_diff($symbols, $previousSymbols) === []) {
                return $this->siteStatus($agent, $waypoint, now: $previousAt);
            }

            $stripped = (bool) ($previous->stripped ?? false) || in_array('STRIPPED', $symbols, true);
            $holdUntil = $previous->hold_until ?? null;
            $reducedUntil = $previous->reduced_rate_until ?? null;
            $conflict = $older || ($previousAt && $observedAt->eq($previousAt) && $modifiersPresent
                && (isset($previousModifiers['conflict']) || (! isset($previousModifiers['incomplete']) && $symbols !== $previousSymbols)));
            if ($conflict || ! $modifiersPresent) {
                $symbols = array_values(array_unique(array_merge($previousSymbols, $symbols)));
                sort($symbols);
            }

            if ($observedHazards !== []) {
                $holdUntil = max($holdUntil ?? '', $observedAt->addHours(6)->toDateTimeString());
                $reducedUntil = null;
            } elseif ($holdUntil !== null && $modifiersPresent && ! $conflict && in_array($source, ['waypoint', 'read'], true)
                && $observedAt->gte(CarbonImmutable::parse($holdUntil, 'UTC'))
                && array_intersect(self::BLOCKING_MODIFIERS, $symbols) === [] && ! $stripped) {
                $holdUntil = null;
                $reducedUntil = $observedAt->addHours(6)->toDateTimeString();
            }

            $query->updateOrInsert(
                ['agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'waypoint' => $waypoint],
                [
                    'modifiers' => json_encode(match (true) {
                        $conflict => ['symbols' => $symbols, 'conflict' => true],
                        ! $modifiersPresent => ['symbols' => $symbols, 'incomplete' => true],
                        default => $symbols,
                    }, JSON_THROW_ON_ERROR),
                    'version' => ($previous->version ?? 0) + 1,
                    'observed_at' => ($older ? $previousAt : $observedAt)->toDateTimeString(),
                    'hold_until' => $holdUntil,
                    'reduced_rate_until' => $reducedUntil,
                    'stripped' => $stripped,
                    'source' => $source,
                    'updated_at' => now(),
                ],
            );

            return $this->siteStatus($agent, $waypoint, now: $older ? $previousAt : $observedAt);
        }, 3);
    }

    public function siteStatus(Agent $agent, string $waypoint, bool $allowDebris = false, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $row = $this->site($agent, $waypoint)->first();
        $modifiers = $row ? json_decode($row->modifiers, true, flags: JSON_THROW_ON_ERROR) : [];
        $symbols = $modifiers['symbols'] ?? $modifiers;
        $hold = isset($row->hold_until) ? CarbonImmutable::parse($row->hold_until, 'UTC') : null;
        $reduced = isset($row->reduced_rate_until) ? CarbonImmutable::parse($row->reduced_rate_until, 'UTC') : null;
        $blocked = array_values(array_intersect(self::BLOCKING_MODIFIERS, $symbols));
        $reason = match (true) {
            (bool) ($row->stripped ?? false) => 'STRIPPED',
            $hold && $now->lt($hold) => 'site_hold',
            $hold !== null => 'fresh_clear_required',
            ! $row || isset($modifiers['incomplete']) || $now->lt(CarbonImmutable::parse($row->observed_at, 'UTC')) => 'modifiers_missing',
            isset($modifiers['conflict']) => 'modifiers_conflict',
            $blocked !== [] => $blocked[0],
            ! $allowDebris && in_array('DEBRIS_CLUSTER', $symbols, true) => 'DEBRIS_CLUSTER',
            default => null,
        };

        return [
            'allowed' => $reason === null,
            'reason' => $reason,
            'version' => (int) ($row->version ?? 0),
            'reduced_rate' => $reduced !== null && $now->lt($reduced),
            'explosive' => in_array('EXPLOSIVE_GASES', $symbols, true),
            'hold_until' => $hold?->toIso8601String(),
        ];
    }

    public function explosiveConditionCheck(array $ship, int $plannedExtractions, int $lossPerExtractionPct = 1, int $minConditionPct = 50): bool
    {
        $frame = $ship['frame']['condition'] ?? null;
        if (! is_numeric($frame) || $plannedExtractions < 0 || $lossPerExtractionPct < 0 || $minConditionPct < 0) {
            return false;
        }

        $limiting = (float) $frame;
        // Provider mounts need not report condition. Use every component measurement available.
        foreach ([$frame, ...array_column($ship['mounts'] ?? [], 'condition')] as $condition) {
            if (! is_numeric($condition) || ! is_finite((float) $condition) || $condition < 0 || $condition > 1) {
                return false;
            }
            $limiting = min($limiting, (float) $condition);
        }

        return round($limiting * 100, 10) - $plannedExtractions * $lossPerExtractionPct >= $minConditionPct;
    }

    public function rollingCap(int $maxPerMin = 8, int $safetyPermille = 750, bool $reducedRate = false): int
    {
        $cap = intdiv($maxPerMin * $safetyPermille, 1000);

        return $reducedRate ? max(1, intdiv($cap, 2)) : $cap;
    }

    public function maxMiners(int $cooldownLcbSeconds, int $receiptCount, int $maxPerMin = 8, int $safetyPermille = 750, bool $reducedRate = false): int
    {
        $bounded = $receiptCount < 20 ? min(60, $cooldownLcbSeconds) : $cooldownLcbSeconds;
        $cap = intdiv($maxPerMin * $safetyPermille * $bounded, 60000);

        return $reducedRate ? intdiv($cap, 2) : $cap;
    }

    public function admitSend(Agent $agent, string $waypoint, string $shipSymbol, ?int $actionId, int $nowTs, int $cap = 6): array
    {
        return DB::transaction(function () use ($agent, $waypoint, $shipSymbol, $actionId, $nowTs, $cap) {
            Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            $sends = DB::table('extraction_site_sends')->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id);
            $existing = $actionId === null ? null : DB::table('extraction_site_sends')->where('ship_action_id', $actionId)->lockForUpdate()->first();
            if ($existing && ($existing->state !== 'released' || (int) $existing->agent_id !== $agent->id
                || $existing->reset_id !== $agent->reset_id || $existing->waypoint !== $waypoint || $existing->ship_symbol !== $shipSymbol)) {
                return ['allowed' => false, 'reason' => 'action_already_reserved', 'reservation_id' => null];
            }

            $count = (clone $sends)->where('waypoint', $waypoint)->where('sent_at', '>', $nowTs - 60)
                ->whereIn('state', ['reserved', 'sent', 'confirmed', 'uncertain'])->count();
            if ($count >= $cap) {
                return ['allowed' => false, 'reason' => 'site_rate_cap', 'reservation_id' => null];
            }

            $reservation = ['sent_at' => $nowTs, 'state' => 'reserved', 'updated_at' => now()];
            if ($existing) {
                (clone $sends)->where('id', $existing->id)->update($reservation);
                $id = $existing->id;
            } else {
                $id = DB::table('extraction_site_sends')->insertGetId($reservation + [
                    'agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'waypoint' => $waypoint,
                    'ship_symbol' => $shipSymbol, 'ship_action_id' => $actionId, 'created_at' => now(),
                ]);
            }

            return ['allowed' => true, 'reason' => null, 'reservation_id' => (int) $id];
        }, 3);
    }

    public function markSendResult(int $actionId, string $state): void
    {
        if (! in_array($state, ['confirmed', 'released', 'uncertain'], true)) {
            throw new InvalidArgumentException('Unknown extraction send outcome.');
        }

        $from = $state === 'confirmed' ? ['reserved', 'sent', 'uncertain'] : ['reserved', 'sent'];
        DB::table('extraction_site_sends')->where('ship_action_id', $actionId)->whereIn('state', $from)
            ->update(['state' => $state, 'updated_at' => now()]);
    }

    public function cycleModel(string $command, int $cargo, int $yieldUnits, int $cooldown, int $headroom, int $travelSeconds, float $meanBid, int $refuels, int $fuelCost, int $originReads = 0): array
    {
        if (! in_array($command, ['mine', 'mine_survey', 'siphon'], true) || $yieldUnits < 1 || $cooldown < 1
            || min($cargo, $headroom, $travelSeconds, $refuels, $fuelCost, $originReads) < 0) {
            throw new InvalidArgumentException('Invalid extraction cycle assumptions.');
        }
        if ($cargo < $headroom || $cargo === 0) {
            return ['duration_seconds' => 0, 'requests' => 0, 'net_credits' => 0.0, 'extractions' => 0, 'units' => 0];
        }

        $extractions = intdiv($cargo - $headroom, $yieldUnits) + 1;
        $units = min($cargo, $extractions * $yieldUnits);
        $sales = min(3, $extractions);
        $duration = ($extractions - 1) * $cooldown + max($cooldown, 2 * $travelSeconds + 30 + 10 * ($sales + $refuels));

        // Every command needs the full field set in the reference replay. Config and
        // equipment keep the oldest timestamp until a full read refreshes all fields.
        $shipReads = 2;
        $lastRefresh = $travelSeconds;
        for ($i = 1; $i < $extractions; $i++) {
            $at = $travelSeconds + $i * $cooldown;
            if ($at - $lastRefresh > 120) {
                $shipReads++;
                $lastRefresh = $at;
            }
        }
        $requests = 2 * $extractions + 10 + 2 * ($sales + $refuels) + $originReads + (int) ($duration >= 300) + $shipReads;

        return [
            'duration_seconds' => $duration,
            'requests' => $requests,
            'net_credits' => $units * $meanBid - $fuelCost * $refuels,
            'extractions' => $extractions,
            'units' => $units,
        ];
    }

    private function site(Agent $agent, string $waypoint): Builder
    {
        return DB::table('extraction_site_states')->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('waypoint', $waypoint);
    }
}
