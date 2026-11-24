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

declare(strict_types=1);

namespace App\Services\ProjectSystem;

use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\ProjectSystem\Project;
use Doctrine\Common\Collections\Criteria;

/**
 * The one rule of which build parts a BOM entry reaches, used by the BOM entry, the connection, the part and the
 * project validation, so that a project can not reach its own build part, or that of an ancestor, by any route.
 *
 * A BOM entry on a part reaches the part and, for a kind with connections, its connected parts. A reached part brings
 * in the build part it is, and the meta parts it is connected to. Every status counts, as a status can change without
 * the BOM being validated again.
 *
 * The methods are static and need no services, so that entities can call them. A connection that is being validated
 * may not be in the collections yet, so it can be given as $extra, and is then counted on both of its ends.
 *
 * @see \App\Tests\Services\ProjectSystem\BuildPartReachTest
 */
final class BuildPartReach
{
    /**
     * The parts a BOM entry on the given part draws on: the part and, for a kind with connections, its connected parts.
     * @return list<Part>
     */
    public static function reachedParts(Part $part, ?PartConnection $extra = null): array
    {
        $parts = [$part];
        if ($part->getKind()->allowsConnections()) {
            foreach (self::connectionsOf($part, $extra) as $connection) {
                self::addOnce($parts, $connection->getConnectedPart());
            }
        }

        return $parts;
    }

    /**
     * The parts whose builds the given part may stand for: the part and the meta parts it is connected to.
     * @return list<Part>
     */
    public static function buildPartCandidates(Part $part, ?PartConnection $extra = null): array
    {
        $parts = [$part];
        foreach (self::connectionsAsPartOf($part, $extra) as $connection) {
            self::addOnce($parts, $connection->getMetaPart());
        }

        return $parts;
    }

    /**
     * The projects whose build part a BOM entry on the given part reaches.
     * @return list<Project>
     */
    public static function projectsReached(Part $part, ?PartConnection $extra = null): array
    {
        $projects = [];
        foreach (self::reachedParts($part, $extra) as $reached) {
            foreach (self::buildPartCandidates($reached, $extra) as $candidate) {
                $project = $candidate->getBuiltProject();
                if ($project instanceof Project && !in_array($project, $projects, true)) {
                    $projects[] = $project;
                }
            }
        }

        return $projects;
    }

    /**
     * Whether a BOM entry on the given part in the given project would reach the build part of that project or of one
     * of its ancestors.
     */
    public static function reachesOwnBuildPart(Project $project, Part $part, ?PartConnection $extra = null): bool
    {
        foreach (self::projectsReached($part, $extra) as $reached) {
            if ($project->isOrHasAncestor($reached)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The other way round: the parts on which a BOM entry reaches the given build part. These are the build part, the
     * meta parts it is connected to, and for a kind with connections its connected parts and the meta parts those are
     * connected to.
     * @return list<Part>
     */
    public static function partsReaching(Part $buildPart, ?PartConnection $extra = null): array
    {
        $parts = [];
        foreach (self::buildPartCandidates($buildPart, $extra) as $part) {
            self::addOnce($parts, $part);
        }
        if ($buildPart->getKind()->allowsConnections()) {
            foreach (self::connectionsOf($buildPart, $extra) as $connection) {
                $connected = $connection->getConnectedPart();
                if ($connected instanceof Part) {
                    foreach (self::buildPartCandidates($connected, $extra) as $part) {
                        self::addOnce($parts, $part);
                    }
                }
            }
        }

        return $parts;
    }

    /**
     * Whether a BOM entry of the given project or of one of its sub-projects reaches the given part as its build part,
     * by any route. The extra-lazy BOMs are asked with matching(), so that no BOM is loaded.
     */
    public static function isReachedByBomOf(Project $project, Part $buildPart): bool
    {
        return self::bomOfProjectOrDescendantsHasOneOf($project, self::partsReaching($buildPart));
    }

    /**
     * @param  list<Part>  $parts
     */
    private static function bomOfProjectOrDescendantsHasOneOf(Project $project, array $parts): bool
    {
        foreach ($parts as $part) {
            $found = $project->getBomEntries()->matching(Criteria::create()->where(Criteria::expr()->eq('part', $part)));
            if (!$found->isEmpty()) {
                return true;
            }
        }

        foreach ($project->getChildren() as $child) {
            if (self::bomOfProjectOrDescendantsHasOneOf($child, $parts)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<PartConnection>
     */
    private static function connectionsOf(Part $metaPart, ?PartConnection $extra): array
    {
        $connections = array_values($metaPart->getPartConnections()->toArray());
        if ($extra instanceof PartConnection && $extra->getMetaPart() === $metaPart && !in_array($extra, $connections, true)) {
            $connections[] = $extra;
        }

        return $connections;
    }

    /**
     * @return list<PartConnection>
     */
    private static function connectionsAsPartOf(Part $part, ?PartConnection $extra): array
    {
        $connections = array_values($part->getConnectionsAsPart()->toArray());
        if ($extra instanceof PartConnection && $extra->getConnectedPart() === $part && !in_array($extra, $connections, true)) {
            $connections[] = $extra;
        }

        return $connections;
    }

    /**
     * @param  list<Part>  $parts
     */
    private static function addOnce(array &$parts, ?Part $part): void
    {
        if ($part instanceof Part && !in_array($part, $parts, true)) {
            $parts[] = $part;
        }
    }
}
