<?php

declare(strict_types=1);

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class LoginRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 180)]
        public string $email = '',

        // Stesso tetto degli altri DTO con password: oltre 4096 byte l'hasher lancia (hash) o rifiuta (verify)
        // in modo diverso per email sconosciuta e registrata. Con il tetto entrambe rispondono 422.
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public string $password = '',
    ) {
    }
}
