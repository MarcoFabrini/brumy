<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Eliminazione morbida dei componenti, come per le voci (Version20261025100000): un componente eliminato resta per i
 * lavori che lo usano (deleted_at valorizzato) ma perde name_key, così il nome torna libero.
 */
final class Version20261025130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Componenti: eliminazione morbida';
    }

    /** DDL soltanto: senza transazione e con istruzioni rilanciabili. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE components ADD COLUMN IF NOT EXISTS deleted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE components MODIFY name_key VARCHAR(200) DEFAULT NULL COLLATE `utf8mb4_bin`');
    }

    /** I componenti eliminati spariscono (lo schema precedente non li conosce), con i loro collegamenti ai lavori. */
    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM components WHERE deleted_at IS NOT NULL');
        $this->addSql('ALTER TABLE components MODIFY name_key VARCHAR(200) NOT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE components DROP COLUMN IF EXISTS deleted_at');
    }
}
