import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  createRefueling,
  deleteRefueling,
  getRefueling,
  listRefuelingPage,
  listRefuelings,
  updateRefueling,
} from '@/api/endpoints/refuelings';
import type { CreateRefuelingDto, UpdateRefuelingDto } from '@/api/types/refueling';
import { userValueKeys } from '@/hooks/userValueKeys';
import { invalidateVehicleStats } from '@/hooks/useVehicles';
import { keepPreviousOf, nextListPage, searchKeyPart } from '@/lib/pagination';

export const refuelingKeys = {
  all: ['refuelings'] as const,
  byVehicle: (vehicleId: number) => [...refuelingKeys.all, 'vehicle', vehicleId] as const,
  detail: (id: number) => [...refuelingKeys.all, 'detail', id] as const,
};

/** I rifornimenti più recenti del veicolo (prima pagina): l'aggiunta rapida legge l'ultimo. */
export function useRefuelings(vehicleId: number) {
  return useQuery({
    queryKey: refuelingKeys.byVehicle(vehicleId),
    queryFn: () => listRefuelings(vehicleId),
    enabled: vehicleId > 0,
  });
}

/**
 * L'elenco dei rifornimenti a pagine (si caricano scorrendo), con la ricerca `search`. Chiave figlia di
 * `byVehicle(id)` ma diversa da quella di {@link useRefuelings} (i dati a pagine hanno un'altra forma): le
 * invalidazioni esistenti coprono entrambe e ricaricano tutte le pagine già caricate.
 */
export function useRefuelingList(vehicleId: number, search?: string) {
  const base = [...refuelingKeys.byVehicle(vehicleId), 'pages'] as const;
  return useInfiniteQuery({
    queryKey: [...base, ...searchKeyPart(search)],
    queryFn: ({ pageParam }) => listRefuelingPage(vehicleId, { page: pageParam, search }),
    initialPageParam: 1,
    getNextPageParam: nextListPage,
    enabled: vehicleId > 0,
    placeholderData: keepPreviousOf(base),
  });
}

export function useRefueling(id: number) {
  return useQuery({
    queryKey: refuelingKeys.detail(id),
    queryFn: () => getRefueling(id),
    enabled: id > 0,
  });
}

export function useCreateRefueling() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: CreateRefuelingDto) => createRefueling(body),
    onSuccess: (_data, vars) => {
      void qc.invalidateQueries({ queryKey: refuelingKeys.byVehicle(vars.vehicleId) });
      void qc.invalidateQueries({ queryKey: userValueKeys.all });
      invalidateVehicleStats(qc, vars.vehicleId);
    },
  });
}

export function useUpdateRefueling(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: UpdateRefuelingDto) => updateRefueling(id, body),
    onSuccess: (_data, vars) => {
      // Il PUT può spostare la voce su un altro veicolo: si invalida tutto il dominio, non solo il veicolo corrente.
      void qc.invalidateQueries({ queryKey: refuelingKeys.all });
      void qc.invalidateQueries({ queryKey: userValueKeys.all });
      invalidateVehicleStats(qc, vars.vehicleId);
    },
  });
}

export function useDeleteRefueling(vehicleId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteRefueling(id),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: refuelingKeys.all });
      void qc.invalidateQueries({ queryKey: userValueKeys.all });
      invalidateVehicleStats(qc, vehicleId);
    },
  });
}
