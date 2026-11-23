<?php

namespace App\Gameplay;

use App\Enums\RunState;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\ObservationHistory;
use App\Models\ReconMission;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Minute-0 probe bootstrap: bounded SHIP_PROBE purchases under opt-in C0 playbook authority. */
class CampaignProbeBootstrap
{
    public const SHIPYARD_BACKOFF_MINUTES = 30;

    public const MAX_SHIPYARD_READS_PER_PASS = 2;

    public static function policy(CampaignGrant $grant, CampaignEpisode $episode): ?array
    {
        if ($grant->isDraining() || $grant->slot !== 1 || $grant->arm() || ! $grant->available() || $episode->closed_at
            || $episode->campaign_grant_id !== $grant->id || ! hash_equals($grant->revision_hash, $grant->revision()->content_hash)
            || app(CampaignSafety::class)->windDown($episode)) {
            return null;
        }
        $policy = $grant->revision()->body['probes'] ?? null;
        if (! is_array($policy)) {
            return null;
        }

        return [
            'target' => $policy['target'],
            'max_price' => $policy['max_price'],
            'min_working_capital' => $policy['min_working_capital'],
            'pool' => (bool) ($policy['pool'] ?? true),
        ];
    }

    public function ownedProbeCount(Agent $agent): int
    {
        $fleet = app(CampaignFleet::class);

        return Ship::where('agent_id', $agent->id)->get()->filter(fn ($ship) => $fleet->isProbe($ship->snapshot))->count();
    }

    public function inFlightProbePurchases(Agent $agent, CampaignEpisode $episode, ?int $ignoreRunId = null): int
    {
        $activeRuns = AutomationRun::where('agent_id', $agent->id)
            ->where('campaign_episode_id', $episode->id)
            ->where('strategy', 'buy_probe')
            ->when($ignoreRunId !== null, fn ($query) => $query->where('id', '!=', $ignoreRunId))
            ->whereNotNull('active_agent_id')
            ->count();

        $fleet = app(CampaignFleet::class);
        $ownedProbes = Ship::where('agent_id', $agent->id)->get()
            ->filter(fn ($ship) => $fleet->isProbe($ship->snapshot))
            ->keyBy('symbol');
        $confirmedDeliveries = ShipAction::where('agent_id', $agent->id)
            ->where('reset_id', $agent->reset_id)
            ->where('command', 'buy_ship')
            ->whereIn('state', ['confirmed', 'acknowledged'])
            ->get()
            ->map(fn ($action) => $action->outcome['delivered_ship'] ?? $action->outcome['bootstrap_delivered_probe'] ?? null)
            ->filter()->all();
        $attributedLateProbes = [];

        $shipyardObs = GameObservation::where('agent_id', $agent->id)
            ->where('reset_id', $agent->reset_id)
            ->where('kind', 'shipyard')
            ->get()
            ->keyBy('symbol');

        $pendingActions = ShipAction::where('agent_id', $agent->id)
            ->where('reset_id', $agent->reset_id)
            ->where('command', 'buy_ship')
            ->whereIn('state', ['pending', 'sending', 'uncertain', 'acknowledged'])
            ->orderBy('id')
            ->get()
            ->filter(function ($action) use ($agent, $ignoreRunId, $ownedProbes, $confirmedDeliveries, $shipyardObs, &$attributedLateProbes) {
                if (($action->arguments['shipType'] ?? null) !== 'SHIP_PROBE') {
                    return false;
                }
                if ($ignoreRunId !== null && $action->automation_run_id === $ignoreRunId) {
                    return false;
                }
                if ($action->state->value === 'acknowledged') {
                    if (! empty($action->outcome['delivered_ship']) || ! empty($action->outcome['bootstrap_delivered_probe']) || ! empty($action->outcome['confirmed_not_sent'])) {
                        return false;
                    }
                    $beforeFleet = $action->before_state['estimate']['fleet_symbols'] ?? null;
                    $shipyard = $action->arguments['waypointSymbol'] ?? null;
                    if (! is_array($beforeFleet) || ! is_string($shipyard) || ! $action->sent_at) {
                        return true;
                    }
                    $reconciledFleet = collect($action->outcome['fleet'] ?? [])->keyBy('symbol');
                    $deliveryCandidates = $action->outcome['probe_delivery_candidates'] ?? [];
                    foreach ($ownedProbes as $symbol => $shipModel) {
                        if (! in_array($symbol, $beforeFleet, true)
                            && (($reconciledFleet->get($symbol)['nav']['waypointSymbol'] ?? null) === $shipyard
                                || ($shipModel->snapshot['nav']['waypointSymbol'] ?? null) === $shipyard)
                            && ! in_array($symbol, $deliveryCandidates, true)) {
                            $deliveryCandidates[] = $symbol;
                        }
                    }
                    // Keep delivery location evidence before the pool can dispatch the new probe.
                    if ($deliveryCandidates !== ($action->outcome['probe_delivery_candidates'] ?? [])) {
                        $action->update(['outcome' => array_merge($action->outcome ?? [], ['probe_delivery_candidates' => $deliveryCandidates])]);
                    }
                    $transactions = array_merge(
                        is_array($action->outcome['transactions'] ?? null) ? $action->outcome['transactions'] : [],
                        is_array($shipyardObs->get($shipyard)?->data['transactions'] ?? null) ? $shipyardObs->get($shipyard)->data['transactions'] : [],
                    );
                    $matchingTx = $this->matchingProbeTransaction($agent, $action, $transactions);
                    if ($matchingTx) {
                        foreach ($deliveryCandidates as $symbol) {
                            if ($ownedProbes->has($symbol)
                                && ! in_array($symbol, $beforeFleet, true)
                                && ! in_array($symbol, $confirmedDeliveries, true)
                                && ! in_array($symbol, $attributedLateProbes, true)) {
                                $attributedLateProbes[] = $symbol;
                                $action->update(['outcome' => array_merge($action->outcome ?? [], ['bootstrap_delivered_probe' => $symbol, 'bootstrap_delivery_transaction' => $matchingTx])]);

                                return false;
                            }
                        }
                    }

                    return true;
                }

                return ! AutomationRun::whereKey($action->automation_run_id)->whereNotNull('active_agent_id')->exists();
            })
            ->count();

        return $activeRuns + $pendingActions;
    }

