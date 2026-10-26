<?php

declare(strict_types=1);

namespace App\Service\FuelPrices;

use App\Enum\FuelType;

/**
 * Lettura dei due CSV dell'open data MIMIT "Osservaprezzi carburanti". Logica pura (stringa in, righe pulite fuori),
 * così le regole sui dati sporchi si testano senza rete né database.
 *
 * Formato (verificato a ottobre 2026): prima riga `Estrazione del AAAA-MM-GG`, poi l'intestazione, poi righe separate
 * da `|`. Nell'anagrafica un centinaio di nomi contiene a sua volta un `|`: i primi quattro campi si leggono da sinistra,
 * gli ultimi cinque da destra, e il nome è quello che resta in mezzo.
 *
 * @phpstan-type StationRow array{id: int, operator: string, brand: string, name: string, address: string, city: string, province: string, latitude: float|null, longitude: float|null}
 * @phpstan-type PriceRow array{stationId: int, fuel: FuelType, self: bool, price: string, communicatedAt: \DateTimeImmutable}
 */
final class FuelPriceCsv
{
    public const STATIONS_HEADER = 'idImpianto|Gestore|Bandiera|Tipo Impianto|Nome Impianto|Indirizzo|Comune|Provincia|Latitudine|Longitudine';
    public const PRICES_HEADER = 'idImpianto|descCarburante|prezzo|isSelf|dtComu';

    /** Solo i carburanti base: le varianti di marca (Blue Diesel, HVO, V-Power…) non sono confrontabili. */
    private const FUELS = [
        'Benzina' => FuelType::GASOLINE,
        'Gasolio' => FuelType::DIESEL,
        'GPL' => FuelType::LPG,
        'Metano' => FuelType::CNG,
    ];

    /** Fuori da questo intervallo (€/l) il prezzo è un errore di battitura del gestore (nei dati c'è anche 0,100). */
    public const MIN_PRICE = 0.3;
    public const MAX_PRICE = 5.0;
    /** Un prezzo comunicato più di tanti giorni prima dell'estrazione non dice più niente del prezzo di oggi. */
    public const MAX_AGE_DAYS = 30;

    /** Riquadro dell'Italia: coordinate fuori (o 0,0) sono errori di inserimento, il distributore resta senza posizione. */
    private const LAT_RANGE = [35.0, 48.0];
    private const LNG_RANGE = [6.0, 19.0];

    /**
     * @return array{extractedOn: \DateTimeImmutable, stations: list<StationRow>}
     *
     * @throws \UnexpectedValueException se il file non ha la forma attesa
     */
    public static function parseStations(string $csv): array
    {
        [$extractedOn, $lines] = self::open($csv, self::STATIONS_HEADER);
        $stations = [];
        foreach ($lines as $line) {
            $fields = explode('|', $line);
            if (count($fields) < 10 || !ctype_digit($fields[0])) {
                continue;
            }
            $tail = array_slice($fields, -5);
            $stations[] = [
                'id' => (int) $fields[0],
                'operator' => self::text($fields[1], 200),
                'brand' => self::text($fields[2], 100),
                'name' => self::text(implode('|', array_slice($fields, 4, count($fields) - 9)), 200),
                'address' => self::text($tail[0], 300),
                'city' => self::text($tail[1], 100),
                'province' => self::text($tail[2], 5),
                ...self::position($tail[3], $tail[4]),
            ];
        }

        return ['extractedOn' => $extractedOn, 'stations' => $stations];
    }

    /**
     * Prezzi dei carburanti base, validi e recenti rispetto all'estrazione; per ogni distributore, carburante e
     * self/servito resta la comunicazione più recente.
     *
     * @return list<PriceRow>
     *
     * @throws \UnexpectedValueException se il file non ha la forma attesa
     */
    public static function parsePrices(string $csv): array
    {
        [$extractedOn, $lines] = self::open($csv, self::PRICES_HEADER);
        $oldest = $extractedOn->modify(sprintf('-%d days', self::MAX_AGE_DAYS));
        /** @var array<string, PriceRow> $prices */
        $prices = [];
        foreach ($lines as $line) {
            $fields = explode('|', $line);
            if (count($fields) !== 5 || !ctype_digit($fields[0])) {
                continue;
            }
            $fuel = self::FUELS[trim($fields[1])] ?? null;
            $value = trim($fields[2]);
            $communicatedAt = \DateTimeImmutable::createFromFormat('!d/m/Y H:i:s', trim($fields[4]));
            if ($fuel === null || !is_numeric($value) || $communicatedAt === false) {
                continue;
            }
            if ((float) $value < self::MIN_PRICE || (float) $value > self::MAX_PRICE || $communicatedAt < $oldest) {
                continue;
            }
            $row = [
                'stationId' => (int) $fields[0],
                'fuel' => $fuel,
                'self' => trim($fields[3]) === '1',
                'price' => number_format((float) $value, 3, '.', ''),
                'communicatedAt' => $communicatedAt,
            ];
            $key = $row['stationId'].'|'.$fuel->value.'|'.(int) $row['self'];
            if (!isset($prices[$key]) || $prices[$key]['communicatedAt'] < $communicatedAt) {
                $prices[$key] = $row;
            }
        }

        return array_values($prices);
    }

    /**
     * Data dell'estrazione e righe di dati, dopo aver controllato la prima riga e l'intestazione.
     *
     * @return array{\DateTimeImmutable, list<string>}
     */
    private static function open(string $csv, string $header): array
    {
        if (!mb_check_encoding($csv, 'UTF-8')) {
            $csv = mb_convert_encoding($csv, 'UTF-8', 'ISO-8859-1');
        }
        $lines = preg_split('/\r\n|\n|\r/', $csv) ?: [];
        $first = trim($lines[0] ?? '');
        $extractedOn = preg_match('/^Estrazione del (\d{4}-\d{2}-\d{2})$/', $first, $m)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $m[1])
            : false;
        if ($extractedOn === false || trim($lines[1] ?? '') !== $header) {
            throw new \UnexpectedValueException('Unexpected MIMIT CSV format.');
        }

        return [$extractedOn, array_values(array_filter(array_slice($lines, 2), static fn (string $l): bool => trim($l) !== ''))];
    }

    /** Spazi normalizzati e taglio alla lunghezza della colonna. */
    private static function text(string $value, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $value)), 0, $max);
    }

    /** @return array{latitude: float|null, longitude: float|null} */
    private static function position(string $lat, string $lng): array
    {
        $lat = trim($lat);
        $lng = trim($lng);
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return ['latitude' => null, 'longitude' => null];
        }
        $la = (float) $lat;
        $lo = (float) $lng;
        if ($la < self::LAT_RANGE[0] || $la > self::LAT_RANGE[1] || $lo < self::LNG_RANGE[0] || $lo > self::LNG_RANGE[1]) {
            return ['latitude' => null, 'longitude' => null];
        }

        return ['latitude' => $la, 'longitude' => $lo];
    }
}
