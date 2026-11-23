<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\ReconMission;
use App\Models\ShipAction;
use Illuminate\Support\Facades\DB;

class CampaignSafety
{
    public function reason(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent): ?string
    {
        $reason = $this->actionBlocker($grant, $episode, $agent, pinRoles: true);
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

    public function actionBlocker(CampaignGrant $grant, ?CampaignEpisode $episode, Agent $agent, bool $pinRoles = false): ?string
    {
        if ($this->uncertain($agent)) {
            return 'uncertain_action';
        }

        return $this->priceReview($grant, $episode, $agent, $pinRoles) ? 'uncertain_action' : null;
    }

    public function priceReview(CampaignGrant $grant, ?CampaignEpisode $episode, Agent $agent, bool $pinRoles = false, ?array $fleet = null): bool
    {
        $reviews = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('review_required', true);
        // Most reviews have no unsettled actions. Avoid resolving campaign roles until they matter.
        if ($fleet === null && ! $pinRoles && ! (clone $reviews)->exists()) {
            return false;
        }
        $fleet ??= $episode ? app(CampaignFleet::class)->symbols($episode, $pinRoles) : array_filter([$grant->arm()['ship'] ?? null]);
        $runs = AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->when($episode, fn ($query) => $query->where('campaign_episode_id', $episode->id),
                fn ($query) => $query->whereIn('campaign_episode_id', CampaignEpisode::where('campaign_grant_id', $grant->id)
                    ->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->select('id')))->select('id');

        return $reviews->where(fn ($query) => $query->whereIn('ship_symbol', $fleet)->orWhereNull('ship_symbol')->orWhere('ship_symbol', '')
            ->orWhereIn('automation_run_id', $runs))->exists();
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

    public function check(AutomationRun $run, Agent $agent, ?string $command = null, array $arguments = [], bool $boundaryOnly = false): void
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
            || ! $grant->fresh()->available() || $episode->closed_at || $agent->reset_id !== $episode->reset_id) {
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
        if ($command === 'buy_ship') {
            $probes = CampaignProbeBootstrap::policy($grant, $episode);
            if (! $probes || $run->strategy !== 'buy_probe' || ($arguments['shipType'] ?? null) !== 'SHIP_PROBE') {
                throw new GameException('Command is outside the campaign grant.');
            }
            $maxTotal = $arguments['maxTotal'] ?? PHP_INT_MAX;
            $bootstrap = app(CampaignProbeBootstrap::class);
            if ($boundaryOnly) {
                $bootstrap->assertPurchaseBudget($agent);
            }
            $reserve = $bootstrap->effectiveReserve($agent, $grant, $probes, $run->id);
            if ($maxTotal > $probes['max_price'] || $agent->credits - $maxTotal < $reserve) {
                throw new GameException('Probe purchase exceeds max_price or working-capital reserve.');
            }
        }
        if ($command === 'refuel' && $run->strategy === 'buy_probe' && ! $boundaryOnly) {
            $probes = CampaignProbeBootstrap::policy($grant, $episode);
            if (! $probes) {
                throw new GameException('Command is outside the campaign grant.');
            }
            $maxTotal = $arguments['maxTotal'] ?? PHP_INT_MAX;
            $reserve = app(CampaignProbeBootstrap::class)->effectiveReserve($agent, $grant, $probes, $run->id);
            if ($agent->credits - $maxTotal < $reserve) {
                throw new GameException('Probe bootstrap refuel exceeds current working-capital reserve.');
            }
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
