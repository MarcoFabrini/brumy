<?php

declare(strict_types=1);

namespace App\Service\FuelPrices;

use App\Repository\FuelStationRepository;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Scarica anagrafica e prezzi dall'open data MIMIT e li sostituisce nel database, poi aggiunge allo storico il prezzo
 * del giorno dei distributori seguiti. Se il download o il formato non vanno, non tocca niente: restano i dati
 * dell'import precedente.
 *
 * Gira dal job giornaliero (`app:fuel-prices:import`) e, se i dati sono vecchi, dalla coda (vedi {@see FuelPrices}):
 * un lock del database (`GET_LOCK`, come la registrazione del primo utente) evita due import insieme.
 */
final class FuelPriceImporter
{
    public const STATIONS_URL = 'https://www.mimit.gov.it/images/exportCSV/anagrafica_impianti_attivi.csv';
    public const PRICES_URL = 'https://www.mimit.gov.it/images/exportCSV/prezzo_alle_8.csv';
    /** Sotto questo numero di distributori il file è troncato o sbagliato (in Italia sono ~24mila). */
    public const MIN_STATIONS = 1000;
    private const LOCK = 'brumy_fuel_prices_import';
    private const TIMEOUT_SECONDS = 120;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly FuelStationRepository $stations,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{stations: int, prices: int, extractedOn: \DateTimeImmutable}|null null se un altro import è in corso
     *
     * @throws FuelPriceImportException download fallito o file non valido (i dati precedenti restano)
     */
    public function import(): ?array
    {
        if (!$this->connection->fetchOne('SELECT GET_LOCK(?, 0)', [self::LOCK])) {
            return null;
        }
        try {
            $parsed = FuelPriceCsv::parseStations($this->download(self::STATIONS_URL));
            $prices = FuelPriceCsv::parsePrices($this->download(self::PRICES_URL));
            if (count($parsed['stations']) < self::MIN_STATIONS) {
                throw new FuelPriceImportException(sprintf('Only %d fuel stations in the MIMIT file.', count($parsed['stations'])));
            }
            $counts = $this->stations->replaceAll($parsed['stations'], $prices, $parsed['extractedOn'], new \DateTimeImmutable());
            $this->stations->recordHistory($parsed['extractedOn']);
            $this->logger->info('Fuel prices imported.', $counts + ['extractedOn' => $parsed['extractedOn']->format('Y-m-d')]);

            return $counts + ['extractedOn' => $parsed['extractedOn']];
        } catch (\UnexpectedValueException $e) {
            throw new FuelPriceImportException($e->getMessage(), previous: $e);
        } finally {
            $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    private function download(string $url): string
    {
        try {
            return $this->httpClient->request('GET', $url, ['timeout' => self::TIMEOUT_SECONDS])->getContent();
        } catch (ExceptionInterface $e) {
            throw new FuelPriceImportException(sprintf('Download of %s failed: %s', $url, $e->getMessage()), previous: $e);
        }
    }
}
