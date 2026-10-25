<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schede uniformi: ogni record ha descrizione e note (note ai lavori e ai promemoria, descrizione ai rifornimenti);
 * il veicolo ha il prezzo d'acquisto (solo informativo).
 */
final class Version20261025140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Note di lavori e promemoria, descrizione dei rifornimenti, prezzo d\'acquisto del veicolo';
    }

    /** DDL soltanto: senza transazione e con istruzioni rilanciabili. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE maintenances ADD COLUMN IF NOT EXISTS notes LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE reminders ADD COLUMN IF NOT EXISTS notes LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE refuelings ADD COLUMN IF NOT EXISTS description VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS purchase_price NUMERIC(10, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE maintenances DROP COLUMN IF EXISTS notes');
        $this->addSql('ALTER TABLE reminders DROP COLUMN IF EXISTS notes');
        $this->addSql('ALTER TABLE refuelings DROP COLUMN IF EXISTS description');
        $this->addSql('ALTER TABLE vehicles DROP COLUMN IF EXISTS purchase_price');
    }
}
