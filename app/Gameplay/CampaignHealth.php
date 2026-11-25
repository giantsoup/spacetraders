<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\LocalEngine;
use App\Models\Ship;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CampaignHealth
{
    /**
     * The probe's own runs hide a stranded command ship from the sixty-minute check. The longest
     * normal command-ship gap observed was about nineteen minutes.
     */
    public const COMMAND_STALL_MINUTES = 25;

    /** No command-ship alert while a new episode starts up. */
    public const COMMAND_STALL_GRACE_MINUTES = 30;

    public function problems(?CampaignGrant $selected = null): array
    {
        if ($selected) {
            return $this->grantProblems($selected);
        }
        $grants = CampaignGrant::allPrimaries()->get();
        $problems = [];
        foreach ($grants as $grant) {
            foreach ($this->grantProblems($grant) as $key => $message) {
                $problems[$key !== 'engine_heartbeat_stale' && $grants->count() > 1 ? $grant->resolvedAgentSymbol().':'.$key : $key] = $message;
            }
        }

        return $problems;
    }

    private function grantProblems(?CampaignGrant $grant): array
    {
        if (! $grant) {
            return [];
        }
        $problems = [];
        $engine = LocalEngine::find(1);
        if (! $engine?->heartbeat_at || $engine->heartbeat_at->lt(now()->subMinutes(5))) {
            $problems['engine_heartbeat_stale'] = 'Engine heartbeat is missing or older than five minutes.';
        }
        $alive = $engine?->state === 'running' && ! isset($problems['engine_heartbeat_stale']);
        if ($alive && ! $grant->paused_at) {
            $resumed = $grant->last_directed_at ? null : CampaignEvent::where('campaign_grant_id', $grant->id)->where('kind', 'resume')->latest('id')->value('created_at');
            $seen = $grant->last_directed_at ?? $resumed ?? $grant->created_at;
            if ($seen->lt(now()->subMinutes(15))) {
                $problems['campaign_decisions_stalled:'.$grant->id] = 'Campaign director has not run for fifteen minutes while the engine is alive.';
            }
            $episode = CampaignEpisode::where('campaign_grant_id', $grant->id)->whereNull('closed_at')->latest('id')->first();
            $started = AutomationRun::whereIn('campaign_episode_id', CampaignEpisode::where('campaign_grant_id', $grant->id)->select('id'))->where('strategy', '!=', 'sell_aboard')->max('started_at');
            $result = CampaignEvent::where('campaign_grant_id', $grant->id)->whereIn('kind', ['load_result', 'recovery_result'])->latest('id')->value('created_at');
            $progress = collect([$episode?->opened_at ?? $grant->created_at, $started ? CarbonImmutable::parse($started) : null, $result])->filter()->max();
            if ($episode?->phase !== 'wind_down' && $progress->lte(now()->subMinutes(60))) {
                $problems['campaign_progress_stalled:'.$grant->id] = 'Campaign has not started a run or recorded a load or recovery result for sixty minutes.';
            }
            if ($episode && $this->commandStalled($grant, $episode)) {
                $problems['command_ship_stalled:'.$grant->id] = 'The command ship has not started a run for '.self::COMMAND_STALL_MINUTES.' minutes in earn.';
            }
        }
        if ($grant->paused_at) {
            $problems['campaign_paused:'.$grant->id.':'.$grant->pause_reason] = 'Campaign '.$grant->id.' is paused: '.$grant->pause_reason.'.';
        }

        return $problems;
    }

    /** Local telemetry only. Baselines and consecutive-breach timers survive worker restarts. */
    public function evaluateKillSwitch(): void
    {
        app(HostLock::class)->run('campaign', function () {
            foreach (CampaignGrant::allPrimaries()->get()->groupBy('user_id') as $grants) {
                $control = $grants->first(fn ($g) => $g->resolvedAgentSymbol() === 'GIANTSOUP');
                if ($grants->count() > 1) {
                    $this->hardPauseAffectedAgents($grants);
                }
                $episode = $control ? CampaignEpisode::where('campaign_grant_id', $control->id)->whereNull('closed_at')->latest('id')->first() : null;
                if ($grants->count() === 1 && ! $episode) {
                    continue;
                }
                $key = 'kill_switch:'.$grants->first()->user_id.':'.($episode?->reset_id ?? 'unconfirmed');
                $state = json_decode(DB::table('game_runtime')->where('key', $key)->value('value') ?? '{}', true) ?: [];
                $current = $episode ? $this->controlWindow($control, $episode) : null;
                if ($current && ($grants->count() === 1 || empty($state['baseline']))) {
                    $state['baseline'] = $current;
                }
                if ($grants->count() > 1) {
                    $this->pressureChecks($grants, $control, $episode, $state, $current);
                }
                DB::table('game_runtime')->upsert([['key' => $key, 'value' => json_encode($state, JSON_THROW_ON_ERROR)]], ['key'], ['value']);
            }
        });
    }

    private function controlWindow(CampaignGrant $grant, CampaignEpisode $episode): ?array
    {
        if ($episode->opened_at->gt(now()->subHours(3))) {
            return null;
        }
        $episodes = CampaignEpisode::where('reset_id', $episode->reset_id)
            ->whereIn('campaign_grant_id', CampaignGrant::where('user_id', $grant->user_id)->where('agent_symbol', 'GIANTSOUP')->whereNull('limits->arm')->select('id'))->pluck('id');
        $loads = CampaignEvent::whereIn('campaign_episode_id', $episodes)->where('kind', 'load_result')
            ->where('created_at', '>', now()->subHours(3))->get();
        if ($loads->isEmpty() && ! CampaignEvent::whereIn('campaign_episode_id', $episodes)->where('kind', 'load_result')->exists()) {
            return null;
        }
        $units = $loads->sum(fn ($e) => $e->data['units'] ?? 0);

        return ['at' => now()->timestamp, 'loads_per_hour' => $loads->count() / 3,
            'cr_per_hour' => $loads->sum(fn ($e) => $e->data['net'] ?? 0) / 3,
            'pair_margin' => $units > 0 ? $loads->sum(fn ($e) => $e->data['net'] ?? 0) / $units : null,
            'goods' => $loads->pluck('data.good')->filter()->unique()->values()->all(),
            'market_bids' => $this->marketBids($episode)];
    }

    private function hardPauseAffectedAgents(Collection $grants): void
    {
        foreach ($grants as $grant) {
            $episodes = CampaignEpisode::where('campaign_grant_id', $grant->id)->whereNull('closed_at')->get();
            foreach ($episodes as $episode) {
                try {
                    app(HostLock::class)->run('agent-'.$episode->agent_id, function () use ($episode, $grant) {
                        $agent = Agent::find($episode->agent_id);
                        if (! $agent || $agent->isArchived()) {
                            return;
                        }
                        $safety = app(CampaignSafety::class);
                        $grant->refresh();
                        $episode->refresh();
                        $reason = $safety->reason($grant, $episode, $agent);
                        if ($reason === 'uncertain_action') {
                            $perShip = (bool) (CampaignPlaybook::saturationPolicy($grant->revision()->body)['per_ship_noncredit_locks'] ?? false);
                            $agentUncertain = $perShip
                                ? $safety->uncertainCredit($agent) || $safety->creditReview($agent)
                                : $safety->uncertain($agent);
                            if (! $agentUncertain && ! $safety->purchaseConfigurationMismatch($grant, $agent)
                                && $safety->priceReview($grant, $episode, $agent)) {
                                $reason = $safety->unexplainedBalance($agent) ? 'unexplained_change' : null;
                            }
                        }
                        if (in_array($reason, ['uncertain_action', 'unexplained_change'], true)) {
                            foreach (collect([$grant])->merge($agent->reset_id === $episode->reset_id ? app(CampaignFleet::class)->currentArms($agent) : collect()) as $affected) {
                                app(CampaignControl::class)->pause($affected, $reason);
                            }
                        }
                    });
                } catch (GameException $e) {
                    if ($e->retryAfter === 0) {
                        throw $e;
                    }
                    // A sender owns this agent's balance and receipts. Check after it settles.
                }
            }
        }
    }

    private function pressureChecks(Collection $grants, ?CampaignGrant $control, ?CampaignEpisode $episode, array &$state, ?array $current): void
    {
        $budget = app(RequestBudget::class)->usage(agentSymbol: 'GIANTSOUP');
        $conditions = $this->pressureConditions($budget, $state, $current);
        $priority = ['SOUPFLEET' => 2, 'SOUPBURN' => 1];
        $targets = $grants->filter(fn ($g) => $g->id !== $control?->id && $g->fresh()->available())
            ->sortByDesc(fn ($g) => [$priority[$g->resolvedAgentSymbol()] ?? 0, $g->id]);
        foreach ($targets as $target) {
            $conditions['cannibalization:'.$target->id] = [$this->cannibalization($target, $episode, $state['baseline'] ?? null, $current), 900];
        }
        if (now()->timestamp - ($state['last_evaluation'] ?? 0) > 120) {
            $state['breaches'] = [];
        }
        $state['last_evaluation'] = now()->timestamp;
        $breaches = [];
        foreach ($conditions as $reason => [$breached, $seconds]) {
            if (! $breached) {
                unset($state['breaches'][$reason]);

                continue;
            }
            $state['breaches'][$reason] ??= now()->timestamp;
            if (now()->timestamp - $state['breaches'][$reason] >= $seconds) {
                $breaches[] = $reason;
            }
        }
        if ($targets->contains(fn ($g) => $g->isDraining())) {
            return;
        }
        foreach ($breaches as $reason) {
            $overlap = str_starts_with($reason, 'cannibalization:');
            $target = $overlap ? $targets->firstWhere('id', (int) substr($reason, strlen('cannibalization:'))) : $targets->first();
            if ($target && $this->drainWithArms($target, $overlap ? 'shared_system_cannibalization' : $reason)) {
                $state['last_drain_at'] = $budget['host']['observed_at'];
                $state['breaches'] = [];

                return;
            }
        }
    }

    /** The current measurements for a drain review, without updating timers or the control baseline. */
    public function drainPressure(CampaignGrant $target): array
    {
        $budget = app(RequestBudget::class)->usage(agentSymbol: 'GIANTSOUP', requireTelemetry: true);
        $control = CampaignGrant::primaryFor($target->user_id, 'GIANTSOUP')->first();
        $episode = $control ? CampaignEpisode::where('campaign_grant_id', $control->id)->whereNull('closed_at')->latest('id')->first() : null;
        $key = 'kill_switch:'.$target->user_id.':'.($episode?->reset_id ?? 'unconfirmed');
        $state = json_decode(DB::table('game_runtime')->where('key', $key)->value('value') ?? '{}', true) ?: [];
        $current = $episode ? $this->controlWindow($control, $episode) : null;
        $conditions = $this->pressureConditions($budget, $state, $current);
        $conditions['shared_system_cannibalization'] = [
            $target->id !== $control?->id && $this->cannibalization($target, $episode, $state['baseline'] ?? null, $current), 900,
        ];

        return array_keys(array_filter($conditions, fn ($condition) => $condition[0]));
    }

    private function pressureConditions(array $budget, array $state, ?array $current): array
    {
        $host = $budget['host'];
        $agent = $budget['agent'];
        $base = $state['baseline'] ?? null;
        $requests = $agent['tier2_4_last_hour'] + $agent['tier1_last_hour'];
        $deferrals = $agent['deferrals_last_hour'];
        $drop = $base && $current && ((($base['loads_per_hour'] ?? 0) > 0 && $current['loads_per_hour'] < $base['loads_per_hour'] * .85)
            || (($base['cr_per_hour'] ?? 0) > 0 && $current['cr_per_hour'] < $base['cr_per_hour'] * .85));
        $limits = isset($state['last_drain_at'])
            ? app(RequestBudget::class)->countRateLimitsSince($state['last_drain_at']) : $host['rate_limits_last_15_minutes'];

        return [
            'control_degradation' => [$drop && $deferrals / max(1, $requests + $deferrals) > .05, 900],
            'host_operating_pressure' => [$host['tier2_4_last_60_seconds'] >= 86.4, 300],
            'host_total_pressure' => [$host['tier2_4_last_60_seconds'] + $host['tier1_last_60_seconds'] >= 102, 300],
            'host_rate_limits' => [$limits >= 3, 0],
        ];
    }

    private function cannibalization(CampaignGrant $target, ?CampaignEpisode $episode, ?array $base, ?array $current): bool
    {
        return $episode && ($base['pair_margin'] ?? 0) > 0 && ($current['pair_margin'] ?? null) !== null
            && $current['pair_margin'] < $base['pair_margin'] * .8 && $this->noMarketDepletion($base, $current)
            && $this->tradesInControlSystem($target, $episode);
    }

    private function tradesInControlSystem(CampaignGrant $grant, CampaignEpisode $control): bool
    {
        $agents = Agent::where('user_id', $grant->user_id)->where('reset_id', $control->reset_id)->where('name', $grant->agent_symbol)->pluck('id');
        $ships = Ship::whereIn('agent_id', $agents)->where('snapshot->nav->systemSymbol', 'X1-SK70')->get()
            ->reject(fn ($ship) => app(CampaignFleet::class)->isProbe($ship->snapshot))->pluck('symbol');
        if ($ships->isEmpty()) {
            return false;
        }

        return CampaignEvent::whereIn('campaign_episode_id', CampaignEpisode::whereIn('agent_id', $agents)->where('reset_id', $control->reset_id)->select('id'))
            ->where('kind', 'load_result')->where('created_at', '>', now()->subHours(3))
            ->whereIn('data->ship', $ships)->where('data->buyer', 'like', 'X1-SK70-%')->exists();
    }

    private function drainWithArms(CampaignGrant $grant, string $reason): bool
    {
        $control = app(CampaignControl::class);
        $agent = $control->agent($grant->user_id, $grant->resolvedAgentSymbol());
        $drain = function () use ($control, $agent, $grant, $reason) {
            $arms = $agent && $control->currentEpisode($grant, $agent->refresh())
                ? app(CampaignFleet::class)->currentArms($agent)->filter(fn (CampaignGrant $arm) => ! $arm->paused_at)
                : collect();
            foreach (collect([$grant])->merge($arms) as $affected) {
                $control->drainPause($affected, $reason);
            }

            return true;
        };
        try {
            return $agent ? app(HostLock::class)->run('agent-'.$agent->id, $drain) : $drain();
        } catch (GameException $exception) {
            if ($exception->retryAfter === 0) {
                throw $exception;
            }

            return false;
        }
    }

    private function marketBids(CampaignEpisode $episode): array
    {
        $bids = [];
        foreach (GameObservation::where('agent_id', $episode->agent_id)->where('reset_id', $episode->reset_id)->where('kind', 'market')->where('observed_at', '>', now()->subHours(3))->get() as $market) {
            foreach ($market->data['tradeGoods'] ?? [] as $good) {
                if (($good['sellPrice'] ?? 0) > 0) {
                    $bids[$market->symbol.':'.$good['symbol']] = $good['sellPrice'];
                }
            }
        }

        return $bids;
    }

    /** Require a stable comparison basket outside C0's traded goods before blaming another agent. */
    private function noMarketDepletion(array $base, array $current): bool
    {
        $basket = [];
        foreach ($base['market_bids'] as $key => $price) {
            $good = substr($key, strrpos($key, ':') + 1);
            if (! in_array($good, array_merge($base['goods'], $current['goods']), true) && isset($current['market_bids'][$key])) {
                $basket[] = $current['market_bids'][$key] / $price;
            }
        }

        return count($basket) >= 3 && count(array_filter($basket, fn ($ratio) => $ratio < .8)) < count($basket) / 2;
    }

    /**
     * In earn, outside wind-down and reset, the timer runs from the latest of the command ship's
     * last run start (any strategy), the move into earn, and the last resume, so paused time is
     * not counted. Runs by the probe or by extra non-role ships do not reset it.
     */
    private function commandStalled(CampaignGrant $grant, CampaignEpisode $episode): bool
    {
        $command = $episode->state['role_ships']['command'] ?? null;
        if (! $command || $episode->phase !== 'earn' || app(CampaignSafety::class)->windDown($episode)
            || $episode->opened_at->gt(now()->subMinutes(self::COMMAND_STALL_GRACE_MINUTES))) {
            return false;
        }
        $started = AutomationRun::where('campaign_episode_id', $episode->id)->where('ship_symbol', $command)->whereNotNull('started_at')->max('started_at');
        // An episode whose playbook starts in earn has no phase event; it entered earn when it opened.
        $earn = CampaignEvent::where('campaign_episode_id', $episode->id)->where('kind', 'phase')->where('data->to', 'earn')->latest('id')->value('created_at') ?? $episode->opened_at;
        $resumed = CampaignEvent::where('campaign_grant_id', $grant->id)->where('kind', 'resume')->latest('id')->value('created_at');
        $since = collect([$started ? CarbonImmutable::parse($started) : null, $earn, $resumed])->filter()->max();

        return $since->lte(now()->subMinutes(self::COMMAND_STALL_MINUTES));
    }
}
