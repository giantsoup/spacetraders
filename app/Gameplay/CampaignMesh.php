<?php

namespace App\Gameplay;

use App\Enums\RunState;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\Ship;
use App\Models\ShipAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class CampaignMesh
{
    public const TRANSPORT_CUTOFF_MARGIN_SECONDS = 5;

    private ?string $modelKey = null;

    private ?MeshModel $model = null;

    /** @var array<int, string> */
    private array $purchaseHolds = [];

    public function holdPurchase(CampaignEpisode $episode, string $ship, callable $work): mixed
    {
        $this->purchaseHolds[$episode->id] = $ship;
        try {
            return $work();
        } catch (\Throwable $exception) {
            $this->clearPurchaseHold($episode);
            throw $exception;
        }
    }

    public function clearPurchaseHold(CampaignEpisode $episode): void
    {
        unset($this->purchaseHolds[$episode->id]);
    }

    public function beginPass(): void
    {
        $this->purchaseHolds = [];
        $this->modelKey = null;
        $this->model = null;
        app(MeshPlanner::class)->beginPass();
    }

    public function model(CampaignEpisode $episode, Agent $agent): MeshModel
    {
        $system = app(CampaignFleet::class)->role($episode, 'command', pin: false)['nav']['systemSymbol'] ?? null;
        $policy = self::policy($episode)['saturation'];
        [$points, $listings, $stations] = MeshModel::evidence($agent);
        $revision = $episode->getRelationValue('policyRevision')?->content_hash ?? $episode->grant()->revision_hash;
        $key = hash('sha256', serialize([$episode->id, $revision, $agent->id, $agent->reset_id, $system, $policy, $points, $listings, $stations]));
        if ($this->model === null || $this->modelKey !== $key) {
            $this->model = MeshModel::build($system, $points, $listings, $stations, $policy);
            $this->modelKey = $key;
        }

        return $this->model;
    }

    public function secondsLeft(CampaignEpisode $episode, CampaignGrant $grant): int
    {
        $reset = $episode->next_reset_at->subHour();

        return (int) now()->diffInSeconds($grant->expires_at ? $reset->min($grant->expires_at) : $reset, false);
    }

    public function probes(CampaignEpisode $episode, Agent $agent): array
    {
        $probes = app(CampaignFleet::class)->probes($episode, pin: false);
        $confirmed = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('command', 'navigate')->where('state', 'confirmed')->whereIn('ship_symbol', array_column($probes, 'symbol'))
            ->whereNotNull('automation_run_id')->latest('id')->get()->groupBy('ship_symbol');
        $runs = AutomationRun::whereIn('id', $confirmed->flatten(1)->pluck('automation_run_id')->unique())->get()->keyBy('id');
        foreach ($probes as &$probe) {
            $route = $probe['nav']['route'] ?? [];
            $destination = $route['destination']['symbol'] ?? null;
            $arrival = $route['arrival'] ?? null;
            if ($probe['nav']['status'] === 'IN_TRANSIT' || ! $arrival || $destination !== $probe['nav']['waypointSymbol']
                || ($route['origin']['symbol'] ?? null) === $destination || CarbonImmutable::parse($arrival)->isFuture()) {
                continue;
            }
            foreach ($confirmed->get($probe['symbol'], collect())->take(1) as $action) {
                $run = $runs->get($action->automation_run_id);
                if (isset($run?->plan['mesh_work']) && ($action->arguments['waypointSymbol'] ?? null) === $destination
                    && CarbonImmutable::parse($action->outcome['travel']['server_route']['arrival'] ?? $action->outcome['response']['nav']['route']['arrival'] ?? '1970-01-01')->timestamp === CarbonImmutable::parse($arrival)->timestamp) {
                    $probe['mesh_arrival'] = CarbonImmutable::parse($arrival)->timestamp;
                    break;
                }
            }
        }
        unset($probe);

        return $probes;
    }

    public function context(CampaignEpisode $episode, Agent $agent, ?int $ignoreRun = null, ?array $pool = null): array
    {
        $runs = AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->when($ignoreRun !== null, fn ($query) => $query->whereKeyNot($ignoreRun))
            ->where(fn ($query) => $query->whereNotNull('active_agent_id')->orWhere(fn ($previews) => $previews
                ->where('state', 'preview')->where(fn ($owned) => $owned->where('expires_at', '>', now())->orWhereNotNull('plan->mesh_work'))))
            ->orderBy('id')->get();
        $pending = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->whereIn('state', ['pending', 'sending', 'uncertain'])
            ->when($ignoreRun !== null, fn ($query) => $query->where(fn ($actions) => $actions->whereNull('automation_run_id')->orWhere('automation_run_id', '!=', $ignoreRun)))
            ->orderBy('id')->get();
        $ships = Ship::where('agent_id', $agent->id)->get();
        $fleet = app(CampaignFleet::class);
        $excluded = array_filter([$fleet->role($episode, 'command', pin: false)['symbol'] ?? null,
            ...$fleet->reservedArmShips($agent, $agent->reset_id)]);
        $probeSymbols = $ships->filter(fn (Ship $ship) => $fleet->isProbe($ship->snapshot)
            && ! in_array($ship->symbol, $excluded, true))->pluck('symbol')->all();
        $parked = [];
        if ($pool !== null) {
            $poolSymbols = array_fill_keys(array_column($pool, 'symbol'), true);
            foreach ($ships as $ship) {
                $nav = $ship->snapshot['nav'] ?? [];
                if (! isset($poolSymbols[$ship->symbol]) && $fleet->isProbe($ship->snapshot)
                    && in_array($nav['status'] ?? null, ['DOCKED', 'IN_ORBIT'], true) && isset($nav['waypointSymbol'])) {
                    $parked[$nav['waypointSymbol']] = true;
                }
            }
        }
        $commitments = $reserved = [];
        foreach ($runs as $run) {
            $destination = $run->plan['destination'] ?? $run->plan['shipyard'] ?? null;
            if (is_string($destination) && in_array($run->ship_symbol, $probeSymbols, true)
                && in_array($run->strategy, ['scout', ...CampaignProbeBootstrap::STRATEGIES], true)) {
                $reserved[] = $commitments[$run->ship_symbol] = $destination;
            }
        }
        foreach ($pending as $action) {
            if ($action->command === 'navigate' && isset($action->arguments['waypointSymbol']) && in_array($action->ship_symbol, $probeSymbols, true)) {
                $reserved[] = $commitments[$action->ship_symbol] = $action->arguments['waypointSymbol'];
            }
        }
        foreach ($ships as $ship) {
            $nav = $ship->snapshot['nav'] ?? [];
            $destination = $nav['route']['destination']['symbol'] ?? null;
            if (($nav['status'] ?? null) === 'IN_TRANSIT' && is_string($destination) && in_array($ship->symbol, $probeSymbols, true)) {
                $reserved[] = $commitments[$ship->symbol] = $destination;
            }
        }
        $backoff = [];
        foreach ($episode->state['mesh_dispatch_failed'] ?? [] as $ship => $failure) {
            if (isset($failure['at'])) {
                $backoff[$ship] = CarbonImmutable::parse($failure['at'])->timestamp;
            }
        }

        return [
            'now' => now()->timestamp, 'seconds_left' => $this->secondsLeft($episode, $episode->grant()),
            'busy' => $runs->pluck('ship_symbol')->merge($pending->pluck('ship_symbol'))->unique()->all(),
            'commitments' => $commitments, 'reserved' => array_values(array_unique($reserved)), 'backoff' => $backoff,
            'parked_outside_pool' => $parked,
        ];
    }

    public function dispatchPlan(CampaignEpisode $episode, Agent $agent, array $busy, array $reserved, ?string $buyer, array $pins): array
    {
        $mesh = self::policy($episode);
        $model = $this->model($episode, $agent);
        $probes = $this->probes($episode, $agent);
        $context = $this->context($episode, $agent, pool: $probes);
        $quotes = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', 'market')->get()->filter(fn ($quote) => ! empty($quote->data['tradeGoods']));
        $ages = $quotes->mapWithKeys(fn ($quote) => [$quote->symbol => max(0, now()->timestamp - $quote->observed_at->timestamp)])->all();
        $cooldowns = $episode->state['mesh_patrol_at'] ?? [];
        foreach ($probes as $probe) {
            $origin = $probe['nav']['waypointSymbol'];
            $bucket = $model->targets[$origin]['bucket'] ?? null;
            if ($bucket !== null && isset($probe['mesh_arrival'])) {
                $cooldowns[$bucket] = max($cooldowns[$bucket] ?? 0, $probe['mesh_arrival']);
            }
            $destination = $context['commitments'][$probe['symbol']] ?? null;
            $activeBucket = $destination === null ? null : ($model->targets[$destination]['bucket'] ?? null);
            if ($activeBucket !== null && $activeBucket === $bucket && $destination !== $origin) {
                $cooldowns[$activeBucket] = now()->timestamp;
            }
        }
        $hold = $this->purchaseHolds[$episode->id] ?? null;
        $purchase = $hold !== null || app(CampaignProbeBootstrap::class)->reservations($agent)->isNotEmpty() || $this->yardWork($episode, $agent);
        $demand = app(MeshPoller::class)->demand($episode, $agent, $buyer);
        $context += ['pins' => $pins, 'hold' => $hold, 'protect_anchors' => $purchase, 'ages' => $ages, 'cooldowns' => $cooldowns, 'demand' => $demand];
        try {
            return app(MeshPlanner::class)->plan($model, $probes, array_unique([...$busy, ...$context['busy']]),
                array_unique([...$reserved, ...$context['reserved']]), $context['backoff'], $mesh['saturation'], $context) + ['purchase_hold' => $hold];
        } catch (InvalidArgumentException $exception) {
            return ['moves' => [], 'reason' => $exception->getMessage(), 'probes' => count($probes), 'targets' => count($model->targets)];
        }
    }

    public function hasRecoveryObserver(CampaignEpisode $episode, Agent $agent, MeshModel $model, array $probes, string $destination, int $secondsLeft): bool
    {
        $pool = array_column($probes, null, 'symbol');
        $runs = AutomationRun::where('active_agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('campaign_episode_id', $episode->id)->whereIn('ship_symbol', array_keys($pool))
            ->where('strategy', 'scout')->where('plan->mesh_work', 'market')->where('plan->destination', $destination)
            ->whereIn('state', [RunState::Running, RunState::Waiting])->whereNotNull('started_at')
            ->whereNull('stop_requested_at')->where('expires_at', '>', now())->get();
        $now = now()->timestamp;
        $planner = app(MeshPlanner::class);
        foreach ($runs as $run) {
            $ship = $pool[$run->ship_symbol];
            $nav = $ship['nav'];
            $margin = 0;
            if ($nav['status'] === 'IN_TRANSIT') {
                $arrival = strtotime($nav['route']['arrival'] ?? '');
                if (($nav['route']['destination']['symbol'] ?? null) !== $destination || $arrival === false) {
                    continue;
                }
                $observableAt = max($arrival, $run->next_action_at?->timestamp ?? $arrival);
                $eta = max(0, $observableAt - $now);
            } else {
                $travel = $planner->route($model, $ship, $destination, $secondsLeft);
                if ($travel === null) {
                    continue;
                }
                $departure = max($now, $run->next_action_at?->timestamp ?? $now);
                $eta = $departure - $now + $travel;
                $margin = self::TRANSPORT_CUTOFF_MARGIN_SECONDS;
            }
            if ($eta + 180 <= $secondsLeft && $now + $eta + $margin < $run->expires_at->timestamp) {
                return true;
            }
        }

        return false;
    }

    public function assertPool(CampaignEpisode $episode, Agent $agent, array $ship): void
    {
        if (! in_array($ship['symbol'], array_column(app(CampaignFleet::class)->probes($episode, pin: false), 'symbol'), true)
            || ! app(FuelPlanner::class)->fuelFree($ship) || ($ship['cargo']['units'] ?? null) !== 0
            || ! in_array($ship['nav']['status'] ?? null, ['DOCKED', 'IN_ORBIT'], true)) {
            throw new GameException('Mesh pool authority no longer permits this ship.');
        }
    }

    public function assertDeparture(CampaignEpisode $episode, Agent $agent, array $ship, string $destination, ?int $ignoreRun = null, bool $yard = false): void
    {
        $this->assertPool($episode, $agent, $ship);
        $origin = $ship['nav']['waypointSymbol'];
        $model = $this->model($episode, $agent);
        $context = $this->context($episode, $agent, $ignoreRun);
        $probe = collect($this->probes($episode, $agent))->firstWhere('symbol', $ship['symbol']) ?? $ship;
        $restriction = app(MeshPlanner::class)->restriction($probe, $context['busy'], $context['backoff'], self::policy($episode)['saturation'], now()->timestamp);
        if ($restriction !== null || app(MeshPlanner::class)->route($model, $ship, $destination, $context['seconds_left']) === null
            || ($yard ? ! $this->yards($agent, $model->system)->has($destination) : ! isset($model->targets[$destination]))
            || ($origin !== $destination && in_array($destination, $context['reserved'], true))) {
            throw new GameException('Mesh movement authority changed: '.($restriction ?? 'route, destination or reservation').'.');
        }
        if ($origin === $destination) {
            return;
        }
        $occupants = $this->parkedProbes($agent)
            ->filter(fn (Ship $row) => $row->symbol !== $ship['symbol'] && ($row->snapshot['nav']['waypointSymbol'] ?? null) === $destination);
        if ($occupants->contains(fn (Ship $row) => ! $yard || $row->stationed_waypoint !== $destination)) {
            throw new GameException('Mesh destination is occupied by a probe.');
        }
        $this->assertFloor($episode, $agent, $ship);
    }

    private function parkedProbes(Agent $agent): Collection
    {
        $fleet = app(CampaignFleet::class);

        return Ship::where('agent_id', $agent->id)->get()->filter(fn (Ship $ship) => $fleet->isProbe($ship->snapshot)
            && ($ship->snapshot['nav']['status'] ?? null) !== 'IN_TRANSIT');
    }

    public function coverage(CampaignEpisode $episode, Agent $agent): array
    {
        $target = $this->target($episode, $agent);
        $observers = [];
        foreach ($target['parked'] as $waypoint => $probes) {
            if ($target['hubs']->has($waypoint)) {
                $observers[$waypoint] = array_column($probes->all(), 'symbol');
            }
        }
        foreach (Ship::where('agent_id', $agent->id)->whereNotNull('stationed_waypoint')->get() as $station) {
            if ($target['hubs']->has($station->stationed_waypoint)
                && app(CampaignFleet::class)->isProbe($station->snapshot)
                && ($station->snapshot['nav']['status'] ?? null) !== 'IN_TRANSIT'
                && ($station->snapshot['nav']['waypointSymbol'] ?? null) === $station->stationed_waypoint) {
                $observers[$station->stationed_waypoint][] = $station->symbol;
            }
        }
        $ratio = $episode->grant()->revision()->body['fleet']['haulers']['min_hub_coverage'] ?? 0.8;

        return [$observers, (int) ceil($target['hub_target'] * $ratio)];
    }

    public function departureEvidence(CampaignEpisode $episode, Agent $agent): array
    {
        [$observers, $required] = $this->coverage($episode, $agent);
        $departures = [];
        $runs = AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->whereNotNull('active_agent_id')
            ->whereNotNull('started_at')->whereNull('stop_requested_at')->whereIn('strategy', ['scout', 'buy_probe', 'buy_hauler'])->get();
        foreach ($runs as $run) {
            if (! in_array($run->state, [RunState::Running, RunState::Waiting], true)) {
                continue;
            }
            $origin = $run->plan['initial_waypoint'] ?? $run->plan['site'] ?? null;
            $destination = $run->plan['destination'] ?? $run->plan['shipyard'] ?? null;
            if ($origin && $destination && $origin !== $destination) {
                $departures[$run->ship_symbol] = $origin;
            }
        }
        foreach (ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('command', 'navigate')
            ->whereIn('state', ['pending', 'sending', 'uncertain'])->latest('id')->get()->unique('ship_symbol') as $action) {
            $origin = $action->before_state['ship']['nav']['waypointSymbol'] ?? $action->before_state['ship']['nav']['route']['origin']['symbol'] ?? null;
            if ($origin && $origin !== ($action->arguments['waypointSymbol'] ?? null)) {
                $departures[$action->ship_symbol] = $origin;
            }
        }

        return compact('observers', 'required', 'departures');
    }

    public function floorAfter(array $evidence, array $ship): array
    {
        ['observers' => $observers, 'required' => $required, 'departures' => $departures] = $evidence;
        $departures[$ship['symbol']] = $ship['nav']['waypointSymbol'];
        $verified = count($observers);
        foreach ($departures as $symbol => $origin) {
            if (isset($observers[$origin])) {
                $observers[$origin] = array_diff($observers[$origin], [$symbol]);
            }
        }
        $projected = count(array_filter($observers));

        return ['projected' => $projected, 'verified' => $verified, 'required' => $required, 'pass' => $projected >= min($verified, $required)];
    }

    public function assertFloor(CampaignEpisode $episode, Agent $agent, array $ship): void
    {
        ['projected' => $projected, 'verified' => $verified, 'required' => $required, 'pass' => $pass]
            = $this->floorAfter($this->departureEvidence($episode, $agent), $ship);
        if (! $pass) {
            throw new GameException('Mesh G3 floor: projected '.$projected.', verified '.$verified.', required '.$required.'.');
        }
    }

    public function failure(CampaignEpisode $episode, string $ship, string $destination, ?int $runId = null): void
    {
        $state = $episode->refresh()->state ?? [];
        $failures = array_filter($state['mesh_dispatch_failed'] ?? [], fn ($entry) => CarbonImmutable::parse($entry['at'])->gt(now()->subMinutes(30)));
        if ($runId !== null && ($failures[$ship]['run_id'] ?? null) === $runId) {
            return;
        }
        $failures[$ship] = ['at' => now()->toIso8601String(), 'destination' => $destination, 'run_id' => $runId];
        $state['mesh_dispatch_failed'] = $failures;
        $episode->update(['state' => $state]);
    }

    public static function dispatchFailure(string $reason): bool
    {
        return ! preg_match('/Mesh G3 floor|Mesh (movement|pool) authority|Mesh destination is occupied|authority|allowance|request budget|reconcil|uncertain|campaign cutoff|freshness|expired|stopped|purchase cutoff|run cutoff/i', $reason);
    }

    public function yardWork(CampaignEpisode $episode, Agent $agent, ?int $except = null): bool
    {
        return AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('campaign_episode_id', $episode->id)->whereIn('plan->mesh_work', ['yard', 'purchase'])
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->where(fn ($query) => $query->whereNotNull('active_agent_id')->orWhere('state', 'preview'))->exists();
    }

    public function invalidatePreview(Agent $agent, AutomationRun $run, ?float $deadline = null): bool
    {
        $work = function () use ($agent, $run, $deadline) {
            $run->refresh();
            if ($run->agent_id !== $agent->id || $run->reset_id !== $agent->reset_id || $run->started_at || $run->active_agent_id
                || ! in_array($run->state, [RunState::Preview, RunState::Stopped], true)
                || ShipAction::where('automation_run_id', $run->id)->where(fn ($query) => $query
                    ->whereIn('state', ['pending', 'sending', 'uncertain', 'confirmed', 'acknowledged'])->orWhereNotNull('sent_at')
                    ->orWhereNotNull('outcome->response_received_at')->orWhereNotNull('outcome->body_sha256'))->exists()) {
                return false;
            }
            if ($run->state === RunState::Preview) {
                $run->update(['state' => RunState::Stopped, 'blocked_reason' => 'Mesh preview requires fresh ranked admission.']);
            }
            if (isset($run->plan['mesh_allowance']) && app(MeshGovernor::class)->release($run->plan['mesh_allowance'], $deadline)) {
                $plan = $run->plan;
                unset($plan['mesh_allowance']);
                $run->update(['plan' => $plan]);
            }

            return true;
        };
        $lock = app(HostLock::class);
        $key = 'agent-'.$agent->id;

        return $lock->holds($key) ? $work() : $lock->scoped($key, $work);
    }

    public function cleanRetained(CampaignEpisode $episode, Agent $agent): void
    {
        foreach (AutomationRun::where('campaign_episode_id', $episode->id)->where('agent_id', $agent->id)
            ->where('reset_id', $agent->reset_id)->whereNotNull('plan->mesh_work')->whereNull('started_at')
            ->where(fn ($query) => $query->where('state', 'preview')->orWhere(fn ($stopped) => $stopped->where('state', 'stopped')->whereNotNull('plan->mesh_allowance')))->get() as $run) {
            $this->invalidatePreview($agent, $run);
        }
    }

    public static function policy(CampaignEpisode $episode): ?array
    {
        return app(CampaignFleet::class)->probePolicy($episode)['mesh'] ?? null;
    }

    public function hubs(Agent $agent, ?string $system): Collection
    {
        $observations = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id);
        $prices = (clone $observations)->where('kind', 'market')->get()->keyBy('symbol');
        $yards = $this->yards($agent, $system);

        return (clone $observations)->where('kind', 'market_listing')->orderBy('symbol')->get()
            ->filter(fn (GameObservation $row) => $system !== null && FleetClaims::systemOf($row->symbol) === $system)
            ->mapWithKeys(function (GameObservation $row) use ($prices, $yards) {
                $goods = array_diff(array_unique(array_column(array_merge(
                    $row->data['imports'] ?? [], $row->data['exports'] ?? [], $row->data['exchange'] ?? [],
                ), 'symbol')), ['FUEL', 'ANTIMATTER']);
                $quote = $prices->get($row->symbol);

                return [$row->symbol => ['symbol' => $row->symbol, 'goods' => count($goods), 'shipyard' => $yards->has($row->symbol),
                    'prices_unknown' => empty($quote?->data['tradeGoods']), 'quote' => $quote]];
            })->filter(fn (array $hub) => $hub['goods'] > 0);
    }

    public function yards(Agent $agent, ?string $system): Collection
    {
        return GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'waypoint')
            ->orderBy('symbol')->get()
            ->filter(fn (GameObservation $point) => $system !== null && ($point->data['systemSymbol'] ?? null) === $system
                && in_array('SHIPYARD', array_column($point->data['traits'] ?? [], 'symbol'), true))->keyBy('symbol');
    }

    public function target(CampaignEpisode $episode, Agent $agent): array
    {
        $fleet = app(CampaignFleet::class);
        $command = $fleet->role($episode, 'command', pin: false);
        $system = $command['nav']['systemSymbol'] ?? null;
        $probes = collect($fleet->probes($episode, pin: false));
        $hubs = $this->hubs($agent, $system);
        $yards = $this->yards($agent, $system);
        $policy = self::policy($episode);
        $traders = app(CampaignSaturation::class)->traderSymbols($episode, $agent);
        $hubTarget = min($hubs->count(), ($policy['base'] ?? 0) + ($policy['per_trader'] ?? 0) * max(0, count($traders) - 1));
        $parked = $probes->reject(fn (array $probe) => $probe['nav']['status'] === 'IN_TRANSIT')
            ->groupBy('nav.waypointSymbol');
        $stations = Ship::where('agent_id', $agent->id)->whereNotNull('stationed_waypoint')->get()
            ->filter(fn (Ship $ship) => $fleet->isProbe($ship->snapshot) && $ship->snapshot['nav']['status'] !== 'IN_TRANSIT'
                && $ship->stationed_waypoint === $ship->snapshot['nav']['waypointSymbol'] && $hubs->has($ship->stationed_waypoint))
            ->pluck('stationed_waypoint')->unique()->values();
        $offset = $stations->reject(fn ($symbol) => $yards->has($symbol))->count();
        $coverage = max(0, $hubTarget - $offset);
        $usableYards = $this->eligibleYards($agent, $yards);
        if ($usableYards->count() === 1 && ! $hubs->has($usableYards->first()->symbol)) {
            $coverage++;
        } elseif ($usableYards->keys()->contains(fn ($symbol) => $hubs->has($symbol) && ! $parked->has($symbol))) {
            $coverage = max(1, $coverage);
        }
        $covered = $hubs->keys()->filter(fn ($symbol) => $parked->has($symbol) || $stations->contains($symbol))->values();

        if (isset($policy['saturation'])) {
            $model = $this->model($episode, $agent);
            $coverage = count($model->targets);
        }

        return ['system' => $system, 'hubs' => $hubs, 'yards' => $usableYards, 'parked' => $parked, 'probes' => $probes,
            'traders' => $traders, 'covered' => $covered, 'hub_target' => $hubTarget, 'stationed_hubs' => $offset,
            'pool_probe_target' => min($policy['max_probes'] ?? 0, $coverage), 'pool_probe_count' => $probes->count()]
            + (isset($model) ? ['mesh_model' => [
                'targets' => count($model->targets), 'tiers' => array_count_values(array_column($model->targets, 'tier')),
                'clusters' => count(array_unique(array_column($model->buckets, 'cluster'))), 'stations' => $model->stations,
            ]] : []);
    }

    private function eligibleYards(Agent $agent, Collection $yards): Collection
    {
        $offers = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'shipyard')->get()->keyBy('symbol');

        return $yards->filter(function (GameObservation $point) use ($offers) {
            $yard = $offers->get($point->symbol);

            return ! $yard || collect(CampaignProbeBootstrap::PURCHASE_TYPES)->contains(
                fn ($type) => app(CampaignProbeBootstrap::class)->offers($yard->data, $type));
        });
    }

    public function demand(CampaignEpisode $episode, Agent $agent): array
    {
        $active = [];
        $ranked = [];
        $claims = app(FleetClaims::class);
        foreach (CampaignEpisode::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->whereNull('closed_at')->get() as $other) {
            foreach ($claims->pairs($other) as $pair) {
                $active[] = $pair['source'] ?? null;
                $active[] = $pair['buyer'] ?? null;
            }
            $decision = CampaignEvent::where('campaign_episode_id', $other->id)->where('kind', 'decision')
                ->whereNotNull('data->candidates')->latest('id')->first();
            foreach (collect($decision?->data['candidates'] ?? [])->filter(fn ($pair) => isset($pair['source'], $pair['buyer'], $pair['score']))->take(3) as $candidate) {
                $ranked[] = $candidate['source'];
                $ranked[] = $candidate['buyer'];
            }
        }
        $active[] = $episode->state['active_buyer'] ?? null;

        return array_values(array_unique(array_filter([...$active, ...$ranked])));
    }

    public function refresh(CampaignEpisode $episode, Agent $agent, array &$read, float $deadline, ?string $buyer = null, ?array &$detailedRead = null): void
    {
        $policy = self::policy($episode);
        if (isset($policy['saturation'])) {
            app(MeshPoller::class)->refresh($episode, $agent, $read, $deadline, $buyer, detailedRead: $detailedRead);

            return;
        }
        $target = $this->target($episode, $agent);
        $demand = array_filter([...$this->demand($episode, $agent), $buyer]);
        $occupied = Ship::where('agent_id', $agent->id)->get()
            ->reject(fn (Ship $ship) => $ship->snapshot['nav']['status'] === 'IN_TRANSIT')
            ->groupBy('snapshot.nav.waypointSymbol');
        $due = [];
        foreach ($target['hubs'] as $symbol => $hub) {
            if (isset($read[$symbol]) || ! $occupied->has($symbol)) {
                continue;
            }
            $quote = $hub['quote'];
            $needed = in_array($symbol, $demand, true);
            $urgent = $hub['prices_unknown'] || isset($quote?->data['invalidated_by_action']);
            $age = $quote ? $quote->observed_at->diffInSeconds(now(), false) : INF;
            if ($urgent || $age >= $policy[$needed ? 'demand_read_interval_seconds' : 'read_interval_seconds']) {
                $due[] = ['symbol' => $symbol, 'priority' => $urgent ? 0 : ($needed ? 1 : 2), 'age' => -$age];
            }
        }
        usort($due, fn ($a, $b) => [$a['priority'], $a['age'], $a['symbol']] <=> [$b['priority'], $b['age'], $b['symbol']]);
        foreach ($due as $entry) {
            if (count($read) >= $policy['reads_per_pass'] || microtime(true) >= $deadline) {
                return;
            }
            $symbol = $entry['symbol'];
            $read[$symbol] = true;
            try {
                $observer = $occupied->get($symbol)->sortBy('symbol')->first()->symbol;
                $data = FleetMetrics::as($observer, fn () => app(SpaceTradersApi::class)->market($agent, $symbol));
                app(AgentSynchronizer::class)->observe($agent, 'market', $symbol, $data);
                if (! empty($data['tradeGoods'])) {
                    $detailedRead[$symbol] = true;
                }
            } catch (GameException $exception) {
                if ($exception->retryAfter > 0) {
                    return;
                }
            }
        }
    }

    public function assignments(CampaignEpisode $episode, Agent $agent, array $busy, array $reserved, ?string $buyer): array
    {
        $policy = self::policy($episode);
        if (isset($policy['saturation'])) {
            $probes = collect($this->probes($episode, $agent))->keyBy('symbol');
            $plan = $this->dispatchPlan($episode, $agent, $busy, $reserved, $buyer, []);

            return array_map(fn ($move) => [$probes[$move['ship']], $move['destination']], array_slice($plan['moves'], 0, $policy['dispatches_per_pass']));
        }
        $mesh = $this->target($episode, $agent);
        $hubs = $mesh['hubs'];
        $parked = $mesh['parked'];
        $demand = array_unique(array_filter([$buyer, ...$this->demand($episode, $agent)]));
        $discovering = $episode->phase === 'discover' || $hubs->contains('prices_unknown', true);
        $purchaser = $mesh['yards']->keys()->map(fn ($yard) => $parked->get($yard)?->first())->filter()->first()['symbol'] ?? null;
        foreach ($mesh['probes'] as $probe) {
            if ($probe['nav']['status'] === 'IN_TRANSIT') {
                $reserved[] = $probe['nav']['route']['destination']['symbol'] ?? null;
            }
        }
        $anchors = $parked->filter(fn ($rows, $symbol) => $hubs->has($symbol))->map(fn ($rows) => $rows->first()['symbol'])->all();
        $targets = $hubs->filter(fn ($hub, $symbol) => ! in_array($symbol, $reserved, true)
            && ($discovering ? $hub['prices_unknown'] && ! $parked->has($symbol) : ! $mesh['covered']->contains($symbol)))
            ->sortBy(fn ($hub) => [array_search($hub['symbol'], $demand, true) !== false ? array_search($hub['symbol'], $demand, true) : PHP_INT_MAX,
                ! $hub['shipyard'], -$hub['goods'], $hub['symbol']]);
        if ($buyer && $mesh['covered']->count() < $hubs->count() && $hubs->has($buyer) && ! in_array($buyer, $reserved, true)
            && ! $mesh['covered']->contains($buyer)) {
            $targets = collect([$buyer => $hubs->get($buyer)])->union($targets);
        }
        $available = $mesh['probes']->filter(fn (array $probe) => $probe['nav']['status'] !== 'IN_TRANSIT' && $probe['cargo']['units'] === 0
            && $probe['symbol'] !== $purchaser && ! in_array($probe['symbol'], $busy, true));
        $points = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'waypoint')->get()->keyBy('symbol');
        $assignments = [];
        foreach ($targets as $symbol => $hub) {
            if (count($assignments) >= $policy['dispatches_per_pass']) {
                break;
            }
            $movable = $available->filter(function (array $probe) use ($anchors, $hubs, $demand, $buyer, $symbol, $discovering) {
                $origin = $probe['nav']['waypointSymbol'];
                if ($origin === $symbol) {
                    return false;
                }
                if (($anchors[$origin] ?? null) !== $probe['symbol']) {
                    return true;
                }

                return ($discovering || $symbol === $buyer) && ! ($hubs->get($origin)['shipyard'] ?? false)
                    && ($discovering ? $origin !== $buyer : ! in_array($origin, $demand, true)) && ! ($hubs->get($origin)['prices_unknown'] ?? false);
            });
            $probe = $movable->sortBy(function (array $probe) use ($points, $symbol, $anchors, $hubs) {
                $origin = $probe['nav']['waypointSymbol'];
                $from = $points->get($origin)?->data;
                $to = $points->get($symbol)?->data;
                $anchored = ($anchors[$origin] ?? null) === $probe['symbol'];
                $distance = $from && $to ? hypot($from['x'] - $to['x'], $from['y'] - $to['y']) : INF;

                return [$anchored, $anchored ? ($hubs->get($origin)['goods'] ?? 0) : 0, $distance, $probe['symbol']];
            })->first();
            if ($probe) {
                $assignments[] = [$probe, $symbol];
                $available = $available->reject(fn ($ship) => $ship['symbol'] === $probe['symbol']);
            }
        }

        return $assignments;
    }

    public function assertYardCutoff(CampaignEpisode $episode, Agent $agent, array $ship, string $yard): void
    {
        $grant = $episode->grant();
        $policy = CampaignProbeBootstrap::policy($grant, $episode);
        if (! $policy) {
            throw new GameException('Mesh yard positioning lacks purchase authority.');
        }
        $secondsLeft = (int) now()->diffInSeconds(app(ProbeYardSelector::class)->cutoff($episode, $grant, $policy), false);
        if ($secondsLeft <= 0 || app(MeshPlanner::class)->route($this->model($episode, $agent), $ship, $yard, $secondsLeft) === null) {
            throw new GameException('Mesh yard positioning exceeds the purchase cutoff.');
        }
    }

    public function authorizeScout(CampaignEpisode $episode, Agent $agent, array $ship, array $plan): void
    {
        $grant = $episode->grant();
        if (! isset(self::policy($episode)['saturation']) || ! $grant->available() || $grant->isDraining() || $episode->closed_at
            || $grant->user_id !== $agent->user_id || $grant->resolvedAgentSymbol() !== $agent->name
            || ! hash_equals($grant->revision_hash, $grant->revision()->content_hash)
            || app(CampaignRecovery::class)->settlementOnly($episode)
            || $episode->agent_id !== $agent->id || $episode->reset_id !== $agent->reset_id
            || ($plan['mesh_episode'] ?? null) !== $episode->id || ($plan['strategy'] ?? null) !== 'scout'
            || empty($plan['one_way']) || ($plan['max_fuel'] ?? null) !== 0
            || ($plan['max_spend'] ?? null) !== 0 || ($plan['max_loss'] ?? null) !== 0) {
            throw new GameException('Mesh scout lacks bound campaign authority.');
        }
        $model = $this->model($episode, $agent);
        $destination = $plan['destination'] ?? '';
        $yard = ($plan['observe_kind'] ?? 'market') === 'shipyard';
        if (($yard ? ! $this->yards($agent, $model->system)->has($destination) : ! isset($model->targets[$destination]))
            || app(MeshPlanner::class)->route($model, $ship, $destination, $this->secondsLeft($episode, $grant)) === null) {
            throw new GameException('Mesh scout requires a verified target and fuel-free route within campaign cutoff.');
        }
        $this->assertPool($episode, $agent, $ship);
        if ($yard) {
            $this->assertYardCutoff($episode, $agent, $ship, $destination);
        }
    }

    public function scoutMinutes(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $ship, array $plan): int
    {
        $destination = $plan['destination'] ?? null;
        $seconds = $plan['route']['outbound']['estimated_seconds'] ?? null;
        $eligible = self::policy($episode) && ($plan['strategy'] ?? null) === 'scout' && ! empty($plan['one_way'])
            && app(FuelPlanner::class)->fuelFree($ship) && is_string($destination)
            && FleetClaims::systemOf($destination) === $ship['nav']['systemSymbol'];
        $observeYard = ($plan['observe_kind'] ?? 'market') === 'shipyard';
        $saturation = isset(self::policy($episode)['saturation']);
        if ($saturation) {
            $this->authorizeScout($episode, $agent, $ship, $plan);
            $route = $plan['route'] ?? [];
            $model = $this->model($episode, $agent);
            $expected = app(MeshPlanner::class)->route($model, $ship, $destination, $this->secondsLeft($episode, $grant));
            if ($expected !== $seconds || ($route['market'] ?? null) !== $destination
                || ($route['site'] ?? null) !== $ship['nav']['waypointSymbol']
                || ($route['fuel_estimate'] ?? null) !== 0 || ($route['fuel_ceiling'] ?? null) !== 0) {
                throw new GameException('Mesh scout route differs from the verified exact fuel-free leg.');
            }
        }
        if (! $eligible || ! is_numeric($seconds) || $seconds < 0
            || (! $saturation && ! ($observeYard ? $this->yards($agent, $ship['nav']['systemSymbol']) : $this->hubs($agent, $ship['nav']['systemSymbol']))->has($destination))) {
            throw new GameException('Mesh positioning requires a verified one-way fuel-free route to a hub or shipyard.');
        }
        $required = (int) ceil(($seconds + 180) / 60);
        $windDown = $episode->next_reset_at->subHour();
        $cutoff = $grant->expires_at ? $windDown->min($grant->expires_at) : $windDown;
        $minutes = (int) floor(now()->diffInSeconds($cutoff, false) / 60);
        $cap = min(90, max($grant->limits['run_max_minutes'], $required), $minutes);
        if ($required > $cap || (isset($plan['minutes']) && ($plan['minutes'] < $required || $plan['minutes'] > $cap))) {
            throw new GameException('Verified positioning duration exceeds available time before campaign cutoff.');
        }

        return $required;
    }
}
