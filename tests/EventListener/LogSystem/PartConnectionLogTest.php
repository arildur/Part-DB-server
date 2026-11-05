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

declare(strict_types=1);namespace App\Tests\EventListener\LogSystem;

use App\Entity\LogSystem\AbstractLogEntry;
use App\Entity\LogSystem\CollectionElementDeleted;
use App\Entity\LogSystem\ElementCreatedLogEntry;
use App\Entity\LogSystem\LogTargetType;
use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartKind;
use App\Entity\Parts\PartLot;
use App\Form\Filters\LogFilterType;
use App\Services\ElementTypeNameGenerator;
use App\Services\ElementTypes;
use App\Services\EntityURLGenerator;
use App\Services\LogSystem\HistoryHelper;
use App\Services\LogSystem\TimeTravel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PartConnectionLogTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function part(PartKind $kind, string $name): Part
    {
        $part = (new Part())->setKind($kind);
        $part->setName($name);
        $part->setCategory($this->em->find(Category::class, 1));
        $this->em->persist($part);

        return $part;
    }

    /** @return array{Part, Part, PartConnection} */
    private function connectedPair(): array
    {
        $meta = $this->part(PartKind::META, 'The meta part');
        $part = $this->part(PartKind::MANUFACTURING, 'The connected part');
        $connection = (new PartConnection())->setConnectedPart($part);
        $meta->addPartConnection($connection);
        $this->em->flush();

        return [$meta, $part, $connection];
    }

    /**
     * @param class-string $class
     * @return list<AbstractLogEntry>
     */
    private function logEntries(string $class, object $target): array
    {
        return array_values(array_filter(
            $this->em->getRepository(AbstractLogEntry::class)->findBy([
                'target_type' => LogTargetType::fromElementClass($target::class),
                'target_id' => $target->getID(),
            ]),
            static fn (object $entry): bool => $entry instanceof $class
        ));
    }

    public function testSavingAConnectionIsLogged(): void
    {
        [, , $connection] = $this->connectedPair();

        $this->assertSame(PartConnection::class, LogTargetType::PART_CONNECTION->toClass());
        $this->assertSame(LogTargetType::PART_CONNECTION, LogTargetType::fromElementClass(PartConnection::class));
        $this->assertCount(1, $this->logEntries(ElementCreatedLogEntry::class, $connection));
    }

    public function testTheListsThatNameATargetTypeKnowTheConnection(): void
    {
        $this->assertNotNull(self::getContainer()->get(FormFactoryInterface::class)->create(LogFilterType::class)->createView());

        $type = ElementTypes::fromClass(PartConnection::class);
        $this->assertSame(ElementTypes::PART_CONNECTION, $type);
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $this->assertSame('Connected part', $translator->trans($type->getDefaultLabelKey()));
        $this->assertSame('Connected parts', $translator->trans($type->getDefaultPluralLabelKey()));
    }

    public function testALogEntryNamesBothPartsAndLinksToTheMetaPart(): void
    {
        [$meta, , $connection] = $this->connectedPair();

        $label = self::getContainer()->get(ElementTypeNameGenerator::class)->formatLabelHTMLForEntity($connection, true);
        $this->assertStringContainsString('The connected part', $label);
        $this->assertStringContainsString('The meta part', $label);

        $url = self::getContainer()->get(EntityURLGenerator::class)->timeTravelURL($connection, new \DateTimeImmutable());
        $this->assertStringContainsString('/part/'.$meta->getID().'/info', $url);
    }

    public function testRemovingAConnectionIsLoggedOnBothParts(): void
    {
        [$meta, $part, $connection] = $this->connectedPair();

        $meta->removePartConnection($connection);
        $this->em->flush();

        $this->assertCount(1, $this->logEntries(CollectionElementDeleted::class, $meta));
        $this->assertCount(1, $this->logEntries(CollectionElementDeleted::class, $part));
    }

    public function testTheHistoryOfBothPartsHoldsTheConnection(): void
    {
        [$meta, $part, $connection] = $this->connectedPair();
        $ids = [$meta->getID(), $part->getID(), $connection->getID()];
        $this->em->clear();
        $helper = self::getContainer()->get(HistoryHelper::class);
        $loaded = $this->em->find(PartConnection::class, $ids[2]);

        $this->assertContains($loaded, $helper->getAssociatedElements($this->em->find(Part::class, $ids[0])));
        $this->assertContains($loaded, $helper->getAssociatedElements($this->em->find(Part::class, $ids[1])));
    }

    public function testRevertingAMetaPartLeavesAConnectedPartAndItsStock(): void
    {
        $meta = $this->part(PartKind::META, 'Meta');
        $part = $this->part(PartKind::MANUFACTURING, 'Part');
        $lot = new PartLot();
        $lot->setAmount(100);
        $part->addPartLot($lot);
        $connection = (new PartConnection())->setConnectedPart($part);
        $meta->addPartConnection($connection);
        $this->em->flush();

        sleep(1);
        $before = new \DateTimeImmutable();
        sleep(1);

        $part->setDescription('Changed');
        $lot->setAmount(60);
        $connection->setNote('Changed');
        $meta->setDescription('Changed');
        $this->em->flush();

        self::getContainer()->get(TimeTravel::class)->revertEntityToTimestamp($meta, $before);

        $this->assertSame('', $meta->getDescription(), 'The meta part itself goes back');
        $this->assertSame(60.0, $lot->getAmount(), 'The stock of the connected part stays');
        $this->assertSame('Changed', $part->getDescription(), 'The connected part stays');
    }

    public function testARemovedConnectionCanBeRestoredOnlyIfThePairIsFree(): void
    {
        [$meta, $part, $connection] = $this->connectedPair();
        $timeTravel = self::getContainer()->get(TimeTravel::class);
        $detached = (new PartConnection())->setMetaPart($meta)->setConnectedPart($part);

        $this->assertFalse($timeTravel->canRestoreConnection($detached), 'The pair is connected');

        $meta->removePartConnection($connection);
        $this->em->flush();
        $this->assertTrue($timeTravel->canRestoreConnection($detached), 'The pair is free');

        $this->assertFalse($timeTravel->canRestoreConnection((new PartConnection())->setMetaPart($meta)), 'A part is missing');
    }

    public function testRevertingLeavesOutAConnectionWhenThePairIsConnectedAgain(): void
    {
        [$meta, $part, $connection] = $this->connectedPair();
        sleep(1);
        $before = new \DateTimeImmutable();
        sleep(1);

        $meta->removePartConnection($connection);
        $this->em->flush();
        $again = (new PartConnection())->setConnectedPart($part);
        $meta->addPartConnection($again);
        $meta->setDescription('Changed');
        $this->em->flush();

        self::getContainer()->get(TimeTravel::class)->revertEntityToTimestamp($meta, $before);

        $this->assertNotContains($connection, $meta->getPartConnections()->toArray());
    }
}
