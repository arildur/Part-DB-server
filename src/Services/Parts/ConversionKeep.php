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

/**
 * Which record keeps the ID of a manufacturing part converted to use a meta part, and with it its BOM entries, labels
 * and history.
 * @see PartKindConverter::convertToMeta()
 */
enum ConversionKeep: string
{
    /** The part stays the manufacturing part, and a new meta part is created above it. */
    case MANUFACTURING_KEEPS_ID = 'manufacturing';
    /** The part becomes the meta part, and its manufacturer data, lots and suppliers move to a new manufacturing part below it. */
    case META_KEEPS_ID = 'meta';
}
