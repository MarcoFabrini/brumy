<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Tentativi sbagliati sui codici della verifica in due passaggi, per utente (limiter `two_factor`, 5 ogni 15 minuti):
 * contano solo i fallimenti, un successo azzera. Lo usano il login ({@see \App\EventListener\TwoFactorRateLimitListener})
 * e la gestione dal profilo ({@see \App\Service\TwoFactor\TwoFactorSetup}), così il limite è uno solo.
 */
final class TwoFactorAttempts
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $twoFactorLimiter,
    ) {
    }

    /** True se l'utente ha esaurito i tentativi, senza consumarne uno. */
    public function isBlocked(User $user): bool
    {
        return $this->limiter($user)->consume(0)->getRemainingTokens() <= 0;
    }

    public function fail(User $user): void
    {
        $this->limiter($user)->consume();
    }

    public function reset(User $user): void
    {
        $this->limiter($user)->reset();
    }

    private function limiter(User $user): LimiterInterface
    {
        return $this->twoFactorLimiter->create('user_'.$user->getId());
    }
}
