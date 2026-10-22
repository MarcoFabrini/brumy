<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RefreshToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RefreshToken>
 */
class RefreshTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefreshToken::class);
    }

    public function findValidByToken(string $token): ?RefreshToken
    {
        $rt = $this->findOneBy(['token' => $token]);
        return $rt?->isValid() ? $rt : null;
    }

    /** Il token dato il suo hash, in qualsiasi stato (anche ruotato, revocato o scaduto). */
    public function findOneByHash(string $hash): ?RefreshToken
    {
        return $this->findOneBy(['token' => $hash]);
    }

    /**
     * Rotazione atomica: revoca il token e lo segna come ruotato. True solo per la richiesta che passa per
     * prima (niente doppia rotazione concorrente).
     */
    public function rotateIfActive(RefreshToken $token): bool
    {
        $now = new \DateTimeImmutable();
        $affected = $this->createQueryBuilder('rt')
            ->update()
            ->set('rt.revokedAt', ':now')
            ->set('rt.rotatedAt', ':now')
            ->where('rt.id = :id')
            ->andWhere('rt.revokedAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('id', $token->getId())
            ->getQuery()
            ->execute();

        if ($affected !== 1) {
            return false;
        }
        $token->markRotated($now);

        return true;
    }

    public function countActiveForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('rt')
            ->select('COUNT(rt.id)')
            ->where('rt.user = :user')
            ->andWhere('rt.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Revoca tutti i token ancora attivi dell'utente. Ritorna quanti ne ha revocati. */
    public function revokeAllForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('rt')
            ->update()
            ->set('rt.revokedAt', ':now')
            ->where('rt.user = :user')
            ->andWhere('rt.revokedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /** Revoca tutti i token ancora attivi della famiglia (la sessione intera). Ritorna quanti ne ha revocati. */
    public function revokeFamily(string $familyId): int
    {
        return (int) $this->createQueryBuilder('rt')
            ->update()
            ->set('rt.revokedAt', ':now')
            ->where('rt.familyId = :family')
            ->andWhere('rt.revokedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('family', $familyId)
            ->getQuery()
            ->execute();
    }

    /**
     * Cancella i token scaduti o revocati prima di `$olderThan`. Un token ruotato ancora non scaduto resta
     * comunque: finché potrebbe essere ripresentato serve per riconoscere il riuso (RefreshTokenService).
     */
    public function deleteExpiredAndRevoked(\DateTimeImmutable $olderThan): int
    {
        return (int) $this->createQueryBuilder('rt')
            ->delete()
            ->where('rt.expiresAt < :cutoff OR (rt.revokedAt < :cutoff AND (rt.rotatedAt IS NULL OR rt.expiresAt < :now))')
            ->setParameter('cutoff', $olderThan)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }
}
