<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Entity\Reminder;
use App\Entity\Vehicle;
use App\Enum\ReminderType;
use App\Enum\ReminderUrgency;
use App\Repository\ReminderRepository;
use App\Service\AppClock;
use App\Service\ReminderAlreadyCompletedException;
use App\Service\ReminderCompletion;
use App\Tests\Factory\RefuelingFactory;
use App\Tests\Factory\ReminderFactory;
use App\Tests\Factory\VehicleFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Completamento con data e km effettivi: il successore di un promemoria ricorrente si calcola dal
 * completamento, solo nelle dimensioni che ricorrono; il predecessore resta come storico.
 */
final class ReminderCompletionTest extends KernelTestCase
{
    use ResetDatabase;
    use Factories;

    private ReminderCompletion $completion;
    private EntityManagerInterface $em;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->completion = static::getContainer()->get(ReminderCompletion::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->vehicle = VehicleFactory::createOne(['initialKm' => 5_000]);
    }

    /** @param array<string, mixed> $attributes */
    private function reminder(array $attributes): Reminder
    {
        return ReminderFactory::createOne([
            'organization' => $this->vehicle->getOrganization(),
            'vehicle' => $this->vehicle,
            'dueDate' => null,
            ...$attributes,
        ]);
    }

    public function testKmRecurrenceStartsFromTheActualCompletionKm(): void
    {
        $r = $this->reminder([
            'type' => ReminderType::OIL_CHANGE,
            'description' => 'Tagliando',
            'dueKm' => 10_000,
            'recurrenceKm' => 10_000,
            'notifyDaysBefore' => 15,
            'notifyKmBefore' => 500,
        ]);
        $r->markNotified(ReminderUrgency::SOON);
        $this->em->flush();

        $next = $this->completion->complete($r, new \DateTimeImmutable('2026-10-01'), 9_800);

        self::assertNotNull($next);
        self::assertNotNull($next->getId());
        self::assertNotSame($r->getId(), $next->getId());
        self::assertSame(19_800, $next->getDueKm());
        self::assertNull($next->getDueDate());
        self::assertSame(ReminderType::OIL_CHANGE, $next->getType());
        self::assertSame('Tagliando', $next->getDescription());
        self::assertSame(15, $next->getNotifyDaysBefore());
        self::assertSame(500, $next->getNotifyKmBefore());
        self::assertNull($next->getRecurrenceMonths());
        self::assertSame(10_000, $next->getRecurrenceKm());
        self::assertNull($next->getCompletedAt());
        self::assertNull($next->getCompletedOn());
        self::assertNull($next->getCompletedKm());
        self::assertNull($next->getNotifiedUrgency());
        self::assertNull($next->getLastNotifiedAt());
        self::assertSame($this->vehicle->getId(), $next->getVehicle()->getId());

        $this->em->clear();
        $done = $this->em->find(Reminder::class, $r->getId());
        self::assertNotNull($done);
        self::assertNotNull($done->getCompletedAt());
        self::assertSame('2026-10-01', $done->getCompletedOn()?->format('Y-m-d'));
        self::assertSame(9_800, $done->getCompletedKm());
        self::assertSame(ReminderUrgency::SOON, $done->getNotifiedUrgency(), 'lo stato di notifica del predecessore resta come storico');
    }

    public function testTimeRecurrenceStartsFromTheActualCompletionDate(): void
    {
        $r = $this->reminder(['dueDate' => new \DateTimeImmutable('2026-12-15'), 'recurrenceMonths' => 24]);

        $next = $this->completion->complete($r, new \DateTimeImmutable('2026-11-30'), null);

        self::assertNotNull($next);
        self::assertSame('2028-11-30', $next->getDueDate()?->format('Y-m-d'));
        self::assertNull($next->getDueKm());
        self::assertNull($r->getCompletedKm(), 'senza ricorrenza a km non serve un km di base');
    }

    public function testBothRecurrencesAdvanceBothDeadlinesFromTheCompletion(): void
    {
        $r = $this->reminder([
            'dueDate' => new \DateTimeImmutable('2027-03-01'),
            'dueKm' => 30_000,
            'recurrenceMonths' => 12,
            'recurrenceKm' => 15_000,
        ]);

        $next = $this->completion->complete($r, new \DateTimeImmutable('2027-01-31'), 28_500);

        self::assertNotNull($next);
        self::assertSame('2028-01-31', $next->getDueDate()?->format('Y-m-d'));
        self::assertSame(43_500, $next->getDueKm());
    }

