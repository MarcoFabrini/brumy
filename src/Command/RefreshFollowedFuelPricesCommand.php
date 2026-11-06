<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\FuelStationRepository;
use App\Service\AppClock;
use App\Service\FuelPrices\LiveFuelPrices;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prezzi del giorno stesso per i distributori seguiti ({@see LiveFuelPrices}) e il punto di oggi nel loro andamento.
 * Job Ofelia ogni ora di giorno (compose.yml). Rilanciabile: ogni distributore si chiede al massimo ogni 15 minuti, e
 * lo storico di oggi si riscrive invece di duplicarsi. Con FUEL_PRICES_LIVE_ENABLED=0 non fa niente.
 */
#[AsCommand(name: 'app:fuel-prices:refresh-followed', description: 'Refresh today\'s prices of the followed fuel stations')]
final class RefreshFollowedFuelPricesCommand extends Command
{
    public function __construct(
        private readonly LiveFuelPrices $livePrices,
        private readonly FuelStationRepository $stations,
        private readonly AppClock $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->livePrices->isEnabled()) {
            $io->note('Prezzi in tempo reale spenti (FUEL_PRICES_LIVE_ENABLED=0 o FUEL_PRICES_ENABLED=0): niente da fare.');

            return Command::SUCCESS;
        }
        $followed = $this->stations->followedIds();
        $asked = $this->livePrices->refresh($followed);
        if ($followed !== []) {
            $this->stations->recordHistory($this->clock->today());
        }
        $io->success(sprintf('%d distributori seguiti, %d chiesti al ministero.', count($followed), $asked));

        return Command::SUCCESS;
    }
}
