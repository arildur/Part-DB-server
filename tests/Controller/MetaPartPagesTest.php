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

declare(strict_types=1);namespace App\Tests\Controller;

use App\Settings\MiscSettings\IpnSuggestSettings;
use App\Entity\Parts\Category;
use App\Entity\Parts\Footprint;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartConnectionStatus;
use App\Entity\Parts\PartKind;
use App\Entity\Parts\PartLot;
use App\Entity\Parts\Supplier;
use App\Entity\PriceInformations\Orderdetail;
use App\Entity\PriceInformations\Pricedetail;
use App\Entity\UserSystem\User;
use App\Form\Part\PartBaseType;
use Brick\Math\BigDecimal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\FormFactoryInterface;

final class MetaPartPagesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
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

    private function lot(Part $part, float $amount, ?string $expires = null): void
    {
        $lot = new PartLot();
        $lot->setAmount($amount);
        $lot->setDescription('Lot of '.$amount);
        if ($expires !== null) {
            $lot->setExpirationDate(new \DateTimeImmutable($expires));
        }
        $part->addPartLot($lot);
    }

    private function price(Part $part, string $price): void
    {
        $orderdetail = new Orderdetail();
        $orderdetail->setSupplier($this->em->find(Supplier::class, 1));
        $orderdetail->setSupplierpartnr('SP-'.$part->getName());
        $orderdetail->addPricedetail((new Pricedetail())->setMinDiscountQuantity(1.0)->setPrice(BigDecimal::of($price)));
        $part->addOrderdetail($orderdetail);
    }

    private function connect(Part $meta, Part $part, int $position, PartConnectionStatus $status = PartConnectionStatus::APPROVED, string $note = ''): void
    {
        $meta->addPartConnection((new PartConnection())->setConnectedPart($part)->setPosition($position)->setStatus($status)->setNote($note));
    }

    /** The example of the concept: a common bin, two connected parts, one retired. */
    private function exampleMetaPart(): Part
    {
        $meta = $this->part('Example meta', PartKind::META);
        $this->lot($meta, 200);
        $first = $this->part('First source');
        $this->lot($first, 500);
        $this->price($first, '2');
        $second = $this->part('Second source');
        $this->lot($second, 100);
        $this->lot($second, 40, '-2 days');
        $this->price($second, '1');
        $retired = $this->part('Retired source');
        $this->lot($retired, 1000);
        $this->price($retired, '0.5');
        $this->connect($meta, $retired, 1, PartConnectionStatus::DO_NOT_USE, 'Bad batch');
        $this->connect($meta, $second, 3);
        $this->connect($meta, $first, 2, note: 'Preferred');
        $this->em->flush();
        $id = $meta->getID();
        $this->em->clear();
        $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['name' => 'admin']));

        return $this->em->find(Part::class, $id);
    }

    private function info(Part $part): Crawler
    {
        $crawler = $this->client->request('GET', '/en/part/'.$part->getID().'/info');
        $this->assertResponseIsSuccessful();

        return $crawler;
    }

    private function tabIds(Crawler $crawler): array
    {
        return $crawler->filter('#part-tabs a.nav-link, ul.nav-tabs a.nav-link')->each(static fn (Crawler $a) => (string) $a->attr('href'));
    }

    public function testAManufacturingPartHasNoNewTabAndNoBadge(): void
    {
        $crawler = $this->info($this->em->find(Part::class, 1));

        $this->assertNotContains('#connections', $this->tabIds($crawler));
        $this->assertSame(0, $crawler->filter('.fa-sitemap')->reduce(static fn (Crawler $n) => str_contains((string) $n->ancestors()->first()->attr('class'), 'badge'))->count());
        $this->assertSelectorTextNotContains('main, body', 'Own lots of this meta part');
    }

    public function testAMetaPartShowsItsHeaderAndSummary(): void
    {
        $meta = $this->exampleMetaPart();

        $crawler = $this->info($meta);
        $body = $crawler->filter('body')->text();

        $this->assertStringContainsString('Meta part', $crawler->filter('.card-header, .card-title')->first()->text());
        $this->assertStringContainsString('800', $body);
    }

    public function testThePriceRangeLeavesOutADoNotUseConnection(): void
    {
        $body = $this->info($this->exampleMetaPart())->filter('body')->text();

        $this->assertMatchesRegularExpression('/1\.00.*-.*2\.00/s', $body);
    }

    public function testAMetaPartWithNoPricedPartShowsNoPriceLine(): void
    {
        $meta = $this->part('No price', PartKind::META);
        $this->em->flush();

        $this->assertSame(0, $this->info($meta)->filter('.fa-money-bill-alt')->count());
    }

    public function testTheConnectedPartsTabListsThemInOrderWithDoNotUseLast(): void
    {
        $crawler = $this->info($this->exampleMetaPart());

        $this->assertContains('#connections', $this->tabIds($crawler));
        $rows = $crawler->filter('#connections tbody tr:not(.table-light)')->each(static fn (Crawler $r) => $r->text());
        $this->assertCount(3, $rows);
        $this->assertStringContainsString('First source', $rows[0]);
        $this->assertStringContainsString('Preferred', $rows[0]);
        $this->assertStringContainsString('Second source', $rows[1]);
        $this->assertStringContainsString('Retired source', $rows[2]);
        $this->assertStringContainsString('Do not use', $rows[2]);
        $this->assertStringContainsString('Bad batch', $rows[2]);
    }

    /** A meta part with one connection of each status, listed by position in the reverse order of usability. */
    private function metaPartWithEachStatus(): Part
    {
        $meta = $this->part('Status meta', PartKind::META);
        $statuses = [PartConnectionStatus::DO_NOT_USE, PartConnectionStatus::UNDER_EVALUATION, PartConnectionStatus::USE_UP, PartConnectionStatus::APPROVED];
        foreach ($statuses as $i => $status) {
            $part = $this->part('Part '.$status->value);
            $this->lot($part, 10);
            $this->price($part, '1');
            $this->connect($meta, $part, $i + 1, $status);
        }
        $this->em->flush();
        $id = $meta->getID();
        $this->em->clear();
        $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['name' => 'admin']));

        return $this->em->find(Part::class, $id);
    }

    public function testTheConnectionsTabShowsEachStatusWithItsBadgeAndTheUnusableUnderAHeading(): void
    {
        $crawler = $this->info($this->metaPartWithEachStatus());

        $rows = $crawler->filter('#connections tbody tr');
        $this->assertCount(5, $rows);
        $this->assertStringContainsString('Part use_up', $rows->eq(0)->text());
        $this->assertStringContainsString('Part approved', $rows->eq(1)->text());
        $this->assertStringContainsString('table-light', (string) $rows->eq(2)->attr('class'));
        $this->assertStringContainsString('Not usable', $rows->eq(2)->text());
        $this->assertStringContainsString('Part do_not_use', $rows->eq(3)->text());
        $this->assertStringContainsString('table-danger', (string) $rows->eq(3)->attr('class'));
        $this->assertStringContainsString('Part under_evaluation', $rows->eq(4)->text());
        $this->assertStringContainsString('text-muted', (string) $rows->eq(4)->attr('class'));

        $expected = [
            'use_up' => ['text-bg-warning', 'fa-hourglass-end', 'Use up'],
            'approved' => ['text-bg-success', 'fa-check', 'Approved'],
            'do_not_use' => ['text-bg-danger', 'fa-ban', 'Do not use'],
            'under_evaluation' => ['text-bg-info', 'fa-flask', 'Under evaluation'],
        ];
        foreach ($expected as $value => [$class, $icon, $label]) {
            $badge = $crawler->filter('#connections [data-connection-status="'.$value.'"]');
            $this->assertCount(1, $badge, $value);
            $this->assertStringContainsString($class, (string) $badge->attr('class'));
            $this->assertCount(1, $badge->filter('i.'.$icon));
            $this->assertSame($label, trim($badge->text()));
        }
    }

    public function testTheStockAndShoppingTabsListTheUnusableLastUnderAHeading(): void
    {
        $crawler = $this->info($this->metaPartWithEachStatus());

        $order = ['Part use_up', 'Part approved', 'Not usable', 'Part do_not_use', 'Part under_evaluation'];
        foreach (['#part_lots', '#suppliers'] as $pane) {
            $text = $crawler->filter($pane)->text();
            $positions = array_map(static fn (string $needle): int|false => strpos($text, $needle), $order);
            $this->assertNotContains(false, $positions, $pane);
            $sorted = $positions;
            sort($sorted);
            $this->assertSame($sorted, $positions, $pane.' lists the blocks in the order of usability and priority');
            $this->assertCount(4, $crawler->filter($pane.' [data-connection-status]'), $pane.' marks every block with its status');
        }
        //Only usable connections count: 10 each for approved and use up
        $this->assertStringContainsString('20', $crawler->filter('#part_lots tfoot')->text());
    }

    public function testTheStockTabListsEverythingWithATotalAndNoForeignButtons(): void
    {
        $meta = $this->exampleMetaPart();
        $crawler = $this->info($meta);
        $pane = $crawler->filter('#part_lots');

        $text = $pane->text();
        foreach (['Own lots of this meta part', 'First source', 'Second source', 'Retired source', 'Not counted'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        $this->assertStringContainsString('800', $pane->filter('tfoot')->text());

        //Only the own lot has stock buttons
        $this->assertSame(1, $pane->filter('[data-bs-target="#stocktake-modal"]')->count());

        //Own lot, then three connected parts with one, two and one lots
        $counter = $crawler->filter('#part_lots-tab .badge')->text();
        $this->assertSame('5', trim($counter));
    }

    public function testAStockChangeOnALotOfAConnectedPartIsRefusedThroughTheMetaPart(): void
    {
        $meta = $this->exampleMetaPart();
        $foreign = $meta->getOrderedConnections()[0]->getConnectedPart()->getPartLots()->first();
        $this->client->catchExceptions(true);

        $this->client->request('POST', '/en/part/'.$meta->getID().'/add_withdraw', [
            'lot_id' => $foreign->getID(),
            'amount' => 1,
            'action' => 'withdraw',
        ]);

        $this->em->clear();
        $this->assertSame($foreign->getAmount(), $this->em->find(PartLot::class, $foreign->getID())->getAmount());
    }

    public function testTheShoppingTabIsGroupedPerConnectedPart(): void
    {
        $crawler = $this->info($this->exampleMetaPart());

        $this->assertContains('#suppliers', $this->tabIds($crawler));
        $text = $crawler->filter('#suppliers')->text();
        $this->assertStringContainsString('SP-First source', $text);
        $this->assertStringContainsString('SP-Second source', $text);
        $this->assertLessThan(strpos($text, 'Retired source'), strpos($text, 'Second source'), 'Do not use comes last');
    }

    public function testAMetaPartWithNoSuppliersHasNoShoppingTab(): void
    {
        $meta = $this->part('No suppliers', PartKind::META);
        $this->connect($meta, $this->part('Plain'), 1);
        $this->em->flush();

        $this->assertNotContains('#suppliers', $this->tabIds($this->info($meta)));
    }

    public function testAConnectedPartListsItsMetaParts(): void
    {
        $meta = $this->exampleMetaPart();
        $part = $meta->getOrderedConnections()[0]->getConnectedPart();

        $crawler = $this->info($part);

        $this->assertContains('#connections', $this->tabIds($crawler));
        $this->assertStringContainsString('Example meta', $crawler->filter('#connections')->text());
    }

    public function testAPastViewSaysConnectedStockIsCurrent(): void
    {
        $meta = $this->exampleMetaPart();

        $crawler = $this->client->request('GET', '/en/part/'.$meta->getID().'/info/'.(time() - 1));

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('current stock', $crawler->filter('#part_lots')->text());
    }

    public function testTheSettingsPageRendersEveryTab(): void
    {
        $this->client->request('GET', '/en/settings');

        $this->assertResponseIsSuccessful();
    }

    public function testToolsTabOfAMetaPart(): void
    {
        $meta = $this->exampleMetaPart();
        $crawler = $this->info($meta);

        $this->assertSame(0, $crawler->filter('a[href*="info_providers/update"], a[href*="from_info_provider"]')->count());
        $this->assertGreaterThan(0, $crawler->filter('#tools a[href*="kind=meta"]')->count());
    }

    public function testDeletingAConnectedPartNamesTheMetaParts(): void
    {
        $part = $this->exampleMetaPart()->getOrderedConnections()[0]->getConnectedPart();

        $message = $this->info($part)->filter('#tools form[data-delete-message]')->attr('data-delete-message');

        $this->assertStringContainsString('Example meta', (string) $message);
    }

    private function kindField(Crawler $crawler): Crawler
    {
        return $crawler->filter('select[name="part_base[kind]"]');
    }

    public function testOnlyTheBlankFormLetsTheKindBeChosen(): void
    {
        $crawler = $this->client->request('GET', '/en/part/new');
        $this->assertNull($this->kindField($crawler)->attr('disabled'));
        $this->assertSame('manufacturing', $this->kindField($crawler)->filter('option[selected]')->attr('value'));

        $crawler = $this->client->request('GET', '/en/part/new?kind=meta');
        $this->assertSame('meta', $this->kindField($crawler)->filter('option[selected]')->attr('value'));

        $crawler = $this->client->request('GET', '/en/part/1/clone');
        $this->assertNotNull($this->kindField($crawler)->attr('disabled'));

        $crawler = $this->client->request('GET', '/en/part/1/edit');
        $this->assertNotNull($this->kindField($crawler)->attr('disabled'));
    }

    public function testTheFormOutsideTheBlankRouteHoldsTheKindFixed(): void
    {
        $form = self::getContainer()->get(FormFactoryInterface::class)->create(PartBaseType::class, new Part());

        $this->assertTrue($form->get('kind')->isDisabled(), 'As for a part from an info provider or a scan');
    }

    public function testABuildPartFormIgnoresARequestedKind(): void
    {
        $project = $this->em->find(\App\Entity\ProjectSystem\Project::class, 1);
        if ($project === null || $project->getBuildPart() !== null) {
            $project = (new \App\Entity\ProjectSystem\Project());
            $project->setName('Project');
            $this->em->persist($project);
            $this->em->flush();
        }

        $crawler = $this->client->request('GET', '/en/part/new_build_part/'.$project->getID().'?kind=meta');

        $this->assertResponseIsSuccessful();
        $this->assertNotNull($this->kindField($crawler)->attr('disabled'));
        $this->assertSame('manufacturing', $this->kindField($crawler)->filter('option[selected]')->attr('value'));
    }

    private function submitNew(string $url, string $kind, string $name): Part
    {
        $crawler = $this->client->request('GET', $url);
        $form = $crawler->filter('form[name="part_base"]')->form();
        $values = $form->getPhpValues();
        $values['part_base']['name'] = $name;
        $values['part_base']['kind'] = $kind;
        $values['part_base']['category'] = '1';
        $values['part_base']['save'] = '';
        $this->client->request('POST', $url, $values);
        $this->assertResponseRedirects();

        return $this->em->getRepository(Part::class)->findOneBy(['name' => $name]);
    }

    public function testTheSubmittedKindWinsOverTheUrl(): void
    {
        $this->assertSame(PartKind::MANUFACTURING, $this->submitNew('/en/part/new?kind=meta', 'manufacturing', 'Submitted manufacturing')->getKind());
    }

    public function testDataTheSubmittedKindDoesNotAllowIsDiscarded(): void
    {
        $part = $this->submitNew('/en/part/new?supplier=1&manufacturer=1', 'meta', 'Submitted meta');

        $this->assertSame(PartKind::META, $part->getKind());
        $this->assertCount(0, $part->getOrderdetails());
        $this->assertNull($part->getManufacturer());
    }

    public function testPresetsFollowTheKind(): void
    {
        $crawler = $this->client->request('GET', '/en/part/new?kind=meta&category=1&footprint=1&manufacturer=1&supplier=1&storelocation=1');
        $this->assertResponseIsSuccessful();

        $this->assertSame('1', $crawler->filter('select[name="part_base[category]"] option[selected]')->attr('value'));
        $this->assertSame('1', $crawler->filter('select[name="part_base[footprint]"] option[selected]')->attr('value'));
        $this->assertSame(0, $crawler->filter('#orderdetails_table tbody tr')->count(), 'No preset supplier');
        $this->assertGreaterThan(0, $crawler->filter('select[name^="part_base[partLots][0]"]')->count(), 'The location makes an own lot');
    }

    public function testEveryEntryOffersANewMetaPart(): void
    {
        $crawler = $this->client->request('GET', '/en/parts');
        $this->assertResponseIsSuccessful();

        $this->assertGreaterThan(0, $crawler->filter('nav a[href*="/part/new?kind=meta"]')->count(), 'Navigation bar');
        $this->assertGreaterThan(0, $crawler->filter('.btn-group a.dropdown-item[href*="kind=meta"]')->count(), 'Action bar');

        $tree = self::getContainer()->get(\App\Services\Trees\ToolsTreeBuilder::class);
        $this->assertStringContainsString('kind=meta', (string) json_encode($tree->getTree()));
    }

    public function testSaveAndNewKeepsTheKind(): void
    {
        $crawler = $this->client->request('GET', '/en/part/new?kind=meta');
        $values = $crawler->filter('form[name="part_base"]')->form()->getPhpValues();
        $values['part_base']['name'] = 'Save and new';
        $values['part_base']['kind'] = 'meta';
        $values['part_base']['category'] = '1';
        $values['part_base']['save_and_new'] = '';

        $this->client->request('POST', '/en/part/new?kind=meta', $values);

        $this->assertResponseRedirects('/en/part/new?kind=meta');
    }

    public function testTheEditPageShowsTheTabsOfTheKind(): void
    {
        $meta = $this->exampleMetaPart();

        $crawler = $this->client->request('GET', '/en/part/'.$meta->getID().'/edit');
        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $crawler->filter('a[href="#part_connections"]')->count());
        $this->assertSame(0, $crawler->filter('a[href="#manufacturer"]')->count());
        $this->assertSame(0, $crawler->filter('a[href="#orderdetails"]')->count());
        $this->assertSame(0, $crawler->filter('[name="part_base[gtin]"]')->count());

        $crawler = $this->client->request('GET', '/en/part/1/edit');
        $this->assertSame(0, $crawler->filter('a[href="#part_connections"]')->count());
        $this->assertSame(1, $crawler->filter('a[href="#manufacturer"]')->count());
        $this->assertSame(1, $crawler->filter('[name="part_base[gtin]"]')->count());
    }

    private function editValues(Part $meta): array
    {
        $crawler = $this->client->request('GET', '/en/part/'.$meta->getID().'/edit');
        $values = $crawler->filter('form[name="part_base"]')->form()->getPhpValues();
        $values['part_base']['save'] = '';

        return $values;
    }

    public function testConnectionsCanBeAddedChangedAndRemovedOnTheEditPage(): void
    {
        $meta = $this->part('Edit me', PartKind::META);
        $keep = $this->part('Keep');
        $remove = $this->part('Remove');
        $this->lot($remove, 7);
        $added = $this->part('Added');
        $this->connect($meta, $keep, 1);
        $this->connect($meta, $remove, 2);
        $this->em->flush();
        [$metaId, $removeId] = [$meta->getID(), $remove->getID()];
        $url = '/en/part/'.$metaId.'/edit';

        $values = $this->editValues($meta);
        $rows = $values['part_base']['part_connections'];
        foreach ($rows as $key => $row) {
            if ((string) $row['connected_part']['autocomplete'] === (string) $keep->getID()) {
                $values['part_base']['part_connections'][$key]['position'] = '5';
                $values['part_base']['part_connections'][$key]['status'] = 'do_not_use';
                $values['part_base']['part_connections'][$key]['note'] = 'Changed';
            } else {
                unset($values['part_base']['part_connections'][$key]);
            }
        }
        $values['part_base']['part_connections'][9] = ['connected_part' => ['autocomplete' => (string) $added->getID()], 'position' => '', 'status' => 'approved', 'note' => 'New'];

        $this->client->request('POST', $url, $values);
        $this->assertResponseRedirects();

        $this->em->clear();
        $connections = $this->em->find(Part::class, $metaId)->getOrderedConnections();
        $this->assertCount(2, $connections);
        $this->assertSame('Added', $connections[0]->getConnectedPart()->getName());
        $this->assertSame('New', $connections[0]->getNote());
        $this->assertSame(6, $connections[0]->getPosition(), 'A new one with no position goes last');
        $this->assertSame('Keep', $connections[1]->getConnectedPart()->getName());
        $this->assertSame('Changed', $connections[1]->getNote());
        $this->assertFalse($connections[1]->isUsable());
        $this->assertSame(7.0, $this->em->find(Part::class, $removeId)->getPartLots()->first()->getAmount(), 'Removal leaves the part and its stock');
    }

    public function testAViolationOnAMetaPartShowsOnThePage(): void
    {
        $meta = $this->part('Duplicate', PartKind::META);
        $part = $this->part('Connected');
        $this->connect($meta, $part, 1);
        $this->em->flush();

        $values = $this->editValues($meta);
        $values['part_base']['part_connections'][9] = ['connected_part' => ['autocomplete' => (string) $part->getID()], 'position' => '', 'status' => 'approved', 'note' => ''];
        $crawler = $this->client->request('POST', '/en/part/'.$meta->getID().'/edit', $values);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('already connected', $crawler->filter('#part_connections')->text());
    }

    public function testADifferentFootprintWarnsButSaves(): void
    {
        $footprintA = $this->em->find(Footprint::class, 1);
        $footprintB = $this->em->find(Footprint::class, 2);
        $meta = $this->part('With footprint', PartKind::META);
        $meta->setFootprint($footprintA);
        $part = $this->part('Other footprint');
        $part->setFootprint($footprintB);
        $this->connect($meta, $part, 1);
        $this->em->flush();

        $this->client->request('POST', '/en/part/'.$meta->getID().'/edit', $this->editValues($meta));
        $this->assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        $this->assertStringContainsString('another footprint', $crawler->filter('body')->html());
    }

    public function testTheConnectionPickerAsksOnlyForManufacturingPartsAndTheMergePickerForTheSameKind(): void
    {
        $meta = $this->exampleMetaPart();

        $crawler = $this->client->request('GET', '/en/part/'.$meta->getID().'/edit');
        $autocomplete = (string) $crawler->filter('#connection_table [data-autocomplete]')->first()->attr('data-autocomplete');
        $this->assertStringContainsString('kind=manufacturing', $autocomplete);

        $crawler = $this->info($meta);
        $this->assertStringContainsString('kind=meta', (string) $crawler->filter('#merge-modal [data-autocomplete], select[data-autocomplete*="typeahead"]')->first()->attr('data-autocomplete'));
    }

    public function testAPartListWithMetaPartsLoadsWithABoundedNumberOfQueries(): void
    {
        $count = function (int $metaParts): int {
            for ($i = 0; $i < $metaParts; $i++) {
                $meta = $this->part('List meta '.$metaParts.'-'.$i, PartKind::META);
                $connected = $this->part('List part '.$metaParts.'-'.$i);
                $this->lot($connected, 3);
                $this->connect($meta, $connected, 1);
            }
            $this->em->flush();
            $this->em->clear();

            $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['name' => 'admin']));
            $this->client->enableProfiler();
            $this->client->request('POST', '/en/parts', ['_dt' => 'dt', 'draw' => 1, 'start' => 0, 'length' => 500, 'search' => ['value' => '', 'regex' => false]]);
            self::assertResponseIsSuccessful();
            $rows = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
            $this->assertGreaterThanOrEqual(2 * $metaParts, count($rows), 'The list must really show the parts, or the count says nothing');

            return $this->client->getProfile()->getCollector('db')->getQueryCount();
        };

        $this->client->disableReboot();
        //The first request also reads the schema, which has nothing to do with the rows
        $count(0);
        $few = $count(10);
        $many = $count(100);

        $this->assertSame($few, $many, 'The number of queries must not depend on the number of rows');
    }

    public function testTheKindFilterIsTwoCheckboxesInTheCommonTab(): void
    {
        $crawler = $this->client->request('GET', '/en/parts');

        $boxes = $crawler->filter('#filter-common input[type="checkbox"][name="part_filter[kind][value][]"]');
        $this->assertSame(['meta', 'manufacturing'], $boxes->each(static fn (Crawler $box) => $box->attr('value')));
        $this->assertCount(2, $crawler->filter('#filter-common input[name="part_filter[kind][value][]"][checked]'), 'Both are set until the user changes that');
        $this->assertCount(1, $crawler->filter('#filter-common [data-controller="filters--part-kind-constraint"]'), 'This keeps at least one of them set');
        $this->assertStringNotContainsString('Kind of part', $crawler->filter('#filter-common')->text());
    }

    public function testTheKindCheckboxesFilterTheListAndStayTicked(): void
    {
        $this->part('Checkbox meta', PartKind::META);
        $this->part('Checkbox part');
        $this->em->flush();

        $list = function (string $query): string {
            $this->client->request('POST', '/en/parts?'.$query, ['_dt' => 'dt', 'draw' => 1, 'start' => 0, 'length' => 500, 'search' => ['value' => '', 'regex' => false]]);
            self::assertResponseIsSuccessful();

            return (string) $this->client->getResponse()->getContent();
        };
        $meta = 'part_filter[kind][value][]=meta';
        $manufacturing = 'part_filter[kind][value][]=manufacturing';

        $this->assertStringContainsString('Checkbox meta', $list($meta));
        $this->assertStringNotContainsString('Checkbox part', $list($meta));
        $this->assertStringContainsString('Checkbox part', $list($manufacturing));
        $this->assertStringNotContainsString('Checkbox meta', $list($manufacturing));
        foreach ([$list($meta.'&'.$manufacturing), $list('')] as $all) {
            $this->assertStringContainsString('Checkbox meta', $all);
            $this->assertStringContainsString('Checkbox part', $all);
        }

        $crawler = $this->client->request('GET', '/en/parts?'.$meta);
        $this->assertSame(1, $crawler->filter('#filter-common input[value="meta"][checked]')->count());
        $this->assertSame(0, $crawler->filter('#filter-common input[value="manufacturing"][checked]')->count());
    }

    public function testTheNewPartFormGivesTheIpnPatternOfEachKind(): void
    {
        $settings = self::getContainer()->get(IpnSuggestSettings::class);
        $settings->regex = '^ALL-\d+$';
        $settings->regexHelp = 'All parts';
        $settings->metaRegex = '^META-\d+$';
        $settings->metaRegexHelp = 'Meta parts';
        $this->client->disableReboot();

        $input = $this->client->request('GET', '/en/part/new')->filter('input[name="part_base[ipn]"]');
        $this->assertSame('^ALL-\d+$', $input->attr('pattern'));
        $this->assertSame([
            'manufacturing' => ['pattern' => '^ALL-\d+$', 'help' => 'All parts'],
            'meta' => ['pattern' => '^META-\d+$', 'help' => 'Meta parts'],
        ], json_decode((string) $input->attr('data-ipn-by-kind'), true));

        $input = $this->client->request('GET', '/en/part/new?kind=meta')->filter('input[name="part_base[ipn]"]');
        $this->assertSame('^META-\d+$', $input->attr('pattern'));

        //The kind of a saved part is fixed, so it only needs its own
        $meta = $this->part('Saved for its pattern', PartKind::META);
        $this->em->flush();
        $input = $this->client->request('GET', '/en/part/'.$meta->getID().'/edit')->filter('input[name="part_base[ipn]"]');
        $this->assertSame('^META-\d+$', $input->attr('pattern'));
        $this->assertNull($input->attr('data-ipn-by-kind'));
    }

    public function testTheImportPageSaysConnectionsAreNotImported(): void
    {
        $this->client->request('GET', '/en/parts/import');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-body', 'they are not imported');
    }

    public function testTheImportPageNamesAnUnknownKindAndWritesNothing(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kind').'.csv';
        file_put_contents($file, "name;kind\nGood row of a refused file;meta\nBad row;sorcery\n");

        $crawler = $this->client->request('GET', '/en/parts/import');
        $form = $crawler->filter('input[type="file"]')->closest('form')->form();
        $form[$crawler->filter('input[type="file"]')->attr('name')]->upload($file);
        $this->client->submit($form);
        unlink($file);

        self::assertResponseIsSuccessful();
        $content = html_entity_decode((string) $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Unknown kind of part "sorcery"', $content);
        $this->assertNull($this->em->getRepository(Part::class)->findOneBy(['name' => 'Good row of a refused file']));
    }

    public function testKindTextsNameThePartByItsSynonymTokenOnAPage(): void
    {
        $crawler = $this->client->request('GET', '/en/part/new');

        $labels = $crawler->filter('select[name="part_base[kind]"] option')->each(static fn (Crawler $option): string => $option->text());
        $this->assertSame(['Manufacturing part', 'Meta part'], $labels);
        $this->assertStringNotContainsString('[part]', (string) $this->client->getResponse()->getContent());
    }
}
