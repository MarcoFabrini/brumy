import { describe, expect, it } from 'vitest';
import type { DashboardCharts } from '@/api/types/dashboardCharts';
import { monthSpending } from './monthSpending';

const month = (m: string, total: string, km = 0) => ({
  month: m,
  spending: { refuelings: total, maintenances: '0.00', expenses: '0.00', total },
  kmDriven: km,
  consumption: {},
});
const charts = (months: ReturnType<typeof month>[]): DashboardCharts => ({
  from: '2026-01',
  to: '2026-10',
  fuelTypes: [],
  months,
  spendingByCategory: [],
  totals: { spending: '0.00', kmDriven: 0 },
});

describe('monthSpending', () => {
  const now = new Date('2026-10-22T10:00:00');

  it('prende il mese corrente e il totale del mese prima', () => {
    const res = monthSpending(charts([month('2026-09', '120.50'), month('2026-10', '61.25', 610)]), now);
    expect(res).toMatchObject({ month: '2026-10', total: 61.25, refuelings: 61.25, kmDriven: 610, previousTotal: 120.5 });
  });

  it('un mese senza movimenti vale zero', () => {
    expect(monthSpending(charts([month('2026-09', '120.50')]), now)).toMatchObject({ total: 0, previousTotal: 120.5 });
  });

  it('gennaio confronta con dicembre dell\'anno prima', () => {
    const res = monthSpending(charts([month('2025-12', '10.00'), month('2026-01', '20.00')]), new Date('2026-01-15T10:00:00'));
    expect(res).toMatchObject({ month: '2026-01', total: 20, previousTotal: 10 });
  });
});
