<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Vehicle;
use App\Enum\FuelType;
use App\Enum\RecurringPeriod;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Aggregati per la dashboard del veicolo.
 *
 * Le query SQL sono native per evitare il costo ORM su aggregazioni pesanti.
 *
 * Le spese ricorrenti si contano a ogni addebito già avvenuto ({@see RecurringSchedule}): il calcolo
 * dipende dal giorno corrente, non solo dai dati scritti. Il TTL della cache (300 s) è un ritardo
 * accettabile per un cambio di giorno; la modifica di una spesa invalida comunque la cache.
 */
final class VehicleStatsService
{
    /** Le statistiche costano ~9 query: si tengono in cache, invalidate a ogni scrittura che le alimenta. */
    private const CACHE_TTL = 300;

    public function __construct(
        private readonly Connection $db,
        private readonly CacheInterface $cache,
        private readonly FuelConsumption $consumption,
        private readonly RecurringSchedule $schedule,
        private readonly AppClock $clock,
    ) {
    }

    /** Toglie dalla cache le statistiche del veicolo (vedi VehicleStatsCacheInvalidator). */
    public function invalidate(int $vehicleId): void
    {
        $this->cache->delete(self::cacheKey($vehicleId));
    }

    private static function cacheKey(int $vehicleId): string
    {
        return 'vehicle_stats.'.$vehicleId;
    }

    /**
     * Statistiche complete per un veicolo. `totals.cost` e `costPerKm` contano ogni addebito già avvenuto
     * delle spese ricorrenti, mentre `totals.expenses` è il numero di RECORD spesa (non di addebiti).
     * `totals.sections` conta i record per sezione: scheduled, unscheduled, inspection (lavori), tax, insurance,
     * expense (spese) e component (componenti non eliminati), con le chiavi delle sezioni del frontend.
     *
     * @return array{
     *     consumption: array<string, float|null>,   // per fuel type → km/l
     *     totals: array{cost: string, refuelings: int, maintenances: int, expenses: int, sections: array<string, int>},
     *     currentKm: int,
     *     kmDriven: int,
     *     costPerKm: float|null,
     * }
     */
    public function compute(Vehicle $vehicle): array
    {
        $vehicleId = (int) $vehicle->getId();

        return $this->cache->get(self::cacheKey($vehicleId), function (ItemInterface $item) use ($vehicle): array {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->computeUncached($vehicle);
        });
    }

    /**
     * @return array{
     *     consumption: array<string, float|null>,
     *     totals: array{cost: string, refuelings: int, maintenances: int, expenses: int, sections: array<string, int>},
     *     currentKm: int,
     *     kmDriven: int,
     *     costPerKm: float|null,
     * }
     */
    private function computeUncached(Vehicle $vehicle): array
    {
        $vehicleId = (int) $vehicle->getId();

        $currentKm = $this->currentKm($vehicle);
        $kmDriven = max(0, $currentKm - $vehicle->getInitialKm());
        $totalCost = $this->totalCost($vehicleId);

        return [
            'consumption' => $this->averageConsumptionPerFuel($vehicleId, $vehicle->getAllFuelTypes()),
            'totals' => [
                'cost' => $totalCost,
                'refuelings' => $this->countByTable($vehicleId, 'refuelings'),
                'maintenances' => $this->countByTable($vehicleId, 'maintenances'),
                'expenses' => $this->countByTable($vehicleId, 'expenses'),
                'sections' => $this->countBySection($vehicleId),
            ],
            'currentKm' => $currentKm,
            'kmDriven' => $kmDriven,
            'costPerKm' => $kmDriven > 0 ? round((float) $totalCost / $kmDriven, 4) : null,
        ];
    }

    /**
     * Km attuali = odometro più alto inserito su rifornimenti/manutenzioni,
     * mai sotto i km iniziali del veicolo.
     */
    public function currentKm(Vehicle $vehicle): int
    {
        return max($this->maxRecordedKm((int) $vehicle->getId()), $vehicle->getInitialKm());
    }

    // ----- internals -----

