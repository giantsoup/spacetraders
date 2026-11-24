<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\CampaignRevision;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Immediate, this-reset replacement only. Called before generic approval can mutate either slot. */
class CampaignLocalEarnReplacement
{
    public function approve(CampaignControl $control, CampaignGrant $old, CampaignRevision $revision, User $user, ?CarbonImmutable $expires, ?array $expected): CampaignGrant
    {
        $episode = CampaignEpisode::where('campaign_grant_id', $old->id)->whereNull('closed_at')->first();
        if (! $expected || ($expected['grant'] ?? null) !== $old->id || ($expected['hash'] ?? null) !== $old->revision_hash || ($expected['episode'] ?? null) !== $episode?->id) {
            throw new GameException('Local Earn replacement needs the exact expected C0 grant, revision hash and open episode.');
        }
        $agent = Agent::findOrFail($episode->agent_id);

        return app(HostLock::class)->scoped('agent-'.$agent->id, fn () => app(ShipCommandExecutor::class)->locked($agent,
            fn () => DB::transaction(function () use ($control, $old, $revision, $user, $expires, $expected, $episode, $agent) {
                $old->refresh();
                $episode->refresh();
                $agent->refresh();
                $symbol = strtoupper((string) $revision->body['agent']['symbol']);
                $current = CampaignGrant::primaryFor($user->id, $symbol)->first();
                if ($current?->id !== $expected['grant'] || $old->revision_hash !== $expected['hash'] || ! hash_equals($old->revision_hash, $old->revision()->content_hash)
                    || $episode->id !== $expected['episode'] || $episode->campaign_grant_id !== $old->id || $episode->closed_at
                    || CampaignEpisode::where('campaign_grant_id', $old->id)->whereNull('closed_at')->count() !== 1) {
                    throw new GameException('Local Earn replacement basis changed.');
                }
                if ($old->user_id !== $user->id || $agent->user_id !== $user->id || $agent->reset_id !== $episode->reset_id || $agent->isArchived()
                    || $old->revision()->body['agent'] !== $revision->body['agent'] || $agent->name !== $revision->body['agent']['symbol']
                    || $agent->faction !== $revision->body['agent']['faction']) {
                    throw new GameException('Local Earn replacement must keep the exact owner, agent and reset.');
                }
                if ($old->isDraining() || $old->arm() || CampaignGrant::pendingFor($user->id, $symbol)->exists() || ! $old->available() || $old->paused_at || $episode->phase !== 'earn'
                    || app(CampaignSafety::class)->windDown($episode) || ! empty($episode->state['pending_pause'])) {
                    throw new GameException('Local Earn replacement requires unpaused Earn authority, no pending approval or pause, and time before wind-down.');
                }
                if (! $old->expires_at || $old->expires_at->isPast() || ($expires && ! $expires->equalTo($old->expires_at))) {
                    throw new GameException('Local Earn replacement requires the original non-null, future expiry unchanged.');
                }
                $before = $old->revision()->body;
                $after = $revision->body;
                unset($before['trade']['local_earn'], $after['trade']['local_earn']);
                $phases = array_values(array_filter($before['phases'], fn ($phase) => in_array($phase['id'], ['earn', 'wind_down'], true)));
                if ($after['trade'] !== $before['trade'] || $after['limits'] !== $before['limits'] || $after['predictions'] !== $before['predictions']
                    || $after['phases'] !== $phases || ($after['phases'][0]['id'] ?? null) !== 'earn') {
                    throw new GameException('Local Earn successor must preserve trade terms, limits, predictions and Earn/Wind-down activities.');
                }
                app(CampaignTrade::class)->recordResults($episode);
                $this->settled($control, $old, $episode, $agent);
                $reason = app(CampaignSafety::class)->reason($old, $episode->refresh(), $agent);
                $latest = CampaignEvent::where('campaign_episode_id', $episode->id)->where('kind', 'load_result')->orderByDesc('data->finished_at')->orderByDesc('id')->first();
                if ($reason || ($latest && CampaignTrade::losing($latest->data))) {
                    throw new GameException('Local Earn replacement cannot discard safety or the latest losing load: '.($reason ?? 'latest_losing_load'));
                }
                $arms = $this->armModes($episode);
                $this->settled($control, $old->refresh(), $episode->refresh(), $agent);
                if (! $old->available() || $old->expires_at->lte(now()) || app(CampaignSafety::class)->windDown($episode)) {
                    throw new GameException('Local Earn replacement authority expired or reached wind-down during approval.');
                }

                $old->update(['slot' => null, 'superseded_at' => now()]);
                $episode->update(['closed_at' => now()]);
                $control->event($old, $episode, 'superseded', ['successor_policy' => 'local_earn']);
                $grant = CampaignGrant::create(['campaign_revision_id' => $revision->id, 'revision_hash' => $revision->content_hash,
                    'user_id' => $user->id, 'agent_symbol' => $symbol, 'limits' => $revision->body['limits'], 'expires_at' => $old->expires_at, 'slot' => 1]);
                $successor = CampaignEpisode::create(['campaign_grant_id' => $grant->id, 'agent_id' => $agent->id, 'reset_id' => $episode->reset_id,
                    'phase' => 'earn', 'opened_at' => now(), 'start_credits' => $agent->credits, 'next_reset_at' => $episode->next_reset_at,
                    'state' => ['role_ships' => $episode->state['role_ships'] ?? []]]);
                $control->event($grant, $successor, 'approval', ['revision' => $revision->id, 'pending' => false,
                    'replaced_grant' => $old->id, 'replaced_episode' => $episode->id, 'expected_hash' => $expected['hash'],
                    'expires_at' => $old->expires_at->toIso8601String(), 'learning' => 'Fresh C0 proof and bids; H1 own results retained; C0 learning and receipt-derived decay reference changes.', 'arm_modes' => $arms]);

                return $grant;
            })));
    }

