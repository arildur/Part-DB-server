<?php
/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2026 Jan Böhmer (https://github.com/jbtronics)
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

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartKind;
use App\Repository\PartRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PartRepositoryMetaPartNameTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PartRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = $this->em->getRepository(Part::class);
    }

    private function part(string $name, PartKind $kind = PartKind::META): Part
    {
        $part = (new Part())->setKind($kind);
        $part->setName($name);
        $part->setCategory($this->em->find(Category::class, 1));
        $this->em->persist($part);

        return $part;
    }

    public function testOtherMetaPartsOfTheSameNameAreFoundIgnoringCaseAndOuterSpaces(): void
    {
        $part = $this->part('Name meta');
        $same = $this->part('Name meta');
        $otherCase = $this->part('  NAME META  ');
        $this->part('Name meta 2');
        $this->part('Name  meta');
        $this->part('Name meta', PartKind::MANUFACTURING);
        $this->em->flush();

        $this->assertSame([$same, $otherCase], $this->repo->findOtherMetaPartsWithName($part));
    }

    public function testAnUnsavedMetaPartFindsAllOfItsName(): void
    {
        $saved = $this->part('Unsaved meta');
        $this->em->flush();

        $this->assertSame([$saved], $this->repo->findOtherMetaPartsWithName((new Part())->setKind(PartKind::META)->setName('unsaved meta')));
    }

    public function testAManufacturingPartIsNeverWarnedAbout(): void
    {
        $this->part('Shared name');
        $manufacturing = $this->part('Shared name', PartKind::MANUFACTURING);
        $this->em->flush();

        $this->assertSame([], $this->repo->findOtherMetaPartsWithName($manufacturing));
    }
}
