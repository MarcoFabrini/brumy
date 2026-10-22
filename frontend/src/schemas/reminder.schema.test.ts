import { describe, it, expect } from 'vitest';
import type { z } from 'zod';
import { todayIso } from '@/lib/format';
import { completeReminderSchema, reminderSchema } from './reminder.schema';

const base = {
  vehicleId: 3,
  type: 'service',
  description: 'Tagliando',
  dueDate: '2026-12-31',
  dueKm: 10_000,
  notifyDaysBefore: 30,
  notifyKmBefore: 1000,
} as const;

function issues(result: z.SafeParseReturnType<unknown, unknown>): { path: string; message: string }[] {
  return result.success ? [] : result.error.issues.map((i) => ({ path: i.path.join('.'), message: i.message }));
}

function isoDaysFromToday(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return todayIso(d);
}

describe('reminderSchema — ricorrenza', () => {
  it('ricorrenze valide (a tempo e a km) passano', () => {
    const r = reminderSchema.safeParse({ ...base, recurrenceMonths: 24, recurrenceKm: 10_000, notifyKmBefore: 500 });
    expect(r.success).toBe(true);
    expect(r.data).toMatchObject({ recurrenceMonths: 24, recurrenceKm: 10_000, notifyKmBefore: 500 });
  });

  it('una tantum: ricorrenze null', () => {
    expect(reminderSchema.safeParse({ ...base, recurrenceMonths: null, recurrenceKm: null }).success).toBe(true);
  });

  it('ricorrenza a tempo senza data di scadenza: errore su recurrenceMonths', () => {
    const r = reminderSchema.safeParse({ ...base, dueDate: null, recurrenceMonths: 12 });
    expect(issues(r)).toEqual([
      { path: 'recurrenceMonths', message: 'reminder.recurrence_months_requires_due_date' },
    ]);
  });

  it('ricorrenza a km senza km di scadenza: errore su recurrenceKm', () => {
    const r = reminderSchema.safeParse({ ...base, dueKm: null, recurrenceKm: 10_000 });
    expect(issues(r)).toEqual([{ path: 'recurrenceKm', message: 'reminder.recurrence_km_requires_due_km' }]);
  });

  it('ricorrenza a tempo oltre i 120 mesi (10 anni) rifiutata', () => {
    expect(reminderSchema.safeParse({ ...base, recurrenceMonths: 120 }).success).toBe(true);
    expect(issues(reminderSchema.safeParse({ ...base, recurrenceMonths: 132 }))).toEqual([
      { path: 'recurrenceMonths', message: 'reminder.recurrence_too_long' },
    ]);
    expect(reminderSchema.safeParse({ ...base, recurrenceMonths: 0 }).success).toBe(false);
  });

  it('anticipo a km negativo o enorme rifiutato', () => {
    expect(reminderSchema.safeParse({ ...base, notifyKmBefore: -1 }).success).toBe(false);
    expect(reminderSchema.safeParse({ ...base, notifyKmBefore: 100_001 }).success).toBe(false);
  });
});

describe('completeReminderSchema', () => {
  it('oggi e km noti passano', () => {
    const r = completeReminderSchema(true).safeParse({ completedOn: todayIso(), completedKm: 9800 });
    expect(r.success).toBe(true);
  });

  it('una data futura è rifiutata', () => {
    const r = completeReminderSchema(false).safeParse({ completedOn: isoDaysFromToday(1), completedKm: null });
    expect(issues(r)).toEqual([{ path: 'completedOn', message: 'reminder.completed_on_future' }]);
  });

  it('i km servono solo se il promemoria ricorre a km', () => {
    const payload = { completedOn: isoDaysFromToday(-3), completedKm: null };
    expect(completeReminderSchema(false).safeParse(payload).success).toBe(true);
    expect(issues(completeReminderSchema(true).safeParse(payload))).toEqual([
      { path: 'completedKm', message: 'common.required' },
    ]);
  });

  it('km oltre il massimo rifiutati', () => {
    const r = completeReminderSchema(false).safeParse({ completedOn: todayIso(), completedKm: 10_000_000 });
    expect(issues(r)).toEqual([{ path: 'completedKm', message: 'common.km_too_large' }]);
  });
});
