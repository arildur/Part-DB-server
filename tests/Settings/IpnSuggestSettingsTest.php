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

use App\Entity\Parts\PartKind;
use App\Settings\MiscSettings\IpnSuggestSettings;
use App\Tests\SettingsTestHelper;
use PHPUnit\Framework\TestCase;

final class IpnSuggestSettingsTest extends TestCase
{
    private function settings(): IpnSuggestSettings
    {
        $settings = SettingsTestHelper::createSettingsDummy(IpnSuggestSettings::class);
        $settings->regex = '^ALL-\d+$';
        $settings->regexHelp = 'All parts';
        $settings->globalPrefix = 'ALL-';

        return $settings;
    }

    public function testEmptyMetaValuesFallBackToTheSettingsOfToday(): void
    {
        foreach ([null, ''] as $empty) {
            $settings = $this->settings();
            $settings->metaRegex = $empty;
            $settings->metaRegexHelp = $empty;
            $settings->metaPrefix = $empty;

            foreach (PartKind::cases() as $kind) {
                $this->assertSame('^ALL-\d+$', $settings->getRegexFor($kind));
                $this->assertSame('All parts', $settings->getRegexHelpFor($kind));
                $this->assertSame('ALL-', $settings->getPrefixFor($kind));
            }
        }
    }

    public function testSetMetaValuesAreUsedForMetaPartsOnly(): void
    {
        $settings = $this->settings();
        $settings->metaRegex = '^META-\d+$';
        $settings->metaRegexHelp = 'Meta parts';
        $settings->metaPrefix = 'META-';

        $this->assertSame('^META-\d+$', $settings->getRegexFor(PartKind::META));
        $this->assertSame('Meta parts', $settings->getRegexHelpFor(PartKind::META));
        $this->assertSame('META-', $settings->getPrefixFor(PartKind::META));

        $this->assertSame('^ALL-\d+$', $settings->getRegexFor(PartKind::MANUFACTURING));
        $this->assertSame('All parts', $settings->getRegexHelpFor(PartKind::MANUFACTURING));
        $this->assertSame('ALL-', $settings->getPrefixFor(PartKind::MANUFACTURING));
    }

    public function testAMetaPatternIsNeverDescribedByTheTextOfTheOtherPattern(): void
    {
        $settings = $this->settings();
        $settings->metaRegex = '^META-\d+$';

        $this->assertNull($settings->getRegexHelpFor(PartKind::META));
    }

    public function testAMetaHelpTextWithoutAPatternIsNotUsed(): void
    {
        $settings = $this->settings();
        $settings->metaRegexHelp = 'Meta parts';

        $this->assertSame('All parts', $settings->getRegexHelpFor(PartKind::META));
    }
}
