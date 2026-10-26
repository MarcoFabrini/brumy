<?php

declare(strict_types=1);

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

/** Distributore ufficiale (id MIMIT) da collegare a una voce distributore; null la scollega. */
final class UserValueFuelStationRequest
{
    public function __construct(
        #[Assert\Positive]
        public ?int $fuelStationId = null,
    ) {
    }
}
