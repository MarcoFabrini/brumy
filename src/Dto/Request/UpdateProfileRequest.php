<?php

declare(strict_types=1);

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class UpdateProfileRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 180)]
        public string $email = '',

        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $firstName = '',

        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $lastName = '',

        #[Assert\Choice(choices: ['it', 'en'])]
        public string $locale = 'it',

        /** Obbligatoria solo se l'email cambia: un access token rubato non deve bastare per prendere l'account. */
        #[Assert\Length(max: 200)]
        public ?string $currentPassword = null,
    ) {
    }
}
