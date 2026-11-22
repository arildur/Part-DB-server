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
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return iterable<string, array{PartConnectionStatus, PartConnectionStatus, PartConnectionStatus}> */
    public static function statusPairs(): iterable
    {
        yield 'use up over approved' => [PartConnectionStatus::APPROVED, PartConnectionStatus::USE_UP, PartConnectionStatus::USE_UP];
        yield 'use up kept against approved' => [PartConnectionStatus::USE_UP, PartConnectionStatus::APPROVED, PartConnectionStatus::USE_UP];
        yield 'under evaluation over use up' => [PartConnectionStatus::USE_UP, PartConnectionStatus::UNDER_EVALUATION, PartConnectionStatus::UNDER_EVALUATION];
        yield 'under evaluation kept against use up' => [PartConnectionStatus::UNDER_EVALUATION, PartConnectionStatus::USE_UP, PartConnectionStatus::UNDER_EVALUATION];
        yield 'do not use over under evaluation' => [PartConnectionStatus::UNDER_EVALUATION, PartConnectionStatus::DO_NOT_USE, PartConnectionStatus::DO_NOT_USE];
        yield 'do not use kept against under evaluation' => [PartConnectionStatus::DO_NOT_USE, PartConnectionStatus::UNDER_EVALUATION, PartConnectionStatus::DO_NOT_USE];
    }

    #[DataProvider('statusPairs')]
    public function testTheStricterStatusIsKept(PartConnectionStatus $targetStatus, PartConnectionStatus $otherStatus, PartConnectionStatus $expected): void
    {
        //Two manufacturing parts under the same meta part
        $meta = $this->part(PartKind::META, 'Meta');
        $a = $this->part(PartKind::MANUFACTURING, 'A');
        $b = $this->part(PartKind::MANUFACTURING, 'B');
        $this->connect($meta, $a, 1, $targetStatus);
        $this->connect($meta, $b, 2, $otherStatus);
        //Two meta parts over the same manufacturing part
        $target = $this->part(PartKind::META, 'Target');
        $other = $this->part(PartKind::META, 'Other');
        $shared = $this->part(PartKind::MANUFACTURING, 'Shared');
        $this->connect($target, $shared, 1, $targetStatus);
        $this->connect($other, $shared, 1, $otherStatus);
        $this->em->flush();
        $ids = array_map(static fn (Part $part): ?int => $part->getID(), [$a, $b, $target, $other]);
        $this->em->clear();

        $merged = $this->mergeAndReload($this->em->find(Part::class, $ids[0]), $this->em->find(Part::class, $ids[1]));
        $this->assertSame($expected, $merged->getConnectionsAsPart()->first()->getStatus(), 'Manufacturing parts');

        $merged = $this->mergeAndReload($this->em->find(Part::class, $ids[2]), $this->em->find(Part::class, $ids[3]));
        $this->assertSame($expected, $merged->getPartConnections()->first()->getStatus(), 'Meta parts');
    }
}
