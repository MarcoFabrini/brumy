import { z } from 'zod';
import { daysUntil } from '@/lib/format';

export const REMINDER_TYPES = [
  'inspection',
  'insurance',
  'road_tax',
  'service',
  'oil_change',
  'tires',
  'battery',
  'license',
  'extinguisher',
  'custom',
] as const;
export type ReminderType = (typeof REMINDER_TYPES)[number];

/**
 * Promemoria come lo serializza il backend (`reminder:read` + `vehicle:nested`). Le date arrivano come
 * datetime ISO. Lo schema valida la risposta del completamento (`completeReminderResponseSchema`); gli
 * endpoint storici usano lo stesso tipo senza parse.
 */
export const reminderResponseSchema = z.object({
  id: z.number().int(),
  vehicle: z.object({ id: z.number().int(), name: z.string(), brand: z.string(), model: z.string() }),
  type: z.enum(REMINDER_TYPES),
  description: z.string(),
  dueDate: z.string().nullable(),
  dueKm: z.number().int().nullable(),
  notifyDaysBefore: z.number().int(),
  notifyKmBefore: z.number().int(),
  recurrenceMonths: z.number().int().nullable(),
  recurrenceKm: z.number().int().nullable(),
  completedAt: z.string().nullable(),
  completedOn: z.string().nullable(),
  completedKm: z.number().int().nullable(),
});
export type ReminderResponse = z.infer<typeof reminderResponseSchema>;

/** Un elemento di `GET /api/reminders/overview` (tab "Scadenze"): urgenza e km attuali già calcolati dal backend. */
export const reminderOverviewItemSchema = reminderResponseSchema.extend({
  urgency: z.enum(['ok', 'soon', 'overdue']),
  currentKm: z.number().int(),
});

export interface ReminderOverviewItem extends Reminder {
  vehicleName: string;
  urgency: 'ok' | 'soon' | 'overdue';
  currentKm: number;
}

export function adaptReminderOverviewItem(raw: z.infer<typeof reminderOverviewItemSchema>): ReminderOverviewItem {
  return { ...adaptReminder(raw), vehicleName: raw.vehicle.name, urgency: raw.urgency, currentKm: raw.currentKm };
}

/** Risposta di `POST /api/reminders/{id}/complete`: `next` è il successore di un ricorrente, o null. */
export const completeReminderResponseSchema = z.object({
  completed: reminderResponseSchema,
  next: reminderResponseSchema.nullable(),
});

export interface Reminder {
  id: number;
  vehicleId: number;
  type: ReminderType;
  description: string;
  dueDate: string | null;       // YYYY-MM-DD
  dueKm: number | null;
  notifyDaysBefore: number;
  /** "In scadenza" quando mancano al massimo tanti km alla scadenza a km. */
  notifyKmBefore: number;
  /** Ricorrenza a tempo in mesi (12 = ogni anno); null = nessuna. */
  recurrenceMonths: number | null;
  /** Ricorrenza a km; null = nessuna. */
  recurrenceKm: number | null;
  /** Istante in cui è stato segnato completato: decide se è completato. */
  completedAt: string | null;   // ISO datetime
  /** Data effettiva del lavoro (null per i completamenti precedenti alla ricorrenza). */
  completedOn: string | null;   // YYYY-MM-DD
  completedKm: number | null;
}

export interface CreateReminderDto {
  vehicleId: number;
  type: ReminderType;
  description: string;
  dueDate?: string | null;
  dueKm?: number | null;
  notifyDaysBefore: number;
  notifyKmBefore: number;
  recurrenceMonths?: number | null;
  recurrenceKm?: number | null;
}

export type UpdateReminderDto = CreateReminderDto;

/** Body di `POST /api/reminders/{id}/complete`: data e km EFFETTIVI del lavoro. */
export interface CompleteReminderDto {
  completedOn: string;          // YYYY-MM-DD
  completedKm: number | null;
}

/** Il promemoria completato e, se ricorrente, il successore con la scadenza successiva. */
export interface CompleteReminderResult {
  completed: Reminder;
  next: Reminder | null;
}

