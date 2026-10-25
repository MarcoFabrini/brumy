<?php

declare(strict_types=1);

namespace App\Enum;

/** Sezione di un lavoro: ordinario (programmato), imprevisto, revisione. La revisione coincide con il tipo `inspection`. */
enum MaintenanceCategory: string
{
    case SCHEDULED = 'scheduled';
    case UNSCHEDULED = 'unscheduled';
    case INSPECTION = 'inspection';
}
