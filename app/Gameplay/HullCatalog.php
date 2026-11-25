<?php

declare(strict_types=1);

namespace App\Gameplay;

use App\Enums\ActionState;
use App\Models\Agent;
use App\Models\GameObservation;
use App\Models\ObservationHistory;
use App\Models\ShipAction;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

final class HullCatalog
{
    private const array HULLS = [
        'SHIP_LIGHT_HAULER' => ['class' => 'trader', 'frame' => 'FRAME_LIGHT_FREIGHTER', 'hard_cap' => 600000, 'cargo' => 80, 'fuel' => 600, 'speed' => 15, 'inflation_cap_pct' => 15],
        'SHIP_LIGHT_SHUTTLE' => ['class' => 'trader', 'frame' => 'FRAME_SHUTTLE', 'hard_cap' => 150000, 'cargo' => 40, 'fuel' => 300, 'speed' => 15, 'inflation_cap_pct' => 15],
        'SHIP_MINING_DRONE' => ['class' => 'miner', 'frame' => 'FRAME_MINER', 'hard_cap' => 60000, 'cargo' => 15, 'fuel' => 80, 'speed' => 9, 'inflation_cap_pct' => 25],
        'SHIP_SIPHON_DRONE' => ['class' => 'siphon', 'frame' => 'FRAME_DRONE', 'hard_cap' => 60000, 'cargo' => 15, 'fuel' => 80, 'speed' => 9, 'inflation_cap_pct' => 25],
        'SHIP_SURVEYOR' => ['class' => 'surveyor', 'frame' => 'FRAME_DRONE', 'hard_cap' => 50000, 'cargo' => 0, 'fuel' => 80, 'speed' => 9, 'inflation_cap_pct' => 25],
        'SHIP_PROBE' => ['class' => 'probe', 'frame' => 'FRAME_PROBE', 'hard_cap' => 50000, 'cargo' => 0, 'fuel' => 0, 'speed' => 9, 'inflation_cap_pct' => 25],
    ];

    public function allTypes(): array
    {
        return array_keys(self::HULLS);
    }

    public function economicTypes(): array
    {
        return array_keys(array_filter(self::HULLS, fn (array $hull) => $hull['class'] !== 'probe'));
    }

    public function traderTypes(): array
    {
        return array_keys(array_filter(self::HULLS, fn (array $hull) => $hull['class'] === 'trader'));
    }

    public function spec(string $type): ?array
    {
        return self::HULLS[$type] ?? null;
    }

    public function matchesShip(array $ship, string $type): bool
    {
        $hull = $this->spec($type);
        if ($hull === null) {
            return false;
        }
        foreach ([$ship['type'] ?? null, $ship['registration']['type'] ?? null] as $declared) {
            if (is_string($declared) && str_starts_with($declared, 'SHIP_')) {
                return $declared === $type;
            }
        }
        $frame = $ship['frame']['symbol'] ?? $ship['frame'] ?? null;
        if ($frame !== $hull['frame']) {
            return false;
        }
        if ($frame !== 'FRAME_DRONE') {
            return true;
        }

        $siphon = $surveyor = false;
        foreach ($ship['mounts'] ?? [] as $mount) {
            $symbol = is_string($mount) ? $mount : ($mount['symbol'] ?? '');
            $siphon = $siphon || str_starts_with($symbol, 'MOUNT_GAS_SIPHON_');
            $surveyor = $surveyor || str_starts_with($symbol, 'MOUNT_SURVEYOR_');
        }

        return $type === 'SHIP_SIPHON_DRONE' ? $siphon && ! $surveyor : $surveyor && ! $siphon;
    }

    public function capitalWeight(string $typeOrFrame, int $workingCapitalPerTrader = 150000): int
    {
        return match ($typeOrFrame) {
            'command', 'FRAME_FRIGATE', 'hauler', 'SHIP_LIGHT_HAULER', 'FRAME_LIGHT_FREIGHTER' => $workingCapitalPerTrader,
            'shuttle', 'SHIP_LIGHT_SHUTTLE', 'FRAME_SHUTTLE' => intdiv($workingCapitalPerTrader, 2),
            default => 0,
        };
    }

    public function workingCapital(array $existingFramesOrTypes, ?string $incomingType = null, int $minWorkingCapital = 200000, int $workingCapitalPerTrader = 150000): int
    {
        $total = $incomingType === null ? 0 : $this->capitalWeight($incomingType, $workingCapitalPerTrader);
        foreach ($existingFramesOrTypes as $type) {
            $total += $this->capitalWeight($type, $workingCapitalPerTrader);
        }

        return max($minWorkingCapital, $total);
    }

    public function reserveParts(array $existingFramesOrTypes, ?string $incomingType = null, int $grantReserve = 0, int $commitments = 0, int $reconReturn = 0, int $minWorkingCapital = 200000, int $workingCapitalPerTrader = 150000): array
    {
        $workingCapital = $this->workingCapital($existingFramesOrTypes, $incomingType, $minWorkingCapital, $workingCapitalPerTrader);

        return [
            'grant' => $grantReserve,
            'working_capital' => $workingCapital,
            'commitments' => $commitments,
            'recon_return' => $reconReturn,
            'total' => $grantReserve + $workingCapital + $commitments + $reconReturn,
        ];
    }

