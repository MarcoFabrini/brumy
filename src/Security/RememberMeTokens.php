<?php

declare(strict_types=1);

namespace App\Security;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Le due query sulla tabella `rememberme_token` (DoctrineTokenProvider di Symfony, nessuna entity) che Symfony non
 * offre: cancellare le serie di un utente (GDPR: la colonna `username` è l'email) e quelle non usate da una data
 * (pulizia periodica: Symfony non scade le righe da sé).
 */
final class RememberMeTokens
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function deleteForUser(string $userIdentifier): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM rememberme_token WHERE username = :username',
            ['username' => $userIdentifier],
        );
    }

    public function deleteUnusedSince(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM rememberme_token WHERE lastUsed < :cutoff',
            ['cutoff' => $cutoff],
            ['cutoff' => Types::DATETIME_IMMUTABLE],
        );
    }
}
