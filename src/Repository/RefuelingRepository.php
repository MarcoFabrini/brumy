<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\Refueling;
use App\Entity\Vehicle;
use App\Enum\FuelType;
use App\Repository\Search\ListSearchFields;
use App\Repository\Search\ListSearchFilter;
use App\Service\Search\ListSearch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Refueling>
 */
class RefuelingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Refueling::class);
    }

    public function findOneInOrganization(int $id, Organization $org): ?Refueling
    {
        return $this->findOneBy(['id' => $id, 'organization' => $org]);
    }

    /**
     * @param ListSearch|null $search ricerca dell'elenco; null = nessun filtro
     *
     * @return list<Refueling>
     */
    public function findByVehiclePaginated(Vehicle $vehicle, int $page = 1, int $limit = 20, ?ListSearch $search = null): array
    {
        $offset = max(0, ($page - 1) * $limit);
        $qb = $this->createQueryBuilder('r')
            ->where('r.vehicle = :vehicle')
            ->setParameter('vehicle', $vehicle);
        if ($search !== null) {
            ListSearchFilter::apply($qb, $search, self::searchFields());
        }

        return $qb
            ->orderBy('r.refueledAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Carburanti usati dai rifornimenti del veicolo (senza duplicati).
     *
     * @return list<FuelType>
     */
    public function findUsedFuelTypes(Vehicle $vehicle): array
    {
        /** @var list<array{fuelType: FuelType}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('DISTINCT r.fuelType AS fuelType')
            ->where('r.vehicle = :vehicle')
            ->setParameter('vehicle', $vehicle)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): FuelType => $row['fuelType'], $rows);
    }

    /** Dove cerca la ricerca dell'elenco dei rifornimenti: testi, distributore, carburante, pieno/parziale. */
    private static function searchFields(): ListSearchFields
    {
        return new ListSearchFields(
            text: ['r.description', 'r.notes', 'station.name'],
            joins: ['station' => 'r.station'],
            families: ['fuel_type' => ['r.fuelType'], 'refueling_tank' => ['r.fullTank']],
            date: 'r.refueledAt',
            amount: 'r.totalCost',
            km: ['r.km'],
        );
    }
}
