<?php
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

declare(strict_types=1);


namespace App\Command\Migrations;

use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartKind;
use App\Entity\ProjectSystem\Project;
use App\Services\LogSystem\EventCommentHelper;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prepares a rollback of the meta-part migrations, whose connections migration refuses to go down while connections
 * exist. For each meta part with connections it asks whether to keep it, removing only its connections, so that it
 * becomes an ordinary part when the kind is dropped, or to replace it in the BOM entries by one of its connected
 * parts and delete it. Keep is the default, so an unattended run never deletes a part. Each part is done in one
 * transaction through the entity manager, so the event log records the changes; --dry-run changes nothing.
 * @see \App\Tests\Command\PrepareMetaPartsRollbackCommandTest
 */
#[AsCommand('partdb:meta-parts:prepare-rollback', 'Removes the connections of meta parts, keeping or replacing each meta part, so that the meta-part migrations can be rolled back')]
final class PrepareMetaPartsRollbackCommand extends Command
{
    /** The last migration before the meta parts: rolling back to it removes them from the schema. */
    public const MIGRATION_BEFORE_META_PARTS = 'DoctrineMigrations\\Version20261119120000';

    private const KEEP = 'keep';
    private const REPLACE = 'replace';
    private const SKIP = 'skip';

    public function __construct(private readonly EntityManagerInterface $entityManager,
        private readonly DependencyFactory $dependencyFactory, private readonly EventCommentHelper $commentHelper)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
            Run this before rolling back the database past the meta parts. The rollback refuses while part connections
            exist, so for each meta part with connections this command asks what to do:

              keep     the part stays, with its own lots, BOM entries and history; only its connections are removed.
                       It becomes an ordinary part when the rollback drops the kind of parts. This is the default,
                       and the answer under --no-interaction.
              replace  every BOM entry of the meta part is pointed at one of its connected parts (by default the
                       primary source), keeping quantity, mount names and comment, and the meta part is deleted.
                       Refused while the meta part has lots of its own, is the build part of a project, or a BOM
                       that uses it already has an entry for the chosen part.
              skip     nothing changes for this part now; the rollback stays refused while it has connections.

