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

declare(strict_types=1);namespace App\Tests\Services\EntityMergers\Mergers;

use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartConnectionStatus;
use App\Entity\Parts\PartKind;
use App\Services\EntityMergers\Mergers\PartMerger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Connections look right in memory while a flush can fail on cascade or orphan removal, so this saves and reloads them.
 */
final class PartMergerConnectionPersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PartMerger $merger;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->merger = self::getContainer()->get(PartMerger::class);
    }

    private function part(PartKind $kind, string $name): Part
    {
        $part = (new Part())->setKind($kind);
        $part->setName($name);
        $part->setCategory($this->em->find(Category::class, 1));
        $this->em->persist($part);

        return $part;
    }

    private function connect(Part $meta, Part $part, int $position, PartConnectionStatus $status = PartConnectionStatus::APPROVED): void
    {
        $meta->addPartConnection((new PartConnection())->setConnectedPart($part)->setPosition($position)->setStatus($status));
    }

    /** Merges the way the controller does: changes the target, removes the other part, and flushes. */
    private function mergeAndReload(Part $target, Part $other): Part
    {
        $this->merger->merge($target, $other);
        $this->em->remove($other);
        $this->em->flush();
        $id = $target->getID();
        $this->em->clear();

        return $this->em->find(Part::class, $id);
    }

    public function testPartsOfDifferentKindsAreRefusedBeforeAnythingChanges(): void
    {
        $meta = (new Part())->setKind(PartKind::META);
        $meta->setName('Meta');
        $part = new Part();
        $part->setName('Part');
        $part->setManufacturerProductNumber('X1');

        foreach ([[$meta, $part], [$part, $meta]] as [$target, $other]) {
            $this->assertFalse($this->merger->canMerge($target, $other));
            try {
                $this->merger->merge($target, $other);
                $this->fail('The merge should have been refused');
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertSame('Meta', $meta->getName());
        $this->assertSame('', $meta->getManufacturerProductNumber());
        $this->assertSame('Part', $part->getName());
    }

    public function testMergingAManufacturingPartKeepsItsConnections(): void
    {
        $onlyB = $this->part(PartKind::META, 'Only B');
        $both = $this->part(PartKind::META, 'Both');
        $a = $this->part(PartKind::MANUFACTURING, 'A');
        $b = $this->part(PartKind::MANUFACTURING, 'B');
        $this->connect($onlyB, $b, 3);
        $this->connect($both, $a, 5);
        $this->connect($both, $b, 2, PartConnectionStatus::DO_NOT_USE);
        $this->em->flush();
        $bothId = $both->getID();
        [$aId, $bId] = [$a->getID(), $b->getID()];
        $this->em->clear();

        $merged = $this->mergeAndReload($this->em->find(Part::class, $aId), $this->em->find(Part::class, $bId));

        $connections = $merged->getConnectionsAsPart();
        $this->assertCount(2, $connections, 'One connection per meta part');
        $byMeta = [];
        foreach ($connections as $connection) {
            $byMeta[$connection->getMetaPart()->getName()] = $connection;
        }
        $this->assertSame(3, $byMeta['Only B']->getPosition());
        $this->assertSame(2, $byMeta['Both']->getPosition(), 'The lower position stays');
        $this->assertSame(PartConnectionStatus::DO_NOT_USE, $byMeta['Both']->getStatus(), 'Do not use wins');
        $this->assertCount(1, $this->em->find(Part::class, $bothId)->getPartConnections());
    }

    public function testMergingTwoMetaPartsCombinesTheirConnections(): void
    {
        $target = $this->part(PartKind::META, 'Target');
        $other = $this->part(PartKind::META, 'Other');
        $shared = $this->part(PartKind::MANUFACTURING, 'Shared');
        $onlyOther = $this->part(PartKind::MANUFACTURING, 'Only other');
        $this->connect($target, $shared, 4);
        $this->connect($other, $shared, 1, PartConnectionStatus::DO_NOT_USE);
        $this->connect($other, $onlyOther, 2);
        $this->em->flush();
        [$targetId, $otherId] = [$target->getID(), $other->getID()];
        $this->em->clear();

        $merged = $this->mergeAndReload($this->em->find(Part::class, $targetId), $this->em->find(Part::class, $otherId));

        $byName = [];
        foreach ($merged->getPartConnections() as $connection) {
            $byName[$connection->getConnectedPart()->getName()] = $connection;
        }
        $this->assertCount(2, $byName);
        $this->assertSame(PartConnectionStatus::DO_NOT_USE, $byName['Shared']->getStatus());
        $this->assertSame(1, $byName['Shared']->getPosition());
        $this->assertSame(2, $byName['Only other']->getPosition());
    }
}
