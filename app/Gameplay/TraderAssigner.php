<?php

declare(strict_types=1);

namespace App\Gameplay;

use App\Models\Agent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TraderAssigner
{
    public function score(int $netCredits, int $requests, int $seconds, int $shadowPrice, int $posRequests = 0, int $posSeconds = 0, int $stimulusBonus = 0): int
    {
        $duration = $seconds + $posSeconds;
        if ($duration <= 0) {
            return 0;
        }
        $numerator = ($netCredits + $stimulusBonus - $shadowPrice * ($requests + $posRequests)) * 3600;

        return intdiv($numerator, $duration) - (int) ($numerator % $duration < 0);
    }

    public function incumbentAdjustedScore(int $score, bool $isIncumbent): int
    {
        return $isIncumbent ? intdiv($score * 110, 100) : $score;
    }

    /**
     * Legs are keyed by origin and carry buyer (or destination), net_credits,
     * requests, seconds and optional events. Empty moves use empty=true or good=null.
     */
    public function buildChainCandidates(array $legsByOrigin, int $maxLegs = 3, int $seedPerOrigin = 12): array
    {
        $maxLegs = min(3, $maxLegs);
        if ($maxLegs < 1 || $seedPerOrigin < 1) {
            return [];
        }

        $loaded = [];
        $empty = [];
        ksort($legsByOrigin, SORT_STRING);
        foreach ($legsByOrigin as $origin => $legs) {
            foreach ($legs as $leg) {
                if (($leg['eligible'] ?? true) === false || ! isset($leg['buyer']) && ! isset($leg['destination'])) {
                    continue;
                }
                $leg += ['source' => $origin, 'buyer' => $leg['destination'] ?? null];
                $leg['corridor_key'] ??= implode('|', [$leg['source'], $leg['buyer'], $leg['good'] ?? '']);
                if (! empty($leg['empty']) || array_key_exists('good', $leg) && $leg['good'] === null) {
                    $empty[$origin][] = $leg;
                } else {
                    $loaded[$origin][] = $leg;
                }
            }
        }
        foreach ($loaded as $origin => $legs) {
            usort($legs, function (array $a, array $b) {
                $difference = $this->baselineScore($b) <=> $this->baselineScore($a);

                return $difference ?: strcmp($a['corridor_key'], $b['corridor_key']);
            });
            $loaded[$origin] = array_slice($legs, 0, $seedPerOrigin);
        }

        $candidates = [];
        foreach ($loaded as $legs) {
            foreach ($legs as $leg) {
                $this->extendChain([$leg], 1, $maxLegs, $loaded, $empty, $candidates);
            }
        }

        return $candidates;
    }

    /**
     * Candidate events use the CorridorBook shape. Candidates can target one
     * ship_symbol or all free ships and carry net_credits, requests and seconds.
     * options supports shadow_price, deadline (microtime), depth_batches,
     * recovery_seconds, decay, now (Unix timestamp), and max_quote_age_seconds.
     */
    public function assignGreedy(array $freeShips, array $candidates, CorridorBook $book, array $existingEvents, array $quotesByKey, array $existingGuards = [], array $options = []): array
    {
        $ships = [];
        foreach ($freeShips as $key => $ship) {
            $symbol = is_string($ship) ? $ship : ($ship['symbol'] ?? $ship['ship_symbol'] ?? (is_string($key) ? $key : null));
            if ($symbol !== null) {
                $ships[$symbol] = is_array($ship) ? $ship : [];
            }
        }
        ksort($ships, SORT_STRING);
        $assignments = [];
        $residual = null;
        $events = $existingEvents;
        $depth = (int) ($options['depth_batches'] ?? 7);
        $recovery = (int) ($options['recovery_seconds'] ?? 1800);
        $decay = (float) ($options['decay'] ?? 0.04);
        $deadline = $options['deadline'] ?? INF;
        $now = (int) ($options['now'] ?? time());

        while ($ships !== [] && microtime(true) < $deadline) {
            $best = null;
            $suppressed = null;
            foreach ($ships as $symbol => $ship) {
                foreach ($candidates as $candidate) {
                    if (microtime(true) >= $deadline) {
                        break 2;
                    }
                    if (($candidate['eligible'] ?? true) === false || ($candidate['ship_symbol'] ?? $symbol) !== $symbol) {
                        continue;
                    }
                    $generic = ! isset($candidate['ship_symbol']);
                    $candidate['ship_symbol'] = $symbol;
                    $candidate['corridor_key'] ??= '';
                    $candidate['shadow_price'] ??= (int) ($options['shadow_price'] ?? 0);
                    $incumbent = $candidate['is_incumbent'] ?? $candidate['incumbent']
                        ?? (($ship['incumbent_corridor_key'] ?? null) === $candidate['corridor_key']);
                    $orders = $candidate['events'] ?? [];
                    $sequence = 0;
                    foreach ($orders as &$order) {
                        if ($generic || ! isset($order['tie_key'])) {
                            $order['tie_key'] = implode("\x1F", [
                                $symbol, $candidate['corridor_key'], sprintf('%05d', $order['leg_index'] ?? 0),
                                sprintf('%05d', $order['batch_index'] ?? $sequence), $order['owner_id'] ?? '',
                            ]);
                        }
                        $sequence++;
                    }
                    unset($order);
                    $candidate['events'] = $orders;
                    $prices = $this->freshEnough($orders, $quotesByKey, $now, (int) ($options['max_quote_age_seconds'] ?? 180), $candidate)
                        ? $book->priceAndValidate($events, $orders, $quotesByKey, $existingGuards, $depth, $recovery, $decay)
                        : null;
                    $net = $candidate['net_credits'] ?? null;
                    if ($prices !== null && $net !== null && $orders !== []) {
                        $baseline = $candidate['baseline_prices'] ?? $book->priceAndValidate([], $orders, $quotesByKey, [], $depth, $recovery, $decay);
                        $net = $baseline === null ? null : $net + $this->cashValue($orders, $prices) - $this->cashValue($orders, $baseline);
                    } elseif ($prices !== null && $net === null && $orders !== []) {
                        $net = $this->cashValue($orders, $prices) - ($candidate['fuel_cost'] ?? 0);
                    }

                    $candidate['score'] = $net === null ? $this->baselineScore($candidate) : $this->score(
                        $net - (int) ($candidate['position_fuel_cost'] ?? 0),
                        (int) ($candidate['requests'] ?? 0), (int) ($candidate['seconds'] ?? 0), $candidate['shadow_price'],
                        (int) ($candidate['pos_requests'] ?? 0), (int) ($candidate['pos_seconds'] ?? 0),
                        (int) ($candidate['stimulus_bonus'] ?? 0),
                    );
                    $candidate['comparison_score'] = $this->incumbentAdjustedScore($candidate['score'], (bool) $incumbent);
                    if ($prices === null || ! $this->satisfiesGuards($orders, $prices)
                        || ($net !== null && $net < -((int) ($candidate['loss_auth'] ?? 0)))) {
                        $candidate['suppressed'] = true;
                        if ($this->better($candidate, $suppressed)) {
                            $suppressed = $candidate;
                        }

                        continue;
                    }

                    $candidate['prices'] = $prices;
                    $candidate['projected_net_credits'] = $net;
                    if ($this->better($candidate, $best)) {
                        $best = $candidate;
                    }
                }
            }

            $residual = $this->better($suppressed, $best) ? $suppressed : $best;
            if ($best === null || $best['score'] <= 0 || microtime(true) >= $deadline) {
                break;
            }
            $residual = null;
            foreach ($best['events'] ?? [] as $index => $event) {
                $event['guard_price'] ??= $best['prices'][$index];
                $best['events'][$index] = $event;
                $events[] = $event;
            }
            $assignments[] = $best;
            unset($ships[$best['ship_symbol']]);
        }

        return ['assignments' => $assignments, 'rounds' => count($assignments), 'residual_best' => $residual, 'book_events' => $events];
    }

    public function canaryState(Agent $agent, string $family): array
    {
        $row = DB::table('corridor_canary_states')->where($this->canaryIdentity($agent, $family))->first();
        if (! $row) {
            return ['state' => 'waiting_evidence', 'automation_run_id' => null, 'canary_pair_id' => null, 'evidence' => []];
        }

        return array_replace((array) $row, ['evidence' => $row->evidence === null ? [] : json_decode($row->evidence, true, flags: JSON_THROW_ON_ERROR)]);
    }

    public function transitionCanary(Agent $agent, string $family, string $targetState, ?int $runId = null, array $evidence = []): array
    {
        return DB::transaction(function () use ($agent, $family, $targetState, $runId, $evidence) {
            Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            $current = $this->canaryState($agent, $family);
            $state = $current['state'];
            $currentRun = $current['automation_run_id'] === null ? null : (int) $current['automation_run_id'];
            if ($state === $targetState && ($runId === null || $currentRun === $runId)) {
                return $current;
            }

            $allowed = match ($state) {
                'waiting_evidence' => $targetState === 'eligible',
                'eligible' => $targetState === 'canary_in_flight' && $runId > 0,
                'canary_in_flight' => in_array($targetState, ['passed', 'held'], true) && $runId === $currentRun,
                'held' => $targetState === 'eligible' && ($evidence['reconciled'] ?? false) === true,
                default => false,
            };
            if (! $allowed) {
                throw new GameException('The corridor canary transition conflicts with the current evidence or run.');
            }

            DB::table('corridor_canary_states')->updateOrInsert($this->canaryIdentity($agent, $family), [
                'state' => $targetState,
                'automation_run_id' => $targetState === 'eligible' ? null : ($runId ?? $currentRun),
                'canary_pair_id' => $evidence['canary_pair_id'] ?? $current['canary_pair_id'],
                'evidence' => json_encode(array_replace($current['evidence'], $evidence), JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

            return $this->canaryState($agent, $family);
        }, 3);
    }

    private function extendChain(array $legs, int $loadedCount, int $maxLegs, array $loaded, array $empty, array &$candidates): void
    {
        $candidates[] = $this->chain($legs);
        if ($loadedCount === $maxLegs) {
            return;
        }

        $buyer = $legs[array_key_last($legs)]['buyer'];
        foreach ($loaded[$buyer] ?? [] as $leg) {
            $this->extendChain([...$legs, $leg], $loadedCount + 1, $maxLegs, $loaded, $empty, $candidates);
        }
        foreach ($empty[$buyer] ?? [] as $move) {
            foreach ($loaded[$move['buyer']] ?? [] as $leg) {
                $this->extendChain([...$legs, $move, $leg], $loadedCount + 1, $maxLegs, $loaded, $empty, $candidates);
            }
        }
    }

    private function chain(array $legs): array
    {
        $events = [];
        $keys = [];
        $seconds = 0;
        $start = $legs[0]['start_at'] ?? null;
        foreach ($legs as $legIndex => $leg) {
            foreach ($leg['events'] ?? [] as $event) {
                $event['leg_index'] = $legIndex;
                $event['landing_at'] += $seconds + ($start ?? $leg['start_at'] ?? 0) - ($leg['start_at'] ?? $start ?? 0);
                $events[] = $event;
            }
            $keys[] = $leg['corridor_key'];
            $seconds += $leg['seconds'] ?? 0;
        }

        $chain = [
            'source' => $legs[0]['source'], 'buyer' => $legs[array_key_last($legs)]['buyer'], 'legs' => $legs,
            'corridor_key' => implode('>', $keys), 'seconds' => $seconds, 'events' => $events,
            'requests' => array_sum(array_column($legs, 'requests')), 'fuel_cost' => array_sum(array_column($legs, 'fuel_cost')),
        ];
        if (count(array_column($legs, 'net_credits')) === count($legs)) {
            $chain['net_credits'] = array_sum(array_column($legs, 'net_credits'));
        }

        return $chain;
    }

    private function baselineScore(array $candidate): int
    {
        return $candidate['score'] ?? $this->score(
            (int) ($candidate['net_credits'] ?? 0) - (int) ($candidate['position_fuel_cost'] ?? 0),
            (int) ($candidate['requests'] ?? 0), (int) ($candidate['seconds'] ?? 0),
            (int) ($candidate['shadow_price'] ?? 0), (int) ($candidate['pos_requests'] ?? 0),
            (int) ($candidate['pos_seconds'] ?? 0), (int) ($candidate['stimulus_bonus'] ?? 0),
        );
    }

    private function freshEnough(array $orders, array $quotes, int $now, int $maxAge, array $candidate): bool
    {
        foreach ($orders as $order) {
            $market = $order['market'];
            $good = $order['good'];
            $side = $order['side'];
            $quote = $quotes[$market.'|'.$good.'|'.$side] ?? $quotes[$market][$good][$side]
                ?? $quotes[$market.'|'.$good] ?? $quotes[$market][$good] ?? [];
            if (! empty($quote['invalidated']) || ! empty($quote['invalidated_by_action'])) {
                return false;
            }
            $observedAt = $quote['observed_at'] ?? null;
            if ($observedAt === null) {
                continue;
            }
            try {
                $timestamp = is_int($observedAt) ? $observedAt : CarbonImmutable::parse($observedAt)->timestamp;
            } catch (InvalidArgumentException) {
                return false;
            }
            $age = $now - $timestamp;
            $limit = $side === 'sell' && ! empty($candidate['committed_buyer_exception']) ? 900 : $maxAge;
            if ($age < 0 || $age > min($limit, 900)) {
                return false;
            }
        }

        return true;
    }

    private function cashValue(array $orders, array $prices): int
    {
        $cash = 0;
        foreach ($orders as $index => $order) {
            $cash += ($order['side'] === 'buy' ? -1 : 1) * $order['units'] * $prices[$index];
        }

        return $cash;
    }

    private function satisfiesGuards(array $orders, array $prices): bool
    {
        foreach ($orders as $index => $order) {
            $guard = $order['guard_price'] ?? null;
            if ($guard !== null && ($order['side'] === 'buy' ? $prices[$index] > $guard : $prices[$index] < $guard)) {
                return false;
            }
        }

        return true;
    }

    private function better(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) {
            return $a !== null;
        }
        if ($a['comparison_score'] !== $b['comparison_score']) {
            return $a['comparison_score'] > $b['comparison_score'];
        }

        return (strcmp($a['ship_symbol'], $b['ship_symbol']) ?: strcmp($a['corridor_key'], $b['corridor_key'])) < 0;
    }

    private function canaryIdentity(Agent $agent, string $family): array
    {
        return ['agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'corridor_family' => $family];
    }
}
