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
namespace App\Tests\Helpers\Projects;

use App\Entity\Parts\MeasurementUnit;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartConnectionStatus;
use App\Entity\Parts\PartKind;
use App\Entity\Parts\PartLot;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use App\Helpers\Projects\ProjectBuildRequest;
use PHPUnit\Framework\TestCase;

final class ProjectBuildRequestTest extends TestCase
{

    /** @var Project */
    private Project $project1;
    /** @var ProjectBOMEntry */
    private ProjectBOMEntry $bom_entry1a;
    /** @var ProjectBOMEntry */
    private ProjectBOMEntry $bom_entry1b;
    /** @var ProjectBOMEntry */
    private ProjectBOMEntry $bom_entry1c;

    private PartLot $lot1a;
    private PartLot $lot1b;
    private PartLot $lot2;


    private function entryWithId(int $id): ProjectBOMEntry
    {
        return new class($id) extends ProjectBOMEntry {
            public function __construct(private readonly int $entry_id)
            {
                parent::__construct();
            }

            public function getID(): ?int
            {
                return $this->entry_id;
            }
        };
    }

    public function setUp(): void
    {
        $float_unit = new MeasurementUnit();
        $float_unit->setName('float');
        $float_unit->setUnit('f');
        $float_unit->setIsInteger(false);
        $float_unit->setUseSIPrefix(true);

        //Setup some example parts and part lots
        $part1 = new Part();
        $part1->setName('Part 1');
        $this->lot1a = new class extends PartLot {
            public function getID(): ?int
            {
                return 1;
            }
        };
        $part1->addPartLot($this->lot1a);
        $this->lot1a->setAmount(10);
        $this->lot1a->setDescription('Lot 1a');

        $this->lot1b = new class extends PartLot {
            public function getID(): ?int
            {
                return 2;
            }
        };
        $part1->addPartLot($this->lot1b);
        $this->lot1b->setAmount(20);
        $this->lot1b->setDescription('Lot 1b');

        $part2 = new Part();

        $part2->setName('Part 2');
        $part2->setPartUnit($float_unit);
        $this->lot2 = new class extends PartLot {
            public function getID(): ?int
            {
                return 3;
            }
        };;
        $part2->addPartLot($this->lot2);
        $this->lot2->setAmount(2.5);
        $this->lot2->setDescription('Lot 2');

        $this->bom_entry1a = $this->entryWithId(1);
        $this->bom_entry1a->setPart($part1);
        $this->bom_entry1a->setQuantity(2);

        $this->bom_entry1b = $this->entryWithId(2);
        $this->bom_entry1b->setPart($part2);
        $this->bom_entry1b->setQuantity(1.5);

        $this->bom_entry1c = $this->entryWithId(3);
        $this->bom_entry1c->setName('Non-part BOM entry');
        $this->bom_entry1c->setQuantity(4);


        $this->project1 = new Project();
        $this->project1->setName('Project 1');
        $this->project1->addBomEntry($this->bom_entry1a);
        $this->project1->addBomEntry($this->bom_entry1b);
        $this->project1->addBomEntry($this->bom_entry1c);
    }

