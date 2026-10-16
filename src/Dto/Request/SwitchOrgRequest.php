<?php

declare(strict_types=1);

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class SwitchOrgRequest
{
    public function __construct(
        #[Assert\Positive]
        public int $organizationId = 0,

        /** Solo client mobile (il web usa il cookie): serve a ricordare l'org attiva al prossimo refresh. */
        #[Assert\Length(max: 128)]
        public ?string $refreshToken = null,
    ) {
    }
}
