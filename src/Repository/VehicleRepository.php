<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\ShareRole;
use App\Repository\Search\ListSearchFields;
use App\Repository\Search\ListSearchFilter;
use App\Service\Search\ListSearch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Vehicle>
 */
class VehicleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vehicle::class);
    }

    /**
     * Veicoli a cui l'utente ha accesso dentro l'org attiva:
     * - se l'utente è org owner/admin → tutti i veicoli dell'org
     * - se è member → solo quelli con uno share esplicito
     *
     * Di default solo i veicoli attivi; con `$archivedOnly` SOLO gli archiviati (stesse regole di accesso).
     * `$search` (ricerca dell'elenco) restringe ancora; null = nessun filtro.
     *
     * @return list<Vehicle>
     */
    public function findAccessibleByUserInOrganization(
        User $user,
        Organization $org,
        bool $archivedOnly = false,
        bool $isOrgAdmin = false,
        ?ListSearch $search = null,
    ): array {
        $qb = $this->createQueryBuilder('v')
            ->where('v.organization = :org')
            ->setParameter('org', $org)
            ->orderBy('v.name', 'ASC');

        $qb->andWhere($archivedOnly ? 'v.archivedAt IS NOT NULL' : 'v.archivedAt IS NULL');

        if (!$isOrgAdmin) {
            $qb->innerJoin('v.shares', 'vs', 'WITH', 'vs.user = :user AND vs.acceptedAt IS NOT NULL')
                ->setParameter('user', $user);
        }
        if ($search !== null) {
            ListSearchFilter::apply($qb, $search, self::searchFields());
        }

        return $qb->getQuery()->getResult();
    }

    /** Quanti veicoli ha l'organizzazione, archiviati inclusi (serve al tetto {@see \App\Service\VehicleQuota}). */
    public function countByOrganization(Organization $org): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.organization = :org')
            ->setParameter('org', $org)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Veicoli non archiviati di cui `$owner` è proprietario nell'org: share `admin` accettato.
     * Né quelli condivisi con lui né, per owner/admin dell'org, quelli degli altri membri
     * (stessa regola di {@see ReminderRepository::findUpcomingForOwner}). È la fonte dei
     * veicoli su cui si calcolano i grafici della dashboard.
     *
     * @return list<Vehicle>
     */
    public function findOwnedByUserInOrganization(User $owner, Organization $org): array
    {
        return $this->createQueryBuilder('v')
            ->innerJoin('v.shares', 'vs', 'WITH', 'vs.user = :owner AND vs.role = :ownerRole AND vs.acceptedAt IS NOT NULL')
            ->where('v.organization = :org')
            ->andWhere('v.archivedAt IS NULL')
            ->setParameter('org', $org)
            ->setParameter('owner', $owner)
            ->setParameter('ownerRole', ShareRole::ADMIN)
            ->orderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Tutti i veicoli di cui `$owner` è proprietario in QUALSIASI organizzazione, archiviati inclusi:
     * stessa regola di {@see self::findOwnedByUserInOrganization} (share `admin` accettato) ma senza
     * filtro org né archiviazione. Serve all'export GDPR, che deve restituire i dati dell'utente e mai
     * quelli degli altri membri (nemmeno se l'utente è owner dell'org).
     *
     * @return list<Vehicle>
     */
    public function findAllOwnedByUser(User $owner): array
    {
        return $this->createQueryBuilder('v')
            ->innerJoin('v.shares', 'vs', 'WITH', 'vs.user = :owner AND vs.role = :ownerRole AND vs.acceptedAt IS NOT NULL')
            ->innerJoin('v.organization', 'o')
            ->addSelect('o')
            ->setParameter('owner', $owner)
            ->setParameter('ownerRole', ShareRole::ADMIN)
            ->orderBy('o.id', 'ASC')
            ->addOrderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneInOrganization(int $id, Organization $org): ?Vehicle
    {
        return $this->findOneBy(['id' => $id, 'organization' => $org]);
    }

    /** Dove cerca la ricerca dell'elenco dei veicoli: nomi e identificativi, tipo, carburanti, anno, km iniziali. */
    private static function searchFields(): ListSearchFields
    {
        return new ListSearchFields(
            text: ['v.name', 'v.brand', 'v.model', 'v.licensePlate', 'v.vin', 'v.notes'],
            families: ['vehicle_type' => ['v.type'], 'fuel_type' => ['v.fuelType', 'v.secondaryFuelType']],
            year: 'v.year',
            km: ['v.initialKm'],
        );
    }
}
