<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251121151500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create sync_jobs table to track synchronization progress';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sync_jobs (
            id SERIAL NOT NULL,
            boutique_id INT NOT NULL,
            type VARCHAR(50) NOT NULL,
            status VARCHAR(50) NOT NULL,
            started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            items_processed INT DEFAULT NULL,
            total_items INT DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            orders_days INT DEFAULT NULL,
            PRIMARY KEY(id)
        )');

        $this->addSql('CREATE INDEX IDX_sync_jobs_boutique ON sync_jobs (boutique_id)');
        $this->addSql('CREATE INDEX IDX_sync_jobs_status ON sync_jobs (status)');
        $this->addSql('ALTER TABLE sync_jobs ADD CONSTRAINT FK_sync_jobs_boutique
            FOREIGN KEY (boutique_id) REFERENCES boutiques (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('COMMENT ON COLUMN sync_jobs.started_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN sync_jobs.completed_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sync_jobs DROP CONSTRAINT FK_sync_jobs_boutique');
        $this->addSql('DROP TABLE sync_jobs');
    }
}
