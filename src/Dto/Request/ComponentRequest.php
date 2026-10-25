<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Entity\UserValue;
use App\Validator\DecimalString;
use Symfony\Component\Validator\Constraints as Assert;

final class ComponentRequest
{
    public function __construct(
        #[Assert\Positive]
        public int $vehicleId = 0,

        /** Vuoto anche se di soli spazi Unicode. */
        #[Assert\NotBlank(message: 'component.name.required', normalizer: [UserValue::class, 'normalizeName'])]
        #[Assert\Length(max: 200)]
        public string $name = '',

        /** Modello o codice del pezzo. */
        #[Assert\Length(max: 200)]
        public ?string $code = null,

        #[Assert\Length(max: 200)]
        public ?string $serialNumber = null,

        /** Nome del negozio: si cerca nella lista negozi del proprietario del veicolo o diventa una voce nuova. */
        #[Assert\Length(max: 200)]
        public ?string $shop = null,

        #[Assert\Date]
        public ?string $purchasedAt = null,

        #[DecimalString(maxDecimals: 2, maxIntegerDigits: 8, allowZero: true, message: 'validation.amount_format')]
        public ?string $price = null,

        #[Assert\Length(max: 5000)]
        public ?string $notes = null,
    ) {
    }
}
