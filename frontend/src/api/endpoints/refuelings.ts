import { authFetch } from '@/api/client';
import { listUrl } from '@/api/endpoints/listUrl';
import { LIST_PAGE_SIZE } from '@/lib/pagination';
import {
  adaptRefueling,
  type CreateRefuelingDto,
  type Refueling,
  type RefuelingResponse,
  type UpdateRefuelingDto,
} from '@/api/types/refueling';

/** I rifornimenti più recenti del veicolo (la prima pagina dell'API, come la usa l'aggiunta rapida). */
export async function listRefuelings(vehicleId: number): Promise<Refueling[]> {
  const raw = await authFetch<RefuelingResponse[]>(`/api/refuelings?vehicleId=${vehicleId}`);
  return raw.map(adaptRefueling);
}

/** Una pagina ({@link LIST_PAGE_SIZE} record, dai più recenti) dei rifornimenti del veicolo, con la ricerca `search`. */
export async function listRefuelingPage(
  vehicleId: number,
  { page = 1, search }: { page?: number; search?: string } = {},
): Promise<Refueling[]> {
  const url = listUrl('/api/refuelings', { vehicleId, page, limit: LIST_PAGE_SIZE }, search);
  const raw = await authFetch<RefuelingResponse[]>(url);
  return raw.map(adaptRefueling);
}

export async function getRefueling(id: number): Promise<Refueling> {
  const raw = await authFetch<RefuelingResponse>(`/api/refuelings/${id}`);
  return adaptRefueling(raw);
}

export async function createRefueling(body: CreateRefuelingDto): Promise<Refueling> {
  const raw = await authFetch<RefuelingResponse>('/api/refuelings', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  return adaptRefueling(raw);
}

export async function updateRefueling(id: number, body: UpdateRefuelingDto): Promise<Refueling> {
  const raw = await authFetch<RefuelingResponse>(`/api/refuelings/${id}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  return adaptRefueling(raw);
}

export function deleteRefueling(id: number) {
  return authFetch<void>(`/api/refuelings/${id}`, { method: 'DELETE' });
}
