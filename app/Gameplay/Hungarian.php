<?php

namespace App\Gameplay;

use InvalidArgumentException;

final class Hungarian
{
    public const BIG = 1 << 52;

    public static function encode(int $primary, int $tie, int $scale): int
    {
        if ($scale < 1 || $tie < 0 || $tie >= $scale
            || $primary > intdiv(self::BIG - 1 - $tie, $scale)
            || $primary < -intdiv(self::BIG - 1, $scale)) {
            throw new InvalidArgumentException('Assignment cost exceeds the integer packing bound.');
        }

        return $primary * $scale + $tie;
    }

    /** @param list<list<int>> $costs
     * @return list<int> Selected column for each row.
     */
    public static function solve(array $costs): array
    {
        $rows = count($costs);
        $columns = is_array($costs[0] ?? null) ? count($costs[0]) : 0;
        $fail = static function (string $reason) use ($rows, $columns): never {
            throw new InvalidArgumentException("Assignment {$rows}×{$columns}: ".$reason);
        };
        if (PHP_INT_SIZE !== 8) {
            $fail('64-bit integers are required.');
        }
        if ($rows === 0) {
            return [];
        }
        if (! array_is_list($costs) || $rows > $columns || $rows >= intdiv(self::BIG - 4, 4)) {
            $fail('Expected a dense rectangular matrix with at least as many columns as rows.');
        }

        // This envelope bounds all potential arithmetic well below PHP_INT_MAX.
        $maxShift = intdiv(self::BIG - 1, 4 * $rows + 4);
        $matrix = [];
        foreach ($costs as $row) {
            if (! is_array($row) || ! array_is_list($row) || count($row) !== $columns) {
                $fail('Unequal row widths or sparse columns.');
            }
            $minimum = self::BIG;
            foreach ($row as $cost) {
                if (! is_int($cost) || $cost <= -self::BIG || $cost > self::BIG) {
                    $fail('Cost outside the integer range.');
                }
                $minimum = min($minimum, $cost);
            }
            if ($minimum === self::BIG) {
                $fail('Row has no finite choice.');
            }
            $shifted = [];
            foreach ($row as $cost) {
                $shift = $cost === self::BIG ? self::BIG : $cost - $minimum;
                if ($cost !== self::BIG && $shift > $maxShift) {
                    $fail('Row shift exceeds the safe potential bound.');
                }
                $shifted[] = $shift;
            }
            $matrix[] = $shifted;
        }

        $rowPotential = array_fill(0, $rows + 1, 0);
        $columnPotential = $matchedRow = $previous = array_fill(0, $columns + 1, 0);
        for ($i = 1; $i <= $rows; $i++) {
            $matchedRow[0] = $i;
            $column = 0;
            $best = array_fill(0, $columns + 1, self::BIG);
            $used = array_fill(0, $columns + 1, false);
            do {
                $used[$column] = true;
                $row = $matchedRow[$column];
                $delta = self::BIG;
                $next = 0;
                for ($j = 1; $j <= $columns; $j++) {
                    if ($used[$j]) {
                        continue;
                    }
                    $cost = $matrix[$row - 1][$j - 1];
                    if ($cost !== self::BIG) {
                        $reduced = $cost - $rowPotential[$row] - $columnPotential[$j];
                        if ($reduced < 0 || $reduced >= self::BIG) {
                            $fail('Reduced cost exceeds the safe potential bound.');
                        }
                        if ($reduced < $best[$j]) {
                            $best[$j] = $reduced;
                            $previous[$j] = $column;
                        }
                    }
                    if ($best[$j] < $delta) {
                        $delta = $best[$j];
                        $next = $j;
                    }
                }
                if ($delta === self::BIG) {
                    $fail('No complete feasible matching.');
                }
                for ($j = 0; $j <= $columns; $j++) {
                    if ($used[$j]) {
                        $rowPotential[$matchedRow[$j]] += $delta;
                        $columnPotential[$j] -= $delta;
                        if ($rowPotential[$matchedRow[$j]] >= self::BIG || $columnPotential[$j] <= -self::BIG) {
                            $fail('Potential exceeds the safe bound.');
                        }
                    } elseif ($best[$j] !== self::BIG) {
                        $best[$j] -= $delta;
                    }
                }
                $column = $next;
            } while ($matchedRow[$column] !== 0);

            do {
                $prior = $previous[$column];
                $matchedRow[$column] = $matchedRow[$prior];
                $column = $prior;
            } while ($column !== 0);
        }

        $result = array_fill(0, $rows, -1);
        for ($j = 1; $j <= $columns; $j++) {
            if ($matchedRow[$j] !== 0) {
                $row = $matchedRow[$j] - 1;
                if ($costs[$row][$j - 1] === self::BIG) {
                    $fail('Matched a forbidden edge.');
                }
                $result[$row] = $j - 1;
            }
        }
        if (in_array(-1, $result, true)) {
            $fail('Assignment omitted a row.');
        }

        return $result;
    }
}
