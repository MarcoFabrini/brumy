<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Expense;
use App\Entity\Organization;
use App\Entity\Vehicle;
use App\Enum\ExpenseSection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Expense>
 */
class ExpenseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Expense::class);
    }

    public function findOneInOrganization(int $id, Organization $org): ?Expense
    {
        return $this->findOneBy(['id' => $id, 'organization' => $org]);
    }

    /**
     * @param ExpenseSection|null $section tasse, assicurazione o altre; null = tutte
     *
     * @return list<Expense>
     */
    public function findByVehiclePaginated(Vehicle $vehicle, int $page = 1, int $limit = 20, ?ExpenseSection $section = null): array
    {
        $offset = max(0, ($page - 1) * $limit);
        $qb = $this->createQueryBuilder('e')
            ->addSelect('c', 'i')
            ->leftJoin('e.category', 'c') // serializzate in ogni riga della lista
            ->leftJoin('e.insurer', 'i')
            ->where('e.vehicle = :vehicle')
            ->setParameter('vehicle', $vehicle);
        if ($section !== null) {
            $qb->andWhere('e.section = :section')->setParameter('section', $section);
        }

        return $qb
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
