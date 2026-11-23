<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\CampaignMesh;
use App\Gameplay\CampaignProbeBootstrap;
use App\Gameplay\CampaignStatus;
use App\Models\AutomationRun;
use App\Models\CampaignEvent;
use App\Models\GameObservation;
use App\Models\Ship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SaturationFixture;
use Tests\TestCase;

class CampaignMeshTest extends TestCase
{
    use RefreshDatabase;
    use SaturationFixture;

    protected function tearDown(): void
    {
        $this->tearDownSaturation();
        parent::tearDown();
    }

    #[DataProvider('hubCounts')]
    public function test_minute_zero_bootstraps_three_probes_and_sweeps_all_seeded_hubs(int $hubCount): void
    {
        $this->setUpSaturation(hubs: $hubCount, seedCoverage: false, credits: 156000);
        $episode = $this->primary('discover');
        for ($pass = 0; $pass < 40; $pass++) {
            $this->tickC0();
            foreach (AutomationRun::whereNotNull('active_agent_id')->get() as $run) {
                $this->workRun($run);
            }
            $mesh = app(CampaignMesh::class)->target($episode->refresh(), $this->agent);
            if ($mesh['hubs']->count() === $hubCount && ! $mesh['hubs']->contains('prices_unknown', true) && $mesh['pool_probe_count'] === 4 && $episode->phase === 'earn') {
                break;
            }
        }

        $this->assertSame('earn', $episode->fresh()->phase);
        $this->assertSame(3, CampaignEvent::where('kind', 'probe_purchased')->count());
        $this->assertSame(4, $mesh['pool_probe_count']);
        $this->assertCount($hubCount, $mesh['hubs']);
        $this->assertCount($hubCount, $mesh['hubs']->where('prices_unknown', false));
        $this->assertFalse($episode->grant()->paused_at !== null);
    }

    public static function hubCounts(): array
    {
        return [[8], [21]];
    }

    public function test_earn_directory_reads_are_system_scoped_and_deferrals_do_not_enter_backoff(): void
    {
        $this->setUpSaturation(hubs: 7, seedCoverage: false);
        $episode = $this->primary();
        $sync = app(AgentSynchronizer::class);
        $sync->observe($this->agent, 'waypoint', 'X1-ELSE-NEW', $this->point('X1-ELSE-NEW', 20), 'listing');
        $this->editFixture(function (&$world) {
            $world['defer_read_path'] = '/systems/X1-TEST/waypoints/X1-TEST-H3/market';
        });
        $this->tickC0();
        $this->assertArrayNotHasKey('X1-TEST-H3', $episode->fresh()->state['directory_failures']);
        $this->editFixture(function (&$world) {
            unset($world['defer_read_path']);
            $world['market_errors']['X1-TEST-H3'] = 404;
        });

        for ($pass = 0; $pass < 5; $pass++) {
            $this->tickC0();
        }
        $record = $episode->fresh()->state['directory_failures']['X1-TEST-H3'];
        $this->assertSame(2, $record['count']);
        $this->assertTrue(now()->lt($record['retry_at']));
        $status = app(CampaignStatus::class)->saturation($this->operator->id, 'LOCAL');
        $this->assertContains('X1-TEST-H3', array_column($status['mesh']['directory_gaps'], 'waypoint'));
        $this->assertFalse(GameObservation::where('kind', 'market_listing')->where('symbol', 'X1-ELSE-NEW')->exists());
        $this->assertTrue(GameObservation::where('kind', 'market_listing')->where('symbol', 'X1-TEST-H7')->exists());

        $this->editFixture(function (&$world) {
            unset($world['market_errors']['X1-TEST-H3']);
        });
        $this->travel(31)->minutes();
        $this->tickC0();
        $this->assertArrayNotHasKey('X1-TEST-H3', $episode->fresh()->state['directory_failures']);
        $this->assertTrue(GameObservation::where('kind', 'market_listing')->where('symbol', 'X1-TEST-H3')->exists());
    }

