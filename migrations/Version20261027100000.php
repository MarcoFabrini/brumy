<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Autenticazione con le sessioni di Symfony al posto di JWT e refresh token: tabella `sessions` (PdoSessionHandler) e
 * `rememberme_token` (remember-me persistente, una serie per dispositivo); via `refresh_tokens` e
 * `users.token_version`, che servivano solo ai token.
 *
 * Dopo l'aggiornamento tutti rifanno il login una volta: le sessioni aperte erano refresh token, che spariscono.
 * `down()` ricrea `refresh_tokens` vuota (i token non tornano) e `users.token_version` a 0.
 */
final class Version20261027100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sessioni Symfony e remember-me al posto dei refresh token';
    }

    /** DDL soltanto: senza transazione e con istruzioni rilanciabili. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS sessions (sess_id VARBINARY(128) NOT NULL, sess_data LONGBLOB NOT NULL, sess_lifetime INT UNSIGNED NOT NULL, sess_time INT UNSIGNED NOT NULL, INDEX sess_lifetime_idx (sess_lifetime), PRIMARY KEY (sess_id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE IF NOT EXISTS rememberme_token (series VARCHAR(88) NOT NULL, value VARCHAR(88) NOT NULL, lastUsed DATETIME NOT NULL, class VARCHAR(100) DEFAULT \'\' NOT NULL, username VARCHAR(200) NOT NULL, PRIMARY KEY (series)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('DROP TABLE IF EXISTS refresh_tokens');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS token_version');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS token_version INT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE TABLE IF NOT EXISTS refresh_tokens (id BIGINT AUTO_INCREMENT NOT NULL, token VARCHAR(128) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, revoked_at DATETIME DEFAULT NULL, user_id BIGINT NOT NULL, active_organization_id BIGINT DEFAULT NULL, family_id VARCHAR(32) NOT NULL, rotated_at DATETIME DEFAULT NULL, INDEX idx_refresh_tokens_expires (expires_at), INDEX idx_refresh_tokens_family (family_id), UNIQUE INDEX UNIQ_9BACE7E15F37A13B (token), INDEX idx_refresh_tokens_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('ALTER TABLE refresh_tokens ADD CONSTRAINT FK_9BACE7E1A76ED395 FOREIGN KEY IF NOT EXISTS (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('DROP TABLE IF EXISTS rememberme_token');
        $this->addSql('DROP TABLE IF EXISTS sessions');
    }
}
