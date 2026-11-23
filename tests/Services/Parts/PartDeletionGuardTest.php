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

namespace App\Tests\Services\Parts;

use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartKind;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use App\Exceptions\PartUsedInBomException;
use App\Services\Parts\PartDeletionGuard;
use App\Settings\BehaviorSettings\PartDeletionSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PartDeletionGuardTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PartDeletionGuard $guard;
    private PartDeletionSettings $settings;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->guard = self::getContainer()->get(PartDeletionGuard::class);
        $this->settings = self::getContainer()->get(PartDeletionSettings::class);
        $this->settings->refuseDeletingPartsUsedInBom = true;
    }

    private function part(string $name, PartKind $kind = PartKind::MANUFACTURING): Part
    {
        $part = (new Part())->setKind($kind);
        $part->setName($name);
        $part->setCategory($this->em->find(Category::class, 1));
        $this->em->persist($part);

        return $part;
    }

    private function useIn(Part $part, string $projectName): Project
    {
        $project = new Project();
        $project->setName($projectName);
        $entry = (new ProjectBOMEntry())->setQuantity(1.0);
        $project->addBomEntry($entry);
        $part->addProjectBomEntry($entry);
        $this->em->persist($project);

        return $project;
    }

    public function testAPartInTwoBomsIsRefusedNamingBothProjects(): void
    {
        $part = $this->part('Guarded part');
        $this->useIn($part, 'Zeta project');
        $this->useIn($part, 'Alpha project');
        $this->em->flush();

        try {
            $this->guard->assertDeletable($part);
            $this->fail('The guard did not refuse.');
        } catch (PartUsedInBomException $exception) {
            $this->assertSame('Guarded part', $exception->getPartName());
            $this->assertSame(['Alpha project', 'Zeta project'], $exception->getProjectNames());
            $this->assertStringContainsString('Alpha project, Zeta project', $exception->getMessage());
        }
    }

    public function testAPartInNoBomPasses(): void
    {
        $part = $this->part('Unused part');
        $this->em->flush();

        $this->guard->assertDeletable($part);
        $this->assertSame([], $this->guard->getBlockingProjects($part));
    }

    public function testWithTheSettingOffAPartInABomPasses(): void
    {
        $this->settings->refuseDeletingPartsUsedInBom = false;
        $part = $this->part('Part of an unguarded BOM');
        $this->useIn($part, 'Some project');
        $this->em->flush();

        $this->guard->assertDeletable($part);
        $this->assertFalse($this->guard->isRefusing());
        //The projects are still known, for the page that names them
        $this->assertCount(1, $this->guard->getBlockingProjects($part));
    }

    public function testAPartUsedOnlyThroughAMetaPartPasses(): void
    {
        $meta = $this->part('Meta in a BOM', PartKind::META);
        $part = $this->part('Connected part');
        $meta->addPartConnection((new PartConnection())->setConnectedPart($part)->setPosition(1));
        $this->useIn($meta, 'Project of the meta part');
        $this->em->flush();

        $this->guard->assertDeletable($part);
        $this->expectException(PartUsedInBomException::class);
        $this->guard->assertDeletable($meta);
    }

    public function testAnEntryWhoseProjectIsBeingDeletedDoesNotCount(): void
    {
        $part = $this->part('Part of a deleted project');
        $deleted = $this->useIn($part, 'Deleted project');
        $this->useIn($part, 'Kept project');
        $this->em->flush();

        $this->em->remove($deleted);

        $this->assertSame(['Kept project'], array_map(static fn (Project $p): string => $p->getName(), $this->guard->getBlockingProjects($part)));
    }

    public function testGetProjectsNamesEachProjectOnceSortedByName(): void
    {
        $part = $this->part('Part used twice');
        $beta = $this->useIn($part, 'Beta');
        $second = (new ProjectBOMEntry())->setQuantity(2.0)->setMountnames('R2');
        $beta->addBomEntry($second);
        $part->addProjectBomEntry($second);
        $this->useIn($part, 'alpha');

        $this->assertSame(['alpha', 'Beta'], array_map(static fn (Project $p): string => $p->getName(), $part->getProjects()));
    }
}
