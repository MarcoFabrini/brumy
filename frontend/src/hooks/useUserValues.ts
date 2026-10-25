import { useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query';
import {
  createUserValue,
  deleteUserValue,
  listUserValues,
  listValueSuggestions,
  mergeUserValue,
  renameUserValue,
} from '@/api/endpoints/userValues';
import type { UserValueKind } from '@/api/types/userValue';
import { dashboardKeys } from '@/hooks/useDashboardCharts';
import { expenseKeys } from '@/hooks/useExpenses';
import { maintenanceKeys } from '@/hooks/useMaintenances';
import { refuelingKeys } from '@/hooks/useRefuelings';
import { userValueKeys } from '@/hooks/userValueKeys';
import { vehicleKeys } from '@/hooks/useVehicles';

export function useValueSuggestions(vehicleId: number, kind: UserValueKind) {
  return useQuery({
    queryKey: userValueKeys.suggestions(vehicleId, kind),
    queryFn: () => listValueSuggestions(vehicleId, kind),
    enabled: vehicleId > 0,
  });
}

export function useUserValues(kind: UserValueKind) {
  return useQuery({
    queryKey: userValueKeys.list(kind),
    queryFn: () => listUserValues(kind),
  });
}

/** I nomi compaiono nei record e nei grafici: ogni modifica alle voci invalida anche quelli. */
function invalidateAfterManagement(qc: QueryClient) {
  for (const key of [
    userValueKeys.all,
    refuelingKeys.all,
    maintenanceKeys.all,
    expenseKeys.all,
    dashboardKeys.all,
    vehicleKeys.all,
  ]) {
    void qc.invalidateQueries({ queryKey: key });
  }
}

export function useCreateUserValue() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ kind, name }: { kind: UserValueKind; name: string }) => createUserValue(kind, name),
    onSuccess: () => invalidateAfterManagement(qc),
  });
}

export function useRenameUserValue() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, name }: { id: number; name: string }) => renameUserValue(id, name),
    onSuccess: () => invalidateAfterManagement(qc),
  });
}

export function useMergeUserValue() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, intoId }: { id: number; intoId: number }) => mergeUserValue(id, intoId),
    onSuccess: () => invalidateAfterManagement(qc),
  });
}

export function useDeleteUserValue() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteUserValue(id),
    onSuccess: () => invalidateAfterManagement(qc),
  });
}
