<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251105113516 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE boutique_users_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE boutiques_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE daily_stocks_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE "users_id_seq" INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE boutique_users (id INT NOT NULL, boutique_id INT NOT NULL, user_id INT NOT NULL, role VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_BFF406F1AB677BE6 ON boutique_users (boutique_id)');
        $this->addSql('CREATE INDEX IDX_BFF406F1A76ED395 ON boutique_users (user_id)');
        $this->addSql('CREATE UNIQUE INDEX boutique_user_unique ON boutique_users (boutique_id, user_id)');
        $this->addSql('COMMENT ON COLUMN boutique_users.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE boutiques (id INT NOT NULL, name VARCHAR(255) NOT NULL, domain VARCHAR(255) NOT NULL, api_key TEXT NOT NULL, logo_url VARCHAR(255) DEFAULT NULL, favicon_url VARCHAR(255) DEFAULT NULL, theme_color VARCHAR(50) DEFAULT NULL, font_family VARCHAR(100) DEFAULT NULL, custom_css TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN boutiques.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN boutiques.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE daily_stocks (id INT NOT NULL, boutique_id INT NOT NULL, product_id INT NOT NULL, reference VARCHAR(100) DEFAULT NULL, name VARCHAR(255) NOT NULL, quantity INT NOT NULL, collected_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_D7C9AFA9AB677BE6 ON daily_stocks (boutique_id)');
        $this->addSql('CREATE INDEX idx_boutique_date ON daily_stocks (boutique_id, collected_at)');
        $this->addSql('CREATE INDEX idx_product_id ON daily_stocks (product_id)');
        $this->addSql('COMMENT ON COLUMN daily_stocks.collected_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE "users" (id INT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL ON "users" (email)');
        $this->addSql('ALTER TABLE boutique_users ADD CONSTRAINT FK_BFF406F1AB677BE6 FOREIGN KEY (boutique_id) REFERENCES boutiques (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE boutique_users ADD CONSTRAINT FK_BFF406F1A76ED395 FOREIGN KEY (user_id) REFERENCES "users" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE daily_stocks ADD CONSTRAINT FK_D7C9AFA9AB677BE6 FOREIGN KEY (boutique_id) REFERENCES boutiques (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE boutique_users_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE boutiques_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE daily_stocks_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE "users_id_seq" CASCADE');
        $this->addSql('ALTER TABLE boutique_users DROP CONSTRAINT FK_BFF406F1AB677BE6');
        $this->addSql('ALTER TABLE boutique_users DROP CONSTRAINT FK_BFF406F1A76ED395');
        $this->addSql('ALTER TABLE daily_stocks DROP CONSTRAINT FK_D7C9AFA9AB677BE6');
        $this->addSql('DROP TABLE boutique_users');
        $this->addSql('DROP TABLE boutiques');
        $this->addSql('DROP TABLE daily_stocks');
        $this->addSql('DROP TABLE "users"');
    }
}
