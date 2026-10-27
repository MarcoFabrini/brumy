<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ultima organizzazione scelta come attiva: con le sessioni l'org attiva vive nella sessione, e una sessione nuova
 * (browser riavviato, ripresa da remember-me) riparte da qui invece che dalla prima membership. Nullable, nessun
 * backfill: si riempie alla prima richiesta di ogni utente.
 */
final class Version20261027100100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ultima organizzazione attiva dell\'utente';
    }

    /** DDL soltanto: senza transazione e con istruzioni rilanciabili. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS last_active_organization_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E94B1E9221 FOREIGN KEY IF NOT EXISTS (last_active_organization_id) REFERENCES organizations (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_1483A5E94B1E9221 ON users (last_active_organization_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP FOREIGN KEY IF EXISTS FK_1483A5E94B1E9221');
        $this->addSql('DROP INDEX IF EXISTS IDX_1483A5E94B1E9221 ON users');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS last_active_organization_id');
    }
}
