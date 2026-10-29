import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError } from '@/api/client';
import {
  completeReminder,
  createReminder,
  deleteReminder,
  getReminder,
  listReminders,
  listReminderOverview,
  updateReminder,
} from '@/api/endpoints/reminders';
import type {
  CompleteReminderDto,
  CreateReminderDto,
  UpdateReminderDto,
} from '@/api/types/reminder';
import { keepPreviousOf, searchKeyPart } from '@/lib/pagination';

export const reminderKeys = {
  all: ['reminders'] as const,
  byVehicle: (vehicleId: number) => [...reminderKeys.all, 'vehicle', vehicleId] as const,
  detail: (id: number) => [...reminderKeys.all, 'detail', id] as const,
  overview: () => [...reminderKeys.all, 'overview'] as const,
};

/**
 * Scadenze di tutti i veicoli propri, con urgenza: la tab "Scadenze". Con `search` (query string di
 * `buildSearchQuery`) la chiave è figlia di `overview()`.
 */
export function useReminderOverview(options: { enabled?: boolean; search?: string } = {}) {
  const { enabled = true, search } = options;
  return useQuery({
    queryKey: [...reminderKeys.overview(), ...searchKeyPart(search)],
    queryFn: () => listReminderOverview(search),
    enabled,
    placeholderData: keepPreviousOf(reminderKeys.overview()),
  });
}

/** Promemoria attivi del veicolo; con `search` la chiave è figlia di `byVehicle(id)`. */
export function useReminders(vehicleId: number, search?: string) {
  return useQuery({
    queryKey: [...reminderKeys.byVehicle(vehicleId), ...searchKeyPart(search)],
    queryFn: () => listReminders(vehicleId, search),
    enabled: vehicleId > 0,
    placeholderData: keepPreviousOf(reminderKeys.byVehicle(vehicleId)),
  });
}

export function useReminder(id: number) {
  return useQuery({
    queryKey: reminderKeys.detail(id),
    queryFn: () => getReminder(id),
    enabled: id > 0,
  });
}

export function useCreateReminder() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: CreateReminderDto) => createReminder(body),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: reminderKeys.all });
    },
  });
}

export function useUpdateReminder(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: UpdateReminderDto) => updateReminder(id, body),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: reminderKeys.all });
    },
  });
}

/**
 * Completamento con data e km effettivi. Invalida tutti i promemoria (lista, dettaglio, panoramica):
 * il successore di un ricorrente compare tra gli attivi. Statistiche e grafici non cambiano, perché
 * i km del completamento non alimentano l'odometro del veicolo.
 */
export function useCompleteReminder() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, ...body }: CompleteReminderDto & { id: number }) => completeReminder(id, body),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: reminderKeys.all });
    },
    onError: (e) => {
      // 409: completato nel frattempo (altra scheda o dispositivo): i dati in pagina sono vecchi, si ricaricano.
      if (e instanceof ApiError && e.status === 409) void qc.invalidateQueries({ queryKey: reminderKeys.all });
    },
  });
}

export function useDeleteReminder() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteReminder(id),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: reminderKeys.all });
    },
  });
}
