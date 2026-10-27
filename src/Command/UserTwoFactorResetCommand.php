<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\TwoFactor\TwoFactorSetup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Azzera la verifica in due passaggi di un utente che ha perso telefono e codici di recupero (o dopo un cambio di
 * APP_SECRET, che rende indecifrabili i segreti TOTP): al prossimo accesso basta la password.
 *
 *   php bin/console app:user:2fa-reset user@example.com --confirm
 *
 * Senza --confirm: dry-run, non cambia nulla.
 */
#[AsCommand(name: 'app:user:2fa-reset', description: 'Turn off two-step verification for a user who lost phone and recovery codes')]
final class UserTwoFactorResetCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly TwoFactorSetup $twoFactor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email')
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Actually reset (otherwise dry-run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        $user = $this->userRepo->findOneByEmail($email);
        if ($user === null) {
            $io->error(sprintf('User "%s" not found', $email));

            return Command::FAILURE;
        }
        if (!$user->isTotpAuthenticationEnabled() && !$user->hasPendingTotpSetup()) {
            $io->info(sprintf('Two-step verification is not on for "%s": nothing to do.', $email));

            return Command::SUCCESS;
        }

        if (!$input->getOption('confirm')) {
            $io->warning(sprintf('DRY-RUN — two-step verification of "%s" (id=%d) would be turned off. Re-run with --confirm to execute.', $email, $user->getId()));

            return Command::SUCCESS;
        }

        $this->twoFactor->reset($user);
        $io->success(sprintf('Two-step verification turned off for "%s": the next sign-in needs only the password.', $email));

        return Command::SUCCESS;
    }
}
