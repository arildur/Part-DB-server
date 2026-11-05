<?php

declare(strict_types=1);

/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2023 Jan Böhmer (https://github.com/jbtronics)
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as published
 *  by the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */
namespace App\Tests\Services\ProjectSystem;

use App\Services\ProjectSystem\BomStockAllocator;
use PHPUnit\Framework\TestCase;

final class BomStockAllocatorTest extends TestCase
{
    public function testWithNoSharedLotEachEntryTakesItsLotsInOrderAsToday(): void
    {
        $stock = [1 => 10.0, 2 => 20.0, 3 => 2.5, 4 => 0.0];
        $entries = [
            1 => ['need' => 20.0, 'lots' => [1, 2]],
            2 => ['need' => 7.5, 'lots' => [3]],
            3 => ['need' => 1.0, 'lots' => [4]],
            4 => ['need' => 1.0, 'lots' => []],
        ];

        //The numbers of the method it replaces, ProjectBuildRequest::initializeArray() at commit 3e715806
        $result = BomStockAllocator::allocate($entries, $stock);

        $this->assertSame([1 => 10.0, 2 => 10.0], $result['amounts'][1]);
        $this->assertSame([3 => 2.5], $result['amounts'][2]);
        $this->assertSame([], $result['amounts'][3]);
        $this->assertSame([2 => 5.0, 3 => 1.0, 4 => 1.0], $result['short']);
    }

    public function testTheWorkedExampleOfTheDesign(): void
    {
        $result = BomStockAllocator::allocate([
            10 => ['need' => 60.0, 'lots' => [201, 301, 401]],
            11 => ['need' => 60.0, 'lots' => [301]],
            12 => ['need' => 5.0, 'lots' => [501]],
        ], [201 => 10.0, 301 => 100.0, 401 => 40.0, 501 => 500.0]);

        $this->assertSame([201 => 10.0, 301 => 40.0, 401 => 10.0], $result['amounts'][10]);
        $this->assertSame([301 => 60.0], $result['amounts'][11]);
        $this->assertSame([501 => 5.0], $result['amounts'][12]);
        $this->assertSame([], $result['short']);
    }

    public function testTheCounterexampleOfTheReviewIsCovered(): void
    {
        $stock = [1 => 5.0, 2 => 10.0, 3 => 5.0];
        $result = BomStockAllocator::allocate([
            1 => ['need' => 5.0, 'lots' => [2, 1]],
            2 => ['need' => 5.0, 'lots' => [3, 1]],
            3 => ['need' => 10.0, 'lots' => [2, 3]],
        ], $stock);

        $this->assertSame([], $result['short']);
        $this->assertNoLotGoesOver($result['amounts'], $stock);
    }

    public function testOneShortLeavesOneEntryShortByOne(): void
    {
        $stock = [1 => 5.0, 2 => 10.0, 3 => 4.0];
        $result = BomStockAllocator::allocate([
            1 => ['need' => 5.0, 'lots' => [2, 1]],
            2 => ['need' => 5.0, 'lots' => [3, 1]],
            3 => ['need' => 10.0, 'lots' => [2, 3]],
        ], $stock);

        $this->assertSame([3 => 1.0], $result['short']);
        $this->assertNoLotGoesOver($result['amounts'], $stock);
    }

    public function testFloatResidueEndsAndIsCountedCorrectly(): void
    {
        $result = BomStockAllocator::allocate([
            1 => ['need' => 2.2, 'lots' => [1, 2]],
            2 => ['need' => 0.8, 'lots' => [1]],
        ], [1 => 1.6, 2 => 1.1]);
        $this->assertEqualsWithDelta(0.8, array_sum($result['amounts'][2]) + 0.3, 1e-9);
        $this->assertArrayHasKey(2, $result['short']);
        $this->assertEqualsWithDelta(0.3, array_sum($result['short']), 1e-9);

        $residue = 0.3 - 0.2;
        $this->assertSame(0.09999999999999998, $residue);
        $this->assertSame([], BomStockAllocator::allocate([1 => ['need' => 0.1, 'lots' => [1]]], [1 => $residue])['short']);

        $shared = BomStockAllocator::allocate([
            1 => ['need' => 0.1, 'lots' => [1]],
            2 => ['need' => 0.2, 'lots' => [1]],
        ], [1 => 0.3]);
        $this->assertSame([], $shared['short']);
        $this->assertSame(0.1, $shared['amounts'][1][1]);
        $this->assertSame(0.2, $shared['amounts'][2][1]);
    }

    public function testACountRoundsBeforeItFloors(): void
    {
        $this->assertSame(3, BomStockAllocator::maximumBuilds([
            1 => ['quantity' => 0.1, 'lots' => [1]],
            2 => ['quantity' => 0.0, 'lots' => [1]],
        ], [1 => 0.3], 3));
    }

    public function testTheSameBomInAnyOrderOfItsLotsStockGivesTheSameResult(): void
    {
        $entries = [
            1 => ['need' => 5.0, 'lots' => [2, 1]],
            2 => ['need' => 5.0, 'lots' => [3, 1]],
            3 => ['need' => 10.0, 'lots' => [2, 3]],
        ];
        $stock = [1 => 5.0, 2 => 10.0, 3 => 5.0];
        $first = BomStockAllocator::allocate($entries, $stock);

        for ($i = 0; $i < 10; $i++) {
            $shuffled = array_keys($stock);
            shuffle($shuffled);
            $this->assertEquals($first, BomStockAllocator::allocate($entries, array_replace(array_flip($shuffled), $stock)));
        }
    }

    public function testTwoEntriesOnOneLotAddUpToTheirOwnNeeds(): void
    {
        $need = round(0.26 * 11, 10);
        $result = BomStockAllocator::allocate([
            1 => ['need' => $need, 'lots' => [1]],
            2 => ['need' => $need, 'lots' => [1]],
        ], [1 => 10.0]);

        $this->assertSame(2.86, $result['amounts'][1][1]);
        $this->assertSame(2.86, $result['amounts'][2][1]);
    }

    public function testTheCountSharesStockBetweenEntries(): void
    {
        $bom = [
            1 => ['quantity' => 80.0, 'lots' => [1, 2]],
            2 => ['quantity' => 80.0, 'lots' => [1]],
        ];
        $this->assertSame(0, BomStockAllocator::maximumBuilds($bom, [1 => 100.0, 2 => 40.0], 1));

        $bom = [
            1 => ['quantity' => 60.0, 'lots' => [1, 2]],
            2 => ['quantity' => 60.0, 'lots' => [1]],
        ];
        $this->assertSame(1, BomStockAllocator::maximumBuilds($bom, [1 => 100.0, 2 => 40.0], 1));
    }

    /**
     * @param  array<int, array<int, float>>  $amounts
     * @param  array<int, float>  $stock
     */
    private function assertNoLotGoesOver(array $amounts, array $stock): void
    {
        foreach ($stock as $lot => $held) {
            $this->assertLessThanOrEqual($held + 1e-9, array_sum(array_column($amounts, $lot)));
        }
    }
}
