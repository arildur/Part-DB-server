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

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Command\Migrations\PrepareMetaPartsRollbackCommand;
use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartKind;
use App\Entity\Parts\PartLot;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Rolls the meta-part migrations back on the test database, whichever it is, after partdb:meta-parts:prepare-rollback,
 * and up again. A migration changes the schema, which no transaction can roll back on every database, so this test
 * saves its rows for real and cleans up after itself.
 */
#[SkipDatabaseRollback]
final class MetaPartsRollbackTest extends KernelTestCase
{
    /** The meta-part migrations, newest first: the order of a rollback. */
    private const MIGRATIONS = [
        'DoctrineMigrations\\Version20261123100000',
        'DoctrineMigrations\\Version20261120110000',
        'DoctrineMigrations\\Version20261120100000',
    ];

    private Connection $db;
    /** @var list<int> */
    private array $partIds = [];
    /** @var list<int> */
    private array $projectIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get('doctrine.dbal.default_connection');
    }

    protected function tearDown(): void
    {
        foreach ($this->projectIds as $id) {
            $this->db->executeStatement('DELETE FROM project_bom_entries WHERE id_device = ?', [$id]);
            $this->db->executeStatement('DELETE FROM projects WHERE id = ?', [$id]);
        }
        if ($this->partIds !== []) {
            $ids = implode(', ', $this->partIds);
            $this->db->executeStatement("DELETE FROM part_lots WHERE id_part IN ($ids)");
            $this->db->executeStatement("DELETE FROM part_connections WHERE id_meta_part IN ($ids) OR id_connected_part IN ($ids)");
            $this->db->executeStatement('DELETE FROM '.$this->parts()." WHERE id IN ($ids)");
        }
        parent::tearDown();
    }

    private function parts(): string
    {
        return $this->db->getDatabasePlatform()->quoteSingleIdentifier('parts');
    }

    /**
     * Runs a console command on a new kernel, as Doctrine Migrations runs once per kernel. Through CommandTester, as
     * the application would take its verbosity from SHELL_VERBOSITY, which phpunit.xml.dist sets to quiet.
     * @return array{0: int, 1: string} The exit code and the output, with the message of an exception
     */
    private function console(string $command, array $input = []): array
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find($command));
        try {
            $code = $tester->execute($input, ['interactive' => false]);
            $text = $tester->getDisplay();
        } catch (\Throwable $exception) {
            $code = 1;
            $text = $tester->getDisplay().$exception->getMessage();
        }
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->db = self::getContainer()->get('doctrine.dbal.default_connection');

        return [$code, (string) preg_replace('/\s+/', ' ', $text)];
    }

    private function migrate(string $version, string $direction): string
    {
        [$code, $output] = $this->console('doctrine:migrations:execute', ['versions' => [$version], '--'.$direction => true]);
        $this->assertSame(0, $code, $output);

        return $output;
    }

    /**
     * Saves a meta part connected to a manufacturing part, with a lot of its own and a BOM entry.
     * @return array{meta: int, connected: int, lot: int, entry: int}
     */
    private function saveMetaPart(): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $category = $em->find(Category::class, 1);
        $meta = (new Part())->setKind(PartKind::META);
        $meta->setName('Rollback meta');
        $meta->setDescription('Kept through the rollback');
        $meta->setCategory($category);
        $connected = new Part();
        $connected->setName('Rollback connected');
        $connected->setCategory($category);
        $meta->addPartConnection((new PartConnection())->setConnectedPart($connected)->setPosition(1));
        $lot = (new PartLot())->setAmount(7);
        $meta->addPartLot($lot);
        $project = new Project();
        $project->setName('Rollback project');
        $entry = (new ProjectBOMEntry())->setQuantity(2);
        $meta->addProjectBomEntry($entry);
        $project->addBomEntry($entry);
        foreach ([$meta, $connected, $project] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $this->partIds = [$meta->getID(), $connected->getID()];
        $this->projectIds = [$project->getID()];

        return ['meta' => $meta->getID(), 'connected' => $connected->getID(), 'lot' => $lot->getID(), 'entry' => $entry->getID()];
    }

    public function testTheMetaPartMigrationsAreTheLastOnesAfterTheTargetOfTheRollback(): void
    {
        //Migrating to the target must roll back only the meta-part migrations, none of Part-DB's own
        $versions = array_map(static fn ($migration): string => (string) $migration->getVersion(),
            self::getContainer()->get(DependencyFactory::class)->getMigrationRepository()->getMigrations()->getItems());
        $target = array_search(PrepareMetaPartsRollbackCommand::MIGRATION_BEFORE_META_PARTS, $versions, true);

        $this->assertIsInt($target);
        $this->assertSame(array_reverse(self::MIGRATIONS), array_slice($versions, $target + 1));
    }

    public function testTheConnectionsRollbackIsRefusedNamingTheCommand(): void
    {
        $ids = $this->saveMetaPart();

        [$code, $output] = $this->console('doctrine:migrations:execute', ['versions' => [self::MIGRATIONS[1]], '--down' => true]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('Can not roll back: 1 part connections exist.', $output);
        $this->assertStringContainsString('doctrine:migrations:migrate', preg_replace('/\s+/', '', $output));
        $this->assertStringContainsString('partdb:meta-parts:prepare-rollback', preg_replace('/\s+/', '', $output));
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM part_connections WHERE id_meta_part = ?', [$ids['meta']]), 'Nothing changed');
    }

    public function testAfterTheCommandAFullRollbackKeepsTheMetaPartAsAnOrdinaryPart(): void
    {
        $ids = $this->saveMetaPart();
        [$code, $output] = $this->console('partdb:meta-parts:prepare-rollback');
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Kept: 1, replaced: 0, skipped: 0', $output);
        $before = $this->db->fetchAssociative('SELECT name, description FROM '.$this->parts().' WHERE id = ?', [$ids['meta']]);

        $done = [];
        try {
            foreach (self::MIGRATIONS as $version) {
                $output = $this->migrate($version, 'down');
                $done[] = $version;
            }
            $this->assertStringContainsString('1 parts are not manufacturing parts. They become ordinary parts, keeping their data.', $output);

            $columns = array_map('strtolower', array_keys($this->db->createSchemaManager()->introspectTable('parts')->getColumns()));
            $this->assertNotContains('kind', $columns);
            $this->assertFalse($this->db->createSchemaManager()->tablesExist(['part_connections']));
            $this->assertSame($before, $this->db->fetchAssociative('SELECT name, description FROM '.$this->parts().' WHERE id = ?', [$ids['meta']]));
            $this->assertSame($ids['meta'], (int) $this->db->fetchOne('SELECT id_part FROM part_lots WHERE id = ?', [$ids['lot']]));
            $this->assertSame($ids['meta'], (int) $this->db->fetchOne('SELECT id_part FROM project_bom_entries WHERE id = ?', [$ids['entry']]));
            $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.$this->parts().' WHERE id = ?', [$ids['connected']]));

            [$code, $output] = $this->console('partdb:meta-parts:prepare-rollback');
            $this->assertSame(1, $code);
            $this->assertStringContainsString('The table of parts has no kind column', $output);
        } finally {
            foreach (array_reverse($done) as $version) {
                $this->migrate($version, 'up');
            }
        }

        //Up again, the kind is gone: the part comes back as a manufacturing part
        $this->assertSame('manufacturing', $this->db->fetchOne('SELECT kind FROM '.$this->parts().' WHERE id = ?', [$ids['meta']]));
    }

    public function testTheCommandRefusesADatabaseNotAtTheLatestVersion(): void
    {
        $this->migrate(self::MIGRATIONS[0], 'down');
        try {
            [$code, $output] = $this->console('partdb:meta-parts:prepare-rollback');
        } finally {
            $this->migrate(self::MIGRATIONS[0], 'up');
        }

        $this->assertSame(1, $code);
        $this->assertStringContainsString('The database is not at the latest version: 1 migrations are not applied. Run "php bin/console doctrine:migrations:migrate" first', $output);
    }
}
