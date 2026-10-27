<?php

declare(strict_types=1);

namespace App\Service\TwoFactor;

use App\Entity\User;
use App\Security\BackupCode;
use App\Security\TwoFactorAttempts;
use App\Service\SecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Psr\Log\LoggerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Backup\BackupCodeManagerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Gestione della verifica in due passaggi dal profilo: configurazione (segreto + QR), attivazione con password e codice,
 * disattivazione e nuovi codici di recupero con password e codice (dell'app o di recupero, che si consuma), reset da
 * comando. I codici si verificano con i servizi di Scheb, gli stati sono invarianti dell'entity.
 *
 * Password o codice sbagliati consumano un tentativo dello stesso limite del login ({@see TwoFactorAttempts}): con i
 * tentativi esauriti si risponde 429 anche con credenziali giuste. Attivazione, disattivazione, nuovi codici e reset
 * finiscono nel canale `security` (solo `user_id`, mai segreti o codici).
 *
 * @phpstan-type TotpSetup array{secret: string, otpauthUri: string, qrCode: string}
 */
final class TwoFactorSetup
{
    public function __construct(
        private readonly TotpAuthenticatorInterface $totp,
        #[Autowire(service: 'scheb_two_factor.backup_code_manager')]
        private readonly BackupCodeManagerInterface $backupCodes,
        private readonly SecretCipher $cipher,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TwoFactorAttempts $attempts,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    /**
     * Segreto nuovo in attesa di conferma (sostituisce una configurazione precedente non confermata), con il QR da
     * inquadrare (SVG come data URI) e il link `otpauth://` per l'inserimento manuale o l'apertura dell'app.
     *
     * @return TotpSetup
     */
    public function begin(User $user): array
    {
        if ($user->isTotpAuthenticationEnabled()) {
            throw new TwoFactorSetupException(TwoFactorSetupError::AlreadyEnabled);
        }

        $secret = $this->totp->generateSecret();
        $user->beginTotpSetup($this->cipher->encrypt($secret), $secret);
        $this->em->flush();

        $uri = $this->totp->getQRContent($user);

        return [
            'secret' => $secret,
            'otpauthUri' => $uri,
            'qrCode' => (new SvgWriter())->write(new QrCode($uri))->getDataUri(),
        ];
    }

    /**
     * Conferma la configurazione con la password attuale e un codice dell'app: da qui il login chiede il codice.
     *
     * @return list<string> i codici di recupero in chiaro, mostrati solo ora
     */
    public function enable(User $user, string $password, string $code): array
    {
        if ($user->isTotpAuthenticationEnabled()) {
            throw new TwoFactorSetupException(TwoFactorSetupError::AlreadyEnabled);
        }
        if (!$user->hasPendingTotpSetup()) {
            throw new TwoFactorSetupException(TwoFactorSetupError::SetupRequired);
        }
        $this->guard($user, $password, fn (): bool => $this->totp->checkCode($user, $code));

        $codes = $this->newBackupCodes();
        $user->enableTotp(new \DateTimeImmutable(), array_map(BackupCode::hash(...), $codes));
        $this->em->flush();
        $this->securityLogger->notice('Two-step verification enabled', ['user_id' => $user->getId()]);

        return $codes;
    }

    /** Disattiva con password e codice (dell'app o di recupero): segreto, attivazione e codici azzerati. */
    public function disable(User $user, string $password, string $code): void
    {
        $this->guardEnabled($user, $password, $code);

        $user->disableTwoFactor();
        $this->em->flush();
        $this->securityLogger->notice('Two-step verification disabled', ['user_id' => $user->getId()]);
    }

    /**
     * Nuovi codici di recupero con password e codice: i precedenti smettono di valere.
     *
     * @return list<string>
     */
    public function regenerateBackupCodes(User $user, string $password, string $code): array
    {
        $this->guardEnabled($user, $password, $code);

        $codes = $this->newBackupCodes();
        $user->replaceBackupCodes(array_map(BackupCode::hash(...), $codes));
        $this->em->flush();
        $this->securityLogger->notice('Two-step verification: recovery codes regenerated', ['user_id' => $user->getId()]);

        return $codes;
    }

    /** Reset dell'amministratore dell'istanza (`app:user:2fa-reset`): l'utente ha perso telefono e codici. */
    public function reset(User $user): void
    {
        $user->disableTwoFactor();
        $this->em->flush();
        $this->securityLogger->warning('Two-step verification reset by the instance administrator', ['user_id' => $user->getId()]);
    }

    private function guardEnabled(User $user, string $password, string $code): void
    {
        if (!$user->isTotpAuthenticationEnabled()) {
            throw new TwoFactorSetupException(TwoFactorSetupError::NotEnabled);
        }
        $this->guard($user, $password, function () use ($user, $code): bool {
            if ($this->totp->checkCode($user, $code)) {
                return true;
            }
            if (!$this->backupCodes->isBackupCode($user, $code)) {
                return false;
            }
            $this->backupCodes->invalidateBackupCode($user, $code);

            return true;
        });
    }

    /** @param \Closure(): bool $checkCode */
    private function guard(User $user, string $password, \Closure $checkCode): void
    {
        if ($this->attempts->isBlocked($user)) {
            throw new TwoFactorSetupException(TwoFactorSetupError::TooManyAttempts);
        }
        if (!$this->passwordHasher->isPasswordValid($user, $password)) {
            $this->attempts->fail($user);
            throw new TwoFactorSetupException(TwoFactorSetupError::InvalidPassword);
        }
        if (!$checkCode()) {
            $this->attempts->fail($user);
            throw new TwoFactorSetupException(TwoFactorSetupError::InvalidCode);
        }
        $this->attempts->reset($user);
    }

    /** @return list<string> */
    private function newBackupCodes(): array
    {
        $codes = [];
        while (\count($codes) < BackupCode::COUNT) {
            $codes[BackupCode::hash($code = BackupCode::generate())] = $code;
        }

        return array_values($codes);
    }
}
