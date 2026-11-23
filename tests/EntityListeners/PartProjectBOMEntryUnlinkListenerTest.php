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

namespace App\Tests\EntityListeners;

use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use App\Exceptions\PartUsedInBomException;
use App\Exceptions\PartUsedInBomSafetyNetException;
use App\Settings\BehaviorSettings\PartDeletionSettings;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\EventListener\ErrorListener;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The listener is the safety net behind the delete paths: with the setting on, a flush that would delete a part used in
 * a BOM fails before any SQL; with it off, the entries are unlinked and keep the name.
 */
final class PartProjectBOMEntryUnlinkListenerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PartDeletionSettings $settings;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->settings = self::getContainer()->get(PartDeletionSettings::class);
    }

    /**
     * @return array{0: Part, 1: Project, 2: ProjectBOMEntry}
     */
    private function partInABom(): array
    {
        $part = new Part();
        $part->setName('Listener part');
        $part->setCategory($this->em->find(Category::class, 1));
        $project = new Project();
        $project->setName('Listener project');
        $entry = (new ProjectBOMEntry())->setQuantity(1.0);
        $project->addBomEntry($entry);
        $part->addProjectBomEntry($entry);
        $this->em->persist($part);
        $this->em->persist($project);
        $this->em->flush();

        return [$part, $project, $entry];
    }

    public function testWithTheSettingOnTheFlushThrowsAndTheEntryStaysLinked(): void
    {
        $this->settings->refuseDeletingPartsUsedInBom = true;
        [$part, , $entry] = $this->partInABom();
        $partId = $part->getID();
        $entryId = $entry->getID();

        $this->em->remove($part);
        try {
            $this->em->flush();
            $this->fail('The flush deleted a part used in a BOM.');
        } catch (PartUsedInBomSafetyNetException $exception) {
            $this->assertStringContainsString('did not ask PartDeletionGuard', $exception->getMessage());
            $this->assertInstanceOf(PartUsedInBomException::class, $exception->getPrevious());
            $this->assertStringContainsString('"Listener part"', $exception->getMessage());
            $this->assertStringContainsString('Listener project', $exception->getMessage());
        }
        $this->assertSame($part, $entry->getPart());

        $this->em->clear();
        $this->assertNotNull($this->em->find(Part::class, $partId));
        $this->assertSame($partId, $this->em->find(ProjectBOMEntry::class, $entryId)?->getPart()?->getID());
    }

    /**
     * The safety net fires only when a delete path forgot to ask the guard, which is a bug and must be logged as
     * critical; the refusal of a path that asked is expected and only a warning.
     */
    public function testTheSafetyNetIsLoggedAsCriticalAndTheRefusalAsAWarning(): void
    {
        $refusal = new PartUsedInBomException('Logged part', ['Logged project']);
        $levels = [];
        foreach ([$refusal, new PartUsedInBomSafetyNetException($refusal)] as $exception) {
            $handler = new TestHandler();
            self::getContainer()->get('monolog.logger.request')->pushHandler($handler);
            /** @var ErrorListener $errorListener */
            $errorListener = self::getContainer()->get('exception_listener');
            $errorListener->logKernelException(new ExceptionEvent(self::$kernel, Request::create('/api/parts/1', 'DELETE'),
                HttpKernelInterface::MAIN_REQUEST, $exception));
            $levels[$exception::class] = array_map(static fn ($record): Level => $record->level, $handler->getRecords());
            self::getContainer()->get('monolog.logger.request')->popHandler();
        }

        $this->assertSame([PartUsedInBomException::class => [Level::Warning], PartUsedInBomSafetyNetException::class => [Level::Critical]], $levels);
    }

    public function testAProjectDeletedTogetherWithItsPartPasses(): void
    {
        $this->settings->refuseDeletingPartsUsedInBom = true;
        [$part, $project] = $this->partInABom();
        $partId = $part->getID();

        $this->em->remove($project);
        $this->em->remove($part);
        $this->em->flush();

        $this->em->clear();
        $this->assertNull($this->em->find(Part::class, $partId));
    }

    public function testWithTheSettingOffTheEntryIsUnlinkedAndKeepsTheName(): void
    {
        $this->settings->refuseDeletingPartsUsedInBom = false;
        [$part, , $entry] = $this->partInABom();
        $entryId = $entry->getID();

        $this->em->remove($part);
        $this->em->flush();

        $this->em->clear();
        $entry = $this->em->find(ProjectBOMEntry::class, $entryId);
        $this->assertNull($entry->getPart());
        $this->assertSame('Listener part', $entry->getName());
        $this->assertStringContainsString('Part was deleted: Listener part', (string) $entry->getComment());
    }
}
