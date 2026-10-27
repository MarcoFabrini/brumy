<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => strtolower(trim($email))]);
    }

    /** L'istanza ha almeno un utente? Finché no, la registrazione libera è aperta (bootstrap del primo admin). */
    public function hasUsers(): bool
    {
        return $this->findOneBy([]) !== null;
    }

    /**
     * Il primo utente mai registrato è l'amministratore implicito dell'istanza
     * self-host (impostazioni globali VAPID — SMTP invece è solo env, vedi
     * AppMailer) — nessun flag/migrazione: id crescente = ordine di creazione,
     * stesso idioma di PushSettingsRepository per le config singleton di
     * istanza. Se quell'utente si elimina (GDPR) la sua riga resta in DB
     * anonimizzata: va saltata, così il ruolo passa al successivo più vecchio
     * invece di restare su un account che nessuno può usare.
     */
    public function isInstanceAdmin(User $user): bool
    {
        $firstId = $this->createQueryBuilder('u')
            ->select('u.id')
            ->where('u.email NOT LIKE :anonymized')
            ->setParameter('anonymized', '%'.User::ANONYMIZED_EMAIL_SUFFIX)
            ->orderBy('u.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $firstId !== null && (int) $firstId['id'] === $user->getId();
    }

    /**
     * Ricorda l'org scelta come attiva senza flush dell'intera unit of work (il resolver gira anche a metà di una
     * richiesta che sta modificando altre entity).
     */
    public function rememberActiveOrganization(User $user, Organization $organization): void
    {
        $this->createQueryBuilder('u')
            ->update()
            ->set('u.lastActiveOrganization', ':org')
            ->where('u.id = :id')
            ->setParameter('org', $organization)
            ->setParameter('id', $user->getId())
            ->getQuery()
            ->execute();
        $user->setLastActiveOrganization($organization);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }
}
