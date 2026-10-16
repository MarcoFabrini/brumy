<?php

declare(strict_types=1);

namespace App\Service\Push;

/**
 * Quali endpoint Web Push accettiamo. Il server fa una POST verso l'endpoint registrato dall'utente:
 * senza una lista chiusa potrebbe puntare a host della rete interna (SSRF cieco). Solo https, porta 443,
 * nessuna credenziale nell'URL e solo gli host dei push service che i browser usano davvero.
 * Controllo puro, senza I/O: lo usano la validazione del payload e l'invio (per le subscription già salvate).
 */
final class PushEndpointPolicy
{
    /** Host esatti: FCM (Chrome, Edge, Android) e Mozilla autopush (Firefox). */
    private const ALLOWED_HOSTS = [
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
    ];

    /** Suffissi con confine di punto: WNS (`wns2-par02p.notify.windows.com`) e Apple (`web.push.apple.com`). */
    private const ALLOWED_HOST_SUFFIXES = [
        'notify.windows.com',
        'push.apple.com',
    ];

    public static function isAllowed(?string $endpoint): bool
    {
        if ($endpoint === null || $endpoint === '') {
            return false;
        }

        $parts = parse_url($endpoint);
        if (!is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            // user O pass: `https://fcm.googleapis.com@evil.test/` e `https://user@fcm.googleapis.com/` sono entrambi trucchi
            || isset($parts['user'])
            || isset($parts['pass'])
            || ($parts['port'] ?? 443) !== 443
        ) {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');
        // Solo etichette DNS ASCII: backslash, percent-encoding e simili fanno leggere l'host in modo diverso
        // a parse_url e al client HTTP.
        if ($host === '' || preg_match('/^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$/D', $host) !== 1) {
            return false;
        }
        if (in_array($host, self::ALLOWED_HOSTS, true)) {
            return true;
        }
        foreach (self::ALLOWED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }
}
