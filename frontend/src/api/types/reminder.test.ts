import { describe, it, expect } from 'vitest';
import {
  DEFAULT_NOTIFY_KM_BEFORE,
  recurrenceInterval,
  reminderUrgency,
  type Reminder,
} from './reminder';

const NOW = new Date(2026, 8, 25, 15, 0); // 25/09/2026 15:00 locale

function reminder(over: Partial<Reminder>): Reminder {
  return {
    id: 1,
    vehicleId: 1,
    type: 'inspection',
    description: 'Revisione',
    dueDate: null,
    dueKm: null,
    notifyDaysBefore: 7,
    notifyKmBefore: DEFAULT_NOTIFY_KM_BEFORE,
    recurrenceMonths: null,
    recurrenceKm: null,
    completedAt: null,
    completedOn: null,
    completedKm: null,
    ...over,
  };
}

describe('reminderUrgency — scadenza a data', () => {
  it('scade OGGI: "in scadenza", non scaduto (bug: il giorno stesso risultava scaduto)', () => {
    expect(reminderUrgency(reminder({ dueDate: '2026-09-25' }), { now: NOW })).toBe('soon');
    expect(reminderUrgency(reminder({ dueDate: '2026-09-25' }), { now: new Date(2026, 8, 25, 23, 59) })).toBe('soon');
  });
  it('scaduta ieri: overdue', () => {
    expect(reminderUrgency(reminder({ dueDate: '2026-09-24' }), { now: NOW })).toBe('overdue');
  });
  it('entro notifyDaysBefore: soon; oltre: ok', () => {
    expect(reminderUrgency(reminder({ dueDate: '2026-10-02' }), { now: NOW })).toBe('soon'); // +7
    expect(reminderUrgency(reminder({ dueDate: '2026-10-03' }), { now: NOW })).toBe('ok'); // +8
  });
  it('completato: done, qualunque sia la scadenza', () => {
    expect(
      reminderUrgency(reminder({ dueDate: '2020-01-01', completedAt: '2026-09-01T10:00:00+00:00' }), { now: NOW }),
    ).toBe('done');
  });
  it('senza data né km: ok', () => {
    expect(reminderUrgency(reminder({}), { now: NOW })).toBe('ok');
  });
});

describe('reminderUrgency — scadenza a chilometri (bug: i km erano ignorati)', () => {
  const km = (dueKm: number) => reminder({ dueKm });

  it('km oltre la soglia: overdue', () => {
    expect(reminderUrgency(km(100_000), { currentKm: 100_001, now: NOW })).toBe('overdue');
  });
  it('entro i 1000 km di default dalla soglia: soon (anche esattamente sulla soglia)', () => {
    expect(reminderUrgency(km(100_000), { currentKm: 100_000, now: NOW })).toBe('soon');
    expect(reminderUrgency(km(100_000), { currentKm: 99_000, now: NOW })).toBe('soon');
  });
  it('lontano dalla soglia: ok', () => {
    expect(reminderUrgency(km(100_000), { currentKm: 98_999, now: NOW })).toBe('ok');
  });
  it('usa notifyKmBefore del promemoria, come il backend', () => {
    const r = reminder({ dueKm: 100_000, notifyKmBefore: 500 });
    expect(reminderUrgency(r, { currentKm: 99_500, now: NOW })).toBe('soon');
    expect(reminderUrgency(r, { currentKm: 99_499, now: NOW })).toBe('ok');
    expect(reminderUrgency(r, { currentKm: 100_001, now: NOW })).toBe('overdue');
  });
  it('senza chilometraggio noto conta solo la data', () => {
    expect(reminderUrgency(km(100_000), { now: NOW })).toBe('ok');
    expect(reminderUrgency(km(100_000), { currentKm: null, now: NOW })).toBe('ok');
  });
  it('data e km insieme: vince il peggiore', () => {
    const r = reminder({ dueDate: '2026-12-31', dueKm: 100_000 }); // data lontana, km superati
    expect(reminderUrgency(r, { currentKm: 100_500, now: NOW })).toBe('overdue');
    const r2 = reminder({ dueDate: '2026-09-20', dueKm: 100_000 }); // data scaduta, km lontani
    expect(reminderUrgency(r2, { currentKm: 50_000, now: NOW })).toBe('overdue');
  });
});

describe('recurrenceInterval', () => {
  it('multipli di 12 in anni', () => {
    expect(recurrenceInterval(24)).toEqual({ unit: 'years', count: 2 });
    expect(recurrenceInterval(12)).toEqual({ unit: 'years', count: 1 });
  });
  it('il resto in mesi', () => {
    expect(recurrenceInterval(18)).toEqual({ unit: 'months', count: 18 });
    expect(recurrenceInterval(6)).toEqual({ unit: 'months', count: 6 });
  });
});
