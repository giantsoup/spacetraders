<?php

namespace Tests\Feature;

use App\Enums\RunState;
use App\Gameplay\AutomationRunner;
use App\Gameplay\Bridge\BridgeFleet;
use App\Gameplay\Bridge\BridgeLog;
use App\Gameplay\Bridge\BridgeRoutes;
use App\Gameplay\Bridge\BridgeScope;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\CampaignRevision;
use App\Models\GameObservation;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BridgeReadModelsTest extends TestCase
{
    use RefreshDatabase;

    private const PLAN = ['symbol' => 'IRON_ORE', 'site' => 'X1-TEST-A', 'market' => 'X1-TEST-B'];

    private const RESET = '2026-11-16';

    private User $user;

    private Agent $agent;

    private CampaignGrant $primary;

    private CampaignEpisode $episode;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(now()->parse('2026-11-19T14:30:00+00:00'));
        $this->user = User::factory()->create();
        $this->primary = $this->grant($this->user, 'BRIDGE', 1);
        $this->agent = $this->agent($this->user, 'BRIDGE', self::RESET);
        $this->episode = $this->episode($this->primary, $this->agent);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_route_totals_share_legacy_keys_and_use_utc_cutoff(): void
    {
        $arm = $this->grant($this->user, 'BRIDGE', null, 'BRIDGE-2');
        $armEpisode = $this->episode($arm, $this->agent, ['first_seen' => ['IRON_ORE@X1-TEST-B' => 420]]);
        $retired = $this->grant($this->user, 'BRIDGE', null, 'BRIDGE-3');
        $retiredEpisode = $this->episode($retired, $this->agent);
        $retired->update(['revoked_at' => now()]);
        $retiredEpisode->update(['closed_at' => now()]);
        $foreignAgent = $this->agent($this->user, 'ALTERNATE', self::RESET);
        $foreignEpisode = $this->episode($this->grant($this->user, 'ALTERNATE', 1), $foreignAgent);

        $run = $this->tradeRun($armEpisode, 'BRIDGE-2');
        $this->episode->update(['state' => ['first_seen' => ['IRON_ORE@X1-TEST-B' => 100]]]);
        $this->load($armEpisode, 20, '2026-11-19T12:29:59+00:00', ['run_id' => $run->id, 'source' => null]);
        $this->load($this->episode, 40, '2026-11-19T12:30:00+00:00');
        $this->load($armEpisode, -40, '2026-11-19T12:30:00+00:00');
        $this->load($this->episode, 3, '2026-11-19T12:30:00+00:00', ['source' => null]);
        $this->load($retiredEpisode, 100, '2026-11-19T10:00:00+00:00', ['source' => 'X1-TEST-C']);
        $this->load($foreignEpisode, 500, '2026-11-19T13:00:00+00:00');
        $this->load($armEpisode, 900, '2026-11-19T13:00:00+00:00', kind: 'recovery_result');

        foreach (['X1-TEST-A' => self::RESET, 'X1-TEST-B' => '2026-11-09'] as $symbol => $reset) {
            GameObservation::create(['agent_id' => $this->agent->id, 'reset_id' => $reset, 'observed_at' => now(),
                'kind' => 'market', 'symbol' => $symbol,
                'data' => ['tradeGoods' => [['symbol' => 'IRON_ORE', 'purchasePrice' => 200, 'sellPrice' => 850]]]]);
        }

        $scope = $this->scope();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $routes = BridgeRoutes::read($scope);
        $this->assertCount(1, DB::getQueryLog());
        $detail = BridgeRoutes::detail($scope, 'IRON_ORE', 'X1-TEST-A', 'X1-TEST-B');
        $this->assertCount(3, DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1.5, $routes['rate']);
        $this->assertSame(['count' => 2, 'net' => 103], $routes['ended']);
        $row = $routes['running'][0];
        $this->assertSame('IRON_ORE|X1-TEST-A|X1-TEST-B', $row['key']);
        $this->assertSame('R2', $row['ships'][0]['owner']);
        $this->assertSame([3, 20, 0, -40], [$row['loads'], $row['net'], $row['rate'], $row['last_net']]);
        $this->assertSame([100, 3], array_column(BridgeRoutes::ended($scope), 'net'));
        $this->assertNull(BridgeRoutes::ended($scope)[1]['key']);

        $this->assertSame(420, $detail['first_seen']);
        $this->assertSame(200, $detail['prices']['source']['price']);
        $this->assertNull($detail['prices']['buyer']);
        $this->assertSame([-40, 40, 20], array_column($detail['recent'], 'net'));
        $armEpisode->update(['state' => []]);
        $this->assertSame(100, BridgeRoutes::detail($this->scope(), 'IRON_ORE', 'X1-TEST-A', 'X1-TEST-B')['first_seen']);
    }

    public function test_stop_with_price_review_keeps_the_route_and_ship_unsettled_until_slot_releases(): void
    {
        $ship = $this->ship('BRIDGE-1', 'COMMAND');
        $run = $this->tradeRun($this->episode, $ship->symbol);
        $runner = app(AutomationRunner::class);
        $runner->stop($run);
        $stop = $run->fresh();

        $scope = $this->scope();
        $this->assertSame('Stopping · settling commands', BridgeRoutes::read($scope)['settling'][0]['status']);
        $this->assertSame('Stopping · settling commands', BridgeFleet::read($scope)['groups'][0]['ships'][0]['status_text']);

        ShipAction::create(['agent_id' => $this->agent->id, 'automation_run_id' => $run->id, 'key' => 'price-review',
            'reset_id' => self::RESET, 'ship_symbol' => $ship->symbol, 'origin' => 'automation', 'command' => 'sell',
            'state' => 'confirmed', 'review_required' => true, 'arguments' => [], 'expires_at' => now()->addHour()]);
        $this->travel(1)->second();
        $runner->advance($run->id);

        $this->assertSame(RunState::Stopping, $run->refresh()->state);
        $this->assertEquals($stop->stop_requested_at, $run->stop_requested_at);
        $this->assertEquals($stop->next_action_at, $run->next_action_at);
        $this->assertSame($this->agent->id, $run->active_agent_id);
        $scope = $this->scope();
        $this->assertSame([], BridgeRoutes::read($scope)['running']);
        $this->assertSame('Unsettled · price review required', BridgeRoutes::read($scope)['settling'][0]['status']);
        $this->assertSame('Unsettled · price review required', BridgeFleet::read($scope)['groups'][0]['ships'][0]['status_text']);

        $this->primary->update(['paused_at' => now()]);
        $run->update(['state' => 'stopped', 'active_agent_id' => null]);
        $latest = $this->tradeRun($this->episode, $ship->symbol, ['market' => 'X1-TEST-NEW'] + self::PLAN, active: false);
        $this->assertGreaterThan($run->id, $latest->id);
        $routes = BridgeRoutes::read($this->scope());
        $this->assertSame([], $routes['settling']);
        $this->assertSame(['X1-TEST-NEW'], array_column($routes['stopped'], 'buyer'));
    }

    public function test_a_running_route_keeps_distinct_ships_including_its_paused_ship(): void
    {
        $command = $this->ship('BRIDGE-1', 'COMMAND');
        $paused = $this->grant($this->user, 'BRIDGE', null, 'BRIDGE-2');
        $working = $this->grant($this->user, 'BRIDGE', null, 'BRIDGE-3');
        $paused->update(['paused_at' => now()]);
        $this->grant($this->user, 'BRIDGE', null, 'BRIDGE-4')->update(['paused_at' => now()]);
        $pausedEpisode = $this->episode($paused, $this->agent, ['claim' => ['good' => 'OTHER', 'source' => 'X1-TEST-C', 'buyer' => 'X1-TEST-D']]);
        $workingEpisode = $this->episode($working, $this->agent);
        $this->tradeRun($pausedEpisode, 'BRIDGE-2', active: false);
        $this->tradeRun($workingEpisode, 'BRIDGE-3');
        $this->tradeRun($this->episode, $command->symbol)->update(['state' => 'waiting']);

        $routes = BridgeRoutes::read($this->scope());
        $this->assertSame([], $routes['settling']);
        $this->assertSame([], $routes['stopped']);
        $this->assertCount(1, $routes['running']);
        $this->assertSame(['BRIDGE-1' => 'Waiting', 'BRIDGE-2' => 'Paused', 'BRIDGE-3' => 'Running'],
            collect($routes['running'][0]['ships'])->pluck('status', 'symbol')->sortKeys()->all());
    }

    public function test_mining_requires_a_revision_activity_and_an_open_campaign_episode(): void
    {
        $this->ship('BRIDGE-1', 'COMMAND');
        $this->ship('BRIDGE-2', 'EXCAVATOR');
        $this->tradeRun($this->episode, 'BRIDGE-1', ['site' => 'X1-TEST-A', 'market' => 'X1-TEST-B'], strategy: 'mining');
        $this->tradeRun($this->episode, 'BRIDGE-2', strategy: 'mining')->update(['campaign_episode_id' => null]);
        $scope = $this->scope();
        $this->assertSame([], BridgeRoutes::read($scope)['mining']);
        $this->assertSame(['Other work'], array_column(BridgeFleet::read($scope)['groups'], 'name'));

        $revision = $this->primary->revision();
        $revision->body = ['phases' => [['activities' => [['strategy' => 'mining']]]]];
        $revision->saveQuietly();

        $scope = $this->scope();
        $mining = BridgeRoutes::read($scope)['mining'];
        $this->assertCount(1, $mining);
        $this->assertSame('BRIDGE-1', $mining[0]['ships'][0]['symbol']);
        $this->assertNull($mining[0]['key']);
        $this->assertSame(['Other work', 'Mining'], array_column(BridgeFleet::read($scope)['groups'], 'name'));
    }

    public function test_fleet_compacts_probes_and_projects_ship_scene_and_results(): void
    {
        $command = $this->ship('BRIDGE-1', 'COMMAND', ['nickname' => 'Pathfinder']);
        $arm = $this->grant($this->user, 'BRIDGE', null, 'BRIDGE-2');
        $arm->update(['paused_at' => now()]);
        $this->ship('BRIDGE-2', 'HAULER');
        $probe = $this->ship('BRIDGE-3', 'SATELLITE', ['stationed_waypoint' => 'X1-TEST-A']);
        $this->ship('BRIDGE-4', 'SATELLITE');
        $idleShip = $this->ship('BRIDGE-5', 'HAULER');
        $this->ship('BRIDGE-6', 'SATELLITE');
        $this->tradeRun($this->episode, $idleShip->symbol, active: false);
        $this->tradeRun($this->episode, 'BRIDGE-4', strategy: 'buy_probe');
        $this->tradeRun($this->episode, $command->symbol);
        $this->episode->update(['state' => ['role_ships' => ['command' => $command->symbol, 'probe' => 'BRIDGE-4']]]);
        $command->update(['snapshot' => [
            'nav' => ['status' => 'IN_TRANSIT', 'waypointSymbol' => 'X1-TEST-A', 'systemSymbol' => 'X1-TEST',
                'route' => ['departureTime' => now()->subMinute()->toIso8601String(), 'arrival' => now()->addMinute()->toIso8601String(),
                    'destination' => ['symbol' => 'X1-TEST-B', 'type' => 'PLANET']]],
            'cargo' => ['units' => 10, 'capacity' => 20], 'fuel' => ['current' => 10, 'capacity' => 20],
        ] + $command->snapshot]);
        foreach (['X1-TEST-A' => 'JUMP_GATE', 'X1-TEST-B' => 'PLANET'] as $symbol => $type) {
            GameObservation::create(['agent_id' => $this->agent->id, 'reset_id' => self::RESET, 'observed_at' => now(),
                'kind' => 'waypoint', 'symbol' => $symbol, 'data' => ['type' => $type]]);
        }
        $this->load($this->episode, 60, '2026-11-19T13:30:00+00:00', ['ship' => $command->symbol, 'units' => 10]);
        $this->load($this->episode, 90, '2026-11-19T14:30:00+00:00', ['ship' => $command->symbol], kind: 'recovery_result');
        $scope = $this->scope();
        $fleet = BridgeFleet::read($scope);

        $this->assertSame(6, $fleet['count']);
        $this->assertSame(6, array_sum(array_column($fleet['groups'], 'count')));
        $this->assertSame(['Stopped', 'Idle', 'Trading', 'Probes'], array_column($fleet['groups'], 'name'));
        $this->assertSame('BRIDGE-2', $fleet['groups'][0]['ships'][0]['symbol']);
        $this->assertNotNull($fleet['groups'][1]['ships'][0]['idle_since']);
        $this->assertSame([], $fleet['groups'][3]['ships']);
        $this->assertSame([['name' => 'Stationed', 'count' => 1], ['name' => 'Other', 'count' => 2]], $fleet['probes']['groups']);
        $probes = BridgeFleet::probes($scope);
        $this->assertSame($probe->symbol, $probes[0]['ships'][0]['symbol']);
        $this->assertStringStartsWith('Gate Watch', $probes[0]['ships'][0]['purpose']);
        $this->assertSame(['Shipyard Buyer', 'Standby probe'], array_column($probes[1]['ships'], 'status_text'));

        $detail = BridgeFleet::detail($scope, $command->symbol);
        $this->assertSame('Pathfinder', $detail['name']);
        $this->assertSame('1:00', $detail['right']);
        $this->assertSame(['JUMP_GATE', 'PLANET'], array_column($detail['locations'], 'type'));
        $this->assertSame(['loads' => 1, 'net' => 60, 'last_at' => '2026-11-19T13:30:00+00:00', 'last_net' => 60], $detail['totals']);
        $this->assertSame(['recovery_result', 'load_result'], array_column($detail['recent'], 'kind'));
        $this->assertStringEndsWith('?ship=BRIDGE-1#report', $detail['report_href']);
    }

    public function test_log_paginates_by_id_and_filters_legacy_route_keys(): void
    {
        $run = $this->tradeRun($this->episode, 'BRIDGE-1');
        $first = $this->load($this->episode, -50, '2026-11-19T10:00:00+00:00', ['source' => null, 'run_id' => $run->id]);
        $second = $this->load($this->episode, 40, '2026-11-19T11:00:00+00:00');
        $recovery = $this->load($this->episode, 60, '2026-11-19T12:00:00+00:00', kind: 'recovery_result');
        $other = $this->load($this->episode, 25, '2026-11-19T13:00:00+00:00', ['source' => 'X1-TEST-C']);
        $this->event($this->primary, 'decision', episode: $this->episode);
        $error = $this->event($this->primary, 'director_error', ['message' => 'Raw exception text'], episode: $this->episode);
        $phase = $this->event($this->primary, 'phase', ['from' => 'discover', 'to' => 'earn'], episode: $this->episode);

        $key = 'IRON_ORE|X1-TEST-A|X1-TEST-B';
        $firstPage = BridgeLog::read($this->scope(), 'routes', route: $key, limit: 1);
        $this->assertSame($second->id, $firstPage['next_before']);
        $this->assertSame($second->id, $firstPage['rows'][0]['id']);
        $newest = $this->load($this->episode, 100, '2026-11-19T14:00:00+00:00');
        $secondPage = BridgeLog::read($this->scope(), 'routes', $firstPage['next_before'], $key, 1);
        $this->assertSame([$first->id], array_column($secondPage['rows'], 'id'));
        $this->assertSame($key, $secondPage['route']);
        $this->assertNull($secondPage['next_before']);
        $this->assertSame($key, $secondPage['rows'][0]['route_key']);
        $this->assertStringContainsString('/routes/IRON_ORE/X1-TEST-A/X1-TEST-B?', $secondPage['rows'][0]['link']);
        $this->assertSame('problem', $secondPage['rows'][0]['severity']);

        $problems = BridgeLog::read($this->scope(), 'problems')['rows'];
        $this->assertSame([$error->id, $first->id], array_column($problems, 'id'));
        $this->assertStringNotContainsString('Raw exception text', json_encode($problems));
        $this->assertSame([$phase->id], array_column(BridgeLog::read($this->scope(), 'plan')['rows'], 'id'));
        $this->assertSame([$newest->id, $other->id, $recovery->id, $second->id, $first->id],
            array_column(BridgeLog::read($this->scope(), 'routes')['rows'], 'id'));
        $this->assertSame([$newest->id, $phase->id, $error->id, $other->id, $recovery->id, $second->id, $first->id],
            array_column(BridgeLog::read($this->scope())['rows'], 'id'));
    }

    public function test_log_excludes_archived_wipe_and_resolves_exact_or_ambiguous_start(): void
    {
        $this->episode->delete();
        $this->primary->forceFill(['created_at' => '2026-11-09 16:00:00'])->saveQuietly();
        $this->agent->update(['connection_state' => 'reset', 'reset_id' => '2026-11-09']);
        $approval = $this->event($this->primary, 'approval', at: '2026-11-09 16:00:00');
        $oldTag = $this->event($this->primary, 'registration', ['reset_id' => '2026-11-09'], '2026-11-16 01:00:00');
        $early = $this->event($this->primary, 'pause', at: '2026-11-16 04:00:00');
        $late = $this->event($this->primary, 'resume', at: '2026-11-16 14:00:00');
        $tagged = $this->event($this->primary, 'registration_intent', ['reset_id' => self::RESET], '2026-11-16 13:00:00');
        $this->assertSame([], BridgeLog::read($this->scope())['rows']);

        $newAgent = $this->agent($this->user, 'BRIDGE', self::RESET);
        $this->assertSame([$tagged->id], array_column(BridgeLog::read($this->scope())['rows'], 'id'));
        $prior = $this->episode($this->primary, $this->agent, opened: '2026-11-09 18:00:00', end: '2026-11-16 12:00:00');
        $this->load($prior, 50, '2026-11-16T04:00:00+00:00');
        $this->assertSame([$tagged->id, $late->id], array_column(BridgeLog::read($this->scope())['rows'], 'id'));

        $replacement = $this->grant($this->user, 'BRIDGE', 2);
        $replacementApproval = $this->event($replacement, 'approval', at: '2026-11-17 01:00:00');
        $activation = $this->event($replacement, 'activated', at: '2026-11-17 10:00:00');
        $this->episode($this->primary, $newAgent, opened: '2026-11-16 15:00:00', end: '2026-11-23 12:00:00');
        $end = $this->event($this->primary, 'pause', at: '2026-11-23 12:00:00');
        $log = array_column(BridgeLog::read($this->scope())['rows'], 'id');
        $this->assertSame([$activation->id, $replacementApproval->id, $tagged->id, $late->id], $log);
        foreach ([$approval, $oldTag, $early, $end] as $hidden) {
            $this->assertNotContains($hidden->id, $log);
        }
    }

    public function test_log_keeps_pre_episode_approvals_when_the_wipe_can_account_for_them(): void
    {
        $this->episode->delete();
        $this->primary->forceFill(['created_at' => '2026-11-17 01:00:00'])->saveQuietly();
        $approval = $this->event($this->primary, 'approval', ['revision' => 1], '2026-11-17 01:00:00');
        $registration = $this->event($this->primary, 'registration', ['reset_id' => self::RESET], '2026-11-17 02:00:00');
        $outsideAuthority = $this->grant($this->user, 'BRIDGE', null, 'BRIDGE-2');
        $outsideAuthority->update(['revoked_at' => '2026-11-16 08:00:00']);
        $this->event($outsideAuthority, 'resume', at: '2026-11-17 02:30:00');
        $this->assertSame([$registration->id, $approval->id], array_column(BridgeLog::read($this->scope(), 'plan')['rows'], 'id'));

        $sameDateGrant = $this->grant($this->user, 'BRIDGE', null, 'BRIDGE-3');
        $ambiguous = $this->event($sameDateGrant, 'approval', at: '2026-11-16 01:00:00');
        $this->episode($sameDateGrant, $this->agent);

        $olderGrant = $this->grant($this->user, 'BRIDGE', null, 'BRIDGE-4');
        $original = $this->event($olderGrant, 'approval', at: '2026-11-15 01:00:00');
        $this->episode($olderGrant, $this->agent);

        $log = array_column(BridgeLog::read($this->scope(), 'plan')['rows'], 'id');
        $this->assertSame([$original->id, $registration->id, $approval->id], $log);
        $this->assertNotContains($ambiguous->id, $log);
    }

    private function scope(): BridgeScope
    {
        return BridgeScope::resolve($this->user->id, 'BRIDGE');
    }

    private function grant(User $user, string $symbol, ?int $slot, ?string $ship = null): CampaignGrant
    {
        $revision = CampaignRevision::firstOrCreate(['content_hash' => str_repeat('b', 64)], [
            'name' => 'Bridge fixture', 'source' => 'fixture', 'body' => ['phases' => []],
        ]);

        return CampaignGrant::create(['user_id' => $user->id, 'agent_symbol' => $symbol, 'slot' => $slot,
            'campaign_revision_id' => $revision->id, 'revision_hash' => $revision->content_hash,
            'limits' => $ship ? ['arm' => ['label' => 'R2', 'ship' => $ship]] : []]);
    }

    private function agent(User $user, string $symbol, string $reset): Agent
    {
        return $user->agents()->create(['name' => $symbol, 'token' => 'fixture', 'faction' => 'VOID', 'reset_id' => $reset, 'credits' => 10000]);
    }

    private function episode(CampaignGrant $grant, Agent $agent, array $state = [], string $opened = '2026-11-19 10:00:00', string $end = '2026-11-23 14:00:00'): CampaignEpisode
    {
        return CampaignEpisode::create(['campaign_grant_id' => $grant->id, 'agent_id' => $agent->id, 'reset_id' => $agent->reset_id,
            'phase' => 'earn', 'state' => $state, 'start_credits' => 10000, 'opened_at' => $opened, 'next_reset_at' => $end]);
    }

    private function tradeRun(CampaignEpisode $episode, string $ship, array $plan = self::PLAN, bool $active = true, string $strategy = 'trade'): AutomationRun
    {
        return AutomationRun::create(['agent_id' => $episode->agent_id, 'campaign_episode_id' => $episode->id,
            'active_agent_id' => $active ? $episode->agent_id : null, 'reset_id' => $episode->reset_id, 'ship_symbol' => $ship,
            'strategy' => $strategy, 'plan' => $plan, 'totals' => [], 'state' => $active ? 'running' : 'stopped',
            'started_at' => now()->subHour(), 'expires_at' => now()->addHour()]);
    }

    private function load(CampaignEpisode $episode, int $net, string $at, array $data = [], string $kind = 'load_result'): CampaignEvent
    {
        return $this->event($episode->grant(), $kind, $data + ['good' => 'IRON_ORE', 'source' => 'X1-TEST-A',
            'buyer' => 'X1-TEST-B', 'finished_at' => $at, 'net' => $net], episode: $episode);
    }

    private function event(CampaignGrant $grant, string $kind, array $data = [], ?string $at = null, ?CampaignEpisode $episode = null): CampaignEvent
    {
        return CampaignEvent::create(['campaign_grant_id' => $grant->id, 'campaign_episode_id' => $episode?->id,
            'kind' => $kind, 'data' => $data, 'created_at' => $at ?? now()]);
    }

    private function ship(string $symbol, string $role, array $options = []): Ship
    {
        return Ship::create($options + ['agent_id' => $this->agent->id, 'symbol' => $symbol, 'observed_at' => now(),
            'snapshot' => ['registration' => ['role' => $role], 'cargo' => ['units' => 0, 'capacity' => 20, 'inventory' => []], 'nav' => ['status' => 'DOCKED', 'waypointSymbol' => 'X1-TEST-A']]]);
    }
}
