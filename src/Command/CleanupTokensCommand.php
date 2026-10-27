<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\EmailVerificationTokenRepository;
use App\Repository\OrganizationInvitationRepository;
use App\Repository\PasswordResetTokenRepository;
use App\Security\RememberMeTokens;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pulizia periodica, pianificata in `compose.yml` (job Ofelia `tokens-cleanup`, la domenica alle 04:00):
 * - serie remember-me non usate da più di 7 giorni (la durata del cookie): Symfony non le cancella da sé;
 * - token di reset password e verifica email e inviti scaduti da più di N giorni: altrimenti le tabelle crescono
 *   all'infinito e le email degli invitati restano salvate senza motivo.
 *
 * L'alias `app:refresh-tokens:cleanup` tiene vivi i `compose.yml` scaricati prima delle sessioni.
 */
#[AsCommand(name: 'app:tokens:cleanup', description: 'Delete unused remember-me series, expired reset/verification tokens and invitations', aliases: ['app:refresh-tokens:cleanup'])]
final class CleanupTokensCommand extends Command
{
    /** Uguale a `remember_me.lifetime` (security.yaml): una serie più vecchia non autentica comunque più. */
    private const REMEMBER_ME_LIFETIME_DAYS = 7;

    public function __construct(
        private readonly RememberMeTokens $rememberMeTokens,
        private readonly PasswordResetTokenRepository $resetTokens,
        private readonly EmailVerificationTokenRepository $verificationTokens,
        private readonly OrganizationInvitationRepository $invitations,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Delete reset/verification tokens and invitations expired more than N days ago', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = (int) $input->getOption('days');
        if ($days < 1) {
            $io->error('--days deve essere un intero >= 1.');

            return Command::INVALID;
        }

        $rememberMe = $this->rememberMeTokens->deleteUnusedSince(
            (new \DateTimeImmutable())->modify('-'.self::REMEMBER_ME_LIFETIME_DAYS.' days'),
        );
        $io->success("Cancellate $rememberMe serie remember-me non usate da più di ".self::REMEMBER_ME_LIFETIME_DAYS.' giorni.');

        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days");
        $others = $this->resetTokens->deleteExpired($cutoff)
            + $this->verificationTokens->deleteExpired($cutoff)
            + $this->invitations->deleteExpired($cutoff);
        $io->success("Cancellati $others token di reset/verifica e inviti scaduti.");

        return Command::SUCCESS;
    }
}
