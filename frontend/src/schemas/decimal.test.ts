import { describe, it, expect } from 'vitest';
import { refuelingSchema } from './refueling.schema';
import { expenseSchema } from './expense.schema';
import { maintenanceSchema } from './maintenance.schema';
import { normalizeDecimal, parseDecimal } from '@/lib/decimal';

const refueling = { vehicleId: 1, refueledAt: '2026-09-25', km: 100, fuelType: 'diesel', fullTank: true };

describe('normalizeDecimal / parseDecimal', () => {
  it('virgola → punto, trim', () => {
    expect(normalizeDecimal(' 45,50 ')).toBe('45.50');
    expect(parseDecimal('1,859')).toBe(1.859);
    expect(parseDecimal('12.5')).toBe(12.5);
  });
  it('vuoto o non numerico → NaN', () => {
    expect(parseDecimal('')).toBeNaN();
    expect(parseDecimal('abc')).toBeNaN();
  });
});

describe('schemi con importi decimali: la virgola della tastiera italiana è accettata', () => {
  it('rifornimento: "42,5" e "1,859" passano e escono col punto (formato backend)', () => {
    const r = refuelingSchema.safeParse({ ...refueling, liters: '42,5', pricePerLiter: '1,859' });
    expect(r.success).toBe(true);
    if (r.success) {
      expect(r.data.liters).toBe('42.5');
      expect(r.data.pricePerLiter).toBe('1.859');
    }
  });
  it('rifornimento: il punto continua a funzionare', () => {
    const r = refuelingSchema.safeParse({ ...refueling, liters: '42.5', pricePerLiter: '1.859' });
    expect(r.success && r.data.liters).toBe('42.5');
  });
  it('rifornimento: rifiuta testo, separatori doppi e troppe cifre decimali', () => {
    for (const bad of ['abc', '4,5,0', '1,2345', '', ',5', '-3']) {
      expect(refuelingSchema.safeParse({ ...refueling, liters: bad, pricePerLiter: '1,8' }).success).toBe(false);
    }
  });
  it('spesa: importo con virgola (max 2 decimali)', () => {
    const base = { vehicleId: 1, occurredAt: '2026-09-25', section: 'other', category: 'other', description: 'x', recurring: false };
    const ok = expenseSchema.safeParse({ ...base, amount: '120,90' });
    expect(ok.success && ok.data.amount).toBe('120.90');
    expect(expenseSchema.safeParse({ ...base, amount: '120,999' }).success).toBe(false);
  });
  it('manutenzione: costo opzionale, con virgola quando presente', () => {
    const base = {
      vehicleId: 1,
      performedAt: '2026-09-25',
      km: 10,
      type: 'oil_change',
      category: 'scheduled',
      description: 'Tagliando',
      components: [],
    };
    const ok = maintenanceSchema.safeParse({ ...base, cost: '89,5' });
    expect(ok.success && ok.data.cost).toBe('89.5');
    expect(maintenanceSchema.safeParse({ ...base, cost: null }).success).toBe(true);
    expect(maintenanceSchema.safeParse(base).success).toBe(true);
  });
});

describe('limiti allineati al backend (DecimalString)', () => {
  const refuelingBase = { ...refueling, pricePerLiter: '1,8' };

  it('litri e prezzo: zero e valori più grandi della colonna sono rifiutati sul campo', () => {
    expect(refuelingSchema.safeParse({ ...refuelingBase, liters: '0' }).success).toBe(false);
    expect(refuelingSchema.safeParse({ ...refuelingBase, liters: '100000' }).success).toBe(false); // 6 cifre intere
    expect(refuelingSchema.safeParse({ ...refuelingBase, liters: '99999,999' }).success).toBe(true);
    expect(refuelingSchema.safeParse({ ...refuelingBase, liters: '40', pricePerLiter: '100' }).success).toBe(false);
    expect(refuelingSchema.safeParse({ ...refuelingBase, liters: '40', pricePerLiter: '1,8595' }).success).toBe(true);
  });

  it('spesa: importo a zero rifiutato, manutenzione gratuita (costo 0) ammessa', () => {
    const expense = { vehicleId: 1, occurredAt: '2026-09-25', section: 'other', category: 'other', description: 'x', recurring: false };
    expect(expenseSchema.safeParse({ ...expense, amount: '0' }).success).toBe(false);
    expect(expenseSchema.safeParse({ ...expense, amount: '123456789' }).success).toBe(false); // 9 cifre intere

    const maintenance = {
      vehicleId: 1,
      performedAt: '2026-09-25',
      km: 10,
      type: 'oil_change',
      category: 'scheduled',
      description: 'Tagliando',
      components: [],
    };
    expect(maintenanceSchema.safeParse({ ...maintenance, cost: '0' }).success).toBe(true);
  });

  it('spesa non ricorrente: il periodo scelto e poi lasciato non viene inviato', () => {
    const r = expenseSchema.safeParse({
      vehicleId: 1,
      occurredAt: '2026-09-25',
      section: 'other',
      category: 'other',
      description: 'x',
      amount: '10',
      recurring: false,
      recurringPeriod: 'yearly',
    });
    expect(r.success && r.data.recurringPeriod).toBeNull();
  });

  it('chilometri oltre il massimo del backend rifiutati', () => {
    expect(refuelingSchema.safeParse({ ...refuelingBase, liters: '40', km: 10_000_000 }).success).toBe(false);
    expect(refuelingSchema.safeParse({ ...refuelingBase, liters: '40', km: 9_999_999 }).success).toBe(true);
  });
});
