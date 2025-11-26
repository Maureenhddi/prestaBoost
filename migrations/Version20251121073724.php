<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251121073724 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE "orders_id_seq" INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE "orders" (id INT NOT NULL, boutique_id INT NOT NULL, order_id INT NOT NULL, reference VARCHAR(50) DEFAULT NULL, total_paid NUMERIC(10, 2) NOT NULL, current_state VARCHAR(50) NOT NULL, payment VARCHAR(100) DEFAULT NULL, order_date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, collected_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_E52FFDEEAB677BE6 ON "orders" (boutique_id)');
        $this->addSql('CREATE INDEX idx_orders_boutique_order ON "orders" (boutique_id, order_id)');
        $this->addSql('CREATE INDEX idx_orders_boutique_date ON "orders" (boutique_id, order_date)');
        $this->addSql('COMMENT ON COLUMN "orders".order_date IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN "orders".collected_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE "orders" ADD CONSTRAINT FK_E52FFDEEAB677BE6 FOREIGN KEY (boutique_id) REFERENCES boutiques (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE "orders_id_seq" CASCADE');
        $this->addSql('ALTER TABLE "orders" DROP CONSTRAINT FK_E52FFDEEAB677BE6');
        $this->addSql('DROP TABLE "orders"');
    }
}
