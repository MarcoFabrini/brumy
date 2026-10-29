import { authFetch } from '@/api/client';
import { listUrl } from '@/api/endpoints/listUrl';
import type { CreateVehicleDto, UpdateVehicleDto, Vehicle } from '@/api/types/vehicle';
import type { VehicleStats } from '@/api/types/vehicleStats';
import { dashboardChartsSchema, type DashboardCharts } from '@/api/types/dashboardCharts';
import {
  transferCandidatesSchema,
  type TransferCandidate,
  type TransferVehicleDto,
} from '@/api/types/vehicleTransfer';

/**
 * Vehicle API endpoints — thin wrapper su authFetch.
 * Restituisce dati typed senza side-effect.
 */

/** Veicoli attivi accessibili; `search`: query string di `buildSearchQuery`. */
export function listVehicles(search?: string) {
  return authFetch<Vehicle[]>(listUrl('/api/vehicles', {}, search));
}

/** Solo i veicoli archiviati a cui l'utente ha accesso (stesse regole della lista normale). */
export function listArchivedVehicles(search?: string) {
  return authFetch<Vehicle[]>(listUrl('/api/vehicles', { archived: 1 }, search));
}

export function getVehicle(id: number) {
  return authFetch<Vehicle>(`/api/vehicles/${id}`);
}

export function createVehicle(body: CreateVehicleDto) {
  return authFetch<Vehicle>('/api/vehicles', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
}

export function updateVehicle(id: number, body: UpdateVehicleDto) {
  return authFetch<Vehicle>(`/api/vehicles/${id}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
}

export function deleteVehicle(id: number) {
  return authFetch<void>(`/api/vehicles/${id}`, { method: 'DELETE' });
}

export function unarchiveVehicle(id: number) {
  return authFetch<Vehicle>(`/api/vehicles/${id}/unarchive`, { method: 'POST' });
}

export function archiveVehicle(id: number) {
  return authFetch<Vehicle>(`/api/vehicles/${id}/archive`, { method: 'POST' });
}

export function getVehicleStats(id: number) {
  return authFetch<VehicleStats>(`/api/vehicles/${id}/stats`);
}

/** Grafici del solo veicolo degli ultimi `months` mesi: stesso contratto della dashboard. */
export async function getVehicleCharts(id: number, months: number): Promise<DashboardCharts> {
  const raw = await authFetch<unknown>(`/api/vehicles/${id}/charts?months=${months}`);
  return dashboardChartsSchema.parse(raw);
}

/** Membri dell'organizzazione del veicolo a cui lo si può cedere (esclusi il proprietario attuale e chi non è accettato). */
export async function getTransferCandidates(id: number): Promise<TransferCandidate[]> {
  const raw = await authFetch<unknown>(`/api/vehicles/${id}/transfer-candidates`);
  return transferCandidatesSchema.parse(raw);
}

/** Cede la proprietà del veicolo: 204 senza corpo. */
export function transferVehicle(id: number, body: TransferVehicleDto) {
  return authFetch<void>(`/api/vehicles/${id}/transfer`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
}
