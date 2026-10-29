import { authFetch } from '@/api/client';
import { listUrl } from '@/api/endpoints/listUrl';
import { LIST_PAGE_SIZE } from '@/lib/pagination';
import {
  adaptExpense,
  type CreateExpenseDto,
  type Expense,
  type ExpenseResponse,
  type ExpenseSection,
  type UpdateExpenseDto,
} from '@/api/types/expense';

/**
 * Una pagina ({@link LIST_PAGE_SIZE} record, dalle più recenti) delle spese del veicolo, di una sezione o di tutte,
 * con la ricerca `search` (query string di `buildSearchQuery`).
 */
export async function listExpenses(
  vehicleId: number,
  { section, page = 1, search }: { section?: ExpenseSection; page?: number; search?: string } = {},
): Promise<Expense[]> {
  const url = listUrl('/api/expenses', { vehicleId, section, page, limit: LIST_PAGE_SIZE }, search);
  const raw = await authFetch<ExpenseResponse[]>(url);
  return raw.map(adaptExpense);
}

export async function getExpense(id: number): Promise<Expense> {
  const raw = await authFetch<ExpenseResponse>(`/api/expenses/${id}`);
  return adaptExpense(raw);
}

export async function createExpense(body: CreateExpenseDto): Promise<Expense> {
  const raw = await authFetch<ExpenseResponse>('/api/expenses', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  return adaptExpense(raw);
}

export async function updateExpense(id: number, body: UpdateExpenseDto): Promise<Expense> {
  const raw = await authFetch<ExpenseResponse>(`/api/expenses/${id}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  return adaptExpense(raw);
}

export function deleteExpense(id: number) {
  return authFetch<void>(`/api/expenses/${id}`, { method: 'DELETE' });
}
