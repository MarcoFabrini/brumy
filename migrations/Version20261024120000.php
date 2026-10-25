<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Entity\UserValue;
use App\Enum\ExpenseCategory;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La categoria delle spese passa dallo slug di `ExpenseCategory` (`expenses.category`) a una FK verso
 * `user_values`: una voce della lista categorie del proprietario del veicolo.
 *
 * Percorso in avanti:
 * - `up()` aggiunge `category_id` (nullable, FK RESTRICT, indice);
 * - `postUp()`, a schema già cambiato, collega le spese: per ogni proprietario (stessa regola della migrazione
 *   precedente: share `admin` accettato più vecchio; veicolo orfano → owner accettato più anziano dell'org; in
 *   mancanza, il membro accettato più anziano), nell'org del veicolo, crea le 13 categorie di partenza che gli
 *   mancano, chiamate nella sua lingua (`users.locale`, come {@see ExpenseCategory::label()}), e collega ogni spesa
 *   alla voce con `starter_key = expenses.category`. Una voce con lo stesso nome già presente senza `starter_key`
 *   diventa quella di partenza invece di duplicarla. Uno slug sconosciuto (dato scritto a mano) diventa una voce
 *   con quel nome, uno vuoto va su "Altro". Poi `category_id` diventa NOT NULL e la colonna slug sparisce.
 *
 * Rilanciabile: le spese già collegate si saltano, le voci esistenti si riusano, il DDL usa IF [NOT] EXISTS.
 * Se restasse una spesa senza nessun utente a cui dare la voce (org senza membri accettati), `category_id` resta
 * nullable, la colonna slug non viene tolta (diventa nullable, perché l'app non la scrive più) e la migrazione
 * lo segnala.
 *
 * Indietro (`down()`, tutto in `addSql()`): rimette `category` (`VARCHAR(30) NOT NULL`, sia che fosse stata
 * tolta sia che fosse rimasta nullable), con lo `starter_key` della voce dove c'è; per una voce creata
 * dall'utente `other`, col nome della voce in testa alle note (`[Revisione camper] note…`, oppure solo
 * `[Revisione camper]` se le note erano vuote). Poi toglie FK, indice e `category_id`. Le voci restano in
 * `user_values`.
 *
 * `--dry-run`: Doctrine chiama comunque `postUp()`; senza `category_id` (DDL non eseguito) non fa nulla.
 */
final class Version20261024120000 extends AbstractMigration
{
    private const KIND = 'expense_category';

    public function getDescription(): string
    {
        return 'Le categorie delle spese diventano voci della lista del proprietario del veicolo';
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
        $this->addSql('ALTER TABLE expenses ADD COLUMN IF NOT EXISTS category_id BIGINT DEFAULT NULL AFTER occurred_at');
        $this->addSql('ALTER TABLE expenses ADD CONSTRAINT FK_2496F35B12469DE2 FOREIGN KEY IF NOT EXISTS (category_id) REFERENCES user_values (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_2496F35B12469DE2 ON expenses (category_id)');
    }

    public function postUp(Schema $schema): void
    {
        if (!$this->hasColumn('expenses', 'category_id')) {
            return; // --dry-run: il DDL di up() non è stato eseguito
        }
        if (!$this->hasColumn('expenses', 'category')) {
            return; // già convertita da un giro precedente
        }

        $this->resetStaleTransactionLevel();
        $left = $this->connection->transactional(fn (): int => $this->link());
        if ($left > 0) {
            // La colonna resta per non perdere gli slug, ma l'app non la scrive più: NOT NULL senza default
            // bloccherebbe ogni nuova spesa (STRICT_TRANS_TABLES)
            $this->connection->executeStatement('ALTER TABLE expenses MODIFY category VARCHAR(30) DEFAULT NULL');
            $this->write(sprintf('expenses: %d spese senza utente a cui dare la categoria, colonna category lasciata (ora nullable)', $left));

            return;
        }

        $this->connection->executeStatement('ALTER TABLE expenses MODIFY category_id BIGINT NOT NULL');
        $this->connection->executeStatement('ALTER TABLE expenses DROP COLUMN IF EXISTS category');
    }