    private function matchingProbeTransaction(Agent $agent, ShipAction $action, array $transactions): ?array
    {
        $shipyard = $action->arguments['waypointSymbol'] ?? null;
        if (! is_string($shipyard) || ! $action->sent_at) {
            return null;
        }

        return collect($transactions)->first(function ($tx) use ($agent, $shipyard, $action) {
            if (! is_array($tx)
                || ($tx['waypointSymbol'] ?? null) !== $shipyard
                || (($tx['shipType'] ?? $tx['shipSymbol'] ?? null) !== 'SHIP_PROBE')
                || ($tx['agentSymbol'] ?? null) !== $agent->name
                || ! is_string($tx['timestamp'] ?? null)
                || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/', $tx['timestamp'])) {
                return false;
            }
            if (! checkdate((int) substr($tx['timestamp'], 5, 2), (int) substr($tx['timestamp'], 8, 2), (int) substr($tx['timestamp'], 0, 4))
                || (int) substr($tx['timestamp'], 11, 2) > 23
                || (int) substr($tx['timestamp'], 14, 2) > 59
                || (int) substr($tx['timestamp'], 17, 2) > 59) {
                return false;
            }
            try {
                return CarbonImmutable::parse($tx['timestamp'])->gte($action->sent_at->subSeconds(5));
            } catch (\Throwable) {
                return false;
            }
        });
    }

    public function releasePurchase(ShipAction $action, User $user, bool $dryRun = false): array
    {
        return app(HostLock::class)->scoped('agent-'.$action->agent_id, fn () => DB::transaction(function () use ($action, $user, $dryRun) {
            $action->refresh();
            $agent = $user->agents()->find($action->agent_id);
            if (! $agent) {
                throw new GameException('This operator does not operate the action agent.');
            }
            if ($action->reset_id !== $agent->reset_id || $action->command !== 'buy_ship'
                || ($action->arguments['shipType'] ?? null) !== 'SHIP_PROBE' || $action->state->value !== 'acknowledged') {
                throw new GameException('Only an acknowledged SHIP_PROBE buy_ship action in the current reset can be released.');
            }
            if (isset($action->outcome['confirmed_not_sent'])) {
                throw new GameException('This purchase was already released.');
            }
            if (! empty($action->outcome['delivered_ship']) || ! empty($action->outcome['bootstrap_delivered_probe'])) {
                throw new GameException('The purchase already has delivery evidence.');
            }
            // Successful write receipt markers are stored before validation, even when sanitized data is empty.
            $outcome = $action->outcome ?? [];
            if (array_key_exists('response', $outcome) || array_key_exists('body_sha256', $outcome)
                || array_key_exists('response_received_at', $outcome) || array_key_exists('http_status', $outcome)
                || array_key_exists('response_validated', $outcome)) {
                throw new GameException('The slot stays held because stored receipt evidence shows the purchase may have happened. Syncing cannot clear this evidence.');
            }
            $beforeFleet = $action->before_state['estimate']['fleet_symbols'] ?? null;
            $shipyard = $action->arguments['waypointSymbol'] ?? null;
            if (! is_array($beforeFleet) || ! is_string($shipyard) || ! $action->sent_at) {
                throw new GameException('Purchase baseline is incomplete; cannot rule out a delivery. Sync the agent first.');
            }
            $transactions = is_array($action->outcome['transactions'] ?? null) ? $action->outcome['transactions'] : [];
            foreach ([GameObservation::class, ObservationHistory::class] as $observations) {
                foreach ($observations::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                    ->where('kind', 'shipyard')->where('symbol', $shipyard)->cursor() as $observation) {
                    $transactions = array_merge($transactions, is_array($observation->data['transactions'] ?? null) ? $observation->data['transactions'] : []);
                }
            }
            if ($this->matchingProbeTransaction($agent, $action, $transactions)) {
                throw new GameException('A matching shipyard transaction was recorded; the slot stays held because stored evidence shows the purchase may have happened. Syncing cannot clear this evidence.');
            }
            $attributed = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                ->where('command', 'buy_ship')->whereIn('state', ['confirmed', 'acknowledged'])->get()
                ->flatMap(fn ($purchase) => [$purchase->outcome['delivered_ship'] ?? null, $purchase->outcome['bootstrap_delivered_probe'] ?? null])
                ->filter()->all();
            $fleet = app(CampaignFleet::class);
            $newProbe = Ship::where('agent_id', $agent->id)->get()->first(fn ($ship) => $fleet->isProbe($ship->snapshot)
                && ! in_array($ship->symbol, $beforeFleet, true) && ! in_array($ship->symbol, $attributed, true));
            if ($newProbe) {
                throw new GameException('Unattributed new probe '.$newProbe->symbol.' may be this delivery. Sync the agent first.');
            }
            $confirmation = ['user_id' => $user->id, 'at' => now()->toIso8601String(), 'reason' => 'operator release'];
            if (! $dryRun) {
                $action->update(['outcome' => array_merge($action->outcome ?? [], ['confirmed_not_sent' => $confirmation])]);
                $run = AutomationRun::find($action->automation_run_id);
                $episode = $run ? CampaignEpisode::find($run->campaign_episode_id) : null;
                if ($episode) {
                    $grant = CampaignGrant::find($episode->campaign_grant_id)
                        ?? throw new GameException('Campaign grant not found; the purchase cannot be released.');
                    app(CampaignControl::class)->event($grant, $episode,
                        'probe_purchase_released', ['action_id' => $action->id] + $confirmation);
                }
            }

            return ['action_id' => $action->id, 'released' => ! $dryRun, 'dry_run' => $dryRun, 'confirmed_not_sent' => $confirmation];
        }));
    }

