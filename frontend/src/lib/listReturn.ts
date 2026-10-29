import { safeNextPath } from '@/lib/safeNext';

/** `state` del link da una riga di elenco al dettaglio: da dove si è arrivati (path + query, ricerca compresa). */
export interface ListReturnState {
  from: string;
}

/**
 * Dove torna il "‹ Indietro" di un dettaglio: all'elenco da cui si è arrivati (con la sua ricerca), se `state.from`
 * è un path interno di quell'elenco (`listPathname`, es. `/maintenance`); altrimenti — link diretto, notifica,
 * arrivo da un'altra pagina — a `fallback`, l'elenco di sempre.
 */
export function listReturnPath(state: unknown, listPathname: string, fallback: string): string {
  if (typeof state !== 'object' || state === null || !('from' in state)) return fallback;
  const { from } = state;
  if (typeof from !== 'string' || safeNextPath(from) !== from) return fallback;
  const pathname = from.split(/[?#]/, 1)[0];
  return pathname === listPathname ? from : fallback;
}
