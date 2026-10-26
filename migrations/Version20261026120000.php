<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prezzi del carburante dall'open data MIMIT: distributori e ultimo prezzo per carburante (dati pubblici, riscritti a
 * ogni import), più il collegamento di una voce distributore dell'elenco al distributore ufficiale.
 */
final class Version20261026120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Distributori e prezzi del carburante (open data MIMIT), voce distributore collegabile';
    }

    /** DDL soltanto: senza transazione e con istruzioni rilanciabili. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS fuel_stations (id INT NOT NULL, operator VARCHAR(200) NOT NULL, brand VARCHAR(100) NOT NULL, name VARCHAR(200) NOT NULL, address VARCHAR(300) NOT NULL, city VARCHAR(100) NOT NULL, province VARCHAR(5) NOT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, extracted_on DATE NOT NULL, imported_at DATETIME NOT NULL, INDEX idx_fuel_stations_position (latitude, longitude), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE IF NOT EXISTS fuel_prices (id BIGINT AUTO_INCREMENT NOT NULL, fuel VARCHAR(20) NOT NULL, is_self TINYINT NOT NULL, price NUMERIC(5, 3) NOT NULL, communicated_at DATETIME NOT NULL, station_id INT NOT NULL, UNIQUE INDEX uniq_fuel_prices_station_fuel_self (station_id, fuel, is_self), INDEX IDX_D2A287F021BDB235 (station_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE fuel_prices ADD CONSTRAINT FK_D2A287F021BDB235 FOREIGN KEY IF NOT EXISTS (station_id) REFERENCES fuel_stations (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_values ADD COLUMN IF NOT EXISTS fuel_station_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_values DROP COLUMN IF EXISTS fuel_station_id');
        $this->addSql('DROP TABLE IF EXISTS fuel_prices');
        $this->addSql('DROP TABLE IF EXISTS fuel_stations');
    }
}
