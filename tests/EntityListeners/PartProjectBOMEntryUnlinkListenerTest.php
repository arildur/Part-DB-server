<?php

declare(strict_types=1);

/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2025 Jan Böhmer (https://github.com/jbtronics)
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

namespace App\Tests\EntityListeners;

use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use App\Exceptions\PartUsedInBomException;
use App\Settings\BehaviorSettings\PartDeletionSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The listener is the safety net behind the delete paths: with the setting on, a flush that would delete a part used in
 * a BOM fails before any SQL; with it off, the entries are unlinked and keep the name.
 */
final class PartProjectBOMEntryUnlinkListenerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PartDeletionSettings $settings;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->settings = self::getContainer()->get(PartDeletionSettings::class);
    }

    /**
     * @return array{0: Part, 1: Project, 2: ProjectBOMEntry}
     */
    private function partInABom(): array
    {
        $part = new Part();
        $part->setName('Listener part');
        $part->setCategory($this->em->find(Category::class, 1));
        $project = new Project();
        $project->setName('Listener project');
        $entry = (new ProjectBOMEntry())->setQuantity(1.0);
        $project->addBomEntry($entry);
        $part->addProjectBomEntry($entry);
        $this->em->persist($part);
        $this->em->persist($project);
        $this->em->flush();

        return [$part, $project, $entry];
    }

    public function testWithTheSettingOnTheFlushThrowsAndTheEntryStaysLinked(): void
    {
        $this->settings->refuseDeletingPartsUsedInBom = true;
        [$part, , $entry] = $this->partInABom();
        $partId = $part->getID();
        $entryId = $entry->getID();

        $this->em->remove($part);
        try {
            $this->em->flush();
            $this->fail('The flush deleted a part used in a BOM.');
        } catch (PartUsedInBomException $exception) {
            $this->assertSame(['Listener project'], $exception->getProjectNames());
            $this->assertStringContainsString('Listener part', $exception->getMessage());
        }
        $this->assertSame($part, $entry->getPart());

        $this->em->clear();
        $this->assertNotNull($this->em->find(Part::class, $partId));
        $this->assertSame($partId, $this->em->find(ProjectBOMEntry::class, $entryId)?->getPart()?->getID());
    }

    public function testAProjectDeletedTogetherWithItsPartPasses(): void
    {
        $this->settings->refuseDeletingPartsUsedInBom = true;
        [$part, $project] = $this->partInABom();
        $partId = $part->getID();

        $this->em->remove($project);
        $this->em->remove($part);
        $this->em->flush();

        $this->em->clear();
        $this->assertNull($this->em->find(Part::class, $partId));
    }

    public function testWithTheSettingOffTheEntryIsUnlinkedAndKeepsTheName(): void
    {
        $this->settings->refuseDeletingPartsUsedInBom = false;
        [$part, , $entry] = $this->partInABom();
        $entryId = $entry->getID();

        $this->em->remove($part);
        $this->em->flush();

        $this->em->clear();
        $entry = $this->em->find(ProjectBOMEntry::class, $entryId);
        $this->assertNull($entry->getPart());
        $this->assertSame('Listener part', $entry->getName());
        $this->assertStringContainsString('Part was deleted: Listener part', (string) $entry->getComment());
    }
}
