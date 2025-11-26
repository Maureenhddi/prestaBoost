<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add low_stock_threshold column to boutiques table
 */
final class Version20251125100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add low_stock_threshold column to boutiques table with default value of 10';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE boutiques ADD COLUMN low_stock_threshold INTEGER NOT NULL DEFAULT 10');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE boutiques DROP COLUMN low_stock_threshold');
    }
}
