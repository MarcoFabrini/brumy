<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Eliminazione morbida delle voci private: una voce eliminata resta per i record che la usano (deleted_at
 * valorizzato) ma perde name_key, così non blocca più il nome (l'indice unico ammette più NULL).
 */
final class Version20261025100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Voci private: eliminazione morbida';
    }

    /** DDL soltanto: come Version20261024100000, senza transazione e con istruzioni rilanciabili. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_values ADD COLUMN IF NOT EXISTS deleted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE user_values MODIFY name_key VARCHAR(200) DEFAULT NULL COLLATE `utf8mb4_bin`');
    }

    /**
     * Le voci eliminate senza record spariscono; quelle ancora usate riprendono una name_key unica che non
     * coincide con nessun nome (keyOf() non lascia tabulazioni), perché potrebbero avere lo stesso nome di una
     * voce attiva.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM user_values WHERE deleted_at IS NOT NULL
            AND id NOT IN (SELECT station_id FROM refuelings WHERE station_id IS NOT NULL)
            AND id NOT IN (SELECT workshop_id FROM maintenances WHERE workshop_id IS NOT NULL)
            AND id NOT IN (SELECT category_id FROM expenses WHERE category_id IS NOT NULL)');
        $this->addSql('UPDATE user_values SET name_key = CONCAT(LEFT(LOWER(name), 180), CHAR(9), id) WHERE name_key IS NULL');
        $this->addSql('ALTER TABLE user_values MODIFY name_key VARCHAR(200) NOT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE user_values DROP COLUMN IF EXISTS deleted_at');
    }
}
