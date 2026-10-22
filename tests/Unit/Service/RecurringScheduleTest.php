<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Enum\RecurringPeriod;
use App\Service\RecurringSchedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecurringScheduleTest extends TestCase
{
    private RecurringSchedule $schedule;

    protected function setUp(): void
    {
        $this->schedule = new RecurringSchedule();
    }

    /** @return iterable<string, array{RecurringPeriod, string, string, list<string>}> */
    public static function periods(): iterable
    {
        yield 'weekly' => [RecurringPeriod::WEEKLY, '2026-01-01', '2026-01-31', ['2026-01-01', '2026-01-08', '2026-01-15', '2026-01-22', '2026-01-29']];
        yield 'monthly' => [RecurringPeriod::MONTHLY, '2026-01-15', '2026-05-14', ['2026-01-15', '2026-02-15', '2026-03-15', '2026-04-15']];
        yield 'quarterly' => [RecurringPeriod::QUARTERLY, '2025-11-10', '2026-12-31', ['2025-11-10', '2026-02-10', '2026-05-10', '2026-08-10', '2026-11-10']];
        yield 'semiannual' => [RecurringPeriod::SEMIANNUAL, '2025-03-01', '2026-12-31', ['2025-03-01', '2025-09-01', '2026-03-01', '2026-09-01']];
        yield 'yearly' => [RecurringPeriod::YEARLY, '2023-06-20', '2026-06-20', ['2023-06-20', '2024-06-20', '2025-06-20', '2026-06-20']];
        yield 'biennial' => [RecurringPeriod::BIENNIAL, '2018-09-05', '2026-12-31', ['2018-09-05', '2020-09-05', '2022-09-05', '2024-09-05', '2026-09-05']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('periods')]
    public function testEveryPeriodAdvancesByItsStep(RecurringPeriod $period, string $start, string $today, array $expected): void
    {
        self::assertSame($expected, self::formatted($this->schedule->chargesUpTo($period, self::d($start), self::d($today))));
    }

    public function testMonthEndIsClampedWithoutDrift(): void
    {
        $charges = $this->schedule->chargesUpTo(RecurringPeriod::MONTHLY, self::d('2026-01-31'), self::d('2026-06-30'));

        // Dopo il 28 febbraio si torna al 31 marzo: le date partono sempre dall'ancora, non dall'addebito prima.
        self::assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30'], self::formatted($charges));
    }

    public function testMonthEndInALeapYearUsesFebruary29(): void
    {
        $charges = $this->schedule->chargesUpTo(RecurringPeriod::MONTHLY, self::d('2028-01-31'), self::d('2028-03-31'));

        self::assertSame(['2028-01-31', '2028-02-29', '2028-03-31'], self::formatted($charges));
    }

    public function testQuarterlyFromThe31stClampsToShortMonths(): void
    {
        $charges = $this->schedule->chargesUpTo(RecurringPeriod::QUARTERLY, self::d('2025-08-31'), self::d('2026-12-31'));

        self::assertSame(['2025-08-31', '2025-11-30', '2026-02-28', '2026-05-31', '2026-08-31', '2026-11-30'], self::formatted($charges));
    }

    public function testYearlyFromFebruary29FallsBackToThe28thInCommonYears(): void
    {
        $charges = $this->schedule->chargesUpTo(RecurringPeriod::YEARLY, self::d('2024-02-29'), self::d('2028-12-31'));

        self::assertSame(['2024-02-29', '2025-02-28', '2026-02-28', '2027-02-28', '2028-02-29'], self::formatted($charges));
    }

    public function testBiennialFromFebruary29(): void
    {
        $charges = $this->schedule->chargesUpTo(RecurringPeriod::BIENNIAL, self::d('2024-02-29'), self::d('2032-12-31'));

        self::assertSame(['2024-02-29', '2026-02-28', '2028-02-29', '2030-02-28', '2032-02-29'], self::formatted($charges));
    }

    public function testStartInTheFutureHasNoCharges(): void
    {
        self::assertSame([], $this->schedule->chargesUpTo(RecurringPeriod::MONTHLY, self::d('2026-10-06'), self::d('2026-10-05')));
    }

    public function testStartEqualToTodayIsASingleCharge(): void
    {
        $charges = $this->schedule->chargesUpTo(RecurringPeriod::MONTHLY, self::d('2026-10-05'), self::d('2026-10-05'));

        self::assertSame(['2026-10-05'], self::formatted($charges));
    }

    public function testChargeOnTheLimitDayIsIncludedAndTheDayBeforeIsNot(): void
    {
        $onTheDay = $this->schedule->chargesUpTo(RecurringPeriod::WEEKLY, self::d('2026-09-21'), self::d('2026-10-05'));
        $dayBefore = $this->schedule->chargesUpTo(RecurringPeriod::WEEKLY, self::d('2026-09-21'), self::d('2026-10-04'));

        self::assertSame(['2026-09-21', '2026-09-28', '2026-10-05'], self::formatted($onTheDay));
        self::assertSame(['2026-09-21', '2026-09-28'], self::formatted($dayBefore));
    }

    public function testRecurringUntilIsInclusive(): void
    {
        $charges = $this->schedule->chargesUpTo(RecurringPeriod::MONTHLY, self::d('2026-01-10'), self::d('2026-12-31'), self::d('2026-04-10'));

        self::assertSame(['2026-01-10', '2026-02-10', '2026-03-10', '2026-04-10'], self::formatted($charges));
    }

    public function testRecurringUntilTheDayBeforeACharge(): void
    {
        $charges = $this->schedule->chargesUpTo(RecurringPeriod::MONTHLY, self::d('2026-01-10'), self::d('2026-12-31'), self::d('2026-04-09'));

        self::assertSame(['2026-01-10', '2026-02-10', '2026-03-10'], self::formatted($charges));
    }

    public function testRecurringUntilBeforeStartHasNoCharges(): void
    {
        $charges = $this->schedule->chargesUpTo(RecurringPeriod::MONTHLY, self::d('2026-01-10'), self::d('2026-12-31'), self::d('2026-01-09'));

        self::assertSame([], $charges);
    }

    public function testRecurringUntilEqualToStartIsASingleCharge(): void
    {
        $charges = $this->schedule->chargesUpTo(RecurringPeriod::YEARLY, self::d('2026-01-10'), self::d('2030-01-01'), self::d('2026-01-10'));

        self::assertSame(['2026-01-10'], self::formatted($charges));
    }

    public function testRecurringUntilAfterTodayDoesNotCountFutureCharges(): void
    {
        $charges = $this->schedule->chargesUpTo(RecurringPeriod::MONTHLY, self::d('2026-08-20'), self::d('2026-10-05'), self::d('2027-12-31'));

        self::assertSame(['2026-08-20', '2026-09-20'], self::formatted($charges));
    }

    public function testWeeklyBoundariesAcrossMonthAndYear(): void
    {
        $charges = $this->schedule->chargesUpTo(RecurringPeriod::WEEKLY, self::d('2025-12-25'), self::d('2026-01-15'));

        self::assertSame(['2025-12-25', '2026-01-01', '2026-01-08', '2026-01-15'], self::formatted($charges));
    }

    public function testWindowKeepsOnlyChargesInsideWithEndExcluded(): void
    {
        $charges = $this->schedule->chargesInWindow(
            RecurringPeriod::MONTHLY,
            self::d('2026-01-01'),
            self::d('2026-03-01'),
            self::d('2026-06-01'),
            self::d('2026-12-31'),
        );

        // Il 1 giugno (fine finestra) è escluso, il 1 marzo (inizio) incluso.
        self::assertSame(['2026-03-01', '2026-04-01', '2026-05-01'], self::formatted($charges));
    }

    /** @return iterable<string, array{RecurringPeriod, string}> */
    public static function windowEquivalenceCases(): iterable
    {
        foreach (RecurringPeriod::cases() as $period) {
            foreach (['1999-01-31', '2000-02-29', '2024-03-01', '2025-12-31', '2026-05-15'] as $start) {
                yield $period->value.' da '.$start => [$period, $start];
            }
        }
    }

    /**
     * La finestra salta direttamente al primo addebito utile invece di generare tutta la storia: il risultato deve
     * restare identico a "tutti gli addebiti, poi filtra", per ogni cadenza, per inizi a fine mese e bisestili,
     * e per finestre prima, dentro e dopo la storia.
     */
    #[DataProvider('windowEquivalenceCases')]
    public function testWindowSkipGivesTheSameChargesAsFilteringTheWholeHistory(RecurringPeriod $period, string $start): void
    {
        $today = self::d('2026-06-30');
        $startDate = self::d($start);
        $all = $this->schedule->chargesUpTo($period, $startDate, $today);

        foreach (['1990-01-01', '2000-02-29', '2010-07-31', '2024-03-01', '2025-01-31', '2026-02-28', '2026-06-01', '2027-01-01'] as $from) {
            foreach (['+1 month', '+9 months', '+7 years'] as $span) {
                $fromDate = self::d($from);
                $untilDate = $fromDate->modify($span);
                $expected = array_values(array_filter(
                    $all,
                    static fn (\DateTimeImmutable $d): bool => $d >= $fromDate && $d < $untilDate,
                ));

                self::assertSame(
                    self::formatted($expected),
                    self::formatted($this->schedule->chargesInWindow($period, $startDate, $fromDate, $untilDate, $today)),
                    sprintf('%s da %s, finestra %s +%s', $period->value, $start, $from, $span),
                );
            }
        }
    }

    public function testWindowSkipKeepsTheOccurrenceCapForPathologicalStarts(): void
    {
        // Settimanale da 1834: l'addebito 9999 cade nel 2026-01 circa; oltre il tetto non si genera niente.
        $start = self::d('1700-01-01');
        $from = self::d('2020-01-01');
        $today = self::d('2026-06-30');

        $all = $this->schedule->chargesUpTo(RecurringPeriod::WEEKLY, $start, $today);
        self::assertCount(RecurringSchedule::MAX_OCCURRENCES, $all);
        self::assertSame(
            [],
            $this->schedule->chargesInWindow(RecurringPeriod::WEEKLY, $start, $from, $today->modify('+1 day'), $today),
            'La finestra recente cade oltre il tetto: nessun addebito, come prima',
        );
    }

    public function testWindowAfterTodayAndUntilStillClipCharges(): void
    {
        $charges = $this->schedule->chargesInWindow(
            RecurringPeriod::MONTHLY,
            self::d('2026-01-15'),
            self::d('2026-01-01'),
            self::d('2027-01-01'),
            self::d('2026-10-05'),
            self::d('2026-06-30'),
        );

        self::assertSame(['2026-01-15', '2026-02-15', '2026-03-15', '2026-04-15', '2026-05-15', '2026-06-15'], self::formatted($charges));
    }

    public function testWindowBeforeTheStartIsEmpty(): void
    {
        $charges = $this->schedule->chargesInWindow(
            RecurringPeriod::MONTHLY,
            self::d('2026-06-01'),
            self::d('2026-01-01'),
            self::d('2026-06-01'),
            self::d('2026-12-31'),
        );

        self::assertSame([], $charges);
    }

    public function testWindowAfterTodayIsEmpty(): void
    {
        $charges = $this->schedule->chargesInWindow(
            RecurringPeriod::WEEKLY,
            self::d('2026-01-01'),
            self::d('2026-11-01'),
            self::d('2026-12-01'),
            self::d('2026-10-05'),
        );

        self::assertSame([], $charges);
    }

    public function testOccurrencesAreCappedForPathologicalData(): void
    {
        // Dal 1100 con cadenza settimanale ci sarebbero ~46.000 addebiti: ne escono al massimo MAX_OCCURRENCES.
        $charges = $this->schedule->chargesUpTo(RecurringPeriod::WEEKLY, self::d('1100-01-01'), self::d('2026-10-05'));

        self::assertCount(RecurringSchedule::MAX_OCCURRENCES, $charges);
        self::assertSame('1100-01-01', $charges[0]->format('Y-m-d'));
        self::assertSame('1100-01-08', $charges[1]->format('Y-m-d'));
    }

    public function testCapAlsoBoundsMonthBasedPeriods(): void
    {
        $charges = $this->schedule->chargesUpTo(RecurringPeriod::MONTHLY, self::d('0100-01-01'), self::d('2026-10-05'));

        self::assertCount(RecurringSchedule::MAX_OCCURRENCES, $charges);
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function addMonthsCases(): iterable
    {
        yield 'revisione ogni 2 anni' => ['2026-11-30', 24, '2028-11-30'];
        yield '31 gennaio + 1 in anno comune' => ['2027-01-31', 1, '2027-02-28'];
        yield '31 gennaio + 1 in anno bisestile' => ['2028-01-31', 1, '2028-02-29'];
        yield '29 febbraio + 24' => ['2028-02-29', 24, '2030-02-28'];
        yield '31 agosto + 6' => ['2026-08-31', 6, '2027-02-28'];
        yield 'cambio d\'anno' => ['2026-12-15', 1, '2027-01-15'];
        yield 'zero mesi' => ['2026-05-31', 0, '2026-05-31'];
    }

    #[DataProvider('addMonthsCases')]
    public function testAddMonthsClampsTheDayToTheEndOfTheTargetMonth(string $date, int $months, string $expected): void
    {
        self::assertSame($expected, $this->schedule->addMonths(self::d($date), $months)->format('Y-m-d'));
    }

    private static function d(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    /**
     * @param list<\DateTimeImmutable> $dates
     * @return list<string>
     */
    private static function formatted(array $dates): array
    {
        return array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $dates);
    }
}
