<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organization;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\RecurringPeriod;
use App\Repository\VehicleRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Serie mensili per i grafici, con due soli perimetri possibili:
 * - dashboard ({@see self::compute()}): SOLO i veicoli di proprietà dell'utente nell'org attiva
 *   (archiviati esclusi), come i totali e le scadenze della dashboard. Gli id vengono solo da
 *   {@see VehicleRepository::findOwnedByUserInOrganization}, l'unico punto in cui stanno la regola
 *   di proprietà e lo scoping per org;
 * - singolo veicolo ({@see self::computeForVehicle()}): il veicolo del dettaglio, archiviato o no.
 *   Lo scoping per org e il controllo VEHICLE_VIEW spettano al chiamante.
 * Non c'è un ingresso pubblico che accetti una lista arbitraria di veicoli.
 *
 * Le aggregazioni sono SQL nativo (raggruppamento per mese con DATE_FORMAT, che il DQL non
 * esprime) in un numero costante di query, indipendente dai veicoli.
 *
 * Nessuna cache applicativa: il dato della dashboard è per utente e la sua invalidazione
 * dipenderebbe da chi è proprietario di ogni veicolo toccato, quello del veicolo varia con la
 * finestra e il mese corrente; le query aggregate su indici (vehicle_id, data) costano poco e lato
 * client React Query evita le richieste ripetute.
 *
 * Le spese ricorrenti entrano a ogni addebito già avvenuto che cade nella finestra ({@see RecurringSchedule}):
 * quelle singole restano in SQL (`recurring = 0`), le ricorrenti si leggono con UNA query in più per
 * tutti i veicoli e si espandono in PHP per mese, sezione e voce di categoria. Le serie sono per sezione di
 * costo ({@see self::SOURCES}); i componenti non contano (il loro prezzo è già nel costo del lavoro che li monta). Il risultato dipende dal giorno
 * corrente (oggi come limite degli addebiti), ma i grafici non sono in cache, quindi nessun ritardo.
 * Le tre viste di spesa (serie mensile, totale, categorie) derivano dagli stessi importi e
 * tornano sempre alla pari.
 *
 * Gli importi si sommano in centesimi interi (niente errori di arrotondamento dei float) e si
 * restituiscono come stringhe a 2 decimali, come il resto delle API.
 *
 * @phpstan-import-type FillInterval from FuelConsumption
 * @phpstan-import-type RefuelingRow from FuelConsumption
 *
 * @phpstan-type CategorySlice array{category: string, name: string|null, amount: string}
 * @phpstan-type CategoryCents array{name: string|null, cents: int}
 * @phpstan-type MonthSpending array{refuelings: string, scheduled: string, unscheduled: string, inspection: string, taxes: string, insurance: string, expenses: string, total: string}
 * @phpstan-type MonthPoint array{month: string, spending: MonthSpending, kmDriven: int, consumption: array<string, float|null>}
 * @phpstan-type DashboardCharts array{
 *     from: string,
 *     to: string,
 *     fuelTypes: list<string>,
 *     months: list<MonthPoint>,
 *     spendingByCategory: list<CategorySlice>,
 *     totals: array{spending: string, kmDriven: int},
 * }
 */
final class DashboardChartsService
{
    public const DEFAULT_MONTHS = 12;
    public const MAX_MONTHS = 24;

    /**
     * Sezioni di costo, nell'ordine delle serie mensili: rifornimenti, lavori ordinari, imprevisti, revisione
     * (manutenzioni per `category`), tasse, assicurazione e altre spese (spese per `section`).
     */
    private const SOURCES = ['refuelings', 'scheduled', 'unscheduled', 'inspection', 'taxes', 'insurance', 'expenses'];

    /**
     * Chiave di `spendingByCategory` di ogni sezione. Le altre spese non hanno una fetta sola ma una per categoria,
     * `expense:<id della voce>`: per id, così due voci con lo stesso nome non si fondono e un nome scelto
     * dall'utente non collide con `fuel`.
     */
    private const SOURCE_SLICES = [
        'refuelings' => 'fuel',
        'scheduled' => 'scheduled',
        'unscheduled' => 'unscheduled',
        'inspection' => 'inspection',
        'taxes' => 'tax',
        'insurance' => 'insurance',
    ];
    private const CATEGORY_EXPENSE_PREFIX = 'expense:';

    /** Marcatore delle letture d'odometro precedenti alla finestra (base dei km cumulati). */
    private const BEFORE_WINDOW = 'before';

