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
namespace App\Tests\Entity\Parts;

use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartConnectionStatus;
use App\Entity\Parts\PartKind;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use PHPUnit\Framework\TestCase;

/**
 * The BOM entries that use a part, directly and through the meta parts it is connected to (TPRJ-36).
 */
final class PartBomUsagesTest extends TestCase
{
    private function part(string $name, PartKind $kind = PartKind::MANUFACTURING): Part
    {
        $part = (new Part())->setKind($kind);
        $part->setName($name);

        return $part;
    }

    private function useIn(Part $part, string $projectName): ProjectBOMEntry
    {
        $project = new Project();
        $project->setName($projectName);
        $entry = (new ProjectBOMEntry())->setQuantity(1.0);
        $project->addBomEntry($entry);
        $part->addProjectBomEntry($entry);

        return $entry;
    }

    /** Both ends, as Doctrine has them after loading. */
    private function connect(Part $meta, Part $part, PartConnectionStatus $status = PartConnectionStatus::APPROVED): PartConnection
    {
        $connection = (new PartConnection())->setConnectedPart($part)->setStatus($status);
        $meta->addPartConnection($connection);
        $part->getConnectionsAsPart()->add($connection);

        return $connection;
    }

    /**
     * @return list<array{string, string|null}> The project name and the meta part's name of each usage
     */
    private function summary(Part $part): array
    {
        return array_map(static fn (array $usage): array => [$usage['entry']->getProject()->getName(), $usage['via']?->getName()], $part->getBomUsages());
    }

    public function testDirectUseAndUseThroughAMetaPartAreBothGivenTheSecondNamingTheMetaPart(): void
    {
        $part = $this->part('R1');
        $direct = $this->useIn($part, 'P1');
        $meta = $this->part('Resistor 10k', PartKind::META);
        $through = $this->useIn($meta, 'P2');
        $connection = $this->connect($meta, $part);

        $usages = $part->getBomUsages();

        $this->assertCount(2, $usages);
        $this->assertSame(['entry' => $direct, 'via' => null, 'connection' => null], $usages[0]);
        $this->assertSame(['entry' => $through, 'via' => $meta, 'connection' => $connection], $usages[1]);
    }

    public function testDirectUseComesFirstAndEachGroupIsSortedByProjectName(): void
    {
        $part = $this->part('R1');
        $this->useIn($part, 'Zeta');
        $this->useIn($part, 'Alpha');
        $metaB = $this->part('Meta B', PartKind::META);
        $metaA = $this->part('Meta A', PartKind::META);
        $this->useIn($metaB, 'Beta');
        $this->useIn($metaA, 'Beta');
        $this->useIn($metaA, 'Aardvark');
        $this->connect($metaB, $part);
        $this->connect($metaA, $part);

        $this->assertSame([
            ['Alpha', null], ['Zeta', null],
            ['Aardvark', 'Meta A'], ['Beta', 'Meta A'], ['Beta', 'Meta B'],
        ], $this->summary($part));
    }

    public function testConnectionsOfEveryStatusCountAndCarryTheirConnection(): void
    {
        $part = $this->part('R1');
        foreach (PartConnectionStatus::cases() as $i => $status) {
            $meta = $this->part('Meta '.$i, PartKind::META);
            $this->useIn($meta, 'Project '.$i);
            $this->connect($meta, $part, $status);
        }

        $statuses = array_map(static fn (array $usage): PartConnectionStatus => $usage['connection']->getStatus(), $part->getBomUsages());

        $this->assertSame(PartConnectionStatus::cases(), $statuses);
    }

    public function testAProjectUsingThePartBothWaysGivesTwoUsages(): void
    {
        $part = $this->part('R1');
        $meta = $this->part('Meta', PartKind::META);
        $this->useIn($part, 'Board');
        $meta->addProjectBomEntry($entry = (new ProjectBOMEntry())->setQuantity(2.0));
        $part->getProjectBomEntries()->first()->getProject()->addBomEntry($entry);
        $this->connect($meta, $part);

        $this->assertSame([['Board', null], ['Board', 'Meta']], $this->summary($part));
    }

    public function testAMetaPartGivesItsOwnEntriesOnlyAndAnUnusedPartNone(): void
    {
        $meta = $this->part('Meta', PartKind::META);
        $this->useIn($meta, 'Board');
        $this->connect($meta, $this->part('Connected'));

        $this->assertSame([['Board', null]], $this->summary($meta));
        $this->assertSame([], $this->part('Unused')->getBomUsages());
    }
}
