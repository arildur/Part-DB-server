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

namespace App\Tests\Entity\Parts;

use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartKind;
use App\Entity\Parts\PartPriceMethod;
use App\Entity\PriceInformations\Currency;
use Brick\Math\BigDecimal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The price method and the user value of a part: what it holds, what it stores and what validation refuses.
 */
final class PartPriceFieldsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    /**
     * @return list<string> The paths of the violations about the price
     */
    private function pricePaths(Part $part): array
    {
        $paths = [];
        foreach ($this->validator->validate($part) as $violation) {
            if (str_starts_with($violation->getPropertyPath(), 'price')) {
                $paths[] = $violation->getPropertyPath();
            }
        }
        sort($paths);

        return $paths;
    }

    public function testANewPartStartsWithSystemDefaultAndNoUserValue(): void
    {
        $meta = (new Part())->setKind(PartKind::META);

        $this->assertSame(PartPriceMethod::SYSTEM_DEFAULT, $meta->getPriceMethod());
        $this->assertNull($meta->getPriceUserValue());
        $this->assertNull($meta->getPriceUserCurrency());
        $this->assertNull((new Part())->getPriceMethod(), 'A manufacturing part has no price method');
    }

    public function testNullSetsSystemDefault(): void
    {
        $meta = (new Part())->setKind(PartKind::META)->setPriceMethod(PartPriceMethod::CHEAPEST);

        $this->assertSame(PartPriceMethod::SYSTEM_DEFAULT, $meta->setPriceMethod(null)->getPriceMethod());
    }

    public function testTheUserValueIsRoundedToFiveDecimals(): void
    {
        $currency = new Currency();
        $part = (new Part())->setPriceUserValue(BigDecimal::of('0.123456'))->setPriceUserCurrency($currency);

        $this->assertSame('0.12346', (string) $part->getPriceUserValue());
        $this->assertSame($currency, $part->getPriceUserCurrency());
        $this->assertNull($part->setPriceUserValue(null)->getPriceUserValue());
    }

    public function testTheFieldsAreSavedAndReadBack(): void
    {
        //A currency of the fixtures: their fixed IDs leave the sequence of PostgreSQL behind, so a new one can collide
        $currency = $this->em->find(Currency::class, 1);
        $meta = (new Part())->setKind(PartKind::META)->setPriceMethod(PartPriceMethod::WEIGHTED_BY_STOCK)
            ->setPriceUserValue(BigDecimal::of('1.5'))->setPriceUserCurrency($currency);
        $meta->setName('Price test meta');
        $meta->setCategory($this->em->find(Category::class, 1));
        $plain = new Part();
        $plain->setName('Price test part');
        $plain->setCategory($this->em->find(Category::class, 1));
        $this->em->persist($meta);
        $this->em->persist($plain);
        $this->em->flush();
        $this->em->clear();

        $meta = $this->em->find(Part::class, $meta->getID());
        $this->assertSame(PartPriceMethod::WEIGHTED_BY_STOCK, $meta->getPriceMethod());
        $this->assertTrue(BigDecimal::of('1.5')->isEqualTo($meta->getPriceUserValue()));
        $this->assertSame(1, $meta->getPriceUserCurrency()?->getID());
        $table = $this->em->getConnection()->getDatabasePlatform()->quoteSingleIdentifier('parts');
        $this->assertSame('system_default', $this->em->getConnection()->fetchOne("SELECT price_method FROM $table WHERE id = ?", [$plain->getID()]));
    }

    public function testAManufacturingPartRefusesEachPriceField(): void
    {
        $part = new Part();
        $this->assertSame([], $this->pricePaths($part));

        $this->assertSame(['priceMethod'], $this->pricePaths((new Part())->setPriceMethod(PartPriceMethod::CHEAPEST)));
        $this->assertSame(['priceUserValue'], $this->pricePaths((new Part())->setPriceUserValue(BigDecimal::of('0.1'))));
        $this->assertSame(['priceUserCurrency'], $this->pricePaths((new Part())->setPriceUserCurrency(new Currency())));
        //A given method is refused, system default too; only null, which resets it, is not
        $this->assertSame(['priceMethod'], $this->pricePaths((new Part())->setPriceMethod(PartPriceMethod::SYSTEM_DEFAULT)));
        $this->assertSame([], $this->pricePaths((new Part())->setPriceMethod(PartPriceMethod::CHEAPEST)->setPriceMethod(null)));
    }

    public function testAMetaPartMayHoldEachPriceField(): void
    {
        $meta = (new Part())->setKind(PartKind::META)->setPriceMethod(PartPriceMethod::CHEAPEST)
            ->setPriceUserValue(BigDecimal::of('0.1'))->setPriceUserCurrency(new Currency());

        $this->assertSame([], $this->pricePaths($meta), 'A user value is kept with a calculated method');
    }

    public function testTheMethodUserValueNeedsAValue(): void
    {
        $meta = (new Part())->setKind(PartKind::META)->setPriceMethod(PartPriceMethod::USER_VALUE);
        $this->assertSame(['priceUserValue'], $this->pricePaths($meta));

        $meta->setPriceUserValue(BigDecimal::zero());
        $this->assertSame([], $this->pricePaths($meta));
    }

    public function testANegativeUserValueIsRefused(): void
    {
        $meta = (new Part())->setKind(PartKind::META)->setPriceUserValue(BigDecimal::of('-1'));

        $this->assertSame(['priceUserValue'], $this->pricePaths($meta));
    }

    /**
     * Nothing calculated is stored: the user value is the only price a part maps.
     */
    public function testNoCalculatedPriceIsMapped(): void
    {
        $metadata = $this->em->getClassMetadata(Part::class);
        $priceColumns = array_values(array_filter($metadata->getFieldNames(), static fn (string $field): bool => stripos($field, 'price') !== false));
        $priceAssociations = array_values(array_filter($metadata->getAssociationNames(), static fn (string $field): bool => stripos($field, 'price') !== false));

        $this->assertSame(['priceMethod', 'priceUserValue'], $priceColumns);
        $this->assertSame(['priceUserCurrency'], $priceAssociations);
    }
}