    public function __construct(
        private readonly Connection $db,
        private readonly VehicleRepository $vehicleRepo,
        private readonly AppClock $clock,
        private readonly FuelConsumption $consumption,
        private readonly RecurringSchedule $schedule,
    ) {
    }

    /**
     * Grafici della dashboard: veicoli di proprietà dell'utente nell'org, archiviati esclusi.
     *
     * @return DashboardCharts
     */
    public function compute(User $user, Organization $org, int $months): array
    {
        return $this->computeForVehicles($this->vehicleRepo->findOwnedByUserInOrganization($user, $org), $months);
    }

    /**
     * Grafici di un solo veicolo (anche archiviato). Il servizio non autorizza: il chiamante deve
     * aver già caricato il veicolo nell'org attiva e verificato VEHICLE_VIEW.
     *
     * @return DashboardCharts
     */
    public function computeForVehicle(Vehicle $vehicle, int $months): array
    {
        return $this->computeForVehicles([$vehicle], $months);
    }

    /**
     * Serie pronte per JSON: `consumption` è una mappa carburante → valore e, vuota (nessun
     * veicolo), va serializzata come `{}` e non come lista `[]`.
     *
     * @param DashboardCharts $charts
     * @return array<string, mixed>
     */
    public static function toJson(array $charts): array
    {
        $charts['months'] = array_map(
            static fn (array $point): array => [...$point, 'consumption' => (object) $point['consumption']],
            $charts['months'],
        );

        return $charts;
    }

    /**
     * Grafici degli ultimi `$months` mesi, mese corrente (nel fuso dell'istanza) incluso.
     * Tutti i mesi della finestra sono presenti in ordine crescente, anche se vuoti.
     *
     * @param list<Vehicle> $vehicles
     * @return DashboardCharts
     */
    private function computeForVehicles(array $vehicles, int $months): array
    {
        if ($months < 1 || $months > self::MAX_MONTHS) {
            throw new \InvalidArgumentException(sprintf('months must be between 1 and %d, got %d', self::MAX_MONTHS, $months));
        }

        $currentMonth = $this->clock->today()->modify('first day of this month');
        $firstMonth = $currentMonth->modify(sprintf('-%d months', $months - 1));
        $from = $firstMonth->format('Y-m-d');
        $until = $currentMonth->modify('+1 month')->format('Y-m-d'); // escluso

        $monthKeys = [];
        for ($month = $firstMonth; $month <= $currentMonth; $month = $month->modify('+1 month')) {
            $monthKeys[] = $month->format('Y-m');
        }

        $fuelTypes = self::fuelTypes($vehicles);

        // Uscita anticipata: senza veicoli non c'è niente da aggregare.
        $ids = self::ids($vehicles);
        $spending = $ids === [] ? [] : $this->monthlySpendingCents($ids, $from, $until);
        $expenseCategories = $ids === [] ? [] : $this->expenseCategoryCents($ids, $from, $until);
        if ($ids !== []) {
            // Addebiti delle spese ricorrenti nella finestra, sommati a quelli delle spese singole.
            $recurring = $this->recurringExpenseCents($ids, $firstMonth, $currentMonth->modify('+1 month'), $this->clock->today());
            foreach ($recurring['months'] as $key => $bySource) {
                foreach ($bySource as $source => $cents) {
                    $spending[$key][$source] = ($spending[$key][$source] ?? 0) + $cents;
                }
            }
            foreach ($recurring['categories'] as $category => $slice) {
                $expenseCategories[$category] = [
                    'name' => $slice['name'],
                    'cents' => ($expenseCategories[$category]['cents'] ?? 0) + $slice['cents'],
                ];
            }
        }
        $kmDriven = $ids === [] ? [] : $this->monthlyKmDriven($vehicles, $monthKeys, $from, $until);
        $consumption = $ids === [] ? [] : $this->monthlyConsumption($vehicles, $from, $until);

        $points = [];
        $totalCents = 0;
        $totalKm = 0;
        $sourceCents = array_fill_keys(self::SOURCES, 0);
        foreach ($monthKeys as $key) {
            $monthCents = [];
            foreach (self::SOURCES as $source) {
                $monthCents[$source] = $spending[$key][$source] ?? 0;
                $sourceCents[$source] += $monthCents[$source];
            }
            $monthTotal = array_sum($monthCents);
            $km = $kmDriven[$key] ?? 0;
            $totalCents += $monthTotal;
            $totalKm += $km;

            $monthConsumption = [];
            foreach ($fuelTypes as $fuel) {
                $monthConsumption[$fuel] = $this->consumption->kmPerLiter($consumption[$key][$fuel] ?? []);
            }

            $points[] = [
                'month' => $key,
                'spending' => self::monthSpending($monthCents, $monthTotal),
                'kmDriven' => $km,
                'consumption' => $monthConsumption,
            ];
        }

        $slices = [];
        foreach (self::SOURCE_SLICES as $source => $slice) {
            $slices[$slice] = ['name' => null, 'cents' => $sourceCents[$source]];
        }

        return [
            'from' => $firstMonth->format('Y-m'),
            'to' => $currentMonth->format('Y-m'),
            'fuelTypes' => $fuelTypes,
            'months' => $points,
            'spendingByCategory' => self::byCategory($slices + $expenseCategories),
            'totals' => ['spending' => self::money($totalCents), 'kmDriven' => $totalKm],
        ];
    }

