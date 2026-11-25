<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\ReconMission;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CampaignHaulerPurchase
{
    public const TYPE = 'SHIP_LIGHT_HAULER';

    public static function offerProfile(?array $offer): ?array
    {
        $price = $offer['purchasePrice'] ?? null;
        $tank = $offer['frame']['fuelCapacity'] ?? null;
        $speed = $offer['engine']['speed'] ?? null;
        $crew = $offer['crew']['capacity'] ?? null;
        $modules = $offer['modules'] ?? null;
        $mounts = $offer['mounts'] ?? null;
        if (! is_int($price) || $price < 1 || ! is_int($tank) || $tank < 1 || ! is_int($speed) || $speed < 1
            || ! is_int($crew) || $crew < 1 || ! is_array($modules) || ! is_array($mounts)) {
            return null;
        }

        foreach ([$offer['frame'], $offer['reactor'] ?? null, $offer['engine'], ...$mounts] as $part) {
            if (! is_array($part) || ! is_string($part['symbol'] ?? null) || $part['symbol'] === '') {
                return null;
            }
        }

        $hold = 0;
        foreach ($modules as $module) {
            if (! is_array($module) || ! is_string($module['symbol'] ?? null) || $module['symbol'] === '') {
                return null;
            }
            if (str_starts_with($module['symbol'], 'MODULE_CARGO_HOLD_')) {
                $capacity = $module['capacity'] ?? null;
                if (! is_int($capacity) || $capacity < 1 || $capacity > PHP_INT_MAX - $hold) {
                    return null;
                }
                $hold += $capacity;
            }
        }

        $hash = ShipCommandExecutor::configurationHash($offer);

        return $hash !== null && $hold > 0 ? ['hash' => $hash, 'tank' => $tank, 'speed' => $speed, 'hold' => $hold] : null;
    }

    public function policy(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent): ?array
    {
        $policy = $grant->revision()->body['fleet']['haulers'] ?? null;
        if (! $policy || CampaignSaturation::authorityReason($grant, $episode, $agent) !== null) {
            return null;
        }

        return $this->withWorkingCapital($episode, $agent, $policy);
    }

    public function withWorkingCapital(CampaignEpisode $episode, Agent $agent, array $policy): array
    {
        $traders = count(app(CampaignSaturation::class)->traderSymbols($episode, $agent));
        $policy['min_working_capital'] = max($policy['min_working_capital'], $policy['working_capital_per_trader'] * ($traders + 1));

        return $policy;
    }

    public function ownedHaulers(CampaignEpisode $episode, Agent $agent): int
    {
        $command = app(CampaignFleet::class)->role($episode, 'command', pin: false)['symbol'] ?? null;

        return Ship::where('agent_id', $agent->id)->when($command, fn ($query) => $query->where('symbol', '!=', $command))
            ->get()->filter(fn (Ship $ship) => CampaignProbeBootstrap::matchesType($ship->snapshot, self::TYPE))->count();
    }

    public function plan(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $busyShips, array &$considered, float $deadline, ?array &$meshReads = null, ?array &$detailedRead = null): ?AutomationRun
    {
        return app(CampaignProbeBootstrap::class)->plan($grant, $episode, $agent, $busyShips, $considered, $deadline, self::TYPE, $meshReads, detailedRead: $detailedRead);
    }

    public function compose(Agent $agent, string $symbol, string $yard, array $limits, CampaignEpisode $episode, ?int $ignoreRunId = null, ?float $deadline = null): array
    {
        return app(CampaignProbeBootstrap::class)->compose($agent, $symbol, $yard, $limits, $episode, $ignoreRunId, self::TYPE, $deadline);
    }

    public function composeFleetPurchase(Agent $agent, string $symbol, string $shipyard, string $shipType, array $limits, CampaignEpisode $episode, ?float $deadline = null): array
    {
        $grant = $episode->grant();
        $hulls = app(HullCatalog::class);
        $policy = CampaignPlaybook::saturationPolicy($grant->revision()->body);
        $spec = $hulls->spec($shipType);
        $ignoreRun = $limits['ignore_run_id'] ?? null;
        if (! $policy || ! in_array($shipType, $hulls->economicTypes(), true) || ! isset($policy['max_prices'][$shipType])
            || CampaignSaturation::authorityReason($grant, $episode, $agent) !== null || microtime(true) >= ($deadline ?? INF)) {
            throw new GameException('Fleet purchase is outside current campaign authority or its planning deadline.');
        }

        $bootstrap = app(CampaignProbeBootstrap::class);
        $bootstrap->assertSlot($agent, $ignoreRun);
        $row = Ship::where('agent_id', $agent->id)->where('symbol', $symbol)->firstOrFail();
        $ship = $row->snapshot;
        $pin = ShipCommandExecutor::configurationHash($ship);
        $fleet = app(CampaignFleet::class);
        $command = $fleet->role($episode, 'command', pin: false);
        $assigned = $symbol === ($command['symbol'] ?? null) || in_array($symbol, array_column($fleet->probes($episode, pin: false), 'symbol'), true);
        if (! $assigned || $row->stationed_waypoint || $ship['nav']['status'] === 'IN_TRANSIT' || $ship['cargo']['units'] !== 0
            || ! $pin || ReconMission::holds($agent, $symbol) || $ship['nav']['systemSymbol'] !== FleetClaims::systemOf($shipyard)) {
            throw new GameException('Fleet purchase requires an assigned stationary empty purchaser in the home system.');
        }

        $evidence = $hulls->shipyardOfferEvidence($agent, $shipyard, $shipType);
        $offer = $evidence['offer'];
        $offerPin = $offer ? ShipCommandExecutor::configurationHash($offer) : null;
        if (! $evidence['fresh'] || ! $offerPin || ! $hulls->matchesShip($offer, $shipType)) {
            throw new GameException('Fleet purchase requires a fresh detailed offer for the exact approved hull.');
        }
        $parts = $bootstrap->reserveParts($agent, $grant, $grant->revision()->body['fleet']['haulers'] ?? [], $ignoreRun, $shipType, $episode);
        $reserve = max($limits['reserve'] ?? 0, array_sum($parts));
        $route = app(OperationPlanner::class)->cruiseRoute($agent, $ship, $shipyard, $reserve, $deadline);
        $ceiling = min($policy['max_prices'][$shipType], $spec['hard_cap'], $limits['max_price'] ?? PHP_INT_MAX,
            ($limits['max_spend'] ?? PHP_INT_MAX) - $route['fuel_ceiling'], ($limits['max_loss'] ?? PHP_INT_MAX) - $route['fuel_ceiling']);
        if ($evidence['price'] > $ceiling || $agent->credits < $reserve + $ceiling + $route['fuel_ceiling']) {
            // Price authority may be narrowed to the current affordable amount, up to the approved limit.
            $ceiling = min($ceiling, $agent->credits - $reserve - $route['fuel_ceiling']);
            if ($evidence['price'] > $ceiling) {
                throw new GameException('Fleet purchase and delivery fuel exceed approved spending or the additive reserve.');
            }
        }
        $purchaseCutoff = $episode->next_reset_at->toImmutable()->subHour();
        if ($grant->expires_at) {
            $purchaseCutoff = $purchaseCutoff->min($grant->expires_at->toImmutable());
        }
        if (isset($limits['acquisition_cutoff'])) {
            $purchaseCutoff = $purchaseCutoff->min(CarbonImmutable::parse($limits['acquisition_cutoff']));
        }
        $deliverySeconds = $route['estimated_seconds'] + 10 * $route['refuel_batches'] + 120;
        $minutes = max(1, (int) ceil($deliverySeconds / 60));
        if ($minutes > ($limits['minutes'] ?? $grant->revision()->body['limits']['run_max_minutes'])
            || now()->addSeconds($deliverySeconds)->gte($purchaseCutoff) || $route['fuel_estimate'] > ($limits['max_fuel'] ?? PHP_INT_MAX)) {
            throw new GameException('Fleet purchase route exceeds the approved movement or acquisition time.');
        }

        $allocator = app(CampaignSaturation::class);
        $candidate = $limits['allocation_candidate'] ?? null;
        $candidates = $limits['allocation_candidates'] ?? ($candidate ? [$candidate] : []);
        $rank = $allocator->rankFleetPurchases($grant, $episode, $agent, $candidates, $ignoreRun, $deadline,
            [$shipType, $shipyard, $symbol], $route['fuel_ceiling'], $ceiling, $deliverySeconds);
        $candidate = $rank['selected'];
        if (! $candidate || [$candidate['type'], $candidate['yard'], $candidate['purchaser']] !== [$shipType, $shipyard, $symbol]) {
            throw new GameException('The exact fleet purchase is not currently eligible for the shared slot.');
        }

        $ceiling = min($ceiling, $candidate['approved_ceiling']);
        unset($limits['ignore_run_id'], $limits['allocation_candidates']);
        $plan = array_replace($limits, ['plan_version' => 8, 'strategy' => 'buy_fleet', 'cycles' => 1, 'minutes' => $minutes, 'shipyard' => $shipyard,
            'ship_type' => $shipType, 'hull_class' => $spec['class'], 'max_price' => $ceiling, 'reserve' => $reserve,
            'max_spend' => $ceiling + $route['fuel_ceiling'], 'max_loss' => $ceiling + $route['fuel_ceiling'], 'max_fuel' => $route['fuel_estimate'],
            'allocation_candidate' => $candidate, 'g2_reservation' => ['agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'slot' => $candidate['slot']],
            'reserve_parts' => $parts, 'offer_configuration_hash' => $offerPin, 'purchaser_configuration_hash' => $pin,
            'observed_price' => $evidence['price'], 'site' => $ship['nav']['waypointSymbol'], 'market' => $shipyard,
            'route' => $route, 'hop_index' => 0, 'grant_id' => $grant->id, 'revision_hash' => $grant->revision_hash, 'campaign_episode_id' => $episode->id,
            'purchaser' => $symbol, 'initial_credits' => $agent->credits, 'initial_configuration' => FuelPlanner::configuration($ship),
            'initial_fuel' => $ship['fuel']['current'], 'initial_status' => $ship['nav']['status'], 'initial_waypoint' => $ship['nav']['waypointSymbol'],
            'initial_inventory' => MiningPlanner::inventory($ship), 'initial_nav' => $ship['nav'], 'initial_cargo' => $ship['cargo'],
            'initial_tank' => $ship['fuel'], 'equipment' => OpportunityEvaluator::equipment($ship), 'participating_ships' => [$symbol],
            'acquisition_cutoff' => $purchaseCutoff->toIso8601String(), 'settlement_deadline' => $purchaseCutoff->toIso8601String()]);
        $approvedHops = array_map(static fn (array $hop) => array_diff_key($hop, ['quote_age' => true, 'continuation_quote_age' => true]), $route['hops']);
        $fingerprint = hash('sha256', json_encode([$agent->reset_id, $symbol, $shipType, $shipyard, $offerPin, $pin, $ceiling, $approvedHops], JSON_THROW_ON_ERROR));

        return ['plan' => $plan, 'fingerprint' => $fingerprint, 'allocation' => $rank];
    }

    private function nextFleetPurchase(Agent $agent, array $ship, AutomationRun $run): AutomationStep
    {
        $plan = $run->plan;
        if ($ship['nav']['status'] === 'IN_TRANSIT') {
            return AutomationStep::wait(max(CarbonImmutable::parse($ship['nav']['route']['arrival']), CarbonImmutable::now()->addSeconds(5)));
        }
        $action = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->latest('id')->first();
        if ($action) {
            return CampaignProbeBootstrap::deliveredShip($action) && empty($action->outcome['configuration_mismatch'])
                ? AutomationStep::trip()
                : AutomationStep::blocked('This fleet purchase already has a pending, failed or reviewed attempt.');
        }
        if (! empty($run->totals['purchased_ships'])) {
            return AutomationStep::trip();
        }
        $episode = CampaignEpisode::findOrFail($run->campaign_episode_id);
        $grant = $episode->grant();
        $type = $plan['ship_type'];
        $yard = $plan['shipyard'];
        $bootstrap = app(CampaignProbeBootstrap::class);
        if (CampaignSaturation::authorityReason($grant, $episode, $agent) !== null || now()->gte($plan['acquisition_cutoff'])
            || $ship['cargo']['units'] !== 0 || ShipCommandExecutor::configurationHash($ship) !== $plan['purchaser_configuration_hash']) {
            return AutomationStep::blocked('Fleet purchase authority or purchaser configuration changed.');
        }
        $bootstrap->assertSlot($agent, $run->id);
        $route = $plan['route'];
        $index = $run->totals['hop_index'] ?? 0;
        $paid = $run->totals['fuel_cost'] ?? 0;
        $reserve = max($plan['reserve'], array_sum($bootstrap->reserveParts($agent, $grant, $grant->revision()->body['fleet']['haulers'] ?? [],
            $run->id, $type, $episode)));
        if ($ship['nav']['waypointSymbol'] !== $yard || $index < count($route['hops'])) {
            return app(OperationPlanner::class)->nextPinnedHop($agent, $ship, $route['hops'], $index, $route['fuel_ceiling'] - $paid,
                $reserve + $plan['max_price'], $run->totals['hop_fuel_cost'] ?? []);
        }
        if ($ship['nav']['status'] !== 'DOCKED') {
            return AutomationStep::act('dock');
        }
        $data = app(SpaceTradersApi::class)->shipyard($agent, $yard, fresh: true);
        app(AgentSynchronizer::class)->observe($agent, 'shipyard', $yard, $data);
        $offer = $bootstrap->offer($data, $type);
        $price = $offer['purchasePrice'] ?? 0;
        if (! $offer || $price < 1 || $price > min($plan['max_price'], $plan['max_spend'] - $paid)
            || ShipCommandExecutor::configurationHash($offer) !== $plan['offer_configuration_hash']) {
            return AutomationStep::blocked('Current fleet offer exceeds the approved price or configuration.');
        }

        $continuation = $route['continuation_ceiling'];
        $rank = app(CampaignSaturation::class)->rankFleetPurchases($grant, $episode, $agent, [$plan['allocation_candidate']], $run->id,
            exact: [$type, $yard, $ship['symbol']], deliveryFuel: $continuation, approvedCeiling: $plan['max_price']);
        if (! $rank['selected'] || $agent->credits < $price + $reserve + $continuation) {
            return AutomationStep::blocked('Fleet purchase no longer clears the allocation gates and additive reserve.');
        }

        return AutomationStep::act('buy_ship', ['shipType' => $type, 'waypointSymbol' => $yard, 'maxTotal' => $price,
            'reserve' => $reserve, 'offer_configuration_hash' => $plan['offer_configuration_hash']]);
    }

    public function next(Agent $agent, array $ship, AutomationRun $run): AutomationStep
    {
        return $run->strategy === 'buy_fleet' ? $this->nextFleetPurchase($agent, $ship, $run) : app(CampaignProbeBootstrap::class)->next($agent, $ship, $run);
    }

    public function acknowledgePacing(ShipAction $action, User $user, bool $dryRun = false, ?CampaignGrant $arm = null, ?callable $revoke = null): array
    {
        return app(HostLock::class)->scoped('agent-'.$action->agent_id, fn () => DB::transaction(function () use ($action, $user, $dryRun, $arm, $revoke) {
            $action->refresh();
            $agent = $user->agents()->unarchived()->find($action->agent_id);
            $ship = CampaignProbeBootstrap::deliveredShip($action);
            $type = $action->arguments['shipType'] ?? null;
            if (! $agent || $agent->reset_id !== $action->reset_id || $action->command !== 'buy_ship' || ! in_array($type, app(HullCatalog::class)->traderTypes(), true)
                || isset($action->outcome['confirmed_not_sent']) || ! $ship || ! in_array($action->state->value, ['confirmed', 'acknowledged'], true)
                || ($action->state->value === 'acknowledged' && ! isset($action->outcome['delivery_transaction']))) {
                throw new GameException('Select a current-reset automatic hauler purchase with conclusive delivery evidence.');
            }
            $link = app(CampaignProbeBootstrap::class)->linkage($action);
            if (! $link || ! in_array($link[0]->plan_version, [7, 8], true) || $link[2]->user_id !== $user->id) {
                throw new GameException('Select an automatic hauler purchase with a valid originating C0 grant.');
            }
            [, $episode, $primary] = $link;
            $linked = CampaignGrant::where('user_id', $user->id)->where('agent_symbol', $agent->name)->whereNotNull('limits->arm')
                ->where('limits->arm->purchase_action_id', $action->id)->get();
            CampaignGrant::loadFirstEpisodeResets($linked);
            $schedule = $linked->isNotEmpty() ? CampaignDirector::cachedSchedule() : null;
            $linked = $linked->filter(fn (CampaignGrant $grant) => CampaignDirector::armReset($grant, $agent->reset_id, $schedule) === $agent->reset_id);
            if (($arm && ($linked->count() !== 1 || ! $linked->contains('id', $arm->id))) || (! $arm && $linked->isNotEmpty())) {
                throw new GameException('Pacing review must select the exact linked arm grant or an unarmed delivery.');
            }
            if (isset($action->outcome['pacing_acknowledged'])) {
                return $action->outcome['pacing_acknowledged'];
            }
            $stalled = ($action->settled_at ?? $action->created_at)->lte(now()->subMinutes(90));
            $held = ! empty($action->outcome['configuration_mismatch']) || $arm?->revoked_at || $arm?->paused_at;
            if ((! $stalled && ! $held) || ($arm && app(CampaignSaturation::class)->firstSale($arm, $agent->reset_id))) {
                throw new GameException('The delivery must have stalled before confirming its first load.');
            }
            $ack = ['user_id' => $user->id, 'at' => now()->toIso8601String(), 'reason' => $arm ? 'linked arm revoked' : 'exact-action pacing review'];
            if (! $dryRun) {
                if ($revoke) {
                    $revoke();
                }
                $action->update(['outcome' => array_replace($action->outcome ?? [], ['pacing_acknowledged' => $ack])]);
                app(CampaignControl::class)->event($primary, $episode, 'hauler_pacing_acknowledged',
                    ['action_id' => $action->id, 'ship' => $ship, 'grant_id' => $arm?->id] + $ack);
            }

            return $ack;
        }));
    }
}
