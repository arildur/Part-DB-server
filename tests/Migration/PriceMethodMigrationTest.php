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

use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261123100000;
use Psr\Log\AbstractLogger;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs the migration of the price method down and up again on the test database, whichever it is. A migration changes the
 * schema, which no transaction can roll back on every database, so this test cleans up after itself.
 */
#[SkipDatabaseRollback]
final class PriceMethodMigrationTest extends KernelTestCase
{
    private const VERSION = 'DoctrineMigrations\\Version20261123100000';

    private Connection $db;
    /** @var list<int> */
    private array $partIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get('doctrine.dbal.default_connection');
    }

    protected function tearDown(): void
    {
        if ($this->partIds !== []) {
            $this->db->executeStatement('DELETE FROM '.$this->parts().' WHERE id IN ('.implode(', ', $this->partIds).')');
        }
        parent::tearDown();
    }

    private function parts(): string
    {
        return $this->db->getDatabasePlatform()->quoteSingleIdentifier('parts');
    }

    /**
     * @return string The output of the command, holding the warnings of the migration
     */
    private function migrate(string $version, string $direction): string
    {
        //Doctrine Migrations runs once per kernel, so each run gets a new one
        self::ensureKernelShutdown();
        self::bootKernel();
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        $code = $application->run(new ArrayInput([
            'command' => 'doctrine:migrations:execute',
            'versions' => [$version],
            '--'.$direction => true,
            '--no-interaction' => true,
        ]), $output);
        $text = $output->fetch();
        $this->assertSame(0, $code, $text);
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->db = self::getContainer()->get('doctrine.dbal.default_connection');

        return $text;
    }

    /**
     * Runs the checks of the migration in the given direction without its SQL, which is only queued, and returns its warnings.
     */
    private function warningsOf(string $direction): string
    {
        $logger = new class extends AbstractLogger {
            public string $messages = '';

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages .= $message."\n";
            }
        };
        //Migrations are not autoloaded; Doctrine Migrations loads them the same way, once
        require_once dirname(__DIR__, 2).'/migrations/Version20261123100000.php';
        $migration = new Version20261123100000($this->db, $logger);
        $migration->{$direction}(new Schema());

        return $logger->messages;
    }

    /** Inserts a row in SQL, with a value for each required column, so that no entity or log entry is made. */
    private function insertRow(string $table, array $values): int
    {
        foreach ($this->db->createSchemaManager()->introspectTable($table)->getColumns() as $column) {
            $columnName = $column->getName();
            if (isset($values[$columnName]) || !$column->getNotnull() || $column->getDefault() !== null || $column->getAutoincrement() || $columnName === 'id') {
                continue;
            }
            $type = strtolower((new \ReflectionClass($column->getType()))->getShortName());
            $values[$columnName] = match (true) {
                str_contains($type, 'date') => '2020-01-02 03:04:05',
                str_contains($type, 'int'), str_contains($type, 'float'), str_contains($type, 'decimal'), str_contains($type, 'bool') => 0,
                default => '',
            };
        }
        $quoted = [];
        foreach ($values as $columnName => $value) {
            $quoted[$this->db->quoteSingleIdentifier($columnName)] = $value;
        }
        $quotedTable = $this->db->getDatabasePlatform()->quoteSingleIdentifier($table);
        $this->db->insert($quotedTable, $quoted);

        return (int) $this->db->fetchOne('SELECT MAX(id) FROM '.$quotedTable);
    }

    private function insertPart(string $name, string $kind, array $values = []): int
    {
        $id = $this->insertRow('parts', $values + ['name' => $name, 'kind' => $kind, 'id_category' => 1]);
        $this->partIds[] = $id;

        return $id;
    }

    /**
     * A currency of the fixtures: their fixed IDs leave the sequence of PostgreSQL behind, so a new one can collide.
     */
    private function existingCurrency(): int
    {
        return (int) $this->db->fetchOne('SELECT MIN(id) FROM currencies');
    }

    /**
     * @return list<string> The names of the columns of the table, in lower case
     */
    private function columns(string $table): array
    {
        return array_values(array_map(static fn ($column): string => strtolower($column->getName()), $this->db->createSchemaManager()->introspectTable($table)->getColumns()));
    }

    public function testDownDropsAndUpBringsBackThePriceColumns(): void
    {
        $plain = $this->insertPart('Migration plain', 'manufacturing');
        $currency = $this->existingCurrency();
        $meta = $this->insertPart('Migration meta', 'meta', ['price_method' => 'cheapest', 'price_user_value' => '0.25', 'price_user_currency_id' => $currency]);
        $this->assertSame('system_default', $this->db->fetchOne('SELECT price_method FROM '.$this->parts().' WHERE id = ?', [$plain]), 'The default is system default');

        $this->assertStringContainsString('1 parts have a price method other than system default', $this->warningsOf('down'), 'The down step names what it loses');
        $this->migrate(self::VERSION, 'down');
        try {
            $this->assertNotContains('price_method', $this->columns('parts'));
            $this->assertNotContains('price_user_value', $this->columns('parts'));
            $this->assertNotContains('price_user_currency_id', $this->columns('parts'));
            $this->assertSame('Migration meta', $this->db->fetchOne('SELECT name FROM '.$this->parts().' WHERE id = ?', [$meta]), 'The rows stay');

        } finally {
            $this->migrate(self::VERSION, 'up');
        }

        $rows = $this->db->fetchAllAssociative('SELECT id, price_method, price_user_value, price_user_currency_id FROM '.$this->parts().' WHERE id IN (?, ?) ORDER BY id', [$plain, $meta]);
        $this->assertSame(['system_default', 'system_default'], array_column($rows, 'price_method'), 'Existing parts follow the setting');
        $this->assertSame([null, null], array_column($rows, 'price_user_value'));
        $this->assertSame([null, null], array_column($rows, 'price_user_currency_id'));
    }

    public function testAUserValueWithItsCurrencyIsStored(): void
    {
        $currency = $this->existingCurrency();
        $meta = $this->insertPart('Migration priced meta', 'meta', ['price_method' => 'user_value', 'price_user_value' => '1.23456', 'price_user_currency_id' => $currency]);

        $row = $this->db->fetchAssociative('SELECT price_method, price_user_value, price_user_currency_id FROM '.$this->parts().' WHERE id = ?', [$meta]);
        $this->assertSame('user_value', $row['price_method']);
        $this->assertEqualsWithDelta(1.23456, (float) $row['price_user_value'], 0.000001);
        $this->assertSame($currency, (int) $row['price_user_currency_id']);
    }

    public function testThePriceColumnsAreAsMapped(): void
    {
        $table = $this->db->createSchemaManager()->introspectTable('parts');

        $this->assertSame(32, $table->getColumn('price_method')->getLength());
        $this->assertSame('system_default', $table->getColumn('price_method')->getDefault());
        $this->assertTrue($table->getColumn('price_method')->getNotnull());
        $this->assertSame(5, $table->getColumn('price_user_value')->getScale());
        $this->assertFalse($table->getColumn('price_user_value')->getNotnull());
        $this->assertFalse($table->getColumn('price_user_currency_id')->getNotnull());
        $foreignKeys = array_filter($table->getForeignKeys(), static fn ($key): bool => array_map('strtolower', $key->getLocalColumns()) === ['price_user_currency_id']);
        $this->assertCount(1, $foreignKeys);
        $this->assertSame('currencies', reset($foreignKeys)->getForeignTableName());
    }

    public function testTheMappingAndTheSchemaAgreeAfterTheMigration(): void
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $sql = (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata());

        $this->assertSame([], array_values(array_filter($sql, static fn (string $line): bool => str_contains($line, 'price_')
            || (bool) preg_match('/^(ALTER TABLE|CREATE TABLE|DROP TABLE|CREATE (UNIQUE )?INDEX \S+ ON) [`"]?parts[`"]?[\s(;]/i', $line))));
    }
}
