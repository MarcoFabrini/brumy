<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Entity\Maintenance;
use App\Entity\Reminder;
use App\Entity\Vehicle;
use App\Enum\OrgRole;
use App\Enum\ReminderUrgency;
use App\Message\SendReminderNotificationMessage;
use App\MessageHandler\SendReminderNotificationHandler;
use App\Tests\Factory\MaintenanceFactory;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\RefuelingFactory;
use App\Tests\Factory\ReminderFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Il job accoda un messaggio solo per i promemoria che hanno raggiunto un NUOVO livello di
 * urgenza, sia a data sia a chilometri (prima i promemoria a km non venivano mai notificati).
 */
final class DispatchRemindersCommandTest extends KernelTestCase
{
    use ResetDatabase;
    use Factories;

    /** @return list<int> id dei promemoria per cui è stato accodato un messaggio */
    private function dispatchedIds(bool $dryRun = false): array
    {
        $application = new Application(self::bootKernel());
        $tester = new CommandTester($application->find('app:reminders:dispatch'));
        $tester->execute($dryRun ? ['--dry-run' => true] : []);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $ids = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(SendReminderNotificationMessage::class, $message);
            $ids[] = $message->reminderId;
        }
        $transport->reset();

        return $ids;
    }

    public function testDispatchesDateAndKmRemindersButNotTheOnesFarAway(): void
    {
        $nearVehicle = VehicleFactory::createOne(['initialKm' => 99_600]);
        $farVehicle = VehicleFactory::createOne(['initialKm' => 10_000]);

        $byDate = ReminderFactory::createOne(['dueDate' => new \DateTimeImmutable('+5 days'), 'notifyDaysBefore' => 7]);
        $byKm = ReminderFactory::createOne([
            'vehicle' => $nearVehicle, 'organization' => $nearVehicle->getOrganization(),
            'dueDate' => null, 'dueKm' => 100_000,
        ]);
        ReminderFactory::createOne(['dueDate' => new \DateTimeImmutable('+90 days'), 'notifyDaysBefore' => 7]);
        ReminderFactory::createOne([
            'vehicle' => $farVehicle, 'organization' => $farVehicle->getOrganization(),
            'dueDate' => null, 'dueKm' => 100_000,
        ]);
        ReminderFactory::createOne(['dueDate' => new \DateTimeImmutable('+1 day'), 'completedAt' => new \DateTimeImmutable()]);

        self::assertEqualsCanonicalizing([$byDate->getId(), $byKm->getId()], $this->dispatchedIds());
    }

    public function testOverdueByDateIsNotifiedToo(): void
    {
        // Prima la finestra era "da oggi in poi": un promemoria già scaduto non partiva mai.
        $overdue = ReminderFactory::createOne(['dueDate' => new \DateTimeImmutable('-2 days')]);

        self::assertSame([$overdue->getId()], $this->dispatchedIds());
    }

    public function testRespectsPerReminderNotifyDaysBefore(): void
    {
        // Prima contava una finestra fissa di 30 giorni, qualunque fosse notifyDaysBefore.
        ReminderFactory::createOne(['dueDate' => new \DateTimeImmutable('+20 days'), 'notifyDaysBefore' => 3]);
        $inWindow = ReminderFactory::createOne(['dueDate' => new \DateTimeImmutable('+2 days'), 'notifyDaysBefore' => 3]);

        self::assertSame([$inWindow->getId()], $this->dispatchedIds());
    }

    public function testRespectsPerReminderNotifyKmBefore(): void
    {
        // Mancano 700 km con un anticipo di 500: non ancora (con la vecchia soglia fissa di 1000 sarebbe partito).
        $notYet = VehicleFactory::createOne(['initialKm' => 99_300]);
        ReminderFactory::createOne([
            'vehicle' => $notYet, 'organization' => $notYet->getOrganization(),
            'dueDate' => null, 'dueKm' => 100_000, 'notifyKmBefore' => 500,
        ]);
        $inWindow = VehicleFactory::createOne(['initialKm' => 99_600]);
        $soon = ReminderFactory::createOne([
            'vehicle' => $inWindow, 'organization' => $inWindow->getOrganization(),
            'dueDate' => null, 'dueKm' => 100_000, 'notifyKmBefore' => 500,
        ]);

        self::assertSame([$soon->getId()], $this->dispatchedIds());
    }

    public function testDoesNotRepeatTheSameLevelButEscalatesToOverdue(): void
    {
        $vehicle = VehicleFactory::createOne(['initialKm' => 99_500]);
        $r = ReminderFactory::createOne([
            'vehicle' => $vehicle, 'organization' => $vehicle->getOrganization(),
            'dueDate' => null, 'dueKm' => 100_000,
        ]);

        // Notificato a livello "in scadenza": il giorno dopo il cron non lo ripropone...
        $r->markNotified(ReminderUrgency::SOON);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        self::assertSame([], $this->dispatchedIds());

        // ...ma quando i km superano la soglia sale a "scaduto" e parte la seconda notifica.
        RefuelingFactory::createOne(['vehicle' => $vehicle, 'organization' => $vehicle->getOrganization(), 'km' => 100_200]);
        self::assertSame([$r->getId()], $this->dispatchedIds());
    }

    public function testKmCorrectionRearmsAReminderNotifiedAtAHigherLevel(): void
    {
        $vehicle = VehicleFactory::createOne(['initialKm' => 50_000]);
        $r = ReminderFactory::createOne([
            'vehicle' => $vehicle, 'organization' => $vehicle->getOrganization(),
            'dueDate' => null, 'dueKm' => 100_000,
        ]);
        $r->markNotified(ReminderUrgency::OVERDUE);
        $lastNotifiedAt = $r->getLastNotifiedAt();
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        // I km attuali (50.000) sono lontani dalla soglia: il livello "scaduto" era un refuso. Nessun
        // messaggio, ma il livello notificato torna a "ok", conservando quando è partita l'ultima notifica.
        self::assertSame([], $this->dispatchedIds());

        $fresh = $this->fresh($r->getId());
        self::assertNull($fresh->getNotifiedUrgency());
        self::assertNotNull($lastNotifiedAt);
        self::assertSame($lastNotifiedAt->format('Y-m-d H:i:s'), $fresh->getLastNotifiedAt()?->format('Y-m-d H:i:s'));
    }

    public function testRearmLowersToTheComputedLevelWithoutResendingASoonNotification(): void
    {
        $vehicle = VehicleFactory::createOne(['initialKm' => 99_500]);
        $r = ReminderFactory::createOne([
            'vehicle' => $vehicle, 'organization' => $vehicle->getOrganization(),
            'dueDate' => null, 'dueKm' => 100_000,
        ]);
        $r->markNotified(ReminderUrgency::OVERDUE);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        // Ora è "in scadenza" (500 km): non si rimanda quel livello, già dato prima dello "scaduto"
        self::assertSame([], $this->dispatchedIds());
        self::assertSame(ReminderUrgency::SOON, $this->fresh($r->getId())->getNotifiedUrgency());

        // ...e il superamento vero della soglia notifica
        RefuelingFactory::createOne(['vehicle' => $vehicle, 'organization' => $vehicle->getOrganization(), 'km' => 100_300]);
        self::assertSame([$r->getId()], $this->dispatchedIds());
    }

    public function testStillOverdueKmReminderKeepsItsLevelAndDateOnlyOnesAreUntouched(): void
    {
        $vehicle = VehicleFactory::createOne(['initialKm' => 100_400]);
        $byKm = ReminderFactory::createOne([
            'vehicle' => $vehicle, 'organization' => $vehicle->getOrganization(),
            'dueDate' => null, 'dueKm' => 100_000,
        ]);
        $byKm->markNotified(ReminderUrgency::OVERDUE);
        $byDate = ReminderFactory::createOne(['dueDate' => new \DateTimeImmutable('-3 days'), 'dueKm' => null]);
        $byDate->markNotified(ReminderUrgency::OVERDUE);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertSame([], $this->dispatchedIds());

        self::assertSame(ReminderUrgency::OVERDUE, $this->fresh($byKm->getId())->getNotifiedUrgency());
        self::assertSame(ReminderUrgency::OVERDUE, $this->fresh($byDate->getId())->getNotifiedUrgency());
    }

    public function testDryRunDoesNotRearm(): void
    {
        $vehicle = VehicleFactory::createOne(['initialKm' => 50_000]);
        $r = ReminderFactory::createOne([
            'vehicle' => $vehicle, 'organization' => $vehicle->getOrganization(),
            'dueDate' => null, 'dueKm' => 100_000,
        ]);
        $r->markNotified(ReminderUrgency::OVERDUE);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertSame([], $this->dispatchedIds(dryRun: true));

        self::assertSame(ReminderUrgency::OVERDUE, $this->fresh($r->getId())->getNotifiedUrgency());
    }

    /**
     * Scenario reale: refuso sui km (1.234.567 invece di 123.456) → ogni promemoria a km risulta scaduto
     * e viene notificato; corretto il refuso, il livello viene riarmato e il vero superamento notifica.
     */
    public function testKmTypoThenFixThenRealCrossingNotifiesAgain(): void
    {
        $vehicle = VehicleFactory::createOne(['initialKm' => 100_000]);
        $org = $vehicle->getOrganization();
        $owner = UserFactory::createOne(['email' => 'typo@test.it']);
        OrganizationMemberFactory::createOne(['organization' => $org, 'user' => $owner, 'role' => OrgRole::OWNER]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $vehicle, 'user' => $owner]);
        $reminder = ReminderFactory::createOne([
            'vehicle' => $vehicle, 'organization' => $org,
            'dueDate' => null, 'dueKm' => 130_000, 'description' => 'Distribuzione',
        ]);
        $maintenance = MaintenanceFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'km' => 1_234_567]);
        $id = (int) $reminder->getId();

        // 1. Il refuso fa risultare il promemoria scaduto: notifica "scaduto"
        self::assertSame([$id], $this->dispatchedIds());
        $this->runHandler($id, expectedMails: 1);
        self::assertSame(ReminderUrgency::OVERDUE, $this->fresh($id)->getNotifiedUrgency());

        // 2. L'utente corregge il refuso: i km veri (123.456) sono lontani dalla soglia → riarmato, nessun invio
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->find(Maintenance::class, $maintenance->getId())?->setKm(123_456);
        $em->flush();
        self::assertSame([], $this->dispatchedIds());
        self::assertNull($this->fresh($id)->getNotifiedUrgency());

        // 3. Il veicolo si avvicina alla soglia: "in scadenza" notifica
        $this->refuel($vehicle, 129_400);
        self::assertSame([$id], $this->dispatchedIds());
        $this->runHandler($id, expectedMails: 1);
        self::assertSame(ReminderUrgency::SOON, $this->fresh($id)->getNotifiedUrgency());

        // 4. Supera la soglia: "scaduto" notifica di nuovo
        $this->refuel($vehicle, 130_200);
        self::assertSame([$id], $this->dispatchedIds());
        $this->runHandler($id, expectedMails: 1);
        self::assertSame(ReminderUrgency::OVERDUE, $this->fresh($id)->getNotifiedUrgency());

        // 5. E da lì in poi non si ripete
        self::assertSame([], $this->dispatchedIds());
    }

    private function fresh(?int $id): Reminder
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reminder = $em->find(Reminder::class, $id);
        self::assertNotNull($reminder);

        return $reminder;
    }

    private function refuel(Vehicle $vehicle, int $km): void
    {
        RefuelingFactory::createOne(['vehicle' => $vehicle, 'organization' => $vehicle->getOrganization(), 'km' => $km]);
    }

    private function runHandler(int $reminderId, int $expectedMails): void
    {
        (static::getContainer()->get(SendReminderNotificationHandler::class))(new SendReminderNotificationMessage($reminderId));
        $mails = array_filter(static::getMailerMessages(), static fn ($m) => $m instanceof Email && str_contains((string) $m->getSubject(), 'Distribuzione'));
        self::assertCount($expectedMails, $mails);
    }

    public function testDryRunListsButDispatchesNothing(): void
    {
        ReminderFactory::createOne(['dueDate' => new \DateTimeImmutable('+1 day'), 'notifyDaysBefore' => 7]);

        self::assertSame([], $this->dispatchedIds(dryRun: true));
    }
}
