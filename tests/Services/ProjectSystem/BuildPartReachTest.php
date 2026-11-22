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
use App\Entity\ProjectSystem\Project;
use App\Services\ProjectSystem\BuildPartReach;
use PHPUnit\Framework\TestCase;

final class BuildPartReachTest extends TestCase
{
    private function meta(): Part
    {
        return (new Part())->setKind(PartKind::META);
    }

    /**
     * Connects both ends in memory, as Doctrine does when it loads them.
     */
    private function connect(Part $meta, Part $part, PartConnectionStatus $status = PartConnectionStatus::APPROVED): PartConnection
    {
        $connection = (new PartConnection())->setConnectedPart($part)->setStatus($status);
        $meta->addPartConnection($connection);
        $part->getConnectionsAsPart()->add($connection);

        return $connection;
    }

    private function buildOf(Part $part): Project
    {
        $project = new Project();
        $project->setBuildPart($part);

        return $project;
    }

    public function testAManufacturingPartReachesItselfAndStandsForItsMetaParts(): void
    {
        $part = new Part();
        $meta = $this->meta();
        $this->connect($meta, $part, PartConnectionStatus::DO_NOT_USE);

        $this->assertSame([$part], BuildPartReach::reachedParts($part));
        $this->assertSame([$part, $meta], BuildPartReach::buildPartCandidates($part), 'Every status counts');
    }

    public function testAMetaPartReachesItsConnectedParts(): void
    {
        $meta = $this->meta();
        $one = new Part();
        $two = new Part();
        $this->connect($meta, $one);
        $this->connect($meta, $two, PartConnectionStatus::UNDER_EVALUATION);

        $this->assertSame([$meta, $one, $two], BuildPartReach::reachedParts($meta));
        $this->assertSame([$meta], BuildPartReach::buildPartCandidates($meta));
    }

    public function testEveryRouteToABuildPartIsFound(): void
    {
        //A meta build part reached through its connected part, from an entry on that part or on another meta part
        $metaBuild = $this->meta();
        $shared = new Part();
        $otherMeta = $this->meta();
        $this->connect($metaBuild, $shared);
        $this->connect($otherMeta, $shared);
        $x = $this->buildOf($metaBuild);

        $this->assertSame([$x], BuildPartReach::projectsReached($metaBuild));
        $this->assertSame([$x], BuildPartReach::projectsReached($shared));
        $this->assertSame([$x], BuildPartReach::projectsReached($otherMeta));

        //A manufacturing build part reached through a meta part it is connected to
        $build = new Part();
        $meta = $this->meta();
        $this->connect($meta, $build);
        $y = $this->buildOf($build);
        $this->assertSame([$y], BuildPartReach::projectsReached($meta));

        $this->assertSame([], BuildPartReach::projectsReached(new Part()));
    }

    public function testASubProjectReachesTheBuildPartOfItsParent(): void
    {
        $metaBuild = $this->meta();
        $connected = new Part();
        $this->connect($metaBuild, $connected);
        $parent = $this->buildOf($metaBuild);
        $child = new Project();
        $child->setParent($parent);

        $this->assertTrue(BuildPartReach::reachesOwnBuildPart($parent, $connected));
        $this->assertTrue(BuildPartReach::reachesOwnBuildPart($child, $connected));
        $this->assertFalse(BuildPartReach::reachesOwnBuildPart(new Project(), $connected));
    }

    public function testAConnectionNotYetInTheCollectionsCanBeGiven(): void
    {
        $metaBuild = $this->meta();
        $part = new Part();
        $x = $this->buildOf($metaBuild);
        $connection = (new PartConnection())->setMetaPart($metaBuild)->setConnectedPart($part);

        $this->assertSame([], BuildPartReach::projectsReached($part));
        $this->assertSame([$x], BuildPartReach::projectsReached($part, $connection));
        $this->assertSame([$metaBuild, $part], BuildPartReach::reachedParts($metaBuild, $connection));
        $this->assertSame([$metaBuild, $part], BuildPartReach::partsReaching($metaBuild, $connection));
    }

    public function testPartsReachingIsTheOtherWayRound(): void
    {
        //Two meta parts sharing a connected part, each with one more, and a part on its own
        $metaA = $this->meta();
        $metaB = $this->meta();
        $shared = new Part();
        $onlyA = new Part();
        $onlyB = new Part();
        $alone = new Part();
        $this->connect($metaA, $shared);
        $this->connect($metaA, $onlyA);
        $this->connect($metaB, $shared, PartConnectionStatus::DO_NOT_USE);
        $this->connect($metaB, $onlyB);
        $parts = [$metaA, $metaB, $shared, $onlyA, $onlyB, $alone];

        foreach ($parts as $buildPart) {
            $project = $this->buildOf($buildPart);
            $reaching = BuildPartReach::partsReaching($buildPart);
            foreach ($parts as $entryPart) {
                $this->assertSame(
                    in_array($project, BuildPartReach::projectsReached($entryPart), true),
                    in_array($entryPart, $reaching, true),
                );
            }
            $project->setBuildPart(null);
            $buildPart->setBuiltProject(null);
        }
    }
}
