import { z } from 'zod';
import { authFetch } from '@/api/client';
import { listUrl } from '@/api/endpoints/listUrl';
import {
  adaptComponent,
  adaptComponentDetail,
  componentDetailSchema,
  componentSchema,
  type Component,
  type ComponentDetail,
  type ComponentDto,
} from '@/api/types/component';

const jsonInit = (method: string, body: unknown): RequestInit => ({
  method,
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify(body),
});

/** Componenti del veicolo, per nome; `search`: query string di `buildSearchQuery`. */
export async function listComponents(vehicleId: number, search?: string): Promise<Component[]> {
  const raw = await authFetch<unknown>(listUrl('/api/components', { vehicleId }, search));
  return z.array(componentSchema).parse(raw).map(adaptComponent);
}

export async function getComponent(id: number): Promise<ComponentDetail> {
  const raw = await authFetch<unknown>(`/api/components/${id}`);
  return adaptComponentDetail(componentDetailSchema.parse(raw));
}

export async function createComponent(body: ComponentDto): Promise<ComponentDetail> {
  const raw = await authFetch<unknown>('/api/components', jsonInit('POST', body));
  return adaptComponentDetail(componentDetailSchema.parse(raw));
}

export async function updateComponent(id: number, body: ComponentDto): Promise<ComponentDetail> {
  const raw = await authFetch<unknown>(`/api/components/${id}`, jsonInit('PUT', body));
  return adaptComponentDetail(componentDetailSchema.parse(raw));
}

export async function deleteComponent(id: number): Promise<void> {
  await authFetch<void>(`/api/components/${id}`, { method: 'DELETE' });
}