    public function effectiveReserve(Agent $agent, CampaignGrant $grant, array $policy, ?int $ignoreRunId = null): int
    {
        $commitments = AutomationRun::where('active_agent_id', $agent->id)
            ->where('strategy', '!=', 'gate_recon')
            ->when($ignoreRunId !== null, fn ($query) => $query->where('id', '!=', $ignoreRunId))
            ->get()
            ->sum(function ($run) use ($agent) {
                if ($run->strategy === 'trade' && ($run->totals['phase'] ?? null) === 'buy') {
                    return max(0, (int) ($run->plan['max_spend'] ?? 0) - (int) ($run->totals['spending'] ?? 0));
                }
                $ship = Ship::where('agent_id', $agent->id)->where('symbol', $run->ship_symbol)->first()?->snapshot;
                if (($ship['cargo']['units'] ?? 0) < 1 || ! isset($run->plan['market'])) {
                    return 0;
                }
                try {
                    return app(CampaignTrade::class)->fuelReserve($agent, $run->ship_symbol, $run->plan['market']);
                } catch (GameException $e) {
                    // As with manual spending, stale delivery quotes preserve the approved cash ceilings.
                    $planned = $run->plan['campaign_trade'] ?? [];
                    if (! isset($planned['fuel_reserve']) && ! isset($planned['fuel_safety_ceiling'])
                        && ! isset($run->plan['route']['fuel_ceiling'])) {
                        throw new GameException('Ship '.$run->ship_symbol.' carries cargo and its delivery fuel reserve cannot be verified right now ('.$e->getMessage().'). Retry once its fuel quotes are re-read.');
                    }

                    return (int) max($planned['fuel_reserve'] ?? 0, $planned['fuel_safety_ceiling'] ?? 0, $run->plan['route']['fuel_ceiling'] ?? 0);
                }
            });

        return $grant->limits['reserve_credits']
            + $policy['min_working_capital']
            + $commitments
            + ReconMission::returnExposure($agent);
    }

