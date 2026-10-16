<?php

declare(strict_types=1);

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class RefreshRequest
{
    public function __construct(
        /** Opzionale: presente solo per i client mobile che inviano il refresh nel body */
        #[Assert\Length(max: 128)]
        public ?string $refreshToken = null,
    ) {
    }
}
