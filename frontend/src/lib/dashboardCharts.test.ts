import { describe, it, expect } from 'vitest';
import type { DashboardCharts } from '@/api/types/dashboardCharts';
import {
  groupCategories,
  hasConsumption,
  hasKmDriven,
  hasSpending,
  REST_CATEGORY,
  toConsumptionRows,
  toKmDrivenRows,
  toMonthlySpendingRows,
} from './dashboardCharts';

const zeroSpending = { refuelings: '0.00', scheduled: '0.00', unscheduled: '0.00', inspection: '0.00', taxes: '0.00', insurance: '0.00', expenses: '0.00', total: '0.00' };

const charts = (overrides: Partial<DashboardCharts> = {}): DashboardCharts => ({
  from: '2026-09',
  to: '2026-10',
  fuelTypes: ['diesel', 'lpg'],
  months: [
    {
      month: '2026-09',
      spending: { refuelings: '84.58', scheduled: '0.00', unscheduled: '0.00', inspection: '0.00', taxes: '0.00', insurance: '0.00', expenses: '650.00', total: '734.58' },
      kmDriven: 1200,
      consumption: { diesel: 15.38, lpg: null },
    },
    { month: '2026-10', spending: zeroSpending, kmDriven: 0, consumption: { diesel: null, lpg: null } },
  ],
  spendingByCategory: [
    { category: 'expense:1', name: 'Assicurazione', amount: '650.00' },
    { category: 'fuel', name: null, amount: '84.58' },
  ],
  totals: { spending: '734.58', kmDriven: 1200 },
  ...overrides,
});

const label = (month: string) => `L${month}`;

describe('righe dei grafici', () => {
  it('spese mensili: stringhe decimali → number, etichetta del mese', () => {
    expect(toMonthlySpendingRows(charts(), label)).toEqual([
      { month: '2026-09', label: 'L2026-09', refuelings: 84.58, scheduled: 0, unscheduled: 0, inspection: 0, taxes: 0, insurance: 0, expenses: 650, total: 734.58 },
      { month: '2026-10', label: 'L2026-10', refuelings: 0, scheduled: 0, unscheduled: 0, inspection: 0, taxes: 0, insurance: 0, expenses: 0, total: 0 },
    ]);
  });

  it('km percorsi per mese', () => {
    expect(toKmDrivenRows(charts(), label).map((r) => r.km)).toEqual([1200, 0]);
  });

  it('consumo: un valore per ogni carburante di fuelTypes, null se manca nel mese', () => {
    const data = charts({
      months: [{ month: '2026-09', spending: zeroSpending, kmDriven: 0, consumption: { diesel: 15.38 } }],
    });
    expect(toConsumptionRows(data, label)).toEqual([
      { month: '2026-09', label: 'L2026-09', values: { diesel: 15.38, lpg: null } },
    ]);
  });
});

describe('groupCategories', () => {
  it('fino a 5 categorie: tutte, per importo decrescente', () => {
    expect(groupCategories(charts().spendingByCategory)).toEqual([
      { category: 'expense:1', name: 'Assicurazione', amount: 650 },
      { category: 'fuel', name: null, amount: 84.58 },
    ]);
  });

  it('oltre 5: le prime 5 più "Altre categorie" con la somma delle restanti', () => {
    const items = [
      { category: 'fuel', name: null, amount: '500.00' },
      { category: 'expense:1', name: 'Assicurazione', amount: '400.00' },
      { category: 'maintenance', name: null, amount: '300.00' },
      { category: 'expense:2', name: 'Bollo', amount: '200.00' },
      { category: 'expense:3', name: 'Pedaggio', amount: '100.00' },
      { category: 'expense:4', name: 'Parcheggio', amount: '10.10' },
      { category: 'expense:5', name: 'Multa', amount: '20.20' },
    ];

    expect(groupCategories(items)).toEqual([
      { category: 'fuel', name: null, amount: 500 },
      { category: 'expense:1', name: 'Assicurazione', amount: 400 },
      { category: 'maintenance', name: null, amount: 300 },
      { category: 'expense:2', name: 'Bollo', amount: 200 },
      { category: 'expense:3', name: 'Pedaggio', amount: 100 },
      { category: REST_CATEGORY, name: null, amount: 30.3 },
    ]);
  });

  it('nessuna categoria → nessuna fetta', () => {
    expect(groupCategories([])).toEqual([]);
  });
});

describe('serie vuote', () => {
  const empty = charts({
    fuelTypes: ['diesel'],
    months: [{ month: '2026-10', spending: zeroSpending, kmDriven: 0, consumption: { diesel: null } }],
    spendingByCategory: [],
  });

  it('con dati', () => {
    expect(hasSpending(toMonthlySpendingRows(charts(), label))).toBe(true);
    expect(hasKmDriven(toKmDrivenRows(charts(), label))).toBe(true);
    expect(hasConsumption(toConsumptionRows(charts(), label))).toBe(true);
  });

  it('spese tutte a zero, km tutti a zero, consumo tutto null', () => {
    expect(hasSpending(toMonthlySpendingRows(empty, label))).toBe(false);
    expect(hasKmDriven(toKmDrivenRows(empty, label))).toBe(false);
    expect(hasConsumption(toConsumptionRows(empty, label))).toBe(false);
  });

  it('nessun carburante (nessun veicolo) → nessun consumo', () => {
    expect(hasConsumption(toConsumptionRows(charts({ fuelTypes: [] }), label))).toBe(false);
  });
});
