import { EXPENSE_SECTIONS, RECURRING_PERIODS } from '@/api/types/expense';
import { MAINTENANCE_CATEGORIES, MAINTENANCE_TYPES } from '@/api/types/maintenance';
import { REMINDER_TYPES } from '@/api/types/reminder';
import { FUEL_TYPES, VEHICLE_TYPES } from '@/api/types/vehicle';

/**
 * Ricerca negli elenchi, lato client. Il server cerca testi, numeri e date da solo (`?q=`); le etichette tradotte
 * (categoria "Tagliando", "marzo") esistono solo qui, quindi per ogni parola si mandano come `hint[i][]` i valori
 * enum la cui etichetta, nella lingua dell'utente, contiene la parola e i mesi il cui nome inizia con essa.
 * Contratto con `src/Service/Search/ListSearch.php`: stessi limiti e stessa divisione in parole, così l'indice `i`
 * è la stessa parola da entrambe le parti.
 */

/** Caratteri considerati (come `ListSearch::MAX_LENGTH`). */
export const SEARCH_MAX_LENGTH = 100;
/** Parole considerate (come `ListSearch::MAX_TERMS`). */
export const SEARCH_MAX_TERMS = 6;
/** Ritardo tra l'ultimo tasto e la richiesta. */
export const SEARCH_DEBOUNCE_MS = 300;
/** Valori tradotti per parola (come `ListSearch::MAX_HINTS_PER_TERM`). */
const MAX_HINTS_PER_TERM = 30;
/** Lettere minime perché una parola cerchi tra le etichette ("ta" sì, "t" no). */
const MIN_LABEL_CHARS = 2;
/** Lettere minime perché una parola indichi un mese ("mar" → marzo, "ma" niente). */
const MIN_MONTH_CHARS = 3;

export const SEARCH_FAMILIES = [
  'maintenance_type',
  'maintenance_category',
  'expense_section',
  'recurring_period',
  'fuel_type',
  'vehicle_type',
  'reminder_type',
  'refueling_tank',
  'month',
] as const;
export type SearchFamily = (typeof SEARCH_FAMILIES)[number];

/** Famiglie di valori tradotti che ogni elenco conosce (lato server: `ListSearchFields` del repository). */
export const LIST_SEARCH_FAMILIES = {
  maintenance: ['maintenance_type', 'maintenance_category', 'month'],
  expense: ['expense_section', 'recurring_period', 'month'],
  refueling: ['fuel_type', 'refueling_tank', 'month'],
  reminder: ['reminder_type', 'month'],
  component: ['month'],
  vehicle: ['vehicle_type', 'fuel_type'],
} as const satisfies Record<string, readonly SearchFamily[]>;

/** Il `t` di i18next, ridotto a quello che serve qui (testabile senza React). */
export type Translate = (key: string, options?: { defaultValue: string }) => string;

/** Spazi di qualsiasi tipo → uno spazio, senza spazi ai bordi, tagliato a {@link SEARCH_MAX_LENGTH} caratteri. */
export function normalizeSearch(raw: string): string {
  const spaced = raw.replace(/\s+/gu, ' ').trim();
  // Array.from conta i caratteri come mb_substr (un'emoji è uno), non le unità UTF-16
  return Array.from(spaced).slice(0, SEARCH_MAX_LENGTH).join('').trimEnd();
}

/** Le parole cercate, come le divide il server: al massimo {@link SEARCH_MAX_TERMS}. */
export function searchTerms(q: string): string[] {
  return normalizeSearch(q).split(' ').filter(Boolean).slice(0, SEARCH_MAX_TERMS);
}

/** Per il confronto con le etichette: senza accenti e minuscolo ("Perché" → "perche"). */
export function foldText(text: string): string {
  return text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();
}

