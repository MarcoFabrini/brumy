<?php

declare(strict_types=1);

namespace App\Service\FuelPrices;

use App\Repository\FuelStationRepository;
use Doctrine\DBAL\Exception as DBALException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Prezzi del giorno stesso per pochi distributori alla volta, dall'interfaccia del sito Osservaprezzi del MIMIT: il
 * file giornaliero ({@see FuelPriceImporter}) ha solo i prezzi del giorno prima. Non è open data con licenza e può
 * cambiare senza avviso: ogni errore lascia i prezzi che ci sono e finisce solo nel log. Si spegne da solo con
 * `FUEL_PRICES_LIVE_ENABLED=0` (o con FUEL_PRICES_ENABLED=0).
 *
 * @phpstan-import-type PriceRow from FuelPriceCsv
 */
final class LiveFuelPrices
{
    public const URL = 'https://carburanti.mise.gov.it/ospzApi/registry/servicearea/%d';
    /** Un distributore si richiede al ministero al massimo ogni tanti secondi, per quanti utenti lo guardino. */
    public const MIN_INTERVAL_SECONDS = 900;
    /** Le richieste partono insieme: in tutto non si aspetta più di così, poi restano i prezzi del file. */
    public const TIMEOUT_SECONDS = 5.0;
    /**
     * Richieste contemporanee al ministero: quante ne chiede al massimo /live (FuelPrices::SEARCH_LIMIT), così una
     * chiamata dal rifornimento aspetta un giro solo; il job dei seguiti ne fa più giri.
     */
    private const CONCURRENCY = FuelPrices::SEARCH_LIMIT;
    private const CACHE_PREFIX = 'fuel_prices.live.';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly FuelStationRepository $stations,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'bool:default:app_fuel_prices_enabled_default:FUEL_PRICES_ENABLED')]
        private readonly bool $pricesEnabled = true,
        #[Autowire(env: 'bool:default:app_fuel_prices_live_enabled_default:FUEL_PRICES_LIVE_ENABLED')]
        private readonly bool $liveEnabled = true,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->pricesEnabled && $this->liveEnabled;
    }

    /**
     * Chiede al ministero i prezzi dei distributori non aggiornati negli ultimi MIN_INTERVAL_SECONDS e salva quelli più
     * recenti di ciò che c'è.
     *
     * @param list<int> $stationIds
     *
     * @return int distributori chiesti al ministero
     */
    public function refresh(array $stationIds): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }
        $due = [];
        foreach ($this->stations->existingIds(array_values(array_unique($stationIds))) as $id) {
            $item = $this->cache->getItem(self::CACHE_PREFIX.$id);
            if (!$item->isHit()) {
                // Segnato prima di chiedere: anche un errore aspetta il prossimo giro, senza martellare il ministero
                $this->cache->saveDeferred($item->set(true)->expiresAfter(self::MIN_INTERVAL_SECONDS));
                $due[] = $id;
            }
        }
        $this->cache->commit();
        foreach (array_chunk($due, self::CONCURRENCY) as $chunk) {
            $this->fetchAndSave($chunk);
        }

        return count($due);
    }

    /**
     * I prezzi di un distributore nella risposta del ministero, con le regole del file: solo i carburanti base, prezzi
     * plausibili e non più vecchi di FuelPriceCsv::MAX_AGE_DAYS, l'ultima comunicazione per carburante e self/servito.
     * L'ora arriva in UTC e si salva in ora italiana, come `dtComu` del file.
     *
     * @return list<PriceRow>
     *
     * @throws \UnexpectedValueException la risposta non ha la forma attesa (l'interfaccia è cambiata)
     */
    public static function parse(mixed $data, int $stationId, ?\DateTimeImmutable $now = null): array
    {
        $timezone = new \DateTimeZone(FuelPrices::TIMEZONE);
        $oldest = ($now ?? new \DateTimeImmutable())->modify(sprintf('-%d days', FuelPriceCsv::MAX_AGE_DAYS));
        if (!is_array($data) || ($data['id'] ?? null) !== $stationId || !is_array($data['fuels'] ?? null)) {
            throw new \UnexpectedValueException('Unexpected Osservaprezzi response.');
        }
        /** @var array<string, PriceRow> $prices */
        $prices = [];
        foreach ($data['fuels'] as $fuel) {
            if (!is_array($fuel) || !is_string($fuel['name'] ?? null) || !is_numeric($fuel['price'] ?? null)
                || !is_bool($fuel['isSelf'] ?? null) || !is_string($fuel['insertDate'] ?? null)) {
                continue;
            }
            $type = FuelPriceCsv::fuel($fuel['name']);
            $at = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', preg_replace('/\.\d+/', '', $fuel['insertDate']) ?? '');
            if ($type === null || $at === false || $at < $oldest || !FuelPriceCsv::isPlausible((float) $fuel['price'])) {
                continue;
            }
            $row = [
                'stationId' => $stationId,
                'fuel' => $type,
                'self' => $fuel['isSelf'],
                'price' => number_format((float) $fuel['price'], 3, '.', ''),
                // Ora locale senza fuso, come le altre righe della tabella
                'communicatedAt' => new \DateTimeImmutable($at->setTimezone($timezone)->format('Y-m-d H:i:s')),
            ];
            $key = $type->value.'|'.(int) $fuel['isSelf'];
            if (!isset($prices[$key]) || $prices[$key]['communicatedAt'] < $row['communicatedAt']) {
                $prices[$key] = $row;
            }
        }

        return array_values($prices);
    }

    /** @param list<int> $ids */
    private function fetchAndSave(array $ids): void
    {
        /** @var array<int, ResponseInterface> $responses */
        $responses = [];
        foreach ($ids as $id) {
            $responses[$id] = $this->httpClient->request('GET', sprintf(self::URL, $id), [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
            ]);
        }
        $prices = [];
        foreach ($responses as $id => $response) {
            try {
                array_push($prices, ...self::parse($response->toArray(), $id));
            } catch (ExceptionInterface|\UnexpectedValueException $e) {
                $this->logger->warning('Live fuel prices unavailable.', ['station' => $id, 'error' => $e->getMessage()]);
            }
        }
        try {
            $this->stations->upsertNewerPrices($prices);
        } catch (DBALException $e) {
            // Un distributore sparito con l'import del mattino nel frattempo: restano i prezzi che ci sono
            $this->logger->warning('Live fuel prices not saved.', ['error' => $e->getMessage()]);
        }
    }
}
