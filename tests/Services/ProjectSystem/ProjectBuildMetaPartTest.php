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
use App\Entity\Parts\PartPriceMethod;
use App\Entity\PriceInformations\Currency;
use App\Entity\PriceInformations\Orderdetail;
use App\Entity\PriceInformations\Pricedetail;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use App\Helpers\Projects\ProjectBuildRequest;
use App\Services\ProjectSystem\ProjectBuildHelper;
use App\Settings\MiscSettings\MetaPartSettings;
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

    private function meta(PartPriceMethod $method = PartPriceMethod::SYSTEM_DEFAULT): Part
    {
        return (new Part())->setKind(PartKind::META)->setPriceMethod($method);
    }

    private function connect(Part $meta, Part $part, int $position, PartConnectionStatus $status = PartConnectionStatus::APPROVED): void
    {
        $meta->addPartConnection((new PartConnection())->setConnectedPart($part)->setPosition($position)->setStatus($status));
    }

    /**
     * @param  array<string, string>  $tiers  More prices by the quantity from which they apply
     */
    private function price(Part $part, string $price, ?Currency $currency = null, array $tiers = [], float $from = 1.0): Part
    {
        $orderdetail = new Orderdetail();
        $orderdetail->addPricedetail((new Pricedetail())->setMinDiscountQuantity($from)->setPrice(BigDecimal::of($price))->setCurrency($currency));
        foreach ($tiers as $quantity => $tierPrice) {
            $orderdetail->addPricedetail((new Pricedetail())->setMinDiscountQuantity((float) $quantity)->setPrice(BigDecimal::of($tierPrice))->setCurrency($currency));
        }
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

    public function testUseUpPartsAreProposedBeforeApprovedOnesEachByPriority(): void
    {
        $meta = $this->meta();
        $own = $this->lot($meta, 5, 1);
        $approvedFirst = new Part();
        $lotApprovedFirst = $this->lot($approvedFirst, 100, 2);
        $useUpLater = new Part();
        $lotUseUpLater = $this->lot($useUpLater, 10, 3);
        $useUpFirst = new Part();
        $lotUseUpFirst = $this->lot($useUpFirst, 10, 4);
        $approvedLater = new Part();
        $lotApprovedLater = $this->lot($approvedLater, 100, 5);
        $this->connect($meta, $approvedFirst, 1);
        $this->connect($meta, $useUpLater, 3, PartConnectionStatus::USE_UP);
        $this->connect($meta, $useUpFirst, 2, PartConnectionStatus::USE_UP);
        $this->connect($meta, $approvedLater, 4);
        $project = $this->project([[$meta, 30.0]]);
        $entry = $project->getBomEntries()->first();

        $request = new ProjectBuildRequest($project, 1);

        $this->assertSame([$own, $lotUseUpFirst, $lotUseUpLater, $lotApprovedFirst, $lotApprovedLater], $request->getPartLotsForBOMEntry($entry));
        $this->assertSame(5.0, $request->getWithdrawAmount($entry, $own));
        $this->assertSame(10.0, $request->getWithdrawAmount($entry, $lotUseUpFirst));
        $this->assertSame(10.0, $request->getWithdrawAmount($entry, $lotUseUpLater));
        $this->assertSame(5.0, $request->getWithdrawAmount($entry, $lotApprovedFirst));
        $this->assertSame(0.0, $request->getWithdrawAmount($entry, $lotApprovedLater));
    }

    public function testOnlyUsableConnectionsAreReachedCountedAndPriced(): void
    {
        $meta = $this->meta();
        $lots = [];
        foreach (PartConnectionStatus::cases() as $i => $status) {
            $part = $this->price(new Part(), (string) (10 - $i));
            $lots[$status->value] = $this->lot($part, 10, $i + 1);
            $this->connect($meta, $part, $i + 1, $status);
        }
        $meta->setPriceMethod(PartPriceMethod::CHEAPEST);
        $project = $this->project([[$meta, 1.0]]);
        $entry = $project->getBomEntries()->first();

        $reached = (new ProjectBuildRequest($project, 1))->getPartLotsForBOMEntry($entry);
        $this->assertSame([$lots['use_up'], $lots['approved']], $reached);
        $this->assertSame(20, $this->helper->getMaximumBuildableCount($project));

        //The connections that are not usable are cheaper, but give no price
        $prices = [];
        foreach (PartConnectionStatus::cases() as $i => $status) {
            $prices[$status->value] = 10 - $i;
        }
        $cost = $this->helper->calculateCost($project);
        $this->assertTrue(BigDecimal::of(min($prices['approved'], $prices['use_up']))->isEqualTo($cost->total), 'Got '.$cost->total);

        $meta->setPriceMethod(PartPriceMethod::AVERAGE);
        $cost = $this->helper->calculateCost($project);
        $this->assertTrue(BigDecimal::of(($prices['approved'] + $prices['use_up']) / 2)->isEqualTo($cost->total), 'Got '.$cost->total);
    }

    public function testAnExpiredLotOfAMetaPartEntryIsProposedButNotCounted(): void
    {
        $meta = $this->meta();
        $connected = new Part();
        $expired = $this->lot($connected, 100, 1, '2000-01-01');
        $this->connect($meta, $connected, 1);
        $project = $this->project([[$meta, 10.0]]);
        $entry = $project->getBomEntries()->first();

        $this->assertSame(10.0, (new ProjectBuildRequest($project, 1))->getWithdrawAmount($entry, $expired));
        $this->assertSame(0, $this->helper->getMaximumBuildableCount($project));
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
     * Prices 2 with a stock of 1, 1 with a stock of 3, unpriced with a stock of 10, and a do-not-use at 0.5.
     */
    private function pricedMetaPart(bool $firstHasPrice = true): Part
    {
        $meta = $this->meta();
        $first = $firstHasPrice ? $this->price(new Part(), '2') : new Part();
        $this->lot($first, 1);
        $second = $this->price(new Part(), '1');
        $this->lot($second, 3);
        $this->connect($meta, $first, 1);
        $this->connect($meta, $second, 2);
        $unpriced = new Part();
        $this->lot($unpriced, 10);
        $this->connect($meta, $unpriced, 3);
        $this->connect($meta, $this->price(new Part(), '0.5'), 4, PartConnectionStatus::DO_NOT_USE);

        return $meta;
    }

    /**
     * @return array<string, array{PartPriceMethod, string}> The cost of one unit of the priced meta part by each method
     */
    public static function methodProvider(): array
    {
        //Prices 2 (stock 1), 1 (stock 3), unpriced, and a do-not-use at 0.5
        return [
            'primary source' => [PartPriceMethod::PRIMARY_SOURCE, '2'],
            'cheapest' => [PartPriceMethod::CHEAPEST, '1'],
            'average' => [PartPriceMethod::AVERAGE, '1.5'],
            'weighted by stock' => [PartPriceMethod::WEIGHTED_BY_STOCK, '1.25'],
            'user value' => [PartPriceMethod::USER_VALUE, '0.3'],
            'system default, primary source by default' => [PartPriceMethod::SYSTEM_DEFAULT, '2'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('methodProvider')]
    public function testAMetaPartEntryCostsItsPriceByItsMethodTimesTheQuantity(PartPriceMethod $method, string $unit): void
    {
        $meta = $this->pricedMetaPart();
        $meta->setPriceMethod($method)->setPriceUserValue(BigDecimal::of('0.3'));

        $cost = $this->helper->calculateCost($this->project([[$meta, 2.0]]), 3);

        $this->assertTrue(BigDecimal::of($unit)->multipliedBy(6)->isEqualTo($cost->total), 'Got '.$cost->total);
        $this->assertTrue(BigDecimal::of($unit)->isEqualTo($this->helper->getEntryUnitCost($this->project([[$meta, 2.0]])->getBomEntries()->first())));
    }

    public function testThePrimarySourceIsTheFirstUsableConnectionWithAPrice(): void
    {
        $meta = $this->pricedMetaPart(false)->setPriceMethod(PartPriceMethod::PRIMARY_SOURCE);

        $this->assertTrue(BigDecimal::of('1')->isEqualTo($this->helper->calculateCost($this->project([[$meta, 1.0]]))->total));
    }

    public function testWeightedByStockIsTheAverageWithNoStock(): void
    {
        $meta = $this->meta(PartPriceMethod::WEIGHTED_BY_STOCK);
        $this->connect($meta, $this->price(new Part(), '2'), 1);
        $this->connect($meta, $this->price(new Part(), '1'), 2);

        $this->assertTrue(BigDecimal::of('1.5')->isEqualTo($this->helper->calculateCost($this->project([[$meta, 1.0]]))->total));
    }

    public function testTheSystemDefaultFollowsTheSetting(): void
    {
        $settings = self::getContainer()->get(MetaPartSettings::class);
        $before = $settings->defaultPriceMethod;
        $project = $this->project([[$this->pricedMetaPart(), 1.0]]);
        try {
            $settings->defaultPriceMethod = PartPriceMethod::CHEAPEST;
            $this->assertTrue(BigDecimal::of('1')->isEqualTo($this->helper->calculateCost($project)->total));
            $settings->defaultPriceMethod = PartPriceMethod::AVERAGE;
            $this->assertTrue(BigDecimal::of('1.5')->isEqualTo($this->helper->calculateCost($project)->total));
        } finally {
            $settings->defaultPriceMethod = $before;
        }
    }

    public function testThePriceIsTakenAtTheQuantityOfAllBuilds(): void
    {
        $meta = $this->meta(PartPriceMethod::PRIMARY_SOURCE);
        $this->connect($meta, $this->price(new Part(), '2', null, ['10' => '1']), 1);
        $project = $this->project([[$meta, 5.0]]);

        $this->assertTrue(BigDecimal::of('10')->isEqualTo($this->helper->calculateCost($project)->total), 'Five at the price of one');
        $this->assertTrue(BigDecimal::of('10')->isEqualTo($this->helper->calculateCost($project, 2)->total), 'Ten at the bulk price');
    }

    public function testTheMinimumOrderIsAppliedOnceToEachConnectedPart(): void
    {
        $meta = $this->meta(PartPriceMethod::CHEAPEST);
        //Only sold from 100 pieces at 0.5, and from 1 piece at 1
        $this->connect($meta, $this->price(new Part(), '0.5', null, [], 100.0), 1);
        $this->connect($meta, $this->price(new Part(), '1'), 2);

        $cost = $this->helper->calculateCost($this->project([[$meta, 10.0]]));

        $this->assertTrue(BigDecimal::of('5')->isEqualTo($cost->total), 'The price of 100 pieces applies to 10, got '.$cost->total);
    }

    public function testAUserValueIsTheSameAtAnyQuantity(): void
    {
        $meta = $this->pricedMetaPart();
        $meta->setPriceMethod(PartPriceMethod::USER_VALUE)->setPriceUserValue(BigDecimal::of('0.25'));

        foreach ([1, 1000] as $builds) {
            $cost = $this->helper->calculateCost($this->project([[$meta, 4.0]]), $builds);
            $this->assertTrue(BigDecimal::of('0.25')->multipliedBy(4 * $builds)->isEqualTo($cost->total), 'Got '.$cost->total);
        }
    }

    public function testAMetaPartWithNoPriceIsCountedAsUnpriced(): void
    {
        $userValueWithoutValue = $this->meta(PartPriceMethod::USER_VALUE);
        $this->connect($userValueWithoutValue, $this->price(new Part(), '1'), 1);
        $onlyUnusable = $this->meta();
        $this->connect($onlyUnusable, $this->price(new Part(), '1'), 1, PartConnectionStatus::UNDER_EVALUATION);
        $project = $this->project([[$this->pricedMetaPart(), 1.0], [new Part(), 1.0], [$userValueWithoutValue, 1.0], [$onlyUnusable, 1.0]]);

        $cost = $this->helper->calculateCost($project);

        $this->assertSame(3, $cost->unpricedEntries, 'A plain part and two meta parts without a price');
        $this->assertTrue(BigDecimal::of('2')->isEqualTo($cost->total), 'The others are summed, got '.$cost->total);
        $this->assertNull($this->helper->getEntryUnitCost($project->getBomEntries()->get(2)));
    }

    public function testASubProjectIsPricedByThePriceMethodsOfItsOwnEntries(): void
    {
        $sub = $this->project([[$this->pricedMetaPart()->setPriceMethod(PartPriceMethod::CHEAPEST), 1.0]]);
        $buildPart = new Part();
        $sub->setBuildPart($buildPart);

        $cost = $this->helper->calculateCost($this->project([[$buildPart, 2.0]]));

        $this->assertTrue(BigDecimal::of('2')->isEqualTo($cost->total), 'Got '.$cost->total);
    }

    public function testAMetaPartAsBuildPartOfASubProjectCostsTheSubProject(): void
    {
        //Stored data can hold this, though validation refuses it
        $sub = $this->project([[$this->price(new Part(), '7'), 1.0]]);
        $metaBuildPart = $this->pricedMetaPart();
        $sub->setBuildPart($metaBuildPart);

        $cost = $this->helper->calculateCost($this->project([[$metaBuildPart, 2.0]]));

        $this->assertTrue(BigDecimal::of('14')->isEqualTo($cost->total), 'Got '.$cost->total);
    }

    public function testPricesAreComparedInTheRequestedCurrency(): void
    {
        $eur = (new Currency())->setExchangeRate(BigDecimal::of('2.00000'));
        $usd = (new Currency())->setExchangeRate(BigDecimal::of('1.00000'));
        $meta = $this->meta(PartPriceMethod::CHEAPEST);
        //3 EUR is 6 in the base currency, and 5 USD is 5: the higher number is the cheaper part
        $this->connect($meta, $this->price(new Part(), '3', $eur), 1);
        $this->connect($meta, $this->price(new Part(), '5', $usd), 2);

        $cost = $this->helper->calculateCost($this->project([[$meta, 1.0]]), 1, $usd);

        $this->assertTrue(BigDecimal::of('5')->isEqualTo($cost->total), 'Got '.$cost->total);
    }

    public function testAUserValueIsConvertedToTheRequestedCurrency(): void
    {
        $eur = (new Currency())->setExchangeRate(BigDecimal::of('2.00000'));
        $meta = $this->meta(PartPriceMethod::USER_VALUE)->setPriceUserValue(BigDecimal::of('3'))->setPriceUserCurrency($eur);

        $this->assertTrue(BigDecimal::of('6')->isEqualTo($this->helper->calculateCost($this->project([[$meta, 1.0]]))->total));
        $this->assertTrue(BigDecimal::of('3')->isEqualTo($this->helper->calculateCost($this->project([[$meta, 1.0]]), 1, $eur)->total));
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
        $this->assertContains('validator.part_connection.would_let_project_reach_own_build_part', $paths);
    }

    /**
     * @return list<string> The violations as path: message
     */
    private function violations(object $object): array
    {
        $found = [];
        foreach (self::getContainer()->get(ValidatorInterface::class)->validate($object) as $violation) {
            $found[] = $violation->getPropertyPath().': '.$violation->getMessageTemplate();
        }

        return $found;
    }

    /**
     * Connects both ends in memory, as Doctrine does when it loads them.
     */
    private function connectBothEnds(Part $meta, Part $part, PartConnectionStatus $status = PartConnectionStatus::APPROVED): void
    {
        $connection = (new PartConnection())->setConnectedPart($part)->setStatus($status);
        $meta->addPartConnection($connection);
        $part->getConnectionsAsPart()->add($connection);
    }

    private function entryIn(Project $project, Part $part): ProjectBOMEntry
    {
        $entry = new ProjectBOMEntry();
        $entry->setPart($part);
        $entry->setQuantity(1);
        $project->addBomEntry($entry);
        $part->getProjectBomEntries()->add($entry);

        return $entry;
    }

    public function testAMetaPartCanBeABuildPart(): void
    {
        $project = new Project();
        $project->setName('Project');
        $project->setBuildPart($this->meta());

        $this->assertSame([], array_values(array_filter($this->violations($project), static fn (string $v): bool => str_starts_with($v, 'build_part'))));
    }

    public function testABomEntryCanNotReachAMetaBuildPartThroughItsConnectedParts(): void
    {
        $metaBuild = $this->meta();
        $connected = new Part();
        $otherMeta = $this->meta();
        $this->connectBothEnds($metaBuild, $connected, PartConnectionStatus::DO_NOT_USE);
        $this->connectBothEnds($otherMeta, $connected);
        $x = new Project();
        $x->setBuildPart($metaBuild);
        $sub = new Project();
        $sub->setParent($x);

        $own = 'part: project.bom_entry.can_not_add_own_builds_part';
        $refused = 'part: validator.build_part.reached_by_own_bom';
        $this->assertSame([$own], $this->violations($this->entryIn($x, $metaBuild)), 'The build part itself');
        $this->assertSame([$own], $this->violations($this->entryIn($sub, $metaBuild)), 'The build part of the parent project');
        $this->assertSame([$refused], $this->violations($this->entryIn($x, $connected)), 'Its connected part, whatever the status');
        $this->assertSame([$refused], $this->violations($this->entryIn($sub, $connected)), 'In a sub-project');
        $this->assertSame([$refused], $this->violations($this->entryIn($x, $otherMeta)), 'A meta part sharing the connected part');
        $this->assertNotContains($refused, $this->violations($this->entryIn(new Project(), $connected)), 'Another project');
        $this->assertNotContains($refused, $this->violations($this->entryIn($x, new Part())), 'An unrelated part');
    }

    public function testAProjectCanNotGetABuildPartItsBomReaches(): void
    {
        $metaBuild = $this->meta();
        $connected = new Part();
        $this->connectBothEnds($metaBuild, $connected);
        $x = new Project();
        $x->setName('X');
        $sub = new Project();
        $sub->setName('Sub');
        $sub->setParent($x);
        $this->entryIn($sub, $connected);

        $x->setBuildPart($metaBuild);
        $this->assertContains('build_part: validator.build_part.reached_by_own_bom', $this->violations($x));

        $y = new Project();
        $y->setName('Y');
        $this->entryIn($y, new Part());
        $y->setBuildPart($this->meta());
        $this->assertNotContains('build_part: validator.build_part.reached_by_own_bom', $this->violations($y));
    }

    public function testAMetaBuildPartOfASubProjectCostsOneBuildOfIt(): void
    {
        $sub = $this->project([[$this->price(new Part(), '2'), 3.0]]);
        $metaBuild = $this->meta();
        $this->connect($metaBuild, $this->price(new Part(), '100'), 1);
        $sub->setBuildPart($metaBuild);
        $parent = $this->project([[$metaBuild, 2.0]]);

        $cost = $this->helper->calculateCost($parent);
        $this->assertTrue(BigDecimal::of('12')->isEqualTo($cost->total), 'Two builds of the sub-project at 6, got '.$cost->total);
    }

    public function testACostEndsOnAMetaBuildPartStoredInItsOwnBom(): void
    {
        $metaBuild = $this->meta();
        $project = $this->project([[$metaBuild, 1.0], [$this->price(new Part(), '4'), 1.0]]);
        $project->setBuildPart($metaBuild);

        $cost = $this->helper->calculateCost($project);
        $this->assertTrue(BigDecimal::of('4')->isEqualTo($cost->total), 'The loop is left out, got '.$cost->total);
    }
}
