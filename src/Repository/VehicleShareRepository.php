<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Entity\VehicleShare;
use App\Enum\ShareRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VehicleShare>
 */
class VehicleShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VehicleShare::class);
    }

    public function findForUserAndVehicle(User $user, Vehicle $vehicle): ?VehicleShare
    {
        return $this->findOneBy(['user' => $user, 'vehicle' => $vehicle]);
    }

    /** @return list<VehicleShare> */
    public function findByVehicle(Vehicle $vehicle): array
    {
        return $this->findBy(['vehicle' => $vehicle], ['createdAt' => 'ASC']);
    }

    /**
     * Come {@see self::findByVehicle()} ma rilegge le righe dal DB anche se sono già nell'identity map:
     * serve dopo un lock pessimistico, quando un'altra richiesta può aver cambiato gli share nell'attesa.
     * Gli share eliminati nel frattempo non compaiono.
     *
     * @return list<VehicleShare>
     */
    public function findByVehicleRefreshed(Vehicle $vehicle): array
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.user', 'u')
            ->addSelect('u')
            ->where('s.vehicle = :vehicle')
            ->setParameter('vehicle', $vehicle)
            ->orderBy('s.createdAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
    }

    /**
     * Il proprietario attuale del veicolo (share `admin` accettato, il più vecchio se per dati legacy
     * ce ne fosse più di uno), o null per un veicolo orfano.
     */
    public function findOwnerShare(Vehicle $vehicle): ?VehicleShare
    {
        /** @var VehicleShare|null $share */
        $share = $this->createQueryBuilder('s')
            ->addSelect('u')
            ->innerJoin('s.user', 'u')
            ->where('s.vehicle = :vehicle')
            ->andWhere('s.role = :ownerRole')
            ->andWhere('s.acceptedAt IS NOT NULL')
            ->setParameter('vehicle', $vehicle)
            ->setParameter('ownerRole', ShareRole::ADMIN)
            ->orderBy('s.createdAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $share;
    }

    /**
     * Share di proprietà (`admin` accettato) dell'utente sui veicoli dell'org, archiviati inclusi:
     * stessa regola di {@see VehicleRepository::findOwnedByUserInOrganization}, ma restituisce le
     * share e non filtra l'archiviazione (un veicolo archiviato va comunque riassegnato).
     *
     * @return list<VehicleShare>
     */
    public function findOwnerSharesInOrganization(User $user, Organization $org): array
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.vehicle', 'v')
            ->addSelect('v')
            ->where('s.user = :user')
            ->andWhere('s.role = :ownerRole')
            ->andWhere('s.acceptedAt IS NOT NULL')
            ->andWhere('v.organization = :org')
            ->setParameter('user', $user)
            ->setParameter('ownerRole', ShareRole::ADMIN)
            ->setParameter('org', $org)
            ->orderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Cancella tutte le condivisioni dell'utente sui veicoli dell'org (proprietà compresa). Ritorna quante. */
    public function deleteAllForUserInOrganization(User $user, Organization $org): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'DELETE FROM '.VehicleShare::class.' s WHERE s.user = :user AND s.vehicle IN (SELECT v.id FROM '.Vehicle::class.' v WHERE v.organization = :org)',
        )->setParameter('user', $user)->setParameter('org', $org)->execute();
    }

    /**
     * Ruolo delle condivisioni accettate dell'utente sui veicoli dell'org, in UNA query: serve
     * alle liste per sapere quali veicoli sono suoi (`admin`) e quali condivisi con lui.
     *
     * @return array<int, ShareRole> vehicleId => ruolo
     */
    public function findAcceptedRolesByUserInOrganization(User $user, Organization $org): array
    {
        /** @var list<array{vehicleId: int|string, role: ShareRole|string}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.vehicle) AS vehicleId', 's.role AS role')
            ->innerJoin('s.vehicle', 'v')
            ->where('s.user = :user')
            ->andWhere('s.acceptedAt IS NOT NULL')
            ->andWhere('v.organization = :org')
            ->setParameter('user', $user)
            ->setParameter('org', $org)
            ->getQuery()
            ->getArrayResult();

        $roles = [];
        foreach ($rows as $row) {
            $role = $row['role'];
            $roles[(int) $row['vehicleId']] = $role instanceof ShareRole ? $role : ShareRole::from($role);
        }

        return $roles;
    }
}
