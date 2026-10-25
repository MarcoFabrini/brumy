<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tabella user_values: le liste ricorrenti sono per utente e organizzazione, i record le puntano con una FK (task successivi).
 */
final class Version20261024100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Voci private per utente (distributori, officine, categorie di spesa)';
    }

    /**
     * Il DDL di MariaDB chiude da solo la transazione: Doctrine non la chiude (non è più attiva sul server) e
     * lascia il livello di annidamento a 1, così il `postUp()` delle migrazioni successive, lanciate nella stessa
     * esecuzione, userebbe un savepoint inesistente. Senza transazione un'esecuzione interrotta resta a metà:
     * ogni istruzione è idempotente (IF [NOT] EXISTS) perché il rilancio di AUTO_MIGRATE la completi.
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS user_values (id BIGINT AUTO_INCREMENT NOT NULL, kind VARCHAR(20) NOT NULL, name VARCHAR(200) NOT NULL, name_key VARCHAR(200) NOT NULL COLLATE `utf8mb4_bin`, starter_key VARCHAR(30) DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, user_id BIGINT NOT NULL, organization_id BIGINT NOT NULL, UNIQUE INDEX uniq_user_values_list_key (user_id, organization_id, kind, name_key), INDEX IDX_5FF557DBA76ED395 (user_id), INDEX IDX_5FF557DB32C8A3DE (organization_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE user_values ADD CONSTRAINT FK_5FF557DBA76ED395 FOREIGN KEY IF NOT EXISTS (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_values ADD CONSTRAINT FK_5FF557DB32C8A3DE FOREIGN KEY IF NOT EXISTS (organization_id) REFERENCES organizations (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS user_values');
    }
}
