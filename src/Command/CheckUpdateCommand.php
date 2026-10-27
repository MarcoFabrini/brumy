<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AppUpdates;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Controlla su GitHub se è uscita una versione più recente di quella installata: l'amministratore dell'istanza la
 * vede nel profilo. Job Ofelia giornaliero (compose.yml). Rilanciabile; con UPDATE_CHECK_ENABLED=0 non fa niente.
 */
#[AsCommand(name: 'app:update:check', description: 'Check GitHub for a newer Brumy release')]
final class CheckUpdateCommand extends Command
{
    public function __construct(private readonly AppUpdates $updates)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->updates->isEnabled()) {
            $io->note('Controllo degli aggiornamenti spento (UPDATE_CHECK_ENABLED=0): niente da fare.');

            return Command::SUCCESS;
        }

        try {
            $latest = $this->updates->check();
        } catch (\RuntimeException $e) {
            $io->error('Controllo non riuscito: '.$e->getMessage());

            return Command::FAILURE;
        }

        $current = $this->updates->currentVersion();
        $update = $this->updates->availableUpdate();
        if ($update !== null) {
            $io->warning(sprintf('Disponibile la versione %s (installata: %s): %s', $update, $current, AppUpdates::releaseUrl($update)));
        } else {
            $io->success(sprintf('Nessun aggiornamento (installata: %s, ultima pubblicata: %s).', $current, $latest ?? 'nessuna'));
        }

        return Command::SUCCESS;
    }
}
