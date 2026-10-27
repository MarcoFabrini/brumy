<?php

declare(strict_types=1);

namespace App\Security;

use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * Risposta del login JSON e del codice della verifica in due passaggi (guida "Two-Factor Authentication in an API" di
 * Scheb): `{"twoFactorRequired": true}` se dopo la password serve ancora il codice, `false` quando l'utente è dentro.
 * La SPA legge l'utente da `GET /api/auth/me`.
 */
final class JsonAuthenticationSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function onAuthenticationSuccess(Request $request, TokenInterface $token): JsonResponse
    {
        return new JsonResponse(['twoFactorRequired' => $token instanceof TwoFactorTokenInterface]);
    }
}
