<?php

declare(strict_types=1);

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class ResetPasswordRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 128)]
        public string $token = '',

        #[Assert\NotBlank]
        #[Assert\Length(min: 8, max: 200)]
        public string $password = '',
    ) {
    }
}