    public function testInitialization(): void
    {
        //The values should be already prefilled correctly
        $request = new ProjectBuildRequest($this->project1, 10);
        //We need totally 20: The largest lot comes first, as in the collection read from the database (amount
        //descending), so all 20 are taken from the second (maximum 20) and none from the first (maximum 10)
        $this->assertEqualsWithDelta(0.0, $request->getLotWithdrawAmount($this->lot1a), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(20.0, $request->getLotWithdrawAmount($this->lot1b), PHP_FLOAT_EPSILON);

        //If the needed amount is higher than the maximum, we should get the maximum
        $this->assertEqualsWithDelta(2.5, $request->getLotWithdrawAmount($this->lot2), PHP_FLOAT_EPSILON);
    }

    public function testGetNumberOfBuilds(): void
    {
        $build_request = new ProjectBuildRequest($this->project1, 5);
        $this->assertSame(5, $build_request->getNumberOfBuilds());
    }

    public function testGetProject(): void
    {
        $build_request = new ProjectBuildRequest($this->project1, 5);
        $this->assertEquals($this->project1, $build_request->getProject());
    }

    public function testGetNeededAmountForBOMEntry(): void
    {
        $build_request = new ProjectBuildRequest($this->project1, 5);
        $this->assertEqualsWithDelta(10.0, $build_request->getNeededAmountForBOMEntry($this->bom_entry1a), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(7.5, $build_request->getNeededAmountForBOMEntry($this->bom_entry1b), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(20.0, $build_request->getNeededAmountForBOMEntry($this->bom_entry1c), PHP_FLOAT_EPSILON);
    }

    public function testGetSetLotWithdrawAmount(): void
    {
        $build_request = new ProjectBuildRequest($this->project1, 5);

        //We can set the amount for a lot either via the lot object or via the ID
        $build_request->setWithdrawAmount($this->bom_entry1a, $this->lot1a, 2);
        $build_request->setWithdrawAmount($this->bom_entry1a->getID(), $this->lot1b->getID(), 3);

        //And it should be possible to get the amount via the lot object or via the ID
        $this->assertEqualsWithDelta(2.0, $build_request->getLotWithdrawAmount($this->lot1a->getID()), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(3.0, $build_request->getLotWithdrawAmount($this->lot1b), PHP_FLOAT_EPSILON);
    }

    public function testGetWithdrawAmountSum(): void
    {
        //The sum of all withdraw amounts for an BOM entry (over all lots of the associated part) should be correct
        $build_request = new ProjectBuildRequest($this->project1, 5);

        $build_request->setWithdrawAmount($this->bom_entry1a, $this->lot1a, 2);
        $build_request->setWithdrawAmount($this->bom_entry1a, $this->lot1b, 3);

        $this->assertEqualsWithDelta(5.0, $build_request->getWithdrawAmountSum($this->bom_entry1a), PHP_FLOAT_EPSILON);
        $build_request->setWithdrawAmount($this->bom_entry1b, $this->lot2, 1.5);
        $this->assertEqualsWithDelta(1.5, $build_request->getWithdrawAmountSum($this->bom_entry1b), PHP_FLOAT_EPSILON);
    }

    /**
     * Regression test for issue #1613: Floating point inaccuracies (0.26 * 11 = 2.8600000000000003) must not cause
     * the needed amount to differ from the withdrawn amount entered by the user.
     */
    public function testFloatingPointInaccuracies(): void
    {
        $float_unit = new MeasurementUnit();
        $float_unit->setName('Liter');
        $float_unit->setUnit('l');
        $float_unit->setIsInteger(false);

        $part = new Part();
        $part->setPartUnit($float_unit);
        $lot_a = new class extends PartLot {
            public function getID(): ?int
            {
                return 10;
            }
        };
        $lot_a->setAmount(1.2);
        $part->addPartLot($lot_a);
        $lot_b = new class extends PartLot {
            public function getID(): ?int
            {
                return 11;
            }
        };
        //Smaller than the need, so that the largest lot, taken first, leaves a rest with float residue for the other
        $lot_b->setAmount(2.0);
        $part->addPartLot($lot_b);

        $bom_entry = $this->entryWithId(1);
        $bom_entry->setPart($part);
        $bom_entry->setQuantity(0.26);

        $project = new Project();
        $project->addBomEntry($bom_entry);

        $request = new ProjectBuildRequest($project, 11);
        $this->assertSame(2.86, $request->getNeededAmountForBOMEntry($bom_entry));

        //The prefilled values must exactly match the needed amount
        $this->assertSame(0.86, $request->getLotWithdrawAmount($lot_a));
        $this->assertSame(2.0, $request->getLotWithdrawAmount($lot_b));
        $this->assertSame(2.86, $request->getWithdrawAmountSum($bom_entry));

        //Values entered by the user (as they come from the form) must also match exactly
        $request->setWithdrawAmount($bom_entry, $lot_a, 0.0);
        $request->setWithdrawAmount($bom_entry, $lot_b, 2.86);
        $this->assertSame(2.86, $request->getWithdrawAmountSum($bom_entry));
    }

    private function lotWithId(int $id, float $amount, ?string $expires = null): PartLot
    {
        $lot = new class($id) extends PartLot {
            public function __construct(private readonly int $lot_id)
            {
            }

            public function getID(): ?int
            {
                return $this->lot_id;
            }
        };
        $lot->setAmount($amount);
        if ($expires !== null) {
            $lot->setExpirationDate(new \DateTimeImmutable($expires));
        }

        return $lot;
    }

    public function testEntriesOnOneLotEachKeepTheirOwnAmount(): void
    {
        $part = new Part();
        $lot = $this->lotWithId(1, 100);
        $part->addPartLot($lot);
        $other_part = new Part();
        $out_of_reach = $this->lotWithId(2, 100);
        $other_part->addPartLot($out_of_reach);

        $first = $this->entryWithId(1);
        $first->setPart($part);
        $first->setQuantity(30);
        $second = $this->entryWithId(2);
        $second->setName('Same part again');
        $second->setPart($part);
        $second->setQuantity(20);
        $third = $this->entryWithId(3);
        $third->setPart($other_part);
        $third->setQuantity(1);
        $project = new Project();
        $project->addBomEntry($first);
        $project->addBomEntry($second);
        $project->addBomEntry($third);

        $request = new ProjectBuildRequest($project, 1);
        $request->setWithdrawAmount($first, $lot, 25);
        $request->setWithdrawAmount($second, $lot, 15);

        $this->assertSame(25.0, $request->getWithdrawAmount($first, $lot));
        $this->assertSame(15.0, $request->getWithdrawAmount($second, $lot));
        $this->assertSame(25.0, $request->getWithdrawAmountSum($first));
        $this->assertSame(15.0, $request->getWithdrawAmountSum($second));
        $this->assertSame(40.0, $request->getLotWithdrawAmount($lot));

        $this->expectException(\InvalidArgumentException::class);
        $request->setWithdrawAmount($first, $out_of_reach, 1);
    }

    public function testMetaPartEntryProposesAnExpiredLotAsADirectEntryDoes(): void
    {
        $meta = (new Part())->setKind(PartKind::META);
        $expired = $this->lotWithId(1, 50, '2000-01-01');
        $meta->addPartLot($expired);
        $connected = new Part();
        $good = $this->lotWithId(2, 50);
        $connected->addPartLot($good);
        $meta->addPartConnection((new PartConnection())->setConnectedPart($connected));

        $entry = $this->entryWithId(1);
        $entry->setPart($meta);
        $entry->setQuantity(10);
        $project = new Project();
        $project->addBomEntry($entry);

        $request = new ProjectBuildRequest($project, 1);

        $this->assertSame([$expired, $good], $request->getPartLotsForBOMEntry($entry));
        $this->assertSame(10.0, $request->getWithdrawAmount($entry, $expired));
        $this->assertSame(0.0, $request->getWithdrawAmount($entry, $good));
    }

    public function testNothingIsProposedFromALotTheUserMayNotWithdrawFrom(): void
    {
        $part = new Part();
        $forbidden = $this->lotWithId(1, 5);
        $allowed = $this->lotWithId(2, 50);
        $part->addPartLot($forbidden);
        $part->addPartLot($allowed);
        $entry = $this->entryWithId(1);
        $entry->setPart($part);
        $entry->setQuantity(10);
        $project = new Project();
        $project->addBomEntry($entry);

        $request = new ProjectBuildRequest($project, 1, static fn (PartLot $lot): bool => $lot !== $forbidden);

        $this->assertSame(0.0, $request->getWithdrawAmount($entry, $forbidden));
        $this->assertSame(10.0, $request->getWithdrawAmount($entry, $allowed));
    }

    /**
     * @return array{0: Project, 1: PartLot, 2: array<string, PartLot>} A project built from a meta part with an own
     * lot and one connected part per status, each with one lot
     */
    private function projectWithMetaBuildPart(): array
    {
        $meta = (new Part())->setKind(PartKind::META);
        $own = $this->lotWithId(100, 1);
        $meta->addPartLot($own);
        $connected = [];
        foreach (PartConnectionStatus::cases() as $i => $status) {
            $part = new Part();
            $connected[$status->value] = $this->lotWithId(101 + $i, 5);
            $part->addPartLot($connected[$status->value]);
            $meta->addPartConnection((new PartConnection())->setConnectedPart($part)->setPosition($i + 1)->setStatus($status));
        }
        $project = new Project();
        $project->setBuildPart($meta);

        return [$project, $own, $connected];
    }

    public function testAMetaBuildPartOffersItsOwnLotsAndThoseOfEveryConnectedPart(): void
    {
        [$project, $own, $connected] = $this->projectWithMetaBuildPart();
        $request = new ProjectBuildRequest($project, 1);

        $this->assertSame([$own, $connected['approved'], $connected['use_up'], $connected['under_evaluation'], $connected['do_not_use']],
            $request->getBuildsPartLotChoices());
        $this->assertSame($own, $request->getBuildsPartLot());

        foreach ($connected as $status => $lot) {
            $request->setBuildsPartLot($lot);
            $this->assertSame($lot, $request->getBuildsPartLot(), $status);
        }
    }

    public function testAMetaBuildPartWithoutConnectionsOffersItsOwnLotsOnly(): void
    {
        $meta = (new Part())->setKind(PartKind::META);
        $own = $this->lotWithId(100, 1);
        $meta->addPartLot($own);
        $project = new Project();
        $project->setBuildPart($meta);

        $this->assertSame([$own], (new ProjectBuildRequest($project, 1))->getBuildsPartLotChoices());
    }

    public function testTheFirstLotOfKnownAmountIsChosenByDefault(): void
    {
        $meta = (new Part())->setKind(PartKind::META);
        $unknown = $this->lotWithId(100, 0);
        $unknown->setInstockUnknown(true);
        $meta->addPartLot($unknown);
        $part = new Part();
        $lot = $this->lotWithId(101, 5);
        $part->addPartLot($lot);
        $meta->addPartConnection((new PartConnection())->setConnectedPart($part));
        $project = new Project();
        $project->setBuildPart($meta);

        $this->assertSame($lot, (new ProjectBuildRequest($project, 1))->getBuildsPartLot());
    }

    public function testTheLotOfAnUnusableConnectedPartIsNeverChosenByDefault(): void
    {
        $meta = (new Part())->setKind(PartKind::META);
        $notUsable = new Part();
        $notUsableLot = $this->lotWithId(100, 5);
        $notUsable->addPartLot($notUsableLot);
        $meta->addPartConnection((new PartConnection())->setConnectedPart($notUsable)->setPosition(1)->setStatus(PartConnectionStatus::DO_NOT_USE));
        $project = new Project();
        $project->setBuildPart($meta);

        $request = new ProjectBuildRequest($project, 1);
        $this->assertSame([$notUsableLot], $request->getBuildsPartLotChoices(), 'It can still be chosen');
        $this->assertNull($request->getBuildsPartLot());

        //A lot of a usable connected part comes before it
        $usable = new Part();
        $usableLot = $this->lotWithId(101, 5);
        $usable->addPartLot($usableLot);
        $meta->addPartConnection((new PartConnection())->setConnectedPart($usable)->setPosition(2)->setStatus(PartConnectionStatus::USE_UP));
        $this->assertSame($usableLot, (new ProjectBuildRequest($project, 1))->getBuildsPartLot());
    }

    public function testALotOfAnotherPartIsRefused(): void
    {
        [$project] = $this->projectWithMetaBuildPart();
        $request = new ProjectBuildRequest($project, 1);

        $this->expectException(\InvalidArgumentException::class);
        $request->setBuildsPartLot($this->lot1a);
    }

    public function testANewLotIsAcceptedOnlyOnTheBuildPartItself(): void
    {
        [$project, , $connected] = $this->projectWithMetaBuildPart();
        $request = new ProjectBuildRequest($project, 1);

        $newOwn = new PartLot();
        $project->getBuildPart()->addPartLot($newOwn);
        $request->setBuildsPartLot($newOwn);
        $this->assertSame($newOwn, $request->getBuildsPartLot());

        $newOnConnected = new PartLot();
        $connected['approved']->getPart()->addPartLot($newOnConnected);
        $this->expectException(\InvalidArgumentException::class);
        $request->setBuildsPartLot($newOnConnected);
    }
}

