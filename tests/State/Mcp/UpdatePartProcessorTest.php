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

namespace App\Tests\State\Mcp;

use ApiPlatform\Metadata\Patch;
use App\Entity\Parts\Category;
use App\Entity\Parts\InfoProviderReference;
use App\Entity\Parts\ManufacturingStatus;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnectionStatus;
use App\Entity\Parts\PartKind;
use App\Entity\Parts\PartLot;
use App\Entity\Parts\PartPriceMethod;
use App\Entity\Parts\StorageLocation;
use App\Entity\UserSystem\User;
use App\Entity\PriceInformations\Currency;
use App\Mcp\DTO\UpdatePartInput;
use App\Settings\AISettings\McpSettings;
use App\State\Mcp\UpdatePartProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class UpdatePartProcessorTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UpdatePartProcessor $processor;
    private McpSettings $mcpSettings;
    private Part $part;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $userRepository = self::getContainer()->get('doctrine')->getRepository(User::class);
        $admin = $userRepository->findOneBy(['name' => 'admin']);
        $this->client->loginUser($admin);

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->processor = self::getContainer()->get(UpdatePartProcessor::class);

        //Editing MCP tools is disabled by default (McpSettings::$editingEnabled) - enable it for these tests,
        //which exercise the tool's actual behavior; testDeniesAccessWhenEditingToolsDisabled() covers the guard itself.
        $this->mcpSettings = self::getContainer()->get(McpSettings::class);
        $this->mcpSettings->editingEnabled = true;

        $category = $this->em->getRepository(Category::class)->findOneBy(['name' => 'Node 1']);
        self::assertNotNull($category);

        $this->part = new Part();
        $this->part->setName('MCP Update Test Part');
        $this->part->setDescription('Original description');
        $this->part->setCategory($category);
        $this->em->persist($this->part);
        $this->em->flush();
    }

    private function getOperation(): Patch
    {
        return new Patch();
    }

    /**
     * Builds an UpdatePartInput from a raw arguments array the same way UpdatePartInputProvider does,
     * so tests exercise the real "only provided keys are applied" semantics.
     */
    private function buildInput(array $data): UpdatePartInput
    {
        return UpdatePartInput::fromArray($data);
    }

    /**
     * Expected failures (permission denied, not found, bad input) are reported as a successful CallToolResult
     * with isError:true and an explanatory text - not as a thrown exception. See McpToolErrorHandling.
     */
    private function assertErrorResult(mixed $result, string $messageContains): void
    {
        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue($result->isError);
        self::assertInstanceOf(TextContent::class, $result->content[0]);
        self::assertStringContainsString($messageContains, $result->content[0]->text);
    }

    public function testUpdatingOnlyNameLeavesOtherFieldsUntouched(): void
    {
        $input = $this->buildInput(['id' => $this->part->getID(), 'name' => 'Renamed Part']);

        $result = $this->processor->process($input, $this->getOperation());

        self::assertSame('Renamed Part', $result->getName());
        self::assertSame('Original description', $result->getDescription());
    }

    public function testReconcilesPartLotsByIdCreateUpdateRemove(): void
    {
        $storageLocation = $this->em->getRepository(StorageLocation::class)->findOneBy(['name' => 'Node 1']);
        $storageLocation2 = $this->em->getRepository(StorageLocation::class)->findOneBy(['name' => 'Node 2']);
        self::assertNotNull($storageLocation);
        self::assertNotNull($storageLocation2);

        $keepLot = new PartLot();
        $keepLot->setDescription('Keep me (updated)');
        $keepLot->setAmount(1.0);
        $this->part->addPartLot($keepLot);

        $removeLot = new PartLot();
        $removeLot->setDescription('Remove me');
        $removeLot->setAmount(2.0);
        $this->part->addPartLot($removeLot);

        $this->em->flush();
        $keepLotId = $keepLot->getID();
        $removeLotId = $removeLot->getID();
        self::assertNotNull($keepLotId);
        self::assertNotNull($removeLotId);

        //Omit the "removeLot" entirely -> it must be removed. Update "keepLot" by id. Add a brand-new lot (no id).
        $input = $this->buildInput([
            'id' => $this->part->getID(),
            'partLots' => [
                ['id' => $keepLotId, 'storageLocationId' => $storageLocation->getID()],
                ['description' => 'New lot', 'storageLocationId' => $storageLocation2->getID(), 'amount' => 5.0],
            ],
        ]);

        $result = $this->processor->process($input, $this->getOperation());

        self::assertCount(2, $result->getPartLots());
        $descriptions = array_map(static fn (PartLot $lot) => $lot->getDescription(), $result->getPartLots()->toArray());
        self::assertContains('Keep me (updated)', $descriptions);
        self::assertContains('New lot', $descriptions);
        self::assertNotContains('Remove me', $descriptions);
    }

    public function testRejectsSettingAmountOnExistingLot(): void
    {
        $lot = new PartLot();
        $lot->setDescription('Existing lot');
        $lot->setAmount(1.0);
        $this->part->addPartLot($lot);
        $this->em->flush();

        $input = $this->buildInput([
            'id' => $this->part->getID(),
            'partLots' => [
                ['id' => $lot->getID(), 'amount' => 999.0],
            ],
        ]);

        $result = $this->processor->process($input, $this->getOperation());

        $this->assertErrorResult($result, 'withdraw_part_stock');
    }

    public function testExplicitNullManufacturingStatusResetsToNotSet(): void
    {
        $this->part->setManufacturingStatus(ManufacturingStatus::ACTIVE);
        $this->em->flush();

        //Explicitly providing null must actually clear the field, not silently no-op like an omitted key would
        $input = $this->buildInput(['id' => $this->part->getID(), 'manufacturingStatus' => null]);

        $result = $this->processor->process($input, $this->getOperation());

        self::assertSame(ManufacturingStatus::NOT_SET, $result->getManufacturingStatus());
    }

    public function testLinksPartToInfoProvider(): void
    {
        $input = $this->buildInput([
            'id' => $this->part->getID(),
            'providerKey' => 'digikey',
            'providerId' => 'ABC123',
            'providerUrl' => 'https://example.com/abc123',
        ]);

        $result = $this->processor->process($input, $this->getOperation());

        self::assertTrue($result->getProviderReference()->isProviderCreated());
        self::assertSame('digikey', $result->getProviderReference()->getProviderKey());
        self::assertSame('ABC123', $result->getProviderReference()->getProviderId());
        self::assertSame('https://example.com/abc123', $result->getProviderReference()->getProviderUrl());
        self::assertNotNull($result->getProviderReference()->getLastUpdated());
    }

    public function testUnlinksPartFromInfoProvider(): void
    {
        $this->part->setProviderReference(InfoProviderReference::providerReference('digikey', 'ABC123'));
        $this->em->flush();

        $input = $this->buildInput(['id' => $this->part->getID(), 'providerKey' => null, 'providerId' => null]);

        $result = $this->processor->process($input, $this->getOperation());

        self::assertFalse($result->getProviderReference()->isProviderCreated());
        self::assertNull($result->getProviderReference()->getProviderKey());
        self::assertNull($result->getProviderReference()->getProviderId());
    }

    public function testMismatchedProviderKeyAndIdFailsValidation(): void
    {
        //providerId without providerKey is an invalid combination (InfoProviderReference::validate())
        $input = $this->buildInput(['id' => $this->part->getID(), 'providerId' => 'ABC123']);

        $result = $this->processor->process($input, $this->getOperation());

        $this->assertErrorResult($result, 'provider_key');
    }

    public function testOmittingProviderFieldsLeavesReferenceUntouched(): void
    {
        $this->part->setProviderReference(InfoProviderReference::providerReference('digikey', 'ABC123'));
        $this->em->flush();

        $input = $this->buildInput(['id' => $this->part->getID(), 'name' => 'Renamed but still linked']);

        $result = $this->processor->process($input, $this->getOperation());

        self::assertTrue($result->getProviderReference()->isProviderCreated());
        self::assertSame('digikey', $result->getProviderReference()->getProviderKey());
    }

    public function testThrowsNotFoundForUnknownPartId(): void
    {
        $input = $this->buildInput(['id' => 999999999, 'name' => 'Does not matter']);
        $result = $this->processor->process($input, $this->getOperation());

        $this->assertErrorResult($result, 'not found');
    }

    public function testChangingTheKindIsAToolErrorNamingTheField(): void
    {
        $result = $this->processor->process($this->buildInput(['id' => $this->part->getID(), 'kind' => 'meta', 'name' => 'Should not change']), $this->getOperation());

        $this->assertErrorResult($result, 'kind');
        $this->em->clear();
        $stored = $this->em->find(Part::class, $this->part->getID());
        self::assertSame(PartKind::MANUFACTURING, $stored->getKind());
        self::assertSame('MCP Update Test Part', $stored->getName());
    }

    public function testSendingTheSameKindIsAccepted(): void
    {
        $result = $this->processor->process($this->buildInput(['id' => $this->part->getID(), 'kind' => 'manufacturing', 'name' => 'Renamed']), $this->getOperation());

        self::assertInstanceOf(Part::class, $result);
        self::assertSame('Renamed', $result->getName());
    }

    private function makePart(string $name, PartKind $kind = PartKind::MANUFACTURING): Part
    {
        $part = new Part();
        $part->setName($name);
        $part->setKind($kind);
        $part->setCategory($this->part->getCategory());
        $this->em->persist($part);
        $this->em->flush();

        return $part;
    }

    public function testConnectionsAreAddedChangedAndLeftOutOnesRemoved(): void
    {
        $meta = $this->makePart('Meta', PartKind::META);
        [$kept, $removed, $added] = [$this->makePart('Kept'), $this->makePart('Removed'), $this->makePart('Added')];

        $result = $this->processor->process($this->buildInput(['id' => $meta->getID(), 'connectedParts' => [
            ['connectedPartId' => $kept->getID()],
            ['connectedPartId' => $removed->getID()],
        ]]), $this->getOperation());
        self::assertInstanceOf(Part::class, $result);
        self::assertSame([1, 2], array_map(static fn ($c) => $c->getPosition(), $result->getOrderedConnections()), 'Next free positions, in the order given');

        $keptId = $result->getOrderedConnections()[0]->getID();
        $result = $this->processor->process($this->buildInput(['id' => $meta->getID(), 'connectedParts' => [
            ['id' => $keptId, 'status' => 'do_not_use', 'note' => 'Retired', 'position' => 5],
            ['connectedPartId' => $added->getID(), 'position' => 1],
        ]]), $this->getOperation());
        self::assertInstanceOf(Part::class, $result);

        $this->em->clear();
        $connections = $this->em->find(Part::class, $meta->getID())->getOrderedConnections();
        self::assertCount(2, $connections);
        self::assertSame('Added', $connections[0]->getConnectedPart()->getName());
        self::assertSame($keptId, $connections[1]->getID());
        self::assertSame(PartConnectionStatus::DO_NOT_USE, $connections[1]->getStatus());
        self::assertSame('Retired', $connections[1]->getNote());
        self::assertSame(5, $connections[1]->getPosition());

        //Leaving the key out touches nothing, an empty list removes all of them
        $this->processor->process($this->buildInput(['id' => $meta->getID(), 'name' => 'Still connected']), $this->getOperation());
        self::assertCount(2, $this->em->find(Part::class, $meta->getID())->getPartConnections());
        $this->processor->process($this->buildInput(['id' => $meta->getID(), 'connectedParts' => []]), $this->getOperation());
        $this->em->clear();
        self::assertCount(0, $this->em->find(Part::class, $meta->getID())->getPartConnections());
    }

    public function testANewItemIsApprovedAndEachStatusIsStored(): void
    {
        $meta = $this->makePart('Meta', PartKind::META);
        $result = $this->processor->process($this->buildInput(['id' => $meta->getID(), 'connectedParts' => [
            ['connectedPartId' => $this->makePart('Connected')->getID()],
        ]]), $this->getOperation());
        self::assertInstanceOf(Part::class, $result);
        $connection = $result->getPartConnections()->first();
        self::assertSame(PartConnectionStatus::APPROVED, $connection->getStatus());
        $id = $connection->getID();

        foreach (PartConnectionStatus::cases() as $status) {
            $this->processor->process($this->buildInput(['id' => $meta->getID(), 'connectedParts' => [
                ['id' => $id, 'status' => $status->value],
            ]]), $this->getOperation());
            $this->em->clear();
            self::assertSame($status, $this->em->find(Part::class, $meta->getID())->getPartConnections()->first()->getStatus());
        }
    }

    public function testAnUnknownStatusIsAToolErrorNamingTheValidOnesAndChangesNothing(): void
    {
        $meta = $this->makePart('Meta', PartKind::META);
        $result = $this->processor->process($this->buildInput(['id' => $meta->getID(), 'connectedParts' => [
            ['connectedPartId' => $this->makePart('Connected')->getID(), 'status' => 'use_up', 'position' => 2],
        ]]), $this->getOperation());
        $id = $result->getPartConnections()->first()->getID();

        $result = $this->processor->process($this->buildInput(['id' => $meta->getID(), 'connectedParts' => [
            ['id' => $id, 'status' => 'unknown', 'position' => 7],
        ]]), $this->getOperation());

        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue($result->isError);
        self::assertInstanceOf(TextContent::class, $result->content[0]);
        foreach (['"unknown"', '"approved"', '"under_evaluation"', '"use_up"', '"do_not_use"'] as $value) {
            self::assertStringContainsString($value, $result->content[0]->text);
        }
        $this->em->clear();
        $connection = $this->em->find(Part::class, $meta->getID())->getPartConnections()->first();
        self::assertSame(PartConnectionStatus::USE_UP, $connection->getStatus());
        self::assertSame(2, $connection->getPosition());
    }

    /**
     * @return iterable<string, array{callable(int, int): list<array<string, mixed>>, string}>
     */
    public static function refusedConnections(): iterable
    {
        yield 'two equal rows' => [static fn (int $other): array => [['connectedPartId' => $other], ['connectedPartId' => $other]], 'connected'];
        yield 'unknown status' => [static fn (int $other): array => [['connectedPartId' => $other, 'status' => 'not-a-status']], 'not-a-status'];
        yield 'no part' => [static fn (int $other): array => [['note' => 'Nothing to connect']], 'connectedPartId'];
        yield 'unknown part' => [static fn (int $other): array => [['connectedPartId' => 99999999]], 'not found'];
        yield 'row of another part' => [static fn (int $other): array => [['id' => 99999999]], 'does not belong'];
        yield 'position of zero' => [static fn (int $other): array => [['connectedPartId' => $other, 'position' => 0]], 'position'];
    }

    #[DataProvider('refusedConnections')]
    public function testRefusedConnectionsAreToolErrorsAndChangeNothing(callable $rows, string $message): void
    {
        $meta = $this->makePart('Meta', PartKind::META);
        $other = $this->makePart('Other');

        $result = $this->processor->process($this->buildInput(['id' => $meta->getID(), 'connectedParts' => $rows($other->getID())]), $this->getOperation());

        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue($result->isError);
        self::assertInstanceOf(TextContent::class, $result->content[0]);
        self::assertStringContainsStringIgnoringCase($message, $result->content[0]->text);
        $this->em->clear();
        self::assertCount(0, $this->em->find(Part::class, $meta->getID())->getPartConnections());
    }

    public function testConnectingAMetaPartToAMetaPartIsAToolError(): void
    {
        $meta = $this->makePart('Meta', PartKind::META);
        $other = $this->makePart('Other meta', PartKind::META);

        $result = $this->processor->process($this->buildInput(['id' => $meta->getID(), 'connectedParts' => [['connectedPartId' => $other->getID()]]]), $this->getOperation());

        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue($result->isError);
    }

    public function testChangingThePartOfAConnectionIsAToolError(): void
    {
        $meta = $this->makePart('Meta', PartKind::META);
        [$first, $other] = [$this->makePart('First'), $this->makePart('Other')];
        $result = $this->processor->process($this->buildInput(['id' => $meta->getID(), 'connectedParts' => [['connectedPartId' => $first->getID()]]]), $this->getOperation());
        $id = $result->getPartConnections()->first()->getID();

        $result = $this->processor->process($this->buildInput(['id' => $meta->getID(), 'connectedParts' => [['id' => $id, 'connectedPartId' => $other->getID()]]]), $this->getOperation());

        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue($result->isError);
        $this->em->clear();
        self::assertSame('First', $this->em->find(Part::class, $meta->getID())->getPartConnections()->first()->getConnectedPart()->getName());
    }

    public function testAManufacturingPartHasNoConnections(): void
    {
        $other = $this->makePart('Other');

        $result = $this->processor->process($this->buildInput(['id' => $this->part->getID(), 'connectedParts' => [['connectedPartId' => $other->getID()]]]), $this->getOperation());

        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue($result->isError);
        $this->em->clear();
        self::assertCount(0, $this->em->find(Part::class, $this->part->getID())->getPartConnections());
    }

    public function testConnectionsAreNotWrittenWithEditingOffOrWithoutPermission(): void
    {
        $meta = $this->makePart('Meta', PartKind::META);
        $input = $this->buildInput(['id' => $meta->getID(), 'connectedParts' => [['connectedPartId' => $this->makePart('Other')->getID()]]]);

        $this->mcpSettings->editingEnabled = false;
        $this->assertErrorResult($this->processor->process($input, $this->getOperation()), 'Editing via MCP tools is disabled');

        $this->mcpSettings->editingEnabled = true;
        $this->client->loginUser(self::getContainer()->get('doctrine')->getRepository(User::class)->findOneBy(['name' => 'noread']));
        $this->assertErrorResult($this->processor->process($input, $this->getOperation()), 'Access denied');

        $this->em->clear();
        self::assertCount(0, $this->em->find(Part::class, $meta->getID())->getPartConnections());
    }

    public function testThePriceMethodAndUserPriceOfAMetaPartAreStoredAndNullResetsThem(): void
    {
        $meta = $this->makePart('Priced meta', PartKind::META);
        $currency = $this->em->getRepository(Currency::class)->findOneBy([]) ?? new Currency();
        if ($currency->getID() === null) {
            $currency->setName('MCP currency');
            $currency->setIsoCode('NOK');
            $this->em->persist($currency);
            $this->em->flush();
        }

        $result = $this->processor->process($this->buildInput(['id' => $meta->getID(), 'priceMethod' => 'average', 'userPrice' => '0.30',
            'userPriceCurrencyId' => $currency->getID()]), $this->getOperation());
        self::assertInstanceOf(Part::class, $result);
        $this->em->clear();
        $stored = $this->em->find(Part::class, $meta->getID());
        self::assertSame(PartPriceMethod::AVERAGE, $stored->getPriceMethod());
        self::assertTrue($stored->getPriceUserValue()?->isEqualTo('0.3'));
        self::assertSame($currency->getID(), $stored->getPriceUserCurrency()?->getID());

        //Leaving the keys out changes nothing
        $this->processor->process($this->buildInput(['id' => $meta->getID(), 'name' => 'Renamed meta']), $this->getOperation());
        $this->em->clear();
        self::assertSame(PartPriceMethod::AVERAGE, $this->em->find(Part::class, $meta->getID())->getPriceMethod());

        $this->processor->process($this->buildInput(['id' => $meta->getID(), 'priceMethod' => null, 'userPriceCurrencyId' => null]), $this->getOperation());
        $this->em->clear();
        $stored = $this->em->find(Part::class, $meta->getID());
        self::assertSame(PartPriceMethod::SYSTEM_DEFAULT, $stored->getPriceMethod());
        self::assertNull($stored->getPriceUserValue(), 'Null resets the method and clears the user price');
        self::assertNull($stored->getPriceUserCurrency());
    }

    public static function refusedPriceInputs(): iterable
    {
        yield 'an unknown method' => [['priceMethod' => 'range'], '"range" is not a valid price method'];
        yield 'a user price that is not a decimal' => [['userPrice' => 'abc'], '"abc" is not a valid price'];
    }

    #[DataProvider('refusedPriceInputs')]
    public function testRefusedPriceInputsAreToolErrorsAndSaveNothing(array $input, string $message): void
    {
        $meta = $this->makePart('Refusing meta', PartKind::META);
        $meta->setPriceMethod(PartPriceMethod::CHEAPEST);
        $this->em->flush();

        $result = $this->processor->process($this->buildInput(['id' => $meta->getID(), 'name' => 'Not saved'] + $input), $this->getOperation());

        $this->assertErrorResult($result, $message);
        $this->em->clear();
        $stored = $this->em->find(Part::class, $meta->getID());
        self::assertSame(PartPriceMethod::CHEAPEST, $stored->getPriceMethod());
        self::assertSame('Refusing meta', $stored->getName());
    }

    public function testTheUnknownMethodErrorNamesTheValidOnes(): void
    {
        $meta = $this->makePart('Refusing meta', PartKind::META);

        $result = $this->processor->process($this->buildInput(['id' => $meta->getID(), 'priceMethod' => 'range']), $this->getOperation());

        foreach (PartPriceMethod::cases() as $method) {
            $this->assertErrorResult($result, '"'.$method->value.'"');
        }
    }

    public static function priceFieldsOfAManufacturingPart(): iterable
    {
        yield 'a price method' => [['priceMethod' => 'cheapest'], 'priceMethod'];
        yield 'the system default price method' => [['priceMethod' => 'system_default'], 'priceMethod'];
        yield 'a user price' => [['userPrice' => '0.10'], 'priceUserValue'];
    }

    #[DataProvider('priceFieldsOfAManufacturingPart')]
    public function testAManufacturingPartRefusesThePriceFields(array $input, string $field): void
    {
        $result = $this->processor->process($this->buildInput(['id' => $this->part->getID()] + $input), $this->getOperation());

        $this->assertErrorResult($result, $field);
        $this->em->clear();
        $stored = $this->em->find(Part::class, $this->part->getID());
        self::assertNull($stored->getPriceUserValue());
        $table = $this->em->getConnection()->getDatabasePlatform()->quoteSingleIdentifier('parts');
        self::assertSame('system_default', $this->em->getConnection()->fetchOne("SELECT price_method FROM $table WHERE id = ?", [$this->part->getID()]));
    }

    public function testDeniesAccessForUserWithoutEditPermission(): void
    {
        $noreadUser = self::getContainer()->get('doctrine')->getRepository(User::class)->findOneBy(['name' => 'noread']);
        self::assertNotNull($noreadUser);
        $this->client->loginUser($noreadUser);

        $input = $this->buildInput(['id' => $this->part->getID(), 'name' => 'Should not change']);
        $result = $this->processor->process($input, $this->getOperation());

        $this->assertErrorResult($result, 'Access denied');
    }

    public function testDeniesAccessWhenEditingToolsDisabled(): void
    {
        $this->mcpSettings->editingEnabled = false;

        $input = $this->buildInput(['id' => $this->part->getID(), 'name' => 'Should not change']);
        $result = $this->processor->process($input, $this->getOperation());

        $this->assertErrorResult($result, 'Editing via MCP tools is disabled');
    }
}
