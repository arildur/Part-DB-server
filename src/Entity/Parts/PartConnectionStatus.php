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
 * The status of the connection between a meta part and a manufacturing part. It belongs to that one connection,
 * so a part can be approved under one meta part and marked do not use under another.
 */
enum PartConnectionStatus: string
{
    case APPROVED = 'approved';
    case DO_NOT_USE = 'do_not_use';

    public function getTranslationKey(): string
    {
        return 'part_connection.status.'.$this->value;
    }
}
