<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Reminder;
use App\Enum\ReminderUrgency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regole di urgenza dei promemoria (date e km). Stesse regole del frontend
 * (frontend/src/api/types/reminder.test.ts): se cambiano, cambiano in entrambi.
 */
final class ReminderUrgencyTest extends TestCase
{
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->today = new \DateTimeImmutable('2026-09-25 15:00:00');
    }

    private function byDate(string $dueDate, int $notifyDaysBefore = 7): Reminder
    {
        return (new Reminder())->setDueDate(new \DateTimeImmutable($dueDate))->setNotifyDaysBefore($notifyDaysBefore);
    }

    /** @return iterable<string, array{string, ReminderUrgency}> */
    public static function dateCases(): iterable
    {
        yield 'scade oggi: in scadenza, non scaduto' => ['2026-09-25', ReminderUrgency::SOON];
        yield 'scaduta ieri' => ['2026-09-24', ReminderUrgency::OVERDUE];
        yield 'esattamente notifyDaysBefore' => ['2026-10-02', ReminderUrgency::SOON];
        yield 'un giorno oltre notifyDaysBefore' => ['2026-10-03', ReminderUrgency::OK];
    }

    #[DataProvider('dateCases')]
    public function testDateUrgency(string $dueDate, ReminderUrgency $expected): void
    {
        self::assertSame($expected, $this->byDate($dueDate)->urgency(null, $this->today));
    }

    public function testTodayStaysSoonUntilMidnight(): void
    {
        $lateEvening = new \DateTimeImmutable('2026-09-25 23:59:00');

        self::assertSame(ReminderUrgency::SOON, $this->byDate('2026-09-25')->urgency(null, $lateEvening));
    }

    public function testNoDeadlineIsOk(): void
    {
        self::assertSame(ReminderUrgency::OK, (new Reminder())->urgency(120_000, $this->today));
    }

    /** @return iterable<string, array{int, ReminderUrgency}> */
    public static function kmCases(): iterable
    {
        yield 'km superati' => [100_001, ReminderUrgency::OVERDUE];
        yield 'esattamente sulla soglia' => [100_000, ReminderUrgency::SOON];
        yield 'entro la soglia di default' => [100_000 - Reminder::DEFAULT_NOTIFY_KM_BEFORE, ReminderUrgency::SOON];
        yield 'appena oltre la soglia di default' => [100_000 - Reminder::DEFAULT_NOTIFY_KM_BEFORE - 1, ReminderUrgency::OK];
    }

    #[DataProvider('kmCases')]
    public function testKmUrgencyWithDefaultNotifyKmBefore(int $currentKm, ReminderUrgency $expected): void
    {
        $r = (new Reminder())->setDueKm(100_000);

        self::assertSame(1000, $r->getNotifyKmBefore());
        self::assertSame($expected, $r->urgency($currentKm, $this->today));
    }

    /** @return iterable<string, array{int, ReminderUrgency}> */
    public static function customKmCases(): iterable
    {
        yield 'km superati' => [100_001, ReminderUrgency::OVERDUE];
        yield 'esattamente notifyKmBefore' => [99_500, ReminderUrgency::SOON];
        yield 'un km oltre notifyKmBefore' => [99_499, ReminderUrgency::OK];
    }

    #[DataProvider('customKmCases')]
    public function testKmUrgencyUsesTheReminderNotifyKmBefore(int $currentKm, ReminderUrgency $expected): void
    {
        $r = (new Reminder())->setDueKm(100_000)->setNotifyKmBefore(500);

        self::assertSame($expected, $r->urgency($currentKm, $this->today));
    }

    public function testKmDeadlineWithUnknownCurrentKmIsIgnored(): void
    {
        self::assertSame(ReminderUrgency::OK, (new Reminder())->setDueKm(100_000)->urgency(null, $this->today));
    }

    public function testWorstOfDateAndKmWins(): void
    {
        $farDateKmPassed = $this->byDate('2026-12-31')->setDueKm(100_000);
        self::assertSame(ReminderUrgency::OVERDUE, $farDateKmPassed->urgency(100_500, $this->today));

        $datePassedFarKm = $this->byDate('2026-09-20')->setDueKm(100_000);
        self::assertSame(ReminderUrgency::OVERDUE, $datePassedFarKm->urgency(50_000, $this->today));

        $dateSoonKmOk = $this->byDate('2026-09-27')->setDueKm(100_000);
        self::assertSame(ReminderUrgency::SOON, $dateSoonKmOk->urgency(50_000, $this->today));
    }

    public function testNotificationIsOnePerLevel(): void
    {
        $r = $this->byDate('2026-09-27');

        self::assertFalse($r->needsNotification(ReminderUrgency::OK), 'niente da notificare se non è in scadenza');
        self::assertTrue($r->needsNotification(ReminderUrgency::SOON), 'prima notifica: in scadenza');

        $r->markNotified(ReminderUrgency::SOON);
        self::assertFalse($r->needsNotification(ReminderUrgency::SOON), 'stesso livello: non si ripete ogni giorno');
        self::assertTrue($r->needsNotification(ReminderUrgency::OVERDUE), 'sale a scaduto: nuova notifica');

        $r->markNotified(ReminderUrgency::OVERDUE);
        self::assertFalse($r->needsNotification(ReminderUrgency::OVERDUE));
        self::assertFalse($r->needsNotification(ReminderUrgency::SOON), 'non si torna indietro');
        self::assertNotNull($r->getLastNotifiedAt());
    }

    public function testChangingDeadlinesResetsNotificationLevel(): void
    {
        $r = $this->byDate('2026-09-27')->setDueKm(100_000);
        $r->markNotified(ReminderUrgency::OVERDUE);

        // Riscrivere gli stessi valori (come fa un PUT che non cambia nulla) non azzera.
        $r->setDueDate(new \DateTimeImmutable('2026-09-27'))->setDueKm(100_000)->setNotifyDaysBefore(7)
            ->setNotifyKmBefore(Reminder::DEFAULT_NOTIFY_KM_BEFORE);
        self::assertSame(ReminderUrgency::OVERDUE, $r->getNotifiedUrgency());

        foreach ([
            fn (Reminder $x) => $x->setDueDate(new \DateTimeImmutable('2026-11-01')),
            fn (Reminder $x) => $x->setDueKm(110_000),
            fn (Reminder $x) => $x->setNotifyDaysBefore(14),
            fn (Reminder $x) => $x->setNotifyKmBefore(500),
        ] as $change) {
            $r->markNotified(ReminderUrgency::OVERDUE);
            $change($r);
            self::assertNull($r->getNotifiedUrgency(), 'cambiata una scadenza: si riparte da zero');
        }
    }

    public function testChangingTheRecurrenceKeepsTheNotificationLevel(): void
    {
        $r = $this->byDate('2026-09-27')->setDueKm(100_000);
        $r->markNotified(ReminderUrgency::SOON);

        // La ricorrenza riguarda la scadenza successiva, non quella corrente.
        $r->setRecurrenceMonths(12)->setRecurrenceKm(10_000);

        self::assertSame(ReminderUrgency::SOON, $r->getNotifiedUrgency());
        self::assertTrue($r->isRecurring());
    }

    public function testIsRecurringWithEitherDimension(): void
    {
        self::assertFalse((new Reminder())->isRecurring());
        self::assertTrue((new Reminder())->setRecurrenceMonths(24)->isRecurring());
        self::assertTrue((new Reminder())->setRecurrenceKm(10_000)->isRecurring());
    }

    public function testMarkCompletedStoresActualDateAndKm(): void
    {
        $at = new \DateTimeImmutable('2026-12-01 10:00:00');
        $r = $this->byDate('2026-12-15')->markCompleted(new \DateTimeImmutable('2026-11-30'), 9800, $at);

        self::assertTrue($r->isCompleted());
        self::assertSame($at, $r->getCompletedAt());
        self::assertSame('2026-11-30', $r->getCompletedOn()?->format('Y-m-d'));
        self::assertSame(9800, $r->getCompletedKm());
    }

    public function testMarkCompletedTwiceIsRejected(): void
    {
        $r = $this->byDate('2026-12-15')->markCompleted(new \DateTimeImmutable('2026-11-30'), null, new \DateTimeImmutable());

        $this->expectException(\LogicException::class);
        $r->markCompleted(new \DateTimeImmutable('2026-12-01'), null, new \DateTimeImmutable());
    }
}
