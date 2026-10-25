export const RECURRING_PERIODS = [
  'weekly',
  'monthly',
  'quarterly',
  'semiannual',
  'yearly',
  'biennial',
] as const;
export type RecurringPeriod = (typeof RECURRING_PERIODS)[number];

/** Sezione di una spesa: tasse (tipo di tassa), assicurazione (compagnia), altre spese (categoria). */
export const EXPENSE_SECTIONS = ['tax', 'insurance', 'other'] as const;
export type ExpenseSection = (typeof EXPENSE_SECTIONS)[number];

export interface ExpenseResponse {
  id: number;
  vehicle: { id: number; name: string; brand: string; model: string };
  occurredAt: string;          // ISO datetime
  section: ExpenseSection;
  /** Categoria (altre spese) o tipo di tassa (tasse); null per l'assicurazione. */
  category: { id: number; name: string } | null;
  insurer: { id: number; name: string } | null;
  description: string;
  amount: string;              // decimal string
  recurring: boolean;
  recurringPeriod: RecurringPeriod | null;
  recurringUntil?: string | null; // ISO datetime, ultimo addebito possibile (inclusivo); null/assente = in corso
  notes?: string | null;
}

export interface Expense {
  id: number;
  vehicleId: number;
  occurredAt: string;          // YYYY-MM-DD
  section: ExpenseSection;
  /** Nome della categoria o del tipo di tassa (voce privata dell'utente); '' per l'assicurazione. */
  category: string;
  /** Nome della compagnia (solo assicurazione). */
  insurer: string | null;
  description: string;
  amount: string;
  recurring: boolean;
  recurringPeriod: RecurringPeriod | null;
  recurringUntil: string | null; // YYYY-MM-DD, ultimo addebito possibile (inclusivo); null = in corso
  notes: string | null;
}

export interface CreateExpenseDto {
  vehicleId: number;
  occurredAt: string;
  section: ExpenseSection;
  /** Nome della categoria o del tipo di tassa; ignorato per l'assicurazione. */
  category: string;
  insurer?: string | null;
  /** Solo in creazione di assicurazione e bollo: aggiorna o crea il promemoria con questa scadenza. */
  nextDueDate?: string | null;
  description: string;
  amount: string;
  recurring: boolean;
  recurringPeriod?: RecurringPeriod | null;
  recurringUntil?: string | null;
  notes?: string | null;
}

export type UpdateExpenseDto = CreateExpenseDto;

export function adaptExpense(raw: ExpenseResponse): Expense {
  return {
    id: raw.id,
    vehicleId: raw.vehicle.id,
    occurredAt: raw.occurredAt.slice(0, 10),
    section: raw.section,
    category: raw.category?.name ?? '',
    insurer: raw.insurer?.name ?? null,
    description: raw.description,
    amount: raw.amount,
    recurring: raw.recurring,
    recurringPeriod: raw.recurringPeriod,
    recurringUntil: raw.recurringUntil ? raw.recurringUntil.slice(0, 10) : null,
    notes: raw.notes ?? null,
  };
}
