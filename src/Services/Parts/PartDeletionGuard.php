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

use App\Entity\Parts\Part;
use App\Entity\ProjectSystem\Project;
use App\Exceptions\PartUsedInBomException;
use App\Settings\BehaviorSettings\PartDeletionSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one check whether a part may be deleted, asked by every path that deletes a part: the part page, the delete
 * action of part tables, REST, MCP and, as a safety net, PartProjectBOMEntryUnlinkListener. Only a BOM entry that
 * points to the part itself counts; a part used only through a meta part may be deleted. A merge does not ask it.
 * @see \App\Tests\Services\Parts\PartDeletionGuardTest
 */
final readonly class PartDeletionGuard
{
    public function __construct(private EntityManagerInterface $entityManager, private PartDeletionSettings $settings)
    {
    }

    /**
     * Whether deleting a part used in a BOM is refused, read on each call so that a saved change counts at once.
     */
    public function isRefusing(): bool
    {
        return $this->settings->refuseDeletingPartsUsedInBom;
    }

    /**
     * The projects whose BOM points to the part, each once and sorted by name, leaving out entries that are already
     * scheduled for deletion (their project is deleted in the same flush). It does not look at the setting.
     * @return list<Project>
     */
    public function getBlockingProjects(Part $part): array
    {
        $uow = $this->entityManager->getUnitOfWork();
        $projects = [];
        foreach ($part->getProjectBomEntries() as $entry) {
            $project = $entry->getProject();
            if ($project === null || $uow->isScheduledForDelete($entry) || $uow->isScheduledForDelete($project)) {
                continue;
            }
            $projects[spl_object_id($project)] = $project;
        }
        $projects = array_values($projects);
        usort($projects, static fn (Project $a, Project $b): int => strnatcasecmp($a->getName(), $b->getName()));

        return $projects;
    }

    /**
     * Does nothing while the setting is off; otherwise throws when a BOM entry points to the part.
     * @throws PartUsedInBomException
     */
    public function assertDeletable(Part $part): void
    {
        if (!$this->isRefusing()) {
            return;
        }

        $projects = $this->getBlockingProjects($part);
        if ($projects !== []) {
            throw new PartUsedInBomException($part->getName(), array_map(static fn (Project $p): string => $p->getName(), $projects));
        }
    }
}
