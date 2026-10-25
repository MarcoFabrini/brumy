<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Entity\Vehicle;
use App\Enum\ExpenseCategory;
use App\Service\VehicleStatsService;
use App\Tests\Factory\ExpenseFactory;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\VehicleFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * I listener Doctrine raccolgono in onFlush e agiscono in postFlush. Se il flush fallisce il postFlush
 * non arriva: in un processo longevo (worker Messenger, FrankenPHP) lo stato avanzato non deve
 * contaminare il flush riuscito successivo.
 */
final class ListenerStateAfterFailedFlushTest extends KernelTestCase
{
    use ResetDatabase;
    use Factories;

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function auditRows(): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM audit_logs');
    }

    /** Un flush che fallisce su un indice unico (slug già preso) dopo che i listener hanno già raccolto. */
    private function failAFlushThatAlsoTouches(callable $touch): void
    {
        OrganizationFactory::createOne(['slug' => 'taken']);
        $touch($this->em());
        $this->em()->persist(OrganizationFactory::new()->withoutPersisting()->create(['slug' => 'taken']));

        try {
            $this->em()->flush();
            self::fail('Il flush doveva fallire sull\'indice unico dello slug');
        } catch (UniqueConstraintViolationException) {
            // atteso: l'EntityManager si chiude, come in un worker, e viene ricreato
            static::getContainer()->get(ManagerRegistry::class)->resetManager();
        }
    }

    public function testFailedFlushDoesNotLeakPhantomAuditRowsIntoTheNextFlush(): void
    {
        self::bootKernel();
        $org = OrganizationFactory::createOne();

        $this->failAFlushThatAlsoTouches(function (EntityManagerInterface $em) use ($org): void {
            $em->persist((new Vehicle())
                ->setOrganization($org)
                ->setName('Fantasma')->setBrand('Fiat')->setModel('Panda')->setYear(2020)
                ->setType(\App\Enum\VehicleType::CAR)->setFuelType(\App\Enum\FuelType::GASOLINE)->setInitialKm(0));
        });
        $rowsBeforeNextFlush = $this->auditRows();

        $real = VehicleFactory::createOne(['organization' => $org, 'name' => 'Vera']);

        self::assertSame(
            $rowsBeforeNextFlush + 1,
            $this->auditRows(),
            'Solo la creazione del veicolo "Vera": niente righe per le entità del flush fallito',
        );
        $last = static::getContainer()->get(Connection::class)->fetchAssociative('SELECT entity_class, entity_id FROM audit_logs ORDER BY id DESC LIMIT 1');
        self::assertSame(['entity_class' => 'Vehicle', 'entity_id' => (string) $real->getId()], $last);
    }

    public function testFailedFlushDoesNotInvalidateStatsAtTheNextFlush(): void
    {
        self::bootKernel();
        $stats = static::getContainer()->get(VehicleStatsService::class);
        $vehicle = VehicleFactory::createOne(['initialKm' => 0]);
        $id = (int) $vehicle->getId();
        $categoryId = (int) ExpenseFactory::new()->starterCategory($vehicle, ExpenseCategory::OTHER)->getId();
        self::assertSame(0, $stats->compute($vehicle)['totals']['expenses']); // ora in cache

        $this->failAFlushThatAlsoTouches(function (EntityManagerInterface $em) use ($id): void {
            $em->find(Vehicle::class, $id)?->setName('Rinominato nel flush fallito');
        });

        // Scrittura FUORI da Doctrine: la cache non lo sa, quindi resta il dato in cache...
        static::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO expenses (organization_id, vehicle_id, category_id, occurred_at, amount, description, recurring, created_at) "
            ."SELECT organization_id, id, $categoryId, CURRENT_DATE, 10, 'raw', 0, NOW() FROM vehicles WHERE id = $id",
        );
        // ...e un flush riuscito che non tocca quel veicolo non deve invalidarla per un id rimasto dal flush fallito
        OrganizationFactory::createOne();

        self::assertSame(0, $stats->compute($vehicle)['totals']['expenses'], 'ancora servito dalla cache');
    }

    public function testClearDropsWhatWasCollected(): void
    {
        self::bootKernel();
        $stats = static::getContainer()->get(VehicleStatsService::class);
        $vehicle = VehicleFactory::createOne(['initialKm' => 0]);
        $id = (int) $vehicle->getId();
        $categoryId = (int) ExpenseFactory::new()->starterCategory($vehicle, ExpenseCategory::OTHER)->getId();
        self::assertSame(0, $stats->compute($vehicle)['totals']['expenses']);

        $this->failAFlushThatAlsoTouches(function (EntityManagerInterface $em) use ($id): void {
            $em->find(Vehicle::class, $id)?->setName('x');
        });
        // Anche senza un nuovo onFlush, il clear dell'EntityManager (reset del worker) svuota la raccolta
        $this->em()->clear();
        static::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO expenses (organization_id, vehicle_id, category_id, occurred_at, amount, description, recurring, created_at) "
            ."SELECT organization_id, id, $categoryId, CURRENT_DATE, 10, 'raw', 0, NOW() FROM vehicles WHERE id = $id",
        );
        $this->em()->flush(); // flush senza modifiche: arriva il postFlush ma, se onFlush non gira, nessuna raccolta nuova

        self::assertSame(0, $stats->compute($vehicle)['totals']['expenses']);
    }
}
