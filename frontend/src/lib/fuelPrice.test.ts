import { describe, expect, it } from 'vitest';
import type { FuelStation } from '@/api/types/fuelPrice';
import { communicatedWhen, isRecentForFuelPrice, pricedMode, sameValueName, withLivePrices } from './fuelPrice';

const station = (id: number, self: string, distanceKm: number | null): FuelStation => ({
  id,
  label: `Q8 ${id}`,
  brand: 'Q8',
  address: 'Via Roma 1',
  city: 'Modena',
  province: 'MO',
  distanceKm,
  prices: { self: { price: self, communicatedAt: '2026-11-04T07:30:00+01:00' }, served: null },
});

describe('withLivePrices', () => {
  it('mette i prezzi in tempo reale e tiene il resto, distanza compresa', () => {
    const merged = withLivePrices([station(1, '1.799', 0.4), station(2, '1.749', 1.2)], [station(1, '1.709', null)]);
    expect(merged.map((s) => [s.id, s.prices.self?.price, s.distanceKm])).toEqual([
      [1, '1.709', 0.4],
      [2, '1.749', 1.2],
    ]);
  });
});

describe('communicatedWhen', () => {
  const now = new Date(2026, 10, 5, 10, 0);
  const at = (day: number, h: number, m: number) => new Date(2026, 10, day, h, m).toISOString();

  it('oggi e ieri con l\'ora, prima solo la data', () => {
    expect(communicatedWhen(at(5, 7, 12), 'it-IT', now)).toEqual({ day: 'today', time: expect.stringMatching(/^0?7:12$/) });
    expect(communicatedWhen(at(4, 18, 30), 'it-IT', now)).toEqual({ day: 'yesterday', time: '18:30' });
    expect(communicatedWhen(at(3, 18, 30), 'it-IT', now).day).toBe('earlier');
  });

  it('una data senza ora o non valida è una data e basta', () => {
    expect(communicatedWhen('2026-11-05', 'it-IT', now).day).toBe('earlier');
    expect(communicatedWhen('boh', 'it-IT', now).day).toBe('earlier');
  });
});

describe('isRecentForFuelPrice', () => {
  const now = new Date(2026, 9, 26, 9, 0);

  it('vale per oggi e per ieri', () => {
    expect(isRecentForFuelPrice('2026-10-26', now)).toBe(true);
    expect(isRecentForFuelPrice('2026-10-25', now)).toBe(true);
  });

  it('non vale per giorni prima, per il futuro o senza data', () => {
    expect(isRecentForFuelPrice('2026-10-24', now)).toBe(false);
    expect(isRecentForFuelPrice('2026-10-27', now)).toBe(false);
    expect(isRecentForFuelPrice(undefined, now)).toBe(false);
  });

  it('a cavallo del mese', () => {
    expect(isRecentForFuelPrice('2026-10-31', new Date(2026, 10, 1, 8, 0))).toBe(true);
  });
});

describe('sameValueName', () => {
  it('ignora maiuscole e spazi, come il server', () => {
    expect(sameValueName('Q8  sotto casa ', 'q8 sotto casa')).toBe(true);
    expect(sameValueName('Q8', 'Q8 Via Roma')).toBe(false);
    expect(sameValueName('', '  ')).toBe(false);
  });
});

describe('pricedMode', () => {
  const point = { price: '1.729', communicatedAt: '2026-10-24' };

  it('tiene la scelta quando il distributore ha quel prezzo', () => {
    expect(pricedMode({ self: point, served: point }, 'served')).toBe('served');
    expect(pricedMode({ self: point, served: null }, 'self')).toBe('self');
  });

  it("passa all'altro prezzo quando manca quello scelto (GPL solo al servito)", () => {
    expect(pricedMode({ self: null, served: point }, 'self')).toBe('served');
    expect(pricedMode({ self: point, served: null }, 'served')).toBe('self');
  });

  it('senza nessun prezzo resta la scelta', () => {
    expect(pricedMode({ self: null, served: null }, 'self')).toBe('self');
  });
});
