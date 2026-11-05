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

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What it costs to build a project.
 */
final readonly class ProjectCost
{
    public function __construct(
        /** The sum over the priced entries, or null if no entry has a price */
        public ?BigDecimal $total,
        /** The number of part entries with no price, so that the total can be read as incomplete */
        public int $unpricedEntries,
        public bool $hasMetaPartEntry,
    ) {
    }

    public function getRoundedTotal(): ?BigDecimal
    {
        return $this->total?->toScale(2, RoundingMode::Up);
    }

    public function perBuild(int $number_of_builds): self
    {
        return new self(
            $this->total?->dividedBy($number_of_builds, 10, RoundingMode::HalfUp),
            $this->unpricedEntries,
            $this->hasMetaPartEntry,
        );
    }
}
