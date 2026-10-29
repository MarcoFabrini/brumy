import { authFetch } from '@/api/client';
import { listUrl } from '@/api/endpoints/listUrl';
import { z } from 'zod';
import {
  adaptReminder,
  adaptReminderOverviewItem,
  reminderOverviewItemSchema,
  type ReminderOverviewItem,
  completeReminderResponseSchema,
  type CompleteReminderDto,
  type CompleteReminderResult,
  type CreateReminderDto,
  type Reminder,
  type ReminderResponse,
  type UpdateReminderDto,
} from '@/api/types/reminder';

/** Promemoria attivi del veicolo; `search`: query string di `buildSearchQuery`. */
export async function listReminders(vehicleId: number, search?: string): Promise<Reminder[]> {
  const raw = await authFetch<ReminderResponse[]>(listUrl('/api/reminders', { vehicleId }, search));
  return raw.map(adaptReminder);
}

/** Tutte le scadenze attive dei veicoli propri, con urgenza (data e km) e in ordine: la tab "Scadenze". */
export async function listReminderOverview(search?: string): Promise<ReminderOverviewItem[]> {
  const raw = z.array(reminderOverviewItemSchema).parse(await authFetch<unknown>(listUrl('/api/reminders/overview', {}, search)));
  return raw.map(adaptReminderOverviewItem);
}

export async function getReminder(id: number): Promise<Reminder> {
  const raw = await authFetch<ReminderResponse>(`/api/reminders/${id}`);
  return adaptReminder(raw);
}

export async function createReminder(body: CreateReminderDto): Promise<Reminder> {
  const raw = await authFetch<ReminderResponse>('/api/reminders', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  return adaptReminder(raw);
}

export async function updateReminder(id: number, body: UpdateReminderDto): Promise<Reminder> {
  const raw = await authFetch<ReminderResponse>(`/api/reminders/${id}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  return adaptReminder(raw);
}

/** Completa con data e km effettivi; se il promemoria è ricorrente il backend crea il successivo (`next`). */
export async function completeReminder(
  id: number,
  body: CompleteReminderDto,
): Promise<CompleteReminderResult> {
  const raw = completeReminderResponseSchema.parse(
    await authFetch<unknown>(`/api/reminders/${id}/complete`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    }),
  );
  return { completed: adaptReminder(raw.completed), next: raw.next ? adaptReminder(raw.next) : null };
}

export function deleteReminder(id: number) {
  return authFetch<void>(`/api/reminders/${id}`, { method: 'DELETE' });
}
