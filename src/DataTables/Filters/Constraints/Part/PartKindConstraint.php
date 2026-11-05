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

namespace App\DataTables\Filters\Constraints\Part;

use App\DataTables\Filters\Constraints\AbstractConstraint;
use App\Entity\Parts\PartKind;
use Doctrine\ORM\QueryBuilder;

/**
 * Shows only the parts of the kinds that are set. Both kinds are set until the user changes that, and the form keeps at
 * least one of them set. A request without any kind, such as a link naming only other filters, shows all parts as well.
 */
class PartKindConstraint extends AbstractConstraint
{
    /** @var list<PartKind> */
    private array $value = [PartKind::META, PartKind::MANUFACTURING];

    /**
     * @return list<PartKind>
     */
    public function getValue(): array
    {
        return $this->value;
    }

    /**
     * @param  array<int, PartKind>  $value The form keeps the positions of the choices, so the keys can have gaps
     */
    public function setValue(array $value): self
    {
        $this->value = array_values($value);

        return $this;
    }

    public function isEnabled(): bool
    {
        return count($this->value) === 1;
    }

    public function apply(QueryBuilder $queryBuilder): void
    {
        if ($this->isEnabled()) {
            $this->addSimpleAndConstraint($queryBuilder, $this->property, $this->identifier, '=', $this->value[0]->value);
        }
    }
}
