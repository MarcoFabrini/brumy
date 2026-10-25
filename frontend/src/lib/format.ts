/**
 * Formatters condivisi cross-feature.
 * Per evitare duplicazione delle string concat e Intl init.
 */

const NUMBER_FORMATS = new Map<string, Intl.NumberFormat>();

/** `Intl.NumberFormat` in cache per locale+opzioni: costruirlo è costoso e i formattatori girano a ogni render. */
function numberFormat(locale: string, options: Intl.NumberFormatOptions): Intl.NumberFormat {
  const key = `${locale}|${JSON.stringify(options)}`;
  let format = NUMBER_FORMATS.get(key);
  if (!format) {
    format = new Intl.NumberFormat(locale, options);
    NUMBER_FORMATS.set(key, format);
  }
  return format;
}

/** "12.345 km" (it-IT) / "12,345 km" (en-GB). */
export function formatKm(km: number, locale = 'it-IT'): string {
  return `${numberFormat(locale, { maximumFractionDigits: 0 }).format(km)} km`;
}

/** "8,4" — numero con al più `maxFractionDigits` decimali. Null → "—". */
export function formatDecimal(n: number | null | undefined, maxFractionDigits = 1, locale = 'it-IT'): string {
  if (n === null || n === undefined || Number.isNaN(n)) return '—';
  return numberFormat(locale, { maximumFractionDigits: maxFractionDigits }).format(n);
}

/** "1.234,50 €" — accetta decimal string ("123.45") o number. Null → "—". */
export function formatCurrency(amount: string | number | null | undefined, locale = 'it-IT', currency = 'EUR'): string {
  if (amount === null || amount === undefined || amount === '') return '—';
  const n = typeof amount === 'string' ? Number.parseFloat(amount) : amount;
  if (Number.isNaN(n)) return '—';
  return numberFormat(locale, { style: 'currency', currency }).format(n);
}

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Date "2026-05-27" → "27 mag 2026" (locale-aware).
 * Una data di calendario ("YYYY-MM-DD") non ha fuso: va formattata in UTC, altrimenti
 * in un fuso a ovest di Greenwich mostra il giorno prima. Un datetime resta locale.
 */
export function formatDate(iso: string | null | undefined, locale = 'it-IT'): string {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return new Intl.DateTimeFormat(locale, {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    ...(ISO_DATE.test(iso) && { timeZone: 'UTC' }),
  }).format(d);
}

/** Locale `Intl` della lingua dell'interfaccia (`i18n.language`): "en…" → en-GB, altrimenti it-IT. */
export function intlLocale(language: string): string {
  return language.startsWith('en') ? 'en-GB' : 'it-IT';
}

/** Lingua del profilo (`User['locale']`) per la lingua UI corrente: "en…" → 'en', altrimenti 'it'. */
export function profileLocale(language: string): 'it' | 'en' {
  return language.startsWith('en') ? 'en' : 'it';
}

const YEAR_MONTH = /^(\d{4})-(0[1-9]|1[0-2])$/;

/**
 * Mese di calendario "2026-05" → "mag 26" / "May 26" (etichette brevi degli assi dei grafici).
 * Come le date di calendario, formattato in UTC per non slittare al mese prima. Input non
 * valido restituito così com'è.
 */
export function formatMonth(yyyyMm: string, locale = 'it-IT'): string {
  const match = YEAR_MONTH.exec(yyyyMm);
  if (!match) return yyyyMm;
  const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, 1));
  return new Intl.DateTimeFormat(locale, { month: 'short', year: '2-digit', timeZone: 'UTC' }).format(date);
}

/** Giorno di calendario LOCALE "YYYY-MM-DD" (mai `toISOString`: è UTC e slitta a cavallo della mezzanotte). */
export function toLocalIsoDate(d: Date): string {
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return `${d.getFullYear()}-${mm}-${dd}`;
}

/** Data di oggi (locale) formato "YYYY-MM-DD" per default form. */
export function todayIso(now: Date = new Date()): string {
  return toLocalIsoDate(now);
}

/**
 * `iso` ("YYYY-MM-DD") più `months` mesi, col giorno ridotto all'ultimo del mese di arrivo se non esiste
 * (31 gen + 1 → 28/29 feb), come `RecurringSchedule::addMonths()` sul server. '' se `iso` non è una data.
 */
export function addMonthsIso(iso: string, months: number): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
  if (!match) return '';
  const [year, month, day] = [Number(match[1]), Number(match[2]) - 1 + months, Number(match[3])];
  const lastDay = new Date(year, month + 1, 0).getDate();
  return toLocalIsoDate(new Date(year, month, Math.min(day, lastDay)));
}

/**
 * Giorni di calendario da oggi (locale) a `iso` ("YYYY-MM-DD"): 0 = oggi, negativo = passata.
 * Confronta giorni interi, non istanti: una scadenza di oggi non è mai "scaduta".
 * Null se `iso` non è una data valida.
 */
export function daysUntil(iso: string, now: Date = new Date()): number | null {
  if (!ISO_DATE.test(iso)) return null;
  const [y = 0, m = 1, d = 1] = iso.split('-').map(Number);
  const due = Date.UTC(y, m - 1, d);
  const today = Date.UTC(now.getFullYear(), now.getMonth(), now.getDate());
  return Math.round((due - today) / 86_400_000);
}

/** Byte → "1,2 MB" / "340 KB" (base 1024, locale-aware). */
export function formatBytes(bytes: number, locale = 'it-IT'): string {
  if (bytes < 1024) return `${bytes} B`;
  const units = ['KB', 'MB', 'GB'];
  let value = bytes / 1024;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }
  const formatted = numberFormat(locale, { maximumFractionDigits: 1 }).format(value);
  return `${formatted} ${units[unit]}`;
}
