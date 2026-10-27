<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Codici di recupero della verifica in due passaggi: 12 caratteri dell'alfabeto Crockford base32 minuscolo (60 bit),
 * mostrati come `xxxx-xxxx-xxxx`. Si salvano solo come SHA-256 del codice normalizzato: con 60 bit di entropia un
 * hash veloce regge alla forza bruta offline come i token a 256 bit già salvati in SHA-256, mentre un hash lento
 * costerebbe fino a 10 verifiche lente a ogni tentativo sbagliato.
 *
 * Funzioni pure, usate dall'entity (verifica) e da {@see \App\Service\TwoFactorSetup} (generazione).
 */
final class BackupCode
{
    public const COUNT = 10;

    private const ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';
    private const LENGTH = 12;

    /** Un codice nuovo nel formato mostrato all'utente. */
    public static function generate(): string
    {
        $chars = '';
        for ($i = 0; $i < self::LENGTH; ++$i) {
            $chars .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
        }

        return implode('-', str_split($chars, 4));
    }

    /**
     * Hash del codice come digitato: minuscolo, senza spazi e trattini, con le sostituzioni di Crockford per i
     * caratteri che si confondono (o → 0, i/l → 1).
     */
    public static function hash(string $code): string
    {
        $normalized = strtr(preg_replace('/[\s-]+/', '', mb_strtolower($code)) ?? '', ['o' => '0', 'i' => '1', 'l' => '1']);

        return hash('sha256', $normalized);
    }
}
