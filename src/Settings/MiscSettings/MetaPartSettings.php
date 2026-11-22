<?php

/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2024 Jan Böhmer (https://github.com/jbtronics)
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


namespace App\Settings\MiscSettings;

use App\Entity\Parts\PartPriceMethod;
use App\Settings\SettingsIcon;
use Jbtronics\SettingsBundle\Metadata\EnvVarMode;
use Jbtronics\SettingsBundle\Settings\Settings;
use Jbtronics\SettingsBundle\Settings\SettingsParameter;
use Jbtronics\SettingsBundle\Settings\SettingsTrait;
use Symfony\Component\Translation\TranslatableMessage as TM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The settings of meta parts. The price of a meta part is worked out by Parts, which reads the default method here.
 * @see \App\Tests\Settings\MetaPartSettingsTest
 */
#[Settings(label: new TM("settings.misc.meta_parts"))]
#[SettingsIcon("fa-sitemap")]
class MetaPartSettings
{
    use SettingsTrait;

    /**
     * The method used by every meta part whose price method is system default. It can not be system default itself.
     */
    #[SettingsParameter(
        label: new TM("settings.misc.meta_parts.defaultPriceMethod"),
        description: new TM("settings.misc.meta_parts.defaultPriceMethod.help"),
        formOptions: ['choices' => [PartPriceMethod::PRIMARY_SOURCE, PartPriceMethod::CHEAPEST, PartPriceMethod::AVERAGE,
            PartPriceMethod::WEIGHTED_BY_STOCK, PartPriceMethod::USER_VALUE]],
        envVar: "META_PART_DEFAULT_PRICE_METHOD", envVarMode: EnvVarMode::OVERWRITE, envVarMapper: [self::class, 'mapDefaultPriceMethod'],
    )]
    #[Assert\NotEqualTo(PartPriceMethod::SYSTEM_DEFAULT)]
    public PartPriceMethod $defaultPriceMethod = PartPriceMethod::PRIMARY_SOURCE;

    /**
     * Returns the method that system default stands for. Never system default, so that a reader can not loop.
     */
    public function getDefaultPriceMethod(): PartPriceMethod
    {
        return $this->defaultPriceMethod === PartPriceMethod::SYSTEM_DEFAULT ? PartPriceMethod::PRIMARY_SOURCE : $this->defaultPriceMethod;
    }

    /**
     * Maps the environment variable to a method. An unknown value or system default gives primary source.
     */
    public static function mapDefaultPriceMethod(string $value): PartPriceMethod
    {
        $method = PartPriceMethod::tryFrom(trim($value));

        return $method === null || $method === PartPriceMethod::SYSTEM_DEFAULT ? PartPriceMethod::PRIMARY_SOURCE : $method;
    }
}