    /**
     * Plans a single bounded buy_probe preview if the agent is below target probes and an eligible
     * idle ship can safely reach a SHIP_PROBE shipyard within price and working-capital bounds.
     *
     * @param  list<string>  $busyShips
     */
    public function plan(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $busyShips, array &$considered, ?float $deadline = null): ?AutomationRun
    {
        $policy = self::policy($grant, $episode);
        if (! $policy) {
            return null;
        }
        $owned = $this->ownedProbeCount($agent);
        $inFlight = $this->inFlightProbePurchases($agent, $episode);
        if ($owned < $policy['target'] && $owned + $inFlight >= $policy['target']) {
            $this->refreshAcknowledgedShipyards($grant, $episode, $agent, $deadline);
            $inFlight = $this->inFlightProbePurchases($agent, $episode);
        }
        if ($owned + $inFlight >= $policy['target']) {
            return null;
        }
        $fleet = app(CampaignFleet::class);
        $command = $fleet->role($episode, 'command');
        $probes = $fleet->probes($episode);
        $system = $command['nav']['systemSymbol'] ?? ($probes[0]['nav']['systemSymbol'] ?? null);
        if (! $system) {
            $considered[] = ['strategy' => 'buy_probe', 'rejected' => 'No home system ship available for probe bootstrap.'];

            return null;
        }

        $eligibleShips = $this->eligibleShips($agent, $episode, $command, $probes, $busyShips, $system);
        if ($eligibleShips === []) {
            return null;
        }

        $reserve = $this->effectiveReserve($agent, $grant, $policy);
        if ($agent->credits <= $reserve) {
            $considered[] = ['strategy' => 'buy_probe', 'rejected' => 'Credits do not clear working-capital reserve of '.$reserve.' CR.'];

            return null;
        }

        $shipyards = $this->discoverShipyards($grant, $episode, $agent, $system, $eligibleShips, $deadline);
        if ($shipyards === []) {
            $considered[] = ['strategy' => 'buy_probe', 'rejected' => 'No SHIP_PROBE shipyard observed yet in '.$system.'.'];

            return null;
        }

        $points = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', 'waypoint')->get()->keyBy('symbol');
        $candidates = $this->rankCandidates($episode, $agent, $policy, $reserve, $shipyards, $eligibleShips, $points, $considered);
        foreach ($candidates as $candidate) {
            try {
                if (CampaignLocalEarn::policy($grant, $episode) && $deadline !== null) {
                    app(CampaignLocalEarn::class)->reserve($deadline);
                }
                $limits = [
                    'reserve' => $reserve,
                    'max_loss' => $policy['max_price'],
                    'max_spend' => min(max(0, $agent->credits - $reserve), $policy['max_price']),
                    'minutes' => min($grant->limits['run_max_minutes'], max(1, (int) now()->diffInMinutes($episode->next_reset_at, false))),
                    'cycles' => 1,
                ];

                return app(AutomationRunner::class)->previewProbePurchase($agent, $candidate['ship']['symbol'], $candidate['shipyard'], $limits, $episode);
            } catch (GameException $exception) {
                if ($exception instanceof LocalEarnBudget || $exception->retryAfter > 0) {
                    throw $exception;
                }
                $considered[] = ['strategy' => 'buy_probe', 'ship' => $candidate['ship']['symbol'], 'shipyard' => $candidate['shipyard'], 'rejected' => $exception->getMessage()];
            }
        }

        return null;
    }

    public function assertPurchaseBudget(Agent $agent): void
    {
        if (RequestBudget::agentCaps() === []) {
            return;
        }

        $symbol = strtoupper($agent->name);
        $episodes = CampaignEpisode::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->pluck('id')->all();
        $until = now()->toImmutable();
        $row = app(FleetMetrics::class)->byAgent([
            ['agent_symbol' => $symbol, 'qualified_label' => $symbol.':C0', 'episodes' => $episodes],
        ], $until->subHours(6), $until)[$symbol] ?? [];
        if (isset($row['purchase_hold_reason']) || ! isset($row['formula_max_traders'])) {
            throw new GameException('Fleet purchase held for '.$symbol.': '.($row['purchase_hold_reason'] ?? 'missing_request_budget_ceiling').'.');
        }

        $ships = Ship::where('agent_id', $agent->id)->get();
        if ($ships->isEmpty()) {
            throw new GameException('Fleet purchase held for '.$symbol.': missing verified fleet.');
        }
        $traded = AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('strategy', 'trade')->whereNotNull('started_at')->distinct()->pluck('ship_symbol')->flip();
        $fleet = app(CampaignFleet::class);
        $traders = $ships->filter(function (Ship $ship) use ($fleet, $traded) {
            $snapshot = $ship->snapshot;

            return ! $fleet->isProbe($snapshot) && ($traded->has($ship->symbol)
                || in_array($snapshot['registration']['role'] ?? null, ['COMMAND', 'HAULER', 'TRANSPORT'], true)
                || in_array($snapshot['frame']['symbol'] ?? null, ['FRAME_SHUTTLE', 'FRAME_LIGHT_FREIGHTER', 'FRAME_HEAVY_FREIGHTER', 'FRAME_BULK_FREIGHTER'], true));
        })->count();
        // A probe adds no traders, but an already over-cap fleet cannot add purchase overhead.
        if ($traders > $row['formula_max_traders']) {
            throw new GameException('Fleet purchase held for '.$symbol.': '.$traders.' traders exceed trader ceiling '.$row['formula_max_traders'].'.');
        }
    }

