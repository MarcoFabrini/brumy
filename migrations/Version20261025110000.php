<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Negozio della manutenzione (dove sono stati presi i pezzi): voce facoltativa della lista privata `shop`.
 */
final class Version20261025110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Manutenzioni: negozio facoltativo dalla lista privata';
    }

    /** DDL soltanto: come Version20261024100000, senza transazione e con istruzioni rilanciabili. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE maintenances ADD COLUMN IF NOT EXISTS shop_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE maintenances ADD CONSTRAINT FK_C2F7112F4D16C4DD FOREIGN KEY IF NOT EXISTS (shop_id) REFERENCES user_values (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_C2F7112F4D16C4DD ON maintenances (shop_id)');
    }

    /** Lo schema precedente non ha il negozio: si perde, e con lui le voci `shop`. */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE maintenances DROP FOREIGN KEY IF EXISTS FK_C2F7112F4D16C4DD');
        $this->addSql('ALTER TABLE maintenances DROP COLUMN IF EXISTS shop_id');
        $this->addSql("DELETE FROM user_values WHERE kind = 'shop'");
    }
}
