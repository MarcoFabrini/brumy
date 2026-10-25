<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Entity\UserValue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Distributore (`refuelings.station`) e officina (`maintenances.workshop`) passano da testo libero a una FK
 * verso `user_values`, la lista privata del proprietario del veicolo.
 *
 * Percorso in avanti:
 * - `up()` aggiunge `station_id`/`workshop_id` (nullable, FK RESTRICT, indice);
 * - `postUp()`, a schema già cambiato, converte i testi: ogni record va nella lista del proprietario del veicolo
 *   (share `admin` accettato più vecchio; veicolo orfano → owner accettato più anziano dell'org; in mancanza,
 *   il membro accettato più anziano, per non perdere il testo), nell'org del veicolo. I nomi uguali a meno di
 *   maiuscole e spazi ({@see UserValue::keyOf()}) diventano una voce sola, chiamata con la grafia più frequente
 *   (a pari merito quella del record più recente). Testi vuoti o di soli spazi restano senza voce. Poi toglie le colonne testo.
 *
 * Rilanciabile: i record già collegati si saltano, una voce che esiste già si riusa, il DDL usa IF [NOT] EXISTS
 * (un `postUp()` interrotto non blocca il riavvio con AUTO_MIGRATE). Se restasse un testo non vuoto senza nessun
 * utente a cui darlo (org senza membri accettati), la colonna testo non viene tolta e la migrazione lo segnala.
 *
 * Indietro: `down()` riaggiunge le colonne testo, ci copia il nome attuale della voce, poi toglie FK, indici e
 * colonne `*_id`. Le voci restano in `user_values` (le toglie la migrazione precedente, se si scende ancora).
 *
 * `--dry-run`: Doctrine chiama comunque `postUp()`; senza le colonne `*_id` (DDL non eseguito) non fa nulla.
 * Tutto il percorso indietro è in `addSql()`, quindi in dry-run non tocca i dati.
 */
final class Version20261024110000 extends AbstractMigration
{
    /** tabella => [colonna testo, colonna FK, kind, colonna data, colonna dopo cui rimettere il testo] */
    private const TARGETS = [
        'refuelings' => ['station', 'station_id', 'station', 'refueled_at', 'full_tank'],
        'maintenances' => ['workshop', 'workshop_id', 'workshop', 'performed_at', 'cost'],
    ];

    public function getDescription(): string
    {
        return 'Distributore e officina diventano voci della lista del proprietario del veicolo';
    }

