<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Promemoria ricorrenti: ricorrenza a tempo (mesi) e a km, anticipo dell'avviso a km per promemoria,
 * data e km effettivi del completamento.
 *
 * Nessun UPDATE di dati: i promemoria esistenti restano una tantum (ricorrenze NULL) e con la soglia di
 * 1000 km del DEFAULT, cioè il comportamento precedente. `completed_on`/`completed_km` restano NULL per
 * quelli già completati: l'istante del click (`completed_at`) non è la data del lavoro.
 */
final class Version20261021150724 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'reminders: ricorrenza (recurrence_months, recurrence_km), notify_km_before, completed_on, completed_km';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reminders ADD notify_km_before INT DEFAULT 1000 NOT NULL, ADD recurrence_months INT DEFAULT NULL, ADD recurrence_km INT DEFAULT NULL, ADD completed_on DATE DEFAULT NULL, ADD completed_km INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reminders DROP notify_km_before, DROP recurrence_months, DROP recurrence_km, DROP completed_on, DROP completed_km');
    }
}
