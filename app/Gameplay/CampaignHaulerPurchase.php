<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CampaignHaulerPurchase
{
    public const TYPE = 'SHIP_LIGHT_HAULER';

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

    public function plan(CampaignGrant $grant, CampaignEpisode $episode, Agent $agent, array $busyShips, array &$considered, float $deadline): ?AutomationRun
    {
        return app(CampaignProbeBootstrap::class)->plan($grant, $episode, $agent, $busyShips, $considered, $deadline, self::TYPE);
    }

    public function compose(Agent $agent, string $symbol, string $yard, array $limits, CampaignEpisode $episode, ?int $ignoreRunId = null): array
    {
        return app(CampaignProbeBootstrap::class)->compose($agent, $symbol, $yard, $limits, $episode, $ignoreRunId, self::TYPE);
    }

    public function next(Agent $agent, array $ship, AutomationRun $run): AutomationStep
    {
        return app(CampaignProbeBootstrap::class)->next($agent, $ship, $run);
    }

    public function acknowledgePacing(ShipAction $action, User $user, bool $dryRun = false, ?CampaignGrant $arm = null, ?callable $revoke = null): array
    {
        return app(HostLock::class)->scoped('agent-'.$action->agent_id, fn () => DB::transaction(function () use ($action, $user, $dryRun, $arm, $revoke) {
            $action->refresh();
            $agent = $user->agents()->unarchived()->find($action->agent_id);
            $ship = CampaignProbeBootstrap::deliveredShip($action);
            if (! $agent || $agent->reset_id !== $action->reset_id || $action->command !== 'buy_ship' || ($action->arguments['shipType'] ?? null) !== self::TYPE
                || isset($action->outcome['confirmed_not_sent']) || ! $ship || ! in_array($action->state->value, ['confirmed', 'acknowledged'], true)
                || ($action->state->value === 'acknowledged' && ! isset($action->outcome['delivery_transaction']))) {
                throw new GameException('Select a current-reset automatic hauler purchase with conclusive delivery evidence.');
            }
            $link = app(CampaignProbeBootstrap::class)->linkage($action);
            if (! $link || $link[0]->plan_version !== 7 || $link[2]->user_id !== $user->id) {
                throw new GameException('Select an automatic hauler purchase with a valid originating C0 grant.');
            }
            [, $episode, $primary] = $link;
            $linked = CampaignGrant::where('user_id', $user->id)->where('agent_symbol', $agent->name)->whereNotNull('limits->arm')
                ->where('limits->arm->purchase_action_id', $action->id)->get()
                ->filter(fn (CampaignGrant $grant) => CampaignDirector::armReset($grant, $agent->reset_id, CampaignDirector::cachedSchedule()) === $agent->reset_id);
            if (($arm && ($linked->count() !== 1 || ! $linked->contains('id', $arm->id))) || (! $arm && $linked->isNotEmpty())) {
                throw new GameException('Pacing review must select the exact linked arm grant or an unarmed delivery.');
            }
            if (isset($action->outcome['pacing_acknowledged'])) {
                return $action->outcome['pacing_acknowledged'];
            }
            if ($arm && (app(CampaignSaturation::class)->firstSale($arm, $agent->reset_id)
                || (! $arm->revoked_at && ! $arm->paused_at && ($action->settled_at ?? $action->created_at)->gt(now()->subMinutes(90))))) {
                throw new GameException('The exact linked arm must have stalled before confirming its first load.');
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
