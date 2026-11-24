<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\ObservationHistory;
use App\Models\Ship;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class ProbeYardSelector
{
    public const TYPE = 'SHIP_PROBE';

    public function quote(Agent $agent, string $yard, ?GameObservation $observation): array
    {
        $offer = $observation ? $this->price($observation->data) : null;
        $watermark = null;
        $actions = app(CampaignProbeBootstrap::class)->actions($agent)->where('arguments->shipType', self::TYPE)
            ->where('arguments->waypointSymbol', $yard)->whereIn('state', ['confirmed', 'acknowledged', 'uncertain'])->get();
        foreach ($actions as $action) {
            $outcome = $action->outcome ?? [];
            $receipt = $outcome['response']['transaction'] ?? [];
            $transaction = $outcome['delivery_transaction'] ?? $outcome['bootstrap_delivery_transaction'] ?? $receipt;
            if ($action->state->value !== 'confirmed' && ! CampaignProbeBootstrap::deliveredShip($action)) {
                continue;
            }
            if (empty($transaction) && ! CampaignProbeBootstrap::deliveredShip($action)) {
                continue;
            }
            $settled = $action->settled_at?->getTimestamp() ?? $action->updated_at?->getTimestamp();
            $attributed = $outcome['delivery_attributed_at'] ?? null;
            if ($attributed === null && isset($outcome['bootstrap_delivery_transaction'])) {
                $settled = max($settled ?? 0, $action->updated_at?->getTimestamp() ?? 0);
            }
            foreach ([$transaction['timestamp'] ?? null, $outcome['response_received_at'] ?? null, $attributed] as $time) {
                if (is_string($time)) {
                    $settled = max($settled ?? 0, CarbonImmutable::parse($time)->timestamp);
                }
            }
            $watermark = max($watermark ?? 0, $settled ?? 0);
        }
        $observed = $observation?->observed_at->timestamp;
        $age = $observed === null ? null : max(0, now()->timestamp - $observed);
        $invalidated = $watermark !== null && ($observed === null || $observed <= $watermark);

        return [
            'price' => $offer, 'age' => $age, 'watermark' => $watermark, 'invalidated' => $invalidated,
            'fresh' => $offer !== null && $age <= 900 && ! $invalidated,
        ];
    }

    private function price(array $data): ?int
    {
        $price = app(CampaignProbeBootstrap::class)->offer($data, self::TYPE)['purchasePrice'] ?? null;

        return is_int($price) && $price > 0 ? $price : null;
    }

    public function ceiling(Agent $agent, string $yard, array $policy, int $tier): int
    {
        if ($tier === 1) {
            return $policy['max_price'];
        }
        $floor = $policy['max_price'];
        foreach ([ObservationHistory::class, GameObservation::class] as $class) {
            $history = $class::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'shipyard')
                ->where('symbol', $yard)->select('data')->cursor();
            foreach ($history as $row) {
                $floor = min($floor, $this->price($row->data) ?? $floor);
            }
        }
        $inflation = $policy['mesh']['saturation']['tier2_price_inflation_cap_pct'];

        return min($policy['max_price'], intdiv(MeshModel::product($floor, 100 + $inflation), 100));
    }

    public function cutoff(CampaignEpisode $episode, CampaignGrant $grant, array $policy): CarbonImmutable
    {
        $reset = $episode->next_reset_at->subHours($policy['mesh']['saturation']['stop_purchases_hours_before_reset']);

        return $grant->expires_at ? $reset->min($grant->expires_at) : $reset;
    }

    public function cash(Agent $agent, CampaignEpisode $episode, CampaignGrant $grant, array $policy, int $reserve, int $paidFuel = 0, int $remainingFuel = 0): array
    {
        $rules = $grant->revision()->body['trade']['proof'];
        $episodes = CampaignEpisode::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->get()->filter(fn ($old) => ! $old->grant()->arm())->pluck('id');
        $proof = CampaignEvent::whereIn('campaign_episode_id', $episodes)->where('kind', 'load_result')->get()
            ->contains(fn (CampaignEvent $event) => ! empty($event->data['proof']) && ! empty($event->data['proof_eligible'])
                && empty($event->data['standalone_recovery']) && empty($event->data['shortened'])
                && ($event->data['arm'] ?? 'C0') === 'C0' && ($event->data['net'] ?? 0) > 0
                && ($event->data['realized_ratio'] ?? 0) >= $rules['min_realized_ratio']);
        $fuel = $policy['mesh']['saturation']['proof_fuel_buffer_credits'];
        $overlap = 0;
        if (! $proof) {
            $command = app(CampaignFleet::class)->role($episode, 'command', pin: false)['symbol'] ?? null;
            $runs = AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                ->where('ship_symbol', $command)->whereIn('campaign_episode_id', $episodes)->where('strategy', 'trade')
                ->where('plan->campaign_trade->proof', true)->get();
            foreach ($runs as $run) {
                $route = $run->plan['route'] ?? [];
                $fuel = max($fuel, ($route['departure_refuel_ceiling'] ?? 0) + max(
                    $route['outbound']['refuel_ceiling'] ?? 0,
                    $run->plan['campaign_trade']['fuel_safety_ceiling'] ?? 0,
                ));
                if ($run->active_agent_id && ($run->totals['phase'] ?? null) === 'buy') {
                    $overlap += max(0, ($run->plan['max_spend'] ?? 0) - ($run->totals['spending'] ?? 0));
                }
            }
        }
        $guard = $proof ? 0 : max(0, $rules['max_credits'] + $fuel - $overlap);
        $share = $policy['mesh']['saturation']['free_capital_share_pct'];
        $free = max(0, $agent->credits + $paidFuel - $reserve);
        $pace = intdiv(MeshModel::product($free, $share), 100) - $paidFuel - $remainingFuel;
        $cash = $agent->credits - $remainingFuel - $reserve - $guard;

        return ['ceiling' => max(0, min($pace, $cash)), 'reserve' => $reserve, 'proof_guard' => $guard, 'pace' => $pace, 'cash' => $cash];
    }

    private function phantom(array $ship, string $yard, ?GameObservation $observation): array
    {
        $offer = $observation ? app(CampaignProbeBootstrap::class)->offer($observation->data, self::TYPE) : null;
        if (isset($offer['engine']['speed'], $offer['frame']['fuelCapacity'])) {
            $ship['engine'] = $offer['engine'];
            $ship['frame'] = $offer['frame'];
            $ship['fuel'] = ['current' => 0, 'capacity' => $offer['frame']['fuelCapacity']];
        }
        $ship['symbol'] = '~delivery:'.$yard;
        $ship['nav']['waypointSymbol'] = $yard;
        $ship['nav']['status'] = 'DOCKED';
        unset($ship['mesh_arrival']);

        return $ship;
    }

    public function gain(CampaignEpisode $episode, Agent $agent, array $ship, string $yard, ?int $ignoreRun = null): array
    {
        $mesh = app(CampaignMesh::class);
        $model = $mesh->model($episode, $agent);
        $probes = $mesh->probes($episode, $agent);
        $context = $mesh->context($episode, $agent, $ignoreRun, pool: $probes);

        $observation = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', 'shipyard')->where('symbol', $yard)->first();

        return $this->compare($model, $probes, $context, CampaignMesh::policy($episode)['saturation'], $this->phantom($ship, $yard, $observation));
    }

    private function compare(MeshModel $model, array $probes, array $context, array $policy, array $delivery): array
    {
        try {
            $gain = app(MeshPlanner::class)->gain($model, $probes, $context['busy'], $context['reserved'], $context['backoff'], $policy, $context, $delivery);
        } catch (InvalidArgumentException $exception) {
            throw new GameException($exception->getMessage());
        }

        return $gain + ['tier' => $gain['tier1'] > 0 ? 1 : ($gain['tier1'] === 0 && $gain['all'] > 0 ? 2 : null)];
    }

    public function check(CampaignEpisode $episode, Agent $agent, array $ship, string $yard, array $policy, int $price, int $reserve,
        ?int $ignoreRun = null, int $paidFuel = 0, int $remainingFuel = 0, ?int $approved = null): array
    {
        $grant = $episode->grant();
        if (! $grant->available() || $grant->isDraining() || $episode->closed_at || $episode->reset_id !== $agent->reset_id
            || $episode->agent_id !== $agent->id || $grant->resolvedAgentSymbol() !== $agent->name
            || CampaignGrant::pendingFor($grant->user_id, $agent->name)->exists()
            || app(CampaignSafety::class)->priceReview($grant, $episode, $agent)
            || $this->cutoff($episode, $grant, $policy)->lte(now())) {
            throw new GameException('Mesh purchase authority is outside its cutoff or pending handover.');
        }
        app(CampaignMesh::class)->assertPool($episode, $agent, $ship);
        if ($ship['nav']['waypointSymbol'] !== $yard) {
            throw new GameException('Mesh purchase requires the eligible pool purchaser at the authorized yard.');
        }
        $gain = $this->gain($episode, $agent, $ship, $yard, $ignoreRun);
        if ($gain['tier'] === null) {
            throw new GameException('Mesh purchase has no net target gain.');
        }
        $cash = $this->cash($agent, $episode, $grant, $policy, $reserve, $paidFuel, $remainingFuel);
        $ceiling = min($this->ceiling($agent, $yard, $policy, $gain['tier']), $cash['ceiling'], $approved ?? PHP_INT_MAX);
        if ($price < 1 || $price > $ceiling) {
            throw new GameException('Mesh Tier-'.$gain['tier'].' purchase price '.$price.' CR exceeds current ceiling '.$ceiling.' CR.');
        }

        return ['gain' => $gain, 'ceiling' => $ceiling, 'cash' => $cash];
    }

    public function rank(CampaignEpisode $episode, Agent $agent, array $policy, array $busy, int $reserve, float $deadline, array &$considered, ?array &$purchaseYards = null, ?int $ignoreRun = null): array
    {
        $mesh = app(CampaignMesh::class);
        $planner = app(MeshPlanner::class);
        $model = $mesh->model($episode, $agent);
        $probes = $mesh->probes($episode, $agent);
        $context = $mesh->context($episode, $agent, $ignoreRun, pool: $probes);
        $purchaseYards = [];
        $context['busy'] = array_unique([...$context['busy'], ...$busy]);
        $saturation = $policy['mesh']['saturation'];
        $positionSeconds = min($context['seconds_left'],
            (int) now()->diffInSeconds($this->cutoff($episode, $episode->grant(), $policy), false));
        $cash = $this->cash($agent, $episode, $episode->grant(), $policy, $reserve);
        $observations = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', 'shipyard')->get()->keyBy('symbol');
        $yards = $mesh->yards($agent, $model->system)->keys()->all();
        $candidates = $recovery = [];
        $eligible = array_filter($probes, fn (array $probe) => $planner->restriction($probe, $context['busy'], $context['backoff'], $saturation, $context['now'], steady: true) === null);
        if ($eligible === []) {
            return [];
        }
        $context['deliveries'] = array_map(fn ($yard) => $this->phantom(reset($eligible), $yard, $observations->get($yard)), $yards);
        $stationary = Ship::where('agent_id', $agent->id)->get()
            ->filter(fn (Ship $ship) => in_array($ship->snapshot['nav']['status'] ?? null, ['DOCKED', 'IN_ORBIT'], true))
            ->groupBy('snapshot.nav.waypointSymbol');
        $floor = $mesh->departureEvidence($episode, $agent);
        foreach ($yards as $yard) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $observation = $observations->get($yard);
            if (! $observation || ! app(CampaignProbeBootstrap::class)->offers($observation->data, self::TYPE)) {
                continue;
            }
            $quotes = $this->quote($agent, $yard, $observation);
            $gain = $this->compare($model, $probes, $context, $saturation, $this->phantom(reset($eligible), $yard, $observations->get($yard)));
            if ($gain['tier'] === null) {
                continue;
            }
            $tierCeiling = $this->ceiling($agent, $yard, $policy, $gain['tier']);
            $ceiling = min($tierCeiling, $cash['ceiling']);
            $considered[] = ['yard' => $yard, 'gain' => $gain, 'ceiling' => $ceiling, 'price' => $quotes['price'], 'cash' => $cash];
            if ($ceiling < 1 || ($gain['tier'] === 2 && $quotes['fresh'] && $quotes['price'] > $tierCeiling)) {
                continue;
            }
            $attempt = $episode->state['mesh_yard_attempt_at'][$yard] ?? 0;
            foreach ($eligible as $ship) {
                if (microtime(true) >= $deadline) {
                    break 2;
                }
                $origin = $ship['nav']['waypointSymbol'];
                $travel = $planner->route($model, $ship, $yard, $positionSeconds);
                if ($travel === null || ($origin !== $yard && in_array($yard, $context['reserved'], true))) {
                    continue;
                }
                $remote = $origin !== $yard;
                $projection = null;
                if ($remote) {
                    $projection = $mesh->floorAfter($floor, $ship);
                    $occupied = $stationary->get($yard, collect())->contains(fn (Ship $row) => app(CampaignFleet::class)->isProbe($row->snapshot)
                        && $row->stationed_waypoint !== $yard);
                    if (! $projection['pass'] || $occupied
                        || $planner->restriction($ship, $context['busy'], $context['backoff'], $saturation, $context['now']) !== null) {
                        continue;
                    }
                }
                if ($stationary->has($yard)) {
                    $purchaseYards[$yard] = $yard;
                }
                if (! $quotes['fresh']) {
                    if ($remote && ! $stationary->has($yard)
                        && $attempt <= $context['now'] - $saturation['yard_recovery_cooldown_seconds']) {
                        $recovery[] = compact('yard', 'ship', 'travel', 'attempt') + ['position' => true, 'tier' => $gain['tier'], 'recovery' => true];
                    }

                    continue;
                }
                if ($quotes['price'] > $ceiling) {
                    continue;
                }
                $hysteresis = ($remote && isset($model->targets[$origin]) && $model->targets[$origin]['cluster'] !==
                    ($model->points[$yard]['x'].','.$model->points[$yard]['y'])) ? $saturation['hysteresis_seconds'] : 0;
                $overhead = $remote ? 60 + $hysteresis : 0;
                $kappa = $saturation['travel_credits_per_second'];
                $price = $quotes['price'];
                $travelCredits = MeshModel::product($kappa, $travel);
                $overheadCredits = MeshModel::product($kappa, $overhead);
                $utility = ($gain['utility'] < 0 ? -1 : 1) * MeshModel::product($kappa, abs($gain['utility']));
                $effective = $price + $travelCredits + $overheadCredits - $utility;
                $considered[] = ['yard' => $yard, 'ship' => $ship['symbol'], 'effective' => $effective,
                    'price' => $price, 'travel' => $travelCredits, 'overhead' => $overheadCredits, 'utility' => $utility];
                $candidates[] = compact('yard', 'ship', 'effective', 'ceiling', 'projection') + ['position' => $remote, 'tier' => $gain['tier'], 'recovery' => false];
            }
        }
        usort($candidates, fn ($a, $b) => [$a['effective'], $a['yard'], $a['ship']['symbol']] <=> [$b['effective'], $b['yard'], $b['ship']['symbol']]);
        usort($recovery, fn ($a, $b) => [$a['attempt'], $a['yard'], $a['travel'], $a['ship']['symbol']] <=> [$b['attempt'], $b['yard'], $b['travel'], $b['ship']['symbol']]);

        $purchaseYards = array_values($purchaseYards);

        return [...$candidates, ...$recovery];
    }
}
