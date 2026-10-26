<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ImportFuelPricesMessage;
use App\Service\FuelPrices\FuelPriceImporter;
use App\Service\FuelPrices\FuelPrices;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Import in coda. Idempotente: se nel frattempo il job giornaliero ha già aggiornato (o l'istanza ha spento i prezzi)
 * non fa niente; un import già in corso lo salta (lock dell'importer). Un fallimento (rete) torna alla retry strategy.
 */
#[AsMessageHandler]
final class ImportFuelPricesHandler
{
    public function __construct(
        private readonly FuelPrices $fuelPrices,
        private readonly FuelPriceImporter $importer,
    ) {
    }

    public function __invoke(ImportFuelPricesMessage $message): void
    {
        if ($this->fuelPrices->isEnabled() && $this->fuelPrices->isStale()) {
            $this->importer->import();
        }
    }
}
