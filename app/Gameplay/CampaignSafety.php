<?php

namespace App\Gameplay;

use App\Enums\RunState;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\ReconMission;
use App\Models\Ship;
use App\Models\ShipAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CampaignSafety
{
    public const CREDIT_COMMANDS = ['buy', 'sell', 'refuel', 'buy_ship', 'install_mount', 'remove_mount', 'install_module', 'remove_module', 'repair', 'scrap', 'jump', 'chart', 'contract_accept', 'contract_fulfill'];

    public const SHIP_COMMANDS = ['orbit', 'dock', 'navigate', 'flight_mode', 'mine', 'mine_survey', 'siphon', 'survey', 'jettison'];

    public const ACQUISITION_COMMANDS = ['buy', 'mine', 'mine_survey', 'siphon'];

    public static function isCreditCommand(?string $command): bool
    {
        return ! in_array($command, [...self::SHIP_COMMANDS, 'transfer'], true);
    }

    public function perShipLocksEnabled(Agent $agent, ?CampaignEpisode $episode = null): bool
    {
        $grant = $episode?->campaign_grant_id ? CampaignGrant::find($episode->campaign_grant_id) : null;
        if ($grant?->slot !== 1) {
            $grant = CampaignGrant::primaryFor($agent->user_id, $agent->name)->first();
        }

        return (bool) (CampaignPlaybook::saturationPolicy($grant?->revision()->body ?? [])['per_ship_noncredit_locks'] ?? false);
    }

    public function saturationPolicy(AutomationRun $run): array
    {
        if ($run->plan_version !== 8 || ! $run->campaign_episode_id) {
            return [];
        }

        $episode = CampaignEpisode::find($run->campaign_episode_id);
        $agent = Agent::find($run->agent_id);

        return $episode && $agent ? CampaignPlaybook::saturationPolicy($this->saturationGrant($episode, $agent)?->revision()->body ?? []) ?? [] : [];
    }

    public function saturationGrant(CampaignEpisode $episode, Agent $agent): ?CampaignGrant
    {
        $grant = $episode->grant();
        if (! $grant->arm()) {
            return $grant;
        }
        $primary = CampaignGrant::primaryFor($agent->user_id, $agent->name)->first();
        if ($primary?->available() && ! $primary->isDraining() && CampaignEpisode::where('campaign_grant_id', $primary->id)
            ->where('agent_id', $agent->id)->where('reset_id', $episode->reset_id)->whereNull('closed_at')->exists()) {
            return $primary;
        }

        return null;
    }

    public function uncertainCredit(Agent $agent): bool
    {
        return ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('state', 'uncertain')->whereNotIn('command', [...self::SHIP_COMMANDS, 'transfer'])->exists();
    }

    public function hasShipUncertaintyHold(Agent $agent, string $shipSymbol): bool
    {
        $actions = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('state', 'uncertain');
        if ((clone $actions)->where('ship_symbol', $shipSymbol)->whereIn('command', [...self::SHIP_COMMANDS, 'transfer'])->exists()) {
            return true;
        }

        foreach (app(MiningCluster::class)->activeLeasesForShip($agent, $shipSymbol) as $lease) {
            $other = $lease->source_ship === $shipSymbol ? $lease->receiver_ship : $lease->source_ship;
            if ($lease->state === 'uncertain' || (clone $actions)->where('ship_symbol', $other)->exists()) {
                return true;
            }
        }

        return false;
    }

    public function creditReview(Agent $agent): bool
    {
        return ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('review_required', true)->whereNotIn('command', [...self::SHIP_COMMANDS, 'transfer'])->exists();
    }

    public function acquisitionDeadline(AutomationRun $run, ?int $legIndex = null): ?CarbonImmutable
    {
        if ($run->plan_version !== 8 || ! in_array($run->strategy, ['trade', 'mining', 'siphon'], true)) {
            return null;
        }
        $cutoff = $this->planTime($run->plan['acquisition_cutoff'] ?? null);
        if ($run->strategy !== 'trade') {
            $effective = $this->planTime($run->plan['effective_acquisition_cutoff'] ?? null);
            $seconds = $run->plan['delivery_seconds'] ?? $run->plan['route']['return']['estimated_seconds'] ?? null;
            $deliveryBound = $seconds === null ? null : $this->settlementDeadline($run)?->subSeconds($seconds);

            return collect([$effective, $cutoff, $deliveryBound])->filter()->min();
        }
        $legIndex ??= (int) ($run->totals['leg_index'] ?? 0);
        $leg = ($run->plan['legs'] ?? [])[$legIndex] ?? $run->plan;
        $buy = $this->planTime($leg['latestBuyAt'] ?? $leg['latest_buy_at'] ?? null);

        return $cutoff && $buy ? $cutoff->min($buy) : ($cutoff ?? $buy);
    }

    public function settlementDeadline(AutomationRun $run): ?CarbonImmutable
    {
        return $this->planTime($run->plan['settlement_deadline'] ?? null) ?? $run->expires_at;
    }

    public function deliveryDeadline(AutomationRun $run, ?int $legIndex = null): ?CarbonImmutable
    {
        $legIndex ??= (int) ($run->totals['leg_index'] ?? 0);
        $leg = $run->plan['legs'][$legIndex] ?? $run->plan;
        $delivery = $this->planTime($leg['deliveryDeadline'] ?? null);
        $settlement = $this->settlementDeadline($run);

        return $delivery && $settlement ? $delivery->min($settlement) : ($delivery ?? $settlement);
    }

    public function assertDeliveryOpen(AutomationRun $run, string $command, array $arguments): void
    {
        $delivering = ($arguments['phase'] ?? null) === 'deliver' || in_array($run->totals['phase'] ?? null, ['sell', 'deliver'], true);
        if (($command === 'sell' || ($delivering && in_array($command, ['navigate', 'dock', 'orbit', 'refuel'], true)))
            && ($deadline = $this->deliveryDeadline($run, $arguments['leg_index'] ?? null)) && now()->gte($deadline)) {
            throw new GameException('Delivery deadline reached. Retain attributable cargo for authorized recovery.');
        }
    }

    public function planTime(mixed $value): ?CarbonImmutable
    {
        if (is_int($value)) {
            return CarbonImmutable::createFromTimestampUTC($value);
        }

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    public function reason(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, ?array $roleShips = null): ?string
    {
        $reason = $this->actionBlocker($grant, $episode, $agent, pinRoles: $roleShips === null && ! $this->perShipLocksEnabled($agent, $episode), fleet: $roleShips);
        if ($reason) {
            return $reason;
        }
        if ($this->unexplainedBalance($agent)) {
            return 'unexplained_change';
        }
        if (! empty($episode->state['pending_pause'])) {
            return $episode->state['pending_pause'];
        }
        if ($grant->arm() && self::lossStop($grant, self::recordedNet($episode))) {
            return 'arm_loss_stop';
        }
        $limit = $grant->revision()->body['trade']['losses']['pause_after_consecutive_losing_loads'];
        $results = CampaignEvent::where('campaign_episode_id', $episode->id)->where('kind', 'load_result')
            ->when(isset($episode->state['losses_reviewed_at']), fn ($query) => $query->where('data->finished_at', '>', $episode->state['losses_reviewed_at']))
            ->orderByDesc('data->finished_at')->orderByDesc('id')->limit($limit)->pluck('data');
        $consecutive = 0;
        foreach ($results as $result) {
            if (! CampaignTrade::losing($result)) {
                break;
            }
            $consecutive++;
        }

        return $consecutive >= $grant->revision()->body['trade']['losses']['pause_after_consecutive_losing_loads'] ? 'consecutive_losing_loads' : null;
    }

    public function uncertain(Agent $agent): bool
    {
        return ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('state', 'uncertain')->exists();
    }

    public function actionBlocker(CampaignGrant $grant, ?CampaignEpisode $episode, Agent $agent, bool $pinRoles = false, ?array $fleet = null): ?string
    {
        $split = $this->perShipLocksEnabled($agent, $episode);
        if (($split ? $this->uncertainCredit($agent) || $this->creditReview($agent) : $this->uncertain($agent))
            || $this->purchaseConfigurationMismatch($grant, $agent)) {
            return 'uncertain_action';
        }

        return $this->priceReview($grant, $episode, $agent, $pinRoles, $fleet) ? 'uncertain_action' : null;
    }

    public function purchaseConfigurationMismatch(CampaignGrant $grant, Agent $agent): bool
    {
        return $grant->slot === 1 && ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('command', 'buy_ship')->where('review_required', true)->where('outcome->configuration_mismatch', true)->exists();
    }

    public function priceReview(CampaignGrant $grant, ?CampaignEpisode $episode, Agent $agent, bool $pinRoles = false, ?array $fleet = null): bool
    {
        $reviews = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('review_required', true);
        if ($this->perShipLocksEnabled($agent, $episode)) {
            $reviews->whereNotIn('command', [...self::SHIP_COMMANDS, 'transfer']);
        }
        // Most reviews have no unsettled actions. Avoid resolving campaign roles until they matter.
        if ($fleet === null && ! $pinRoles && ! (clone $reviews)->exists()) {
            return false;
        }
        $fleet ??= $episode ? app(CampaignFleet::class)->symbols($episode, $pinRoles) : array_filter([$grant->arm()['ship'] ?? null]);
        $runs = $episode ? AutomationRun::where('campaign_episode_id', $episode->id)
            : AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                ->whereIn('campaign_episode_id', CampaignEpisode::where('campaign_grant_id', $grant->id)
                    ->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->select('id'));

        return $reviews->where(fn ($query) => $query->whereIn('ship_symbol', $fleet)->orWhereNull('ship_symbol')->orWhere('ship_symbol', '')
            ->orWhereIn('automation_run_id', $runs->select('id')))->exists();
    }

    public function unexplainedBalance(Agent $agent, ?int $except = null): bool
    {
        $sending = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->when($except, fn ($query) => $query->whereKeyNot($except));
        if ($this->perShipLocksEnabled($agent)) {
            $sending->where('state', 'sending')->whereNotIn('command', [...self::SHIP_COMMANDS, 'transfer']);
        } else {
            $sending->whereIn('state', ['pending', 'sending']);
        }

        return ! $sending->exists() && (app(CreditAccounting::class)->summary($agent)['unexplained_change'] ?? 0) !== 0;
    }

    public static function recordedNet(?CampaignEpisode $episode): int
    {
        return $episode ? (int) CampaignEvent::where('campaign_episode_id', $episode->id)->where('kind', 'load_result')
            ->sum(DB::raw("coalesce(json_extract(data, '$.net'), 0)")) : 0;
    }

    public static function lossStop(CampaignGrant $grant, int $net): bool
    {
        $limit = $grant->arm()['max_net_loss'] ?? null;

        return $grant->arm() !== null && ($grant->pause_reason === 'arm_loss_stop' || ($limit !== null && $net < -$limit));
    }

    public function check(AutomationRun $run, Agent $agent, ?string $command = null, array $arguments = [], bool $boundaryOnly = false, ?int $preflightPrice = null): void
    {
        if (! $run->campaign_episode_id) {
            if ($run->plan_version === 8) {
                throw new GameException('A saturation action requires a pinned campaign episode.');
            }

            return;
        }
        $episode = CampaignEpisode::findOrFail($run->campaign_episode_id);
        $grant = $episode->grant();
        $split = $this->perShipLocksEnabled($agent, $episode);
        $reason = $boundaryOnly
            ? (($split ? $this->actionBlocker($grant, $episode, $agent) : null) ?? $episode->state['pending_pause'] ?? null)
            : $this->reason($grant, $episode, $agent);
        if (! $boundaryOnly && $reason && $reason !== 'consecutive_losing_loads' && ! app(HostLock::class)->holdsShared('agent-'.$agent->id)) {
            app(CampaignControl::class)->pause($grant, $reason, $episode);
        }
        if (($reason && ($reason !== 'consecutive_losing_loads' || empty($run->plan['sell_only'])))
            || ! $grant->fresh()->available() || $episode->closed_at || $run->reset_id !== $episode->reset_id || $agent->id !== $episode->agent_id || $agent->reset_id !== $episode->reset_id
            || ($grant->arm() && CampaignDirector::armReset($grant, $agent->reset_id, CampaignDirector::cachedSchedule()) !== $agent->reset_id)) {
            throw new GameException('Campaign authority is paused, closed or awaiting liquidation.');
        }
        if ($split && $command !== null && ($this->hasShipUncertaintyHold($agent, $run->ship_symbol)
            || ($command === 'transfer' && $this->hasShipUncertaintyHold($agent, (string) ($arguments['targetShip'] ?? ''))))) {
            throw new GameException('This ship or its transfer partner has an unresolved action.', retryAfter: 5);
        }
        $v8 = $run->plan_version === 8;
        $authority = $v8 ? $this->saturationGrant($episode, $agent) : null;
        $policy = $authority ? CampaignPlaybook::saturationPolicy($authority->revision()->body) : null;
        $cleanup = $v8 && $command === 'flight_mode' && ($arguments['flightMode'] ?? null) === 'CRUISE'
            && $this->positionModeAllowed($run, $agent, 'CRUISE');
        if ($v8) {
            if ($command !== null) {
                $this->assertDeliveryOpen($run, $command, $arguments);
            }
            $this->checkSaturationAuthority($run, $grant, $episode, $agent, $authority, $policy, $command, $arguments);
        } elseif (! in_array($command, [null, 'flight_mode', 'orbit', 'dock', 'navigate', 'mine', 'mine_survey', 'sell', 'buy', 'refuel', 'buy_ship'], true)) {
            throw new GameException('Command is outside the campaign grant.');
        }
        $acquiring = [...self::ACQUISITION_COMMANDS, 'buy_ship', 'survey', 'jettison', 'transfer'];
        if ($grant->isDraining() && ! $cleanup && (empty($run->plan['sell_only']) || in_array($command, $acquiring, true))) {
            throw new GameException('Drain pause only permits settling cargo aboard.');
        }
        if ($command === 'flight_mode' && ! ($v8 && $this->positionModeAllowed($run, $agent, $arguments['flightMode'] ?? null))
            && ($run->strategy !== 'sell_aboard' || empty($run->plan['restore_cruise']) || empty($run->plan['sell_only']) || ($arguments['flightMode'] ?? null) !== 'CRUISE')) {
            throw new GameException('Campaign flight mode only permits approved positioning or CRUISE cargo recovery.');
        }
        if ($episode->next_reset_at->isPast()) {
            throw new GameException('Awaiting confirmation of the next reset.');
        }
        if ($this->windDown($episode) && (in_array($command, $acquiring, true)
            || ($v8 && $command === 'flight_mode' && ($arguments['flightMode'] ?? null) !== 'CRUISE'))) {
            throw new GameException('Wind-down only permits selling aboard.');
        }
        if (in_array($run->strategy, ['scout', ...CampaignProbeBootstrap::STRATEGIES], true)) {
            $operatorShip = Ship::where('agent_id', $agent->id)->where('symbol', $run->ship_symbol)->first();
            if ($operatorShip && app(CampaignFleet::class)->isProbe($operatorShip->snapshot)) {
                if ($operatorShip->stationed_waypoint !== null) {
                    throw new GameException('Campaign pool authority changed: operator stationed this probe.');
                }
                if (isset(CampaignMesh::policy($episode)['saturation']) && ! isset($run->plan['mesh_work'])) {
                    if ($run->strategy === 'scout') {
                        throw new GameException('Mesh pool scouts require governed campaign authority.');
                    }
                    $yard = $run->plan['shipyard'];
                    if (in_array($command, ['orbit', 'navigate'], true) && $operatorShip->snapshot['nav']['waypointSymbol'] !== $yard) {
                        if ($command === 'navigate' && ($arguments['waypointSymbol'] ?? null) !== $yard) {
                            throw new GameException('Purchase route destination changed.');
                        }
                        if ($run->strategy === 'buy_hauler') {
                            throw new GameException('Remote mesh hauler purchase requires current movement admission.');
                        }
                        app(CampaignMesh::class)->assertFloor($episode, $agent, $operatorShip->snapshot);
                    }
                }
            }
        }
        if (! empty($run->plan['sell_only']) && in_array($command, ['orbit', 'navigate'], true)) {
            $recovery = app(CampaignRecovery::class);
            $age = $recovery->verifiedBuyerAge($episode, $run->plan);
            if ($age !== null) {
                $recovery->verifiedBuyer($agent, $run->plan['market'], $run->plan['recovery_good'], $age);
            }
        }
        if (isset($run->plan['mesh_work']) && (! $v8 || $run->strategy === 'scout') && in_array($command, ['orbit', 'dock', 'navigate'], true)) {
            $ship = Ship::where('agent_id', $agent->id)->where('symbol', $run->ship_symbol)->firstOrFail()->snapshot;
            $mesh = app(CampaignMesh::class);
            $destination = $run->plan['destination'] ?? $run->plan['shipyard'];
            $target = $arguments['waypointSymbol'] ?? null;
            if (CampaignGrant::pendingFor($grant->user_id, $agent->name)->exists()
                || $this->windDown($episode) || ($command === 'navigate' && $target !== $destination)) {
                throw new GameException('Mesh movement authority or destination changed.');
            }
            if ($run->plan['mesh_work'] === 'yard') {
                $mesh->assertYardCutoff($episode, $agent, $ship, $destination);
            }
            if ($command === 'navigate' || $ship['nav']['waypointSymbol'] !== $destination) {
                $travel = app(MeshPlanner::class)->route($mesh->model($episode, $agent), $ship, $destination, $mesh->secondsLeft($episode, $grant));
                if ($travel === null || $travel + CampaignMesh::TRANSPORT_CUTOFF_MARGIN_SECONDS >= now()->diffInSeconds($run->expires_at, false)) {
                    throw new GameException('Mesh movement cannot finish before the run cutoff.');
                }
                $mesh->assertDeparture($episode, $agent, $ship, $destination, $run->id, $run->plan['mesh_work'] !== 'market');
            } else {
                $mesh->assertPool($episode, $agent, $ship);
            }
        }
        if ($command === 'buy_ship' || ($command === 'refuel' && in_array($run->strategy, [...CampaignProbeBootstrap::STRATEGIES, 'buy_fleet'], true))) {
            $this->purchaseBoundary($run, $agent, $grant, $episode, $command, $arguments, $boundaryOnly, $preflightPrice);
        }
        if (! $v8 && in_array($command, ['buy', 'sell'], true)) {
            app(FleetClaims::class)->follow($run, $episode, $command, $arguments);
        }
        if ($command === 'buy' && $grant->arm() && ($arguments['maxTotal'] ?? PHP_INT_MAX) > app(CampaignTrade::class)->allowance($grant, $agent, $run)) {
            throw new GameException('Buy skipped: arm exposure requires a smaller batch.', retryAfter: 30);
        }
        if ($command === 'buy' && ! $boundaryOnly && ! $v8) {
            if ($run->strategy !== 'trade' || ! empty($run->plan['sell_only'])) {
                throw new GameException('Recovery blocks new trade buys.');
            }
            app(CampaignTrade::class)->quoteLife($agent, $run->plan, $run->totals);
            $reserve = app(CampaignTrade::class)->fuelReserve($agent, $run->ship_symbol, $run->plan['market']);
            if ($agent->credits - ($arguments['maxTotal'] ?? PHP_INT_MAX) < $reserve + ReconMission::returnExposure($agent)) {
                throw new GameException('Buy skipped: delivery fuel reserve requires a smaller batch.');
            }
        }
    }

    private function checkSaturationAuthority(AutomationRun $run, CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, ?CampaignGrant $authority, ?array $policy, ?string $command, array $arguments): void
    {
        $plan = $run->plan;
        if (! $policy || ($plan['grant_id'] ?? null) !== $grant->id || ($plan['revision_hash'] ?? null) !== $grant->revision_hash
            || ($plan['campaign_episode_id'] ?? null) !== $episode->id || ! hash_equals($grant->revision_hash, $grant->revision()->content_hash)
            || ($grant->arm() && (($plan['saturation_grant_id'] ?? null) !== $authority?->id
                || ($plan['saturation_revision_hash'] ?? null) !== $authority?->revision_hash
                || $run->ship_symbol !== ($grant->arm()['ship'] ?? null) || ! in_array($run->strategy, ['trade', 'sell_aboard'], true)))) {
            throw new GameException('Saturation plan authority changed.');
        }
        $fleet = app(CampaignFleet::class);
        if ($run->strategy === 'trade' && ! $grant->arm() && $run->ship_symbol !== ($fleet->role($episode, 'command', pin: false)['symbol'] ?? null)) {
            throw new GameException('The selected ship no longer has command trade authority.');
        }
        if (in_array($run->strategy, ['scout', 'buy_fleet'], true)) {
            $assigned = [$fleet->role($episode, 'command', pin: false)['symbol'] ?? null, ...array_column($fleet->probes($episode, pin: false), 'symbol')];
            $ship = Ship::where('agent_id', $agent->id)->where('symbol', $run->ship_symbol)->firstOrFail();
            if (! in_array($run->ship_symbol, $assigned, true) || $ship->stationed_waypoint) {
                throw new GameException('The selected scout or purchaser no longer has pool authority.');
            }
            $destination = $run->strategy === 'buy_fleet' ? ($plan['shipyard'] ?? null) : ($plan['destination'] ?? $plan['market'] ?? null);
            if ($command === 'navigate' && in_array($run->strategy, ['scout', 'buy_fleet'], true)
                && ($departure = $ship->snapshot['nav']['waypointSymbol']) !== $destination
                && $fleet->isProbe($ship->snapshot) && isset(CampaignMesh::policy($episode)['saturation'])) {
                app(CampaignMesh::class)->assertDeparture($episode, $agent, $ship->snapshot, $arguments['waypointSymbol'], $run->id, $run->strategy === 'buy_fleet');
            }
        }
        $extra = match ($run->strategy) {
            'trade' => ['buy', 'sell', 'refuel'],
            'scout' => [],
            'buy_fleet' => ['buy_ship', 'refuel'],
            'mining' => ['mine', 'mine_survey', 'sell', 'refuel', 'transfer', 'jettison'],
            'siphon' => ['siphon', 'sell', 'refuel', 'transfer'],
            'survey' => ['survey', 'refuel'],
            'position' => ['flight_mode', 'refuel'],
            'tender' => ['sell', 'refuel'],
            'sell_aboard' => ['sell', 'refuel', 'flight_mode'],
            default => throw new GameException('Strategy is outside the saturation campaign grant.'),
        };
        $extraction = $policy['extraction'] ?? [];
        if (! in_array($command, [null, 'orbit', 'dock', 'navigate', ...$extra], true)
            || (in_array($run->strategy, ['mining', 'siphon', 'survey', 'position', 'tender'], true) && empty($extraction['enabled']))
            || (count($plan['legs'] ?? []) > 1 && empty($policy['corridor']['chains_enabled']))
            || ($run->strategy === 'tender' && (empty($extraction['stationary']) || empty($plan['cluster_id'])))
            || ($run->strategy === 'sell_aboard' && empty($plan['sell_only']))
            || ($command === 'jettison' && (empty($extraction['stationary']) || empty($plan['stationary'])))
            || ($command === 'transfer' && (empty($extraction['stationary'])
                || (empty($plan['cluster_id']) && empty($plan['canary_pair_id']))
                || empty($arguments['transfer_lease_id'] ?? $arguments['transferLeaseId'] ?? null)))) {
            throw new GameException('Command is outside the saturation strategy or phase authority.');
        }
        // Endpoint previews hold the ship lock. Activation holds the agent writer and can bind a previously previewed pair.
        if ($run->strategy === 'tender' && $run->state === RunState::Preview
            && ! app(HostLock::class)->holdsExclusive('ship-'.$agent->id.'-'.$run->ship_symbol)) {
            $this->assertTenderAdmission($agent, $run);
        }
    }

    private function assertTenderAdmission(Agent $agent, AutomationRun $run): void
    {
        $plan = $run->plan;
        if (! empty($plan['canary_transfer'])) {
            $canary = app(TraderAssigner::class)->canaryState($agent, 'phase3_transfer_canary');
            $pair = $canary['evidence'];
            if ($canary['state'] === 'admitted' && ($plan['canary_pair_id'] ?? null) === $canary['canary_pair_id']
                && ($pair['receiver_run_id'] ?? null) === $run->id && ($pair['receiver_ship'] ?? null) === $run->ship_symbol
                && ($pair['cluster_id'] ?? null) === $plan['cluster_id'] && ($pair['site'] ?? null) === $plan['site']
                && ($pair['buyer'] ?? null) === $plan['buyer']) {
                return;
            }

            throw new GameException('Tender activation requires a valid transfer canary pair.');
        }

        $site = $plan['site'];
        $configurations = DB::table('extraction_calibrations')->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where('kind', 'transfer')->where('waypoint', $site)->pluck('config_hash');
        $calibrator = app(ExtractionCalibrator::class);
        $cluster = app(MiningCluster::class);
        foreach ($configurations as $configuration) {
            $sample = $calibrator->summary($agent, 'transfer', $site, $configuration);
            if ($cluster->canAdmitOrdinary($sample['transfer_cooldown_measured'], $sample['transfer_dwell_seconds'] !== null, true)) {
                return;
            }
        }

        throw new GameException('Ordinary tender activation requires calibrated transfer cooldown and dwell at the extraction site.');
    }

    public function positionModeAllowed(AutomationRun $run, Agent $agent, ?string $mode): bool
    {
        if ($run->plan_version !== 8 || $run->strategy !== 'position' || ! in_array($mode, ['DRIFT', 'CRUISE'], true)) {
            return false;
        }
        $ship = Ship::where('agent_id', $agent->id)->where('symbol', $run->ship_symbol)->first()?->snapshot;
        if (($ship['cargo']['units'] ?? null) !== 0 || ! in_array($ship['nav']['status'] ?? null, ['DOCKED', 'IN_ORBIT'], true)) {
            return false;
        }
        $current = $ship['nav']['flightMode'];
        $hashes = $run->plan['route']['configurations'] ?? $run->plan['configuration_hashes'] ?? [];
        $expected = $hashes[$current] ?? $run->plan[strtolower($current).'_configuration'] ?? null;
        if ($expected !== FuelPlanner::configuration($ship)) {
            return false;
        }
        $phase = $run->totals['position_phase'] ?? $run->plan['position_phase'] ?? null;
        $route = $run->plan['route'] ?? [];
        $cleanup = $route['cleanup'] ?? $run->plan['cleanup'] ?? [];
        if ($mode === 'DRIFT') {
            return $current === 'CRUISE' && $phase === 'switch_to_drift' && ($route['mode'] ?? null) === 'DRIFT'
                && ($ship['nav']['waypointSymbol'] ?? null) === ($route['origin'] ?? null);
        }

        $safeBy = $this->planTime($route['hard_deadline'] ?? null)?->subSeconds($route['margin_seconds'] ?? 1800);

        return $current === 'DRIFT' && ($cleanup['mode'] ?? null) === 'CRUISE' && $this->planTime($cleanup['expires_at'] ?? null)?->isFuture()
            && (in_array($phase, ['restore_cruise', 'cleanup', 'navigate_refused'], true) || $ship['nav']['waypointSymbol'] === $route['destination']
                || ($safeBy && now()->addSeconds($route['estimated_seconds'])->gt($safeBy)));
    }

    private function purchaseBoundary(AutomationRun $run, Agent $agent, CampaignGrant $grant, CampaignEpisode $episode, string $command, array $arguments, bool $boundaryOnly, ?int $preflightPrice): void
    {
        if ($run->strategy === 'buy_fleet') {
            $this->fleetPurchaseBoundary($run, $agent, $grant, $command, $arguments, $preflightPrice);

            return;
        }
        $hauler = $run->strategy === 'buy_hauler';
        $type = $hauler ? 'SHIP_LIGHT_HAULER' : 'SHIP_PROBE';
        $buyer = app(CampaignHaulerPurchase::class);
        $policy = $hauler ? $buyer->policy($grant, $episode, $agent) : CampaignProbeBootstrap::policy($grant, $episode);
        if (! $policy || ! in_array($run->strategy, CampaignProbeBootstrap::STRATEGIES, true)
            || $agent->name !== $grant->resolvedAgentSymbol() || $run->plan['ship_type'] !== $type
            || ($hauler && ($run->plan_version !== 7 || $run->plan['grant_id'] !== $grant->id || $run->plan['revision_hash'] !== $grant->revision_hash
                || $run->plan['campaign_episode_id'] !== $episode->id || $run->plan['purchaser'] !== $run->ship_symbol))) {
            throw new GameException('Purchase command is outside the current campaign grant.');
        }
        $bootstrap = app(CampaignProbeBootstrap::class);
        $maxTotal = $arguments['maxTotal'] ?? PHP_INT_MAX;
        $reserve = max($run->plan['reserve'], $arguments['reserve'] ?? 0, $bootstrap->effectiveReserve($agent, $grant, $policy, $run->id));
        $spent = max($run->totals['spending'] ?? 0, $run->totals['fuel_cost'] ?? 0);
        if ($maxTotal > $run->plan['max_spend'] - $spent || $maxTotal > $run->plan['max_loss'] + $run->totals['credit_delta'] || $agent->credits - $maxTotal < $reserve) {
            throw new GameException('Purchase exceeds remaining run caps or working-capital reserve.');
        }
        if ($command === 'refuel') {
            if ($maxTotal > ($run->plan['route']['departure_refuel_ceiling'] ?? 0) - ($run->totals['fuel_cost'] ?? 0)
                || ($run->totals['fuel_units'] ?? 0) + ($arguments['units'] ?? PHP_INT_MAX) > $run->plan['max_fuel']) {
                throw new GameException('Purchase refuel exceeds the authorized route fuel limits.');
            }

            return;
        }

        $ship = Ship::where('agent_id', $agent->id)->where('symbol', $run->ship_symbol)->first()?->snapshot;
        $yard = $run->plan['shipyard'];
        if (($arguments['shipType'] ?? null) !== $type || ($arguments['waypointSymbol'] ?? null) !== $yard
            || ($ship['nav']['waypointSymbol'] ?? null) !== $yard || ($ship['nav']['status'] ?? null) === 'IN_TRANSIT'
            || ($ship['cargo']['units'] ?? null) !== 0 || ReconMission::holds($agent, $run->ship_symbol)
            || ($hauler && ShipCommandExecutor::configurationHash($ship) !== $run->plan['purchaser_configuration_hash'])
            || $maxTotal > min($policy['max_price'], $run->plan['max_price'])
            || ($hauler && (empty($arguments['offer_configuration_hash']) || ($run->plan['offer_configuration_hash'] ?? $arguments['offer_configuration_hash']) !== $arguments['offer_configuration_hash']
                || ($run->totals['offer_configuration_hash'] ?? $arguments['offer_configuration_hash']) !== $arguments['offer_configuration_hash']))) {
            throw new GameException('Purchase exceeds the authorized purchaser, yard, type, price or configuration.');
        }
        $bootstrap->assertSlot($agent, $run->id);
        [$owned, $target] = $hauler ? [$buyer->ownedHaulers($episode, $agent), $policy['max_haulers']] : $bootstrap->probeTarget($episode, $agent, $policy);
        if ($owned + $bootstrap->reservations($agent, $run->id, undeliveredType: $type)->count() >= $target) {
            throw new GameException('Purchase target already reached.');
        }
        if (! $hauler && isset($policy['mesh']['saturation'])) {
            $pin = $run->plan['mesh_purchase'] ?? [];
            if (($pin['grant'] ?? null) !== $grant->id || ($pin['revision'] ?? null) !== $grant->revision_hash
                || ($pin['reset'] ?? null) !== $agent->reset_id || ($pin['purchaser'] ?? null) !== $run->ship_symbol
                || ($pin['yard'] ?? null) !== $yard || ($pin['cutoff'] ?? 0) <= now()->timestamp
                || $this->priceReview($grant, $episode, $agent)) {
                throw new GameException('Mesh purchase authority or price review changed before spend.');
            }
            $paid = (int) ($run->totals['fuel_cost'] ?? 0);
            $current = app(ProbeYardSelector::class)->check($episode, $agent, $ship, $yard, $policy,
                $preflightPrice ?? $maxTotal, $reserve, $run->id, $paid, max(0, $run->plan['route']['fuel_ceiling'] - $paid), $pin['ceiling']);
            if ($maxTotal > $current['ceiling'] || ($preflightPrice !== null && $preflightPrice > $maxTotal)) {
                throw new GameException('Mesh intended spend exceeds the current Tier-'.$current['gain']['tier'].' ceiling.');
            }
        }
        if ($boundaryOnly) {
            if ($hauler) {
                $requests = app(CampaignSaturation::class)->requests($agent, $policy, $run->id);
                if (! $requests['pass']) {
                    throw new GameException($requests['reason']);
                }
            } else {
                $bootstrap->assertPurchaseBudget($agent);
            }
        }
    }

    private function fleetPurchaseBoundary(AutomationRun $run, Agent $agent, CampaignGrant $grant, string $command, array $arguments, ?int $preflightPrice): void
    {
        $plan = $run->plan;
        $hulls = app(HullCatalog::class);
        $type = $plan['ship_type'] ?? '';
        $policy = CampaignPlaybook::saturationPolicy($grant->revision()->body);
        $ceiling = min($policy['max_prices'][$type] ?? 0, $plan['max_price'] ?? 0);
        $spent = (int) ($run->totals['spending'] ?? 0);
        $max = $arguments['maxTotal'] ?? PHP_INT_MAX;
        $slot = $plan['g2_reservation'] ?? [];
        $candidate = $plan['allocation_candidate'] ?? [];
        if ($run->plan_version !== 8 || $grant->slot !== 1 || $agent->name !== $grant->resolvedAgentSymbol()
            || ! in_array($type, $hulls->economicTypes(), true) || $ceiling < 1
            || ($plan['hull_class'] ?? null) !== ($hulls->spec($type)['class'] ?? null)
            || ($slot['agent_id'] ?? null) !== $agent->id || ($slot['reset_id'] ?? null) !== $agent->reset_id || empty($slot['slot'])
            || ($candidate['slot'] ?? null) !== $slot['slot'] || ($candidate['type'] ?? null) !== $type
            || ($candidate['yard'] ?? null) !== ($plan['shipyard'] ?? null) || ($candidate['purchaser'] ?? null) !== $run->ship_symbol
            || ! $this->planTime($plan['acquisition_cutoff'] ?? null)?->isFuture()
            || (! in_array($type, $hulls->traderTypes(), true) && empty($policy['extraction']['enabled']))
            || $max > min($plan['max_spend'] - $spent, $plan['max_loss'] + ($run->totals['credit_delta'] ?? 0))
            || $agent->credits - $max < max($plan['reserve'], $arguments['reserve'] ?? 0)) {
            throw new GameException('Fleet purchase exceeds type, spend or reserve authority.');
        }
        if ($command === 'refuel') {
            $index = (int) ($run->totals['hop_index'] ?? 0);
            $hop = $plan['route']['hops'][$index] ?? null;
            if (! $hop || ($arguments['hop_index'] ?? null) !== $index
                || $max > ($hop['refuel_ceiling'] ?? 0) - ($run->totals['hop_fuel_cost'][$index] ?? 0)
                || ($arguments['units'] ?? PHP_INT_MAX) > ($hop['topped_up'] ?? 0)) {
                throw new GameException('Fleet purchase refuel exceeds the approved route reserve.');
            }

            return;
        }
        $ship = Ship::where('agent_id', $agent->id)->where('symbol', $run->ship_symbol)->first()?->snapshot ?? [];
        if (($arguments['shipType'] ?? null) !== $type || ($arguments['waypointSymbol'] ?? null) !== ($plan['shipyard'] ?? null)
            || ($ship['nav']['waypointSymbol'] ?? null) !== ($plan['shipyard'] ?? null)
            || ($ship['nav']['status'] ?? null) !== 'DOCKED' || ($ship['cargo']['units'] ?? null) !== 0
            || ($plan['purchaser'] ?? null) !== $run->ship_symbol || ReconMission::holds($agent, $run->ship_symbol)
            || ShipCommandExecutor::configurationHash($ship) !== ($plan['purchaser_configuration_hash'] ?? null)
            || empty($arguments['offer_configuration_hash']) || ($plan['offer_configuration_hash'] ?? $run->totals['offer_configuration_hash'] ?? null) !== $arguments['offer_configuration_hash']
            || $max > $ceiling || ($preflightPrice !== null && $preflightPrice > $max) || ! empty($run->totals['purchased_ships'])) {
            throw new GameException('Fleet purchase differs from the approved yard, offer or purchaser.');
        }
        $this->assertFleetPurchaseSlot($agent, $run->id);
        $cap = match ($type) {
            'SHIP_LIGHT_HAULER' => $policy['max_haulers'] ?? 0,
            'SHIP_LIGHT_SHUTTLE' => $policy['max_shuttles'] ?? 0,
            'SHIP_MINING_DRONE' => $policy['extraction']['max_miners'] ?? 0,
            'SHIP_SIPHON_DRONE' => $policy['extraction']['max_siphons'] ?? 0,
            'SHIP_SURVEYOR' => $policy['extraction']['max_surveyors'] ?? 0,
        };
        if (Ship::where('agent_id', $agent->id)->get()->filter(fn (Ship $row) => $hulls->matchesShip($row->snapshot, $type))->count() >= $cap) {
            throw new GameException('Fleet hull limit reached.');
        }
    }

    public function assertFleetPurchaseSlot(Agent $agent, ?int $runId = null, ?int $actionId = null): void
    {
        app(CampaignProbeBootstrap::class)->assertSlot($agent, $runId, $actionId);
        $types = app(HullCatalog::class)->allTypes();
        if (AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->whereNotNull('active_agent_id')
            ->where('strategy', 'buy_fleet')->whereIn('plan->ship_type', $types)->when($runId, fn ($query) => $query->whereKeyNot($runId))->exists()
            || ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('command', 'buy_ship')
                ->whereIn('arguments->shipType', $types)->where(fn ($query) => $query->whereIn('state', ['pending', 'sending', 'uncertain'])->orWhere('review_required', true))
                ->when($runId, fn ($query) => $query->where(fn ($query) => $query->whereNull('automation_run_id')->orWhere('automation_run_id', '!=', $runId)))
                ->when($actionId, fn ($query) => $query->whereKeyNot($actionId))->exists()) {
            throw new GameException('The shared fleet purchase slot is occupied.');
        }
    }

    /**
     * @param  array|null  $gate  The costly positive_spread_gte metric is computed only when this gate uses it. It
     *                            ranks trades, which records first-seen bids in episode state, so with no gate it is null.
     */
    public function metrics(CampaignEpisode $episode, Agent $agent, ?array $gate = null): array
    {
        $lastTrade = AutomationRun::where('campaign_episode_id', $episode->id)->where('strategy', 'trade')->whereNotNull('started_at')->latest('id')->first();
        $tradeAt = $lastTrade ? ($lastTrade->active_agent_id ? now() : $lastTrade->updated_at) : $episode->opened_at;

        return ['minutes_elapsed_gte' => $episode->opened_at->diffInMinutes(now()),
            'minutes_without_trade_gte' => $tradeAt->diffInMinutes(now()),
            'hours_to_reset_lte' => now()->diffInHours($episode->next_reset_at, false), 'credits_gte' => $agent->credits,
            'markets_observed_gte' => GameObservation::where('agent_id', $agent->id)->where('reset_id', $episode->reset_id)->where('kind', 'market')->get()->filter(fn ($row) => ! empty($row->data['tradeGoods']))->count(),
            'cycles_completed_gte' => AutomationRun::where('campaign_episode_id', $episode->id)->get()->sum(fn ($run) => $run->totals['cycles'] ?? 0),
            'positive_spread_gte' => $gate !== null && in_array('positive_spread_gte', CampaignPlaybook::gateMetrics($gate), true) ? $this->positiveSpreads($episode, $agent) : null];
    }

    /**
     * Trade candidates the command ship could run now whose score (predicted margin per planned
     * minute) reaches the value floor, so a tiny margin never ends discover. A ranking error
     * counts none rather than pausing the campaign; only a retryable refusal is rethrown.
     */
    private function positiveSpreads(CampaignEpisode $episode, Agent $agent): int
    {
        $ship = app(CampaignFleet::class)->role($episode, 'command');
        if (! $ship) {
            return 0;
        }
        $considered = [];
        try {
            $ranked = app(CampaignTrade::class)->rank($episode->grant(), $episode, $agent, $ship, $considered);
        } catch (GameException $exception) {
            if ($exception->retryAfter > 0) {
                throw $exception;
            }

            return 0;
        }

        return count(array_filter($ranked, fn ($candidate) => $candidate['score'] >= CampaignTrade::FLOOR_CR_PER_MINUTE));
    }

    public function windDown(CampaignEpisode $episode): bool
    {
        return $episode->phase === 'wind_down' || now()->addHour()->gte($episode->next_reset_at);
    }
}
