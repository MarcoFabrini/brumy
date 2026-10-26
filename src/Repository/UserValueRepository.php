<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Component;
use App\Entity\Expense;
use App\Entity\Maintenance;
use App\Entity\Organization;
use App\Entity\Refueling;
use App\Entity\User;
use App\Entity\UserValue;
use App\Entity\Vehicle;
use App\Enum\UserValueKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserValue>
 */
class UserValueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserValue::class);
    }

    /** Le voci eliminate non hanno chiave: non si trovano per nome. */
    public function findOneInList(User $user, Organization $org, UserValueKind $kind, string $nameKey): ?UserValue
    {
        return $this->findOneBy(['user' => $user, 'organization' => $org, 'kind' => $kind, 'nameKey' => $nameKey]);
    }

    /** Voce della lista (non eliminata) di $user in $org. */
    public function findOneOwnedBy(int $id, User $user, Organization $org): ?UserValue
    {
        return $this->findOneBy(['id' => $id, 'user' => $user, 'organization' => $org, 'deletedAt' => null]);
    }

    /** Voce di partenza (non eliminata) con quella chiave nella lista di quel tipo. */
    public function findStarter(User $user, Organization $org, UserValueKind $kind, string $starterKey): ?UserValue
    {
        return $this->findOneBy(['user' => $user, 'organization' => $org, 'kind' => $kind, 'starterKey' => $starterKey, 'deletedAt' => null]);
    }

    /** Numero di record che puntano alla voce. */
    public function countUsages(UserValue $value): int
    {
        $count = 0;
        foreach (self::usagesOf($value->getKind()) as [$entity, $field]) {
            $count += (int) $this->getEntityManager()->createQueryBuilder()
                ->select('COUNT(r.id)')
                ->from($entity, 'r')
                ->where("r.$field = :value")
                ->setParameter('value', $value)
                ->getQuery()
                ->getSingleScalarResult();
        }

        return $count;
    }

    /**
     * Voci della lista (non eliminate) per i suggerimenti: le più usate prima, a parità la più recente, poi per nome.
     * `starterKey` dice da quale voce di partenza viene (es. il bollo, per proporne la scadenza), null se fatta a mano;
     * `fuelStationId` il distributore ufficiale collegato (solo distributori), da cui proporre il prezzo.
     *
     * @return list<array{id: int, name: string, starterKey: string|null, fuelStationId: int|null}>
     */
    public function findSuggestions(User $user, Organization $org, UserValueKind $kind): array
    {
        $rows = $this->findWithUsageRows($user, $org, $kind);
        // usort è stabile: a parità di uso e di data resta l'ordine per nome della query
        usort($rows, static fn (array $a, array $b): int => [$b['usageCount'], $b['lastUsed']] <=> [$a['usageCount'], $a['lastUsed']]);

        return array_map(static fn (array $row): array => ['id' => $row['id'], 'name' => $row['name'], 'starterKey' => $row['starterKey'], 'fuelStationId' => $row['fuelStationId']], $rows);
    }

    /**
     * Voci della lista (non eliminate) con il numero di record che le usano (in tutta l'organizzazione), per nome.
     *
     * @return list<array{id: int, name: string, usageCount: int, fuelStationId: int|null, followsPrice: bool}>
     */
    public function findWithUsage(User $user, Organization $org, UserValueKind $kind): array
    {
        return array_map(
            static fn (array $row): array => ['id' => $row['id'], 'name' => $row['name'], 'usageCount' => $row['usageCount'], 'fuelStationId' => $row['fuelStationId'], 'followsPrice' => $row['followsPrice']],
            $this->findWithUsageRows($user, $org, $kind),
        );
    }

    /**
     * I distributori dell'elenco (non eliminati) di cui l'utente segue il prezzo, per nome.
     *
     * @return list<UserValue>
     */
    public function findFollowedStations(User $user, Organization $org): array
    {
        /** @var list<UserValue> */
        return $this->createQueryBuilder('v')
            ->where('v.user = :user AND v.organization = :org AND v.kind = :kind AND v.deletedAt IS NULL')
            ->andWhere('v.followsPrice = true AND v.fuelStationId IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('org', $org)
            ->setParameter('kind', UserValueKind::STATION)
            ->orderBy('v.name', 'ASC')
            ->addOrderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Voci per nome con uso e data dell'ultimo uso ('' se mai usata), sommati su tutti i campi che usano il tipo.
     *
     * @return list<array{id: int, name: string, starterKey: string|null, fuelStationId: int|null, followsPrice: bool, usageCount: int, lastUsed: string}>
     */
    private function findWithUsageRows(User $user, Organization $org, UserValueKind $kind): array
    {
        /** @var list<array{id: int|string, name: string, starterKey: string|null, fuelStationId: int|string|null, followsPrice: bool|int|string}> $values */
        $values = $this->createQueryBuilder('v')
            ->select('v.id AS id', 'v.name AS name', 'v.starterKey AS starterKey', 'v.fuelStationId AS fuelStationId', 'v.followsPrice AS followsPrice')
            ->where('v.user = :user AND v.organization = :org AND v.kind = :kind AND v.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('org', $org)
            ->setParameter('kind', $kind)
            ->orderBy('v.name', 'ASC')
            ->addOrderBy('v.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        /** @var array<int, array{n: int, last: string}> $usage */
        $usage = [];
        foreach (self::usagesOf($kind) as [$entity, $field, $dateField]) {
            /** @var list<array{vid: int|string, n: int|string, last: \DateTimeInterface|string|null}> $rows */
            $rows = $this->getEntityManager()->createQueryBuilder()
                ->select("IDENTITY(r.$field) AS vid", 'COUNT(r.id) AS n', "MAX(r.$dateField) AS last")
                ->from($entity, 'r')
                ->join("r.$field", 'v')
                ->where('v.user = :user AND v.organization = :org AND v.kind = :kind AND v.deletedAt IS NULL')
                ->setParameter('user', $user)
                ->setParameter('org', $org)
                ->setParameter('kind', $kind)
                ->groupBy('vid')
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $last = $row['last'] instanceof \DateTimeInterface ? $row['last']->format('Y-m-d H:i:s') : (string) $row['last'];
                $current = $usage[(int) $row['vid']] ?? ['n' => 0, 'last' => ''];
                $usage[(int) $row['vid']] = ['n' => $current['n'] + (int) $row['n'], 'last' => max($current['last'], $last)];
            }
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'starterKey' => $row['starterKey'],
            'fuelStationId' => $row['fuelStationId'] === null ? null : (int) $row['fuelStationId'],
            'followsPrice' => (bool) $row['followsPrice'],
            'usageCount' => $usage[(int) $row['id']]['n'] ?? 0,
            'lastUsed' => $usage[(int) $row['id']]['last'] ?? '',
        ], $values);
    }

    /**
     * Record del veicolo che nel campo $field puntano a una voce di quel tipo fuori dalla lista di $owner (nell'org del veicolo).
     *
     * @param class-string $entity
     *
     * @return list<object>
     */
    public function findVehicleRecordsOutsideList(string $entity, string $field, UserValueKind $kind, Vehicle $vehicle, User $owner): array
    {
        /** @var list<object> */
        return $this->getEntityManager()->createQueryBuilder()
            ->select('r', 'v')
            ->from($entity, 'r')
            ->join("r.$field", 'v')
            ->where('r.vehicle = :vehicle AND v.kind = :kind')
            ->andWhere('v.user <> :owner OR v.organization <> :org')
            ->setParameter('vehicle', $vehicle)
            ->setParameter('kind', $kind)
            ->setParameter('owner', $owner)
            ->setParameter('org', $vehicle->getOrganization())
            ->getQuery()
            ->getResult();
    }

    /**
     * Tutte le voci di $user, eliminate comprese, in ogni organizzazione (export GDPR), con l'organizzazione già caricata.
     *
     * @return list<UserValue>
     */
    public function findAllOfUser(User $user): array
    {
        /** @var list<UserValue> */
        return $this->createQueryBuilder('v')
            ->addSelect('o')
            ->join('v.organization', 'o')
            ->where('v.user = :user')
            ->setParameter('user', $user)
            ->orderBy('o.id', 'ASC')
            ->addOrderBy('v.kind', 'ASC')
            ->addOrderBy('v.name', 'ASC')
            ->addOrderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Elimina le voci di $user in $org a cui non punta nessun record; restituisce quante. */
    public function deleteUnusedForUserInOrganization(User $user, Organization $org): int
    {
        $qb = $this->createQueryBuilder('v')
            ->delete()
            ->where('v.user = :user')
            ->andWhere('v.organization = :org')
            ->setParameter('user', $user)
            ->setParameter('org', $org);
        // NOT IN senza correlazione con la riga esterna (la DELETE DQL non ha alias): i NULL vanno esclusi
        foreach (self::allUsages() as $i => [$entity, $field]) {
            $qb->andWhere($qb->expr()->notIn(
                'v.id',
                $this->getEntityManager()->createQueryBuilder()
                    ->select("IDENTITY(u$i.$field)")
                    ->from($entity, "u$i")
                    ->where("u$i.$field IS NOT NULL")
                    ->getDQL(),
            ));
        }

        return (int) $qb->getQuery()->execute();
    }

    /**
     * Entity, campo e campo data dei record che puntano a una voce di quel tipo.
     *
     * @return list<array{class-string, string, string}>
     */
    public static function usagesOf(UserValueKind $kind): array
    {
        return match ($kind) {
            UserValueKind::STATION => [[Refueling::class, 'station', 'refueledAt']],
            UserValueKind::WORKSHOP => [[Maintenance::class, 'workshop', 'performedAt']],
            UserValueKind::SHOP => [[Maintenance::class, 'shop', 'performedAt'], [Component::class, 'shop', 'purchasedAt']],
            UserValueKind::EXPENSE_CATEGORY, UserValueKind::TAX_TYPE => [[Expense::class, 'category', 'occurredAt']],
            UserValueKind::INSURER => [[Expense::class, 'insurer', 'occurredAt']],
        };
    }

    /**
     * Ogni campo di record che punta a una voce, una volta sola.
     *
     * @return list<array{class-string, string, string}>
     */
    public static function allUsages(): array
    {
        $all = [];
        foreach (UserValueKind::cases() as $kind) {
            foreach (self::usagesOf($kind) as $usage) {
                $all[$usage[0].'::'.$usage[1]] = $usage;
            }
        }

        return array_values($all);
    }
}
