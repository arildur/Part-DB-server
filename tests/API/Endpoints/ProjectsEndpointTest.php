<?php
/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2024 Jan Böhmer (https://github.com/jbtronics)
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


namespace App\Tests\API\Endpoints;

use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use App\Settings\BehaviorSettings\PartDeletionSettings;
use App\Tests\API\Endpoints\CrudEndpointTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class ProjectsEndpointTest extends CrudEndpointTestCase
{

    protected function getBasePath(): string
    {
        return '/api/projects';
    }

    public function testGetCollection(): void
    {
        $this->_testGetCollection();
        self::assertJsonContains([
            'hydra:totalItems' => 7,
        ]);
    }

    public function testGetChildrenCollection(): void
    {
        $this->_testGetChildrenCollection(1);
    }

    public function testGetItem(): void
    {
        $this->_testGetItem(1);
        $this->_testGetItem(2);
        $this->_testGetItem(3);
    }

    public function testCreateItem(): void
    {
        $this->_testPostItem([
            'name' => 'Test API',
            'parent' => '/api/projects/1',
        ]);
    }

    public function testUpdateItem(): void
    {
        $this->_testPatchItem(5, [
            'name' => 'Updated',
            'parent' => '/api/projects/2',
        ]);
    }

    public function testDeleteItem(): void
    {
        $this->_testDeleteItem(7);
    }

    public function testAMetaPartCanBeABuildPart(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('POST', '/api/parts', ['json' => ['name' => 'API meta', 'kind' => 'meta', 'category' => '/api/categories/1']]);
        self::assertResponseStatusCodeSame(201);
        $meta = $client->getResponse()->toArray()['@id'];
        $client->request('POST', '/api/projects', ['json' => ['name' => 'Built from a meta part']]);
        self::assertResponseStatusCodeSame(201);
        $project = $client->getResponse()->toArray()['@id'];

        $client->request('PATCH', $project, ['json' => ['build_part' => $meta], 'headers' => ['Content-Type' => 'application/merge-patch+json']]);
        self::assertResponseIsSuccessful();
        $buildPart = $client->request('GET', $project)->toArray()['build_part'];
        $this->assertSame($meta, is_array($buildPart) ? $buildPart['@id'] : $buildPart);
    }

    public function testABuildPartReachedByTheProjectsBomIsRefused(): void
    {
        $client = static::createAuthenticatedClient();
        $post = static function (string $path, array $json) use ($client): string {
            $client->request('POST', $path, ['json' => $json]);
            self::assertResponseStatusCodeSame(201);

            return $client->getResponse()->toArray()['@id'];
        };
        $meta = $post('/api/parts', ['name' => 'API meta with a loop', 'kind' => 'meta', 'category' => '/api/categories/1']);
        $connected = $post('/api/parts', ['name' => 'API connected in the BOM', 'category' => '/api/categories/1']);
        $post('/api/part_connections', ['meta_part' => $meta, 'connected_part' => $connected]);
        $project = $post('/api/projects', ['name' => 'Using the connected part']);
        $post('/api/project_bom_entries', ['project' => $project, 'part' => $connected, 'quantity' => 1]);

        $client->request('PATCH', $project, ['json' => ['build_part' => $meta], 'headers' => ['Content-Type' => 'application/merge-patch+json']]);
        self::assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('build_part', $client->getResponse()->getContent(false));

        //The other order: a connection from a meta build part to a part in its project's BOM
        $metaBuild = $post('/api/parts', ['name' => 'API meta build part', 'kind' => 'meta', 'category' => '/api/categories/1']);
        $inBom = $post('/api/parts', ['name' => 'API part in the BOM', 'category' => '/api/categories/1']);
        $built = $post('/api/projects', ['name' => 'Built from the meta part']);
        $post('/api/project_bom_entries', ['project' => $built, 'part' => $inBom, 'quantity' => 1]);
        $client->request('PATCH', $built, ['json' => ['build_part' => $metaBuild], 'headers' => ['Content-Type' => 'application/merge-patch+json']]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/part_connections', ['json' => ['meta_part' => $metaBuild, 'connected_part' => $inBom]]);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Deleting a project deletes its build part, so the setting refusing the deletion of parts used in a BOM
     * also refuses deleting a sub-project whose build part is in its parent's BOM, with 409 as for a part.
     */
    public function testDeletingAProjectWhoseBuildPartIsInABomIsRefusedWith409WhileTheSettingIsOn(): void
    {
        $client = self::createAuthenticatedClient();
        $client->disableReboot();
        self::getContainer()->get(PartDeletionSettings::class)->refuseDeletingPartsUsedInBom = true;
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $parent = new Project();
        $parent->setName('API parent project');
        $child = new Project();
        $child->setName('API sub-project');
        $child->setParent($parent);
        $buildPart = new Part();
        $buildPart->setName('API sub-project build');
        $buildPart->setCategory($em->find(Category::class, 1));
        $child->setBuildPart($buildPart);
        $entry = (new ProjectBOMEntry())->setQuantity(1.0);
        $parent->addBomEntry($entry);
        $buildPart->addProjectBomEntry($entry);
        foreach ([$parent, $child, $buildPart] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        [$childId, $partId] = [$child->getID(), $buildPart->getID()];
        $em->clear();

        $response = $client->request('DELETE', '/api/projects/'.$childId);

        self::assertResponseStatusCodeSame(409);
        $this->assertStringContainsString('API parent project', $response->toArray(false)['detail']);
        $em->clear();
        $this->assertNotNull($em->find(Project::class, $childId));
        $this->assertNotNull($em->find(Part::class, $partId));

        //With the setting off, the project and its build part are deleted, and the BOM entry keeps the name
        self::getContainer()->get(PartDeletionSettings::class)->refuseDeletingPartsUsedInBom = false;
        $client->request('DELETE', '/api/projects/'.$childId);
        self::assertResponseStatusCodeSame(204);
        $em->clear();
        $this->assertNull($em->find(Project::class, $childId));
        $this->assertNull($em->find(Part::class, $partId));
    }
}
