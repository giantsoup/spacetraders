<?php

namespace Tests\Support;

final class Sk70Topology
{
    public const SYSTEM = 'X1-SK70';

    public static function observations(): array
    {
        // Shared coordinates and structural inputs come from the replay. The singleton
        // coordinates use isolated fixture positions where the replay gives no coordinates.
        $groups = [
            [9, -25, ['A1' => 6, 'A2' => 2, 'A3' => 3, 'A4' => 3]],
            [58, 64, ['D42' => 6, 'D43' => 6, 'D44' => 11, 'D45' => 4]],
            [45, 12, ['H52' => 7, 'H53' => 2, 'H54' => 4, 'H55' => 5]],
            [-46, -30, ['E46' => 5, 'E47' => 7]],
            [58, -48, ['F49' => 5, 'F50' => 6]],
            [-55, 10, ['B7' => 17]], [-69, 83, ['C40' => 8]], [21, 83, ['G51' => 5]],
            [710, 110, ['J59' => 14]], [73, -88, ['K90' => 9]],
            [-56, 10, ['B6' => 0]], [-71, 83, ['C41' => 0]], [709, 110, ['J58' => 0]],
            [-150, 30, ['I56' => 0]], [-151, 31, ['I57' => 0]], [81, 73, ['DX5D' => 0]],
        ];
        $points = $listings = [];
        foreach ($groups as [$x, $y, $members]) {
            foreach ($members as $name => $count) {
                $symbol = self::SYSTEM.'-'.$name;
                $traits = [['symbol' => 'MARKETPLACE']];
                if (in_array($name, ['A2', 'C40', 'H53'], true)) {
                    $traits[] = ['symbol' => 'SHIPYARD'];
                }
                $goods = [['symbol' => 'FUEL']];
                for ($i = 0; $i < $count; $i++) {
                    $goods[] = ['symbol' => $i === 0 && in_array($name, ['A3', 'D44', 'H52', 'H54', 'H55', 'E47', 'F49', 'F50', 'B7', 'C40', 'G51', 'J59'], true)
                        ? 'IRON_ORE' : 'FIXTURE_GOOD_'.$i];
                }
                $points[$symbol] = compact('symbol', 'x', 'y', 'traits') + ['systemSymbol' => self::SYSTEM];
                $listings[$symbol] = ['symbol' => $symbol, 'imports' => [], 'exports' => [], 'exchange' => $goods];
            }
        }

        return [$points, $listings];
    }

    public static function probe(string $symbol, string $waypoint, int $speed = 9): array
    {
        $ship = FakeSpaceTraders::probe($symbol, $waypoint, $speed);
        $ship['nav']['systemSymbol'] = implode('-', array_slice(explode('-', $waypoint), 0, 2));

        return $ship;
    }
}
