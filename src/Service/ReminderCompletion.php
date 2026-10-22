<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Reminder;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Completamento di un promemoria con la data e i km EFFETTIVI del lavoro.
 *
 * Se il promemoria è ricorrente nasce, nella stessa transazione, un promemoria NUOVO con la scadenza
 * successiva calcolata dal completamento (non dalla scadenza vecchia): tagliando fatto a 9800 km con
 * ricorrenza 10000 → prossimo a 19800; revisione fatta il 30/11 ogni 24 mesi → 30/11 di due anni dopo.
 * Il predecessore resta completato con i suoi allegati e il suo stato di notifica: è lo storico.
 *
 * I km del completamento restano sul promemoria: non alimentano l'odometro del veicolo e non creano
 * una manutenzione.
 */
final class ReminderCompletion
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AppClock $clock,
        private readonly VehicleStatsService $stats,
        private readonly RecurringSchedule $schedule,
    ) {
    }

    /**
     * @param \DateTimeImmutable|null $completedOn data effettiva del lavoro; null = oggi ({@see AppClock})
     * @param int|null $completedKm km effettivi; null = odometro attuale se la ricorrenza è a km, altrimenti nessuno
     *
     * @return Reminder|null il promemoria successivo, o null se non è ricorrente
     *
     * @throws ReminderAlreadyCompletedException
     */
    public function complete(Reminder $reminder, ?\DateTimeImmutable $completedOn, ?int $completedKm): ?Reminder
    {
        return $this->em->wrapInTransaction(function () use ($reminder, $completedOn, $completedKm): ?Reminder {
            // Lock e rilettura: un doppio click o due richieste concorrenti non devono creare due successori.
            $this->em->lock($reminder, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($reminder);
            if ($reminder->isCompleted()) {
                throw new ReminderAlreadyCompletedException();
            }

            $on = $completedOn ?? $this->clock->today();
            // Il km di base del calcolo si salva sempre: il dato mostrato spiega la scadenza successiva.
            $km = $completedKm ?? ($reminder->getRecurrenceKm() !== null ? $this->stats->currentKm($reminder->getVehicle()) : null);

            $reminder->markCompleted($on, $km, new \DateTimeImmutable());

            $next = $reminder->isRecurring() ? $this->successor($reminder, $on, $km) : null;
            if ($next !== null) {
                $this->em->persist($next);
            }
            $this->em->flush();

            return $next;
        });
    }

    /**
     * Copia della configurazione con la scadenza avanzata solo nelle dimensioni ricorrenti: una dimensione
     * senza ricorrenza non si copia, altrimenti il successore nascerebbe già scaduto su quella.
     */
    private function successor(Reminder $done, \DateTimeImmutable $on, ?int $km): Reminder
    {
        $months = $done->getRecurrenceMonths();
        $everyKm = $done->getRecurrenceKm();

        return (new Reminder())
            ->setOrganization($done->getOrganization())
            ->setVehicle($done->getVehicle())
            ->setType($done->getType())
            ->setDescription($done->getDescription())
            ->setNotifyDaysBefore($done->getNotifyDaysBefore())
            ->setNotifyKmBefore($done->getNotifyKmBefore())
            ->setRecurrenceMonths($months)
            ->setRecurrenceKm($everyKm)
            ->setDueDate($months !== null ? $this->schedule->addMonths($on, $months) : null)
            ->setDueKm($everyKm !== null && $km !== null ? $km + $everyKm : null);
    }
}
