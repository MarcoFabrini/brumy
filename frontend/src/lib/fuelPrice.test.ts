import { describe, expect, it } from 'vitest';
import { isRecentForFuelPrice, pricedMode, sameValueName } from './fuelPrice';

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
