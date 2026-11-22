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
 *
 * A further status is a new case with its answers below; the stored values of the existing cases stay as they are.
 */
enum PartConnectionStatus: string
{
    /** The part may be used. */
    case APPROVED = 'approved';
    /** The part is still being evaluated, and is not used until it is approved. */
    case UNDER_EVALUATION = 'under_evaluation';
    /** The part may be used, and its stock is used first, so that it runs out. */
    case USE_UP = 'use_up';
    /** The part must not be used. */
    case DO_NOT_USE = 'do_not_use';

    public function getTranslationKey(): string
    {
        return 'part_connection.status.'.$this->value;
    }

    /**
     * Whether the connected part's stock counts for the meta part and may be taken by a build. There is no default
     * arm, so a new case must answer this.
     */
    public function isUsable(): bool
    {
        return match ($this) {
            self::APPROVED, self::USE_UP => true,
            self::UNDER_EVALUATION, self::DO_NOT_USE => false,
        };
    }

    /**
     * Whether a build takes the stock of this connection before that of the other usable connections, so that it runs
     * out. There is no default arm, so a new case must answer this.
     */
    public function isUsedFirst(): bool
    {
        return match ($this) {
            self::USE_UP => true,
            self::APPROVED, self::UNDER_EVALUATION, self::DO_NOT_USE => false,
        };
    }

    /**
     * How strongly the status restricts use, the higher the stricter. When two connections are joined, the status of
     * the higher rank is kept. There is no default arm, so a new case must answer this.
     */
    public function restrictionRank(): int
    {
        return match ($this) {
            self::APPROVED => 0,
            self::USE_UP => 1,
            self::UNDER_EVALUATION => 2,
            self::DO_NOT_USE => 3,
        };
    }

    /**
     * The stored values of the usable statuses, for queries that must agree with isUsable().
     * @return list<string>
     */
    public static function usableValues(): array
    {
        return array_values(array_map(static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isUsable())));
    }
}
