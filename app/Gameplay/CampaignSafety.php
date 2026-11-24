<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\ReconMission;
use App\Models\Ship;
use App\Models\ShipAction;
use Illuminate\Support\Facades\DB;

class CampaignSafety
{
    public function reason(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, ?array $roleShips = null): ?string
    {
        $reason = $this->actionBlocker($grant, $episode, $agent, pinRoles: $roleShips === null, fleet: $roleShips);
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
        if ($this->uncertain($agent) || $this->purchaseConfigurationMismatch($grant, $agent)) {
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

    public function unexplainedBalance(Agent $agent): bool
    {
        return ! ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->whereIn('state', ['pending', 'sending'])->exists()
            && (app(CreditAccounting::class)->summary($agent)['unexplained_change'] ?? 0) !== 0;
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
            return;
        }
        $episode = CampaignEpisode::findOrFail($run->campaign_episode_id);
        $grant = $episode->grant();
        $reason = $boundaryOnly ? ($episode->state['pending_pause'] ?? null) : $this->reason($grant, $episode, $agent);
        if (! $boundaryOnly && $reason && $reason !== 'consecutive_losing_loads') {
            app(CampaignControl::class)->pause($grant, $reason, $episode);
        }
        if (($reason && ($reason !== 'consecutive_losing_loads' || empty($run->plan['sell_only'])))
            || ! $grant->fresh()->available() || $episode->closed_at || $run->reset_id !== $episode->reset_id || $agent->id !== $episode->agent_id || $agent->reset_id !== $episode->reset_id
            || ($grant->arm() && CampaignDirector::armReset($grant, $agent->reset_id, CampaignDirector::cachedSchedule()) !== $agent->reset_id)) {
            throw new GameException('Campaign authority is paused, closed or awaiting liquidation.');
        }
        if (! in_array($command, [null, 'flight_mode', 'orbit', 'dock', 'navigate', 'mine', 'mine_survey', 'sell', 'buy', 'refuel', 'buy_ship'], true)) {
            throw new GameException('Command is outside the campaign grant.');
        }
        if ($grant->isDraining() && (empty($run->plan['sell_only']) || in_array($command, ['buy', 'buy_ship', 'mine', 'mine_survey'], true))) {
            throw new GameException('Drain pause only permits settling cargo aboard.');
        }
        if ($command === 'flight_mode' && ($run->strategy !== 'sell_aboard' || empty($run->plan['restore_cruise']) || empty($run->plan['sell_only']) || ($arguments['flightMode'] ?? null) !== 'CRUISE')) {
            throw new GameException('Campaign flight mode only permits CRUISE cargo recovery.');
        }
        if ($episode->next_reset_at->isPast()) {
            throw new GameException('Awaiting confirmation of the next reset.');
        }
        if ($this->windDown($episode) && in_array($command, ['buy', 'buy_ship', 'mine', 'mine_survey'], true)) {
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
        if (isset($run->plan['mesh_work']) && in_array($command, ['orbit', 'dock', 'navigate'], true)) {
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
        if ($command === 'buy_ship' || ($command === 'refuel' && in_array($run->strategy, CampaignProbeBootstrap::STRATEGIES, true))) {
            $this->purchaseBoundary($run, $agent, $grant, $episode, $command, $arguments, $boundaryOnly, $preflightPrice);
        }
        if (in_array($command, ['buy', 'sell'], true)) {
            app(FleetClaims::class)->follow($run, $episode, $command, $arguments);
        }
        if ($command === 'buy' && $grant->arm() && ($arguments['maxTotal'] ?? PHP_INT_MAX) > app(CampaignTrade::class)->allowance($grant, $agent, $run)) {
            throw new GameException('Buy skipped: arm exposure requires a smaller batch.', retryAfter: 30);
        }
        if ($command === 'buy' && ! $boundaryOnly) {
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

    private function purchaseBoundary(AutomationRun $run, Agent $agent, CampaignGrant $grant, CampaignEpisode $episode, string $command, array $arguments, bool $boundaryOnly, ?int $preflightPrice): void
    {
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
