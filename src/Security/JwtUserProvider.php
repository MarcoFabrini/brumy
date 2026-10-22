<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Security\User\PayloadAwareUserProviderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * User provider del firewall `api`: l'identità di un access token è il claim `user_id` (immutabile), non l'email.
 *
 * Il token vale solo se, sull'utente trovato per id:
 * - il claim `username` (l'identifier Lexik) è ancora la sua email: un token emesso prima di un cambio email non
 *   autentica chi ottiene poi quell'indirizzo, né il titolare dopo il cambio;
 * - il claim `token_version` è uguale a {@see User::getTokenVersion()}: ogni revoca di sessioni la incrementa
 *   ({@see \App\Service\RefreshTokenService}) e invalida subito gli access token emessi prima.
 *
 * Un claim mancante (token emesso prima di questo controllo) o di tipo sbagliato è un rifiuto come gli altri:
 * l'eccezione diventa il 401 `http.401` di {@see \App\EventListener\JwtFailureListener} e il client ritenta col
 * refresh. Costo: la stessa query per richiesta di prima (`find` per chiave primaria invece che per email).
 */
final class JwtUserProvider implements PayloadAwareUserProviderInterface
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function loadUserByIdentifierAndPayload(string $identifier, array $payload): UserInterface
    {
        $userId = $payload['user_id'] ?? null;
        $tokenVersion = $payload['token_version'] ?? null;
        $user = is_int($userId) ? $this->users->find($userId) : null;

        if ($user === null
            || !is_int($tokenVersion)
            || $user->getUserIdentifier() !== $identifier
            || $user->getTokenVersion() !== $tokenVersion
        ) {
            // Stesso esito per ogni motivo: il client non deve capire quale controllo è fallito.
            $exception = new UserNotFoundException('JWT does not match a current user session.');
            $exception->setUserIdentifier($identifier);

            throw $exception;
        }

        return $user;
    }

    /** Il solo percorso legittimo passa dal payload: senza `user_id` e `token_version` non si autentica nessuno. */
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $exception = new UserNotFoundException('Users are loaded only from a JWT payload.');
        $exception->setUserIdentifier($identifier);

        throw $exception;
    }

    /** Firewall stateless: nessun utente in sessione da ricaricare. */
    public function refreshUser(UserInterface $user): UserInterface
    {
        throw new UnsupportedUserException('The JWT user provider serves a stateless firewall only.');
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class || is_subclass_of($class, User::class);
    }
}
