<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Expense;
use App\Entity\Maintenance;
use App\Entity\Reminder;
use App\Enum\ExpenseSection;
use App\Enum\MaintenanceCategory;
use App\Enum\ReminderType;
use App\Enum\TaxType;
use App\Repository\ReminderRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Prossima scadenza di un record periodico: revisione (ogni 24 mesi), assicurazione (il periodo della spesa, o
 * 12 mesi) e bollo/superbollo (12 mesi). Al salvataggio, con una data scelta dall'utente, il promemoria aperto
 * dello stesso tipo per il veicolo si segna fatto alla data del record ({@see ReminderCompletion}) e la scadenza
 * successiva diventa quella data; se non ce n'è, nasce un promemoria ricorrente con quella scadenza.
 * Il frontend propone la data con gli stessi intervalli.
 */
final class RecordReminders
{
    private const INSPECTION_MONTHS = 24;
    private const DEFAULT_MONTHS = 12;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReminderRepository $reminders,
        private readonly ReminderCompletion $completion,
    ) {
    }

    /** Solo per la revisione; per gli altri lavori non fa nulla. */
    public function forMaintenance(Maintenance $m, \DateTimeImmutable $nextDue): void
    {
        if ($m->getCategory() !== MaintenanceCategory::INSPECTION) {
            return;
        }
        $this->schedule($m, ReminderType::INSPECTION, $m->getDescription(), $m->getPerformedAt(), $m->getKm(), $nextDue, self::INSPECTION_MONTHS);
    }

    /** Assicurazione e tasse periodiche (bollo, superbollo); per le altre spese non fa nulla. */
    public function forExpense(Expense $e, \DateTimeImmutable $nextDue): void
    {
        $months = $e->isRecurring() ? ($e->getRecurringPeriod()?->months() ?? self::DEFAULT_MONTHS) : self::DEFAULT_MONTHS;
        if ($e->getSection() === ExpenseSection::INSURANCE) {
            $this->schedule($e, ReminderType::INSURANCE, $e->getDescription(), $e->getOccurredAt(), null, $nextDue, $months);

            return;
        }

        $taxType = $e->getSection() === ExpenseSection::TAX ? $e->getCategory() : null;
        $type = match ($taxType?->getStarterKey()) {
            TaxType::ROAD_TAX->value => ReminderType::ROAD_TAX,
            // Non un altro bollo: un promemoria personalizzato, che si riconosce dal nome del tipo di tassa
            TaxType::SUPER_TAX->value => ReminderType::CUSTOM,
            default => null,
        };
        if ($type !== null && $taxType !== null) {
            $this->schedule($e, $type, $taxType->getName(), $e->getOccurredAt(), null, $nextDue, $months);
        }
    }

    private function schedule(Maintenance|Expense $record, ReminderType $type, string $description, \DateTimeImmutable $doneOn, ?int $doneKm, \DateTimeImmutable $nextDue, int $months): void
    {
        $open = $this->reminders->findOpenOfType($record->getVehicle(), $type, $description);
        $next = null;
        if ($open !== null) {
            try {
                $next = $this->completion->complete($open, $doneOn, $doneKm);
            } catch (ReminderAlreadyCompletedException) {
                // completato nel frattempo da un'altra richiesta: si crea il nuovo qui sotto
            }
        }

        if ($next === null) {
            $next = (new Reminder())
                ->setOrganization($record->getOrganization())
                ->setVehicle($record->getVehicle())
                ->setType($type)
                ->setDescription($open?->getDescription() ?? $description)
                ->setRecurrenceMonths($months);
            $this->em->persist($next);
        }
        $next->setDueDate($nextDue);
        $this->em->flush();
    }
}
