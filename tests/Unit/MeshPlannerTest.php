<?php

namespace Tests\Unit;

use App\Gameplay\FuelPlanner;
use App\Gameplay\Hungarian;
use App\Gameplay\MeshModel;
use App\Gameplay\MeshPlanner;
use App\Gameplay\MeshPoller;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Sk70Topology;

final class MeshPlannerTest extends TestCase
{
    public function test_checked_integer_matcher_agrees_with_a_seeded_brute_force_oracle(): void
    {
        mt_srand(762);
        for ($i = 0; $i < 400; $i++) {
            $n = mt_rand(1, 4);
            $m = mt_rand($n, 7);
            $costs = $primary = $secondary = [];
            for ($row = 0; $row < $n; $row++) {
                for ($column = 0; $column < $m; $column++) {
                    $tie = mt_rand(0, 1);
                    $cost = $row === $column || mt_rand(0, 4) > 0 ? mt_rand(-50, 50) : Hungarian::BIG;
                    $primary[$row][$column] = $cost;
                    $secondary[$row][$column] = $tie;
                    $costs[$row][$column] = $cost === Hungarian::BIG ? $cost : Hungarian::encode($cost, $tie, $n + 1);
                }
            }
            $match = Hungarian::solve($costs);
            $score = $ties = 0;
            foreach ($match as $row => $column) {
                $score += $primary[$row][$column];
                $ties += $secondary[$row][$column];
            }
            $this->assertSame($this->oracle($costs), $score * ($n + 1) + $ties);
            $this->assertCount($n, array_unique($match));
        }

        $this->assertSame([1, 0], Hungarian::solve([[-4, 0], [-8, 0]]));
    }

    private function oracle(array $table, int $row = 0, array $used = []): int
    {
        if (! isset($table[$row])) {
            return 0;
        }
        $best = Hungarian::BIG;
        foreach ($table[$row] as $column => $cost) {
            if ($cost === Hungarian::BIG || isset($used[$column])) {
                continue;
            }
            $remaining = $this->oracle($table, $row + 1, $used + [$column => true]);
            if ($remaining !== Hungarian::BIG) {
                $best = min($best, $cost + $remaining);
            }
        }

        return $best;
    }