    /**
     * Media km/l per ogni fuel type del veicolo, con l'algoritmo fill-to-fill di {@see FuelConsumption}.
     *
     * @param list<FuelType> $fuels
     * @return array<string, float|null>  key = fuel_type value
     */
    private function averageConsumptionPerFuel(int $vehicleId, array $fuels): array
    {
        // Una sola lettura ordinata di tutti i rifornimenti: servono anche quelli dell'altro carburante.
        /** @var list<array{km: string|int, liters: string, full_tank: string|int|bool, fuel_type: string, refueled_at: string, id: string|int}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT km, liters, full_tank, fuel_type, refueled_at, id
             FROM refuelings
             WHERE vehicle_id = :vid
             ORDER BY refueled_at ASC, km ASC, id ASC',
            ['vid' => $vehicleId],
        );

        $result = [];
        foreach ($fuels as $fuel) {
            $result[$fuel->value] = $this->consumption->kmPerLiter($this->consumption->intervals($rows, $fuel->value));
        }

        return $result;
    }

    /** Odometro più alto tra rifornimenti e manutenzioni (0 se nessun record). */
    private function maxRecordedKm(int $vehicleId): int
    {
        return (int) ($this->db->fetchOne(
            'SELECT GREATEST(
                COALESCE((SELECT MAX(km) FROM refuelings WHERE vehicle_id = :vid), 0),
                COALESCE((SELECT MAX(km) FROM maintenances WHERE vehicle_id = :vid), 0)
             )',
            ['vid' => $vehicleId],
        ) ?: 0);
    }

    /**
     * Costo totale: rifornimenti, manutenzioni, spese singole (SQL) e spese ricorrenti espanse in
     * addebiti. Si somma in centesimi interi e si formatta una volta sola.
     */
    private function totalCost(int $vehicleId): string
    {
        $cost = $this->db->fetchOne(
            'SELECT COALESCE(
                (SELECT SUM(total_cost) FROM refuelings WHERE vehicle_id = :vid), 0
             ) + COALESCE(
                (SELECT SUM(cost) FROM maintenances WHERE vehicle_id = :vid AND cost IS NOT NULL), 0
             ) + COALESCE(
                (SELECT SUM(amount) FROM expenses WHERE vehicle_id = :vid AND recurring = 0), 0
             )',
            ['vid' => $vehicleId],
        );
        $cents = self::cents((string) $cost) + $this->recurringExpenseCents($vehicleId);

        return number_format($cents / 100, 2, '.', '');
    }

    /** Somma, in centesimi, degli addebiti già avvenuti di tutte le spese ricorrenti del veicolo. */
    private function recurringExpenseCents(int $vehicleId): int
    {
        /** @var list<array{amount: string, occurred_at: string, recurring_period: string|null, recurring_until: string|null}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT amount, occurred_at, recurring_period, recurring_until
             FROM expenses
             WHERE vehicle_id = :vid AND recurring = 1',
            ['vid' => $vehicleId],
        );

        $today = $this->clock->today();
        $total = 0;
        foreach ($rows as $row) {
            $period = $row['recurring_period'] !== null ? RecurringPeriod::tryFrom($row['recurring_period']) : null;
            // Ricorrente senza periodo (dato incoerente, l'API non lo ammette): vale come spesa singola.
            $count = $period === null ? 1 : \count($this->schedule->chargesUpTo(
                $period,
                new \DateTimeImmutable($row['occurred_at']),
                $today,
                $row['recurring_until'] !== null ? new \DateTimeImmutable($row['recurring_until']) : null,
            ));
            $total += $count * self::cents($row['amount']);
        }

        return $total;
    }

    private static function cents(string $decimal): int
    {
        return (int) round((float) $decimal * 100);
    }

    /** @return array<string, int> sezione → numero di record, zero compresi */
    private function countBySection(int $vehicleId): array
    {
        /** @var list<array{section: string, n: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            "SELECT category AS section, COUNT(*) AS n FROM maintenances WHERE vehicle_id = :vid GROUP BY category
             UNION ALL
             SELECT CASE section WHEN 'other' THEN 'expense' ELSE section END, COUNT(*) FROM expenses WHERE vehicle_id = :vid GROUP BY section
             UNION ALL
             SELECT 'component', COUNT(*) FROM components WHERE vehicle_id = :vid AND deleted_at IS NULL",
            ['vid' => $vehicleId],
        );

        $counts = array_fill_keys(['scheduled', 'unscheduled', 'inspection', 'tax', 'insurance', 'expense', 'component'], 0);
        foreach ($rows as $row) {
            $counts[$row['section']] = (int) $row['n'];
        }

        return $counts;
    }

    private function countByTable(int $vehicleId, string $table): int
    {
        // Whitelist defensive: solo tabelle previste
        if (!in_array($table, ['refuelings', 'maintenances', 'expenses'], true)) {
            throw new \InvalidArgumentException("Unexpected table: $table");
        }
        return (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM $table WHERE vehicle_id = :vid",
            ['vid' => $vehicleId],
        );
    }
}
