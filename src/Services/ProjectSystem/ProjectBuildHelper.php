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
namespace App\Services\ProjectSystem;

use App\Entity\Parts\Part;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use App\Entity\PriceInformations\Currency;
use App\Helpers\Projects\ProjectBuildRequest;
use App\Services\Parts\PartLotWithdrawAddHelper;
use App\Services\Parts\PricedetailHelper;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * @see \App\Tests\Services\ProjectSystem\ProjectBuildHelperTest
 */
final readonly class ProjectBuildHelper
{
    private const AMOUNT_PRECISION = 10;

    public function __construct(
        private PartLotWithdrawAddHelper $withdraw_add_helper,
        private PricedetailHelper $pricedetailHelper,
    ) {
    }

    /**
     * Returns the maximum buildable amount of the given BOM entry based on the stock of the used parts.
     * This function only works for BOM entries that are associated with a part.
     */
    public function getMaximumBuildableCountForBOMEntry(ProjectBOMEntry $projectBOMEntry): int
    {
        $part = $projectBOMEntry->getPart();

        if (!$part instanceof Part) {
            throw new \InvalidArgumentException('This function cannot determine the maximum buildable count for a BOM entry without a part!');
        }

        if ($projectBOMEntry->getQuantity() <= 0) {
            throw new \RuntimeException('The quantity of the BOM entry must be greater than 0!');
        }

        //Rounded first, as a float division can land just below a whole number: floor(0.3 / 0.1) is 2
        return (int) floor(round($part->getAmountSum() / $projectBOMEntry->getQuantity(), self::AMOUNT_PRECISION));
    }

    /**
     * Returns the maximum buildable amount of the given project, based on the stock of the used parts in the BOM.
     */
    public function getMaximumBuildableCount(Project $project): int
    {
        $bom_entries = $project->getBomEntries();
        if ($bom_entries->isEmpty()) {
            return 0;
        }
        $maximum_buildable_count = PHP_INT_MAX;
        foreach ($bom_entries as $bom_entry) {
            //Skip BOM entries without a part (as we can not determine that)
            if (!$bom_entry->isPartBomEntry()) {
                continue;
            }
            //The maximum buildable count for the whole project is the minimum of all BOM entries
            $maximum_buildable_count = min($maximum_buildable_count, $this->getMaximumBuildableCountForBOMEntry($bom_entry));
        }

        [$reach, $stock] = $this->getCountedReach($project);
        if (!$this->hasSharedLot($reach)) {
            return $maximum_buildable_count;
        }

        //Each entry counted alone says too much when entries draw on the same stock
        $quantities = [];
        foreach ($project->getBomEntries() as $bom_entry) {
            if ($bom_entry->isPartBomEntry()) {
                $quantities[spl_object_id($bom_entry)] = ['quantity' => $bom_entry->getQuantity(), 'lots' => $reach[spl_object_id($bom_entry)]];
            }
        }

        return BomStockAllocator::maximumBuilds($quantities, $stock, $maximum_buildable_count);
    }

    /**
     * Returns the maximum buildable amount of the given project as string, based on the stock of the used parts in the BOM.
     * If the maximum buildable count is infinite, the string '∞' is returned.
     * @param  Project  $project
     * @return string
     */
    public function getMaximumBuildableCountAsString(Project $project): string
    {
        $max_count = $this->getMaximumBuildableCount($project);
        if ($max_count === PHP_INT_MAX) {
            return '∞';
        }
        return (string) $max_count;
    }

    /**
     * Checks if the given project can be built with the current stock.
     * This means that the maximum buildable count is greater or equal than the requested $number_of_projects
     * @param int $number_of_builds
     */
    public function isProjectBuildable(Project $project, int $number_of_builds = 1): bool
    {
        return $this->getMaximumBuildableCount($project) >= $number_of_builds;
    }

    /**
     * Check if the given BOM entry can be built with the current stock.
     * This means that the maximum buildable count is greater or equal than the requested $number_of_projects
     */
    public function isBOMEntryBuildable(ProjectBOMEntry $bom_entry, int $number_of_builds = 1): bool
    {
        return $this->getMaximumBuildableCountForBOMEntry($bom_entry) >= $number_of_builds;
    }

    /**
     * Returns the project BOM entries for which parts are missing in the stock for the given number of builds
     * @param  Project  $project The project for which the BOM entries should be checked
     * @param  int  $number_of_builds How often should the project be build?
     * @return ProjectBOMEntry[]
     */
    public function getNonBuildableProjectBomEntries(Project $project, int $number_of_builds = 1): array
    {
        if ($number_of_builds < 1) {
            throw new \InvalidArgumentException('The number of builds must be greater than 0!');
        }

        $short = $this->getShortAmounts($project, $number_of_builds);

        return array_values(array_filter(
            $project->getBomEntries()->toArray(),
            static fn (ProjectBOMEntry $entry): bool => isset($short[spl_object_id($entry)])
        ));
    }

    /**
     * Returns what is available to the given BOM entry for the given number of builds, after what the other entries need.
     */
    public function getAvailableAmountForBOMEntry(ProjectBOMEntry $entry, int $number_of_builds = 1): float
    {
        $needed = round($entry->getQuantity() * $number_of_builds, self::AMOUNT_PRECISION);
        $project = $entry->getProject();
        if (!$project instanceof Project) {
            throw new \InvalidArgumentException('The given BOM entry must belong to a project!');
        }

        return round($needed - ($this->getShortAmounts($project, $number_of_builds)[spl_object_id($entry)] ?? 0.0), self::AMOUNT_PRECISION);
    }

    /**
     * Returns what the part BOM entries lack for the given number of builds, by the object ID of the entry.
     * @return array<int, float>
     */
    private function getShortAmounts(Project $project, int $number_of_builds): array
    {
        [$reach, $stock] = $this->getCountedReach($project);

        $wanted = [];
        //By ID, so that a BOM that can not be covered always comes out short on the same entries
        $entries = $project->getBomEntries()->filter(static fn (ProjectBOMEntry $entry): bool => $entry->isPartBomEntry())->toArray();
        usort($entries, static fn (ProjectBOMEntry $a, ProjectBOMEntry $b): int => ($a->getID() ?? PHP_INT_MAX) <=> ($b->getID() ?? PHP_INT_MAX));
        foreach ($entries as $entry) {
            $wanted[spl_object_id($entry)] = [
                'need' => round($entry->getQuantity() * $number_of_builds, self::AMOUNT_PRECISION),
                'lots' => $reach[spl_object_id($entry)],
            ];
        }

        return BomStockAllocator::allocate($wanted, $stock)['short'];
    }

    /**
     * Returns the lots each part BOM entry can draw on as far as the stock total counts them, and the amount of each lot.
     * Expired lots are not counted. All by object ID, as this also works on entries and lots not saved yet.
     * @return array{0: array<int, list<int>>, 1: array<int, float>}
     */
    private function getCountedReach(Project $project): array
    {
        $reach = [];
        $stock = [];
        foreach ($project->getBomEntries() as $entry) {
            if (!$entry->isPartBomEntry()) {
                continue;
            }
            $reach[spl_object_id($entry)] = [];
            foreach ($entry->getLotsInReach() as $lot) {
                if ($lot->isExpired() !== true) {
                    $reach[spl_object_id($entry)][] = spl_object_id($lot);
                    $stock[spl_object_id($lot)] = max(0.0, $lot->getAmount());
                }
            }
        }

        return [$reach, $stock];
    }

    /**
     * @param  array<int, list<int>>  $reach
     */
    private function hasSharedLot(array $reach): bool
    {
        $lots = array_merge(...array_values($reach) ?: [[]]);

        return count($lots) !== count(array_unique($lots));
    }

    /**
     * Withdraw the parts from the stock using the given ProjectBuildRequest and create the build parts entries, if needed.
     * The ProjectBuildRequest has to be validated before!!
     * You have to flush changes to DB afterward
     */
    public function doBuild(ProjectBuildRequest $buildRequest): void
    {
        $message = $buildRequest->getComment();
        $message .= ' (Project build: '.$buildRequest->getProject()->getName().')';

        //One withdrawal per lot, of the sum over all entries. A rounded sum can come out just above what a lot holds, so at most that.
        foreach ($buildRequest->getLotsOfBuild() as $part_lot) {
            $amount = min($buildRequest->getLotWithdrawAmount($part_lot), $part_lot->getAmount());
            if ($amount > 0) {
                $this->withdraw_add_helper->withdraw($part_lot, $amount, $message);
            }
        }

        if ($buildRequest->getAddBuildsToBuildsPart()) {
            $this->withdraw_add_helper->add($buildRequest->getBuildsPartLot(), $buildRequest->getNumberOfBuilds(), $message);
        }
    }

    /**
     * Calculates what it costs to build the given project N times, taking bulk pricing into account.
     */
    public function calculateCost(Project $project, int $number_of_builds = 1, ?Currency $currency = null): ProjectCost
    {
        return $this->costOfProject($project, $number_of_builds, $currency, []);
    }

    /**
     * Calculates the total price to build the given project N times, taking bulk pricing into account.
     * Returns null if no BOM entry has any pricing information.
     */
    public function calculateTotalBuildPrice(Project $project, int $number_of_builds = 1, ?Currency $currency = null): ?BigDecimal
    {
        return $this->calculateCost($project, $number_of_builds, $currency)->total;
    }

    /**
     * Calculates the price to build one unit of the given project when ordering for N builds in total.
     * Returns null if no BOM entry has any pricing information.
     */
    public function calculateUnitBuildPrice(Project $project, int $number_of_builds = 1, ?Currency $currency = null): ?BigDecimal
    {
        return $this->calculateCost($project, $number_of_builds, $currency)->perBuild($number_of_builds)->total;
    }

    /**
     * Returns the total build price rounded up to 2 decimal places, ready for display.
     */
    public function roundedTotalBuildPrice(Project $project, int $number_of_builds = 1, ?Currency $currency = null): ?BigDecimal
    {
        return $this->calculateTotalBuildPrice($project, $number_of_builds, $currency)
            ?->toScale(2, RoundingMode::Up);
    }

    /**
     * Returns the unit build price rounded up to 2 decimal places, ready for display.
     */
    public function roundedUnitBuildPrice(Project $project, int $number_of_builds = 1, ?Currency $currency = null): ?BigDecimal
    {
        return $this->calculateUnitBuildPrice($project, $number_of_builds, $currency)
            ?->toScale(2, RoundingMode::Up);
    }

    /**
     * Returns the effective unit price for a single piece of the given BOM entry,
     * taking bulk pricing and minimum order amounts into account for N builds.
     * Returns BigDecimal::zero() when no pricing data is available.
     */
    public function getEntryUnitPrice(ProjectBOMEntry $entry, int $number_of_builds = 1, ?Currency $currency = null): BigDecimal
    {
        return $this->getEntryUnitCost($entry, $number_of_builds, $currency) ?? BigDecimal::zero();
    }

    /**
     * Returns the unit price of the given BOM entry, or null if it has no price. A meta part entry costs the price of
     * its meta part, by its price method, at the quantity of the entry.
     */
    public function getEntryUnitCost(ProjectBOMEntry $entry, int $number_of_builds = 1, ?Currency $currency = null): ?BigDecimal
    {
        return $this->unitCostOfEntry($entry, $number_of_builds, $currency, []);
    }

    /**
     * @param  array<int, true>  $visited  The projects being worked out, so that a cycle in stored data still ends
     */
    private function costOfProject(Project $project, int $number_of_builds, ?Currency $currency, array $visited): ProjectCost
    {
        $visited[spl_object_id($project)] = true;

        $total = BigDecimal::zero();
        $has_price = false;
        $unpriced = 0;

        foreach ($project->getBomEntries() as $entry) {
            $unit = $this->unitCostOfEntry($entry, $number_of_builds, $currency, $visited);
            if ($unit === null) {
                if ($entry->isPartBomEntry()) {
                    $unpriced++;
                }
                continue;
            }

            $has_price = true;
            $quantity = BigDecimal::fromFloatShortest($entry->getQuantity());
            $total = $total->plus($unit->multipliedBy($quantity)->multipliedBy($number_of_builds));
        }

        return new ProjectCost($has_price ? $total : null, $unpriced);
    }

    /**
     * @param  array<int, true>  $visited
     */
    private function unitCostOfEntry(ProjectBOMEntry $entry, int $number_of_builds, ?Currency $currency, array $visited): ?BigDecimal
    {
        $part = $entry->getPart();

        if (!$part instanceof Part) {
            $price = $entry->getPrice();
            if ($price === null) {
                return null;
            }

            return $this->pricedetailHelper->convertMoneyToCurrency($price, $entry->getPriceCurrency(), $currency);
        }

        //The build part of a sub-project costs one build of that project, whatever its kind
        $built_project = $part->getBuiltProject();
        if ($built_project instanceof Project) {
            if (isset($visited[spl_object_id($built_project)])) {
                return null;
            }

            return $this->costOfProject($built_project, 1, $currency, $visited)->total;
        }

        $quantity = $entry->getQuantity() * $number_of_builds;

        //A meta part costs its price by its price method, which applies the minimum order to each connected part
        if ($part->getKind()->allowsPriceMethod()) {
            return $this->pricedetailHelper->calculateMetaPartPrice($part, $quantity, $currency);
        }

        $min_order = $this->pricedetailHelper->getMinOrderAmount($part);
        $effective_quantity = $min_order !== null ? max($quantity, $min_order) : $quantity;

        return $this->pricedetailHelper->calculateAvgPrice($part, $effective_quantity, $currency);
    }
}
