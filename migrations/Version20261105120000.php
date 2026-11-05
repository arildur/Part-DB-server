<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20261105120000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Add the kind column to the parts table. Existing parts become manufacturing parts.';
    }

    /**
     * Dropping the column would turn meta parts into ordinary parts, so this is refused while any exist.
     */
    private function abortIfMetaPartsExist(): void
    {
        $table = $this->connection->getDatabasePlatform()->quoteSingleIdentifier('parts');
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table." WHERE kind <> 'manufacturing'");
        $this->abortIf($count > 0, sprintf('Can not roll back: %d parts are not manufacturing parts. Remove them first.', $count));
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `parts` ADD kind VARCHAR(255) DEFAULT 'manufacturing' NOT NULL");
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->abortIfMetaPartsExist();
        $this->addSql('ALTER TABLE `parts` DROP COLUMN kind');
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql("ALTER TABLE \"parts\" ADD COLUMN kind VARCHAR(255) DEFAULT 'manufacturing' NOT NULL");
    }

    public function sqLiteDown(Schema $schema): void
    {
        $this->abortIfMetaPartsExist();
        $this->addSql('ALTER TABLE "parts" DROP COLUMN kind');
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql("ALTER TABLE \"parts\" ADD kind VARCHAR(255) DEFAULT 'manufacturing' NOT NULL");
    }

    public function postgreSQLDown(Schema $schema): void
    {
        $this->abortIfMetaPartsExist();
        $this->addSql('ALTER TABLE "parts" DROP COLUMN kind');
    }
}
