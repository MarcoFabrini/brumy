<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Entity\UserValue;
use App\Enum\TaxType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sezioni per tipo di costo e componenti.
 *
 * Avanti:
 * - `up()`: `expenses.section` (default `other`), `expenses.insurer_id`, `category_id` nullable (l'assicurazione non
 *   ha categoria); tabelle `components` e `maintenance_components`.
 * - `postUp()`, in transazione, porta i record nella sezione giusta:
 *   - i lavori di tipo revisione passano nella sezione revisione (`category = inspection`);
 *   - le spese nella categoria di partenza "Assicurazione" diventano assicurazione, senza categoria;
 *   - per chi ha le categorie di partenza "Bollo" e "Tasse" si creano i tipi di tassa di partenza (Bollo e Altra
 *     tassa prendono il nome che l'utente aveva dato a quelle categorie, gli altri l'etichetta nella sua lingua) e le
 *     loro spese diventano tasse con il tipo corrispondente;
 *   - le tre categorie di partenza spostate vengono eliminate (morbido: nessuna spesa le usa più).
 *   Rilanciabile: ogni passo salta ciò che è già convertito.
 *
 * Indietro (`down()`, tutto in `addSql()`): i componenti si perdono (lo schema precedente non li ha); revisione →
 * lavoro ordinario; tasse e assicurazione tornano spese con la categoria di partenza corrispondente del proprietario
 * (rimessa in vita, o creata se manca), e un tipo di tassa senza corrispondente o la compagnia finiscono in testa
 * alle note (`[Superbollo] …`); poi spariscono le voci `tax_type` e `insurer`.
 */
