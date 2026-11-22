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
namespace App\Helpers\Projects;

use App\Entity\Parts\Part;
use App\Entity\Parts\PartLot;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use App\Services\ProjectSystem\BomStockAllocator;
use App\Validator\Constraints\ProjectSystem\ValidProjectBuildRequest;

/**
 * @see \App\Tests\Helpers\Projects\ProjectBuildRequestTest
 */
#[ValidProjectBuildRequest]
final class ProjectBuildRequest
{
    private readonly int $number_of_builds;

    /**
     * The amounts per BOM entry and lot. A lot can be reached by several entries, each with an amount of its own.
     * It holds every pair an entry can reach, and no others.
     * @var array<int, array<int, float>>
     */
    private array $withdraw_amounts = [];

    /** @var array<int, PartLot> The lots that have an amount, by ID */
    private array $lots_by_id = [];

    /** @var (\Closure(PartLot): bool)|null */
    private readonly ?\Closure $may_withdraw;

    private string $comment = '';

    private ?PartLot $builds_lot = null;

    private bool $add_build_to_builds_part = false;

    private bool $dont_check_quantity = false;

    /**
     * The number of decimal places to which calculated amounts are rounded, to get rid of floating point inaccuracies
     * (e.g. 0.26 * 11 = 2.8600000000000003), which would otherwise make the build form impossible to submit.
     */
    public const AMOUNT_PRECISION = 10;

    /**
     * @param  Project  $project  The project that should be build
     * @param  int  $number_of_builds The number of builds that should be created
     * @param  (callable(PartLot): bool)|null  $may_withdraw  Tells which lots the user may withdraw from, as nothing is proposed from the others
     */
    public function __construct(private readonly Project $project, int $number_of_builds, ?callable $may_withdraw = null)
    {
        if ($number_of_builds < 1) {
            throw new \InvalidArgumentException('Number of builds must be at least 1!');
        }
        $this->number_of_builds = $number_of_builds;
        $this->may_withdraw = $may_withdraw === null ? null : $may_withdraw(...);

        $this->initializeArray();

        //By default, use the first available lot the builds can be added to, if there is one: an own lot of the build
        //part, or a lot of a usable connected part. The lots of other connected parts can only be chosen.
        if($project->getBuildPart() instanceof Part) {
            $this->add_build_to_builds_part = true;
            foreach ($project->getBuildPart()->getLotsCountedInStock() as $lot) {
                if (!$lot->isInstockUnknown()) {
                    $this->builds_lot = $lot;
                    break;
                }
            }
        }
    }

    private function initializeArray(): void
    {
        //Completely reset the array
        $this->withdraw_amounts = [];

        $wanted = [];
        $stock = [];
        foreach ($this->getEntriesInOrder() as $bom_entry) {
            $entry_id = $this->entryId($bom_entry);
            $this->withdraw_amounts[$entry_id] = [];
            $proposable = [];
            foreach ($this->getPartLotsForBOMEntry($bom_entry) as $lot) {
                $id = $lot->getID() ?? throw new \RuntimeException("Part lot needs to have an ID!");
                $this->withdraw_amounts[$entry_id][$id] = 0.0;
                $this->lots_by_id[$id] = $lot;
                if ($this->canBeProposed($lot)) {
                    $proposable[] = $id;
                    $stock[$id] = max(0.0, $lot->getAmount());
                }
            }
            $wanted[$entry_id] = ['need' => $this->getNeededAmountForBOMEntry($bom_entry), 'lots' => $proposable];
        }

        foreach (BomStockAllocator::allocate($wanted, $stock)['amounts'] as $entry_id => $lots) {
            foreach ($lots as $id => $amount) {
                $this->withdraw_amounts[$entry_id][$id] = $amount;
            }
        }
    }

    /**
     * Every lot in reach is proposed, expired ones too, as upstream always did, except those the user may not withdraw from.
     */
    private function canBeProposed(PartLot $lot): bool
    {
        return !$this->may_withdraw instanceof \Closure || ($this->may_withdraw)($lot);
    }

    /**
     * @return list<ProjectBOMEntry> The part BOM entries by ID, so that the same BOM always gets the same proposal
     */
    private function getEntriesInOrder(): array
    {
        $entries = array_values($this->getPartBomEntries());
        usort($entries, fn (ProjectBOMEntry $a, ProjectBOMEntry $b): int => $this->entryId($a) <=> $this->entryId($b));

        return $entries;
    }

    private function entryId(ProjectBOMEntry|int $entry): int
    {
        return $entry instanceof ProjectBOMEntry
            ? ($entry->getID() ?? throw new \InvalidArgumentException('The given BOM entry must have an ID!'))
            : $entry;
    }

