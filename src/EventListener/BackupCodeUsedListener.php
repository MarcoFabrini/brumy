<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\BackupCodeEvents;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorCodeEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Un codice di recupero usato al login finisce nel canale `security` (senza il codice): può voler dire telefono perso,
 * o qualcuno che ha trovato i codici.
 */
#[AsEventListener(event: BackupCodeEvents::VALID)]
final class BackupCodeUsedListener
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    public function __invoke(TwoFactorCodeEvent $event): void
    {
        $user = $event->getUser();
        $this->securityLogger->notice('Two-step verification: recovery code used to sign in', [
            'user_id' => $user instanceof User ? $user->getId() : null,
        ]);
    }
}
