<?php

declare(strict_types=1);

namespace App\Service\Search;

/**
 * La ricerca di un elenco (`?q=` più gli `hint` tradotti dal frontend), con i limiti già applicati: ogni parola deve
 * corrispondere (AND), ognuna in un campo qualsiasi (OR, vedi {@see SearchTerm}). La applica ai repository
 * {@see \App\Repository\Search\ListSearchFilter}.
 *
 * Contratto con il frontend (`frontend/src/lib/listSearch.ts`): stessa tokenizzazione, così l'indice `i` di
 * `hint[i][]` è la stessa parola da entrambe le parti.
 */
final readonly class ListSearch
{
    /** Caratteri considerati di `q` (gli altri si ignorano). */
    public const MAX_LENGTH = 100;

    /** Parole considerate (le successive si ignorano). */
    public const MAX_TERMS = 6;

    /** Hint considerati per parola (gli altri si ignorano). */
    public const MAX_HINTS_PER_TERM = 30;

    public function __construct(
        /** @var non-empty-list<SearchTerm> */
        public array $terms,
    ) {
    }

    /**
     * @param array<mixed> $hints `hint` della query string: indice della parola → lista di `famiglia:valore`
     *
     * @return self|null null se non c'è nessuna parola: nessun filtro
     */
    public static function fromQuery(string $q, array $hints = []): ?self
    {
        $q = mb_substr(trim($q), 0, self::MAX_LENGTH);
        $words = \array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, self::MAX_TERMS);

        $terms = [];
        foreach ($words as $i => $word) {
            $terms[] = SearchTerm::parse($word, self::hintsOf($hints[$i] ?? null));
        }

        return $terms === [] ? null : new self($terms);
    }

    /** @return list<string> */
    private static function hintsOf(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            \array_slice($raw, 0, self::MAX_HINTS_PER_TERM),
            static fn (mixed $hint): bool => \is_string($hint),
        ));
    }
}
