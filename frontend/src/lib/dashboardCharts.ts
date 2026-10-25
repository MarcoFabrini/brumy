import type { DashboardCharts } from '@/api/types/dashboardCharts';

/**
 * Trasformazioni pure dalla risposta di `GET /api/dashboard/charts` alle righe dei grafici:
 * stringhe decimali → number (recharts lavora su numeri), etichetta del mese già formattata.
 */

type MonthFormatter = (yyyyMm: string) => string;

/** Serie mensili di spesa, una per sezione di costo, nell'ordine dell'API. */
export const SPENDING_SERIES = ['refuelings', 'scheduled', 'unscheduled', 'inspection', 'taxes', 'insurance', 'expenses'] as const;
export type SpendingSeries = (typeof SPENDING_SERIES)[number];

export type MonthlySpendingRow = {
  month: string;
  label: string;
  total: number;
} & Record<SpendingSeries, number>;

export interface KmDrivenRow {
  month: string;
  label: string;
  km: number;
}

export interface ConsumptionRow {
  month: string;
  label: string;
  /** Carburante → km/l (null = nessun intervallo tra pieni chiuso nel mese). */
  values: Record<string, number | null>;
}

export interface CategorySlice {
  /** Una sezione (`fuel`, `scheduled`, `tax`…), una categoria delle altre spese o {@link REST_CATEGORY}. */
  category: string;
  /** Nome della categoria di spesa; null per le sezioni e {@link REST_CATEGORY}. */
  name: string | null;
  amount: number;
}

/** Voce che raccoglie le categorie oltre le prime {@link MAX_CATEGORY_SLICES}. */
export const REST_CATEGORY = 'rest';
export const MAX_CATEGORY_SLICES = 5;

export function toMonthlySpendingRows(charts: DashboardCharts, formatLabel: MonthFormatter): MonthlySpendingRow[] {
  return charts.months.map(({ month, spending }) => ({
    month,
    label: formatLabel(month),
    refuelings: Number(spending.refuelings),
    scheduled: Number(spending.scheduled),
    unscheduled: Number(spending.unscheduled),
    inspection: Number(spending.inspection),
    taxes: Number(spending.taxes),
    insurance: Number(spending.insurance),
    expenses: Number(spending.expenses),
    total: Number(spending.total),
  }));
}

export function toKmDrivenRows(charts: DashboardCharts, formatLabel: MonthFormatter): KmDrivenRow[] {
  return charts.months.map(({ month, kmDriven }) => ({ month, label: formatLabel(month), km: kmDriven }));
}

/** Una riga per mese con un valore per ogni carburante di `fuelTypes` (null se manca nel mese). */
export function toConsumptionRows(charts: DashboardCharts, formatLabel: MonthFormatter): ConsumptionRow[] {
  return charts.months.map(({ month, consumption }) => ({
    month,
    label: formatLabel(month),
    values: Object.fromEntries(charts.fuelTypes.map((fuel) => [fuel, consumption[fuel] ?? null])),
  }));
}

/**
 * Fette della torta: le prime {@link MAX_CATEGORY_SLICES} categorie per importo (il backend le
 * ordina già), le altre sommate in un'unica voce {@link REST_CATEGORY}, così la legenda resta
 * leggibile anche su mobile.
 */
export function groupCategories(
  items: DashboardCharts['spendingByCategory'],
  maxSlices = MAX_CATEGORY_SLICES,
): CategorySlice[] {
  const slices = items
    .map(({ category, name, amount }) => ({ category, name, amount: Number(amount) }))
    .sort((a, b) => b.amount - a.amount || a.category.localeCompare(b.category));
  if (slices.length <= maxSlices) return slices;

  const rest = slices.slice(maxSlices).reduce((sum, slice) => sum + slice.amount, 0);
  return [...slices.slice(0, maxSlices), { category: REST_CATEGORY, name: null, amount: Math.round(rest * 100) / 100 }];
}

// ----- serie vuote: la card mostra un messaggio invece di un grafico piatto -----

export function hasSpending(rows: MonthlySpendingRow[]): boolean {
  return rows.some((row) => row.total !== 0);
}

export function hasKmDriven(rows: KmDrivenRow[]): boolean {
  return rows.some((row) => row.km !== 0);
}

export function hasConsumption(rows: ConsumptionRow[]): boolean {
  return rows.some((row) => Object.values(row.values).some((value) => value !== null));
}
