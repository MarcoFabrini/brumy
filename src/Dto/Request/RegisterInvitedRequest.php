<?php

declare(strict_types=1);

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Registrazione contestuale all'accettazione di un invito: l'email NON è nel
 * payload (viene dall'invito, già verificata dal possesso del link).
 */
final class RegisterInvitedRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 128)]
        public string $token = '',

        #[Assert\NotBlank]
        #[Assert\Length(min: 8, max: 200)]
        public string $password = '',

        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $firstName = '',

        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $lastName = '',

        #[Assert\Choice(choices: ['it', 'en'])]
        public string $locale = 'it',
    ) {
    }
}
