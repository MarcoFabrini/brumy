<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Toglie dai messaggi di eccezione quello che non deve finire nei log aggregati: indirizzi email e URL
 * (un endpoint di push è un capability token del dispositivo). Gli errori di mailer e push service spesso
 * li citano nel testo.
 */
final class LogRedactor
{
    public static function scrub(string $message): string
    {
        $message = preg_replace('#https?://\S+#i', '[url]', $message) ?? $message;

        return preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email]', $message) ?? $message;
    }
}
