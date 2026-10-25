<?php

declare(strict_types=1);

namespace App\Enum;

enum AttachmentEntityType: string
{
    case VEHICLE = 'vehicle';
    case MAINTENANCE = 'maintenance';
    case EXPENSE = 'expense';
    /** Foto scontrino benzina, ricevuta colonnina EV */
    case REFUELING = 'refueling';
    /** Foto documento scadenza (es. polizza, bollo pagato) */
    case REMINDER = 'reminder';
    /** Scontrino o scheda tecnica di un componente comprato */
    case COMPONENT = 'component';
}
