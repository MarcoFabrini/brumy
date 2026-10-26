<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Import dei prezzi del carburante in coda (transport "async"): lo chiede {@see \App\Service\FuelPrices\FuelPrices}
 * quando trova dati più vecchi di un giorno, così l'istanza si aggiorna anche senza il job giornaliero.
 */
final readonly class ImportFuelPricesMessage
{
}
