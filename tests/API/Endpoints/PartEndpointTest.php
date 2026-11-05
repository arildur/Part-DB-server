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

final class PartEndpointTest extends CrudEndpointTestCase
{

    protected function getBasePath(): string
    {
        return '/api/parts';
    }

    public function testGetCollection(): void
    {
        $this->_testGetCollection();
        self::assertJsonContains([
            'hydra:totalItems' => 3,
        ]);
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
            'name' => 'Test Part',
            'description' => 'This is a test part',
            'category' => '/api/categories/1',
            'manufacturer' => '/api/manufacturers/1',
        ]);
    }

    public function testUpdateItem(): void
    {
        $this->_testPatchItem(1, [
            'name' => 'Test Part Updated',
            'category' => '/api/categories/2',
            'manufacturer' => '/api/manufacturers/2',
        ]);
    }

    public function testNewPartIsAManufacturingPartByDefault(): void
    {
        $response = $this->_testPostItem([
            'name' => 'Part with no kind',
            'category' => '/api/categories/1',
        ]);
        $this->assertSame('manufacturing', $response->toArray()['kind']);
    }

    public function testCreateMetaPart(): void
    {
        $response = $this->_testPostItem([
            'name' => 'Meta part',
            'kind' => 'meta',
            'category' => '/api/categories/1',
        ]);
        $this->assertSame('meta', $response->toArray()['kind']);
    }

    public function testCreatePartWithUnknownKindIsRefused(): void
    {
        self::createAuthenticatedClient()->request('POST', $this->getBasePath(), ['json' => [
            'name' => 'Part with an unknown kind',
            'kind' => 'not-a-kind',
            'category' => '/api/categories/1',
        ]]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testKindOfASavedPartCanNotBeChanged(): void
    {
        self::createAuthenticatedClient()->request('PATCH', $this->getItemPath(2), [
            'json' => ['kind' => 'meta'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        self::assertResponseStatusCodeSame(422);

        $response = self::createAuthenticatedClient()->request('GET', $this->getItemPath(2));
        $this->assertSame('manufacturing', $response->toArray()['kind']);
    }

    public function testSendingTheSameKindBackIsAccepted(): void
    {
        self::createAuthenticatedClient()->request('PATCH', $this->getItemPath(2), [
            'json' => ['kind' => 'manufacturing'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testAMetaPartRefusesAManufacturer(): void
    {
        self::createAuthenticatedClient()->request('POST', $this->getBasePath(), ['json' => [
            'name' => 'Meta part',
            'kind' => 'meta',
            'category' => '/api/categories/1',
            'manufacturer' => '/api/manufacturers/1',
        ]]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAMetaPartReportsItsTotalAndItsOwnStock(): void
    {
        $meta = $this->_testPostItem(['name' => 'Meta', 'kind' => 'meta', 'category' => '/api/categories/1'])->toArray()['@id'];
        $part = $this->_testPostItem(['name' => 'Connected', 'category' => '/api/categories/1'])->toArray()['@id'];
        foreach ([[$meta, 10], [$part, 100]] as [$owner, $amount]) {
            self::createAuthenticatedClient()->request('POST', '/api/part_lots', ['json' => ['part' => $owner, 'amount' => $amount]]);
            self::assertResponseStatusCodeSame(201);
        }
        self::createAuthenticatedClient()->request('POST', '/api/part_connections', ['json' => ['meta_part' => $meta, 'connected_part' => $part]]);
        self::assertResponseStatusCodeSame(201);

        $data = self::createAuthenticatedClient()->request('GET', $meta)->toArray();
        $this->assertEqualsWithDelta(110.0, $data['total_instock'], 0.001);
        $this->assertEqualsWithDelta(10.0, $data['own_instock'], 0.001);
        $this->assertCount(1, $data['partLots'], 'Only its own lots');

        $data = self::createAuthenticatedClient()->request('GET', $part)->toArray();
        $this->assertEqualsWithDelta(100.0, $data['total_instock'], 0.001);
        $this->assertEqualsWithDelta(100.0, $data['own_instock'], 0.001);
    }

    public function testDeleteItem(): void
    {
        $this->_testDeleteItem(1);
    }

    public function testMasterPictureAttachmentPatchWithIRI(): void
    {
        $client = static::createAuthenticatedClient();

        // Create a new attachment with a picture URL for Part 1
        $response = $client->request('POST', '/api/attachments', ['json' => [
            'name' => 'Test Picture',
            'url' => 'http://example.com/test.jpg',
            '_type' => 'Part',
            'element' => '/api/parts/1',
            'attachment_type' => '/api/attachment_types/1',
        ]]);
        self::assertResponseIsSuccessful();
        $attachmentIri = $response->toArray()['@id'];

        // Now PATCH Part 1 to set master_picture_attachment
        $client->request('PATCH', '/api/parts/1', [
            'json' => ['master_picture_attachment' => $attachmentIri],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertJsonContains(['master_picture_attachment' => ['@id' => $attachmentIri]]);
    }

    public function testMasterPictureAttachmentPatchWithArray(): void
    {
        $client = static::createAuthenticatedClient();

        // Create a new attachment with a picture URL for Part 1
        $response = $client->request('POST', '/api/attachments', ['json' => [
            'name' => 'Test Picture',
            'url' => 'http://example.com/test.jpg',
            '_type' => 'Part',
            'element' => '/api/parts/1',
            'attachment_type' => '/api/attachment_types/1',
        ]]);
        self::assertResponseIsSuccessful();
        $attachmentIri = $response->toArray()['@id'];

        // Now PATCH Part 1 to set master_picture_attachment
        $client->request('PATCH', '/api/parts/1', [
            'json' => ['master_picture_attachment' => ['@id' => $attachmentIri, '_type' => 'Part']],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertJsonContains(['master_picture_attachment' => ['@id' => $attachmentIri]]);
    }
}