    public function compose(Agent $agent, string $symbol, string $shipyard, array $limits, CampaignEpisode $episode, ?int $ignoreRunId = null): array
    {
        $grant = $episode->grant();
        $policy = self::policy($grant, $episode);
        if (! $policy) {
            throw new GameException('Probe bootstrap is outside current C0 campaign authority.');
        }
        app(AgentSynchronizer::class)->sync($agent, false);
        $this->assertPurchaseBudget($agent);
        $ship = Ship::where('agent_id', $agent->id)->where('symbol', $symbol)->firstOrFail()->snapshot;
        if ($ship['nav']['status'] === 'IN_TRANSIT' || $ship['cargo']['units'] !== 0) {
            throw new GameException('Probe purchase requires a stationary ship with empty cargo.');
        }
        if (ReconMission::holds($agent, $symbol)) {
            throw new GameException('Ship is held by a gate-recon mission.');
        }
        $owned = $this->ownedProbeCount($agent);
        $inFlight = $this->inFlightProbePurchases($agent, $episode, $ignoreRunId);
        if ($owned + $inFlight >= $policy['target']) {
            throw new GameException('Probe target of '.$policy['target'].' is already satisfied or in flight.');
        }
        $point = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', 'waypoint')->where('symbol', $shipyard)->first();
        if (! $point || ($point->data['systemSymbol'] ?? null) !== $ship['nav']['systemSymbol']
            || ! in_array('SHIPYARD', array_column($point->data['traits'] ?? [], 'symbol'), true)) {
            throw new GameException('Probe purchase requires an observed same-system shipyard.');
        }

        $atYard = $ship['nav']['waypointSymbol'] === $shipyard;
        $yardObs = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', 'shipyard')->where('symbol', $shipyard)->first();
        if ($atYard || ! $yardObs) {
            $yardData = app(SpaceTradersApi::class)->shipyard($agent, $shipyard);
            app(AgentSynchronizer::class)->observe($agent, 'shipyard', $shipyard, $yardData);
            $yardObs = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                ->where('kind', 'shipyard')->where('symbol', $shipyard)->first();
        }
        $yardData = $yardObs?->data ?? [];
        if (! $this->offersProbe($yardData)) {
            throw new GameException('Shipyard '.$shipyard.' does not list SHIP_PROBE.');
        }

        $reserve = max($limits['reserve'] ?? 0, $this->effectiveReserve($agent, $grant, $policy, $ignoreRunId));
        $fuel = app(FuelPlanner::class);
        $departureCeiling = 0;
        $outboundFuel = 0;
        $fuelCeiling = 0;
        if ($atYard) {
            $leg = ['destination' => $shipyard, 'continuation' => $shipyard, 'outbound_fuel' => 0, 'required_fuel' => 0, 'refuel_ceiling' => 0];
        } else {
            $listings = $fuel->listings($agent);
            $departure = $fuel->departureRefuel($agent, $ship, $listings);
            if ($departure) {
                $ceiling = min($departure['ceiling'], max(0, $agent->credits - $reserve));
                $fill = (int) ceil(($ship['fuel']['capacity'] - $ship['fuel']['current']) / 100) * $departure['quote']['purchasePrice'];
                if ($ceiling < $fill) {
                    $departure = null;
                } else {
                    $departure['ceiling'] = $ceiling;
                    $departure['agent']->credits = $agent->credits - $ceiling;
                    $departureCeiling = $ceiling;
                }
            }
            $leg = $fuel->leg($departure['agent'] ?? $agent, $departure['ship'] ?? $ship, $shipyard, $reserve, listings: $listings);
            $outboundFuel = $leg['outbound_fuel'];
            $fuelCeiling = $departureCeiling + $leg['refuel_ceiling'];
        }

        $offer = $this->probeOffer($yardData);
        if ($offer !== null) {
            $price = $offer['purchasePrice'];
            if ($price > $policy['max_price']) {
                $this->recordShipyardRejection($episode, $shipyard, 'max_price', $price);
                throw new GameException('SHIP_PROBE price '.$price.' CR exceeds max_price '.$policy['max_price'].' CR.');
            }
            if ($agent->credits - $price - $fuelCeiling < $reserve) {
                $this->recordShipyardRejection($episode, $shipyard, 'reserve', $price);
                throw new GameException('SHIP_PROBE purchase at '.$price.' CR breaches working-capital reserve '.$reserve.' CR.');
            }
        } elseif ($agent->credits - $fuelCeiling <= $reserve) {
            throw new GameException('Insufficient credits above working-capital reserve for probe purchase.');
        }

        $maxSpend = min(max(0, $agent->credits - $reserve), $policy['max_price'] + $fuelCeiling);
        if ($offer !== null && $maxSpend < $offer['purchasePrice'] + $fuelCeiling) {
            throw new GameException('Approved spending bound cannot cover probe purchase and fuel.');
        }

        $route = [
            'site' => $ship['nav']['waypointSymbol'],
            'market' => $shipyard,
            'outbound' => $leg,
            'departure_refuel_ceiling' => $departureCeiling,
            'fuel_estimate' => $outboundFuel,
            'fuel_ceiling' => $fuelCeiling,
        ];
        $plan = array_merge($limits, [
            'strategy' => 'buy_probe',
            'shipyard' => $shipyard,
            'ship_type' => 'SHIP_PROBE',
            'max_price' => $policy['max_price'],
            'min_working_capital' => $policy['min_working_capital'],
            'reserve' => $reserve,
            'max_spend' => $maxSpend,
            'max_loss' => $maxSpend,
            'max_fuel' => $ship['fuel']['capacity'],
            'cycles' => 1,
            'site' => $ship['nav']['waypointSymbol'],
            'market' => $shipyard,
            'route' => $route,
            'owned_probes' => $owned,
            'observed_price' => $offer['purchasePrice'] ?? null,
            'initial_credits' => $agent->credits,
            'initial_inventory' => MiningPlanner::inventory($ship),
            'initial_fuel' => $ship['fuel']['current'],
            'initial_waypoint' => $ship['nav']['waypointSymbol'],
            'initial_status' => $ship['nav']['status'],
            'initial_configuration' => FuelPlanner::configuration($ship),
            'equipment' => OpportunityEvaluator::equipment($ship),
            'participating_ships' => [$symbol],
        ]);
        $fingerprint = hash('sha256', json_encode([
            $agent->reset_id,
            $plan['initial_credits'],
            $plan['initial_inventory'],
            $plan['initial_fuel'],
            $plan['initial_waypoint'],
            $plan['initial_status'],
            $plan['initial_configuration'],
            $plan['shipyard'],
            $plan['max_price'],
            $plan['reserve'],
            $plan['owned_probes'],
            $plan['observed_price'],
        ]));

        return ['plan' => $plan, 'fingerprint' => $fingerprint];
    }