    public function effectiveOffer(int $offer, bool $anotherPlannedWithinWindow, int $inflationPriorPct = 3): int
    {
        return $offer + ($anotherPlannedWithinWindow ? intdiv($offer * $inflationPriorPct, 100) : 0);
    }

    public function normalPriceCeiling(int $pMin, string $type, int $hardCap, ?int $inflationCapPct = null): int
    {
        $capPct = $inflationCapPct ?? $this->spec($type)['inflation_cap_pct'] ?? 0;

        return min($hardCap, intdiv($pMin * (100 + $capPct), 100));
    }

    /**
     * @return array{p_min: ?int, offer: ?array, price: ?int, observed_at: ?int, age: ?int, watermark: ?int, invalidated: bool, fresh: bool}
     */
    public function shipyardOfferEvidence(Agent $agent, string $yard, string $type, int $maxAgeSeconds = 1800): array
    {
        $pMin = $offer = $observed = null;
        $watermark = $this->purchaseWatermark($agent, $yard, $type);
        $bootstrap = app(CampaignProbeBootstrap::class);
        foreach ([ObservationHistory::class, GameObservation::class] as $model) {
            $observations = $model::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                ->where('kind', 'shipyard')->where('symbol', $yard)->orderBy('observed_at')->orderBy('id')->cursor();
            foreach ($observations as $observation) {
                foreach ($observation->data['transactions'] ?? [] as $transaction) {
                    if (($transaction['agentSymbol'] ?? null) === $agent->name && ($transaction['waypointSymbol'] ?? null) === $yard
                        && ($transaction['shipType'] ?? $transaction['shipSymbol'] ?? null) === $type) {
                        $timestamp = $this->timestamp($transaction['timestamp'] ?? null);
                        if ($timestamp !== null) {
                            $watermark = max($watermark ?? $timestamp, $timestamp);
                        }
                    }
                }
                if (! is_array($observation->data['ships'] ?? null)) {
                    continue;
                }
                $detail = $bootstrap->offer($observation->data, $type);
                $price = $detail['purchasePrice'] ?? null;
                if (is_int($price) && $price > 0) {
                    $pMin = min($pMin ?? $price, $price);
                } else {
                    $detail = null;
                }
                $timestamp = $observation->observed_at->getTimestamp();
                if ($observed === null || $timestamp >= $observed) {
                    $observed = $timestamp;
                    $offer = $detail;
                }
            }
        }

        $invalidated = $watermark !== null && ($observed === null || $observed <= $watermark);
        $age = $observed === null ? null : max(0, now()->getTimestamp() - $observed);
        $ageLimit = $type === 'SHIP_PROBE' ? min(900, $maxAgeSeconds) : $maxAgeSeconds;

        return [
            'p_min' => $pMin,
            'offer' => $offer,
            'price' => $offer['purchasePrice'] ?? null,
            'observed_at' => $observed,
            'age' => $age,
            'watermark' => $watermark,
            'invalidated' => $invalidated,
            'fresh' => $offer !== null && $age <= $ageLimit && ! $invalidated,
        ];
    }

    private function purchaseWatermark(Agent $agent, string $yard, string $type): ?int
    {
        $actions = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('command', 'buy_ship')->where('arguments->shipType', $type)
            ->where('arguments->waypointSymbol', $yard)
            ->whereIn('state', ['confirmed', 'acknowledged', 'uncertain', 'sending'])->cursor();
        $watermark = null;
        foreach ($actions as $action) {
            $outcome = $action->outcome ?? [];
            $delivery = $outcome['delivery_transaction'] ?? $outcome['bootstrap_delivery_transaction'] ?? null;
            $receipt = $outcome['response']['transaction'] ?? null;
            if (isset($outcome['confirmed_not_sent']) || ($action->state !== ActionState::Confirmed
                && ! $delivery && ! CampaignProbeBootstrap::deliveredShip($action)
                && (empty($outcome['response_validated']) || ! $receipt))) {
                continue;
            }
            $settled = $action->settled_at ?? $action->updated_at ?? $action->sent_at;
            $timestamp = $settled?->getTimestamp();
            if (isset($outcome['bootstrap_delivery_transaction']) && ! isset($outcome['delivery_attributed_at']) && $action->updated_at !== null) {
                $updated = $action->updated_at->getTimestamp();
                $timestamp = max($timestamp ?? $updated, $updated);
            }
            foreach ([$delivery['timestamp'] ?? null, $receipt['timestamp'] ?? null, $outcome['delivery_attributed_at'] ?? null, $outcome['response_received_at'] ?? null] as $time) {
                $receiptTime = $this->timestamp($time);
                if ($receiptTime !== null) {
                    $timestamp = max($timestamp ?? $receiptTime, $receiptTime);
                }
            }
            if ($timestamp !== null) {
                $watermark = max($watermark ?? $timestamp, $timestamp);
            }
        }

        return $watermark;
    }

    private function timestamp(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T/', $value)) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->getTimestamp();
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
