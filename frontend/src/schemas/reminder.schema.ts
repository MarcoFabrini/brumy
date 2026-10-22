import { z } from 'zod';
import { REMINDER_TYPES } from '@/api/types/reminder';
import { todayIso } from '@/lib/format';

const isoDate = /^\d{4}-\d{2}-\d{2}$/;

/** Specchio di `ReminderRequest`: i valori del form sono il DTO, la ricorrenza a tempo è in mesi. */
export const reminderSchema = z
  .object({
    vehicleId: z.number({ message: 'reminder.vehicle.required' }).int().positive(),
    type: z.enum(REMINDER_TYPES, { message: 'reminder.type.required' }),
    description: z.string().min(1, 'reminder.description.required').max(500),
    dueDate: z.string().regex(isoDate, 'common.invalid_date').nullable().optional(),
    dueKm: z.number().int().nonnegative().max(9_999_999, 'common.km_too_large').nullable().optional(),
    notifyDaysBefore: z.number({ message: 'reminder.notify_days.required' }).int().min(0).max(365),
    notifyKmBefore: z.number().int().min(0).max(100_000),
    // Il form la inserisce in mesi o anni: il limite vale sul valore convertito (al massimo 10 anni).
    recurrenceMonths: z.number().int().min(1).max(120, 'reminder.recurrence_too_long').nullable().optional(),
    recurrenceKm: z.number().int().positive().max(9_999_999, 'common.km_too_large').nullable().optional(),
  })
  .refine((d) => !!d.dueDate || (d.dueKm != null && d.dueKm > 0), {
    message: 'reminder.due_required',
    path: ['dueDate'],
  })
  // La ricorrenza fa avanzare una scadenza esistente: stessa regola di `ReminderRequest::validateRecurrence()`.
  .superRefine((d, ctx) => {
    if (d.recurrenceMonths != null && !d.dueDate) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['recurrenceMonths'],
        message: 'reminder.recurrence_months_requires_due_date',
      });
    }
    if (d.recurrenceKm != null && d.dueKm == null) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['recurrenceKm'],
        message: 'reminder.recurrence_km_requires_due_km',
      });
    }
  });

export type ReminderFormData = z.infer<typeof reminderSchema>;

/**
 * Specchio di `CompleteReminderRequest`. I km sono obbligatori quando il promemoria ricorre a km
 * (sono la base della scadenza successiva); la data non può essere futura (il backend lo ricontrolla
 * con il proprio "oggi").
 */
export function completeReminderSchema(requiresKm: boolean) {
  return z
    .object({
      completedOn: z
        .string()
        .min(1, 'common.required')
        .regex(isoDate, 'common.invalid_date')
        .refine((d) => d <= todayIso(), 'reminder.completed_on_future'),
      completedKm: z.number().int().min(0).max(9_999_999, 'common.km_too_large').nullable(),
    })
    .superRefine((d, ctx) => {
      if (requiresKm && d.completedKm == null) {
        ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['completedKm'], message: 'common.required' });
      }
    });
}

export type CompleteReminderFormData = z.infer<ReturnType<typeof completeReminderSchema>>;
