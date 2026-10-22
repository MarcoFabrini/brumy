<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Service\Storage\AttachmentStorageInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sostituisce o toglie la foto profilo. Il percorso precedente si legge sotto lock sulla riga `users`, non
 * dall'utente caricato a inizio richiesta: due caricamenti contemporanei dello stesso account leggerebbero lo stesso
 * vecchio file e quello salvato dal primo resterebbe sul volume senza più nessun riferimento.
 */
final class UserAvatar
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttachmentStorageInterface $storage,
    ) {
    }

    /**
     * Imposta `$storedPath` (già salvato nello storage) come foto dell'utente, o la toglie con null, e cancella il file
     * precedente dopo il commit. Rilegge l'utente: le modifiche non flushate dell'entity vanno salvate prima.
     */
    public function replace(User $user, ?string $storedPath): void
    {
        $previous = $this->em->wrapInTransaction(function () use ($user, $storedPath): ?string {
            $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
            $previous = $user->getAvatarPath();
            $user->setAvatarPath($storedPath);

            return $previous;
        });

        if ($previous !== null && $previous !== $storedPath) {
            try {
                $this->storage->delete($previous);
            } catch (\Throwable) {
                // File già assente: il DB è coerente, non blocchiamo la richiesta.
            }
        }
    }
}
