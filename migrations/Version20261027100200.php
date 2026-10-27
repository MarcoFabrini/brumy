<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Verifica in due passaggi facoltativa: segreto TOTP cifrato, data di attivazione (null = non attiva) e hash dei codici
 * di recupero. Le righe esistenti restano valide senza backfill (verifica non attiva, nessun codice).
 */
final class Version20261027100200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Verifica in due passaggi (TOTP e codici di recupero)';
    }

    /** DDL soltanto: senza transazione e con istruzioni rilanciabili. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(255) DEFAULT NULL, ADD COLUMN IF NOT EXISTS totp_enabled_at DATETIME DEFAULT NULL, ADD COLUMN IF NOT EXISTS backup_codes JSON DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS totp_secret, DROP COLUMN IF EXISTS totp_enabled_at, DROP COLUMN IF EXISTS backup_codes');
    }
}
