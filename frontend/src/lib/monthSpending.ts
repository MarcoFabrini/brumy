import type { DashboardCharts } from '@/api/types/dashboardCharts';
import type { SpendingSeries } from '@/lib/dashboardCharts';

export interface MonthSpending {
  /** Mese di riferimento, `YYYY-MM`. */
  month: string;
  total: number;
  /** Spesa del mese per sezione di costo. */
  bySeries: Record<SpendingSeries, number>;
  kmDriven: number;
  /** Totale del mese prima (null se fuori dalla finestra dei grafici). */
  previousTotal: number | null;
}

const yearMonth = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;

/**
 * La spesa del mese corrente (e il totale del mese prima, per il confronto) dalla risposta dei grafici, che ha già
 * le somme mensili per sezione di costo. Un mese senza movimenti vale zero.
 */
export function monthSpending(charts: DashboardCharts, now: Date = new Date()): MonthSpending {
  const month = yearMonth(now);
  const previous = yearMonth(new Date(now.getFullYear(), now.getMonth() - 1, 1));
  const current = charts.months.find((m) => m.month === month);
  const before = charts.months.find((m) => m.month === previous);
  const n = (v: string | undefined) => Number.parseFloat(v ?? '0') || 0;

  return {
    month,
    total: n(current?.spending.total),
    bySeries: {
      refuelings: n(current?.spending.refuelings),
      scheduled: n(current?.spending.scheduled),
      unscheduled: n(current?.spending.unscheduled),
      inspection: n(current?.spending.inspection),
      taxes: n(current?.spending.taxes),
      insurance: n(current?.spending.insurance),
      expenses: n(current?.spending.expenses),
    },
    kmDriven: current?.kmDriven ?? 0,
    previousTotal: before ? n(before.spending.total) : null,
  };
}
