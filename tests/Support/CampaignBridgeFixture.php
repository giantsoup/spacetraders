<?php

namespace Tests\Support;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignPlaybook;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignSample;
use App\Models\GameObservation;
use App\Models\LocalEngine;
use App\Models\Ship;
use App\Models\User;

class CampaignBridgeFixture
{
    public const SOURCE = 'X1-SK70-KX3';

    public const BUYER = 'X1-SK70-D46';

    public static function seed(User $operator, ?string $reset = null, string $playbook = 'weekly-default.yaml'): array
    {
        $main = self::primary($operator, 'GIANTSOUP', $reset, $playbook);
        $other = self::primary($operator, 'SOUPFLEET', $reset);
        $other['primary']->update(['paused_at' => now()->subMinutes(8), 'pause_reason' => 'drain_pause:host_rate_limits']);

        $first = self::arm($operator, $main['episode'], $main['agent'], 9, 'consecutive_losing_loads');
        $second = self::arm($operator, $main['episode'], $main['agent'], 10, 'director_error');
        $third = self::arm($operator, $main['episode'], $main['agent'], 11);
        self::arm($operator, $other['episode'], $other['agent'], 3);
        self::run($main['episode'], $main['command'], 'ICE_WATER');
        self::run($third['episode'], $third['ship'], 'ICE_WATER');
        $stopped = self::run($first['episode'], $first['ship'], 'FUEL', 'stopped');
        self::run($second['episode'], $second['ship'], 'FUEL', 'stopped');

        $probe = self::ship($main['agent'], 6, 'PROBE', 'FRAME_PROBE');
        $probe->update(['stationed_waypoint' => self::SOURCE]);
        self::ship($main['agent'], 7, 'PROBE', 'FRAME_PROBE');
        $idle = self::ship($main['agent'], 8, 'HAULER', 'FRAME_LIGHT_FREIGHTER');
        $idle->update(['nickname' => 'The Long Way Home']);

        foreach ([self::SOURCE => 'ASTEROID', self::BUYER => 'ORBITAL_STATION'] as $symbol => $type) {
            GameObservation::create([
                'agent_id' => $main['agent']->id, 'reset_id' => $main['agent']->reset_id, 'kind' => 'waypoint',
                'symbol' => $symbol, 'observed_at' => now(),
                'data' => ['symbol' => $symbol, 'systemSymbol' => 'X1-SK70', 'type' => $type, 'traits' => [['symbol' => 'MARKETPLACE']]],
            ]);
            GameObservation::create([
                'agent_id' => $main['agent']->id, 'reset_id' => $main['agent']->reset_id, 'kind' => 'market',
                'symbol' => $symbol, 'observed_at' => now()->subMinutes(3),
                'data' => ['tradeGoods' => [['symbol' => 'ICE_WATER', 'purchasePrice' => 35, 'sellPrice' => 64]]],
            ]);
        }

        $control = app(CampaignControl::class);
        foreach ([1 => -7200, 2 => -11400] as $index => $net) {
            $control->event($first['grant'], $first['episode'], 'load_result', [
                'run_id' => $stopped->id, 'good' => 'FUEL', 'buyer' => self::BUYER, 'ship' => $first['ship']->symbol,
                'net' => $net, 'units' => 80, 'losing' => true, 'finished_at' => now()->subMinutes($index * 16)->toIso8601String(),
            ]);
        }
        $control->event($second['grant'], $second['episode'], 'director_error', ['message' => 'No usable route remained.', 'consecutive' => 5]);

        for ($index = 0; $index < 65; $index++) {
            $active = $index % 5 !== 0;
            $control->event($main['primary'], $main['episode'], 'load_result', [
                'good' => $active ? 'ICE_WATER' : 'IRON_ORE', 'source' => self::SOURCE, 'buyer' => self::BUYER,
                'ship' => $main['command']->symbol, 'units' => 90 + $index % 19, 'net' => 8600 + $index * 135,
                'finished_at' => now()->subMinutes($index * 8 + 12)->toIso8601String(),
            ]);
        }
        for ($index = 0; $index < 120; $index++) {
            $minutes = 15 * (119 - $index) + ($index < 48 ? 120 : 0);
            CampaignSample::create([
                'campaign_episode_id' => $main['episode']->id, 'phase' => 'earn', 'credits' => 625000 + $index * 34000 + ($index % 7) * 3800,
                'ships' => [], 'request_budget' => [], 'observed_at' => now()->subMinutes($minutes),
            ]);
        }
        LocalEngine::updateOrCreate(['id' => 1], ['state' => 'running', 'heartbeat_at' => now()]);

        return $main + [
            'other' => $other, 'arm' => $first, 'retire' => $second, 'trading_arm' => $third, 'probe' => $probe,
        ];
    }

