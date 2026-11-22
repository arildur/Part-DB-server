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

namespace App\Entity\Parts;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * How the price of a meta part is worked out. Only a meta part uses it; see PartKind::allowsPriceMethod().
 */
enum PartPriceMethod: string implements TranslatableInterface
{
    /** Follows the default method of the settings. */
    case SYSTEM_DEFAULT = 'system_default';
    /** The price of the first usable connected part, in the order of priority, that has a price. */
    case PRIMARY_SOURCE = 'primary_source';
    /** The lowest price of the usable connected parts. */
    case CHEAPEST = 'cheapest';
    /** The average of the prices of the usable connected parts. */
    case AVERAGE = 'average';
    /** The average of the prices of the usable connected parts, each weighted by its stock. */
    case WEIGHTED_BY_STOCK = 'weighted_by_stock';
    /** A fixed unit price typed by the user, whatever the quantity. */
    case USER_VALUE = 'user_value';

    public function getTranslationKey(): string
    {
        return 'part.price_method.'.$this->value;
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->getTranslationKey(), locale: $locale);
    }

    /**
     * Whether the price is worked out from the connected parts. System default is not, as it first has to be resolved.
     * There is no default arm, so a new case must answer this.
     */
    public function isCalculated(): bool
    {
        return match ($this) {
            self::PRIMARY_SOURCE, self::CHEAPEST, self::AVERAGE, self::WEIGHTED_BY_STOCK => true,
            self::SYSTEM_DEFAULT, self::USER_VALUE => false,
        };
    }

    /**
     * The method that applies: the given system default for system default, and this method otherwise.
     */
    public function resolve(self $systemDefault): self
    {
        return $this === self::SYSTEM_DEFAULT ? $systemDefault : $this;
    }
}
