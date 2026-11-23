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
namespace App\Tests\Controller;

use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartConnectionStatus;
use App\Entity\Parts\PartKind;
use App\Entity\Parts\PartPriceMethod;
use App\Entity\Parts\PartLot;
use App\Entity\Parts\Supplier;
use App\Entity\PriceInformations\Orderdetail;
use App\Entity\PriceInformations\Pricedetail;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use App\Entity\UserSystem\User;
use Brick\Math\BigDecimal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The build form, which had no test posting it, with meta parts and lots reached by several entries.
 */
final class ProjectBuildPagesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['name' => 'admin']));
        $this->client->disableReboot();
    }

    private function part(string $name, PartKind $kind = PartKind::MANUFACTURING): Part
    {
        $part = (new Part())->setKind($kind);
        $part->setName($name);
        $part->setCategory($this->em->find(Category::class, 1));
        $this->em->persist($part);

        return $part;
    }

    private function lot(Part $part, float $amount, ?string $name = null): PartLot
    {
        $lot = new PartLot();
        $lot->setAmount($amount);
        $lot->setDescription($name ?? 'Lot of '.$amount);
        $part->addPartLot($lot);

        return $lot;
    }

    private function connect(Part $meta, Part $part, int $position, PartConnectionStatus $status = PartConnectionStatus::APPROVED, string $note = ''): PartConnection
    {
        $connection = (new PartConnection())->setConnectedPart($part)->setPosition($position)->setStatus($status)->setNote($note);
        $meta->addPartConnection($connection);

        return $connection;
    }

    /**
     * @param  list<array{0: Part, 1: float}>  $bom
     */
    private function project(array $bom): Project
    {
        $project = new Project();
        $project->setName('Build test');
        foreach ($bom as [$part, $quantity]) {
            $entry = new ProjectBOMEntry();
            $entry->setPart($part);
            $entry->setQuantity($quantity);
            $project->addBomEntry($entry);
        }
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    private function open(Project $project, int $n = 1): Crawler
    {
        $crawler = $this->client->request('GET', '/en/project/'.$project->getID().'/build?n='.$n);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function field(ProjectBOMEntry $entry, PartLot $lot): string
    {
        return 'project_build[entry_'.$entry->getID().'_lot_'.$lot->getID().'][value]';
    }

    /**
     * @param  array<string, string>  $change  Fields to change or add
     */
    private function post(Project $project, Crawler $crawler, array $change = [], int $n = 1): void
    {
        $form = $crawler->filter('form[name="project_build"]')->form();
        $values = $form->getPhpValues();
        foreach ($change as $name => $value) {
            parse_str($name.'='.$value, $parsed);
            $values = array_replace_recursive($values, $parsed);
        }
        $this->client->request('POST', '/en/project/'.$project->getID().'/build?n='.$n, $values);
    }

    private function entry(Project $project, Part $part): ProjectBOMEntry
    {
        foreach ($project->getBomEntries() as $entry) {
            if ($entry->getPart() === $part) {
                return $entry;
            }
        }
        $this->fail('No such entry');
    }

    /**
     * @return array{0: Project, 1: Part, 2: Part, 3: PartLot, 4: PartLot}
     */
    private function bomWithSharedLot(float $metaNeed = 30, float $directNeed = 20): array
    {
        $meta = $this->part('Shared meta', PartKind::META);
        $connected = $this->part('Shared connected');
        $shared = $this->lot($connected, 100, 'The shared lot');
        $own = $this->lot($meta, 5, 'The own lot');
        $this->connect($meta, $connected, 1);

        return [$this->project([[$meta, $metaNeed], [$connected, $directNeed]]), $meta, $connected, $shared, $own];
    }

    public function testTheFormHasOneFieldPerPairAndABuildWithdrawsExactlyTheAmounts(): void
    {
        [$project, $meta, $connected, $shared, $own] = $this->bomWithSharedLot();
        $metaEntry = $this->entry($project, $meta);
        $directEntry = $this->entry($project, $connected);

        $crawler = $this->open($project);
        $this->assertSame('5', $crawler->filter('input[name="'.$this->field($metaEntry, $own).'"]')->attr('value'));
        $this->assertSame('25', $crawler->filter('input[name="'.$this->field($metaEntry, $shared).'"]')->attr('value'));
        $this->assertSame('20', $crawler->filter('input[name="'.$this->field($directEntry, $shared).'"]')->attr('value'));
        $this->assertCount(3, $crawler->filter('input[name^="project_build[entry_"]'));

        $this->post($project, $crawler);
        self::assertResponseRedirects();

        $this->em->clear();
        $this->assertSame(0.0, $this->em->find(PartLot::class, $own->getID())->getAmount());
        $this->assertSame(55.0, $this->em->find(PartLot::class, $shared->getID())->getAmount());
    }

    public function testAnUnknownPairIsRefusedAndStockStaysAsItWas(): void
    {
        [$project, $meta, , $shared] = $this->bomWithSharedLot();
        $otherPart = $this->part('Elsewhere');
        $other = $this->lot($otherPart, 50);
        $this->em->flush();

        $crawler = $this->open($project);
        $this->post($project, $crawler, [$this->field($this->entry($project, $meta), $other) => '1']);

        self::assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('extra fields', (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        $this->assertSame(100.0, $this->em->find(PartLot::class, $shared->getID())->getAmount());
        $this->assertSame(50.0, $this->em->find(PartLot::class, $other->getID())->getAmount());
    }

    public function testAmountsOverAllEntriesAboveTheStockAreRefusedWithTheCheckOnAndOff(): void
    {
        [$project, $meta, $connected, $shared] = $this->bomWithSharedLot(60, 60);
        $this->em->flush();
        $metaEntry = $this->entry($project, $meta);
        $directEntry = $this->entry($project, $connected);
        $change = [$this->field($metaEntry, $shared) => '60', $this->field($directEntry, $shared) => '60'];

        foreach ([[], ['project_build[dontCheckQuantity]' => '1']] as $extra) {
            $this->post($project, $this->open($project), $change + $extra);
            $crawler = $this->client->getCrawler();

            self::assertResponseStatusCodeSame(422);
            $this->assertStringContainsString(
                'You can not withdraw more than this lot holds',
                $crawler->filter('form[name="project_build"]')->text(),
                'The stock check holds whatever the quantity check says'
            );
            $this->em->clear();
            $this->assertSame(100.0, $this->em->find(PartLot::class, $shared->getID())->getAmount());
            $project = $this->em->find(Project::class, $project->getID());
            $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['name' => 'admin']));
        }
    }

    public function testTheSumOfAnEntryHasToBeWhatItNeedsUnlessTheCheckIsOff(): void
    {
        $meta = $this->part('Summed meta', PartKind::META);
        $own = $this->lot($meta, 100);
        $project = $this->project([[$meta, 10]]);
        $field = $this->field($this->entry($project, $meta), $own);

        $this->post($project, $this->open($project), [$field => '9']);
        self::assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('less quantity', (string) $this->client->getResponse()->getContent());

        $this->post($project, $this->open($project), [$field => '11']);
        self::assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('more quantity', (string) $this->client->getResponse()->getContent());

        $this->post($project, $this->open($project), [$field => '9', 'project_build[dontCheckQuantity]' => '1']);
        self::assertResponseRedirects();
        $this->em->clear();
        $this->assertSame(91.0, $this->em->find(PartLot::class, $own->getID())->getAmount());
    }

    public function testADoNotUsePartHasNoFieldUnderTheMetaPartButKeepsItsDirectEntry(): void
    {
        $meta = $this->part('Careful meta', PartKind::META);
        $good = $this->part('Good source');
        $this->lot($good, 100);
        $retired = $this->part('Retired source');
        $retiredLot = $this->lot($retired, 100);
        $this->connect($meta, $good, 1);
        $this->connect($meta, $retired, 2, PartConnectionStatus::DO_NOT_USE, 'Bad batch');
        $project = $this->project([[$meta, 10], [$retired, 10]]);
        $metaEntry = $this->entry($project, $meta);
        $directEntry = $this->entry($project, $retired);

        $crawler = $this->open($project);

        $this->assertCount(0, $crawler->filter('input[name="'.$this->field($metaEntry, $retiredLot).'"]'));
        $this->assertCount(1, $crawler->filter('input[name="'.$this->field($directEntry, $retiredLot).'"]'));
        $form = $crawler->filter('form[name="project_build"]')->text();
        $this->assertStringContainsString('Retired source', $form);
        $this->assertStringContainsString('Bad batch', $form);

        //A post naming the pair is refused
        $this->post($project, $crawler, [$this->field($metaEntry, $retiredLot) => '10']);
        self::assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('extra fields', (string) $this->client->getResponse()->getContent());
        $this->assertSame(100.0, $retiredLot->getAmount());
    }

    public function testAConnectionMarkedDoNotUseAfterTheFormOpenedRefusesThePost(): void
    {
        $meta = $this->part('Changing meta', PartKind::META);
        $part = $this->part('Changing source');
        $lot = $this->lot($part, 100);
        $connection = $this->connect($meta, $part, 1);
        $project = $this->project([[$meta, 10]]);

        $crawler = $this->open($project);
        $connection->setStatus(PartConnectionStatus::DO_NOT_USE);
        $this->em->flush();
        $this->post($project, $crawler);

        self::assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('no stock to take from', (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        $this->assertSame(100.0, $this->em->find(PartLot::class, $lot->getID())->getAmount());
    }

    public function testASharedLotIsMarkedNamingTheOtherEntry(): void
    {
        [$project] = $this->bomWithSharedLot();

        $form = $this->open($project)->filter('form[name="project_build"]')->text();

        $this->assertStringContainsString('Also used by', $form);
        $this->assertStringContainsString('Shared meta', $form);
        $this->assertStringContainsString('Own stock of the meta part', $form);
    }

    public function testAMetaPartWithNothingToOfferShowsWhyTheBuildIsRefused(): void
    {
        $meta = $this->part('Empty meta', PartKind::META);
        $direct = $this->part('No lots at all');
        $project = $this->project([[$meta, 1], [$direct, 1]]);

        $this->post($project, $this->open($project));

        self::assertResponseStatusCodeSame(422);
        $text = $this->client->getCrawler()->filter('form[name="project_build"]')->text();
        $this->assertStringContainsString('Empty meta has no stock to take from', $text);
        $this->assertStringNotContainsString('No lots at all has no stock', $text);
    }

    public function testADirectEntryOnAPartWithNoLotsStillBuilds(): void
    {
        $project = $this->project([[$this->part('No lots at all'), 1]]);

        $this->post($project, $this->open($project));

        self::assertResponseRedirects();
    }

    /**
     * @return array{0: Project, 1: Part, 2: PartLot, 3: PartLot, 4: PartLot} A project built from a meta part with an
     * own lot and a connected part marked do not use, and the lot of its one BOM part
     */
    private function projectWithMetaBuildPart(): array
    {
        $meta = $this->part('Meta build part', PartKind::META);
        $own = $this->lot($meta, 2, 'Own lot of the build part');
        $connected = $this->part('Connected to the build part');
        $connectedLot = $this->lot($connected, 10, 'Lot of the connected part');
        $this->connect($meta, $connected, 1, PartConnectionStatus::DO_NOT_USE);
        $component = $this->part('Component');
        $componentLot = $this->lot($component, 100);
        $project = $this->project([[$component, 1]]);
        $project->setBuildPart($meta);
        $this->em->flush();

        return [$project, $meta, $own, $connectedLot, $componentLot];
    }

    public function testAMetaBuildPartTakesTheBuiltUnitsInTheChosenLotOfAConnectedPart(): void
    {
        [$project, , $own, $connectedLot, $componentLot] = $this->projectWithMetaBuildPart();

        $crawler = $this->open($project, 3);
        $select = $crawler->filter('select[name="project_build[buildsPartLot]"]');
        $this->assertSame(['Own stock of the meta part', 'Connected to the build part'], $select->filter('optgroup')->each(static fn (Crawler $group): string => (string) $group->attr('label')));
        $this->assertSame(['', (string) $own->getID(), (string) $connectedLot->getID()], $select->filter('option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));

        $this->post($project, $crawler, ['project_build[buildsPartLot]' => (string) $connectedLot->getID()], 3);
        self::assertResponseRedirects();

        $this->em->clear();
        $this->assertSame(13.0, $this->em->find(PartLot::class, $connectedLot->getID())->getAmount());
        $this->assertSame(2.0, $this->em->find(PartLot::class, $own->getID())->getAmount());
        $this->assertSame(97.0, $this->em->find(PartLot::class, $componentLot->getID())->getAmount());
    }

    public function testANewLotForTheBuiltUnitsIsAnOwnLotOfTheMetaBuildPart(): void
    {
        [$project, $meta, , $connectedLot] = $this->projectWithMetaBuildPart();

        $this->post($project, $this->open($project, 2), ['project_build[buildsPartLot]' => ''], 2);
        self::assertResponseRedirects();

        $this->em->clear();
        $lots = $this->em->find(Part::class, $meta->getID())->getPartLots();
        $this->assertCount(2, $lots);
        $this->assertSame([2.0, 2.0], $lots->map(static fn (PartLot $lot): float => $lot->getAmount())->getValues());
        $this->assertSame(10.0, $this->em->find(PartLot::class, $connectedLot->getID())->getAmount());
    }

    public function testALotOfAnotherPartIsRefusedForTheBuiltUnits(): void
    {
        [$project, $meta, $own, , $componentLot] = $this->projectWithMetaBuildPart();

        $this->post($project, $this->open($project), ['project_build[buildsPartLot]' => (string) $componentLot->getID()]);

        self::assertResponseStatusCodeSame(422);
        $this->em->clear();
        $this->assertCount(1, $this->em->find(Part::class, $meta->getID())->getPartLots(), 'No lot is created');
        $this->assertSame(100.0, $this->em->find(PartLot::class, $componentLot->getID())->getAmount());
        $this->assertSame(2.0, $this->em->find(PartLot::class, $own->getID())->getAmount());
    }

    public function testEntriesLeftShortAreListedAndMarked(): void
    {
        $shelf = $this->part('Only shelf');
        $this->lot($shelf, 100);
        $one = $this->part('First wanting', PartKind::META);
        $this->connect($one, $shelf, 1);
        $two = $this->part('Second wanting', PartKind::META);
        $this->connect($two, $shelf, 1);
        $project = $this->project([[$one, 80], [$two, 80]]);

        $crawler = $this->open($project);
        $alert = $crawler->filter('.alert-danger')->text();
        $this->assertStringContainsString('Second wanting', $alert);
        $this->assertStringNotContainsString('First wanting', $alert);
        $this->assertStringContainsString('20', $alert, 'What is left for it after the first entry');
        $this->assertCount(1, $crawler->filter('form[name="project_build"] tr.table-danger'));

        $crawler = $this->open($project, 10);
        $this->assertCount(2, $crawler->filter('form[name="project_build"] tr.table-danger'));

        $crawler = $this->client->request('GET', '/en/project/'.$project->getID().'/info');
        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('Second wanting', $crawler->filter('#builds .alert-danger, .alert-danger')->text());

        //The BOM table itself marks no row: the build page and the alert tell which are short
        foreach ($this->tableRows($project) as $row) {
            $this->assertArrayNotHasKey('$$rowClass', $row);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tableRows(Project $project): array
    {
        $this->client->request('POST', '/en/project/'.$project->getID().'/info', ['_dt' => 'dt', 'draw' => 1, 'start' => 0, 'length' => 500, 'search' => ['value' => '', 'regex' => false]]);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
    }

    private function price(Part $part, string $price): void
    {
        $orderdetail = new Orderdetail();
        $orderdetail->setSupplier($this->em->find(Supplier::class, 1));
        $orderdetail->setSupplierpartnr('SP-'.$part->getName());
        $orderdetail->addPricedetail((new Pricedetail())->setMinDiscountQuantity(1.0)->setPrice(BigDecimal::of($price)));
        $part->addOrderdetail($orderdetail);
    }

    public function testTheBomTableAndProjectPageShowAMetaPartByItsPriceMethod(): void
    {
        $meta = $this->part('Priced meta', PartKind::META);
        $meta->setPriceMethod(PartPriceMethod::CHEAPEST);
        $this->lot($meta, 3, 'Own');
        $cheap = $this->part('Cheap source');
        $this->price($cheap, '1');
        $dear = $this->part('Dear source');
        $this->price($dear, '2');
        $this->lot($dear, 7);
        $this->connect($meta, $dear, 1);
        $this->connect($meta, $cheap, 2);
        $unpricedMeta = $this->part('Unpriced meta', PartKind::META);
        $project = $this->project([[$meta, 2], [$unpricedMeta, 1]]);

        $rows = $this->tableRows($project);
        $byName = array_column($rows, null, 'partId');
        $row = $byName[(string) $meta->getID()];
        $this->assertMatchesRegularExpression('/^\D*1[.,]00\D*$/', $row['price'], 'One value, by the method');
        $this->assertMatchesRegularExpression('/^\D*2[.,]00\D*$/', $row['ext_price']);
        $this->assertStringContainsString('10', $row['instockAmount']);
        $this->assertSame('', $byName[(string) $unpricedMeta->getID()]['price']);

        $text = $this->client->request('GET', '/en/project/'.$project->getID().'/info')->text();
        $this->assertMatchesRegularExpression('/2[.,]00/', $text);
        $this->assertDoesNotMatchRegularExpression('/2[.,]00\D*–/', $text, 'No range');
        $this->assertMatchesRegularExpression('/Part entries with no price, left out of this cost: 1\b/', $text);
    }

    public function testTheProjectPageCountsEveryUnpricedEntry(): void
    {
        $meta = $this->part('Meta with a user value', PartKind::META);
        $meta->setPriceMethod(PartPriceMethod::USER_VALUE)->setPriceUserValue(BigDecimal::of('0.5'));
        $project = $this->project([[$this->part('Plain unpriced'), 1], [$meta, 4], [$this->part('Unpriced meta part', PartKind::META), 1]]);

        $rows = array_column($this->tableRows($project), null, 'partId');
        $this->assertStringContainsString('0.00', $rows[(string) $project->getBomEntries()->first()->getPart()->getID()]['price'], 'Other entries show what they always showed');
        $this->assertMatchesRegularExpression('/^\D*0[.,]50\D*$/', $rows[(string) $meta->getID()]['price']);
        $text = $this->client->request('GET', '/en/project/'.$project->getID().'/info')->text();
        $this->assertMatchesRegularExpression('/2[.,]00/', $text);
        $this->assertMatchesRegularExpression('/Part entries with no price, left out of this cost: 2\b/', $text);
    }

    public function testQueriesDoNotDependOnTheNumberOfMetaPartEntries(): void
    {
        $count = function (int $entries, callable $request): int {
            $bom = [];
            for ($i = 0; $i < $entries; $i++) {
                $meta = $this->part('Counted meta '.$entries.'-'.$i, PartKind::META);
                $this->lot($meta, 1);
                $connected = $this->part('Counted part '.$entries.'-'.$i);
                $this->lot($connected, 100);
                $this->price($connected, '1');
                $this->connect($meta, $connected, 1);
                $bom[] = [$meta, 1];
            }
            $id = $this->project($bom)->getID();
            $this->em->clear();
            $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['name' => 'admin']));
            $request($id, false);
            $this->em->clear();
            $this->client->enableProfiler();
            $request($id, true);

            return $this->client->getProfile()->getCollector('db')->getQueryCount();
        };

        $pages = [
            'build form' => function (int $id): void {
                $this->client->request('GET', '/en/project/'.$id.'/build?n=1');
                self::assertResponseIsSuccessful();
            },
            'BOM table' => function (int $id): void {
                $this->client->request('POST', '/en/project/'.$id.'/info', ['_dt' => 'dt', 'draw' => 1, 'start' => 0, 'length' => 500, 'search' => ['value' => '', 'regex' => false]]);
                self::assertResponseIsSuccessful();
            },
            'BOM export' => function (int $id): void {
                $this->client->request('POST', '/en/project/'.$id.'/bom/export', [
                    '_dt' => 'dt', 'draw' => 1, 'start' => 0, 'length' => 500, 'search' => ['value' => '', 'regex' => false],
                    'exportColumns' => ['name', 'instockAmount', 'storelocation'], 'exportLabels' => ['Name', 'Stock', 'Location'],
                ]);
                self::assertResponseIsSuccessful();
            },
        ];

        foreach ($pages as $name => $page) {
            $count(1, $page);
            $few = $count(10, $page);
            $many = $count(100, $page);
            $this->assertSame($few, $many, $name);
        }
    }

    /**
     * A BOM stored with one part twice, as REST let through before it refused a second entry: the build
     * page opens with fields for each entry and builds, withdrawing what both entries need.
     */
    public function testABomHoldingAPartTwiceOpensAndBuilds(): void
    {
        $part = $this->part('Twice in the BOM');
        $lot = $this->lot($part, 100);
        $project = $this->project([[$part, 3], [$part, 4]]);
        [$first, $second] = array_values($project->getBomEntries()->toArray());

        $crawler = $this->open($project);
        $this->assertSame('3', $crawler->filter('input[name="'.$this->field($first, $lot).'"]')->attr('value'));
        $this->assertSame('4', $crawler->filter('input[name="'.$this->field($second, $lot).'"]')->attr('value'));

        $this->post($project, $crawler);
        self::assertResponseRedirects();

        $this->em->clear();
        $this->assertSame(93.0, $this->em->find(PartLot::class, $lot->getID())->getAmount());
    }

    public function testABomHoldingAPartTwiceShowsOnTheProjectPageAndTheSecondEntryIsShort(): void
    {
        $part = $this->part('Twice on the info page');
        $this->lot($part, 5);
        $project = $this->project([[$part, 3], [$part, 4]]);

        $crawler = $this->client->request('GET', '/en/project/'.$project->getID().'/info');
        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('Twice on the info page', $crawler->filter('.alert-danger')->text());
        $rows = $this->tableRows($project);
        $this->assertCount(2, $rows);
        $this->assertArrayNotHasKey('$$rowClass', $rows[0], 'The BOM table marks no row');

        $crawler = $this->open($project);
        $this->assertCount(1, $crawler->filter('form[name="project_build"] tr.table-danger'));
        $this->post($project, $crawler);
        self::assertResponseStatusCodeSame(422);
    }
}
