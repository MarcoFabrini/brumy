import type { UserValueKind } from '@/api/types/userValue';

/**
 * Query keys delle voci private. Modulo foglia: i hook dei record lo importano per invalidare
 * `all` senza dipendere da useUserValues (che importa le loro chiavi), niente cicli.
 */
export const userValueKeys = {
  all: ['user-values'] as const,
  suggestions: (vehicleId: number, kind: UserValueKind) =>
    [...userValueKeys.all, 'suggestions', vehicleId, kind] as const,
  list: (kind: UserValueKind) => [...userValueKeys.all, 'list', kind] as const,
};
