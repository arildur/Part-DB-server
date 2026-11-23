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
use App\Entity\Parts\PartLot;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use App\Entity\UserSystem\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Posts the build form for entries with nothing to withdraw from: such a build is accepted and withdraws nothing.
 */
final class ProjectBuildControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    /** @var list<object> */
    private array $entitiesToRemove = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->entityManager->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $this->assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin);
        $this->client->catchExceptions(false);
    }

    protected function tearDown(): void
    {
        $this->entityManager->clear();
        foreach (array_reverse($this->entitiesToRemove) as $entity) {
            $managed = $this->entityManager->find($entity::class, $entity->getID());
            if ($managed !== null) {
                $this->entityManager->remove($managed);
            }
        }
        $this->entityManager->flush();
        parent::tearDown();
    }

    private function createProjectUsing(Part $part, string $name): Project
    {
        $category = new Category();
        $category->setName($name);
        $part->setName($name);
        $part->setCategory($category);
        $entry = new ProjectBOMEntry();
        $entry->setPart($part);
        $entry->setQuantity(5);
        $project = new Project();
        $project->setName($name);
        $project->addBomEntry($entry);

        foreach ([$category, $part, $project] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        array_push($this->entitiesToRemove, $category, $part, $project);

        return $project;
    }

    private function postBuildForm(Project $project): void
    {
        $crawler = $this->client->request('GET', '/en/project/'.$project->getID().'/build?n=1');
        $this->assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form[name="project_build"]')->form());
    }

    public function testBuildPassesForAPartWithNoLots(): void
    {
        $project = $this->createProjectUsing(new Part(), 'Build test, no lots');

        $this->postBuildForm($project);

        $this->assertResponseRedirects();
    }

    /**
     * Keeping passives with an unknown amount is ordinary use, and such a part builds with nothing withdrawn.
     */
    public function testBuildPassesForAPartWithOnlyAnUnknownAmount(): void
    {
        $part = new Part();
        $lot = new PartLot();
        $lot->setInstockUnknown(true);
        $part->addPartLot($lot);
        $project = $this->createProjectUsing($part, 'Build test, unknown amount');

        $this->postBuildForm($project);

        $this->assertResponseRedirects();
    }
}
