<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240702223900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'initial tables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(
            <<<SQL
            CREATE TABLE IF NOT EXISTS `listings` (
                `id` INT(10) NOT NULL AUTO_INCREMENT,
                `willhaben_id` INT(10) NULL DEFAULT NULL,
                `first_seen` TIMESTAMP NULL DEFAULT NULL,
                `last_seen` TIMESTAMP NULL DEFAULT NULL,
                `title` VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
                PRIMARY KEY (`id`) USING BTREE
            )
            COLLATE='utf8mb4_unicode_ci'
            ENGINE=InnoDB
            ;
        SQL
        );

        $this->addSql(
            <<<SQL
            CREATE TABLE IF NOT EXISTS `listings_data` (
                `id` INT(10) NOT NULL AUTO_INCREMENT,
                `listing_id` INT(10) NULL DEFAULT NULL,
                `data` JSON NULL DEFAULT NULL,
                `created_at` TIMESTAMP NULL DEFAULT 'CURRENT_TIMESTAMP',
                PRIMARY KEY (`id`) USING BTREE,
                INDEX `listing_id` (`listing_id`) USING BTREE,
                CONSTRAINT `listing_id` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
            )
            COLLATE='utf8mb4_unicode_ci'
            ENGINE=InnoDB
            ;
        SQL
        );
    }

    public function down(Schema $schema): void
    {
    }
}
