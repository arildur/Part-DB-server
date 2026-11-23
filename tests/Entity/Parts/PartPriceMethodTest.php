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

declare(strict_types=1);

namespace App\Tests\Entity\Parts;

use App\Entity\Parts\PartPriceMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PartPriceMethodTest extends TestCase
{
    public function testTheCasesAndTheirValues(): void
    {
        $this->assertSame(['system_default', 'primary_source', 'cheapest', 'average', 'weighted_by_stock', 'user_value'],
            array_map(static fn (PartPriceMethod $method): string => $method->value, PartPriceMethod::cases()));
    }

    public function testEachCaseHasItsOwnTranslationKey(): void
    {
        foreach (PartPriceMethod::cases() as $method) {
            $this->assertSame('part.price_method.'.$method->value, $method->getTranslationKey());
        }

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => 'translated '.$key);
        $this->assertSame('translated part.price_method.cheapest', PartPriceMethod::CHEAPEST->trans($translator));
    }

    public function testOnlyTheMethodsOverTheConnectedPartsAreCalculated(): void
    {
        $calculated = array_filter(PartPriceMethod::cases(), static fn (PartPriceMethod $method): bool => $method->isCalculated());

        $this->assertSame([PartPriceMethod::PRIMARY_SOURCE, PartPriceMethod::CHEAPEST, PartPriceMethod::AVERAGE, PartPriceMethod::WEIGHTED_BY_STOCK],
            array_values($calculated));
    }

    public static function methodProvider(): \Generator
    {
        foreach (PartPriceMethod::cases() as $method) {
            yield $method->value => [$method];
        }
    }

    #[DataProvider('methodProvider')]
    public function testResolveGivesTheSystemDefaultForSystemDefaultAndItselfOtherwise(PartPriceMethod $method): void
    {
        foreach ([PartPriceMethod::PRIMARY_SOURCE, PartPriceMethod::AVERAGE] as $systemDefault) {
            $expected = $method === PartPriceMethod::SYSTEM_DEFAULT ? $systemDefault : $method;
            $this->assertSame($expected, $method->resolve($systemDefault));
        }
    }
}
