<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Distributori seguiti: una voce distributore collegata può seguire il prezzo, che da allora si salva ogni giorno
 * nello storico per i grafici dell'andamento.
 */
final class Version20261026170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Storico giornaliero dei prezzi dei distributori seguiti';
    }

    /** DDL soltanto: senza transazione e con istruzioni rilanciabili. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS fuel_price_history (id BIGINT AUTO_INCREMENT NOT NULL, station_id INT NOT NULL, fuel VARCHAR(20) NOT NULL, is_self TINYINT NOT NULL, day DATE NOT NULL, price NUMERIC(5, 3) NOT NULL, UNIQUE INDEX uniq_fuel_price_history_station_fuel_self_day (station_id, fuel, is_self, day), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE user_values ADD COLUMN IF NOT EXISTS follows_price TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_values DROP COLUMN IF EXISTS follows_price');
        $this->addSql('DROP TABLE IF EXISTS fuel_price_history');
    }
}
