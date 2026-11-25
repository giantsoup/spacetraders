<?php

declare(strict_types=1);

namespace App\Gameplay;

use App\Enums\ActionState;
use App\Enums\RunState;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\GameObservation;
use App\Models\ShipAction;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CorridorBook
{
    private const ACTIVE_STATES = ['reserved', 'sending', 'uncertain', 'settled', 'confirmed'];

    public function askAt(int $baseAsk, int $offset, float $decay = 0.04): int
    {
        $factor = $this->decayFactor($offset, $decay);

        return BigDecimal::of($baseAsk)->dividedBy($factor, 0, RoundingMode::Ceiling)->toInt();
    }

    public function bidAt(int $baseBid, int $offset, float $decay = 0.04): int
    {
        $factor = $this->decayFactor($offset, $decay);

        return BigDecimal::of($baseBid)->multipliedBy($factor)->toScale(0, RoundingMode::Floor)->toInt();
    }

    public function fuelAdjustedDepth(int $sourceAsk, int $buyerBid, int $unitsPerBatch, int $fuelCost, float $decay = 0.04): int
    {
        if ($sourceAsk < 1 || $buyerBid < 1 || $unitsPerBatch < 1 || $fuelCost < 0) {
            return 0;
        }
        $minimum = $this->ceilDivide($fuelCost, $unitsPerBatch);
        if ($decay === 0.0) {
            return $buyerBid - $sourceAsk > $minimum ? PHP_INT_MAX : 0;
        }

        $offset = 0;
        while (true) {
            $bid = $this->bidAt($buyerBid, $offset, $decay);
            if ($bid <= $minimum || $this->askAt($sourceAsk, $offset, $decay) >= $bid - $minimum) {
                return $offset;
            }
            $offset++;
        }
    }

    public function decayOffset(array $events, string $market, string $good, string $side, int $landingAt, string $tieKey, int $sideVolume, int $recoverySeconds = 1800): int
    {
        if ($sideVolume < 1 || $recoverySeconds < 1) {
            throw new InvalidArgumentException('Side volume and recovery time must be positive.');
        }
        $key = $market.'|'.$good.'|'.$side;
        $weightedUnits = 0;
        foreach ($events as $event) {
            if ($this->key($event) !== $key || ! $this->active($event) || ! empty($event['absorbed'])) {
                continue;
            }
            if ($this->unresolved($event)) {
                $weight = 1000;
            } elseif ($this->earlier($event, $landingAt, $tieKey)) {
                $weight = $this->weight($landingAt - $event['landing_at'], $recoverySeconds);
            } else {
                continue;
            }
            $weightedUnits += $event['units'] * $weight;
        }

        return $this->ceilDivide($weightedUnits, 1000 * $sideVolume);
    }

    /**
     * Events carry market, good, side, units, landing_at (Unix seconds) and tie_key.
     * Per-side volumes use market|good|side keys (or nested market/good/side keys).
     */
    public function fitsWindow(array $existingEvents, array $candidateEvents, array $sideVolumes, int $depthBatches = 7, int $recoverySeconds = 1800): bool
    {
        if ($depthBatches < 1 || $recoverySeconds < 1) {
            return false;
        }
        $candidatesByKey = $this->byKey($candidateEvents);
        $existingByKey = $this->byKey($existingEvents);
        foreach ($candidatesByKey as $key => $candidates) {
            $volume = $this->lookup($sideVolumes, $key);
            if (! is_int($volume) || $volume < 1) {
                return false;
            }
            $capacity = $depthBatches * $volume;
            if (! $this->fitsKey($existingByKey[$key] ?? [], $candidates, $capacity, $recoverySeconds)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Quotes are keyed by market|good|side and carry price and volume (provider
     * purchasePrice/sellPrice and tradeVolume also work). Result keys match candidate keys.
     */
    public function priceAndValidate(array $existingEvents, array $candidateEvents, array $quotesByKey, array $existingGuards = [], int $depthBatches = 7, int $recoverySeconds = 1800, float $decay = 0.04): ?array
    {
        $candidatesByKey = $this->byKey($candidateEvents);
        $prices = [];
        $volumes = [];
        $bases = [];
        foreach ($candidatesByKey as $key => $candidates) {
            $side = reset($candidates)['side'];
            $quote = $this->lookup($quotesByKey, $key);
            $volumes[$key] = $quote['volume'] ?? $quote['tradeVolume'] ?? 0;
            $bases[$key] = $quote['price'] ?? ($side === 'buy'
                ? ($quote['ask'] ?? $quote['purchasePrice'] ?? 0)
                : ($quote['bid'] ?? $quote['sellPrice'] ?? 0));
            if (! is_int($bases[$key]) || $bases[$key] < 1 || ! in_array($side, ['buy', 'sell'], true)) {
                return null;
            }
        }
        if (! $this->fitsWindow($existingEvents, $candidateEvents, $volumes, $depthBatches, $recoverySeconds)) {
            return null;
        }

        $existingByKey = $this->byKey($existingEvents);
        foreach ($candidatesByKey as $key => $candidates) {
            $retained = $existingByKey[$key] ?? [];
            $combined = [...array_values($retained), ...array_values($candidates)];
            foreach ($retained as $index => $event) {
                $tie = $event['tie_key'];
                $guard = $event['guard_price'] ?? $existingGuards[$key.'|'.$tie] ?? $existingGuards[$tie] ?? $existingGuards[$index] ?? null;
                if ($guard === null || ! empty($event['absorbed']) || $this->unresolved($event)) {
                    continue;
                }
                $affected = false;
                foreach ($candidates as $candidate) {
                    if ($this->unresolved($candidate) || ($this->earlier($candidate, $event['landing_at'], $tie)
                        && $candidate['landing_at'] > $event['landing_at'] - $recoverySeconds)) {
                        $affected = true;
                        break;
                    }
                }
                if ($affected) {
                    $price = $this->eventPrice($event, $combined, $bases[$key], $volumes[$key], $recoverySeconds, $decay);
                    if ($event['side'] === 'buy' ? $price > $guard : $price < $guard) {
                        return null;
                    }
                }
            }
            foreach ($candidates as $index => $candidate) {
                $prices[$index] = $this->eventPrice($candidate, $combined, $bases[$key], $volumes[$key], $recoverySeconds, $decay);
            }
        }

        return array_replace(array_intersect_key($candidateEvents, $prices), $prices);
    }

    public function recordObservation(Agent $agent, string $market, string $good, int $observedVersion): int
    {
        return DB::transaction(function () use ($agent, $market, $good, $observedVersion) {
            Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            $identity = $this->observationKey($agent, $market, $good);
            $observations = DB::table('observation_generations')->where($identity);
            if ($observedVersion <= (int) $observations->value('generation')) {
                return 0;
            }
            $now = CarbonImmutable::now();
            DB::table('observation_generations')->updateOrInsert($identity, ['generation' => $observedVersion, 'updated_at' => $now]);

            return $this->events($agent)->where(['market' => $market, 'good' => $good, 'absorbed' => false])
                ->whereIn('state', ['settled', 'confirmed'])->where('observed_version', '<=', $observedVersion)
                ->where('landing_at', '<=', $now->timestamp)->update(['absorbed' => true, 'updated_at' => $now]);
        }, 3);
    }

    /**
     * Reserve one already-validated set atomically. Call under the agent writer
     * lock with the book snapshot used by priceAndValidate.
     *
     * @return array<int|string, int> Reservation IDs keyed by the supplied order indexes.
     */
    public function reserveOrders(Agent $agent, array $orders): array
    {
        return DB::transaction(function () use ($agent, $orders) {
            Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            $ids = [];
            $now = CarbonImmutable::now();
            foreach ($orders as $index => $order) {
                if (! in_array($order['side'], ['buy', 'sell'], true) || $order['units'] < 1) {
                    throw new InvalidArgumentException('A corridor order must reserve positive units on the buy or sell side.');
                }
                $owner = [
                    'owner_type' => $order['owner_type'], 'owner_id' => (string) $order['owner_id'],
                    'leg_index' => $order['leg_index'] ?? 0, 'batch_index' => $order['batch_index'] ?? 0,
                    'side' => $order['side'],
                ];
                $values = [
                    'market' => $order['market'], 'good' => $order['good'], 'units' => $order['units'],
                    'landing_at' => $order['landing_at'], 'tie_key' => $order['tie_key'],
                    'ship_action_id' => $order['ship_action_id'] ?? null, 'guard_price' => $order['guard_price'] ?? null,
                ];
                $existing = $this->events($agent)->where($owner)->whereIn('state', self::ACTIVE_STATES)->first();
                $actionId = $values['ship_action_id'];
                if ($actionId !== null && $this->events($agent)->where('ship_action_id', $actionId)
                    ->when($existing, fn (Builder $query) => $query->where('id', '!=', $existing->id))->exists()) {
                    throw new GameException('The action already owns a corridor reservation.');
                }
                if ($existing) {
                    $saved = array_intersect_key((array) $existing, $values);
                    $saved['ship_action_id'] = $actionId ?? $saved['ship_action_id'];
                    $values['ship_action_id'] ??= $existing->ship_action_id;
                    if ($saved != $values) {
                        throw new GameException('The existing corridor reservation differs from the order.');
                    }
                    if ($actionId !== null && $existing->ship_action_id === null && $existing->state === 'reserved') {
                        $this->events($agent)->where('id', $existing->id)->where('state', 'reserved')->whereNull('ship_action_id')
                            ->update(['ship_action_id' => $actionId, 'updated_at' => $now]);
                    } elseif ($actionId !== null && $actionId !== $existing->ship_action_id) {
                        throw new GameException('The corridor reservation belongs to another action or has already settled.');
                    }
                    $ids[$index] = (int) $existing->id;

                    continue;
                }
                $ids[$index] = (int) DB::table('corridor_book_events')->insertGetId($this->identity($agent) + $owner + $values + [
                    'state' => 'reserved', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            return $ids;
        }, 3);
    }

    public function markSending(Agent $agent, int $actionId): void
    {
        $this->events($agent)->where(['ship_action_id' => $actionId, 'state' => 'reserved'])
            ->update(['state' => 'sending', 'updated_at' => now()]);
    }

    public function settleReceipt(Agent $agent, int $actionId, int $actualUnits, int $actualLandingAt, ?int $observedVersion = null): void
    {
        $this->settleAction($agent, $actionId, $actualUnits, $actualLandingAt, $observedVersion);
    }

    public function acknowledgeAction(Agent $agent, int $actionId, ?int $actualUnits, int $landingAt): void
    {
        $this->settleAction($agent, $actionId, $actualUnits, $landingAt, acknowledged: true);
    }

    private function settleAction(Agent $agent, int $actionId, ?int $actualUnits, int $landingAt, ?int $observedVersion = null, bool $acknowledged = false): void
    {
        if ($actualUnits !== null && $actualUnits < 0) {
            throw new InvalidArgumentException('A settled corridor order cannot have negative units.');
        }
        DB::transaction(function () use ($agent, $actionId, $actualUnits, $landingAt, $observedVersion, $acknowledged) {
            Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            $query = $this->events($agent)->where('ship_action_id', $actionId)->whereIn('state', ['reserved', 'sending', 'uncertain']);
            $event = $query->first();
            if (! $event) {
                return;
            }
            if ($acknowledged && $actualUnits === 0) {
                $query->update(['state' => 'released', 'updated_at' => now()]);

                return;
            }
            $watermark = DB::table('observation_generations')->where($this->observationKey($agent, $event->market, $event->good))->first();
            $observedAt = $watermark ? CarbonImmutable::parse($watermark->updated_at)->timestamp : null;
            if ($acknowledged) {
                $absorbed = $observedAt !== null && $observedAt > $landingAt;
                $observedVersion = (int) ($watermark->generation ?? 0) + ($absorbed ? 0 : 1);
            } else {
                $absorbed = $observedVersion !== null && $observedAt !== null
                    && $observedVersion <= $watermark->generation && $landingAt <= $observedAt;
            }
            $query->update([
                'state' => 'settled', 'units' => $actualUnits ?? $event->units, 'landing_at' => $landingAt,
                'observed_version' => $observedVersion, 'absorbed' => $absorbed, 'updated_at' => now(),
            ]);
        }, 3);
    }

    public function releaseOwner(Agent $agent, string $ownerType, string $ownerId, ?int $legIndex = null, bool $keepUnresolved = false): int
    {
        return $this->events($agent)->where(['owner_type' => $ownerType, 'owner_id' => $ownerId, 'state' => 'reserved'])
            ->when($legIndex !== null, fn (Builder $query) => $query->where('leg_index', $legIndex))
            ->when($keepUnresolved, fn (Builder $query) => $query->whereNotExists(ShipAction::whereColumn('ship_actions.id', 'corridor_book_events.ship_action_id')
                ->whereIn('state', ['pending', 'sending', 'uncertain'])->selectRaw('1')->toBase()))
            ->update(['state' => 'released', 'updated_at' => now()]);
    }

    public function activeEvents(Agent $agent, ?array $keys = null): array
    {
        $recoverySeconds = (int) config('fleet.saturation.corridor.recovery_seconds', 1800);
        $this->syncLegacyState($agent, $recoverySeconds);
        $cutoff = CarbonImmutable::now()->timestamp - $recoverySeconds;
        $query = $this->events($agent)->whereIn('state', self::ACTIVE_STATES)
            ->where(fn (Builder $rows) => $rows->whereNotIn('state', ['settled', 'confirmed'])->orWhere('landing_at', '>', $cutoff));
        if ($keys !== null) {
            $query->where(function (Builder $rows) use ($keys) {
                $rows->whereRaw('1 = 0');
                foreach ($keys as $key) {
                    [$market, $good, $side] = is_array($key) ? array_values($key) : explode('|', $key, 3);
                    $rows->orWhere(fn (Builder $match) => $match->where('market', $market)->where('good', $good)->where('side', $side));
                }
            });
        }

        return $query->orderBy('landing_at')->orderBy('tie_key')->orderBy('id')->get()->map(fn (object $row) => (array) $row)->all();
    }

    public function syncLegacyState(Agent $agent, int $recoverySeconds = 1800): int
    {
        return DB::transaction(function () use ($agent, $recoverySeconds) {
            Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now();

            return $this->syncLegacyReceipts($agent, $now, $recoverySeconds) + $this->syncLegacyRuns($agent, $now);
        }, 3);
    }

    private function syncLegacyReceipts(Agent $agent, CarbonImmutable $now, int $recoverySeconds): int
    {
        $identity = $this->identity($agent);
        $cutoff = $now->subSeconds($recoverySeconds);
        $booked = $this->events($agent)->whereNotNull('ship_action_id')
            ->where(fn (Builder $rows) => $rows->where('owner_type', '!=', 'legacy_receipt')->orWhere('absorbed', true))
            ->select('ship_action_id');
        $receipts = ShipAction::where($identity)->whereIn('command', ['buy', 'sell'])->where('state', ActionState::Confirmed)
            ->where('settled_at', '>', $cutoff)->whereNotIn('id', $booked)->get();
        if ($receipts->isEmpty()) {
            return 0;
        }
        $markets = GameObservation::where($identity)->where('kind', 'market')
            ->whereIn('symbol', $receipts->pluck('before_state.ship.nav.waypointSymbol')->filter()->unique())->get()->keyBy('symbol');
        $changes = 0;
        foreach ($receipts as $receipt) {
            $market = $receipt->before_state['ship']['nav']['waypointSymbol'] ?? null;
            $good = $receipt->arguments['symbol'] ?? null;
            $units = $receipt->outcome['units'] ?? 0;
            $landed = isset($receipt->outcome['response_received_at']) ? CarbonImmutable::parse($receipt->outcome['response_received_at']) : $receipt->settled_at;
            if (! $market || ! $good || ! is_int($units) || $units < 1 || $landed->lte($cutoff)) {
                continue;
            }
            $quote = $markets->get($market);
            $absorbed = $quote && $quote->observed_at->gt($landed) && in_array($good, array_column($quote->data['tradeGoods'] ?? [], 'symbol'), true);
            $existing = $this->events($agent)->where('ship_action_id', $receipt->id);
            if ($existing->exists()) {
                if ($absorbed) {
                    $changes += $existing->update(['absorbed' => true, 'observed_version' => $quote->data['observation_version'] ?? null, 'updated_at' => $now]);
                }

                continue;
            }
            DB::table('corridor_book_events')->insert($identity + [
                'owner_type' => 'legacy_receipt', 'owner_id' => 'action:'.$receipt->id, 'ship_action_id' => $receipt->id,
                'side' => $receipt->command, 'market' => $market, 'good' => $good, 'units' => $units, 'landing_at' => $landed->timestamp,
                'tie_key' => $receipt->ship_symbol.'|action:'.$receipt->id, 'state' => 'confirmed', 'absorbed' => $absorbed,
                'observed_version' => $absorbed ? ($quote->data['observation_version'] ?? null) : null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $changes++;
        }

        return $changes;
    }

    private function syncLegacyRuns(Agent $agent, CarbonImmutable $now): int
    {
        $identity = $this->identity($agent);
        $runs = AutomationRun::where($identity)->where('strategy', 'trade')->where('plan_version', '!=', 8)
            ->where(fn ($rows) => $rows->where('active_agent_id', $agent->id)
                ->orWhere(fn ($previews) => $previews->where('state', RunState::Preview)->where('expires_at', '>', $now)))->get();
        $modernOwners = $this->events($agent)->where('owner_type', 'run')
            ->whereIn('owner_id', array_map(strval(...), $runs->modelKeys()))->distinct()->pluck('owner_id')->flip();
        $changes = 0;
        $kept = [];
        foreach ($runs as $run) {
            $plan = $run->plan;
            $good = $plan['symbol'] ?? null;
            if (! $good || $modernOwners->has($run->id)) {
                continue;
            }
            $phase = $run->totals['phase'] ?? 'buy';
            $bought = $run->totals['purchased'][$good] ?? 0;
            $sold = $run->totals['sold'][$good] ?? 0;
            $approved = $plan['units'] ?? 0;
            $landingAt = $now->max($run->next_action_at ?? $now)->timestamp;
            $deliverySeconds = $plan['route']['outbound']['estimated_seconds'] ?? 0;
            $sides = [
                'buy' => [$plan['site'] ?? null, $phase === 'buy' ? $approved - $bought : 0, $landingAt],
                'sell' => [$plan['market'] ?? null, ($phase === 'buy' ? max($approved, $bought) : $bought) - $sold,
                    $landingAt + ($phase === 'buy' ? $deliverySeconds : 0)],
            ];
            foreach ($sides as $side => [$market, $units, $at]) {
                if (! $market || $units < 1) {
                    continue;
                }
                $owner = ['owner_type' => 'legacy_run', 'owner_id' => 'run:'.$run->id, 'side' => $side];
                $values = ['market' => $market, 'good' => $good, 'units' => $units, 'landing_at' => $at, 'state' => 'reserved'];
                $query = $this->events($agent)->where($owner);
                $event = $query->first();
                if ($event) {
                    $kept[] = $event->id;
                    if (array_intersect_key((array) $event, $values) != $values) {
                        $changes += $query->update($values + ['updated_at' => $now]);
                    }

                    continue;
                }
                $kept[] = DB::table('corridor_book_events')->insertGetId($identity + $owner + $values + [
                    'tie_key' => $run->ship_symbol.'|run:'.$run->id.'|'.$side, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $changes++;
            }
        }

        return $changes + $this->events($agent)->where('owner_type', 'legacy_run')->where('state', 'reserved')
            ->whereNotIn('id', $kept)->update(['state' => 'released', 'updated_at' => $now]);
    }

    /**
     * Each matched sample carries delay_seconds and the observed residual weight in
     * permille (observed_weight_permille). Ignore missing or invalid measurements.
     */
    public function refitRecoveryWindow(array $settledSamples): int
    {
        $samples = array_filter($settledSamples, fn (array $sample) => isset($sample['delay_seconds'], $sample['observed_weight_permille'])
            && $sample['delay_seconds'] >= 0 && $sample['observed_weight_permille'] >= 0 && $sample['observed_weight_permille'] <= 1000);
        if (count($samples) < 3) {
            return 1800;
        }

        $best = 1800;
        $lowestError = INF;
        for ($duration = 300; $duration <= 7200; $duration += 300) {
            $error = 0;
            foreach ($samples as $sample) {
                $prediction = $this->weight((int) $sample['delay_seconds'], $duration);
                $error += ($prediction - $sample['observed_weight_permille']) ** 2;
            }
            if ($error < $lowestError) {
                $best = $duration;
                $lowestError = $error;
            }
        }

        return $best;
    }

    private function decayFactor(int $offset, float $decay): BigDecimal
    {
        if ($offset < 0 || ! is_finite($decay) || $decay < 0 || $decay >= 1) {
            throw new InvalidArgumentException('Decay must be in [0, 1) and the offset must be nonnegative.');
        }

        return BigDecimal::of(1)->minus((string) $decay)->power($offset);
    }

    private function ceilDivide(int $numerator, int $denominator): int
    {
        return $numerator === 0 ? 0 : intdiv($numerator - 1, $denominator) + 1;
    }

    private function weight(int $elapsed, int $recoverySeconds): int
    {
        return $elapsed >= $recoverySeconds ? 0 : 1000 - intdiv(1000 * $elapsed, $recoverySeconds);
    }

    private function fitsKey(array $retained, array $candidates, int $capacity, int $recoverySeconds): bool
    {
        $units = 0;
        $affected = 0;
        $landings = [];
        foreach ([$retained, $candidates] as $candidate => $events) {
            foreach ($events as $event) {
                if ($this->unresolved($event)) {
                    $units += $event['units'];
                    $affected += $candidate;
                } else {
                    $landings[] = [$event['landing_at'], $event['tie_key'], $event['units'], $candidate];
                }
            }
        }
        if ($affected > 0 && $units > $capacity) {
            return false;
        }

        usort($landings, fn (array $a, array $b) => ($a[0] <=> $b[0]) ?: strcmp($a[1], $b[1]));
        $first = 0;
        foreach ($landings as [$landingAt, , $landedUnits, $candidate]) {
            while ($landings[$first][0] <= $landingAt - $recoverySeconds) {
                $units -= $landings[$first][2];
                $affected -= $landings[$first][3];
                $first++;
            }
            $units += $landedUnits;
            $affected += $candidate;
            if ($affected > 0 && $units > $capacity) {
                return false;
            }
        }

        return true;
    }

    private function earlier(array $event, int $landingAt, string $tieKey): bool
    {
        return $event['landing_at'] < $landingAt
            || ($event['landing_at'] === $landingAt && strcmp($event['tie_key'], $tieKey) < 0);
    }

    private function eventPrice(array $event, array $events, int $base, int $volume, int $recoverySeconds, float $decay): int
    {
        $offset = $this->decayOffset($events, $event['market'], $event['good'], $event['side'], $event['landing_at'], $event['tie_key'], $volume, $recoverySeconds);

        return $event['side'] === 'buy' ? $this->askAt($base, $offset, $decay) : $this->bidAt($base, $offset, $decay);
    }

    private function key(array $event): string
    {
        return $event['market'].'|'.$event['good'].'|'.$event['side'];
    }

    private function lookup(array $values, string $key): mixed
    {
        [$market, $good, $side] = explode('|', $key, 3);

        return $values[$key] ?? $values[$market][$good][$side] ?? $values[$market.'|'.$good] ?? $values[$market][$good] ?? null;
    }

    private function byKey(array $events): array
    {
        $byKey = [];
        foreach ($events as $index => $event) {
            if ($this->active($event)) {
                $byKey[$this->key($event)][$index] = $event;
            }
        }

        return $byKey;
    }

    private function active(array $event): bool
    {
        return in_array($event['state'] ?? 'reserved', self::ACTIVE_STATES, true);
    }

    private function unresolved(array $event): bool
    {
        return ! empty($event['unresolved']) || in_array($event['state'] ?? null, ['sending', 'uncertain'], true);
    }

    private function observationKey(Agent $agent, string $market, string $good): array
    {
        return $this->identity($agent) + ['kind' => 'corridor_observation', 'symbol' => $market.'|'.$good];
    }

    private function identity(Agent $agent): array
    {
        return ['agent_id' => $agent->id, 'reset_id' => $agent->reset_id];
    }

    private function events(Agent $agent): Builder
    {
        return DB::table('corridor_book_events')->where($this->identity($agent));
    }
}
