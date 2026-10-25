<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Component;
use App\Entity\Maintenance;
use App\Entity\Organization;
use App\Entity\Vehicle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Component>
 */
class ComponentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Component::class);
    }

    /** Anche un componente eliminato (i suoi allegati restano raggiungibili dai lavori, per l'export). */
    public function findOneInOrganization(int $id, Organization $org): ?Component
    {
        return $this->findOneBy(['id' => $id, 'organization' => $org]);
    }

    /** Componente non eliminato: quelli eliminati non si aprono né si modificano. */
    public function findOneActiveInOrganization(int $id, Organization $org): ?Component
    {
        return $this->findOneBy(['id' => $id, 'organization' => $org, 'deletedAt' => null]);
    }

    /**
     * Componenti (non eliminati) del veicolo per nome, con il negozio già caricato.
     *
     * @return list<Component>
     */
    public function findByVehicle(Vehicle $vehicle): array
    {
        /** @var list<Component> */
        return $this->createQueryBuilder('c')
            ->addSelect('s')
            ->leftJoin('c.shop', 's')
            ->where('c.vehicle = :vehicle AND c.deletedAt IS NULL')
            ->setParameter('vehicle', $vehicle)
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** I componenti eliminati non hanno chiave: non si trovano per nome. */
    public function findOneByKey(Vehicle $vehicle, string $nameKey): ?Component
    {
        return $this->findOneBy(['vehicle' => $vehicle, 'nameKey' => $nameKey]);
    }

    /**
     * Lavori in cui il componente è stato usato, dal più recente.
     *
     * @return list<Maintenance>
     */
    public function findMaintenancesUsing(Component $component): array
    {
        /** @var list<Maintenance> */
        return $this->getEntityManager()->createQueryBuilder()
            ->select('m')
            ->from(Maintenance::class, 'm')
            ->join('m.components', 'c')
            ->where('c = :component')
            ->setParameter('component', $component)
            ->orderBy('m.performedAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