    public function down(Schema $schema): void
    {
        // Prima si rimette lo slug (la coda di addSql() rispetta l'ordine), poi si toglie la FK.
        // Il DEFAULT serve solo a riempire le righe esistenti: il MODIFY finale lo toglie, come nella colonna originale.
        $this->addSql("ALTER TABLE expenses ADD COLUMN IF NOT EXISTS category VARCHAR(30) NOT NULL DEFAULT 'other' AFTER occurred_at");
        $this->addSql('UPDATE expenses e INNER JOIN user_values v ON v.id = e.category_id SET e.category = v.starter_key WHERE v.starter_key IS NOT NULL');
        // Il controllo sul prefisso evita di ripeterlo se un down interrotto viene rilanciato
        $this->addSql(<<<'SQL'
            UPDATE expenses e INNER JOIN user_values v ON v.id = e.category_id
            SET e.category = 'other',
                e.notes = CASE WHEN e.notes IS NULL OR e.notes = '' THEN CONCAT('[', v.name, ']')
                               ELSE CONCAT('[', v.name, '] ', e.notes) END
            WHERE v.starter_key IS NULL
              AND (e.notes IS NULL OR LEFT(e.notes, CHAR_LENGTH(v.name) + 2) <> CONCAT('[', v.name, ']'))
            SQL);
        // Colonna rimasta nullable da un up parziale: righe senza slug su 'other', poi la definizione originale
        $this->addSql("UPDATE expenses SET category = 'other' WHERE category IS NULL");
        $this->addSql('ALTER TABLE expenses MODIFY category VARCHAR(30) NOT NULL');
        $this->addSql('ALTER TABLE expenses DROP FOREIGN KEY IF EXISTS FK_2496F35B12469DE2');
        $this->addSql('DROP INDEX IF EXISTS IDX_2496F35B12469DE2 ON expenses');
        $this->addSql('ALTER TABLE expenses DROP COLUMN IF EXISTS category_id');
    }

    /**
     * Collega a una voce le spese non ancora collegate.
     *
     * @return int spese rimaste senza voce (nessun utente a cui darla)
     */
    private function link(): int
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT x.id, x.slug, x.org, x.owner, u.locale
            FROM (
                SELECT e.id, e.category AS slug, v.organization_id AS org, COALESCE(
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
                FROM expenses e
                INNER JOIN vehicles v ON v.id = e.vehicle_id
                WHERE e.category_id IS NULL
            ) x
            LEFT JOIN users u ON u.id = x.owner
            SQL);

        // (proprietario, org) => lingua e spese per slug
        $groups = [];
        $unassigned = 0;
        foreach ($rows as $row) {
            if ($row['owner'] === null) {
                ++$unassigned;
                continue;
            }
            $group = &$groups[$row['owner'].'|'.$row['org']];
            $group['owner'] = (int) $row['owner'];
            $group['org'] = (int) $row['org'];
            $group['locale'] = (string) $row['locale'];
            $group['slugs'][(string) $row['slug']][] = (int) $row['id'];
            unset($group);
        }

        foreach ($groups as $group) {
            $starters = [];
            foreach (ExpenseCategory::cases() as $category) {
                $starters[$category->value] = $this->starter($group['owner'], $group['org'], $category, $group['locale']);
            }

            foreach ($group['slugs'] as $slug => $ids) {
                $slug = (string) $slug;
                $name = UserValue::normalizeName($slug);
                $valueId = $starters[$slug]
                    ?? ($name === '' ? $starters[ExpenseCategory::OTHER->value] : $this->value($group['owner'], $group['org'], $name, null));

                foreach (array_chunk($ids, 500) as $chunk) {
                    $this->connection->executeStatement(
                        'UPDATE expenses SET category_id = :value WHERE id IN (:ids)',
                        ['value' => $valueId, 'ids' => $chunk],
                        ['ids' => ArrayParameterType::INTEGER],
                    );
                }
            }
        }

        return $unassigned;
    }

    /** Id della categoria di partenza dell'utente; se manca la crea con l'etichetta nella sua lingua. */
    private function starter(int $user, int $org, ExpenseCategory $category, string $locale): int
    {
        $id = $this->connection->fetchOne(
            'SELECT id FROM user_values WHERE user_id = :user AND organization_id = :org AND kind = :kind AND starter_key = :starter ORDER BY id LIMIT 1',
            ['user' => $user, 'org' => $org, 'kind' => self::KIND, 'starter' => $category->value],
        );

        return $id !== false ? (int) $id : $this->value($user, $org, $category->label($locale), $category);
    }

    /**
     * Id della voce con quel nome (a meno di maiuscole e spazi); se manca la crea. Una voce omonima senza
     * `starter_key` diventa quella di partenza `$starter`.
     */
    private function value(int $user, int $org, string $name, ?ExpenseCategory $starter): int
    {
        $params = [
            'user' => $user, 'org' => $org, 'kind' => self::KIND, 'name' => $name,
            'key' => UserValue::keyOf($name), 'starter' => $starter?->value,
        ];
        $this->connection->executeStatement(
            'INSERT INTO user_values (user_id, organization_id, kind, name, name_key, starter_key, created_at)
             VALUES (:user, :org, :kind, :name, :key, :starter, NOW())
             ON DUPLICATE KEY UPDATE starter_key = COALESCE(starter_key, VALUES(starter_key))',
            $params,
        );

        return (int) $this->connection->fetchOne(
            'SELECT id FROM user_values WHERE user_id = :user AND organization_id = :org AND kind = :kind AND name_key = :key',
            $params,
        );
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
