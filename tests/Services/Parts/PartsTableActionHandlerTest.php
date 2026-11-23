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
namespace App\Tests\Services\Parts;

use App\Entity\Parts\Manufacturer;
use App\Entity\Parts\MeasurementUnit;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartKind;
use App\Entity\Parts\Category;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use App\Settings\BehaviorSettings\PartDeletionSettings;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Entity\UserSystem\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use App\Services\Parts\PartsTableActionHandler;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class PartsTableActionHandlerTest extends WebTestCase
{
    private PartsTableActionHandler $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = self::getContainer()->get(PartsTableActionHandler::class);
    }

    public function testExportActionsRedirectToExportController(): void
    {
        // Mock a Part entity with required properties
        $part = $this->createMock(Part::class);
        $part->method('getId')->willReturn(1);
        $part->method('getName')->willReturn('Test Part');

        $selected_parts = [$part];

        // Test each export format, focusing on our new xlsx format
        $formats = ['json', 'csv', 'xml', 'yaml', 'xlsx'];

        foreach ($formats as $format) {
            $action = "export_{$format}";
            $result = $this->service->handleAction($action, $selected_parts, 1, '/test');

            $this->assertInstanceOf(RedirectResponse::class, $result);
            $this->assertStringContainsString('parts/export', $result->getTargetUrl());
            $this->assertStringContainsString("format={$format}", $result->getTargetUrl());
        }
    }

    public function testExportUrlContainsPartIds(): void
    {
        $part1 = $this->createMock(Part::class);
        $part1->method('getId')->willReturn(42);

        $part2 = $this->createMock(Part::class);
        $part2->method('getId')->willReturn(99);

        $result = $this->service->handleAction('export_csv', [$part1, $part2], 1, '/test');

        $this->assertInstanceOf(RedirectResponse::class, $result);
        // Commas in query-string values are not percent-encoded by Symfony's UrlGenerator
        $this->assertStringContainsString('ids=42,99', $result->getTargetUrl());
    }

    public function testExportCsvEscaped(): void
    {
        $result = $this->service->handleAction('export_csv_escaped', [], 1, '/test');
        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertStringContainsString('format=csv_escaped', $result->getTargetUrl());
    }

    public function testExportWithNoPartsProducesEmptyIds(): void
    {
        $result = $this->service->handleAction('export_json', [], 1, '/test');

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertStringContainsString('parts/export', $result->getTargetUrl());
        // ids parameter present but empty
        $this->assertStringContainsString('ids=', $result->getTargetUrl());
    }

    public function testUnknownActionWithEmptyPartsReturnsNull(): void
    {
        // The unknown-action switch only runs inside the foreach loop, so an
        // empty parts list means the loop body never executes and no exception is thrown.
        $result = $this->service->handleAction('unknown_action_xyz', [], null, '/test');
        $this->assertNull($result);
    }

    private function metaPart(): Part
    {
        return (new Part())->setKind(PartKind::META);
    }

    public function testChangingTheManufacturerSkipsAPartThatCanNotHoldOne(): void
    {
        $this->loginAsAdmin();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $manufacturer = $em->find(Manufacturer::class, 1);
        $meta = $this->metaPart();
        $part = new Part();

        $errors = [];
        $this->service->handleAction('change_manufacturer', [$meta, $part], $manufacturer->getID(), null, $errors);

        $this->assertNull($meta->getManufacturer());
        $this->assertSame($manufacturer, $part->getManufacturer());
        $this->assertCount(1, $errors);
        $this->assertSame($meta, $errors[0]['part']);
    }

    public function testChangingTheUnitIsRefusedWhenAConnectedPartWouldDiffer(): void
    {
        $this->loginAsAdmin();
        $meta = $this->metaPart();
        $part = new Part();
        $connection = (new PartConnection())->setConnectedPart($part);
        $meta->addPartConnection($connection);
        $part->getConnectionsAsPart()->add($connection);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $unit = new MeasurementUnit();
        $unit->setName('Metres');
        $em->persist($unit);
        $em->flush();

        foreach ([$part, $meta] as $target) {
            $errors = [];
            $this->service->handleAction('change_unit', [$target], $unit->getID(), null, $errors);
            $this->assertNull($target->getPartUnit());
            $this->assertCount(1, $errors);
        }

        $free = new Part();
        $errors = [];
        $this->service->handleAction('change_unit', [$free], $unit->getID(), null, $errors);
        $this->assertSame($unit, $free->getPartUnit());
        $this->assertSame([], $errors);
    }

    public function testChangingTheLocationCreatesNoLotOnAMetaPart(): void
    {
        $this->loginAsAdmin();
        $meta = $this->metaPart();

        $errors = [];
        $this->service->handleAction('change_location', [$meta], 1, null, $errors);

        $this->assertCount(0, $meta->getPartLots());
        $this->assertCount(1, $errors);
    }

    /**
     * @return array{0: Part, 1: Part} a part used in the BOM of "Table project" and a part used in none, both saved
     */
    private function partInABomAndAnUnusedPart(): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $used = new Part();
        $used->setName('Table part in a BOM');
        $used->setCategory($em->find(Category::class, 1));
        $unused = new Part();
        $unused->setName('Table part in no BOM');
        $unused->setCategory($em->find(Category::class, 1));
        $project = new Project();
        $project->setName('Table project');
        $entry = (new ProjectBOMEntry())->setQuantity(1.0);
        $project->addBomEntry($entry);
        $used->addProjectBomEntry($entry);
        $em->persist($used);
        $em->persist($unused);
        $em->persist($project);
        $em->flush();

        return [$used, $unused];
    }

    public function testDeletingWithTheSettingOnSkipsAPartInABomAndDeletesTheOthers(): void
    {
        $this->loginAsAdmin();
        self::getContainer()->get(PartDeletionSettings::class)->refuseDeletingPartsUsedInBom = true;
        $em = self::getContainer()->get(EntityManagerInterface::class);
        [$used, $unused] = $this->partInABomAndAnUnusedPart();
        $usedId = $used->getID();
        $unusedId = $unused->getID();

        $errors = [];
        $this->service->handleAction('delete', [$used, $unused], null, null, $errors);
        $em->flush();

        $this->assertCount(1, $errors);
        $this->assertSame($used, $errors[0]['part']);
        $message = $errors[0]['message']->trans(self::getContainer()->get(TranslatorInterface::class), 'en');
        $this->assertStringContainsString('Table project', $message);

        $em->clear();
        $this->assertNotNull($em->find(Part::class, $usedId));
        $this->assertNull($em->find(Part::class, $unusedId));
    }

    public function testDeletingWithTheSettingOffDeletesBoth(): void
    {
        $this->loginAsAdmin();
        self::getContainer()->get(PartDeletionSettings::class)->refuseDeletingPartsUsedInBom = false;
        $em = self::getContainer()->get(EntityManagerInterface::class);
        [$used, $unused] = $this->partInABomAndAnUnusedPart();
        $usedId = $used->getID();
        $unusedId = $unused->getID();

        $errors = [];
        $this->service->handleAction('delete', [$used, $unused], null, null, $errors);
        $em->flush();

        $this->assertSame([], $errors);
        $em->clear();
        $this->assertNull($em->find(Part::class, $usedId));
        $this->assertNull($em->find(Part::class, $unusedId));
    }

    private function loginAsAdmin(): void
    {
        $user = self::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['name' => 'admin']);
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