    public function next(Agent $agent, array $ship, AutomationRun $run): AutomationStep
    {
        if ($ship['nav']['status'] === 'IN_TRANSIT') {
            $arrival = CarbonImmutable::parse($ship['nav']['route']['arrival']);

            return AutomationStep::wait($arrival->greaterThan(now()) ? $arrival : CarbonImmutable::now()->addSeconds(5));
        }
        if (! empty($run->totals['purchased_ships'])) {
            return AutomationStep::trip();
        }
        $episode = CampaignEpisode::findOrFail($run->campaign_episode_id);
        $existing = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->latest('id')->first();
        if ($existing) {
            if (! empty($existing->outcome['delivered_ship']) && empty($existing->outcome['configuration_mismatch'])) {
                return AutomationStep::trip();
            }
            $this->recordShipyardRejection($episode, $run->plan['shipyard'], 'failed', null);

            return AutomationStep::blocked(! empty($existing->outcome['configuration_mismatch'])
                ? 'Delivered ship configuration differed from the approved offer. Review required.'
                : 'Probe purchase already attempted for this run.');
        }

        $grant = $episode->grant();
        $policy = self::policy($grant, $episode);
        if (! $policy) {
            return AutomationStep::blocked('Probe bootstrap authority is no longer active.');
        }

        $shipyard = $run->plan['shipyard'];
        if ($ship['nav']['waypointSymbol'] !== $shipyard) {
            $departure = $run->plan['route']['departure_refuel_ceiling'] ?? 0;
            $missing = $ship['fuel']['capacity'] - $ship['fuel']['current'];
            if ($departure > 0 && $missing > 0 && $ship['nav']['waypointSymbol'] === $run->plan['site'] && ($run->totals['travel_fuel'] ?? 0) === 0) {
                $fuel = app(OperationPlanner::class)->quote($agent, $run->plan['site'], 'FUEL');
                $units = min($missing, $fuel['tradeVolume'] * 100);
                $reserve = max($run->plan['reserve'], $this->effectiveReserve($agent, $grant, $policy, $run->id));
                $ceiling = min($departure - ($run->totals['fuel_cost'] ?? 0), $run->plan['max_spend'] - ($run->totals['fuel_cost'] ?? 0), max(0, $agent->credits - $reserve));
                $fuelCost = (int) ceil($units / 100) * $fuel['purchasePrice'];
                if ($fuelCost > $ceiling || $agent->credits - $fuelCost < $reserve) {
                    return AutomationStep::blocked('Departure refuel exceeds approved ceiling or current additive reserve for probe bootstrap.');
                }

                return $ship['nav']['status'] === 'DOCKED'
                    ? AutomationStep::act('refuel', ['units' => $units, 'maxTotal' => $ceiling, 'reserve' => $reserve])
                    : AutomationStep::act('dock');
            }

            return $ship['nav']['status'] === 'DOCKED'
                ? AutomationStep::act('orbit')
                : AutomationStep::act('navigate', ['waypointSymbol' => $shipyard]);
        }

        if ($this->ownedProbeCount($agent) + $this->inFlightProbePurchases($agent, $episode, $run->id) >= $policy['target']) {
            return AutomationStep::blocked('Probe target already reached before purchase.');
        }

        $yard = app(SpaceTradersApi::class)->shipyard($agent, $shipyard);
        app(AgentSynchronizer::class)->observe($agent, 'shipyard', $shipyard, $yard);
        $offer = $this->probeOffer($yard);
        if (! $offer) {
            $this->recordShipyardRejection($episode, $shipyard, 'unavailable', null);

            return AutomationStep::blocked('Shipyard '.$shipyard.' does not currently offer SHIP_PROBE.');
        }

        $price = $offer['purchasePrice'];
        $reserve = max($run->plan['reserve'], $this->effectiveReserve($agent, $grant, $policy, $run->id));
        $remainingSpend = $run->plan['max_spend'] - ($run->totals['fuel_cost'] ?? 0);
        if ($price > $policy['max_price']) {
            $this->recordShipyardRejection($episode, $shipyard, 'max_price', $price);

            return AutomationStep::blocked('SHIP_PROBE price '.$price.' CR exceeds max_price '.$policy['max_price'].' CR.');
        }
        if ($agent->credits - $price < $reserve) {
            $this->recordShipyardRejection($episode, $shipyard, 'reserve', $price);

            return AutomationStep::blocked('SHIP_PROBE price '.$price.' CR breaches working-capital reserve '.$reserve.' CR.');
        }
        if ($price > $remainingSpend) {
            return AutomationStep::blocked('SHIP_PROBE price '.$price.' CR exceeds remaining run spending cap '.$remainingSpend.' CR.');
        }

        return AutomationStep::act('buy_ship', [
            'shipType' => 'SHIP_PROBE',
            'waypointSymbol' => $shipyard,
            'maxTotal' => $price,
            'reserve' => $reserve,
        ]);
    }

