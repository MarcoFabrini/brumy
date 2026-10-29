import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router-dom';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { intlLocale } from '@/lib/format';
import { buildSearchQuery, normalizeSearch, SEARCH_DEBOUNCE_MS, type SearchFamily } from '@/lib/listSearch';

export interface ListSearch {
  /** Testo del campo: `?q=` dell'URL (unica fonte). */
  query: string;
  /** Aggiorna `?q=` senza toccare gli altri parametri e senza voci di cronologia; vuoto = toglie `q`. */
  setQuery: (value: string) => void;
  clear: () => void;
  /** Ricerca in corso, normalizzata e dopo il ritardo: vuota = nessuna ricerca. */
  term: string;
  /** Query string per l'API (`q=…&hint[i][]=…`), vuota senza ricerca. Parte della chiave delle query. */
  searchQuery: string;
}

/**
 * Ricerca di un elenco: testo in `?q=` (così resta tornando dal dettaglio), richiesta dopo {@link SEARCH_DEBOUNCE_MS}
 * dall'ultimo tasto, valori tradotti di `families` calcolati nella lingua corrente. `families` va passato come
 * costante di modulo (`LIST_SEARCH_FAMILIES.xxx`).
 */
export function useListSearch(families: readonly SearchFamily[]): ListSearch {
  const { t, i18n } = useTranslation();
  const [params, setParams] = useSearchParams();
  const query = params.get('q') ?? '';
  const normalized = normalizeSearch(query);
  // Svuotare vale subito; scrivere aspetta il ritardo (e non ripesca la ricerca precedente: parte da "nessuna")
  const debounced = useDebouncedValue(normalized, normalized === '' ? 0 : SEARCH_DEBOUNCE_MS);
  const term = normalized === '' ? '' : debounced;
  const locale = intlLocale(i18n.language);
  const searchQuery = useMemo(() => buildSearchQuery(term, families, t, locale), [term, families, t, locale]);

  const setQuery = (value: string) => {
    setParams(
      (prev) => {
        const next = new URLSearchParams(prev);
        if (value === '') next.delete('q');
        else next.set('q', value);
        return next;
      },
      // replace: nessuna voce di cronologia per tasto; flushSync: il campo legge l'URL, che deve aggiornarsi
      // nello stesso evento (in una transition il valore controllato resterebbe indietro mentre si scrive)
      { replace: true, flushSync: true },
    );
  };

  return { query, setQuery, clear: () => setQuery(''), term, searchQuery };
}
