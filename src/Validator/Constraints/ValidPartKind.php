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

declare(strict_types=1);namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * Ensures that the kind of a saved part is not changed, except by PartKindConverter, which marks the part first.
 * This needs the entity manager to know the stored value, so it can not be a simple callback.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ValidPartKind extends Constraint
{
    public string $message = 'validator.part.kind_is_fixed';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
