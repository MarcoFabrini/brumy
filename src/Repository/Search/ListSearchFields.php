<?php

declare(strict_types=1);

namespace App\Repository\Search;

/**
 * Dove cercare in un elenco: i campi DQL (con gli alias della query del repository) che una parola della ricerca può
 * soddisfare. Lo dichiara ogni repository per il proprio elenco; i nomi vengono solo dal codice, mai dall'input.
 */
final readonly class ListSearchFields
{
    /**
     * @param list<string> $text campi di testo e nomi collegati, cercati con `LIKE`
     * @param array<string, string> $joins alias → associazione a uno (`'workshop' => 'm.workshop'`) da unire con
     *                                     `leftJoin` solo se la query non ha già quell'alias
     * @param array<string, list<string>> $collections associazione a molti della radice → campi di testo delle sue
     *                                                 voci (`'components' => ['name']`), cercati con `EXISTS` per non
     *                                                 moltiplicare le righe
     * @param array<string, list<string>> $families famiglia di hint (enum o sì/no) → campi
     * @param string|null $date campo data: anno, mese/anno, giorno e mesi tradotti
     * @param string|null $amount campo importo (`DECIMAL(…, 2)`)
     * @param string|null $year campo anno intero (veicolo)
     * @param list<string> $km campi km (interi), confrontati per uguaglianza
     */
    public function __construct(
        public array $text,
        public array $joins = [],
        public array $collections = [],
        public array $families = [],
        public ?string $date = null,
        public ?string $amount = null,
        public ?string $year = null,
        public array $km = [],
    ) {
    }
}
