<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FuelStation;
use App\Enum\FuelType;
use App\Service\FuelPrices\FuelPriceCsv;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Distributori e prezzi dell'open data MIMIT. SQL via DBAL e non ORM: l'import scrive ~100mila righe in blocco e le
 * letture restituiscono righe piatte, senza idratare entità che nessuno modifica.
 *
 * @phpstan-import-type StationRow from FuelPriceCsv
 * @phpstan-import-type PriceRow from FuelPriceCsv
 *
 * @phpstan-type StationRecord array{id: int, brand: string, name: string, address: string, city: string, province: string, latitude: float|null, longitude: float|null}
 * @phpstan-type PricePair array{self: array{price: string, communicatedAt: string}|null, served: array{price: string, communicatedAt: string}|null}
 *
 * @extends ServiceEntityRepository<FuelStation>
 */
class FuelStationRepository extends ServiceEntityRepository
{
    /** Righe per INSERT: abbastanza per non fare 100mila query, abbastanza poche per restare sotto max_allowed_packet. */
    private const INSERT_BATCH = 500;
    private const STATION_COLUMNS = 'id, brand, name, address, city, province, latitude, longitude';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FuelStation::class);
    }

    /**
     * Sostituisce tutti i distributori e i prezzi in una transazione: chi legge vede i dati vecchi o quelli nuovi, mai
     * un misto. I prezzi di distributori assenti dall'anagrafica si scartano.
     *
     * @param list<StationRow> $stations
     * @param list<PriceRow>   $prices
     *
     * @return array{stations: int, prices: int}
     */
    public function replaceAll(array $stations, array $prices, \DateTimeImmutable $extractedOn, \DateTimeImmutable $importedAt): array
    {
        $known = array_fill_keys(array_column($stations, 'id'), true);
        $prices = array_values(array_filter($prices, static fn (array $p): bool => isset($known[$p['stationId']])));
        $connection = $this->getEntityManager()->getConnection();

        $connection->transactional(function () use ($connection, $stations, $prices, $extractedOn, $importedAt): void {
            $connection->executeStatement('DELETE FROM fuel_prices');
            $connection->executeStatement('DELETE FROM fuel_stations');
            $extracted = $extractedOn->format('Y-m-d');
            $imported = $importedAt->format('Y-m-d H:i:s');
            foreach (array_chunk($stations, self::INSERT_BATCH) as $chunk) {
                $this->insertRows($connection, 'fuel_stations', ['id', 'operator', 'brand', 'name', 'address', 'city', 'province', 'latitude', 'longitude', 'extracted_on', 'imported_at'], array_map(
                    static fn (array $s): array => [$s['id'], $s['operator'], $s['brand'], $s['name'], $s['address'], $s['city'], $s['province'], $s['latitude'], $s['longitude'], $extracted, $imported],
                    $chunk,
                ));
            }
            foreach (array_chunk($prices, self::INSERT_BATCH) as $chunk) {
                $this->insertRows($connection, 'fuel_prices', ['station_id', 'fuel', 'is_self', 'price', 'communicated_at'], array_map(
                    static fn (array $p): array => [$p['stationId'], $p['fuel']->value, (int) $p['self'], $p['price'], $p['communicatedAt']->format('Y-m-d H:i:s')],
                    $chunk,
                ));
            }
        });

        return ['stations' => count($stations), 'prices' => count($prices)];
    }

    /** @return array{extractedOn: \DateTimeImmutable, importedAt: \DateTimeImmutable}|null null se non c'è ancora nessun import */
    public function lastImport(): ?array
    {
        /** @var array{extracted_on: string|null, imported_at: string|null}|false $row */
        $row = $this->getEntityManager()->getConnection()
            ->fetchAssociative('SELECT MAX(extracted_on) AS extracted_on, MAX(imported_at) AS imported_at FROM fuel_stations');
        if ($row === false || $row['extracted_on'] === null || $row['imported_at'] === null) {
            return null;
        }

        return ['extractedOn' => new \DateTimeImmutable($row['extracted_on']), 'importedAt' => new \DateTimeImmutable($row['imported_at'])];
    }

    /**
     * Distributori con posizione dentro il riquadro attorno al punto: il filtro grossolano che usa l'indice, la distanza
     * vera la calcola il chiamante.
     *
     * @return list<StationRecord>
     */
    public function findInBox(float $latitude, float $longitude, float $radiusKm): array
    {
        $dLat = $radiusKm / 111.0;
        $dLng = $radiusKm / (111.32 * max(0.01, cos(deg2rad($latitude))));

        return $this->records(
            'SELECT '.self::STATION_COLUMNS.' FROM fuel_stations WHERE latitude BETWEEN ? AND ? AND longitude BETWEEN ? AND ?',
            [$latitude - $dLat, $latitude + $dLat, $longitude - $dLng, $longitude + $dLng],
        );
    }

    /**
     * Distributori in cui ogni parola compare nel nome, nella bandiera, nell'indirizzo o nel comune.
     *
     * @param list<string> $words
     *
     * @return list<StationRecord>
     */
    public function search(array $words, int $limit): array
    {
        if ($words === []) {
            return [];
        }
        $where = [];
        $params = [];
        foreach ($words as $word) {
            $where[] = '(name LIKE ? OR brand LIKE ? OR address LIKE ? OR city LIKE ?)';
            $like = '%'.addcslashes($word, '%_\\').'%';
            array_push($params, $like, $like, $like, $like);
        }

        return $this->records(
            'SELECT '.self::STATION_COLUMNS.' FROM fuel_stations WHERE '.implode(' AND ', $where).' ORDER BY city, brand, address, id LIMIT '.max(1, $limit),
            $params,
        );
    }

    /** @return StationRecord|null */
    public function findRecord(int $id): ?array
    {
        return $this->records('SELECT '.self::STATION_COLUMNS.' FROM fuel_stations WHERE id = ?', [$id])[0] ?? null;
    }

    public function exists(int $id): bool
    {
        return $this->getEntityManager()->getConnection()->fetchOne('SELECT 1 FROM fuel_stations WHERE id = ?', [$id]) !== false;
    }

    /**
     * Prezzo self e servito di un carburante per ogni distributore richiesto (solo quelli che ne hanno almeno uno).
     *
     * @param list<int> $stationIds
     *
     * @return array<int, PricePair>
     */
    public function pricesFor(array $stationIds, FuelType $fuel): array
    {
        if ($stationIds === []) {
            return [];
        }
        /** @var list<array{station_id: int|string, is_self: int|string, price: string, communicated_at: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT station_id, is_self, price, communicated_at FROM fuel_prices WHERE fuel = ? AND station_id IN (?)',
            [$fuel->value, $stationIds],
            [1 => ArrayParameterType::INTEGER],
        );
        $pairs = [];
        foreach ($rows as $row) {
            $id = (int) $row['station_id'];
            $pairs[$id] ??= ['self' => null, 'served' => null];
            $pairs[$id][(int) $row['is_self'] === 1 ? 'self' : 'served'] = [
                'price' => $row['price'],
                'communicatedAt' => substr($row['communicated_at'], 0, 10),
            ];
        }

        return $pairs;
    }

    /**
     * Copia nello storico, come prezzo di $day, gli ultimi prezzi dei distributori seguiti (da una voce non eliminata),
     * o del solo $stationId se dato. Rifatto lo stesso giorno, aggiorna le righe invece di duplicarle.
     *
     * @return int righe scritte (MariaDB conta 2 per una riga aggiornata)
     */
    public function recordHistory(\DateTimeImmutable $day, ?int $stationId = null): int
    {
        $stations = $stationId === null
            ? 'SELECT fuel_station_id FROM user_values WHERE follows_price = 1 AND deleted_at IS NULL AND fuel_station_id IS NOT NULL'
            : '?';
        $params = $stationId === null ? [$day->format('Y-m-d')] : [$day->format('Y-m-d'), $stationId];

        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO fuel_price_history (station_id, fuel, is_self, day, price)
             SELECT station_id, fuel, is_self, ?, price FROM fuel_prices WHERE station_id IN ('.$stations.')
             ON DUPLICATE KEY UPDATE price = VALUES(price)',
            $params,
        );
    }

    /**
     * Prezzo self di un carburante, giorno per giorno da $since, per ogni distributore richiesto (solo quelli che ne
     * hanno almeno uno).
     *
     * @param list<int> $stationIds
     *
     * @return array<int, list<array{day: string, price: string}>>
     */
    public function history(array $stationIds, FuelType $fuel, \DateTimeImmutable $since): array
    {
        if ($stationIds === []) {
            return [];
        }
        /** @var list<array{station_id: int|string, day: string, price: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT station_id, day, price FROM fuel_price_history WHERE fuel = ? AND is_self = 1 AND day >= ? AND station_id IN (?) ORDER BY day',
            [$fuel->value, $since->format('Y-m-d'), $stationIds],
            [2 => ArrayParameterType::INTEGER],
        );
        $history = [];
        foreach ($rows as $row) {
            $history[(int) $row['station_id']][] = ['day' => $row['day'], 'price' => $row['price']];
        }

        return $history;
    }

    /**
     * @param list<string>                      $columns
     * @param list<list<int|float|string|null>> $rows
     */
    private function insertRows(Connection $connection, string $table, array $columns, array $rows): void
    {
        $placeholders = '('.implode(', ', array_fill(0, count($columns), '?')).')';
        $connection->executeStatement(
            sprintf('INSERT INTO %s (%s) VALUES %s', $table, implode(', ', $columns), implode(', ', array_fill(0, count($rows), $placeholders))),
            array_merge(...$rows),
        );
    }

    /**
     * @param list<int|float|string> $params
     *
     * @return list<StationRecord>
     */
    private function records(string $sql, array $params): array
    {
        /** @var list<array{id: int|string, brand: string, name: string, address: string, city: string, province: string, latitude: float|string|null, longitude: float|string|null}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'brand' => $r['brand'],
            'name' => $r['name'],
            'address' => $r['address'],
            'city' => $r['city'],
            'province' => $r['province'],
            'latitude' => $r['latitude'] === null ? null : (float) $r['latitude'],
            'longitude' => $r['longitude'] === null ? null : (float) $r['longitude'],
        ], $rows);
    }
}
