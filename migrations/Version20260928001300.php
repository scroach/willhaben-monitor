<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928001300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'added type column';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(
            <<<SQL
                ALTER TABLE `listings`
                    ADD COLUMN `type` VARCHAR(50) NULL DEFAULT 'house' AFTER `willhaben_id`;
                SQL,
        );
    }

    public function down(Schema $schema): void
    {
    }
}
