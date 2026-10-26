<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\FuelPrices\FuelPriceImporter;
use App\Service\FuelPrices\FuelPriceImportException;
use App\Service\FuelPrices\FuelPrices;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Scarica distributori e prezzi del carburante dall'open data MIMIT e sostituisce quelli salvati. Job Ofelia
 * giornaliero (compose.yml, dopo le 8: il file è "prezzo alle 8"). Rilanciabile: ogni volta riscrive tutto, e se il
 * download o il formato non vanno lascia i dati precedenti. Con FUEL_PRICES_ENABLED=0 non fa niente.
 */
#[AsCommand(name: 'app:fuel-prices:import', description: 'Download fuel stations and prices from the MIMIT open data')]
final class ImportFuelPricesCommand extends Command
{
    public function __construct(
        private readonly FuelPrices $fuelPrices,
        private readonly FuelPriceImporter $importer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->fuelPrices->isEnabled()) {
            $io->note('Prezzi del carburante spenti (FUEL_PRICES_ENABLED=0): niente da fare.');

            return Command::SUCCESS;
        }

        try {
            $result = $this->importer->import();
        } catch (FuelPriceImportException $e) {
            $io->error('Import non riuscito, restano i dati precedenti: '.$e->getMessage());

            return Command::FAILURE;
        }
        if ($result === null) {
            $io->note('Un altro import è in corso.');

            return Command::SUCCESS;
        }
        $io->success(sprintf(
            'Importati %d distributori e %d prezzi (estrazione del %s).',
            $result['stations'],
            $result['prices'],
            $result['extractedOn']->format('Y-m-d'),
        ));

        return Command::SUCCESS;
    }
}