    private function lotId(PartLot|int $lot): int
    {
        return $lot instanceof PartLot
            ? ($lot->getID() ?? throw new \InvalidArgumentException('The given lot must have an ID!'))
            : $lot;
    }

    /**
     * Ensure that the projectBOMEntry belongs to the project, otherwise throw an exception.
     */
    private function ensureBOMEntryValid(ProjectBOMEntry $entry): void
    {
        if ($entry->getProject() !== $this->project) {
            throw new \InvalidArgumentException('The given BOM entry does not belong to the project!');
        }
    }

    /**
     * Returns the partlot where the builds should be added to, or null if it should not be added to any lot.
     */
    public function getBuildsPartLot(): ?PartLot
    {
        return $this->builds_lot;
    }

    /**
     * Return if the builds should be added to the builds part of this project as new stock
     */
    public function getAddBuildsToBuildsPart(): bool
    {
        return $this->add_build_to_builds_part;
    }

    /**
     * Set if the builds should be added to the builds part of this project as new stock
     * @return $this
     */
    public function setAddBuildsToBuildsPart(bool $new_value): self
    {
        $this->add_build_to_builds_part = $new_value;

        if ($new_value === false) {
            $this->builds_lot = null;
        }

        return $this;
    }

    /**
     * Returns the lots the builds can be added to: the build part's own lots and, for a meta build part, the lots of
     * each of its connected parts, whatever the status of the connection.
     * @return list<PartLot>
     */
    public function getBuildsPartLotChoices(): array
    {
        $build_part = $this->project->getBuildPart();

        return $build_part instanceof Part ? $build_part->getLotsForBuiltUnits() : [];
    }

    /**
     * Set the partlot where the builds should be added to, or null if it should not be added to any lot.
     * The part lot must be a lot of the project build part, new or saved, or a saved lot among the choices, or an
     * exception is thrown!
     * @return $this
     */
    public function setBuildsPartLot(?PartLot $new_part_lot): self
    {
        $build_part = $this->project->getBuildPart();
        //Ensure that this new_part_lot belongs to the build part, or is a saved lot of one of its connected parts
        if (!$build_part instanceof Part || ($new_part_lot instanceof PartLot && $new_part_lot->getPart() !== $build_part
                && ($new_part_lot->getID() === null || !in_array($new_part_lot, $this->getBuildsPartLotChoices(), true)))) {
            throw new \InvalidArgumentException('The given part lot does not belong to the projects build part!');
        }

        if ($new_part_lot instanceof PartLot) {
            $this->setAddBuildsToBuildsPart(true);
        }

        $this->builds_lot = $new_part_lot;

        return $this;
    }

    /**
     * Returns the comment where the user can write additional information about the build.
     */
    public function getComment(): string
    {
        return $this->comment;
    }

    /**
     * Sets the comment where the user can write additional information about the build.
     */
    public function setComment(string $comment): void
    {
        $this->comment = $comment;
    }

    /**
     * Returns the total amount that should be withdrawn from the given lot, over all BOM entries reaching it.
     * It is rounded the way the stock of the lot is, so the two can be compared.
     * @param PartLot|int $lot The part lot (or the ID of the part lot) for which the withdrawal amount should be got
     */
    public function getLotWithdrawAmount(PartLot|int $lot): float
    {
        $lot_id = $this->lotId($lot);

        $sum = 0.0;
        $found = false;
        foreach ($this->withdraw_amounts as $lots) {
            if (array_key_exists($lot_id, $lots)) {
                $found = true;
                $sum += $lots[$lot_id];
            }
        }

        if (!$found) {
            throw new \InvalidArgumentException('The given lot is not in the withdraw amounts array!');
        }

        return round($sum, self::AMOUNT_PRECISION);
    }

    /**
     * Returns the amount that the given BOM entry takes from the given lot.
     */
    public function getWithdrawAmount(ProjectBOMEntry|int $entry, PartLot|int $lot): float
    {
        $entry_id = $this->entryId($entry);
        $lot_id = $this->lotId($lot);

        if (!isset($this->withdraw_amounts[$entry_id][$lot_id])) {
            throw new \InvalidArgumentException('The given BOM entry can not take from the given lot!');
        }

        return $this->withdraw_amounts[$entry_id][$lot_id];
    }

    /**
     * Sets the amount that the given BOM entry takes from the given lot. A pair out of the reach of the entry is refused.
     * @return $this
     */
    public function setWithdrawAmount(ProjectBOMEntry|int $entry, PartLot|int $lot, float $amount): self
    {
        $entry_id = $this->entryId($entry);
        $lot_id = $this->lotId($lot);

        if (!isset($this->withdraw_amounts[$entry_id][$lot_id])) {
            throw new \InvalidArgumentException('The given BOM entry can not take from the given lot!');
        }

        $this->withdraw_amounts[$entry_id][$lot_id] = $amount;

        return $this;
    }

