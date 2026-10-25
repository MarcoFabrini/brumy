import { z } from 'zod';
import { authFetch } from '@/api/client';
import {
  managedUserValueSchema,
  userValueSchema,
  type ManagedUserValue,
  type UserValue,
  type UserValueKind,
} from '@/api/types/userValue';

const jsonInit = (method: string, body: unknown): RequestInit => ({
  method,
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify(body),
});

/** Suggerimenti per un veicolo, ordinati per uso. */
export async function listValueSuggestions(vehicleId: number, kind: UserValueKind): Promise<UserValue[]> {
  const raw = await authFetch<unknown>(`/api/vehicles/${vehicleId}/values/${kind}`);
  return z.array(userValueSchema).parse(raw);
}

/** Tutte le voci dell'utente di un tipo, ordinate per nome. */
export async function listUserValues(kind: UserValueKind): Promise<ManagedUserValue[]> {
  const raw = await authFetch<unknown>(`/api/values/${kind}`);
  return z.array(managedUserValueSchema).parse(raw);
}

export async function createUserValue(kind: UserValueKind, name: string): Promise<ManagedUserValue> {
  const raw = await authFetch<unknown>(`/api/values/${kind}`, jsonInit('POST', { name }));
  return managedUserValueSchema.parse(raw);
}

export async function renameUserValue(id: number, name: string): Promise<ManagedUserValue> {
  const raw = await authFetch<unknown>(`/api/values/${id}`, jsonInit('PATCH', { name }));
  return managedUserValueSchema.parse(raw);
}

export async function mergeUserValue(id: number, intoId: number): Promise<void> {
  await authFetch<void>(`/api/values/${id}/merge`, jsonInit('POST', { intoId }));
}

export async function deleteUserValue(id: number): Promise<void> {
  await authFetch<void>(`/api/values/${id}`, { method: 'DELETE' });
}