    public function complete(AutomationRun $run, array $ship): void
    {
        $totals = $run->totals;
        $totals['cycles'] = ($totals['cycles'] ?? 0) + 1;
        $totals['ending_fuel'] = $ship['fuel']['current'];
        $totals['unsold'] = MiningPlanner::inventory($ship);
        $totals['elapsed_seconds'] = $run->started_at ? (int) $run->started_at->diffInSeconds(now()) : 0;
        $delivered = $totals['purchased_ships'][0] ?? null;
        $run->update([
            'totals' => $totals,
            'state' => RunState::Completed,
            'active_agent_id' => null,
            'next_action_at' => null,
            'blocked_reason' => null,
        ]);
        if ($run->campaign_episode_id) {
            $episode = CampaignEpisode::find($run->campaign_episode_id);
            if ($episode) {
                $buyAction = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->where('state', 'confirmed')->latest('id')->first();
                $shipPrice = isset($buyAction?->outcome['transaction_total'])
                    ? (int) $buyAction->outcome['transaction_total']
                    : max(0, abs((int) ($totals['credit_delta'] ?? 0)) - (int) ($totals['fuel_cost'] ?? 0));
                app(CampaignControl::class)->event($episode->grant(), $episode, 'probe_purchased', [
                    'run_id' => $run->id,
                    'ship' => $delivered,
                    'shipyard' => $run->plan['shipyard'] ?? null,
                    'price' => $shipPrice,
                ]);
            }
        }
    }

    /**
     * @param  list<array>  $probes
     * @param  list<string>  $busyShips
     * @return list<array>
     */
    private function eligibleShips(Agent $agent, CampaignEpisode $episode, ?array $command, array $probes, array $busyShips, string $system): array
    {
        $fuel = app(FuelPlanner::class);
        $eligible = [];
        $seen = [];
        foreach ([...$probes, ...($command ? [$command] : [])] as $ship) {
            $symbol = $ship['symbol'];
            if (isset($seen[$symbol]) || in_array($symbol, $busyShips, true)) {
                continue;
            }
            $seen[$symbol] = true;
            if (($ship['nav']['systemSymbol'] ?? null) !== $system
                || ($ship['nav']['status'] ?? null) === 'IN_TRANSIT'
                || ($ship['cargo']['units'] ?? 0) !== 0
                || ReconMission::holds($agent, $symbol)
                || app(CampaignFleet::class)->pendingForShip($episode, $symbol)) {
                continue;
            }
            $eligible[] = $ship + ['_fuel_free' => $fuel->fuelFree($ship)];
        }

        return $eligible;
    }

    /**
     * @param  list<array>  $eligibleShips
     * @return array<string, GameObservation>
     */
    private function discoverShipyards(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, string $system, array $eligibleShips, ?float $deadline): array
    {
        $waypoints = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', 'waypoint')->get()
            ->filter(fn ($point) => ($point->data['systemSymbol'] ?? null) === $system
                && in_array('SHIPYARD', array_column($point->data['traits'] ?? [], 'symbol'), true))
            ->pluck('symbol')->sort()->values();
        if ($waypoints->isEmpty()) {
            return [];
        }

        $existing = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', 'shipyard')->whereIn('symbol', $waypoints)->get()->keyBy('symbol');
        $occupied = array_unique(array_map(fn ($ship) => $ship['nav']['waypointSymbol'], $eligibleShips));
        $api = app(SpaceTradersApi::class);
        $sync = app(AgentSynchronizer::class);
        $reads = 0;

        // First refresh any shipyard where an eligible ship is already parked (to see live prices),
        // then inspect unobserved shipyards up to MAX_SHIPYARD_READS_PER_PASS.
        $toRead = [];
        foreach ($waypoints as $symbol) {
            $obs = $existing->get($symbol);
            if (in_array($symbol, $occupied, true) && (! $obs || empty($obs->data['ships']) || $obs->observed_at->lt(now()->subSeconds(config('gameplay.snapshot_seconds'))))) {
                $toRead[] = $symbol;
            }
        }
        foreach ($waypoints as $symbol) {
            if (! $existing->has($symbol) && ! in_array($symbol, $toRead, true)) {
                $toRead[] = $symbol;
            }
        }
        foreach ($toRead as $symbol) {
            if ($reads >= self::MAX_SHIPYARD_READS_PER_PASS) {
                break;
            }
            if (CampaignLocalEarn::policy($grant, $episode) && $deadline !== null) {
                app(CampaignLocalEarn::class)->reserve($deadline);
            }
            try {
                $sync->observe($agent, 'shipyard', $symbol, $api->shipyard($agent, $symbol));
                $reads++;
            } catch (GameException $exception) {
                if ($exception instanceof LocalEarnBudget || $exception->retryAfter > 0) {
                    throw $exception;
                }
            }
        }

        return GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', 'shipyard')->whereIn('symbol', $waypoints)->get()
            ->filter(fn ($obs) => $this->offersProbe($obs->data ?? []))
            ->keyBy('symbol')->all();
    }

