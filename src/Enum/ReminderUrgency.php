<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Quanto è vicina (o superata) la scadenza di un promemoria.
 *
 * Stesse regole del frontend (`reminderUrgency` in `frontend/src/api/types/reminder.ts`):
 * se cambiano qui, cambiano anche lì.
 */
enum ReminderUrgency: string
{
    case OK = 'ok';
    /** Entro `notifyDaysBefore` giorni, o entro `notifyKmBefore` km dalla scadenza a km. */
    case SOON = 'soon';
    case OVERDUE = 'overdue';

    public function rank(): int
    {
        return match ($this) {
            self::OK => 0,
            self::SOON => 1,
            self::OVERDUE => 2,
        };
    }

    public function worst(self $other): self
    {
        return $other->rank() > $this->rank() ? $other : $this;
    }

    /** Notifica dovuta solo quando si SALE di livello rispetto all'ultima già mandata. */
    public function isEscalationFrom(?self $lastNotified): bool
    {
        return $this !== self::OK && $this->rank() > ($lastNotified?->rank() ?? 0);
    }
}
