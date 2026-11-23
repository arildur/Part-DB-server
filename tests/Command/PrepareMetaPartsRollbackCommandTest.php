<?php
/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2025 Jan Böhmer (https://github.com/jbtronics)
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

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartConnectionStatus;
use App\Entity\Parts\PartKind;
use App\Entity\Parts\PartLot;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Runs partdb:meta-parts:prepare-rollback on saved parts, which the test database rolls back after each test.
 */
final class PrepareMetaPartsRollbackCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $application = new Application(self::$kernel);
        $this->tester = new CommandTester($application->find('partdb:meta-parts:prepare-rollback'));
    }

    private function part(PartKind $kind, string $name): Part
    {
        $part = (new Part())->setKind($kind);
        $part->setName($name);
        $part->setCategory($this->em->find(Category::class, 1));
        $this->em->persist($part);

        return $part;
    }

    /**
     * @param  array<string, PartConnectionStatus>  $parts  The status of each connected part by its name, in the order of position
     * @return array{0: Part, 1: array<string, Part>} The meta part and its connected parts by name
     */
    private function metaWith(string $name, array $parts): array
    {
        $meta = $this->part(PartKind::META, $name);
        $connected = [];
        $position = 1;
        foreach ($parts as $partName => $status) {
            $connected[$partName] = $this->part(PartKind::MANUFACTURING, $partName);
            $meta->addPartConnection((new PartConnection())->setConnectedPart($connected[$partName])->setPosition($position++)->setStatus($status));
        }

        return [$meta, $connected];
    }

    private function project(string $name): Project
    {
        $project = new Project();
        $project->setName($name);
        $this->em->persist($project);

        return $project;
    }

    private function bomEntry(Project $project, Part $part, float $quantity = 1, string $mountnames = '', string $comment = ''): ProjectBOMEntry
    {
        $entry = (new ProjectBOMEntry())->setQuantity($quantity)->setMountnames($mountnames)->setComment($comment);
        $part->addProjectBomEntry($entry);
        $project->addBomEntry($entry);

        return $entry;
    }

    private function lot(Part $part, float $amount): PartLot
    {
        $lot = (new PartLot())->setAmount($amount)->setDescription('Own lot');
        $part->addPartLot($lot);

        return $lot;
    }

    private function connectionsOf(int $metaId): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM part_connections WHERE id_meta_part = ?', [$metaId]);
    }

    /**
     * @param  list<string>  $inputs  The answers, after the one going on past the note on backups
     */
    private function runCommand(array $inputs, bool $dryRun = false): string
    {
        $this->em->flush();
        $this->em->clear();
        $this->tester->setInputs(['yes', ...$inputs]);
        $this->tester->execute($dryRun ? ['--dry-run' => true] : []);
        $this->em->clear();

        return $this->display();
    }

    /** The output with each run of white space as one space, as blocks wrap their text. */
    private function display(): string
    {
        return (string) preg_replace('/\s+/', ' ', $this->tester->getDisplay());
    }

    public function testListsEachMetaPartWithConnectionsAndKeepsBothWithoutInteraction(): void
    {
        [$first, $firstParts] = $this->metaWith('Rollback meta one', ['Rollback part A' => PartConnectionStatus::APPROVED,
            'Rollback part B' => PartConnectionStatus::UNDER_EVALUATION]);
        [$second] = $this->metaWith('Rollback meta two', ['Rollback part C' => PartConnectionStatus::USE_UP]);
        $withoutConnections = $this->part(PartKind::META, 'Rollback meta without connections');
        $lot = $this->lot($first, 5);
        $entry = $this->bomEntry($this->project('Rollback project'), $first, 2);
        $this->em->flush();
        [$firstId, $secondId, $lotId, $entryId, $aId] = [$first->getID(), $second->getID(), $lot->getID(), $entry->getID(), $firstParts['Rollback part A']->getID()];
        $this->em->clear();

        $this->tester->execute([], ['interactive' => false]);
        $output = $this->display();

        $this->assertSame(Command::SUCCESS, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('2 meta parts have connections', $output);
        $this->assertStringContainsString('Rollback meta one (ID '.$firstId.')', $output);
        $this->assertStringContainsString('Rollback meta two (ID '.$secondId.')', $output);
        $this->assertStringNotContainsString('Rollback meta without connections', $output);
        $this->assertStringContainsString('Used in the BOM of: Rollback project', $output);
        $this->assertStringContainsString('Own lots: 5', $output);
        $this->assertMatchesRegularExpression('/1\s+Rollback part A\s+'.$aId.'\s+approved/', $output, 'The connections by priority with status');
        $this->assertStringContainsString('under_evaluation', $output);
        $this->assertStringContainsString('Kept: 2, replaced: 0, skipped: 0', $output);
        $this->assertStringContainsString("doctrine:migrations:migrate 'DoctrineMigrations\\Version20260907090000'", $output);

        $this->assertSame(0, $this->connectionsOf($firstId));
        $this->assertSame(0, $this->connectionsOf($secondId));
        $kept = $this->em->find(Part::class, $firstId);
        $this->assertSame(PartKind::META, $kept->getKind(), 'The kind goes with the rollback, not with the command');
        $this->assertSame($firstId, $this->em->find(PartLot::class, $lotId)?->getPart()?->getID(), 'Keep leaves the own lots');
        $this->assertSame($firstId, $this->em->find(ProjectBOMEntry::class, $entryId)?->getPart()?->getID(), 'Keep leaves the BOM entries');
        $this->assertNotNull($this->em->find(Part::class, $aId), 'The connected parts are not touched');
        $this->assertNotNull($this->em->find(Part::class, $withoutConnections->getID()));
    }

    public function testReplacePointsTheBomEntriesAtThePrimarySourceAndDeletesTheMetaPart(): void
    {
        //The first by position is not usable, so the primary source is the second
        [$meta, $parts] = $this->metaWith('Rollback meta', ['Rollback not usable' => PartConnectionStatus::DO_NOT_USE,
            'Rollback primary' => PartConnectionStatus::APPROVED, 'Rollback other' => PartConnectionStatus::APPROVED]);
        $entry = $this->bomEntry($this->project('Rollback project'), $meta, 3, 'R1,R2,R3', 'A comment');
        $other = $this->bomEntry($this->project('Rollback second project'), $meta, 1);
        $this->em->flush();
        [$metaId, $entryId, $otherId] = [$meta->getID(), $entry->getID(), $other->getID()];
        $primaryId = $parts['Rollback primary']->getID();

        $output = $this->runCommand(['replace', '']);

        $this->assertSame(Command::SUCCESS, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Kept: 0, replaced: 1, skipped: 0', $output);
        $this->assertNull($this->em->find(Part::class, $metaId), 'The meta part is deleted');
        $this->assertSame(0, $this->connectionsOf($metaId));
        $entry = $this->em->find(ProjectBOMEntry::class, $entryId);
        $this->assertSame($primaryId, $entry->getPart()?->getID());
        $this->assertSame(3.0, $entry->getQuantity());
        $this->assertSame('R1,R2,R3', $entry->getMountnames());
        $this->assertSame('A comment', $entry->getComment());
        $this->assertNull($entry->getName(), 'Not unlinked as for a deleted part');
        $this->assertSame($primaryId, $this->em->find(ProjectBOMEntry::class, $otherId)?->getPart()?->getID());
        foreach ($parts as $part) {
            $this->assertNotNull($this->em->find(Part::class, $part->getID()), 'The connected parts stay');
        }
    }

    public function testReplaceTakesTheChosenConnectedPart(): void
    {
        [$meta, $parts] = $this->metaWith('Rollback meta', ['Rollback primary' => PartConnectionStatus::APPROVED,
            'Rollback chosen' => PartConnectionStatus::USE_UP]);
        $entry = $this->bomEntry($this->project('Rollback project'), $meta);
        $this->em->flush();
        $entryId = $entry->getID();
        $chosen = $parts['Rollback chosen'];

        $output = $this->runCommand(['replace', sprintf('Rollback chosen (ID %d, use_up)', $chosen->getID())]);

        $this->assertSame(Command::SUCCESS, $this->tester->getStatusCode(), $output);
        $this->assertSame($chosen->getID(), $this->em->find(ProjectBOMEntry::class, $entryId)?->getPart()?->getID());
    }

    public function testReplaceIsRefusedWhileTheMetaPartHasOwnLots(): void
    {
        [$meta] = $this->metaWith('Rollback meta', ['Rollback part' => PartConnectionStatus::APPROVED]);
        $this->lot($meta, 4);
        $entry = $this->bomEntry($this->project('Rollback project'), $meta);
        $this->em->flush();
        [$metaId, $entryId] = [$meta->getID(), $entry->getID()];

        $output = $this->runCommand(['replace', 'keep']);

        $this->assertStringContainsString('It can not be replaced: it has 1 lots of its own', $output);
        $this->assertStringContainsString('Kept: 1, replaced: 0', $output);
        $this->assertNotNull($this->em->find(Part::class, $metaId));
        $this->assertSame($metaId, $this->em->find(ProjectBOMEntry::class, $entryId)?->getPart()?->getID());
        $this->assertCount(1, $this->em->find(Part::class, $metaId)->getPartLots());
    }

    public function testReplaceIsRefusedWhileTheMetaPartIsABuildPart(): void
    {
        [$meta] = $this->metaWith('Rollback meta', ['Rollback part' => PartConnectionStatus::APPROVED]);
        $this->project('Rollback built project')->setBuildPart($meta);
        $this->em->flush();
        $metaId = $meta->getID();

        $output = $this->runCommand(['replace', 'keep']);

        $this->assertStringContainsString('It can not be replaced: it is the build part of the project Rollback built project', $output);
        $this->assertNotNull($this->em->find(Part::class, $metaId));
        $this->assertSame(0, $this->connectionsOf($metaId), 'Kept after the refusal');
    }

    public function testReplaceIsRefusedWhileABomAlreadyHasTheChosenPart(): void
    {
        [$meta, $parts] = $this->metaWith('Rollback meta', ['Rollback primary' => PartConnectionStatus::APPROVED]);
        $project = $this->project('Rollback project');
        $entry = $this->bomEntry($project, $meta);
        $this->bomEntry($project, $parts['Rollback primary']);
        $this->em->flush();
        [$metaId, $entryId] = [$meta->getID(), $entry->getID()];

        $output = $this->runCommand(['replace', '', 'skip']);

        $this->assertStringContainsString('It can not be replaced by Rollback primary: the BOM of Rollback project already has an entry for it', $output);
        $this->assertStringContainsString('Kept: 0, replaced: 0, skipped: 1', $output);
        $this->assertSame($metaId, $this->em->find(ProjectBOMEntry::class, $entryId)?->getPart()?->getID());
        $this->assertSame(1, $this->connectionsOf($metaId), 'Skip leaves the connections');
        $this->assertStringContainsString('1 part connections are left', $output);
    }

    public function testDryRunChangesNothing(): void
    {
        [$keep] = $this->metaWith('Rollback meta kept', ['Rollback part A' => PartConnectionStatus::APPROVED]);
        [$replace] = $this->metaWith('Rollback meta replaced', ['Rollback part B' => PartConnectionStatus::APPROVED]);
        $entry = $this->bomEntry($this->project('Rollback project'), $replace);
        $this->em->flush();
        [$keepId, $replaceId, $entryId] = [$keep->getID(), $replace->getID(), $entry->getID()];

        $output = $this->runCommand(['keep', 'replace', ''], true);

        $this->assertSame(Command::SUCCESS, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Would keep it and remove its 1 connections', $output);
        $this->assertStringContainsString('Would point its 1 BOM entries at Rollback part B', $output);
        $this->assertStringContainsString('No part connection would be left', $output);
        $this->assertSame(1, $this->connectionsOf($keepId));
        $this->assertSame(1, $this->connectionsOf($replaceId));
        $this->assertSame($replaceId, $this->em->find(ProjectBOMEntry::class, $entryId)?->getPart()?->getID());
    }

    public function testWithNoMetaPartWithConnectionsItSaysSoAndNamesTheRollback(): void
    {
        $this->part(PartKind::META, 'Rollback meta without connections');

        $output = $this->runCommand([]);

        $this->assertSame(Command::SUCCESS, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('0 meta parts have connections', $output);
        $this->assertStringContainsString('No part connection is left', $output);
    }
}
