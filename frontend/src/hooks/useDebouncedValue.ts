import { useEffect, useState } from 'react';

/**
 * `value` in ritardo di `delayMs` dall'ultimo cambiamento (debounce). Il primo valore è quello di partenza, senza
 * attesa: tornando a una pagina con `?q=` la ricerca parte subito.
 */
export function useDebouncedValue<T>(value: T, delayMs: number): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delayMs);
    return () => clearTimeout(timer);
  }, [value, delayMs]);
  return debounced;
}
