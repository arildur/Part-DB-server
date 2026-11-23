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

namespace App\Entity\Parts\PartTraits;

use ApiPlatform\Metadata\ApiProperty;
use App\Entity\Parts\PartPriceMethod;
use App\Entity\PriceInformations\Currency;
use App\Entity\PriceInformations\Pricedetail;
use App\Validator\Constraints\BigDecimal\BigDecimalPositiveOrZero;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * How the price of a meta part is worked out, and the price the user may give it. Nothing calculated is stored: the
 * price is worked out on each read, see PricedetailHelper::calculateMetaPartPrice().
 */
trait PriceMethodTrait
{
    /**
     * @var PartPriceMethod The price method of this part. Only read for a kind that allows a price method.
     */
    #[ORM\Column(name: 'price_method', type: Types::STRING, length: 32, enumType: PartPriceMethod::class, options: ['default' => 'system_default'])]
    #[Groups(['full', 'import', 'part:read', 'part:write'])]
    #[SerializedName('price_method')]
    #[ApiProperty(description: 'How the price of a meta part is worked out. system_default follows the setting of Part-DB. Null for a manufacturing part.')]
    protected PartPriceMethod $priceMethod = PartPriceMethod::SYSTEM_DEFAULT;

    /**
     * @var bool Whether a price method was given since the part was loaded, not stored. A kind without a price method
     * refuses any given method, system default too, while null (resetting it) is accepted.
     */
    private bool $priceMethodGiven = false;

    /**
     * @var BigDecimal|null A fixed unit price typed by the user, used by the price method user value whatever the quantity
     */
    #[ORM\Column(name: 'price_user_value', type: 'big_decimal', precision: 11, scale: 5, nullable: true)]
    #[BigDecimalPositiveOrZero]
    #[Groups(['full', 'import', 'part:read', 'part:write'])]
    #[SerializedName('user_price')]
    #[ApiProperty(description: 'A fixed unit price of a meta part, used by the price method user_value, in user_price_currency. It is kept whatever the method.')]
    protected ?BigDecimal $priceUserValue = null;

    /**
     * @var Currency|null The currency of the user value. Null is the base currency.
     */
    #[ORM\ManyToOne(targetEntity: Currency::class)]
    #[ORM\JoinColumn(name: 'price_user_currency_id')]
    #[Groups(['full', 'import', 'part:read', 'part:write'])]
    #[SerializedName('user_price_currency')]
    #[ApiProperty(description: 'The currency of user_price. Null is the base currency.')]
    protected ?Currency $priceUserCurrency = null;

    /**
     * Returns the price method of this part, or null if its kind has none.
     */
    public function getPriceMethod(): ?PartPriceMethod
    {
        return $this->getKind()->allowsPriceMethod() ? $this->priceMethod : null;
    }

    /**
     * Sets the price method. Null resets it to system default. Validation refuses any method given to a kind without
     * one, system default too, so only null can be set there.
     */
    public function setPriceMethod(?PartPriceMethod $priceMethod): self
    {
        $this->priceMethod = $priceMethod ?? PartPriceMethod::SYSTEM_DEFAULT;
        $this->priceMethodGiven = $priceMethod instanceof PartPriceMethod;

        return $this;
    }

    public function getPriceUserValue(): ?BigDecimal
    {
        return $this->priceUserValue;
    }

    /**
     * Sets the user value, rounded to the precision of a price. It is kept whatever the price method.
     */
    public function setPriceUserValue(?BigDecimal $priceUserValue): self
    {
        $value = $priceUserValue?->toScale(Pricedetail::PRICE_PRECISION, RoundingMode::HalfUp);
        //Only change the object if the value changes, so that Doctrine does not see a change. Compared by value,
        //as the loaded value may lack the trailing zeros of the scale
        $unchanged = null === $value || null === $this->priceUserValue
            ? $value === $this->priceUserValue
            : $value->isEqualTo($this->priceUserValue);
        if (!$unchanged) {
            $this->priceUserValue = $value;
        }

        return $this;
    }

    public function getPriceUserCurrency(): ?Currency
    {
        return $this->priceUserCurrency;
    }

    public function setPriceUserCurrency(?Currency $priceUserCurrency): self
    {
        $this->priceUserCurrency = $priceUserCurrency;

        return $this;
    }
}
