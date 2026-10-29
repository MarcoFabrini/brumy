<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Expense;
use App\Entity\Organization;
use App\Entity\Vehicle;
use App\Enum\ExpenseSection;
use App\Repository\Search\ListSearchFields;
use App\Repository\Search\ListSearchFilter;
use App\Service\Search\ListSearch;
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
     * @param ListSearch|null $search ricerca dell'elenco; null = nessun filtro
     *
     * @return list<Expense>
     */
    public function findByVehiclePaginated(Vehicle $vehicle, int $page = 1, int $limit = 20, ?ExpenseSection $section = null, ?ListSearch $search = null): array
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
        if ($search !== null) {
            ListSearchFilter::apply($qb, $search, self::searchFields());
        }

        return $qb
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Dove cerca la ricerca dell'elenco delle spese: testi, categoria o tipo di tassa, compagnia (già uniti). */
    private static function searchFields(): ListSearchFields
    {
        return new ListSearchFields(
            text: ['e.description', 'e.notes', 'c.name', 'i.name'],
            joins: ['c' => 'e.category', 'i' => 'e.insurer'],
            families: ['expense_section' => ['e.section'], 'recurring_period' => ['e.recurringPeriod']],
            date: 'e.occurredAt',
            amount: 'e.amount',
        );
    }
}
