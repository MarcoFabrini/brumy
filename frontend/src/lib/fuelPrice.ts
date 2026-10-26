import { todayIso } from './format';

/**
 * Il prezzo del giorno vale per un rifornimento di oggi o di ieri (il ministero pubblica i prezzi delle 8 e i gestori
 * li cambiano di rado); per date più vecchie sarebbe un prezzo sbagliato, quindi non si propone.
 */
export function isRecentForFuelPrice(isoDate: string | undefined, now: Date = new Date()): boolean {
  if (!isoDate) return false;
  const yesterday = new Date(now);
  yesterday.setDate(yesterday.getDate() - 1);
  return isoDate === todayIso(now) || isoDate === todayIso(yesterday);
}

/** Stesso nome di una voce dell'elenco: come l'unicità sul server, a meno di maiuscole e spazi. */
export function sameValueName(a: string, b: string): boolean {
  const key = (s: string) => s.trim().replace(/\s+/gu, ' ').toLowerCase();
  return key(a) !== '' && key(a) === key(b);
}
