<?php

declare(strict_types=1);

namespace App\Service\TwoFactor;

/** Perché un'operazione sulla verifica in due passaggi è stata rifiutata (il controller ne fa la risposta). */
enum TwoFactorSetupError
{
    case AlreadyEnabled;
    case NotEnabled;
    case SetupRequired;
    case InvalidPassword;
    case InvalidCode;
    case TooManyAttempts;
}
