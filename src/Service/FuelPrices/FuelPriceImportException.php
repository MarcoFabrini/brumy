<?php

declare(strict_types=1);

namespace App\Service\FuelPrices;

/** Import dei prezzi non riuscito (rete, file troncato o formato cambiato): i dati precedenti restano. */
final class FuelPriceImportException extends \RuntimeException
{
}
