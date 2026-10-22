<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\RecurringPeriod;

/**
 * Calendario degli addebiti di una spesa ricorrente, senza I/O: lo usano sia le statistiche del
 * singolo veicolo ({@see VehicleStatsService}) sia i grafici ({@see DashboardChartsService}).
 *
 * `occurredAt` è la data del PRIMO addebito (n = 0); l'addebito n cade a:
 * - settimanale: inizio + 7n giorni;
 * - mensile, trimestrale, semestrale, annuale, biennale: inizio + n × (1, 3, 6, 12, 24) mesi.
 * Le date a mesi si calcolano sempre dall'ANCORA (la data di inizio), mai dall'addebito precedente,
 * e il giorno si riduce all'ultimo del mese di arrivo: il 31 gennaio mensile dà 28/29 febbraio e poi
 * di nuovo 31 marzo (niente deriva), il 29 febbraio annuale dà il 28 negli anni non bisestili.
 *
 * Un addebito conta se la sua data è <= "oggi" e, se impostata, <= `recurringUntil` (inclusiva):
 * gli addebiti futuri non contano, come per gli altri record. Un tetto fisso di
 * {@see self::MAX_OCCURRENCES} addebiti per spesa protegge da dati patologici (una data di inizio
 * lontanissima con cadenza settimanale) che altrimenti farebbero girare il ciclo troppo a lungo.
 */
final class RecurringSchedule
{
    /** Massimo numero di addebiti generati per una singola spesa (n = 0 … MAX_OCCURRENCES - 1). */
    public const MAX_OCCURRENCES = 10000;

    /**
     * Date degli addebiti già avvenuti: dall'inizio a `min($today, $recurringUntil)`, estremi inclusi.
     *
     * @return list<\DateTimeImmutable>
     */
    public function chargesUpTo(
        RecurringPeriod $period,
        \DateTimeImmutable $start,
        \DateTimeImmutable $today,
        ?\DateTimeImmutable $recurringUntil = null,
    ): array {
        return $this->expand($period, $start, $today, $recurringUntil, 0);
    }

    /** @return list<\DateTimeImmutable> addebiti dall'indice $firstIndex fino a `min($today, $recurringUntil)` inclusi */
    private function expand(
        RecurringPeriod $period,
        \DateTimeImmutable $start,
        \DateTimeImmutable $today,
        ?\DateTimeImmutable $recurringUntil,
        int $firstIndex,
    ): array {
        $limit = $recurringUntil !== null && $recurringUntil < $today ? $recurringUntil : $today;

        $charges = [];
        for ($n = $firstIndex; $n < self::MAX_OCCURRENCES; ++$n) {
            $date = $this->charge($period, $start, $n);
            if ($date > $limit) {
                break; // le date crescono con n: oltre il limite non c'è più niente da contare
            }
            $charges[] = $date;
        }

        return $charges;
    }

    /**
     * Primo indice che può cadere dentro la finestra che inizia a `$from` (mai oltre il primo che ci cade davvero):
     * serve a non generare anni di addebiti già passati solo per scartarli. Il costo di una richiesta segue così
     * la finestra e non la lontananza della data di inizio.
     */
    private function firstIndexFrom(RecurringPeriod $period, \DateTimeImmutable $start, \DateTimeImmutable $from): int
    {
        if ($from <= $start) {
            return 0;
        }

        if ($period === RecurringPeriod::WEEKLY) {
            $steps = intdiv((int) $start->diff($from)->days, 7);
        } else {
            $monthIndex = static fn (\DateTimeImmutable $d): int => (int) $d->format('Y') * 12 + (int) $d->format('n') - 1;
            // Con il giorno ridotto a fine mese un addebito resta nello stesso mese di calendario: indice mensile esatto.
            $steps = intdiv($monthIndex($from) - $monthIndex($start), self::months($period));
        }

        // Un passo di margine: l'arrotondamento per difetto non deve mai saltare un addebito dentro la finestra.
        return max(0, $steps - 1);
    }

    /**
     * Come {@see self::chargesUpTo()}, ma solo gli addebiti con `$from <= data < $until` (fine esclusa,
     * come le finestre mensili dei grafici). Gli addebiti precedenti alla finestra vengono scartati.
     *
     * @return list<\DateTimeImmutable>
     */
    public function chargesInWindow(
        RecurringPeriod $period,
        \DateTimeImmutable $start,
        \DateTimeImmutable $from,
        \DateTimeImmutable $until,
        \DateTimeImmutable $today,
        ?\DateTimeImmutable $recurringUntil = null,
    ): array {
        return array_values(array_filter(
            $this->expand($period, $start, $today, $recurringUntil, $this->firstIndexFrom($period, $start, $from)),
            static fn (\DateTimeImmutable $date): bool => $date >= $from && $date < $until,
        ));
    }

    /** Data dell'addebito n-esimo (n = 0 è l'inizio), calcolata dall'ancora. */
    private function charge(RecurringPeriod $period, \DateTimeImmutable $start, int $n): \DateTimeImmutable
    {
        if ($period === RecurringPeriod::WEEKLY) {
            return $start->modify(sprintf('+%d days', 7 * $n));
        }

        return $this->addMonths($start, self::months($period) * $n);
    }

    /**
     * `$date` + `$months` mesi di calendario, con il giorno ridotto all'ultimo del mese di arrivo:
     * 31/01 + 1 → 28/02 (29/02 negli anni bisestili), 29/02 + 12 → 28/02. Lo usano anche i promemoria
     * ricorrenti per la scadenza successiva ({@see ReminderCompletion}).
     */
    public function addMonths(\DateTimeImmutable $date, int $months): \DateTimeImmutable
    {
        $total = (int) $date->format('Y') * 12 + ((int) $date->format('n') - 1) + $months;
        $year = intdiv($total, 12);
        $month = $total % 12 + 1;
        $lastDay = (int) $date->setDate($year, $month, 1)->format('t');

        return $date->setDate($year, $month, min((int) $date->format('j'), $lastDay));
    }

    /** Mesi tra due addebiti consecutivi di una cadenza a mesi. */
    private static function months(RecurringPeriod $period): int
    {
        return match ($period) {
            RecurringPeriod::MONTHLY => 1,
            RecurringPeriod::QUARTERLY => 3,
            RecurringPeriod::SEMIANNUAL => 6,
            RecurringPeriod::YEARLY => 12,
            RecurringPeriod::BIENNIAL => 24,
            RecurringPeriod::WEEKLY => throw new \LogicException('La cadenza settimanale non è a mesi.'),
        };
    }
}
