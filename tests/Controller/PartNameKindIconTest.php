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

namespace App\Tests\Controller;

use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartKind;
use App\Entity\Parts\PartLot;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use App\Entity\UserSystem\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A meta part's name carries the icon of its kind wherever the web interface shows it, a manufacturing part's never.
 */
final class PartNameKindIconTest extends WebTestCase
{
    private const META = 'Named meta <b>';
    private const MANUFACTURING = 'Named manufacturing <b>';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Part $meta;
    private Part $manufacturing;
    private Project $project;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['name' => 'admin']));

        $this->meta = $this->part(self::META, PartKind::META);
        $this->manufacturing = $this->part(self::MANUFACTURING);
        $lot = new PartLot();
        $lot->setAmount(10);
        $this->manufacturing->addPartLot($lot);
        $this->meta->addPartConnection((new PartConnection())->setConnectedPart($this->manufacturing)->setPosition(1));

        $this->project = new Project();
        $this->project->setName('Kind icon project');
        foreach ([$this->meta, $this->manufacturing] as $part) {
            $entry = (new ProjectBOMEntry())->setQuantity(1.0);
            $this->project->addBomEntry($entry);
            $part->addProjectBomEntry($entry);
        }
        $this->em->persist($this->project);
        $this->em->flush();
        //The pages read the connections and BOM entries from the database, not from the collections filled here
        $this->em->clear();
        $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['name' => 'admin']));
    }

    private function part(string $name, PartKind $kind = PartKind::MANUFACTURING): Part
    {
        $part = (new Part())->setKind($kind);
        $part->setName($name);
        $part->setCategory($this->em->find(Category::class, 1));
        $this->em->persist($part);

        return $part;
    }

    private function get(string $url): string
    {
        $this->client->request('GET', $url);
        $this->assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    /** @return list<array<string, mixed>> */
    private function tableRows(string $url): array
    {
        $this->client->request('POST', $url, ['_dt' => 'dt', 'draw' => 1, 'start' => 0, 'length' => 500, 'search' => ['value' => '', 'regex' => false]]);
        $this->assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
    }

    /** The pattern of the given name right after the icon of the meta part kind */
    private static function withIcon(string $name): string
    {
        return '#<i class="fa-solid fa-sitemap fa-fw me-1" title="Meta part"></i>'.preg_quote(htmlspecialchars($name), '#').'#';
    }

    private function assertMetaMarkedAndManufacturingNot(string $html, string $where): void
    {
        $this->assertMatchesRegularExpression(self::withIcon(self::META), $html, $where);
        $this->assertStringContainsString(htmlspecialchars(self::MANUFACTURING), $html, $where);
        $this->assertDoesNotMatchRegularExpression('#fa-sitemap[^>]*></i>\s*'.preg_quote(htmlspecialchars(self::MANUFACTURING), '#').'#', $html,
            $where.': a manufacturing part has no icon before its name');
    }

    public function testTheInfoPageHeadingOfAMetaPartCarriesTheIcon(): void
    {
        $crawler = $this->client->request('GET', '/en/part/'.$this->meta->getID().'/info');
        $this->assertResponseIsSuccessful();
        $this->assertMatchesRegularExpression(self::withIcon(self::META), $crawler->filter('h3')->first()->html());
        $this->assertStringNotContainsString('fa-sitemap', $crawler->filter('title')->html(), 'The page title stays plain text');

        $crawler = $this->client->request('GET', '/en/part/'.$this->manufacturing->getID().'/info');
        $this->assertStringNotContainsString('fa-sitemap', $crawler->filter('h3')->first()->html());
    }

    public function testTheMetaPartsTabOfAManufacturingPartMarksTheMetaPart(): void
    {
        $html = $this->get('/en/part/'.$this->manufacturing->getID().'/info');
        $this->assertMatchesRegularExpression(self::withIcon(self::META), $html);
        $this->assertDoesNotMatchRegularExpression('#fa-sitemap[^>]*></i>\s*'.preg_quote(htmlspecialchars(self::MANUFACTURING), '#').'#', $html);
    }

    public function testTheBomTableAndTheBuildPageMarkOnlyTheMetaPart(): void
    {
        $names = implode("\n", array_column($this->tableRows('/en/project/'.$this->project->getID().'/info'), 'name'));
        $this->assertMetaMarkedAndManufacturingNot($names, 'BOM table');

        $this->assertMetaMarkedAndManufacturingNot($this->get('/en/project/'.$this->project->getID().'/build?n=1'), 'build page');
    }

    public function testTheLogTableMarksOnlyTheMetaPart(): void
    {
        $targets = implode("\n", array_column($this->tableRows('/en/log/'), 'target'));
        $this->assertMetaMarkedAndManufacturingNot($targets, 'log table');
    }
}
