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
                if (! $control) {
                    continue;
                }
                $episode = CampaignEpisode::where('campaign_grant_id', $control->id)->whereNull('closed_at')->latest('id')->first();
                if (! $episode) {
                    continue;
                }
                $key = 'kill_switch:'.$control->user_id.':'.$episode->reset_id;
                $state = json_decode(DB::table('game_runtime')->where('key', $key)->value('value') ?? '{}', true) ?: [];
                $current = $this->controlWindow($control, $episode);
                // Only C0 loads enter this baseline. Single-agent operation never takes action.
                if ($current && ($grants->count() === 1 || empty($state['baseline']))) {
                    $state['baseline'] = $current;
                }
                if ($grants->count() > 1 && ! empty($state['baseline']) && $current) {
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
                $agent = Agent::find($episode->agent_id);
                $reason = $agent && ! $agent->isArchived() ? app(CampaignSafety::class)->reason($grant, $episode, $agent) : null;
                if (! in_array($reason, ['uncertain_action', 'unexplained_change'], true)) {
                    continue;
                }
                foreach (collect([$grant])->merge(CampaignGrant::liveArms($grant->resolvedAgentSymbol())->where('user_id', $grant->user_id)->get()) as $affected) {
                    app(CampaignControl::class)->pause($affected, $reason);
                }
            }
        }
    }

    private function pressureChecks(Collection $grants, CampaignGrant $control, CampaignEpisode $episode, array &$state, array $current): void
    {
        $budget = app(RequestBudget::class)->usage(agentSymbol: 'GIANTSOUP');
        $host = $budget['host'];
        $agent = $budget['agent'];
        $base = $state['baseline'];
        $requests = $agent['tier2_4_last_hour'] + $agent['tier1_last_hour'];
        $deferrals = $agent['deferrals_last_hour'];
        $drop = ($base['loads_per_hour'] > 0 && $current['loads_per_hour'] < $base['loads_per_hour'] * .85)
            || ($base['cr_per_hour'] > 0 && $current['cr_per_hour'] < $base['cr_per_hour'] * .85);
        $conditions = [
            'control_degradation' => [$drop && $deferrals / max(1, $requests + $deferrals) > .05, 900],
            'host_operating_pressure' => [$host['tier2_4_last_60_seconds'] >= 86.4, 300],
            'host_total_pressure' => [$host['tier2_4_last_60_seconds'] + $host['tier1_last_60_seconds'] >= 102, 300],
            'host_rate_limits' => [$host['rate_limits_last_15_minutes'] >= 3, 0],
        ];
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
        $priority = ['SOUPFLEET' => 2, 'SOUPBURN' => 1];
        $targets = $grants->filter(fn ($g) => $g->id !== $control->id && $g->fresh()->available())
            ->sortByDesc(fn ($g) => [$priority[$g->resolvedAgentSymbol()] ?? 0, $g->id]);
        $target = $targets->first(fn ($g) => ! $g->isDraining());
        // One agent drains at a time; only persistent pressure after settlement escalates further.
        if ($breaches && ! $targets->contains(fn ($g) => $g->isDraining()) && $target) {
            $this->drainWithArms($target, $breaches[0]);
            $state['breaches'] = [];

            return;
        }
        if (($base['pair_margin'] ?? 0) <= 0 || ($current['pair_margin'] ?? null) === null
            || $current['pair_margin'] >= $base['pair_margin'] * .8 || ! $this->noMarketDepletion($base, $current)) {
            return;
        }
        foreach ($targets as $other) {
            $agents = Agent::where('user_id', $control->user_id)->where('reset_id', $episode->reset_id)->where('name', $other->resolvedAgentSymbol())->pluck('id');
            $overlap = Ship::whereIn('agent_id', $agents)->where('snapshot->nav->systemSymbol', 'X1-SK70')->exists();
            $trading = CampaignEvent::whereIn('campaign_episode_id', CampaignEpisode::whereIn('agent_id', $agents)->select('id'))
                ->where('kind', 'load_result')->where('created_at', '>', now()->subHours(3))->exists();
            if ($overlap && $trading && ! $other->isDraining()) {
                $this->drainWithArms($other, 'shared_system_cannibalization');
                break;
            }
        }
    }

    private function drainWithArms(CampaignGrant $grant, string $reason): void
    {
        foreach (collect([$grant])->merge(CampaignGrant::liveArms($grant->resolvedAgentSymbol())->where('user_id', $grant->user_id)->whereNull('paused_at')->get()) as $affected) {
            app(CampaignControl::class)->drainPause($affected, $reason);
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
