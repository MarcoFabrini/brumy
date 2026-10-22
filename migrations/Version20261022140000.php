<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cambio email in due tempi: il nuovo indirizzo resta in `pending_email` finché l'utente non apre il link mandato lì.
 * Nessun backfill: nessun cambio è in attesa al momento della migrazione.
 */
final class Version20261022140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'users.pending_email — nuovo indirizzo in attesa di conferma';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD pending_email VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP pending_email');
    }
}
