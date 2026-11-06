<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ultima versione pubblicata su GitHub già notificata all'amministratore dell'istanza: una notifica push per versione.
 * Le righe esistenti partono da null (la prossima versione nuova viene notificata).
 */
final class Version20261106100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Versione di Brumy già notificata all\'amministratore';
    }

    /** DDL soltanto: senza transazione e con istruzioni rilanciabili. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS update_notified_version VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS update_notified_version');
    }
}
