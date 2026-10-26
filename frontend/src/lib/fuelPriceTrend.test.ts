import { describe, expect, it } from 'vitest';
import { defaultTrendFuel, priceFuelOf, seriesKey, toTrendRows } from './fuelPriceTrend';

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
  it('una riga per giorno, in ordine, con i prezzi self e servito di ogni distributore', () => {
    const rows = toTrendRows(
      [
        {
          valueId: 1,
          name: 'Q8',
          fuelStationId: 10,
          self: [{ day: '2026-10-24', price: '1.729' }, { day: '2026-10-26', price: '1.719' }],
          served: [{ day: '2026-10-24', price: '1.899' }],
        },
        { valueId: 2, name: 'Eni', fuelStationId: 20, self: [], served: [{ day: '2026-10-25', price: '0.749' }, { day: '2026-10-24', price: '0.759' }] },
        { valueId: 3, name: 'Tamoil', fuelStationId: 30, self: [], served: [] },
      ],
      (iso) => `g${iso.slice(8)}`,
    );

    expect(rows).toEqual([
      { day: '2026-10-24', label: 'g24', values: { '1:self': 1.729, '1:served': 1.899, '2:served': 0.759 } },
      { day: '2026-10-25', label: 'g25', values: { '2:served': 0.749 } },
      { day: '2026-10-26', label: 'g26', values: { '1:self': 1.719 } },
    ]);
    expect(seriesKey(2, 'served')).toBe('2:served');
  });
});
