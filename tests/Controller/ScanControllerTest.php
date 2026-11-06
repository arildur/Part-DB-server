<?php

declare(strict_types=1);

/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2023 Jan Böhmer (https://github.com/jbtronics)
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
namespace App\Tests\Controller;

use Symfony\Component\DomCrawler\Crawler;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Parts\PartLot;
use App\Entity\Parts\PartKind;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\Part;
use App\Entity\Parts\Category;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ScanControllerTest extends WebTestCase
{

    private ?KernelBrowser $client = null;

    public function setUp(): void
    {
        $this->client = static::createClient([], [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => 'test',
        ]);
        $this->client->disableReboot();
        $this->client->catchExceptions(false);
    }

    public function testRedirectOnInputParameter(): void
    {
        $this->client->request('GET', '/en/scan?input=0000001');
        $this->assertResponseRedirects('/en/part/1');
    }

    public function testScanQRCode(): void
    {
        $this->client->request('GET', '/scan/part/1');
        $this->assertResponseRedirects('/en/part/1');
    }

    private function scanInInfoMode(int $partId): Crawler
    {
        $crawler = $this->client->request('GET', '/en/scan');

        return $this->client->submit($crawler->filter('form[name="scan_dialog"]')->form(), [
            'scan_dialog[input]' => sprintf('%07d', $partId),
            'scan_dialog[info_mode]' => true,
        ]);
    }

    private function metaPart(string $name): Part
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $meta = new Part();
        $meta->setName($name);
        $meta->setKind(PartKind::META);
        $meta->setCategory($em->find(Category::class, 1));
        $em->persist($meta);

        return $meta;
    }

    private function connectWithStock(Part $meta, float $amount): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $lot = new PartLot();
        $lot->setAmount($amount);
        $part = new Part();
        $part->setName('Holds the stock');
        $part->setCategory($em->find(Category::class, 1));
        $part->addPartLot($lot);
        $em->persist($part);

        $connection = new PartConnection();
        $connection->setConnectedPart($part);
        $connection->setPosition(1);
        $meta->addPartConnection($connection);
    }

    public function testInfoModeShowsTheTotalOfAMetaPartHoldingOnlyConnectedStock(): void
    {
        $meta = $this->metaPart('Scanned meta');
        $this->connectWithStock($meta, 7);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->flush();

        $crawler = $this->scanInInfoMode($meta->getID());

        self::assertResponseIsSuccessful();
        $total = $crawler->filter('.part-kind-total');
        $this->assertCount(1, $total);
        $this->assertStringContainsString('7', $total->text());
        $this->assertStringEndsWith('/part/'.$meta->getID().'/info#part_lots', (string) $total->filter('a')->attr('href'));
        $this->assertStringNotContainsString('Part is not stored at any location', (string) $this->client->getResponse()->getContent());
    }

    public function testInfoModeStillSaysNoLocationsWhenNothingHoldsStock(): void
    {
        $meta = $this->metaPart('Empty meta');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->flush();

        $this->scanInInfoMode($meta->getID());

        $this->assertStringContainsString('Part is not stored at any location', (string) $this->client->getResponse()->getContent());
    }

    public function testInfoModeOfAManufacturingPartHasNoTotalLine(): void
    {
        $crawler = $this->scanInInfoMode(1);

        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('h4.card-title'));
        $this->assertCount(0, $crawler->filter('.part-kind-total'));
    }
}
