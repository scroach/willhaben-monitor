<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240702223920 extends AbstractMigration
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
                ADD COLUMN `price_current` FLOAT NULL DEFAULT NULL AFTER `price_max`,
                ADD COLUMN `title_image` VARCHAR(255) NULL DEFAULT NULL AFTER `zip`;
        SQL
        );
    }

    public function down(Schema $schema): void
    {
    }
}
