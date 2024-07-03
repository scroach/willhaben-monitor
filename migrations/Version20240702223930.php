<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240702223930 extends AbstractMigration
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
                ADD COLUMN `price_current_per_sqm` FLOAT NULL DEFAULT NULL AFTER `price_current`;
        SQL
        );
    }

    public function down(Schema $schema): void
    {
    }
}
