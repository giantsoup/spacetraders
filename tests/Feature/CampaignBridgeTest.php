<?php

namespace Tests\Feature;

use App\Gameplay\CampaignControl;
use App\Models\AutomationRun;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CampaignBridgeFixture;
use Tests\TestCase;

class CampaignBridgeTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private const BRIEF = '/campaign/bridge';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->freezeSecond();
        $this->operator = User::factory()->create();
        config(['gameplay.operator_id' => $this->operator->id, 'gameplay.request_budget_enabled' => false]);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public static function reads(): array
    {
        $source = CampaignBridgeFixture::SOURCE;
        $buyer = CampaignBridgeFixture::BUYER;

        return [
            'brief' => ['', 'Brief'],
            'routes' => ['/routes', 'Routes'],
            'ended routes' => ['/routes/ended', 'EndedRoutes'],
            'route detail' => ["/routes/ICE_WATER/$source/$buyer", 'Routes'],
            'fleet' => ['/fleet', 'Fleet'],
            'probes' => ['/fleet/probes', 'Probes'],
            'ship detail' => ['/fleet/GIANTSOUP-9', 'Fleet'],
            'log' => ['/log', 'Log'],
        ];
    }

    public static function endpoints(): iterable
    {
        foreach (self::reads() as $name => [$path]) {
            yield $name => ['GET', $path];
        }

        yield 'resume arm' => ['POST', '/arms/123/resume'];
        yield 'retire arm' => ['POST', '/arms/123/retire'];
        yield 'pause fleet' => ['POST', '/fleet/pause'];
        yield 'resume fleet' => ['POST', '/fleet/resume'];
    }

    #[DataProvider('endpoints')]
    public function test_every_endpoint_requires_an_authenticated_operator(string $method, string $path): void
    {
        $this->call($method, self::BRIEF.$path)->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->call($method, self::BRIEF.$path)->assertNotFound();
    }

    #[DataProvider('reads')]
    public function test_saved_links_render_the_authorization_empty_state_without_primaries(string $path, string $component): void
    {
        $this->actingAs($this->operator)->get(self::BRIEF.$path.'?agent=OLD')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Campaign/Bridge/Brief')
            ->where('bridge.agent', null)->where('bridge.engine.state', 'off')
            ->has('bridge.campaignAgents', 0)->where('reviews.fleet_pause', null)
            ->has('log.rows', 0)->has('chart', 0));
    }

    public function test_explicit_agent_selection_and_classic_coexistence(): void
    {
        CampaignBridgeFixture::primary($this->operator, 'GIANTSOUP');
        CampaignBridgeFixture::primary(User::factory()->create(), 'PRIVATE');

        $this->actingAs($this->operator)->get(self::BRIEF)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('bridge.agent.symbol', 'GIANTSOUP')->has('bridge.campaignAgents', 1));
        $this->get(self::BRIEF.'?agent=giantsoup')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('bridge.agent.symbol', 'GIANTSOUP'));
        $this->get(self::BRIEF.'?agent=PRIVATE')->assertNotFound();
        $this->get(self::BRIEF.'/fleet/GIANTSOUP-900')->assertNotFound();
        $this->get('/')->assertRedirect('/agents');
        $this->get('/campaign')->assertInertia(fn (Assert $page) => $page->component('Campaign/Status')
            ->where('selectedAgent', null));
        $this->get('/campaign/strategy?agent=GIANTSOUP&from=bridge')
            ->assertInertia(fn (Assert $page) => $page->component('Campaign/Strategy')->where('fromBridge', true)
                ->where('selectedAgent', 'GIANTSOUP'));
    }

    public function test_cross_agent_drain_badge_is_scoped_and_reads_do_not_harvest(): void
    {
        $fixture = CampaignBridgeFixture::seed($this->operator);
        $before = CampaignEvent::count();

        $this->actingAs($this->operator)->get(self::BRIEF.'?agent=GIANTSOUP')->assertOk()
            ->assertInertia(function (Assert $page) {
                $page->where('bridge.agent.symbol', 'GIANTSOUP')->where('bridge.creditsStrip.credits', 4860000)
                    ->where('bridge.creditsStrip.wipe_delta', 4235000)->has('chart', 2)
                    ->where('plan.budget', 'Request budget off')
                    ->where('bridge.campaignAgents', fn ($agents) => collect($agents)->where('symbol', 'SOUPFLEET')->sole()['needs_you'] === 1)
                    ->where('attention.cards', fn ($cards) => ! collect($cards)->contains('code', 'drain_pause'));
            });
        $this->get(self::BRIEF.'?agent=SOUPFLEET')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('bridge.agent.symbol', 'SOUPFLEET')->where('bridge.creditsStrip.credits', 2175000)
            ->where('attention.cards.0.code', 'drain_pause')->has('chart', 0));
        $this->get(self::BRIEF.'/log?agent=GIANTSOUP&filter=routes')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('log.rows.0.detail', fn ($text) => str_contains($text, 'Ice Water') || str_contains($text, 'Iron Ore')));
        $this->get(self::BRIEF.'/fleet/GIANTSOUP-9?agent=SOUPFLEET')->assertNotFound();
        $this->post(self::BRIEF.'/arms/'.$fixture['arm']['grant']->id.'/resume', [
            'agent' => 'SOUPFLEET', 'reset_id' => $fixture['other']['agent']->reset_id, 'expected' => [],
        ])->assertNotFound();

        $this->assertSame($before, CampaignEvent::count());
    }

    public function test_waiting_for_registration_does_not_show_the_archived_wipe(): void
    {
        $fixture = CampaignBridgeFixture::seed($this->operator);
        $fixture['agent']->update(['connection_state' => 'reset']);

        $this->actingAs($this->operator)->get(self::BRIEF.'?agent=GIANTSOUP')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('bridge.agent.reset_id', null)
                ->where('bridge.creditsStrip.credits', null)->where('bridge.creditsStrip.wipe_delta', null)
                ->has('log.rows', 0)->has('chart', 0)->where('fleet.count', 0));

        $identity = $this->operator->agents()->create([
            'name' => 'GIANTSOUP', 'token' => 'synthetic-new-wipe', 'faction' => 'VOID',
            'reset_id' => now()->toDateString(), 'credits' => 175000,
        ]);
        $this->get(self::BRIEF.'?agent=GIANTSOUP')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('bridge.agent.reset_id', $identity->reset_id)->where('bridge.creditsStrip.credits', 175000)
            ->where('bridge.creditsStrip.wipe_delta', null)->has('chart', 0));
    }

    public function test_reads_are_bounded_with_large_wipe_history(): void
    {
        $fixture = CampaignBridgeFixture::seed($this->operator);
        CampaignBridgeFixture::primary($this->operator, 'SOUPBURN');
        CampaignBridgeFixture::arm($this->operator, $fixture['episode'], $fixture['agent'], 12, 'operator_pause');
        CampaignBridgeFixture::arm($this->operator, $fixture['episode'], $fixture['agent'], 13, 'operator_pause');
        CampaignBridgeFixture::arm($this->operator, $fixture['episode'], $fixture['agent'], 14);
        CampaignBridgeFixture::addLargeHistory($fixture['episode']);

        $this->actingAs($this->operator);
        $version = $this->get(self::BRIEF)->assertOk()->inertiaPage()['version'];
        DB::enableQueryLog();
        try {
            foreach (self::reads() as $label => [$path, $component]) {
                DB::flushQueryLog();
                $response = $this->get(self::BRIEF.$path.'?agent=GIANTSOUP')->assertOk();
                $response->assertInertia(fn (Assert $page) => $page->component('Campaign/Bridge/'.$component));
                $queries = count(DB::getQueryLog());
                $this->assertLessThanOrEqual(40, $queries, "$label used $queries queries.");
                if ($label === 'brief') {
                    $bridge = $response->inertiaPage()['props'];
                    $this->assertLessThan(60000, strlen(json_encode($bridge, JSON_THROW_ON_ERROR)));
                    $this->assertLessThanOrEqual(240, array_sum(array_map('count', $bridge['chart'])));
                }
            }
            DB::flushQueryLog();
            $partial = $this->get(self::BRIEF.'?agent=GIANTSOUP&review=fleet_resume', [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $version,
                'X-Inertia-Partial-Data' => 'reviews',
                'X-Inertia-Partial-Component' => 'Campaign/Bridge/Brief',
            ])->assertOk();
            $partial->assertJsonPath('props.reviews.fleet_resume.intent', 'fleet')
                ->assertJsonMissingPath('props.chart')->assertJsonMissingPath('props.routes');
            $this->assertLessThanOrEqual(40, count(DB::getQueryLog()), 'fleet review query cap');
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_queued_replacement_handover_stays_within_the_query_budget(): void
    {
        $fixture = CampaignBridgeFixture::seed($this->operator, playbook: 'weekly-probe-bootstrap.yaml');
        ['primary' => $primary, 'agent' => $agent, 'episode' => $episode] = $fixture;
        CampaignBridgeFixture::primary($this->operator, 'SOUPBURN');
        CampaignBridgeFixture::arm($this->operator, $episode, $agent, 12, 'operator_pause');
        CampaignBridgeFixture::arm($this->operator, $episode, $agent, 13, 'operator_pause');
        $primary->update(['paused_at' => now(), 'pause_reason' => 'grant_expired']);
        CampaignGrant::create([
            'user_id' => $primary->user_id, 'agent_symbol' => $primary->agent_symbol,
            'campaign_revision_id' => $primary->campaign_revision_id, 'revision_hash' => $primary->revision_hash,
            'limits' => $primary->limits, 'slot' => 2,
        ]);
        $run = AutomationRun::where('campaign_episode_id', $episode->id)->sole();

        $this->actingAs($this->operator);
        $assertQueryBudget = function (string $state) {
            $queries = [];
            DB::enableQueryLog();
            try {
                foreach (self::reads() as $label => [$path, $component]) {
                    DB::flushQueryLog();
                    $response = $this->get(self::BRIEF.$path.'?agent=GIANTSOUP')->assertOk();
                    $queries[$label] = count(DB::getQueryLog());
                    $response->assertInertia(fn (Assert $page) => $page->component('Campaign/Bridge/'.$component));
                }
            } finally {
                DB::disableQueryLog();
            }

            $this->assertLessThanOrEqual(40, max($queries), $state.' '.json_encode($queries, JSON_THROW_ON_ERROR));
        };

        $assertPending = function (?string $reason) use ($primary) {
            $this->assertSame($reason, app(CampaignControl::class)->busyReason($primary, includePause: false)['reason'] ?? null);
            $this->get(self::BRIEF.'?agent=GIANTSOUP')->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('plan.pending', $reason === null
                    ? 'New playbook queued for the next safe point'
                    : 'New playbook queued. Waiting for the main campaign to settle.'));
        };
        $assertQueryBudget('busy');
        $assertPending('role_work');
        $run->update(['active_agent_id' => null, 'state' => 'stopped']);
        $assertQueryBudget('idle');
        $assertPending(null);

        $probe = Ship::where('agent_id', $agent->id)->where('symbol', 'GIANTSOUP-7')->sole();
        $secondProbe = $probe->replicate();
        $secondProbe->fill(['symbol' => 'GIANTSOUP-F', 'snapshot->symbol' => 'GIANTSOUP-F'])->save();
        $secondProbe->update(['snapshot->cargo->units' => 1]);
        $assertPending('cargo');
        $secondProbe->update(['snapshot->cargo->units' => 0, 'snapshot->nav->status' => 'IN_TRANSIT']);
        $assertPending('transit');
        $secondProbe->update(['stationed_waypoint' => CampaignBridgeFixture::SOURCE]);
        $assertPending(null);

        $purchase = ShipAction::create([
            'agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'ship_symbol' => 'GIANTSOUP-9',
            'key' => 'queued-replacement-hauler-purchase', 'state' => 'uncertain', 'origin' => 'automation',
            'command' => 'buy_ship', 'arguments' => ['shipType' => 'SHIP_LIGHT_HAULER'], 'expires_at' => now()->addMinute(),
        ]);
        $assertPending('unsettled_purchase');
        $purchase->update(['state' => 'confirmed']);
        $assertPending(null);

        $tradingArm = $fixture['trading_arm']['episode'];
        $armRun = AutomationRun::where('campaign_episode_id', $tradingArm->id)->sole();
        $armRun->update(['strategy' => 'buy_hauler']);
        $assertPending('unsettled_purchase');
        $armRun->update(['strategy' => 'trade']);
        $assertPending(null);

        $purchase->update(['command' => 'sell', 'state' => 'sending', 'automation_run_id' => $run->id]);
        $assertPending('role_work');
        $episode->update(['closed_at' => now()]);
        $this->get(self::BRIEF.'?agent=GIANTSOUP')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('plan.pending', 'New playbook queued. Waiting for the main campaign to settle.'));
    }
}