    // ----- query -----

    /**
     * Spese della finestra per mese e per sorgente, in centesimi.
     *
     * @param list<int> $ids
     * @return array<string, array<string, int>> mese (Y-m) → sezione ({@see self::SOURCES}) → centesimi
     */
    private function monthlySpendingCents(array $ids, string $from, string $until): array
    {
        /** @var list<array{source: string, ym: string, amount: string|null}> $rows */
        $rows = $this->db->fetchAllAssociative(
            "SELECT 'refuelings' AS source, DATE_FORMAT(refueled_at, '%Y-%m') AS ym, SUM(total_cost) AS amount
             FROM refuelings
             WHERE vehicle_id IN (:ids) AND refueled_at >= :from AND refueled_at < :until
             GROUP BY ym
             UNION ALL
             SELECT category, DATE_FORMAT(performed_at, '%Y-%m') AS ym, SUM(cost)
             FROM maintenances
             WHERE vehicle_id IN (:ids) AND performed_at >= :from AND performed_at < :until AND cost IS NOT NULL
             GROUP BY category, ym
             UNION ALL
             SELECT CASE section WHEN 'tax' THEN 'taxes' WHEN 'insurance' THEN 'insurance' ELSE 'expenses' END,
                    DATE_FORMAT(occurred_at, '%Y-%m') AS ym, SUM(amount)
             FROM expenses
             WHERE vehicle_id IN (:ids) AND occurred_at >= :from AND occurred_at < :until AND recurring = 0
             GROUP BY section, ym",
            ['ids' => $ids, 'from' => $from, 'until' => $until],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['ym']][$row['source']] = ($result[$row['ym']][$row['source']] ?? 0) + self::cents($row['amount']);
        }

