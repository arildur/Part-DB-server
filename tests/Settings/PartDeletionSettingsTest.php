<?php

declare(strict_types=1);

/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2026 Jan Böhmer (https://github.com/jbtronics)
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

use App\Settings\BehaviorSettings\BehaviorSettings;
use App\Settings\BehaviorSettings\PartDeletionSettings;
use App\Tests\SettingsTestHelper;
use Jbtronics\SettingsBundle\Manager\SettingsManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PartDeletionSettingsTest extends KernelTestCase
{
    private const ENV = 'REFUSE_DELETING_PARTS_USED_IN_BOM';

    public function testAFreshSettingsObjectDoesNotRefuse(): void
    {
        $settings = SettingsTestHelper::createSettingsDummy(PartDeletionSettings::class);

        $this->assertFalse($settings->refuseDeletingPartsUsedInBom);
    }

    public function testTheSettingNeverSavedDoesNotRefuse(): void
    {
        self::bootKernel();

        $this->assertFalse(self::getContainer()->get(PartDeletionSettings::class)->refuseDeletingPartsUsedInBom);
    }

    public function testTheSwitchIsStoredAndReadBack(): void
    {
        self::bootKernel();
        $manager = self::getContainer()->get(SettingsManagerInterface::class);
        $settings = $manager->get(PartDeletionSettings::class);

        $settings->refuseDeletingPartsUsedInBom = true;
        $manager->save($settings);
        $settings->refuseDeletingPartsUsedInBom = false;
        $manager->reload($settings);
        $this->assertTrue($settings->refuseDeletingPartsUsedInBom);

        //Leave the stored value as the other tests expect it
        $settings->refuseDeletingPartsUsedInBom = false;
        $manager->save($settings);
    }

    public function testTheEnvironmentVariableOverridesTheSwitch(): void
    {
        $_SERVER[self::ENV] = $_ENV[self::ENV] = '1';
        try {
            self::bootKernel();
            $manager = self::getContainer()->get(SettingsManagerInterface::class);
            $settings = $manager->get(PartDeletionSettings::class);

            $this->assertTrue($settings->refuseDeletingPartsUsedInBom);
            $this->assertTrue($manager->isEnvVarOverwritten($settings, 'refuseDeletingPartsUsedInBom'));
        } finally {
            unset($_SERVER[self::ENV], $_ENV[self::ENV]);
        }
    }

    public function testTheSettingsAreUnderBehavior(): void
    {
        self::bootKernel();
        $behavior = self::getContainer()->get(SettingsManagerInterface::class)->get(BehaviorSettings::class);

        $this->assertInstanceOf(PartDeletionSettings::class, $behavior->partDeletion);
    }
}
