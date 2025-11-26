<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251125140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create suppliers and product_suppliers tables for replenishment assistant';
    }

    public function up(Schema $schema): void
    {
        // Create suppliers table
        $this->addSql('CREATE TABLE suppliers (
            id SERIAL PRIMARY KEY,
            boutique_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            address TEXT,
            phone VARCHAR(100),
            email VARCHAR(255),
            contact_name VARCHAR(255),
            siret VARCHAR(50),
            vat_number VARCHAR(50),
            lead_time_days INT,
            minimum_order_quantity INT,
            payment_terms TEXT,
            free_shipping_threshold NUMERIC(10, 2),
            notes TEXT,
            active BOOLEAN NOT NULL DEFAULT TRUE,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE,
            CONSTRAINT fk_suppliers_boutique FOREIGN KEY (boutique_id) REFERENCES boutiques(id) ON DELETE CASCADE
        )');

        $this->addSql('CREATE INDEX idx_suppliers_boutique ON suppliers(boutique_id)');
        $this->addSql('COMMENT ON COLUMN suppliers.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN suppliers.updated_at IS \'(DC2Type:datetime_immutable)\'');

        // Create product_suppliers table
        $this->addSql('CREATE TABLE product_suppliers (
            id SERIAL PRIMARY KEY,
            boutique_id INT NOT NULL,
            supplier_id INT NOT NULL,
            product_id INT NOT NULL,
            product_name VARCHAR(255) NOT NULL,
            product_reference VARCHAR(100),
            supplier_reference VARCHAR(100),
            wholesale_price NUMERIC(10, 2) NOT NULL,
            minimum_order_quantity INT,
            discount_percent NUMERIC(5, 2),
            discount_threshold INT,
            is_preferred BOOLEAN NOT NULL DEFAULT FALSE,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            last_purchase_date TIMESTAMP(0) WITHOUT TIME ZONE,
            last_purchase_price NUMERIC(10, 2),
            CONSTRAINT fk_product_suppliers_boutique FOREIGN KEY (boutique_id) REFERENCES boutiques(id) ON DELETE CASCADE,
            CONSTRAINT fk_product_suppliers_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
        )');

        $this->addSql('CREATE INDEX idx_ps_product_id ON product_suppliers(product_id)');
        $this->addSql('CREATE INDEX idx_ps_supplier_id ON product_suppliers(supplier_id)');
        $this->addSql('CREATE INDEX idx_ps_boutique ON product_suppliers(boutique_id)');
        $this->addSql('COMMENT ON COLUMN product_suppliers.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN product_suppliers.last_purchase_date IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE product_suppliers');
        $this->addSql('DROP TABLE suppliers');
    }
}
