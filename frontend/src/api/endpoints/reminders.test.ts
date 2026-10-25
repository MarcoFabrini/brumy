import { describe, it, expect, vi, beforeEach } from 'vitest';
import { ZodError } from 'zod';
import { authFetch } from '@/api/client';
import type { ReminderResponse } from '@/api/types/reminder';
import {
  completeReminder,
  createReminder,
  deleteReminder,
  getReminder,
  listReminders,
  updateReminder,
} from './reminders';

vi.mock('@/api/client', () => ({ authFetch: vi.fn() }));
const mockedAuthFetch = vi.mocked(authFetch);

const response: ReminderResponse = {
  id: 9,
  vehicle: { id: 3, name: 'Panda', brand: 'Fiat', model: 'Panda' },
  type: 'inspection',
  description: 'Revisione',
  dueDate: '2026-12-31T00:00:00+00:00',
  dueKm: null,
  notifyDaysBefore: 30,
  notifyKmBefore: 1000,
  recurrenceMonths: 24,
  recurrenceKm: null,
  completedAt: null,
  completedOn: null,
  completedKm: null,
};

const dto = {
  vehicleId: 3,
  type: 'inspection',
  description: 'Revisione',
  dueDate: '2026-12-31',
  notifyDaysBefore: 30,
  notifyKmBefore: 1000,
  recurrenceMonths: 24,
  recurrenceKm: null,
} as const;

describe('endpoint promemoria', () => {
  beforeEach(() => mockedAuthFetch.mockReset());

  it('la lista è filtrata per veicolo e restituisce la forma del client (data senza orario, id del veicolo)', async () => {
    mockedAuthFetch.mockResolvedValue([response]);

    const [reminder] = await listReminders(3);

    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/reminders?vehicleId=3');
    expect(reminder).toEqual({
      id: 9,
      vehicleId: 3,
      type: 'inspection',
      description: 'Revisione',
      dueDate: '2026-12-31',
      dueKm: null,
      notifyDaysBefore: 30,
      notifyKmBefore: 1000,
      recurrenceMonths: 24,
      recurrenceKm: null,
      completedAt: null,
      completedOn: null,
      completedKm: null,
      notes: null,
    });
  });

  it('un promemoria a soli chilometri resta senza data', async () => {
    mockedAuthFetch.mockResolvedValue({ ...response, dueDate: null, dueKm: 120000 });

    await expect(getReminder(9)).resolves.toMatchObject({ dueDate: null, dueKm: 120000 });
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/reminders/9');
  });

  it('create e update mandano JSON con il verbo giusto', async () => {
    mockedAuthFetch.mockResolvedValue(response);

    await createReminder(dto);
    await updateReminder(9, dto);

    const [createPath, createInit] = mockedAuthFetch.mock.calls[0] ?? [];
    const [updatePath, updateInit] = mockedAuthFetch.mock.calls[1] ?? [];
    expect([createPath, createInit?.method]).toEqual(['/api/reminders', 'POST']);
    expect([updatePath, updateInit?.method]).toEqual(['/api/reminders/9', 'PUT']);
    for (const init of [createInit, updateInit]) {
      expect(init?.headers).toEqual({ 'Content-Type': 'application/json' });
      expect(JSON.parse(String(init?.body))).toEqual(dto);
    }
  });

  it('complete manda data e km effettivi e adatta completato e successore', async () => {
    mockedAuthFetch.mockResolvedValue({
      completed: {
        ...response,
        completedAt: '2026-10-05T10:00:00+00:00',
        completedOn: '2026-10-01T00:00:00+00:00',
        completedKm: 9800,
      },
      next: { ...response, id: 10, dueDate: '2028-10-01T00:00:00+00:00' },
    });

    const result = await completeReminder(9, { completedOn: '2026-10-01', completedKm: 9800 });

    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/reminders/9/complete', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ completedOn: '2026-10-01', completedKm: 9800 }),
    });
    expect(result.completed).toMatchObject({ id: 9, completedOn: '2026-10-01', completedKm: 9800 });
    expect(result.next).toMatchObject({ id: 10, vehicleId: 3, dueDate: '2028-10-01' });
  });

  it('complete di una tantum: nessun successore; delete è un DELETE', async () => {
    mockedAuthFetch.mockResolvedValueOnce({ completed: { ...response, completedAt: '2026-10-05T10:00:00+00:00' }, next: null });
    mockedAuthFetch.mockResolvedValueOnce(undefined);

    const result = await completeReminder(9, { completedOn: '2026-10-05', completedKm: null });
    await deleteReminder(9);

    expect(result.next).toBeNull();
    expect(mockedAuthFetch).toHaveBeenNthCalledWith(2, '/api/reminders/9', { method: 'DELETE' });
  });

  it('una risposta di complete malformata è rifiutata (zod) invece di produrre promemoria incompleti', async () => {
    mockedAuthFetch.mockResolvedValue({ completed: { ...response, completedKm: '9800' }, next: null });

    await expect(completeReminder(9, { completedOn: '2026-10-01', completedKm: 9800 })).rejects.toBeInstanceOf(ZodError);
  });

  it('una risposta senza veicolo (contratto rotto) fallisce invece di produrre un promemoria orfano', async () => {
    mockedAuthFetch.mockResolvedValue({ ...response, vehicle: undefined });

    await expect(getReminder(9)).rejects.toThrow();
  });
});
