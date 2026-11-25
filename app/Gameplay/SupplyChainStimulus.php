<?php

declare(strict_types=1);

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\GameObservation;
use App\Models\ObservationHistory;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SupplyChainStimulus
{
    private const ACTIVITY_WEIGHTS = ['RESTRICTED' => 1000, 'WEAK' => 500, 'GROWING' => 250];

    private const SUPPLY_WEIGHTS = ['SCARCE' => 1000, 'LIMITED' => 500, 'MODERATE' => 250];

    private const SUPPLY_ORDER = ['SCARCE', 'LIMITED', 'MODERATE', 'HIGH', 'ABUNDANT'];

    private const ACTIVITY_ORDER = ['RESTRICTED', 'WEAK', 'GROWING', 'STRONG'];

    private const MEASURED_STATES = ['effective', 'ineffective'];

    public function bonus(array $benefitingExports, int $betaPermille, int $hoursToReset, int $batches, int $availableMarketInputCap = 10000, int $bonusCap = 10000): int
    {
        $hours = min(6, max(0, $hoursToReset - 24));
        $cap = max(0, min($bonusCap, $availableMarketInputCap));
        if ($hours === 0 || $cap === 0 || $batches <= 0 || $betaPermille <= 0) {
            return 0;
        }

        $weighted = BigInteger::zero();
        foreach ($benefitingExports as $export) {
            $activity = self::ACTIVITY_WEIGHTS[$export['activity'] ?? ''] ?? 0;
            $supply = self::SUPPLY_WEIGHTS[$export['input_supply'] ?? $export['supply'] ?? ''] ?? 0;
            $rate = (int) ($export['v_hat'] ?? $export['rate'] ?? 0);
            $weighted = $weighted->plus(BigInteger::of($rate)->multipliedBy($activity * $supply));
        }
        $raw = $weighted->multipliedBy($betaPermille)->multipliedBy($hours)->multipliedBy($batches)
            ->dividedBy(1_000_000_000, RoundingMode::Floor);

        if ($raw->isNegative()) {
            return 0;
        }

        return $raw->isGreaterThan($cap) ? $cap : $raw->toInt();
    }

    public function trailingSixHourRate(Agent $agent, string $market, string $exportGood, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $cutoff = $now->subHours(6);
        $loads = CampaignEvent::whereIn('campaign_episode_id', CampaignEpisode::where('agent_id', $agent->id)
            ->where('reset_id', $agent->reset_id)->select('id'))
            ->where('kind', 'load_result')->where('created_at', '>', $cutoff)
            ->where('data->source', $market)->where('data->good', $exportGood)->get();

        $net = 0;
        foreach ($loads as $load) {
            $data = $load->data;
            if (! is_int($data['net'] ?? null) || ($data['proof_eligible'] ?? true) === false || ($data['settled'] ?? true) === false) {
                continue;
            }
            $finished = CarbonImmutable::parse($data['finished_at'] ?? $load->created_at);
            if ($finished->gt($cutoff) && $finished->lte($now)) {
                $net += $data['net'];
            }
        }

        return intdiv($net, 6) - (int) ($net % 6 < 0);
    }

    public function canAdmit(int $lossAuth, int $reservedDownside, int $realizedLoss, int $episodeBudget, int $cycleLossCap, int $bonus, int $outstandingBonus, int $pendingDeliveries, bool $windDown, int $bonusCap = 10000): bool
    {
        $used = $reservedDownside + $realizedLoss;

        return ! $windDown && $pendingDeliveries < 3 && $lossAuth >= 0 && $lossAuth <= $cycleLossCap
            && $used < $episodeBudget && $lossAuth <= $episodeBudget - $used
            && $bonus >= 0 && $bonus <= $bonusCap - $outstandingBonus;
    }

    public function episodeBudget(int $episodeStartCredits, int $cap = 50000): int
    {
        return max(0, min($cap, intdiv($episodeStartCredits, 100)));
    }

    public function snapshot(GameObservation|ObservationHistory|null $observation, array $goods): array
    {
        $exports = [];
        foreach ($observation?->data['tradeGoods'] ?? [] as $row) {
            if (in_array($row['symbol'], $goods, true) && ($row['type'] ?? null) === 'EXPORT') {
                $exports[$row['symbol']] = [
                    'activity' => $row['activity'] ?? null, 'supply' => $row['supply'] ?? null,
                    'tradeVolume' => $row['tradeVolume'] ?? null, 'ask' => $row['purchasePrice'] ?? null,
                ];
            }
        }

        return [
            'exports' => $exports, 'observed_at' => $observation?->observed_at->toIso8601String(),
            'invalidated' => $observation === null || isset($observation->data['invalidated_by_action']),
        ];
    }

    /**
     * The snapshot carries the affected export metrics, optionally under exports
     * keyed by good. Each metric row uses activity, supply, tradeVolume and ask.
     */
    public function reserve(Agent $agent, CampaignEpisode $episode, AutomationRun $run, string $market, string $inputGood, int $lossAuth, int $bonus, int $cycleLossCap, int $episodeLossCap, int $bonusCap, bool $windDown, array $baselineSnapshot = []): ?int
    {
        if ((int) $episode->agent_id !== (int) $agent->id || $episode->reset_id !== $agent->reset_id
            || (int) $run->agent_id !== (int) $agent->id || $run->reset_id !== $agent->reset_id
            || (int) $run->campaign_episode_id !== (int) $episode->id) {
            return null;
        }

        return DB::transaction(function () use ($agent, $episode, $run, $market, $inputGood, $lossAuth, $bonus, $cycleLossCap, $episodeLossCap, $bonusCap, $windDown, $baselineSnapshot) {
            Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            $key = ['market' => $market, 'input_good' => $inputGood];
            $episodeEntries = $this->entries($agent)->where('campaign_episode_id', $episode->id);
            $marketEntries = $this->entries($agent)->where($key);
            $existing = (clone $episodeEntries)->where($key)->where('automation_run_id', $run->id)->where('state', '!=', 'released')->first();
            if ($existing) {
                return $existing->state === 'reserved' && $existing->downside_reserved >= $lossAuth && $existing->bonus_reserved >= $bonus
                    ? (int) $existing->id : null;
            }
            if ($this->isBetaDisabled($agent, (int) $episode->id) || (new StateChain)->hasUnresolvedCreditAction($agent)) {
                return null;
            }

            $now = CarbonImmutable::now();
            $recovery = (int) config('fleet.saturation.corridor.recovery_seconds', 1800);
            $reserved = (int) (clone $episodeEntries)->where('state', 'reserved')->sum('downside_reserved');
            $realized = (int) (clone $episodeEntries)->sum('realized_loss');
            $pending = (int) (clone $marketEntries)->whereIn('state', ['reserved', 'delivered'])->count();
            $outstanding = (int) (clone $marketEntries)->where(fn (Builder $query) => $query->whereIn('state', ['reserved', 'delivered'])
                ->orWhere(fn (Builder $measured) => $measured->whereIn('state', self::MEASURED_STATES)
                    ->where('delivered_at', '>', $now->subSeconds($recovery))))->sum('bonus_reserved');
            $overdue = (clone $marketEntries)->where('state', 'delivered')->where('delivered_at', '<=', $now->subHours(2))->exists();
            $budget = $this->episodeBudget($episode->start_credits, $episodeLossCap);

            if ($overdue || ! $this->canAdmit($lossAuth, $reserved, $realized, $budget, $cycleLossCap, $bonus, $outstanding, $pending, $windDown, $bonusCap)) {
                return null;
            }

            return (int) DB::table('stimulus_entries')->insertGetId($this->identity($agent) + $key + [
                'campaign_episode_id' => $episode->id, 'automation_run_id' => $run->id,
                'downside_reserved' => $lossAuth, 'bonus_reserved' => $bonus, 'state' => 'reserved',
                'baseline_snapshot' => json_encode($baselineSnapshot, JSON_THROW_ON_ERROR),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }, 3);
    }

    public function recordDelivery(int $entryId, int $realizedLoss, ?CarbonImmutable $deliveredAt = null): void
    {
        $now = CarbonImmutable::now();
        DB::table('stimulus_entries')->where(['id' => $entryId, 'state' => 'reserved'])->update([
            'state' => 'delivered', 'downside_reserved' => 0, 'realized_loss' => max(0, $realizedLoss),
            'delivered_at' => $deliveredAt ?? $now, 'updated_at' => $now,
        ]);
    }

    public function evaluateEffectiveness(int $entryId, array $postSnapshot): string
    {
        return DB::transaction(function () use ($entryId, $postSnapshot) {
            $entries = DB::table('stimulus_entries')->where('id', $entryId);
            $entry = (clone $entries)->lockForUpdate()->first();
            if (! $entry || $entry->state !== 'delivered') {
                return $entry->state ?? 'missing';
            }

            $now = CarbonImmutable::now();
            $delivered = CarbonImmutable::parse($entry->delivered_at);
            $observation = $postSnapshot['observed_at'] ?? $now;
            $observedAt = is_int($observation) ? CarbonImmutable::createFromTimestampUTC($observation) : CarbonImmutable::parse($observation);
            $state = null;
            $cutoff = $delivered->addHours(2);
            if ($observedAt->gte($delivered) && $observedAt->lte($now) && empty($postSnapshot['invalidated'])) {
                $baseline = json_decode($entry->baseline_snapshot ?? '[]', true, flags: JSON_THROW_ON_ERROR);
                $state = $this->measure($baseline, $postSnapshot);
                if ($state !== null && $observedAt->gt($cutoff)) {
                    $state = 'ineffective';
                }
            }
            if ($state === null) {
                return $now->lt($cutoff) ? 'pending' : 'awaiting_evidence';
            }
            $entries->where('state', 'delivered')->update(['state' => $state, 'measured_at' => $observedAt, 'updated_at' => $now]);

            return $state;
        }, 3);
    }

    public function isBetaDisabled(Agent $agent, int $episodeId): bool
    {
        return $this->entries($agent)->where(['campaign_episode_id' => $episodeId, 'state' => 'ineffective'])->count() >= 3;
    }

    public function betaPermille(Agent $agent, int $episodeId, int $prior): int
    {
        $counts = $this->entries($agent)->where('campaign_episode_id', $episodeId)->whereIn('state', self::MEASURED_STATES)
            ->selectRaw('state, count(*) as samples')->groupBy('state')->pluck('samples', 'state');
        $ineffective = (int) ($counts['ineffective'] ?? 0);
        $effective = (int) ($counts['effective'] ?? 0);

        // One prior observation keeps the estimate usable until the explicit ineffective-delivery gate trips.
        return $ineffective >= 3 ? 0 : intdiv($prior * ($effective + 1), $effective + $ineffective + 1);
    }

    public function release(int $entryId): void
    {
        DB::table('stimulus_entries')->where(['id' => $entryId, 'state' => 'reserved'])->update([
            'state' => 'released', 'downside_reserved' => 0, 'bonus_reserved' => 0, 'updated_at' => now(),
        ]);
    }

    private function measure(array $baseline, array $after): ?string
    {
        $beforeRows = $baseline['exports'] ?? [$baseline];
        $afterRows = $after['exports'] ?? [$after];
        $matched = false;
        $complete = true;
        foreach ($beforeRows as $good => $before) {
            $post = $afterRows[$good] ?? null;
            if (! is_array($post)) {
                $complete = false;

                continue;
            }
            foreach (['activity' => self::ACTIVITY_ORDER, 'supply' => self::SUPPLY_ORDER] as $field => $order) {
                $initial = array_search($before[$field] ?? null, $order, true);
                $observed = array_search($post[$field] ?? null, $order, true);
                if ($initial !== false && $observed !== false) {
                    $matched = true;
                    if ($observed > $initial) {
                        return 'effective';
                    }
                }
                $complete = $complete && ($initial === false || $observed !== false);
            }
            $oldVolume = $before['tradeVolume'] ?? $before['volume'] ?? null;
            $newVolume = $post['tradeVolume'] ?? $post['volume'] ?? null;
            if (is_int($oldVolume) && $oldVolume > 0) {
                if (is_int($newVolume) && $newVolume > 0) {
                    $matched = true;
                    if ($newVolume > $oldVolume) {
                        return 'effective';
                    }
                } else {
                    $complete = false;
                }
            }
            $oldAsk = $before['ask'] ?? $before['purchasePrice'] ?? null;
            $newAsk = $post['ask'] ?? $post['purchasePrice'] ?? null;
            if (is_int($oldAsk) && $oldAsk > 0) {
                if (is_int($newAsk) && $newAsk > 0) {
                    $matched = true;
                    if ($newAsk * 100 <= $oldAsk * 97) {
                        return 'effective';
                    }
                } else {
                    $complete = false;
                }
            }
        }

        return $matched && $complete ? 'ineffective' : null;
    }

    private function identity(Agent $agent): array
    {
        return ['agent_id' => $agent->id, 'reset_id' => $agent->reset_id];
    }

    private function entries(Agent $agent): Builder
    {
        return DB::table('stimulus_entries')->where($this->identity($agent));
    }
}
