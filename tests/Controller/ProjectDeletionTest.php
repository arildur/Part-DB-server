<?php

declare(strict_types=1);

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

namespace App\Tests\Controller;

use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use App\Entity\UserSystem\User;
use App\Settings\BehaviorSettings\PartDeletionSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Deleting a project deletes its build part, so it is one more path that asks the deletion guard: for a sub-project,
 * whose build part is in the BOM of its parent, it is refused with a message instead of an error page.
 */
final class ProjectDeletionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['name' => 'admin']));
        self::getContainer()->get(PartDeletionSettings::class)->refuseDeletingPartsUsedInBom = true;
    }

    /**
     * @return array{0: Project, 1: Project} a parent project and its sub-project, whose build part is in the BOM of the parent
     */
    private function subProjectInBomOfParent(): array
    {
        $parent = new Project();
        $parent->setName('Parent of the sub-project');
        $child = new Project();
        $child->setName('Sub-project to delete');
        $child->setParent($parent);
        $buildPart = new Part();
        $buildPart->setName('Build part of the sub-project');
        $buildPart->setCategory($this->em->find(Category::class, 1));
        $child->setBuildPart($buildPart);
        $entry = (new ProjectBOMEntry())->setQuantity(1.0);
        $parent->addBomEntry($entry);
        $buildPart->addProjectBomEntry($entry);
        $this->em->persist($parent);
        $this->em->persist($child);
        $this->em->persist($buildPart);
        $this->em->flush();

        return [$parent, $child];
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function delete(Project $project, array $extra = []): void
    {
        $id = $project->getID();
        $token = $this->client->request('GET', "/en/project/{$id}/edit")
            ->filter('form input[name="_method"][value="DELETE"]')->closest('form')->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', "/en/project/{$id}", ['_method' => 'DELETE', '_token' => $token] + $extra);
    }

    public function testDeletingASubProjectWhoseBuildPartIsInTheBomOfItsParentIsRefused(): void
    {
        [$parent, $child] = $this->subProjectInBomOfParent();
        $childId = $child->getID();
        $parentId = $parent->getID();
        $partId = $child->getBuildPart()->getID();

        $this->delete($child);

        $this->assertResponseRedirects("/en/project/{$childId}/edit");
        $text = $this->client->followRedirect()->filter('body')->text();
        $this->assertStringContainsString('Sub-project to delete', $text);
        $this->assertStringContainsString('is used in the BOM of: Parent of the sub-project', $text);
        $this->em->clear();
        $this->assertNotNull($this->em->find(Project::class, $childId));
        $this->assertNotNull($this->em->find(Project::class, $parentId));
        $this->assertNotNull($this->em->find(Part::class, $partId));
    }

    public function testDeletingTheParentWithItsSubProjectsIsAllowedAsTheBomGoesWithIt(): void
    {
        [$parent, $child] = $this->subProjectInBomOfParent();
        $childId = $child->getID();
        $parentId = $parent->getID();

        $this->delete($parent, ['delete_recursive' => '1']);

        $this->assertResponseRedirects('/en/project/new');
        $this->em->clear();
        $this->assertNull($this->em->find(Project::class, $parentId));
        $this->assertNull($this->em->find(Project::class, $childId));
    }

    public function testWithTheSettingOffTheSubProjectIsDeleted(): void
    {
        self::getContainer()->get(PartDeletionSettings::class)->refuseDeletingPartsUsedInBom = false;
        [, $child] = $this->subProjectInBomOfParent();
        $childId = $child->getID();

        $this->delete($child);

        $this->assertResponseRedirects('/en/project/new');
        $this->em->clear();
        $this->assertNull($this->em->find(Project::class, $childId));
    }
}
