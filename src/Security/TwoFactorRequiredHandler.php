<?php

declare(strict_types=1);

namespace App\Security;

use App\Controller\Api\ProblemDetailsResponseTrait;
use Scheb\TwoFactorBundle\Security\Http\Authentication\AuthenticationRequiredHandlerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Password giusta ma codice della verifica in due passaggi non ancora dato: ogni rotta protetta risponde 401
 * `auth.two_factor_required` invece del redirect al form di Scheb, così la SPA mostra il campo del codice.
 */
final class TwoFactorRequiredHandler implements AuthenticationRequiredHandlerInterface
{
    use ProblemDetailsResponseTrait;

    public function onAuthenticationRequired(Request $request, TokenInterface $token): Response
    {
        return $this->problem('auth.two_factor_required', 401);
    }
}
