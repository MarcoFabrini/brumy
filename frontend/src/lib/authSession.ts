import { getMe } from '@/api/endpoints/auth';
import type { Me, User } from '@/api/types/auth';
import { useAuthStore } from '@/stores/useAuthStore';

/** Separa l'organizzazione attiva della sessione dall'utente, come li tiene lo store. */
export function splitMe({ activeOrganizationId, ...user }: Me): { user: User; activeOrgId: number | null } {
  return { user, activeOrgId: activeOrganizationId };
}

/** Dopo login, codice della verifica in due passaggi o registrazione: chiede `/me` e apre la sessione nello store. */
export async function enterSession(): Promise<User> {
  const { user, activeOrgId } = splitMe(await getMe());
  useAuthStore.getState().setAuthenticated(user, activeOrgId);
  return user;
}

/** Ricarica l'utente collegato (profilo, membership, verifica in due passaggi) senza cambiare lo stato della sessione. */
export async function reloadUser(): Promise<User> {
  const { user } = splitMe(await getMe());
  useAuthStore.getState().setUser(user);
  return user;
}
