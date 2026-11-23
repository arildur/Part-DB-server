<?php
/**
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 * Copyright (C) 2019 - 2026 Jan Böhmer (https://github.com/jbtronics)
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

declare(strict_types=1);namespace App\Tests\Entity\Parts;

use App\Entity\Parts\Part;
use App\Entity\Parts\PartKind;
use PHPUnit\Framework\TestCase;

final class PartKindTest extends TestCase
{
    public function testValuesAreStable(): void
    {
        $this->assertSame('manufacturing', PartKind::MANUFACTURING->value);
        $this->assertSame('meta', PartKind::META->value);
        $this->assertCount(2, PartKind::cases());
    }

    public function testManufacturingPartAllows(): void
    {
        $kind = PartKind::MANUFACTURING;
        $this->assertTrue($kind->allowsManufacturerData());
        $this->assertTrue($kind->allowsSuppliers());
        $this->assertFalse($kind->allowsConnections());
        $this->assertTrue($kind->canBeConnected());
        $this->assertTrue($kind->canBeBuildPart());
        $this->assertFalse($kind->allowsPriceMethod());
    }

    public function testMetaPartAllows(): void
    {
        $kind = PartKind::META;
        $this->assertFalse($kind->allowsManufacturerData());
        $this->assertFalse($kind->allowsSuppliers());
        $this->assertTrue($kind->allowsConnections());
        $this->assertFalse($kind->canBeConnected());
        $this->assertTrue($kind->canBeBuildPart());
        $this->assertTrue($kind->allowsPriceMethod());
    }

    public function testEachKindAnswersWhichConversionItAllows(): void
    {
        $this->assertTrue(PartKind::MANUFACTURING->canBeConvertedToMeta());
        $this->assertFalse(PartKind::MANUFACTURING->canBeConvertedToManufacturing());
        $this->assertFalse(PartKind::META->canBeConvertedToMeta());
        $this->assertTrue(PartKind::META->canBeConvertedToManufacturing());
    }

    public function testEveryKindCanBeABuildPart(): void
    {
        foreach (PartKind::cases() as $kind) {
            $this->assertTrue($kind->canBeBuildPart(), $kind->value);
        }
    }

    public function testOnlyTheMetaPartHasAnIcon(): void
    {
        $this->assertNull(PartKind::MANUFACTURING->getIconClass(), 'A manufacturing part looks as any part did before the kinds');
        $this->assertSame('fa-sitemap', PartKind::META->getIconClass());

        $icons = array_values(array_filter(array_map(static fn (PartKind $kind): ?string => $kind->getIconClass(), PartKind::cases())));
        $this->assertSame($icons, array_unique($icons), 'Two kinds must not share an icon');
        foreach ($icons as $icon) {
            $this->assertMatchesRegularExpression('/^fa-[a-z-]+$/', $icon, 'The pickers only use icons of this form');
        }
    }

    public function testNewPartIsAManufacturingPart(): void
    {
        $this->assertSame(PartKind::MANUFACTURING, (new Part())->getKind());
    }
}
