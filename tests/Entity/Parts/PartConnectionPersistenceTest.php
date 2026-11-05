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

use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartConnectionStatus;
use App\Entity\Parts\PartKind;
use App\Entity\Parts\PartLot;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PartConnectionPersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function part(PartKind $kind, string $name): Part
    {
        $part = (new Part())->setKind($kind);
        $part->setName($name);
        $part->setCategory($this->em->find(Category::class, 1));
        $this->em->persist($part);

        return $part;
    }

    /**
     * @return array{Part, Part, PartConnection}
     */
    private function connectedPair(): array
    {
        $meta = $this->part(PartKind::META, 'Meta');
        $part = $this->part(PartKind::MANUFACTURING, 'Part');
        $lot = new PartLot();
        $lot->setAmount(100);
        $part->addPartLot($lot);
        $connection = (new PartConnection())->setConnectedPart($part);
        $meta->addPartConnection($connection);
        $this->em->flush();

        return [$meta, $part, $connection];
    }

    private function connectionCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM part_connections');
    }

    public function testASavedConnectionKeepsItsFields(): void
    {
        [$meta, $part, $connection] = $this->connectedPair();
        $connection->setStatus(PartConnectionStatus::DO_NOT_USE)->setNote('Note');
        $this->em->flush();
        $id = $connection->getID();
        $this->em->clear();

        $loaded = $this->em->find(PartConnection::class, $id);
        $this->assertSame($meta->getID(), $loaded->getMetaPart()->getID());
        $this->assertSame($part->getID(), $loaded->getConnectedPart()->getID());
        $this->assertSame(1, $loaded->getPosition());
        $this->assertSame(PartConnectionStatus::DO_NOT_USE, $loaded->getStatus());
        $this->assertSame('Note', $loaded->getNote());
        $this->assertNotNull($loaded->getAddedDate());
    }

    public function testAConnectionMadeOnlyThroughSettersGetsTheNextPosition(): void
    {
        [$meta] = $this->connectedPair();
        $other = $this->part(PartKind::MANUFACTURING, 'Other');
        $this->em->flush();
        $this->em->clear();

        $connection = (new PartConnection())
            ->setMetaPart($this->em->find(Part::class, $meta->getID()))
            ->setConnectedPart($this->em->find(Part::class, $other->getID()));
        $this->em->persist($connection);
        $this->em->flush();

        $this->assertSame(2, $connection->getPosition());
    }

    public function testDeletingTheConnectedPartLeavesTheMetaPart(): void
    {
        [$meta, $part] = $this->connectedPair();
        $metaId = $meta->getID();
        $this->assertSame(1, $this->connectionCount());

        $this->em->remove($part);
        $this->em->flush();
        $this->em->clear();

        $this->assertSame(0, $this->connectionCount());
        $this->assertCount(0, $this->em->find(Part::class, $metaId)->getPartConnections());
    }

    public function testDeletingTheMetaPartLeavesTheConnectedPartAndItsStock(): void
    {
        [$meta, $part] = $this->connectedPair();
        $partId = $part->getID();

        $this->em->remove($meta);
        $this->em->flush();
        $this->em->clear();

        $this->assertSame(0, $this->connectionCount());
        $loaded = $this->em->find(Part::class, $partId);
        $this->assertNotNull($loaded);
        $this->assertSame(100.0, $loaded->getPartLots()->first()->getAmount());
    }

    public function testRemovingAConnectionLeavesBothPartsAndStock(): void
    {
        [$meta, $part, $connection] = $this->connectedPair();
        $metaId = $meta->getID();
        $partId = $part->getID();

        $meta->removePartConnection($connection);
        $this->em->flush();
        $this->em->clear();

        $this->assertSame(0, $this->connectionCount());
        $this->assertNotNull($this->em->find(Part::class, $metaId));
        $this->assertSame(100.0, $this->em->find(Part::class, $partId)->getPartLots()->first()->getAmount());
    }

    public function testCloningAMetaPartCopiesItsConnections(): void
    {
        [$meta, $part] = $this->connectedPair();
        $second = $this->part(PartKind::MANUFACTURING, 'Second');
        $meta->addPartConnection((new PartConnection())->setConnectedPart($second)->setNote('Note'));
        $this->em->flush();
        $metaId = $meta->getID();

        $clone = clone $meta;
        $clone->setName('Clone');
        $this->em->persist($clone);
        $this->em->flush();
        $cloneId = $clone->getID();
        $this->em->clear();

        $loaded = $this->em->find(Part::class, $cloneId);
        $this->assertSame(PartKind::META, $loaded->getKind());
        $this->assertCount(2, $loaded->getPartConnections());
        foreach ($loaded->getPartConnections() as $connection) {
            $this->assertSame($cloneId, $connection->getMetaPart()->getID());
        }
        $this->assertCount(2, $this->em->find(Part::class, $metaId)->getPartConnections(), 'The original keeps its own');
    }

    public function testCloningAConnectedPartCreatesNoConnection(): void
    {
        [, $part] = $this->connectedPair();
        $this->em->flush();
        $this->em->clear();

        $clone = clone $this->em->find(Part::class, $part->getID());
        $clone->setName('Clone');
        $this->em->persist($clone);
        $this->em->flush();

        $this->assertCount(0, $clone->getConnectionsAsPart());
        $this->assertSame(1, $this->connectionCount());
    }
}