    public function testDimensionWithoutRecurrenceIsNotCopied(): void
    {
        $r = $this->reminder([
            'dueDate' => new \DateTimeImmutable('2026-12-15'),
            'dueKm' => 50_000,
            'recurrenceMonths' => 12,
        ]);

        $next = $this->completion->complete($r, new \DateTimeImmutable('2026-12-10'), 49_000);

        self::assertNotNull($next);
        self::assertSame('2027-12-10', $next->getDueDate()?->format('Y-m-d'));
        self::assertNull($next->getDueKm(), 'una scadenza a km non ricorrente farebbe nascere il successore già scaduto');
    }

    public function testOneOffReminderIsOnlyCompleted(): void
    {
        $r = $this->reminder(['dueKm' => 20_000]);

        $next = $this->completion->complete($r, new \DateTimeImmutable('2026-09-01'), 19_950);

        self::assertNull($next);
        self::assertTrue($r->isCompleted());
        self::assertSame('2026-09-01', $r->getCompletedOn()?->format('Y-m-d'));
        self::assertSame(19_950, $r->getCompletedKm());
        self::assertCount(1, $this->remindersOfVehicle());
    }

    public function testDefaultsAreTodayAndTheCurrentOdometerForKmRecurrence(): void
    {
        RefuelingFactory::createOne(['organization' => $this->vehicle->getOrganization(), 'vehicle' => $this->vehicle, 'km' => 12_345]);
        $r = $this->reminder(['dueKm' => 12_000, 'recurrenceKm' => 10_000]);

        $next = $this->completion->complete($r, null, null);

        $today = static::getContainer()->get(AppClock::class)->today();
        self::assertSame($today->format('Y-m-d'), $r->getCompletedOn()?->format('Y-m-d'));
        self::assertSame(12_345, $r->getCompletedKm(), 'il km di base si salva sul completamento');
        self::assertSame(22_345, $next?->getDueKm());
    }

    public function testOmittedKmWithoutKmRecurrenceStaysNull(): void
    {
        RefuelingFactory::createOne(['organization' => $this->vehicle->getOrganization(), 'vehicle' => $this->vehicle, 'km' => 12_345]);
        $r = $this->reminder(['dueDate' => new \DateTimeImmutable('2026-12-15'), 'recurrenceMonths' => 12]);

        $this->completion->complete($r, null, null);

        self::assertNull($r->getCompletedKm());
    }

    public function testAlreadyCompletedReminderIsRejectedWithoutANewRow(): void
    {
        $r = $this->reminder(['dueKm' => 10_000, 'recurrenceKm' => 10_000]);
        $this->completion->complete($r, new \DateTimeImmutable('2026-10-01'), 9_800);
        self::assertCount(2, $this->remindersOfVehicle());

        try {
            $this->completion->complete($r, new \DateTimeImmutable('2026-10-02'), 9_900);
            self::fail('Un secondo completamento deve fallire');
        } catch (ReminderAlreadyCompletedException) {
        }

        // L'eccezione dentro wrapInTransaction() chiude l'EntityManager: si rilegge via DBAL.
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT completed_km FROM reminders WHERE vehicle_id = ? ORDER BY id',
            [$this->vehicle->getId()],
        );
        self::assertCount(2, $rows, 'nessun secondo successore');
        self::assertSame(9_800, (int) $rows[0]['completed_km'], 'il primo completamento resta');
    }

    public function testStaleInstanceIsCheckedAgainstTheDatabase(): void
    {
        $r = $this->reminder(['dueKm' => 10_000, 'recurrenceKm' => 10_000]);
        // Un'altra richiesta ha completato la riga: l'istanza in memoria non lo sa ancora.
        $this->em->getConnection()->executeStatement('UPDATE reminders SET completed_at = NOW() WHERE id = ?', [$r->getId()]);

        $this->expectException(ReminderAlreadyCompletedException::class);
        $this->completion->complete($r, new \DateTimeImmutable('2026-10-01'), 9_800);
    }

    /** @return list<Reminder> */
    private function remindersOfVehicle(): array
    {
        return static::getContainer()->get(ReminderRepository::class)->findBy(['vehicle' => $this->vehicle]);
    }
}
