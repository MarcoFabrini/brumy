<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Entity\Maintenance;
use App\Entity\Vehicle;
use App\Service\AttachmentCleaner;
use App\Service\AttachmentQuota;
use App\Tests\Factory\MaintenanceFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\OrganizationFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Il lock per organizzazione che serializza controllo della quota e inserimento degli allegati.
 */
final class AttachmentQuotaLockTest extends KernelTestCase
{
    use ResetDatabase;
    use Factories;

    public function testRunsTheWorkAndReturnsItsResult(): void
    {
        self::bootKernel();
        $quota = static::getContainer()->get(AttachmentQuota::class);
        $org = OrganizationFactory::createOne();

        self::assertSame('fatto', $quota->serialized($org, static fn (): string => 'fatto'));
    }

    public function testTheLockIsReleasedEvenWhenTheWorkThrows(): void
    {
        self::bootKernel();
        $quota = static::getContainer()->get(AttachmentQuota::class);
        $db = static::getContainer()->get(Connection::class);
        $org = OrganizationFactory::createOne();
        $name = 'brumy_attachments_org_'.$org->getId();

        try {
            $quota->serialized($org, static function (): never {
                throw new \RuntimeException('upload fallito');
            });
            self::fail('L\'eccezione deve risalire');
        } catch (\RuntimeException) {
        }

        // Nessuno tiene più il lock: IS_FREE_LOCK vale 1.
        self::assertSame(1, (int) $db->fetchOne('SELECT IS_FREE_LOCK(:n)', ['n' => $name]));
    }

    public function testLocksAreIndependentPerOrganization(): void
    {
        self::bootKernel();
        $quota = static::getContainer()->get(AttachmentQuota::class);
        $db = static::getContainer()->get(Connection::class);
        $a = OrganizationFactory::createOne();
        $b = OrganizationFactory::createOne();

        $quota->serialized($a, function () use ($db, $a, $b): void {
            self::assertSame(0, (int) $db->fetchOne('SELECT IS_FREE_LOCK(:n)', ['n' => 'brumy_attachments_org_'.$a->getId()]));
            self::assertSame(1, (int) $db->fetchOne('SELECT IS_FREE_LOCK(:n)', ['n' => 'brumy_attachments_org_'.$b->getId()]));
        });
    }
    /**
     * La cancellazione di un record o di un veicolo avviene sotto il lock degli upload della sua org: un upload
     * in coda non può inserire un allegato per l'entità mentre viene cancellata. La sonda guarda il lock da
     * un'altra connessione nel momento in cui l'entità viene rimossa.
     */
    #[DataProvider('removals')]
    public function testRemovingARecordOrVehicleHoldsTheOrganizationUploadLock(string $what): void
    {
        self::bootKernel();
        $org = OrganizationFactory::createOne();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $maintenance = MaintenanceFactory::createOne(['organization' => $org, 'vehicle' => $vehicle]);
        $name = 'brumy_attachments_org_'.$org->getId();
        $other = DriverManager::getConnection(static::getContainer()->get(Connection::class)->getParams());
        $probe = new class($other, $name) {
            public ?bool $lockHeld = null;

            public function __construct(private readonly Connection $other, private readonly string $name)
            {
            }

            public function preRemove(PreRemoveEventArgs $args): void
            {
                $entity = $args->getObject();
                if ($this->lockHeld === null && ($entity instanceof Maintenance || $entity instanceof Vehicle)) {
                    $this->lockHeld = (int) $this->other->fetchOne('SELECT IS_FREE_LOCK(?)', [$this->name]) === 0;
                }
            }
        };
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getEventManager()->addEventListener([Events::preRemove], $probe);
        $cleaner = static::getContainer()->get(AttachmentCleaner::class);

        try {
            $what === 'record' ? $cleaner->removeRecord($maintenance) : $cleaner->removeVehicle($vehicle);
        } finally {
            $em->getEventManager()->removeEventListener([Events::preRemove], $probe);
        }

        self::assertTrue($probe->lockHeld, 'La cancellazione deve tenere il lock degli upload');
        self::assertSame(1, (int) $other->fetchOne('SELECT IS_FREE_LOCK(?)', [$name]), 'Lock rilasciato alla fine');
        $other->close();
    }

    /** @return iterable<string, array{string}> */
    public static function removals(): iterable
    {
        yield 'record' => ['record'];
        yield 'veicolo' => ['vehicle'];
    }
}
