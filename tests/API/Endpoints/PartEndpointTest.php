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

use App\DataFixtures\APITokenFixtures;
use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use App\Settings\BehaviorSettings\PartDeletionSettings;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\UserSystem\User;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;

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

    public function testAMetaPartListsItsConnectionsAndTheOutputEndsThere(): void
    {
        $meta = $this->_testPostItem(['name' => 'Meta', 'kind' => 'meta', 'category' => '/api/categories/1'])->toArray()['@id'];
        $second = $this->_testPostItem(['name' => 'Second', 'category' => '/api/categories/1'])->toArray();
        $first = $this->_testPostItem(['name' => 'First', 'category' => '/api/categories/1'])->toArray();
        self::createAuthenticatedClient()->request('POST', '/api/part_lots', ['json' => ['part' => $first['@id'], 'amount' => 7]]);
        foreach ([[$second, 2, 'Later'], [$first, 1, 'Sooner']] as [$part, $position, $note]) {
            self::createAuthenticatedClient()->request('POST', '/api/part_connections', ['json' => [
                'meta_part' => $meta, 'connected_part' => $part['@id'], 'position' => $position, 'note' => $note, 'status' => 'approved',
            ]]);
            self::assertResponseStatusCodeSame(201);
        }

        $connections = self::createAuthenticatedClient()->request('GET', $meta)->toArray()['part_connections'];

        $this->assertCount(2, $connections);
        $this->assertSame(['id', 'position', 'status', 'note', 'connected_part'], array_keys($connections[0]));
        $this->assertSame(1, $connections[0]['position']);
        $this->assertSame('approved', $connections[0]['status']);
        $this->assertSame('Sooner', $connections[0]['note']);
        $this->assertSame(['id', 'name', 'kind', 'total_instock'], array_keys($connections[0]['connected_part']), 'Nothing further is nested');
        $this->assertSame($first['id'], $connections[0]['connected_part']['id']);
        $this->assertSame('First', $connections[0]['connected_part']['name']);
        $this->assertSame('manufacturing', $connections[0]['connected_part']['kind']);
        $this->assertEqualsWithDelta(7.0, $connections[0]['connected_part']['total_instock'], 0.001);
        $this->assertSame('Second', $connections[1]['connected_part']['name']);

        //The connected part itself has none
        $this->assertSame([], self::createAuthenticatedClient()->request('GET', $first['@id'])->toArray()['part_connections']);

        //The linked parts columns are for export files only
        foreach ([$meta, $first['@id']] as $iri) {
            $data = self::createAuthenticatedClient()->request('GET', $iri)->toArray();
            $this->assertArrayNotHasKey('linked_parts_count', $data);
            $this->assertArrayNotHasKey('linked_parts', $data);
        }
    }

    public function testTheApiDocumentationNamesTheKindFilterAndTheConnections(): void
    {
        $documentation = self::createAuthenticatedClient()->request('GET', '/api/docs.jsonopenapi', ['headers' => ['Accept' => 'application/vnd.openapi+json']])->toArray();

        $parameters = array_column($documentation['paths']['/api/parts']['get']['parameters'], null, 'name');
        $this->assertEqualsCanonicalizing(['meta', 'manufacturing'], $parameters['kind']['schema']['enum']);

        $schemas = array_filter($documentation['components']['schemas'], static fn (array $schema): bool => isset($schema['properties']['part_connections']));
        $this->assertNotSame([], $schemas);
        $this->assertSame('integer', reset($schemas)['properties']['part_connections']['items']['properties']['position']['type']);
    }

    public function testFilterByKind(): void
    {
        $this->_testPostItem(['name' => 'Kind filter meta', 'kind' => 'meta', 'category' => '/api/categories/1']);
        $this->_testPostItem(['name' => 'Kind filter part', 'category' => '/api/categories/1']);

        $names = fn (string $query): array => array_column(
            self::createAuthenticatedClient()->request('GET', $this->getBasePath().'?name=Kind%20filter%25'.$query)->toArray()['hydra:member'],
            'name'
        );

        $this->assertSame(['Kind filter meta'], $names('&kind=meta'));
        $this->assertSame(['Kind filter part'], $names('&kind=manufacturing'));
        $found = $names('');
        sort($found);
        $this->assertSame(['Kind filter meta', 'Kind filter part'], $found, 'All kinds until a client filters');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAKind(): iterable
    {
        yield 'unknown value' => ['kind=not-a-kind'];
        yield 'empty value' => ['kind='];
        yield 'a list' => ['kind[]=meta'];
    }

    #[DataProvider('notAKind')]
    public function testFilterByKindRefusesWhatIsNotAKind(string $query): void
    {
        self::createAuthenticatedClient()->request('GET', $this->getBasePath().'?'.$query);
        self::assertResponseStatusCodeSame(400);
    }

    public function testStorageLocationFilterMatchesOwnLotsOnly(): void
    {
        $location = self::createAuthenticatedClient()->request('POST', '/api/storage_locations', ['json' => ['name' => 'Only the connected part']])->toArray();
        $meta = $this->_testPostItem(['name' => 'Meta', 'kind' => 'meta', 'category' => '/api/categories/1'])->toArray()['@id'];
        $part = $this->_testPostItem(['name' => 'Connected', 'category' => '/api/categories/1'])->toArray()['@id'];
        self::createAuthenticatedClient()->request('POST', '/api/part_lots', ['json' => ['part' => $part, 'amount' => 5, 'storage_location' => $location['@id']]]);
        self::createAuthenticatedClient()->request('POST', '/api/part_connections', ['json' => ['meta_part' => $meta, 'connected_part' => $part]]);

        $found = self::createAuthenticatedClient()->request('GET', $this->getBasePath().'?storage_location='.$location['id'])->toArray()['hydra:member'];

        $this->assertSame(['Connected'], array_column($found, 'name'));
    }

    public function testDeleteItem(): void
    {
        $this->_testDeleteItem(1);
    }

    /**
     * A client that keeps one kernel, so that the setting set here holds for its requests, and a part, used in the BOM
     * of "API project" unless told otherwise.
     * @return array{0: Client, 1: int, 2: int|null} the client, the part's ID and the project's ID
     */
    private function clientAndPart(bool $refuse, bool $inABom = true): array
    {
        $client = self::createAuthenticatedClient();
        $client->disableReboot();
        self::getContainer()->get(PartDeletionSettings::class)->refuseDeletingPartsUsedInBom = $refuse;
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $part = new Part();
        $part->setName('API part in a BOM');
        $part->setCategory($em->find(Category::class, 1));
        $em->persist($part);
        $project = null;
        if ($inABom) {
            $project = new Project();
            $project->setName('API project');
            $entry = (new ProjectBOMEntry())->setQuantity(1.0);
            $project->addBomEntry($entry);
            $part->addProjectBomEntry($entry);
            $em->persist($project);
        }
        $em->flush();
        $ids = [$client, $part->getID(), $project?->getID()];
        $em->clear();

        return $ids;
    }

    public function testDeletingAPartInABomIsRefusedWith409WhileTheSettingIsOn(): void
    {
        [$client, $id] = $this->clientAndPart(true);

        $response = $client->request('DELETE', '/api/parts/'.$id);

        self::assertResponseStatusCodeSame(409);
        $this->assertStringContainsString('API project', $response->toArray(false)['detail']);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->assertNotNull(self::getContainer()->get(EntityManagerInterface::class)->find(Part::class, $id));
    }

    /**
     * The refusal is an expected client error, caused by a setting, so it must not be logged as critical like a bug.
     */
    public function testTheRefusalIsNotLoggedAsCritical(): void
    {
        [$client, $id] = $this->clientAndPart(true);
        $handler = new TestHandler();
        self::getContainer()->get('monolog.logger.request')->pushHandler($handler);

        $client->request('DELETE', '/api/parts/'.$id);

        self::assertResponseStatusCodeSame(409);
        $this->assertFalse($handler->hasRecords(Level::Critical));
        $this->assertFalse($handler->hasRecords(Level::Error));
        $this->assertTrue($handler->hasRecordThatContains('PartUsedInBomException', Level::Warning));
    }

    public function testTheRefusalGivesOnlyTheNumberOfProjectsToAUserWhoMayNotReadProjects(): void
    {
        [$client, $id] = $this->clientAndPart(true);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getRepository(User::class)->findOneBy(['name' => 'admin'])->getPermissions()->setPermissionValue('projects', 'read', false);
        $em->flush();

        $response = $client->request('DELETE', '/api/parts/'.$id);

        self::assertResponseStatusCodeSame(409);
        $detail = $response->toArray(false)['detail'];
        $this->assertStringContainsString('used in the BOM of 1 project(s)', $detail);
        $this->assertStringNotContainsString('API project', $detail);
    }

    public function testDeletingAPartInABomUnlinksItWhileTheSettingIsOff(): void
    {
        [$client, $id, $projectId] = $this->clientAndPart(false);

        $client->request('DELETE', '/api/parts/'.$id);

        self::assertResponseStatusCodeSame(204);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $this->assertNull($em->find(Part::class, $id));
        $entry = $em->find(Project::class, $projectId)->getBomEntries()->first();
        $this->assertNull($entry->getPart());
        $this->assertSame('API part in a BOM', $entry->getName());
    }

    public function testDeletingAPartInNoBomWorksWhileTheSettingIsOn(): void
    {
        [$client, $id] = $this->clientAndPart(true, false);

        $client->request('DELETE', '/api/parts/'.$id);

        self::assertResponseStatusCodeSame(204);
    }

    public function testAUserWithoutDeleteGets403BeforeTheRefusal(): void
    {
        [$client, $id] = $this->clientAndPart(true);

        $client->request('DELETE', '/api/parts/'.$id, ['headers' => ['authorization' => 'Token '.APITokenFixtures::TOKEN_READONLY]]);

        self::assertResponseStatusCodeSame(403);
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

    private function patch(string $iri, array $data): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return self::createAuthenticatedClient()->request('PATCH', $iri, [
            'json' => $data,
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
    }

    /**
     * @return array{0: string, 1: string} The IRI of a meta part with two usable connected parts priced 0.10 and 0.20,
     *                                      and the IRI of the price detail of the first
     */
    private function pricedMetaPart(): array
    {
        $meta = $this->_testPostItem(['name' => 'Priced meta', 'kind' => 'meta', 'category' => '/api/categories/1'])->toArray()['@id'];
        $firstPrice = null;
        foreach (['0.20', '0.10'] as $position => $price) {
            $part = $this->_testPostItem(['name' => 'Priced '.$price, 'category' => '/api/categories/1'])->toArray()['@id'];
            $orderdetail = self::createAuthenticatedClient()->request('POST', '/api/orderdetails', ['json' => ['supplier' => '/api/suppliers/1', 'part' => $part]])->toArray()['@id'];
            $pricedetail = self::createAuthenticatedClient()->request('POST', '/api/pricedetails', ['json' => ['price' => $price, 'orderdetail' => $orderdetail, 'min_discount_quantity' => 1]])->toArray()['@id'];
            $firstPrice ??= $pricedetail;
            self::createAuthenticatedClient()->request('POST', '/api/part_connections', ['json' => ['meta_part' => $meta, 'connected_part' => $part, 'position' => $position + 1]]);
            self::assertResponseStatusCodeSame(201);
        }

        return [$meta, $firstPrice];
    }

    public function testAMetaPartStartsWithTheSystemDefaultAndTakesEachMethod(): void
    {
        $meta = $this->_testPostItem(['name' => 'Method meta', 'kind' => 'meta', 'category' => '/api/categories/1'])->toArray();
        $this->assertSame('system_default', $meta['price_method']);
        $this->assertNull($meta['user_price']);
        $this->assertNull($meta['user_price_currency']);

        $this->patch($meta['@id'], ['user_price' => '1']);
        foreach (['primary_source', 'cheapest', 'average', 'weighted_by_stock', 'user_value', 'system_default'] as $method) {
            $this->patch($meta['@id'], ['price_method' => $method]);
            self::assertResponseIsSuccessful();
            $this->assertSame($method, self::createAuthenticatedClient()->request('GET', $meta['@id'])->toArray()['price_method']);
        }
    }

    public function testAnUnknownPriceMethodIsRefusedAndChangesNothing(): void
    {
        $meta = $this->_testPostItem(['name' => 'Method meta', 'kind' => 'meta', 'category' => '/api/categories/1', 'price_method' => 'cheapest'])->toArray()['@id'];

        $this->patch($meta, ['price_method' => 'range']);
        self::assertResponseStatusCodeSame(400);
        $this->assertSame('cheapest', self::createAuthenticatedClient()->request('GET', $meta)->toArray()['price_method']);
    }

    public function testTheUserPriceIsKeptAsWrittenWhateverTheMethodAndTheConnectedPrices(): void
    {
        [$meta, $firstPrice] = $this->pricedMetaPart();
        $currency = '/api/currencies/1';

        $this->patch($meta, ['price_method' => 'cheapest', 'user_price' => '0.25', 'user_price_currency' => $currency]);
        self::assertResponseIsSuccessful();
        $this->patch($firstPrice, ['price' => '0.05']);
        self::assertResponseIsSuccessful();

        $data = self::createAuthenticatedClient()->request('GET', $meta)->toArray();
        $this->assertSame('0.25', $this->trimmed($data['user_price']));
        $this->assertSame($currency, $data['user_price_currency']['@id']);
        $this->assertSame('0.05', $this->trimmed($data['price']), 'The calculated price follows the connected part');

        $this->patch($meta, ['user_price' => null, 'user_price_currency' => null]);
        $data = self::createAuthenticatedClient()->request('GET', $meta)->toArray();
        $this->assertNull($data['user_price']);
        $this->assertNull($data['user_price_currency']);
    }

    private function trimmed(?string $decimal): ?string
    {
        return $decimal === null ? null : rtrim(rtrim($decimal, '0'), '.');
    }

    public function testTheSinglePartResponseHoldsTheReadOnlyPrice(): void
    {
        [$meta] = $this->pricedMetaPart();

        $this->patch($meta, ['price_method' => 'cheapest']);
        $this->assertSame('0.1', $this->trimmed(self::createAuthenticatedClient()->request('GET', $meta)->toArray()['price']));
        $this->patch($meta, ['price_method' => 'primary_source']);
        $this->assertSame('0.2', $this->trimmed(self::createAuthenticatedClient()->request('GET', $meta)->toArray()['price']));

        $this->patch($meta, ['price_method' => 'user_value', 'user_price' => '0.30']);
        $this->assertSame('0.3', $this->trimmed(self::createAuthenticatedClient()->request('GET', $meta)->toArray()['price']));

        //A price sent by the client is ignored
        $this->patch($meta, ['price' => '1.00']);
        self::assertResponseIsSuccessful();
        $this->assertSame('0.3', $this->trimmed(self::createAuthenticatedClient()->request('GET', $meta)->toArray()['price']));

        //A list works out no price for each row
        $list = self::createAuthenticatedClient()->request('GET', '/api/parts?kind=meta')->toArray()['hydra:member'];
        $this->assertNotSame([], $list);
        foreach ($list as $row) {
            $this->assertArrayNotHasKey('price', $row);
        }
    }

    public function testUserValueWithNoUserPriceGivesNoPrice(): void
    {
        [$meta] = $this->pricedMetaPart();
        //The entity refuses the method with no value, so the value goes in SQL
        $this->patch($meta, ['price_method' => 'user_value', 'user_price' => '0.30']);
        $id = (int) basename($meta);
        $em = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $em->getConnection()->executeStatement('UPDATE '.$em->getConnection()->getDatabasePlatform()->quoteSingleIdentifier('parts').' SET price_user_value = NULL WHERE id = ?', [$id]);

        $this->assertNull(self::createAuthenticatedClient()->request('GET', $meta)->toArray()['price']);
    }

    public function testAUserValueMethodWithNoUserPriceIsRefused(): void
    {
        $meta = $this->_testPostItem(['name' => 'Method meta', 'kind' => 'meta', 'category' => '/api/categories/1'])->toArray()['@id'];

        $response = $this->patch($meta, ['price_method' => 'user_value']);
        self::assertResponseStatusCodeSame(422);
        $this->assertSame(['user_price'], array_column($response->toArray(false)['violations'], 'propertyPath'));
    }

    public function testAManufacturingPartRefusesThePriceFields(): void
    {
        $part = $this->_testPostItem(['name' => 'No price method', 'category' => '/api/categories/1'])->toArray();
        $this->assertNull($part['price_method'], 'A manufacturing part has no price method');

        foreach ([['user_price', '0.10'], ['price_method', 'cheapest'], ['price_method', 'system_default']] as [$field, $value]) {
            $response = $this->patch($part['@id'], [$field => $value]);
            self::assertResponseStatusCodeSame(422);
            $this->assertContains($field, array_column($response->toArray(false)['violations'], 'propertyPath'));
        }

        $data = self::createAuthenticatedClient()->request('GET', $part['@id'])->toArray();
        $this->assertNull($data['price_method']);
        $this->assertNull($data['user_price']);
        $this->assertArrayNotHasKey('price', $data);

        self::createAuthenticatedClient()->request('POST', $this->getBasePath(), ['json' => ['name' => 'No price method', 'category' => '/api/categories/1', 'price_method' => 'average']]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testTheApiDocumentationNamesThePriceFields(): void
    {
        $documentation = self::createAuthenticatedClient()->request('GET', '/api/docs.jsonopenapi', ['headers' => ['Accept' => 'application/vnd.openapi+json']])->toArray();

        $read = array_filter($documentation['components']['schemas'], static fn (array $schema): bool => isset($schema['properties']['price_method']));
        $this->assertNotSame([], $read);
        $withPrice = array_filter($documentation['components']['schemas'], static fn (array $schema): bool => isset($schema['properties']['price'], $schema['properties']['price_method']));
        $this->assertNotSame([], $withPrice, 'The read-only price is documented');
        $this->assertTrue(reset($withPrice)['properties']['price']['readOnly'] ?? false);
    }
}