/** Anticipo di default dell'avviso a km, come `Reminder::DEFAULT_NOTIFY_KM_BEFORE`. */
export const DEFAULT_NOTIFY_KM_BEFORE = 1000;

export function adaptReminder(raw: ReminderResponse): Reminder {
  return {
    id: raw.id,
    vehicleId: raw.vehicle.id,
    type: raw.type,
    description: raw.description,
    dueDate: raw.dueDate ? raw.dueDate.slice(0, 10) : null,
    dueKm: raw.dueKm,
    notifyDaysBefore: raw.notifyDaysBefore,
    notifyKmBefore: raw.notifyKmBefore,
    recurrenceMonths: raw.recurrenceMonths,
    recurrenceKm: raw.recurrenceKm,
    completedAt: raw.completedAt,
    completedOn: raw.completedOn ? raw.completedOn.slice(0, 10) : null,
    completedKm: raw.completedKm,
  };
}

export const RECURRENCE_UNITS = ['months', 'years'] as const;
export type RecurrenceUnit = (typeof RECURRENCE_UNITS)[number];

/** Mesi per unità: la ricorrenza si salva sempre in mesi. */
export const MONTHS_PER_UNIT: Record<RecurrenceUnit, number> = { months: 1, years: 12 };

/** Ricorrente se ha almeno una ricorrenza (a tempo o a km): al completamento nasce il successivo. */
export function isRecurringReminder(r: Pick<Reminder, 'recurrenceMonths' | 'recurrenceKm'>): boolean {
  return r.recurrenceMonths != null || r.recurrenceKm != null;
}

/** Una ricorrenza in mesi espressa nell'unità più naturale: anni se multiplo di 12 (24 → 2 anni). */
export function recurrenceInterval(months: number): { unit: RecurrenceUnit; count: number } {
  return months % 12 === 0 ? { unit: 'years', count: months / 12 } : { unit: 'months', count: months };
}

export type ReminderUrgency = 'overdue' | 'soon' | 'ok' | 'done';

const URGENCY_RANK = { ok: 0, soon: 1, overdue: 2 } as const;

function dateUrgency(r: Reminder, now: Date): keyof typeof URGENCY_RANK {
  if (!r.dueDate) return 'ok';
  const daysLeft = daysUntil(r.dueDate, now);
  if (daysLeft === null) return 'ok';
  // Scade oggi (daysLeft = 0) = ancora in tempo, "in scadenza" ma non scaduto.
  if (daysLeft < 0) return 'overdue';
  return daysLeft <= r.notifyDaysBefore ? 'soon' : 'ok';
}

/**
 * Un promemoria a chilometri è "in scadenza" quando mancano al massimo `notifyKmBefore` km.
 * Stesse regole del backend (`Reminder::urgency()`), che è ciò che decide quando parte la
 * notifica: tenerle allineate.
 */
function kmUrgency(r: Reminder, currentKm: number | null | undefined): keyof typeof URGENCY_RANK {
  if (r.dueKm == null || currentKm == null) return 'ok';
  const kmLeft = r.dueKm - currentKm;
  if (kmLeft < 0) return 'overdue';
  return kmLeft <= r.notifyKmBefore ? 'soon' : 'ok';
}

/**
 * Urgenza di un promemoria non completato: la peggiore tra scadenza a data e a chilometri.
 * Per la parte a km serve il chilometraggio attuale del veicolo (`currentKm`); senza, conta
 * solo la data. Confronto a giorni di calendario locali, non a istanti.
 */
export function reminderUrgency(
  r: Reminder,
  opts: { currentKm?: number | null; now?: Date } = {},
): ReminderUrgency {
  if (r.completedAt) return 'done';
  const byDate = dateUrgency(r, opts.now ?? new Date());
  const byKm = kmUrgency(r, opts.currentKm);
  return URGENCY_RANK[byDate] >= URGENCY_RANK[byKm] ? byDate : byKm;
}
