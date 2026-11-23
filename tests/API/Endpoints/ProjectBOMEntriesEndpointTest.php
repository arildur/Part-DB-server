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

final class ProjectBOMEntriesEndpointTest extends CrudEndpointTestCase
{

    protected function getBasePath(): string
    {
        return '/api/project_bom_entries';
    }

    public function testGetCollection(): void
    {
        $this->_testGetCollection();
    }

    public function testItemLifecycle(): void
    {
        $response = $this->_testPostItem([
            'project' => '/api/projects/1',
            'part' => '/api/parts/1',
            'quantity' => 1,
        ]);

        $new_id = $this->getIdOfCreatedElement($response);

        //Check if the new item is in the database
        $this->_testGetItem($new_id);

        //Check if we can change the item
        $this->_testPatchItem($new_id, [
            'quantity' => 2,
        ]);

        //Check if we can delete the item
        $this->_testDeleteItem($new_id);
    }

    public function testGetBomOfProject(): void
    {
        $response = self::createAuthenticatedClient()->request('GET', '/api/projects/1/bom');
        self::assertResponseIsSuccessful();
    }

    public function testAnEntryCanPointAtAMetaPart(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('POST', '/api/parts', ['json' => ['name' => 'API meta', 'kind' => 'meta', 'category' => '/api/categories/1']]);
        self::assertResponseStatusCodeSame(201);
        $meta = $client->getResponse()->toArray()['@id'];

        $response = $this->_testPostItem(['project' => '/api/projects/1', 'part' => $meta, 'quantity' => 1]);
        $this->assertSame('meta', $this->_testGetItem($this->getIdOfCreatedElement($response))->toArray()['part']['kind']);
    }

    private function newPart(string $name): string
    {
        $client = static::createAuthenticatedClient();
        $client->request('POST', '/api/parts', ['json' => ['name' => $name, 'category' => '/api/categories/1']]);
        self::assertResponseStatusCodeSame(201);

        return $client->getResponse()->toArray()['@id'];
    }

    private function post(array $data): array
    {
        $client = static::createAuthenticatedClient();
        $client->request('POST', $this->getBasePath(), ['json' => $data]);

        return [$client->getResponse()->getStatusCode(), $client->getResponse()->toArray(false)];
    }

    private function patch(string $iri, array $data): array
    {
        $client = static::createAuthenticatedClient();
        $client->request('PATCH', $iri, ['json' => $data, 'headers' => ['Content-Type' => 'application/merge-patch+json']]);

        return [$client->getResponse()->getStatusCode(), $client->getResponse()->toArray(false)];
    }

    private function bomSize(string $project): int
    {
        return count(static::createAuthenticatedClient()->request('GET', $project.'/bom')->toArray()['hydra:member']);
    }

    public function testASecondEntryForAPartInTheSameBomIsRefused(): void
    {
        $part = $this->newPart('Once per BOM');
        $this->assertSame(201, $this->post(['project' => '/api/projects/1', 'part' => $part, 'quantity' => 1])[0]);
        $before = $this->bomSize('/api/projects/1');
        $this->assertGreaterThan(0, $before);

        [$status, $body] = $this->post(['project' => '/api/projects/1', 'part' => $part, 'quantity' => 2]);

        $this->assertSame(422, $status);
        $this->assertSame('part', $body['violations'][0]['propertyPath']);
        $this->assertSame('This part already exists in the BOM!', $body['violations'][0]['message']);
        $this->assertSame($before, $this->bomSize('/api/projects/1'), 'The BOM keeps one entry');

        //The same part in the BOM of another project
        $this->assertSame(201, $this->post(['project' => '/api/projects/2', 'part' => $part, 'quantity' => 1])[0]);
    }

    public function testPatchingAnEntryToAPartAnotherEntryHasIsRefusedAndKeepingItsOwnPartIsNot(): void
    {
        $first = $this->newPart('First in BOM');
        $second = $this->newPart('Second in BOM');
        $this->post(['project' => '/api/projects/1', 'part' => $first, 'quantity' => 1]);
        [, $entry] = $this->post(['project' => '/api/projects/1', 'part' => $second, 'quantity' => 1]);

        [$status, $body] = $this->patch($entry['@id'], ['part' => $first]);
        $this->assertSame(422, $status);
        $this->assertSame('part', $body['violations'][0]['propertyPath']);

        $this->assertSame(200, $this->patch($entry['@id'], ['part' => $second, 'quantity' => 5])[0]);
        $this->assertSame(200, $this->patch($entry['@id'], ['quantity' => 6])[0]);
    }

    public function testEntriesWithoutAPartAreComparedByTheirName(): void
    {
        $this->assertSame(201, $this->post(['project' => '/api/projects/1', 'name' => 'Screw M3', 'quantity' => 4])[0]);
        $this->assertSame(201, $this->post(['project' => '/api/projects/1', 'name' => 'Screw M4', 'quantity' => 4])[0]);

        [$status, $body] = $this->post(['project' => '/api/projects/1', 'name' => 'Screw M3', 'quantity' => 1]);
        $this->assertSame(422, $status);
        $this->assertSame('name', $body['violations'][0]['propertyPath']);
        $this->assertSame('There is already a BOM entry with this name!', $body['violations'][0]['message']);
    }
}
