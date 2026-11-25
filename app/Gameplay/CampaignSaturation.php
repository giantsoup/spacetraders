<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\CampaignRevision;
use App\Models\GameObservation;
use App\Models\ReconMission;
use App\Models\Ship;
use App\Models\ShipAction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CampaignSaturation
{
    public static function authorityReason(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, bool $safety = true, bool $provisional = false): ?string
    {
        if ($grant->arm() || ! $grant->available() || ! hash_equals($grant->revision_hash, $grant->revision()->content_hash)
            || (! $episode->exists && ! $provisional) || $episode->closed_at || $episode->campaign_grant_id !== $grant->id
            || $episode->agent_id !== $agent->id || $episode->reset_id !== $agent->reset_id
            || $grant->user_id !== $agent->user_id || $grant->resolvedAgentSymbol() !== $agent->name || $agent->isArchived()) {
            return 'authority_unavailable';
        }
        if ($grant->isDraining() || ! empty($episode->state['draining'])) {
            return 'draining';
        }
        if (app(CampaignSafety::class)->windDown($episode)) {
            return 'wind_down';
        }
        if (CampaignGrant::pendingFor($grant->user_id, $agent->name)->exists()) {
            return 'awaiting_pending_activation';
        }

        return $safety ? app(CampaignSafety::class)->reason($grant, $episode, $agent, app(CampaignFleet::class)->symbols($episode, pin: false)) : null;
    }

    public function traderSymbols(CampaignEpisode $episode, Agent $agent): array
    {
        $fleet = app(CampaignFleet::class);
        $command = $fleet->role($episode, 'command', pin: false);
        $arms = $fleet->currentArms($agent)->filter(fn (CampaignGrant $grant) => $grant->available())->map(fn (CampaignGrant $grant) => $grant->arm()['ship']);
        $delivered = app(CampaignControl::class)->autoArmCandidates($episode, $agent)->pluck('symbol');

        return collect([$command['symbol'] ?? null])->merge($arms)->merge($delivered)->filter()->unique()->values()->all();
    }

    public function evaluate(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, ?string $yard = null, ?array $offer = null, ?int $ignoreRun = null, int $fuel = 0, ?CampaignRevision $revision = null, bool $offerIndependentOnly = false, ?array $candidateYards = null, ?float $deadline = null, array $fuelByYard = []): array
    {
        $simulate = $revision !== null;
        $revision ??= $grant->revision();
        $policy = $revision->body['fleet']['haulers'] ?? null;
        if (! $policy) {
            return ['enabled' => false];
        }
        $policy = app(CampaignHaulerPurchase::class)->withWorkingCapital($episode, $agent, $policy);
        $mesh = app(CampaignMesh::class)->target($episode, $agent);
        $purchase = app(CampaignProbeBootstrap::class);
        $authority = self::authorityReason($grant, $episode, $agent, provisional: $simulate);
        $activePolicy = $grant->exists && $episode->exists && $grant->revision_hash === $revision->content_hash && isset($grant->revision()->body['fleet']['haulers']);
        $reserveReason = null;
        try {
            $reserve = $purchase->reserveParts($agent, $grant, $policy, $ignoreRun);
        } catch (GameException $exception) {
            $reserve = ['grant' => $grant->limits['reserve_credits'], 'working_capital' => $policy['min_working_capital'],
                'commitments' => null, 'recon_return' => ReconMission::returnExposure($agent)];
            $reserveReason = $exception->getMessage();
        }
        $slot = $purchase->reservations($agent, $ignoreRun);
        $halt = $this->yieldEvidence($episode, $agent, $policy);
        $arms = app(CampaignFleet::class)->currentArms($agent)->filter(fn (CampaignGrant $arm) => $arm->available());
        $hours = max(0, now()->diffInSeconds($episode->next_reset_at, false) / 3600);
        $covered = $mesh['covered']->count();
        $target = $mesh['hub_target'];
        $required = (int) ceil($target * $policy['min_hub_coverage']);
        $owned = app(CampaignHaulerPurchase::class)->ownedHaulers($episode, $agent);
        $inFlight = $purchase->reservations($agent, $ignoreRun, undeliveredType: CampaignHaulerPurchase::TYPE)->count();
        $gates = [
            'G1' => ['pass' => $authority === null, 'reason' => $authority],
            'G2' => ['pass' => $slot->isEmpty(), 'reason' => $slot->isEmpty() ? null : 'shared_purchase_in_flight', 'blockers' => $slot->values()->all()],
            'G3' => ['pass' => $target > 0 && $covered >= $required, 'reason' => $target === 0 ? 'no_hubs' : ($covered < $required ? 'hub_coverage' : null),
                'covered' => $covered, 'target' => $target, 'required' => $required, 'fraction' => $target ? $covered / $target : null],
            'G6' => $this->scheduler($agent, $arms, $policy['scheduler']),
            'G7' => $this->requests($agent, $policy, $ignoreRun),
            'G9' => $this->pacing($agent),
        ];
        $permanent = match (true) {
            $halt['marginal'] !== null => ['reason' => 'marginal_yield', 'evidence' => $halt['marginal']],
            $hours < $policy['stop_hours_before_reset'] => ['reason' => 'time_to_reset'],
            $owned + $inFlight >= $policy['max_haulers'] => ['reason' => 'hauler_cap'],
            default => null,
        };
        $gates['G5'] = $this->liquidity($agent, $reserve, $reserveReason, null, 0);

        $searchComplete = true;
        $aggregate = $yard === null;
        $offer = CampaignHaulerPurchase::offerProfile($offer) === null ? null : $offer;
        $price = $offer['purchasePrice'] ?? $policy['max_price'];
        $idlePass = false;
        if ($offerIndependentOnly) {
            if ($permanent === null && $halt['cannibalization'] === null && collect($gates)->every(fn ($gate) => $gate['pass'])) {
                $idle = $this->idleShare($agent, $arms);
                $idlePass = $idle['maximum'] < $policy['max_idle_share'];
                if (! $idlePass) {
                    $gates['G4'] = ['pass' => false, 'reason' => 'idle_share', 'idle' => $idle, 'scope' => 'offer_independent'];
                }
            }
        } else {
            $yardOffers = $aggregate ? $this->yardOffers($agent, $mesh['yards']) : collect();
            $choices = $aggregate ? $yardOffers->only($candidateYards) : collect([$yard => $offer]);
            $idle = $this->idleShare($agent, $arms);
            $reference = $this->referenceYield($agent, $arms, $policy['assumed_net_per_hour']);
            $paybackLimit = ($hours - 1) * $policy['payback_fraction'];
            FuelPlanner::memoize(function () use ($choices, $deadline, $policy, $fuelByYard, $fuel, $idle, $reserveReason, $grant, $episode, $agent, $revision, $reserve, $reference, $hours, $paybackLimit, &$searchComplete, &$yard, &$offer, &$price, &$gates) {
                $best = null;
                foreach ($choices->isEmpty() ? ['' => null] : $choices as $candidateYard => $candidateOffer) {
                    if (microtime(true) >= ($deadline ?? INF)) {
                        $searchComplete = false;
                        break;
                    }
                    $candidatePrice = $candidateOffer['purchasePrice'] ?? $policy['max_price'];
                    $candidateFuel = $fuelByYard[$candidateYard] ?? $fuel;
                    if ($candidateYard !== '' && $idle['maximum'] >= $policy['max_idle_share']) {
                        $g4 = ['pass' => false, 'reason' => 'idle_share', 'idle' => $idle, 'scope' => 'offer_independent',
                            'score' => 0, 'limiting_factor' => 'idle_share', 'excluded_claims' => []];
                    } else {
                        $opportunity = $reserveReason ? ['score' => 0, 'limiting_factor' => 'unknown_commitments', 'excluded_claims' => []]
                            : $this->opportunity($grant, $episode, $agent, $revision, $candidateYard ?: null, $candidateOffer, $candidatePrice, $candidateFuel, $reserve, $deadline);
                        $searchComplete = $searchComplete && ! ($opportunity['search_incomplete'] ?? false);
                        $g4 = ['pass' => $candidateYard !== '' && $opportunity['score'] >= $policy['min_unclaimed_score'],
                            'reason' => $candidateYard === '' ? 'no_eligible_yard' : ($opportunity['score'] < $policy['min_unclaimed_score'] ? 'spare_corridor' : null),
                            'idle' => $idle] + $opportunity;
                    }
                    $g5 = $this->liquidity($agent, $reserve, $reserveReason, $candidateYard === '' ? null : $candidatePrice, $candidateFuel);
                    $payback = $reference['net_per_hour'] > 0 ? $candidatePrice / $reference['net_per_hour'] : null;
                    $g8 = ['pass' => $hours >= $policy['stop_hours_before_reset'] && $payback !== null && $payback <= $paybackLimit,
                        'reason' => $hours < $policy['stop_hours_before_reset'] ? 'time_to_reset' : ($payback === null ? 'nonpositive_reference_yield' : ($payback > $paybackLimit ? 'payback' : null)),
                        'hours_to_reset' => $hours, 'payback_hours' => $payback, 'limit_hours' => $paybackLimit] + $reference;
                    $underCap = $candidatePrice <= $policy['max_price'];
                    $rank = [$underCap && $g4['pass'] && $g5['pass'] && $g8['pass'], $underCap && $g4['pass'], $underCap, -$candidatePrice];
                    if ($best === null || $rank > $best) {
                        $best = $rank;
                        [$yard, $offer, $price] = [$candidateYard ?: null, $candidateOffer, $candidatePrice];
                        [$gates['G4'], $gates['G5'], $gates['G8']] = [$g4, $g5, $g8];
                    }
                }
            });
            $known = $yardOffers->isNotEmpty() && $yardOffers->every(fn (?array $value) => $value !== null);
            if ($searchComplete && $known && $mesh['system'] !== null) {
                $catalogue = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                    ->where('kind', 'catalogue')->where('symbol', $mesh['system'])->first();
                $cheapest = $yardOffers->min('purchasePrice');
                if (($catalogue?->data['complete'] ?? null) === true) {
                    if ($cheapest > $policy['max_price']) {
                        $permanent ??= ['reason' => 'all_shipyards_over_price_cap'];
                    } elseif ($reference['net_per_hour'] > 0 && $cheapest / $reference['net_per_hour'] > $paybackLimit) {
                        $permanent ??= ['reason' => 'payback'];
                    }
                }
            }
        }
        ksort($gates);
        $hold = $this->hold($episode, $gates, $permanent, $halt['cannibalization'], $activePolicy, $aggregate && $yard !== null && $searchComplete);
        if ($hold === null && ! $offerIndependentOnly && $price > $policy['max_price']) {
            $hold = ['permanent' => false, 'reason' => 'max_price', 'minutes' => null];
        }
        $pass = $searchComplete && collect($gates)->every(fn ($gate) => $gate['pass']) && ! $hold && ($offerIndependentOnly || ($yard !== null && $price <= $policy['max_price']));
        $gaps = $offerIndependentOnly ? [] : app(CampaignDiscovery::class)->directoryGaps($episode, $agent, includeBackoff: true);

        return [
            'enabled' => true, 'agent' => $agent->name, 'reset_id' => $agent->reset_id, 'revision' => $revision->content_hash, 'phase' => $episode->phase,
            'active_purchase_authority' => $grant->exists && isset($grant->revision()->body['fleet']['haulers']) && $episode->exists && $authority === null, 'policy_pass' => $pass, 'can_purchase' => $activePolicy && $pass,
            'yard' => $yard, 'search_complete' => $searchComplete, 'provisional' => $offer === null, 'gates' => $gates, 'hold' => $hold,
            'owned_haulers' => $owned, 'undelivered_haulers' => $inFlight, 'traders' => $mesh['traders'],
            'mesh' => ['hubs' => $mesh['hubs']->map(fn ($hub) => array_diff_key($hub, ['quote' => true]))->values()->all(),
                'covered_hubs' => $mesh['covered']->all(), 'hub_target' => $target, 'pool_probe_target' => $mesh['pool_probe_target'],
                'eligible_pool_probes' => $mesh['pool_probe_count'], 'directory_gaps' => $gaps]
                + (isset($mesh['mesh_model']) ? $mesh['mesh_model'] + ['governor' => app(MeshGovernor::class)->evaluate($agent, $episode)] : []),
        ] + ($idlePass ? ['idle_pass' => true] : []);
    }

    public function assertPurchase(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, string $yard, ?array $offer = null, ?int $ignoreRun = null, int $fuel = 0, bool $persist = false, ?float $deadline = null): array
    {
        $evaluation = $this->evaluate($grant, $episode, $agent, $yard, $offer, $ignoreRun, $fuel, deadline: $deadline);
        $this->assertGates($grant, $episode, $evaluation, $persist);

        return $evaluation;
    }

    public function assertGates(CampaignGrant $grant, CampaignEpisode $episode, array $evaluation, bool $persist = false): void
    {
        if ($persist) {
            $this->record($grant, $episode, $evaluation);
        }
        if (! ($evaluation['can_purchase'] ?? false)) {
            $failed = collect($evaluation['gates'] ?? [])->first(fn ($gate) => ! $gate['pass']);

            throw new GameException('Hauler purchase held: '.($evaluation['hold']['reason'] ?? $failed['reason'] ?? 'offer or authority unavailable').'.');
        }
    }

    public static function isOfferIndependentHold(?array $hold): bool
    {
        return ($hold['scope'] ?? null) === 'offer_independent'
            || (($hold['gate'] ?? null) === 'G4' && ($hold['reason'] ?? null) === 'idle_share');
    }

    public function record(CampaignGrant $grant, CampaignEpisode $episode, array $evaluation): void
    {
        if (! ($evaluation['enabled'] ?? false)) {
            return;
        }
        $state = $episode->refresh()->state ?? [];
        $prior = $state['saturation_hold'] ?? null;
        $hold = $evaluation['hold'];
        $priorYardGate = in_array($prior['gate'] ?? null, ['G4', 'G5', 'G8'], true) || ($prior['reason'] ?? null) === 'max_price';
        $priorOfferIndependent = self::isOfferIndependentHold($prior);
        if ($priorYardGate && (self::isOfferIndependentHold($hold) && ! $priorOfferIndependent
            || $hold === null && ! isset($evaluation['gates']['G4']) && ! ($priorOfferIndependent && ($evaluation['idle_pass'] ?? false)))) {
            return;
        }
        if ($hold && ! $hold['permanent'] && ! empty($hold['minutes']) && ! isset($hold['held_until'])) {
            $hold['held_until'] = now()->addMinutes($hold['minutes'])->toIso8601String();
        }
        if ($hold === $prior) {
            return;
        }
        if ($hold) {
            $state['saturation_hold'] = $hold;
            if ($hold['reason'] === 'incumbent_yield') {
                $state['saturation_yield_reviews'][$hold['evidence']['action_id']] = true;
            }
        } else {
            unset($state['saturation_hold']);
        }
        $episode->update(['state' => $state]);
        app(CampaignControl::class)->event($grant, $episode, 'saturation', ['hold' => $hold, 'previous_reason' => $prior['reason'] ?? null,
            'gate' => $hold['gate'] ?? (collect($evaluation['gates'])->search(fn ($gate) => ! $gate['pass']) ?: null)]);
    }

    private function hold(CampaignEpisode $episode, array $gates, ?array $permanent, ?array $cannibalization, bool $activePolicy, bool $hasYard): ?array
    {
        $prior = $activePolicy ? ($episode->state['saturation_hold'] ?? null) : null;
        if (! empty($prior['permanent'])) {
            return $prior;
        }
        if ($permanent) {
            return ['permanent' => true, 'held_until' => null] + $permanent;
        }
        if (! self::isOfferIndependentHold($prior) && ! empty($prior['held_until']) && CarbonImmutable::parse($prior['held_until'])->isFuture()) {
            return $prior;
        }
        if ($cannibalization) {
            return ['permanent' => false, 'reason' => 'incumbent_yield', 'minutes' => 180, 'evidence' => $cannibalization];
        }
        foreach (['G1', 'G2', 'G3', 'G6', 'G7', 'G9', 'G5', 'G4', 'G8'] as $gate) {
            if (isset($gates[$gate]) && ! $gates[$gate]['pass']) {
                if ($gate === 'G4' && $gates[$gate]['reason'] === 'idle_share') {
                    return ['permanent' => false, 'reason' => 'idle_share', 'gate' => 'G4', 'minutes' => null, 'scope' => 'offer_independent'];
                }
                $minutes = match ($gate) {
                    'G6', 'G7' => 30,
                    'G4' => $hasYard ? 60 : null,
                    default => null,
                };

                $reason = $gate === 'G4' && $minutes && $gates[$gate]['reason'] === 'spare_corridor'
                    ? 'no_unclaimed_corridor' : $gates[$gate]['reason'];

                return ['permanent' => false, 'reason' => $reason, 'gate' => $gate, 'minutes' => $minutes];
            }
        }

        return null;
    }

    private function liquidity(Agent $agent, array $reserve, ?string $reserveReason, ?int $price, int $fuel): array
    {
        $liquid = $agent->credits - ($price ?? 0) - $fuel;
        $clearsReserve = $price === null ? $liquid > array_sum($reserve) : $liquid >= array_sum($reserve);

        return ['pass' => $reserveReason === null && $clearsReserve,
            'reason' => $reserveReason ?? ($clearsReserve ? null : 'liquidity'),
            'price' => $price, 'purchase_fuel_ceiling' => $fuel, 'available_credits' => $agent->credits,
            'reserve' => $reserveReason ? null : array_sum($reserve), 'reserve_components' => $reserve];
    }

    private function yardOffers(Agent $agent, Collection $yards): Collection
    {
        $observations = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'shipyard')->get()->keyBy('symbol');
        $purchase = app(CampaignProbeBootstrap::class);

        return $yards->reject(fn ($point) => $observations->has($point->symbol)
            && ! $purchase->offers($observations->get($point->symbol)->data, CampaignHaulerPurchase::TYPE))
            ->map(fn ($point) => $purchase->freshHaulerOffer($observations->get($point->symbol)));
    }

    private function opportunity(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, CampaignRevision $revision, ?string $yard, ?array $offer, int $price, int $fuel, array $reserve, ?float $deadline): array
    {
        return FuelPlanner::memoize(function () use ($grant, $episode, $agent, $revision, $yard, $offer, $price, $fuel, $reserve, $deadline) {
            $ship = $yard ? $this->projectedHauler($yard, $offer) : null;
            $armRevision = CampaignRevision::where('content_hash', strtolower($revision->body['fleet']['auto_arm']['revision_hash']))->first();
            $basis = $agent->credits - $price - $fuel;
            if (! $ship || ! $armRevision || $basis <= 0) {
                return ['score' => 0, 'excluded_claims' => [], 'limiting_factor' => ! $ship ? 'unknown_yard_or_configuration' : ($armRevision ? 'post_purchase_credits' : 'arm_revision_unavailable')];
            }
            $arm = new CampaignGrant(['limits' => ['arm' => ['exposure_share' => $revision->body['fleet']['auto_arm']['exposure_share']]]]);
            $funded = clone $agent;
            $funded->credits = $basis;
            $trade = app(CampaignTrade::class);
            $exposure = $trade->allowance($arm, $funded);
            $cash = $basis - $reserve['commitments'] - $reserve['recon_return'];
            $settings = $armRevision->body['trade'];
            $maxSeconds = $armRevision->body['limits']['run_max_minutes'] * 60;
            $system = $ship['nav']['systemSymbol'];
            $claims = $this->claims($grant, $episode, $system);
            $excluded = [];
            $markets = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'market')
                ->orderBy('symbol')->get()->filter(fn (GameObservation $market) => FleetClaims::systemOf($market->symbol) === $system && empty($market->data['invalidated_by_action']));
            $quotes = [];
            foreach ($markets as $market) {
                foreach ($market->data['tradeGoods'] ?? [] as $quote) {
                    if (in_array($quote['symbol'], ['FUEL', 'ANTIMATTER'], true) || ($quote['tradeVolume'] ?? 0) < 1) {
                        continue;
                    }
                    $quotes[$quote['symbol']][$market->symbol] = $quote + ['observed_at' => $market->observed_at->toIso8601String()];
                }
            }
            $reference = CampaignEpisode::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->whereNull('closed_at')
                ->whereIn('campaign_grant_id', CampaignGrant::primaryFor($grant->user_id, $agent->name)->select('id'))->first();
            $results = $reference ? $trade->learningResults($reference) : [];
            $salesByKey = $reference ? $trade->confirmedSales($reference) : collect();
            $best = ['score' => 0, 'limiting_factor' => 'no_priced_pair'];
            $lastFailure = null;
            $fuelPlanner = app(FuelPlanner::class);
            $departure = $fuelPlanner->departureRefuel($funded, $ship);
            foreach ($quotes as $good => $waypoints) {
                foreach ($waypoints as $source => $ask) {
                    if (($ask['purchasePrice'] ?? 0) < 1 || ! in_array($ask['type'] ?? 'EXCHANGE', ['EXPORT', 'EXCHANGE'], true)) {
                        continue;
                    }
                    foreach ($waypoints as $buyer => $bid) {
                        if (microtime(true) >= ($deadline ?? INF)) {
                            return $best + ['excluded_claims' => $excluded, 'search_incomplete' => true];
                        }
                        if ($source === $buyer || ($bid['sellPrice'] ?? 0) <= $ask['purchasePrice'] || ! in_array($bid['type'] ?? 'EXCHANGE', ['IMPORT', 'EXCHANGE'], true)) {
                            continue;
                        }
                        if (isset($claims[$good.'@'.$source]) || isset($claims[$good.'@'.$buyer])) {
                            $excluded[$good.'@'.$source.'→'.$buyer] = $claims[$good.'@'.$source] ?? $claims[$good.'@'.$buyer];

                            continue;
                        }
                        try {
                            $mode = $reference ? $trade->mode($reference, $good, $buyer, $results, $settings['proof']['min_realized_ratio']) : 'proof';
                            $decay = $reference ? $trade->decay($reference, $good, $buyer, $settings['lot']['default_batch_decay'], $salesByKey->get($good.'@'.$buyer, collect())) : $settings['lot']['default_batch_decay'];
                            $projection = $trade->projectPair($funded, $ship, $departure, ['good' => $good, 'source' => $source, 'market' => $buyer],
                                $ask, $bid, $settings, $maxSeconds, $mode, $decay, $exposure, arm: true, cashAfterRecon: $cash);
                            if (! isset($projection['candidate'])) {
                                throw new GameException('Projected first load exceeds the pinned arm duration.');
                            }
                            $candidate = $projection['candidate'];
                            $positionSeconds = $projection['position']['estimated_seconds'] ?? null;
                            if ($positionSeconds === null || ($projection['return']['continuation'] ?? null) !== $source) {
                                throw new GameException('Projected load lacks a verified return to its starting fuel seller.');
                            }
                            $secondsToLoad = $positionSeconds + (int) ceil($candidate['units'] / $ask['tradeVolume']) * 10 + 10;
                            $this->checkQuoteLife($agent, $source, $ask, $settings['quote_max_age_seconds'], $secondsToLoad, $positionSeconds);
                            $this->checkQuoteLife($agent, $buyer, $bid, $settings['quote_max_age_seconds'], $secondsToLoad);
                            $atSource = $projection['at_source'];
                            $available = clone $funded;
                            $available->credits = max(0, $cash - ($departure['ceiling'] ?? 0) - ($source !== $yard ? $projection['position']['refuel_ceiling'] : 0));
                            $topUp = $fuelPlanner->departureRefuel($available, $atSource);
                            $allowance = $candidate['max_inventory'] + $candidate['fuel_safety_ceiling'] + ($topUp['ceiling'] ?? 0);
                            $plan = ['strategy' => 'trade', 'market' => $buyer, 'symbol' => $good, 'units' => $candidate['units'],
                                'min_sell_price' => (int) ceil($candidate['predicted_cost'] / $candidate['units']), 'min_margin' => 1,
                                'max_inventory' => $candidate['max_inventory'], 'max_fuel' => $atSource['fuel']['capacity'],
                                'max_loss' => PHP_INT_MAX, 'reserve' => $armRevision->body['limits']['reserve_credits'],
                                'max_spend' => min(max(0, $available->credits - $armRevision->body['limits']['reserve_credits']), $allowance),
                                'campaign_trade' => $candidate];
                            app(OperationPlanner::class)->project($available, $atSource, $plan, ['ask' => $ask, 'bid' => $bid]);
                            if ($candidate['score'] > $best['score']) {
                                $best = $candidate;
                            }
                        } catch (GameException $exception) {
                            $lastFailure = $exception->getMessage();
                        }
                    }
                }
            }

            return $best + ['excluded_claims' => $excluded, 'last_refusal' => $lastFailure, 'projected_exposure' => $exposure, 'cash_after_commitments' => $cash];
        });
    }

    private function projectedHauler(string $yard, ?array $offer): array
    {
        $profile = CampaignHaulerPurchase::offerProfile($offer);
        $tank = $profile['tank'] ?? 600;
        $speed = $profile['speed'] ?? 15;
        $hold = $profile['hold'] ?? 80;

        return ['symbol' => 'PROJECTED-HAULER', 'nav' => ['systemSymbol' => FleetClaims::systemOf($yard), 'waypointSymbol' => $yard,
            'status' => 'DOCKED', 'flightMode' => 'CRUISE'], 'fuel' => ['capacity' => $tank, 'current' => $tank],
            'cargo' => ['capacity' => $hold, 'units' => 0, 'inventory' => []], 'engine' => ($offer['engine'] ?? []) + ['speed' => $speed],
            'frame' => $offer['frame'] ?? ['symbol' => 'FRAME_LIGHT_FREIGHTER'], 'reactor' => $offer['reactor'] ?? ['symbol' => 'REACTOR_FUSION_I'],
            'mounts' => $offer['mounts'] ?? [], 'modules' => $offer['modules'] ?? []];
    }

    private function claims(CampaignGrant $grant, CampaignEpisode $episode, string $system): array
    {
        $agents = Agent::where('user_id', $grant->user_id)->where('reset_id', $episode->reset_id)->select('id');
        $taken = [];
        foreach (CampaignEpisode::where('reset_id', $episode->reset_id)->whereIn('agent_id', $agents)->orderBy('id')->get() as $other) {
            foreach (app(FleetClaims::class)->pairs($other) as $pair) {
                if (FleetClaims::systemOf($pair['buyer']) !== $system) {
                    continue;
                }
                foreach (array_filter([$pair['source'] ?? null, $pair['buyer']]) as $waypoint) {
                    $taken[$pair['good'].'@'.$waypoint] ??= FleetClaims::label($other);
                }
            }
        }

        return $taken;
    }

    private function checkQuoteLife(Agent $agent, string $waypoint, array $quote, int $age, int $through, ?int $projectedArrival = null): void
    {
        $now = now()->toImmutable();
        if (CarbonImmutable::parse($quote['observed_at'])->addSeconds($age)->gt($now->addSeconds($through))) {
            return;
        }
        $earliest = max(0, $through - $age + 1);
        if ($projectedArrival !== null && $projectedArrival >= $earliest && $projectedArrival <= $through) {
            return;
        }
        foreach (Ship::where('agent_id', $agent->id)->get() as $ship) {
            $nav = $ship->snapshot['nav'];
            $run = AutomationRun::where('active_agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('ship_symbol', $ship->symbol)->first();
            $destination = $run?->plan['destination'] ?? $run?->plan['market'] ?? null;
            $stays = ! $run || ($run->strategy === 'scout' && ! empty($run->plan['one_way']) && $destination === $waypoint);
            if ($stays && $nav['status'] !== 'IN_TRANSIT' && $nav['waypointSymbol'] === $waypoint) {
                return;
            }
            if ($stays && $nav['status'] === 'IN_TRANSIT' && ($nav['route']['destination']['symbol'] ?? null) === $waypoint && isset($nav['route']['arrival'])
                && CarbonImmutable::parse($nav['route']['arrival'])->lte($now->addSeconds($through - 10))) {
                return;
            }
        }

        throw new GameException('Projected quotes expire before loading without timely observer evidence.');
    }

    public function scheduler(Agent $agent, Collection $arms, array $limits): array
    {
        $rows = DB::table('fleet_metrics')->where('kind', 'scheduler')->where('created_at', '>=', now()->subMinutes(30)->toDateTimeString())
            ->pluck('data')->map(fn ($row) => json_decode($row, true) ?: []);
        $lags = $rows->flatMap(fn (array $row) => $row['dispatch_lags'] ?? (isset($row['dispatch_lag']) ? [$row['dispatch_lag']] : []))->all();
        $depths = $rows->pluck('queue_depth')->filter(fn ($value) => is_numeric($value))->sort()->values();
        $p90 = $depths->isEmpty() ? null : $depths->get((int) ceil($depths->count() * .9) - 1);
        $lag = FleetMetrics::median($lags);
        $wait = 0.0;
        foreach ($arms as $arm) {
            $pending = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('ship_symbol', $arm->arm()['ship'])
                ->whereIn('state', ['pending', 'sending'])->exists();
            $runs = $this->runs($agent, $arm);
            if ($pending || (clone $runs)->whereNotNull('active_agent_id')->exists()) {
                continue;
            }
            $terminal = (clone $runs)->whereNull('active_agent_id')->whereNotNull('started_at')->get()
                ->map(fn (AutomationRun $run) => $this->finishedAt($run))->filter()->max();
            $since = max($arm->last_directed_at ?? $arm->created_at, $terminal ?? $arm->created_at);
            $wait = max($wait, $since->diffInSeconds(now(), false));
        }
        try {
            $engine = app(EngineGate::class)->session();
        } catch (GameException) {
            $engine = null;
        }
        $reason = match (true) {
            ! $engine || ! $rows->contains('engine_session_id', $engine) => 'scheduler_evidence',
            $wait > $limits['max_idle_arm_wait_seconds'] => 'idle_arm_wait',
            $lag !== null && $lag > $limits['max_dispatch_lag_seconds'] => 'dispatch_lag',
            $p90 === null || $p90 > $limits['max_queue_depth'] => 'queue_depth',
            default => null,
        };

        return ['pass' => $reason === null, 'reason' => $reason, 'max_idle_arm_wait_seconds' => $wait,
            'median_dispatch_lag_seconds' => $lag, 'p90_queue_depth' => $p90, 'passes' => $rows->count()];
    }

    public function requests(Agent $agent, array $policy, ?int $ignoreRun = null, ?array $usage = null): array
    {
        if (RequestBudget::agentCaps() !== []) {
            try {
                $buyer = app(CampaignProbeBootstrap::class);
                $prospective = 1 + $buyer->reservations($agent, $ignoreRun, undeliveredType: CampaignHaulerPurchase::TYPE)->count();
                $buyer->assertPurchaseBudget($agent, $prospective);

                return ['pass' => true, 'reason' => null, 'staged' => true, 'additional_traders' => $prospective];
            } catch (GameException $exception) {
                return ['pass' => false, 'reason' => $exception->getMessage(), 'staged' => true];
            }
        }
        $usage ??= app(RequestBudget::class)->usage(agentSymbol: $agent->name);
        $symbols = app(CampaignFleet::class)->currentArms($agent)->filter(fn (CampaignGrant $arm) => $arm->available())->map(fn ($arm) => $arm->arm()['ship']);
        $ordinary = DB::table('fleet_metrics')->where('kind', 'requests')->where('agent_symbol', $agent->name)->whereIn('ship', $symbols)
            ->where('created_at', '>=', now()->subHour()->toDateTimeString())->get(['ship', 'data'])->groupBy('ship')
            ->map(fn ($rows) => $rows->sum(fn ($row) => json_decode($row->data, true)['ordinary'] ?? 0))->max();
        $projected = max(220, $ordinary ?? 0);
        $hostCap = max(1, config('gameplay.host_tier2_4_per_minute'));
        $hour = $usage['agent']['tier2_4_last_hour'];
        $minute = $usage['host']['tier2_4_last_60_seconds'];
        $rateLimits = $usage['host']['rate_limits_last_15_minutes'];
        $hourLimit = $policy['request_headroom'] * $hostCap * 60;
        $reason = match (true) {
            $hour + $projected > $hourLimit => 'ordinary_hour_headroom',
            $minute >= .8 * $hostCap => 'ordinary_host_minute_headroom',
            $rateLimits >= 3 => 'host_rate_limits',
            default => null,
        };

        return ['pass' => $reason === null, 'reason' => $reason, 'staged' => false, 'ordinary_hour' => $hour, 'hour_limit' => $hourLimit,
            'projected_arm_requests' => $projected, 'measured_arm_requests' => $ordinary, 'host_ordinary_minute' => $minute, 'host_429s' => $rateLimits];
    }

    private function runs(Agent $agent, CampaignGrant $grant): Builder
    {
        return AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->whereIn('campaign_episode_id', CampaignEpisode::where('campaign_grant_id', $grant->id)->where('reset_id', $agent->reset_id)->select('id'));
    }

    private function finishedAt(AutomationRun $run): CarbonImmutable
    {
        $finished = $run->totals['finished_at'] ?? $run->totals['stopped_at'] ?? null;

        return $finished ? CarbonImmutable::parse($finished) : $run->updated_at->toImmutable();
    }

    private function idleShare(Agent $agent, Collection $arms): array
    {
        $from = now()->subHour()->getTimestamp();
        $to = now()->getTimestamp();
        $ratios = [];
        foreach ($arms as $arm) {
            $episode = CampaignEpisode::where('campaign_grant_id', $arm->id)->where('reset_id', $agent->reset_id)->whereNull('closed_at')->first();
            if (! $episode || $episode->opened_at->getTimestamp() > $from || ! empty($episode->state['recovering_since'])) {
                continue;
            }
            $runs = $this->runs($agent, $arm)->whereNotNull('started_at')->get();
            if ($runs->contains(fn (AutomationRun $run) => $run->active_agent_id && ! empty($run->plan['sell_only']))) {
                continue;
            }
            $busy = [];
            $excluded = [];
            foreach ($runs as $run) {
                $interval = [$run->started_at->getTimestamp(), $run->active_agent_id ? $to : $this->finishedAt($run)->getTimestamp()];
                if (! empty($run->plan['sell_only'])) {
                    $excluded[] = $interval;
                } else {
                    $busy[] = $interval;
                }
            }
            foreach (ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('ship_symbol', $arm->arm()['ship'])
                ->where('created_at', '<=', now())->where('state', '!=', 'preview')->get() as $action) {
                $busy[] = [$action->created_at->getTimestamp(), in_array($action->state->value, ['pending', 'sending'], true)
                    ? $to : ($action->settled_at ?? $action->updated_at)->getTimestamp()];
            }
            $transitions = CampaignEvent::where('campaign_grant_id', $arm->id)->whereIn('kind', ['pause', 'drain_pause', 'resume', 'arm_recovery_started', 'arm_recovery_ended'])->orderBy('id')->get();
            $starts = [];
            foreach ($transitions as $event) {
                $group = str_starts_with($event->kind, 'arm_recovery') ? 'recovery' : 'pause';
                if (in_array($event->kind, ['pause', 'drain_pause', 'arm_recovery_started'], true)) {
                    $starts[$group] ??= $event->created_at->getTimestamp();
                } elseif (isset($starts[$group])) {
                    $excluded[] = [$starts[$group], $event->created_at->getTimestamp()];
                    unset($starts[$group]);
                }
            }
            foreach ($starts as $started) {
                $excluded[] = [$started, $to];
            }
            $eligible = ($to - $from) - $this->intervalSeconds($excluded, $from, $to);
            if ($eligible > 0) {
                $idle = ($to - $from) - $this->intervalSeconds([...$busy, ...$excluded], $from, $to);
                $ratios[$arm->arm()['label']] = $idle / $eligible;
            }
        }

        return ['maximum' => $ratios ? max($ratios) : 0.0, 'by_arm' => $ratios];
    }

    private function intervalSeconds(array $intervals, int $from, int $to): int
    {
        sort($intervals);
        $seconds = 0;
        $end = $from;
        foreach ($intervals as [$start, $stop]) {
            $start = max($end, $start);
            $stop = min($to, $stop);
            if ($stop > $start) {
                $seconds += $stop - $start;
                $end = $stop;
            }
        }

        return $seconds;
    }

    private function autoPurchases(Agent $agent): Collection
    {
        return app(CampaignProbeBootstrap::class)->actions($agent)->where('arguments->shipType', CampaignHaulerPurchase::TYPE)
            ->whereIn('automation_run_id', AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                ->where('strategy', 'buy_hauler')->where('plan_version', 7)->whereNotNull('campaign_episode_id')->select('id'))
            ->whereIn('state', ['pending', 'sending', 'uncertain', 'acknowledged', 'confirmed'])->latest('id')->get()
            ->reject(fn (ShipAction $action) => isset($action->outcome['confirmed_not_sent']));
    }

    private function pacing(Agent $agent): array
    {
        $action = $this->autoPurchases($agent)->first();
        if (! $action) {
            return ['pass' => true, 'reason' => null];
        }
        $ship = CampaignProbeBootstrap::deliveredShip($action);
        $arm = $this->autoArms($agent)->first(fn (CampaignGrant $grant) => ($grant->arm()['purchase_action_id'] ?? null) === $action->id);
        $first = $arm ? $this->firstSale($arm, $agent->reset_id) : null;
        $review = ! empty($action->outcome['configuration_mismatch']) && ! ShipCommandExecutor::reviewedHaulerMismatch($action);
        $resolved = $first !== null || isset($action->outcome['pacing_acknowledged']) || (! empty($action->outcome['configuration_mismatch']) && ! $review);
        $stalled = ! $resolved && ($arm?->paused_at || ($action->settled_at ?? $action->created_at)->lte(now()->subMinutes(90)));
        $reason = match (true) {
            $review => 'hauler_configuration_mismatch_review',
            $resolved => null,
            $stalled => 'pacing_stalled',
            $ship && ! $arm => 'awaiting_auto_arm',
            $ship !== null => 'awaiting_first_load',
            default => 'awaiting_hauler_delivery',
        };

        return ['pass' => $reason === null, 'reason' => $reason, 'action_id' => $action->id, 'delivery' => $ship, 'arm' => $arm?->id,
            'first_sale_at' => $first?->toIso8601String()];
    }

    private function autoArms(Agent $agent): Collection
    {
        return CampaignGrant::where('user_id', $agent->user_id)->where('agent_symbol', $agent->name)->where('limits->arm->automatic', true)
            ->where('limits->arm->reset_id', $agent->reset_id)->orderBy('id')->get();
    }

    private function loads(CampaignGrant $arm, string $reset): Collection
    {
        return CampaignEvent::where('kind', 'load_result')->whereIn('campaign_episode_id', CampaignEpisode::where('campaign_grant_id', $arm->id)
            ->where('reset_id', $reset)->select('id'))->orderBy('data->finished_at')->orderBy('id')->pluck('data')->filter(fn ($load) => isset($load['finished_at']));
    }

    public function firstSale(CampaignGrant $arm, string $reset): ?CarbonImmutable
    {
        $load = $this->loads($arm, $reset)->first();

        return $load ? CarbonImmutable::parse($load['finished_at']) : null;
    }

    private function netBetween(Collection $loads, CarbonImmutable $from, CarbonImmutable $through): float
    {
        return $loads->sum(function (array $load) use ($from, $through) {
            $finished = CarbonImmutable::parse($load['finished_at']);

            return $finished->gt($from) && $finished->lte($through) ? ($load['net'] ?? 0) : 0;
        });
    }

    private function referenceYield(Agent $agent, Collection $arms, int $assumed): array
    {
        $now = now()->toImmutable();
        $rates = [];
        foreach ($arms as $arm) {
            $loads = $this->loads($arm, $agent->reset_id);
            if ($first = $loads->first()) {
                $start = CarbonImmutable::parse($first['finished_at']);
                $hours = $start->diffInSeconds($now, false) / 3600;
                if ($hours >= 3) {
                    $rates[] = $this->netBetween($loads, $start, $now) / $hours;
                }
            }
        }

        return ['net_per_hour' => FleetMetrics::median($rates) ?? $assumed, 'reference_arms' => count($rates), 'assumed' => $rates === []];
    }

    private function pausedDuring(CampaignGrant $arm, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $holds = ['pause', 'drain_pause'];
        $events = CampaignEvent::where('campaign_grant_id', $arm->id)->whereIn('kind', [...$holds, 'resume']);
        $initial = (clone $events)->where('created_at', '<=', $start)->latest('created_at')->latest('id')->value('kind');

        return in_array($initial, $holds, true)
            || $events->whereIn('kind', $holds)->where('created_at', '>', $start)->where('created_at', '<', $end)->exists();
    }

    private function yieldEvidence(CampaignEpisode $episode, Agent $agent, array $policy): array
    {
        $now = now()->toImmutable();
        $arms = $this->autoArms($agent);
        $marginal = null;
        $cannibalization = null;
        foreach ($arms as $arm) {
            $loads = $this->loads($arm, $agent->reset_id);
            if (! $loads->first()) {
                continue;
            }
            $first = CarbonImmutable::parse($loads->first()['finished_at']);
            $end = $first->addHours(3);
            if ($end->gt($now)) {
                continue;
            }
            $net = $this->netBetween($loads, $first, $end) / 3;
            if ($net < $policy['min_marginal_net_per_hour']) {
                $marginal ??= ['grant_id' => $arm->id, 'from' => $first->toIso8601String(), 'to' => $end->toIso8601String(), 'net_per_hour' => $net];
            }
            $action = $arm->arm()['purchase_action_id'] ?? null;
            if (! $action || isset($episode->state['saturation_yield_reviews'][$action])) {
                continue;
            }
            $before = [];
            $after = [];
            $schedule = CampaignDirector::cachedSchedule();
            $history = CampaignGrant::where('user_id', $agent->user_id)->where('agent_symbol', $agent->name)->whereNotNull('limits->arm')->get();
            CampaignGrant::loadFirstEpisodeResets($history);
            $history = $history->filter(fn (CampaignGrant $grant) => CampaignDirector::armReset($grant, $agent->reset_id, $schedule) === $agent->reset_id);
            foreach ($history as $incumbent) {
                if ($incumbent->id === $arm->id) {
                    continue;
                }
                $incumbentEpisode = CampaignEpisode::where('campaign_grant_id', $incumbent->id)->where('reset_id', $agent->reset_id)->first();
                $incumbentLoads = $this->loads($incumbent, $agent->reset_id);
                $start = $first->subHours(3);
                if (! $incumbentEpisode || $incumbentEpisode->opened_at->gt($start) || ($incumbentEpisode->closed_at && $incumbentEpisode->closed_at->lt($end))
                    || ! $incumbentLoads->first() || CarbonImmutable::parse($incumbentLoads->first()['finished_at'])->gt($start)
                    || ($incumbent->expires_at && $incumbent->expires_at->lt($end)) || ($incumbent->paused_at && $incumbent->paused_at->lt($end))
                    || $this->pausedDuring($incumbent, $start, $end)) {
                    continue;
                }
                $before[] = $this->netBetween($incumbentLoads, $start, $first) / 3;
                $after[] = $this->netBetween($incumbentLoads, $first, $end) / 3;
            }
            $baseline = FleetMetrics::median($before);
            $later = FleetMetrics::median($after);
            if ($baseline > 0 && $later < .7 * $baseline) {
                $cannibalization ??= ['action_id' => $action, 'grant_id' => $arm->id, 'before' => $baseline, 'after' => $later, 'cohort' => count($before)];
            }
        }

        return ['marginal' => $marginal, 'cannibalization' => $cannibalization];
    }
}
