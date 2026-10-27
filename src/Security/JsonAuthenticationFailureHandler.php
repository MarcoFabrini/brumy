<?php

declare(strict_types=1);

namespace App\Security;

use App\Controller\Api\ProblemDetailsResponseTrait;
use Scheb\TwoFactorBundle\Security\Authentication\Exception\InvalidTwoFactorCodeException;
use Scheb\TwoFactorBundle\Security\Authentication\Exception\ReusedTwoFactorCodeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Errori del login JSON e del codice della verifica in due passaggi in formato Problem Details. Email sconosciuta e
 * password sbagliata danno la stessa risposta (Symfony le riduce entrambe a credenziali non valide); un codice TOTP o
 * di recupero sbagliato, o già usato, ha la propria chiave; il blocco per troppi tentativi (login_throttling o
 * {@see \App\EventListener\TwoFactorRateLimitListener}) è un 429.
 */
final class JsonAuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    use ProblemDetailsResponseTrait;

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): JsonResponse
    {
        return match (true) {
            $exception instanceof TooManyLoginAttemptsAuthenticationException => $this->problem('auth.too_many_attempts', 429),
            $exception instanceof InvalidTwoFactorCodeException,
            $exception instanceof ReusedTwoFactorCodeException => $this->problem('auth.invalid_two_factor_code', 401),
            default => $this->problem('auth.invalid_credentials', 401),
        };
    }
}
