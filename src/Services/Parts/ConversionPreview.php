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
 * What a conversion between the kinds does, before it is carried out: one row per thing it touches, each with what
 * happens to it and, for a collection, how many there are. The conversion page shows it as a table.
 * @see PartKindConverter
 */
final readonly class ConversionPreview
{
    /** It stays on the part that keeps the ID. */
    public const KEPT = 'kept';
    /** It moves to the new part. */
    public const MOVED = 'moved';
    /** It is copied to the new part, and also stays on the part. */
    public const COPIED = 'copied';
    /** It is created by the conversion. */
    public const CREATED = 'created';
    /** It is changed on the part. */
    public const CHANGED = 'changed';
    /** It is reset to its default. */
    public const RESET = 'reset';

    /**
     * @param list<array{what: string, action: string, count: int|null}> $rows what is touched (the key of its
     * translation part.convert.row.*), what happens to it (one of the constants) and how many there are, if it is a collection
     */
    public function __construct(private ?ConversionKeep $keep, private array $rows)
    {
    }

    /**
     * The choice this preview is for, or null for the conversion back to a manufacturing part.
     */
    public function getKeep(): ?ConversionKeep
    {
        return $this->keep;
    }

    /**
     * @return list<array{what: string, action: string, count: int|null}>
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    /**
     * The row of the given thing, or null if this conversion does not touch it.
     * @return array{what: string, action: string, count: int|null}|null
     */
    public function getRow(string $what): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['what'] === $what) {
                return $row;
            }
        }

        return null;
    }
}
