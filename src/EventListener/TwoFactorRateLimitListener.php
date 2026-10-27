<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Security\TwoFactorAttempts;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;

/**
 * Limite ai codici sbagliati al login con verifica in due passaggi (pagina "Brute force protection" di Scheb): prima del
 * controllo un utente bloccato riceve 429 anche con il codice giusto (l'eccezione arriva al failure handler del
 * firewall), ogni codice sbagliato consuma un tentativo, l'accesso completato li azzera.
 */
final class TwoFactorRateLimitListener
{
    public function __construct(
        private readonly TwoFactorAttempts $attempts,
    ) {
    }

    #[AsEventListener(event: TwoFactorAuthenticationEvents::ATTEMPT)]
    public function onAttempt(TwoFactorAuthenticationEvent $event): void
    {
        $user = $event->getToken()->getUser();
        if ($user instanceof User && $this->attempts->isBlocked($user)) {
            throw new TooManyLoginAttemptsAuthenticationException();
        }
    }

    #[AsEventListener(event: TwoFactorAuthenticationEvents::FAILURE)]
    public function onFailure(TwoFactorAuthenticationEvent $event): void
    {
        $user = $event->getToken()->getUser();
        if ($user instanceof User) {
            $this->attempts->fail($user);
        }
    }

    #[AsEventListener(event: TwoFactorAuthenticationEvents::COMPLETE)]
    public function onComplete(TwoFactorAuthenticationEvent $event): void
    {
        $user = $event->getToken()->getUser();
        if ($user instanceof User) {
            $this->attempts->reset($user);
        }
    }
}