    public static function addLargeHistory(CampaignEpisode $episode): void
    {
        $stamp = now()->subMinutes(2)->toDateTimeString();
        $payload = json_encode([
            'good' => 'ICE_WATER', 'source' => CampaignBridgeFixture::SOURCE, 'buyer' => CampaignBridgeFixture::BUYER,
            'ship' => 'GIANTSOUP-1', 'units' => 80, 'net' => 1200, 'finished_at' => now()->subMinutes(2)->utc()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);
        $event = [
            'campaign_grant_id' => $episode->campaign_grant_id, 'campaign_episode_id' => $episode->id,
            'kind' => 'load_result', 'data' => $payload, 'created_at' => $stamp, 'updated_at' => $stamp,
        ];
        foreach ([3000 => $event, 17000 => array_replace($event, ['kind' => 'decision', 'data' => '{}'])] as $count => $record) {
            for ($offset = 0; $offset < $count; $offset += 100) {
                CampaignEvent::insert(array_fill(0, min(100, $count - $offset), $record));
            }
        }
        foreach (array_chunk(range(1, 700), 100) as $chunk) {
            CampaignSample::insert(array_map(fn ($index) => [
                'campaign_episode_id' => $episode->id, 'phase' => 'earn', 'credits' => 1000000 + $index * 3500,
                'ships' => '[]', 'request_budget' => '[]', 'observed_at' => now()->subMinutes(4 * (700 - $index))->toDateTimeString(),
            ], $chunk));
        }
    }

    public static function primary(User $operator, string $symbol, ?string $reset = null, string $playbook = 'weekly-default.yaml'): array
    {
        $revision = app(CampaignPlaybook::class)->import(str_replace('GIANTSOUP', $symbol, file_get_contents(base_path('campaigns/'.$playbook))));
        $primary = app(CampaignControl::class)->approve($revision, $operator, now()->toImmutable()->addDays(5));
        $primary->update(['last_directed_at' => now()->subMinutes(2)]);
        $reset ??= now()->subDays(2)->toDateString();
        $agent = $operator->agents()->create([
            'name' => $symbol, 'token' => 'synthetic-fixture-only', 'faction' => 'VOID', 'synced_at' => now()->subMinutes(2),
            'reset_id' => $reset, 'canonical_identity' => AgentSynchronizer::identity($reset, $symbol), 'credits' => $symbol === 'GIANTSOUP' ? 4860000 : 2175000,
        ]);
        $command = self::ship($agent, 1, 'COMMAND', 'FRAME_FRIGATE');
        $episode = CampaignEpisode::create([
            'campaign_grant_id' => $primary->id, 'agent_id' => $agent->id, 'reset_id' => $agent->reset_id,
            'phase' => 'earn', 'start_credits' => 625000, 'opened_at' => now()->subHours(38), 'next_reset_at' => now()->addHours(126),
            'state' => ['role_ships' => ['command' => $command->symbol], 'first_seen' => ['ICE_WATER@'.self::BUYER => 71]],
        ]);

        return compact('primary', 'agent', 'command', 'episode');
    }

    public static function arm(User $operator, CampaignEpisode $primaryEpisode, Agent $agent, int $number, ?string $pause = null): array
    {
        $ship = self::ship($agent, $number, 'HAULER', 'FRAME_LIGHT_FREIGHTER');
        $grant = app(CampaignControl::class)->approveArm($primaryEpisode->grant()->revision(), $operator, [
            'label' => 'R'.$number, 'ship' => $ship->symbol, 'max_net_loss' => 50000,
        ], now()->toImmutable()->addHours(12));
        $episode = CampaignEpisode::create([
            'campaign_grant_id' => $grant->id, 'agent_id' => $agent->id, 'reset_id' => $agent->reset_id,
            'phase' => 'earn', 'start_credits' => $agent->credits, 'opened_at' => now()->subHours(12), 'next_reset_at' => $primaryEpisode->next_reset_at,
        ]);
        if ($pause !== null) {
            app(CampaignControl::class)->pause($grant, $pause, $episode);
        }

        return compact('ship', 'grant', 'episode');
    }

    private static function ship(Agent $agent, int $number, string $role, string $frame): Ship
    {
        return Ship::create(['agent_id' => $agent->id, 'symbol' => $agent->name.'-'.$number, 'observed_at' => now()->subMinutes(2), 'snapshot' => [
            'symbol' => $agent->name.'-'.$number,
            'registration' => ['role' => $role], 'frame' => ['symbol' => $frame],
            'nav' => ['systemSymbol' => 'X1-SK70', 'waypointSymbol' => self::SOURCE, 'status' => 'DOCKED'],
            'fuel' => ['current' => 85, 'capacity' => 100], 'cargo' => ['units' => 0, 'capacity' => 120, 'inventory' => []],
        ]]);
    }

    private static function run(CampaignEpisode $episode, Ship $ship, string $good, string $state = 'running'): AutomationRun
    {
        return AutomationRun::create([
            'agent_id' => $episode->agent_id, 'active_agent_id' => $state === 'running' ? $episode->agent_id : null, 'campaign_episode_id' => $episode->id,
            'reset_id' => $episode->reset_id, 'ship_symbol' => $ship->symbol, 'strategy' => 'trade', 'state' => $state,
            'started_at' => now()->subMinutes(6), 'expires_at' => now()->addMinutes(20),
            'plan' => ['symbol' => $good, 'site' => self::SOURCE, 'market' => self::BUYER, 'units' => 90],
            'totals' => ['phase' => 'buy'],
        ]);
    }
}
