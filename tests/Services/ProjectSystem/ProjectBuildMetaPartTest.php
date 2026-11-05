<?php

declare(strict_types=1);

/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2023 Jan Böhmer (https://github.com/jbtronics)
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
namespace App\Tests\Services\ProjectSystem;

use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartConnectionStatus;
use App\Entity\Parts\PartKind;
use App\Entity\Parts\PartLot;
use App\Entity\PriceInformations\Currency;
use App\Entity\PriceInformations\Orderdetail;
use App\Entity\PriceInformations\Pricedetail;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use App\Helpers\Projects\ProjectBuildRequest;
use App\Services\ProjectSystem\ProjectBuildHelper;
use Brick\Math\BigDecimal;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ProjectBuildMetaPartTest extends KernelTestCase
{
    private ProjectBuildHelper $helper;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->helper = self::getContainer()->get(ProjectBuildHelper::class);
    }

    private function lot(Part $part, float $amount, int $id = 0, ?string $expires = null): PartLot
    {
        $lot = new class($id) extends PartLot {
            public function __construct(private readonly int $lot_id)
            {
            }

            public function getID(): ?int
            {
                return $this->lot_id === 0 ? null : $this->lot_id;
            }
        };
        $lot->setAmount($amount);
        if ($expires !== null) {
            $lot->setExpirationDate(new \DateTimeImmutable($expires));
        }
        $part->addPartLot($lot);

        return $lot;
    }

    private function meta(): Part
    {
        return (new Part())->setKind(PartKind::META);
    }

    private function connect(Part $meta, Part $part, int $position, PartConnectionStatus $status = PartConnectionStatus::APPROVED): void
    {
        $meta->addPartConnection((new PartConnection())->setConnectedPart($part)->setPosition($position)->setStatus($status));
    }

    private function price(Part $part, string $price, ?Currency $currency = null): Part
    {
        $orderdetail = new Orderdetail();
        $orderdetail->addPricedetail((new Pricedetail())->setMinDiscountQuantity(1.0)->setPrice(BigDecimal::of($price))->setCurrency($currency));
        $part->addOrderdetail($orderdetail);

        return $part;
    }

    /**
     * @param  list<array{0: Part, 1: float}>  $bom
     */
    private function project(array $bom): Project
    {
        $project = new Project();
        foreach ($bom as $i => [$part, $quantity]) {
            $entry = new class($i + 1) extends ProjectBOMEntry {
                public function __construct(private readonly int $entry_id)
                {
                    parent::__construct();
                }

                public function getID(): ?int
                {
                    return $this->entry_id;
                }
            };
            $entry->setPart($part);
            $entry->setQuantity($quantity);
            $project->addBomEntry($entry);
        }

        return $project;
    }

    public function testASingleMetaPartEntryCountsItsTotal(): void
    {
        $meta = $this->meta();
        $this->lot($meta, 50);
        $connected = new Part();
        $this->lot($connected, 75);
        $this->connect($meta, $connected, 1);

        $this->assertSame(12, $this->helper->getMaximumBuildableCount($this->project([[$meta, 10.0]])));
    }

    public function testMetaPartsSharingStockCountWhatTheStockCanCover(): void
    {
        $shelf = new Part();
        $this->lot($shelf, 100);
        $other = new Part();
        $this->lot($other, 40);
        $one = $this->meta();
        $this->connect($one, $shelf, 1);
        $five = $this->meta();
        $this->connect($five, $shelf, 1);
        $this->connect($five, $other, 2);

        $this->assertSame(0, $this->helper->getMaximumBuildableCount($this->project([[$one, 80.0], [$five, 80.0]])));
        $this->assertSame(1, $this->helper->getMaximumBuildableCount($this->project([[$one, 60.0], [$five, 60.0]])));

        //An expired lot changes nothing
        $this->lot($other, 1000, 0, '2000-01-01');
        $this->assertSame(0, $this->helper->getMaximumBuildableCount($this->project([[$one, 80.0], [$five, 80.0]])));
        $this->assertSame(1, $this->helper->getMaximumBuildableCount($this->project([[$one, 60.0], [$five, 60.0]])));
    }

    public function testEntriesShortOnlyBecauseOfEachOtherAreNamed(): void
    {
        $shelf = new Part();
        $this->lot($shelf, 100);
        $one = $this->meta();
        $this->connect($one, $shelf, 1);
        $two = $this->meta();
        $this->connect($two, $shelf, 1);
        $project = $this->project([[$one, 80.0], [$two, 80.0]]);

        $short = $this->helper->getNonBuildableProjectBomEntries($project);

        $this->assertCount(1, $short);
        $this->assertSame($two, $short[0]->getPart());
        $this->assertSame(20.0, $this->helper->getAvailableAmountForBOMEntry($short[0]));
        $this->assertFalse($this->helper->isProjectBuildable($project));
    }

    public function testOwnStockGoesFirstThenConnectedPartsByPosition(): void
    {
        $meta = $this->meta();
        $own = $this->lot($meta, 5, 1);
        $lowPriority = new Part();
        $lotLow = $this->lot($lowPriority, 100, 2);
        $highPriority = new Part();
        $lotHigh = $this->lot($highPriority, 100, 3);
        $this->connect($meta, $lowPriority, 2);
        $this->connect($meta, $highPriority, 1);
        $project = $this->project([[$meta, 10.0]]);
        $entry = $project->getBomEntries()->first();

        $request = new ProjectBuildRequest($project, 1);

        $this->assertSame([$own, $lotHigh, $lotLow], $request->getPartLotsForBOMEntry($entry));
        $this->assertSame(5.0, $request->getWithdrawAmount($entry, $own));
        $this->assertSame(5.0, $request->getWithdrawAmount($entry, $lotHigh));
        $this->assertSame(0.0, $request->getWithdrawAmount($entry, $lotLow));
    }

    public function testTheProposalIsTheSameWhateverOrderTheEntriesComeIn(): void
    {
        $shelf = new Part();
        $shelfLot = $this->lot($shelf, 100, 1);
        $other = new Part();
        $otherLot = $this->lot($other, 40, 2);
        $wide = $this->meta();
        $this->connect($wide, $shelf, 1);
        $this->connect($wide, $other, 2);
        $narrow = $this->meta();
        $this->connect($narrow, $shelf, 1);
        $bom = [[$wide, 60.0], [$narrow, 60.0]];
        $project = $this->project($bom);
        $entries = $project->getBomEntries()->toArray();

        for ($i = 0; $i < 10; $i++) {
            $shuffled = $entries;
            shuffle($shuffled);
            $reordered = new Project();
            foreach ($shuffled as $entry) {
                $reordered->addBomEntry($entry);
            }
            $request = new ProjectBuildRequest($reordered, 1);

            $this->assertSame(40.0, $request->getWithdrawAmount($entries[0], $shelfLot));
            $this->assertSame(20.0, $request->getWithdrawAmount($entries[0], $otherLot));
            $this->assertSame(60.0, $request->getWithdrawAmount($entries[1], $shelfLot));
        }
    }

    public function testTheBuildWithdrawsOnceFromALotReachedByTwoEntries(): void
    {
        $part = new Part();
        $lot = $this->lot($part, 100, 1);
        $meta = $this->meta();
        $this->connect($meta, $part, 1);
        $project = $this->project([[$meta, 30.0], [$part, 20.0]]);

        $this->helper->doBuild(new ProjectBuildRequest($project, 1));

        $this->assertSame(50.0, $lot->getAmount());
    }

    public function testTheBuildCanEmptyALotHoldingFloatResidue(): void
    {
        $unit = (new \App\Entity\Parts\MeasurementUnit())->setIsInteger(false);
        $part = (new Part())->setPartUnit($unit);
        $lot = $this->lot($part, 0.3 - 0.2, 1);
        $project = $this->project([[$part, 0.1]]);

        $this->helper->doBuild(new ProjectBuildRequest($project, 1));

        $this->assertSame(0.0, $lot->getAmount());
    }

    /**
     * Prices 2, 1, unpriced and a do-not-use at 0.5.
     */
    private function pricedMetaPart(bool $firstHasPrice = true): Part
    {
        $meta = $this->meta();
        $first = $firstHasPrice ? $this->price(new Part(), '2') : new Part();
        $second = $this->price(new Part(), '1');
        $this->connect($meta, $first, 1);
        $this->connect($meta, $second, 2);
        $this->connect($meta, new Part(), 3);
        $this->connect($meta, $this->price(new Part(), '0.5'), 4, PartConnectionStatus::DO_NOT_USE);

        return $meta;
    }

    public function testAMetaPartCostsItsFirstUsablePartWithAPrice(): void
    {
        $cost = $this->helper->calculateCost($this->project([[$this->pricedMetaPart(), 1.0]]));
        $this->assertTrue(BigDecimal::of('2')->isEqualTo($cost->total), 'The first, got '.$cost->total);

        $cost = $this->helper->calculateCost($this->project([[$this->pricedMetaPart(false), 1.0]]));
        $this->assertTrue(BigDecimal::of('1')->isEqualTo($cost->total), 'The next with a price, got '.$cost->total);
    }

    public function testAnEntryWithNoPriceIsCounted(): void
    {
        $cost = $this->helper->calculateCost($this->project([[$this->pricedMetaPart(), 1.0], [new Part(), 1.0]]));

        $this->assertSame(1, $cost->unpricedEntries);
        $this->assertTrue($cost->hasMetaPartEntry);
    }

    public function testTheBuildPartOfASubProjectCostsWhatItsBomCosts(): void
    {
        $sub = $this->project([[$this->pricedMetaPart(), 1.0]]);
        $buildPart = new Part();
        $sub->setBuildPart($buildPart);

        $cost = $this->helper->calculateCost($this->project([[$buildPart, 2.0]]));

        $this->assertTrue(BigDecimal::of('4')->isEqualTo($cost->total), 'Got '.$cost->total);
    }

    public function testThePriceOfAMetaPartIsInTheRequestedCurrency(): void
    {
        $eur = (new Currency())->setExchangeRate(BigDecimal::of('2.00000'));
        $usd = (new Currency())->setExchangeRate(BigDecimal::of('1.00000'));
        $meta = $this->meta();
        //3 EUR is 6 in the base currency, which is 6 USD
        $this->connect($meta, $this->price(new Part(), '3', $eur), 1);
        $this->connect($meta, $this->price(new Part(), '5', $usd), 2);

        $cost = $this->helper->calculateCost($this->project([[$meta, 1.0]]), 1, $usd);

        $this->assertTrue(BigDecimal::of('6')->isEqualTo($cost->total), 'Got '.$cost->total);
    }

    public function testACycleInStoredDataStillEnds(): void
    {
        $project = new Project();
        $buildPart = new Part();
        $project->setBuildPart($buildPart);
        $meta = $this->meta();
        $this->connect($meta, $buildPart, 1);
        $this->connect($meta, $this->price(new Part(), '3'), 2);
        $entry = new ProjectBOMEntry();
        $entry->setPart($meta);
        $entry->setQuantity(1);
        $project->addBomEntry($entry);

        $cost = $this->helper->calculateCost($project);

        $this->assertTrue(BigDecimal::of('3')->isEqualTo($cost->total), 'The cycle is left out, got '.$cost->total);
    }

    public function testAMetaPartAndItsConnectedPartMayShareABomButNotBringBackTheBuildPart(): void
    {
        $validator = self::getContainer()->get(ValidatorInterface::class);
        $paths = static function (ProjectBOMEntry $entry) use ($validator): array {
            $found = [];
            foreach ($validator->validate($entry) as $violation) {
                $found[] = $violation->getPropertyPath();
            }

            return $found;
        };

        $connected = new Part();
        $meta = $this->meta();
        $this->connect($meta, $connected, 1);
        $project = $this->project([[$meta, 1.0], [$connected, 1.0]]);
        $this->assertNotContains('part', $paths($project->getBomEntries()->first()));

        $parent = new Project();
        $parentBuildPart = new Part();
        $parent->setBuildPart($parentBuildPart);
        $child = new Project();
        $child->setParent($parent);
        $metaThroughParent = $this->meta();
        $this->connect($metaThroughParent, $parentBuildPart, 1);
        $entry = new ProjectBOMEntry();
        $entry->setPart($metaThroughParent);
        $entry->setQuantity(1);
        $child->addBomEntry($entry);
        $this->assertContains('part', $paths($entry));
    }

    public function testAConnectionMayNotBringBackTheBuildPartOfAProjectUsingTheMetaPart(): void
    {
        $validator = self::getContainer()->get(ValidatorInterface::class);
        $project = new Project();
        $buildPart = new Part();
        $project->setBuildPart($buildPart);
        $meta = $this->meta();
        $entry = new ProjectBOMEntry();
        $entry->setPart($meta);
        $entry->setQuantity(1);
        $project->addBomEntry($entry);
        $meta->getProjectBomEntries()->add($entry);

        $connection = (new PartConnection())->setConnectedPart($buildPart);
        $meta->addPartConnection($connection);

        $paths = [];
        foreach ($validator->validate($connection) as $violation) {
            $paths[] = $violation->getMessageTemplate();
        }
        $this->assertContains('validator.part_connection.would_reach_own_build_part', $paths);
    }

    public function testAMetaPartCanNotBeABuildPart(): void
    {
        $validator = self::getContainer()->get(ValidatorInterface::class);
        $project = new Project();
        $project->setName('Project');
        $project->setBuildPart($this->meta());

        $messages = [];
        foreach ($validator->validate($project) as $violation) {
            $messages[] = $violation->getMessageTemplate();
        }
        $this->assertContains('validator.project.build_part_kind_not_allowed', $messages);
    }
}
