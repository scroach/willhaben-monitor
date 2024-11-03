<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20241103140300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'added is_starred column';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(
            <<<SQL
            ALTER TABLE `listings`
                ADD COLUMN `is_starred` BIGINT NOT NULL DEFAULT '0' AFTER `title_image`;
        SQL
        );
    }

    public function down(Schema $schema): void
    {
    }
}
