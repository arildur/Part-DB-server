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

use App\Entity\LogSystem\AbstractLogEntry;
use App\Entity\LogSystem\ElementCreatedLogEntry;
use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use App\Entity\UserSystem\User;
use App\Settings\BehaviorSettings\PartDeletionSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Undoing the creation of a part deletes the part, so it is one more path that asks the deletion guard.
 */
final class LogControllerUndoPartCreationTest extends WebTestCase
{
    public function testUndoingTheCreationOfAPartInABomIsRefusedWhileTheSettingIsOn(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $client->loginUser($em->getRepository(User::class)->findOneBy(['name' => 'admin']));
        self::getContainer()->get(PartDeletionSettings::class)->refuseDeletingPartsUsedInBom = true;

        $part = new Part();
        $part->setName('Created part in a BOM');
        $part->setCategory($em->find(Category::class, 1));
        $em->persist($part);
        $em->flush();
        $project = new Project();
        $project->setName('Undo project');
        $entry = (new ProjectBOMEntry())->setQuantity(1.0);
        $project->addBomEntry($entry);
        $part->addProjectBomEntry($entry);
        $em->persist($project);
        $em->flush();
        $id = $part->getID();

        $creation = array_values(array_filter($em->getRepository(AbstractLogEntry::class)->getElementHistory($part),
            static fn (AbstractLogEntry $e): bool => $e instanceof ElementCreatedLogEntry));
        $this->assertCount(1, $creation);

        $client->request('POST', '/en/log/undo', ['undo' => $creation[0]->getID(), 'redirect_back' => '/en/part/'.$id.'/info']);

        $this->assertResponseRedirects('/en/part/'.$id.'/info');
        $this->assertStringContainsString('Undo project', $client->followRedirect()->filter('body')->text());
        $em->clear();
        $this->assertNotNull($em->find(Part::class, $id));
    }
}
