<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251124160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add category column to daily_stocks table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE daily_stocks ADD COLUMN category VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE daily_stocks DROP COLUMN category');
    }
}
