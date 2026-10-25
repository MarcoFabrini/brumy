import { authFetch } from '@/api/client';
import {
  adaptMaintenance,
  type CreateMaintenanceDto,
  type Maintenance,
  type MaintenanceCategory,
  type MaintenanceResponse,
  type UpdateMaintenanceDto,
} from '@/api/types/maintenance';

/** Lavori del veicolo (i più recenti), di una sezione o di tutte. */
export async function listMaintenances(vehicleId: number, category?: MaintenanceCategory): Promise<Maintenance[]> {
  const query = new URLSearchParams({ vehicleId: String(vehicleId), ...(category ? { category } : {}) });
  const raw = await authFetch<MaintenanceResponse[]>(`/api/maintenances?${query}`);
  return raw.map(adaptMaintenance);
}

export async function getMaintenance(id: number): Promise<Maintenance> {
  const raw = await authFetch<MaintenanceResponse>(`/api/maintenances/${id}`);
  return adaptMaintenance(raw);
}

export async function createMaintenance(body: CreateMaintenanceDto): Promise<Maintenance> {
  const raw = await authFetch<MaintenanceResponse>('/api/maintenances', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  return adaptMaintenance(raw);
}

export async function updateMaintenance(
  id: number,
  body: UpdateMaintenanceDto,
): Promise<Maintenance> {
  const raw = await authFetch<MaintenanceResponse>(`/api/maintenances/${id}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  return adaptMaintenance(raw);
}

export function deleteMaintenance(id: number) {
  return authFetch<void>(`/api/maintenances/${id}`, { method: 'DELETE' });
}
