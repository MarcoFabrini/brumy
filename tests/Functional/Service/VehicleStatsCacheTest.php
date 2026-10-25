<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Enum\ExpenseCategory;
use App\Service\VehicleStatsService;
use App\Tests\Factory\ExpenseFactory;
use App\Tests\Factory\RefuelingFactory;
use App\Tests\Factory\VehicleFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Le statistiche sono in cache (5 minuti) ma ogni scrittura che le alimenta le invalida subito:
 * una modifica si vede al prossimo caricamento, non dopo la scadenza.
 */
final class VehicleStatsCacheTest extends KernelTestCase
{
    use ResetDatabase;
    use Factories;

    public function testStatsAreServedFromCacheUntilSomethingChanges(): void
    {
        self::bootKernel();
        $stats = static::getContainer()->get(VehicleStatsService::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vehicle = VehicleFactory::createOne(['initialKm' => 0]);
        $id = (int) $vehicle->getId();
        $categoryId = (int) ExpenseFactory::new()->starterCategory($vehicle, ExpenseCategory::OTHER)->getId();

        self::assertSame(0, $stats->compute($vehicle)['totals']['expenses']);

        // Scrittura FUORI da Doctrine (nessun evento): la cache non può saperlo → dato ancora in cache.
        $em->getConnection()->executeStatement(
            "INSERT INTO expenses (organization_id, vehicle_id, category_id, occurred_at, amount, description, recurring, created_at) "
            ."SELECT organization_id, id, $categoryId, CURRENT_DATE, 10, 'raw', 0, NOW() FROM vehicles WHERE id = $id",
        );
        self::assertSame(0, $stats->compute($vehicle)['totals']['expenses'], 'servito dalla cache');

        // Una scrittura passata da Doctrine invalida la cache.
        ExpenseFactory::createOne(['vehicle' => $vehicle, 'organization' => $vehicle->getOrganization(), 'amount' => '5.00']);
        self::assertSame(2, $stats->compute($vehicle)['totals']['expenses']);
    }

    public function testNewRefuelingShowsUpImmediately(): void
    {
        self::bootKernel();
        $stats = static::getContainer()->get(VehicleStatsService::class);
        $vehicle = VehicleFactory::createOne(['initialKm' => 100]);

        self::assertSame(100, $stats->compute($vehicle)['currentKm']);

        RefuelingFactory::createOne(['vehicle' => $vehicle, 'organization' => $vehicle->getOrganization(), 'km' => 900]);

        self::assertSame(900, $stats->compute($vehicle)['currentKm']);
    }
}