    public function test_mesh_refresh_prioritizes_invalidated_and_unknown_quotes_and_honors_both_cadences(): void
    {
        $this->setUpSaturation(hubs: 6);
        $episode = $this->primary();
        $mesh = app(CampaignMesh::class);
        $positions = ['X1-TEST-ROCK' => 500, 'X1-TEST-PORT' => 120, 'X1-TEST-H3' => 299, 'X1-TEST-H4' => 300,
            'X1-TEST-H5' => 359, 'X1-TEST-H6' => 500];
        foreach ($positions as $symbol => $age) {
            $quote = GameObservation::where('kind', 'market')->where('symbol', $symbol)->firstOrFail();
            $data = $quote->data;
            if ($symbol === 'X1-TEST-H3') {
                $data['invalidated_by_action'] = 1;
            }
            if ($symbol === 'X1-TEST-H5') {
                $data['tradeGoods'] = [];
            }
            $quote->update(['observed_at' => now()->subSeconds($age), 'data' => $data]);
        }
        $read = [];
        $before = count($this->world()['calls']);
        $mesh->refresh($episode, $this->agent, $read, microtime(true) + 30, 'X1-TEST-PORT');
        $first = $this->marketCallsSince($before);
        $this->assertCount(4, $first);
        $this->assertSame(['X1-TEST-H5', 'X1-TEST-H3', 'X1-TEST-PORT'], array_slice($first, 0, 3));
        $this->assertNotContains('X1-TEST-H4', $first);
        $before = count($this->world()['calls']);
        $mesh->refresh($episode, $this->agent, $read, microtime(true) + 30, 'X1-TEST-PORT');
        $this->assertSame([], $this->marketCallsSince($before));
        $read = [];
        $mesh->refresh($episode, $this->agent, $read, microtime(true) + 30, 'X1-TEST-PORT');
        $this->assertContains('X1-TEST-H4', $this->marketCallsSince($before));
    }

    private function marketCallsSince(int $offset): array
    {
        return collect(array_slice($this->world()['calls'], $offset))
            ->filter(fn (array $call) => str_ends_with($call['path'], '/market'))
            ->map(fn (array $call) => explode('/', $call['path'])[4])->values()->all();
    }

    public function test_verified_nonmarket_yard_scout_uses_36_minutes_and_collects_detailed_offers(): void
    {
        $this->setUpSaturation(remoteYard: true);
        $this->editFixture(function (&$world) {
            $world['ships'][1]['engine']['speed'] = 5;
        });
        $sync = app(AgentSynchronizer::class);
        $sync->observe($this->agent, 'waypoint', 'X1-TEST-YARD', $this->point('X1-TEST-YARD', 393, ['SHIPYARD']), 'listing');
        $sync->sync($this->agent, false);
        $episode = $this->primary();
        $mesh = app(CampaignMesh::class)->target($episode, $this->agent);
        $this->assertSame(5, $mesh['pool_probe_target']);
        $runner = app(AutomationRunner::class);
        $probe = Ship::where('symbol', 'LOCAL-2')->sole()->snapshot;
        $scout = $runner->previewMeshScout($this->agent, $probe, 'X1-TEST-YARD', ['minutes' => 15, 'reserve' => 0], $episode, 'shipyard');
        $this->assertSame(1980, $scout->plan['route']['outbound']['estimated_seconds']);
        $this->assertSame(36, $scout->plan['minutes']);
        $this->assertSame('shipyard', $scout->plan['observe_kind']);
        $runner->startCampaign($this->agent, $scout, $episode);
        $this->workRun($scout);

        $this->assertSame('completed', $scout->fresh()->state->value, $scout->fresh()->blocked_reason ?? '');
        $yards = GameObservation::where('kind', 'shipyard')->where('symbol', 'X1-TEST-YARD')->sole();
        $this->assertCount(2, $yards->data['ships']);
        $buy = $runner->previewProbePurchase($this->agent, 'LOCAL-2', 'X1-TEST-YARD', ['minutes' => 15], $episode);
        $runner->startCampaign($this->agent, $buy, $episode);
        $this->workRun($buy);
        $this->assertSame('completed', $buy->fresh()->state->value, $buy->fresh()->blocked_reason ?? '');
        $this->assertSame(0, collect($this->world()['calls'])->where('path', '/systems/X1-TEST/waypoints/X1-TEST-YARD/market')->count());
        $this->assertSame([5, 5], app(CampaignProbeBootstrap::class)->probeTarget($episode, $this->agent, CampaignProbeBootstrap::policy($episode->grant(), $episode)));
    }

    public function test_extended_scout_is_refused_at_start_when_grant_or_wind_down_cutoff_moves_too_close(): void
    {
        $this->setUpSaturation(remoteYard: true);
        $this->editFixture(function (&$world) {
            $world['ships'][1]['engine']['speed'] = 5;
        });
        $sync = app(AgentSynchronizer::class);
        $sync->observe($this->agent, 'waypoint', 'X1-TEST-YARD', $this->point('X1-TEST-YARD', 393, ['SHIPYARD']), 'listing');
        $sync->sync($this->agent, false);
        $episode = $this->primary();
        $ship = Ship::where('symbol', 'LOCAL-2')->sole()->snapshot;
        $runner = app(AutomationRunner::class);
        $preview = $runner->previewMeshScout($this->agent, $ship, 'X1-TEST-YARD', ['minutes' => 15, 'reserve' => 0], $episode, 'shipyard');
        $episode->update(['next_reset_at' => now()->addMinutes(75)]);
        $this->expectExceptionMessage('duration exceeds available time');
        $runner->startCampaign($this->agent, $preview, $episode);
    }
}
