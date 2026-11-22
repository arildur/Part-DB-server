<?php
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

declare(strict_types=1);

namespace App\Tests\State\Mcp;

use App\DataFixtures\APITokenFixtures;
use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartKind;
use App\Entity\Parts\PartLot;
use App\Entity\Parts\PartPriceMethod;
use App\Entity\Parts\Supplier;
use App\Entity\PriceInformations\Orderdetail;
use App\Entity\PriceInformations\Pricedetail;
use Brick\Math\BigDecimal;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use App\Settings\AISettings\McpSettings;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Sends real JSON-RPC requests to /mcp, as a client does. The processor tests call the processors directly, so they
 * see neither what a tool writes out, nor the output schema a client checks that against, nor the scope of a token.
 */
final class MetaPartOverMcpTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private McpSettings $settings;
    private string $sessionId = '';
    private string $token = APITokenFixtures::TOKEN_ADMIN;
    private int $nextId = 1;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->settings = self::getContainer()->get(McpSettings::class);
        $this->settings->enabled = true;
        $this->settings->editingEnabled = true;
    }

    private function send(array $message): array
    {
        $this->client->request('POST', '/mcp', [], [], array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            'HTTP_MCP_SESSION_ID' => $this->sessionId,
        ]), json_encode(['jsonrpc' => '2.0'] + $message, JSON_THROW_ON_ERROR));

        $response = $this->client->getResponse();
        $this->sessionId = $response->headers->get('mcp-session-id') ?? $this->sessionId;
        $content = (string) $response->getContent();

        return $content === '' ? [] : json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    }

    private function connect(string $token = APITokenFixtures::TOKEN_ADMIN): void
    {
        $this->token = $token;
        $this->sessionId = '';
        $this->send(['id' => $this->nextId++, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'test', 'version' => '1'],
        ]]);
        self::assertNotSame('', $this->sessionId, (string) $this->client->getResponse()->getContent());
        $this->send(['method' => 'notifications/initialized']);
    }

    /**
     * @return array<string, mixed> The result of the tool
     */
    private function call(string $tool, array $arguments): array
    {
        $answer = $this->send(['id' => $this->nextId++, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]]);
        self::assertArrayHasKey('result', $answer, json_encode($answer, JSON_THROW_ON_ERROR));

        return $answer['result'];
    }

    private function makePart(string $name, PartKind $kind = PartKind::MANUFACTURING): Part
    {
        $part = new Part();
        $part->setName($name);
        $part->setKind($kind);
        $part->setCategory($this->em->getRepository(Category::class)->findOneBy(['name' => 'Node 1']));
        $this->em->persist($part);

        return $part;
    }

    private function makeMetaPart(): Part
    {
        $connected = $this->makePart('Connected over MCP');
        $lot = new PartLot();
        $lot->setAmount(12);
        $connected->addPartLot($lot);

        $meta = $this->makePart('Meta over MCP', PartKind::META);
        $connection = new PartConnection();
        $connection->setConnectedPart($connected);
        $connection->setNote('Preferred');
        $meta->addPartConnection($connection);
        $this->em->flush();

        return $meta;
    }

    /**
     * What a client does with the output schema it was given. The schema names only a few fields of a part and allows others.
     */
    private function assertMatchesSchema(mixed $value, array $schema, string $path): void
    {
        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            $actual = match (true) {
                is_array($value) => array_is_list($value) ? ['array', 'object'] : ['object'],
                is_int($value) => ['integer', 'number'],
                is_float($value) => ['number'],
                is_string($value) => ['string'],
                is_bool($value) => ['boolean'],
                default => ['null'],
            };
            self::assertNotSame([], array_intersect($types, $actual), sprintf('%s is %s, the schema says %s', $path, json_encode($value), json_encode($types)));
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $itemSchema = is_int($key) ? ($schema['items'] ?? null) : ($schema['properties'][$key] ?? null);
                if (is_array($itemSchema)) {
                    $this->assertMatchesSchema($item, $itemSchema, $path.'.'.$key);
                }
            }
        }
    }

    public function testToolsWritingOrReadingAPartShowItsConnectionsAndMatchTheirSchema(): void
    {
        $meta = $this->makeMetaPart();
        $lotOfMeta = new PartLot();
        $lotOfMeta->setAmount(3);
        $meta->addPartLot($lotOfMeta);
        $this->em->flush();

        $this->connect();
        $tools = array_column($this->send(['id' => $this->nextId++, 'method' => 'tools/list'])['result']['tools'], null, 'name');

        foreach ([
            'get_part_details' => [['id' => $meta->getID()], 3],
            'update_part' => [['id' => $meta->getID(), 'needsReview' => false], 3],
            'add_part_stock' => [['lotId' => $lotOfMeta->getID(), 'amount' => 2], 5],
            'withdraw_part_stock' => [['lotId' => $lotOfMeta->getID(), 'amount' => 1], 4],
            'stocktake_part_lot' => [['lotId' => $lotOfMeta->getID(), 'actualAmount' => 3], 3],
        ] as $tool => [$arguments, $own]) {
            $result = $this->call($tool, $arguments);
            self::assertFalse($result['isError'] ?? false, $tool.': '.json_encode($result));
            $part = $result['structuredContent'];

            self::assertSame('meta', $part['kind'], $tool);
            self::assertEqualsWithDelta($own + 12, $part['total_instock'], 0.001, $tool);
            self::assertEqualsWithDelta($own, $part['own_instock'], 0.001, $tool);
            self::assertCount(1, $part['part_connections'], $tool);
            self::assertSame('Preferred', $part['part_connections'][0]['note'], $tool);
            self::assertSame(['id', 'name', 'kind', 'total_instock'], array_keys($part['part_connections'][0]['connected_part']), $tool);
            $this->assertMatchesSchema($part, $tools[$tool]['outputSchema'], $tool);
            //A meta part with no price still names the price keys, with null
            self::assertSame('system_default', $part['price_method'], $tool);
            foreach (['user_price', 'user_price_currency', 'price'] as $key) {
                self::assertArrayHasKey($key, $part, $tool.': '.$key);
                self::assertNull($part[$key], $tool.': '.$key);
            }
        }

        //A manufacturing part has no price fields, so its result stays as it was
        $connected = $meta->getPartConnections()->first()->getConnectedPart();
        $manufacturing = $this->call('get_part_details', ['id' => $connected->getID()])['structuredContent'];
        foreach (['user_price', 'user_price_currency', 'price'] as $key) {
            self::assertArrayNotHasKey($key, $manufacturing, $key);
        }

        $created = $this->call('create_part', ['name' => 'Created meta', 'kind' => 'meta', 'categoryId' => $meta->getCategory()->getID()]);
        self::assertFalse($created['isError'] ?? false, json_encode($created));
        self::assertSame('meta', $created['structuredContent']['kind']);
        self::assertSame([], $created['structuredContent']['part_connections']);
    }

    public function testSinglePartToolsShowThePriceFieldsAndTheSearchNoPrice(): void
    {
        $meta = $this->makeMetaPart();
        $meta->setPriceMethod(PartPriceMethod::CHEAPEST)->setPriceUserValue(BigDecimal::of('0.5'));
        $orderdetail = new Orderdetail();
        $orderdetail->setSupplier($this->em->getRepository(Supplier::class)->findOneBy([]));
        $orderdetail->addPricedetail((new Pricedetail())->setMinDiscountQuantity(1.0)->setPrice(BigDecimal::of('0.12')));
        $meta->getPartConnections()->first()->getConnectedPart()->addOrderdetail($orderdetail);
        $this->em->flush();

        $this->connect();
        $tools = array_column($this->send(['id' => $this->nextId++, 'method' => 'tools/list'])['result']['tools'], null, 'name');
        foreach (['get_part_details', 'create_part', 'update_part'] as $tool) {
            foreach (['priceMethod', 'userPrice', 'userPriceCurrencyId'] as $input) {
                if ($tool !== 'get_part_details') {
                    self::assertArrayHasKey($input, $tools[$tool]['inputSchema']['properties'], $tool);
                }
            }
            self::assertStringContainsString('price', $tools[$tool]['description'], $tool);
        }

        $part = $this->call('get_part_details', ['id' => $meta->getID()])['structuredContent'];
        self::assertSame('cheapest', $part['price_method']);
        self::assertTrue(BigDecimal::of($part['user_price'])->isEqualTo('0.5'));
        self::assertTrue(BigDecimal::of($part['price'])->isEqualTo('0.12'), 'The price is worked out by the method, got '.$part['price']);
        $this->assertMatchesSchema($part, $tools['get_part_details']['outputSchema'], 'get_part_details');

        $updated = $this->call('update_part', ['id' => $meta->getID(), 'priceMethod' => 'user_value'])['structuredContent'];
        self::assertSame('user_value', $updated['price_method']);
        self::assertTrue(BigDecimal::of($updated['price'])->isEqualTo('0.5'));

        $search = $this->call('search_parts', ['keyword' => 'Meta over MCP']);
        self::assertFalse($search['isError'] ?? false, json_encode($search));
        self::assertStringNotContainsString('"price":', str_replace(' ', '', json_encode($search, JSON_THROW_ON_ERROR)));
    }

    public function testSearchToolsShowTheKind(): void
    {
        $this->makeMetaPart();
        $this->connect();

        $simple = $this->call('search_parts', ['keyword' => 'Meta over MCP']);
        self::assertFalse($simple['isError'] ?? false, json_encode($simple));
        self::assertStringContainsString('"kind":"meta"', str_replace(' ', '', json_encode($simple, JSON_THROW_ON_ERROR)));

        $advanced = $this->call('advanced_search_parts', ['kind' => 'meta', 'name' => ['operator' => '=', 'value' => 'Meta over MCP']]);
        self::assertFalse($advanced['isError'] ?? false, json_encode($advanced));
        self::assertStringContainsString('Preferred', json_encode($advanced, JSON_THROW_ON_ERROR));
    }

    public function testProjectDetailsShowTheKindOfTheParts(): void
    {
        $meta = $this->makeMetaPart();
        $entry = new ProjectBOMEntry();
        $entry->setPart($meta);
        $entry->setQuantity(2);
        $project = new Project();
        $project->setName('Project over MCP');
        $project->addBomEntry($entry);
        $this->em->persist($project);
        $this->em->flush();

        $this->connect();
        $result = $this->call('get_project_details', ['id' => $project->getID()]);

        self::assertFalse($result['isError'] ?? false, json_encode($result));
        self::assertSame('meta', $result['structuredContent']['bom_entries'][0]['part']['kind']);
    }

    public function testAReadOnlyTokenCanReadAConnectionButNotWriteOne(): void
    {
        $meta = $this->makeMetaPart();
        $other = $this->makePart('Not to be connected');
        $this->em->flush();

        $this->connect(APITokenFixtures::TOKEN_READONLY);

        $read = $this->call('get_part_details', ['id' => $meta->getID()]);
        self::assertFalse($read['isError'] ?? false, json_encode($read));

        //The security of the tool stops this before the processor, so it is an error of the protocol, not of the tool
        $write = $this->send(['id' => $this->nextId++, 'method' => 'tools/call', 'params' => [
            'name' => 'update_part',
            'arguments' => ['id' => $meta->getID(), 'connectedParts' => [['connectedPartId' => $other->getID()]]],
        ]]);
        self::assertArrayNotHasKey('result', $write, json_encode($write));
        self::assertSame('Access Denied.', $write['error']['message'] ?? null);

        $this->em->clear();
        self::assertCount(1, $this->em->find(Part::class, $meta->getID())->getPartConnections());
    }

    public function testAManufacturingPartIsNotAskedForConnections(): void
    {
        $id = $this->makeMetaPart()->getPartConnections()->first()->getConnectedPart()->getID();
        $this->em->clear();
        $part = $this->em->find(Part::class, $id);

        self::assertSame([], $part->getConnectionsOverview());

        $connections = $part->getPartConnections();
        self::assertInstanceOf(PersistentCollection::class, $connections);
        self::assertFalse($connections->isInitialized(), 'No query for a part that can not hold connections');
    }
}