    #[DataProvider('invalidMatrices')]
    public function test_solver_refuses_invalid_or_infeasible_matrices(array $matrix, string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);
        Hungarian::solve($matrix);
    }

    public static function invalidMatrices(): array
    {
        return [
            [[[1], [1]], '2×1'],
            [[[1, 2], [1]], 'row widths'],
            [[[1.25]], 'integer range'],
            [[[Hungarian::BIG]], 'no finite choice'],
            [[[0, Hungarian::BIG], [2, Hungarian::BIG]], 'No complete feasible matching'],
            [[[-Hungarian::BIG + 1, 0]], 'Row shift'],
        ];
    }

    public function test_integer_packing_rejects_overflow_and_handles_real_fleet_scale(): void
    {
        $start = hrtime(true);
        foreach ([[64, 128], [64, 64], [96, 192]] as [$rows, $columns]) {
            $matrix = [];
            for ($i = 0; $i < $rows; $i++) {
                for ($j = 0; $j < $columns; $j++) {
                    $matrix[$i][$j] = Hungarian::encode(-100000000 + abs($j - $i) * 5000, $i === $j ? 0 : 1, $rows + 1);
                }
            }
            $this->assertSame(range(0, $rows - 1), Hungarian::solve($matrix));
        }
        $this->assertLessThan(1_000_000_000, hrtime(true) - $start);
        $this->expectException(InvalidArgumentException::class);
        Hungarian::encode(PHP_INT_MAX, 1, 65);
    }

    public function test_historical_tiers_and_clusters_and_station_and_provisional_classification(): void
    {
        [$points, $listings] = Sk70Topology::observations();
        $policy = $this->policy();
        $model = MeshModel::build('X1-SK70', $points, $listings, [], $policy);
        $this->assertSame([1 => 21, 2 => 6], array_count_values(array_column($model->targets, 'tier')));
        $this->assertCount(16, array_unique(array_column($model->buckets, 'cluster')));
        $this->assertGreaterThan($model->targets['X1-SK70-A4']['weight'], $model->targets['X1-SK70-A3']['weight']);
        unset($listings['X1-SK70-J58']);
        $model = MeshModel::build('X1-SK70', $points, $listings, ['X1-SK70-A2'], $policy);
        $this->assertArrayNotHasKey('X1-SK70-A2', $model->targets);
        $this->assertTrue($model->targets['X1-SK70-J58']['provisional']);
        $this->assertSame(1000, $model->targets['X1-SK70-J58']['weight']);
        $listings['X1-SK70-J58'] = ['exchange' => [['symbol' => 'FUEL']]];
        $model = MeshModel::build('X1-SK70', $points, $listings, ['X1-SK70-A2'], ['tier2' => false] + $policy);
        $this->assertCount(20, $model->targets);
    }

    public function test_count_prefix_and_fixed_exact_member_and_long_fuel_only_assignment(): void
    {
        [$model, $probes] = $this->smallModel(['A' => [0, 1], 'B' => [0, 1], 'C' => [0, 1], 'D' => [350, 2]], ['P-1' => 'A', 'P-2' => 'C']);
        $planner = new MeshPlanner(new FuelPlanner);
        $policy = $this->policy();
        $plan = $planner->plan($model, $probes, [], [], [], $policy);
        $this->assertSame([1, 2], $plan['prefixes']['0,0:1']);
        $this->assertSame(0, $plan['tie']);
        $this->assertSame([1 => 2, 2 => 0], $plan['tiers']);
        $fixed = $planner->plan($model, $probes, ['P-2'], ['X1-TEST-B', 'X1-TEST-C'], [], $policy, ['commitments' => ['P-2' => 'X1-TEST-C']]);
        $this->assertSame(['X1-TEST-C' => 'P-2'], $fixed['locked']);
        $this->assertSame([1, 2], $fixed['prefixes']['0,0:1']);
        $probes[0]['nav']['waypointSymbol'] = 'X1-TEST-B';
        $long = $planner->plan($model, $probes, ['P-2'], ['X1-TEST-A', 'X1-TEST-C'], [],
            $policy, ['commitments' => ['P-2' => 'X1-TEST-B']]);
        $this->assertSame([1 => 1, 2 => 1], $long['tiers']);
        $this->assertSame('X1-TEST-D', $long['moves'][0]['destination']);
        $this->assertGreaterThan(900, $planner->route($model, $probes[0], 'X1-TEST-D', 5400));
    }

    public function test_external_arrival_reservation_keeps_covered_singletons_parked(): void
    {
        [$model, $probes] = $this->smallModel(['A' => [0, 1], 'B' => [20, 1], 'C' => [40, 1]], ['P-1' => 'A', 'P-2' => 'B']);
        $plan = (new MeshPlanner(new FuelPlanner))->plan($model, $probes, [], ['X1-TEST-A'], [], $this->policy());

        $this->assertSame([], $plan['moves']);
        $this->assertSame([1 => 2, 2 => 0], $plan['tiers']);
    }

    public function test_ordinary_demand_churn_produces_no_cross_cluster_moves(): void
    {
        [$model, $probes] = $this->smallModel(['A' => [0, 1], 'B' => [0, 1], 'C' => [40, 1], 'D' => [40, 1]],
            ['P-1' => 'A', 'P-2' => 'C']);
        $planner = new MeshPlanner(new FuelPlanner);
        $hops = 0;
        for ($pass = 0; $pass < 60; $pass++) {
            $demand = ['X1-TEST-'.['B', 'D', 'A', 'C'][$pass % 4]];
            $plan = $planner->plan($model, $probes, [], [], [], $this->policy(), ['now' => 1000 + $pass * 601, 'demand' => $demand]);
            foreach ($plan['moves'] as $move) {
                $this->assertSame($model->targets[$move['origin']]['cluster'], $model->targets[$move['destination']]['cluster']);
                foreach ($probes as &$probe) {
                    if ($probe['symbol'] === $move['ship']) {
                        $probe['nav']['waypointSymbol'] = $move['destination'];
                    }
                }
                unset($probe);
                $hops++;
            }
        }
        $this->assertGreaterThan(20, $hops);
    }

    public function test_dwell_only_probes_are_released_in_both_pricing_solves(): void
    {
        [$model, $probes] = $this->smallModel(['YARD' => [0, 1], 'A' => [5, 1], 'B' => [10, 1], 'F' => [35, 2]],
            ['Q1' => 'YARD', 'D' => 'YARD'], ['X1-TEST-YARD']);
        $planner = new MeshPlanner(new FuelPlanner);
        $policy = $this->policy();
        $probes[0]['mesh_arrival'] = 990;
        $delivery = Sk70Topology::probe('NEW', 'X1-TEST-YARD');
        $gain = $planner->gain($model, $probes, [], [], [], $policy, ['now' => 1000], $delivery);
        $this->assertSame([[1 => 2, 2 => 0], [1 => 2, 2 => 1]], [$gain['base'], $gain['delivered']]);
        $retained = $planner->gain($model, $probes, [], [], [], $policy, ['now' => 1000, 'steady' => false], $delivery);
        $this->assertSame(1, $retained['tier1']);
        $busy = $planner->gain($model, $probes, ['Q1'], [], [], $policy, ['now' => 1000], $delivery);
        $this->assertSame(1, $busy['tier1']);
        $plan = $planner->plan($model, $probes, [], [], [], $policy, ['now' => 1000]);
        $this->assertSame('dwell', $plan['restrictions']['Q1']);
        $this->assertNotContains('Q1', array_column($plan['moves'], 'ship'));
    }

    public function test_patrol_is_tier_local_and_recent_quotes_and_cooldown_hold_hops(): void
    {
        [$model, $probes] = $this->smallModel(['A' => [0, 1], 'B' => [0, 1], 'F' => [0, 2], 'C' => [100, 1]],
            ['P-1' => 'A', 'P-2' => 'C', 'P-3' => 'F']);
        $planner = new MeshPlanner(new FuelPlanner);
        $policy = $this->policy();
        $context = ['now' => 1000, 'ages' => ['X1-TEST-A' => 130, 'X1-TEST-B' => 299], 'demand' => ['X1-TEST-B']];
        $recent = $planner->plan($model, $probes, ['P-3'], [], [], $policy, $context);
        $this->assertSame([], $recent['moves']);
        $context['ages']['X1-TEST-B'] = 300;
        $plan = $planner->plan($model, array_reverse($probes), ['P-3'], [], [], $policy, $context);
        $this->assertSame(['X1-TEST-B'], array_column($plan['moves'], 'destination'));
        $this->assertSame(['P-1'], array_column($plan['moves'], 'ship'));
        $this->assertTrue($plan['moves'][0]['patrol']);
        $this->assertSame([], $planner->plan($model, $probes, ['P-3'], [], [], $policy, $context + ['cooldowns' => ['0,0:1' => 401]])['moves']);
        $this->assertSame([], $planner->plan($model, $probes, ['P-3'], ['X1-TEST-B'], [], $policy, $context)['moves']);
        $context['demand'] = ['X1-TEST-F', 'X1-TEST-C'];
        $this->assertSame($plan['counts'], $planner->plan($model, $probes, ['P-3'], [], [], $policy, $context)['counts']);
    }

    public function test_poller_prioritizes_due_buyer_then_other_demand_and_keeps_endpoint_identity(): void
    {
        $list = [
            ['symbol' => 'A', 'age' => 120],
            ['symbol' => 'B', 'age' => 240],
            ['symbol' => 'C', 'age' => 119],
            ['symbol' => 'A', 'age' => 1, 'kind' => 'shipyard', 'invalidated' => true],
            ['symbol' => 'F', 'age' => 1800, 'interval' => 1800],
            ['symbol' => 'MISSING', 'age' => null, 'observer' => null],
        ];
        $items = array_map(fn ($item) => $item + ['kind' => 'market', 'interval' => 120, 'invalidated' => false, 'purchase' => false, 'observer' => 'P'], $list);
        $poller = new MeshPoller;
        $this->assertSame(['A', 'B', 'shipyard:A', 'F'], array_column($poller->order($items, ['A', 'B', 'C'], 'A', []), 'key'));
        $this->assertSame(['B', 'shipyard:A'], array_column($poller->order($items, ['A', 'B', 'C'], 'A', ['A' => true], 100), 'key'));
    }

    private function policy(): array
    {
        return Yaml::parseFile(__DIR__.'/../../campaigns/weekly-system-saturation-mesh.yaml')['probes']['mesh']['saturation'];
    }

    private function smallModel(array $layout, array $positions, array $stations = []): array
    {
        $points = $listings = [];
        foreach ($layout as $name => [$x, $tier]) {
            $symbol = 'X1-TEST-'.$name;
            $points[$symbol] = compact('symbol', 'x') + ['y' => 0, 'systemSymbol' => 'X1-TEST', 'traits' => [['symbol' => 'MARKETPLACE']]];
            $listings[$symbol] = ['exchange' => [['symbol' => $tier === 1 ? 'IRON' : 'FUEL']]];
        }

        return [MeshModel::build('X1-TEST', $points, $listings, $stations, $this->policy()),
            array_map(fn ($ship, $position) => Sk70Topology::probe($ship, 'X1-TEST-'.$position), array_keys($positions), $positions)];
    }
}
