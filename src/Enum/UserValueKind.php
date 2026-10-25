<?php

declare(strict_types=1);

namespace App\Enum;

enum UserValueKind: string
{
    case STATION = 'station';
    case WORKSHOP = 'workshop';
    case SHOP = 'shop';
    case EXPENSE_CATEGORY = 'expense_category';
    case TAX_TYPE = 'tax_type';
    case INSURER = 'insurer';

    /**
     * Voci con cui parte una lista vuota di questo tipo, nella lingua dell'utente.
     *
     * @return array<string, string> starter_key => nome
     */
    public function starterNames(string $locale): array
    {
        $names = [];
        foreach (match ($this) {
            self::EXPENSE_CATEGORY => ExpenseCategory::starters(),
            self::TAX_TYPE => TaxType::cases(),
            default => [],
        } as $starter) {
            $names[$starter->value] = $starter->label($locale);
        }

        return $names;
    }
}
