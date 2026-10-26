<?php

declare(strict_types=1);

namespace App\Service\FuelPrices;

use App\Entity\Organization;
use App\Entity\User;
use App\Entity\UserValue;
use App\Enum\FuelType;
use App\Message\ImportFuelPricesMessage;
use App\Repository\FuelStationRepository;
use App\Repository\UserValueRepository;
use App\Service\AppClock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Prezzi del carburante proposti nel rifornimento: distributori vicini a una posizione, ricerca per nome o città,
 * prezzo del distributore collegato a una voce dell'elenco. I dati vengono dall'open data MIMIT
 * ({@see FuelPriceImporter}); si spegne tutto con `FUEL_PRICES_ENABLED=0` (istanze fuori dall'Italia).
 *
 * La posizione arriva solo per la ricerca e non si salva da nessuna parte.
 *
 * @phpstan-import-type StationRecord from FuelStationRepository
 * @phpstan-import-type PricePair from FuelStationRepository
 * @phpstan-import-type PriceSeries from FuelStationRepository
 *
 * @phpstan-type StationView array{id: int, label: string, brand: string, address: string, city: string, province: string, distanceKm: float|null, prices: PricePair}
 * @phpstan-type FollowedTrend array{valueId: int, name: string, fuelStationId: int, self: list<array{day: string, price: string}>, served: list<array{day: string, price: string}>}
 */
final class FuelPrices
{
    /** Oltre questa età l'import è vecchio: il job giornaliero è saltato (o l'istanza non lo ha) e se ne chiede uno in coda. */
    public const STALE_AFTER_HOURS = 26;
    public const NEARBY_RADIUS_KM = 10.0;
    public const NEARBY_LIMIT = 10;
    public const SEARCH_LIMIT = 20;
    /** Giorni di storico nei grafici dell'andamento dei distributori seguiti. */
    public const TREND_DAYS = 30;
    /** Un import in coda al massimo ogni ora, per quante richieste trovino i dati vecchi. */
    private const IMPORT_REQUEST_KEY = 'fuel_prices.import_requested';
    private const IMPORT_REQUEST_TTL = 3600;
    /** I distributori senza marchio hanno questa "bandiera": per loro conta il nome. */
    private const UNBRANDED = 'Pompe Bianche';

    public function __construct(
        private readonly FuelStationRepository $stations,
        private readonly UserValueRepository $values,
        private readonly MessageBusInterface $bus,
        private readonly CacheInterface $cache,
        private readonly AppClock $clock,
        #[Autowire(env: 'bool:default:app_fuel_prices_enabled_default:FUEL_PRICES_ENABLED')]
        private readonly bool $enabled = true,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isStale(?\DateTimeImmutable $now = null): bool
    {
        $last = $this->stations->lastImport();
        $limit = ($now ?? new \DateTimeImmutable())->modify(sprintf('-%d hours', self::STALE_AFTER_HOURS));

        return $last === null || $last['importedAt'] < $limit;
    }

    /** Dati vecchi o assenti: un import in coda (al massimo uno all'ora). I dati attuali restano finché non finisce. */
    public function requestImportIfStale(): void
    {
        if (!$this->enabled || !$this->isStale()) {
            return;
        }
        $this->cache->get(self::IMPORT_REQUEST_KEY, function (ItemInterface $item): bool {
            $item->expiresAfter(self::IMPORT_REQUEST_TTL);
            $this->bus->dispatch(new ImportFuelPricesMessage());

            return true;
        });
    }

    /** @return array{enabled: bool, extractedOn: string|null} */
    public function status(): array
    {
        $last = $this->enabled ? $this->stations->lastImport() : null;

        return ['enabled' => $this->enabled, 'extractedOn' => $last === null ? null : $last['extractedOn']->format('Y-m-d')];
    }

    /**
     * I distributori più vicini entro NEARBY_RADIUS_KM, dal più vicino.
     *
     * @return list<StationView>
     */
    public function nearby(float $latitude, float $longitude, ?FuelType $fuel): array
    {
        $found = [];
        foreach ($this->stations->findInBox($latitude, $longitude, self::NEARBY_RADIUS_KM) as $station) {
            $distance = self::distanceKm($latitude, $longitude, (float) $station['latitude'], (float) $station['longitude']);
            if ($distance <= self::NEARBY_RADIUS_KM) {
                $found[] = [$station, $distance];
            }
        }
        usort($found, static fn (array $a, array $b): int => $a[1] <=> $b[1]);

        return $this->views(array_slice($found, 0, self::NEARBY_LIMIT), $fuel);
    }

    /**
     * Distributori in cui compaiono tutte le parole cercate (nome, bandiera, indirizzo o comune).
     *
     * @return list<StationView>
     */
    public function search(string $query, ?FuelType $fuel): array
    {
        $words = array_values(array_filter(preg_split('/\s+/u', trim($query)) ?: [], static fn (string $w): bool => mb_strlen($w) >= 2));

        return $this->views(array_map(static fn (array $s): array => [$s, null], $this->stations->search($words, self::SEARCH_LIMIT)), $fuel);
    }

    /** @return StationView|null */
    public function station(int $id, ?FuelType $fuel): ?array
    {
        $record = $this->stations->findRecord($id);

        return $record === null ? null : $this->views([[$record, null]], $fuel)[0];
    }

    /**
     * Collega una voce distributore dell'elenco al distributore ufficiale (null = scollega).
     *
     * @return bool false se il distributore non esiste (la voce resta com'era)
     */
    public function link(UserValue $value, ?int $fuelStationId): bool
    {
        if ($fuelStationId !== null && !$this->stations->exists($fuelStationId)) {
            return false;
        }
        $value->linkFuelStation($fuelStationId);
        if ($value->followsPrice()) {
            $this->recordToday($value);
        }

        return true;
    }

    /**
     * Segue (o smette di seguire) il prezzo del distributore collegato alla voce. Da subito c'è il prezzo di oggi, così
     * il grafico non resta vuoto fino al prossimo import.
     *
     * @return bool false se la voce non è collegata a un distributore (resta com'era)
     */
    public function follow(UserValue $value, bool $follow): bool
    {
        if ($follow && $value->getFuelStationId() === null) {
            return false;
        }
        $value->followPrice($follow);
        if ($follow) {
            $this->recordToday($value);
        }

        return true;
    }

    /**
     * Andamento del prezzo self e servito di un carburante negli ultimi TREND_DAYS giorni, per ogni distributore seguito
     * dall'utente nell'organizzazione (anche quelli ancora senza prezzi, con le serie vuote).
     *
     * @return list<FollowedTrend>
     */
    public function followedTrend(User $user, Organization $org, FuelType $fuel): array
    {
        $followed = $this->values->findFollowedStations($user, $org);
        $priceFuel = self::priceFuel($fuel);
        $since = $this->clock->today()->modify(sprintf('-%d days', self::TREND_DAYS - 1));
        $history = $priceFuel === null ? [] : $this->stations->history(
            array_values(array_unique(array_map(static fn (UserValue $v): int => (int) $v->getFuelStationId(), $followed))),
            $priceFuel,
            $since,
        );

        return array_map(static fn (UserValue $v): array => [
            'valueId' => (int) $v->getId(),
            'name' => $v->getName(),
            'fuelStationId' => (int) $v->getFuelStationId(),
            ...($history[(int) $v->getFuelStationId()] ?? ['self' => [], 'served' => []]),
        ], $followed);
    }

    /** Prezzo dell'ultimo import nello storico per il distributore della voce (niente, prima del primo import). */
    private function recordToday(UserValue $value): void
    {
        $last = $this->stations->lastImport();
        if ($last !== null && $value->getFuelStationId() !== null) {
            $this->stations->recordHistory($last['extractedOn'], $value->getFuelStationId());
        }
    }

    /** Il carburante base di cui cercare il prezzo: un'ibrida fa benzina; elettrico, idrogeno e simili non hanno prezzo. */
    public static function priceFuel(FuelType $fuel): ?FuelType
    {
        return match ($fuel) {
            FuelType::GASOLINE, FuelType::HYBRID => FuelType::GASOLINE,
            FuelType::DIESEL => FuelType::DIESEL,
            FuelType::LPG => FuelType::LPG,
            FuelType::CNG => FuelType::CNG,
            default => null,
        };
    }

    /**
     * Nome leggibile, che diventa anche il nome della voce nell'elenco: "Q8 · Via Emilia Est 1420, Modena". Il ministero
     * scrive indirizzi e comuni in maiuscolo.
     *
     * @param StationRecord $station
     */
    public static function label(array $station): string
    {
        $brand = $station['brand'] !== '' && $station['brand'] !== self::UNBRANDED ? $station['brand'] : self::titleCase($station['name']);
        $place = implode(', ', array_filter([self::titleCase($station['address']), self::titleCase($station['city'])], static fn (string $s): bool => $s !== ''));

        return mb_substr(UserValue::normalizeName($place === '' ? $brand : "$brand · $place"), 0, 200);
    }

    /** Distanza in linea d'aria (haversine), in km. */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * @param list<array{0: StationRecord, 1: float|null}> $stations
     *
     * @return list<StationView>
     */
    private function views(array $stations, ?FuelType $fuel): array
    {
        $priceFuel = $fuel === null ? null : self::priceFuel($fuel);
        $prices = $priceFuel === null ? [] : $this->stations->pricesFor(array_map(static fn (array $s): int => $s[0]['id'], $stations), $priceFuel);

        return array_map(static fn (array $s): array => [
            'id' => $s[0]['id'],
            'label' => self::label($s[0]),
            'brand' => $s[0]['brand'],
            'address' => self::titleCase($s[0]['address']),
            'city' => self::titleCase($s[0]['city']),
            'province' => $s[0]['province'],
            'distanceKm' => $s[1] === null ? null : round($s[1], 2),
            'prices' => $prices[$s[0]['id']] ?? ['self' => null, 'served' => null],
        ], $stations);
    }

    private static function titleCase(string $value): string
    {
        return mb_convert_case(mb_strtolower($value), MB_CASE_TITLE);
    }
}