    private function settled(CampaignControl $control, CampaignGrant $grant, CampaignEpisode $episode, Agent $agent): void
    {
        $command = app(CampaignFleet::class)->role($episode, 'command');
        $roles = app(CampaignFleet::class)->symbols($episode);
        $runs = AutomationRun::where('agent_id', $agent->id)->where('reset_id', $episode->reset_id)
            ->where(fn ($q) => $q->where('campaign_episode_id', $episode->id)->orWhereIn('ship_symbol', $roles));
        $busy = (clone $runs)->where(fn ($q) => $q->whereNotNull('active_agent_id')
            ->orWhere(fn ($q) => $q->where('state', 'preview')->where('expires_at', '>', now())))->exists();
        $actions = ShipAction::where('agent_id', $agent->id)->where('reset_id', $episode->reset_id)
            ->where(fn ($q) => $q->whereIn('ship_symbol', $roles)->orWhereNull('ship_symbol')->orWhere('ship_symbol', '')->orWhereIn('automation_run_id', (clone $runs)->select('id')))
            ->where(fn ($q) => $q->whereIn('state', ['pending', 'sending', 'uncertain'])->orWhere('review_required', true))->exists();
        $ships = Ship::where('agent_id', $agent->id)->whereIn('symbol', $roles)->get();
        if (! $command || $control->busy($grant) || $busy || $actions || $ships->contains(fn ($ship) => ($ship->snapshot['cargo']['units'] ?? 0) !== 0 || ($ship->snapshot['nav']['status'] ?? null) === 'IN_TRANSIT')) {
            throw new GameException('Local Earn replacement requires idle, settled C0 ships, no cargo, transit, retained preview or relevant action.');
        }
    }

    private function armModes(CampaignEpisode $c0): array
    {
        $trades = app(CampaignTrade::class);
        $levels = ['proof' => 0, 'first_scaled' => 1, 'scaled' => 2];
        $evidence = [];
        foreach (CampaignEpisode::where('agent_id', $c0->agent_id)->where('reset_id', $c0->reset_id)->whereNull('closed_at')
            ->whereIn('campaign_grant_id', app(CampaignFleet::class)->currentArms(Agent::findOrFail($c0->agent_id))->pluck('id'))->get() as $arm) {
            $trades->recordResults($arm);
            $own = $trades->results($arm);
            $combined = $trades->learningResults($arm);
            $pairs = collect(array_merge($own, $combined))->unique(fn ($row) => $row['good'].'@'.$row['buyer']);
            foreach ($pairs as $pair) {
                $before = $trades->mode($arm, $pair['good'], $pair['buyer'], $combined);
                $after = $trades->mode($arm, $pair['good'], $pair['buyer'], $own);
                if ($levels[$after] > $levels[$before]) {
                    throw new GameException('Local Earn replacement would increase arm '.$arm->grant()->arm()['label'].' sizing for '.$pair['good'].'@'.$pair['buyer'].' from '.$before.' to '.$after.'.');
                }
                $evidence[] = ['arm_episode' => $arm->id, 'good' => $pair['good'], 'buyer' => $pair['buyer'], 'before' => $before, 'after' => $after];
            }
        }

        return $evidence;
    }
}
