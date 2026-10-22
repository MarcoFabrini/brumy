<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Versione dei token sull'utente: il claim `token_version` degli access token deve coincidere con questa colonna,
 * incrementata a ogni revoca di sessioni (cambio/reset password, cambio email, logout, riuso, GDPR).
 *
 * Nessun backfill: le righe esistenti partono da 0. Gli access token emessi prima del deploy non hanno il claim
 * e vengono rifiutati alla prima richiesta; i refresh token non sono toccati, quindi il client si riprende con
 * un refresh trasparente.
 */
final class Version20261020075514 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'users.token_version — versione dei token per invalidare subito gli access token alla revoca delle sessioni';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD token_version INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP token_version');
    }
}
