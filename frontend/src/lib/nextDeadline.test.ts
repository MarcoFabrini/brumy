import { describe, expect, it } from 'vitest';
import type { Reminder } from '@/api/types/reminder';
import { nextDeadline } from './nextDeadline';

const now = new Date('2026-10-22T10:00:00');
const r = (over: Partial<Reminder>): Reminder => ({
  id: 1,
  vehicleId: 1,
  type: 'custom',
  description: 'x',
  dueDate: null,
  dueKm: null,
  notifyDaysBefore: 30,
  notifyKmBefore: 1000,
  recurrenceMonths: null,
  recurrenceKm: null,
  completedAt: null,
  completedOn: null,
  completedKm: null,
  ...over,
});

describe('nextDeadline', () => {
  it('la più urgente vince anche se la data di un\'altra è più vicina', () => {
    const oil = r({ id: 1, dueKm: 20_000, notifyKmBefore: 500 });
    const inspection = r({ id: 2, dueDate: '2026-12-30' });
    expect(nextDeadline([inspection, oil], 19_700, now)?.reminder.id).toBe(1);
  });

  it('a parità di urgenza la data più vicina', () => {
    const a = r({ id: 1, dueDate: '2027-05-01' });
    const b = r({ id: 2, dueDate: '2027-02-01' });
    expect(nextDeadline([a, b], 0, now)?.reminder.id).toBe(2);
  });

  it('i completati e quelli senza scadenza non contano', () => {
    expect(nextDeadline([r({ completedAt: '2026-10-01T10:00:00Z', dueDate: '2026-10-01' }), r({})], 0, now)).toBeNull();
  });
});
