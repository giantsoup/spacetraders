<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignFleet;
use App\Gameplay\SpaceTradersApi;
use App\Models\AutomationRun;
use App\Models\CampaignGrant;
use App\Models\Ship;
use App\Models\ShipAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeSpaceTraders;
use Tests\Support\SaturationFixture;
use Tests\TestCase;

class CampaignReusedShipSymbolTest extends TestCase
{
    use RefreshDatabase;
    use SaturationFixture;

    protected function tearDown(): void
    {
        $this->tearDownSaturation();
        parent::tearDown();
    }

    #[DataProvider('armResets')]
    public function test_c0_buys_with_a_reused_probe_only_when_the_arm_belongs_to_a_prior_reset(bool $sameReset, bool $expired): void
    {
        $this->setUpSaturation(hubs: 2, seedCoverage: false, agentSymbol: 'GIANTSOUP');
        $this->editFixture(fn (&$world) => $world['ships'][1] = FakeSpaceTraders::probe('GIANTSOUP-5', 'X1-TEST-ROCK'));
        $sync = app(AgentSynchronizer::class);
        $sync->sync($this->agent, false);
        foreach (['X1-TEST-ROCK', 'X1-TEST-PORT'] as $waypoint) {
            $sync->observe($this->agent, 'market', $waypoint, app(SpaceTradersApi::class)->market($this->agent, $waypoint));
        }
        $episode = $this->primary();
        $old = $this->legacyArm($sameReset, $expired);
        $pool = app(CampaignFleet::class)->probes($episode);
        $this->assertSame($sameReset ? [] : ['GIANTSOUP-5'], array_column($pool, 'symbol'));

        $this->tickC0();
        $buy = AutomationRun::where('campaign_episode_id', $episode->id)->where('strategy', 'buy_probe')->whereNotNull('active_agent_id')->sole();
        $this->assertSame($sameReset ? 'GIANTSOUP-1' : 'GIANTSOUP-5', $buy->ship_symbol);
        $this->workRun($buy);
        $this->assertSame('completed', $buy->fresh()->state->value, $buy->fresh()->blocked_reason ?? '');
        $action = ShipAction::where('automation_run_id', $buy->id)->where('command', 'buy_ship')->sole();
        $this->assertSame('confirmed', $action->state->value);
        $this->assertSame('GIANTSOUP-3', $action->outcome['delivered_ship']);
        $this->assertNull($old->fresh()->revoked_at);
    }

    #[DataProvider('armResets')]
    public function test_c0_drain_recovers_previously_used_ship_unless_an_arm_owns_this_reset(bool $sameReset, bool $expired): void
    {
        $this->setUpSaturation(hubs: 2, agentSymbol: 'GIANTSOUP');
        $this->editFixture(function (&$world) {
            $ship = FakeSpaceTraders::lightHauler('GIANTSOUP-5', 'X1-TEST-PORT');
            $ship['cargo']['units'] = 5;
            $ship['cargo']['inventory'] = [['symbol' => 'IRON_ORE', 'name' => 'Iron Ore', 'description' => 'Ore', 'units' => 5]];
            $world['ships'][] = $ship;
        });
        app(AgentSynchronizer::class)->sync($this->agent, false);
        $episode = $this->primary();
        $old = $this->legacyArm($sameReset, $expired);
        AutomationRun::create(['agent_id' => $this->agent->id, 'campaign_episode_id' => $episode->id,
            'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'GIANTSOUP-5', 'strategy' => 'trade', 'state' => 'stopped',
            'started_at' => now()->subMinute(), 'expires_at' => now()->addMinute(),
            'plan' => ['symbol' => 'IRON_ORE', 'market' => 'X1-TEST-PORT'], 'totals' => []]);
        app(CampaignControl::class)->drainPause($episode->grant(), 'operator_pause', $episode);

        $this->tickC0();
        $recoveries = AutomationRun::where('campaign_episode_id', $episode->id)->where('strategy', 'sell_aboard');
        if ($sameReset) {
            $this->assertFalse($recoveries->exists());
            $this->assertEmpty($this->world()['writes']);
            $this->assertSame(5, Ship::where('symbol', 'GIANTSOUP-5')->sole()->snapshot['cargo']['units']);

            return;
        }
        $recovery = $recoveries->sole();
        $this->assertSame('GIANTSOUP-5', $recovery->ship_symbol);
        $this->workRun($recovery);
        $this->assertSame('completed', $recovery->fresh()->state->value, $recovery->fresh()->blocked_reason ?? '');
        $sale = ShipAction::where('automation_run_id', $recovery->id)->where('command', 'sell')->sole();
        $this->assertSame('confirmed', $sale->state->value);
        $this->assertSame(5, $sale->arguments['units']);
        $this->assertSame(0, Ship::where('symbol', 'GIANTSOUP-5')->sole()->snapshot['cargo']['units']);
        $this->assertNull($old->fresh()->revoked_at);
    }

    public static function armResets(): array
    {
        return [
            'expired prior-reset grant' => [false, true],
            'unexpired prior-reset grant' => [false, false],
            'unexpired same-reset grant' => [true, false],
        ];
    }

    private function legacyArm(bool $sameReset, bool $expired): CampaignGrant
    {
        return CampaignGrant::create(['campaign_revision_id' => $this->armRevision->id, 'revision_hash' => $this->armRevision->content_hash,
            'user_id' => $this->operator->id, 'agent_symbol' => $this->agent->name, 'slot' => null,
            'expires_at' => $expired ? now()->subMinute() : now()->addHour(), 'limits' => $this->armRevision->body['limits'] + ['arm' => [
                'label' => 'H1', 'ship' => 'GIANTSOUP-5', 'reset_id' => $sameReset ? $this->agent->reset_id : now()->subWeek()->toDateString(),
            ]]]);
    }
}
