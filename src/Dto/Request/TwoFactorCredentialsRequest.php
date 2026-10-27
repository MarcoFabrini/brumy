<?php

declare(strict_types=1);

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Attivazione, disattivazione e nuovi codici di recupero della verifica in due passaggi: password attuale e un codice
 * (dell'app di autenticazione; per disattivare e rigenerare vale anche un codice di recupero).
 */
final class TwoFactorCredentialsRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public string $currentPassword = '',

        #[Assert\NotBlank]
        #[Assert\Length(max: 32)]
        public string $code = '',
    ) {
    }
}
