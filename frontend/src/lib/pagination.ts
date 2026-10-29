import type { QueryKey } from '@tanstack/react-query';

/** Record per pagina degli elenchi paginati dal server (lavori, spese, rifornimenti): il `limit` di default dell'API. */
export const LIST_PAGE_SIZE = 20;

/** Pagina successiva di `useInfiniteQuery`: nessuna quando l'ultima è arrivata incompleta. */
export function nextListPage(lastPage: readonly unknown[], allPages: readonly unknown[]): number | undefined {
  return lastPage.length < LIST_PAGE_SIZE ? undefined : allPages.length + 1;
}

/** Parte di chiave per una ricerca: niente senza ricerca, così la chiave resta quella di sempre. */
export function searchKeyPart(search: string | undefined): readonly ['search', string] | readonly [] {
  return search ? (['search', search] as const) : ([] as const);
}

/**
 * `placeholderData` che tiene i dati precedenti solo se venivano dallo stesso elenco (chiave che inizia con `base`:
 * stesso veicolo e sezione), così mentre arriva il risultato di una ricerca si vede ancora il precedente, ma mai i
 * record di un altro veicolo.
 */
export function keepPreviousOf(base: QueryKey) {
  return <T>(previous: T | undefined, previousQuery: { queryKey: QueryKey } | undefined): T | undefined =>
    previousQuery && base.every((part, i) => previousQuery.queryKey[i] === part) ? previous : undefined;
}
