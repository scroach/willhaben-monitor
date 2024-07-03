<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240702223910 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'added more aggregated fields to listings table';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(
            <<<SQL
            ALTER TABLE `listings`
                ADD COLUMN `price_min` FLOAT NULL DEFAULT NULL AFTER `title`,
                ADD COLUMN `price_max` FLOAT NULL DEFAULT NULL AFTER `price_min`,
                ADD COLUMN `area` FLOAT NULL DEFAULT NULL AFTER `price_max`,
                ADD COLUMN `city` VARCHAR(250) NULL DEFAULT NULL AFTER `area`,
                ADD COLUMN `zip` VARCHAR(50) NULL DEFAULT NULL AFTER `city`;
        SQL
        );
    }

    public function down(Schema $schema): void
    {
    }
}