/** Valori di una famiglia con le etichette che li nominano nella lingua corrente (chiavi i18n esistenti). */
function familyLabels(family: Exclude<SearchFamily, 'month'>, t: Translate): Array<{ value: string; labels: string[] }> {
  switch (family) {
    case 'maintenance_type':
      return MAINTENANCE_TYPES.map((v) => ({ value: v, labels: [t(`maintenance.type_options.${v}`)] }));
    case 'maintenance_category':
      return MAINTENANCE_CATEGORIES.map((v) => ({
        value: v,
        labels: [t(`sections.${v}.title`), t(`maintenance.category_options.${v}`, { defaultValue: '' })],
      }));
    case 'expense_section':
      // Le "altre" spese hanno la sezione `expense` (come expenseKind in vehicleRecordActions)
      return EXPENSE_SECTIONS.map((v) => ({ value: v, labels: [t(`sections.${v === 'other' ? 'expense' : v}.title`)] }));
    case 'recurring_period':
      return RECURRING_PERIODS.map((v) => ({ value: v, labels: [t(`expense.recurring_options.${v}`)] }));
    case 'fuel_type':
      return FUEL_TYPES.map((v) => ({ value: v, labels: [t(`vehicle.fuel.${v}`)] }));
    case 'vehicle_type':
      return VEHICLE_TYPES.map((v) => ({ value: v, labels: [t(`vehicle.type_options.${v}`)] }));
    case 'reminder_type':
      return REMINDER_TYPES.map((v) => ({ value: v, labels: [t(`reminder.type_options.${v}`)] }));
    case 'refueling_tank':
      return [
        { value: 'full', labels: [t('refueling.full_tank')] },
        { value: 'partial', labels: [t('refueling.partial')] },
      ];
  }
}

/** Nomi dei mesi 1–12 nella lingua `locale` (dati CLDR del browser). */
function monthNames(locale: string): string[] {
  const format = new Intl.DateTimeFormat(locale, { month: 'long', timeZone: 'UTC' });
  return Array.from({ length: 12 }, (_, i) => format.format(new Date(Date.UTC(2000, i, 1))));
}

/** I valori `famiglia:valore` che una parola nomina, nell'ordine delle famiglie e dei valori. */
export function termHints(term: string, families: readonly SearchFamily[], t: Translate, locale: string): string[] {
  const folded = foldText(term);
  const hints: string[] = [];
  for (const family of families) {
    if (family === 'month') {
      if (folded.length < MIN_MONTH_CHARS) continue;
      monthNames(locale).forEach((name, i) => {
        if (foldText(name).startsWith(folded)) hints.push(`month:${i + 1}`);
      });
      continue;
    }
    if (folded.length < MIN_LABEL_CHARS) continue;
    for (const { value, labels } of familyLabels(family, t)) {
      if (labels.some((label) => label !== '' && foldText(label).includes(folded))) hints.push(`${family}:${value}`);
    }
  }
  return hints.slice(0, MAX_HINTS_PER_TERM);
}

/**
 * Query string della ricerca (`q=…&hint[0][]=…`), vuota se non c'è nessuna parola. Stessa ricerca → stessa stringa:
 * fa parte della chiave delle query.
 */
export function buildSearchQuery(q: string, families: readonly SearchFamily[], t: Translate, locale: string): string {
  const terms = searchTerms(q);
  if (terms.length === 0) return '';
  const params = new URLSearchParams({ q: terms.join(' ') });
  terms.forEach((term, i) => {
    for (const hint of termHints(term, families, t, locale)) params.append(`hint[${i}][]`, hint);
  });
  return params.toString();
}

/**
 * Il campo di ricerca si mostra se c'è una ricerca (anche mentre il ritardo non è passato) oppure se l'elenco non è
 * "caricato e vuoto": un elenco vuoto senza ricerca mostra solo il suo stato vuoto.
 */
export function shouldShowListSearch(query: string, term: string, items: readonly unknown[] | undefined): boolean {
  return query !== '' || term !== '' || items === undefined || items.length > 0;
}
