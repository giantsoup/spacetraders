<?php

namespace Tests\Support;

use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignPlaybook;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignSample;
use App\Models\LocalEngine;
use App\Models\Ship;
use App\Models\User;

class CampaignStatusFixture
{
    public static function seed(User $user): array
    {
        $revision = app(CampaignPlaybook::class)->import(file_get_contents(base_path('campaigns/weekly-default.yaml')));
        $grant = app(CampaignControl::class)->approve($revision, $user);
        $grant->update(['last_directed_at' => now()]);
        $agent = $user->agents()->create(['name' => 'OFFLINE', 'token' => 'synthetic-only', 'faction' => 'VOID', 'reset_id' => 'offline-demo', 'credits' => 115000]);
        $episode = CampaignEpisode::create(['campaign_grant_id' => $grant->id, 'agent_id' => $agent->id, 'reset_id' => 'offline-demo',
            'phase' => 'earn', 'start_credits' => 100000, 'opened_at' => now()->subHours(2), 'next_reset_at' => now()->addDay()]);
        $ship = Ship::create(['agent_id' => $agent->id, 'symbol' => 'OFFLINE-1', 'observed_at' => now(), 'snapshot' => [
            'registration' => ['role' => 'COMMAND'], 'nav' => ['waypointSymbol' => 'X1-DEMO-SOURCE', 'status' => 'DOCKED'],
            'fuel' => ['current' => 90, 'capacity' => 100], 'cargo' => ['units' => 20, 'capacity' => 40],
        ]]);
        $run = AutomationRun::create(['agent_id' => $agent->id, 'active_agent_id' => $agent->id, 'campaign_episode_id' => $episode->id,
            'reset_id' => $agent->reset_id, 'ship_symbol' => $ship->symbol, 'strategy' => 'trade', 'state' => 'running',
            'started_at' => now()->subMinutes(5), 'expires_at' => now()->addMinutes(20),
            'plan' => ['symbol' => 'IRON_ORE', 'site' => 'X1-DEMO-SOURCE', 'market' => 'X1-DEMO-BUYER', 'units' => 20], 'totals' => ['phase' => 'buy']]);
        $control = app(CampaignControl::class);
        $control->event($grant, $episode, 'decision', ['choice' => 'trade', 'run_id' => $run->id, 'candidates' => [
            ['rejected' => ['quote_stale', 'quote_stale']], ['rejected' => ['no_route']],
        ]]);
        $control->event($grant, $episode, 'load_result', ['run_id' => $run->id, 'good' => 'IRON_ORE', 'buyer' => 'X1-DEMO-BUYER',
            'proof' => true, 'predicted_margin' => 2000, 'net' => 1800, 'losing' => false, 'finished_at' => now()->toIso8601String()]);
        $control->event($grant, $episode, 'phase', ['from' => 'discover', 'to' => 'earn']);
        foreach (range(0, 25) as $index) {
            CampaignSample::create(['campaign_episode_id' => $episode->id, 'phase' => 'earn', 'credits' => 100000 + $index * 600,
                'ships' => [], 'request_budget' => [], 'observed_at' => now()->subMinutes((25 - $index) * 4)]);
        }
        LocalEngine::updateOrCreate(['id' => 1], ['state' => 'running', 'heartbeat_at' => now()]);

        return compact('grant', 'agent', 'episode', 'run', 'ship');
    }
}
