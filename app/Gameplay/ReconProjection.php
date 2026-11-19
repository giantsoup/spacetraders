<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\GameObservation;
use App\Models\ReconMission;
use App\Models\Ship;
use Carbon\CarbonImmutable;

class ReconProjection
{
    public function evaluate(Agent $agent, ReconMission $mission, string $system): array
    {
        $result = ['classification' => 'evidence_incomplete', 'evaluated_at' => now()->toIso8601String(), 'pairs' => [], 'qualifying' => [],
            'assumption' => 'assumed_full_tank_after_relocation', 'relocation_cost' => 'unknown', 'trader_jump_eligibility' => 'unknown',
            'label' => 'Provisional. Prices age. Trader jump eligibility and relocation cost unknown. C0 relocation not authorized.'];
        $episode = app(GateRecon::class)->episode($mission);
        $trader = $episode ? Ship::where('agent_id', $agent->id)->where('symbol', $episode->state['role_ships']['command'] ?? '')->first() : null;
        if (! $trader) {
            $result['reason'] = 'Open C0 inputs or pinned trader missing.';
            // An empty complete catalogue needs no trader projection.
            if ($this->noMarkets($mission, $system)) {
                $result['classification'] = 'no_markets';
            }

            return $result;
        }
        $catalogue = $mission->graph[$system]['catalogue'] ?? [];
        $visit = $mission->visits[$system] ?? [];
        $samples = $visit['markets'] ?? [];
        $markets = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'market')
            ->whereIn('symbol', array_keys($samples))->get()->filter(fn ($quote) => str_starts_with($quote->symbol, $system.'-'))->keyBy('symbol');
        $body = $episode->grant()->revision()->body;
        $rules = $body['trade'];
        $seconds = $episode->grant()->limits['run_max_minutes'] * 60;
        $hypothetical = $trader->snapshot;
        $hypothetical['nav']['systemSymbol'] = $system;
        $hypothetical['nav']['waypointSymbol'] = $mission->graph[$system]['gate'];
        $hypothetical['nav']['status'] = 'IN_ORBIT';
        $hypothetical['cargo']['inventory'] = [];
        $hypothetical['cargo']['units'] = 0;
        $hypothetical['fuel']['current'] = $hypothetical['fuel']['capacity'];
        // Expired sampled quotes cannot establish a permanent negative, even without a spread.
        $unresolved = $markets->contains(fn ($quote) => $quote->observed_at->lt(now()->subSeconds(config('gameplay.fuel_quote_max_seconds', 21600))))
            ? ['evidence_expired'] : [];
        $operable = false;
        foreach ($markets as $source => $askQuote) {
            foreach ($askQuote->data['tradeGoods'] ?? [] as $ask) {
                foreach ($markets as $buyer => $bidQuote) {
                    $bid = collect($bidQuote->data['tradeGoods'] ?? [])->firstWhere('symbol', $ask['symbol']);
                    if ($source === $buyer || ! $bid || $ask['purchasePrice'] < 1 || $bid['sellPrice'] <= $ask['purchasePrice'] || min($ask['tradeVolume'], $bid['tradeVolume']) < 1) {
                        continue;
                    }
                    $row = ['good' => $ask['symbol'], 'source' => $source, 'buyer' => $buyer, 'quotes' => [
                        $source => ['observed_at' => $askQuote->observed_at->toIso8601String(), 'age_seconds' => max(0, $askQuote->observed_at->diffInSeconds(now()))],
                        $buyer => ['observed_at' => $bidQuote->observed_at->toIso8601String(), 'age_seconds' => max(0, $bidQuote->observed_at->diffInSeconds(now()))]]];
                    if ($askQuote->observed_at->lt(now()->subSeconds(config('gameplay.fuel_quote_max_seconds', 21600))) || $bidQuote->observed_at->lt(now()->subSeconds(config('gameplay.fuel_quote_max_seconds', 21600)))) {
                        $row['rejected'] = 'evidence_expired';
                        $unresolved[] = 'evidence_expired';
                        $result['pairs'][] = $row;

                        continue;
                    }
                    try {
                        $pair = ['good' => $ask['symbol'], 'source' => $source, 'market' => $buyer];
                        $trade = app(CampaignTrade::class);
                        $decay = $rules['lot']['default_batch_decay'];
                        $row['proof'] = $trade->projectPair($agent, $hypothetical, null, $pair, $ask, $bid, $rules, $seconds, 'proof', $decay);
                        $atSource = $hypothetical;
                        $atSource['nav']['waypointSymbol'] = $source;
                        $row['scaled'] = $trade->projectPair($agent, $atSource, null, $pair, $ask, $bid, $rules, $seconds, 'scaled', $decay);
                        $proof = $row['proof']['candidate'] ?? null;
                        $scaled = $row['scaled']['candidate'] ?? null;
                        $operable = $operable || ($proof && $scaled);
                        $row['qualifies'] = $proof && $scaled && $proof['units'] >= 1 && $proof['conservative_margin'] > 0 && $proof['seconds'] <= $seconds
                            && $scaled['score'] >= $mission->terms['qualify']['scaled_score_min'] && $scaled['conservative_margin'] >= $mission->terms['qualify']['scaled_margin_min'];
                        $row['confirmed'] = false;
                        if ($row['qualifies']) {
                            $row['confirmed'] = true;
                            foreach ([$source, $buyer] as $endpoint) {
                                $sample = $samples[$endpoint];
                                $row['confirmed'] = $row['confirmed'] && ! empty($sample['confirmed_at'])
                                    && CarbonImmutable::parse($sample['confirmed_at'])->gte(CarbonImmutable::parse($sample['first_at'])->addMinutes(15))
                                    && $markets[$endpoint]->observed_at->gte(now()->subMinutes($mission->terms['qualify']['confirm_max_age_min']));
                            }
                            $result['qualifying'][] = $row;
                        }
                    } catch (GameException $e) {
                        $row['rejected'] = $e->getMessage();
                        if ($e->retryAfter > 0) {
                            throw $e;
                        }
                        if (str_contains(strtolower($e->getMessage()), 'measured')) {
                            $unresolved[] = 'route_unmeasured';
                        } elseif (! $markets->contains(fn ($quote) => empty($quote->data['invalidated_by_action']) && $quote->observed_at->gte(now()->subSeconds(config('gameplay.fuel_quote_max_seconds', 21600))) && collect($quote->data['tradeGoods'] ?? [])->contains(fn ($good) => $good['symbol'] === 'FUEL' && $good['purchasePrice'] > 0))) {
                            $unresolved[] = 'evidence_expired';
                        }
                    }
                    $result['pairs'][] = $row;
                }
            }
        }
        usort($result['qualifying'], fn ($a, $b) => $b['scaled']['candidate']['score'] <=> $a['scaled']['candidate']['score']);
        $unsampled = array_diff($catalogue['marketplaces'] ?? [], array_keys($samples));
        $result['classification'] = collect($result['qualifying'])->contains(fn ($row) => $row['confirmed']) ? 'promising'
            : ($result['qualifying'] ? 'unconfirmed' : ($unresolved[0] ?? (! ($catalogue['complete'] ?? false) || $unsampled ? 'partial'
                : ($this->noMarkets($mission, $system) ? 'no_markets' : ($operable ? 'below_bar' : 'no_viable_pair')))));

        return $result;
    }

    private function noMarkets(ReconMission $mission, string $system): bool
    {
        $node = $mission->graph[$system] ?? [];

        return ($node['catalogue']['complete'] ?? false) && ! array_diff($node['catalogue']['marketplaces'] ?? [], [$node['gate']]);
    }
}
