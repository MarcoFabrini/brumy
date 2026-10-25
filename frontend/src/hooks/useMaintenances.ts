import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  createMaintenance,
  deleteMaintenance,
  getMaintenance,
  listMaintenances,
  updateMaintenance,
} from '@/api/endpoints/maintenances';
import type {
  CreateMaintenanceDto,
  MaintenanceCategory,
  UpdateMaintenanceDto,
} from '@/api/types/maintenance';
import { componentKeys } from '@/hooks/componentKeys';
import { reminderKeys } from '@/hooks/useReminders';
import { userValueKeys } from '@/hooks/userValueKeys';
import { invalidateVehicleStats } from '@/hooks/useVehicles';

export const maintenanceKeys = {
  all: ['maintenances'] as const,
  byVehicle: (vehicleId: number) => [...maintenanceKeys.all, 'vehicle', vehicleId] as const,
  detail: (id: number) => [...maintenanceKeys.all, 'detail', id] as const,
};

export function useMaintenances(vehicleId: number, category?: MaintenanceCategory) {
  return useQuery({
    queryKey: [...maintenanceKeys.byVehicle(vehicleId), category ?? 'all'],
    queryFn: () => listMaintenances(vehicleId, category),
    enabled: vehicleId > 0,
  });
}

export function useMaintenance(id: number) {
  return useQuery({
    queryKey: maintenanceKeys.detail(id),
    queryFn: () => getMaintenance(id),
    enabled: id > 0,
  });
}

export function useCreateMaintenance() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: CreateMaintenanceDto) => createMaintenance(body),
    onSuccess: (_data, vars) => {
      void qc.invalidateQueries({ queryKey: maintenanceKeys.byVehicle(vars.vehicleId) });
      void qc.invalidateQueries({ queryKey: userValueKeys.all });
      // Revisione, assicurazione e bollo possono aver aggiornato o creato un promemoria
      void qc.invalidateQueries({ queryKey: reminderKeys.all });
      void qc.invalidateQueries({ queryKey: componentKeys.all });
      invalidateVehicleStats(qc, vars.vehicleId);
    },
  });
}

export function useUpdateMaintenance(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: UpdateMaintenanceDto) => updateMaintenance(id, body),
    onSuccess: (_data, vars) => {
      // Il PUT può spostare la voce su un altro veicolo: si invalida tutto il dominio, non solo il veicolo corrente.
      void qc.invalidateQueries({ queryKey: maintenanceKeys.all });
      void qc.invalidateQueries({ queryKey: userValueKeys.all });
      void qc.invalidateQueries({ queryKey: componentKeys.all });
      invalidateVehicleStats(qc, vars.vehicleId);
    },
  });
}

export function useDeleteMaintenance(vehicleId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteMaintenance(id),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: maintenanceKeys.all });
      void qc.invalidateQueries({ queryKey: userValueKeys.all });
      void qc.invalidateQueries({ queryKey: componentKeys.all });
      invalidateVehicleStats(qc, vehicleId);
    },
  });
}
