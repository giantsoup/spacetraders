<?php

namespace App\Gameplay\Bridge;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\CampaignFleet;
use App\Gameplay\CampaignHealth;
use App\Gameplay\CampaignSafety;
use App\Gameplay\RequestDeferred;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Services\SpaceTradersClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BridgeGuards
{
    public const RESET_MESSAGE = 'Waiting to confirm the current wipe. Reopen this review once it is confirmed.';

    public static function resetConfirmed(?Agent $agent, ?CampaignEpisode $episode): bool
    {
        if (! $agent || $agent->isArchived() || ! $agent->reset_id
            || ($agent->canonical_identity !== null && $agent->canonical_identity !== AgentSynchronizer::identity($agent->reset_id, $agent->name))) {
            return false;
        }
        $key = 'reset_schedule:'.hash('sha256', SpaceTradersClient::BASE_URL);
        $schedule = json_decode(DB::table('game_runtime')->where('key', $key)->value('value') ?? '{}', true);
        $next = self::scheduleTime($schedule['next_reset'] ?? null);
        $observed = self::scheduleTime($schedule['observed_at'] ?? null);
        $known = $next && $observed && $observed->lte(now()) && $next->gt($observed)
            && is_string($schedule['reset_id'] ?? null) && $schedule['reset_id'] !== '';

        if (is_string($schedule['reset_id'] ?? null) && $schedule['reset_id'] !== '' && $schedule['reset_id'] !== $agent->reset_id) {
            // A newly registered identity is more recent than a schedule for a prior wipe.
            $prior = self::scheduleTime($schedule['reset_id']);
            $current = self::scheduleTime($agent->reset_id);
            if (! $known || ! $prior || ! $current || $prior->gte($current) || ! $agent->created_at?->gt($observed)) {
                return false;
            }
            $known = false;
        }
        if ($episode && $episode->agent_id === $agent->id && $episode->reset_id === $agent->reset_id) {
            return $episode->next_reset_at?->gt(now()) ?? false;
        }

        return $known && $next->gt(now());
    }

    private static function scheduleTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}(?:[T ][\d:.+-]+Z?)?\z/', $value)) {
            return null;
        }
        try {
            $date = CarbonImmutable::parse($value);
            $errors = CarbonImmutable::getLastErrors();

            return $errors && ($errors['warning_count'] || $errors['error_count']) ? null : $date;
        } catch (\Exception) {
            return null;
        }
    }

    public static function lossStop(CampaignGrant $grant, int $net): bool
    {
        return CampaignSafety::lossStop($grant, $net);
    }

    public static function retireEligible(CampaignGrant $grant, int $net): bool
    {
        return $grant->arm() !== null && (
            self::lossStop($grant, $net) || in_array($grant->pause_reason, ['director_error', 'grant_expired'], true)
            || $grant->expires_at?->lte(now())
        );
    }

    public function resetBlocker(?CampaignGrant $primary = null): array
    {
        $message = match ($primary?->pause_reason) {
            'registration_unknown', 'registration_requires_review' => 'Confirm the agent registration on the flight deck, then use campaign:resume on the PC if registration is paused.',
            'account_token_missing' => 'Save the account token on the flight deck, then use campaign:resume on the PC.',
            default => self::RESET_MESSAGE,
        };

        return $this->blocker('reset_unconfirmed', $message);
    }

    /** Shared balance and command-outcome blockers apply across all grants of this agent. */
    public function agentBlockers(Agent $agent): array
    {
        $safety = app(CampaignSafety::class);
        $hasFlaggedAction = ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where(fn ($query) => $query->where('state', 'uncertain')->orWhere('review_required', true))->exists();
        if ($hasFlaggedAction && ($safety->perShipLocksEnabled($agent) ? ($safety->uncertainCredit($agent) || $safety->creditReview($agent)) : $safety->uncertain($agent))) {
            return [$this->blocker('uncertain_action', 'Reconcile the uncertain command on the flight deck.', $agent)];
        }
        if ($safety->unexplainedBalance($agent)) {
            return [$this->blocker('unexplained_change', 'Review the unexplained credit change on the flight deck.', $agent)];
        }

        return [];
    }

    public function resumeBlockers(CampaignGrant $grant, ?CampaignEpisode $episode, Agent $agent, int $net, ?BridgeArmEvidence $evidence): array
    {
        $blockers = [];
        if ($grant->arm() && $grant->pause_reason === 'reset_mismatch') {
            $blockers[] = $this->blocker('reset_mismatch', 'This arm does not have approval for the current wipe.');
        }
        $draining = BridgeDrain::marker($grant, $episode) !== null;
        $arm = $grant->arm() ? $evidence?->forGrant($grant, $episode, $draining) : null;
        $recovery = $draining && $arm === null ? $this->recoveryFleet($agent, $grant, $episode) : null;
        $safety = app(CampaignSafety::class);
        if ($safety->purchaseConfigurationMismatch($grant, $agent)) {
            $blockers[] = $this->blocker('configuration_mismatch', 'Review the ship purchase configuration on the flight deck.', $agent);
        } elseif ($arm['heldPrice'] ?? $safety->priceReview($grant, $episode, $agent, fleet: $recovery['roleSymbols'] ?? null)) {
            $blockers[] = $this->blocker('uncertain_action', 'Review the held price on the flight deck.', $agent);
        }
        if ($grant->expires_at?->lte(now())) {
            $message = $grant->arm() ? 'This arm approval expired. Retire the arm and approve a new one on the PC.'
                : 'This playbook approval expired. Approve a playbook on the PC.';
            $blockers[] = $this->blocker('grant_expired', $message);
        }
        if (self::lossStop($grant, $net)) {
            $blockers[] = $this->blocker('arm_loss_stop', 'This arm hit its loss limit. Review and retire it.');
        }
        $settling = $arm === null
            ? $this->settlingRun($this->runs($agent, $grant, $episode), $agent, $draining)
            : ($arm['run'] ? $this->runBlocker($arm['run'], $agent, $arm['runActions']) : null);
        if ($settling) {
            $blockers[] = $settling;
        }
        if ($draining) {
            $incomplete = BridgeDrain::incomplete($grant, $episode);
            array_push($blockers, ...($arm === null ? $this->drainSettlement($agent, $grant, $episode, $recovery) : $this->settlementBlockers(
                $agent, $arm['pending'], $incomplete ? $arm['preview'] : null, $incomplete ? $arm['recovery'] : null,
            )));
        }

        return $blockers;
    }

    public function retireBlockers(CampaignGrant $grant, ?CampaignEpisode $episode, Agent $agent, int $net): array
    {
        if (! self::retireEligible($grant, $net)) {
            return [$this->blocker('not_retirable', 'Phone retirement is available for an arm that hit its loss limit, stopped after repeated director failures, or expired.')];
        }
        $ship = $grant->arm()['ship'];
        $pending = $episode ? app(CampaignFleet::class)->pendingForShip($episode, $ship)
            : $this->pending($agent)->where(fn ($query) => $query->where('ship_symbol', $ship)
                ->orWhereIn('automation_run_id', $this->runs($agent, $grant, null)->select('id')))->exists();

        return $pending ? [$this->blocker('settling', 'Wait for the outstanding command on '.$ship.' before retiring it.', $agent)] : [];
    }

    public function pressureBlockers(CampaignGrant $primary): array
    {
        if (! config('gameplay.request_budget_enabled')) {
            return [];
        }
        try {
            $causes = app(CampaignHealth::class)->drainPressure($primary);
        } catch (RequestDeferred) {
            return [$this->blocker('drain_pressure', 'Request budget unavailable. Wait for current budget telemetry, then reopen this review.')];
        }
        $labels = [
            'host_operating_pressure' => 'Host Tier 2–4 request rate is still high.',
            'host_total_pressure' => 'Total host request rate is still high.',
            'host_rate_limits' => 'SpaceTraders is still rate-limiting requests.',
            'control_degradation' => 'The control campaign is still slowing while requests wait for budget.',
            'shared_system_cannibalization' => 'The two campaigns are still competing in the control system.',
        ];

        return array_map(fn ($cause) => $this->blocker('drain_pressure', $labels[$cause]), $causes);
    }

    /** A standalone hold can cover any unsettled work of the current agent. */
    public function standaloneDrainBlockers(Agent $agent): array
    {
        $settling = $this->settlingRun($this->runs($agent), $agent, true);

        return [...($settling ? [$settling] : []), ...$this->drainSettlement($agent)];
    }

    private function drainSettlement(Agent $agent, ?CampaignGrant $grant = null, ?CampaignEpisode $episode = null, ?array $recovery = null): array
    {
        $incomplete = ! $grant || BridgeDrain::incomplete($grant, $episode);
        $runs = $this->runs($agent, $grant, $episode);
        $recovery ??= $this->recoveryFleet($agent, $grant, $episode);
        $symbols = array_keys($recovery['ships']);
        $pending = $episode ? app(CampaignFleet::class)->pending($episode, $symbols) : $this->pending($agent);
        if ($grant && ! $episode) {
            $pending->where(fn ($query) => $query->whereIn('ship_symbol', $symbols)
                ->orWhereIn('automation_run_id', (clone $runs)->select('id')));
        }
        $preview = $incomplete && ($episode || ! $grant)
            ? $runs->where('state', 'preview')->when(! $grant, fn ($query) => $query->where(fn ($query) => $query
                ->whereNull('campaign_episode_id')->orWhereIn('campaign_episode_id', CampaignEpisode::where('agent_id', $agent->id)
                ->where('reset_id', $agent->reset_id)->whereNull('closed_at')->select('id'))))->oldest('id')->first(['id', 'ship_symbol'])
            : null;

        return $this->settlementBlockers($agent, $pending->oldest('id')->first(['id', 'ship_symbol']), $preview, $incomplete ? $recovery : null);
    }

    private function settlementBlockers(Agent $agent, ?ShipAction $command, ?AutomationRun $preview, ?array $recovery): array
    {
        $blockers = [];
        if ($command) {
            $blockers[] = $this->blocker('settling', 'Wait for command #'.$command->id.' to settle.', $agent);
        }
        if ($preview) {
            $blockers[] = $this->blocker('settling', 'Preview #'.$preview->id.' for '.$preview->ship_symbol.' still needs to settle.', $agent);
        }
        if ($recovery !== null && ! $recovery['commandIdentified']) {
            $blockers[] = $this->blocker('settling', 'A local cargo snapshot for the command ship is needed to confirm its hold is empty.', $agent);
        }
        foreach ($recovery['ships'] ?? [] as $symbol => $ship) {
            $units = $ship['cargo']['units'] ?? null;
            if (! is_numeric($units) || (float) $units !== 0.0) {
                $message = ! is_numeric($units) || (float) $units < 0.0
                    ? 'Confirm the cargo aboard '.$symbol.'.'
                    : $symbol.' still has '.number_format($units).' units of cargo aboard.';
                $blockers[] = $this->blocker('settling', $message, $agent);
            }
        }

        return $blockers;
    }

    private function recoveryFleet(Agent $agent, ?CampaignGrant $grant, ?CampaignEpisode $episode): array
    {
        if ($grant && $episode) {
            return app(CampaignFleet::class)->recoveryFleet($grant, $episode, $agent, draining: true, pinRoles: false);
        }
        $ships = Ship::where('agent_id', $agent->id)->pluck('snapshot', 'symbol')->all();
        if ($symbol = $grant?->arm()['ship'] ?? null) {
            return ['ships' => [$symbol => $ships[$symbol] ?? null], 'commandIdentified' => true];
        }

        return [
            'ships' => $ships,
            'commandIdentified' => array_any($ships, fn ($ship) => ($ship['registration']['role'] ?? null) === 'COMMAND'),
        ];
    }

    private function settlingRun(Builder $runs, Agent $agent, bool $draining): ?array
    {
        $run = (clone $runs)->whereNotNull('active_agent_id')
            ->when(! $draining, fn ($query) => $query->whereNotNull('stop_requested_at'))->oldest('id')->first();
        if (! $run) {
            return null;
        }
        $actions = BridgeWork::unsettled($run) ? ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->where(fn ($query) => $query->where('automation_run_id', $run->id)->orWhere('ship_symbol', $run->ship_symbol))
            ->where(fn ($query) => $query->where('state', 'uncertain')->orWhere('review_required', true))
            ->get(['state', 'review_required']) : collect();

        return $this->runBlocker($run, $agent, $actions);
    }

    private function runBlocker(AutomationRun $run, Agent $agent, Collection $actions): array
    {
        $reason = match (true) {
            $actions->contains('state', 'uncertain') => 'command outcome uncertain',
            $actions->contains('review_required', true) => 'price review required',
            default => $run->blocked_reason,
        };
        $detail = $reason ? ' '.$reason : '';

        return $this->blocker('settling', 'Run #'.$run->id.' for '.$run->ship_symbol.' is still unsettled.'.$detail, $agent);
    }

    private function runs(Agent $agent, ?CampaignGrant $grant = null, ?CampaignEpisode $episode = null): Builder
    {
        return AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
            ->when($episode, fn ($query) => $query->where('campaign_episode_id', $episode->id))
            ->when($grant && ! $episode, fn ($query) => $query->whereIn('campaign_episode_id', CampaignEpisode::where('campaign_grant_id', $grant->id)
                ->where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->select('id')));
    }

    private function pending(Agent $agent): Builder
    {
        return ShipAction::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->whereIn('state', ['pending', 'sending']);
    }

    private function blocker(string $code, string $message, ?Agent $agent = null): array
    {
        return ['code' => $code, 'message' => $message] + ($agent ? ['href' => route('agents.show', $agent, absolute: false)] : []);
    }
}
