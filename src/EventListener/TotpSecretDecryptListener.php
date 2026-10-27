<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\SecretCipher;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Decifra il segreto TOTP quando l'utente viene caricato e lo passa all'entity, che non conosce la cifratura (Scheb non
 * ne offre una: il segreto è salvato con {@see SecretCipher}).
 *
 * Se non si decifra (APP_SECRET cambiato) la verifica resta richiesta (fail closed): l'entity riceve un segreto
 * casuale usa-e-getta, quindi nessun codice dell'app vale e non c'è nessun 500. Si entra con un codice di recupero o
 * dopo `app:user:2fa-reset`; un `error` sul canale `security` lo segnala all'amministratore.
 */
#[AsEntityListener(event: Events::postLoad, method: 'postLoad', entity: User::class)]
final class TotpSecretDecryptListener
{
    public function __construct(
        private readonly SecretCipher $cipher,
        private readonly TotpAuthenticatorInterface $totp,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    public function postLoad(User $user): void
    {
        $encrypted = $user->getEncryptedTotpSecret();
        if ($encrypted === null) {
            return;
        }

        try {
            $user->useTotpSecret($this->cipher->decrypt($encrypted));
        } catch (\RuntimeException) {
            $this->securityLogger->error('TOTP secret cannot be decrypted (APP_SECRET changed?): two-step verification stays required', [
                'user_id' => $user->getId(),
            ]);
            $user->useTotpSecret($this->totp->generateSecret());
        }
    }
}
