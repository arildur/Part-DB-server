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

declare(strict_types=1);namespace App\Tests\Entity\Parts;

use App\Entity\Contracts\TimeStampableInterface;
use App\Entity\Parts\Category;
use App\Entity\Parts\Footprint;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartConnectionStatus;
use App\Entity\Parts\PartKind;
use PHPUnit\Framework\TestCase;

final class PartConnectionTest extends TestCase
{
    private function connection(int $id, ?int $position, PartConnectionStatus $status = PartConnectionStatus::APPROVED): PartConnection
    {
        $connection = new class($id) extends PartConnection {
            public function __construct(private readonly int $fixedId)
            {
            }

            public function getID(): ?int
            {
                return $this->fixedId;
            }
        };

        return $connection->setPosition($position)->setStatus($status);
    }

    public function testTheDifferencesAreTheCategoryAndFootprintThatDoNotMatch(): void
    {
        $resistors = (new Category())->setName('Resistors');
        $capacitors = (new Category())->setName('Capacitors');
        $small = (new Footprint())->setName('0603');
        $large = (new Footprint())->setName('0805');
        $meta = (new Part())->setKind(PartKind::META)->setCategory($resistors)->setFootprint($small);
        $part = (new Part())->setCategory($resistors)->setFootprint($small);
        $connection = (new PartConnection())->setMetaPart($meta)->setConnectedPart($part);
        $this->assertSame([], $connection->getDifferences(), 'All match');

        $part->setCategory($capacitors);
        $this->assertSame(['category'], $connection->getDifferences());
        $this->assertTrue($connection->hasDifferentCategory());

        $part->setFootprint($large);
        $this->assertSame(['category', 'footprint'], $connection->getDifferences(), 'In a fixed order');

        //A footprint only counts when both have one
        $part->setCategory($resistors)->setFootprint(null);
        $this->assertSame([], $connection->getDifferences());
        $meta->setFootprint(null);
        $part->setFootprint($large);
        $this->assertSame([], $connection->getDifferences());
    }

    public function testFieldsReadBack(): void
    {
        $meta = (new Part())->setKind(PartKind::META);
        $part = new Part();
        $connection = new PartConnection();
        $this->assertInstanceOf(TimeStampableInterface::class, $connection);
        $this->assertSame(PartConnectionStatus::APPROVED, $connection->getStatus());
        $this->assertSame('', $connection->getNote());

        $connection->setMetaPart($meta)->setConnectedPart($part)->setPosition(2)->setNote('Second source')
            ->setStatus(PartConnectionStatus::DO_NOT_USE);

        $this->assertSame($meta, $connection->getMetaPart());
        $this->assertSame($part, $connection->getConnectedPart());
        $this->assertSame(2, $connection->getPosition());
        $this->assertSame('Second source', $connection->getNote());
        $this->assertFalse($connection->isUsable());
    }

    public function testUsableFollowsTheStatus(): void
    {
        foreach (PartConnectionStatus::cases() as $status) {
            $this->assertSame($status->isUsable(), (new PartConnection())->setStatus($status)->isUsable(), $status->value);
        }
    }

    public function testAPartCanHaveItsOwnPositionAndStatusUnderEachMetaPart(): void
    {
        $part = new Part();
        $one = (new PartConnection())->setMetaPart((new Part())->setKind(PartKind::META))->setConnectedPart($part)->setPosition(1);
        $two = (new PartConnection())->setMetaPart((new Part())->setKind(PartKind::META))->setConnectedPart($part)->setPosition(5)
            ->setStatus(PartConnectionStatus::DO_NOT_USE);

        $this->assertTrue($one->isUsable());
        $this->assertSame(1, $one->getPosition());
        $this->assertFalse($two->isUsable());
        $this->assertSame(5, $two->getPosition());
    }

    public function testOrderIsUsableFirstThenPositionThenId(): void
    {
        $meta = (new Part())->setKind(PartKind::META);
        $connections = [
            'retired' => $this->connection(1, 1, PartConnectionStatus::DO_NOT_USE),
            'evaluated' => $this->connection(3, 2, PartConnectionStatus::UNDER_EVALUATION),
            'later' => $this->connection(2, 3),
            'usedUp' => $this->connection(5, 4, PartConnectionStatus::USE_UP),
            'tieHighId' => $this->connection(9, 2),
            'tieLowId' => $this->connection(4, 2),
        ];
        foreach ($connections as $connection) {
            $meta->addPartConnection($connection);
        }

        $expected = [$connections['tieLowId'], $connections['tieHighId'], $connections['later'], $connections['usedUp'],
            $connections['retired'], $connections['evaluated']];
        $this->assertSame($expected, $meta->getOrderedConnections());
        $this->assertSame($expected, $meta->getOrderedConnections());
    }

    public function testNewConnectionWithNoPositionGoesLast(): void
    {
        $meta = (new Part())->setKind(PartKind::META);
        $meta->addPartConnection($this->connection(1, 7));
        $meta->addPartConnection($this->connection(2, 3));

        $new = (new PartConnection())->setMetaPart($meta);
        $new->setNextFreePositionIfEmpty();

        $this->assertSame(8, $new->getPosition());
    }

    public function testFirstConnectionGetsPositionOne(): void
    {
        $new = (new PartConnection())->setMetaPart((new Part())->setKind(PartKind::META));
        $new->setNextFreePositionIfEmpty();

        $this->assertSame(1, $new->getPosition());
    }

    public function testAGivenPositionIsKept(): void
    {
        $new = (new PartConnection())->setMetaPart((new Part())->setKind(PartKind::META))->setPosition(4);
        $new->setNextFreePositionIfEmpty();

        $this->assertSame(4, $new->getPosition());
    }

    public function testNameIsThatOfTheConnectedPart(): void
    {
        $part = new Part();
        $part->setName('RC0805');

        $this->assertSame('RC0805', (new PartConnection())->setConnectedPart($part)->getName());
        $this->assertSame('', (new PartConnection())->getName());
    }
}