        return $result;
    }

    /**
     * Altre spese singole della finestra per voce di categoria, in centesimi, col nome attuale della voce
     * (tasse e assicurazione hanno una fetta per sezione).
     *
     * @param list<int> $ids
     * @return array<string, CategoryCents> `expense:<id voce>` → nome e centesimi
     */
    private function expenseCategoryCents(array $ids, string $from, string $until): array
    {
        /** @var list<array{category_id: string|int, name: string, amount: string|null}> $rows */
        $rows = $this->db->fetchAllAssociative(
            "SELECT e.category_id, c.name, SUM(e.amount) AS amount
             FROM expenses e
             INNER JOIN user_values c ON c.id = e.category_id
             WHERE e.vehicle_id IN (:ids) AND e.occurred_at >= :from AND e.occurred_at < :until AND e.recurring = 0
               AND e.section = 'other'
             GROUP BY e.category_id, c.name",
            ['ids' => $ids, 'from' => $from, 'until' => $until],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[self::CATEGORY_EXPENSE_PREFIX.$row['category_id']] = ['name' => $row['name'], 'cents' => self::cents($row['amount'])];
        }

        return $result;
    }

    /**
     * Addebiti delle spese ricorrenti che cadono nella finestra, in centesimi per mese e sezione, e per categoria
     * (solo le altre spese, come {@see self::expenseCategoryCents()}).
     * Una sola query per tutti i veicoli: si leggono le ricorrenti già iniziate prima della fine della
     * finestra e non finite prima del suo inizio; poi ogni spesa si espande con {@see RecurringSchedule}.
     *
     * @param list<int> $ids
     * @return array{months: array<string, array<string, int>>, categories: array<string, CategoryCents>}
     */
    private function recurringExpenseCents(array $ids, \DateTimeImmutable $from, \DateTimeImmutable $until, \DateTimeImmutable $today): array
    {
        /** @var list<array{amount: string, section: string, category_id: string|int|null, name: string|null, occurred_at: string, recurring_period: string|null, recurring_until: string|null}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT e.amount, e.section, e.category_id, c.name, e.occurred_at, e.recurring_period, e.recurring_until
             FROM expenses e
             LEFT JOIN user_values c ON c.id = e.category_id
             WHERE e.vehicle_id IN (:ids) AND e.recurring = 1
               AND e.occurred_at < :until AND (e.recurring_until IS NULL OR e.recurring_until >= :from)',
            ['ids' => $ids, 'from' => $from->format('Y-m-d'), 'until' => $until->format('Y-m-d')],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $months = [];
        $categories = [];
        foreach ($rows as $row) {
            $period = $row['recurring_period'] !== null ? RecurringPeriod::tryFrom($row['recurring_period']) : null;
            $start = new \DateTimeImmutable($row['occurred_at']);
            // Ricorrente senza periodo (dato incoerente, l'API non lo ammette): vale come spesa singola.
            $charges = $period === null
                ? [$start]
                : $this->schedule->chargesInWindow(
                    $period,
                    $start,
                    $from,
                    $until,
                    $today,
                    $row['recurring_until'] !== null ? new \DateTimeImmutable($row['recurring_until']) : null,
                );

            $amount = self::cents($row['amount']);
            $source = match ($row['section']) {
                'tax' => 'taxes',
                'insurance' => 'insurance',
                default => 'expenses',
            };
            $category = $source === 'expenses' && $row['category_id'] !== null ? self::CATEGORY_EXPENSE_PREFIX.$row['category_id'] : null;
            foreach ($charges as $charge) {
                $key = $charge->format('Y-m');
                $months[$key][$source] = ($months[$key][$source] ?? 0) + $amount;
                if ($category !== null) {
                    $categories[$category] = ['name' => (string) $row['name'], 'cents' => ($categories[$category]['cents'] ?? 0) + $amount];
                }
            }
        }

        return ['months' => $months, 'categories' => $categories];
    }

    /**
     * Km percorsi per mese, sommati tra i veicoli. Per ogni veicolo l'odometro "cumulato" di un
     * mese è il massimo tra quello del mese prima e la lettura più alta del mese (rifornimenti e
     * manutenzioni); la base è il massimo tra i km iniziali e le letture precedenti alla finestra.
     * Un mese senza letture vale 0 e il primo mese con una lettura assorbe la differenza; una
     * lettura più bassa di una precedente (refuso) non produce mai valori negativi.
     *
     * @param list<Vehicle> $vehicles
     * @param list<string> $monthKeys
     * @return array<string, int> mese (Y-m) → km
     */
    private function monthlyKmDriven(array $vehicles, array $monthKeys, string $from, string $until): array
    {
        /** @var list<array{vehicle_id: string|int, ym: string, km: string|int}> $rows */
        $rows = $this->db->fetchAllAssociative(
            "SELECT vehicle_id, ym, MAX(km) AS km FROM (
                SELECT vehicle_id, km,
                       CASE WHEN refueled_at < :from THEN :before ELSE DATE_FORMAT(refueled_at, '%Y-%m') END AS ym
                FROM refuelings
                WHERE vehicle_id IN (:ids) AND refueled_at < :until
                UNION ALL
                SELECT vehicle_id, km,
                       CASE WHEN performed_at < :from THEN :before ELSE DATE_FORMAT(performed_at, '%Y-%m') END
                FROM maintenances
                WHERE vehicle_id IN (:ids) AND performed_at < :until
             ) readings
             GROUP BY vehicle_id, ym",
            ['ids' => self::ids($vehicles), 'from' => $from, 'until' => $until, 'before' => self::BEFORE_WINDOW],
            ['ids' => ArrayParameterType::INTEGER],
        );

        /** @var array<int, array<string, int>> $readings veicolo → mese (o BEFORE_WINDOW) → km massimo */
        $readings = [];
        foreach ($rows as $row) {
            $readings[(int) $row['vehicle_id']][$row['ym']] = (int) $row['km'];
        }

        $result = array_fill_keys($monthKeys, 0);
        foreach ($vehicles as $vehicle) {
            $own = $readings[(int) $vehicle->getId()] ?? [];
            $cumulative = max($vehicle->getInitialKm(), $own[self::BEFORE_WINDOW] ?? 0);
            foreach ($monthKeys as $key) {
                $next = max($cumulative, $own[$key] ?? 0);
                $result[$key] += $next - $cumulative;
                $cumulative = $next;
            }
        }

        return $result;
    }

    /**
     * Intervalli fill-to-fill chiusi nella finestra, per mese del pieno di chiusura e carburante.
     * Gli intervalli si calcolano per singolo veicolo su tutta la sua storia (un pieno precedente
     * alla finestra resta un'ancora valida), mai mescolando rifornimenti di veicoli diversi.
     *
     * @param list<Vehicle> $vehicles
     * @return array<string, array<string, list<FillInterval>>> mese (Y-m) → carburante → intervalli
     */
    private function monthlyConsumption(array $vehicles, string $from, string $until): array
    {
        /** @var list<array{vehicle_id: string|int, km: string|int, liters: string, full_tank: string|int|bool, fuel_type: string, refueled_at: string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT vehicle_id, km, liters, full_tank, fuel_type, refueled_at
             FROM refuelings
             WHERE vehicle_id IN (:ids) AND refueled_at < :until
             ORDER BY vehicle_id ASC, refueled_at ASC, km ASC, id ASC',
            ['ids' => self::ids($vehicles), 'until' => $until],
            ['ids' => ArrayParameterType::INTEGER],
        );

        /** @var array<int, list<RefuelingRow>> $byVehicle */
        $byVehicle = [];
        foreach ($rows as $row) {
            $byVehicle[(int) $row['vehicle_id']][] = $row;
        }

        $result = [];
        foreach ($vehicles as $vehicle) {
            $own = $byVehicle[(int) $vehicle->getId()] ?? [];
            foreach ($vehicle->getAllFuelTypes() as $fuel) {
                foreach ($this->consumption->intervals($own, $fuel->value) as $interval) {
                    if ($interval['closedOn'] < $from) {
                        continue; // chiuso prima della finestra: serviva solo come ancora
                    }
                    $result[substr($interval['closedOn'], 0, 7)][$fuel->value][] = $interval;
                }
            }
        }

        return $result;
    }

    // ----- helper puri -----

    /**
     * @param list<Vehicle> $vehicles
     * @return list<int>
     */
    private static function ids(array $vehicles): array
    {
        return array_map(static fn (Vehicle $v): int => (int) $v->getId(), $vehicles);
    }

    /**
     * Carburanti dei veicoli, deduplicati: per veicolo, primario prima del secondario.
     *
     * @param list<Vehicle> $vehicles
     * @return list<string>
     */
    private static function fuelTypes(array $vehicles): array
    {
        $fuels = [];
        foreach ($vehicles as $vehicle) {
            foreach ($vehicle->getAllFuelTypes() as $fuel) {
                $fuels[$fuel->value] = true;
            }
        }

        return array_keys($fuels);
    }

    /**
     * Voci a zero omesse, ordinate per importo decrescente e a parità per chiave.
     *
     * @param array<string, CategoryCents> $slices
     * @return list<CategorySlice>
     */
    private static function byCategory(array $slices): array
    {
        $slices = array_filter($slices, static fn (array $slice): bool => $slice['cents'] !== 0);
        uksort($slices, static fn (string $a, string $b): int => [$slices[$b]['cents'], $a] <=> [$slices[$a]['cents'], $b]);

        $result = [];
        foreach ($slices as $category => $slice) {
            $result[] = ['category' => $category, 'name' => $slice['name'], 'amount' => self::money($slice['cents'])];
        }

        return $result;
    }

    /**
     * @param array<string, int> $cents sezione → centesimi
     *
     * @return MonthSpending
     */
    private static function monthSpending(array $cents, int $total): array
    {
        $money = static fn (string $source): string => self::money($cents[$source] ?? 0);

        return [
            'refuelings' => $money('refuelings'),
            'scheduled' => $money('scheduled'),
            'unscheduled' => $money('unscheduled'),
            'inspection' => $money('inspection'),
            'taxes' => $money('taxes'),
            'insurance' => $money('insurance'),
            'expenses' => $money('expenses'),
            'total' => self::money($total),
        ];
    }

    private static function cents(?string $decimal): int
    {
        return (int) round((float) $decimal * 100);
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