    /**
     * Returns the sum of all withdraw amounts for the given BOM entry.
     */
    public function getWithdrawAmountSum(ProjectBOMEntry $entry): float
    {
        $this->ensureBOMEntryValid($entry);

        $sum = array_sum($this->withdraw_amounts[$this->entryId($entry)] ?? []);

        if ($entry->getPart() && !$entry->getPart()->useFloatAmount()) {
            return round($sum);
        }

        return round($sum, self::AMOUNT_PRECISION);
    }

    /**
     * Returns the lots this build can take from, each once, whatever number of entries reach it.
     * @return list<PartLot>
     */
    public function getLotsOfBuild(): array
    {
        $lots = [];
        foreach ($this->getEntriesInOrder() as $bom_entry) {
            foreach ($this->getPartLotsForBOMEntry($bom_entry) as $lot) {
                $lots[$this->lotId($lot)] = $lot;
            }
        }

        return array_values($lots);
    }

    /**
     * Returns the other BOM entries reaching the given lot.
     * @return list<ProjectBOMEntry>
     */
    public function getOtherEntriesReachingLot(ProjectBOMEntry $entry, PartLot $lot): array
    {
        $others = [];
        foreach ($this->getEntriesInOrder() as $other) {
            if ($other !== $entry && in_array($lot, $this->getPartLotsForBOMEntry($other), true)) {
                $others[] = $other;
            }
        }

        return $others;
    }

    /**
     * Returns the pairs of BOM entry and lot holding an amount, which the entry can not reach anymore.
     * @return list<array{0: ProjectBOMEntry, 1: PartLot}>
     */
    public function getHeldPairsOutOfReach(): array
    {
        $pairs = [];
        foreach ($this->getEntriesInOrder() as $bom_entry) {
            $reach = array_map(fn (PartLot $lot): int => $this->lotId($lot), $this->getPartLotsForBOMEntry($bom_entry));
            foreach ($this->withdraw_amounts[$this->entryId($bom_entry)] ?? [] as $lot_id => $amount) {
                if ($amount > 0 && !in_array($lot_id, $reach, true)) {
                    $pairs[] = [$bom_entry, $this->lots_by_id[$lot_id]];
                }
            }
        }

        return $pairs;
    }

    /**
     * Returns the available lots to take stock from for the given BOM entry.
     * @return PartLot[]|null Returns null if the entry is a non-part BOM entry
     */
    public function getPartLotsForBOMEntry(ProjectBOMEntry $projectBOMEntry): ?array
    {
        $this->ensureBOMEntryValid($projectBOMEntry);

        if (!$projectBOMEntry->getPart() instanceof Part) {
            return null;
        }

        return $projectBOMEntry->getLotsInReach();
    }

    /**
     * Returns the needed amount of parts for the given BOM entry.
     */
    public function getNeededAmountForBOMEntry(ProjectBOMEntry $entry): float
    {
        $this->ensureBOMEntryValid($entry);

        return round($entry->getQuantity() * $this->number_of_builds, self::AMOUNT_PRECISION);
    }

    /**
     * Returns the list of all bom entries that have to be built.
     * @return ProjectBOMEntry[]
     */
    public function getBomEntries(): array
    {
        return $this->project->getBomEntries()->toArray();
    }

    /**
     * Returns the all part bom entries that have to be built.
     * @return ProjectBOMEntry[]
     */
    public function getPartBomEntries(): array
    {
        return $this->project->getBomEntries()->filter(fn(ProjectBOMEntry $entry) => $entry->isPartBomEntry())->toArray();
    }

    /**
     * Returns which project should be build
     */
    public function getProject(): Project
    {
        return $this->project;
    }

    /**
     * Returns the number of builds that should be created.
     */
    public function getNumberOfBuilds(): int
    {
        return $this->number_of_builds;
    }

    /**
     * If Set to true, the given withdraw amounts are used without any checks for requirements.
     * @return bool
     */
    public function isDontCheckQuantity(): bool
    {
        return $this->dont_check_quantity;
    }

    /**
     * Set to true, the given withdraw amounts are used without any checks for requirements.
     * @param  bool  $dont_check_quantity
     * @return $this
     */
    public function setDontCheckQuantity(bool $dont_check_quantity): self
    {
        $this->dont_check_quantity = $dont_check_quantity;
        return $this;
    }


}
