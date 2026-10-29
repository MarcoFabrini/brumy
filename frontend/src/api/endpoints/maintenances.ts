import { authFetch } from '@/api/client';
import { listUrl } from '@/api/endpoints/listUrl';
import { LIST_PAGE_SIZE } from '@/lib/pagination';
import {
  adaptMaintenance,
  type CreateMaintenanceDto,
  type Maintenance,
  type MaintenanceCategory,
  type MaintenanceResponse,
  type UpdateMaintenanceDto,
} from '@/api/types/maintenance';

/**
 * Una pagina ({@link LIST_PAGE_SIZE} record, dai più recenti) dei lavori del veicolo, di una sezione o di tutte,
 * con la ricerca `search` (query string di `buildSearchQuery`).
 */
export async function listMaintenances(
  vehicleId: number,
  { category, page = 1, search }: { category?: MaintenanceCategory; page?: number; search?: string } = {},
): Promise<Maintenance[]> {
  const url = listUrl('/api/maintenances', { vehicleId, category, page, limit: LIST_PAGE_SIZE }, search);
  const raw = await authFetch<MaintenanceResponse[]>(url);
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
