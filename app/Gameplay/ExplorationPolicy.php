<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\GameObservation;
use Illuminate\Support\Facades\Validator;

class ExplorationPolicy
{
    public const QUESTIONS = [
        'MARKETPLACE' => 'Does this waypoint have a marketplace? If present, fetch its goods directory before considering a buyer or fuel visit; otherwise exclude it from market scouting.',
        'SHIPYARD' => 'Does this waypoint have a shipyard? If present, inspect its offers before considering setup; otherwise exclude it from shipyard scouting.',
    ];

    public static function command(string $command): bool
    {
        return in_array($command, ['scan_waypoints', 'chart'], true);
    }

    public static function traitsKnown(array $point): bool
    {
        return array_key_exists('traits', $point) && (! in_array('UNCHARTED', array_column($point['traits'], 'symbol'), true) || ($point['traits_observed'] ?? false));
    }

    public function inspect(Agent $agent, array $ship, string $command, array $arguments, int $reserve): array
    {
        if (Validator::make($arguments, [
            'waypointSymbol' => 'required|string|max:80|regex:/^[A-Z0-9]+-[A-Z0-9]+-[A-Z0-9]+$/D',
            'question' => 'required|in:MARKETPLACE,SHIPYARD', 'maxCooldownSeconds' => 'required|integer|between:0,900',
        ])->fails()) {
            throw new GameException('Choose an unresolved trait question, target and cooldown review threshold.');
        }
        if ($ship['nav']['status'] !== 'IN_ORBIT' || now()->lt($ship['cooldown']['expiration'])) {
            throw new GameException('Exploration requires orbit and an expired cooldown under this client policy.');
        }
        if ($command === 'scan_waypoints' && ! collect($ship['mounts'])->contains(fn ($m) => in_array($m['symbol'], ['MOUNT_SENSOR_ARRAY_I', 'MOUNT_SENSOR_ARRAY_II', 'MOUNT_SENSOR_ARRAY_III'], true))) {
            throw new GameException('Waypoint scanning requires a Sensor Array mount.');
        }
        $parts = array_merge($ship['mounts'], $ship['modules'], [$ship['frame'], $ship['reactor'], $ship['engine']]);
        if (count($ship['mounts']) > $ship['frame']['mountingPoints'] || array_sum(array_column(array_column($parts, 'requirements'), 'power')) > $ship['reactor']['powerOutput'] || max($ship['crew']['required'], array_sum(array_column(array_column($parts, 'requirements'), 'crew'))) > $ship['crew']['current']) {
            throw new GameException('Exploration equipment exceeds available mounting points, crew or power.');
        }
        $saved = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('kind', 'waypoint')->where('symbol', $arguments['waypointSymbol'])->first();
        if (! $saved || $saved->data['systemSymbol'] !== $ship['nav']['systemSymbol']) {
            throw new GameException('Choose a known candidate in this ship\'s current system. Catalogue coverage may be partial.');
        }
        if ($command === 'chart' && $saved->symbol !== $ship['nav']['waypointSymbol']) {
            throw new GameException('Charting requires the current waypoint. Travel needs a separate preview and approval.');
        }
        $point = app(SpaceTradersApi::class)->waypoint($agent, $saved->symbol);
        app(AgentSynchronizer::class)->observe($agent, 'waypoint', $saved->symbol, $point);
        $point = $saved->fresh()->data;
        if (self::traitsKnown($point)) {
            throw new GameException('Traits are already observed. This question no longer justifies a scan or chart.');
        }
        $continuation = app(FuelPlanner::class)->leg($agent, $ship, $ship['nav']['waypointSymbol'], $reserve, policy: 'conservative');

        return ['question' => self::QUESTIONS[$arguments['question']], 'target' => $saved->symbol,
            'api_limit' => 'At most 4 reads per preview, approval and execution, plus 1 POST. No automatic retry or follow-up.',
            'travel_fuel' => 0, 'spend' => 0, 'cooldowns' => $command === 'scan_waypoints' ? 1 : 0,
            'cooldown_seconds' => null, 'cooldown_review_threshold' => (int) $arguments['maxCooldownSeconds'],
            'cooldown_policy' => 'Scan duration and range are unknown until the response. The threshold pauses for review; it cannot cap the server cooldown. No worker waits through a cooldown.',
            'continuation' => $continuation, 'benefit' => 'Unknown; displaced earnings are unmeasured.',
            'stop' => 'One action only. Stop after the response, missing target, failure, uncertainty or reset. Reassess observations before any new approval.'];
    }
}
