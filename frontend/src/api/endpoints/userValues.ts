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

/** Nuova voce; con `fuelStationId` un distributore nasce già collegato a quello ufficiale. */
export async function createUserValue(kind: UserValueKind, name: string, fuelStationId?: number): Promise<ManagedUserValue> {
  const raw = await authFetch<unknown>(`/api/values/${kind}`, jsonInit('POST', { name, fuelStationId }));
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

/** Collega una voce distributore al distributore ufficiale (null la scollega). */
export async function linkUserValueFuelStation(id: number, fuelStationId: number | null): Promise<ManagedUserValue> {
  const raw = await authFetch<unknown>(`/api/values/${id}/fuel-station`, jsonInit('PATCH', { fuelStationId }));
  return managedUserValueSchema.parse(raw);
}

/** Segue (o smette di seguire) il prezzo del distributore collegato alla voce. */
export async function followUserValuePrice(id: number, follow: boolean): Promise<ManagedUserValue> {
  const raw = await authFetch<unknown>(`/api/values/${id}/follow-price`, jsonInit('PATCH', { follow }));
  return managedUserValueSchema.parse(raw);
}
