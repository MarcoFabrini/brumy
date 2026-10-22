import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError } from '@/api/client';
import {
  completeReminder,
  createReminder,
  deleteReminder,
  getReminder,
  listReminders,
  listUpcomingReminders,
  updateReminder,
} from '@/api/endpoints/reminders';
import type {
  CompleteReminderDto,
  CreateReminderDto,
  UpdateReminderDto,
} from '@/api/types/reminder';

export const reminderKeys = {
  all: ['reminders'] as const,
  byVehicle: (vehicleId: number) => [...reminderKeys.all, 'vehicle', vehicleId] as const,
  detail: (id: number) => [...reminderKeys.all, 'detail', id] as const,
  upcoming: (days: number, limit: number) => [...reminderKeys.all, 'upcoming', days, limit] as const,
};

export function useReminders(vehicleId: number) {
  return useQuery({
    queryKey: reminderKeys.byVehicle(vehicleId),
    queryFn: () => listReminders(vehicleId),
    enabled: vehicleId > 0,
  });
}

/** Scadenze a data in arrivo su tutti i veicoli dell'organizzazione (dashboard). */
export function useUpcomingReminders(days = 30, limit = 5) {
  return useQuery({
    queryKey: reminderKeys.upcoming(days, limit),
    queryFn: () => listUpcomingReminders(days, limit),
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
 * Completamento con data e km effettivi. Invalida tutti i promemoria (lista, dettaglio, upcoming):
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
