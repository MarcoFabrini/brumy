<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Riconcilia le condivisioni dei veicoli con le membership. Le condivisioni si creano solo verso membri accettati,
 * ma il codice precedente alla rimozione con passaggio di proprietà lasciava le share di chi veniva rimosso, e
 * Version20261001120000 le ha contate come proprietà valide: un ex membro reinvitato tornava proprietario.
 *
 * Una share è valida solo se il suo utente è un membro accettato e non anonimizzato dell'org del veicolo.
 * - Proprietà (`admin`) non valida: passa al più anziano degli owner vivi dell'org, se il veicolo non ha già
 *   un altro proprietario valido; se l'org non ha owner vivi il veicolo resta senza proprietario (come in
 *   Version20261001120000).
 * - Ogni altra share non valida viene cancellata.
 *
 * Idempotente: una seconda esecuzione non trova più share non valide.
 */
final class Version20261022090000 extends AbstractMigration
{
    private const LIVE_MEMBER = <<<'SQL'
        EXISTS (SELECT 1 FROM organization_members m INNER JOIN users mu ON mu.id = m.user_id
            WHERE m.user_id = %1$s AND m.organization_id = %2$s
              AND m.accepted_at IS NOT NULL AND mu.email NOT LIKE '%%@anonymized.local')
        SQL;

    public function getDescription(): string
    {
        return 'vehicle_shares — rimuove le condivisioni di chi non è più membro vivo dell\'org, proprietà al più anziano owner';
    }

    public function up(Schema $schema): void
    {
        $staleOwners = $this->connection->fetchAllAssociative(sprintf(<<<'SQL'
            SELECT s.vehicle_id, s.user_id, v.organization_id
            FROM vehicle_shares s INNER JOIN vehicles v ON v.id = s.vehicle_id
            WHERE s.role = 'admin' AND NOT %s
            ORDER BY s.vehicle_id
            SQL, sprintf(self::LIVE_MEMBER, 's.user_id', 'v.organization_id')));

        foreach ($staleOwners as $row) {
            $vehicle = (int) $row['vehicle_id'];
            $this->addSql('DELETE FROM vehicle_shares WHERE vehicle_id = :vehicle AND user_id = :user', [
                'vehicle' => $vehicle,
                'user' => (int) $row['user_id'],
            ]);

            $newOwner = $this->connection->fetchOne(<<<'SQL'
                SELECT m.user_id FROM organization_members m INNER JOIN users u ON u.id = m.user_id
                WHERE m.organization_id = :org AND m.role = 'owner' AND m.accepted_at IS NOT NULL
                  AND u.email NOT LIKE '%@anonymized.local'
                ORDER BY m.id ASC LIMIT 1
                SQL, ['org' => (int) $row['organization_id']]);
            if ($newOwner === false) {
                continue;
            }
            // Il veicolo ha già un altro proprietario valido: niente da riassegnare. Letto prima delle cancellazioni
            // (che addSql esegue dopo up()), ma la share in esame non è valida e quindi non conta comunque.
            $hasLiveOwner = $this->connection->fetchOne(sprintf(<<<'SQL'
                SELECT 1 FROM vehicle_shares s INNER JOIN vehicles v ON v.id = s.vehicle_id
                WHERE s.vehicle_id = :vehicle AND s.role = 'admin' AND %s
                LIMIT 1
                SQL, sprintf(self::LIVE_MEMBER, 's.user_id', 'v.organization_id')), ['vehicle' => $vehicle]);
            if ($hasLiveOwner !== false) {
                continue;
            }
            $params = ['vehicle' => $vehicle, 'user' => (int) $newOwner];

            // Il nuovo proprietario aveva già una condivisione (unique vehicle+user): la si promuove.
            $this->addSql(
                "UPDATE vehicle_shares SET role = 'admin', accepted_at = COALESCE(accepted_at, NOW())
                 WHERE vehicle_id = :vehicle AND user_id = :user",
                $params,
            );
            $this->addSql(
                "INSERT INTO vehicle_shares (vehicle_id, user_id, role, invited_by, accepted_at, created_at)
                 SELECT :vehicle, :user, 'admin', :user, NOW(), NOW() FROM DUAL
                 WHERE NOT EXISTS (SELECT 1 FROM vehicle_shares WHERE vehicle_id = :vehicle AND user_id = :user)",
                $params,
            );
        }

        $this->addSql(sprintf(<<<'SQL'
            DELETE s FROM vehicle_shares s INNER JOIN vehicles v ON v.id = s.vehicle_id
            WHERE NOT %s
            SQL, sprintf(self::LIVE_MEMBER, 's.user_id', 'v.organization_id')));
    }

    public function down(Schema $schema): void
    {
        // Non reversibile: le condivisioni cancellate erano già senza effetto o indebite, ricrearle le riattiverebbe.
    }
}
