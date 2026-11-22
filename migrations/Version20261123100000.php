<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20261123100000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Add the price method and the user value of the price to parts.';
    }

    private function warnAboutPriceMethods(): void
    {
        $table = $this->connection->getDatabasePlatform()->quoteSingleIdentifier('parts');
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table." WHERE price_method <> 'system_default' OR price_user_value IS NOT NULL");
        $this->warnIf($count > 0, sprintf('%d parts have a price method other than system default or a user value for their price. These are lost.', $count));
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `parts` ADD price_method VARCHAR(32) DEFAULT 'system_default' NOT NULL, ADD price_user_value NUMERIC(11, 5) DEFAULT NULL, ADD price_user_currency_id INT DEFAULT NULL");
        $this->addSql('ALTER TABLE `parts` ADD CONSTRAINT FK_6940A7FE6244553C FOREIGN KEY (price_user_currency_id) REFERENCES currencies (id)');
        $this->addSql('CREATE INDEX IDX_6940A7FE6244553C ON `parts` (price_user_currency_id)');
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->warnAboutPriceMethods();
        $this->addSql('ALTER TABLE `parts` DROP FOREIGN KEY FK_6940A7FE6244553C');
        $this->addSql('DROP INDEX IDX_6940A7FE6244553C ON `parts`');
        $this->addSql('ALTER TABLE `parts` DROP COLUMN price_method, DROP COLUMN price_user_value, DROP COLUMN price_user_currency_id');
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql("ALTER TABLE \"parts\" ADD COLUMN price_method VARCHAR(32) DEFAULT 'system_default' NOT NULL");
        $this->addSql('ALTER TABLE "parts" ADD COLUMN price_user_value NUMERIC(11, 5) DEFAULT NULL');
        $this->addSql('ALTER TABLE "parts" ADD COLUMN price_user_currency_id INTEGER DEFAULT NULL CONSTRAINT FK_6940A7FE6244553C REFERENCES currencies (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_6940A7FE6244553C ON "parts" (price_user_currency_id)');
    }

    /**
     * SQLite can not drop a column with a foreign key, so the table of parts is built again without the price columns.
     */
    public function sqLiteDown(Schema $schema): void
    {
        $this->warnAboutPriceMethods();
        $this->addSql('CREATE TEMPORARY TABLE __temp__parts AS SELECT id, id_preview_attachment, id_category, id_footprint, id_part_unit, id_manufacturer, id_part_custom_state, order_orderdetails_id, built_project_id, datetime_added, name, last_modified, needs_review, tags, mass, description, comment, visible, favorite, minamount, manufacturer_product_url, manufacturer_product_number, manufacturing_status, order_quantity, manual_order, ipn, provider_reference_provider_key, provider_reference_provider_id, provider_reference_provider_url, provider_reference_last_updated, eda_info_reference_prefix, eda_info_value, eda_info_invisible, eda_info_exclude_from_bom, eda_info_exclude_from_board, eda_info_exclude_from_sim, eda_info_kicad_symbol, eda_info_kicad_footprint, gtin, kind FROM "parts"');
        $this->addSql('DROP TABLE "parts"');
        $this->addSql('CREATE TABLE "parts" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, id_preview_attachment INTEGER DEFAULT NULL, id_category INTEGER NOT NULL, id_footprint INTEGER DEFAULT NULL, id_part_unit INTEGER DEFAULT NULL, id_manufacturer INTEGER DEFAULT NULL, id_part_custom_state INTEGER DEFAULT NULL, order_orderdetails_id INTEGER DEFAULT NULL, built_project_id INTEGER DEFAULT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, name VARCHAR(255) NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, needs_review BOOLEAN NOT NULL, tags CLOB NOT NULL, mass DOUBLE PRECISION DEFAULT NULL, description CLOB NOT NULL, comment CLOB NOT NULL, visible BOOLEAN NOT NULL, favorite BOOLEAN NOT NULL, minamount DOUBLE PRECISION NOT NULL, manufacturer_product_url CLOB NOT NULL, manufacturer_product_number VARCHAR(255) NOT NULL, manufacturing_status VARCHAR(255) DEFAULT NULL, order_quantity INTEGER NOT NULL, manual_order BOOLEAN NOT NULL, ipn VARCHAR(100) DEFAULT NULL, provider_reference_provider_key VARCHAR(255) DEFAULT NULL, provider_reference_provider_id VARCHAR(255) DEFAULT NULL, provider_reference_provider_url VARCHAR(2048) DEFAULT NULL, provider_reference_last_updated DATETIME DEFAULT NULL, eda_info_reference_prefix VARCHAR(255) DEFAULT NULL, eda_info_value VARCHAR(255) DEFAULT NULL, eda_info_invisible BOOLEAN DEFAULT NULL, eda_info_exclude_from_bom BOOLEAN DEFAULT NULL, eda_info_exclude_from_board BOOLEAN DEFAULT NULL, eda_info_exclude_from_sim BOOLEAN DEFAULT NULL, eda_info_kicad_symbol VARCHAR(255) DEFAULT NULL, eda_info_kicad_footprint VARCHAR(255) DEFAULT NULL, gtin VARCHAR(255) DEFAULT NULL, kind VARCHAR(255) DEFAULT \'manufacturing\' NOT NULL, CONSTRAINT FK_6940A7FEEA7100A1 FOREIGN KEY (id_preview_attachment) REFERENCES attachments (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_6940A7FE5697F554 FOREIGN KEY (id_category) REFERENCES categories (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_6940A7FE7E371A10 FOREIGN KEY (id_footprint) REFERENCES footprints (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_6940A7FE2626CEF9 FOREIGN KEY (id_part_unit) REFERENCES measurement_units (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_6940A7FE1ECB93AE FOREIGN KEY (id_manufacturer) REFERENCES manufacturers (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_6940A7FEA3ED1215 FOREIGN KEY (id_part_custom_state) REFERENCES part_custom_states (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_6940A7FE81081E9B FOREIGN KEY (order_orderdetails_id) REFERENCES orderdetails (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_6940A7FEE8AE70D9 FOREIGN KEY (built_project_id) REFERENCES projects (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO "parts" (id, id_preview_attachment, id_category, id_footprint, id_part_unit, id_manufacturer, id_part_custom_state, order_orderdetails_id, built_project_id, datetime_added, name, last_modified, needs_review, tags, mass, description, comment, visible, favorite, minamount, manufacturer_product_url, manufacturer_product_number, manufacturing_status, order_quantity, manual_order, ipn, provider_reference_provider_key, provider_reference_provider_id, provider_reference_provider_url, provider_reference_last_updated, eda_info_reference_prefix, eda_info_value, eda_info_invisible, eda_info_exclude_from_bom, eda_info_exclude_from_board, eda_info_exclude_from_sim, eda_info_kicad_symbol, eda_info_kicad_footprint, gtin, kind) SELECT id, id_preview_attachment, id_category, id_footprint, id_part_unit, id_manufacturer, id_part_custom_state, order_orderdetails_id, built_project_id, datetime_added, name, last_modified, needs_review, tags, mass, description, comment, visible, favorite, minamount, manufacturer_product_url, manufacturer_product_number, manufacturing_status, order_quantity, manual_order, ipn, provider_reference_provider_key, provider_reference_provider_id, provider_reference_provider_url, provider_reference_last_updated, eda_info_reference_prefix, eda_info_value, eda_info_invisible, eda_info_exclude_from_bom, eda_info_exclude_from_board, eda_info_exclude_from_sim, eda_info_kicad_symbol, eda_info_kicad_footprint, gtin, kind FROM __temp__parts');
        $this->addSql('DROP TABLE __temp__parts');
        $this->addSql('CREATE INDEX parts_idx_gtin ON "parts" (gtin)');
        $this->addSql('CREATE INDEX IDX_6940A7FEA3ED1215 ON "parts" (id_part_custom_state)');
        $this->addSql('CREATE INDEX IDX_6940A7FE1ECB93AE ON "parts" (id_manufacturer)');
        $this->addSql('CREATE INDEX IDX_6940A7FE2626CEF9 ON "parts" (id_part_unit)');
        $this->addSql('CREATE INDEX IDX_6940A7FE5697F554 ON "parts" (id_category)');
        $this->addSql('CREATE INDEX IDX_6940A7FE7E371A10 ON "parts" (id_footprint)');
        $this->addSql('CREATE INDEX IDX_6940A7FEEA7100A1 ON "parts" (id_preview_attachment)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6940A7FE3D721C14 ON "parts" (ipn)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6940A7FE81081E9B ON "parts" (order_orderdetails_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6940A7FEE8AE70D9 ON "parts" (built_project_id)');
        $this->addSql('CREATE INDEX parts_idx_datet_name_last_id_needs ON "parts" (datetime_added, name, last_modified, id, needs_review)');
        $this->addSql('CREATE INDEX parts_idx_ipn ON "parts" (ipn)');
        $this->addSql('CREATE INDEX parts_idx_name ON "parts" (name)');
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql("ALTER TABLE \"parts\" ADD price_method VARCHAR(32) DEFAULT 'system_default' NOT NULL");
        $this->addSql('ALTER TABLE "parts" ADD price_user_value NUMERIC(11, 5) DEFAULT NULL');
        $this->addSql('ALTER TABLE "parts" ADD price_user_currency_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE "parts" ADD CONSTRAINT FK_6940A7FE6244553C FOREIGN KEY (price_user_currency_id) REFERENCES currencies (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_6940A7FE6244553C ON "parts" (price_user_currency_id)');
    }

    public function postgreSQLDown(Schema $schema): void
    {
        $this->warnAboutPriceMethods();
        $this->addSql('ALTER TABLE "parts" DROP CONSTRAINT FK_6940A7FE6244553C');
        $this->addSql('DROP INDEX IDX_6940A7FE6244553C');
        $this->addSql('ALTER TABLE "parts" DROP COLUMN price_method');
        $this->addSql('ALTER TABLE "parts" DROP COLUMN price_user_value');
        $this->addSql('ALTER TABLE "parts" DROP COLUMN price_user_currency_id');
    }
}
