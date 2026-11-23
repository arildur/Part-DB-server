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

use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class PartConnectionValidationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    private function part(PartKind $kind, string $name): Part
    {
        $part = (new Part())->setKind($kind);
        $part->setName($name);
        $part->setCategory($this->em->find(Category::class, 1));
        $this->em->persist($part);

        return $part;
    }

    /** @return list<string> */
    private function violatedPaths(object $object): array
    {
        $paths = [];
        foreach ($this->validator->validate($object) as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        return $paths;
    }

    public function testOnlyMetaToManufacturingIsAllowed(): void
    {
        $meta = (new Part())->setKind(PartKind::META);
        $part = new Part();
        $check = fn (Part $a, Part $b): array => array_values(array_intersect(
            $this->violatedPaths((new PartConnection())->setMetaPart($a)->setConnectedPart($b)),
            ['meta_part', 'connected_part']
        ));

        $this->assertSame([], $check($meta, $part));
        $this->assertSame(['connected_part'], $check($meta, (new Part())->setKind(PartKind::META)));
        $this->assertSame(['meta_part'], $check($part, new Part()));
        $this->assertSame(['meta_part', 'connected_part'], $check($part, (new Part())->setKind(PartKind::META)));
    }

    public function testTwoNewConnectionsForOnePairAreRefusedTogether(): void
    {
        $meta = $this->part(PartKind::META, 'Meta');
        $part = $this->part(PartKind::MANUFACTURING, 'Part');
        $this->em->flush();
        $meta->addPartConnection((new PartConnection())->setConnectedPart($part));
        $meta->addPartConnection((new PartConnection())->setConnectedPart($part));

        $this->assertContains('part_connections[1].connected_part', $this->pathsWithPrefix($meta, 'part_connections'));
    }

    /** @return list<string> */
    private function pathsWithPrefix(object $object, string $prefix): array
    {
        return array_values(array_filter($this->violatedPaths($object), static fn (string $p): bool => str_starts_with($p, $prefix)));
    }

    public function testAConnectionForAPairAlreadySavedIsRefused(): void
    {
        $meta = $this->part(PartKind::META, 'Meta');
        $part = $this->part(PartKind::MANUFACTURING, 'Part');
        $meta->addPartConnection((new PartConnection())->setConnectedPart($part));
        $this->em->flush();

        $second = (new PartConnection())->setMetaPart($meta)->setConnectedPart($part);

        $this->assertNotSame([], $this->violatedPaths($second));
    }

    public function testRemovingAndAddingOnePairInOneSaveIsRefusedBeforeTheDatabase(): void
    {
        $meta = $this->part(PartKind::META, 'Meta');
        $part = $this->part(PartKind::MANUFACTURING, 'Part');
        $saved = (new PartConnection())->setConnectedPart($part);
        $meta->addPartConnection($saved);
        $this->em->flush();

        $meta->removePartConnection($saved);
        $again = (new PartConnection())->setConnectedPart($part);
        $meta->addPartConnection($again);

        $this->assertNotSame([], $this->pathsWithPrefix($meta, 'part_connections'));
    }

    public function testTheEndsOfASavedConnectionAreFixed(): void
    {
        $meta = $this->part(PartKind::META, 'Meta');
        $otherMeta = $this->part(PartKind::META, 'Other meta');
        $part = $this->part(PartKind::MANUFACTURING, 'Part');
        $otherPart = $this->part(PartKind::MANUFACTURING, 'Other part');
        $connection = (new PartConnection())->setConnectedPart($part);
        $meta->addPartConnection($connection);
        $this->em->flush();

        $connection->setPosition(9)->setNote('Fine to change');
        $this->assertSame([], $this->violatedPaths($connection));

        $connection->setConnectedPart($otherPart);
        $this->assertContains('connected_part', $this->violatedPaths($connection));
        $connection->setConnectedPart($part);

        $connection->setMetaPart($otherMeta);
        $this->assertContains('meta_part', $this->violatedPaths($connection));
    }

    public function testPositionMustBePositive(): void
    {
        $connection = (new PartConnection())->setMetaPart((new Part())->setKind(PartKind::META))->setConnectedPart(new Part())->setPosition(0);

        $this->assertContains('position', $this->violatedPaths($connection));
    }
}
