<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\Gdpr\GdprDeleteBlockedException;
use App\Service\Gdpr\GdprDeleteService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * GDPR Art. 17 — right to erasure (#5.5).
 *
 * Anonymize user (overwrite PII) + end sessions and remember-me cookies + reset two-step verification + delete push subs.
 * NB: vehicles e dati derivati restano in DB perché appartengono all'org
 * (potrebbero esserci altri membri).
 *
 *   php bin/console app:gdpr:delete user@example.com --confirm
 *
 * Senza --confirm: dry-run mostra cosa farà.
 */
#[AsCommand(name: 'app:gdpr:delete', description: 'Anonymize user (GDPR Art. 17 right to erasure)')]
final class GdprDeleteCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly GdprDeleteService $deleter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email')
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Actually perform the deletion (otherwise dry-run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $confirm = (bool) $input->getOption('confirm');

        $user = $this->userRepo->findOneByEmail($email);
        if ($user === null) {
            $io->error(sprintf('User "%s" not found', $email));
            return Command::FAILURE;
        }

        $io->section(sprintf('GDPR delete request: %s (id=%d)', $email, $user->getId()));
        $io->listing([
            sprintf('Memberships: %d', $user->getMemberships()->count()),
        ]);

        if (!$confirm) {
            $io->warning('DRY-RUN — no changes made. Re-run with --confirm to execute.');
            return Command::SUCCESS;
        }

        try {
            $summary = $this->deleter->anonymize($user);
        } catch (GdprDeleteBlockedException $e) {
            $io->error('Deletion blocked: '.$e->getMessage());
            return Command::FAILURE;
        }

        $io->success('User anonymized.');
        $io->table(['Metric', 'Value'], array_map(fn ($k, $v) => [$k, (string) $v], array_keys($summary), array_values($summary)));
        return Command::SUCCESS;
    }
}