            Meta parts without connections need nothing. Back up first (partdb:backup). The database must be at the
            latest migration: if a rollback already stopped at the connections, migrate up first.
            HELP);
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Ask the same questions and show what would be done, changing nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if (!$this->hasKindColumn()) {
            $io->error('The table of parts has no kind column: the meta-part migrations are not applied or already rolled back, so there is nothing to prepare.');

            return Command::FAILURE;
        }
        //The parts are read as entities, which need the latest schema; a rollback stopped at the connections has
        //already taken the later migrations down
        $newMigrations = count($this->dependencyFactory->getMigrationStatusCalculator()->getNewMigrations()->getItems());
        if ($newMigrations > 0) {
            $io->error(sprintf('The database is not at the latest version: %d migrations are not applied. Run "php bin/console doctrine:migrations:migrate" first, then this command again.', $newMigrations));

            return Command::FAILURE;
        }

        $io->note('Make a backup before going on, for example with "php bin/console partdb:backup --full backup.zip".');
        if (!$io->confirm('Go on?', true)) {
            $io->writeln('Nothing was changed.');

            return Command::SUCCESS;
        }
        if ($dryRun) {
            $io->note('Dry run: nothing will be changed.');
        }

        $ids = $this->findMetaPartsWithConnections();
        $io->writeln(sprintf('%d meta parts have connections.', count($ids)));

        $done = [self::KEEP => 0, self::REPLACE => 0, self::SKIP => 0];
        $removedInDryRun = 0;
        foreach ($ids as $id) {
            //Each part is read fresh, so that nothing left over from the one before reaches its transaction
            $this->entityManager->clear();
            $part = $this->entityManager->find(Part::class, $id);
            if (!$part instanceof Part) {
                continue;
            }

            $this->show($io, $part);
            [$action, $target] = $this->ask($io, $part);
            if ($dryRun && $action !== self::SKIP) {
                $removedInDryRun += $part->getPartConnections()->count();
            }
            try {
                $this->carryOut($io, $part, $action, $target, $dryRun);
            } catch (\Throwable $exception) {
                $io->error(sprintf('%s (ID %d) was left as it was, as saving failed: %s', $part->getName(), $id, $exception->getMessage()));

                return Command::FAILURE;
            }
            $done[$action]++;
        }

        $io->section('Summary');
        $io->writeln(sprintf('Kept: %d, replaced: %d, skipped: %d.', $done[self::KEEP], $done[self::REPLACE], $done[self::SKIP]));

        $left = $this->countConnections() - $removedInDryRun;
        if ($left > 0) {
            $io->warning(sprintf('%d part connections %s left, and the rollback refuses while any exist.', $left, $dryRun ? 'would be' : 'are'));
        } else {
            $io->success(sprintf("No part connection %s left. Roll back with:\nphp bin/console doctrine:migrations:migrate '%s'",
                $dryRun ? 'would be' : 'is', self::MIGRATION_BEFORE_META_PARTS));
        }
        if ($dryRun) {
            $io->note('Dry run: nothing was changed.');
        }

        return Command::SUCCESS;
    }

    private function hasKindColumn(): bool
    {
        $columns = $this->entityManager->getConnection()->createSchemaManager()->introspectTable('parts')->getColumns();
        foreach ($columns as $column) {
            if (strtolower($column->getName()) === 'kind') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int> The IDs of the meta parts with at least one connection, in ID order
     */
    private function findMetaPartsWithConnections(): array
    {
        $ids = $this->entityManager->createQuery('SELECT DISTINCT IDENTITY(c.meta_part) AS id FROM '.PartConnection::class
            .' c JOIN c.meta_part p WHERE p.kind = :kind ORDER BY id')
            ->setParameter('kind', PartKind::META->value)
            ->getSingleColumnResult();

        return array_map('intval', $ids);
    }

    private function countConnections(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM part_connections');
    }

    /**
     * Shows the meta part: the projects whose BOM uses it, its own lots and its connections in their order of priority.
     */
    private function show(SymfonyStyle $io, Part $part): void
    {
        $io->section(sprintf('%s (ID %d)', $part->getName(), $part->getID()));

        $projects = array_map(static fn (Project $project): string => $project->getName(), $this->projectsUsing($part));
        $io->writeln('Used in the BOM of: '.($projects === [] ? 'no project' : implode(', ', $projects)));
        if ($part->getBuiltProject() instanceof Project) {
            $io->writeln('It is the build part of the project '.$part->getBuiltProject()->getName());
        }

        $lots = [];
        foreach ($part->getPartLots() as $lot) {
            $lots[] = sprintf('%s at %s%s', $lot->isInstockUnknown() ? 'unknown amount' : $lot->getAmount(),
                $lot->getStorageLocation()?->getFullPath() ?? 'no storage location',
                $lot->getDescription() !== '' ? ' ('.$lot->getDescription().')' : '');
        }
        $io->writeln('Own lots: '.($lots === [] ? 'none' : implode('; ', $lots)));

        $rows = [];
        foreach ($part->getOrderedConnections() as $index => $connection) {
            $rows[] = [$index + 1, $connection->getConnectedPart()?->getName() ?? '', $connection->getConnectedPart()?->getID() ?? '',
                $connection->getStatus()->value];
        }
        $io->table(['Priority', 'Connected part', 'ID', 'Status'], $rows);
    }

    /**
     * Asks what to do with the meta part until the answer can be carried out: a replace that is blocked says why and
     * asks again, with keep still possible.
     * @return array{0: string, 1: Part|null} The action, and for a replace the part taking the place of the meta part
     */
    private function ask(SymfonyStyle $io, Part $part): array
    {
        while (true) {
            $action = $io->choice('Keep it as an ordinary part, removing its connections, replace it in the BOM entries by a connected part and delete it, or skip it for now?',
                [self::KEEP, self::REPLACE, self::SKIP], self::KEEP);
            if ($action !== self::REPLACE) {
                return [$action, null];
            }

            $blocks = [];
            if (!$part->getPartLots()->isEmpty()) {
                $blocks[] = sprintf('it has %d lots of its own', $part->getPartLots()->count());
            }
            if ($part->getBuiltProject() instanceof Project) {
                $blocks[] = 'it is the build part of the project '.$part->getBuiltProject()->getName();
            }
            if ($blocks !== []) {
                $io->warning('It can not be replaced: '.implode(', and ', $blocks).'.');
                continue;
            }

            $target = $this->askTarget($io, $part);
            $conflicts = array_map(static fn (Project $project): string => $project->getName(), $this->projectsHolding($part, $target));
            if ($conflicts !== []) {
                $io->warning(sprintf('It can not be replaced by %s: the BOM of %s already has an entry for it.', $target->getName(), implode(', ', $conflicts)));
                continue;
            }

            return [self::REPLACE, $target];
        }
    }

    /**
     * Asks which connected part takes the place of the meta part, offering them in their order of priority with the
     * primary source as default: the first usable one, or the first by position when none is usable.
     */
    private function askTarget(SymfonyStyle $io, Part $part): Part
    {
        $parts = [];
        $labels = [];
        foreach ($part->getOrderedConnections() as $connection) {
            $connected = $connection->getConnectedPart();
            if ($connected instanceof Part) {
                $parts[] = $connected;
                $labels[] = sprintf('%s (ID %d, %s)', $connected->getName(), $connected->getID(), $connection->getStatus()->value);
            }
        }
        $label = $io->choice('Which connected part takes its place?', $labels, $labels[0]);

        return $parts[(int) array_search($label, $labels, true)];
    }

    /**
     * @return list<Project> The projects whose BOM uses the part, each once, by name
     */
    private function projectsUsing(Part $part): array
    {
        $projects = [];
        foreach ($part->getProjectBomEntries() as $entry) {
            $project = $entry->getProject();
            if ($project instanceof Project) {
                $projects[$project->getID()] = $project;
            }
        }
        $projects = array_values($projects);
        usort($projects, static fn (Project $a, Project $b): int => strnatcasecmp($a->getName(), $b->getName()));

        return $projects;
    }

    /**
     * @return list<Project> The projects using the meta part whose BOM already has an entry for the target part
     */
    private function projectsHolding(Part $metaPart, Part $target): array
    {
        return array_values(array_filter($this->projectsUsing($metaPart), static function (Project $project) use ($target): bool {
            foreach ($project->getBomEntries() as $entry) {
                if ($entry->getPart() === $target) {
                    return true;
                }
            }

            return false;
        }));
    }

    private function carryOut(SymfonyStyle $io, Part $part, string $action, ?Part $target, bool $dryRun): void
    {
        $connections = $part->getPartConnections()->count();
        $entries = $part->getProjectBomEntries()->count();

        if ($action === self::SKIP) {
            $io->writeln('Skipped: its connections stay.');

            return;
        }

        if ($action === self::KEEP) {
            if ($dryRun) {
                $io->writeln(sprintf('Would keep it and remove its %d connections.', $connections));

                return;
            }
            $this->inTransaction(function (EntityManagerInterface $entityManager) use ($part): void {
                foreach ($part->getPartConnections()->toArray() as $connection) {
                    $part->removePartConnection($connection);
                    $connection->getConnectedPart()?->getConnectionsAsPart()->removeElement($connection);
                    $entityManager->remove($connection);
                }
            });
            $io->writeln(sprintf('Kept, and its %d connections removed.', $connections));

            return;
        }

        \assert($target instanceof Part);
        if ($dryRun) {
            $io->writeln(sprintf('Would point its %d BOM entries at %s (ID %d) and delete it.', $entries, $target->getName(), $target->getID()));

            return;
        }
        $this->inTransaction(function (EntityManagerInterface $entityManager) use ($part, $target): void {
            //Out of the collection of the meta part, or the deletion would unlink the entries from any part
            foreach ($part->getProjectBomEntries()->toArray() as $entry) {
                $part->removeProjectBomEntry($entry);
                $target->addProjectBomEntry($entry);
            }
            foreach ($part->getPartConnections() as $connection) {
                $connection->getConnectedPart()?->getConnectionsAsPart()->removeElement($connection);
            }
            //Its connections, parameters and attachments go with it
            $entityManager->remove($part);
        });
        $io->writeln(sprintf('Replaced: its %d BOM entries point at %s (ID %d), and it is deleted.', $entries, $target->getName(), $target->getID()));
    }

    /**
     * Saves the changes of one part in one transaction, with a comment in the event log. On an error the transaction
     * is rolled back and the exception rethrown.
     */
    private function inTransaction(\Closure $changes): void
    {
        $this->commentHelper->setMessage('Prepared for rolling back the meta-part migrations');
        try {
            $this->entityManager->wrapInTransaction($changes);
        } finally {
            $this->commentHelper->clearMessage();
        }
    }
}
