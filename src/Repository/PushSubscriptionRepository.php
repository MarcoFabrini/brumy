<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PushSubscription>
 */
class PushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushSubscription::class);
    }

    /** @return list<PushSubscription> */
    public function findActiveForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC']);
    }

    /** @return list<PushSubscription> */
    public function findActiveWebForUser(User $user): array
    {
        return $this->findBy(
            ['user' => $user, 'platform' => \App\Enum\PushPlatform::WEB],
            ['createdAt' => 'DESC'],
        );
    }

    /**
     * Cancella TUTTE le subscription (tutti gli utenti). Usato quando si rigenera
     * la coppia VAPID: la vecchia public key incastonata diventa inutilizzabile.
     *
     * @return int righe eliminate
     */
    public function deleteAll(): int
    {
        return (int) $this->createQueryBuilder('s')->delete()->getQuery()->execute();
    }

    public function countForUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }

    public function findByWebEndpoint(User $user, string $endpoint): ?PushSubscription
    {
        return $this->findOneBy(['user' => $user, 'endpoint' => $endpoint]);
    }
}
