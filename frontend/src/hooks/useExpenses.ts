import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  createExpense,
  deleteExpense,
  getExpense,
  listExpenses,
  updateExpense,
} from '@/api/endpoints/expenses';
import type { CreateExpenseDto, ExpenseSection, UpdateExpenseDto } from '@/api/types/expense';
import { reminderKeys } from '@/hooks/useReminders';
import { userValueKeys } from '@/hooks/userValueKeys';
import { invalidateVehicleStats } from '@/hooks/useVehicles';
import { keepPreviousOf, nextListPage, searchKeyPart } from '@/lib/pagination';

export const expenseKeys = {
  all: ['expenses'] as const,
  byVehicle: (vehicleId: number) => [...expenseKeys.all, 'vehicle', vehicleId] as const,
  detail: (id: number) => [...expenseKeys.all, 'detail', id] as const,
};

/**
 * Spese del veicolo a pagine (si caricano scorrendo), di una sezione o di tutte, con la ricerca `search`. La chiave
 * resta figlia di `byVehicle(id)`: le invalidazioni esistenti ricaricano tutte le pagine già caricate.
 */
export function useExpenses(vehicleId: number, section?: ExpenseSection, search?: string) {
  const base = [...expenseKeys.byVehicle(vehicleId), section ?? 'all'] as const;
  return useInfiniteQuery({
    queryKey: [...base, ...searchKeyPart(search)],
    queryFn: ({ pageParam }) => listExpenses(vehicleId, { section, page: pageParam, search }),
    initialPageParam: 1,
    getNextPageParam: nextListPage,
    enabled: vehicleId > 0,
    placeholderData: keepPreviousOf(base),
  });
}

export function useExpense(id: number) {
  return useQuery({
    queryKey: expenseKeys.detail(id),
    queryFn: () => getExpense(id),
    enabled: id > 0,
  });
}

export function useCreateExpense() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: CreateExpenseDto) => createExpense(body),
    onSuccess: (_data, vars) => {
      void qc.invalidateQueries({ queryKey: expenseKeys.byVehicle(vars.vehicleId) });
      void qc.invalidateQueries({ queryKey: userValueKeys.all });
      // Revisione, assicurazione e bollo possono aver aggiornato o creato un promemoria
      void qc.invalidateQueries({ queryKey: reminderKeys.all });
      invalidateVehicleStats(qc, vars.vehicleId);
    },
  });
}

export function useUpdateExpense(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: UpdateExpenseDto) => updateExpense(id, body),
    onSuccess: (_data, vars) => {
      // Il PUT può spostare la voce su un altro veicolo: si invalida tutto il dominio, non solo il veicolo corrente.
      void qc.invalidateQueries({ queryKey: expenseKeys.all });
      void qc.invalidateQueries({ queryKey: userValueKeys.all });
      invalidateVehicleStats(qc, vars.vehicleId);
    },
  });
}

export function useDeleteExpense(vehicleId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteExpense(id),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: expenseKeys.all });
      void qc.invalidateQueries({ queryKey: userValueKeys.all });
      invalidateVehicleStats(qc, vehicleId);
    },
  });
}
