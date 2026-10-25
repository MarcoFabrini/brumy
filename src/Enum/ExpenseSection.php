<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Sezione di una spesa: dice quale elenco personale fa da categoria.
 * Tasse → tipo di tassa (obbligatorio), assicurazione → compagnia (facoltativa), altre → categoria (obbligatoria).
 */
enum ExpenseSection: string
{
    case TAX = 'tax';
    case INSURANCE = 'insurance';
    case OTHER = 'other';
}
