<?php

declare(strict_types=1);

/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2026 Part-DB project contributors
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

namespace App\Tests\Settings;

use App\Entity\Parts\PartPriceMethod;
use App\Settings\MiscSettings\MetaPartSettings;
use App\Tests\SettingsTestHelper;
use Jbtronics\SettingsBundle\Form\SettingsFormFactoryInterface;
use Jbtronics\SettingsBundle\Manager\SettingsManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\ChoiceList\View\ChoiceView;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class MetaPartSettingsTest extends KernelTestCase
{
    public function testAFreshSettingsObjectGivesPrimarySource(): void
    {
        $settings = SettingsTestHelper::createSettingsDummy(MetaPartSettings::class);

        $this->assertSame(PartPriceMethod::PRIMARY_SOURCE, $settings->defaultPriceMethod);
        $this->assertSame(PartPriceMethod::PRIMARY_SOURCE, $settings->getDefaultPriceMethod());
    }

    public function testTheReaderNeverGivesSystemDefault(): void
    {
        $settings = SettingsTestHelper::createSettingsDummy(MetaPartSettings::class);
        $settings->defaultPriceMethod = PartPriceMethod::SYSTEM_DEFAULT;
        $this->assertSame(PartPriceMethod::PRIMARY_SOURCE, $settings->getDefaultPriceMethod());

        $settings->defaultPriceMethod = PartPriceMethod::AVERAGE;
        $this->assertSame(PartPriceMethod::AVERAGE, $settings->getDefaultPriceMethod());
    }

    public function testValidationRefusesSystemDefault(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get(ValidatorInterface::class);
        $settings = SettingsTestHelper::createSettingsDummy(MetaPartSettings::class);

        $this->assertCount(0, $validator->validate($settings));
        $settings->defaultPriceMethod = PartPriceMethod::SYSTEM_DEFAULT;
        $violations = $validator->validate($settings);
        $this->assertCount(1, $violations);
        $this->assertSame('defaultPriceMethod', $violations[0]->getPropertyPath());
    }

    public function testTheFormOffersEveryMethodButSystemDefault(): void
    {
        self::bootKernel();
        $settings = self::getContainer()->get(SettingsManagerInterface::class)->get(MetaPartSettings::class);
        $form = self::getContainer()->get(SettingsFormFactoryInterface::class)->createSettingsFormBuilder($settings, null, ['csrf_protection' => false])->getForm();

        $choices = array_map(static fn (ChoiceView $choice): string => $choice->value, $form->get('defaultPriceMethod')->createView()->vars['choices']);

        $this->assertSame(['primary_source', 'cheapest', 'average', 'weighted_by_stock', 'user_value'], array_values($choices));
    }

    public function testTheEnvironmentVariableIsMappedToAMethod(): void
    {
        $this->assertSame(PartPriceMethod::CHEAPEST, MetaPartSettings::mapDefaultPriceMethod('cheapest'));
        $this->assertSame(PartPriceMethod::PRIMARY_SOURCE, MetaPartSettings::mapDefaultPriceMethod('system_default'));
        $this->assertSame(PartPriceMethod::PRIMARY_SOURCE, MetaPartSettings::mapDefaultPriceMethod('range'));
    }

    public function testTheSettingsAreUnderMiscellaneous(): void
    {
        self::bootKernel();
        $misc = self::getContainer()->get(SettingsManagerInterface::class)->get(\App\Settings\MiscSettings\MiscSettings::class);

        $this->assertInstanceOf(MetaPartSettings::class, $misc->metaParts);
    }

    public function testTheSettingsShowTheIconOfTheMetaKind(): void
    {
        $attributes = (new \ReflectionClass(MetaPartSettings::class))->getAttributes(\App\Settings\SettingsIcon::class);

        $this->assertCount(1, $attributes);
        $this->assertSame('fa-sitemap', $attributes[0]->newInstance()->icon);
    }
}
