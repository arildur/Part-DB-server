<?php
/**
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 * Copyright (C) 2019 - 2026 Jan Böhmer (https://github.com/jbtronics)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace App\Services\ProjectSystem;

/**
 * Shares the stock of the lots between the entries of a BOM, where several entries can reach the same lot.
 * It works on plain keys and amounts, so it needs no services and no database.
 *
 * Each entry takes from the first of its lots with stock left. When none has, it looks for a path from itself to a lot,
 * from that lot to an entry holding some of it, and on, ending at a lot with stock left, and moves the amount along it.
 * When no such path exists, no division of the stock can cover the entry.
 *
 * @see \App\Tests\Services\ProjectSystem\BomStockAllocatorTest
 * @phpstan-type BomEntries array<int, array{need: float, lots: list<int>}>
 */
final class BomStockAllocator
{
    /**
     * Below this an amount counts as zero, both as stock left and as short. Amounts carry float residue, such as
     * 0.3 - 0.2 = 0.09999999999999998, and every move being larger than this is what makes the search end.
     */
    public const TOLERANCE = 1e-9;

    private const AMOUNT_PRECISION = 10;

    /**
     * @param  BomEntries  $entries  What each entry needs and the lots it reaches, both in the order of priority
     * @param  array<int, float>  $stock  The amount of each lot
     * @return array{amounts: array<int, array<int, float>>, short: array<int, float>} The amount each entry takes from
     * each lot, and what each entry that is not covered still lacks
     */
    public static function allocate(array $entries, array $stock): array
    {
        $left = $stock;
        $amounts = array_fill_keys(array_keys($entries), []);
        $short = [];

        foreach ($entries as $entry => $wanted) {
            $remaining = $wanted['need'];
            while ($remaining > self::TOLERANCE) {
                $moved = self::moveAlongShortestPath($entry, $remaining, $entries, $amounts, $left);
                if ($moved <= self::TOLERANCE) {
                    break;
                }
                $remaining -= $moved;
            }
            if ($remaining > self::TOLERANCE) {
                $short[$entry] = round($remaining, self::AMOUNT_PRECISION);
            }
        }

        foreach ($amounts as $entry => $lots) {
            foreach ($lots as $lot => $amount) {
                $amounts[$entry][$lot] = round($amount, self::AMOUNT_PRECISION);
                if ($amounts[$entry][$lot] <= 0.0) {
                    unset($amounts[$entry][$lot]);
                }
            }
        }

        return ['amounts' => $amounts, 'short' => $short];
    }

    /**
     * Returns the largest number of builds that the stock covers.
     * @param  array<int, array{quantity: float, lots: list<int>}>  $entries
     * @param  array<int, float>  $stock
     * @param  int  $upperLimit  A number of builds known not to be exceeded
     */
    public static function maximumBuilds(array $entries, array $stock, int $upperLimit): int
    {
        $low = 0;
        $high = $upperLimit;
        while ($low < $high) {
            $middle = (int) (($low + $high + 1) / 2);
            $needs = array_map(static fn (array $entry): array => [
                'need' => round($entry['quantity'] * $middle, self::AMOUNT_PRECISION),
                'lots' => $entry['lots'],
            ], $entries);

            if (self::allocate($needs, $stock)['short'] === []) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return $low;
    }

    /**
     * Breadth first, so the entry stays as near as possible to the lots it prefers.
     * @param  BomEntries  $entries
     * @param  array<int, array<int, float>>  $amounts
     * @param  array<int, float>  $left
     * @return float The amount moved, which is 0 if no path exists
     */
    private static function moveAlongShortestPath(int $start, float $wanted, array $entries, array &$amounts, array &$left): float
    {
        $cameFromEntry = []; //Lot => the entry it was reached from
        $cameFromLot = [$start => null]; //Entry => the lot it was reached from
        $queue = [$start];
        $end = null;

        while ($queue !== [] && $end === null) {
            $entry = array_shift($queue);
            foreach ($entries[$entry]['lots'] as $lot) {
                if (array_key_exists($lot, $cameFromEntry)) {
                    continue;
                }
                $cameFromEntry[$lot] = $entry;
                if (($left[$lot] ?? 0.0) > self::TOLERANCE) {
                    $end = $lot;
                    break;
                }
                foreach ($amounts as $holder => $held) {
                    if (($held[$lot] ?? 0.0) > self::TOLERANCE && !array_key_exists($holder, $cameFromLot)) {
                        $cameFromLot[$holder] = $lot;
                        $queue[] = $holder;
                    }
                }
            }
        }

        if ($end === null) {
            return 0.0;
        }

        //Walk back to find how much the whole path can carry
        $moved = min($wanted, $left[$end]);
        $path = [];
        for ($lot = $end; $lot !== null; $lot = $cameFromLot[$cameFromEntry[$lot]]) {
            $path[] = [$cameFromEntry[$lot], $lot];
            $released = $cameFromLot[$cameFromEntry[$lot]];
            if ($released !== null) {
                $moved = min($moved, $amounts[$cameFromEntry[$lot]][$released]);
            }
        }

        if ($moved <= self::TOLERANCE) {
            return 0.0;
        }

        foreach ($path as [$entry, $lot]) {
            $amounts[$entry][$lot] = ($amounts[$entry][$lot] ?? 0.0) + $moved;
            $released = $cameFromLot[$entry];
            if ($released !== null) {
                $amounts[$entry][$released] -= $moved;
            }
        }
        $left[$end] -= $moved;

        return $moved;
    }
}
