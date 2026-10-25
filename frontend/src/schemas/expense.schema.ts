import { z } from 'zod';
import { EXPENSE_SECTIONS, RECURRING_PERIODS } from '@/api/types/expense';
import { decimalString } from './decimal';

export const expenseSchema = z
  .object({
    vehicleId: z.number({ message: 'expense.vehicle.required' }).int().positive(),
    occurredAt: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'common.invalid_date'),
    section: z.enum(EXPENSE_SECTIONS),
    // Categoria (altre spese) o tipo di tassa (tasse); l'assicurazione non ce l'ha ma ha la compagnia, facoltativa
    category: z.string().trim().max(200),
    insurer: z.string().max(200).nullable().optional(),
    description: z.string().min(1, 'expense.description.required').max(500),
    amount: decimalString(2, { maxIntegerDigits: 8 }),
    recurring: z.boolean(),
    recurringPeriod: z.enum(RECURRING_PERIODS).nullable().optional(),
    // Ultima data di addebito (inclusa); vuota = la spesa continua finché non viene disdetta.
    recurringUntil: z
      .string()
      .regex(/^\d{4}-\d{2}-\d{2}$/, 'common.invalid_date')
      .nullable()
      .optional(),
    notes: z.string().max(2000).nullable().optional(),
    // Solo in creazione di assicurazione e bollo: prossima scadenza per il promemoria (null = nessuno)
    nextDueDate: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'common.invalid_date').or(z.literal('')).nullable().optional(),
  })
  .refine((d) => d.section !== 'other' || d.category !== '', {
    message: 'expense.category.required',
    path: ['category'],
  })
  .refine((d) => d.section !== 'tax' || d.category !== '', {
    message: 'expense.tax_type.required',
    path: ['category'],
  })
  .refine((d) => !d.recurring || !!d.recurringPeriod, {
    message: 'expense.recurring_period.required',
    path: ['recurringPeriod'],
  })
  // Le date ISO si confrontano come stringhe; la fine non può precedere il primo addebito.
  .refine((d) => !d.recurring || !d.recurringUntil || d.recurringUntil >= d.occurredAt, {
    message: 'expense.recurring_until.before_start',
    path: ['recurringUntil'],
  })
  // Periodo e data di fine valgono solo per le spese ricorrenti: se l'utente li imposta e poi disattiva
  // "ricorrente", non vanno inviati (il backend li rifiuta come incoerenti).
  .transform((d) => (d.recurring ? d : { ...d, recurringPeriod: null, recurringUntil: null }));

export type ExpenseFormData = z.infer<typeof expenseSchema>;
