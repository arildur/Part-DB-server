<?php
/**
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 * Copyright (C) 2019 - 2022 Jan Böhmer (https://github.com/jbtronics)
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

declare(strict_types=1);namespace App\Tests\Entity\Parts;

use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartConnectionStatus;
use App\Entity\Parts\PartKind;
use App\Entity\Parts\PartLot;
use PHPUnit\Framework\TestCase;

final class PartStockTest extends TestCase
{
    private function lot(float $amount, bool $unknown = false, ?string $expires = null): PartLot
    {
        $lot = new PartLot();
        $lot->setAmount($amount);
        $lot->setInstockUnknown($unknown);
        if ($expires !== null) {
            $lot->setExpirationDate(new \DateTimeImmutable($expires));
        }

        return $lot;
    }

    /** @param list<PartLot> $lots */
    private function part(array $lots = [], PartKind $kind = PartKind::MANUFACTURING): Part
    {
        $part = (new Part())->setKind($kind);
        foreach ($lots as $lot) {
            $part->addPartLot($lot);
        }

        return $part;
    }

    /** The stock tab example of the concept: a common bin, two boxes, and an expired lot. */
    private function exampleMetaPart(): Part
    {
        $meta = $this->part([$this->lot(200)], PartKind::META);
        $meta->addPartConnection((new PartConnection())->setConnectedPart($this->part([$this->lot(500)]))->setPosition(1));
        $meta->addPartConnection((new PartConnection())->setConnectedPart($this->part([$this->lot(100), $this->lot(40, expires: '-1 day')]))->setPosition(2));

        return $meta;
    }

    public function testTheExampleOfTheConcept(): void
    {
        $meta = $this->exampleMetaPart();

        $this->assertSame(800.0, $meta->getAmountSum());
        $this->assertSame(200.0, $meta->getOwnAmountSum());
        $this->assertSame(40.0, $meta->getExpiredAmountSum());
        $this->assertFalse($meta->isAmountUnknown());
        $this->assertCount(4, $meta->getLotsCountedInStock());
        $this->assertCount(1, $meta->getPartLots(), 'The stock forms rely on this being own lots only');
    }

    public function testAMinimumIsComparedWithTheTotal(): void
    {
        $meta = $this->exampleMetaPart();

        $meta->setMinAmount(500);
        $this->assertFalse($meta->isNotEnoughInstock());

        $meta->setMinAmount(900);
        $this->assertTrue($meta->isNotEnoughInstock());
    }

    public function testADoNotUseConnectionChangesNothing(): void
    {
        $meta = $this->exampleMetaPart();
        $retired = $this->part([$this->lot(1000), $this->lot(0, unknown: true), $this->lot(70, expires: '-1 day')]);
        $meta->addPartConnection((new PartConnection())->setConnectedPart($retired)->setPosition(3)->setStatus(PartConnectionStatus::DO_NOT_USE));

        $this->assertSame(800.0, $meta->getAmountSum());
        $this->assertSame(40.0, $meta->getExpiredAmountSum());
        $this->assertFalse($meta->isAmountUnknown());
    }

    public function testOnlyApprovedAndUseUpConnectionsCount(): void
    {
        $meta = $this->part([$this->lot(1)], PartKind::META);
        $amounts = [];
        foreach (PartConnectionStatus::cases() as $i => $status) {
            $amounts[$status->value] = 10 ** ($i + 1);
            $meta->addPartConnection((new PartConnection())->setConnectedPart($this->part([$this->lot($amounts[$status->value])]))
                ->setPosition($i + 1)->setStatus($status));
        }

        $this->assertSame(1.0 + $amounts['approved'] + $amounts['use_up'], $meta->getAmountSum());
        $this->assertCount(3, $meta->getLotsCountedInStock());
    }

    public function testAnUnknownAmountOfAConnectionNotUsableIsNotReported(): void
    {
        $meta = $this->part([], PartKind::META);
        $meta->addPartConnection((new PartConnection())->setConnectedPart($this->part([$this->lot(5, unknown: true)]))
            ->setStatus(PartConnectionStatus::UNDER_EVALUATION));
        $meta->addPartConnection((new PartConnection())->setConnectedPart($this->part([$this->lot(5, expires: '-1 day')]))
            ->setStatus(PartConnectionStatus::UNDER_EVALUATION));

        $this->assertFalse($meta->isAmountUnknown());
        $this->assertSame(0.0, $meta->getExpiredAmountSum());
    }

    public function testAnUnknownAmountInAConnectedPartIsReported(): void
    {
        $meta = $this->part([], PartKind::META);
        $meta->addPartConnection((new PartConnection())->setConnectedPart($this->part([$this->lot(5, unknown: true)])));

        $this->assertTrue($meta->isAmountUnknown());
        $this->assertSame(0.0, $meta->getAmountSum());
    }

    public function testAMetaPartWithNoConnectionsHasOnlyItsOwnStock(): void
    {
        $this->assertSame(10.0, $this->part([$this->lot(10)], PartKind::META)->getAmountSum());
        $this->assertSame(0.0, $this->part([], PartKind::META)->getAmountSum());
    }

    public function testOwnLotsOfAMetaPartCountOnlyForThatMetaPart(): void
    {
        $shared = new Part();
        $shared->addPartLot($this->lot(100));
        $one = $this->part([$this->lot(10)], PartKind::META);
        $two = $this->part([$this->lot(20)], PartKind::META);
        foreach ([$one, $two] as $meta) {
            $meta->addPartConnection((new PartConnection())->setConnectedPart($shared));
        }

        $this->assertSame(110.0, $one->getAmountSum());
        $this->assertSame(120.0, $two->getAmountSum());
        $this->assertSame(100.0, $shared->getAmountSum());
    }

    public function testAManufacturingPartIsAsBefore(): void
    {
        $part = $this->part([$this->lot(10), $this->lot(5, unknown: true), $this->lot(3, expires: '-1 day')]);

        $this->assertSame(10.0, $part->getAmountSum());
        $this->assertSame(10.0, $part->getOwnAmountSum());
        $this->assertSame(3.0, $part->getExpiredAmountSum());
        $this->assertTrue($part->isAmountUnknown());
    }

    public function testAManufacturingPartOffersItsOwnLotsForBuiltUnits(): void
    {
        $lots = [$this->lot(1), $this->lot(2, unknown: true)];

        $this->assertSame($lots, $this->part($lots)->getLotsForBuiltUnits());
    }

    public function testAMetaPartWithoutConnectionsOffersItsOwnLotsOnly(): void
    {
        $own = [$this->lot(1), $this->lot(2)];

        $this->assertSame($own, $this->part($own, PartKind::META)->getLotsForBuiltUnits());
        $this->assertSame([], $this->part([], PartKind::META)->getLotsForBuiltUnits());
    }

    public function testAMetaPartOffersTheLotsOfEveryConnectedPartWhateverTheStatus(): void
    {
        $own = $this->lot(1);
        $meta = $this->part([$own], PartKind::META);
        $byStatus = [];
        foreach (PartConnectionStatus::cases() as $i => $status) {
            $byStatus[$status->value] = [$this->lot(10 * ($i + 1)), $this->lot(0, unknown: true)];
            $meta->addPartConnection((new PartConnection())->setConnectedPart($this->part($byStatus[$status->value]))
                ->setPosition($i + 1)->setStatus($status));
        }

        //In the order of the connections: usable ones first, then by position
        $this->assertSame(array_merge([$own], $byStatus['approved'], $byStatus['use_up'], $byStatus['under_evaluation'], $byStatus['do_not_use']),
            $meta->getLotsForBuiltUnits());
    }
}
