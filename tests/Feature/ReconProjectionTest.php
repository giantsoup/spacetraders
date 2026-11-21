<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\ReconProjection;
use App\Models\CampaignEpisode;
use App\Models\GameObservation;
use App\Models\ReconMission;
use App\Models\Ship;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\ReconTestCase;

class ReconProjectionTest extends ReconTestCase
{
    private CampaignEpisode $episode;

    private ReconMission $mission;

    protected function setUp(): void
    {
        parent::setUp();
        $body = Yaml::parse(str_replace('GIANTSOUP', 'LOCAL', file_get_contents(base_path('campaigns/weekly-default.yaml'))));
        $grant = app(CampaignControl::class)->approve(app(CampaignPlaybook::class)->import(Yaml::dump($body, 10)), $this->user);
        app(CampaignDirector::class)->tick();
        $this->episode = CampaignEpisode::where('campaign_grant_id', $grant->id)->firstOrFail();
        $this->episode->update(['state' => array_replace_recursive($this->episode->state ?? [], ['role_ships' => ['command' => 'LOCAL-1', 'probe' => 'LOCAL-2']])]);
        $this->mission = $this->mission();
        $graph = $this->mission->graph;
        $graph['X1-NEXT'] = ['gate' => 'X1-NEXT-GATE', 'complete' => true, 'connections' => ['X1-TEST-GATE'],
            'catalogue' => ['complete' => true, 'marketplaces' => ['X1-NEXT-GATE', 'X1-NEXT-ASK', 'X1-NEXT-BID']]];
        $this->mission->update(['graph' => $graph]);
        foreach (['GATE' => 0, 'ASK' => 5, 'BID' => 10] as $name => $x) {
            app(AgentSynchronizer::class)->observe($this->agent, 'waypoint', 'X1-NEXT-'.$name, ['symbol' => 'X1-NEXT-'.$name, 'systemSymbol' => 'X1-NEXT', 'type' => 'PLANET', 'x' => $x, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]]);
        }
        $this->quotes(100, 400);
    }

    private function quotes(int $ask, int $bid, int $volume = 10): void
    {
        $samples = [];
        foreach (['ASK' => [$ask, 1], 'BID' => [1000, $bid]] as $name => $prices) {
            $symbol = 'X1-NEXT-'.$name;
            $data = ['symbol' => $symbol, 'imports' => [], 'exports' => [], 'exchange' => [['symbol' => 'IRON'], ['symbol' => 'FUEL']],
                'tradeGoods' => [['symbol' => 'IRON', 'tradeVolume' => $volume, 'purchasePrice' => $prices[0], 'sellPrice' => $prices[1]],
                    ['symbol' => 'FUEL', 'tradeVolume' => 100, 'purchasePrice' => 5, 'sellPrice' => 1]]];
            $observation = app(AgentSynchronizer::class)->observe($this->agent, 'market', $symbol, $data);
            app(AgentSynchronizer::class)->observe($this->agent, 'market_listing', $symbol, $data);
            $samples[$symbol] = ['first_at' => now()->subMinutes(20)->toIso8601String(), 'observed_at' => now()->toIso8601String(), 'observation_id' => $observation->id, 'data' => $data];
        }
        $samples['X1-NEXT-GATE'] = ['first_at' => now()->toIso8601String()];
        $this->mission->update(['visits' => ['X1-NEXT' => ['markets' => $samples, 'reads' => 3, 'arrival' => now()->toIso8601String()]]]);
    }

    public function test_projection_uses_shared_proof_and_scaled_physics_without_writing_campaign_or_ship(): void
    {
        $before = [$this->episode->fresh()->getRawOriginal(), $this->episode->grant()->getRawOriginal(), $this->episode->grant()->revision()->getRawOriginal(), Ship::where('symbol', 'LOCAL-1')->firstOrFail()->getRawOriginal()];
        $report = app(ReconProjection::class)->evaluate($this->agent, $this->mission, 'X1-NEXT');
        $pair = collect($report['pairs'])->firstWhere('source', 'X1-NEXT-ASK');
        $this->assertSame('unconfirmed', $report['classification']);
        $proof = $pair['proof']['candidate'];
        $scaled = $pair['scaled']['candidate'];
        $this->assertSame(10, $proof['units']);
        $this->assertSame(40, $scaled['units']);
        $this->assertSame(19, $proof['position_seconds']);
        $this->assertSame(197.0, (float) $proof['seconds']);
        $this->assertSame(238.0, (float) $scaled['seconds']);
        $this->assertSame($before, [$this->episode->fresh()->getRawOriginal(), $this->episode->grant()->getRawOriginal(), $this->episode->grant()->revision()->getRawOriginal(), Ship::where('symbol', 'LOCAL-1')->firstOrFail()->getRawOriginal()]);
    }

    public function test_a_cycle_whose_individual_legs_fit_but_total_tank_does_not_cannot_qualify(): void
    {
        $row = Ship::where('symbol', 'LOCAL-1')->firstOrFail();
        $ship = $row->snapshot;
        $ship['fuel'] = ['capacity' => 25, 'current' => 25];
        $row->update(['snapshot' => $ship]);
        $point = GameObservation::where('kind', 'waypoint')->where('symbol', 'X1-NEXT-BID')->firstOrFail();
        $point->update(['data' => array_replace($point->data, ['x' => 20])]);
        $report = app(ReconProjection::class)->evaluate($this->agent, $this->mission, 'X1-NEXT');
        $this->assertSame([], $report['qualifying']);
        $this->assertSame('no_viable_pair', $report['classification']);
    }

    public function test_proof_credit_cap_and_scaled_long_run_are_respected(): void
    {
        $this->quotes(10000, 30000, 100);
        $report = app(ReconProjection::class)->evaluate($this->agent, $this->mission, 'X1-NEXT');
        $pair = collect($report['pairs'])->firstWhere('source', 'X1-NEXT-ASK');
        $this->assertLessThanOrEqual(6, $pair['proof']['candidate']['units']);
        $this->quotes(100, 10000, 1);
        $row = Ship::where('symbol', 'LOCAL-1')->firstOrFail();
        $ship = $row->snapshot;
        $ship['engine']['speed'] = 15;
        $ship['fuel'] = ['capacity' => 500, 'current' => 500];
        $row->update(['snapshot' => $ship]);
        $point = GameObservation::where('kind', 'waypoint')->where('symbol', 'X1-NEXT-BID')->firstOrFail();
        $point->update(['data' => array_replace($point->data, ['x' => 202])]);
        $report = app(ReconProjection::class)->evaluate($this->agent, $this->mission, 'X1-NEXT');
        $pair = collect($report['pairs'])->firstWhere('source', 'X1-NEXT-ASK');
        $this->assertArrayHasKey('candidate', $pair['proof']);
        $this->assertArrayHasKey('long_run', $pair['scaled']);
        $this->assertSame([], $report['qualifying']);
    }

    public function test_confirmation_re_evaluates_all_pairs_after_best_deteriorates(): void
    {
        $this->quotes(100, 400);
        $visits = $this->mission->visits;
        foreach (['ASK', 'BID'] as $name) {
            $visits['X1-NEXT']['markets']['X1-NEXT-'.$name]['confirmed_at'] = now()->toIso8601String();
        }
        $this->mission->update(['visits' => $visits]);
        $this->assertSame('promising', app(ReconProjection::class)->evaluate($this->agent, $this->mission, 'X1-NEXT')['classification']);
        $quote = GameObservation::where('kind', 'market')->where('symbol', 'X1-NEXT-BID')->firstOrFail();
        $data = $quote->data;
        $data['tradeGoods'][0]['sellPrice'] = 90;
        $quote->update(['data' => $data]);
        $this->assertNotSame('promising', app(ReconProjection::class)->evaluate($this->agent, $this->mission, 'X1-NEXT')['classification']);
    }

    public function test_expired_evidence_and_missing_measured_edges_remain_unresolved(): void
    {
        GameObservation::where('kind', 'market')->update(['observed_at' => now()->subHours(7)]);
        $this->assertSame('evidence_expired', app(ReconProjection::class)->evaluate($this->agent, $this->mission, 'X1-NEXT')['classification']);
        $this->quotes(100, 400);
        config(['gameplay.route_evidence' => 'measured']);
        $this->assertSame('route_unmeasured', app(ReconProjection::class)->evaluate($this->agent, $this->mission, 'X1-NEXT')['classification']);
    }

    public function test_expired_negative_quotes_remain_eligible_for_evidence_refresh(): void
    {
        $this->quotes(100, 90);
        $this->assertSame('no_viable_pair', app(ReconProjection::class)->evaluate($this->agent, $this->mission, 'X1-NEXT')['classification']);
        GameObservation::where('kind', 'market')->update(['observed_at' => now()->subHours(7)]);
        $this->assertSame('evidence_expired', app(ReconProjection::class)->evaluate($this->agent, $this->mission, 'X1-NEXT')['classification']);
        $this->quotes(100, 400);
        $this->assertSame('unconfirmed', app(ReconProjection::class)->evaluate($this->agent, $this->mission, 'X1-NEXT')['classification']);
    }

    public function test_remote_source_near_or_beyond_one_tank_from_gate_qualifies_when_fuel_stops_exist(): void
    {
        $row = Ship::where('symbol', 'LOCAL-1')->firstOrFail();
        $ship = $row->snapshot;
        $ship['fuel'] = ['capacity' => 400, 'current' => 400];
        $row->update(['snapshot' => $ship]);
        // Case 1: direct gate->ASK takes 352 fuel, leaving 48, while ASK->BID->ASK takes 184 fuel.
        // Because ASK sells fuel, the trader refuels at ASK before starting the trade cycle.
        GameObservation::where('kind', 'waypoint')->where('symbol', 'X1-NEXT-ASK')->firstOrFail()
            ->update(['data' => ['symbol' => 'X1-NEXT-ASK', 'systemSymbol' => 'X1-NEXT', 'type' => 'PLANET', 'x' => 350, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]]]);
        GameObservation::where('kind', 'waypoint')->where('symbol', 'X1-NEXT-BID')->firstOrFail()
            ->update(['data' => ['symbol' => 'X1-NEXT-BID', 'systemSymbol' => 'X1-NEXT', 'type' => 'PLANET', 'x' => 440, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]]]);
        $report = app(ReconProjection::class)->evaluate($this->agent, $this->mission, 'X1-NEXT');
        $this->assertSame('unconfirmed', $report['classification'], json_encode($report));
        $this->assertCount(1, $report['qualifying']);

        // Case 2: gate->ASK is 440 units (>400 tank). Without a mid-system fuel stop it fails;
        // once a verified intermediate fuel seller at x=220 is observed, 1-stop arrival qualifies it.
        GameObservation::where('kind', 'waypoint')->where('symbol', 'X1-NEXT-ASK')->firstOrFail()
            ->update(['data' => ['symbol' => 'X1-NEXT-ASK', 'systemSymbol' => 'X1-NEXT', 'type' => 'PLANET', 'x' => 440, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]]]);
        GameObservation::where('kind', 'waypoint')->where('symbol', 'X1-NEXT-BID')->firstOrFail()
            ->update(['data' => ['symbol' => 'X1-NEXT-BID', 'systemSymbol' => 'X1-NEXT', 'type' => 'PLANET', 'x' => 490, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]]]);
        $withoutMid = app(ReconProjection::class)->evaluate($this->agent, $this->mission, 'X1-NEXT');
        $this->assertSame([], $withoutMid['qualifying']);

        app(AgentSynchronizer::class)->observe($this->agent, 'waypoint', 'X1-NEXT-MID', [
            'symbol' => 'X1-NEXT-MID', 'systemSymbol' => 'X1-NEXT', 'type' => 'FUEL_STATION', 'x' => 220, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE']]]);
        $midData = ['symbol' => 'X1-NEXT-MID', 'imports' => [], 'exports' => [], 'exchange' => [['symbol' => 'FUEL']],
            'tradeGoods' => [['symbol' => 'FUEL', 'tradeVolume' => 100, 'purchasePrice' => 5, 'sellPrice' => 1]]];
        $midObs = app(AgentSynchronizer::class)->observe($this->agent, 'market', 'X1-NEXT-MID', $midData);
        app(AgentSynchronizer::class)->observe($this->agent, 'market_listing', 'X1-NEXT-MID', $midData);
        $visits = $this->mission->visits;
        $visits['X1-NEXT']['markets']['X1-NEXT-MID'] = ['first_at' => now()->subMinutes(20)->toIso8601String(), 'observed_at' => now()->toIso8601String(), 'observation_id' => $midObs->id, 'data' => $midData];
        $this->mission->update(['visits' => $visits]);

        $withMid = app(ReconProjection::class)->evaluate($this->agent, $this->mission->fresh(), 'X1-NEXT');
        $this->assertSame('unconfirmed', $withMid['classification'], json_encode($withMid));
        $this->assertCount(1, $withMid['qualifying']);
    }
}
