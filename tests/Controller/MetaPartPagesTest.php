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

declare(strict_types=1);namespace App\Tests\Controller;

use App\Settings\MiscSettings\IpnSuggestSettings;
use App\Entity\Attachments\AttachmentType;
use App\Entity\Attachments\PartAttachment;
use App\Entity\Parts\Category;
use App\Entity\Parts\Footprint;
use App\Entity\Parts\Manufacturer;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartConnectionStatus;
use App\Entity\Parts\PartKind;
use App\Entity\Parts\PartLot;
use App\Entity\Parts\PartPriceMethod;
use App\Entity\Parts\Supplier;
use App\Entity\PriceInformations\Orderdetail;
use App\Entity\PriceInformations\Pricedetail;
use App\Entity\UserSystem\User;
use App\Tests\Security\PartEditDenyingDecisionManager;
use App\Form\Part\PartBaseType;
use App\Settings\MiscSettings\MetaPartSettings;
use App\Settings\BehaviorSettings\PartDeletionSettings;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
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
    /** The edit page that editValues() read last */
    private Crawler $editPage;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['name' => 'admin']));
    }

    protected function tearDown(): void
    {
        PartEditDenyingDecisionManager::$deniedPartIds = [];
        parent::tearDown();
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

    public function testTheSummaryShowsThePriceByTheMethodAndNamesIt(): void
    {
        $meta = $this->exampleMetaPart();
        $line = $this->info($meta)->filter('.fa-money-bill-alt')->ancestors()->first()->ancestors()->first()->text();
        $this->assertMatchesRegularExpression('/2\.00.*\(System default: Primary source\)/s', $line, 'The first usable connection by priority');
        $this->assertStringNotContainsString('0.50', $line, 'Not the do-not-use connection');

        $meta->setPriceMethod(PartPriceMethod::CHEAPEST);
        $this->em->flush();
        $line = $this->info($meta)->filter('.fa-money-bill-alt')->ancestors()->first()->ancestors()->first()->text();
        $this->assertMatchesRegularExpression('/1\.00.*\(Cheapest\)/s', $line);
        $this->assertStringNotContainsString('2.00', $line, 'One value, not a range');
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
        $this->assertStringContainsString('Connected parts', $crawler->filter('a[href="#connections"]')->text());
        $rows = $crawler->filter('#connections tbody tr')->each(static fn (Crawler $r) => $r->text());
        $this->assertCount(3, $rows);
        $this->assertStringContainsString('First source', $rows[0]);
        $this->assertStringContainsString('Preferred', $rows[0]);
        $this->assertStringContainsString('Second source', $rows[1]);
        $this->assertStringContainsString('Retired source', $rows[2]);
        $this->assertStringContainsString('Do not use', $rows[2]);
        $this->assertStringContainsString('Bad batch', $rows[2]);
    }

    public function testTheConnectedPartsTabShowsManufacturerMpnAndNameInSeparateColumns(): void
    {
        $meta = $this->part('Column meta', PartKind::META);
        $part = $this->part('RES SMD 10K 1% 0603');
        $part->setManufacturerProductNumber('RC0603FR-1310KL');
        $this->connect($meta, $part, 1);
        $this->em->flush();

        $crawler = $this->info($meta);
        $heads = $crawler->filter('#connections thead th')->each(static fn (Crawler $th) => trim($th->text()));
        //The owner's order: manufacturer, MPN, then the part's name
        $manufacturerColumn = array_search('Manufacturer', $heads, true);
        $mpnColumn = array_search('MPN', $heads, true);
        $partColumn = array_search('Part', $heads, true);
        $this->assertNotFalse($manufacturerColumn, implode(' | ', $heads));
        $this->assertSame($manufacturerColumn + 1, $mpnColumn, implode(' | ', $heads));
        $this->assertSame($mpnColumn + 1, $partColumn, implode(' | ', $heads));

        $cells = $crawler->filter('#connections tbody tr')->first()->filter('td')->each(static fn (Crawler $td) => trim($td->text()));
        $this->assertStringContainsString('RES SMD 10K 1% 0603', $cells[$partColumn]);
        $this->assertStringNotContainsString('RC0603FR-1310KL', $cells[$partColumn]);
        $this->assertSame('RC0603FR-1310KL', $cells[$mpnColumn]);
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

    public function testTheConnectionsTabShowsEachStatusWithItsBadgeAndTheUnusableLast(): void
    {
        $crawler = $this->info($this->metaPartWithEachStatus());

        $rows = $crawler->filter('#connections tbody tr');
        $this->assertCount(4, $rows);
        $this->assertStringContainsString('Part use_up', $rows->eq(0)->text());
        $this->assertStringContainsString('Part approved', $rows->eq(1)->text());
        $this->assertStringContainsString('Part do_not_use', $rows->eq(2)->text());
        $this->assertStringContainsString('table-danger', (string) $rows->eq(2)->attr('class'));
        $this->assertStringContainsString('Part under_evaluation', $rows->eq(3)->text());
        $this->assertStringContainsString('text-muted', (string) $rows->eq(3)->attr('class'));

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

    public function testTheConnectedPartsTabListsAllInOneSortableTableWithoutAHeadingRow(): void
    {
        $crawler = $this->info($this->metaPartWithEachStatus());
        $table = $crawler->filter('#connections table');

        $this->assertCount(1, $table);
        $this->assertSame('elements--sortable-table', $table->attr('data-controller'));
        $this->assertCount(1, $table->filter('tbody'));
        $this->assertCount(4, $table->filter('tbody tr'), 'Usable and unusable connections in one tbody');
        $this->assertCount(0, $table->filter('tbody tr.table-light'));
        $this->assertCount(0, $table->filter('tbody th'));
        $this->assertStringNotContainsString('Not usable', $table->text());

        $heads = $table->filter('thead th')->each(static fn (Crawler $th) => trim($th->text()));
        $columns = [];
        foreach (['Priority', 'Status', 'Amount', 'Suppliers'] as $label) {
            $columns[$label] = array_search($label, $heads, true);
            $this->assertIsInt($columns[$label], $label.' in '.implode(' | ', $heads));
        }
        //A sort value where the shown text is no plain number: priority (the position), status rank, raw amount, count of suppliers
        $expected = [
            'Part use_up' => ['3', '1', '10', '1'],
            'Part approved' => ['4', '0', '10', '1'],
            'Part do_not_use' => ['1', '3', '10', '1'],
            'Part under_evaluation' => ['2', '2', '10', '1'],
        ];
        foreach ($table->filter('tbody tr') as $i => $node) {
            $row = new Crawler($node);
            $cells = $row->filter('td');
            $values = array_values(array_map(static fn (int $column): ?string => $cells->eq($column)->attr('data-sort-value'), $columns));
            $name = array_keys($expected)[$i];
            $this->assertStringContainsString($name, $row->text());
            $this->assertSame($expected[$name], $values, $name);
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

    public function testTheShoppingTabCountsTheOrderDetailsOfTheConnectedParts(): void
    {
        $meta = $this->part('Counted suppliers', PartKind::META);
        $connected = $this->part('Two suppliers');
        $this->price($connected, '1');
        $this->price($connected, '2');
        $this->connect($meta, $connected, 1);
        $this->em->flush();

        $this->assertSame('2', trim($this->info($meta)->filter('#supplier-tab .badge')->text()));
    }

    public function testAMetaPartWithNoSuppliersHasNoShoppingTab(): void
    {
        $meta = $this->part('No suppliers', PartKind::META);
        $this->connect($meta, $this->part('Plain'), 1);
        $this->em->flush();

        $this->assertNotContains('#suppliers', $this->tabIds($this->info($meta)));
    }

    public function testTheConnectionsTabComesRightAfterTheAssociatedPartsOnBothKinds(): void
    {
        $meta = $this->exampleMetaPart();
        $part = $meta->getOrderedConnections()[0]->getConnectedPart();

        foreach ([$meta, $part] as $shown) {
            $tabs = $this->tabIds($this->info($shown));
            $connections = array_search('#connections', $tabs, true);
            $this->assertNotFalse($connections, implode(' ', $tabs));
            //Of the tabs before it, the nearest one shown follows the template's order: associated parts if shown, else shopping information
            $before = array_slice($tabs, 0, $connections);
            $this->assertNotContains('#history', $before, implode(' ', $tabs));
            $this->assertNotContains('#projects', $before, implode(' ', $tabs));
            $this->assertContains('#part_lots', $before, implode(' ', $tabs));
            if (in_array('#associations', $tabs, true)) {
                $this->assertSame('#associations', end($before), implode(' ', $tabs));
            }
        }
    }

    public function testAConnectedPartListsItsMetaParts(): void
    {
        $meta = $this->exampleMetaPart();
        $part = $meta->getOrderedConnections()[0]->getConnectedPart();

        $crawler = $this->info($part);

        $this->assertContains('#connections', $this->tabIds($crawler));
        $this->assertStringContainsString('Example meta', $crawler->filter('#connections')->text());
        $this->assertStringContainsString('Meta parts', $crawler->filter('a[href="#connections"]')->text());
        $this->assertStringNotContainsString('Connected parts', $crawler->filter('a[href="#connections"]')->text());
        $this->assertCount(0, $crawler->filter('#connections caption'), 'The tab has no caption above its table');
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

    /** Takes the given operation on parts away from the admin, who is logged in again. */
    private function denyAdmin(string $operation): void
    {
        $admin = $this->em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->getPermissions()->setPermissionValue('parts', $operation, false);
        $this->em->flush();
        $this->client->loginUser($admin);
    }

    /** A saved manufacturing part with a lot, a supplier and a BOM entry, and a meta part with no connected parts. */
    private function partsToConvert(): array
    {
        $part = $this->part('Part to convert');
        $part->setManufacturerProductNumber('MPN-CONVERT');
        $this->lot($part, 12);
        $this->price($part, '0.1');
        $project = new Project();
        $project->setName('Board of the part to convert');
        $entry = (new ProjectBOMEntry())->setQuantity(1.0);
        $project->addBomEntry($entry);
        $part->addProjectBomEntry($entry);
        $this->em->persist($project);
        $meta = $this->part('Meta part to convert', PartKind::META);
        $this->lot($meta, 7);
        $this->em->flush();
        $ids = [$part->getID(), $meta->getID()];
        $this->em->clear();
        $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['name' => 'admin']));

        return array_map(fn (int $id): Part => $this->em->find(Part::class, $id), $ids);
    }

    public function testTheToolsTabOffersOnlyTheConversionTheKindAllows(): void
    {
        [$part, $meta] = $this->partsToConvert();

        $crawler = $this->info($part);
        $this->assertSame('/en/part/'.$part->getID().'/convert/to_meta', $crawler->filter('#tools a#part-convert-to-meta')->attr('href'));
        $this->assertSame(0, $crawler->filter('#part-convert-to-manufacturing')->count());

        $crawler = $this->info($meta);
        $this->assertSame(0, $crawler->filter('#part-convert-to-meta')->count());
        $this->assertSame('/en/part/'.$meta->getID().'/convert/to_manufacturing', $crawler->filter('#tools a#part-convert-to-manufacturing')->attr('href'));
        $this->assertSame(0, $crawler->filter('#part-convert-blocked')->count());
    }

    public function testWithoutCreateThereIsNoConversionToAMetaPartButStillTheWayBack(): void
    {
        [$part, $meta] = $this->partsToConvert();
        $this->denyAdmin('create');

        $this->assertSame(0, $this->info($part)->filter('#part-convert-to-meta')->count());
        $this->assertSame(1, $this->info($meta)->filter('a#part-convert-to-manufacturing')->count());
        $this->client->request('GET', '/en/part/'.$part->getID().'/convert/to_meta');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testWithoutEditThereIsNoConversion(): void
    {
        [$part, $meta] = $this->partsToConvert();
        $this->denyAdmin('edit');

        $this->assertSame(0, $this->info($part)->filter('#part-convert-to-meta, #part-convert-to-manufacturing')->count());
        $this->assertSame(0, $this->info($meta)->filter('#part-convert-to-meta, #part-convert-to-manufacturing')->count());
        $this->client->request('GET', '/en/part/'.$meta->getID().'/convert/to_manufacturing');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAMetaPartWithConnectionsShowsTheWayBackDisabledWithTheConnections(): void
    {
        $crawler = $this->info($this->exampleMetaPart());

        $this->assertNotNull($crawler->filter('#tools button#part-convert-to-manufacturing')->attr('disabled'));
        $blocked = $crawler->filter('#part-convert-blocked');
        $this->assertSame(['First source', 'Second source', 'Retired source'], $blocked->filter('a')->each(static fn (Crawler $a) => $a->text()));
    }

    public function testThePreviewShowsBothChoicesAndChangesNothing(): void
    {
        [$part] = $this->partsToConvert();
        $id = $part->getID();
        $parts = $this->em->getRepository(Part::class)->count([]);

        $crawler = $this->client->request('GET', '/en/part/'.$id.'/convert/to_meta');

        $this->assertResponseIsSuccessful();
        $this->assertSame(['manufacturing', 'meta'], $crawler->filter('input[name="keep"]')->each(static fn (Crawler $i) => $i->attr('value')));
        $this->assertSame('manufacturing', $crawler->filter('input[name="keep"][checked]')->attr('value'));
        $this->assertSame(['manufacturing', 'meta'], $crawler->filter('.part-convert-preview')->each(static fn (Crawler $t) => $t->attr('data-keep')));
        $lots = $crawler->filter('.part-convert-preview[data-keep="meta"] tr[data-row="lots"]');
        $this->assertSame('moved', $lots->attr('data-action'));
        $this->assertSame('1', $lots->filter('td')->last()->text());
        $this->assertSame('kept', $crawler->filter('.part-convert-preview[data-keep="manufacturing"] tr[data-row="lots"]')->attr('data-action'));
        $this->assertSame('1', $crawler->filter('.part-convert-preview[data-keep="meta"] tr[data-row="bom_entries"] td')->last()->text());

        $this->em->clear();
        $this->assertSame(PartKind::MANUFACTURING, $this->em->find(Part::class, $id)->getKind());
        $this->assertSame($parts, $this->em->getRepository(Part::class)->count([]));
    }

    public function testAConversionWithoutAValidTokenIsRefused(): void
    {
        [$part] = $this->partsToConvert();
        $id = $part->getID();

        $this->client->request('POST', '/en/part/'.$id.'/convert/to_meta', ['_token' => 'wrong', 'keep' => 'meta']);

        $this->assertResponseRedirects('/en/part/'.$id.'/convert/to_meta');
        $this->em->clear();
        $this->assertSame(PartKind::MANUFACTURING, $this->em->find(Part::class, $id)->getKind());
    }

    public function testTheConfirmingSubmitConvertsAndReturnsToThePartKeepingTheId(): void
    {
        [$part] = $this->partsToConvert();
        $id = $part->getID();
        $form = $this->client->request('GET', '/en/part/'.$id.'/convert/to_meta')->filter('#part-convert-form')->form();
        $form['keep']->select('meta');

        $this->client->submit($form);

        $this->assertResponseRedirects('/en/part/'.$id.'/info');
        $text = $this->client->followRedirect()->filter('body')->text();
        $this->assertStringContainsString('this part is now a meta part', $text);
        $this->em->clear();
        $meta = $this->em->find(Part::class, $id);
        $this->assertSame(PartKind::META, $meta->getKind());
        $this->assertSame('MPN-CONVERT', $meta->getPartConnections()->first()->getConnectedPart()->getManufacturerProductNumber());
    }

    public function testKeepingTheManufacturingPartReturnsToItAndNamesTheNewMetaPart(): void
    {
        [$part] = $this->partsToConvert();
        $id = $part->getID();

        $this->client->submit($this->client->request('GET', '/en/part/'.$id.'/convert/to_meta')->filter('#part-convert-form')->form());

        $this->assertResponseRedirects('/en/part/'.$id.'/info');
        $this->assertStringContainsString('the new meta part Part to convert (ID ', $this->client->followRedirect()->filter('body')->text());
        $this->em->clear();
        $this->assertSame(PartKind::MANUFACTURING, $this->em->find(Part::class, $id)->getKind());
        $this->assertCount(1, $this->em->find(Part::class, $id)->getConnectionsAsPart());
    }

    public function testTheWayBackShowsWhatChangesAndConverts(): void
    {
        [, $meta] = $this->partsToConvert();
        $id = $meta->getID();
        $crawler = $this->client->request('GET', '/en/part/'.$id.'/convert/to_manufacturing');
        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $crawler->filter('input[name="keep"]')->count());
        $this->assertSame('reset', $crawler->filter('tr[data-row="price_method"]')->attr('data-action'));
        $this->assertNull($crawler->filter('#part-convert-submit')->attr('disabled'));

        $this->client->submit($crawler->filter('#part-convert-form')->form());

        $this->assertResponseRedirects('/en/part/'.$id.'/info');
        $this->em->clear();
        $this->assertSame(PartKind::MANUFACTURING, $this->em->find(Part::class, $id)->getKind());
        $this->assertCount(1, $this->em->find(Part::class, $id)->getPartLots());
    }

    public function testTheWayBackWithConnectionsIsRefusedNamingThem(): void
    {
        $meta = $this->exampleMetaPart();
        $id = $meta->getID();
        $crawler = $this->client->request('GET', '/en/part/'.$id.'/convert/to_manufacturing');
        $this->assertNotNull($crawler->filter('#part-convert-submit')->attr('disabled'));
        $this->assertSame(3, $crawler->filter('#part-convert-blocked a')->count());
        $token = $crawler->filter('#part-convert-form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/en/part/'.$id.'/convert/to_manufacturing', ['_token' => $token]);

        $this->assertResponseRedirects('/en/part/'.$id.'/info');
        $this->assertStringContainsString('First source, Second source, Retired source', $this->client->followRedirect()->filter('body')->text());
        $this->em->clear();
        $this->assertSame(PartKind::META, $this->em->find(Part::class, $id)->getKind());
    }

    public function testAMetaPartCanNotBeConvertedToAMetaPart(): void
    {
        $meta = $this->exampleMetaPart();

        $this->client->request('GET', '/en/part/'.$meta->getID().'/convert/to_meta');

        $this->assertResponseRedirects('/en/part/'.$meta->getID().'/info');
        $this->assertStringContainsString('already a meta part', $this->client->followRedirect()->filter('body')->text());
    }

    public function testDeletingAConnectedPartNamesTheMetaParts(): void
    {
        $part = $this->exampleMetaPart()->getOrderedConnections()[0]->getConnectedPart();

        $message = $this->info($part)->filter('#tools form[data-delete-message]')->attr('data-delete-message');

        $this->assertStringContainsString('Example meta', (string) $message);
    }

    /** A meta part in the BOM of two projects, and a part connected to it that no BOM names, with deleting refused or not. */
    private function metaPartInTwoBoms(bool $refuse): Part
    {
        $this->client->disableReboot();
        self::getContainer()->get(PartDeletionSettings::class)->refuseDeletingPartsUsedInBom = $refuse;
        $meta = $this->part('Meta in two BOMs', PartKind::META);
        $this->connect($meta, $this->part('Connected, in no BOM'), 1);
        foreach (['Second <b>board</b>', 'First board'] as $name) {
            $project = new Project();
            $project->setName($name);
            $entry = (new ProjectBOMEntry())->setQuantity(1.0);
            $project->addBomEntry($entry);
            $meta->addProjectBomEntry($entry);
            $this->em->persist($project);
        }
        $this->em->flush();
        $id = $meta->getID();
        $this->em->clear();
        $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['name' => 'admin']));

        return $this->em->find(Part::class, $id);
    }

    public function testWithTheSettingOnTheDeleteButtonIsDisabledAndNamesTheProjects(): void
    {
        $crawler = $this->info($this->metaPartInTwoBoms(true));

        $this->assertNotNull($crawler->filter('#tools form[data-delete-message] button.btn-danger')->first()->attr('disabled'));
        $hint = $crawler->filter('#part-delete-refused');
        $this->assertSame(2, $hint->filter('a')->count());
        $this->assertStringStartsWith('Used in the BOM of First board, Second <b>board</b>;', trim($hint->text()));
    }

    private function denyAdminProjectsRead(): void
    {
        $admin = $this->em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->getPermissions()->setPermissionValue('projects', 'read', false);
        $this->em->flush();
        $this->client->loginUser($admin);
    }

    public function testAUserWhoMayNotReadProjectsIsToldOnlyHowManyProjectsUseThePart(): void
    {
        $meta = $this->metaPartInTwoBoms(true);
        $this->denyAdminProjectsRead();

        $hint = $this->info($meta)->filter('#part-delete-refused');

        $this->assertSame(0, $hint->filter('a')->count());
        $this->assertStringStartsWith('Used in the BOM of 2 projects;', trim($hint->text()));
        $this->assertStringNotContainsString('board', $hint->text());
    }

    public function testWithTheSettingOffTheDeleteButtonIsAsToday(): void
    {
        $crawler = $this->info($this->metaPartInTwoBoms(false));

        $this->assertNull($crawler->filter('#tools form[data-delete-message] button.btn-danger')->first()->attr('disabled'));
        $this->assertSame(0, $crawler->filter('#part-delete-refused')->count());
    }

    public function testAPartUsedOnlyThroughAMetaPartCanStillBeDeleted(): void
    {
        $part = $this->metaPartInTwoBoms(true)->getOrderedConnections()[0]->getConnectedPart();
        $crawler = $this->info($part);

        $this->assertNull($crawler->filter('#tools form[data-delete-message] button.btn-danger')->first()->attr('disabled'));
        $this->assertStringContainsString('Meta in two BOMs', (string) $crawler->filter('#tools form[data-delete-message]')->attr('data-delete-message'));
    }

    public function testARefusedDeleteReturnsToThePartWithAnError(): void
    {
        $meta = $this->metaPartInTwoBoms(true);
        $id = $meta->getID();
        $token = $this->info($meta)->filter('#tools form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/en/part/'.$id.'/delete', ['_method' => 'DELETE', '_token' => $token]);

        $this->assertResponseRedirects('/en/part/'.$id.'/info');
        $text = $this->client->followRedirect()->filter('body')->text();
        $this->assertStringContainsString('First board, Second <b>board</b>', $text);
        $this->em->clear();
        $this->assertNotNull($this->em->find(Part::class, $id));
    }

    public function testARefusedDeleteGivesOnlyTheNumberOfProjectsToAUserWhoMayNotReadProjects(): void
    {
        $meta = $this->metaPartInTwoBoms(true);
        $id = $meta->getID();
        $this->denyAdminProjectsRead();
        $token = $this->info($meta)->filter('#tools form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/en/part/'.$id.'/delete', ['_method' => 'DELETE', '_token' => $token]);

        $this->assertResponseRedirects('/en/part/'.$id.'/info');
        $flash = $this->client->followRedirect()->filter('.alert-danger, .toast')->text();
        $this->assertStringContainsString('used in the BOM of: 2 projects.', $flash);
        $this->assertStringNotContainsString('First board', $flash);
        $this->em->clear();
        $this->assertNotNull($this->em->find(Part::class, $id));
    }

    /**
     * A part used directly in one project and through a meta part in another, by a connection of the given status.
     * @return array{Part, Part} The part and the meta part
     */
    private function partUsedBothWays(PartConnectionStatus $status): array
    {
        $part = $this->part('Used both ways');
        $meta = $this->part('Meta <i>above</i>', PartKind::META);
        $this->connect($meta, $part, 1, $status);
        foreach (['Direct board' => $part, 'Board through meta' => $meta] as $name => $user) {
            $project = new Project();
            $project->setName($name);
            $entry = (new ProjectBOMEntry())->setQuantity(3.0);
            $project->addBomEntry($entry);
            $user->addProjectBomEntry($entry);
            $this->em->persist($project);
        }
        $this->em->flush();
        $ids = [$part->getID(), $meta->getID()];
        $this->em->clear();
        $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['name' => 'admin']));

        return array_map(fn (int $id): Part => $this->em->find(Part::class, $id), $ids);
    }

    public function testTheProjectsTabNamesTheMetaPartAPartIsUsedThroughLinked(): void
    {
        [$part, $meta] = $this->partUsedBothWays(PartConnectionStatus::APPROVED);
        $crawler = $this->info($part);

        $this->assertSame('Through meta part', trim($crawler->filter('#projects thead th')->last()->text()));
        $rows = $crawler->filter('#projects tbody tr');
        $this->assertCount(2, $rows);
        $this->assertSame('', trim($rows->eq(0)->filter('td')->last()->text()));
        $link = $rows->eq(1)->filter('td')->last()->filter('a');
        $this->assertSame('Meta <i>above</i>', $link->text());
        $this->assertStringEndsWith('/part/'.$meta->getID().'/info', (string) $link->attr('href'));
        $this->assertSame(0, $rows->eq(1)->filter('[data-connection-status]')->count());
        $this->assertSame('2', trim($crawler->filter('#projects-tab .badge')->text()));
    }

    public function testTheProjectsTabListsUseThroughAnUnusableConnectionMarked(): void
    {
        $crawler = $this->info($this->partUsedBothWays(PartConnectionStatus::DO_NOT_USE)[0]);

        $row = $crawler->filter('#projects tbody tr')->eq(1);
        $this->assertStringContainsString('table-danger', (string) $row->attr('class'));
        $this->assertSame('do_not_use', $row->filter('[data-connection-status]')->attr('data-connection-status'));
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

    public function testABuildPartFormLetsTheKindBeChosen(): void
    {
        $project = (new \App\Entity\ProjectSystem\Project());
        $project->setName('Project with a meta build part');
        $this->em->persist($project);
        $this->em->flush();
        $url = '/en/part/new_build_part/'.$project->getID();

        $crawler = $this->client->request('GET', $url.'?kind=meta');
        $this->assertResponseIsSuccessful();
        $this->assertNull($this->kindField($crawler)->attr('disabled'));
        $this->assertSame('meta', $this->kindField($crawler)->filter('option[selected]')->attr('value'));

        $part = $this->submitNew($url, 'meta', 'Meta build part');
        $this->assertSame(PartKind::META, $part->getKind());
        $this->assertSame($project->getID(), $part->getBuiltProject()?->getID());
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

    public function testOnTheEditPageTheConnectionsTabComesRightAfterTheAssociatedPartsOnBothKinds(): void
    {
        $meta = $this->exampleMetaPart();
        $part = $meta->getOrderedConnections()[0]->getConnectedPart();

        foreach (['#part_connections' => $meta, '#connections_as_part' => $part] as $tab => $shown) {
            $crawler = $this->client->request('GET', '/en/part/'.$shown->getID().'/edit');
            $this->assertResponseIsSuccessful();
            $tabs = $crawler->filter('ul.nav-tabs a.nav-link')->each(static fn (Crawler $a) => (string) $a->attr('href'));
            $associations = array_search('#associations', $tabs, true);
            $this->assertNotFalse($associations, implode(' ', $tabs));
            $this->assertSame($tab, $tabs[$associations + 1] ?? null, implode(' ', $tabs));
        }
    }

    public function testTheFormsOfAMetaPartOfferTheSixPriceMethodsWithSystemDefaultChosen(): void
    {
        foreach (['/en/part/new?kind=meta', '/en/part/'.$this->exampleMetaPart()->getID().'/edit'] as $url) {
            $crawler = $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful();
            $options = $crawler->filter('select[name="part_base[priceMethod]"] option');

            $this->assertSame(['system_default', 'primary_source', 'cheapest', 'average', 'weighted_by_stock', 'user_value'], $options->each(static fn (Crawler $o) => $o->attr('value')), $url);
            $this->assertSame('system_default', $crawler->filter('select[name="part_base[priceMethod]"] option[selected]')->attr('value'), $url);
            $this->assertSame('System default (currently: Primary source)', trim($options->first()->text()), $url);
            $this->assertSame(1, $crawler->filter('[name="part_base[priceUserValue]"]')->count(), $url);
            $this->assertSame(1, $crawler->filter('[name="part_base[priceUserCurrency]"]')->count(), $url);
        }

        //The label follows the setting
        $this->client->disableReboot();
        $settings = self::getContainer()->get(MetaPartSettings::class);
        $settings->defaultPriceMethod = PartPriceMethod::AVERAGE;
        try {
            $crawler = $this->client->request('GET', '/en/part/new?kind=meta');
            $this->assertSame('System default (currently: Average)', trim($crawler->filter('select[name="part_base[priceMethod]"] option')->first()->text()));
        } finally {
            $settings->defaultPriceMethod = PartPriceMethod::PRIMARY_SOURCE;
        }
    }

    public function testAManufacturingPartFormHasNoPriceFields(): void
    {
        $crawler = $this->client->request('GET', '/en/part/1/edit');

        foreach (['priceMethod', 'priceUserValue', 'priceUserCurrency'] as $field) {
            $this->assertSame(0, $crawler->filter('[name="part_base['.$field.']"]')->count(), $field);
        }
        $this->assertSame(0, $crawler->filter('#meta_part_price')->count());
    }

    public function testTheUserValueIsShownOnlyForTheMethodUserValueAndTheCalculatedPriceIsText(): void
    {
        $meta = $this->exampleMetaPart();
        $url = '/en/part/'.$meta->getID().'/edit';

        //A user value with the method user value
        $values = $this->editValues($meta);
        $values['part_base']['priceMethod'] = 'user_value';
        $values['part_base']['priceUserValue'] = '0.75';
        $this->client->request('POST', $url, $values);
        $this->assertResponseRedirects();
        $crawler = $this->client->request('GET', $url);
        $this->assertStringNotContainsString('d-none', (string) $crawler->filter('[data-pages--meta-part-price-target="userValue"]')->attr('class'));
        $this->assertStringContainsString('d-none', (string) $crawler->filter('[data-pages--meta-part-price-target="calculated"]')->attr('class'));

        //Saved with a calculated method, the user value is kept but hidden, and the price is text
        $values = $this->editValues($this->em->find(Part::class, $meta->getID()));
        $this->assertSame('0.75000', $values['part_base']['priceUserValue'], 'The hidden field still holds the value');
        $values['part_base']['priceMethod'] = 'cheapest';
        $this->client->request('POST', $url, $values);
        $this->assertResponseRedirects();

        $this->em->clear();
        $stored = $this->em->find(Part::class, $meta->getID());
        $this->assertSame(PartPriceMethod::CHEAPEST, $stored->getPriceMethod());
        $this->assertTrue($stored->getPriceUserValue()?->isEqualTo('0.75'), 'The user value is kept');

        $crawler = $this->client->request('GET', $url);
        $this->assertStringContainsString('d-none', (string) $crawler->filter('[data-pages--meta-part-price-target="userValue"]')->attr('class'));
        $calculated = $crawler->filter('#meta_part_calculated_price');
        $this->assertStringContainsString('1.00', $calculated->text());
        $this->assertSame(0, $calculated->filter('input, select, textarea')->count(), 'Never an input');
    }

    public function testANewMetaPartSaysThePriceIsWorkedOutAfterSaving(): void
    {
        $crawler = $this->client->request('GET', '/en/part/new?kind=meta');

        $this->assertStringContainsString('Worked out after saving', $crawler->filter('#meta_part_calculated_price')->text());
    }

    public function testAMetaPartWithNoUsablePriceSaysSoOnItsEditPage(): void
    {
        $meta = $this->part('Meta without a price', PartKind::META);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/en/part/'.$meta->getID().'/edit');

        $this->assertStringContainsString('No price', $crawler->filter('#meta_part_calculated_price')->text());
    }

    public function testTheMethodUserValueWithNoValueShowsAViolation(): void
    {
        $meta = $this->part('Meta needing a value', PartKind::META);
        $this->em->flush();
        $values = $this->editValues($meta);
        $values['part_base']['priceMethod'] = 'user_value';
        $values['part_base']['priceUserValue'] = '';

        $crawler = $this->client->request('POST', '/en/part/'.$meta->getID().'/edit', $values);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('needs a user value', $crawler->filter('#meta_part_price')->text());
    }

    public function testPriceDataIsDiscardedWhenTheSubmittedKindIsManufacturing(): void
    {
        $crawler = $this->client->request('GET', '/en/part/new');
        $values = $crawler->filter('form[name="part_base"]')->form()->getPhpValues();
        $values['part_base']['name'] = 'Submitted manufacturing with a price';
        $values['part_base']['kind'] = 'manufacturing';
        $values['part_base']['category'] = '1';
        $values['part_base']['priceMethod'] = 'cheapest';
        $values['part_base']['priceUserValue'] = '0.5';
        $values['part_base']['save'] = '';
        $this->client->request('POST', '/en/part/new', $values);
        $this->assertResponseRedirects();

        $part = $this->em->getRepository(Part::class)->findOneBy(['name' => 'Submitted manufacturing with a price']);
        $this->assertNull($part->getPriceMethod());
        $this->assertNull($part->getPriceUserValue());
    }

    private function editValues(Part $meta): array
    {
        $crawler = $this->client->request('GET', '/en/part/'.$meta->getID().'/edit');
        $this->editPage = $crawler;
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

    /** @return string The text of the toasts on the page the save redirects to */
    private function warningsAfterSaving(string $url, array $values): string
    {
        $this->client->request('POST', $url, $values);
        $this->assertResponseRedirects();

        return implode("\n", $this->client->followRedirect()->filter('.toast')->each(static fn (Crawler $toast) => $toast->text()));
    }

    public function testANewMetaPartWithAnExistingNameIsSavedWithAWarningNamingTheOtherWithItsId(): void
    {
        $first = $this->part('Duplicate meta', PartKind::META);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/en/part/new?kind=meta');
        $values = $crawler->filter('form[name="part_base"]')->form()->getPhpValues();
        $values['part_base']['name'] = 'Duplicate meta';
        $values['part_base']['kind'] = 'meta';
        $values['part_base']['category'] = '1';
        $values['part_base']['save'] = '';
        $warnings = $this->warningsAfterSaving('/en/part/new?kind=meta', $values);

        $this->assertCount(2, $this->em->getRepository(Part::class)->findBy(['name' => 'Duplicate meta']), 'Saved all the same');
        $this->assertStringContainsString('the same name is used by: Duplicate meta (ID '.$first->getID().')', $warnings);
    }

    public function testEditingAMetaPartNamedLikeOthersInAnotherCaseWarnsAndAManufacturingPartDoesNot(): void
    {
        $first = $this->part('Case meta', PartKind::META);
        $second = $this->part('  CASE META ', PartKind::META);
        $manufacturing = $this->part('Case meta');
        $edited = $this->part('case Meta', PartKind::META);
        $this->em->flush();

        $warnings = $this->warningsAfterSaving('/en/part/'.$edited->getID().'/edit', $this->editValues($edited));
        $this->assertStringContainsString(sprintf('Case meta (ID %d), CASE META (ID %d)', $first->getID(), $second->getID()), $warnings);
        $this->assertStringNotContainsString('(ID '.$manufacturing->getID().')', $warnings, 'Only meta parts are named');
        $this->assertStringNotContainsString('(ID '.$edited->getID().')', $warnings, 'Not the part itself');

        $this->client->request('POST', '/en/part/'.$manufacturing->getID().'/edit', $this->editValues($manufacturing));
        $this->assertResponseRedirects();
        $this->assertStringNotContainsString('the same name is used by', $this->client->followRedirect()->filter('body')->html());
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

    /**
     * Saves and reloads the parts, so that the side of a connection that was not set in memory is read from the database.
     * @return list<Part>
     */
    private function saveAndReload(Part ...$parts): array
    {
        $this->em->flush();
        $ids = array_map(static fn (Part $part): ?int => $part->getID(), $parts);
        $this->em->clear();
        $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['name' => 'admin']));

        return array_map(fn (?int $id): Part => $this->em->find(Part::class, $id), $ids);
    }

    /** @return array<string, array{position: string, read_only: bool, selects: int, remove: int}> The rows of the Meta parts tab by the name of the meta part */
    private function metaPartRows(Crawler $crawler): array
    {
        $rows = [];
        $crawler->filter('#meta_part_connection_table tbody tr')->each(function (Crawler $row) use (&$rows): void {
            $rows[$row->filter('[data-meta-part]')->text()] = [
                'position' => trim($row->filter('[data-position]')->text()),
                'read_only' => $row->attr('data-read-only') !== null,
                'selects' => $row->filter('select')->count(),
                'remove' => $row->filter('button.btn-danger')->count(),
            ];
        });

        return $rows;
    }

    /** @return string The key of the row of the given meta part in the values of the edit page that editValues() read */
    private function metaPartRowKey(Part $meta): string
    {
        $row = $this->editPage->filter('#meta_part_connection_table tbody tr')
            ->reduce(static fn (Crawler $row): bool => $row->filter('[data-meta-part="'.$meta->getID().'"]')->count() > 0);
        $this->assertCount(1, $row, 'No row for '.$meta->getName());
        preg_match('/\[connections_as_part\]\[([^\]]+)\]/', (string) $row->filter('[name]')->attr('name'), $match);

        return $match[1];
    }

    public function testTheMetaPartsTabShowsOnTheEditPageOfAManufacturingPartOnly(): void
    {
        $meta = $this->part('Tab meta', PartKind::META);
        $part = $this->part('Tab part');
        $this->connect($meta, $part, 3, PartConnectionStatus::USE_UP);
        [$meta, $part] = $this->saveAndReload($meta, $part);

        $crawler = $this->client->request('GET', '/en/part/'.$part->getID().'/edit');
        $this->assertResponseIsSuccessful();
        $this->assertSame('Meta parts', trim($crawler->filter('a[href="#connections_as_part"]')->text()));
        $this->assertSame(1, $crawler->filter('a[href="#connections_as_part"] i.fa-sitemap')->count());
        $this->assertSame(0, $crawler->filter('a[href="#part_connections"]')->count());
        $this->assertSame(['Tab meta' => ['position' => '3', 'read_only' => false, 'selects' => 1, 'remove' => 1]], $this->metaPartRows($crawler));
        $this->assertSame('use_up', $crawler->filter('#meta_part_connection_table select option[selected]')->attr('value'));
        $this->assertSame(0, $crawler->filter('#meta_part_connection_table input[name$="[position]"]')->count(), 'The position is read-only');

        //The picker of a new row offers only meta parts
        $prototype = html_entity_decode((string) $crawler->filter('#connections_as_part [data-elements--collection-type-prototype-value]')->attr('data-elements--collection-type-prototype-value'));
        $this->assertStringContainsString('kind=meta', $prototype);
        $this->assertStringNotContainsString('kind=manufacturing', $prototype);

        $crawler = $this->client->request('GET', '/en/part/'.$meta->getID().'/edit');
        $this->assertSame(0, $crawler->filter('a[href="#connections_as_part"]')->count());
        $this->assertSame(1, $crawler->filter('a[href="#part_connections"]')->count());
    }

    public function testMetaPartsCanBeAddedChangedAndRemovedFromTheManufacturingPart(): void
    {
        $added = $this->part('Added meta', PartKind::META);
        $this->connect($added, $this->part('Other first'), 1);
        $this->connect($added, $this->part('Other second'), 4);
        $changed = $this->part('Changed meta', PartKind::META);
        $removed = $this->part('Removed meta', PartKind::META);
        $this->lot($removed, 3);
        $part = $this->part('Linked part');
        $this->lot($part, 7);
        $this->connect($changed, $part, 1, note: 'change');
        $this->connect($removed, $part, 2, note: 'remove');
        [$added, $changed, $removed, $part] = $this->saveAndReload($added, $changed, $removed, $part);
        [$partId, $addedId, $changedId, $removedId] = [$part->getID(), $added->getID(), $changed->getID(), $removed->getID()];

        $values = $this->editValues($part);
        $changedKey = $this->metaPartRowKey($changed);
        $values['part_base']['connections_as_part'][$changedKey]['status'] = 'do_not_use';
        $values['part_base']['connections_as_part'][$changedKey]['note'] = 'Changed';
        unset($values['part_base']['connections_as_part'][$this->metaPartRowKey($removed)]);
        $values['part_base']['connections_as_part'][9] = ['meta_part' => ['autocomplete' => (string) $addedId], 'status' => 'approved', 'note' => 'New'];

        $this->client->request('POST', '/en/part/'.$partId.'/edit', $values);
        $this->assertResponseRedirects();

        $this->em->clear();
        $part = $this->em->find(Part::class, $partId);
        $byMeta = [];
        foreach ($part->getConnectionsAsPart() as $connection) {
            $byMeta[$connection->getMetaPart()->getName()] = $connection;
        }
        ksort($byMeta);
        $this->assertSame(['Added meta', 'Changed meta'], array_keys($byMeta));
        $this->assertSame(5, $byMeta['Added meta']->getPosition(), 'Last in the order of the meta part');
        $this->assertSame(PartConnectionStatus::APPROVED, $byMeta['Added meta']->getStatus());
        $this->assertSame('New', $byMeta['Added meta']->getNote());
        $this->assertCount(3, $this->em->find(Part::class, $addedId)->getPartConnections());
        $this->assertSame(PartConnectionStatus::DO_NOT_USE, $byMeta['Changed meta']->getStatus());
        $this->assertSame('Changed', $byMeta['Changed meta']->getNote());
        $this->assertSame(1, $byMeta['Changed meta']->getPosition());

        $removed = $this->em->find(Part::class, $removedId);
        $this->assertNotNull($removed, 'Removing leaves the meta part');
        $this->assertCount(0, $removed->getPartConnections());
        $this->assertSame(3.0, $removed->getPartLots()->first()->getAmount());
        $this->assertSame(7.0, $part->getPartLots()->first()->getAmount(), 'and the part with its stock');
        $this->assertNotNull($this->em->find(Part::class, $changedId));
    }

    public function testAMetaPartTheUserMayNotEditIsReadOnlyAndAForgedChangeIsRefused(): void
    {
        $locked = $this->part('Locked meta', PartKind::META);
        $otherLocked = $this->part('Other locked meta', PartKind::META);
        $open = $this->part('Open meta', PartKind::META);
        $part = $this->part('Part under locked');
        $this->connect($locked, $part, 2, note: 'locked');
        $this->connect($open, $part, 1, note: 'open');
        [$locked, $otherLocked, $part] = $this->saveAndReload($locked, $otherLocked, $part);
        [$partId, $lockedId] = [$part->getID(), $locked->getID()];
        PartEditDenyingDecisionManager::$deniedPartIds = [$lockedId, $otherLocked->getID()];
        $url = '/en/part/'.$partId.'/edit';

        $crawler = $this->client->request('GET', $url);
        $this->assertResponseIsSuccessful();
        $rows = $this->metaPartRows($crawler);
        $this->assertSame(['position' => '2', 'read_only' => true, 'selects' => 0, 'remove' => 0], $rows['Locked meta']);
        $this->assertSame(['position' => '1', 'read_only' => false, 'selects' => 1, 'remove' => 1], $rows['Open meta']);

        //Saved unchanged, the read-only row is no change
        $values = $this->editValues($part);
        $this->client->request('POST', $url, $values);
        $this->assertResponseRedirects();

        $lockedKey = $this->metaPartRowKey($locked);
        $forgeries = [
            'remove' => static function (array $v) use ($lockedKey): array { unset($v['part_base']['connections_as_part'][$lockedKey]); return $v; },
            'add' => static function (array $v) use ($otherLocked): array { $v['part_base']['connections_as_part'][9] = ['meta_part' => ['autocomplete' => (string) $otherLocked->getID()], 'status' => 'approved', 'note' => '']; return $v; },
        ];
        foreach ($forgeries as $name => $forge) {
            $crawler = $this->client->request('POST', $url, $forge($values));
            $this->assertResponseStatusCodeSame(422, $name);
            $this->assertStringContainsString('can only be added, changed or removed by a user who may edit that meta part', $crawler->filter('#connections_as_part')->text(), $name);
        }

        //A forged change of the read-only row is ignored, as its fields are disabled
        $forged = $values;
        $forged['part_base']['connections_as_part'][$lockedKey] = ['status' => 'do_not_use', 'note' => 'forged'];
        $this->client->request('POST', $url, $forged);
        $this->assertResponseRedirects();

        $this->em->clear();
        $connections = $this->em->find(Part::class, $partId)->getConnectionsAsPart();
        $this->assertCount(2, $connections, 'Nothing changed');
        foreach ($connections as $connection) {
            $this->assertSame(PartConnectionStatus::APPROVED, $connection->getStatus());
            $this->assertSame($connection->getMetaPart()->getID() === $lockedId ? 'locked' : 'open', $connection->getNote());
        }
    }

    public function testANoteWithOuterSpacesOrALineBreakIsKeptAndDoesNotBlockSavingThePart(): void
    {
        $locked = $this->part('Locked meta', PartKind::META);
        $open = $this->part('Open meta', PartKind::META);
        $part = $this->part('Part with notes');
        $this->connect($locked, $part, 1, PartConnectionStatus::USE_UP, " prototypes\nonly ");
        $this->connect($open, $part, 1, note: "keep\nthis ");
        [$locked, $open, $part] = $this->saveAndReload($locked, $open, $part);
        [$partId, $lockedId] = [$part->getID(), $locked->getID()];
        PartEditDenyingDecisionManager::$deniedPartIds = [$lockedId];

        $values = $this->editValues($part);
        $values['part_base']['name'] = 'Renamed part';
        //A browser drops the line break of a text field's value
        $openKey = $this->metaPartRowKey($open);
        $values['part_base']['connections_as_part'][$openKey]['note'] = 'keepthis ';
        $this->client->request('POST', '/en/part/'.$partId.'/edit', $values);
        $this->assertResponseRedirects();

        $this->em->clear();
        $part = $this->em->find(Part::class, $partId);
        $this->assertSame('Renamed part', $part->getName());
        $this->assertCount(2, $part->getConnectionsAsPart());
        foreach ($part->getConnectionsAsPart() as $connection) {
            if ($connection->getMetaPart()->getID() === $lockedId) {
                $this->assertSame(" prototypes\nonly ", $connection->getNote());
                $this->assertSame(PartConnectionStatus::USE_UP, $connection->getStatus());
            } else {
                $this->assertSame("keep\nthis ", $connection->getNote());
            }
        }
    }

    public function testADuplicatePairOrAManufacturingPartIsRefusedFromTheManufacturingPart(): void
    {
        $meta = $this->part('Already meta', PartKind::META);
        $other = $this->part('Not a meta part');
        $part = $this->part('Already connected');
        $this->connect($meta, $part, 1);
        [$meta, $other, $part] = $this->saveAndReload($meta, $other, $part);
        $url = '/en/part/'.$part->getID().'/edit';

        foreach ([[$meta, 'already connected'], [$other, 'can only be connected to a meta part']] as [$picked, $message]) {
            $values = $this->editValues($part);
            $values['part_base']['connections_as_part'][9] = ['meta_part' => ['autocomplete' => (string) $picked->getID()], 'status' => 'approved', 'note' => ''];
            $crawler = $this->client->request('POST', $url, $values);

            $this->assertResponseStatusCodeSame(422, $picked->getName());
            $this->assertStringContainsString($message, $crawler->filter('#connections_as_part')->text());
        }

        $this->em->clear();
        $this->assertCount(1, $this->em->find(Part::class, $part->getID())->getConnectionsAsPart());
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

    public function testTheImportPageSaysTheLinkedPartsColumnsAreNotRead(): void
    {
        $this->client->request('GET', '/en/parts/import');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-body', 'The columns linked_parts_count and linked_parts, which the export writes, are not read');
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

    /** The source of the picture in the picture box of the part's info page */
    private function infoPicture(Part $part): string
    {
        return (string) $this->info($part)->filter('.part-image-placeholder img, #pictureCarousel img')->first()->attr('src');
    }

    public function testAMetaPartWithoutAPictureShowsTheIconOfItsKind(): void
    {
        $meta = $this->part('Meta without picture', PartKind::META);
        $plain = $this->part('Part without picture');
        $this->em->flush();

        $this->assertStringContainsString('img/meta_part_placeholder.svg', $this->infoPicture($meta));
        $this->assertStringContainsString('img/part_placeholder.svg', $this->infoPicture($plain), 'A manufacturing part keeps upstream\'s placeholder');
    }

    public function testAMetaPartWithAPictureShowsItsOwn(): void
    {
        $meta = $this->part('Meta with picture', PartKind::META);
        $type = new AttachmentType();
        $type->setName('Pictures');
        $this->em->persist($type);
        $picture = new PartAttachment();
        $picture->setName('Illustration');
        $picture->setAttachmentType($type);
        $picture->setExternalPath('https://example.com/illustration.png');
        $meta->addAttachment($picture);
        $meta->setMasterPictureAttachment($picture);
        $this->em->flush();

        $this->assertSame('https://example.com/illustration.png', $this->infoPicture($meta));
    }

    public function testTheStockAndShoppingTabsNameEachConnectedPartWithItsManufacturerAndMpn(): void
    {
        $meta = $this->part('Resistor 10k 1% 0603', PartKind::META);
        $manufacturer = new Manufacturer();
        $manufacturer->setName('YAGEO');
        $this->em->persist($manufacturer);
        $connected = $this->part('RES SMD 10K 1% 0.1W 0603');
        $connected->setManufacturer($manufacturer);
        $connected->setManufacturerProductNumber('RC0603FR-1310KL');
        $this->lot($connected, 100);
        $this->price($connected, '0.01');
        $this->connect($meta, $connected, 1);
        $this->em->flush();
        $manufacturerUrl = '/en/manufacturer/'.$manufacturer->getID().'/parts';

        $crawler = $this->info($meta);
        $headings = [
            'stocks' => $crawler->filter('#part_lots tr.table-secondary th')->first(),
            'shopping' => $crawler->filter('#suppliers h6')->first(),
        ];
        foreach ($headings as $tab => $heading) {
            $partLink = $heading->filter('a[href="/en/part/'.$connected->getID().'/info"]');
            $this->assertCount(1, $partLink, $tab);
            $this->assertStringContainsString('RES SMD 10K 1% 0.1W 0603', $partLink->text(), $tab);
            //The owner's order: manufacturer, MPN, then the part's name
            $text = $heading->text();
            $this->assertLessThan(strpos($text, 'RC0603FR-1310KL'), strpos($text, 'YAGEO'), $tab.': '.$text);
            $this->assertLessThan(strpos($text, 'RES SMD 10K 1% 0.1W 0603'), strpos($text, 'RC0603FR-1310KL'), $tab.': '.$text);
            $this->assertSame('YAGEO', $heading->filter('a[href="'.$manufacturerUrl.'"]')->text(), $tab);
            $this->assertStringContainsString('RC0603FR-1310KL', $heading->text(), $tab);
            $this->assertStringContainsString('Approved', $heading->text(), $tab.': the status stays');
        }
    }
}
