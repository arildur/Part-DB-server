<?php
/**
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 * Copyright (C) 2019 - 2026 Jan Böhmer (https://github.com/jbtronics)
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

declare(strict_types=1);namespace App\Tests\DataTables;

use App\DataTables\Filters\PartFilter;
use App\DataTables\Helpers\PartDataTableHelper;
use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartConnectionStatus;
use App\Entity\Parts\PartKind;
use App\Entity\Parts\PartLot;
use App\Entity\Parts\StorageLocation;
use App\Services\Trees\NodesListBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PartKindInListsTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function part(string $name, PartKind $kind = PartKind::MANUFACTURING): Part
    {
        $part = (new Part())->setKind($kind);
        $part->setName($name);
        $part->setCategory($this->em->find(Category::class, 1));
        $this->em->persist($part);

        return $part;
    }

    private function lotIn(Part $part, string $location): void
    {
        $place = new StorageLocation();
        $place->setName($location);
        $this->em->persist($place);
        $lot = new PartLot();
        $lot->setAmount(5);
        $lot->setStorageLocation($place);
        $part->addPartLot($lot);
    }

    public function testTheMarkDoesNotCompeteWithTheOtherIcons(): void
    {
        $helper = self::getContainer()->get(PartDataTableHelper::class);
        $meta = $this->part('Meta', PartKind::META);
        $meta->setFavorite(true);
        $meta->setNeedsReview(true);
        $plain = $this->part('Plain');
        $this->em->flush();

        $html = $helper->renderName($meta);

        $this->assertStringContainsString('fa-sitemap', $html);
        $this->assertStringContainsString('fa-ambulance', $html, 'The icon it would otherwise replace');
        $this->assertStringNotContainsString('fa-sitemap', $helper->renderName($plain));
        $this->assertStringNotContainsString('fa-cube', $html);
    }

    public function testOnlyAMetaPartHasTheIconOfItsKindBeforeTheName(): void
    {
        $helper = self::getContainer()->get(PartDataTableHelper::class);
        $plain = $this->part('Plain <b>');
        $plain->setFavorite(true);
        $meta = $this->part('Meta', PartKind::META);
        $this->em->flush();

        $html = $helper->renderName($plain);
        $this->assertStringNotContainsString('title="Manufacturing', $html, 'A manufacturing part has no kind icon');
        $this->assertSame(1, substr_count($html, '<i '), 'Only the icon of the part status');
        $this->assertMatchesRegularExpression(
            '#^<a href="[^"]+"><i class="fa-solid fa-star fa-fw me-1" title="[^"]*"></i>Plain &lt;b&gt;</a>$#',
            $html,
            'The name cell of a manufacturing part is as upstream renders it'
        );

        $html = $helper->renderName($meta);
        $this->assertStringContainsString('<i class="fa-solid fa-sitemap fa-fw me-1" title="Meta', $html);
        $this->assertLessThan(strpos($html, '>Meta</a>'), strpos($html, 'fa-sitemap'));
    }

    public function testTheAmountColumnShowsTheTotal(): void
    {
        $helper = self::getContainer()->get(PartDataTableHelper::class);
        $meta = $this->part('Meta', PartKind::META);
        $connected = $this->part('Connected');
        $this->lotIn($connected, 'Box');
        $meta->addPartConnection((new PartConnection())->setConnectedPart($connected));

        $this->assertStringContainsString('5', $helper->renderAmount($meta));
    }

    public function testTheLocationColumnShowsEveryConnectedLocationWithTheUnusableLastAndMarked(): void
    {
        $helper = self::getContainer()->get(PartDataTableHelper::class);
        $meta = $this->part('Meta', PartKind::META);
        $this->lotIn($meta, 'Common bin');
        $retired = $this->part('Retired');
        $this->lotIn($retired, 'Retired box');
        $evaluated = $this->part('Evaluated');
        $this->lotIn($evaluated, 'Lab box');
        $active = $this->part('Active');
        $this->lotIn($active, 'Active box');
        $sharedLot = new PartLot();
        $sharedLot->setAmount(1);
        $sharedLot->setStorageLocation($meta->getPartLots()->first()->getStorageLocation());
        $active->addPartLot($sharedLot);
        $meta->addPartConnection((new PartConnection())->setConnectedPart($retired)->setPosition(1)->setStatus(PartConnectionStatus::DO_NOT_USE));
        $meta->addPartConnection((new PartConnection())->setConnectedPart($evaluated)->setPosition(2)->setStatus(PartConnectionStatus::UNDER_EVALUATION));
        $meta->addPartConnection((new PartConnection())->setConnectedPart($active)->setPosition(3)->setStatus(PartConnectionStatus::USE_UP));
        $this->em->flush();

        $html = $helper->renderStorageLocations($meta);
        $entries = explode('<br>', $html);

        $this->assertCount(4, $entries, 'A location already listed is not repeated: '.$html);
        $this->assertStringContainsString('Common bin', $entries[0]);
        $this->assertStringContainsString('Active box', $entries[1]);
        $this->assertStringNotContainsString('text-muted', $entries[1]);
        $this->assertStringContainsString('Retired box', $entries[2]);
        $this->assertStringContainsString('class="text-muted"', $entries[2]);
        $this->assertStringContainsString('Do not use', $entries[2]);
        $this->assertStringContainsString('Lab box', $entries[3]);
        $this->assertStringContainsString('Under evaluation', $entries[3]);

        $usableOnly = $helper->renderStorageLocations($meta, usableOnly: true);
        $this->assertStringContainsString('Active box', $usableOnly);
        $this->assertStringNotContainsString('Retired box', $usableOnly);
        $this->assertStringNotContainsString('Lab box', $usableOnly);
    }

    /** @return list<string> */
    private function filtered(callable $configure): array
    {
        $filter = new PartFilter(self::getContainer()->get(NodesListBuilder::class));
        $configure($filter);
        $builder = $this->em->createQueryBuilder()->select('part.name')->from(Part::class, 'part')
            ->leftJoin('part.partLots', '_partLots')->leftJoin('_partLots.storage_location', '_storelocations')
            ->addGroupBy('part.id')->addGroupBy('part.name')->addGroupBy('part.minamount');
        $filter->apply($builder);

        return array_column($builder->getQuery()->getArrayResult(), 'name');
    }

    public function testTheKindFilterShowsAllUntilItIsSet(): void
    {
        $this->part('Meta', PartKind::META);
        $this->part('Plain');
        $this->em->flush();

        $all = $this->filtered(static function (PartFilter $f): void {});
        $this->assertContains('Meta', $all);
        $this->assertContains('Plain', $all);

        $meta = $this->filtered(static function (PartFilter $f): void {
            $f->kind->setValue([PartKind::META]);
        });
        $this->assertSame(['Meta'], $meta);

        $manufacturing = $this->filtered(static function (PartFilter $f): void {
            $f->kind->setValue([PartKind::MANUFACTURING]);
        });
        $this->assertContains('Plain', $manufacturing);
        $this->assertNotContains('Meta', $manufacturing);

        $none = $this->filtered(static function (PartFilter $f): void {
            $f->kind->setValue([]);
        });
        $this->assertSame($all, $none, 'A request naming no kind, such as a link naming only other filters, shows all parts');
    }

    public function testLotFiltersMatchOnlyThePartsOwnLots(): void
    {
        $meta = $this->part('Meta', PartKind::META);
        $connected = $this->part('Connected');
        $this->lotIn($connected, 'Only here');
        $meta->addPartConnection((new PartConnection())->setConnectedPart($connected));
        $this->em->flush();
        $place = $this->em->getRepository(StorageLocation::class)->findOneBy(['name' => 'Only here']);

        $found = $this->filtered(static function (PartFilter $f) use ($place): void {
            $f->storelocation->setOperator('=');
            $f->storelocation->setValue($place);
        });

        $this->assertSame(['Connected'], $found);
    }

    public function testTheLotCountFilterCountsOnlyThePartsOwnLots(): void
    {
        $meta = $this->part('Meta', PartKind::META);
        $connected = $this->part('Connected');
        $this->lotIn($connected, 'Elsewhere');
        $meta->addPartConnection((new PartConnection())->setConnectedPart($connected));
        $this->em->flush();

        $withoutLots = $this->filtered(static function (PartFilter $f): void {
            $f->lotCount->setOperator('=');
            $f->lotCount->setValue1(0);
        });

        $this->assertContains('Meta', $withoutLots);
        $this->assertNotContains('Connected', $withoutLots);
    }
}
