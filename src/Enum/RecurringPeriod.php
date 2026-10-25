<?php

declare(strict_types=1);

namespace App\Enum;

enum RecurringPeriod: string
{
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';
    case QUARTERLY = 'quarterly';
    /** Semestrale (es. assicurazione frazionata) */
    case SEMIANNUAL = 'semiannual';
    case YEARLY = 'yearly';
    /** Biennale (es. revisione MCTC) */
    case BIENNIAL = 'biennial';

    /** Mesi del periodo; null per la cadenza settimanale, che non si esprime in mesi. */
    public function months(): ?int
    {
        return match ($this) {
            self::WEEKLY => null,
            self::MONTHLY => 1,
            self::QUARTERLY => 3,
            self::SEMIANNUAL => 6,
            self::YEARLY => 12,
            self::BIENNIAL => 24,
        };
    }
}
