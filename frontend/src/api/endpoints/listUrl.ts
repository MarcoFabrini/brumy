/**
 * URL di un elenco: `path` + parametri (quelli `undefined` si omettono) + la query string della ricerca già pronta
 * (`q=…&hint[i][]=…`, da `buildSearchQuery`). Senza parametri né ricerca è il solo `path`, come prima.
 */
export function listUrl(path: string, params: Record<string, string | number | undefined>, search?: string): string {
  const query = new URLSearchParams();
  for (const [name, value] of Object.entries(params)) {
    if (value !== undefined) query.set(name, String(value));
  }
  const parts = [query.toString(), search ?? ''].filter(Boolean);
  return parts.length > 0 ? `${path}?${parts.join('&')}` : path;
}
