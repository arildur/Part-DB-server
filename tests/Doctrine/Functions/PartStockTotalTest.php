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

declare(strict_types=1);namespace App\Tests\Doctrine\Functions;

use App\DataTables\Filters\PartFilter;
use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartConnectionStatus;
use App\Entity\Parts\PartKind;
use App\Entity\Parts\PartLot;
use App\Services\Tools\StatisticsHelper;
use App\Services\Trees\NodesListBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The stock of a part in a query must equal Part::getAmountSum, on each database. Run this on all three.
 */
final class PartStockTotalTest extends KernelTestCase
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

    private function lot(Part $part, float $amount, bool $unknown = false, ?string $expires = null): void
    {
        $lot = new PartLot();
        $lot->setAmount($amount);
        $lot->setInstockUnknown($unknown);
        if ($expires !== null) {
            $lot->setExpirationDate(new \DateTimeImmutable($expires));
        }
        $part->addPartLot($lot);
    }

    private function connect(Part $meta, Part $part, PartConnectionStatus $status = PartConnectionStatus::APPROVED): void
    {
        $meta->addPartConnection((new PartConnection())->setConnectedPart($part)->setStatus($status));
    }

    /** @return array<string, Part> */
    private function scenarios(): array
    {
        $own = $this->part('Own lots');
        $this->lot($own, 7);
        $this->lot($own, 5, unknown: true);
        $this->lot($own, 3, expires: '-2 days');
        $this->lot($own, 2, expires: '+2 days');

        $this->part('No lots');
        $this->part('Meta, nothing', PartKind::META);

        $connectedA = $this->part('Connected A');
        $this->lot($connectedA, 500);
        $connectedB = $this->part('Connected B');
        $this->lot($connectedB, 100);
        $this->lot($connectedB, 40, expires: '-2 days');
        $retired = $this->part('Retired');
        $this->lot($retired, 1000);

        $meta = $this->part('Meta, example', PartKind::META);
        $this->lot($meta, 200);
        $this->connect($meta, $connectedA);
        $this->connect($meta, $connectedB);
        $this->connect($meta, $retired, PartConnectionStatus::DO_NOT_USE);

        $metaOther = $this->part('Meta, sharing', PartKind::META);
        $this->lot($metaOther, 11);
        $this->connect($metaOther, $connectedA);

        $this->em->flush();
        $this->em->clear();

        $loaded = [];
        foreach ($this->em->getRepository(Part::class)->findAll() as $part) {
            $loaded[$part->getName()] = $part;
        }

        return $loaded;
    }

    private function total(Part $part): float
    {
        return (float) $this->em->createQuery('SELECT part_stock_total(part.id) FROM '.Part::class.' part WHERE part.id = :id')
            ->setParameter('id', $part->getID())
            ->getSingleScalarResult();
    }

    public function testTheQueryEqualsPhpForEveryPart(): void
    {
        foreach ($this->scenarios() as $name => $part) {
            $this->assertEqualsWithDelta($part->getAmountSum(), $this->total($part), 0.001, $name);
        }
    }

    public function testKnownValues(): void
    {
        $parts = $this->scenarios();

        $this->assertEqualsWithDelta(800.0, $this->total($parts['Meta, example']), 0.001);
        $this->assertEqualsWithDelta(511.0, $this->total($parts['Meta, sharing']), 0.001);
        $this->assertEqualsWithDelta(500.0, $this->total($parts['Connected A']), 0.001, 'Own lots of a meta part do not reach it');
        $this->assertEqualsWithDelta(9.0, $this->total($parts['Own lots']), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->total($parts['Meta, nothing']), 0.001);
    }

    public function testSortingByStockFollowsTheTotal(): void
    {
        $this->scenarios();

        $names = array_column($this->em->createQuery(
            'SELECT part.name, part_stock_total(part.id) AS HIDDEN amountSum FROM '.Part::class.' part ORDER BY amountSum DESC, part.id ASC'
        )->setMaxResults(2)->getArrayResult(), 'name');

        $this->assertSame(['Retired', 'Meta, example'], $names);
    }

    /** @return list<string> */
    private function filtered(callable $configure): array
    {
        $filter = new PartFilter(self::getContainer()->get(NodesListBuilder::class));
        $configure($filter);
        $builder = $this->em->createQueryBuilder()->select('part.name')->from(Part::class, 'part')
            ->addGroupBy('part.id')->addGroupBy('part.name')->addGroupBy('part.minamount');
        $filter->apply($builder);

        return array_column($builder->getQuery()->getArrayResult(), 'name');
    }

    public function testTheAmountFilterUsesTheTotal(): void
    {
        $this->scenarios();

        $over = $this->filtered(static function (PartFilter $f): void {
            $f->amountSum->setOperator('>');
            $f->amountSum->setValue1(700);
        });
        $this->assertContains('Meta, example', $over);
        $this->assertNotContains('Connected A', $over);

        $between = $this->filtered(static function (PartFilter $f): void {
            $f->amountSum->setOperator('BETWEEN');
            $f->amountSum->setValue1(505);
            $f->amountSum->setValue2(515);
        });
        $this->assertSame(['Meta, sharing'], $between);
    }

    public function testBelowMinimumUsesTheTotalForAMetaPartAndOwnStockForAManufacturingPart(): void
    {
        $parts = $this->scenarios();
        $parts['Meta, example']->setMinAmount(500);
        $parts['Meta, sharing']->setMinAmount(600);
        $parts['Own lots']->setMinAmount(100);
        $this->em->flush();

        $below = $this->filtered(static function (PartFilter $f): void {
            $f->lessThanDesired->setValue(true);
        });

        $this->assertNotContains('Meta, example', $below, '800 is above its minimum');
        $this->assertContains('Meta, sharing', $below, '511 is below its minimum');
        $this->assertContains('Own lots', $below);
    }

    public function testStatisticsCountEachLotOnceAndMetaPartsOnALineOfTheirOwn(): void
    {
        $helper = self::getContainer()->get(StatisticsHelper::class);
        $before = $helper->getPartsInstockSum();
        $metaBefore = $helper->getMetaPartsCount();

        $this->scenarios();

        $this->assertSame($metaBefore + 3, $helper->getMetaPartsCount());
        //One lot each of 7, 5, 3, 2, 500, 100, 40, 1000, 200 and 11, whichever parts reach them
        $this->assertEqualsWithDelta($before + 1868, $helper->getPartsInstockSum(), 0.001);
    }
}
