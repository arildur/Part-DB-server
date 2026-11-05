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
use App\Entity\Parts\PartKind;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class PartKindValidationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    private function hasKindViolation(Part $part): bool
    {
        foreach ($this->validator->validate($part) as $violation) {
            if ($violation->getPropertyPath() === 'kind') {
                return true;
            }
        }

        return false;
    }

    public function testUnsavedPartCanHaveAnyKind(): void
    {
        foreach (PartKind::cases() as $kind) {
            $this->assertFalse($this->hasKindViolation((new Part())->setKind($kind)));
        }
    }

    public function testSavedPartAcceptsTheSameKind(): void
    {
        $part = $this->em->find(Part::class, 1);
        $part->setKind(PartKind::MANUFACTURING);

        $this->assertFalse($this->hasKindViolation($part));
    }

    public function testSavedPartRefusesAnotherKind(): void
    {
        $part = $this->em->find(Part::class, 1);
        $part->setKind(PartKind::META);

        $this->assertTrue($this->hasKindViolation($part));
    }

    /**
     * Right after a flush, Doctrine's original data for the part can hold the value of the enum, not the enum.
     */
    public function testPartJustSavedInThisRequestAcceptsTheSameKindAndRefusesAnother(): void
    {
        $this->em->beginTransaction();
        try {
            $part = new Part();
            $part->setName('Kind test');
            $part->setCategory($this->em->find(Category::class, 1));
            $this->em->persist($part);
            $this->em->flush();

            $this->assertFalse($this->hasKindViolation($part));

            $part->setKind(PartKind::META);
            $this->assertTrue($this->hasKindViolation($part));
        } finally {
            $this->em->rollback();
        }
    }

    public function testCloneOfASavedPartCanHaveAnotherKind(): void
    {
        $clone = clone $this->em->find(Part::class, 1);
        $clone->setKind(PartKind::META);

        $this->assertFalse($this->hasKindViolation($clone));
    }

    public function testMetaPartIsRefusedInABomForNow(): void
    {
        $entry = new ProjectBOMEntry();
        $entry->setPart((new Part())->setKind(PartKind::META));
        $entry->setQuantity(1);

        $paths = [];
        foreach ($this->validator->validate($entry) as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        $this->assertContains('part', $paths);
    }
}
