<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Maintenance;
use App\Entity\Organization;
use App\Entity\Vehicle;
use App\Enum\MaintenanceCategory;
use App\Repository\Search\ListSearchFields;
use App\Repository\Search\ListSearchFilter;
use App\Service\Search\ListSearch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Maintenance>
 */
class MaintenanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Maintenance::class);
    }

    public function findOneInOrganization(int $id, Organization $org): ?Maintenance
    {
        return $this->findOneBy(['id' => $id, 'organization' => $org]);
    }

    /**
     * @param MaintenanceCategory|null $category sezione (ordinari, imprevisti, revisione); null = tutte
     * @param ListSearch|null $search ricerca dell'elenco; null = nessun filtro
     *
     * @return list<Maintenance>
     */
    public function findByVehiclePaginated(Vehicle $vehicle, int $page = 1, int $limit = 20, ?MaintenanceCategory $category = null, ?ListSearch $search = null): array
    {
        $offset = max(0, ($page - 1) * $limit);
        $qb = $this->createQueryBuilder('m')
            ->where('m.vehicle = :vehicle')
            ->setParameter('vehicle', $vehicle);
        if ($category !== null) {
            $qb->andWhere('m.category = :category')->setParameter('category', $category);
        }
        if ($search !== null) {
            ListSearchFilter::apply($qb, $search, self::searchFields());
        }

        return $qb
            ->orderBy('m.performedAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Dove cerca la ricerca dell'elenco dei lavori: testi, officina, negozio, nomi dei componenti usati. */
    private static function searchFields(): ListSearchFields
    {
        return new ListSearchFields(
            text: ['m.description', 'm.notes', 'workshop.name', 'shop.name'],
            joins: ['workshop' => 'm.workshop', 'shop' => 'm.shop'],
            collections: ['components' => ['name']],
            families: ['maintenance_type' => ['m.type'], 'maintenance_category' => ['m.category']],
            date: 'm.performedAt',
            amount: 'm.cost',
            km: ['m.km'],
        );
    }
}
