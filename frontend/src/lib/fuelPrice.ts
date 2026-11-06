import type { FuelServiceMode, FuelStation } from '@/api/types/fuelPrice';
import { toLocalIsoDate, todayIso } from './format';

/**
 * Self o servito da usare per il prezzo: quello scelto se il distributore ce l'ha, altrimenti l'altro. GPL e metano si
 * vendono quasi sempre solo al servito, e col self scelto non comparirebbe nessun prezzo.
 */
export function pricedMode(prices: FuelStation['prices'], mode: FuelServiceMode): FuelServiceMode {
  const other = mode === 'self' ? 'served' : 'self';
  return !prices[mode] && prices[other] ? other : mode;
}

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

/**
 * I prezzi in tempo reale al posto di quelli del file, distributore per distributore; quelli senza risposta restano
 * com'erano (distanza compresa, che la risposta in tempo reale non ha).
 */
export function withLivePrices(stations: FuelStation[], live: FuelStation[]): FuelStation[] {
  const byId = new Map(live.map((s) => [s.id, s.prices]));
  return stations.map((s) => {
    const prices = byId.get(s.id);
    return prices ? { ...s, prices } : s;
  });
}

/**
 * Quando è stato comunicato un prezzo, rispetto a oggi sul dispositivo: oggi e ieri con l'ora ("oggi alle 7:12"),
 * prima solo la data. `time` è nel fuso del dispositivo.
 */
export function communicatedWhen(
  iso: string,
  locale: string,
  now: Date = new Date(),
): { day: 'today' | 'yesterday' | 'earlier'; time: string } {
  const at = new Date(iso);
  if (Number.isNaN(at.getTime()) || !iso.includes('T')) return { day: 'earlier', time: '' };
  const yesterday = new Date(now);
  yesterday.setDate(yesterday.getDate() - 1);
  const day = toLocalIsoDate(at) === toLocalIsoDate(now) ? 'today' : toLocalIsoDate(at) === toLocalIsoDate(yesterday) ? 'yesterday' : 'earlier';
  return { day, time: new Intl.DateTimeFormat(locale, { hour: 'numeric', minute: '2-digit' }).format(at) };
}

/** Stesso nome di una voce dell'elenco: come l'unicità sul server, a meno di maiuscole e spazi. */
export function sameValueName(a: string, b: string): boolean {
  const key = (s: string) => s.trim().replace(/\s+/gu, ' ').toLowerCase();
  return key(a) !== '' && key(a) === key(b);
}
