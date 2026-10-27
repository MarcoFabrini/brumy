<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\Exception\CookieTheftException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Due protezioni sui login falliti del firewall `api` che Symfony 8.1 non ha:
 *
 * - **tempi uguali per un'email sconosciuta**: con un'email registrata Symfony verifica la password (un hash Argon2id),
 *   con una sconosciuta risponde subito, e il tempo di risposta rivelerebbe quali email hanno un account. Qui si
 *   calcola un hash dello stesso costo. L'input è costante: la password inviata può superare il limite dell'hasher
 *   (4096 byte) e farlo lanciare, mentre con un'email registrata la verifica la rifiuta senza errori;
 * - **riuso di un cookie remember-me** (`CookieTheftException`: un valore già ruotato ripresentato fuori dalla finestra
 *   di 60 s per le corse tra schede): un warning sul canale `security`, senza email né token nel contesto.
 */
final class LoginFailureListener
{
    private const DUMMY_PASSWORD = 'brumy-unknown-user-timing';

    public function __construct(
        private readonly PasswordHasherFactoryInterface $hasherFactory,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    #[AsEventListener(event: LoginFailureEvent::class, dispatcher: 'security.event_dispatcher.api')]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $exception = $event->getException();
        if ($exception instanceof CookieTheftException) {
            $this->securityLogger->warning('Remember-me token reuse detected');

            return;
        }

        if ($exception instanceof UserNotFoundException || $exception->getPrevious() instanceof UserNotFoundException) {
            $this->hasherFactory->getPasswordHasher(User::class)->hash(self::DUMMY_PASSWORD);
        }
    }
}
