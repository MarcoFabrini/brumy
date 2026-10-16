import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { authFetch } from '@/api/client';
import { getTransferCandidates, transferVehicle } from '@/api/endpoints/vehicles';
import type { TransferVehicleDto } from '@/api/types/vehicleTransfer';
import { dashboardKeys } from '@/hooks/useDashboardCharts';
import { reminderKeys } from '@/hooks/useReminders';
import { vehicleKeys } from '@/hooks/useVehicles';

const JSON_HEADERS = { 'Content-Type': 'application/json' };

export type ShareRole = 'viewer' | 'editor' | 'admin';

/** Solo nomi: le share non espongono l'email dei colleghi (la vede chi gestisce i membri dell'org). */
interface ShareUser {
  id: number;
  firstName: string;
  lastName: string;
}

export interface VehicleShare {
  id: number;
  role: ShareRole;
  acceptedAt: string | null;
  createdAt: string;
  user: ShareUser;
  invitedBy?: ShareUser | null;
}

const sharesKey = (vehicleId: number) => ['vehicles', vehicleId, 'shares'] as const;

/**
 * Share del veicolo: quello `admin` è il proprietario (chi l'ha creato, se
 * member), i `viewer` sono le condivisioni in sola lettura.
 */
export function useVehicleShares(vehicleId: number, enabled = true) {
  return useQuery({
    queryKey: sharesKey(vehicleId),
    queryFn: () => authFetch<VehicleShare[]>(`/api/vehicles/${vehicleId}/shares`),
    enabled,
  });
}

/** Membro dell'organizzazione con cui si può condividere il veicolo (solo nome: niente email). */
export interface ShareCandidate {
  id: number;
  firstName: string;
  lastName: string;
}

const candidatesKey = (vehicleId: number) => ['vehicles', vehicleId, 'share-candidates'] as const;

/**
 * Membri dell'org selezionabili per la condivisione: accettati, ruolo member, non
 * chi condivide e non chi ha già uno share (owner/admin vedono già tutto).
 */
export function useShareCandidates(vehicleId: number, enabled = true) {
  return useQuery({
    queryKey: candidatesKey(vehicleId),
    queryFn: () => authFetch<ShareCandidate[]>(`/api/vehicles/${vehicleId}/share-candidates`),
    enabled,
  });
}

/** Condivide il veicolo in sola lettura con uno o più membri dell'org, scelti per id. */
export function useCreateShare(vehicleId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: { userIds: number[] }) =>
      authFetch<VehicleShare[]>(`/api/vehicles/${vehicleId}/shares`, {
        method: 'POST',
        headers: JSON_HEADERS,
        body: JSON.stringify(payload),
      }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: sharesKey(vehicleId) });
      void qc.invalidateQueries({ queryKey: candidatesKey(vehicleId) });
    },
  });
}

/** Revoca una condivisione. */
export function useRevokeShare(vehicleId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (shareId: number) =>
      authFetch<void>(`/api/vehicles/${vehicleId}/shares/${shareId}`, { method: 'DELETE' }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: sharesKey(vehicleId) });
      void qc.invalidateQueries({ queryKey: candidatesKey(vehicleId) });
    },
  });
}

/** Candidati al trasferimento sotto il dettaglio del veicolo: `vehicleKeys.all`/`detail(id)` li coprono per prefisso. */
const transferCandidatesKey = (vehicleId: number) => [...vehicleKeys.detail(vehicleId), 'transfer-candidates'] as const;

/**
 * Membri accettati dell'org (solo nome, qualsiasi ruolo) a cui cedere il veicolo, escluso il proprietario
 * attuale. `enabled` serve a chiedere l'elenco solo quando il dialog è aperto.
 */
export function useTransferCandidates(vehicleId: number, enabled = true) {
  return useQuery({
    queryKey: transferCandidatesKey(vehicleId),
    queryFn: () => getTransferCandidates(vehicleId),
    enabled: vehicleId > 0 && enabled,
  });
}

/**
 * Cede la proprietà del veicolo. Cambia chi lo possiede: il backend sposta con lui totali e grafici
 * (storico incluso), promemoria e notifiche, e `ownership`/`permissions` di chi cede. Si invalidano quindi
 * tutti i veicoli (`vehicleKeys.all`: liste, dettagli con statistiche e grafici del veicolo, condivisioni,
 * candidati), la dashboard e i promemoria. Non si attende il refetch: se chi cede perde l'accesso la
 * pagina naviga subito altrove.
 */
export function useTransferVehicle(vehicleId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: TransferVehicleDto) => transferVehicle(vehicleId, body),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: vehicleKeys.all });
      void qc.invalidateQueries({ queryKey: dashboardKeys.all });
      void qc.invalidateQueries({ queryKey: reminderKeys.all });
    },
  });
}
