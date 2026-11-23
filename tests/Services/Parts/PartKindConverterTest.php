<?php
/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2025 Jan Böhmer (https://github.com/jbtronics)
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


namespace App\Tests\Services\Parts;

use App\Entity\Attachments\AttachmentType;
use App\Entity\Attachments\PartAttachment;
use App\Entity\Contracts\LogWithCommentInterface;
use App\Entity\LogSystem\AbstractLogEntry;
use App\Entity\Parameters\PartParameter;
use App\Entity\Parts\Category;
use App\Entity\Parts\Manufacturer;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartConnectionStatus;
use App\Entity\Parts\PartKind;
use App\Entity\Parts\PartLot;
use App\Entity\Parts\PartPriceMethod;
use App\Entity\Parts\StorageLocation;
use App\Entity\Parts\Supplier;
use App\Entity\PriceInformations\Currency;
use App\Entity\PriceInformations\Orderdetail;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use App\Exceptions\PartConversionException;
use App\Services\Parts\ConversionKeep;
use App\Services\Parts\ConversionPreview;
use App\Services\Parts\PartKindConverter;
use Brick\Math\BigDecimal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PartKindConverterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PartKindConverter $converter;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->converter = self::getContainer()->get(PartKindConverter::class);
    }

    private function part(string $name, PartKind $kind = PartKind::MANUFACTURING): Part
    {
        $part = (new Part())->setKind($kind);
        $part->setName($name);
        $part->setCategory($this->em->find(Category::class, 1));
        $this->em->persist($part);

        return $part;
    }

    private function lot(Part $part, float $amount, ?StorageLocation $location = null): PartLot
    {
        $lot = (new PartLot())->setAmount($amount)->setDescription('Lot of '.$amount);
        if ($location instanceof StorageLocation) {
            $lot->setStorageLocation($location);
        }
        $part->addPartLot($lot);

        return $lot;
    }

    private function useIn(Part $part, string $projectName): ProjectBOMEntry
    {
        $project = new Project();
        $project->setName($projectName);
        $entry = (new ProjectBOMEntry())->setQuantity(2.0);
        $project->addBomEntry($entry);
        $part->addProjectBomEntry($entry);
        $this->em->persist($project);

        return $entry;
    }

    private function connect(Part $meta, Part $part, int $position, PartConnectionStatus $status = PartConnectionStatus::APPROVED, string $note = ''): void
    {
        $meta->addPartConnection((new PartConnection())->setConnectedPart($part)->setPosition($position)->setStatus($status)->setNote($note));
    }

    /**
     * A manufacturing part with all a conversion touches: manufacturer data, two lots, a supplier, a parameter, an
     * attachment, an IPN, a BOM entry and a connection to a meta part at position 3, use up, with a note.
     */
    private function fullPart(string $ipn = 'CONV-1'): Part
    {
        $part = $this->part('Converted part');
        $part->setDescription('10k 0603');
        $part->setComment('A comment');
        $part->setTags('resistor,smd');
        $part->setMinAmount(25);
        $part->setIpn($ipn);
        $part->setManufacturer($this->em->find(Manufacturer::class, 1));
        $part->setManufacturerProductNumber('RC0603-10K');
        $part->setGtin('4006381333931');
        $part->getEdaInfo()->setReferencePrefix('R');
        $this->lot($part, 100);
        $this->lot($part, 50);
        $orderdetail = (new Orderdetail())->setSupplier($this->em->find(Supplier::class, 1))->setSupplierpartnr('SUP-10K');
        $part->addOrderdetail($orderdetail);
        $part->addParameter((new PartParameter())->setName('Resistance')->setValueTypical(10000.0)->setUnit('Ω'));
        $part->addAttachment((new PartAttachment())->setName('Datasheet')->setAttachmentType($this->em->find(AttachmentType::class, 1))
            ->setExternalPath('https://example.invalid/datasheet.pdf'));
        $this->useIn($part, 'Board using the part');
        $this->connect($this->part('Existing meta', PartKind::META), $part, 3, PartConnectionStatus::USE_UP, 'Second source');
        $this->em->flush();

        //Loaded again, as a request would, so that the connection is also in the collection of the part
        return $this->reload($part);
    }

    private function reload(Part $part): Part
    {
        $id = $part->getID();
        $this->em->clear();

        return $this->em->find(Part::class, $id);
    }

    private function countPartsNamed(string $name): int
    {
        return $this->em->getRepository(Part::class)->count(['name' => $name]);
    }

    public function testKeepingTheManufacturingPartCreatesAConnectedMetaPartWithCopies(): void
    {
        $part = $this->fullPart();
        $id = $part->getID();
        $lotIds = $part->getPartLots()->map(static fn (PartLot $lot) => $lot->getID())->toArray();

        $meta = $this->converter->convertToMeta($part, ConversionKeep::MANUFACTURING_KEEPS_ID);
        $metaId = $meta->getID();
        $this->assertNotNull($metaId);
        $this->assertNotSame($id, $metaId);

        $part = $this->reload($part);
        $meta = $this->em->find(Part::class, $metaId);

        //The part is unchanged, and keeps its BOM entry and the connection above it
        $this->assertSame(PartKind::MANUFACTURING, $part->getKind());
        $this->assertSame('RC0603-10K', $part->getManufacturerProductNumber());
        $this->assertSame('CONV-1', $part->getIpn());
        $this->assertEqualsCanonicalizing($lotIds, $part->getPartLots()->map(static fn (PartLot $lot) => $lot->getID())->toArray());
        $this->assertCount(1, $part->getOrderdetails());
        $this->assertCount(1, $part->getProjectBomEntries());
        $this->assertCount(2, $part->getConnectionsAsPart());

        //The meta part holds copies of the descriptive fields, parameters and attachments, and nothing else
        $this->assertSame(PartKind::META, $meta->getKind());
        $this->assertSame('Converted part', $meta->getName());
        $this->assertSame('10k 0603', $meta->getDescription());
        $this->assertSame('A comment', $meta->getComment());
        $this->assertSame('resistor,smd', $meta->getTags());
        $this->assertSame($part->getCategory(), $meta->getCategory());
        $this->assertSame(25.0, $meta->getMinAmount());
        $this->assertSame('R', $meta->getEdaInfo()->getReferencePrefix());
        $this->assertNull($meta->getIpn());
        $this->assertNull($meta->getManufacturer());
        $this->assertCount(0, $meta->getPartLots());
        $this->assertCount(0, $meta->getProjectBomEntries());
        $this->assertSame(['Resistance'], $meta->getParameters()->map(static fn (PartParameter $p) => $p->getName())->getValues());
        $this->assertCount(1, $part->getParameters());
        $this->assertSame(['Datasheet'], $meta->getAttachments()->map(static fn (PartAttachment $a) => $a->getName())->getValues());
        $this->assertCount(1, $part->getAttachments());

        //One approved connection at position 1
        $this->assertCount(1, $meta->getPartConnections());
        $connection = $meta->getPartConnections()->first();
        $this->assertSame($part, $connection->getConnectedPart());
        $this->assertSame(1, $connection->getPosition());
        $this->assertSame(PartConnectionStatus::APPROVED, $connection->getStatus());
    }

    public function testMakingThePartTheMetaPartMovesItsManufacturerDataLotsSuppliersAndConnections(): void
    {
        $part = $this->fullPart();
        $id = $part->getID();
        $lotIds = $part->getPartLots()->map(static fn (PartLot $lot) => $lot->getID())->toArray();
        $orderdetailId = $part->getOrderdetails()->first()->getID();
        $bomEntryId = $part->getProjectBomEntries()->first()->getID();

        $meta = $this->converter->convertToMeta($part, ConversionKeep::META_KEEPS_ID);
        $this->assertSame($id, $meta->getID());

        $meta = $this->reload($part);
        $this->assertSame(PartKind::META, $meta->getKind());
        $this->assertNull($meta->getManufacturer());
        $this->assertSame('', $meta->getManufacturerProductNumber());
        $this->assertNull($meta->getGtin());
        $this->assertCount(0, $meta->getPartLots());
        $this->assertCount(0, $meta->getOrderdetails());
        $this->assertCount(0, $meta->getConnectionsAsPart());
        $this->assertSame('CONV-1', $meta->getIpn());
        $this->assertSame(25.0, $meta->getMinAmount());
        $this->assertSame([$bomEntryId], $meta->getProjectBomEntries()->map(static fn (ProjectBOMEntry $e) => $e->getID())->getValues());

        $this->assertCount(1, $meta->getPartConnections());
        $connection = $meta->getPartConnections()->first();
        $this->assertSame(1, $connection->getPosition());
        $this->assertSame(PartConnectionStatus::APPROVED, $connection->getStatus());

        $new = $connection->getConnectedPart();
        $this->assertSame(PartKind::MANUFACTURING, $new->getKind());
        $this->assertSame('Converted part', $new->getName());
        $this->assertSame('10k 0603', $new->getDescription());
        $this->assertSame('RC0603-10K', $new->getManufacturerProductNumber());
        $this->assertSame('4006381333931', $new->getGtin());
        $this->assertSame($this->em->find(Manufacturer::class, 1), $new->getManufacturer());
        $this->assertNull($new->getIpn());
        $this->assertSame(0.0, $new->getMinAmount());
        $this->assertEqualsCanonicalizing($lotIds, $new->getPartLots()->map(static fn (PartLot $lot) => $lot->getID())->toArray());
        $this->assertSame([$orderdetailId], $new->getOrderdetails()->map(static fn (Orderdetail $o) => $o->getID())->getValues());
        $this->assertCount(1, $new->getParameters());
        $this->assertCount(1, $new->getAttachments());
        $this->assertCount(1, $meta->getAttachments());

        //The connection to the other meta part moved, keeping position, status and note
        $moved = array_values(array_filter($new->getConnectionsAsPart()->toArray(), static fn (PartConnection $c) => $c->getMetaPart() !== $meta));
        $this->assertCount(1, $moved);
        $this->assertSame('Existing meta', $moved[0]->getMetaPart()->getName());
        $this->assertSame(3, $moved[0]->getPosition());
        $this->assertSame(PartConnectionStatus::USE_UP, $moved[0]->getStatus());
        $this->assertSame('Second source', $moved[0]->getNote());
        $this->assertCount(1, $moved[0]->getMetaPart()->getPartConnections());
    }

    public function testAConversionWhoseNewPartFailsValidationChangesNothing(): void
    {
        //A location limited to existing parts takes no lots of a new part, so the lot can not move to the new part
        $location = (new StorageLocation())->setName('Drawer for existing parts');
        $this->em->persist($location);
        $part = $this->part('Part in a limited drawer');
        $part->setManufacturerProductNumber('MPN-LIMITED');
        $this->lot($part, 10, $location);
        $this->em->flush();
        $location->setLimitToExistingParts(true);
        $this->em->flush();

        try {
            $this->converter->convertToMeta($part, ConversionKeep::META_KEEPS_ID);
            $this->fail('The conversion was not refused.');
        } catch (PartConversionException $exception) {
            $this->assertStringContainsString('Part in a limited drawer', $exception->getMessage());
            $this->assertSame('part.convert.error.invalid', $exception->getTranslatableMessage()->getMessage());
        }

        $part = $this->reload($part);
        $this->assertSame(PartKind::MANUFACTURING, $part->getKind());
        $this->assertSame('MPN-LIMITED', $part->getManufacturerProductNumber());
        $this->assertCount(1, $part->getPartLots());
        $this->assertSame(1, $this->countPartsNamed('Part in a limited drawer'));
        //Nothing left in memory is saved by a later flush
        $this->em->flush();
        $this->assertSame(1, $this->countPartsNamed('Part in a limited drawer'));
    }

    public function testALotInAFullLocationMovesToTheNewPart(): void
    {
        //Moving the lot adds nothing to the full location
        $location = (new StorageLocation())->setName('Full drawer');
        $this->em->persist($location);
        $part = $this->part('Part in a full drawer');
        $lot = $this->lot($part, 10, $location);
        $this->em->flush();
        $location->setIsFull(true);
        $this->em->flush();
        $lotId = $lot->getID();

        $meta = $this->converter->convertToMeta($this->reload($part), ConversionKeep::META_KEEPS_ID);

        $meta = $this->reload($meta);
        $this->assertCount(0, $meta->getPartLots());
        $new = $meta->getPartConnections()->first()->getConnectedPart();
        $this->assertSame([$lotId], $new->getPartLots()->map(static fn (PartLot $l) => $l->getID())->getValues());
        $this->assertSame(10.0, $new->getPartLots()->first()->getAmount());
    }

    public function testAManualOrderOfLegacyDataMovesToTheNewPart(): void
    {
        //Only data of legacy Part-DB has an order detail chosen for a manual order, in a column that is unique
        $part = $this->part('Part with a manual order');
        $orderdetail = (new Orderdetail())->setSupplier($this->em->find(Supplier::class, 1))->setSupplierpartnr('SUP-MANUAL');
        $part->addOrderdetail($orderdetail);
        $this->em->flush();
        $part->setManualOrder(true, 7, $orderdetail);
        $this->em->flush();
        $orderdetailId = $orderdetail->getID();

        $meta = $this->converter->convertToMeta($this->reload($part), ConversionKeep::META_KEEPS_ID);

        $meta = $this->reload($meta);
        $this->assertFalse($meta->isMarkedForManualOrder());
        $this->assertNull($meta->getOrderOrderdetails());
        $new = $meta->getPartConnections()->first()->getConnectedPart();
        $this->assertTrue($new->isMarkedForManualOrder());
        $this->assertSame(7, $new->getOrderQuantity());
        $this->assertSame($orderdetailId, $new->getOrderOrderdetails()?->getID());
    }

    public function testThePreviewOfEachChoiceMatchesTheResult(): void
    {
        foreach (ConversionKeep::cases() as $keep) {
            $part = $this->fullPart('CONV-'.$keep->value);
            $preview = $this->converter->previewToMeta($part, $keep);
            $this->assertSame($keep, $preview->getKeep());
            $moves = $keep === ConversionKeep::META_KEEPS_ID;

            $this->assertSame(['action' => ConversionPreview::KEPT, 'count' => 1], array_slice($preview->getRow('bom_entries'), 1));
            $this->assertSame(['action' => $moves ? ConversionPreview::MOVED : ConversionPreview::KEPT, 'count' => 2], array_slice($preview->getRow('lots'), 1));
            $this->assertSame(['action' => $moves ? ConversionPreview::MOVED : ConversionPreview::KEPT, 'count' => 1], array_slice($preview->getRow('orderdetails'), 1));
            $this->assertSame(['action' => $moves ? ConversionPreview::MOVED : ConversionPreview::KEPT, 'count' => 1], array_slice($preview->getRow('connections'), 1));
            $this->assertSame($moves ? ConversionPreview::MOVED : ConversionPreview::KEPT, $preview->getRow('manufacturer_data')['action']);
            $this->assertSame(ConversionPreview::COPIED, $preview->getRow('parameters')['action']);
            $this->assertNotNull($preview->getRow($moves ? 'new_manufacturing_part' : 'new_meta_part'));
            //The preview changes nothing
            $this->assertSame(PartKind::MANUFACTURING, $part->getKind());

            $meta = $this->converter->convertToMeta($part, $keep);
            $new = $moves ? $meta->getPartConnections()->first()->getConnectedPart() : $meta;
            $holder = $moves ? $new : $part;
            $this->assertCount($preview->getRow('lots')['count'], $holder->getPartLots());
            $this->assertCount($preview->getRow('orderdetails')['count'], $holder->getOrderdetails());
            $this->assertCount($preview->getRow('bom_entries')['count'], $part->getProjectBomEntries());
            $this->assertCount($preview->getRow('parameters')['count'], $new->getParameters());
            $this->assertCount($preview->getRow('attachments')['count'], $new->getAttachments());
            //The connection to the new meta part is added to those above the part kept as manufacturing part
            $this->assertCount($preview->getRow('connections')['count'] + 1, ($moves ? $new : $part)->getConnectionsAsPart());
        }
    }

    public function testAMetaPartCanNotBeConvertedToAMetaPart(): void
    {
        $meta = $this->part('Already meta', PartKind::META);
        $this->em->flush();

        foreach (ConversionKeep::cases() as $keep) {
            try {
                $this->converter->convertToMeta($meta, $keep);
                $this->fail('The conversion was not refused.');
            } catch (PartConversionException $exception) {
                $this->assertSame('part.convert.error.not_to_meta', $exception->getTranslatableMessage()->getMessage());
            }
        }

        $this->expectException(PartConversionException::class);
        $this->converter->previewToMeta($meta, ConversionKeep::MANUFACTURING_KEEPS_ID);
    }

    public function testARefusedConversionChangesNothing(): void
    {
        $meta = $this->part('Already meta, unchanged', PartKind::META);
        $this->em->flush();
        $count = $this->em->getRepository(Part::class)->count([]);

        try {
            $this->converter->convertToMeta($meta, ConversionKeep::META_KEEPS_ID);
        } catch (PartConversionException) {
        }

        $this->assertSame(PartKind::META, $this->reload($meta)->getKind());
        $this->assertSame($count, $this->em->getRepository(Part::class)->count([]));
    }

    public function testConvertingBackKeepsIdLotsAndBomEntriesAndResetsThePrice(): void
    {
        $meta = $this->part('Meta going back', PartKind::META);
        $meta->setPriceMethod(PartPriceMethod::USER_VALUE);
        $meta->setPriceUserValue(BigDecimal::of('1.5'));
        $meta->setPriceUserCurrency($this->em->find(Currency::class, 1));
        $this->lot($meta, 30);
        $this->useIn($meta, 'Board using the meta part');
        $this->em->flush();
        $id = $meta->getID();

        $this->assertSame([ConversionPreview::CHANGED, ConversionPreview::RESET, 1, 1],
            [$this->converter->previewToManufacturing($meta)->getRow('kind')['action'], $this->converter->previewToManufacturing($meta)->getRow('price_method')['action'],
                $this->converter->previewToManufacturing($meta)->getRow('lots')['count'], $this->converter->previewToManufacturing($meta)->getRow('bom_entries')['count']]);

        $this->converter->convertToManufacturing($meta);

        $part = $this->reload($meta);
        $this->assertSame($id, $part->getID());
        $this->assertSame(PartKind::MANUFACTURING, $part->getKind());
        $this->assertNull($part->getPriceUserValue());
        $this->assertNull($part->getPriceUserCurrency());
        $this->assertSame(PartPriceMethod::SYSTEM_DEFAULT->value, $this->em->getConnection()
            ->fetchOne('SELECT price_method FROM parts WHERE id = ?', [$id]));
        $this->assertCount(1, $part->getPartLots());
        $this->assertSame(30.0, $part->getPartLots()->first()->getAmount());
        $this->assertCount(1, $part->getProjectBomEntries());
    }

    public function testConvertingBackIsRefusedWithConnectedPartsNamingThem(): void
    {
        $meta = $this->part('Meta with two parts', PartKind::META);
        $this->connect($meta, $this->part('Second choice'), 2);
        $this->connect($meta, $this->part('First choice'), 1);
        $this->em->flush();

        try {
            $this->converter->convertToManufacturing($meta);
            $this->fail('The conversion was not refused.');
        } catch (PartConversionException $exception) {
            $this->assertSame(['First choice', 'Second choice'], $exception->getConnectedPartNames());
            $this->assertStringContainsString('First choice, Second choice', $exception->getMessage());
        }

        $meta = $this->reload($meta);
        $this->assertSame(PartKind::META, $meta->getKind());
        $this->assertCount(2, $meta->getPartConnections());
    }

    public function testAManufacturingPartCanNotBeConvertedBack(): void
    {
        $part = $this->part('Not a meta part');
        $this->em->flush();

        $this->expectException(PartConversionException::class);
        $this->converter->convertToManufacturing($part);
    }

    public function testEachChangedPartIsLoggedWithTheComment(): void
    {
        $part = $this->fullPart();
        $meta = $this->converter->convertToMeta($part, ConversionKeep::META_KEEPS_ID);
        $new = $meta->getPartConnections()->first()->getConnectedPart();
        //The mark is gone afterwards, so a later change of the kind is refused again
        $this->assertFalse($meta->isKindConversionAllowed());

        $ids = [$meta->getID(), $new->getID()];
        $this->em->clear();
        foreach (array_map(fn (int $id): Part => $this->em->find(Part::class, $id), $ids) as $changed) {
            $entries = $this->em->getRepository(AbstractLogEntry::class)->getElementHistory($changed);
            $comments = array_map(static fn (AbstractLogEntry $entry) => $entry instanceof LogWithCommentInterface ? $entry->getComment() : null, $entries);
            $this->assertContains('Converted to use a meta part: this part became the meta part above a new manufacturing part', $comments);
        }
    }
}
