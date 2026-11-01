<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\GameObservation;

class SurveyPool
{
    public function __construct(private AgentSynchronizer $sync) {}

    public function save(Agent $agent, array $signed, string $observer): void
    {
        $existing = $this->find($agent, $signed['signature']);
        if ($existing && $existing->data['signed'] !== $signed) {
            throw new GameException('Survey signature returned with a different signed object.', uncertain: true);
        }
        $this->sync->observe($agent, 'survey', $signed['signature'], ['signed' => $signed, 'exhausted' => $existing?->data['exhausted'] ?? false,
            'unavailable_reason' => $existing?->data['unavailable_reason'] ?? null], 'survey', $observer);
    }

    public function usable(Agent $agent, string $signature, string $waypoint): array
    {
        $survey = $this->find($agent, $signature);
        if (! $survey || $survey->data['exhausted'] || ! empty($survey->data['unavailable_reason']) || now()->gte($survey->data['signed']['expiration']) || $survey->data['signed']['symbol'] !== $waypoint) {
            throw new GameException('Survey is unknown, expired, exhausted or at a different waypoint.');
        }

        return $survey->data['signed'];
    }

    public function invalidate(Agent $agent, string $signature, bool $exhausted): void
    {
        if ($survey = $this->find($agent, $signature)) {
            $survey->update(['data' => array_merge($survey->data, ['exhausted' => $exhausted, 'unavailable_reason' => $exhausted ? 'exhausted' : 'server_expired'])]);
        }
    }

    public function summary(array $signed): array
    {
        return ['deposit_counts' => array_count_values(array_column($signed['deposits'], 'symbol')),
            'expiration' => $signed['expiration'], 'size' => $signed['size'],
            'interpretation' => 'Counts describe the signed mix, not exact extraction probabilities or guaranteed uses.'];
    }

    public function assess(Agent $agent, array $signed, array $ship): array
    {
        $fuel = app(FuelPlanner::class);
        $listings = $fuel->listings($agent);
        $summary = $this->summary($signed);
        $values = [];
        foreach ($summary['deposit_counts'] as $symbol => $count) {
            $buyers = [];
            foreach (GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'market')->get() as $market) {
                if (! empty($market->data['invalidated_by_action']) || $market->observed_at->lt(now()->subSeconds(config('gameplay.snapshot_seconds')))) {
                    continue;
                }
                $bid = collect($market->data['tradeGoods'] ?? [])->firstWhere('symbol', $symbol);
                if (! $bid || $bid['sellPrice'] < 1) {
                    continue;
                }
                try {
                    $route = $fuel->leg($agent, $ship, $market->symbol, listings: $listings);
                    $buyers[] = ['market' => $market->symbol, 'sell_price' => $bid['sellPrice'], 'volume' => $bid['tradeVolume'], 'fuel_ceiling' => $route['refuel_ceiling'], 'quote_at' => $market->observed_at->toIso8601String()];
                } catch (GameException) {
                }
            }
            usort($buyers, fn ($a, $b) => $b['sell_price'] <=> $a['sell_price']);
            $values[$symbol] = ['deposit_entries' => $count, 'best_observed_buyer' => $buyers[0] ?? null];
        }
        $fullyPriced = $values !== [] && collect($values)->every(fn ($row) => $row['best_observed_buyer'] !== null);

        return $summary + ['resources' => $values, 'fully_priced' => $fullyPriced,
            'mix_price_index' => $fullyPriced ? array_sum(array_map(fn ($row) => $row['deposit_entries'] * $row['best_observed_buyer']['sell_price'], $values)) / count($signed['deposits']) : null,
            'index_meaning' => 'Price index over the entire signed deposit mix for comparison only. It is not an extraction probability, expected yield, net return or guaranteed survey uses. Travel, survey creation time, expiry and exhaustion remain separate constraints.'];
    }

    private function find(Agent $agent, string $signature): ?GameObservation
    {
        return GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'survey')->where('symbol', $signature)->first();
    }
}
