<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Entity\Reminder;
use App\Enum\ReminderType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class ReminderRequest
{
    public function __construct(
        #[Assert\Positive]
        public int $vehicleId = 0,

        public ReminderType $type = ReminderType::CUSTOM,

        #[Assert\NotBlank]
        #[Assert\Length(max: 500)]
        public string $description = '',

        #[Assert\Date]
        public ?string $dueDate = null,

        #[Assert\Range(min: 0, max: 9_999_999, notInRangeMessage: 'common.km_too_large')]
        public ?int $dueKm = null,

        #[Assert\Range(min: 0, max: 365)]
        public int $notifyDaysBefore = 30,

        /** Anticipo dell'avviso per la scadenza a km. */
        #[Assert\Range(min: 0, max: 100_000)]
        public int $notifyKmBefore = Reminder::DEFAULT_NOTIFY_KM_BEFORE,

        /** Ricorrenza a tempo in mesi (12 = ogni anno); null = nessuna. */
        #[Assert\Range(min: 1, max: 120)]
        public ?int $recurrenceMonths = null,

        /** Ricorrenza a km; null = nessuna. */
        #[Assert\Positive]
        #[Assert\LessThanOrEqual(value: 9_999_999, message: 'common.km_too_large')]
        public ?int $recurrenceKm = null,

        #[Assert\Length(max: 2000)]
        public ?string $notes = null,
    ) {
    }

    /** Senza scadenza (data o km) il promemoria non scatterebbe mai. */
    #[Assert\Callback]
    public function validateHasDue(ExecutionContextInterface $context): void
    {
        if (($this->dueDate === null || $this->dueDate === '') && $this->dueKm === null) {
            $context->buildViolation('reminder.due_required')->atPath('dueDate')->addViolation();
        }
    }

    /** La ricorrenza fa avanzare una scadenza esistente: senza la prima non scatterebbe mai. */
    #[Assert\Callback]
    public function validateRecurrence(ExecutionContextInterface $context): void
    {
        if ($this->recurrenceMonths !== null && ($this->dueDate === null || $this->dueDate === '')) {
            $context->buildViolation('reminder.recurrence_months_requires_due_date')->atPath('recurrenceMonths')->addViolation();
        }
        if ($this->recurrenceKm !== null && $this->dueKm === null) {
            $context->buildViolation('reminder.recurrence_km_requires_due_km')->atPath('recurrenceKm')->addViolation();
        }
    }
}