    /**
     * @param  array<string, GameObservation>  $shipyards
     * @param  list<array>  $eligibleShips
     * @return list<array{ship: array, shipyard: string, tier: int, distance: float}>
     */
    private function rankCandidates(CampaignEpisode $episode, Agent $agent, array $policy, int $reserve, array $shipyards, array $eligibleShips, $points, array &$considered): array
    {
        $rejected = $episode->refresh()->state['probe_shipyard_rejected'] ?? [];
        $candidates = [];
        foreach ($shipyards as $symbol => $obs) {
            $rejection = $rejected[$symbol] ?? null;
            if (is_array($rejection) && isset($rejection['at'])
                && CarbonImmutable::parse($rejection['at'])->addMinutes(self::SHIPYARD_BACKOFF_MINUTES)->isFuture()) {
                $canRetryReserve = ($rejection['reason'] ?? null) === 'reserve'
                    && isset($rejection['price']) && is_int($rejection['price'])
                    && $agent->credits - $rejection['price'] >= $reserve;
                if (! $canRetryReserve) {
                    $considered[] = ['strategy' => 'buy_probe', 'shipyard' => $symbol, 'rejected' => 'Shipyard in backoff ('.$rejection['reason'].').'];

                    continue;
                }
            }

            $offer = $this->probeOffer($obs->data ?? []);
            if ($offer !== null && $obs->observed_at->gte(now()->subMinutes(self::SHIPYARD_BACKOFF_MINUTES))) {
                if ($offer['purchasePrice'] > $policy['max_price']) {
                    $considered[] = ['strategy' => 'buy_probe', 'shipyard' => $symbol, 'rejected' => 'Observed SHIP_PROBE price '.$offer['purchasePrice'].' CR exceeds max_price.'];

                    continue;
                }
                if ($agent->credits - $offer['purchasePrice'] < $reserve) {
                    $considered[] = ['strategy' => 'buy_probe', 'shipyard' => $symbol, 'rejected' => 'Observed SHIP_PROBE price '.$offer['purchasePrice'].' CR breaches reserve.'];

                    continue;
                }
            }

            $yardPoint = $points->get($symbol)?->data;
            foreach ($eligibleShips as $ship) {
                $atYard = $ship['nav']['waypointSymbol'] === $symbol;
                $fuelFree = $ship['_fuel_free'];
                $tier = match (true) {
                    $atYard && $fuelFree => 0,
                    $atYard => 1,
                    $fuelFree => 2,
                    default => 3,
                };
                $shipPoint = $points->get($ship['nav']['waypointSymbol'])?->data;
                $distance = ($yardPoint && $shipPoint)
                    ? hypot($yardPoint['x'] - $shipPoint['x'], $yardPoint['y'] - $shipPoint['y'])
                    : INF;
                $candidates[] = ['ship' => $ship, 'shipyard' => $symbol, 'tier' => $tier, 'distance' => $distance];
            }
        }
        usort($candidates, fn ($a, $b) => $a['tier'] <=> $b['tier'] ?: $a['distance'] <=> $b['distance'] ?: [$a['shipyard'], $a['ship']['symbol']] <=> [$b['shipyard'], $b['ship']['symbol']]);

        return $candidates;
    }

    private function offersProbe(array $yard): bool
    {
        return in_array('SHIP_PROBE', array_column($yard['shipTypes'] ?? [], 'type'), true)
            || $this->probeOffer($yard) !== null;
    }

    private function probeOffer(array $yard): ?array
    {
        $offer = collect($yard['ships'] ?? [])->firstWhere('type', 'SHIP_PROBE');

        return is_array($offer) && isset($offer['purchasePrice']) ? $offer : null;
    }

    private function refreshAcknowledgedShipyards(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, ?float $deadline): void
    {
        $yards = ShipAction::where('agent_id', $agent->id)
            ->where('reset_id', $agent->reset_id)
            ->where('command', 'buy_ship')
            ->where('state', 'acknowledged')
            ->get()
            ->filter(fn ($action) => ($action->arguments['shipType'] ?? null) === 'SHIP_PROBE'
                && empty($action->outcome['delivered_ship'])
                && empty($action->outcome['bootstrap_delivered_probe'])
                && empty($action->outcome['confirmed_not_sent'])
                && is_string($action->arguments['waypointSymbol'] ?? null))
            ->map(fn ($action) => $action->arguments['waypointSymbol'])
            ->unique()->values()->take(self::MAX_SHIPYARD_READS_PER_PASS);
        foreach ($yards as $shipyard) {
            if (CampaignLocalEarn::policy($grant, $episode) && $deadline !== null) {
                app(CampaignLocalEarn::class)->reserve($deadline);
            }
            try {
                app(AgentSynchronizer::class)->observe($agent, 'shipyard', $shipyard, app(SpaceTradersApi::class)->shipyard($agent, $shipyard));
            } catch (GameException $exception) {
                if ($exception instanceof LocalEarnBudget || $exception->retryAfter > 0) {
                    throw $exception;
                }
            }
        }
    }

    private function recordShipyardRejection(CampaignEpisode $episode, string $shipyard, string $reason, ?int $price): void
    {
        $state = $episode->refresh()->state ?? [];
        $state['probe_shipyard_rejected'][$shipyard] = [
            'reason' => $reason,
            'price' => $price,
            'at' => now()->toIso8601String(),
        ];
        $episode->update(['state' => $state]);
    }
}
