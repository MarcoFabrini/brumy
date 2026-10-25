<?php

declare(strict_types=1);

namespace App\Enum;

/** Tipi di tassa di partenza: la loro chiave è lo `starter_key` delle voci `tax_type` create per l'utente. */
enum TaxType: string
{
    case ROAD_TAX = 'road_tax';
    case SUPER_TAX = 'super_tax';
    case OWNERSHIP_TRANSFER = 'ownership_transfer';
    case OTHER = 'other';

    /** Nome iniziale della voce nella lingua dell'utente ('it' o 'en'; le altre ricadono sull'italiano). */
    public function label(string $locale): string
    {
        return match ($locale) {
            'en' => match ($this) {
                self::ROAD_TAX => 'Road tax',
                self::SUPER_TAX => 'Super tax',
                self::OWNERSHIP_TRANSFER => 'Ownership transfer',
                self::OTHER => 'Other tax',
            },
            default => match ($this) {
                self::ROAD_TAX => 'Bollo',
                self::SUPER_TAX => 'Superbollo',
                self::OWNERSHIP_TRANSFER => 'Passaggio di proprietà',
                self::OTHER => 'Altra tassa',
            },
        };
    }
}
