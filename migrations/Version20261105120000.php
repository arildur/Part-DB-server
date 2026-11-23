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
     * Dropping the column turns every meta part into an ordinary part, keeping its row and data, so the log says how many.
     */
    private function warnAboutMetaParts(): void
    {
        $table = $this->connection->getDatabasePlatform()->quoteSingleIdentifier('parts');
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table." WHERE kind <> 'manufacturing'");
        $this->warnIf($count > 0, sprintf('%d parts are not manufacturing parts. They become ordinary parts, keeping their data.', $count));
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `parts` ADD kind VARCHAR(255) DEFAULT 'manufacturing' NOT NULL");
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->warnAboutMetaParts();
        $this->addSql('ALTER TABLE `parts` DROP COLUMN kind');
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql("ALTER TABLE \"parts\" ADD COLUMN kind VARCHAR(255) DEFAULT 'manufacturing' NOT NULL");
    }

    public function sqLiteDown(Schema $schema): void
    {
        $this->warnAboutMetaParts();
        $this->addSql('ALTER TABLE "parts" DROP COLUMN kind');
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql("ALTER TABLE \"parts\" ADD kind VARCHAR(255) DEFAULT 'manufacturing' NOT NULL");
    }

    public function postgreSQLDown(Schema $schema): void
    {
        $this->warnAboutMetaParts();
        $this->addSql('ALTER TABLE "parts" DROP COLUMN kind');
    }
}
