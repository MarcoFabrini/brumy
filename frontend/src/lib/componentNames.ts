/** Chiave di confronto di un nome come sul server (`UserValue::keyOf()`): spazi normalizzati, minuscolo. */
export function nameKey(name: string): string {
  return name.trim().replace(/\s+/gu, ' ').toLowerCase();
}

/**
 * Componenti salvati con un lavoro che non c'erano prima, nell'ordine in cui sono stati scritti.
 *
 * @param saved componenti del lavoro come li restituisce il server
 * @param newNames nomi scritti nel modulo che il veicolo non aveva
 */
export function newComponentIds(saved: { id: number; name: string }[], newNames: string[]): number[] {
  const wanted = new Set(newNames.map(nameKey));
  return saved.filter((c) => wanted.has(nameKey(c.name))).map((c) => c.id);
}

/**
 * Dove andare dopo aver salvato un lavoro che ha creato componenti: la scheda del primo da completare, con in coda
 * gli altri (`next`) e la pagina a cui tornare alla fine (`back`). null se non ne ha creati.
 */
export function componentFollowUpPath(ids: number[], back: string): string | null {
  const [first, ...rest] = ids;
  if (first === undefined) return null;
  const query = new URLSearchParams({ back, ...(rest.length > 0 ? { next: rest.join(',') } : {}) });
  return `/components/${first}/edit?${query}`;
}
