<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'added listing source and immoscout24 id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            <<<SQL
                ALTER TABLE `listings`
                    ADD COLUMN `source` VARCHAR(50) NOT NULL DEFAULT 'willhaben' AFTER `type`,
                    ADD COLUMN `immoscout_id` VARCHAR(64) DEFAULT NULL AFTER `willhaben_id`;
                SQL,
        );

        // Separate statement so MySQL can add the columns instantly and only build the index afterwards.
        $this->addSql('CREATE INDEX `idx_listings_immoscout_id` ON `listings` (`immoscout_id`);');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX `idx_listings_immoscout_id` ON `listings`;');
        $this->addSql(
            <<<SQL
                ALTER TABLE `listings`
                    DROP COLUMN `immoscout_id`,
                    DROP COLUMN `source`;
                SQL,
        );
    }
}
