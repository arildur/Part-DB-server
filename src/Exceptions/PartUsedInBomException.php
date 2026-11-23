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


namespace App\Exceptions;

/**
 * Thrown when a part is to be deleted while the setting "Refuse deleting parts used in a BOM" is on and a BOM entry
 * points to it. The message is plain English text naming the part and the projects, for REST (409) and MCP; the web
 * UI builds its own translated message from getPartName() and getProjectNames().
 * @see \App\Services\Parts\PartDeletionGuard
 */
class PartUsedInBomException extends \RuntimeException
{
    /**
     * @param list<string> $projectNames the names of the projects whose BOM uses the part, each once
     */
    public function __construct(private readonly string $partName, private readonly array $projectNames)
    {
        parent::__construct(sprintf('The part "%s" can not be deleted, because it is used in the BOM of the project(s): %s. Remove it from these BOMs first, or turn off the setting "Refuse deleting parts used in a BOM".',
            $partName, implode(', ', $projectNames)));
    }

    public function getPartName(): string
    {
        return $this->partName;
    }

    /**
     * @return list<string>
     */
    public function getProjectNames(): array
    {
        return $this->projectNames;
    }
}
