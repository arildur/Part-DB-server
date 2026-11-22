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

declare(strict_types=1);namespace App\Tests\API\Endpoints;

use App\DataFixtures\APITokenFixtures;
use PHPUnit\Framework\Attributes\DataProvider;

final class PartConnectionsEndpointTest extends CrudEndpointTestCase
{
    protected function getBasePath(): string
    {
        return '/api/part_connections';
    }

    private function createPart(string $kind, string $name): string
    {
        $response = self::createAuthenticatedClient()->request('POST', '/api/parts', ['json' => [
            'name' => $name,
            'kind' => $kind,
            'category' => '/api/categories/1',
        ]]);
        self::assertResponseStatusCodeSame(201);

        return $response->toArray()['@id'];
    }

    public function testGetCollection(): void
    {
        $this->_testGetCollection();
        self::assertJsonContains(['hydra:totalItems' => 0]);
    }

    public function testLifeCycle(): void
    {
        $meta = $this->createPart('meta', 'Meta');
        $part = $this->createPart('manufacturing', 'Part');

        $response = $this->_testPostItem(['meta_part' => $meta, 'connected_part' => $part, 'note' => 'First']);
        $data = $response->toArray();
        $this->assertSame(1, $data['position']);
        $this->assertSame('approved', $data['status']);
        $id = $this->getIdOfCreatedElement($response);

        $this->_testGetItem($id);
        $this->_testPatchItem($id, ['status' => 'do_not_use', 'position' => 4, 'note' => 'Retired']);
        self::assertJsonContains(['status' => 'do_not_use', 'position' => 4, 'note' => 'Retired']);
        $this->_testDeleteItem($id);
    }

    public function testEachStatusIsTakenAndTheOldValueIsRefused(): void
    {
        $meta = $this->createPart('meta', 'Meta');
        $response = $this->_testPostItem(['meta_part' => $meta, 'connected_part' => $this->createPart('manufacturing', 'Part')]);
        $id = $this->getIdOfCreatedElement($response);

        foreach (['use_up', 'under_evaluation', 'do_not_use', 'approved'] as $status) {
            $this->_testPatchItem($id, ['status' => $status]);
            $this->assertSame($status, $this->_testGetItem($id)->toArray()['status']);
        }

        self::createAuthenticatedClient()->request('PATCH', $this->getItemPath($id), [
            'json' => ['status' => 'unknown'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        self::assertResponseStatusCodeSame(400);
        $this->assertSame('approved', $this->_testGetItem($id)->toArray()['status'], 'The refused write changed nothing');
    }

    public function testTheStatusFilterComparesTheStoredValue(): void
    {
        $meta = $this->createPart('meta', 'Meta');
        $this->_testPostItem(['meta_part' => $meta, 'connected_part' => $this->createPart('manufacturing', 'A'), 'status' => 'use_up']);
        $this->_testPostItem(['meta_part' => $meta, 'connected_part' => $this->createPart('manufacturing', 'B'), 'status' => 'use_up']);
        $this->_testPostItem(['meta_part' => $meta, 'connected_part' => $this->createPart('manufacturing', 'C')]);

        $members = fn (string $query): array => self::createAuthenticatedClient()
            ->request('GET', $this->getBasePath().'?'.$query)->toArray()['hydra:member'];

        $useUp = $members('status=use_up');
        $this->assertCount(2, $useUp);
        $this->assertSame(['use_up', 'use_up'], array_column($useUp, 'status'));
        $this->assertSame([], $members('status=unknown'), 'An unknown value is no error, it matches nothing');
    }

    public function testASecondConnectionWithNoPositionComesLast(): void
    {
        $meta = $this->createPart('meta', 'Meta');
        $first = $this->_testPostItem(['meta_part' => $meta, 'connected_part' => $this->createPart('manufacturing', 'A'), 'position' => 3]);
        $this->assertSame(3, $first->toArray()['position']);

        $second = $this->_testPostItem(['meta_part' => $meta, 'connected_part' => $this->createPart('manufacturing', 'B')]);

        $this->assertSame(4, $second->toArray()['position']);
    }

    public function testFiltersAndDefaultOrder(): void
    {
        $meta = $this->createPart('meta', 'Meta');
        $otherMeta = $this->createPart('meta', 'Other meta');
        $partA = $this->createPart('manufacturing', 'A');
        $partB = $this->createPart('manufacturing', 'B');
        $this->_testPostItem(['meta_part' => $meta, 'connected_part' => $partA, 'position' => 2]);
        $this->_testPostItem(['meta_part' => $meta, 'connected_part' => $partB, 'position' => 1, 'status' => 'do_not_use']);
        $this->_testPostItem(['meta_part' => $otherMeta, 'connected_part' => $partA, 'position' => 1]);

        $members = fn (string $query): array => self::createAuthenticatedClient()
            ->request('GET', $this->getBasePath().'?'.$query)->toArray()['hydra:member'];
        $idOf = static fn (string $iri): string => substr($iri, (int) strrpos($iri, '/') + 1);

        $forMeta = $members('meta_part='.$idOf($meta));
        $this->assertCount(2, $forMeta);
        $this->assertSame([1, 2], array_column($forMeta, 'position'), 'Ordered by position');
        $this->assertCount(2, $members('connected_part='.$idOf($partA)));
        $this->assertCount(1, $members('status=do_not_use'));
    }

    /** @return array<string, array{string, string}> */
    public static function wrongKinds(): array
    {
        return [
            'first end is a manufacturing part' => ['manufacturing', 'manufacturing'],
            'second end is a meta part' => ['meta', 'meta'],
        ];
    }

    #[DataProvider('wrongKinds')]
    public function testWrongKindsAreRefused(string $first, string $second): void
    {
        $metaPart = $this->createPart($first, 'First');
        $connectedPart = $this->createPart($second, 'Second');

        self::createAuthenticatedClient()->request('POST', $this->getBasePath(), ['json' => [
            'meta_part' => $metaPart,
            'connected_part' => $connectedPart,
        ]]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnExistingPairIsRefused(): void
    {
        $meta = $this->createPart('meta', 'Meta');
        $part = $this->createPart('manufacturing', 'Part');
        $this->_testPostItem(['meta_part' => $meta, 'connected_part' => $part]);

        self::createAuthenticatedClient()->request('POST', $this->getBasePath(), ['json' => ['meta_part' => $meta, 'connected_part' => $part]]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testTheEndsCanNotBePatched(): void
    {
        $meta = $this->createPart('meta', 'Meta');
        $response = $this->_testPostItem(['meta_part' => $meta, 'connected_part' => $this->createPart('manufacturing', 'A')]);
        $id = $this->getIdOfCreatedElement($response);

        $otherPart = $this->createPart('manufacturing', 'B');

        self::createAuthenticatedClient()->request('PATCH', $this->getItemPath($id), [
            'json' => ['connected_part' => $otherPart],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAReadOnlyTokenCanReadButNotWrite(): void
    {
        $meta = $this->createPart('meta', 'Meta');
        $part = $this->createPart('manufacturing', 'Part');

        $client = self::createAuthenticatedClient(APITokenFixtures::TOKEN_READONLY);
        $client->request('GET', $this->getBasePath());
        self::assertResponseIsSuccessful();

        $client->request('POST', $this->getBasePath(), ['json' => ['meta_part' => $meta, 'connected_part' => $part]]);
        self::assertResponseStatusCodeSame(403);
    }
}
