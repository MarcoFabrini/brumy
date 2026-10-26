import { describe, expect, it } from 'vitest';
import { defaultTrendFuel, priceFuelOf, toTrendRows } from './fuelPriceTrend';

describe('priceFuelOf', () => {
  it('porta ogni carburante a quello base del ministero', () => {
    expect(priceFuelOf('diesel')).toBe('diesel');
    expect(priceFuelOf('hybrid')).toBe('gasoline');
    expect(priceFuelOf('electric')).toBeNull();
  });
});

describe('defaultTrendFuel', () => {
  it('propone il carburante più frequente tra i veicoli propri', () => {
    expect(
      defaultTrendFuel([
        { fuelType: 'lpg', ownership: 'owned' },
        { fuelType: 'diesel', ownership: 'owned' },
        { fuelType: 'diesel', ownership: 'owned' },
        { fuelType: 'lpg', ownership: 'shared' },
        { fuelType: 'lpg', ownership: 'shared' },
      ]),
    ).toBe('diesel');
    expect(defaultTrendFuel([{ fuelType: 'hybrid', ownership: 'owned' }])).toBe('gasoline');
  });

  it('senza veicoli con un prezzo propone la benzina', () => {
    expect(defaultTrendFuel([])).toBe('gasoline');
    expect(defaultTrendFuel([{ fuelType: 'electric', ownership: 'owned' }])).toBe('gasoline');
  });
});

describe('toTrendRows', () => {
  it('una riga per giorno, in ordine, con i prezzi di ogni distributore', () => {
    const rows = toTrendRows(
      [
        { valueId: 1, name: 'Q8', fuelStationId: 10, points: [{ day: '2026-10-24', price: '1.729' }, { day: '2026-10-26', price: '1.719' }] },
        { valueId: 2, name: 'Eni', fuelStationId: 20, points: [{ day: '2026-10-25', price: '1.699' }, { day: '2026-10-24', price: '1.709' }] },
        { valueId: 3, name: 'Tamoil', fuelStationId: 30, points: [] },
      ],
      (iso) => `g${iso.slice(8)}`,
    );

    expect(rows).toEqual([
      { day: '2026-10-24', label: 'g24', values: { 1: 1.729, 2: 1.709 } },
      { day: '2026-10-25', label: 'g25', values: { 2: 1.699 } },
      { day: '2026-10-26', label: 'g26', values: { 1: 1.719 } },
    ]);
  });
});
