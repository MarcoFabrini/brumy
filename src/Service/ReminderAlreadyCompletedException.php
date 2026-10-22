<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Il promemoria risulta già completato quando si prova a completarlo (doppio click, richieste concorrenti):
 * vedi {@see ReminderCompletion}. Il controller la traduce in 409 `reminder.already_completed`.
 */
final class ReminderAlreadyCompletedException extends \RuntimeException
{
}
