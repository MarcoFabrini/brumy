<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Entity\UserValue;
use Symfony\Component\Validator\Constraints as Assert;

/** Nuova voce dell'elenco; un distributore può nascere già collegato a quello ufficiale (id MIMIT). */
final class UserValueCreateRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: [UserValue::class, 'normalizeName'])]
        #[Assert\Length(max: 200, normalizer: [UserValue::class, 'normalizeName'])]
        public string $name = '',
        #[Assert\Positive]
        public ?int $fuelStationId = null,
    ) {
    }
}
