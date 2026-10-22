<?php

declare(strict_types=1);

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload facoltativo del completamento: data e km EFFETTIVI del lavoro. Campi omessi (o body assente)
 * → oggi e, per un promemoria ricorrente a km, l'odometro attuale del veicolo ({@see \App\Service\ReminderCompletion}).
 * Che la data non sia futura lo controlla il controller: dipende dal "oggi" dell'istanza.
 */
final class CompleteReminderRequest
{
    public function __construct(
        #[Assert\Date]
        // Date ISO: il confronto tra stringhe è quello cronologico.
        #[Assert\GreaterThanOrEqual('1970-01-01')]
        public ?string $completedOn = null,

        #[Assert\Range(min: 0, max: 9_999_999, notInRangeMessage: 'common.km_too_large')]
        public ?int $completedKm = null,
    ) {
    }
}
