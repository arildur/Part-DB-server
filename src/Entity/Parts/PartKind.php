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

declare(strict_types=1);namespace App\Entity\Parts;

/**
 * The kind of a part. It decides what a part may hold, and is fixed once the part is saved.
 *
 * Rules should ask one of the methods below, not compare with a case, so that a new kind is treated deliberately.
 */
enum PartKind: string
{
    /** One manufacturer's part, with its stock and suppliers. */
    case MANUFACTURING = 'manufacturing';
    /** A specification, standing above the interchangeable manufacturing parts connected to it. */
    case META = 'meta';

    public function getTranslationKey(): string
    {
        return 'part.kind.'.$this->value;
    }

    /** Whether the part may hold a manufacturer, a part number and other data identifying one product. */
    public function allowsManufacturerData(): bool
    {
        return $this === self::MANUFACTURING;
    }

    /** Whether the part may have suppliers of its own. */
    public function allowsSuppliers(): bool
    {
        return $this === self::MANUFACTURING;
    }

    /** Whether other parts can be connected to this part. */
    public function allowsConnections(): bool
    {
        return $this === self::META;
    }

    /** Whether this part can be connected to a part that allows connections. */
    public function canBeConnected(): bool
    {
        return $this === self::MANUFACTURING;
    }

    /** Whether this part can be the part a project builds. */
    public function canBeBuildPart(): bool
    {
        return $this === self::MANUFACTURING;
    }
}
