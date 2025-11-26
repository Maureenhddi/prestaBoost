<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add wholesale_price column to order_items table for margin calculation
 */
final class Version20251121102500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add wholesale_price column to order_items table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "order_items" ADD wholesale_price NUMERIC(10, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "order_items" DROP wholesale_price');
    }
}
