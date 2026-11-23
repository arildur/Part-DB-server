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


namespace App\Services\Parts;

use App\Entity\Parts\InfoProviderReference;
use App\Entity\Parts\ManufacturingStatus;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartConnectionStatus;
use App\Entity\Parts\PartKind;
use App\Entity\PriceInformations\Orderdetail;
use App\Exceptions\PartConversionException;
use App\Services\LogSystem\EventCommentHelper;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The conversions between the kinds of a part: a manufacturing part converted to use a meta part, with the choice of
 * which record keeps its ID, and a meta part with no connected parts converted back. Each is refused before anything
 * changes when the kind does not allow it, validates every changed and new part and saves only without a violation,
 * in one transaction, with a comment in the event log. Web only: REST and MCP have no conversion.
 * @see \App\Tests\Services\Parts\PartKindConverterTest
 */
final readonly class PartKindConverter
{
    public function __construct(private EntityManagerInterface $entityManager, private ValidatorInterface $validator,
        private EventCommentHelper $commentHelper)
    {
    }

    /**
     * Refuses the conversion to a meta part of a part whose kind does not allow it.
     * @throws PartConversionException
     */
    public function assertCanConvertToMeta(Part $part): void
    {
        if (!$part->getKind()->canBeConvertedToMeta()) {
            throw PartConversionException::cannotBeConvertedToMeta($part->getName());
        }
    }

    /**
     * Refuses the conversion back to a manufacturing part of a part whose kind does not allow it, or of a meta part
     * with connected parts, naming them in their order.
     * @throws PartConversionException
     */
    public function assertCanConvertToManufacturing(Part $part): void
    {
        if (!$part->getKind()->canBeConvertedToManufacturing()) {
            throw PartConversionException::cannotBeConvertedToManufacturing($part->getName());
        }

        if (!$part->getPartConnections()->isEmpty()) {
            throw PartConversionException::hasConnectedParts($part->getName(), array_map(
                static fn (PartConnection $connection): string => $connection->getConnectedPart()?->getName() ?? '',
                $part->getOrderedConnections()));
        }
    }

    /**
     * What the conversion of the part to use a meta part does with the given choice, changing nothing.
     * @throws PartConversionException if the kind of the part does not allow the conversion
     */
    public function previewToMeta(Part $part, ConversionKeep $keep): ConversionPreview
    {
        $this->assertCanConvertToMeta($part);
        $keepsManufacturing = $keep === ConversionKeep::MANUFACTURING_KEEPS_ID;
        $move = $keepsManufacturing ? ConversionPreview::KEPT : ConversionPreview::MOVED;

        return new ConversionPreview($keep, [
            self::row('identity', ConversionPreview::KEPT),
            self::row('bom_entries', ConversionPreview::KEPT, $part->getProjectBomEntries()->count()),
            self::row('manufacturer_data', $move),
            self::row('lots', $move, $part->getPartLots()->count()),
            self::row('orderdetails', $move, $part->getOrderdetails()->count()),
            self::row('connections', $move, $part->getConnectionsAsPart()->count()),
            self::row($keepsManufacturing ? 'descriptive' : 'descriptive_no_min_stock', ConversionPreview::COPIED),
            self::row('parameters', ConversionPreview::COPIED, $part->getParameters()->count()),
            self::row('attachments', ConversionPreview::COPIED, $part->getAttachments()->count()),
            self::row($keepsManufacturing ? 'new_meta_part' : 'new_manufacturing_part', ConversionPreview::CREATED),
        ]);
    }

    /**
     * What the conversion of the meta part back to a manufacturing part does, changing nothing. Whether it has
     * connected parts, which refuse the conversion, is not part of it.
     * @throws PartConversionException if the kind of the part does not allow the conversion
     */
    public function previewToManufacturing(Part $part): ConversionPreview
    {
        if (!$part->getKind()->canBeConvertedToManufacturing()) {
            throw PartConversionException::cannotBeConvertedToManufacturing($part->getName());
        }

        return new ConversionPreview(null, [
            self::row('kind', ConversionPreview::CHANGED),
            self::row('price_method', ConversionPreview::RESET),
            self::row('identity', ConversionPreview::KEPT),
            self::row('bom_entries', ConversionPreview::KEPT, $part->getProjectBomEntries()->count()),
            self::row('lots', ConversionPreview::KEPT, $part->getPartLots()->count()),
            self::row('parameters', ConversionPreview::KEPT, $part->getParameters()->count()),
            self::row('attachments', ConversionPreview::KEPT, $part->getAttachments()->count()),
        ]);
    }

    /**
     * Converts a manufacturing part to use a meta part, and returns the meta part. With MANUFACTURING_KEEPS_ID a new
     * meta part with copies of the descriptive fields, parameters and attachments is created above the part; with
     * META_KEEPS_ID the part becomes the meta part, and its manufacturer data, lots, order details and connections to
     * meta parts move to a new manufacturing part with copies of the same fields. Either way the manufacturing part is
     * connected to the meta part at position 1 as approved, and the part keeps its BOM entries.
     * @throws PartConversionException if the kind does not allow it, or the result is not valid or can not be saved
     */
    public function convertToMeta(Part $part, ConversionKeep $keep): Part
    {
        $this->assertCanConvertToMeta($part);
        $moved = [];
        $connections = [];
        $afterFirstSave = null;

        if ($keep === ConversionKeep::MANUFACTURING_KEEPS_ID) {
            $meta = $this->newPartLike($part, PartKind::META, true);
            $connections[] = self::connect($meta, $part, 1, PartConnectionStatus::APPROVED, '');
            $new = $meta;
            $comment = 'Converted to use a meta part: a new meta part above this part';
        } else {
            $new = $this->newPartLike($part, PartKind::MANUFACTURING, false);
            $this->moveManufacturerData($part, $new);

            foreach ($part->getPartLots()->toArray() as $lot) {
                $part->removePartLot($lot);
                $new->addPartLot($lot);
                $moved[] = $lot;
            }
            foreach ($part->getOrderdetails()->toArray() as $orderdetail) {
                $part->removeOrderdetail($orderdetail);
                $new->addOrderdetail($orderdetail);
                $moved[] = $orderdetail;
            }
            //The order detail chosen for a manual order (only set by legacy data) is given to the new part once its row
            //exists and the old row no longer holds it, as the column is unique
            $manualOrderdetail = $part->getOrderOrderdetails();
            $new->setManualOrder($part->isMarkedForManualOrder(), $part->getOrderQuantity());
            $part->setManualOrder(false, 0, null);
            if ($manualOrderdetail instanceof Orderdetail) {
                $afterFirstSave = static function () use ($new, $manualOrderdetail): void {
                    $new->setManualOrder($new->isMarkedForManualOrder(), $new->getOrderQuantity(), $manualOrderdetail);
                };
            }

            //The ends of a connection are fixed, so each connection to a meta part is removed and made again on the new part
            foreach ($part->getConnectionsAsPart()->toArray() as $old) {
                $metaPart = $old->getMetaPart();
                $part->getConnectionsAsPart()->removeElement($old);
                if ($metaPart instanceof Part) {
                    $metaPart->removePartConnection($old);
                    $connections[] = self::connect($metaPart, $new, $old->getPosition(), $old->getStatus(), $old->getNote());
                }
            }

            $part->allowKindConversion();
            $part->setKind(PartKind::META);
            $connections[] = self::connect($part, $new, 1, PartConnectionStatus::APPROVED, '');
            $meta = $part;
            $comment = 'Converted to use a meta part: this part became the meta part above a new manufacturing part';
        }

        $this->save($part, [$part, $new], $connections, $moved, $comment, $afterFirstSave);

        return $meta;
    }

    /**
     * Converts a meta part with no connected parts back to a manufacturing part. It keeps its ID, own lots, BOM entries
     * and history; its price method is reset to system default and its user price to none.
     * @throws PartConversionException if the kind does not allow it, the part has connected parts, or the result is
     * not valid or can not be saved
     */
    public function convertToManufacturing(Part $part): void
    {
        $this->assertCanConvertToManufacturing($part);

        $part->allowKindConversion();
        $part->setKind(PartKind::MANUFACTURING);
        $part->setPriceMethod(null);
        $part->setPriceUserValue(null);
        $part->setPriceUserCurrency(null);

        $this->save($part, [$part], [], [], 'Converted back to a manufacturing part');
    }

    /**
     * A new part of the given kind with copies of the descriptive fields, parameters and attachments of the part. It
     * gets no IPN, which must be unique, and the minimum stock only if asked for.
     */
    private function newPartLike(Part $part, PartKind $kind, bool $withMinAmount): Part
    {
        $new = (new Part())->setKind($kind);
        $new->setName($part->getName());
        $new->setDescription($part->getDescription());
        $new->setCategory($part->getCategory());
        $new->setFootprint($part->getFootprint());
        $new->setTags($part->getTags());
        $new->setComment($part->getComment());
        $new->setPartUnit($part->getPartUnit());
        if ($withMinAmount) {
            $new->setMinAmount($part->getMinAmount());
        }
        $new->setEdaInfo($part->getEdaInfo());

        foreach ($part->getParameters() as $parameter) {
            $new->addParameter(clone $parameter);
        }
        foreach ($part->getAttachments() as $attachment) {
            $clone = clone $attachment;
            $new->addAttachment($clone);
            if ($attachment === $part->getMasterPictureAttachment()) {
                $new->setMasterPictureAttachment($clone);
            }
        }

        return $new;
    }

    private function moveManufacturerData(Part $from, Part $to): void
    {
        $to->setManufacturer($from->getManufacturer());
        $to->setManufacturerProductNumber($from->getManufacturerProductNumber());
        $to->setManufacturerProductURL($from->getCustomProductURL());
        $to->setManufacturingStatus($from->getManufacturingStatus() ?? ManufacturingStatus::NOT_SET);
        $to->setGtin($from->getGtin());
        $to->setProviderReference($from->getProviderReference());

        $from->setManufacturer(null);
        $from->setManufacturerProductNumber('');
        $from->setManufacturerProductURL('');
        $from->setManufacturingStatus(ManufacturingStatus::NOT_SET);
        $from->setGtin(null);
        $from->setProviderReference(InfoProviderReference::noProvider());
    }

    /**
     * Connects the part to the meta part, on both sides, so that validation sees the connection from either end.
     */
    private static function connect(Part $meta, Part $connected, ?int $position, PartConnectionStatus $status, string $note): PartConnection
    {
        $connection = (new PartConnection())->setConnectedPart($connected)->setPosition($position)->setStatus($status)->setNote($note);
        $meta->addPartConnection($connection);
        $connected->getConnectionsAsPart()->add($connection);

        return $connection;
    }

    /**
     * Validates the parts and the connections on other meta parts and, only without a violation, saves them in one
     * transaction with the comment in the event log. On a violation the entity manager is cleared, so that nothing
     * changed in memory reaches a later flush; a database error rolls back and closes it.
     * @param list<Part> $parts the changed and the new part
     * @param list<PartConnection> $connections the new connections
     * @param list<object> $moved the lots and order details moved to the new part
     * @param (\Closure(): void)|null $afterFirstSave a change that can only be saved after the rest, in the same transaction
     * @throws PartConversionException
     */
    private function save(Part $part, array $parts, array $connections, array $moved, string $comment, ?\Closure $afterFirstSave = null): void
    {
        try {
            $violations = [];
            foreach ($parts as $changed) {
                foreach ($this->validator->validate($changed) as $violation) {
                    $violations[] = $changed->getName().': '.$violation->getMessage();
                }
            }
            //The connections of the parts above are validated with those parts; the others on their own
            foreach ($connections as $connection) {
                if (!in_array($connection->getMetaPart(), $parts, true)) {
                    foreach ($this->validator->validate($connection) as $violation) {
                        $violations[] = $connection->getMetaPart()?->getName().': '.$violation->getMessage();
                    }
                }
            }

            if ($violations !== []) {
                $this->entityManager->clear();
                throw PartConversionException::invalid(array_values(array_unique($violations)));
            }

            $this->commentHelper->setMessage($comment);
            $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($parts, $connections, $moved, $afterFirstSave): void {
                foreach ([...$parts, ...$connections] as $entity) {
                    $entityManager->persist($entity);
                }
                //A lot or order detail removed from the collection of the part would be deleted as an orphan, although it moved
                foreach ($moved as $entity) {
                    $entityManager->getUnitOfWork()->cancelOrphanRemoval($entity);
                }
                if ($afterFirstSave instanceof \Closure) {
                    $entityManager->flush();
                    $afterFirstSave();
                }
            });
        } catch (DBALException $exception) {
            throw PartConversionException::savingFailed($exception);
        } finally {
            $part->endKindConversion();
            $this->commentHelper->clearMessage();
        }
    }

    /**
     * @return array{what: string, action: string, count: int|null}
     */
    private static function row(string $what, string $action, ?int $count = null): array
    {
        return ['what' => $what, 'action' => $action, 'count' => $count];
    }
}