    /**
     * Il DDL di MariaDB chiude da solo qualunque transazione: la transazione esterna di Doctrine non proteggerebbe
     * nulla e farebbe fallire quella vera (savepoint inesistente) che `postUp()` apre attorno ai dati.
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE maintenances ADD COLUMN IF NOT EXISTS workshop_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE maintenances ADD CONSTRAINT FK_C2F7112F1FDCE57C FOREIGN KEY IF NOT EXISTS (workshop_id) REFERENCES user_values (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_C2F7112F1FDCE57C ON maintenances (workshop_id)');
        $this->addSql('ALTER TABLE refuelings ADD COLUMN IF NOT EXISTS station_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE refuelings ADD CONSTRAINT FK_4C3A286821BDB235 FOREIGN KEY IF NOT EXISTS (station_id) REFERENCES user_values (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_4C3A286821BDB235 ON refuelings (station_id)');
    }

    public function postUp(Schema $schema): void
    {
        foreach (self::TARGETS as $table => [$text, $fk, $kind, $date]) {
            if (!$this->hasColumn($table, $fk)) {
                continue; // --dry-run: il DDL di up() non è stato eseguito
            }
            if (!$this->hasColumn($table, $text)) {
                continue; // già convertita da un giro precedente
            }

            $this->resetStaleTransactionLevel();
            $left = $this->connection->transactional(fn (): int => $this->link($table, $text, $fk, $kind, $date));
            if ($left > 0) {
                $this->write(sprintf('%s: %d record senza utente a cui dare la voce, colonna %s lasciata', $table, $left, $text));
                continue;
            }
            $this->connection->executeStatement("ALTER TABLE $table DROP COLUMN IF EXISTS $text");
        }
    }

    public function down(Schema $schema): void
    {
        // Prima si rimettono i testi (la coda di addSql() rispetta l'ordine), poi si tolgono le FK
        foreach (self::TARGETS as $table => [$text, $fk, , , $after]) {
            $this->addSql("ALTER TABLE $table ADD COLUMN IF NOT EXISTS $text VARCHAR(200) DEFAULT NULL AFTER $after");
            $this->addSql("UPDATE $table t INNER JOIN user_values v ON v.id = t.$fk SET t.$text = v.name");
        }
        $this->addSql('ALTER TABLE maintenances DROP FOREIGN KEY IF EXISTS FK_C2F7112F1FDCE57C');
        $this->addSql('DROP INDEX IF EXISTS IDX_C2F7112F1FDCE57C ON maintenances');
        $this->addSql('ALTER TABLE maintenances DROP COLUMN IF EXISTS workshop_id');
        $this->addSql('ALTER TABLE refuelings DROP FOREIGN KEY IF EXISTS FK_4C3A286821BDB235');
        $this->addSql('DROP INDEX IF EXISTS IDX_4C3A286821BDB235 ON refuelings');
        $this->addSql('ALTER TABLE refuelings DROP COLUMN IF EXISTS station_id');
    }

    /**
     * Collega a una voce i record non ancora collegati con un testo non vuoto.
     *
     * @return int record con un testo non vuoto rimasti senza voce (nessun utente a cui darla)
     */
    private function link(string $table, string $text, string $fk, string $kind, string $date): int
    {
        $rows = $this->connection->fetchAllAssociative(<<<SQL
            SELECT t.id, t.$text AS name, t.$date AS day, v.organization_id AS org, COALESCE(
                (SELECT s.user_id FROM vehicle_shares s
                    WHERE s.vehicle_id = v.id AND s.role = 'admin' AND s.accepted_at IS NOT NULL
                    ORDER BY s.created_at ASC, s.id ASC LIMIT 1),
                (SELECT m.user_id FROM organization_members m
                    WHERE m.organization_id = v.organization_id AND m.role = 'owner' AND m.accepted_at IS NOT NULL
                    ORDER BY m.created_at ASC, m.id ASC LIMIT 1),
                (SELECT m.user_id FROM organization_members m
                    WHERE m.organization_id = v.organization_id AND m.accepted_at IS NOT NULL
                    ORDER BY m.created_at ASC, m.id ASC LIMIT 1)
            ) AS owner
            FROM $table t
            INNER JOIN vehicles v ON v.id = t.vehicle_id
            WHERE t.$fk IS NULL AND t.$text IS NOT NULL
            SQL);

        // (proprietario, org, chiave) => grafie con quante volte compaiono e il record più recente
        $groups = [];
        $unassigned = 0;
        foreach ($rows as $row) {
            $name = UserValue::normalizeName((string) $row['name']);
            if ($name === '') {
                continue;
            }
            if ($row['owner'] === null) {
                ++$unassigned;
                continue;
            }
            $group = &$groups[$row['owner'].'|'.$row['org'].'|'.UserValue::keyOf($name)];
            $group['owner'] = (int) $row['owner'];
            $group['org'] = (int) $row['org'];
            $group['ids'][] = (int) $row['id'];
            $spelling = &$group['spellings'][$name];
            $spelling['count'] = ($spelling['count'] ?? 0) + 1;
            $latest = [(string) $row['day'], (int) $row['id']];
            $spelling['latest'] = max($spelling['latest'] ?? $latest, $latest);
            unset($group, $spelling);
        }

        foreach ($groups as $group) {
            uasort($group['spellings'], static fn (array $a, array $b): int => [$b['count'], $b['latest']] <=> [$a['count'], $a['latest']]);
            $name = (string) array_key_first($group['spellings']);
            $params = ['user' => $group['owner'], 'org' => $group['org'], 'kind' => $kind, 'name' => $name, 'key' => UserValue::keyOf($name)];

            // Una voce rimasta da un giro precedente (down e di nuovo up) si riusa così com'è
            $this->connection->executeStatement(
                'INSERT INTO user_values (user_id, organization_id, kind, name, name_key, created_at)
                 VALUES (:user, :org, :kind, :name, :key, NOW())
                 ON DUPLICATE KEY UPDATE id = id',
                $params,
            );
            $valueId = (int) $this->connection->fetchOne(
                'SELECT id FROM user_values WHERE user_id = :user AND organization_id = :org AND kind = :kind AND name_key = :key',
                $params,
            );

            foreach (array_chunk($group['ids'], 500) as $ids) {
                $this->connection->executeStatement(
                    "UPDATE $table SET $fk = :value WHERE id IN (:ids)",
                    ['value' => $valueId, 'ids' => $ids],
                    ['ids' => ArrayParameterType::INTEGER],
                );
            }
        }

        return $unassigned;
    }

    /**
     * Da un database vuoto le migrazioni precedenti girano nella stessa esecuzione: sono transazionali ma il loro
     * DDL chiude da solo la transazione sul server, e DBAL resta con un livello di annidamento > 0 senza nessuna
     * transazione aperta. `transactional()` aprirebbe allora un savepoint che sul server non esiste. Se è così si
     * riparte da una connessione pulita (close() azzera il livello; non c'è niente da perdere, il server non ha
     * transazioni aperte).
     */
    private function resetStaleTransactionLevel(): void
    {
        $native = $this->connection->getNativeConnection();
        if ($this->connection->isTransactionActive() && $native instanceof \PDO && !$native->inTransaction()) {
            $this->connection->close();
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => $table, 'c' => $column],
        );
    }
}
