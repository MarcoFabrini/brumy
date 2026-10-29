import { useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query';
import {
  archiveVehicle,
  unarchiveVehicle,
  createVehicle,
  deleteVehicle,
  getVehicle,
  getVehicleCharts,
  getVehicleStats,
  listArchivedVehicles,
  listVehicles,
  updateVehicle,
} from '@/api/endpoints/vehicles';
import type { CreateVehicleDto, UpdateVehicleDto } from '@/api/types/vehicle';
import { DASHBOARD_CHART_MONTHS, dashboardKeys } from '@/hooks/useDashboardCharts';
import { keepPreviousOf, searchKeyPart } from '@/lib/pagination';

/**
 * Query keys factory — single source of truth per cache invalidation.
 */
export const vehicleKeys = {
  all: ['vehicles'] as const,
  lists: () => [...vehicleKeys.all, 'list'] as const,
  /**
   * Archiviati: figlia di `lists()`, quindi le invalidazioni della lista (crea, modifica, archivia,
   * ripristina, elimina) la coprono per prefisso, ma con chiave diversa da quella di `useVehicles()`
   * (che usa esattamente `lists()`): selettori, aggiunta rapida e dashboard restano sugli attivi.
   */
  archivedList: () => [...vehicleKeys.lists(), 'archived'] as const,
  details: () => [...vehicleKeys.all, 'detail'] as const,
  detail: (id: number) => [...vehicleKeys.details(), id] as const,
};

/**
 * Veicoli attivi accessibili. Con `search` (query string di `buildSearchQuery`, solo la pagina Veicoli) la chiave è
 * figlia di `lists()`; senza è esattamente `lists()`, quella di selettori, aggiunta rapida e dashboard.
 */
export function useVehicles(search?: string) {
  return useQuery({
    queryKey: [...vehicleKeys.lists(), ...searchKeyPart(search)],
    queryFn: () => listVehicles(search),
    placeholderData: keepPreviousOf(vehicleKeys.lists()),
  });
}

/** Veicoli archiviati accessibili all'utente: per la sezione "archiviati" della lista, da cui si ripristinano. */
export function useArchivedVehicles(search?: string) {
  return useQuery({
    queryKey: [...vehicleKeys.archivedList(), ...searchKeyPart(search)],
    queryFn: () => listArchivedVehicles(search),
    placeholderData: keepPreviousOf(vehicleKeys.archivedList()),
  });
}

export function useVehicle(id: number) {
  return useQuery({
    queryKey: vehicleKeys.detail(id),
    queryFn: () => getVehicle(id),
    enabled: id > 0,
  });
}

/**
 * Se l'utente può modificare il veicolo e i suoi dati (record, allegati, promemoria):
 * `undefined` finché non è noto, `false` per un veicolo condiviso in sola lettura.
 * Il backend applica comunque i voter: qui serve solo a non mostrare azioni che darebbero 403.
 */
export function useCanEditVehicle(vehicleId: number): boolean | undefined {
  const { data } = useVehicle(vehicleId);
  return data?.permissions.canEdit;
}

/** Chiave delle statistiche di un veicolo (km attuali, consumi, costi): condivisa da dettaglio, dashboard e badge promemoria. */
export const vehicleStatsKey = (id: number) => [...vehicleKeys.detail(id), 'stats'] as const;

/**
 * Chiavi dei grafici di un veicolo, sotto il suo dettaglio: update/archive/unarchive (che
 * invalidano `detail(id)`) e delete (che lo rimuove) le coprono già per prefisso.
 */
export const vehicleChartsKeys = {
  all: (id: number) => [...vehicleKeys.detail(id), 'charts'] as const,
  window: (id: number, months: number) => [...vehicleChartsKeys.all(id), months] as const,
};

/**
 * Da chiamare quando cambia un dato che alimenta le statistiche (rifornimenti, manutenzioni, spese).
 * Invalida anche i grafici del veicolo e quelli della dashboard, che aggregano gli stessi dati.
 */
export function invalidateVehicleStats(qc: QueryClient, id: number) {
  void qc.invalidateQueries({ queryKey: vehicleStatsKey(id) });
  void qc.invalidateQueries({ queryKey: vehicleChartsKeys.all(id) });
  void qc.invalidateQueries({ queryKey: dashboardKeys.all });
}

/** Grafici del solo veicolo (stesso contratto della dashboard), visibili a chi vede il veicolo. */
export function useVehicleCharts(
  id: number,
  months = DASHBOARD_CHART_MONTHS,
  options: { enabled?: boolean } = {},
) {
  return useQuery({
    queryKey: vehicleChartsKeys.window(id, months),
    queryFn: () => getVehicleCharts(id, months),
    enabled: id > 0 && (options.enabled ?? true),
    staleTime: 60_000,
  });
}

export function useVehicleStats(id: number, options: { enabled?: boolean } = {}) {
  return useQuery({
    queryKey: vehicleStatsKey(id),
    queryFn: () => getVehicleStats(id),
    enabled: id > 0 && (options.enabled ?? true),
  });
}

export function useCreateVehicle() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: CreateVehicleDto) => createVehicle(body),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: vehicleKeys.lists() });
      // Cambia l'insieme dei veicoli propri su cui si calcolano i grafici.
      void qc.invalidateQueries({ queryKey: dashboardKeys.all });
    },
  });
}

export function useUpdateVehicle(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: UpdateVehicleDto) => updateVehicle(id, body),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: vehicleKeys.detail(id) });
      void qc.invalidateQueries({ queryKey: vehicleKeys.lists() });
      // Km iniziali e carburanti entrano nei km percorsi e nelle serie di consumo.
      void qc.invalidateQueries({ queryKey: dashboardKeys.all });
    },
  });
}

export function useDeleteVehicle() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteVehicle(id),
    onSuccess: (_data, id) => {
      void qc.invalidateQueries({ queryKey: vehicleKeys.lists() });
      qc.removeQueries({ queryKey: vehicleKeys.detail(id) });
      qc.removeQueries({ queryKey: vehicleStatsKey(id) });
      void qc.invalidateQueries({ queryKey: dashboardKeys.all });
      // Il veicolo cancellato porta via i figli (cascade): le loro liste in cache sono stale.
      // Radici letterali per non creare import circolari con gli hook dei figli.
      for (const root of ['maintenances', 'refuelings', 'expenses', 'reminders', 'attachments']) {
        void qc.invalidateQueries({ queryKey: [root] });
      }
    },
  });
}

export function useUnarchiveVehicle() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => unarchiveVehicle(id),
    onSuccess: (_data, id) => {
      void qc.invalidateQueries({ queryKey: vehicleKeys.detail(id) });
      void qc.invalidateQueries({ queryKey: vehicleKeys.lists() });
      // I grafici e le scadenze in arrivo escludono gli archiviati. Radice letterale come in
      // useDeleteVehicle, per non importare useReminders (import circolare).
      void qc.invalidateQueries({ queryKey: dashboardKeys.all });
      void qc.invalidateQueries({ queryKey: ['reminders'] });
    },
  });
}

export function useArchiveVehicle() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => archiveVehicle(id),
    onSuccess: (_data, id) => {
      void qc.invalidateQueries({ queryKey: vehicleKeys.detail(id) });
      void qc.invalidateQueries({ queryKey: vehicleKeys.lists() });
      // I grafici e le scadenze in arrivo escludono gli archiviati. Radice letterale come in
      // useDeleteVehicle, per non importare useReminders (import circolare).
      void qc.invalidateQueries({ queryKey: dashboardKeys.all });
      void qc.invalidateQueries({ queryKey: ['reminders'] });
    },
  });
}
