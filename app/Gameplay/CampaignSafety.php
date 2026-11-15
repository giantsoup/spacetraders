<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\ShipAction;

class CampaignSafety
{
    public function reason(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent): ?string
    {
        if (ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where(fn ($query) => $query->where('state', 'uncertain')->orWhere('review_required', true))->exists()) {
            return 'uncertain_action';
        }
        if ((app(CreditAccounting::class)->summary($agent)['unexplained_change'] ?? 0) !== 0) {
            return 'unexplained_change';
        }
        if (! empty($episode->state['pending_pause'])) {
            return $episode->state['pending_pause'];
        }
        $results = array_filter(app(CampaignTrade::class)->results($episode), fn ($result) => ! isset($episode->state['losses_reviewed_at']) || $result['finished_at'] > $episode->state['losses_reviewed_at']);
        $consecutive = 0;
        foreach (array_reverse($results) as $result) {
            if (! $result['losing']) {
                break;
            }
            $consecutive++;
        }

        return $consecutive >= $grant->revision()->body['trade']['losses']['pause_after_consecutive_losing_loads'] ? 'consecutive_losing_loads' : null;
    }

    public function check(AutomationRun $run, Agent $agent, ?string $command = null, array $arguments = []): void
    {
        if (! $run->campaign_episode_id) {
            return;
        }
        $episode = CampaignEpisode::findOrFail($run->campaign_episode_id);
        $grant = $episode->grant();
        $reason = $this->reason($grant, $episode, $agent);
        if ($reason && $reason !== 'consecutive_losing_loads') {
            app(CampaignControl::class)->pause($grant, $reason, $episode);
        }
        if (($reason && ($reason !== 'consecutive_losing_loads' || empty($run->plan['sell_only'])))
            || ! $grant->fresh()->available() || $episode->closed_at || $agent->reset_id !== $episode->reset_id) {
            throw new GameException('Campaign authority is paused, closed or awaiting liquidation.');
        }
        if (! in_array($command, [null, 'orbit', 'dock', 'navigate', 'mine', 'mine_survey', 'sell', 'buy', 'refuel'], true)) {
            throw new GameException('Command is outside the campaign grant.');
        }
        if ($episode->next_reset_at->isPast()) {
            throw new GameException('Awaiting confirmation of the next reset.');
        }
        if ($this->windDown($episode) && in_array($command, ['buy', 'mine', 'mine_survey'], true)) {
            throw new GameException('Wind-down only permits selling aboard.');
        }
        if ($command === 'buy') {
            if ($run->strategy !== 'trade' || ! empty($run->plan['sell_only'])) {
                throw new GameException('Recovery blocks new trade buys.');
            }
            app(CampaignTrade::class)->quoteLife($agent, $run->plan, $run->totals);
            $reserve = app(CampaignTrade::class)->fuelReserve($agent, $run->ship_symbol, $run->plan['market']);
            if ($agent->credits - ($arguments['maxTotal'] ?? PHP_INT_MAX) < $reserve) {
                throw new GameException('Buy skipped: delivery fuel reserve requires a smaller batch.');
            }
        }
    }

    public function metrics(CampaignEpisode $episode, Agent $agent): array
    {
        $lastTrade = AutomationRun::where('campaign_episode_id', $episode->id)->where('strategy', 'trade')->whereNotNull('started_at')->latest('id')->first();
        $tradeAt = $lastTrade ? ($lastTrade->active_agent_id ? now() : $lastTrade->updated_at) : $episode->opened_at;

        return ['minutes_elapsed_gte' => $episode->opened_at->diffInMinutes(now()),
            'minutes_without_trade_gte' => $tradeAt->diffInMinutes(now()),
            'hours_to_reset_lte' => now()->diffInHours($episode->next_reset_at, false), 'credits_gte' => $agent->credits,
            'markets_observed_gte' => GameObservation::where('agent_id', $agent->id)->where('reset_id', $episode->reset_id)->where('kind', 'market')->get()->filter(fn ($row) => ! empty($row->data['tradeGoods']))->count(),
            'cycles_completed_gte' => AutomationRun::where('campaign_episode_id', $episode->id)->get()->sum(fn ($run) => $run->totals['cycles'] ?? 0)];
    }

    public function windDown(CampaignEpisode $episode): bool
    {
        return $episode->phase === 'wind_down' || now()->addHour()->gte($episode->next_reset_at);
    }
}
