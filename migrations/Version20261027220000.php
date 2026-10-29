<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'added indexes for type dashboards and statistics';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX `idx_listings_type_first_seen` ON `listings` (`type`, `first_seen`);');
        $this->addSql('CREATE INDEX `idx_listings_type_last_seen` ON `listings` (`type`, `last_seen`);');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX `idx_listings_type_first_seen` ON `listings`;');
        $this->addSql('DROP INDEX `idx_listings_type_last_seen` ON `listings`;');
    }
}