final class Version20261025120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sezioni (revisione, tasse, assicurazione) e componenti';
    }

    /** Come Version20261024120000: il DDL di MariaDB chiude da solo la transazione, i dati hanno la loro in postUp(). */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE expenses ADD COLUMN IF NOT EXISTS section VARCHAR(20) DEFAULT 'other' NOT NULL, ADD COLUMN IF NOT EXISTS insurer_id BIGINT DEFAULT NULL, MODIFY category_id BIGINT DEFAULT NULL");
        $this->addSql('ALTER TABLE expenses ADD CONSTRAINT FK_2496F35B895854C7 FOREIGN KEY IF NOT EXISTS (insurer_id) REFERENCES user_values (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_2496F35B895854C7 ON expenses (insurer_id)');

        $this->addSql('CREATE TABLE IF NOT EXISTS components (id BIGINT AUTO_INCREMENT NOT NULL, name VARCHAR(200) NOT NULL, name_key VARCHAR(200) NOT NULL COLLATE `utf8mb4_bin`, code VARCHAR(200) DEFAULT NULL, serial_number VARCHAR(200) DEFAULT NULL, purchased_at DATE DEFAULT NULL, price NUMERIC(10, 2) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, organization_id BIGINT NOT NULL, vehicle_id BIGINT NOT NULL, shop_id BIGINT DEFAULT NULL, INDEX idx_components_org (organization_id), UNIQUE INDEX uniq_components_vehicle_key (vehicle_id, name_key), INDEX IDX_EE48F5FD545317D1 (vehicle_id), INDEX IDX_EE48F5FD4D16C4DD (shop_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE components ADD CONSTRAINT FK_EE48F5FD32C8A3DE FOREIGN KEY IF NOT EXISTS (organization_id) REFERENCES organizations (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE components ADD CONSTRAINT FK_EE48F5FD545317D1 FOREIGN KEY IF NOT EXISTS (vehicle_id) REFERENCES vehicles (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE components ADD CONSTRAINT FK_EE48F5FD4D16C4DD FOREIGN KEY IF NOT EXISTS (shop_id) REFERENCES user_values (id) ON DELETE RESTRICT');

        $this->addSql('CREATE TABLE IF NOT EXISTS maintenance_components (maintenance_id BIGINT NOT NULL, component_id BIGINT NOT NULL, INDEX IDX_C90F7573F6C202BC (maintenance_id), INDEX IDX_C90F7573E2ABAFFF (component_id), PRIMARY KEY (maintenance_id, component_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE maintenance_components ADD CONSTRAINT FK_C90F7573F6C202BC FOREIGN KEY IF NOT EXISTS (maintenance_id) REFERENCES maintenances (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE maintenance_components ADD CONSTRAINT FK_C90F7573E2ABAFFF FOREIGN KEY IF NOT EXISTS (component_id) REFERENCES components (id) ON DELETE CASCADE');
    }

    public function postUp(Schema $schema): void
    {
        if (!$this->hasColumn('expenses', 'section')) {
            return; // --dry-run: il DDL di up() non è stato eseguito
        }

        $this->resetStaleTransactionLevel();
        $this->connection->transactional(function (): void {
            $this->connection->executeStatement("UPDATE maintenances SET category = 'inspection' WHERE type = 'inspection' AND category <> 'inspection'");

            $this->connection->executeStatement(<<<'SQL'
                UPDATE expenses e INNER JOIN user_values v ON v.id = e.category_id
                SET e.section = 'insurance', e.category_id = NULL
                WHERE v.kind = 'expense_category' AND v.starter_key = 'insurance'
                SQL);

            $this->moveTaxes();

            $this->connection->executeStatement(<<<'SQL'
                UPDATE user_values SET deleted_at = COALESCE(deleted_at, NOW()), name_key = NULL
                WHERE kind = 'expense_category' AND starter_key IN ('insurance', 'road_tax', 'tax')
                SQL);
        });
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS maintenance_components');
        $this->addSql('DROP TABLE IF EXISTS components');
        $this->addSql("UPDATE maintenances SET category = 'scheduled' WHERE category = 'inspection'");

        // Le categorie di partenza spostate tornano in vita; un nome ripreso nel frattempo da una voce attiva
        // le lascia con una chiave unica che non coincide con nessun nome (keyOf() non lascia tabulazioni)
        $this->addSql(<<<'SQL'
            UPDATE user_values v
            LEFT JOIN (SELECT user_id, organization_id, name_key FROM user_values
                       WHERE kind = 'expense_category' AND name_key IS NOT NULL) c
                ON c.user_id = v.user_id AND c.organization_id = v.organization_id AND c.name_key = LOWER(v.name)
            SET v.deleted_at = NULL,
                v.name_key = IF(c.name_key IS NULL, LOWER(v.name), CONCAT(LEFT(LOWER(v.name), 180), CHAR(9), v.id))
            WHERE v.kind = 'expense_category' AND v.starter_key IN ('insurance', 'road_tax', 'tax') AND v.name_key IS NULL
            SQL);

        // Tasse: il proprietario è quello del tipo di tassa. Bollo → "Bollo", il resto → "Tasse".
        $this->addSql(<<<'SQL'
            INSERT INTO user_values (user_id, organization_id, kind, name, name_key, starter_key, created_at)
            SELECT DISTINCT t.user_id, t.organization_id, 'expense_category',
                   CASE WHEN t.starter_key = 'road_tax' THEN IF(u.locale = 'en', 'Road tax', 'Bollo') ELSE IF(u.locale = 'en', 'Tax', 'Tassa') END,
                   LOWER(CASE WHEN t.starter_key = 'road_tax' THEN IF(u.locale = 'en', 'Road tax', 'Bollo') ELSE IF(u.locale = 'en', 'Tax', 'Tassa') END),
                   IF(t.starter_key = 'road_tax', 'road_tax', 'tax'), NOW()
            FROM expenses e
            INNER JOIN user_values t ON t.id = e.category_id AND t.kind = 'tax_type'
            INNER JOIN users u ON u.id = t.user_id
            WHERE e.section = 'tax' AND NOT EXISTS (
                SELECT 1 FROM user_values s WHERE s.user_id = t.user_id AND s.organization_id = t.organization_id
                  AND s.kind = 'expense_category' AND s.deleted_at IS NULL
                  AND s.starter_key = IF(t.starter_key = 'road_tax', 'road_tax', 'tax'))
            ON DUPLICATE KEY UPDATE starter_key = COALESCE(starter_key, VALUES(starter_key))
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE expenses e INNER JOIN user_values t ON t.id = e.category_id AND t.kind = 'tax_type'
            SET e.notes = CASE WHEN e.notes IS NULL OR e.notes = '' THEN CONCAT('[', t.name, ']') ELSE CONCAT('[', t.name, '] ', e.notes) END
            WHERE e.section = 'tax' AND (t.starter_key IS NULL OR t.starter_key NOT IN ('road_tax', 'other'))
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE expenses e
            INNER JOIN user_values t ON t.id = e.category_id AND t.kind = 'tax_type'
            SET e.category_id = (
                    SELECT s.id FROM user_values s
                    WHERE s.user_id = t.user_id AND s.organization_id = t.organization_id AND s.kind = 'expense_category'
                      AND s.deleted_at IS NULL AND s.starter_key = IF(t.starter_key = 'road_tax', 'road_tax', 'tax')
                    ORDER BY s.id LIMIT 1),
                e.section = 'other'
            WHERE e.section = 'tax'
            SQL);

        // Assicurazione: il proprietario è quello della compagnia, altrimenti quello del veicolo, altrimenti il
        // membro accettato più anziano dell'organizzazione
        $owner = <<<'SQL'
            COALESCE(
                (SELECT i.user_id FROM user_values i WHERE i.id = e.insurer_id),
                (SELECT sh.user_id FROM vehicle_shares sh WHERE sh.vehicle_id = e.vehicle_id AND sh.role = 'admin' AND sh.accepted_at IS NOT NULL ORDER BY sh.created_at, sh.id LIMIT 1),
                (SELECT m.user_id FROM organization_members m WHERE m.organization_id = e.organization_id AND m.accepted_at IS NOT NULL ORDER BY m.created_at, m.id LIMIT 1))
            SQL;
        $this->addSql(<<<SQL
            INSERT INTO user_values (user_id, organization_id, kind, name, name_key, starter_key, created_at)
            SELECT DISTINCT o.owner, o.organization_id, 'expense_category',
                   IF(u.locale = 'en', 'Insurance', 'Assicurazione'), LOWER(IF(u.locale = 'en', 'Insurance', 'Assicurazione')), 'insurance', NOW()
            FROM (SELECT e.organization_id, {$owner} AS owner FROM expenses e WHERE e.section = 'insurance') o
            INNER JOIN users u ON u.id = o.owner
            WHERE NOT EXISTS (
                SELECT 1 FROM user_values s WHERE s.user_id = o.owner AND s.organization_id = o.organization_id
                  AND s.kind = 'expense_category' AND s.deleted_at IS NULL AND s.starter_key = 'insurance')
            ON DUPLICATE KEY UPDATE starter_key = COALESCE(starter_key, VALUES(starter_key))
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE expenses e INNER JOIN user_values i ON i.id = e.insurer_id
            SET e.notes = CASE WHEN e.notes IS NULL OR e.notes = '' THEN CONCAT('[', i.name, ']') ELSE CONCAT('[', i.name, '] ', e.notes) END
            WHERE e.section = 'insurance'
            SQL);
        $this->addSql(<<<SQL
            UPDATE expenses e
            SET e.category_id = (
                    SELECT s.id FROM user_values s
                    WHERE s.user_id = {$owner} AND s.organization_id = e.organization_id AND s.kind = 'expense_category'
                      AND s.deleted_at IS NULL AND s.starter_key = 'insurance'
                    ORDER BY s.id LIMIT 1),
                e.section = 'other', e.insurer_id = NULL
            WHERE e.section = 'insurance'
            SQL);

        $this->addSql('ALTER TABLE expenses DROP FOREIGN KEY IF EXISTS FK_2496F35B895854C7');
        $this->addSql('DROP INDEX IF EXISTS IDX_2496F35B895854C7 ON expenses');
        $this->addSql('ALTER TABLE expenses DROP COLUMN IF EXISTS insurer_id, DROP COLUMN IF EXISTS section, MODIFY category_id BIGINT NOT NULL');
        $this->addSql("DELETE FROM user_values WHERE kind IN ('tax_type', 'insurer')");
    }

    /** Tipi di tassa di partenza per chi aveva Bollo o Tasse, e le loro spese spostate nelle tasse. */
    private function moveTaxes(): void
    {
        /** @var list<array{user_id: int|string, organization_id: int|string, locale: string|null, road_tax: string|null, tax: string|null}> $owners */
        $owners = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT v.user_id, v.organization_id, u.locale,
                   MAX(CASE WHEN v.starter_key = 'road_tax' THEN v.name END) AS road_tax,
                   MAX(CASE WHEN v.starter_key = 'tax' THEN v.name END) AS tax
            FROM user_values v INNER JOIN users u ON u.id = v.user_id
            WHERE v.kind = 'expense_category' AND v.starter_key IN ('road_tax', 'tax')
            GROUP BY v.user_id, v.organization_id, u.locale
            SQL);

        foreach ($owners as $owner) {
            $locale = (string) $owner['locale'];
            foreach (TaxType::cases() as $type) {
                $name = match ($type) {
                    TaxType::ROAD_TAX => $owner['road_tax'],
                    TaxType::OTHER => $owner['tax'],
                    default => null,
                } ?? $type->label($locale);
                $this->taxType((int) $owner['user_id'], (int) $owner['organization_id'], $type, $name);
            }
        }

        foreach (['road_tax' => 'road_tax', 'tax' => 'other'] as $category => $taxType) {
            $this->connection->executeStatement(
                <<<'SQL'
                    UPDATE expenses e
                    INNER JOIN user_values old ON old.id = e.category_id AND old.kind = 'expense_category' AND old.starter_key = :category
                    INNER JOIN user_values nw ON nw.user_id = old.user_id AND nw.organization_id = old.organization_id
                        AND nw.kind = 'tax_type' AND nw.starter_key = :taxType
                    SET e.category_id = nw.id, e.section = 'tax'
                    SQL,
                ['category' => $category, 'taxType' => $taxType],
            );
        }
    }

    /** Il tipo di tassa di partenza dell'utente; se manca lo crea (una voce omonima senza starter_key diventa quella). */
    private function taxType(int $user, int $org, TaxType $type, string $name): void
    {
        $exists = $this->connection->fetchOne(
            "SELECT 1 FROM user_values WHERE user_id = :user AND organization_id = :org AND kind = 'tax_type' AND starter_key = :starter",
            ['user' => $user, 'org' => $org, 'starter' => $type->value],
        );
        if ($exists !== false) {
            return;
        }

        $name = UserValue::normalizeName($name);
        $this->connection->executeStatement(
            "INSERT INTO user_values (user_id, organization_id, kind, name, name_key, starter_key, created_at)
             VALUES (:user, :org, 'tax_type', :name, :key, :starter, NOW())
             ON DUPLICATE KEY UPDATE starter_key = COALESCE(starter_key, VALUES(starter_key))",
            ['user' => $user, 'org' => $org, 'name' => $name, 'key' => UserValue::keyOf($name), 'starter' => $type->value],
        );
    }

    /** Vedi Version20261024120000::resetStaleTransactionLevel(). */
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
