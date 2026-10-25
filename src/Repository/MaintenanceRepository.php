<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Maintenance;
use App\Entity\Organization;
use App\Entity\Vehicle;
use App\Enum\MaintenanceCategory;
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
     *
     * @return list<Maintenance>
     */
    public function findByVehiclePaginated(Vehicle $vehicle, int $page = 1, int $limit = 20, ?MaintenanceCategory $category = null): array
    {
        $offset = max(0, ($page - 1) * $limit);
        $qb = $this->createQueryBuilder('m')
            ->where('m.vehicle = :vehicle')
            ->setParameter('vehicle', $vehicle);
        if ($category !== null) {
            $qb->andWhere('m.category = :category')->setParameter('category', $category);
        }

        return $qb
            ->orderBy('m.performedAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
