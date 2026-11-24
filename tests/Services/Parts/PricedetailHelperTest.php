<?php
/**
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 * Copyright (C) 2019 - 2020 Jan Böhmer (https://github.com/jbtronics)
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

namespace App\Tests\Services\Parts;

use PHPUnit\Framework\Attributes\DataProvider;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartConnectionStatus;
use App\Entity\Parts\PartKind;
use App\Entity\Parts\PartLot;
use App\Entity\Parts\PartPriceMethod;
use App\Entity\PriceInformations\Currency;
use App\Entity\PriceInformations\Orderdetail;
use App\Entity\PriceInformations\Pricedetail;
use App\Services\Formatters\AmountFormatter;
use App\Services\Parts\PricedetailHelper;
use Brick\Math\BigDecimal;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PricedetailHelperTest extends WebTestCase
{
    /**
     * @var AmountFormatter
     */
    protected $service;

    protected function setUp(): void
    {
        //Get a service instance.
        self::bootKernel();
        $this->service = self::getContainer()->get(PricedetailHelper::class);
    }

    public static function maxDiscountAmountDataProvider(): ?\Generator
    {
        $part = new Part();
        yield [$part, null, 'Part without any orderdetails failed!'];

        //Part with empty orderdetails
        $part = new Part();
        $orderdetail = new Orderdetail();
        $part->addOrderdetail($orderdetail);
        yield [$part, null, 'Part with one empty orderdetail failed!'];

        $part = new Part();
        $orderdetail = new Orderdetail();
        $part->addOrderdetail($orderdetail);
        $orderdetail->addPricedetail((new Pricedetail())->setMinDiscountQuantity(1));
        yield [$part, 1.0, 'Part with one pricedetail failed!'];

        $part = new Part();
        $orderdetail = new Orderdetail();
        $part->addOrderdetail($orderdetail);
        $orderdetail->addPricedetail((new Pricedetail())->setMinDiscountQuantity(1));
        $orderdetail->addPricedetail((new Pricedetail())->setMinDiscountQuantity(2));
        $orderdetail->addPricedetail((new Pricedetail())->setMinDiscountQuantity(1.5));
        yield [$part, 2.0, 'Part with multiple pricedetails failed!'];

        $part = new Part();
        $orderdetail = new Orderdetail();
        $orderdetail2 = new Orderdetail();
        $part->addOrderdetail($orderdetail);
        $part->addOrderdetail($orderdetail2);
        $orderdetail->addPricedetail((new Pricedetail())->setMinDiscountQuantity(1));
        $orderdetail->addPricedetail((new Pricedetail())->setMinDiscountQuantity(2));
        $orderdetail->addPricedetail((new Pricedetail())->setMinDiscountQuantity(1.5));
        $orderdetail2->addPricedetail((new Pricedetail())->setMinDiscountQuantity(10));

        yield [$part, 10.0, 'Part with multiple orderdetails failed'];
    }

    #[DataProvider('maxDiscountAmountDataProvider')]
    public function testGetMaxDiscountAmount(Part $part, ?float $expected_result, string $message): void
    {
        $this->assertSame($expected_result, $this->service->getMaxDiscountAmount($part), $message);
    }

    // --- getMinOrderAmount ---

    public static function minOrderAmountDataProvider(): \Generator
    {
        $part = new Part();
        yield [$part, null, 'No orderdetails'];

        $part = new Part();
        $part->addOrderdetail(new Orderdetail()); // orderdetail with no pricedetails
        yield [$part, null, 'Empty orderdetail'];

        $part = new Part();
        $od = new Orderdetail();
        $od->addPricedetail((new Pricedetail())->setMinDiscountQuantity(5));
        $part->addOrderdetail($od);
        yield [$part, 5.0, 'Single pricedetail'];

        // The service reads $pricedetails[0] assuming the collection is sorted ascending
        // (which Doctrine does automatically for persistent collections). For in-memory
        // collections we must insert in ascending order ourselves.
        $part = new Part();
        $od = new Orderdetail();
        $od->addPricedetail((new Pricedetail())->setMinDiscountQuantity(1));
        $od->addPricedetail((new Pricedetail())->setMinDiscountQuantity(3));
        $od->addPricedetail((new Pricedetail())->setMinDiscountQuantity(10));
        $part->addOrderdetail($od);
        yield [$part, 1.0, 'Multiple pricedetails — picks minimum (first in ascending order)'];

        $part = new Part();
        $od1 = new Orderdetail();
        $od1->addPricedetail((new Pricedetail())->setMinDiscountQuantity(5));
        $od2 = new Orderdetail();
        $od2->addPricedetail((new Pricedetail())->setMinDiscountQuantity(2));
        $part->addOrderdetail($od1);
        $part->addOrderdetail($od2);
        yield [$part, 2.0, 'Multiple orderdetails — picks global minimum'];
    }

    #[DataProvider('minOrderAmountDataProvider')]
    public function testGetMinOrderAmount(Part $part, ?float $expected, string $message): void
    {
        $this->assertSame($expected, $this->service->getMinOrderAmount($part), $message);
    }

    // --- calculateAvgPrice ---

    private static function makePartWithPrice(float $pricePerUnit, float $minQty = 1.0): Part
    {
        $part = new Part();
        $od = new Orderdetail();
        $pd = (new Pricedetail())
            ->setMinDiscountQuantity($minQty)
            ->setPrice(BigDecimal::of((string) $pricePerUnit));
        $od->addPricedetail($pd);
        $part->addOrderdetail($od);
        return $part;
    }

    public function testCalculateAvgPriceNoOrderdetailsReturnsNull(): void
    {
        $this->assertNull($this->service->calculateAvgPrice(new Part()));
    }

    public function testCalculateAvgPriceExplicitAmount(): void
    {
        $part = self::makePartWithPrice(2.00);
        $result = $this->service->calculateAvgPrice($part, 1.0);
        $this->assertNotNull($result);
        $this->assertTrue(BigDecimal::of('2.00000')->isEqualTo($result));
    }

    public function testCalculateAvgPriceUsesMinOrderAmountWhenAmountIsNull(): void
    {
        // Min order amount is 5; the price applies for qty >= 5
        $part = self::makePartWithPrice(3.00, 5.0);
        $result = $this->service->calculateAvgPrice($part, null);
        $this->assertNotNull($result);
        $this->assertTrue(BigDecimal::of('3.00000')->isEqualTo($result));
    }

    public function testCalculateAvgPriceAveragesMultipleSuppliers(): void
    {
        $part = new Part();

        $od1 = new Orderdetail();
        $od1->addPricedetail((new Pricedetail())->setMinDiscountQuantity(1)->setPrice(BigDecimal::of('2.00')));
        $part->addOrderdetail($od1);

        $od2 = new Orderdetail();
        $od2->addPricedetail((new Pricedetail())->setMinDiscountQuantity(1)->setPrice(BigDecimal::of('4.00')));
        $part->addOrderdetail($od2);

        // Average of 2.00 and 4.00 = 3.00
        $result = $this->service->calculateAvgPrice($part, 1.0);
        $this->assertNotNull($result);
        $this->assertTrue(BigDecimal::of('3.00000')->isEqualTo($result));
    }

    public function testCalculateAvgPriceSkipsSupplierWithNoCoverageForAmount(): void
    {
        // Only one supplier covers qty=1, the other requires qty >= 100
        $part = new Part();
        $od1 = new Orderdetail();
        $od1->addPricedetail((new Pricedetail())->setMinDiscountQuantity(1)->setPrice(BigDecimal::of('5.00')));
        $part->addOrderdetail($od1);

        $od2 = new Orderdetail();
        $od2->addPricedetail((new Pricedetail())->setMinDiscountQuantity(100)->setPrice(BigDecimal::of('1.00')));
        $part->addOrderdetail($od2);

        $result = $this->service->calculateAvgPrice($part, 1.0);
        $this->assertNotNull($result);
        $this->assertTrue(BigDecimal::of('5.00000')->isEqualTo($result));
    }

    // --- convertMoneyToCurrency ---

    public function testConvertMoneyToCurrencyIdentityBothNull(): void
    {
        // Both currencies null = base currency; same currency, no conversion
        $value = BigDecimal::of('10.00');
        $result = $this->service->convertMoneyToCurrency($value, null, null);
        $this->assertNotNull($result);
        $this->assertTrue($value->isEqualTo($result));
    }

    public function testConvertMoneyToCurrencyFromForeignToBase(): void
    {
        // EUR → base (null): exchange rate = 1.2 means 1 foreign = 1.2 base
        $currency = new Currency();
        $currency->setExchangeRate(BigDecimal::of('1.2'));

        $result = $this->service->convertMoneyToCurrency(BigDecimal::of('10.00'), $currency, null);
        $this->assertNotNull($result);
        // 10 * 1.2 = 12
        $this->assertTrue(BigDecimal::of('12.00000')->isEqualTo($result));
    }

    public function testConvertMoneyToCurrencyNullExchangeRateReturnsNull(): void
    {
        $currency = new Currency();
        // exchange rate not set → null

        $result = $this->service->convertMoneyToCurrency(BigDecimal::of('10.00'), $currency, null);
        $this->assertNull($result);
    }

    public function testConvertMoneyToCurrencyZeroExchangeRateReturnsNull(): void
    {
        $currency = new Currency();
        $currency->setExchangeRate(BigDecimal::zero());

        $result = $this->service->convertMoneyToCurrency(BigDecimal::of('10.00'), $currency, null);
        $this->assertNull($result);
    }

    public function testConvertMoneyToCurrencyTargetNullExchangeRateReturnsNull(): void
    {
        $target = new Currency();
        // exchange rate not set → getInverseExchangeRate() returns null

        $result = $this->service->convertMoneyToCurrency(BigDecimal::of('10.00'), null, $target);
        $this->assertNull($result);
    }

    public function testConvertMoneyToCurrencySameCurrencyInstanceIsIdentity(): void
    {
        $currency = new Currency();
        $currency->setExchangeRate(BigDecimal::of('2.0'));

        $value = BigDecimal::of('5.00');
        // origin === target → no conversion at all
        $result = $this->service->convertMoneyToCurrency($value, $currency, $currency);
        $this->assertNotNull($result);
        $this->assertTrue($value->isEqualTo($result));
    }

    private function pricedPart(string $price, float $stock = 0.0): Part
    {
        $part = new Part();
        $orderdetail = new Orderdetail();
        $orderdetail->addPricedetail((new Pricedetail())->setMinDiscountQuantity(1.0)->setPrice(BigDecimal::of($price)));
        $part->addOrderdetail($orderdetail);
        if ($stock > 0) {
            $part->addPartLot((new PartLot())->setAmount($stock));
        }

        return $part;
    }

    private function metaOf(PartPriceMethod $method, Part ...$parts): Part
    {
        $meta = (new Part())->setKind(PartKind::META)->setPriceMethod($method);
        foreach ($parts as $i => $part) {
            $meta->addPartConnection((new PartConnection())->setConnectedPart($part)->setPosition($i + 1));
        }

        return $meta;
    }

    public function testAMetaPartPriceFollowsThePricesAndStockOfItsConnectedPartsAsTheyChange(): void
    {
        $first = $this->pricedPart('1', 1);
        $second = $this->pricedPart('3', 1);
        $meta = $this->metaOf(PartPriceMethod::WEIGHTED_BY_STOCK, $first, $second);
        $this->assertSame('2.00000', (string) $this->service->calculateMetaPartPrice($meta));

        $first->getOrderdetails()->first()->getPricedetails()->first()->setPrice(BigDecimal::of('2'));
        $this->assertSame('2.50000', (string) $this->service->calculateMetaPartPrice($meta), 'A changed price changes the result');

        $first->getPartLots()->first()->setAmount(3);
        $this->assertSame('2.25000', (string) $this->service->calculateMetaPartPrice($meta), 'A changed stock changes the result');
    }

    public function testAMetaPartPriceUsesOnlyUsableConnections(): void
    {
        $meta = $this->metaOf(PartPriceMethod::CHEAPEST, $this->pricedPart('5'), $this->pricedPart('1'), $this->pricedPart('2'));
        $connections = $meta->getPartConnections()->toArray();
        $connections[1]->setStatus(PartConnectionStatus::UNDER_EVALUATION);
        $connections[2]->setStatus(PartConnectionStatus::USE_UP);

        $this->assertSame('2.00000', (string) $this->service->calculateMetaPartPrice($meta));
        $this->assertSame('5.00000', (string) $this->service->calculateMetaPartPrice($meta->setPriceMethod(PartPriceMethod::PRIMARY_SOURCE)));
    }

    public function testAMetaPartPriceIsTakenAtTheQuantityAsked(): void
    {
        $part = $this->pricedPart('2');
        $part->getOrderdetails()->first()->addPricedetail((new Pricedetail())->setMinDiscountQuantity(100.0)->setPrice(BigDecimal::of('1')));
        $meta = $this->metaOf(PartPriceMethod::AVERAGE, $part);

        $this->assertSame('2.00000', (string) $this->service->calculateMetaPartPrice($meta, 1));
        $this->assertSame('1.00000', (string) $this->service->calculateMetaPartPrice($meta, 100));
    }

    public function testTheLowestUnitPriceIsTheCheapestOrderDetailAtItsSmallestOrder(): void
    {
        $this->assertNull($this->service->getLowestUnitPrice(new Part()), 'No order details, no price');

        $part = $this->pricedPart('2');
        $tiered = new Orderdetail();
        $tiered->addPricedetail((new Pricedetail())->setMinDiscountQuantity(10.0)->setPrice(BigDecimal::of('1.5')));
        $tiered->addPricedetail((new Pricedetail())->setMinDiscountQuantity(1000.0)->setPrice(BigDecimal::of('0.1')));
        $part->addOrderdetail($tiered);
        $this->assertSame('1.50000', (string) $this->service->getLowestUnitPrice($part), 'A bulk price is not the price of one unit');

        $obsolete = new Orderdetail();
        $obsolete->setObsolete(true);
        $obsolete->addPricedetail((new Pricedetail())->setMinDiscountQuantity(1.0)->setPrice(BigDecimal::of('0.01')));
        $part->addOrderdetail($obsolete);
        $part->addOrderdetail(new Orderdetail());
        $this->assertSame('1.50000', (string) $this->service->getLowestUnitPrice($part), 'Obsolete and unpriced order details are left out');
    }

    public function testOnlyAMetaPartHasAPriceByAMethod(): void
    {
        $this->assertNull($this->service->calculateMetaPartPrice($this->pricedPart('1')));
        $this->assertNull($this->service->getEffectivePriceMethod($this->pricedPart('1')));
        $this->assertNull($this->service->calculateMetaPartPrice($this->metaOf(PartPriceMethod::CHEAPEST)), 'No connected part, no price');
        $this->assertSame(PartPriceMethod::PRIMARY_SOURCE, $this->service->getEffectivePriceMethod($this->metaOf(PartPriceMethod::SYSTEM_DEFAULT)));
    }
}
