import { reminderUrgency, type Reminder, type ReminderUrgency } from '@/api/types/reminder';

const RANK: Record<ReminderUrgency, number> = { done: -1, ok: 0, soon: 1, overdue: 2 };

/**
 * La scadenza da mostrare in cima alla scheda del veicolo: la più urgente (data e km insieme, come le notifiche),
 * a parità la data più vicina, poi i km mancanti. Null se non ci sono promemoria attivi con una scadenza.
 */
export function nextDeadline(
  reminders: readonly Reminder[],
  currentKm: number | null | undefined,
  now: Date = new Date(),
): { reminder: Reminder; urgency: ReminderUrgency } | null {
  const candidates = reminders
    .filter((r) => !r.completedAt && (r.dueDate !== null || r.dueKm !== null))
    .map((reminder) => ({ reminder, urgency: reminderUrgency(reminder, { currentKm, now }) }));

  candidates.sort((a, b) => {
    const byUrgency = RANK[b.urgency] - RANK[a.urgency];
    if (byUrgency !== 0) return byUrgency;
    const dateA = a.reminder.dueDate ?? '9999-12-31';
    const dateB = b.reminder.dueDate ?? '9999-12-31';
    if (dateA !== dateB) return dateA < dateB ? -1 : 1;
    const kmA = a.reminder.dueKm !== null ? a.reminder.dueKm - (currentKm ?? 0) : Number.MAX_SAFE_INTEGER;
    const kmB = b.reminder.dueKm !== null ? b.reminder.dueKm - (currentKm ?? 0) : Number.MAX_SAFE_INTEGER;
    return kmA - kmB;
  });

  return candidates[0] ?? null;
}
